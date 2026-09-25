<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 管理画面：ダッシュボード・車両・顧客・クーポン・料金カレンダー・報告書・設定
 */
class BV_Admin_Pages {

	/* ---------- 経営ダッシュボード ---------- */

	/**
	 * 申し込みの離脱分析（ファネル）
	 * 予約は「作成日」を基準に数える。貸出日基準だと、期間内に申し込まれて
	 * 落ちた予約が別の期間に混ざってしまうため。
	 */
	protected static function render_funnel( $from_dt, $to_dt, $where_store, $store_filter ) {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$otp = BV_DB::table( 'otp' );

		/* 申し込み日ベースの絞り込み */
		$w = $wpdb->prepare( 'created_at BETWEEN %s AND %s', $from_dt, $to_dt ) . $where_store;

		$f = $wpdb->get_row( "SELECT COUNT(*) applied,
			SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END) paid,
			SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) returned,
			SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) cancelled,
			SUM(CASE WHEN status = 'pending' AND paid_at IS NULL THEN 1 ELSE 0 END) still_pending
			FROM {$t} WHERE {$w}" );

		/* メール認証（OTP）は店舗を持たないため、全店舗表示のときだけ出す */
		$otp_sent = 0; $otp_ok = 0;
		if ( ! $store_filter ) {
			$otp_sent = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$otp} WHERE code <> '' AND expires_at BETWEEN %s AND %s", $from_dt, $to_dt
			) );
			$otp_ok = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$otp} WHERE code <> '' AND verified = 1 AND expires_at BETWEEN %s AND %s", $from_dt, $to_dt
			) );
		}

		/* キャンセルの内訳を管理メモから判定する */
		$cancels = $wpdb->get_results( "SELECT admin_memo, paid_at FROM {$t} WHERE status = 'cancelled' AND {$w}" );
		$reason = array( 'auto' => 0, 'customer' => 0, 'staff' => 0, 'noshow' => 0, 'other' => 0 );
		foreach ( $cancels as $c ) {
			$m = (string) $c->admin_memo;
			if ( false !== strpos( $m, '【自動キャンセル】' ) )      $reason['auto']++;
			elseif ( false !== strpos( $m, '無断キャンセル' ) )      $reason['noshow']++;
			elseif ( false !== strpos( $m, 'お客様による取消' ) )    $reason['customer']++;
			elseif ( false !== strpos( $m, 'スタッフによる取消' ) )  $reason['staff']++;
			else $reason['other']++;
		}

		$applied  = (int) $f->applied;
		$paid     = (int) $f->paid;
		$returned = (int) $f->returned;
		$cancelled= (int) $f->cancelled;
		$pending  = (int) $f->still_pending;

		echo '<h2>申し込みの離脱分析</h2>';
		echo '<p class="description">この表だけは<strong>申し込み日</strong>（予約が作られた日）で集計します。選択中の期間：'
			. esc_html( date( 'Y-m-d', strtotime( $from_dt ) ) ) . '〜' . esc_html( date( 'Y-m-d', strtotime( $to_dt ) ) ) . '</p>';

		if ( $applied < 1 && $otp_sent < 1 ) {
			echo '<p class="description">この期間に申し込みはありません。</p>';
			return;
		}

		$steps = array();
		if ( ! $store_filter && $otp_sent > 0 ) {
			$steps[] = array( 'メール認証コードを送信', $otp_sent, 'ご入力途中の方を含みます' );
			$steps[] = array( 'メール認証が完了', $otp_ok, '認証コードを入力した方' );
		}
		$steps[] = array( '申し込み完了（予約作成）', $applied, 'お客様情報の入力まで完了' );
		$steps[] = array( 'お支払い完了（予約確定）', $paid, '売上として計上されます' );
		$steps[] = array( '貸出完了（返却済み）', $returned, '実際にご利用いただけた件数' );

		$base = $steps[0][1];
		echo '<table class="wp-list-table widefat striped" style="max-width:900px"><thead><tr>'
			. '<th style="width:220px">段階</th><th style="width:80px;text-align:right">件数</th>'
			. '<th style="width:90px;text-align:right">最初から</th><th style="width:150px;text-align:right">前の段階からの離脱</th>'
			. '<th>備考</th></tr></thead><tbody>';
		$prev = null;
		foreach ( $steps as $i => $st ) {
			list( $label, $cnt, $note ) = $st;
			$pct_all = $base > 0 ? round( $cnt / $base * 100, 1 ) : 0;
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td style="text-align:right;font-size:15px"><strong>' . number_format( $cnt ) . '</strong></td>';
			echo '<td style="text-align:right">' . esc_html( $pct_all ) . '%</td>';
			echo '<td style="text-align:right">';
			if ( null === $prev ) {
				echo '—';
			} else {
				$lost = max( 0, $prev - $cnt );
				$lost_pct = $prev > 0 ? round( $lost / $prev * 100, 1 ) : 0;
				$col = ( $lost_pct >= 30 ) ? '#b32d2e' : ( $lost_pct >= 15 ? '#996800' : '#646970' );
				echo '<span style="color:' . $col . ';font-weight:600">▼' . number_format( $lost ) . '件（' . esc_html( $lost_pct ) . '%）</span>';
			}
			echo '</td><td class="description">' . esc_html( $note ) . '</td></tr>';
			$prev = $cnt;
		}
		echo '</tbody></table>';

		/* 申し込み後に落ちた分の内訳 */
		$lost_after = max( 0, $applied - $paid );
		echo '<h3 style="margin-top:18px">申し込み後に確定しなかった ' . number_format( $lost_after ) . '件の内訳</h3>';
		if ( $lost_after < 1 ) {
			echo '<p class="description">申し込みはすべてお支払いまで進んでいます。</p>';
		} else {
			$rows = array(
				array( '自動キャンセル（未入金のまま期限切れ）', $reason['auto'], '支払期限・督促の設定を見直すと減らせます' ),
				array( 'お客様がご自身でキャンセル', $reason['customer'], '予約確認ページからの取消' ),
				array( 'スタッフによるキャンセル', $reason['staff'], '電話連絡での取消など' ),
				array( '無断キャンセル（No-show）', $reason['noshow'], '' ),
				array( 'その他・理由の記録なし', $reason['other'], '手動でステータスを変更した予約' ),
				array( 'まだお支払い待ち（進行中）', $pending, '期限内のため、これから確定する可能性があります' ),
			);
			echo '<table class="wp-list-table widefat striped" style="max-width:900px"><thead><tr>'
				. '<th style="width:320px">内訳</th><th style="width:80px;text-align:right">件数</th>'
				. '<th style="width:90px;text-align:right">構成比</th><th>コメント</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				if ( $row[1] < 1 ) continue;
				$p = $lost_after > 0 ? round( $row[1] / $lost_after * 100, 1 ) : 0;
				echo '<tr><td>' . esc_html( $row[0] ) . '</td>';
				echo '<td style="text-align:right"><strong>' . number_format( $row[1] ) . '</strong></td>';
				echo '<td style="text-align:right">' . esc_html( $p ) . '%</td>';
				echo '<td class="description">' . esc_html( $row[2] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		/* 読み取りのヒント */
		$hints = array();
		if ( $otp_sent > 0 && $applied > 0 && $otp_ok > 0 ) {
			$drop = $otp_ok - $applied;
			if ( $drop > 0 && $otp_ok > 0 && ( $drop / $otp_ok ) >= 0.2 ) {
				$hints[] = 'メール認証は済んだのに申し込みまで至らない方が' . number_format( $drop ) . '件あります。免許証のアップロードや入力項目が負担になっていないかご確認ください。';
			}
		}
		if ( $applied > 0 && $reason['auto'] > 0 && ( $reason['auto'] / $applied ) >= 0.15 ) {
			$hints[] = '自動キャンセルが申し込みの' . round( $reason['auto'] / $applied * 100, 1 ) . '%を占めています。支払期限（現在'
				. (int) BV_Util::settings()['autocancel_hours'] . '時間）と督促の回数を見直す余地があります。';
		}
		if ( $applied > 0 && $paid > 0 ) {
			$cv = round( $paid / $applied * 100, 1 );
			$hints[] = '申し込みからお支払いまでの完了率は' . $cv . '%です。';
		}
		if ( $hints ) {
			echo '<div class="notice notice-info inline" style="margin:10px 0;max-width:900px"><p>' . implode( '<br>', array_map( 'esc_html', $hints ) ) . '</p></div>';
		}
		echo '<p class="description" style="max-width:900px">※メール認証の2行は、お客様が入力を始めた段階を推定するものです。店舗の情報を持たないため、店舗を絞り込むと表示されません。'
			. 'フォームを開いただけで離脱した方は記録されないため、ここには含まれません。</p>';
	}

	public static function dashboard() {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );

		/* ---- 期間の決定：年度 or 任意期間 ---- */
		$mode = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'fy';
		$year = isset( $_GET['fy'] ) ? (int) $_GET['fy'] : ( (int) date( 'n' ) >= 4 ? (int) current_time( 'Y' ) : (int) current_time( 'Y' ) - 1 );
		if ( 'custom' === $mode ) {
			$from = sanitize_text_field( wp_unslash( $_GET['from'] ?? date( 'Y-m-01' ) ) );
			$to   = sanitize_text_field( wp_unslash( $_GET['to'] ?? date( 'Y-m-t' ) ) );
			$period_label = $from . ' 〜 ' . $to;
		} else {
			$from = $year . '-04-01';
			$to   = ( $year + 1 ) . '-03-31';
			$period_label = $year . '年度（' . $year . '/4/1〜' . ( $year + 1 ) . '/3/31）';
		}
		$from_dt = $from . ' 00:00:00';
		$to_dt   = $to . ' 23:59:59';
		$days_span = max( 1, (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 );

		/* 店舗フィルタ */
		$stores = BV_Util::stores();
		$store_filter = isset( $_GET['store'] ) ? sanitize_key( $_GET['store'] ) : '';
		if ( $store_filter && ! isset( $stores[ $store_filter ] ) ) $store_filter = '';
		$where_store = $store_filter ? $wpdb->prepare( ' AND store = %s', $store_filter ) : '';

		/* 言語フィルタ（全体／日本語／英語） */
		$lang_filter = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : '';
		if ( ! in_array( $lang_filter, array( 'ja', 'en' ), true ) ) $lang_filter = '';
		$where_lang = $lang_filter ? $wpdb->prepare( ' AND lang = %s', $lang_filter ) : '';
		$lang_labels = array( '' => '全体', 'ja' => '日本語予約', 'en' => '英語予約' );

		$base_where = $wpdb->prepare( "status != 'cancelled' AND pickup_dt BETWEEN %s AND %s", $from_dt, $to_dt ) . $where_store . $where_lang;

		$classes   = BV_Util::classes();
		$locations = BV_Util::locations();

		echo '<div class="wrap"><h1>経営ダッシュボード</h1>';
		BV_Admin::notice();

		/* ---- 期間・店舗選択フォーム ---- */
		echo '<form method="get" style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:12px 16px;margin:12px 0">';
		echo '<input type="hidden" name="page" value="bvrm">';
		echo '<label style="margin-right:16px"><input type="radio" name="mode" value="fy"' . checked( $mode, 'fy', false ) . '> 年度</label>';
		echo '<select name="fy" style="margin-right:20px">';
		for ( $y = (int) current_time( 'Y' ) + 1; $y >= (int) current_time( 'Y' ) - 5; $y-- ) {
			echo '<option value="' . $y . '"' . selected( $year, $y, false ) . '>' . $y . '年度</option>';
		}
		echo '</select>';
		echo '<label style="margin-right:8px"><input type="radio" name="mode" value="custom"' . checked( $mode, 'custom', false ) . '> 期間指定</label>';
		echo '<input type="date" name="from" value="' . esc_attr( $from ) . '"> 〜 <input type="date" name="to" value="' . esc_attr( $to ) . '">';
		echo '<span style="margin:0 20px">店舗 <select name="store"><option value="">全店舗</option>';
		foreach ( $stores as $sk => $st ) echo '<option value="' . esc_attr( $sk ) . '"' . selected( $store_filter, $sk, false ) . '>' . esc_html( $st['ja'] ) . '</option>';
		echo '</select></span>';
		echo '<span style="margin-right:20px">予約言語 <select name="lang">';
		foreach ( $lang_labels as $lk => $ll ) echo '<option value="' . esc_attr( $lk ) . '"' . selected( $lang_filter, $lk, false ) . '>' . esc_html( $ll ) . '</option>';
		echo '</select></span>';
		submit_button( '表示', 'primary', '', false );
		$csv_args = array( 'page' => 'bvrm', 'bvrm_action' => 'export_analytics', 'from' => $from, 'to' => $to, 'store' => $store_filter, 'lang' => $lang_filter );
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( $csv_args, admin_url( 'admin.php' ) ), 'bvrm_export_analytics' ) ) . '">分析CSVダウンロード</a>';
		echo '</form>';

		echo '<p class="description">対象期間: <strong>' . esc_html( $period_label ) . '</strong>'
			. ( $store_filter ? '｜店舗: <strong>' . esc_html( $stores[ $store_filter ]['ja'] ) . '</strong>' : '｜全店舗' )
			. '｜予約言語: <strong>' . esc_html( $lang_labels[ $lang_filter ] ) . '</strong>（貸出日基準・キャンセル除く）</p>';

		/* ---- サマリー ---- */
		$sum = $wpdb->get_row( "SELECT COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue, COALESCE(SUM(trip_distance),0) km,
			COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days,
			COUNT(DISTINCT CASE WHEN email <> '' THEN CONCAT('e:', LOWER(email)) WHEN phone <> '' THEN CONCAT('p:', REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'+81','0')) ELSE CONCAT('r:', id) END) customers,
			COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN price_total ELSE 0 END),0) paid_revenue,
			COALESCE(SUM(CASE WHEN paid_at IS NULL THEN price_total ELSE 0 END),0) unpaid_revenue,
			SUM(CASE WHEN paid_at IS NULL THEN 1 ELSE 0 END) unpaid_cnt,
			SUM(CASE WHEN paid_at IS NULL AND status = 'pending' THEN 1 ELSE 0 END) unpaid_pending,
			SUM(CASE WHEN paid_at IS NULL AND status <> 'pending' THEN 1 ELSE 0 END) unpaid_other,
			COALESCE(SUM(CASE WHEN shuttle_paid_at IS NOT NULL THEN shuttle_fee ELSE 0 END),0) shuttle_revenue,
			SUM(CASE WHEN shuttle != 'none' THEN 1 ELSE 0 END) shuttle_cnt,
			SUM(CASE WHEN shuttle_status = 'paid' THEN 1 ELSE 0 END) shuttle_paid_cnt
			FROM {$t} WHERE {$base_where}" );
		$cancelled = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE status = 'cancelled' AND pickup_dt BETWEEN %s AND %s", $from_dt, $to_dt
		) . $where_store );

		/* キャンセル料として手元に残った金額（お支払い済み − 返金額） */
		$cxl = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(GREATEST(price_total - refund_amount, 0)),0) fee,
			 SUM(CASE WHEN GREATEST(price_total - refund_amount,0) > 0 THEN 1 ELSE 0 END) cnt,
			 COALESCE(SUM(refund_amount),0) refunded
			 FROM {$t} WHERE status = 'cancelled' AND paid_at IS NOT NULL AND pickup_dt BETWEEN %s AND %s", $from_dt, $to_dt
		) . $where_store );
		$cxl_fee      = (int) ( $cxl->fee ?? 0 );
		$cxl_fee_cnt  = (int) ( $cxl->cnt ?? 0 );
		$cxl_refunded = (int) ( $cxl->refunded ?? 0 );
		$total_cnt = (int) $sum->cnt;
		$total_rev = (int) $sum->revenue;
		$total_days = (int) $sum->days;

		/* リピーター率 */
		$repeat = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT email FROM {$t} WHERE {$base_where} AND email != '' GROUP BY email HAVING COUNT(*) > 1) x" );

		/* 稼働率：稼働可能な車両台数 × 期間日数 に対する延貸渡日数 */
		$active_vehicles = 0;
		foreach ( BV_DB::get_vehicles( array( 'active' => true ) ) as $vv ) $active_vehicles++;
		$capacity = max( 1, $active_vehicles * $days_span );
		$utilization = round( $total_days / $capacity * 100, 1 );

		$cards = array(
			array( '総売上', BV_Util::money( $total_rev ), '#2271b1' ),
			array( '入金済み', BV_Util::money( $sum->paid_revenue ), '#00a32a' ),
			array(
				'未入金（キャンセル除く）',
				BV_Util::money( $sum->unpaid_revenue ),
				( $sum->unpaid_revenue > 0 ? '#b32d2e' : '#646970' ),
				(int) $sum->unpaid_cnt > 0
					? esc_html( (int) $sum->unpaid_cnt . '件（仮予約 ' . (int) $sum->unpaid_pending . '／確定済み ' . (int) $sum->unpaid_other . '）' )
						. ' <a href="' . esc_url( add_query_arg( array(
							'page' => 'bvrm-reservations',
							'from' => date( 'Y-m-d', strtotime( $from_dt ) ),
							'to'   => date( 'Y-m-d', strtotime( $to_dt ) ),
							'store'=> $store_filter,
							'pay'  => 'unpaid',
						), admin_url( 'admin.php' ) ) ) . '">一覧</a>'
					: '',
			),
			array( '送迎売上（入金済）', BV_Util::money( $sum->shuttle_revenue ), '#dba617' ),
			array( '送迎リクエスト', (int) $sum->shuttle_cnt . ' 件（確定 ' . (int) $sum->shuttle_paid_cnt . '）', '#646970' ),
			array( '貸渡件数', number_format( $total_cnt ) . ' 件', '#646970' ),
			array( '平均単価', $total_cnt ? BV_Util::money( $total_rev / $total_cnt ) : '—', '#646970' ),
			array( '平均貸渡日数', $total_cnt ? round( $total_days / $total_cnt, 1 ) . ' 日' : '—', '#646970' ),
			array( '延貸渡日車数', number_format( $total_days ) . ' 日', '#646970' ),
			array( '稼働率', $utilization . ' %', '#646970' ),
			array( '実顧客数', number_format( (int) $sum->customers ) . ' 名', '#646970' ),
			array( 'リピーター', number_format( $repeat ) . ' 名', '#646970' ),
			array( '走行距離', number_format( (int) $sum->km ) . ' km', '#646970' ),
			array(
				'キャンセル', number_format( $cancelled ) . ' 件', '#646970',
				$cxl_refunded > 0 ? esc_html( '返金 ' . BV_Util::money( $cxl_refunded ) ) : '',
			),
			array(
				'キャンセル料収入', BV_Util::money( $cxl_fee ), ( $cxl_fee > 0 ? '#dba617' : '#646970' ),
				$cxl_fee_cnt > 0 ? esc_html( $cxl_fee_cnt . '件から（総売上には含みません）' ) : '',
			),
		);
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">';
		foreach ( $cards as $c ) {
			echo '<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid ' . esc_attr( $c[2] ) . ';border-radius:6px;padding:12px 18px;min-width:150px">';
			echo '<div style="color:#666;font-size:12px">' . esc_html( $c[0] ) . '</div>';
			echo '<div style="font-size:21px;font-weight:600">' . esc_html( $c[1] ) . '</div>';
			if ( isset( $c[3] ) && '' !== $c[3] ) {
				echo '<div style="color:#666;font-size:11px;margin-top:2px">' . $c[3] . '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '<p class="description" style="margin:0 0 20px">売上・件数・未入金は<strong>キャンセルを除いた</strong>数字です。キャンセル件数のみ参考として表示しています。</p>';

		/* ---- 言語別（日本語 / 英語）比較 ---- */
		$where_nolang = $wpdb->prepare( "status != 'cancelled' AND pickup_dt BETWEEN %s AND %s", $from_dt, $to_dt ) . $where_store;
		$by_lang = $wpdb->get_results( "SELECT lang, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue,
			COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days,
			COALESCE(SUM(trip_distance),0) km, COUNT(DISTINCT CASE WHEN email <> '' THEN CONCAT('e:', LOWER(email)) WHEN phone <> '' THEN CONCAT('p:', REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'+81','0')) ELSE CONCAT('r:', id) END) customers,
			SUM(CASE WHEN shuttle!='none' THEN 1 ELSE 0 END) shuttle,
			SUM(CASE WHEN coverage='C' THEN 1 ELSE 0 END) covc
			FROM {$t} WHERE {$where_nolang} GROUP BY lang", OBJECT_K );
		$lang_total_rev = 0;
		foreach ( $by_lang as $r2 ) $lang_total_rev += (int) $r2->revenue;

		/* ---- 申し込みの離脱分析 ---- */
		self::render_funnel( $from_dt, $to_dt, $where_store, $store_filter );

		echo '<h2>予約言語別（日本語 / 英語）</h2>';
		echo '<p class="description">この表は言語フィルタに関係なく、選択期間・店舗の全予約を言語別に比較します。</p>';
		echo '<table class="wp-list-table widefat striped" style="max-width:1000px"><thead><tr><th style="width:130px">予約言語</th><th>構成比</th><th style="width:120px">売上</th><th style="width:70px">件数</th><th style="width:100px">平均単価</th><th style="width:100px">平均日数</th><th style="width:90px">顧客数</th><th style="width:90px">送迎率</th><th style="width:90px">補償C率</th></tr></thead><tbody>';
		foreach ( array( 'ja' => '日本語予約', 'en' => '英語予約' ) as $lk => $ll ) {
			$r2 = isset( $by_lang[ $lk ] ) ? $by_lang[ $lk ] : null;
			$rev = $r2 ? (int) $r2->revenue : 0;
			$cnt = $r2 ? (int) $r2->cnt : 0;
			$share = $lang_total_rev ? round( $rev / $lang_total_rev * 100, 1 ) : 0;
			$color = ( 'ja' === $lk ) ? '#2271b1' : '#f28e2b';
			echo '<tr><td><strong>' . esc_html( $ll ) . '</strong></td>';
			echo '<td><div style="background:' . $color . ';height:16px;border-radius:3px;width:' . $share . '%;min-width:2px;display:inline-block;vertical-align:middle"></div> <span style="font-size:12px;color:#666">' . $share . '%</span></td>';
			echo '<td style="text-align:right"><strong>' . esc_html( BV_Util::money( $rev ) ) . '</strong></td>';
			echo '<td style="text-align:right">' . $cnt . '</td>';
			echo '<td style="text-align:right">' . esc_html( $cnt ? BV_Util::money( $rev / $cnt ) : '—' ) . '</td>';
			echo '<td style="text-align:right">' . ( $cnt ? round( (int) $r2->days / $cnt, 1 ) . ' 日' : '—' ) . '</td>';
			echo '<td style="text-align:right">' . ( $r2 ? (int) $r2->customers : 0 ) . '</td>';
			echo '<td style="text-align:right">' . ( $cnt ? round( (int) $r2->shuttle / $cnt * 100, 1 ) . '%' : '—' ) . '</td>';
			echo '<td style="text-align:right">' . ( $cnt ? round( (int) $r2->covc / $cnt * 100, 1 ) . '%' : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		/* 店舗×言語のクロス集計 */
		$cross = $wpdb->get_results( "SELECT store, lang, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$where_nolang} GROUP BY store, lang" );
		$cx = array();
		foreach ( $cross as $r2 ) $cx[ $r2->store ][ $r2->lang ] = array( 'cnt' => (int) $r2->cnt, 'revenue' => (int) $r2->revenue );
		echo '<h3>店舗 × 予約言語</h3>';
		echo '<table class="wp-list-table widefat striped" style="max-width:860px"><thead><tr><th>店舗</th><th>日本語 件数</th><th>日本語 売上</th><th>英語 件数</th><th>英語 売上</th><th>英語比率（売上）</th></tr></thead><tbody>';
		foreach ( $stores as $sk => $stv ) {
			$ja = isset( $cx[ $sk ]['ja'] ) ? $cx[ $sk ]['ja'] : array( 'cnt' => 0, 'revenue' => 0 );
			$en = isset( $cx[ $sk ]['en'] ) ? $cx[ $sk ]['en'] : array( 'cnt' => 0, 'revenue' => 0 );
			if ( ! $ja['cnt'] && ! $en['cnt'] ) continue;
			$tot = $ja['revenue'] + $en['revenue'];
			echo '<tr><td>' . esc_html( $stv['ja'] ) . '</td>';
			echo '<td style="text-align:right">' . $ja['cnt'] . '</td><td style="text-align:right">' . esc_html( BV_Util::money( $ja['revenue'] ) ) . '</td>';
			echo '<td style="text-align:right">' . $en['cnt'] . '</td><td style="text-align:right">' . esc_html( BV_Util::money( $en['revenue'] ) ) . '</td>';
			echo '<td style="text-align:right">' . ( $tot ? round( $en['revenue'] / $tot * 100, 1 ) . '%' : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		/* ---- 店舗別売上 ---- */
		$by_store = $wpdb->get_results( "SELECT store, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue, COALESCE(SUM(trip_distance),0) km,
			COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days, COUNT(DISTINCT CASE WHEN email <> '' THEN CONCAT('e:', LOWER(email)) WHEN phone <> '' THEN CONCAT('p:', REPLACE(REPLACE(REPLACE(phone,'-',''),' ',''),'+81','0')) ELSE CONCAT('r:', id) END) customers
			FROM {$t} WHERE {$base_where} GROUP BY store ORDER BY revenue DESC" );
		$max_store_rev = 1;
		foreach ( $by_store as $r2 ) $max_store_rev = max( $max_store_rev, (int) $r2->revenue );

		echo '<h2>店舗別 売上</h2>';
		echo '<table class="wp-list-table widefat striped" style="max-width:1100px"><thead><tr><th style="width:200px">店舗</th><th>構成比</th><th style="width:120px">売上</th><th style="width:70px">件数</th><th style="width:100px">平均単価</th><th style="width:100px">延日車数</th><th style="width:90px">顧客数</th><th style="width:100px">走行km</th></tr></thead><tbody>';
		foreach ( $by_store as $r2 ) {
			$w = (int) round( $r2->revenue / $max_store_rev * 100 );
			$share = $total_rev ? round( $r2->revenue / $total_rev * 100, 1 ) : 0;
			echo '<tr><td><strong>' . esc_html( BV_Util::label( $stores, $r2->store ) ) . '</strong></td>';
			echo '<td><div style="background:#2271b1;height:16px;border-radius:3px;width:' . $w . '%;min-width:2px;display:inline-block;vertical-align:middle"></div> <span style="font-size:12px;color:#666">' . $share . '%</span></td>';
			echo '<td style="text-align:right"><strong>' . esc_html( BV_Util::money( $r2->revenue ) ) . '</strong></td>';
			echo '<td style="text-align:right">' . (int) $r2->cnt . '</td>';
			echo '<td style="text-align:right">' . esc_html( $r2->cnt ? BV_Util::money( $r2->revenue / $r2->cnt ) : '—' ) . '</td>';
			echo '<td style="text-align:right">' . number_format( (int) $r2->days ) . '</td>';
			echo '<td style="text-align:right">' . (int) $r2->customers . '</td>';
			echo '<td style="text-align:right">' . number_format( (int) $r2->km ) . '</td></tr>';
		}
		if ( ! $by_store ) echo '<tr><td colspan="8">データがありません。</td></tr>';
		echo '</tbody></table>';

		/* ---- 月次推移（店舗別内訳つき） ---- */
		$monthly = $wpdb->get_results( "SELECT DATE_FORMAT(pickup_dt,'%Y-%m') ym, store, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$base_where} GROUP BY ym, store ORDER BY ym" );
		$months = array();
		foreach ( $monthly as $m ) {
			if ( ! isset( $months[ $m->ym ] ) ) $months[ $m->ym ] = array( 'total' => 0, 'cnt' => 0, 'stores' => array() );
			$months[ $m->ym ]['total'] += (int) $m->revenue;
			$months[ $m->ym ]['cnt']   += (int) $m->cnt;
			$months[ $m->ym ]['stores'][ $m->store ] = (int) $m->revenue;
		}
		$max_month = 1;
		foreach ( $months as $mm ) $max_month = max( $max_month, $mm['total'] );
		$palette = array( '#4e79a7', '#76b7b2', '#f28e2b', '#59a14f', '#b07aa1' );
		$store_colors = array();
		$ci = 0;
		foreach ( $stores as $sk => $st ) { $store_colors[ $sk ] = $palette[ $ci % count( $palette ) ]; $ci++; }

		/* 月次×言語 */
		$monthly_lang = $wpdb->get_results( "SELECT DATE_FORMAT(pickup_dt,'%Y-%m') ym, lang, COALESCE(SUM(price_total),0) revenue, COUNT(*) cnt
			FROM {$t} WHERE {$where_nolang} GROUP BY ym, lang ORDER BY ym" );
		$ml = array();
		foreach ( $monthly_lang as $m ) $ml[ $m->ym ][ $m->lang ] = array( 'revenue' => (int) $m->revenue, 'cnt' => (int) $m->cnt );

		echo '<h2>月次推移（積み上げ＝店舗別）' . ( $lang_filter ? '｜' . esc_html( $lang_labels[ $lang_filter ] ) . 'のみ' : '' ) . '</h2>';
		echo '<p style="font-size:12px">';
		foreach ( $stores as $sk => $st ) {
			echo '<span style="margin-right:14px"><i style="display:inline-block;width:12px;height:12px;border-radius:2px;background:' . esc_attr( $store_colors[ $sk ] ) . ';vertical-align:-1px;margin-right:4px"></i>' . esc_html( $st['ja'] ) . '</span>';
		}
		echo '</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		$cursor = strtotime( date( 'Y-m-01', strtotime( $from ) ) );
		$end_cursor = strtotime( date( 'Y-m-01', strtotime( $to ) ) );
		while ( $cursor <= $end_cursor ) {
			$key = date( 'Y-m', $cursor );
			$mm = isset( $months[ $key ] ) ? $months[ $key ] : array( 'total' => 0, 'cnt' => 0, 'stores' => array() );
			$bar_w = (int) round( $mm['total'] / $max_month * 100 );
			echo '<tr><td style="width:80px">' . esc_html( date( 'Y/n', $cursor ) ) . '</td>';
			echo '<td><div style="display:flex;height:18px;width:' . $bar_w . '%;min-width:2px;border-radius:3px;overflow:hidden">';
			foreach ( $mm['stores'] as $sk => $rev ) {
				if ( $rev <= 0 || $mm['total'] <= 0 ) continue;
				$seg = $rev / $mm['total'] * 100;
				echo '<div title="' . esc_attr( BV_Util::label( $stores, $sk ) . ' ' . BV_Util::money( $rev ) ) . '" style="width:' . round( $seg, 2 ) . '%;background:' . esc_attr( isset( $store_colors[ $sk ] ) ? $store_colors[ $sk ] : '#888' ) . '"></div>';
			}
			echo '</div></td>';
			echo '<td style="width:130px;text-align:right">' . esc_html( BV_Util::money( $mm['total'] ) ) . '</td>';
			echo '<td style="width:70px;text-align:right">' . (int) $mm['cnt'] . '件</td>';
			$mja = isset( $ml[ $key ]['ja'] ) ? $ml[ $key ]['ja'] : array( 'revenue' => 0, 'cnt' => 0 );
			$men = isset( $ml[ $key ]['en'] ) ? $ml[ $key ]['en'] : array( 'revenue' => 0, 'cnt' => 0 );
			echo '<td style="width:150px;text-align:right;font-size:12px;color:#555">日 ' . esc_html( BV_Util::money( $mja['revenue'] ) ) . '（' . $mja['cnt'] . '件）</td>';
			echo '<td style="width:150px;text-align:right;font-size:12px;color:#555">英 ' . esc_html( BV_Util::money( $men['revenue'] ) ) . '（' . $men['cnt'] . '件）</td>';
			echo '</tr>';
			$cursor = strtotime( '+1 month', $cursor );
		}
		echo '</tbody></table>';
		echo '<p class="description">右の2列は言語フィルタに関係なく、期間・店舗条件での日本語／英語の内訳です。</p>';

		/* ---- 車両クラス別 ---- */
		$by_class = $wpdb->get_results( "SELECT vehicle_class, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue,
			COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days
			FROM {$t} WHERE {$base_where} GROUP BY vehicle_class ORDER BY revenue DESC" );
		echo '<h2>車両クラス別</h2>';
		echo '<table class="wp-list-table widefat striped" style="max-width:760px"><thead><tr><th>クラス</th><th>件数</th><th>延日車数</th><th>売上</th><th>平均単価</th><th>構成比</th></tr></thead><tbody>';
		foreach ( $by_class as $r2 ) {
			$share = $total_rev ? round( $r2->revenue / $total_rev * 100, 1 ) : 0;
			echo '<tr><td>' . esc_html( BV_Util::label( $classes, $r2->vehicle_class ) ) . '</td><td>' . (int) $r2->cnt . '</td><td>' . number_format( (int) $r2->days ) . '</td>';
			echo '<td>' . esc_html( BV_Util::money( $r2->revenue ) ) . '</td><td>' . esc_html( $r2->cnt ? BV_Util::money( $r2->revenue / $r2->cnt ) : '—' ) . '</td><td>' . $share . '%</td></tr>';
		}
		if ( ! $by_class ) echo '<tr><td colspan="6">データがありません。</td></tr>';
		echo '</tbody></table>';

		/* ---- オプション・補償・その他の内訳 ---- */
		$opt = $wpdb->get_row( "SELECT
			COALESCE(SUM(opt_child_seat),0) cs, COALESCE(SUM(opt_junior_seat),0) js,
			COALESCE(SUM(opt_ski_rack),0) sr, COALESCE(SUM(opt_navi),0) nv, COALESCE(SUM(opt_etc),0) etc,
			SUM(CASE WHEN coverage='C' THEN 1 ELSE 0 END) covc,
			SUM(CASE WHEN coverage='B' THEN 1 ELSE 0 END) covb,
			SUM(CASE WHEN coverage='A' THEN 1 ELSE 0 END) cova,
			SUM(CASE WHEN shuttle!='none' THEN 1 ELSE 0 END) shuttle,
			SUM(CASE WHEN is_student=1 THEN 1 ELSE 0 END) student,
			SUM(CASE WHEN coupon_code!='' THEN 1 ELSE 0 END) coupon,
			SUM(CASE WHEN lang='en' THEN 1 ELSE 0 END) en
			FROM {$t} WHERE {$base_where}" );
		$pct = function ( $n ) use ( $total_cnt ) { return $total_cnt ? round( $n / $total_cnt * 100, 1 ) . '%' : '—'; };
		echo '<h2>オプション・属性の利用状況</h2>';
		echo '<table class="wp-list-table widefat striped" style="max-width:560px"><thead><tr><th>項目</th><th>数量／件数</th><th>利用率</th></tr></thead><tbody>';
		foreach ( array(
			array( 'チャイルドシート', (int) $opt->cs, null ),
			array( 'ジュニアシート', (int) $opt->js, null ),
			array( 'スキーラック', (int) $opt->sr, null ),
			array( 'カーナビ', (int) $opt->nv, null ),
			array( 'ETCユニット', (int) $opt->etc, null ),
			array( '補償C（NOC免除）', (int) $opt->covc, $pct( (int) $opt->covc ) ),
			array( '補償B（車両補償）', (int) $opt->covb, $pct( (int) $opt->covb ) ),
			array( '補償A（基本のみ）', (int) $opt->cova, $pct( (int) $opt->cova ) ),
			array( '送迎あり', (int) $opt->shuttle, $pct( (int) $opt->shuttle ) ),
			array( '学割', (int) $opt->student, $pct( (int) $opt->student ) ),
			array( 'クーポン利用', (int) $opt->coupon, $pct( (int) $opt->coupon ) ),
			array( '英語予約', (int) $opt->en, $pct( (int) $opt->en ) ),
		) as $row ) {
			echo '<tr><td>' . esc_html( $row[0] ) . '</td><td>' . number_format( $row[1] ) . '</td><td>' . esc_html( $row[2] ?: '' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		/* ---- 車両別 ---- */
		$by_vehicle = $wpdb->get_results( "SELECT vehicle_id, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue, COALESCE(SUM(trip_distance),0) km,
			COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days
			FROM {$t} WHERE {$base_where} AND vehicle_id > 0 GROUP BY vehicle_id ORDER BY revenue DESC" );

		echo '<h2>車両別 売上・経費・稼働</h2>';
		echo '<table class="wp-list-table widefat striped"><thead><tr><th>車両</th><th>クラス</th><th>場所</th><th>件数</th><th>延貸渡日数</th><th>稼働率</th><th>走行km</th><th>売上</th><th>期間経費</th><th>粗利</th></tr></thead><tbody>';
		$months_in_period = max( 1, $days_span / 30.4 );
		foreach ( $by_vehicle as $row ) {
			$v = BV_DB::get_vehicle( $row->vehicle_id );
			if ( ! $v ) continue;
			$cost = (int) round( (int) $v->monthly_cost * $months_in_period );
			$util = round( (int) $row->days / $days_span * 100, 1 );
			$profit = (int) $row->revenue - $cost;
			echo '<tr><td>' . esc_html( $v->name ) . '</td><td>' . esc_html( BV_Util::label( $classes, $v->class ) ) . '</td><td>' . esc_html( BV_Util::label( $locations, $v->location ) ) . '</td>';
			echo '<td>' . (int) $row->cnt . '</td><td>' . (int) $row->days . '日</td><td>' . $util . '%</td><td>' . number_format( (int) $row->km ) . '</td>';
			echo '<td>' . esc_html( BV_Util::money( $row->revenue ) ) . '</td><td>' . esc_html( BV_Util::money( $cost ) ) . '</td>';
			echo '<td><strong style="color:' . ( $profit >= 0 ? '#00a32a' : '#b32d2e' ) . '">' . esc_html( BV_Util::money( $profit ) ) . '</strong></td></tr>';
		}
		if ( ! $by_vehicle ) echo '<tr><td colspan="10">データがありません。</td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">期間経費 = 車両の月次経費 × 期間の月数。稼働率 = 延貸渡日数 ÷ 期間日数。</p>';
		echo '</div>';
	}

	/* ---------- 車両管理 ---------- */

	public static function vehicles() {
		if ( isset( $_GET['edit'] ) || isset( $_GET['new'] ) ) { self::vehicle_form( isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0 ); return; }

		/* 並び順保存 */
		if ( isset( $_POST['bvrm_save_order'] ) && check_admin_referer( 'bvrm_vorder' ) ) {
			foreach ( (array) $_POST['order'] as $vid => $ord ) {
				BV_DB::save_vehicle( array( 'sort_order' => (int) $ord ), (int) $vid );
			}
			echo '<div class="notice notice-success"><p>並び順を保存しました。ガントにも反映されます。</p></div>';
		}

		$list = BV_DB::get_vehicles();
		$classes = BV_Util::classes(); $locations = BV_Util::locations();
		echo '<div class="wrap"><h1 class="wp-heading-inline">車両管理</h1> <a href="' . esc_url( admin_url( 'admin.php?page=bvrm-vehicles&new=1' ) ) . '" class="page-title-action">車両を追加</a> ';
		echo '<a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-vehicles&bvrm_action=export_vehicles' ), 'bvrm_export_vehicles' ) ) . '">CSVダウンロード</a>';
		echo '<form method="post">';
		wp_nonce_field( 'bvrm_vorder' );
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:70px">並び順</th><th>車両名</th><th>クラス</th><th>場所</th><th>走行距離</th><th>車検日</th><th>任意保険期限</th><th>状態</th><th>操作</th></tr></thead><tbody>';
		foreach ( $list as $v ) {
			$warn = ( $v->shaken_date && strtotime( $v->shaken_date ) < strtotime( '+30 days' ) ) ? ' style="color:#b32d2e;font-weight:bold"' : '';
			echo '<tr><td><input type="number" name="order[' . (int) $v->id . ']" value="' . (int) $v->sort_order . '" style="width:60px"></td>';
			echo '<td><strong>' . esc_html( $v->name ) . '</strong><br><small>' . esc_html( $v->plate ) . '</small></td>';
			echo '<td>' . esc_html( BV_Util::label( $classes, $v->class ) ) . '</td><td>' . esc_html( BV_Util::label( $locations, $v->location ) ) . '</td>';
			echo '<td>' . number_format( (int) $v->mileage ) . ' km</td><td' . $warn . '>' . esc_html( $v->shaken_date ) . '</td><td>' . esc_html( $v->insurance_date ) . '</td>';
			echo '<td>' . ( $v->disposal_date && '0000-00-00' !== $v->disposal_date ? '廃車/売却' : ( $v->active ? '稼働' : '休止' ) ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=bvrm-vehicles&edit=' . $v->id ) ) . '">編集</a> | ';
			echo '<a style="color:#b32d2e" onclick="return confirm(\'「' . esc_js( $v->name ) . '」を削除します。予約履歴がある場合は休止になります。よろしいですか？\')" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-vehicles&bvrm_delete_vehicle=' . $v->id ), 'bvrm_delete_vehicle_' . $v->id ) ) . '">削除</a></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( '並び順を保存', 'secondary', 'bvrm_save_order' );
		echo '</form></div>';
	}

	/**
	 * 車両保存（admin_init で実行 → 保存後リダイレクト）
	 * 二重登録・リロードによる重複を防ぐ
	 */
	public static function handle_vehicle_save() {
		global $wpdb;
		if ( empty( $_POST['bvrm_save_vehicle'] ) || ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_GET['page'] ) || 'bvrm-vehicles' !== $_GET['page'] ) return;
		if ( ! check_admin_referer( 'bvrm_save_vehicle' ) ) return;

		$id = isset( $_POST['vehicle_id'] ) ? (int) $_POST['vehicle_id'] : 0;
		$P = wp_unslash( $_POST );
			$photos = array();
		for ( $i = 1; $i <= 4; $i++ ) $photos[] = esc_url_raw( $P[ 'photo' . $i ] ?? '' );
		$data = array(
				'name' => sanitize_text_field( $P['name'] ), 'plate' => sanitize_text_field( $P['plate'] ),
				'class' => sanitize_key( $P['class'] ), 'location' => sanitize_key( $P['location'] ),
				'color_number' => sanitize_text_field( $P['color_number'] ), 'mileage' => (int) $P['mileage'],
				'has_navi' => ! empty( $P['has_navi'] ) ? 1 : 0,
				'has_etc' => ! empty( $P['has_etc'] ) ? 1 : 0,
				'has_child_seat' => ! empty( $P['has_child_seat'] ) ? 1 : 0,
				'has_junior_seat' => ! empty( $P['has_junior_seat'] ) ? 1 : 0,
				'has_ski_rack' => ! empty( $P['has_ski_rack'] ) ? 1 : 0,
				'photos' => wp_json_encode( $photos ),
				'shaken_date' => sanitize_text_field( $P['shaken_date'] ) ?: null,
				'jibaiseki_date' => sanitize_text_field( $P['jibaiseki_date'] ) ?: null,
				'insurance_date' => sanitize_text_field( $P['insurance_date'] ) ?: null,
				'purchase_date' => sanitize_text_field( $P['purchase_date'] ) ?: null,
				'rental_reg_date' => sanitize_text_field( $P['rental_reg_date'] ) ?: null,
				'disposal_date' => sanitize_text_field( $P['disposal_date'] ) ?: null,
				'monthly_cost' => (int) $P['monthly_cost'],
				'tire_size' => sanitize_text_field( $P['tire_size'] ), 'wiper_length' => sanitize_text_field( $P['wiper_length'] ),
				'notes' => sanitize_textarea_field( $P['notes'] ),
				'active' => ! empty( $P['active'] ) ? 1 : 0,
			);
		$id = BV_DB::save_vehicle( $data, $id );
		/* メンテ記録追加 */
		if ( ! empty( $P['maint_date'] ) && ! empty( $P['maint_memo'] ) ) {
			$wpdb->insert( BV_DB::table( 'maintenance' ), array(
				'vehicle_id' => $id, 'mdate' => sanitize_text_field( $P['maint_date'] ),
				'mtype' => sanitize_key( $P['maint_type'] ), 'odometer' => (int) $P['maint_odo'],
				'cost' => (int) $P['maint_cost'], 'memo' => sanitize_textarea_field( $P['maint_memo'] ),
				'created_at' => current_time( 'mysql' ),
			) );
		}
		set_transient( 'bvrm_notice', '車両を保存しました。', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=bvrm-vehicles&edit=' . $id ) );
		exit;
	}

	/** 車両削除（関連予約がある場合は無効化のみ） */
	public static function handle_vehicle_delete() {
		global $wpdb;
		if ( empty( $_GET['bvrm_delete_vehicle'] ) || ! current_user_can( 'manage_options' ) ) return;
		$id = (int) $_GET['bvrm_delete_vehicle'];
		check_admin_referer( 'bvrm_delete_vehicle_' . $id );

		$v = BV_DB::get_vehicle( $id );
		if ( ! $v ) {
			set_transient( 'bvrm_notice', '車両が見つかりません。', 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=bvrm-vehicles' ) );
			exit;
		}
		$used = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . BV_DB::table( 'reservations' ) . ' WHERE vehicle_id = %d', $id
		) );
		if ( $used > 0 && empty( $_GET['force'] ) ) {
			/* 履歴保持のため無効化（予約からの参照は残す） */
			BV_DB::save_vehicle( array( 'active' => 0 ), $id );
			set_transient( 'bvrm_notice', 'この車両には予約履歴が ' . $used . ' 件あるため、削除せず「休止」にしました（売上分析・報告書の履歴を保持するためです）。完全に削除する場合は編集画面の「完全に削除」をご利用ください。', 120 );
		} else {
			$wpdb->delete( BV_DB::table( 'maintenance' ), array( 'vehicle_id' => $id ) );
			$wpdb->delete( BV_DB::table( 'vehicles' ), array( 'id' => $id ) );
			if ( $used > 0 ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE ' . BV_DB::table( 'reservations' ) . ' SET vehicle_id = 0 WHERE vehicle_id = %d', $id
				) );
			}
			set_transient( 'bvrm_notice', '車両「' . $v->name . '」を削除しました。', 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=bvrm-vehicles' ) );
		exit;
	}

	protected static function vehicle_form( $id ) {
		global $wpdb;
		$v = $id ? BV_DB::get_vehicle( $id ) : null;
		$classes = BV_Util::classes(); $locations = BV_Util::locations();
		$val = function ( $k, $d = '' ) use ( $v ) { return $v ? $v->$k : $d; };
		$photos = $v && $v->photos ? ( json_decode( $v->photos, true ) ?: array() ) : array();

		echo '<div class="wrap"><h1>' . ( $id ? '車両編集' : '車両追加' ) . '</h1>';
		BV_Admin::notice();
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=bvrm-vehicles' ) ) . '">« 車両一覧へ戻る</a></p>';
		echo '<form method="post">';
		wp_nonce_field( 'bvrm_save_vehicle' );
		echo '<input type="hidden" name="bvrm_save_vehicle" value="1">';
		echo '<input type="hidden" name="vehicle_id" value="' . (int) $id . '">';
		echo '<table class="form-table">';
		echo '<tr><th>車両名</th><td><input type="text" name="name" class="regular-text" required value="' . esc_attr( $val( 'name' ) ) . '"></td></tr>';
		echo '<tr><th>ナンバー</th><td><input type="text" name="plate" value="' . esc_attr( $val( 'plate' ) ) . '"></td></tr>';
		echo '<tr><th>クラス</th><td><select name="class">';
		foreach ( $classes as $k => $c ) echo '<option value="' . $k . '"' . selected( $val( 'class', 'compact' ), $k, false ) . '>' . esc_html( $c['ja'] ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th>場所</th><td><select name="location">';
		foreach ( $locations as $k => $c ) echo '<option value="' . $k . '"' . selected( $val( 'location', 'hakuba_norikura' ), $k, false ) . '>' . esc_html( $c['ja'] ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th>カラー番号</th><td><input type="text" name="color_number" value="' . esc_attr( $val( 'color_number' ) ) . '"></td></tr>';
		echo '<tr><th>走行距離(km)</th><td><input type="number" name="mileage" value="' . (int) $val( 'mileage', 0 ) . '"> <span class="description">返却処理で自動更新されます</span></td></tr>';
		echo '<tr><th>装備</th><td>';
		echo '<label style="margin-right:16px"><input type="checkbox" name="has_navi" value="1"' . checked( (int) $val( 'has_navi', 0 ), 1, false ) . '> カーナビ</label>';
		echo '<label style="margin-right:16px"><input type="checkbox" name="has_etc" value="1"' . checked( (int) $val( 'has_etc', 0 ), 1, false ) . '> ETCユニット</label>';
		echo '<label style="margin-right:16px"><input type="checkbox" name="has_child_seat" value="1"' . checked( (int) $val( 'has_child_seat', 0 ), 1, false ) . '> チャイルドシート</label>';
		echo '<label style="margin-right:16px"><input type="checkbox" name="has_junior_seat" value="1"' . checked( (int) $val( 'has_junior_seat', 0 ), 1, false ) . '> ジュニアシート</label>';
		echo '<label><input type="checkbox" name="has_ski_rack" value="1"' . checked( (int) $val( 'has_ski_rack', 0 ), 1, false ) . '> スキーラック</label>';
		echo '<p class="description">ETCユニット搭載車でも、<strong>ETCカードのレンタルは行っていません</strong>（お客様ご自身のETCカードが必要です）。</p></td></tr>';
		echo '<tr><th>写真（URL×4）</th><td>';
		for ( $i = 1; $i <= 4; $i++ ) {
			echo '<input type="url" name="photo' . $i . '" class="large-text" style="margin-bottom:4px" placeholder="写真' . $i . ' URL（メディアライブラリから）" value="' . esc_attr( $photos[ $i - 1 ] ?? '' ) . '">';
		}
		echo '</td></tr>';
		foreach ( array(
			'shaken_date' => '車検日', 'jibaiseki_date' => '自賠責期限', 'insurance_date' => '任意保険期限',
			'purchase_date' => '購入日', 'rental_reg_date' => 'レンタカー登録日', 'disposal_date' => '廃車・売却日',
		) as $k => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="date" name="' . $k . '" value="' . esc_attr( $val( $k ) ) . '"></td></tr>';
		}
		echo '<tr><th>月次経費(円)</th><td><input type="number" name="monthly_cost" value="' . (int) $val( 'monthly_cost', 0 ) . '"> <span class="description">リース料・保険・駐車場等の合計/月</span></td></tr>';
		echo '<tr><th>タイヤサイズ</th><td><input type="text" name="tire_size" value="' . esc_attr( $val( 'tire_size' ) ) . '"></td></tr>';
		echo '<tr><th>ワイパー長</th><td><input type="text" name="wiper_length" value="' . esc_attr( $val( 'wiper_length' ) ) . '"></td></tr>';
		echo '<tr><th>備考</th><td><textarea name="notes" rows="3" class="large-text">' . esc_textarea( $val( 'notes' ) ) . '</textarea></td></tr>';
		echo '<tr><th>稼働状態</th><td><label><input type="checkbox" name="active" value="1"' . checked( (int) $val( 'active', 1 ), 1, false ) . '> 稼働中（予約受付対象）</label></td></tr>';

		/* メンテナンス記録 */
		echo '<tr><th>メンテ記録を追加</th><td>';
		echo '<input type="date" name="maint_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"> ';
		echo '<select name="maint_type"><option value="maintenance">メンテナンス</option><option value="inspection6">6か月定期点検</option></select> ';
		echo 'メーター <input type="number" name="maint_odo" style="width:100px"> km 費用 <input type="number" name="maint_cost" style="width:100px"> 円<br>';
		echo '<textarea name="maint_memo" rows="2" class="large-text" placeholder="内容（オイル交換、タイヤ交換など）"></textarea></td></tr>';
		echo '</table>';
		submit_button( $id ? '車両を更新' : '車両を追加' );
		echo '</form>';

		/* 削除ボタン */
		if ( $id ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . BV_DB::table( 'reservations' ) . ' WHERE vehicle_id = %d', $id
			) );
			echo '<hr><h2>この車両を削除</h2>';
			if ( $used > 0 ) {
				echo '<p class="description">この車両には予約履歴が <strong>' . $used . ' 件</strong>あります。売上分析や貸渡実績報告書の履歴を残すため、通常は「休止にする」をおすすめします。</p>';
				echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-vehicles&bvrm_delete_vehicle=' . $id ), 'bvrm_delete_vehicle_' . $id ) ) . '">休止にする（履歴を残す）</a> ';
				echo '<a class="button" style="color:#b32d2e;border-color:#b32d2e" onclick="return confirm(\'予約履歴からもこの車両の紐付けが外れます。本当に完全削除しますか？この操作は取り消せません。\')" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-vehicles&bvrm_delete_vehicle=' . $id . '&force=1' ), 'bvrm_delete_vehicle_' . $id ) ) . '">完全に削除する</a></p>';
			} else {
				echo '<p><a class="button" style="color:#b32d2e;border-color:#b32d2e" onclick="return confirm(\'この車両を削除します。よろしいですか？\')" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-vehicles&bvrm_delete_vehicle=' . $id . '&force=1' ), 'bvrm_delete_vehicle_' . $id ) ) . '">この車両を削除する</a></p>';
			}
		}

		if ( $id ) {
			$maint = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . BV_DB::table( 'maintenance' ) . ' WHERE vehicle_id = %d ORDER BY mdate DESC', $id ) );
			echo '<h2>メンテナンス・定期点検履歴</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>日付</th><th>種別</th><th>メーター</th><th>費用</th><th>内容</th></tr></thead><tbody>';
			foreach ( $maint as $m ) {
				echo '<tr><td>' . esc_html( $m->mdate ) . '</td><td>' . ( 'inspection6' === $m->mtype ? '6か月定期点検' : 'メンテナンス' ) . '</td><td>' . number_format( (int) $m->odometer ) . ' km</td><td>' . esc_html( BV_Util::money( $m->cost ) ) . '</td><td>' . esc_html( $m->memo ) . '</td></tr>';
			}
			if ( ! $maint ) echo '<tr><td colspan="5">記録がありません。</td></tr>';
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/* ---------- 顧客・会員 ---------- */

	public static function customers() {
		if ( isset( $_GET['email'] ) ) { self::customer_detail( sanitize_email( wp_unslash( $_GET['email'] ) ) ); return; }
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$rows = $wpdb->get_results( "SELECT email, MAX(CONCAT(sei,' ',mei)) name, MAX(phone) phone, COUNT(*) cnt, SUM(price_total) total, MAX(customer_id) member_id, MAX(pickup_dt) last_use FROM {$t} WHERE email != '' AND status != 'cancelled' GROUP BY email ORDER BY last_use DESC LIMIT 500" );
		echo '<div class="wrap"><h1>顧客・会員</h1>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>氏名</th><th>メール</th><th>電話</th><th>会員</th><th>利用回数</th><th>累計金額</th><th>最終利用</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $c ) {
			echo '<tr><td>' . esc_html( $c->name ) . '</td><td>' . esc_html( $c->email ) . '</td><td>' . esc_html( $c->phone ) . '</td>';
			echo '<td>' . ( $c->member_id ? '会員' : '—' ) . '</td>';
			echo '<td>' . (int) $c->cnt . '</td><td>' . esc_html( BV_Util::money( $c->total ) ) . '</td><td>' . esc_html( date( 'Y-m-d', strtotime( $c->last_use ) ) ) . '</td>';
			echo '<td><a class="button" href="' . esc_url( admin_url( 'admin.php?page=bvrm-customers&email=' . rawurlencode( $c->email ) ) ) . '">詳細・編集</a></td></tr>';
		}
		if ( ! $rows ) echo '<tr><td colspan="8">顧客データがありません。</td></tr>';
		echo '</tbody></table></div>';
	}

	/** 顧客詳細・編集（管理者用） */
	protected static function customer_detail( $email ) {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		if ( ! is_email( $email ) ) { echo '<div class="wrap"><p>メールアドレスが不正です。</p></div>'; return; }

		/* 保存処理 */
		if ( isset( $_POST['bvrm_save_customer'] ) && check_admin_referer( 'bvrm_save_customer' ) ) {
			$P = wp_unslash( $_POST );
			$sei = sanitize_text_field( $P['sei'] ); $mei = sanitize_text_field( $P['mei'] );
			$phone = sanitize_text_field( $P['phone'] ); $address = sanitize_textarea_field( $P['address'] );
			$birthdate = sanitize_text_field( $P['birthdate'] );

			/* 会員アカウント更新 */
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$upd = array( 'ID' => $user->ID, 'last_name' => $sei, 'first_name' => $mei );
				$newpass = (string) $P['new_password'];
				if ( '' !== $newpass && strlen( $newpass ) >= 8 ) $upd['user_pass'] = $newpass;
				wp_update_user( $upd );
				update_user_meta( $user->ID, 'bv_phone', $phone );
				update_user_meta( $user->ID, 'bv_address', $address );
				update_user_meta( $user->ID, 'bv_birthdate', $birthdate );
			}
			/* 今後の予約（未返却・未キャンセル）にも反映 */
			if ( ! empty( $P['apply_reservations'] ) ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$t} SET sei = %s, mei = %s, phone = %s, address = %s, birthdate = %s WHERE email = %s AND status IN ('pending','confirmed','in_use')",
					$sei, $mei, $phone, $address, $birthdate ?: null, $email
				) );
			}
			echo '<div class="notice notice-success"><p>顧客情報を保存しました。</p></div>';
		}

		$reservations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s ORDER BY pickup_dt DESC LIMIT 100", $email ) );
		$latest = $reservations ? $reservations[0] : null;
		$user = get_user_by( 'email', $email );

		/* 表示値: 会員情報優先、なければ最新予約 */
		$sei = $user ? $user->last_name : ( $latest ? $latest->sei : '' );
		$mei = $user ? $user->first_name : ( $latest ? $latest->mei : '' );
		$phone = $user ? get_user_meta( $user->ID, 'bv_phone', true ) : ( $latest ? $latest->phone : '' );
		$address = $user ? get_user_meta( $user->ID, 'bv_address', true ) : ( $latest ? $latest->address : '' );
		$birthdate = $user ? get_user_meta( $user->ID, 'bv_birthdate', true ) : ( $latest ? $latest->birthdate : '' );
		if ( ! $phone && $latest ) $phone = $latest->phone;
		if ( ! $address && $latest ) $address = $latest->address;
		if ( ! $birthdate && $latest ) $birthdate = $latest->birthdate;

		/* 免許証等の画像（直近の予約から収集） */
		$files = array();
		foreach ( $reservations as $res ) {
			$f = json_decode( (string) $res->license_files, true );
			if ( is_array( $f ) && $f ) { $files = $f; break; }
		}
		$file_labels = array( 'license_front' => '免許証（表）/マイナ免許証', 'license_back' => '免許証（裏）', 'passport' => 'パスポート', 'intl_license' => '国際免許証' );

		echo '<div class="wrap"><h1>顧客詳細：' . esc_html( trim( $sei . ' ' . $mei ) ?: $email ) . '</h1>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=bvrm-customers' ) ) . '">« 顧客一覧へ戻る</a></p>';

		echo '<form method="post">';
		wp_nonce_field( 'bvrm_save_customer' );
		echo '<input type="hidden" name="bvrm_save_customer" value="1">';
		echo '<table class="form-table">';
		echo '<tr><th>メール</th><td><code>' . esc_html( $email ) . '</code>　' . ( $user ? '<span style="color:green">会員登録済み</span>（<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $user->ID ) ) . '">WPユーザー</a>）' : '非会員（電話予約など）' ) . '</td></tr>';
		echo '<tr><th>氏名</th><td>姓 <input type="text" name="sei" value="' . esc_attr( $sei ) . '"> 名 <input type="text" name="mei" value="' . esc_attr( $mei ) . '"></td></tr>';
		echo '<tr><th>電話番号</th><td><input type="text" name="phone" value="' . esc_attr( $phone ) . '"></td></tr>';
		echo '<tr><th>住所</th><td><textarea name="address" rows="2" class="large-text">' . esc_textarea( $address ) . '</textarea></td></tr>';
		echo '<tr><th>生年月日</th><td><input type="date" name="birthdate" value="' . esc_attr( $birthdate ) . '"></td></tr>';
		echo '<tr><th>免許証等の画像</th><td>';
		if ( $files ) {
			foreach ( $files as $k => $u ) {
				$label = isset( $file_labels[ $k ] ) ? $file_labels[ $k ] : $k;
				$link  = BV_Files::url( $u, 'adm' );
				echo '<a href="' . esc_url( $link ) . '" target="_blank" rel="noreferrer" style="display:inline-block;margin:0 12px 8px 0">';
				if ( BV_Files::is_image( $u ) ) {
					echo '<img src="' . esc_url( $link ) . '" style="max-height:110px;border:1px solid #ccc;border-radius:4px;display:block">';
				}
				echo esc_html( $label ) . '</a>';
			}
			echo '<p class="description">閲覧リンクは2時間で失効し、管理者としてログインしている間だけ開けます。</p>';
		} else {
			echo '—（アップロードなし）';
		}
		echo '</td></tr>';
		if ( $user ) {
			echo '<tr><th>パスワード</th><td>セキュリティのため表示できません（暗号化保存）。<br>再設定: <input type="password" name="new_password" autocomplete="new-password" placeholder="新しいパスワード（8文字以上）"></td></tr>';
		} else {
			echo '<input type="hidden" name="new_password" value="">';
		}
		echo '<tr><th>予約への反映</th><td><label><input type="checkbox" name="apply_reservations" value="1" checked> 進行中の予約（仮予約・確定・貸出中）の連絡先情報にも反映する</label></td></tr>';
		echo '</table>';
		submit_button( '顧客情報を保存' );
		echo '</form>';

		echo '<h2>予約履歴</h2>';
		$statuses = BV_Util::statuses(); $classes = BV_Util::classes(); $stores = BV_Util::stores();
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>予約番号</th><th>ステータス</th><th>貸出</th><th>返却</th><th>店舗</th><th>クラス</th><th>金額</th><th>支払</th><th></th></tr></thead><tbody>';
		foreach ( $reservations as $res ) {
			echo '<tr><td>' . esc_html( $res->code ) . '</td><td>' . esc_html( BV_Util::label( $statuses, $res->status ) ) . '</td>';
			echo '<td>' . esc_html( date( 'Y-m-d H:i', strtotime( $res->pickup_dt ) ) ) . '</td><td>' . esc_html( date( 'Y-m-d H:i', strtotime( $res->return_dt ) ) ) . '</td>';
			echo '<td>' . esc_html( BV_Util::label( $stores, $res->store ) ) . '</td><td>' . esc_html( BV_Util::label( $classes, $res->vehicle_class ) ) . '</td>';
			$ps = BV_Util::payment_state( $res );
			echo '<td>' . esc_html( BV_Util::money( $res->price_total ) ) . '</td>';
			echo '<td><span style="color:' . esc_attr( $ps['color'] ) . '">' . esc_html( $ps['short'] ) . '</span>';
			if ( '' !== $ps['sub'] ) echo '<br><span style="font-size:11px;color:#2271b1">' . esc_html( $ps['sub'] ) . '</span>';
			echo '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $res->id ) ) . '">予約を開く</a></td></tr>';
		}
		if ( ! $reservations ) echo '<tr><td colspan="9">予約履歴がありません。</td></tr>';
		echo '</tbody></table></div>';
	}

	/* ---------- クーポン ---------- */

	public static function coupons() {
		global $wpdb;
		$t = BV_DB::table( 'coupons' );
		if ( isset( $_POST['bvrm_add_coupon'] ) && check_admin_referer( 'bvrm_coupon' ) ) {
			$P = wp_unslash( $_POST );
			$wpdb->replace( $t, array(
				'code' => sanitize_text_field( $P['code'] ), 'label' => sanitize_text_field( $P['label'] ),
				'discount_type' => ( 'percent' === $P['discount_type'] ? 'percent' : 'fixed' ),
				'amount' => (int) $P['amount'], 'expires' => sanitize_text_field( $P['expires'] ) ?: null, 'active' => 1,
				'max_uses' => max( 0, (int) ( $P['max_uses'] ?? 0 ) ),
			) );
			echo '<div class="notice notice-success"><p>クーポンを保存しました。</p></div>';
		}
		if ( isset( $_GET['toggle'] ) && check_admin_referer( 'bvrm_coupon_toggle' ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET active = 1 - active WHERE id = %d", (int) $_GET['toggle'] ) );
		}
		$list = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC" );
		echo '<div class="wrap"><h1>クーポン</h1>';
		echo '<h2>登録・更新</h2><form method="post">';
		wp_nonce_field( 'bvrm_coupon' );
		echo '<input type="hidden" name="bvrm_add_coupon" value="1">';
		echo 'コード <input type="text" name="code" required style="width:220px"> 名称 <input type="text" name="label" style="width:220px"> ';
		echo '<select name="discount_type"><option value="fixed">固定額(円)</option><option value="percent">割引率(%)</option></select> ';
		echo '<input type="number" name="amount" required style="width:100px"> 期限 <input type="date" name="expires"> ';
		echo '利用上限 <input type="number" name="max_uses" min="0" step="1" value="0" style="width:80px" title="0なら回数無制限"> 回 ';
		submit_button( '保存', 'primary', '', false );
		echo '<p class="description">利用上限は0で無制限です。既存のコードと同じコードを保存すると、そのクーポンは上書きされ利用回数もリセットされます。<br>「REV」で始まるクーポンは、口コミのお礼として自動発行されたものです（1回限り）。</p>';
		echo '</form><h2>一覧</h2><table class="wp-list-table widefat fixed striped"><thead><tr><th>コード</th><th>名称</th><th>内容</th><th>期限</th><th>利用</th><th>発行元</th><th>状態</th><th></th></tr></thead><tbody>';
		foreach ( $list as $c ) {
			echo '<tr><td><code>' . esc_html( $c->code ) . '</code></td><td>' . esc_html( $c->label ) . '</td>';
			echo '<td>' . ( 'percent' === $c->discount_type ? (int) $c->amount . '% OFF' : esc_html( BV_Util::money( $c->amount ) ) . ' OFF' ) . '</td>';
			echo '<td>' . esc_html( $c->expires ?: '無期限' ) . '</td>';
			$max = (int) ( $c->max_uses ?? 0 );
			echo '<td>' . (int) $c->used_count . ( $max ? ' / ' . $max : ' 回（無制限）' ) . '</td>';
			echo '<td>' . ( ! empty( $c->note ) ? esc_html( $c->note ) : '<span style="color:#999">—</span>' ) . '</td>';
			$used_up = ( $max > 0 && (int) $c->used_count >= $max );
			echo '<td>' . ( ! $c->active
				? '<span style="color:#999">無効</span>'
				: ( $used_up ? '<span style="color:#999">使用済み</span>' : '有効' ) ) . '</td>';
			echo '<td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-coupons&toggle=' . $c->id ), 'bvrm_coupon_toggle' ) ) . '">' . ( $c->active ? '無効化' : '有効化' ) . '</a></td></tr>';
		}
		if ( ! $list ) echo '<tr><td colspan="8">クーポンがありません。</td></tr>';
		echo '</tbody></table></div>';
	}

	/* ---------- 料金カレンダー ---------- */

	public static function rates() {
		$s = BV_Util::settings();
		if ( isset( $_POST['bvrm_save_rates'] ) && check_admin_referer( 'bvrm_rates' ) ) {
			$P = wp_unslash( $_POST );
			$new = array(
				'rate_default_category' => ( 'normal' === $P['rate_default_category'] ? 'normal' : 'green' ),
				'longterm_3days'  => max( 0, min( 90, (int) $P['longterm_3days'] ) ),
				'longterm_7days'  => max( 0, min( 90, (int) $P['longterm_7days'] ) ),
				'longterm_14days' => max( 0, min( 90, (int) $P['longterm_14days'] ) ),
				'longterm_green'  => ! empty( $P['longterm_green'] ) ? 1 : 0,
				'monthly_2m'      => max( 0, min( 90, (int) $P['monthly_2m'] ) ),
				'monthly_3m'      => max( 0, min( 90, (int) $P['monthly_3m'] ) ),
				'monthly_cap'     => ! empty( $P['monthly_cap'] ) ? 1 : 0,
			);
			foreach ( array( 'kei', 'compact', 'suv', 'minivan' ) as $c ) {
				foreach ( array( 'normal', 'green', 'month' ) as $cat ) {
					$new[ "rate_{$cat}_{$c}" ] = (int) $P[ "rate_{$cat}_{$c}" ];
				}
				/* 学割は対象クラスのみ入力欄がある */
				if ( isset( $P[ "rate_student_{$c}" ] ) )       $new[ "rate_student_{$c}" ] = (int) $P[ "rate_student_{$c}" ];
				if ( isset( $P[ "rate_student_green_{$c}" ] ) ) $new[ "rate_student_green_{$c}" ] = (int) $P[ "rate_student_green_{$c}" ];
				$new[ "hourly_rate_{$c}" ] = max( 0, (int) ( $P[ "hourly_rate_{$c}" ] ?? 0 ) );
			}
			$new['hourly_cov_b']   = max( 0, (int) ( $P['hourly_cov_b'] ?? 0 ) );
			$new['hourly_cov_c']   = max( 0, (int) ( $P['hourly_cov_c'] ?? 0 ) );
			$new['hourly_day_cap'] = max( 0, (int) ( $P['hourly_day_cap'] ?? 0 ) );
			BV_Util::update_settings( $new );
			$s = BV_Util::settings();
			echo '<div class="notice notice-success"><p>料金を保存しました。</p></div>';
		}
		if ( isset( $_POST['bvrm_save_days'] ) && check_admin_referer( 'bvrm_rate_days' ) ) {
			$month = sanitize_text_field( wp_unslash( $_POST['cal_month'] ) );
			$days_in = (int) date( 't', strtotime( $month . '-01' ) );
			for ( $d = 1; $d <= $days_in; $d++ ) {
				$date = $month . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
				$cat = sanitize_key( $_POST['day'][ $d ] ?? 'default' );
				BV_DB::set_rate_day( $date, in_array( $cat, array( 'normal', 'green' ), true ) ? $cat : 'default' );
			}
			echo '<div class="notice notice-success"><p>カレンダーを保存しました。</p></div>';
		}

		$classes = BV_Util::classes();
		echo '<div class="wrap"><h1>料金カレンダー・料金設定</h1>';
		echo '<h2>基本料金（24時間単位）</h2><form method="post">';
		wp_nonce_field( 'bvrm_rates' );
		echo '<input type="hidden" name="bvrm_save_rates" value="1">';
		echo '<table class="widefat" style="max-width:960px"><thead><tr><th>クラス</th><th>通常料金/日</th><th>グリーンシーズン/日</th><th>1か月料金</th><th>学割 通常/日</th><th>学割 グリーン/日</th></tr></thead><tbody>';
		foreach ( $classes as $k => $c ) {
			echo '<tr><td>' . esc_html( $c['ja'] ) . '</td>';
			foreach ( array( 'normal', 'green', 'month' ) as $cat ) {
				echo '<td><input type="number" name="rate_' . $cat . '_' . $k . '" value="' . (int) $s[ "rate_{$cat}_{$k}" ] . '" style="width:110px"> 円</td>';
			}
			if ( BV_Util::is_student_class( $k ) ) {
				echo '<td><input type="number" name="rate_student_' . $k . '" value="' . (int) $s[ "rate_student_{$k}" ] . '" style="width:110px"> 円</td>';
				echo '<td><input type="number" name="rate_student_green_' . $k . '" value="' . (int) $s[ "rate_student_green_{$k}" ] . '" style="width:110px"> 円</td>';
			} else {
				echo '<td colspan="2" style="color:#646970">学割対象外</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		$sc_names = array();
		foreach ( BV_Util::student_classes() as $sk2 ) $sc_names[] = BV_Util::label( $classes, $sk2 );
		echo '<p class="description">学割は<strong>' . esc_html( implode( '・', $sc_names ) ) . '</strong>のみが対象です。予約フォームでも、対象クラスを選んだときだけ学割のチェックボックスが表示されます。<br>'
			. '学割料金は固定価格で、長期割引・月額上限・クーポンとは併用しません。日ごとに通常／グリーンシーズンを判定して計算します。</p>';
		echo '<h2>長期割引・月額上限</h2>';
		echo '<table class="widefat" style="max-width:820px"><tbody>';
		echo '<tr><td style="width:220px">3日以上（1日あたり）</td><td><input type="number" name="longterm_3days" value="' . (int) $s['longterm_3days'] . '" style="width:70px" min="0" max="90"> % OFF</td></tr>';
		echo '<tr><td>7日以上（1日あたり）</td><td><input type="number" name="longterm_7days" value="' . (int) $s['longterm_7days'] . '" style="width:70px" min="0" max="90"> % OFF</td></tr>';
		echo '<tr><td>14日以上（1日あたり）</td><td><input type="number" name="longterm_14days" value="' . (int) $s['longterm_14days'] . '" style="width:70px" min="0" max="90"> % OFF</td></tr>';
		echo '<tr><td>グリーンシーズンにも適用</td><td><label><input type="checkbox" name="longterm_green" value="1"' . checked( (int) $s['longterm_green'], 1, false ) . '> 適用する（既定：適用しない＝グリーンは定額）</label></td></tr>';
		echo '<tr><td><strong>マンスリー割引</strong>（2か月以上）</td><td><input type="number" name="monthly_2m" value="' . (int) $s['monthly_2m'] . '" style="width:70px" min="0" max="90"> % OFF（月額料金に対して）</td></tr>';
		echo '<tr><td><strong>マンスリー割引</strong>（3か月以上）</td><td><input type="number" name="monthly_3m" value="' . (int) $s['monthly_3m'] . '" style="width:70px" min="0" max="90"> % OFF（月額料金に対して）</td></tr>';
		echo '<tr><td>月額料金の上限</td><td><label><input type="checkbox" name="monthly_cap" value="1"' . checked( (int) $s['monthly_cap'], 1, false ) . '> 日額の合計が月額を超えたら月額に丸める（推奨）</label></td></tr>';
		echo '</tbody></table>';
		/* ---- 時間貸し（From P出張所など） ---- */
		$hourly_stores = array();
		foreach ( BV_Util::stores() as $hk => $hv ) if ( BV_Util::is_hourly_store( $hk ) ) $hourly_stores[] = $hv['ja'];
		echo '<h2>時間貸し料金（' . esc_html( $hourly_stores ? implode( '・', $hourly_stores ) : '時間貸し店舗' ) . '）</h2>';
		echo '<table class="widefat" style="max-width:820px"><thead><tr><th>クラス</th><th>1時間あたり</th></tr></thead><tbody>';
		foreach ( $classes as $k => $c ) {
			$avail = array();
			foreach ( BV_Util::stores() as $hk => $hv ) if ( BV_Util::is_hourly_store( $hk ) && in_array( $k, BV_Util::store_classes( $hk ), true ) ) $avail[] = $hv['ja'];
			echo '<tr><td>' . esc_html( $c['ja'] ) . ( $avail ? '' : ' <span style="color:#646970">（時間貸し店舗では取扱なし）</span>' ) . '</td>';
			echo '<td><input type="number" name="hourly_rate_' . $k . '" value="' . (int) $s[ "hourly_rate_{$k}" ] . '" style="width:110px" min="0"> 円</td></tr>';
		}
		echo '<tr><td>補償オプションB</td><td><input type="number" name="hourly_cov_b" value="' . (int) $s['hourly_cov_b'] . '" style="width:110px" min="0"> 円／時間</td></tr>';
		echo '<tr><td>補償オプションC</td><td><input type="number" name="hourly_cov_c" value="' . (int) $s['hourly_cov_c'] . '" style="width:110px" min="0"> 円／時間</td></tr>';
		echo '<tr><td>24時間ごとの上限額</td><td><input type="number" name="hourly_day_cap" value="' . (int) $s['hourly_day_cap'] . '" style="width:110px" min="0"> 円（0＝上限なし）';
		echo '<p class="description">24時間ごとに上限をかけ、24時間を超えた分は超過した時間数だけ時間料金で加算します。端数の時間にも同じ上限がかかります。<br>'
			. '例：1時間2,200円・上限11,000円のとき　10時間＝11,000円（上限）／24時間＝11,000円／<strong>25時間＝13,200円（11,000円＋2,200円）</strong>／48時間＝22,000円</p></td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">時間貸し店舗では、車両料金と補償オプションを<strong>1時間単位（端数切り上げ）</strong>で計算します。装備オプション（チャイルドシート・ジュニアシート・スキーキャリア等）は他店と同じく<strong>1日単位</strong>です。学割・長期割引・月額料金は適用しません。</p>';

		echo '<p class="description">長期割引は通常料金の日額に適用されます。30日ごとに月額料金を適用し、端数日は日額（長期割引後）で計算、その合計が月額を超える場合は月額で頭打ちになります。<br>マンスリー割引は、貸出が2か月（60日）以上・3か月（90日）以上になる場合に月額料金そのものを割り引きます（例：月額22万円で3か月＝22万円×0.8×3＝52.8万円）。端数日の月額上限にも、割引後の月額が適用されます。</p>';

		echo '<p>日付指定のない日のデフォルト料金区分: ';
		echo '<select name="rate_default_category"><option value="green"' . selected( $s['rate_default_category'], 'green', false ) . '>グリーンシーズン</option><option value="normal"' . selected( $s['rate_default_category'], 'normal', false ) . '>通常料金</option></select></p>';
		submit_button( '料金を保存' );
		echo '</form>';

		/* 日別カレンダー */
		$month = isset( $_GET['cal'] ) ? sanitize_text_field( wp_unslash( $_GET['cal'] ) ) : date( 'Y-m' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) $month = date( 'Y-m' );
		$prev = date( 'Y-m', strtotime( $month . '-01 -1 month' ) );
		$next = date( 'Y-m', strtotime( $month . '-01 +1 month' ) );
		$days_in = (int) date( 't', strtotime( $month . '-01' ) );
		$overrides = BV_DB::get_rate_categories( $month . '-01', $month . '-' . $days_in );

		echo '<h2>日別料金区分（GW・夏休みなどは「通常料金」に設定）</h2>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=bvrm-rates&cal=' . $prev ) ) . '">« ' . esc_html( $prev ) . '</a> <strong style="margin:0 12px">' . esc_html( $month ) . '</strong> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=bvrm-rates&cal=' . $next ) ) . '">' . esc_html( $next ) . ' »</a></p>';
		echo '<form method="post">';
		wp_nonce_field( 'bvrm_rate_days' );
		echo '<input type="hidden" name="bvrm_save_days" value="1"><input type="hidden" name="cal_month" value="' . esc_attr( $month ) . '">';
		/* 一括設定ツール */
		echo '<p style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:10px 14px;display:inline-block">';
		echo '<strong>一括設定：</strong> ';
		echo '<button type="button" class="button" data-bulk="normal">全て「通常」</button> ';
		echo '<button type="button" class="button" data-bulk="green">全て「グリーン」</button> ';
		echo '<button type="button" class="button" data-bulk="default">全て「既定」</button> ';
		echo '<button type="button" class="button" data-bulk="weekend-normal">土日祝のみ「通常」</button>';
		echo '</p>';

		$wd = array( '日', '月', '火', '水', '木', '金', '土' );
		$first_w = (int) date( 'w', strtotime( $month . '-01' ) );

		echo '<style>
		.bvcal{border-collapse:collapse;table-layout:fixed;width:100%;max-width:1000px;background:#fff}
		.bvcal th{background:#f0f0f1;border:1px solid #c3c4c7;padding:6px;font-size:13px;width:14.28%}
		.bvcal th.sat{color:#2271b1}.bvcal th.sun{color:#b32d2e}
		.bvcal td{border:1px solid #c3c4c7;padding:6px;vertical-align:top;height:64px}
		.bvcal td.empty{background:#fafafa}
		.bvcal .dnum{font-weight:600;font-size:13px;margin-bottom:4px}
		.bvcal td.sat .dnum{color:#2271b1}.bvcal td.sun .dnum{color:#b32d2e}
		.bvcal select{width:100%;font-size:12px;padding:2px}
		.bvcal select.is-normal{background:#fff3cd;font-weight:600}
		.bvcal select.is-green{background:#d7f0dc;font-weight:600}
		</style>';

		echo '<table class="bvcal"><thead><tr>';
		foreach ( $wd as $i => $w ) {
			$cls = ( 0 === $i ) ? ' class="sun"' : ( ( 6 === $i ) ? ' class="sat"' : '' );
			echo '<th' . $cls . '>' . esc_html( $w ) . '</th>';
		}
		echo '</tr></thead><tbody><tr>';

		/* 月初までの空白セル */
		for ( $i = 0; $i < $first_w; $i++ ) echo '<td class="empty"></td>';

		for ( $d = 1; $d <= $days_in; $d++ ) {
			$date = $month . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			$cur  = isset( $overrides[ $date ] ) ? $overrides[ $date ]->category : 'default';
			$w    = (int) date( 'w', strtotime( $date ) );
			$cls  = ( 0 === $w ) ? ' sun' : ( ( 6 === $w ) ? ' sat' : '' );
			echo '<td class="day' . $cls . '"><div class="dnum">' . $d . '</div>';
			echo '<select name="day[' . $d . ']" class="bvcal-sel is-' . esc_attr( $cur ) . '">';
			echo '<option value="default"' . selected( $cur, 'default', false ) . '>既定</option>';
			echo '<option value="normal"' . selected( $cur, 'normal', false ) . '>通常</option>';
			echo '<option value="green"' . selected( $cur, 'green', false ) . '>グリーン</option>';
			echo '</select></td>';
			if ( 6 === $w && $d < $days_in ) echo '</tr><tr>';
		}
		/* 月末の空白セル */
		$last_w = (int) date( 'w', strtotime( $month . '-' . $days_in ) );
		for ( $i = $last_w + 1; $i <= 6; $i++ ) echo '<td class="empty"></td>';
		echo '</tr></tbody></table>';

		echo '<script>
		(function(){
			function paint(sel){ sel.className = "bvcal-sel is-" + sel.value; }
			var sels = document.querySelectorAll(".bvcal-sel");
			sels.forEach(function(s){ s.addEventListener("change", function(){ paint(s); }); paint(s); });
			document.querySelectorAll("[data-bulk]").forEach(function(b){
				b.addEventListener("click", function(){
					var mode = b.dataset.bulk;
					document.querySelectorAll(".bvcal td.day").forEach(function(td){
						var s = td.querySelector(".bvcal-sel"); if(!s) return;
						if (mode === "weekend-normal") {
							if (td.classList.contains("sat") || td.classList.contains("sun")) s.value = "normal";
						} else { s.value = mode; }
						paint(s);
					});
				});
			});
		})();
		</script>';
		submit_button( 'カレンダーを保存' );
		echo '<p class="description">「既定」は上の基本設定で選んだ料金区分が適用されます。GW・お盆・年末年始などは「通常」に設定してください。色付きのセルが個別設定された日です。</p>';
		echo '</form></div>';
	}

	/* ---------- 貸渡実績報告書 ---------- */

	public static function report() {
		$fy = isset( $_GET['fy'] ) ? (int) $_GET['fy'] : ( (int) date( 'n' ) >= 4 ? (int) current_time( 'Y' ) : (int) current_time( 'Y' ) - 1 );
		echo '<div class="wrap"><h1>貸渡実績報告書（長野運輸支局）</h1>';
		echo '<p>毎年度（4月1日〜3月31日）の貸渡実績報告書をExcel形式で出力します。年度をまたぐ貸渡は当年度分と翌年度分に按分されます。</p>';
		echo '<form method="get"><input type="hidden" name="page" value="bvrm-report">年度: <select name="fy">';
		for ( $y = (int) current_time( 'Y' ); $y >= (int) current_time( 'Y' ) - 6; $y-- ) {
			echo '<option value="' . $y . '"' . selected( $fy, $y, false ) . '>' . $y . '年度（' . $y . '/4/1〜' . ( $y + 1 ) . '/3/31）</option>';
		}
		echo '</select> ';
		submit_button( '集計を表示', 'secondary', '', false );
		echo '</form>';

		$data = BV_Report::aggregate( $fy );
		echo '<h2>' . $fy . '年度 集計プレビュー</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>区分</th><th>車両数</th><th>延貸渡回数</th><th>延貸渡日車数</th><th>延走行キロ</th><th>総貸渡料金</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $row ) {
			echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td>' . (int) $row['vehicles'] . '</td><td>' . number_format( $row['count'] ) . '</td><td>' . number_format( $row['days'] ) . '</td><td>' . number_format( $row['km'] ) . '</td><td>' . esc_html( BV_Util::money( $row['revenue'] ) ) . '</td></tr>';
		}
		echo '<tr style="font-weight:bold"><td>合計</td><td>' . (int) $data['total']['vehicles'] . '</td><td>' . number_format( $data['total']['count'] ) . '</td><td>' . number_format( $data['total']['days'] ) . '</td><td>' . number_format( $data['total']['km'] ) . '</td><td>' . esc_html( BV_Util::money( $data['total']['revenue'] ) ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p><a class="button button-primary button-hero" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-report&bvrm_action=export_report&fy=' . $fy ), 'bvrm_export_report' ) ) . '">Excel（.xlsx）をダウンロード</a></p>';
		echo '</div>';
	}

	/* ---------- 設定 ---------- */

	public static function settings() {
		$s = BV_Util::settings();
		if ( isset( $_POST['bvrm_save_settings'] ) && check_admin_referer( 'bvrm_settings' ) ) {
			$P = wp_unslash( $_POST );
			$keys = array( 'open_time', 'close_time', 'admin_email', 'admin_cc', 'staff_notify', 'mail_from_name',
				'square_env', 'square_access_token', 'square_location_id', 'square_location_id_en', 'square_webhook_sig_key',
				'company_name', 'company_address', 'company_rep', 'company_tel', 'company_invoice',
				'transport_office', 'staff_pass', 'staff_pass_fromp' );
			$new = array();
			foreach ( $keys as $k ) $new[ $k ] = sanitize_text_field( $P[ $k ] ?? '' );
			foreach ( array_keys( BV_Util::stores() ) as $sk ) {
				$new[ 'store_url_' . $sk ]        = esc_url_raw( $P[ 'store_url_' . $sk ] ?? '' );
				$new[ 'store_from_email_' . $sk ] = sanitize_email( $P[ 'store_from_email_' . $sk ] ?? '' );
				$new[ 'store_from_name_' . $sk ]  = sanitize_text_field( $P[ 'store_from_name_' . $sk ] ?? '' );
				$new[ 'store_reply_to_' . $sk ]   = sanitize_email( $P[ 'store_reply_to_' . $sk ] ?? '' );
				$ccs = array();
				foreach ( explode( ',', (string) ( $P[ 'store_staff_cc_' . $sk ] ?? '' ) ) as $ce ) { $ce = sanitize_email( trim( $ce ) ); if ( $ce ) $ccs[] = $ce; }
				$new[ 'store_staff_cc_' . $sk ] = implode( ',', $ccs );
				$ot = sanitize_text_field( $P[ 'store_open_' . $sk ] ?? '' );  $new[ 'store_open_' . $sk ]  = preg_match( '/^\d{2}:\d{2}$/', $ot ) ? $ot : '';
				$ct = sanitize_text_field( $P[ 'store_close_' . $sk ] ?? '' ); $new[ 'store_close_' . $sk ] = preg_match( '/^\d{2}:\d{2}$/', $ct ) ? $ct : '';
				$new[ 'store_access_ja_' . $sk ]  = sanitize_text_field( $P[ 'store_access_ja_' . $sk ] ?? '' );
				$new[ 'store_access_en_' . $sk ]  = sanitize_text_field( $P[ 'store_access_en_' . $sk ] ?? '' );
				$new[ 'store_company_ja_' . $sk ] = sanitize_text_field( $P[ 'store_company_ja_' . $sk ] ?? '' );
				$new[ 'store_company_en_' . $sk ] = sanitize_text_field( $P[ 'store_company_en_' . $sk ] ?? '' );
				$locs = isset( $P[ 'store_locations_' . $sk ] ) ? array_map( 'sanitize_key', (array) $P[ 'store_locations_' . $sk ] ) : array();
				$new[ 'store_locations_' . $sk ] = array_values( array_intersect( $locs, array_keys( BV_Util::locations() ) ) );
				$new[ 'store_square_location_' . $sk ]    = sanitize_text_field( $P[ 'store_square_location_' . $sk ] ?? '' );
				$new[ 'store_square_location_en_' . $sk ] = sanitize_text_field( $P[ 'store_square_location_en_' . $sk ] ?? '' );
				$new[ 'store_review_url_' . $sk ]         = esc_url_raw( trim( $P[ 'store_review_url_' . $sk ] ?? '' ) );
				$lt = trim( (string) ( $P[ 'store_lead_time_' . $sk ] ?? '' ) );
				$new[ 'store_lead_time_' . $sk ]         = ( '' === $lt ) ? '' : (string) max( 0, min( 72, (int) $lt ) );
				$ta = trim( (string) ( $P[ 'store_turnaround_' . $sk ] ?? '' ) );
				$new[ 'store_turnaround_' . $sk ]        = ( '' === $ta ) ? '' : (string) max( 0, min( 72, (float) $ta ) );
			}
			$new['square_skip_sig']  = ! empty( $P['square_skip_sig'] ) ? 1 : 0;
			/* 診断モードは戻し忘れ防止のため、オンにした時刻を記録して60分で自動失効させる */
			$new['square_skip_sig_at'] = $new['square_skip_sig']
				? ( ! empty( $s['square_skip_sig'] ) ? (int) ( $s['square_skip_sig_at'] ?? time() ) : time() )
				: 0;
			$new['doc_retention_days'] = max( 0, min( 3650, (int) ( $P['doc_retention_days'] ?? 0 ) ) );
			$new['min_driver_age']     = max( 0, min( 99, (int) ( $P['min_driver_age'] ?? 0 ) ) );
			$new['review_mail_enabled']  = ! empty( $P['review_mail_enabled'] ) ? 1 : 0;
			$new['review_coupon_amount'] = max( 1, min( 100000, (int) ( $P['review_coupon_amount'] ?? 500 ) ) );
			$new['review_coupon_days']   = max( 1, min( 3650, (int) ( $P['review_coupon_days'] ?? 365 ) ) );
			$new['square_use_long_url'] = ! empty( $P['square_use_long_url'] ) ? 1 : 0;
			$new['invoice_enabled']  = ! empty( $P['invoice_enabled'] ) ? 1 : 0;
			$new['pay_deadline_hours'] = max( 0, min( 168, (int) ( $P['pay_deadline_hours'] ?? 6 ) ) );
			$new['return_24h']       = ! empty( $P['return_24h'] ) ? 1 : 0;
			$new['turnaround_hours'] = max( 0, min( 72, (float) ( $P['turnaround_hours'] ?? 1 ) ) );
			$new['lead_time_hours']  = max( 0, min( 72, (int) ( $P['lead_time_hours'] ?? 2 ) ) );
			$new['max_advance_days'] = max( 1, min( 1095, (int) ( $P['max_advance_days'] ?? 365 ) ) );
			$new['self_change_hours'] = max( 0, min( 720, (int) ( $P['self_change_hours'] ?? 48 ) ) );
			$new['company_seal_id']  = (int) ( $P['company_seal_id'] ?? 0 );
			$new['company_seal_url'] = esc_url_raw( trim( $P['company_seal_url'] ?? '' ) );
			$new['office_count'] = (int) ( $P['office_count'] ?? 4 );
			$new['reminder_hours'] = max( 0, min( 720, (int) ( $P['reminder_hours'] ?? 3 ) ) );

			/* 督促のタイミング（カンマ区切り） */
			$stg = array();
			foreach ( explode( ',', (string) ( $P['reminder_stages'] ?? '' ) ) as $v ) {
				$h = (int) trim( $v );
				if ( $h > 0 && $h <= 720 ) $stg[] = $h;
			}
			$stg = array_values( array_unique( $stg ) );
			sort( $stg );
			$new['reminder_stages'] = implode( ',', $stg );

			/* キャンセルポリシー */
			foreach ( array( 'cancel_pct_month', 'cancel_pct_week', 'cancel_pct_48h', 'cancel_pct_late', 'cancel_pct_noshow' ) as $pk ) {
				$new[ $pk ] = max( 0, min( 100, (int) ( $P[ $pk ] ?? 0 ) ) );
			}
			$new['cancel_auto_refund'] = ! empty( $P['cancel_auto_refund'] ) ? 1 : 0;

			/* 直前予約 */
			$new['immediate_pay_hours']    = max( 0, min( 168, (int) ( $P['immediate_pay_hours'] ?? 48 ) ) );
			$new['immediate_hold_minutes'] = max( 5, min( 180, (int) ( $P['immediate_hold_minutes'] ?? 30 ) ) );

			/* 出発前のご案内メール */
			$new['remind_week_enabled'] = ! empty( $P['remind_week_enabled'] ) ? 1 : 0;
			$new['remind_day_enabled']  = ! empty( $P['remind_day_enabled'] ) ? 1 : 0;
			$new['remind_week_hour'] = max( 0, min( 23, (int) ( $P['remind_week_hour'] ?? 10 ) ) );
			$new['remind_day_hour']  = max( 0, min( 23, (int) ( $P['remind_day_hour'] ?? 17 ) ) );

			/* 自動キャンセル */
			$was_on = ! empty( $s['autocancel_enabled'] );
			$new['autocancel_enabled'] = ! empty( $P['autocancel_enabled'] ) ? 1 : 0;
			$new['autocancel_hours']   = max( 1, min( 720, (int) ( $P['autocancel_hours'] ?? 6 ) ) );
			$new['autocancel_notify_customer'] = ! empty( $P['autocancel_notify_customer'] ) ? 1 : 0;
			/* 有効化した瞬間を記録し、それ以前の古い仮予約は対象外にする */
			if ( $new['autocancel_enabled'] && ! $was_on ) {
				update_option( 'bvrm_autocancel_from', current_time( 'timestamp' ) );
			}

			$em_in = isset( $P['equip_match'] ) ? array_map( 'sanitize_key', (array) $P['equip_match'] ) : array();
			$new['equip_match'] = array_values( array_intersect( $em_in, BV_Util::equipment_keys() ) );

			foreach ( array( 'cov_b', 'cov_c' ) as $k ) $new[ $k ] = (int) $P[ $k ];
			foreach ( BV_Util::equipment_keys() as $ek ) $new[ 'opt_' . $ek ] = (int) ( $P[ 'opt_' . $ek ] ?? 0 );
			BV_Util::update_settings( $new );
			$s = BV_Util::settings();
			echo '<div class="notice notice-success"><p>設定を保存しました。</p></div>';
			if ( $new['autocancel_enabled'] && $stg ) {
				$too_late = array();
				foreach ( $stg as $h ) if ( $h >= $new['autocancel_hours'] ) $too_late[] = $h;
				if ( $too_late ) {
					echo '<div class="notice notice-warning"><p><strong>ご確認ください：</strong>リマインドの ' . esc_html( implode( '・', $too_late ) ) . '時間後は、自動キャンセル（' . (int) $new['autocancel_hours'] . '時間）より後のため送信されません。自動キャンセルより前の時間に設定してください。</p></div>';
				}
			}
			if ( $new['autocancel_enabled'] && (int) $new['pay_deadline_hours'] !== (int) $new['autocancel_hours'] ) {
				echo '<div class="notice notice-warning"><p><strong>ご確認ください：</strong>メール文面のお支払い期限（' . (int) $new['pay_deadline_hours'] . '時間）と、実際の自動キャンセル（' . (int) $new['autocancel_hours'] . '時間）が違います。お客様への案内と実際の動作を揃えることをおすすめします。</p></div>';
			}
		}
		/* 既存の免許証画像を非公開領域へ移行する */
		if ( isset( $_POST['bvrm_migrate_docs'] ) && check_admin_referer( 'bvrm_migrate_docs' ) ) {
			$mr = BV_Files::migrate_legacy( 200 );
			echo '<div class="notice notice-success"><p>本人確認書類の移行を実行しました：'
				. '移動 <strong>' . (int) $mr['moved'] . '</strong> 件'
				. '／ファイルが見つからず記録のみ残っていたもの ' . (int) $mr['missing'] . ' 件'
				. '／失敗 ' . (int) $mr['failed'] . ' 件'
				. ( $mr['remaining'] ? '／未処理 ' . (int) $mr['remaining'] . ' 件（もう一度実行してください）' : '' )
				. '</p></div>';
		}

		if ( isset( $_POST['bvrm_run_pending'] ) && check_admin_referer( 'bvrm_run_pending' ) ) {
			BV_Mailer::send_payment_reminders();
			$n = BV_Mailer::auto_cancel_expired();
			echo '<div class="notice notice-success"><p>未入金予約の判定を実行しました。自動キャンセル：<strong>' . (int) $n . '件</strong>。</p></div>';
		}
		if ( isset( $_POST['bvrm_save_templates'] ) && check_admin_referer( 'bvrm_templates' ) ) {
			$P = wp_unslash( $_POST );
			$templates = array();
			foreach ( BV_Mailer::default_templates() as $key => $tpl ) {
				$templates[ $key ] = array(
					'subject' => sanitize_text_field( $P[ 'tpl_subject_' . $key ] ?? '' ),
					'body'    => sanitize_textarea_field( $P[ 'tpl_body_' . $key ] ?? '' ),
				);
			}
			update_option( 'bvrm_mail_templates', $templates );
			echo '<div class="notice notice-success"><p>メールテンプレートを保存しました。</p></div>';
		}

		echo '<div class="wrap"><h1>設定</h1><form method="post">';
		wp_nonce_field( 'bvrm_settings' );
		echo '<input type="hidden" name="bvrm_save_settings" value="1">';

		echo '<h2>営業時間・オプション料金</h2><table class="form-table">';
		echo '<tr><th>営業時間（貸出）</th><td><input type="time" name="open_time" value="' . esc_attr( $s['open_time'] ) . '"> 〜 <input type="time" name="close_time" value="' . esc_attr( $s['close_time'] ) . '">';
		echo '<p class="description">貸出はこの時間内のみ受け付けます。</p></td></tr>';
		echo '<tr><th>返却の受付時間</th><td><label><input type="checkbox" name="return_24h" value="1"' . checked( (int) $s['return_24h'], 1, false ) . '> 返却は24時間受け付ける（営業時間外の返却を許可）</label>';
		echo '<p class="description">チェックを外すと、返却も営業時間内のみになります。</p></td></tr>';
		echo '<tr><th>返却後インターバル</th><td>返却予定時刻から <input type="number" name="turnaround_hours" value="' . esc_attr( $s['turnaround_hours'] ) . '" min="0" max="72" step="0.5" style="width:80px"> 時間後から次の貸出を可能にする';
		echo '<p class="description">こちらも「店舗別設定」で店舗ごとに変えられます。</p>';
		echo '<p class="description">清掃・給油・点検にかかる時間を確保します。この時間内は同じ車両の予約を受け付けません（0で無効）。<br>予約ガントでは、返却後のインターバルがオレンジの帯で表示されます。</p></td></tr>';
		echo '<tr><th>ネット予約の受付範囲</th><td>現在時刻の <input type="number" name="lead_time_hours" value="' . (int) $s['lead_time_hours'] . '" min="0" max="72" style="width:70px"> 時間後以降　／　<input type="number" name="max_advance_days" value="' . (int) $s['max_advance_days'] . '" min="1" max="1095" style="width:80px"> 日先まで';
		echo '<p class="description">受付開始は「店舗別設定」で店舗ごとに変えられます。</p>';
		echo '<p class="description">直前予約を防ぐための猶予時間と、受付可能な先の期間です（既定：2時間後〜365日先）。スタッフによる管理画面・ポータルからの予約追加はこの制限を受けません。</p></td></tr>';
		echo '<tr><th>お客様ご自身での日程変更</th><td>貸出の <input type="number" name="self_change_hours" value="' . (int) $s['self_change_hours'] . '" min="0" max="720" style="width:70px"> 時間前まで可能（0で無効）';
		echo '<p class="description">予約確認ページから、お客様ご自身で貸出・返却の日時を変更できます。空き状況を確認し、必要なら同クラスの別車両へ自動で振り替えます。変更後はお客様と管理者の両方にメールが届きます。<br>'
			. '・期限を過ぎた変更、車両クラスやオプションの変更は、従来どおり「変更申請」として受け付けます。<br>'
			. '・<strong>お支払い済みの予約で料金が高くなる変更は受け付けません</strong>（変更申請へ誘導）。安くなる場合は変更を確定し、返金が必要な旨を管理者へ通知します。<br>'
			. '・未入金の予約で金額が変わった場合は、古い決済リンクを破棄して新しいリンクをメールでお送りします。</p></td></tr>';
		echo '<tr><th>装備で車両を絞り込む</th><td>';
		$em = BV_Util::equipment_match_keys();
		foreach ( BV_Util::equipment() as $ek => $ev ) {
			echo '<label style="margin-right:16px"><input type="checkbox" name="equip_match[]" value="' . esc_attr( $ek ) . '"'
				. checked( in_array( $ek, $em, true ), true, false ) . '> ' . esc_html( $ev['ja'] ) . '</label>';
		}
		echo '<p class="description">チェックした装備をお客様が申し込んだ場合、<strong>その装備が付いている車両だけ</strong>を割り当てます。空き状況の判定にも反映されるため、装備なしの車両しか空いていないときは予約フォームで「満車」と表示されます。<br>'
			. 'カーナビ・ETC・スキーラックは車に固定されているため既定でONです。チャイルドシートなど持ち運べる備品は、車両ごとに在庫を持たせている場合のみONにしてください（車両登録で装備にチェックが入っていない車が多いままONにすると、予約が取れなくなります）。<br>'
			. '※スタッフが管理画面やガントで手動割当する場合は、この制限は適用されません（装備が足りない場合は警告が出ます）。</p></td></tr>';
		echo '<tr><th>装備オプション/日</th><td>';
		foreach ( BV_Util::equipment() as $ek => $ev ) {
			echo '<p style="margin:0 0 6px"><label style="display:inline-block;width:190px">' . esc_html( $ev['ja'] ) . '</label>';
			echo '<input type="number" name="opt_' . esc_attr( $ek ) . '" value="' . (int) $s[ 'opt_' . $ek ] . '" style="width:90px"> 円';
			if ( ! empty( $ev['note_ja'] ) ) echo ' <span class="description">（' . esc_html( $ev['note_ja'] ) . '）</span>';
			echo '</p>';
		}
		echo '<p class="description">最大数量：チャイルドシート・ジュニアシートは2、その他は1です。</p></td></tr>';
		echo '<tr><th>追加補償/日</th><td>オプションC（車両補償＋NOC免除） <input type="number" name="cov_c" value="' . (int) $s['cov_c'] . '" style="width:90px">円 ｜ オプションB（車両補償） <input type="number" name="cov_b" value="' . (int) $s['cov_b'] . '" style="width:90px">円</td></tr>';
		echo '</table>';

		echo '<h2>メール</h2><table class="form-table">';
		echo '<tr><th>管理者メール</th><td><input type="email" name="admin_email" class="regular-text" value="' . esc_attr( $s['admin_email'] ) . '"></td></tr>';
		echo '<tr><th>CC（カンマ区切り）</th><td><input type="text" name="admin_cc" class="regular-text" value="' . esc_attr( $s['admin_cc'] ) . '"></td></tr>';
		echo '<tr><th>スタッフ通知先</th><td><input type="text" name="staff_notify" class="regular-text" value="' . esc_attr( $s['staff_notify'] ) . '"></td></tr>';
		echo '<tr><th>差出人名</th><td><input type="text" name="mail_from_name" class="regular-text" value="' . esc_attr( $s['mail_from_name'] ) . '"></td></tr>';
		echo '<tr><th>支払リマインド</th><td>仮予約から <input type="text" name="reminder_stages" value="' . esc_attr( $s['reminder_stages'] ) . '" style="width:200px" placeholder="12,24,36,47"> 時間後に送る';
		echo '<p class="description">カンマ区切りで複数指定できます（例：<code>12,24,36,47</code>＝12時間後・24時間後・36時間後・47時間後の4回）。同じ段階で二重に送ることはありません。<br>'
			. '<strong>最後の1回は、自動キャンセルの1時間前あたりに置くのがおすすめです。</strong>「まもなく解除されます」という最後の合図になります。<br>'
			. '直前のご予約（下の「直前のご予約」設定の対象）には送りません。保留時間が短いためです。</p></td></tr>';
		echo '<tr><th>出発前のご案内メール</th><td>';
		echo '<p style="margin:0 0 6px"><label><input type="checkbox" name="remind_week_enabled" value="1"' . checked( (int) $s['remind_week_enabled'], 1, false ) . '> <strong>1週間前</strong>に送る</label>';
		echo ' 　送信時刻 <select name="remind_week_hour">';
		for ( $h = 6; $h <= 21; $h++ ) echo '<option value="' . $h . '"' . selected( (int) $s['remind_week_hour'], $h, false ) . '>' . sprintf( '%02d:00', $h ) . '</option>';
		echo '</select></p>';
		echo '<p style="margin:0 0 6px"><label><input type="checkbox" name="remind_day_enabled" value="1"' . checked( (int) $s['remind_day_enabled'], 1, false ) . '> <strong>前日</strong>に送る</label>';
		echo ' 　　　送信時刻 <select name="remind_day_hour">';
		for ( $h = 6; $h <= 21; $h++ ) echo '<option value="' . $h . '"' . selected( (int) $s['remind_day_hour'], $h, false ) . '>' . sprintf( '%02d:00', $h ) . '</option>';
		echo '</select></p>';
		$pk_last = get_option( 'bvrm_pickup_remind_last', '' );
		echo '<p class="description">貸出日の1週間前・前日に、持ち物や当日の流れをご案内するメールを送ります。1件につき1回だけ送信します。<br>'
			. '<strong>推奨は前日17:00・1週間前10:00です。</strong>前日夕方は帰宅後や宿泊先で予定を確認する時間帯にあたり、忘れ物や日程の勘違いに当日朝より早く気づけます。朝に送ると移動中で読み飛ばされがちです。1週間前は、日程変更や装備の追加をまだ落ち着いて相談できるタイミングとして平日日中を選んでいます。<br>'
			. '未入金のオンライン決済予約には送りません（支払リマインドと役割が重なるため）。現金・振込のお客様には、お支払いのご案内を本文に入れて送ります。'
			. ( $pk_last ? '<br>最後の送信：' . esc_html( $pk_last ) : '' ) . '</p></td></tr>';
		echo '<tr><th>お支払い期限の案内</th><td>ご予約リクエストから <input type="number" name="pay_deadline_hours" value="' . (int) $s['pay_deadline_hours'] . '" style="width:70px" min="0" max="168"> 時間以内';
		echo '<p class="description">仮予約メールに「○時間以内にお支払いまたはご連絡がない場合は自動キャンセル」という案内文を入れます（0で非表示）。<strong>この数字は文面に出るだけ</strong>なので、下の自動キャンセルの時間と揃えてください。</p></td></tr>';
		echo '</table>';

		/* ---- キャンセルポリシー ---- */
		echo '<h2>キャンセルポリシー</h2>';
		echo '<p class="description">貸出日時までの残り時間で判定します。お客様が予約確認ページからキャンセルすると、この割合でキャンセル料を計算し、差額を自動で返金します（Square決済の場合）。</p>';
		echo '<table class="form-table">';
		$pol_rows = array(
			'cancel_pct_month' => '出発1か月前まで',
			'cancel_pct_week'  => '出発1週間前まで',
			'cancel_pct_48h'   => '出発48時間前まで',
			'cancel_pct_late'  => '出発48時間前以降',
			'cancel_pct_noshow'=> '無断キャンセル（No-show）',
		);
		foreach ( $pol_rows as $pk => $plabel ) {
			echo '<tr><th>' . esc_html( $plabel ) . '</th><td><input type="number" name="' . esc_attr( $pk ) . '" value="' . (int) $s[ $pk ] . '" min="0" max="100" style="width:80px"> % のキャンセル料</td></tr>';
		}
		echo '<tr><th>自動返金</th><td><label><input type="checkbox" name="cancel_auto_refund" value="1"' . checked( (int) $s['cancel_auto_refund'], 1, false ) . '> キャンセル時にSquareへ自動で返金する</label>';
		echo '<p class="description">オフにすると、返金は管理画面から手動で行います（金額はメールと管理メモに記録されます）。現金・銀行振込のお支払いは、どちらの設定でも手動対応になります。</p></td></tr>';
		echo '</table>';
		echo '<div class="notice notice-info inline" style="margin:0 0 14px"><p><strong>現在の設定でお客様に表示される内容：</strong><br>'
			. nl2br( esc_html( BV_Util::cancel_policy_text( 'ja' ) ) ) . '</p></div>';

		/* ---- 直前予約 ---- */
		echo '<h2>直前のご予約（お支払い完了で確定）</h2>';
		echo '<table class="form-table">';
		echo '<tr><th>対象</th><td>貸出まで <input type="number" name="immediate_pay_hours" value="' . (int) $s['immediate_pay_hours'] . '" min="0" max="168" style="width:70px"> 時間を切っている予約（0で無効）</td></tr>';
		echo '<tr><th>お支払いの保留時間</th><td><input type="number" name="immediate_hold_minutes" value="' . (int) $s['immediate_hold_minutes'] . '" min="5" max="180" style="width:70px"> 分以内にお支払いがなければ自動解除</td></tr>';
		echo '</table>';
		echo '<p class="description">直前のご予約は、長時間の仮押さえを認めると当日枠が埋まったまま流れてしまいます。この設定では、対象の予約だけ保留時間を短くし、<strong>お支払いが完了して初めて予約が成立する</strong>扱いにします。督促メールは送らず（時間が短いため）、期限を過ぎると自動で解除されます。</p>';

		/* ---- 自動キャンセル ---- */
		echo '<h2>未入金の自動キャンセル</h2>';
		echo '<p class="description">未入金の仮予約が残り続けると、その車両を次のお客様が予約できなくなります。期限を過ぎた仮予約を自動でキャンセルし、空き枠を解放します。</p>';
		echo '<table class="form-table">';
		echo '<tr><th>自動キャンセル</th><td><label><input type="checkbox" name="autocancel_enabled" value="1"' . checked( (int) $s['autocancel_enabled'], 1, false ) . '> 有効にする</label>';
		$ac_from = (int) get_option( 'bvrm_autocancel_from', 0 );
		if ( ! empty( $s['autocancel_enabled'] ) && $ac_from ) {
			echo '<p class="description">対象開始日時：<strong>' . esc_html( date_i18n( 'Y-m-d H:i', $ac_from ) ) . '</strong> 以降に作成された予約（これ以前の古い仮予約を一斉にキャンセルしないための安全装置です）。</p>';
		}
		$ac_last = get_option( 'bvrm_autocancel_last', '' );
		if ( $ac_last ) echo '<p class="description">最後の自動キャンセル：' . esc_html( $ac_last ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th>キャンセルまでの時間</th><td>仮予約から <input type="number" name="autocancel_hours" value="' . (int) $s['autocancel_hours'] . '" style="width:70px" min="1" max="720"> 時間で未入金なら自動キャンセル';
		echo '<p class="description">15分ごとに判定します。落とす直前にSquareへ入金確認を行うので、Webhookを取りこぼしていても支払済みの予約が消えることはありません。</p></td></tr>';
		echo '<tr><th>お客様への通知</th><td><label><input type="checkbox" name="autocancel_notify_customer" value="1"' . checked( (int) $s['autocancel_notify_customer'], 1, false ) . '> 自動キャンセル時にお客様へもメールを送る</label>';
		echo '<p class="description">管理者・スタッフへの通知は常に送信されます。文面は「メール文面」の設定から編集できます。</p></td></tr>';
		echo '<tr><th>対象外</th><td><p class="description">次の予約は自動キャンセルされません。<br>・決済リンクを発行していない予約（電話予約などの仮押さえ）<br>・送迎リクエストの回答待ち（こちら側の対応待ちのため）<br>・送迎代のみお支払い済みの予約（返金の判断が必要なため）<br>・支払済み、またはすでにキャンセル済みの予約</p></td></tr>';
		echo '</table>';

		echo '<h2>店舗別設定（サイトURL・差出人・メール表示名・Square）</h2>';
		echo '<p class="description">差出人アドレスを設定すると、お客様宛メール（仮予約・支払完了・キャンセル・リマインド・認証コード・問い合わせ確認）がその店舗のアドレスから送信されます。「メール表示名」は件名や署名の <code>{company}</code> に使われます（例：【白馬レンタカー】仮予約を受け付けました）。法人名を入れたい場合はテンプレートで <code>{company_legal}</code> を使ってください。未入力の項目は店舗名・共通設定が使われます。</p>';
		echo '<div class="notice notice-warning inline" style="margin:8px 0 14px"><p><strong>重要：</strong>他ドメインのアドレスを差出人にすると迷惑メール判定されやすくなります。各ドメインのDNSにSPF・DKIMを設定するか、WP Mail SMTP等のプラグインで各ドメインのメールサーバー経由で送信することを強く推奨します。設定後は下の「テスト送信」で実際の送信元をご確認ください。</p></div>';
		echo '<table class="form-table">';
		foreach ( BV_Util::stores() as $sk => $store ) {
			echo '<tr><th style="vertical-align:top">' . esc_html( $store['ja'] ) . '</th><td>';
			echo '<p><label style="display:inline-block;width:190px">サイトURL</label><input type="url" name="store_url_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_url_' . $sk ] ) . '" placeholder="https://example.com/">';
			if ( empty( $s[ 'store_url_' . $sk ] ) ) {
				echo ' <span style="color:#b32d2e;font-size:12px">← 未設定（「ホームに戻る」が中央サイトになります）</span>';
			}
			echo '</p>';
			echo '<p><label style="display:inline-block;width:190px;vertical-align:top">来店場所の説明（日本語）</label><input type="text" name="store_access_ja_' . esc_attr( $sk ) . '" class="regular-text" style="width:480px" value="' . esc_attr( $s[ 'store_access_ja_' . $sk ] ) . '" placeholder="例：竹のや旅館内、JR信濃大町駅より徒歩2分"></p>';
			echo '<p><label style="display:inline-block;width:190px;vertical-align:top">来店場所の説明（英語）</label><input type="text" name="store_access_en_' . esc_attr( $sk ) . '" class="regular-text" style="width:480px" value="' . esc_attr( $s[ 'store_access_en_' . $sk ] ) . '" placeholder="e.g. Inside Takenoya Ryokan, 2 min walk from JR Shinano-Omachi Sta."></p>';
			echo '<p><label style="display:inline-block;width:190px">差出人アドレス</label><input type="email" name="store_from_email_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_from_email_' . $sk ] ) . '" placeholder="info@example.com"></p>';
			echo '<p><label style="display:inline-block;width:190px">差出人名</label><input type="text" name="store_from_name_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_from_name_' . $sk ] ) . '" placeholder="' . esc_attr( $store['ja'] ) . '"></p>';
			echo '<p><label style="display:inline-block;width:190px">返信先（任意）</label><input type="email" name="store_reply_to_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_reply_to_' . $sk ] ) . '" placeholder="差出人と別にする場合のみ"></p>';
			echo '<p><label style="display:inline-block;width:190px">店舗スタッフ通知CC（任意）</label><input type="text" name="store_staff_cc_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_staff_cc_' . $sk ] ?? '' ) . '" placeholder="カンマ区切りで複数可"> <span class="description">この店舗の予約・変更・キャンセル・送迎などの管理者通知をCCで送ります</span></p>';
			$sh = BV_Util::store_hours( $sk );
			echo '<p><label style="display:inline-block;width:190px">営業時間（任意）</label><input type="time" name="store_open_' . esc_attr( $sk ) . '" value="' . esc_attr( $s[ 'store_open_' . $sk ] ?? '' ) . '" step="1800"> 〜 <input type="time" name="store_close_' . esc_attr( $sk ) . '" value="' . esc_attr( $s[ 'store_close_' . $sk ] ?? '' ) . '" step="1800"> <span class="description">空欄なら ' . esc_html( $sh['open'] . '〜' . $sh['close'] ) . ( BV_Util::is_hourly_store( $sk ) ? '（時間貸し店舗：返却も営業時間内のみ）' : '' ) . '</span></p>';
			echo '<p><label style="display:inline-block;width:190px">メール表示名（日本語）</label><input type="text" name="store_company_ja_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_company_ja_' . $sk ] ) . '" placeholder="' . esc_attr( $store['ja'] ) . '"></p>';
			echo '<p><label style="display:inline-block;width:190px">メール表示名（英語）</label><input type="text" name="store_company_en_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_company_en_' . $sk ] ) . '" placeholder="' . esc_attr( $store['en'] ) . '"></p>';
			echo '<p style="margin-bottom:2px"><label style="display:inline-block;width:190px;vertical-align:top">貸出できる車両の場所</label>';
			$cur_locs = BV_Util::store_locations( $sk );
			foreach ( BV_Util::locations() as $lk => $lv ) {
				echo '<label style="margin-right:14px"><input type="checkbox" name="store_locations_' . esc_attr( $sk ) . '[]" value="' . esc_attr( $lk ) . '"' . checked( in_array( $lk, $cur_locs, true ), true, false ) . '> ' . esc_html( $lv['ja'] ) . '</label>';
			}
			echo '</p><p style="margin:0 0 10px 190px" class="description">この店舗の予約で使える車両を場所で限定します（チェックした場所の車両のみ在庫としてカウントされます）。';
			$grp_locs = array();
			foreach ( BV_Util::store_groups() as $g ) {
				if ( in_array( $sk, $g['stores'], true ) ) { $grp_locs = $g['locations']; break; }
			}
			$missing = array_diff( $grp_locs, $cur_locs );
			if ( $missing ) {
				$names = array();
				foreach ( $missing as $mk ) $names[] = BV_Util::label( BV_Util::locations(), $mk );
				echo '<br><strong style="color:#b32d2e">同じエリアの「' . esc_html( implode( '・', $names ) ) . '」がチェックされていません。</strong>車両を共通で使う場合はチェックを入れて保存してください。';
			}
			echo '</p>';
			echo '<p><label style="display:inline-block;width:190px">Location ID（日本語予約）</label><input type="text" name="store_square_location_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_square_location_' . $sk ] ) . '" placeholder="未入力なら共通のLocation IDを使用"></p>';
			echo '<p><label style="display:inline-block;width:190px">Location ID（英語予約）</label><input type="text" name="store_square_location_en_' . esc_attr( $sk ) . '" class="regular-text" value="' . esc_attr( $s[ 'store_square_location_en_' . $sk ] ) . '" placeholder="未入力なら英語共通→日本語欄→共通の順で使用"></p>';
			echo '<p><label style="display:inline-block;width:190px">口コミ投稿URL（Google）</label><input type="url" name="store_review_url_' . esc_attr( $sk ) . '" class="large-text" value="' . esc_attr( $s[ 'store_review_url_' . $sk ] ?? '' ) . '" placeholder="https://g.page/r/..../review"></p>';
			$lt_def = BV_Util::store_lead_time_hours( $sk );
			$ta_def = BV_Util::store_turnaround_hours( $sk );
			echo '<p><label style="display:inline-block;width:190px">ネット予約の受付開始</label>現在の <input type="number" name="store_lead_time_' . esc_attr( $sk ) . '" min="0" max="72" style="width:80px" value="' . esc_attr( $s[ 'store_lead_time_' . $sk ] ?? '' ) . '" placeholder="' . (int) $lt_def . '"> 時間後から'
				. ' <span class="description">空欄ならこの店舗の既定（' . (int) $lt_def . '時間後）</span></p>';
			echo '<p><label style="display:inline-block;width:190px">返却後インターバル</label>返却から <input type="number" name="store_turnaround_' . esc_attr( $sk ) . '" min="0" max="72" step="0.5" style="width:80px" value="' . esc_attr( $s[ 'store_turnaround_' . $sk ] ?? '' ) . '" placeholder="' . esc_attr( $ta_def ) . '"> 時間後から次の貸出'
				. ' <span class="description">空欄ならこの店舗の既定（' . esc_html( $ta_def ) . '時間）</span></p>';
			$cov_names = array();
			foreach ( BV_Util::store_coverages( $sk ) as $ck2 => $cv2 ) $cov_names[] = $ck2;
			echo '<p><label style="display:inline-block;width:190px">取扱内容</label><span class="description">補償：' . esc_html( implode( '・', $cov_names ) )
				. '　／　学割：' . ( BV_Util::store_allows_student( $sk ) ? 'あり' : 'なし' )
				. '　／　送迎：' . ( BV_Util::store_allows_shuttle( $sk ) ? 'あり' : 'なし' )
				. '　／　車両クラス：' . esc_html( implode( '・', array_map( function ( $c ) { $cl = BV_Util::classes(); return isset( $cl[ $c ] ) ? $cl[ $c ]['ja'] : $c; }, BV_Util::store_classes( $sk ) ) ) )
				. '<br>これらはプラグイン側で店舗ごとに定義しています（変更が必要な場合はご相談ください）。</span></p>';
			echo '</td></tr>';
		}
		echo '</table>';

		echo '<h2>Square決済</h2><table class="form-table">';
		echo '<tr><th>環境</th><td><select name="square_env"><option value="sandbox"' . selected( $s['square_env'], 'sandbox', false ) . '>Sandbox（テスト）</option><option value="production"' . selected( $s['square_env'], 'production', false ) . '>本番</option></select></td></tr>';
		echo '<tr><th>アクセストークン</th><td><input type="password" name="square_access_token" class="large-text" value="' . esc_attr( $s['square_access_token'] ) . '"></td></tr>';
		echo '<tr><th>Location ID（共通・日本語予約）</th><td><input type="text" name="square_location_id" class="regular-text" value="' . esc_attr( $s['square_location_id'] ) . '"></td></tr>';
		echo '<tr><th>Location ID（共通・英語予約）</th><td><input type="text" name="square_location_id_en" class="regular-text" value="' . esc_attr( $s['square_location_id_en'] ) . '" placeholder="英語予約を分ける場合のみ入力"><p class="description">店舗ごとに分ける場合は下の「店舗別設定」で指定できます。<br><strong>適用の優先順位</strong>／英語予約: 店舗×英語 → 共通×英語 → 店舗×日本語 → 共通　／日本語予約: 店舗×日本語 → 共通</p></td></tr>';
		echo '<tr><th>決済リンクの形式</th><td><label><input type="checkbox" name="square_use_long_url" value="1"' . checked( (int) $s['square_use_long_url'], 1, false ) . '> 短縮URLを使わず、長いURLでお送りする</label>';
		echo '<p class="description">通常は短い <code>square.link/u/○○○</code> のリンクを使います。お客様から「リンクの期限が切れています」と言われる場合は、ここをONにすると長いURL（<code>checkout.square.site/...</code>）でお送りします。見た目は長くなりますが、短縮の転送を経由しないぶん確実です。<br>設定の変更後に発行したリンクから適用されます。すでに送信済みのリンクは、承認をやり直すか「決済リンクを再送」で作り直してください。</p></td></tr>';
		echo '<tr><th>Webhook署名キー</th><td><input type="text" name="square_webhook_sig_key" class="regular-text" value="' . esc_attr( $s['square_webhook_sig_key'] ) . '">';
		echo '<p class="description">Square開発者ダッシュボード → Webhooks → 購読の詳細 → Signature Key の「Show」で表示される値。<strong>Sandboxと本番でキーは別物です。</strong></p>';
		echo '<p><label><input type="checkbox" name="square_skip_sig" value="1"' . checked( (int) $s['square_skip_sig'], 1, false ) . '> <strong>署名検証を一時的に無効化する（診断用）</strong></label>';
		echo '<br><span class="description">Squareの配信履歴が403になる場合、これをオンにして再送すると原因を切り分けられます。通知が通るようになれば「署名キーが違う」ことが確定します。<br><strong>オンにしても、通知の内容をそのまま信用することはありません。</strong>予約の特定にだけ使い、入金の有無はSquareへ直接問い合わせて確認します。戻し忘れを防ぐため、この設定は60分で自動的に失効します。</span></p></td></tr>';
		echo '</table>';

		echo '<h2>会社情報（印刷物）</h2><table class="form-table">';
		echo '<tr><th>社名</th><td><input type="text" name="company_name" class="regular-text" value="' . esc_attr( $s['company_name'] ) . '"></td></tr>';
		echo '<tr><th>住所</th><td><input type="text" name="company_address" class="large-text" value="' . esc_attr( $s['company_address'] ) . '"></td></tr>';
		echo '<tr><th>代表者</th><td><input type="text" name="company_rep" class="regular-text" value="' . esc_attr( $s['company_rep'] ) . '"></td></tr>';
		echo '<tr><th>電話番号</th><td><input type="text" name="company_tel" class="regular-text" value="' . esc_attr( $s['company_tel'] ) . '"></td></tr>';
		echo '<tr><th>適格請求書発行事業者</th><td>';
		echo '<label><input type="checkbox" name="invoice_enabled" value="1"' . checked( (int) $s['invoice_enabled'], 1, false ) . '> 適格請求書発行事業者として領収書を発行する</label>';
		echo '<p class="description">チェックを外すと、領収書は「適格請求書ではない」旨を明記した通常の領収書として発行されます。<br>下の登録番号が未入力の場合は、チェックの有無にかかわらず自動的に適格請求書として発行されません。</p>';
		echo '<p><strong>現在の状態：</strong>' . ( BV_Util::is_invoice_issuer()
			? '<span style="color:#00a32a">適格請求書（インボイス）として発行されます</span>'
			: '<span style="color:#b32d2e">適格請求書としては発行されません（通常の領収書）</span>' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th>インボイス登録番号</th><td><input type="text" name="company_invoice" class="regular-text" value="' . esc_attr( $s['company_invoice'] ) . '" placeholder="T0000000000000（取得後に入力）">';
		if ( empty( $s['company_invoice'] ) ) {
			echo '<p class="description">未設定です。適格請求書として交付するには登録番号（Tから始まる13桁）が必須です。</p>';
		}
		echo '</td></tr>';
		$seal_preview = BV_Util::company_seal_url();
		echo '<tr><th>社印PNG（画像URL）</th><td><input type="url" name="company_seal_url" class="large-text" value="' . esc_attr( $s['company_seal_url'] ) . '" placeholder="https://be-village.com/wp-content/uploads/2026/08/xxxxx.png">';
		echo '<p class="description">メディアライブラリで画像を開き、「ファイルのURL」欄の値をそのまま貼り付けてください（コピー用のボタンがあります）。</p>';
		if ( $seal_preview ) echo '<img src="' . esc_url( $seal_preview ) . '" style="max-height:80px;border:1px solid #ccc;border-radius:4px;margin-top:6px;display:block">';
		echo '</td></tr>';
		echo '<tr><th>社印PNG（メディアID・従来方式）</th><td><input type="number" name="company_seal_id" value="' . (int) $s['company_seal_id'] . '" style="width:110px"><p class="description">上のURL欄が空の場合のみ使用されます。メディア詳細画面のURL（upload.php?item=123）の数字。</p></td></tr>';
		echo '<tr><th>運輸支局</th><td><input type="text" name="transport_office" value="' . esc_attr( $s['transport_office'] ) . '"></td></tr>';
		echo '<tr><th>事業所数</th><td><input type="number" name="office_count" value="' . (int) $s['office_count'] . '" style="width:70px"></td></tr>';
		echo '</table>';

		echo '<h2>スタッフポータル・API</h2><table class="form-table">';
		echo '<tr><th>スタッフPASS</th><td><input type="password" id="bvrm_staff_pass" name="staff_pass" class="regular-text" value="' . esc_attr( $s['staff_pass'] ) . '" autocomplete="off">';
		echo ' <label style="margin-left:6px"><input type="checkbox" onclick="var f=document.getElementById(\'bvrm_staff_pass\');f.type=this.checked?\'text\':\'password\';"> 表示</label>';
		echo '<p class="description">ポータルURL: <code>' . esc_html( home_url( '/?bv_staff=1' ) ) . '</code>（全店舗）<br>画面共有のときに見えてしまわないよう伏せ字にしています。スタッフに伝えるときだけ「表示」にしてください。<br>PASSを変更すると、いま開いている全員のログインが無効になります（総当たり対策として、同一端末から' . (int) BV_Staff_Portal::LOGIN_MAX_TRIES . '回連続で失敗すると15分間ログインできなくなります）。</p></td></tr>';
		echo '<tr><th>From P出張所 専用PASS</th><td><input type="password" name="staff_pass_fromp" class="regular-text" autocomplete="off" value="' . esc_attr( $s['staff_pass_fromp'] ?? '' ) . '"><p class="description">専用ポータルURL: <code>' . esc_html( home_url( '/?bv_staff_fromp=1' ) ) . '</code><br>From P出張所の予約と、場所が「From P出張所」の車両だけを表示・操作できます。全店舗用とは別のPASSにしてください（空欄ならログイン不可）。</p></td></tr>';
		echo '<tr><th>地域サイト用APIキー</th><td><code>' . esc_html( BV_API::get_api_key() ) . '</code><p class="description">白馬・大町・松本サイトの予約フォームプラグイン設定に貼り付けてください。API URL: <code>' . esc_html( rest_url( 'bvrm/v1/' ) ) . '</code></p></td></tr>';
		echo '</table>';

		echo '<h2>運転者の年齢制限</h2><table class="form-table">';
		echo '<tr><th>下限年齢</th><td><input type="number" name="min_driver_age" min="0" max="99" step="1" style="width:90px" value="' . (int) ( $s['min_driver_age'] ?? 21 ) . '"> 歳以上';
		echo '<p class="description"><strong>貸出日時点</strong>の満年齢で判定します（申込日ではありません）。これを下回るネット予約は受け付けず、予約フォームにも理由を表示します。<br>'
			. '<strong>0にすると制限しません。</strong>管理画面・スタッフポータルからの予約追加は、電話での例外対応ができるよう制限の対象外です。</p></td></tr>';
		echo '</table>';

		echo '<h2>返却後のお礼・口コミ依頼メール</h2><table class="form-table">';
		echo '<tr><th>自動送信</th><td><label><input type="checkbox" name="review_mail_enabled" value="1"' . checked( (int) ( $s['review_mail_enabled'] ?? 1 ), 1, false ) . '> 返却処理の完了時に、お客様へお礼＋口コミ依頼メールを自動送信する</label>';
		echo '<p class="description">スタッフポータルの返却処理画面で、1件ずつ送らないことも選べます。同じ予約に2通目が送られることはありません。</p></td></tr>';
		echo '<tr><th>お礼クーポンの割引額</th><td><input type="number" name="review_coupon_amount" min="1" max="100000" step="1" style="width:110px" value="' . (int) ( $s['review_coupon_amount'] ?? 500 ) . '"> 円';
		echo '<p class="description">お客様が「クーポンを受け取る」リンクを押した時点で、その方専用のコードを発行します（1予約につき1枚・1回限り）。全店舗で使えます。</p></td></tr>';
		echo '<tr><th>お礼クーポンの有効期間</th><td><input type="number" name="review_coupon_days" min="1" max="3650" step="1" style="width:110px" value="' . (int) ( $s['review_coupon_days'] ?? 365 ) . '"> 日間（発行日から）</td></tr>';
		$no_url = array();
		foreach ( BV_Util::stores() as $sk2 => $sv2 ) {
			if ( ! BV_Util::store_review_url( $sk2 ) ) $no_url[] = $sv2['ja'];
		}
		echo '<tr><th>口コミ投稿URL</th><td>';
		if ( $no_url ) {
			echo '<span style="color:#dba617">未設定の店舗があります：' . esc_html( implode( '／', $no_url ) ) . '</span>'
				. '<p class="description">URLが未設定の店舗では、お礼メールは送信されません。下の「店舗別設定」で店舗ごとに入力してください。</p>';
		} else {
			echo '<span style="color:#00a32a">✔ 全店舗で設定済みです。</span>';
		}
		echo '<p class="description">GoogleビジネスプロフィールのURLは、Google検索で自店舗を表示 →「クチコミを増やす」→ 表示される <code>https://g.page/r/…/review</code> 形式のリンクをコピーしてください。</p></td></tr>';
		echo '</table>';

		echo '<h2>本人確認書類（免許証・パスポート）の保護</h2><table class="form-table">';
		$prot = BV_Files::dir_protected();
		echo '<tr><th>保存先</th><td><code>wp-content/uploads/' . esc_html( BV_Files::DIRNAME ) . '/</code>'
			. ' ' . ( $prot
				? '<span style="color:#00a32a">✔ 直接アクセス禁止の設定あり（.htaccess）</span>'
				: '<span style="color:#dba617">未作成（次回のアップロード時に自動で作成されます）</span>' )
			. '<p class="description">アップロードされた免許証等は公開領域には置かず、ここに保存します。画面に表示されるリンクは2時間で失効し、管理者・スタッフは配信時にもログイン状態を確認します。</p></td></tr>';
		echo '<tr><th>保持日数</th><td><input type="number" name="doc_retention_days" min="0" max="3650" style="width:90px" value="' . (int) ( $s['doc_retention_days'] ?? 0 ) . '"> 日'
			. '<p class="description">返却済・キャンセルの予約について、返却日からこの日数が過ぎたら本人確認書類を自動削除します（1日1回判定）。<strong>0なら削除しません。</strong>進行中の予約で同じ画像が使われている場合は削除しません。</p></td></tr>';
		echo '</table>';

		submit_button( '設定を保存' );
		echo '</form>';

		/* ---- 既存の本人確認書類の移行 ---- */
		global $wpdb;
		$legacy = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . BV_DB::table( 'reservations' ) . " WHERE license_files LIKE '%http%'"
		);
		echo '<h3>既存の画像の移行</h3>';
		if ( $legacy > 0 ) {
			echo '<div class="notice notice-warning inline"><p>公開領域（<code>wp-content/uploads</code>直下）に置かれたままの本人確認書類が、<strong>' . (int) $legacy . '件</strong>の予約に残っています。URLを知っていれば誰でも開ける状態のため、移行をおすすめします。</p></div>';
		} else {
			echo '<p><span style="color:#00a32a">✔ 公開領域に残っている本人確認書類はありません。</span></p>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'bvrm_migrate_docs' );
		submit_button( '既存の免許証画像を非公開領域へ移動する', 'secondary', 'bvrm_migrate_docs', false );
		echo '</form>';
		echo '<p class="description">画像ファイルを非公開領域へ移動し、予約データの参照先を書き換えます。元の公開ファイルは削除されます。1回につき最大200ファイルまで処理するので、件数が多い場合は完了表示が出るまで繰り返し押してください。<br><strong>実行前にサーバーのバックアップを取ってください。</strong><br>繰り返しても件数が0にならない場合、残っているのは「予約データにURLだけ残っていて、サーバー上に実ファイルがない」記録です（実害はありません）。</p>';

		/* ---- 走行距離の異常値チェック ---- */
		$rt = BV_DB::table( 'reservations' );
		if ( isset( $_POST['bvrm_fix_km'] ) && check_admin_referer( 'bvrm_fix_km' ) ) {
			$ids = isset( $_POST['fix_ids'] ) ? array_map( 'intval', (array) $_POST['fix_ids'] ) : array();
			$n = 0;
			foreach ( $ids as $fid ) {
				if ( $fid > 0 ) $n += (int) $wpdb->update( $rt, array( 'trip_distance' => 0 ), array( 'id' => $fid ) );
			}
			echo '<div class="notice notice-success"><p>' . (int) $n . '件の走行距離を0kmに修正しました。</p></div>';
		}
		$km_limit = 3000; /* 1回の貸渡でこれを超える走行は記録ミスの可能性が高い */
		$bad_km = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, code, pickup_dt, return_dt, vehicle_id, return_odometer, trip_distance
			 FROM {$rt} WHERE trip_distance > %d ORDER BY trip_distance DESC LIMIT 50", $km_limit
		) );
		echo '<hr><h2>走行距離の確認</h2>';
		if ( ! $bad_km ) {
			echo '<p class="description">異常な走行距離の記録は見つかりませんでした。</p>';
		} else {
			echo '<div class="notice notice-warning inline" style="margin:8px 0"><p><strong>1回の貸渡で ' . number_format( $km_limit ) . 'km を超える記録が ' . count( $bad_km ) . '件あります。</strong><br>'
				. '車両のメーターが未登録（0km）のまま返却処理をすると、<strong>返却時のメーターの数字がそのまま走行距離として記録されて</strong>しまいます。'
				. 'この不具合はv1.20.0で修正しましたが、すでに記録された値は残っています。<br>'
				. '経営ダッシュボードの走行kmと<strong>貸渡実績報告書の走行キロ</strong>に影響しますので、心当たりのないものは0kmに戻してください。</p></div>';
			echo '<form method="post">';
			wp_nonce_field( 'bvrm_fix_km' );
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th style="width:30px"></th><th>予約番号</th><th>車両</th><th>貸出〜返却</th><th style="text-align:right">返却メーター</th><th style="text-align:right">記録された走行</th></tr></thead><tbody>';
			foreach ( $bad_km as $b ) {
				$bv = $b->vehicle_id ? BV_DB::get_vehicle( $b->vehicle_id ) : null;
				echo '<tr><td><input type="checkbox" name="fix_ids[]" value="' . (int) $b->id . '" checked></td>';
				echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $b->id ) ) . '">' . esc_html( $b->code ) . '</a></td>';
				echo '<td>' . esc_html( $bv ? $bv->name : '未割当' ) . '</td>';
				echo '<td>' . esc_html( date( 'Y-m-d', strtotime( $b->pickup_dt ) ) . '〜' . date( 'Y-m-d', strtotime( $b->return_dt ) ) ) . '</td>';
				echo '<td style="text-align:right">' . esc_html( number_format( (int) $b->return_odometer ) ) . ' km</td>';
				echo '<td style="text-align:right;color:#b32d2e;font-weight:600">' . esc_html( number_format( (int) $b->trip_distance ) ) . ' km</td></tr>';
			}
			echo '</tbody></table>';
			submit_button( 'チェックした記録の走行距離を0kmにする', 'secondary', 'bvrm_fix_km', false );
			echo '<p class="description">返却メーターの値はそのまま残ります。正しい走行距離が分かる場合は、この操作のあと個別にご相談ください。</p>';
			echo '</form>';
		}

		/* ---- データのバックアップ ---- */
		echo '<hr><h2>データのバックアップ</h2>';
		echo '<p class="description"><strong>プラグインを更新する前に、このボタンを押してファイルを保存しておいてください。</strong>'
			. '予約・車両・顧客・設定をまとめた1つのファイル（ファイル名に日付が入ります）がダウンロードされます。</p>';
		echo '<table class="widefat striped" style="max-width:560px;margin-bottom:12px"><thead><tr><th>内容</th><th style="text-align:right">件数</th></tr></thead><tbody>';
		$total_rows = 0;
		foreach ( BV_Admin::backup_tables() as $key => $label ) {
			$tname = BV_DB::table( $key );
			$cnt = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tname ) ) === $tname )
				? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tname}`" ) : 0;
			$total_rows += $cnt;
			echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right">' . number_format( $cnt ) . '</td></tr>';
		}
		echo '<tr><td>設定・メール文面</td><td style="text-align:right">' . count( BV_Admin::backup_options() ) . '項目</td></tr>';
		echo '</tbody></table>';
		$bk = wp_nonce_url( admin_url( 'admin.php?page=bvrm-settings&bvrm_action=export_backup' ), 'bvrm_export_backup' );
		echo '<p><a class="button button-primary" href="' . esc_url( $bk ) . '">バックアップをダウンロード</a></p>';
		echo '<p class="description">・ファイル形式はSQLです。復元は phpMyAdmin の「インポート」から行います（手順はファイルの先頭に書いてあります）。<br>'
			. '・このバックアップに含まれるのは<strong>レンタカー管理のデータだけ</strong>です。WordPress本体・記事・画像・免許証の画像ファイルは含まれません。サイト全体の備えとしては、エックスサーバーの自動バックアップ（過去14日分・復元無料）も併用してください。<br>'
			. '・件数が0の項目がある場合、そのデータはまだ登録されていないという意味です。</p>';

		/* 未入金予約の状況と手動実行 */
		echo '<hr><h2>未入金の仮予約</h2>';
		$pending_list = BV_DB::get_reservations( array( 'status' => 'pending' ) );
		$ac_from2 = (int) get_option( 'bvrm_autocancel_from', 0 );
		$now2 = current_time( 'timestamp' );
		$rows = array();
		foreach ( $pending_list as $pr ) {
			if ( ! BV_Mailer::is_awaiting_payment( $pr ) ) continue;
			$created = strtotime( $pr->created_at );
			$age_h = ( $now2 - $created ) / HOUR_IN_SECONDS;
			$eligible = ( ! empty( $s['autocancel_enabled'] ) && $ac_from2 && $created >= $ac_from2 );
			$rows[] = array( $pr, $age_h, $eligible );
		}
		if ( $rows ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>予約番号</th><th>店舗</th><th>お名前</th><th>貸出</th><th>経過</th><th>自動キャンセル</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				list( $pr, $age_h, $eligible ) = $row;
				$left = (int) $s['autocancel_hours'] - $age_h;
				if ( ! empty( $s['autocancel_enabled'] ) && ! $eligible ) {
					$judge = '<span style="color:#666">対象外（有効化より前の予約）</span>';
				} elseif ( empty( $s['autocancel_enabled'] ) ) {
					$judge = '<span style="color:#666">無効</span>';
				} elseif ( $left <= 0 ) {
					$judge = '<strong style="color:#b32d2e">次回の判定で解除</strong>';
				} else {
					$judge = 'あと約' . max( 1, (int) ceil( $left ) ) . '時間';
				}
				echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $pr->id ) ) . '">' . esc_html( $pr->code ) . '</a></td>';
				echo '<td>' . esc_html( BV_Util::label( BV_Util::stores(), $pr->store ) ) . '</td>';
				echo '<td>' . esc_html( trim( $pr->sei . ' ' . $pr->mei ) ) . '</td>';
				echo '<td>' . esc_html( date( 'Y-m-d H:i', strtotime( $pr->pickup_dt ) ) ) . '</td>';
				echo '<td>' . esc_html( number_format( $age_h, 1 ) ) . '時間</td>';
				echo '<td>' . $judge . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">お支払い待ちの予約はありません。</p>';
		}
		echo '<form method="post" style="margin-top:10px">';
		wp_nonce_field( 'bvrm_run_pending' );
		submit_button( '今すぐリマインド・自動キャンセルを判定', 'secondary', 'bvrm_run_pending', false );
		echo '</form>';
		echo '<p class="description">通常は15分ごとに自動で判定されます。WordPressの定期実行（WP-Cron）はサイトへのアクセスをきっかけに動くため、アクセスの少ないサイトでは遅れることがあります。時間どおりに動かしたい場合は、サーバーのcronから <code>wp-cron.php</code> を定期実行する設定をご検討ください。</p>';

		/* Webhook 診断 */
		echo '<hr><h2>Square Webhook 診断</h2>';
		$wh_url = rest_url( 'bvrm/v1/square/webhook' );
		echo '<table class="form-table">';
		echo '<tr><th>通知URL（Squareに登録する値）</th><td><code style="user-select:all;background:#f0f0f1;padding:6px 10px;display:inline-block">' . esc_html( $wh_url ) . '</code>';
		echo '<p class="description">Square開発者ダッシュボード → Webhooks → Subscriptions にこのURLをそのまま登録し、イベント <code>payment.updated</code> を選択してください。</p></td></tr>';
		echo '<tr><th>署名キー</th><td>' . ( ! empty( $s['square_webhook_sig_key'] )
			? '<span style="color:#00a32a">設定済み</span>（' . esc_html( strlen( $s['square_webhook_sig_key'] ) ) . '文字）'
			: '<span style="color:#dba617">未設定</span>' );
		if ( BV_Square::signature_unavailable() ) {
			echo '<br><strong style="color:#dba617">⚠ 署名検証ができない状態です。</strong>'
				. '<span class="description"><br>この間、Squareからの通知は内容をそのまま信用せず、予約の特定にだけ使い、入金の有無はSquareへ直接問い合わせて確認します（偽の通知で予約が確定することはありません）。確定までに数秒余分にかかるため、署名キーの設定を推奨します。</span>';
		}
		if ( ! empty( $s['square_skip_sig'] ) ) {
			echo BV_Square::skip_sig_active()
				? '<br><strong style="color:#dba617">⚠ 署名検証を一時停止中です（診断モード・オンにしてから60分で自動失効）。</strong>'
				: '<br><span class="description">診断モードのチェックは入っていますが、60分が過ぎたため失効しています（署名検証は有効です）。チェックを外して保存してください。</span>';
		}
		echo '</td></tr>';

		/* URL到達性チェック */
		$probe = wp_remote_post( $wh_url, array(
			'timeout' => 15, 'body' => '{}',
			'headers' => array( 'Content-Type' => 'application/json', 'X-BVRM-Probe' => '1' ),
		) );
		if ( is_wp_error( $probe ) ) {
			echo '<tr><th>URL到達性</th><td><span style="color:#b32d2e">エラー: ' . esc_html( $probe->get_error_message() ) . '</span><p class="description">サーバーの設定やセキュリティプラグインがREST APIを塞いでいる可能性があります。</p></td></tr>';
		} else {
			$pc = wp_remote_retrieve_response_code( $probe );
			$ok = in_array( $pc, array( 200, 403 ), true );
			echo '<tr><th>URL到達性</th><td>' . ( $ok
				? '<span style="color:#00a32a">✔ 到達可能（HTTP ' . (int) $pc . '）</span>'
				: '<span style="color:#b32d2e">HTTP ' . (int) $pc . ' が返りました。REST APIがブロックされている可能性があります（セキュリティプラグイン・Basic認証・.htaccess等をご確認ください）。</span>' ) . '</td></tr>';
		}
		echo '<tr><th>署名照合に使うURL候補</th><td><p class="description">Square側に登録したURLが、下記のいずれかと完全に一致している必要があります。</p>';
		foreach ( BV_Square::signature_url_candidates() as $cu ) {
			echo '<code style="display:block;font-size:11px">' . esc_html( $cu ) . '</code>';
		}
		echo '</td></tr>';
		echo '</table>';

		/* 支払状況の一括同期 */
		echo '<h3>支払状況の一括同期（Webhookの保険）</h3>';
		$last_sync = get_option( 'bvrm_last_sync' );
		$next_cron = wp_next_scheduled( 'bvrm_sync_payments' );
		echo '<p class="description">未払いの予約についてSquareに直接問い合わせ、支払済みなら自動で確定にします（確定メールも送信）。<strong>15分ごとに自動実行</strong>されるため、Webhookが届かなくても最大15分で反映されます。<br>';
		echo '最終実行: ' . esc_html( $last_sync ?: '未実行' );
		if ( $next_cron ) echo '　／　次回自動実行: ' . esc_html( date_i18n( 'Y-m-d H:i', $next_cron ) );
		echo '</p>';
		if ( isset( $_POST['bvrm_sync_now'] ) && check_admin_referer( 'bvrm_sync_now' ) ) {
			$res = BV_Square::sync_pending_payments( 60 );
			if ( $res ) {
				echo '<div class="notice notice-success"><p><strong>' . count( $res ) . '件を確定しました。</strong><br>' . esc_html( implode( ' / ', $res ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-info"><p>新たに確定できる支払いはありませんでした。</p></div>';
			}
		}
		echo '<form method="post" style="margin-bottom:14px">';
		wp_nonce_field( 'bvrm_sync_now' );
		submit_button( '今すぐ支払状況を同期', 'primary', 'bvrm_sync_now', false );
		echo '</form>';

		/* Square側の購読状況を確認 */
		echo '<form method="post" style="margin:8px 0">';
		wp_nonce_field( 'bvrm_check_subs' );
		submit_button( 'Squareに登録されているWebhookを確認', 'secondary', 'bvrm_check_subs', false );
		echo '</form>';
		if ( isset( $_POST['bvrm_check_subs'] ) && check_admin_referer( 'bvrm_check_subs' ) ) {
			$subs = BV_Square::list_webhook_subscriptions();
			if ( is_wp_error( $subs ) ) {
				echo '<div class="notice notice-error"><p>取得エラー: ' . esc_html( $subs->get_error_message() ) . '</p></div>';
			} elseif ( ! $subs ) {
				echo '<div class="notice notice-error"><p><strong>この環境（' . esc_html( 'production' === $s['square_env'] ? '本番' : 'Sandbox' ) . '）にはWebhookが1件も登録されていません。</strong>これが通知が届かない原因です。Square開発者ダッシュボードで登録してください。</p></div>';
			} else {
				echo '<table class="wp-list-table widefat striped" style="max-width:900px"><thead><tr><th>名称</th><th>通知URL</th><th>状態</th><th>イベント</th></tr></thead><tbody>';
				foreach ( $subs as $sub ) {
					$match = in_array( untrailingslashit( $sub['url'] ), array_map( 'untrailingslashit', BV_Square::signature_url_candidates() ), true );
					echo '<tr><td>' . esc_html( $sub['name'] ) . '</td>';
					echo '<td><code style="font-size:11px">' . esc_html( $sub['url'] ) . '</code>' . ( $match ? ' <span style="color:#00a32a">✔一致</span>' : ' <span style="color:#b32d2e">✘URLが一致しません</span>' ) . '</td>';
					echo '<td>' . ( $sub['enabled'] ? '<span style="color:#00a32a">有効</span>' : '<span style="color:#b32d2e">無効</span>' ) . '</td>';
					echo '<td>' . esc_html( $sub['events'] ) . ( false === strpos( $sub['events'], 'payment.updated' ) ? ' <span style="color:#b32d2e">← payment.updated がありません</span>' : '' ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		/* 受信ログ */
		if ( isset( $_POST['bvrm_clear_wh_log'] ) && check_admin_referer( 'bvrm_wh_log' ) ) {
			delete_option( 'bvrm_webhook_log' );
			echo '<div class="notice notice-success"><p>受信ログを消去しました。</p></div>';
		}
		$wh_log = get_option( 'bvrm_webhook_log', array() );
		echo '<h3>Webhook 受信ログ（直近20件）</h3>';
		if ( ! $wh_log ) {
			echo '<div class="notice notice-warning inline"><p><strong>受信履歴がありません。</strong><br>';
			echo 'Squareの配信履歴（Event History）に <strong>403</strong> が記録されているのにこの欄が空の場合、通知がWordPressに届く前にブロックされています。<br>';
			echo 'エックスサーバーの<strong>WAF設定</strong>（サーバーパネル → WAF設定）や、セキュリティ系プラグイン（SiteGuard・Wordfence等のREST API制限）をご確認ください。</p></div>';
		} else {
			echo '<table class="wp-list-table widefat striped"><thead><tr><th>受信日時</th><th>イベント</th><th>署名</th><th>決済状態</th><th>note</th><th>処理結果</th></tr></thead><tbody>';
			foreach ( (array) $wh_log as $e ) {
				echo '<tr><td>' . esc_html( $e['time'] ?? '' ) . '</td><td>' . esc_html( $e['type'] ?? '' ) . '</td>';
				echo '<td>' . ( 'OK' === ( $e['sig'] ?? '' ) ? '<span style="color:#00a32a">OK</span>' : '<span style="color:#b32d2e">NG</span>' ) . '</td>';
				echo '<td>' . esc_html( $e['status'] ?? '' ) . '</td><td><code>' . esc_html( $e['note'] ?? '' ) . '</code></td>';
				echo '<td>' . esc_html( $e['result'] ?? '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( 'bvrm_wh_log' );
			submit_button( 'ログを消去', 'secondary', 'bvrm_clear_wh_log', false );
			echo '</form>';
		}

		/* Squareロケーション一覧 */
		echo '<hr><h2>Square ロケーション一覧</h2>';
		echo '<p class="description">アクセストークンを保存後、下のボタンでSquareアカウントのロケーション一覧を取得できます。表示されたIDを店舗別設定にコピーしてください。</p>';
		echo '<form method="post">';
		wp_nonce_field( 'bvrm_fetch_locations' );
		submit_button( 'Squareからロケーション一覧を取得', 'secondary', 'bvrm_fetch_locations', false );
		echo '</form>';
		if ( isset( $_POST['bvrm_fetch_locations'] ) && check_admin_referer( 'bvrm_fetch_locations' ) ) {
			$locs = BV_Square::list_locations();
			if ( is_wp_error( $locs ) ) {
				echo '<div class="notice notice-error"><p>取得エラー: ' . esc_html( $locs->get_error_message() ) . '</p></div>';
			} elseif ( ! $locs ) {
				echo '<div class="notice notice-warning"><p>ロケーションが見つかりませんでした。</p></div>';
			} else {
				echo '<table class="wp-list-table widefat striped" style="max-width:760px"><thead><tr><th>ロケーション名</th><th>住所</th><th>状態</th><th>Location ID（コピーして使用）</th></tr></thead><tbody>';
				foreach ( $locs as $loc ) {
					echo '<tr><td>' . esc_html( $loc['name'] ) . '</td><td>' . esc_html( $loc['address'] ) . '</td><td>' . esc_html( $loc['status'] ) . '</td>';
					echo '<td><code style="user-select:all">' . esc_html( $loc['id'] ) . '</code></td></tr>';
				}
				echo '</tbody></table>';
				echo '<p class="description">現在の環境: <strong>' . esc_html( 'production' === $s['square_env'] ? '本番' : 'Sandbox（テスト）' ) . '</strong>。環境を切り替えるとIDも変わります。</p>';
			}
		}

		/* 適用マトリクス */
		echo '<h3>実際に適用されるLocation ID</h3>';
		echo '<table class="wp-list-table widefat striped" style="max-width:760px"><thead><tr><th>店舗</th><th>日本語予約</th><th>英語予約</th></tr></thead><tbody>';
		foreach ( BV_Util::stores() as $sk => $store ) {
			$lja = BV_Util::store_square_location( $sk, 'ja' );
			$len = BV_Util::store_square_location( $sk, 'en' );
			echo '<tr><td>' . esc_html( $store['ja'] ) . '</td>';
			echo '<td><code>' . esc_html( $lja ?: '未設定' ) . '</code></td>';
			echo '<td><code>' . esc_html( $len ?: '未設定' ) . '</code>' . ( $len && $len === $lja ? ' <span class="description">（日本語と同じ）</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		/* テスト送信 */
		echo '<hr><h2>メール送信テスト</h2>';
		if ( isset( $_POST['bvrm_send_test'] ) && check_admin_referer( 'bvrm_test_mail' ) ) {
			$to = sanitize_email( wp_unslash( $_POST['test_to'] ) );
			$store = sanitize_key( wp_unslash( $_POST['test_store'] ) );
			if ( is_email( $to ) ) {
				$sent = BV_Mailer::send_test( $to, $store );
				$from = $store ? BV_Util::store_from( $store ) : array( 'email' => $s['admin_email'], 'name' => $s['mail_from_name'] );
				echo '<div class="notice notice-' . ( $sent ? 'success' : 'error' ) . '"><p>' . ( $sent
					? esc_html( $to . ' 宛に送信しました（差出人: ' . $from['name'] . ' <' . $from['email'] . '>）。実際の受信メールで送信元をご確認ください。' )
					: '送信に失敗しました。サーバーのメール設定をご確認ください。' ) . '</p></div>';
			}
		}
		echo '<form method="post">';
		wp_nonce_field( 'bvrm_test_mail' );
		echo '<table class="form-table"><tr><th>送信先</th><td><input type="email" name="test_to" class="regular-text" value="' . esc_attr( $s['admin_email'] ) . '"></td></tr>';
		echo '<tr><th>店舗</th><td><select name="test_store"><option value="">（共通設定）</option>';
		foreach ( BV_Util::stores() as $sk => $store ) echo '<option value="' . esc_attr( $sk ) . '">' . esc_html( $store['ja'] ) . '</option>';
		echo '</select></td></tr></table>';
		submit_button( 'テスト送信', 'secondary', 'bvrm_send_test' );
		echo '</form>';

		/* メールテンプレート */
		echo '<hr><h2>メールテンプレート（日本語・英語）</h2><form method="post">';
		wp_nonce_field( 'bvrm_templates' );
		echo '<input type="hidden" name="bvrm_save_templates" value="1">';
		echo '<p class="description">使用可能なプレースホルダー: {name} {code} {store} <strong>{store_access}</strong>（店舗名＋来店場所の説明）{class}（定員つき）<strong>{vehicle}</strong>（割当車両名＋ナンバー／未割当なら「未割当」）<strong>{plate}</strong>（ナンバーのみ）{pickup} {return}（曜日つき）{days} <strong>{coverage}</strong> <strong>{equipment}</strong> <strong>{shuttle_text}</strong> {total} {breakdown} {pay_link} {pay_block} <strong>{deadline}</strong>（お支払い期限の案内）{manage_link} {shuttle_note} {otp} <strong>{company}</strong>（＝予約店舗のメール表示名）<strong>{company_legal}</strong>（＝法人名）<br>車両調整の問い合わせメールでは <code>{message}</code>（問い合わせ内容）<code>{email}</code> <code>{phone}</code> <code>{lang}</code>、変更申請メールでは <code>{change_request}</code>（変更希望内容）、キャンセル・変更の管理者通知では <code>{payment_status}</code>、自動キャンセル通知では <code>{deadline_hours}</code>、キャンセル通知では <code>{cancel_policy}</code>（ポリシー全文）<code>{cancel_tier}</code>（適用区分）<code>{cancel_pct}</code>（%）<code>{cancel_fee}</code>（キャンセル料）<code>{refund_note}</code>（返金のご案内）、支払リマインドでは <code>{deadline_note}</code>（期限までの残り時間の案内）も使えます。<code>{cancel_policy}</code> はすべてのメールで使えます。<br>追加料金のメールでは <code>{addon_amount}</code>（追加請求額）<code>{addon_link}</code>（差額の決済リンク）<code>{addon_reason}</code>（請求理由）<code>{paid_amount}</code>（収納済み額）<code>{balance_text}</code>（過不足）が使えます。<br>返却後のお礼・口コミ依頼メールでは <code>{review_link}</code>（店舗ごとの口コミ投稿URL）<code>{coupon_link}</code>（クーポンの受け取りリンク）<code>{coupon_amount}</code>（割引額）、お礼クーポンの送付メールではさらに <code>{coupon_code}</code> <code>{coupon_expires}</code> が使えます。</p>';
		$names = array(
			'provisional_ja' => '仮予約（日本語）', 'provisional_en' => '仮予約（英語）',
			'paid_ja' => '支払完了（日本語）', 'paid_en' => '支払完了（英語）',
			'cancelled_ja' => 'キャンセル（日本語）', 'cancelled_en' => 'キャンセル（英語）',
			'reminder_ja' => '支払リマインド（日本語）', 'reminder_en' => '支払リマインド（英語）',
			'otp_ja' => '認証コード（日本語）', 'otp_en' => '認証コード（英語）',
			'admin_new_ja'    => '管理者通知（新規予約）',
			'admin_paid_ja'   => '管理者通知（決済完了・予約確定）',
			'noshow_ja'       => '無断キャンセルの通知（日本語）',
			'noshow_en'       => '無断キャンセルの通知（英語）',
			'pickup_week_ja'  => '出発1週間前のご案内（日本語）',
			'pickup_week_en'  => '出発1週間前のご案内（英語）',
			'pickup_day_ja'   => '出発前日のご案内（日本語）',
			'pickup_day_en'   => '出発前日のご案内（英語）',
			'change_done_ja'  => '日程変更の完了通知（日本語）',
			'change_done_en'  => '日程変更の完了通知（英語）',
			'admin_change_done_ja' => '管理者通知（お客様による日程変更）',
			'autocancel_ja'   => '自動キャンセル通知（日本語）',
			'autocancel_en'   => '自動キャンセル通知（英語）',
			'admin_autocancel_ja' => '管理者通知（自動キャンセル）',
			'admin_cancel_ja' => '管理者通知（キャンセル・返信先はお客様）',
			'admin_change_ja'    => '管理者通知（変更申請・返信先はお客様）',
			'admin_shuttle_ja'     => '管理者通知（送迎リクエスト・返信先はお客様）',
			'shuttle_quote_ja'     => '送迎の承認・お支払い案内（日本語）',
			'shuttle_quote_en'     => '送迎の承認・お支払い案内（英語）',
			'shuttle_paid_ja'      => '送迎確定（日本語）',
			'shuttle_paid_en'      => '送迎確定（英語）',
			'shuttle_declined_ja'  => '送迎不可のご連絡（日本語）',
			'shuttle_declined_en'  => '送迎不可のご連絡（英語）',
			'change_customer_ja' => '変更申請の受付確認（日本語）',
			'change_customer_en' => '変更申請の受付確認（英語）',
			'inquiry_admin_ja'    => '車両調整の問い合わせ（管理者通知・返信先はお客様）',
			'inquiry_customer_ja' => '車両調整の問い合わせ 受付確認（日本語）',
			'inquiry_customer_en' => '車両調整の問い合わせ 受付確認（英語）',
			'addon_request_ja'    => '追加料金のお支払いのお願い（日本語）',
			'addon_request_en'    => '追加料金のお支払いのお願い（英語）',
			'addon_paid_ja'       => '追加料金の入金確認（日本語）',
			'addon_paid_en'       => '追加料金の入金確認（英語）',
			'admin_addon_paid_ja' => '管理者通知（追加料金の入金）',
			'review_request_ja'   => '返却後のお礼・口コミ依頼（日本語）',
			'review_request_en'   => '返却後のお礼・口コミ依頼（英語）',
			'review_coupon_ja'    => '口コミのお礼クーポン送付（日本語）',
			'review_coupon_en'    => '口コミのお礼クーポン送付（英語）',
		);
		$prev_store = isset( $_GET['prev_store'] ) ? sanitize_key( $_GET['prev_store'] ) : '';
		if ( ! isset( BV_Util::stores()[ $prev_store ] ) ) $prev_store = '';
		echo '<p>プレビュー表示に使う店舗: ';
		foreach ( BV_Util::stores() as $sk2 => $st2 ) {
			$url = add_query_arg( array( 'page' => 'bvrm-settings', 'prev_store' => $sk2 ), admin_url( 'admin.php' ) ) . '#mailtpl';
			echo '<a href="' . esc_url( $url ) . '"' . ( $prev_store === $sk2 ? ' style="font-weight:bold"' : '' ) . '>' . esc_html( $st2['ja'] ) . '</a>　';
		}
		echo '</p>';
		echo '<a id="mailtpl"></a>';

		foreach ( $names as $key => $label ) {
			$tpl = BV_Mailer::get_template( $key );
			echo '<h3>' . esc_html( $label ) . '</h3>';
			echo '<p>件名: <input type="text" name="tpl_subject_' . $key . '" class="large-text" value="' . esc_attr( $tpl['subject'] ) . '"></p>';
			echo '<textarea name="tpl_body_' . $key . '" rows="8" class="large-text" style="font-family:monospace;font-size:12px">' . esc_textarea( $tpl['body'] ) . '</textarea>';
			$pv = BV_Mailer::preview( $key, $prev_store );
			echo '<details style="margin:6px 0 18px"><summary style="cursor:pointer;color:#2271b1">▼ 送信イメージを確認（サンプルデータで差し込み）</summary>';
			echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:12px 16px;max-width:820px;margin-top:6px">';
			echo '<div style="border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:8px"><strong>件名：</strong>' . esc_html( $pv['subject'] ) . '</div>';
			echo '<pre style="white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:13px;line-height:1.7;margin:0">' . esc_html( $pv['body'] ) . '</pre>';
			echo '</div></details>';
		}
		submit_button( 'テンプレートを保存' );
		echo '</form></div>';
	}
}
