<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * スタッフポータル（スマホ前提・PASS付き）
 * URL: /?bv_staff=1
 * メニュー → 予約ガント / 予約一覧 / 予約追加 / 返却処理 / 車両メンテ（各独立ページ）
 */
class BV_Staff_Portal {

	/**
	 * 店舗限定ポータルの範囲（null = 全店舗の通常ポータル）
	 * 例：From P出張所専用 → ?bv_staff_fromp=1 、店舗グループ 'fromp' の予約・車両だけを扱う
	 */
	protected static $scope = null;

	/** 店舗限定ポータルの一覧（クエリ名 → 店舗グループ） */
	public static function scoped_portals() {
		return array(
			'bv_staff_fromp' => array( 'group' => 'fromp', 'pass_key' => 'staff_pass_fromp', 'cookie' => 'bv_staff_auth_fromp', 'title' => 'From P出張所ポータル' ),
		);
	}

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'router' ) );
	}

	/* ---------- 範囲（店舗限定）ヘルパー ---------- */

	protected static function query_key() {
		return self::$scope ? self::$scope['query'] : 'bv_staff';
	}
	protected static function cookie_name() {
		return self::$scope ? self::$scope['cookie'] : 'bv_staff_auth';
	}
	protected static function pass() {
		$s = BV_Util::settings();
		$k = self::$scope ? self::$scope['pass_key'] : 'staff_pass';
		return isset( $s[ $k ] ) ? (string) $s[ $k ] : '';
	}
	protected static function scope_stores() {
		return self::$scope ? self::$scope['stores'] : array_keys( BV_Util::stores() );
	}
	protected static function scope_locations() {
		return self::$scope ? self::$scope['locations'] : array_keys( BV_Util::locations() );
	}
	/** この予約を扱ってよいか */
	protected static function in_scope( $r ) {
		return ! self::$scope || ( $r && in_array( $r->store, self::$scope['stores'], true ) );
	}
	/** この車両を扱ってよいか */
	protected static function vehicle_in_scope( $v ) {
		return ! self::$scope || ( $v && in_array( $v->location, self::$scope['locations'], true ) );
	}
	protected static function filter_reservations( $list ) {
		if ( ! self::$scope ) return $list;
		return array_values( array_filter( (array) $list, array( __CLASS__, 'in_scope' ) ) );
	}
	protected static function filter_vehicles( $list ) {
		if ( ! self::$scope ) return $list;
		return array_values( array_filter( (array) $list, array( __CLASS__, 'vehicle_in_scope' ) ) );
	}
	/** 範囲内の店舗一覧（セレクト用） */
	protected static function stores_for_select() {
		$all = BV_Util::stores();
		if ( ! self::$scope ) return $all;
		return array_intersect_key( $all, array_flip( self::$scope['stores'] ) );
	}

	protected static function authed() {
		$c = self::cookie_name();
		return ! empty( $_COOKIE[ $c ] ) && hash_equals( self::auth_hash(), (string) $_COOKIE[ $c ] );
	}

	protected static function auth_hash() {
		return self::hash_for( self::query_key(), self::pass() );
	}

	protected static function hash_for( $query_key, $pass ) {
		return hash_hmac( 'sha256', 'bv-staff-' . $query_key . '-' . $pass, wp_salt( 'auth' ) );
	}

	/**
	 * いずれかのスタッフポータルにログイン済みか（本人確認書類の配信判定などに使う）
	 * ルーター外から呼ばれるため、$scope に依存せず全ポータルを確認する。
	 */
	public static function any_authed() {
		$s = BV_Util::settings();

		$portals = array( 'bv_staff' => array( 'pass_key' => 'staff_pass', 'cookie' => 'bv_staff_auth' ) );
		foreach ( self::scoped_portals() as $qk => $def ) $portals[ $qk ] = $def;

		foreach ( $portals as $qk => $def ) {
			$pass   = isset( $s[ $def['pass_key'] ] ) ? (string) $s[ $def['pass_key'] ] : '';
			$cookie = $def['cookie'];
			if ( '' === $pass || empty( $_COOKIE[ $cookie ] ) ) continue;
			if ( hash_equals( self::hash_for( $qk, $pass ), (string) $_COOKIE[ $cookie ] ) ) return true;
		}
		return false;
	}

	/* ---------- ログイン試行の制限 ---------- */

	const LOGIN_MAX_TRIES = 10;
	const LOGIN_WINDOW    = 900; /* 15分 */

	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return $ip ? $ip : 'unknown';
	}

	protected static function fail_key() {
		return 'bvrm_staff_fail_' . md5( self::client_ip() . '|' . self::query_key() );
	}

	/** 直近の失敗回数が上限に達しているか */
	protected static function login_blocked() {
		return (int) get_transient( self::fail_key() ) >= self::LOGIN_MAX_TRIES;
	}

	protected static function note_login_failure() {
		$k = self::fail_key();
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, self::LOGIN_WINDOW );
	}

	protected static function clear_login_failures() {
		delete_transient( self::fail_key() );
	}

	/** 認証クッキーの発行（SameSite付き） */
	protected static function set_auth_cookie( $value, $expires ) {
		$args = array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::cookie_name(), $value, $args );
		} else {
			setcookie( self::cookie_name(), $value, $expires, '/; samesite=Lax', '', is_ssl(), true );
		}
	}

	protected static function base( $view = '' ) {
		$url = home_url( '/?' . self::query_key() . '=1' );
		return $view ? add_query_arg( 'view', $view, $url ) : $url;
	}

	public static function router() {
		/* 店舗限定ポータル？ */
		foreach ( self::scoped_portals() as $qk => $def ) {
			if ( isset( $_GET[ $qk ] ) ) {
				$groups = BV_Util::store_groups();
				if ( empty( $groups[ $def['group'] ] ) ) return;
				self::$scope = array_merge( $def, array(
					'query'     => $qk,
					'stores'    => $groups[ $def['group'] ]['stores'],
					'locations' => $groups[ $def['group'] ]['locations'],
				) );
				break;
			}
		}
		if ( ! self::$scope && ! isset( $_GET['bv_staff'] ) ) return;
		nocache_headers();
		$s = BV_Util::settings();
		$pass = self::pass();

		/* ログイン */
		if ( isset( $_POST['bv_staff_pass'] ) ) {
			/* 総当たり対策：同一IPからの失敗が続く場合は一定時間受け付けない */
			if ( self::login_blocked() ) {
				self::header( 'ログイン' );
				echo '<p class="warn">ログインの失敗が続いたため、しばらく受け付けられません。15分ほど時間をおいてからお試しください。</p>';
				self::footer( false );
				exit;
			}
			if ( $pass && hash_equals( $pass, (string) wp_unslash( $_POST['bv_staff_pass'] ) ) ) {
				self::clear_login_failures();
				self::set_auth_cookie( self::auth_hash(), time() + 12 * HOUR_IN_SECONDS );
				wp_safe_redirect( self::base() );
				exit;
			}
			self::note_login_failure();
			$left = max( 0, self::LOGIN_MAX_TRIES - (int) get_transient( self::fail_key() ) );
			self::header( 'ログイン' );
			echo '<p class="warn">PASSが違います。（あと' . (int) $left . '回間違えると、しばらくログインできなくなります）</p>';
			self::login_form();
			self::footer( false );
			exit;
		}
		if ( isset( $_GET['logout'] ) ) {
			self::set_auth_cookie( '', time() - 3600 );
			wp_safe_redirect( self::base() );
			exit;
		}
		if ( ! self::authed() ) {
			self::header( 'スタッフログイン' );
			if ( ! $pass ) echo '<p class="warn">管理画面の「設定」で' . ( self::$scope ? esc_html( self::$scope['title'] ) . 'の' : 'スタッフ' ) . 'PASSを設定してください。</p>';
			self::login_form();
			self::footer( false );
			exit;
		}

		/* ガントの対話操作（JSON） */
		if ( isset( $_GET['gantt_action'] ) ) {
			$raw = file_get_contents( 'php://input' );
			$p = json_decode( $raw, true );
			if ( ! is_array( $p ) ) $p = array();
			header( 'Content-Type: application/json; charset=UTF-8' );
			if ( empty( $p['token'] ) || ! hash_equals( self::auth_hash(), (string) $p['token'] ) ) {
				echo wp_json_encode( array( 'ok' => false, 'message' => 'セッションが無効です。再ログインしてください。' ) );
				exit;
			}
			echo wp_json_encode( BV_Gantt::do_action( sanitize_key( $p['action'] ?? '' ), $p, self::$scope ) );
			exit;
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'menu';
		switch ( $view ) {
			case 'gantt':   self::page_gantt(); break;
			case 'list':    self::page_list(); break;
			case 'add':     self::page_add(); break;
			case 'return':  self::page_return(); break;
			case 'vehicles':self::page_vehicles(); break;
			case 'detail':  self::page_detail(); break;
			case 'stats':   self::page_stats(); break;
			default:        self::page_menu();
		}
		exit;
	}

	/* ---------- 枠 ---------- */

	protected static function header( $title ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
		$ptitle = self::$scope ? self::$scope['title'] : 'スタッフポータル';
		echo '<title>' . esc_html( $title ) . ' | ' . esc_html( $ptitle ) . '</title><style>
		body{font-family:-apple-system,"Hiragino Kaku Gothic ProN",Meiryo,sans-serif;margin:0;background:#f4f5f7;color:#1d2327;overscroll-behavior-x:none}
		.top{background:#1d2327;color:#fff;padding:12px 16px;font-weight:600;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:5}
		.top a{color:#9ec2e6;text-decoration:none;font-size:13px;font-weight:normal}
		.wrap{padding:14px;max-width:960px;margin:0 auto}
		.card{background:#fff;border-radius:10px;padding:14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
		.menu{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:4px}
		.menu a{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;min-height:118px;background:#fff;border-radius:14px;padding:20px 12px;text-decoration:none;color:#1d2327;font-size:17px;font-weight:700;text-align:center;box-shadow:0 2px 6px rgba(0,0,0,.08);border:2px solid transparent;transition:transform .08s,box-shadow .08s}
		.menu a:active{transform:scale(.97)}
		.menu a:hover{border-color:#2271b1;box-shadow:0 4px 12px rgba(34,113,177,.18)}
		.menu a span{font-size:34px;line-height:1}
		.menu a small{display:block;font-size:12px;font-weight:400;color:#646970;margin-top:-4px}
		.menu a.primary{background:#2271b1;color:#fff}
		.menu a.primary small{color:#dbe9f5}
		.menu a.primary:hover{border-color:#135e96}
		@media(min-width:700px){.menu{grid-template-columns:repeat(3,1fr)}.menu a{min-height:130px;font-size:18px}.menu a span{font-size:38px}}
		.overdue{border-left:6px solid #b32d2e}
		.overdue select{margin:6px 0 8px}
		input,select,textarea,button{font-size:16px;padding:10px;border:1px solid #c3c4c7;border-radius:8px;box-sizing:border-box;max-width:100%}
		input,select,textarea{width:100%;margin:4px 0 12px;background:#fff}
		input[type=checkbox]{width:auto;margin-right:6px;transform:scale(1.3)}
		label:has(input[type=checkbox]){display:block;font-weight:normal}
		label{font-weight:600;font-size:13px}
		button,.btn{background:#2271b1;color:#fff;border:0;cursor:pointer;padding:12px 20px;border-radius:8px;display:inline-block;text-decoration:none;text-align:center;font-size:15px}
		.btn.gray{background:#646970}.btn.green{background:#00a32a}.btn.red{background:#b32d2e}
		table{border-collapse:collapse;width:100%;background:#fff;font-size:13px}
		th,td{border-bottom:1px solid #e2e4e7;padding:9px 8px;text-align:left}
		th{background:#f0f0f1}
		.warn{background:#fcf0f1;border:1px solid #d63638;padding:10px;border-radius:8px}
		.ok{background:#edfaef;border:1px solid #00a32a;padding:10px;border-radius:8px}
		.note{font-size:12px;color:#646970}
		.navbtns{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}
		.navbtns .btn{flex:1;min-width:100px}
		/* 本日・明日の業務 */
		.stfil{display:flex;gap:6px;flex-wrap:wrap}
		.stfil a{font-size:12px;padding:6px 10px;border-radius:6px;background:#f0f0f1;color:#50575e;text-decoration:none;white-space:nowrap}
		.stfil a.on{background:#2271b1;color:#fff}
		table.tasks{font-size:13px}
		table.tasks td{border-bottom:1px solid #f0f0f1;padding:8px 6px;vertical-align:top}
		table.tasks td.tm{width:52px;font-weight:700;font-size:15px;padding-left:8px;white-space:nowrap}
		table.tasks td.act{width:64px;text-align:right}
		.bdg{display:inline-block;font-size:11px;padding:2px 7px;border-radius:10px;margin:4px 4px 0 0;font-weight:600;line-height:1.5}
		.bdg.red{background:#fcf0f1;color:#b32d2e;border:1px solid #f0c2c3}
		.bdg.green{background:#edfaef;color:#007017;border:1px solid #b8e6c2}
		.bdg.blue{background:#eef5fb;color:#1d5b8f;border:1px solid #bcd8ef}
		.bdg.gray{background:#f0f0f1;color:#646970;border:1px solid #dcdcde}
		.bdg.yellow{background:#fcf9e8;color:#8a6d00;border:1px solid #eddfa0}
		.mini{display:inline-block;font-size:12px;padding:6px 8px;border-radius:6px;background:#f0f0f1;color:#2271b1;text-decoration:none;white-space:nowrap}
		.mini.green{background:#00a32a;color:#fff}
		</style></head><body>';
		echo '<div class="top"' . ( self::$scope ? ' style="background:#5a4636"' : '' ) . '><span>🚗 ' . esc_html( $ptitle ) . '</span><a href="' . esc_url( self::base() ) . '">メニュー</a></div><div class="wrap">';
		echo '<h2 style="margin-top:4px">' . esc_html( $title ) . '</h2>';
	}

	protected static function footer( $nav = true ) {
		if ( $nav ) {
			/* ガントの表示位置を保持したまま戻る */
			$gantt_url = self::base( 'gantt' );
			$gs = '';
			if ( ! empty( $_REQUEST['pf_gstart'] ) ) $gs = sanitize_text_field( wp_unslash( $_REQUEST['pf_gstart'] ) );
			elseif ( ! empty( $_GET['gstart'] ) )    $gs = sanitize_text_field( wp_unslash( $_GET['gstart'] ) );
			if ( $gs && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $gs ) ) {
				$gd = ! empty( $_REQUEST['pf_gdays'] ) ? (int) $_REQUEST['pf_gdays'] : ( ! empty( $_GET['gdays'] ) ? (int) $_GET['gdays'] : 14 );
				$gantt_url = add_query_arg( array( 'gstart' => $gs, 'gdays' => max( 7, min( 60, $gd ) ) ), $gantt_url );
			}
			echo '<div class="navbtns">';
			echo '<a class="btn gray" href="' . esc_url( self::base() ) . '">メニューへ戻る</a>';
			echo '<a class="btn gray" href="' . esc_url( self::base( 'list' ) ) . '">予約一覧へ</a>';
			echo '<a class="btn gray" href="' . esc_url( $gantt_url ) . '">ガントへ</a>';
			echo '</div>';
			echo '<p class="note" style="margin-top:14px">※ページ移動は下のボタンをご利用ください。ブラウザの「戻る」やスマホの左スワイプは入力内容が消えることがあります。</p>';
		}
		echo '</div></body></html>';
	}

	protected static function login_form() {
		echo '<div class="card"><form method="post"><label>PASS</label><input type="password" name="bv_staff_pass" required autofocus><button>ログイン</button></form></div>';
	}

	/* ---------- メニュー ---------- */

	protected static function page_menu() {
		self::header( 'メニュー' );
		self::render_overdue_returns();
		self::render_daily_board();
		echo '<div class="menu">';
		echo '<a href="' . esc_url( self::base( 'gantt' ) ) . '"><span>📊</span>予約ガント<small>車両ごとの予約状況</small></a>';
		echo '<a href="' . esc_url( self::base( 'list' ) ) . '"><span>📋</span>予約一覧<small>日付で絞り込み</small></a>';
		echo '<a class="primary" href="' . esc_url( self::base( 'add' ) ) . '"><span>➕</span>予約を追加<small>電話・店頭の受付</small></a>';
		echo '<a href="' . esc_url( self::base( 'return' ) ) . '"><span>🔑</span>返却処理<small>メーター・場所の記録</small></a>';
		echo '<a href="' . esc_url( self::base( 'vehicles' ) ) . '"><span>🔧</span>車両メンテ<small>点検・整備の記録</small></a>';
		echo '<a href="' . esc_url( self::base( 'stats' ) ) . '"><span>📈</span>月間集計<small>貸渡・送迎・売上</small></a>';
		echo '</div>';
		echo '<p style="text-align:right"><a class="note" href="' . esc_url( add_query_arg( 'logout', 1, self::base() ) ) . '">ログアウト</a></p>';
		self::footer( false );
	}

	/* ---------- 返却予定を過ぎた未返却 ---------- */

	/**
	 * 返却予定時刻を過ぎたのに返却処理されていない予約（確定・貸出中）を件数つきで表示する。
	 * プルダウンで選んで「返却処理へ」を押すと、その予約が選択された状態で返却処理画面へ移動する。
	 */
	protected static function render_overdue_returns() {
		global $wpdb;
		$t   = BV_DB::table( 'reservations' );
		$now = current_time( 'mysql' );
		$list = self::filter_reservations( $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE status IN ('confirmed','in_use') AND return_dt < %s ORDER BY return_dt ASC LIMIT 100", $now
		) ) );
		if ( ! $list ) return;

		$vmap = array();
		foreach ( BV_DB::get_vehicles( array() ) as $v ) $vmap[ (int) $v->id ] = $v;
		$now_ts = current_time( 'timestamp' );

		echo '<div class="card overdue">';
		echo '<h3 style="margin:0 0 6px;font-size:16px;color:#b32d2e">⚠ 返却時間を過ぎた未返却 <span class="bdg red" style="font-size:14px">' . count( $list ) . '件</span></h3>';
		echo '<p class="note" style="margin:0 0 6px">返却予定時刻を過ぎていますが、まだ返却処理されていない予約です。返却済みなら返却処理を、延長なら詳細で返却日時を変更してください。</p>';
		echo '<form method="get" action="' . esc_url( home_url( '/' ) ) . '" id="bv-overdue-form">';
		echo '<input type="hidden" name="' . esc_attr( self::query_key() ) . '" value="1"><input type="hidden" name="view" value="return">';
		echo '<select name="res" id="bv-overdue-sel">';
		foreach ( $list as $r ) {
			$v = ( $r->vehicle_id && isset( $vmap[ (int) $r->vehicle_id ] ) ) ? $vmap[ (int) $r->vehicle_id ] : null;
			$late = $now_ts - strtotime( $r->return_dt );
			$late_txt = ( $late >= DAY_IN_SECONDS ) ? floor( $late / DAY_IN_SECONDS ) . '日' . floor( ( $late % DAY_IN_SECONDS ) / HOUR_IN_SECONDS ) . '時間' : floor( $late / HOUR_IN_SECONDS ) . '時間' . floor( ( $late % HOUR_IN_SECONDS ) / 60 ) . '分';
			$label = date( 'n/j H:i', strtotime( $r->return_dt ) ) . ' 返却予定（' . $late_txt . '超過）｜' . ( $v ? $v->name : '未割当' ) . '｜' . $r->sei . ' ' . $r->mei . '｜' . $r->code;
			echo '<option value="' . (int) $r->id . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
		echo '<button style="flex:1;background:#00a32a;margin:0">この予約を返却処理する</button>';
		echo '<a class="btn gray" id="bv-overdue-detail" style="flex:1;text-align:center" href="' . esc_url( self::base( 'detail' ) . '&id=' . (int) $list[0]->id ) . '">詳細を見る</a>';
		echo '</div></form>';
		echo '<script>(function(){var s=document.getElementById("bv-overdue-sel"),a=document.getElementById("bv-overdue-detail");if(!s||!a)return;var base=' . wp_json_encode( self::base( 'detail' ) . '&id=' ) . ';s.addEventListener("change",function(){a.href=base+s.value;});})();</script>';
		echo '</div>';
	}

	/* ---------- 本日・明日の業務 ---------- */

	/**
	 * トップに「本日の業務」「明日の業務」を表示する。
	 * 出発（貸出）・返却・送迎を時刻順に並べ、要対応のものを目立たせる。
	 */
	protected static function render_daily_board() {
		$today    = current_time( 'Y-m-d' );
		$tomorrow = date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS );

		/* 店舗の絞り込み（選択はURLで保持する） */
		$stores = self::stores_for_select();
		$filter = isset( $_GET['st'] ) ? sanitize_key( wp_unslash( $_GET['st'] ) ) : '';
		if ( ! isset( $stores[ $filter ] ) ) $filter = '';

		/* 2日分に関係する予約をまとめて取得する */
		$list = self::filter_reservations( BV_DB::get_reservations( array(
			'overlap'           => array( $today . ' 00:00:00', date( 'Y-m-d', current_time( 'timestamp' ) + 2 * DAY_IN_SECONDS ) . ' 00:00:00' ),
			'exclude_cancelled' => true,
		) ) );

		/* 車両名は都度SQLを投げず、一度だけまとめて引く */
		$vmap = array();
		foreach ( BV_DB::get_vehicles( array() ) as $v ) $vmap[ (int) $v->id ] = $v;

		$days = array(
			$today    => array( 'label' => '本日', 'out' => array(), 'in' => array(), 'ongoing' => 0 ),
			$tomorrow => array( 'label' => '明日', 'out' => array(), 'in' => array(), 'ongoing' => 0 ),
		);
		$alerts = array();

		foreach ( $list as $r ) {
			if ( $filter && $r->store !== $filter ) continue;
			$pd = date( 'Y-m-d', strtotime( $r->pickup_dt ) );
			$rd = date( 'Y-m-d', strtotime( $r->return_dt ) );
			if ( isset( $days[ $pd ] ) && 'returned' !== $r->status ) $days[ $pd ]['out'][] = $r;
			if ( isset( $days[ $rd ] ) ) $days[ $rd ]['in'][] = $r;
			/* その日をまたいで貸出が続いているもの（出発も返却もない日） */
			foreach ( array_keys( $days ) as $dk ) {
				if ( $pd < $dk && $rd > $dk && 'returned' !== $r->status ) $days[ $dk ]['ongoing']++;
			}

			/* 要対応（1予約1行にまとめる） */
			if ( ( $pd === $today || $pd === $tomorrow ) && 'returned' !== $r->status ) {
				$when = ( $pd === $today ) ? '本日出発' : '明日出発';
				$why  = array();
				if ( ! $r->paid_at && 'pending' === $r->status ) $why[] = '入金が未確認';
				if ( '' === trim( $r->sei . $r->mei ) )          $why[] = 'お名前が未入力';
				if ( ! $r->vehicle_id )                          $why[] = '車両が未割当';
				if ( in_array( (string) $r->shuttle_status, array( 'requested', 'quoted' ), true ) ) {
					$why[] = '送迎リクエストが未対応（' . BV_Util::label( BV_Util::shuttles(), $r->shuttle ) . '）';
				}
				/* 申し込まれた装備が割当車両に無い */
				$lack = BV_Gantt::missing_equipment( $r );
				if ( $lack ) $why[] = implode( '・', $lack ) . 'が車両に付いていない';
				if ( $why ) {
					$alerts[] = array( 'r' => $r, 'text' => $when . '／' . implode( '・', $why ) );
				}
			}
		}

		/* 店舗の切り替え */
		echo '<div class="card" style="padding:10px 12px">';
		echo '<div class="stfil">';
		$base = self::base();
		echo '<a class="' . ( '' === $filter ? 'on' : '' ) . '" href="' . esc_url( $base ) . '">全店舗</a>';
		foreach ( $stores as $sk => $sv ) {
			$short = preg_replace( '/^(白馬|大町|松本)レンタカー\s*/u', '', $sv['ja'] );
			echo '<a class="' . ( $filter === $sk ? 'on' : '' ) . '" href="' . esc_url( add_query_arg( 'st', $sk, $base ) ) . '"'
				. ' style="border-left:4px solid ' . esc_attr( BV_Util::store_color( $sk ) ) . '">' . esc_html( $short ) . '</a>';
		}
		echo '</div></div>';

		/* 送迎リクエストの未対応一覧（今日以降すべて） */
		self::render_shuttle_queue( $filter );

		/* 要対応 */
		if ( $alerts ) {
			echo '<div class="card" style="border-left:5px solid #d63638">';
			echo '<h3 style="margin:0 0 8px;font-size:15px;color:#b32d2e">要対応（' . count( $alerts ) . '件）</h3>';
			foreach ( $alerts as $a ) {
				$r = $a['r'];
				$who = trim( $r->sei . $r->mei );
				if ( '' === $who ) $who = '（名前未入力）';
				echo '<div style="padding:6px 0;border-bottom:1px solid #f0f0f1">';
				echo '<a href="' . esc_url( self::base( 'detail' ) . '&id=' . $r->id ) . '" style="font-weight:600;text-decoration:none;color:#2271b1">'
					. esc_html( $r->code . '／' . $who ) . '</a>';
				echo '<div class="note">' . esc_html( $a['text'] ) . '</div></div>';
			}
			echo '</div>';
		}

		foreach ( $days as $date => $d ) {
			$wd = array( '日', '月', '火', '水', '木', '金', '土' );
			echo '<div class="card">';
			echo '<h3 style="margin:0 0 10px;font-size:16px">' . esc_html( $d['label'] ) . 'の業務'
				. ' <span class="note" style="font-weight:normal">' . esc_html( date( 'n月j日', strtotime( $date ) ) . '（' . $wd[ (int) date( 'w', strtotime( $date ) ) ] . '）' ) . '</span></h3>';

			if ( ! $d['out'] && ! $d['in'] ) {
				echo '<p class="note" style="margin:0">貸出・返却の予定はありません。'
					. ( $d['ongoing'] ? '（貸出が続いている車両が' . (int) $d['ongoing'] . '台あります）' : '' ) . '</p></div>';
				continue;
			}
			self::task_table( '🚗 出発（貸出）', $d['out'], 'out', $vmap, $date );
			self::task_table( '🔑 返却', $d['in'], 'in', $vmap, $date );
			if ( $d['ongoing'] ) {
				echo '<p class="note" style="margin:10px 0 0">このほか、貸出が続いている車両が' . (int) $d['ongoing'] . '台あります（出発・返却の予定なし）。</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * 送迎料金の入力を読み取る。往復は「お迎え」「お送り」を別々に受け取る。
	 * @return array{pickup:int,dropoff:int,total:int}|WP_Error
	 */
	public static function shuttle_fee_input( $r, $P ) {
		$allowed = BV_Util::shuttle_fees();
		$read = function ( $key ) use ( $P, $allowed ) {
			if ( ! isset( $P[ $key ] ) || '' === (string) $P[ $key ] ) return null; /* 未選択 */
			$v = (int) $P[ $key ];
			if ( 0 === $v || in_array( $v, $allowed, true ) ) return $v;
			return false; /* 不正値 */
		};

		$pu = BV_Util::shuttle_has_pickup( $r->shuttle )  ? $read( 'fee_pickup' )  : 0;
		$dr = BV_Util::shuttle_has_dropoff( $r->shuttle ) ? $read( 'fee_dropoff' ) : 0;

		if ( false === $pu || false === $dr ) {
			return new WP_Error( 'bad_fee', '送迎料金の指定が正しくありません。' );
		}
		if ( null === $pu && null === $dr ) {
			return new WP_Error( 'no_fee', '送迎料金を選んでください。' );
		}
		/* 片方だけ選ばれている場合は、もう片方を0円として扱う */
		if ( null === $pu ) $pu = 0;
		if ( null === $dr ) $dr = 0;

		return array( 'pickup' => $pu, 'dropoff' => $dr, 'total' => $pu + $dr );
	}

	/**
	 * 未対応の送迎リクエスト一覧（本日以降）
	 * requested＝こちらの回答待ち、quoted＝お客様のお支払い待ち。
	 */
	protected static function render_shuttle_queue( $filter = '' ) {
		$list = self::filter_reservations( BV_DB::get_reservations( array(
			'from'              => current_time( 'Y-m-d' ),
			'exclude_cancelled' => true,
			'order'             => 'pickup_dt ASC',
		) ) );

		$waiting = array(); /* 回答待ち */
		$paying  = array(); /* お支払い待ち */
		foreach ( $list as $r ) {
			if ( $filter && $r->store !== $filter ) continue;
			$st = (string) $r->shuttle_status;
			if ( 'requested' === $st ) $waiting[] = $r;
			elseif ( 'quoted' === $st ) $paying[] = $r;
		}
		if ( ! $waiting && ! $paying ) return;

		echo '<div class="card"' . ( $waiting ? ' style="border-left:5px solid #997404"' : '' ) . '>';
		echo '<h3 style="margin:0 0 8px;font-size:15px">送迎リクエスト';
		if ( $waiting ) echo ' <span class="bdg red">回答待ち ' . count( $waiting ) . '件</span>';
		if ( $paying )  echo ' <span class="bdg blue">お支払い待ち ' . count( $paying ) . '件</span>';
		echo '</h3>';

		$row = function ( $r, $kind ) {
			$who = trim( $r->sei . $r->mei );
			if ( '' === $who ) $who = '（名前未入力）';
			echo '<div style="padding:8px 0;border-bottom:1px solid #f0f0f1;display:flex;gap:8px;align-items:flex-start">';
			echo '<div style="width:52px;font-weight:700;font-size:13px;border-left:4px solid ' . esc_attr( BV_Util::store_color( $r->store ) ) . ';padding-left:8px;white-space:nowrap">'
				. esc_html( date( 'n/j', strtotime( $r->pickup_dt ) ) ) . '</div>';
			echo '<div style="flex:1">';
			echo '<a href="' . esc_url( self::base( 'detail' ) . '&id=' . $r->id ) . '" style="font-weight:600;text-decoration:none;color:#1d2327">' . esc_html( $who ) . '</a>';
			echo ' <span class="note">' . esc_html( $r->code ) . '</span>';
			echo '<div class="note">' . esc_html( BV_Util::label( BV_Util::shuttles(), $r->shuttle ) );
			if ( $r->shuttle_detail ) echo '／' . esc_html( mb_strimwidth( $r->shuttle_detail, 0, 40, '…', 'UTF-8' ) );
			echo '</div>';
			if ( 'waiting' === $kind ) {
				echo '<span class="bdg red">料金を決めて承認</span>';
			} else {
				echo '<span class="bdg blue">お支払い待ち ' . esc_html( BV_Util::money( (int) $r->shuttle_fee ) ) . '</span>';
			}
			echo '</div>';
			echo '<div style="width:56px;text-align:right"><a class="mini" href="' . esc_url( self::base( 'detail' ) . '&id=' . $r->id ) . '">対応</a></div>';
			echo '</div>';
		};

		foreach ( $waiting as $r ) $row( $r, 'waiting' );
		foreach ( $paying as $r )  $row( $r, 'paying' );

		if ( $waiting ) {
			echo '<p class="note" style="margin:10px 0 0">回答待ちのリクエストは、予約詳細の「送迎の対応」から料金を選んで承認してください。</p>';
		}
		echo '</div>';
	}

	/** 業務一覧の表（$type: out=出発 / in=返却） */
	protected static function task_table( $title, $rows, $type, $vmap, $date ) {
		$count = count( $rows );
		echo '<p style="margin:10px 0 4px;font-weight:600;font-size:14px">' . esc_html( $title ) . ' <span class="note">' . (int) $count . '件</span></p>';
		if ( ! $rows ) {
			echo '<p class="note" style="margin:0 0 6px">なし</p>';
			return;
		}
		/* 時刻順に並べる */
		usort( $rows, function ( $a, $b ) use ( $type ) {
			$ka = ( 'out' === $type ) ? $a->pickup_dt : $a->return_dt;
			$kb = ( 'out' === $type ) ? $b->pickup_dt : $b->return_dt;
			return strcmp( $ka, $kb );
		} );

		echo '<table class="tasks"><tbody>';
		foreach ( $rows as $r ) {
			$dt   = ( 'out' === $type ) ? $r->pickup_dt : $r->return_dt;
			$who  = trim( $r->sei . $r->mei );
			if ( '' === $who ) $who = '（名前未入力）';
			$v    = ( $r->vehicle_id && isset( $vmap[ (int) $r->vehicle_id ] ) ) ? $vmap[ (int) $r->vehicle_id ] : null;
			$vname = $v ? $v->name : '未割当';
			$color = BV_Util::store_color( $r->store );

			echo '<tr>';
			echo '<td class="tm" style="border-left:4px solid ' . esc_attr( $color ) . '">' . esc_html( date( 'H:i', strtotime( $dt ) ) ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( self::base( 'detail' ) . '&id=' . $r->id ) . '" style="font-weight:600;text-decoration:none;color:#1d2327">' . esc_html( $who ) . '</a>';
			echo ' <span class="note">' . esc_html( $r->code ) . '</span>';
			echo '<div class="note">' . esc_html( $vname . '／' . BV_Util::label( BV_Util::classes(), $r->vehicle_class ) ) . '</div>';

			/* 状態のバッジ */
			if ( 'out' === $type ) {
				if ( ! $r->paid_at && BV_Util::is_offline_payment( $r ) ) {
					echo '<span class="bdg yellow">' . esc_html( BV_Util::label( BV_Util::payment_methods(), $r->payment_method ) )
						. ' ' . esc_html( BV_Util::money( (int) $r->price_total ) ) . ' 要回収</span>';
				} elseif ( ! $r->paid_at && 'pending' === $r->status ) echo '<span class="bdg red">未入金</span>';
				elseif ( 'in_use' === $r->status ) echo '<span class="bdg gray">出発済</span>';
				else echo '<span class="bdg green">入金済</span>';
				if ( ! $r->vehicle_id ) echo '<span class="bdg red">車両未割当</span>';
			} else {
				if ( 'returned' === $r->status ) echo '<span class="bdg gray">返却処理済</span>';
				else echo '<span class="bdg blue">要返却処理</span>';
			}
			if ( 'none' !== $r->shuttle ) {
				$sh_ok = ( 'paid' === $r->shuttle_status );
				echo '<span class="bdg ' . ( $sh_ok ? 'green' : 'red' ) . '">送迎' . ( $sh_ok ? '確定' : '未確定' ) . '</span>';
			}
			if ( $r->request_note ) echo '<span class="bdg yellow">要望あり</span>';
			if ( 'out' === $type ) {
				$lack = BV_Gantt::missing_equipment( $r );
				if ( $lack ) echo '<span class="bdg red">' . esc_html( implode( '・', $lack ) ) . 'なし</span>';
			}
			echo '</td>';

			/* その場で使う操作 */
			echo '<td class="act">';
			if ( 'out' === $type ) {
				echo '<a class="mini" href="' . esc_url( BV_Print::url( $r, 'checkin' ) ) . '" target="_blank" rel="noopener">受付表</a>';
			} elseif ( 'returned' !== $r->status ) {
				echo '<a class="mini green" href="' . esc_url( self::base( 'return' ) . '&res=' . $r->id ) . '">返却</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------- ガント ---------- */

	protected static function page_gantt() {
		self::header( '予約ガント' );
		BV_Gantt::render_with_nav(
			self::base( 'gantt' ),
			self::base( 'detail' ) . '&id={id}',
			array(
				'endpoint' => add_query_arg( 'gantt_action', '1', self::base() ),
				'token'    => self::auth_hash(),
				'new_url'  => self::base( 'add' ),
				'locations'=> self::$scope ? self::$scope['locations'] : array(),
				'stores'   => self::$scope ? self::$scope['stores'] : array(),
			)
		);
		self::footer();
	}

	/* ---------- 予約一覧 ---------- */

	protected static function page_list() {
		self::header( '予約一覧' );
		$from = sanitize_text_field( wp_unslash( $_GET['from'] ?? current_time( 'Y-m-d' ) ) );
		$to   = sanitize_text_field( wp_unslash( $_GET['to'] ?? date( 'Y-m-d', current_time( 'timestamp' ) + 14 * DAY_IN_SECONDS ) ) );
		$list = self::filter_reservations( BV_DB::get_reservations( array( 'from' => $from, 'to' => $to, 'exclude_cancelled' => true ) ) );
		$locations = BV_Util::locations(); $stores = BV_Util::stores(); $statuses = BV_Util::statuses();
		echo '<div class="card"><form method="get">';
		echo '<input type="hidden" name="' . esc_attr( self::query_key() ) . '" value="1"><input type="hidden" name="view" value="list">';
		echo '<label>貸出日</label><div style="display:flex;gap:8px"><input type="date" name="from" value="' . esc_attr( $from ) . '"><input type="date" name="to" value="' . esc_attr( $to ) . '"></div><button>表示</button></form></div>';
		echo '<table><thead><tr><th>貸出</th><th>場所</th><th>車両</th><th>名前</th><th></th></tr></thead><tbody>';
		foreach ( $list as $r ) {
			$v = $r->vehicle_id ? BV_DB::get_vehicle( $r->vehicle_id ) : null;
			$loc = $v ? BV_Util::label( $locations, $v->location ) : BV_Util::label( $stores, $r->store );
			echo '<tr>';
			echo '<td><strong>' . esc_html( date( 'n/j H:i', strtotime( $r->pickup_dt ) ) ) . '</strong><br><span class="note">' . esc_html( BV_Util::label( $statuses, $r->status ) ) . '</span></td>';
			echo '<td>' . esc_html( $loc ) . '</td>';
			echo '<td>' . esc_html( $v ? $v->name : '未割当' ) . '</td>';
			echo '<td>' . esc_html( $r->sei . ' ' . $r->mei );
			if ( 'none' !== $r->shuttle ) {
				$sh_st = $r->shuttle_status ?: 'requested';
				$bg = ( 'paid' === $sh_st ) ? '#00a32a' : ( ( 'quoted' === $sh_st ) ? '#dba617' : '#b32d2e' );
				echo '<br><span style="background:' . esc_attr( $bg ) . ';color:#fff;font-size:10px;padding:1px 5px;border-radius:3px">送迎' . esc_html( 'paid' === $sh_st ? '確定' : ( 'quoted' === $sh_st ? '支払待' : '未承認' ) ) . '</span>';
			}
			echo '</td>';
			echo '<td><a class="btn" style="padding:8px 12px;font-size:13px" href="' . esc_url( self::base( 'detail' ) . '&id=' . $r->id ) . '">詳細</a></td>';
			echo '</tr>';
		}
		if ( ! $list ) echo '<tr><td colspan="5">該当なし</td></tr>';
		echo '</tbody></table>';
		self::footer();
	}

	/* ---------- 予約詳細（確認・編集・印刷） ---------- */

	protected static function page_detail() {
		$id = (int) ( $_GET['id'] ?? 0 );
		$r = BV_DB::get_reservation( $id );
		if ( ! $r || ! self::in_scope( $r ) ) { self::header( '予約詳細' ); echo '<p class="warn">予約が見つかりません。</p>'; self::footer(); return; }

		/* 現金・振込の受領 */
		if ( isset( $_POST['bv_staff_cash'] ) && check_admin_referer( 'bv_staff_cash_' . $id ) ) {
			$P = wp_unslash( $_POST );
			if ( $r->paid_at ) {
				$msg = 'この予約はすでに支払済みです。'; $err = true;
			} else {
				$pm_label = BV_Util::is_offline_payment( $r )
					? BV_Util::label( BV_Util::payment_methods(), $r->payment_method )
					: '店頭・その他';
				$memo = trim( (string) $r->admin_memo );
				$memo .= ( $memo ? "\n" : '' ) . '[' . $pm_label . 'で受領 ' . current_time( 'Y-m-d H:i' ) . '（スタッフポータル）] '
					. BV_Util::money( (int) $r->price_total )
					. ( ! empty( $P['cash_note'] ) ? '（' . sanitize_text_field( $P['cash_note'] ) . '）' : '' );
				BV_DB::update_reservation( $id, array(
					'paid_at'    => current_time( 'mysql' ),
					'status'     => ( 'pending' === $r->status ) ? 'confirmed' : $r->status,
					'admin_memo' => $memo,
				) );
				$r = BV_DB::get_reservation( $id );
				if ( ! empty( $P['notify_customer'] ) && $r->email ) BV_Mailer::send_paid( $r );
				$msg = BV_Util::money( (int) $r->price_total ) . 'を受領し、予約を確定にしました。'; $err = false;
			}
			self::header( '予約詳細' );
			echo '<p class="' . ( $err ? 'warn' : 'ok' ) . '">' . esc_html( $msg ) . '</p>';
			echo '<a class="btn" href="' . esc_url( self::base( 'detail' ) . '&id=' . $id ) . '">予約詳細に戻る</a>';
			self::footer();
			return;
		}

		/* 送迎リクエストへの対応（承認・お断り・リンク再送・現地払い確定） */
		if ( isset( $_POST['bv_staff_shuttle'] ) && check_admin_referer( 'bv_staff_shuttle_' . $id ) ) {
			$P  = wp_unslash( $_POST );
			$do = sanitize_key( $P['bv_staff_shuttle'] );
			$msg = ''; $err = false;

			$parts = self::shuttle_fee_input( $r, $P );

			if ( 'approve' === $do ) {
				if ( is_wp_error( $parts ) ) {
					$msg = $parts->get_error_message(); $err = true;
				} elseif ( $parts['total'] < 1 ) {
					$msg = '0円では決済リンクを作成できません。無料の場合は「対応済み・入金済みとして確定する」をお使いください。'; $err = true;
				} else {
					$fee = $parts['total'];
					BV_DB::update_reservation( $id, array(
						'shuttle_fee'         => $fee,
						'shuttle_fee_pickup'  => $parts['pickup'],
						'shuttle_fee_dropoff' => $parts['dropoff'],
						'shuttle_status'      => 'quoted',
					) );
					$r = BV_DB::get_reservation( $id );
					$link = BV_Square::create_shuttle_payment_link( $r );
					if ( is_wp_error( $link ) ) {
						$msg = '決済リンクの生成に失敗しました：' . $link->get_error_message(); $err = true;
					} else {
						BV_DB::update_reservation( $id, array( 'shuttle_link' => $link['url'], 'shuttle_order_id' => $link['order_id'] ) );
						$r = BV_DB::get_reservation( $id );
						BV_Mailer::send_shuttle_quote( $r );
							$msg = '送迎を承認し、' . BV_Util::shuttle_fee_text( $r ) . 'の決済リンクをお客様へ送信しました。';
					}
				}
			} elseif ( 'resend' === $do ) {
				if ( $r->shuttle_link ) {
					BV_Mailer::send_shuttle_quote( $r );
					$msg = '決済リンクを再送しました。';
				} else {
					$msg = '決済リンクがまだ発行されていません。'; $err = true;
				}
			} elseif ( 'decline' === $do ) {
				BV_DB::update_reservation( $id, array( 'shuttle_status' => 'declined' ) );
				$r = BV_DB::get_reservation( $id );
				BV_Mailer::send_shuttle_declined( $r );
				$msg = '送迎不可としてお客様へご連絡しました。';
			} elseif ( 'mark_paid' === $do ) {
				/* 決済リンクを使わずに確定する（現地払い・銀行振込・無料サービスなど） */
				if ( is_wp_error( $parts ) ) {
					/* 未選択なら、すでに登録済みの内訳をそのまま使う */
					$parts = BV_Util::shuttle_fee_parts( $r );
				}
				$fee = $parts['total'];
				$upd = array(
					'shuttle_fee'         => $fee,
					'shuttle_fee_pickup'  => $parts['pickup'],
					'shuttle_fee_dropoff' => $parts['dropoff'],
					'shuttle_status'      => 'paid',
					'shuttle_paid_at'     => current_time( 'mysql' ),
				);
				$memo = trim( (string) $r->admin_memo );
				$memo .= ( $memo ? "\n" : '' ) . '[送迎を手動で確定 ' . current_time( 'Y-m-d H:i' ) . '] '
					. BV_Util::money( $fee ) . '（' . sanitize_text_field( $P['paid_note'] ?? '別途受領' ) . '）';
				$upd['admin_memo'] = $memo;
				BV_DB::update_reservation( $id, $upd );
				$r = BV_DB::get_reservation( $id );
				$notify = ! empty( $P['notify_customer'] );
				if ( $notify ) BV_Mailer::send_shuttle_confirmed( $r );
				$msg = '送迎を確定にしました（' . BV_Util::shuttle_fee_text( $r ) . '）。'
					. ( $notify ? 'お客様へ確定メールを送信しました。' : 'お客様へのメールは送信していません。' );
			} elseif ( 'reopen' === $do ) {
				/* 誤操作の取り消し。Squareで実際に決済済みのものは戻せない */
				if ( $r->shuttle_order_id && $r->shuttle_paid_at ) {
					$msg = 'Squareでお支払い済みの送迎は、この画面から戻せません。管理画面で返金処理を行ってください。'; $err = true;
				} else {
					BV_DB::update_reservation( $id, array(
						'shuttle_status'  => 'requested',
						'shuttle_paid_at' => null,
					) );
					$r = BV_DB::get_reservation( $id );
					$msg = '送迎リクエストを「回答待ち」に戻しました。お客様へのメールは送信していません。';
				}
			}
			if ( $msg ) {
				self::header( '予約詳細' );
				echo '<p class="' . ( $err ? 'warn' : 'ok' ) . '">' . esc_html( $msg ) . '</p>';
				echo '<a class="btn" href="' . esc_url( self::base( 'detail' ) . '&id=' . $id ) . '">予約詳細に戻る</a>';
				self::footer();
				return;
			}
		}

		/* 簡易編集保存 */
		if ( isset( $_POST['bv_staff_save'] ) && check_admin_referer( 'bv_staff_save_' . $id ) ) {
			$P = wp_unslash( $_POST );
			$prev_status = $r->status;
			$prev_shuttle = $r->shuttle;
			$prev_sh_status = $r->shuttle_status ?: 'none';
			$upd = array(
				'status'     => sanitize_key( $P['status'] ),
				'vehicle_id' => (int) $P['vehicle_id'],
				'pickup_dt'  => sanitize_text_field( $P['pickup_date'] ) . ' ' . sanitize_text_field( $P['pickup_time'] ) . ':00',
				'return_dt'  => sanitize_text_field( $P['return_date'] ) . ' ' . sanitize_text_field( $P['return_time'] ) . ':00',
				'sei'        => sanitize_text_field( $P['sei'] ?? '' ),
				'mei'        => sanitize_text_field( $P['mei'] ?? '' ),
				'phone'      => sanitize_text_field( $P['phone'] ),
				'email'      => sanitize_email( $P['email'] ?? '' ),
				'address'    => sanitize_textarea_field( $P['address'] ?? '' ),
				'coverage'   => strtoupper( sanitize_text_field( $P['coverage'] ?? $r->coverage ) ),

				'is_student' => ! empty( $P['is_student'] ) ? 1 : 0,
				'manual_discount' => isset( $P['manual_discount'] ) ? (int) $P['manual_discount'] : (int) $r->manual_discount,
				'manual_discount_note' => isset( $P['manual_discount_note'] ) ? sanitize_text_field( $P['manual_discount_note'] ) : $r->manual_discount_note,
				'request_note' => sanitize_textarea_field( $P['request_note'] ?? $r->request_note ),
				'admin_memo' => sanitize_textarea_field( $P['admin_memo'] ),
			);
			foreach ( BV_Util::equipment_keys() as $ek ) {
				$upd[ 'opt_' . $ek ] = isset( $P[ 'opt_' . $ek ] ) ? (int) $P[ 'opt_' . $ek ] : ( isset( $r->{ 'opt_' . $ek } ) ? (int) $r->{ 'opt_' . $ek } : 0 );
			}

			/* 店舗限定ポータルでは範囲外の車両を割り当てない */
			if ( (int) $upd['vehicle_id'] > 0 && ! self::vehicle_in_scope( BV_DB::get_vehicle( (int) $upd['vehicle_id'] ) ) ) {
				$upd['vehicle_id'] = (int) $r->vehicle_id;
			}

			/* 車両を変更したら予約の車両クラスも合わせる */
			$class_changed = '';
			if ( (int) $upd['vehicle_id'] > 0 ) {
				$sv = BV_DB::get_vehicle( (int) $upd['vehicle_id'] );
				if ( $sv && $sv->class !== $r->vehicle_class ) {
					$upd['vehicle_class'] = $sv->class;
					$cl = BV_Util::classes();
					$class_changed = '車両クラスを「' . BV_Util::label( $cl, $r->vehicle_class ) . '」→「' . BV_Util::label( $cl, $sv->class ) . '」に変更しました。';
				}
			}

			/* 料金の再計算（チェックされている場合） */
			if ( ! empty( $P['recalc'] ) ) {
				$q = BV_Pricing::quote( array(
					'vehicle_class' => $upd['vehicle_class'] ?? $r->vehicle_class,
					'pickup_dt' => $upd['pickup_dt'], 'return_dt' => $upd['return_dt'],
					'opt_child_seat' => $upd['opt_child_seat'], 'opt_junior_seat' => $upd['opt_junior_seat'],
					'opt_ski_rack' => $upd['opt_ski_rack'], 'opt_navi' => $upd['opt_navi'], 'opt_etc' => $upd['opt_etc'],
					'coverage' => $upd['coverage'], 'shuttle' => $r->shuttle,
					'is_student' => $upd['is_student'], 'coupon_code' => $r->coupon_code, 'lang' => $r->lang,
					'manual_discount' => $upd['manual_discount'], 'manual_discount_note' => $upd['manual_discount_note'],
					'store' => $r->store,
				) );
				if ( ! is_wp_error( $q ) ) {
					$upd['price_breakdown'] = wp_json_encode( $q );
					$upd['price_total'] = (int) $q['total'];
				}
			}
			/* 送迎（支払済みの場合は変更させない） */
			$shuttle_changed = false;
			if ( 'paid' !== $prev_sh_status && isset( $P['shuttle'] ) ) {
				$sh = sanitize_key( $P['shuttle'] );
				if ( ! isset( BV_Util::shuttles()[ $sh ] ) ) $sh = 'none';
				$upd['shuttle'] = $sh;
				$upd['shuttle_detail'] = sanitize_textarea_field( $P['shuttle_detail'] ?? '' );
				if ( 'none' === $sh ) {
					$upd['shuttle_status'] = 'none';
					$upd['shuttle_fee'] = 0; $upd['shuttle_fee_pickup'] = 0; $upd['shuttle_fee_dropoff'] = 0; $upd['shuttle_link'] = ''; $upd['shuttle_order_id'] = '';
				} elseif ( 'none' === $prev_shuttle || 'none' === $prev_sh_status || 'declined' === $prev_sh_status ) {
					$upd['shuttle_status'] = 'requested';
					$upd['shuttle_fee'] = 0; $upd['shuttle_fee_pickup'] = 0; $upd['shuttle_fee_dropoff'] = 0; $upd['shuttle_link'] = ''; $upd['shuttle_order_id'] = '';
					$shuttle_changed = true;
				}
			}
			BV_DB::update_reservation( $id, $upd );
			$r = BV_DB::get_reservation( $id );
			if ( 'cancelled' === $r->status && 'cancelled' !== $prev_status ) {
				BV_Mailer::send_cancelled_admin( $r, 'staff' );
			}
			if ( $shuttle_changed ) BV_Mailer::send_shuttle_request_admin( $r );
		}

		/* キャンセル処理 */
		$cancel_msg = '';
		if ( isset( $_POST['bv_staff_cancel'] ) && check_admin_referer( 'bv_staff_cancel_' . $id ) ) {
			if ( in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) {
				$cancel_msg = '<p class="warn">この予約はキャンセルできません（すでにキャンセル済み、または返却済みです）。</p>';
			} else {
				/* キャンセルポリシーを適用し、返金が必要なら自動で処理する */
				$noshow = ! empty( $_POST['noshow'] );
				$res = BV_Members::do_cancel( $r, 'ja', 'staff', $noshow );
				$r = BV_DB::get_reservation( $id );
				$c = $res['charge'];
				$cancel_msg = '<p class="ok">' . ( $noshow ? '無断キャンセルとして処理しました。' : '予約をキャンセルしました。' )
					. '<br>キャンセル料 ' . (int) $c['pct'] . '%（' . esc_html( BV_Util::money( (int) $c['fee'] ) ) . '）';
				if ( $res['refunded'] > 0 ) {
					$cancel_msg .= '<br>返金 ' . esc_html( BV_Util::money( (int) $res['refunded'] ) ) . ' を自動処理しました。';
				} elseif ( $c['refund'] > 0 ) {
					$cancel_msg .= '<br><strong>返金 ' . esc_html( BV_Util::money( (int) $c['refund'] ) ) . ' が必要です。管理画面から手続きしてください。</strong>';
				}
				$cancel_msg .= '<br>お客様へキャンセル確認メールを送信しました。</p>';
			}
		}

		self::header( '予約詳細 ' . $r->code );
		if ( isset( $_POST['bv_staff_save'] ) ) {
			echo '<p class="ok">保存しました。' . ( ! empty( $class_changed ) ? '<br>' . esc_html( $class_changed ) . '料金が変わる場合は「保存時に料金を再計算する」にチェックして再保存してください。' : '' ) . '</p>';
		}
		echo $cancel_msg;
		$statuses = BV_Util::statuses(); $classes = BV_Util::classes(); $stores = self::stores_for_select();
		$vehicles = self::filter_vehicles( BV_DB::get_vehicles() );

		echo '<div class="card"><table>';
		$ps_sp = BV_Util::payment_state( $r );
		echo '<tr><th>ステータス</th><td>' . esc_html( BV_Util::label( $statuses, $r->status ) )
			. '　<strong style="color:' . esc_attr( $ps_sp['color'] ) . '">' . esc_html( $ps_sp['text'] ) . '</strong>';
		if ( '' !== $ps_sp['sub'] ) echo '<br><span class="note" style="color:#2271b1">' . esc_html( $ps_sp['sub'] ) . '</span>';
		echo '</td></tr>';
		echo '<tr><th>店舗/クラス</th><td>' . esc_html( BV_Util::label( $stores, $r->store ) . ' / ' . BV_Util::label( $classes, $r->vehicle_class ) ) . '</td></tr>';
		$who = trim( $r->sei . ' ' . $r->mei );
		echo '<tr><th>氏名</th><td>' . ( $who ? esc_html( $who ) : '<span style="color:#b32d2e">（未入力）</span>' ) . '　<span class="note">' . esc_html( 'en' === $r->lang ? '英語' : '日本語' ) . '</span></td></tr>';
		echo '<tr><th>連絡先</th><td>';
		if ( $r->phone ) echo '<a href="tel:' . esc_attr( $r->phone ) . '">' . esc_html( $r->phone ) . '</a>';
		if ( $r->email ) echo ( $r->phone ? '<br>' : '' ) . '<a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a>';
		if ( ! $r->phone && ! $r->email ) echo '<span style="color:#b32d2e">（未入力）</span>';
		if ( $r->address ) echo '<br><span class="note">' . nl2br( esc_html( $r->address ) ) . '</span>';
		echo '</td></tr>';
		$opts_list = array();
		foreach ( BV_Util::equipment() as $ek => $ev ) {
			$n = isset( $r->{ 'opt_' . $ek } ) ? (int) $r->{ 'opt_' . $ek } : 0;
			if ( $n > 0 ) $opts_list[] = $ev['ja'] . '×' . $n;
		}
		echo '<tr><th>補償/オプション</th><td>' . esc_html( BV_Util::label( BV_Util::coverages(), $r->coverage ) );
		if ( $opts_list ) echo '<br><span class="note">' . esc_html( implode( '／', $opts_list ) ) . '</span>';
		if ( $r->is_student ) echo '<br><span class="note">学割適用</span>';
		echo '</td></tr>';
		echo '<tr><th>送迎</th><td>' . esc_html( BV_Util::label( BV_Util::shuttles(), $r->shuttle ) );
		if ( 'none' !== $r->shuttle ) {
			$sh_col = array( 'requested' => '#b32d2e', 'quoted' => '#997404', 'paid' => '#00a32a', 'declined' => '#666' );
			$sh_st = $r->shuttle_status ?: 'requested';
			echo '<br><strong style="color:' . esc_attr( $sh_col[ $sh_st ] ?? '#666' ) . '">' . esc_html( BV_Util::label( BV_Util::shuttle_statuses(), $sh_st ) ) . '</strong>';
			if ( $r->shuttle_fee ) echo '　' . esc_html( BV_Util::shuttle_fee_text( $r ) );
			if ( $r->shuttle_detail ) echo '<br><span class="note">' . esc_html( $r->shuttle_detail ) . '</span>';
		}
		echo '</td></tr>';
		echo '<tr><th>合計</th><td>' . esc_html( BV_Util::money( $r->price_total ) ) . '</td></tr>';
		echo '<tr><th>要望</th><td>' . esc_html( $r->request_note ) . '</td></tr>';
		echo '</table></div>';

		/* ---- お支払いの受領（現金・振込・店頭端末など） ---- */
		if ( ! $r->paid_at && 'cancelled' !== $r->status ) {
			$offline = BV_Util::is_offline_payment( $r );
			echo '<div class="card" style="border-left:5px solid #2271b1">';
			echo '<h3 style="margin-top:0">お支払いの受領' . ( $offline ? '（' . esc_html( BV_Util::label( BV_Util::payment_methods(), $r->payment_method ) ) . '）' : '' ) . '</h3>';
			echo '<p style="margin:0 0 4px;font-size:20px;font-weight:700">' . esc_html( BV_Util::money( (int) $r->price_total ) ) . '</p>';
			echo '<p class="note" style="margin:0 0 10px">' . ( $offline
				? 'この予約はオンライン決済を使いません。受領したら下のボタンを押してください。自動キャンセルの対象外です。'
				: '店頭のSquare端末や現金など、この画面の外でお支払いいただいた場合はこちらで記録してください。予約が確定になります。' ) . '</p>';
			echo '<form method="post" onsubmit="return confirm(\'受領済みとして予約を確定にします。よろしいですか？\')">';
			wp_nonce_field( 'bv_staff_cash_' . $id );
			echo '<input type="text" name="cash_note" placeholder="メモ（例：店頭端末で決済、現金受領）">';
			echo '<label style="font-weight:normal"><input type="checkbox" name="notify_customer" value="1"> お客様に確定メールを送る</label>';
			echo '<button name="bv_staff_cash" value="1" style="background:#00a32a;width:100%;margin-top:8px">受領して予約を確定にする</button>';
			echo '</form></div>';
		}

		/* ---- 送迎リクエストへの対応 ---- */
		if ( 'none' !== $r->shuttle ) {
			$sh_st = $r->shuttle_status ?: 'requested';
			echo '<div class="card"' . ( in_array( $sh_st, array( 'requested', 'quoted' ), true ) ? ' style="border-left:5px solid #d63638"' : '' ) . '>';
			echo '<h3 style="margin-top:0">送迎の対応</h3>';
			echo '<p style="margin:0 0 8px;font-size:13px">' . esc_html( BV_Util::label( BV_Util::shuttles(), $r->shuttle ) )
				. '／<strong>' . esc_html( BV_Util::label( BV_Util::shuttle_statuses(), $sh_st ) ) . '</strong></p>';
			if ( $r->shuttle_detail ) echo '<p class="note" style="margin:0 0 10px">場所・希望：' . esc_html( $r->shuttle_detail ) . '</p>';

			if ( 'paid' === $sh_st || 'declined' === $sh_st ) {
				if ( 'paid' === $sh_st ) {
					$how = ( $r->shuttle_order_id && $r->shuttle_paid_at ) ? 'Squareでお支払い済み' : '手動で確定（現地払い等）';
					echo '<p class="ok" style="margin:0 0 10px">送迎確定済みです（' . esc_html( BV_Util::shuttle_fee_text( $r ) ) . '／' . esc_html( $how ) . '）。</p>';
				} else {
					echo '<p class="note" style="margin:0 0 10px">送迎不可としてご連絡済みです。</p>';
				}
				/* Square決済済み以外は、誤操作を取り消せるようにしておく */
				if ( ! ( $r->shuttle_order_id && $r->shuttle_paid_at ) ) {
					echo '<form method="post" onsubmit="return confirm(\'送迎リクエストを「回答待ち」に戻します。お客様へのメールは送りません。よろしいですか？\')">';
					wp_nonce_field( 'bv_staff_shuttle_' . $id );
					echo '<button name="bv_staff_shuttle" value="reopen" style="background:#646970">回答待ちに戻す（取り消し）</button></form>';
				}
			} else {
				if ( 'quoted' === $sh_st ) {
					echo '<p class="note" style="margin:0 0 10px">' . esc_html( BV_Util::shuttle_fee_text( $r ) ) . 'の決済リンクを送信済みです。お支払い待ちです。</p>';
				}
				$cur = BV_Util::shuttle_fee_parts( $r );

				/* 料金の選択（承認・手動確定で共用する） */
				echo '<form method="post">';
				wp_nonce_field( 'bv_staff_shuttle_' . $id );

				$fee_select = function ( $name, $label, $cur_val ) {
					echo '<label>' . esc_html( $label ) . '</label>';
					echo '<select name="' . esc_attr( $name ) . '" class="bv-fee">';
					echo '<option value="">選択してください</option>';
					foreach ( BV_Util::shuttle_fees() as $fee ) {
						echo '<option value="' . (int) $fee . '"' . selected( (int) $cur_val, (int) $fee, false ) . '>' . esc_html( BV_Util::money( $fee ) ) . '</option>';
					}
					echo '<option value="0"' . selected( 0, (int) $cur_val, false ) . '>0円（無料・サービス）</option>';
					echo '</select>';
				};

				if ( 'round' === $r->shuttle ) {
					echo '<p class="note" style="margin:0 0 6px">往復は行き・帰りで別々の料金を設定できます。</p>';
					$fee_select( 'fee_pickup', '送迎料金：お迎え（行き）', $cur['pickup'] );
					$fee_select( 'fee_dropoff', '送迎料金：お送り（帰り）', $cur['dropoff'] );
					echo '<p id="bv-fee-total" style="margin:-4px 0 12px;font-weight:600;font-size:14px"></p>';
					echo '<script>(function(){'
						. 'var f=document.querySelectorAll(".bv-fee"),o=document.getElementById("bv-fee-total");'
						. 'function t(){var s=0,n=0;f.forEach(function(x){if(x.value!==""){s+=parseInt(x.value,10);n++;}});'
						. 'o.textContent=n?("合計 "+s.toLocaleString()+"円"):"";}'
						. 'f.forEach(function(x){x.addEventListener("change",t);});t();})();</script>';
				} elseif ( BV_Util::shuttle_has_pickup( $r->shuttle ) ) {
					$fee_select( 'fee_pickup', '送迎料金（お迎え・片道）', $cur['pickup'] );
				} else {
					$fee_select( 'fee_dropoff', '送迎料金（お送り・片道）', $cur['dropoff'] );
				}

				echo '<button name="bv_staff_shuttle" value="approve" style="background:#00a32a;width:100%">承認して決済リンクを送る</button>';

				echo '<div style="border-top:1px solid #e2e4e7;margin:14px 0 0;padding-top:12px">';
				echo '<p style="margin:0 0 6px;font-weight:600;font-size:13px">決済リンクを使わずに確定する</p>';
				echo '<p class="note" style="margin:0 0 8px">現金でお受け取り済み、銀行振込済み、電話で手配済み、無料サービスの場合はこちらです。上で選んだ料金が記録されます（未選択なら現在の登録額のまま）。</p>';
				echo '<input type="text" name="paid_note" placeholder="メモ（例：現金受領、振込確認済み）" value="">';
				echo '<label style="font-weight:normal"><input type="checkbox" name="notify_customer" value="1" checked> お客様に送迎確定メールを送る</label>';
				echo '<button name="bv_staff_shuttle" value="mark_paid" style="background:#2271b1;width:100%;margin-top:8px" onclick="return confirm(\'この送迎を確定にします。よろしいですか？\')">対応済み・入金済みとして確定する</button>';
				echo '</div>';
				echo '</form>';

				echo '<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">';
				if ( 'quoted' === $sh_st && $r->shuttle_link ) {
					echo '<form method="post" style="flex:1;min-width:130px">';
					wp_nonce_field( 'bv_staff_shuttle_' . $id );
					echo '<button name="bv_staff_shuttle" value="resend" style="background:#646970;width:100%">リンクを再送</button></form>';
				}
				echo '<form method="post" style="flex:1;min-width:130px" onsubmit="return confirm(\'送迎不可としてお客様へご連絡します。よろしいですか？\')">';
				wp_nonce_field( 'bv_staff_shuttle_' . $id );
				echo '<button name="bv_staff_shuttle" value="decline" style="background:#b32d2e;width:100%">お断りの連絡をする</button></form>';
				echo '</div>';
				echo '<p class="note" style="margin:10px 0 0">送迎料金は車両料金とは別のお支払いです。決済リンクからお支払いが完了すると自動で「送迎確定」になり、確定メールが届きます。</p>';
			}
			echo '</div>';
		}

		echo '<div class="card"><h3 style="margin-top:0">編集</h3><form method="post">';
		wp_nonce_field( 'bv_staff_save_' . $id );
		echo '<input type="hidden" name="bv_staff_save" value="1">';
		echo '<label>ステータス</label><select name="status">';
		foreach ( $statuses as $k => $v2 ) echo '<option value="' . $k . '"' . selected( $r->status, $k, false ) . '>' . esc_html( $v2['ja'] ) . '</option>';
		echo '</select>';
		echo '<label>車両</label><select name="vehicle_id"><option value="0">未割当</option>';
		foreach ( $vehicles as $v2 ) echo '<option value="' . (int) $v2->id . '"' . selected( (int) $r->vehicle_id, (int) $v2->id, false ) . '>' . esc_html( $v2->name ) . '</option>';
		echo '</select>';
		echo '<label>貸出</label><div style="display:flex;gap:8px"><input type="date" name="pickup_date" value="' . esc_attr( date( 'Y-m-d', strtotime( $r->pickup_dt ) ) ) . '"><input type="time" step="1800" name="pickup_time" value="' . esc_attr( date( 'H:i', strtotime( $r->pickup_dt ) ) ) . '"></div>';
		echo '<label>返却</label><div style="display:flex;gap:8px"><input type="date" name="return_date" value="' . esc_attr( date( 'Y-m-d', strtotime( $r->return_dt ) ) ) . '"><input type="time" step="1800" name="return_time" value="' . esc_attr( date( 'H:i', strtotime( $r->return_dt ) ) ) . '"></div>';
		/* お客様情報 */
		echo '<h4 style="margin:18px 0 4px;font-size:14px">お客様情報</h4>';
		echo '<div style="display:flex;gap:8px"><div style="flex:1"><label>姓</label><input type="text" name="sei" value="' . esc_attr( $r->sei ) . '"></div>';
		echo '<div style="flex:1"><label>名</label><input type="text" name="mei" value="' . esc_attr( $r->mei ) . '"></div></div>';
		echo '<label>電話</label><input type="tel" name="phone" value="' . esc_attr( $r->phone ) . '">';
		echo '<label>メール</label><input type="email" name="email" value="' . esc_attr( $r->email ) . '">';
		echo '<label>住所</label><textarea name="address" rows="2">' . esc_textarea( $r->address ) . '</textarea>';

		/* 料金に関わる項目 */
		echo '<h4 style="margin:18px 0 4px;font-size:14px">オプション・料金</h4>';
		echo '<label>補償</label><select name="coverage">';
		foreach ( BV_Util::coverages() as $ck => $cv ) {
			echo '<option value="' . esc_attr( $ck ) . '"' . selected( $r->coverage, $ck, false ) . '>' . esc_html( $cv['ja'] ) . '</option>';
		}
		echo '</select>';
		echo '<label>装備オプション</label>';
		foreach ( BV_Util::equipment() as $ek => $ev ) {
			$cur = isset( $r->{ 'opt_' . $ek } ) ? (int) $r->{ 'opt_' . $ek } : 0;
			echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">';
			echo '<div style="flex:1;font-size:13px">' . esc_html( $ev['ja'] );
			if ( ! empty( $ev['note_ja'] ) ) echo '<br><span class="note">' . esc_html( $ev['note_ja'] ) . '</span>';
			echo '</div><select name="opt_' . esc_attr( $ek ) . '" style="width:80px;margin:0">';
			for ( $i = 0; $i <= (int) $ev['max']; $i++ ) echo '<option value="' . $i . '"' . selected( $cur, $i, false ) . '>' . $i . '</option>';
			echo '</select></div>';
		}
		echo '<div style="margin-bottom:12px"></div>';
		echo '<p style="margin:0 0 6px"><label style="font-weight:normal"><input type="checkbox" name="is_student" value="1"' . checked( (int) $r->is_student, 1, false ) . '> 学割を適用する</label></p>';
		echo '<label>特別値引き（円）</label><input type="number" name="manual_discount" step="100" value="' . (int) $r->manual_discount . '">';
		echo '<input type="text" name="manual_discount_note" value="' . esc_attr( $r->manual_discount_note ) . '" placeholder="理由（例：リピーター割引）">';
		echo '<p class="note" style="margin:-6px 0 12px">プラス＝値引き、マイナス＝追加請求。下の「料金を再計算する」にチェックを入れると反映されます。</p>';
		echo '<p style="margin:0 0 12px"><label style="font-weight:normal"><input type="checkbox" name="recalc" value="1"> 保存時に料金を再計算する（現在：' . esc_html( BV_Util::money( $r->price_total ) ) . '）</label></p>';
		echo '<label>ご要望</label><textarea name="request_note" rows="2">' . esc_textarea( $r->request_note ) . '</textarea>';

		if ( 'paid' !== $r->shuttle_status ) {
			echo '<label>送迎</label><select name="shuttle" id="bv-ed-shuttle">';
			foreach ( BV_Util::shuttles() as $k => $v2 ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $r->shuttle, $k, false ) . '>' . esc_html( $v2['ja'] ) . '</option>';
			echo '</select>';
			echo '<div id="bv-ed-shdet" style="display:none"><label>送迎の詳細場所</label><textarea name="shuttle_detail" rows="2">' . esc_textarea( $r->shuttle_detail ) . '</textarea></div>';
			echo '<script>(function(){var s=document.getElementById("bv-ed-shuttle"),d=document.getElementById("bv-ed-shdet");function t(){d.style.display=(s.value!=="none")?"block":"none";}s.addEventListener("change",t);t();})();</script>';
		}
		echo '<label>メモ</label><textarea name="admin_memo" rows="3">' . esc_textarea( $r->admin_memo ) . '</textarea>';
		echo '<button>保存する</button></form></div>';

		echo '<div class="card"><h3 style="margin-top:0">操作</h3>';
		echo '<a class="btn green" href="' . esc_url( self::base( 'return' ) . '&res=' . $r->id ) . '">この予約の返却処理</a> ';
		echo '<a class="btn gray" target="_blank" href="' . esc_url( BV_Print::url( $r, 'voucher' ) ) . '">予約票印刷</a> ';
		echo '<a class="btn gray" target="_blank" href="' . esc_url( BV_Print::url( $r, 'checkin' ) ) . '">受付表印刷</a> ';
		echo '<a class="btn gray" target="_blank" href="' . esc_url( BV_Print::url( $r, 'receipt' ) ) . '">領収書印刷</a>';
		echo '</div>';

		/* キャンセル */
		if ( ! in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) {
			echo '<div class="card"><h3 style="margin-top:0">キャンセル</h3>';
			if ( $r->paid_at ) {
				echo '<p class="warn">この予約は<strong>支払済み</strong>です（' . esc_html( date( 'Y-m-d H:i', strtotime( $r->paid_at ) ) ) . '）。キャンセル後、返金が必要な場合は管理画面から手続きしてください。</p>';
			}
			echo '<form method="post" onsubmit="return confirm(\'予約 ' . esc_js( $r->code ) . ' をキャンセルします。よろしいですか？\')">';
			wp_nonce_field( 'bv_staff_cancel_' . $id );
			echo '<input type="hidden" name="bv_staff_cancel" value="1">';
			$cc2 = BV_Util::cancel_charge( $r );
			$nc2 = BV_Util::cancel_charge( $r, true );
			echo '<div class="warn" style="margin-bottom:10px;font-size:13px">';
			echo '適用区分：<strong>' . esc_html( $cc2['label_ja'] ) . '</strong><br>';
			echo 'キャンセル料 ' . (int) $cc2['pct'] . '%＝<strong>' . esc_html( BV_Util::money( (int) $cc2['fee'] ) ) . '</strong>';
			if ( $r->paid_at ) {
				echo '<br>返金額：<strong>' . esc_html( BV_Util::money( (int) $cc2['refund'] ) ) . '</strong>（Square決済なら自動返金）';
			} else {
				echo '<br>未入金のため返金はありません。';
			}
			echo '</div>';
			echo '<p style="margin:0 0 10px"><label style="font-weight:normal"><input type="checkbox" name="noshow" value="1"> 無断キャンセル（No-show）として処理する（キャンセル料 ' . (int) $nc2['pct'] . '%＝' . esc_html( BV_Util::money( (int) $nc2['fee'] ) ) . '）</label></p>';
			if ( ! $r->email ) {
				echo '<p class="note">メールアドレスの登録がないため、お客様への通知メールは送信されません。</p>';
			}
			echo '<button class="red" style="background:#b32d2e">この予約をキャンセルする</button></form>';
			echo '<p class="note">キャンセルポリシーに沿ってキャンセル料を計算し、返金が必要な場合は自動で処理します。お客様と管理者の両方にメールが届きます。</p>';
			echo '</div>';
		} elseif ( 'cancelled' === $r->status ) {
			echo '<div class="card"><p class="warn">この予約はキャンセル済みです。</p></div>';
		}
		self::footer();
	}

	/* ---------- 予約追加 ---------- */

	protected static function page_add() {
		$msg = '';
		if ( isset( $_POST['bv_staff_add'] ) && check_admin_referer( 'bv_staff_add' ) && in_array( sanitize_key( $_POST['store'] ?? '' ), self::scope_stores(), true ) ) {
			$P = wp_unslash( $_POST );
			$pickup = sanitize_text_field( $P['pickup_date'] ) . ' ' . sanitize_text_field( $P['pickup_time'] ) . ':00';
			$return = sanitize_text_field( $P['return_date'] ) . ' ' . sanitize_text_field( $P['return_time'] ) . ':00';
			$class  = sanitize_key( $P['vehicle_class'] );
			$shuttle = sanitize_key( $P['shuttle'] ?? 'none' );
			if ( ! isset( BV_Util::shuttles()[ $shuttle ] ) ) $shuttle = 'none';
			$shuttle_detail = sanitize_textarea_field( $P['shuttle_detail'] ?? '' );

			$q_args = array(
				'vehicle_class' => $class, 'pickup_dt' => $pickup, 'return_dt' => $return,
				'coverage' => sanitize_text_field( $P['coverage'] ), 'shuttle' => $shuttle,
				'is_student' => ! empty( $P['is_student'] ),
				'coupon_code' => sanitize_text_field( $P['coupon_code'] ?? '' ), 'lang' => 'ja',
				'manual_discount' => (int) ( $P['manual_discount'] ?? 0 ),
				'manual_discount_note' => sanitize_text_field( $P['manual_discount_note'] ?? '' ),
				'store' => sanitize_key( $P['store'] ?? '' ),
			);
			foreach ( BV_Util::equipment_keys() as $ek ) $q_args[ 'opt_' . $ek ] = (int) ( $P[ 'opt_' . $ek ] ?? 0 );
			$quote = BV_Pricing::quote( $q_args );
			if ( 'none' !== $shuttle && '' === trim( $shuttle_detail ) ) {
				$msg = '<p class="warn">送迎をご希望の場合は、送迎場所の入力が必須です。</p>';
			} elseif ( is_wp_error( $quote ) ) {
				$msg = '<p class="warn">' . esc_html( $quote->get_error_message() ) . '</p>';
			} else {
				$equip_data = array();
				foreach ( BV_Util::equipment_keys() as $ek ) $equip_data[ 'opt_' . $ek ] = (int) ( $P[ 'opt_' . $ek ] ?? 0 );
				$id = BV_DB::insert_reservation( array_merge( $equip_data, array(
					'code' => BV_Util::reservation_code(), 'status' => 'pending', 'lang' => 'ja',
					'store' => sanitize_key( $P['store'] ), 'vehicle_class' => $class,
					/* ガントから来た場合はその車両を優先して割り当てる */
					'vehicle_id' => ( ! empty( $P['pf_vehicle'] ) ? (int) $P['pf_vehicle']
						: BV_Availability::auto_assign( $class, $pickup, $return, sanitize_key( $P['store'] ), 0, BV_Availability::required_equipment( $equip_data ) ) ),
					'pickup_dt' => $pickup, 'return_dt' => $return,
					'sei' => sanitize_text_field( $P['sei'] ), 'mei' => sanitize_text_field( $P['mei'] ),
					'email' => sanitize_email( $P['email'] ), 'phone' => sanitize_text_field( $P['phone'] ),
					'coverage' => strtoupper( sanitize_text_field( $P['coverage'] ) ),
					'shuttle' => $shuttle,
					'shuttle_detail' => $shuttle_detail,
					'shuttle_status' => ( 'none' === $shuttle ) ? 'none' : 'requested',
					'coupon_code' => sanitize_text_field( $P['coupon_code'] ?? '' ),
					'manual_discount' => (int) ( $P['manual_discount'] ?? 0 ),
					'manual_discount_note' => sanitize_text_field( $P['manual_discount_note'] ?? '' ),
					'is_student' => ! empty( $P['is_student'] ) ? 1 : 0,
					'payment_method' => ( isset( BV_Util::payment_methods()[ $P['payment_method'] ?? '' ] ) ) ? sanitize_key( $P['payment_method'] ) : 'square',
					'request_note' => sanitize_textarea_field( $P['request_note'] ),
					'price_breakdown' => wp_json_encode( $quote ), 'price_total' => (int) $quote['total'],
				) ) );
				$r = BV_DB::get_reservation( $id );
				if ( 'none' !== $shuttle ) BV_Mailer::send_shuttle_request_admin( $r );
				$mail_mode = sanitize_key( $P['mail_mode'] );
				if ( 'none' !== $mail_mode && $r->email ) {
					if ( 'auto' === $mail_mode && ! BV_Util::is_offline_payment( $r ) ) {
						$link = BV_Square::create_payment_link( $r );
						if ( ! is_wp_error( $link ) && $link['url'] ) {
							BV_DB::update_reservation( $id, array( 'square_link' => $link['url'], 'square_order_id' => $link['order_id'] ) );
							$r = BV_DB::get_reservation( $id );
						}
					}
					BV_Mailer::send_provisional( $r, 'auto' );
				}
				/* ガントから来た場合は、同じ表示位置のガントへ戻る */
				$back_gantt = self::base( 'gantt' );
				if ( ! empty( $P['pf_gstart'] ) ) {
					$back_gantt = add_query_arg( array(
						'gstart' => sanitize_text_field( $P['pf_gstart'] ),
						'gdays'  => max( 7, min( 60, (int) ( $P['pf_gdays'] ?? 14 ) ) ),
					), $back_gantt );
				}

				self::header( '予約を追加' );
				echo '<p class="ok">予約 ' . esc_html( $r->code ) . ' を作成しました（' . esc_html( BV_Util::money( $r->price_total ) ) . '）。</p>';
				if ( 'none' !== $shuttle ) {
					echo '<p class="warn">送迎リクエストを受け付けました。<strong>送迎料金は別途</strong>、管理画面（レンタカー管理 → 予約一覧 → 該当予約）で料金を選んで承認し、決済リンクをお送りしてください。</p>';
				}
				if ( '' === trim( $r->sei . $r->mei ) ) {
					echo '<p class="warn">お名前が未入力です。確定前に詳細画面から入力してください。</p>';
				}
				echo '<a class="btn" href="' . esc_url( self::base( 'detail' ) . '&id=' . $id ) . '">詳細を開く</a> ';
				echo '<a class="btn gray" href="' . esc_url( $back_gantt ) . '">ガントに戻る</a>';
				self::footer();
				return;
			}
		}

		self::header( '予約を追加（電話予約など）' );
		echo $msg;
		$stores = self::stores_for_select(); $classes = BV_Util::classes(); $coverages = BV_Util::coverages();
		/* 店舗限定ポータルでは取り扱いクラスだけを出す（From P＝軽自動車のみ） */
		if ( self::$scope && 1 === count( $stores ) ) {
			$only = key( $stores );
			$classes = array_intersect_key( $classes, array_flip( BV_Util::store_classes( $only ) ) );
		}

		/* ガントからの引き継ぎ */
		$pf_from = isset( $_GET['pf_from'] ) ? sanitize_text_field( wp_unslash( $_GET['pf_from'] ) ) : '';
		$pf_to   = isset( $_GET['pf_to'] ) ? sanitize_text_field( wp_unslash( $_GET['pf_to'] ) ) : '';
		$pf_class = '';
		if ( ! empty( $_GET['pf_vehicle'] ) ) {
			$pv = BV_DB::get_vehicle( (int) $_GET['pf_vehicle'] );
			if ( $pv ) {
				$pf_class = $pv->class;
				echo '<p class="ok">ガントで選択：<strong>' . esc_html( $pv->name ) . '</strong>（' . esc_html( BV_Util::label( $classes, $pv->class ) ) . '）<br>この車両に割り当てて作成します。</p>';
			}
		}
		echo '<div class="card"><form method="post">';
		wp_nonce_field( 'bv_staff_add' );
		echo '<input type="hidden" name="bv_staff_add" value="1">';
		if ( ! empty( $_GET['pf_vehicle'] ) ) {
			echo '<input type="hidden" name="pf_vehicle" value="' . (int) $_GET['pf_vehicle'] . '">';
		}
		/* ガントの表示位置を引き継ぐ（作成後に同じ位置へ戻るため） */
		if ( ! empty( $_GET['pf_gstart'] ) ) {
			echo '<input type="hidden" name="pf_gstart" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['pf_gstart'] ) ) ) . '">';
			echo '<input type="hidden" name="pf_gdays" value="' . (int) ( $_GET['pf_gdays'] ?? 14 ) . '">';
		}
		echo '<label>店舗</label><select name="store">';
		foreach ( $stores as $k => $v ) echo '<option value="' . $k . '">' . esc_html( $v['ja'] ) . '</option>';
		echo '</select><label>車両クラス</label><select name="vehicle_class">';
		foreach ( $classes as $k => $v ) echo '<option value="' . $k . '"' . selected( $pf_class, $k, false ) . '>' . esc_html( BV_Util::class_label_with_capacity( $k ) ) . '</option>';
		echo '</select>';
		echo '<label>貸出</label><div style="display:flex;gap:8px"><input type="date" name="pickup_date" required value="' . esc_attr( $pf_from ?: current_time( 'Y-m-d' ) ) . '"><input type="time" step="1800" name="pickup_time" required value="10:00"></div>';
		echo '<label>返却</label><div style="display:flex;gap:8px"><input type="date" name="return_date" required value="' . esc_attr( $pf_to ?: date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS ) ) . '"><input type="time" step="1800" name="return_time" required value="10:00"></div>';
		echo '<p class="note" style="margin:14px 0 4px">以下はあとから入力できます（仮押さえだけしたい場合は空欄のままでも作成できます）。</p>';
		echo '<div style="display:flex;gap:8px"><div style="flex:1"><label>姓</label><input type="text" name="sei"></div><div style="flex:1"><label>名</label><input type="text" name="mei"></div></div>';
		echo '<label>電話</label><input type="tel" name="phone">';
		echo '<label>メール（任意）</label><input type="email" name="email">';
		echo '<label>補償</label><select name="coverage">';
		foreach ( $coverages as $k => $v ) echo '<option value="' . $k . '"' . selected( 'C', $k, false ) . '>' . esc_html( $v['ja'] ) . '</option>';
		echo '</select>';

		/* 装備オプション */
		echo '<label>装備オプション（1日あたり）</label>';
		foreach ( BV_Util::equipment() as $ek => $ev ) {
			echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">';
			echo '<div style="flex:1;font-size:13px">' . esc_html( $ev['ja'] ) . '（' . esc_html( BV_Util::money( $ev['price'] ) ) . '）';
			if ( ! empty( $ev['note_ja'] ) ) echo '<br><span class="note">' . esc_html( $ev['note_ja'] ) . '</span>';
			echo '</div><select name="opt_' . esc_attr( $ek ) . '" style="width:80px;margin:0">';
			for ( $i = 0; $i <= (int) $ev['max']; $i++ ) echo '<option value="' . $i . '">' . $i . '</option>';
			echo '</select></div>';
		}
		echo '<div style="margin-bottom:12px"></div>';

		/* 送迎 */
		echo '<label>送迎</label><select name="shuttle" id="bv-add-shuttle">';
		foreach ( BV_Util::shuttles() as $k => $v ) echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v['ja'] ) . '</option>';
		echo '</select>';
		echo '<div id="bv-add-shdet" style="display:none">';
		echo '<div class="warn" style="margin-bottom:8px;font-size:13px">送迎は<strong>リクエスト</strong>です。送迎料金は車両料金とは別決済のため、この予約作成後に管理画面から料金を選んで承認し、決済リンクをお送りください。</div>';
		echo '<label>送迎の詳細場所（住所・施設名・人数・希望時刻など）</label>';
		echo '<textarea name="shuttle_detail" id="bv-add-shdetail" rows="3" placeholder="例：白馬駅前 / ホテル○○ロビー。人数・希望時刻もご記入ください。"></textarea>';
		echo '</div>';
		echo '<script>(function(){var s=document.getElementById("bv-add-shuttle"),d=document.getElementById("bv-add-shdet");function t(){d.style.display=(s.value!=="none")?"block":"none";}s.addEventListener("change",t);t();})();</script>';

		echo '<label>クーポンコード（任意）</label><input type="text" name="coupon_code">';
		echo '<label>特別値引き（円・任意）</label><input type="number" name="manual_discount" step="100" value="0">';
		echo '<input type="text" name="manual_discount_note" placeholder="理由（例：リピーター割引）">';
		echo '<p class="note" style="margin:-6px 0 12px">プラス＝値引き、マイナス＝追加請求。明細に1行として表示され、決済リンクの金額にも反映されます。</p>';
		echo '<p style="margin:0 0 12px"><label style="font-weight:normal"><input type="checkbox" name="is_student" value="1"> 学割を適用する</label></p>';
		echo '<label>メモ・要望</label><textarea name="request_note" rows="2"></textarea>';
		echo '<label>お支払い方法</label><select name="payment_method">';
		foreach ( BV_Util::payment_methods() as $pm => $pv ) echo '<option value="' . esc_attr( $pm ) . '">' . esc_html( $pv['ja'] ) . '</option>';
		echo '</select>';
		echo '<p class="note" style="margin:-6px 0 12px">現金・銀行振込を選ぶと決済リンクは発行されず、自動キャンセルや支払リマインドの対象外になります（電話予約・店頭払い向け）。</p>';
		echo '<label>予約・決済メール</label><select name="mail_mode"><option value="auto">自動送信（決済リンク付き）</option><option value="manual">手動送信（リンクなし）</option><option value="none" selected>送信しない</option></select>';
		echo '<button>予約を作成</button></form></div>';
		self::footer();
	}

	/* ---------- 返却処理 ---------- */

	protected static function page_return() {
		global $wpdb;
		$locations = BV_Util::locations();

		if ( isset( $_POST['bv_staff_return'] ) && check_admin_referer( 'bv_staff_return' ) ) {
			$P = wp_unslash( $_POST );
			$r = BV_DB::get_reservation( (int) $P['reservation_id'] );
			if ( $r && ! self::in_scope( $r ) ) $r = null;
			if ( $r ) {
				$odo = (int) $P['return_odometer'];
				$v = $r->vehicle_id ? BV_DB::get_vehicle( $r->vehicle_id ) : null;
				/*
				 * 走行距離＝返却メーター − 貸出前メーター。
				 * 車両のメーターが未登録（0）のときは差が出せないため0とする。
				 * ここで0を許すと「返却メーターの値そのもの」が走行距離として記録され、
				 * 集計や貸渡実績報告書の走行キロが跳ね上がってしまう。
				 */
				$prev = $v ? (int) $v->mileage : 0;
				$trip = ( $prev > 0 && $odo > $prev ) ? $odo - $prev : 0;
				BV_DB::update_reservation( $r->id, array(
					'status' => 'returned',
					'return_odometer' => $odo,
					'return_location' => sanitize_key( $P['return_location'] ),
					'fuel_full' => ! empty( $P['fuel_full'] ) ? 1 : 0,
					'no_accident' => ! empty( $P['no_accident'] ) ? 1 : 0,
					'return_memo' => sanitize_textarea_field( $P['return_memo'] ),
					'trip_distance' => $trip,
					'returned_at' => current_time( 'mysql' ),
				) );
				if ( $v && $odo > 0 ) {
					BV_DB::save_vehicle( array( 'mileage' => $odo, 'location' => sanitize_key( $P['return_location'] ) ), $v->id );
				}
				/* お礼＋Googleレビュー依頼メール（スタッフがチェックを外せば送らない） */
				$review_msg = '';
				if ( empty( $P['skip_review_mail'] ) ) {
					$r = BV_DB::get_reservation( $r->id );
					$sent = BV_Review::maybe_send( $r );
					$review_msg = ( true === $sent )
						? '<p class="ok">お礼＋口コミ依頼メールを送信しました。</p>'
						: '<p class="warn">お礼メールは送信していません：' . esc_html( $sent ) . '</p>';
				}

				self::header( '返却処理' );
				echo '<p class="ok">返却処理が完了しました（' . esc_html( $r->code ) . '）。走行距離: ' . number_format( $trip ) . ' km</p>';
				echo $review_msg;
				echo '<a class="btn" href="' . esc_url( self::base( 'return' ) ) . '">続けて返却処理</a>';
				self::footer();
				return;
			}
		}

		self::header( '返却処理' );
		/* 返却対象: 確定・貸出中・仮予約で未返却のもの（返却予定日順） */
		$t = BV_DB::table( 'reservations' );
		$list = self::filter_reservations( $wpdb->get_results( "SELECT * FROM {$t} WHERE status IN ('pending','confirmed','in_use') ORDER BY return_dt ASC LIMIT 200" ) );
		$selected = isset( $_GET['res'] ) ? (int) $_GET['res'] : 0;
		if ( ! $list ) { echo '<p class="warn">返却待ちの予約がありません。</p>'; self::footer(); return; }

		$stores = BV_Util::stores();
		echo '<div class="card"><form method="post" id="retform">';
		wp_nonce_field( 'bv_staff_return' );
		echo '<input type="hidden" name="bv_staff_return" value="1">';
		echo '<label>予約を選択（返却予定日・車両・名前・予約番号）</label><select name="reservation_id" id="ressel">';
		$meta = array();
		foreach ( $list as $r ) {
			$v = $r->vehicle_id ? BV_DB::get_vehicle( $r->vehicle_id ) : null;
			$label = date( 'n/j H:i', strtotime( $r->return_dt ) ) . '返却 ｜ ' . ( $v ? $v->name : '未割当' ) . ' ｜ ' . $r->sei . $r->mei . ' ｜ ' . $r->code;
			$loc_default = isset( $stores[ $r->store ] ) ? $stores[ $r->store ]['location'] : 'hakuba_norikura';
			$meta[ $r->id ] = array( 'odo' => $v ? (int) $v->mileage : 0, 'loc' => $v ? $v->location : $loc_default );
			echo '<option value="' . (int) $r->id . '"' . selected( $selected, (int) $r->id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label>返却後メーター距離（km）</label><input type="number" name="return_odometer" min="0" required placeholder="例: 45230">';
		echo '<p class="note" id="odonote"></p>';
		echo '<label>返却場所（初期値＝貸出場所）</label><select name="return_location" id="retloc">';
		foreach ( $locations as $k => $l ) {
			if ( self::$scope && ! in_array( $k, self::$scope['locations'], true ) ) continue;
			echo '<option value="' . $k . '">' . esc_html( $l['ja'] ) . '</option>';
		}
		echo '</select>';
		echo '<p style="margin:0 0 6px"><label style="font-weight:normal"><input type="checkbox" name="fuel_full" value="1"> ガソリン満タン確認（任意）</label></p>';
		echo '<p style="margin:0 0 12px"><label style="font-weight:normal"><input type="checkbox" name="no_accident" value="1"> 無事故確認（任意）</label></p>';
		echo '<label>メモ</label><textarea name="return_memo" rows="3" placeholder="傷・忘れ物・清掃など"></textarea>';
		if ( ! empty( BV_Util::settings()['review_mail_enabled'] ) ) {
			echo '<p style="margin:0 0 12px"><label style="font-weight:normal"><input type="checkbox" name="skip_review_mail" value="1"> お礼＋口コミ依頼メールを送らない</label>'
				. '<br><span style="font-size:12px;color:#666">通常はチェック不要です。返却完了と同時にお客様へ自動送信されます。</span></p>';
		}
		echo '<button class="green" style="background:#00a32a">返却を完了する</button></form></div>';
		echo '<script>var META=' . wp_json_encode( $meta ) . ';'
			. 'var sel=document.getElementById("ressel"),loc=document.getElementById("retloc"),note=document.getElementById("odonote");'
			. 'function sync(){var m=META[sel.value];if(!m)return;loc.value=m.loc;'
			. 'if(m.odo>0){note.style.color="";note.textContent="貸出前メーター: "+m.odo.toLocaleString()+" km（差分が走行距離として記録されます）";}'
			. 'else{note.style.color="#b32d2e";note.textContent="この車両は貸出前メーターが未登録です。走行距離は記録されません（0kmになります）。車両管理でメーターを登録してください。";}}'
			. 'sel.addEventListener("change",sync);sync();</script>';
		self::footer();
	}

	/* ---------- 車両メンテ・車両情報 ---------- */

	protected static function page_vehicles() {
		global $wpdb;
		if ( isset( $_POST['bv_staff_maint'] ) && check_admin_referer( 'bv_staff_maint' ) && self::vehicle_in_scope( BV_DB::get_vehicle( (int) ( $_POST['vehicle_id'] ?? 0 ) ) ) ) {
			$P = wp_unslash( $_POST );
			$wpdb->insert( BV_DB::table( 'maintenance' ), array(
				'vehicle_id' => (int) $P['vehicle_id'], 'mdate' => sanitize_text_field( $P['mdate'] ),
				'mtype' => sanitize_key( $P['mtype'] ), 'odometer' => (int) $P['odometer'],
				'cost' => (int) $P['cost'], 'memo' => sanitize_textarea_field( $P['memo'] ),
				'created_at' => current_time( 'mysql' ),
			) );
			if ( ! empty( $P['update_mileage'] ) && (int) $P['odometer'] > 0 ) {
				BV_DB::save_vehicle( array( 'mileage' => (int) $P['odometer'] ), (int) $P['vehicle_id'] );
			}
			echo '';
		}

		self::header( '車両メンテ・車両情報' );
		if ( isset( $_POST['bv_staff_maint'] ) ) echo '<p class="ok">メンテ記録を保存しました。</p>';
		$vehicles = self::filter_vehicles( BV_DB::get_vehicles() );
		$classes = BV_Util::classes(); $locations = BV_Util::locations();

		echo '<div class="card"><h3 style="margin-top:0">メンテ記録を追加</h3><form method="post">';
		wp_nonce_field( 'bv_staff_maint' );
		echo '<input type="hidden" name="bv_staff_maint" value="1">';
		echo '<label>車両</label><select name="vehicle_id">';
		foreach ( $vehicles as $v ) echo '<option value="' . (int) $v->id . '">' . esc_html( $v->name ) . '</option>';
		echo '</select>';
		echo '<label>日付</label><input type="date" name="mdate" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '">';
		echo '<label>種別</label><select name="mtype"><option value="maintenance">メンテナンス</option><option value="inspection6">6か月定期点検</option></select>';
		echo '<label>メーター（km）</label><input type="number" name="odometer" placeholder="点検・整備時のメーター値">';
		echo '<p style="margin:0 0 12px"><label style="font-weight:normal"><input type="checkbox" name="update_mileage" value="1" checked> 上のメーター値を車両の走行距離としても更新する</label></p>';
		echo '<label>費用（円）</label><input type="number" name="cost" placeholder="整備・部品代など">';
		echo '<label>内容</label><textarea name="memo" rows="2" required placeholder="オイル交換、タイヤ交換など"></textarea>';
		echo '<button>記録する</button></form></div>';

		echo '<h3>車両一覧</h3>';
		foreach ( $vehicles as $v ) {
			echo '<div class="card">';
			echo '<strong>' . esc_html( $v->name ) . '</strong>（' . esc_html( BV_Util::label( $classes, $v->class ) ) . '・' . esc_html( BV_Util::label( $locations, $v->location ) ) . '）<br>';
			echo '<span class="note">走行: ' . number_format( (int) $v->mileage ) . ' km ｜ 車検: ' . esc_html( $v->shaken_date ?: '—' ) . ' ｜ 自賠責: ' . esc_html( $v->jibaiseki_date ?: '—' ) . ' ｜ 任意保険: ' . esc_html( $v->insurance_date ?: '—' ) . '<br>';
			echo 'タイヤ: ' . esc_html( $v->tire_size ?: '—' ) . ' ｜ ワイパー: ' . esc_html( $v->wiper_length ?: '—' ) . ' ｜ カラーNo: ' . esc_html( $v->color_number ?: '—' ) . '</span>';
			$maint = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . BV_DB::table( 'maintenance' ) . ' WHERE vehicle_id = %d ORDER BY mdate DESC LIMIT 3', $v->id ) );
			if ( $maint ) {
				echo '<div class="note" style="margin-top:6px">';
				foreach ( $maint as $m ) echo '・' . esc_html( $m->mdate . ' ' . ( 'inspection6' === $m->mtype ? '[6か月点検] ' : '' ) . $m->memo ) . '<br>';
				echo '</div>';
			}
			echo '</div>';
		}
		self::footer();
	}

	/* ---------- 月間集計 ---------- */

	/**
	 * 月ごとの貸渡回数・送迎回数・売上高。
	 * 貸渡＝貸出日がその月にある、キャンセル以外の予約。
	 * 売上＝入金済み予約の（総額－返金額）＋確定した送迎料金＋キャンセル料（返金後に残った額）。
	 */
	public static function month_stats( $ym, $stores = array() ) {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$from = $ym . '-01 00:00:00';
		$to   = date( 'Y-m-01 00:00:00', strtotime( $from . ' +1 month' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE pickup_dt >= %s AND pickup_dt < %s", $from, $to ) );
		$out = array(
			'rentals' => 0, 'hours' => 0, 'days' => 0,
			'shuttle_trips' => 0, 'shuttle_resv' => 0,
			'sales_rental' => 0, 'sales_shuttle' => 0, 'sales_cancel' => 0, 'sales' => 0,
			'unpaid' => 0, 'cancelled' => 0,
			'by_class' => array(), 'by_store' => array(),
		);
		foreach ( $rows as $r ) {
			if ( $stores && ! in_array( $r->store, $stores, true ) ) continue;
			$paid_kept = $r->paid_at ? max( 0, (int) $r->price_total - (int) $r->refund_amount ) : 0;
			if ( 'cancelled' === $r->status ) {
				$out['cancelled']++;
				$out['sales_cancel'] += $paid_kept;
				continue;
			}
			$out['rentals']++;
			$out['days']  += BV_Pricing::rental_days( $r->pickup_dt, $r->return_dt );
			$out['hours'] += BV_Pricing::rental_hours( $r->pickup_dt, $r->return_dt );
			if ( $r->paid_at ) $out['sales_rental'] += $paid_kept; else $out['unpaid'] += (int) $r->price_total;
			if ( 'none' !== $r->shuttle && in_array( (string) $r->shuttle_status, array( 'paid' ), true ) ) {
				$out['shuttle_resv']++;
				$out['shuttle_trips'] += ( 'round' === $r->shuttle ) ? 2 : 1;
				$out['sales_shuttle'] += (int) $r->shuttle_fee;
			}
			$c = $r->vehicle_class;
			if ( ! isset( $out['by_class'][ $c ] ) ) $out['by_class'][ $c ] = array( 'n' => 0, 'sales' => 0 );
			$out['by_class'][ $c ]['n']++; $out['by_class'][ $c ]['sales'] += $paid_kept;
			$st = $r->store;
			if ( ! isset( $out['by_store'][ $st ] ) ) $out['by_store'][ $st ] = array( 'n' => 0, 'sales' => 0, 'shuttle' => 0 );
			$out['by_store'][ $st ]['n']++; $out['by_store'][ $st ]['sales'] += $paid_kept;
			if ( 'none' !== $r->shuttle && 'paid' === (string) $r->shuttle_status ) $out['by_store'][ $st ]['shuttle'] += ( 'round' === $r->shuttle ) ? 2 : 1;
		}
		$out['sales'] = $out['sales_rental'] + $out['sales_shuttle'] + $out['sales_cancel'];
		return $out;
	}

	protected static function page_stats() {
		self::header( '月間集計' );
		$ym = isset( $_GET['ym'] ) ? sanitize_text_field( wp_unslash( $_GET['ym'] ) ) : current_time( 'Y-m' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $ym ) ) $ym = current_time( 'Y-m' );
		$stores_all = BV_Util::stores();
		$stores = self::stores_for_select();
		$filter = isset( $_GET['st'] ) ? sanitize_key( wp_unslash( $_GET['st'] ) ) : '';
		if ( ! isset( $stores[ $filter ] ) ) $filter = '';
		$scope_stores = $filter ? array( $filter ) : ( self::$scope ? self::$scope['stores'] : array() );

		$prev = date( 'Y-m', strtotime( $ym . '-01 -1 month' ) );
		$next = date( 'Y-m', strtotime( $ym . '-01 +1 month' ) );
		$mk = function ( $m, $st ) { return add_query_arg( array( 'ym' => $m, 'st' => $st ), self::base( 'stats' ) ); };

		echo '<div class="card"><form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
		echo '<input type="hidden" name="' . esc_attr( self::query_key() ) . '" value="1"><input type="hidden" name="view" value="stats">';
		echo '<a class="mini" href="' . esc_url( $mk( $prev, $filter ) ) . '">« 前月</a>';
		echo '<input type="month" name="ym" value="' . esc_attr( $ym ) . '" style="margin:0;width:auto">';
		echo '<a class="mini" href="' . esc_url( $mk( $next, $filter ) ) . '">翌月 »</a>';
		if ( count( $stores ) > 1 ) {
			echo '<select name="st" style="margin:0;width:auto"><option value="">全店舗</option>';
			foreach ( $stores as $k => $v ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $filter, $k, false ) . '>' . esc_html( $v['ja'] ) . '</option>';
			echo '</select>';
		}
		echo '<button style="width:auto;margin:0;padding:8px 14px">表示</button></form></div>';

		$m = self::month_stats( $ym, $scope_stores );
		$card = function ( $label, $val, $sub = '', $color = '#1d2327' ) {
			echo '<div class="card" style="flex:1;min-width:140px;text-align:center"><div class="note">' . esc_html( $label ) . '</div>';
			echo '<div style="font-size:24px;font-weight:700;color:' . esc_attr( $color ) . '">' . $val . '</div>';
			if ( $sub ) echo '<div class="note">' . $sub . '</div>';
			echo '</div>';
		};
		echo '<h3 style="margin:6px 0">' . esc_html( date( 'Y年n月', strtotime( $ym . '-01' ) ) ) . ( $filter ? '　' . esc_html( $stores[ $filter ]['ja'] ) : '' ) . '</h3>';
		echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
		$card( '貸渡回数', number_format( $m['rentals'] ) . ' 件', '延べ ' . number_format( $m['days'] ) . '日 / ' . number_format( $m['hours'] ) . '時間' );
		$card( '送迎回数', number_format( $m['shuttle_trips'] ) . ' 回', '予約 ' . number_format( $m['shuttle_resv'] ) . '件（片道1・往復2で集計）' );
		$card( '売上高', esc_html( BV_Util::money( $m['sales'] ) ),
			'貸渡 ' . esc_html( BV_Util::money( $m['sales_rental'] ) ) . '<br>送迎 ' . esc_html( BV_Util::money( $m['sales_shuttle'] ) ) . '<br>キャンセル料 ' . esc_html( BV_Util::money( $m['sales_cancel'] ) ), '#00a32a' );
		echo '</div>';
		echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
		$card( '未入金（貸出予定分）', esc_html( BV_Util::money( $m['unpaid'] ) ), '入金後に売上へ計上', '#b32d2e' );
		$card( 'キャンセル', number_format( $m['cancelled'] ) . ' 件', '' , '#646970' );
		echo '</div>';

		$classes = BV_Util::classes();
		if ( $m['by_class'] ) {
			echo '<div class="card"><strong>クラス別</strong><table style="margin-top:6px"><thead><tr><th>クラス</th><th style="text-align:right">件数</th><th style="text-align:right">売上（入金済）</th></tr></thead><tbody>';
			foreach ( $m['by_class'] as $c => $row ) {
				echo '<tr><td>' . esc_html( BV_Util::label( $classes, $c ) ) . '</td><td style="text-align:right">' . (int) $row['n'] . '</td><td style="text-align:right">' . esc_html( BV_Util::money( $row['sales'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		if ( count( $m['by_store'] ) > 1 ) {
			echo '<div class="card"><strong>店舗別</strong><table style="margin-top:6px"><thead><tr><th>店舗</th><th style="text-align:right">貸渡</th><th style="text-align:right">送迎</th><th style="text-align:right">売上（入金済）</th></tr></thead><tbody>';
			foreach ( $m['by_store'] as $st => $row ) {
				echo '<tr><td>' . esc_html( BV_Util::label( $stores_all, $st ) ) . '</td><td style="text-align:right">' . (int) $row['n'] . '</td><td style="text-align:right">' . (int) $row['shuttle'] . '</td><td style="text-align:right">' . esc_html( BV_Util::money( $row['sales'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		/* 直近12か月の推移 */
		echo '<div class="card"><strong>直近12か月</strong><table style="margin-top:6px"><thead><tr><th>月</th><th style="text-align:right">貸渡</th><th style="text-align:right">送迎</th><th style="text-align:right">売上高</th></tr></thead><tbody>';
		for ( $i = 11; $i >= 0; $i-- ) {
			$mm = date( 'Y-m', strtotime( $ym . '-01 -' . $i . ' month' ) );
			$x  = self::month_stats( $mm, $scope_stores );
			echo '<tr' . ( $mm === $ym ? ' style="font-weight:700;background:#f6f7f7"' : '' ) . '><td><a href="' . esc_url( $mk( $mm, $filter ) ) . '">' . esc_html( date( 'Y/n', strtotime( $mm . '-01' ) ) ) . '</a></td>';
			echo '<td style="text-align:right">' . (int) $x['rentals'] . '</td><td style="text-align:right">' . (int) $x['shuttle_trips'] . '</td><td style="text-align:right">' . esc_html( BV_Util::money( $x['sales'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="note">売上高＝入金済みの貸渡料金（返金分を除く）＋確定した送迎料金＋キャンセル料。貸出日基準で集計しています。</p></div>';
		self::footer();
	}
}
