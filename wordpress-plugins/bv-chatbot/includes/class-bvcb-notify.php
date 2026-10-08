<?php
/**
 * 会話ログのメール通知
 *
 *   each  … 会話が終わったら（最後のやり取りから一定時間たったら）1件ずつ送る
 *   daily … 毎朝、前日分の会話をまとめて1通で送る
 *
 * 1メッセージごとには送らない（お客様が続けて質問している途中で何通も届かないように）。
 * 会話ログ（会話を記録する）がオンのときだけ動く。通知メールにはお客様の入力内容がそのまま入る。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BVCB_Notify {

	const HOOK  = 'bvcb_notify';
	const STATE = 'bvcb_notify_state';
	/** 1通に載せる会話の上限（多すぎる分は件数だけ書いて管理画面へ） */
	const MAX_THREADS = 30;
	/** 1つの発言をメールに載せる長さの上限 */
	const MAX_BODY = 1500;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function schedules( $s ) {
		$s['bvcb_10min'] = array( 'interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'BV Chatbot: 10分ごと' );
		return $s;
	}

	/** 通知がオンなら10分ごとの確認を予約し、オフなら止める */
	public static function maybe_schedule() {
		$on = self::enabled();
		$next = wp_next_scheduled( self::HOOK );
		if ( $on && ! $next ) wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'bvcb_10min', self::HOOK );
		if ( ! $on && $next ) wp_clear_scheduled_hook( self::HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function enabled() {
		$o = BVCB_Settings::get();
		return in_array( $o['notify_mode'], array( 'each', 'daily' ), true ) && ! empty( $o['log_enabled'] ) && self::recipients();
	}

	/** 通知先（カンマ・改行区切り、正しいメールアドレスだけ） */
	public static function recipients() {
		$raw = (string) BVCB_Settings::get()['notify_to'];
		$out = array();
		foreach ( preg_split( '/[\s,;、]+/u', $raw ) as $a ) {
			$a = trim( $a );
			if ( '' !== $a && is_email( $a ) ) $out[] = $a;
		}
		return array_values( array_unique( $out ) );
	}

	protected static function state() {
		$s = get_option( self::STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array_merge( array( 'last_id' => -1, 'sent' => array(), 'daily_date' => '', 'daily_last_id' => -1 ), $s );
	}

	protected static function save_state( $s ) {
		update_option( self::STATE, $s, false );
	}

	/** いまのログの最新ID（通知を始めた時点より前の会話は送らない） */
	protected static function max_id() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . BVCB_Log::table() );
	}

	/** 定期実行 */
	public static function run() {
		if ( ! self::enabled() ) return;
		$mode = BVCB_Settings::get()['notify_mode'];
		if ( 'each' === $mode ) self::run_each();
		else self::run_daily();
	}

	/* ---------- 会話ごと ---------- */

	public static function run_each() {
		global $wpdb;
		$st = self::state();
		if ( $st['last_id'] < 0 ) {
			/* 通知を始めたばかり：これまでの会話は送らない */
			$st['last_id'] = self::max_id();
			self::save_state( $st );
			return;
		}
		$idle = max( 5, (int) BVCB_Settings::get()['notify_idle'] );
		$cut  = gmdate( 'Y-m-d H:i:s', strtotime( BVCB_Settings::now() . ' UTC' ) - $idle * MINUTE_IN_SECONDS );
		$t = BVCB_Log::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE id > %d ORDER BY id ASC LIMIT 2000", (int) $st['last_id'] ) );
		if ( ! $rows ) return;

		$by = array();
		foreach ( $rows as $r ) $by[ $r->sid ][] = $r;

		$done = array();
		$min_open = null;
		foreach ( $by as $sid => $list ) {
			/* 最後のやり取りは、このIDより後の行だけでなく会話全体で判断する */
			$last_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM $t WHERE sid = %s", $sid ) );
			$already = isset( $st['sent'][ $sid ] ) ? (int) $st['sent'][ $sid ] : 0;
			$new = array_values( array_filter( $list, function ( $r ) use ( $already ) { return (int) $r->id > $already; } ) );
			if ( $last_at >= $cut ) {
				/* まだ続いている会話：次の回に回す */
				$first = (int) $list[0]->id;
				$min_open = null === $min_open ? $first : min( $min_open, $first );
				continue;
			}
			if ( $new ) $done[ $sid ] = $new;
		}

		$failed = false;
		foreach ( $done as $sid => $list ) {
			if ( ! $failed && ! self::has_question( $list ) ) { $st['sent'][ $sid ] = (int) end( $list )->id; continue; }
			if ( ! $failed && self::send( self::subject_each( $list ), self::thread_text( $sid, $list ) . self::footer() ) ) {
				$st['sent'][ $sid ] = (int) end( $list )->id;
				continue;
			}
			/* 送れなかった会話（とそれ以降）は、次の回にやり直す */
			$failed = true;
			$first = (int) $list[0]->id;
			$min_open = null === $min_open ? $first : min( $min_open, $first );
		}

		/* 次回はここから。続いている会話があれば、その最初の行の手前まで */
		$max = (int) end( $rows )->id;
		$st['last_id'] = null === $min_open ? $max : max( (int) $st['last_id'], $min_open - 1 );
		foreach ( $st['sent'] as $sid => $id ) {
			if ( $id <= $st['last_id'] ) unset( $st['sent'][ $sid ] );
		}
		self::save_state( $st );
	}

	/* ---------- 毎朝まとめて ---------- */

	public static function run_daily() {
		global $wpdb;
		$st = self::state();
		$today = BVCB_Settings::now( 'Y-m-d' );
		if ( $st['daily_last_id'] < 0 ) {
			$st['daily_last_id'] = self::max_id();
			$st['daily_date'] = $today;
			self::save_state( $st );
			return;
		}
		$hour = (int) BVCB_Settings::now( 'G' );
		$at   = (int) BVCB_Settings::get()['notify_hour'];
		if ( $st['daily_date'] === $today || $hour < $at ) return;

		$t = BVCB_Log::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE id > %d ORDER BY id ASC LIMIT 5000", (int) $st['daily_last_id'] ) );
		$by = array();
		foreach ( $rows as $r ) $by[ $r->sid ][] = $r;
		$by = array_filter( $by, array( __CLASS__, 'has_question' ) );

		if ( $by ) {
			$n = count( $by );
			$body = '前回の通知以降のチャットボットの会話：' . $n . "件\n\n";
			$i = 0;
			foreach ( $by as $sid => $list ) {
				if ( ++$i > self::MAX_THREADS ) {
					$body .= '（ほか ' . ( $n - self::MAX_THREADS ) . " 件は管理画面の「会話ログ」でご覧ください）\n\n";
					break;
				}
				$body .= '━━━━━━━━━━ ' . $i . " ━━━━━━━━━━\n" . self::thread_text( $sid, $list ) . "\n";
			}
			$errors = 0;
			foreach ( $by as $list ) foreach ( $list as $r ) if ( 'error' === $r->role ) $errors++;
			$subject = '【チャットボット】' . BVCB_Settings::now( 'n/j' ) . ' 会話のまとめ ' . $n . '件' . ( $errors ? '（エラーあり）' : '' );
			if ( ! self::send( $subject, $body . self::footer() ) ) return;
		}
		$st['daily_date'] = $today;
		if ( $rows ) $st['daily_last_id'] = (int) end( $rows )->id;
		self::save_state( $st );
	}

	/* ---------- メールの中身 ---------- */

	/** お客様の質問が1つ以上ある会話だけ送る（開いただけ・エラーだけの記録は送らない） */
	public static function has_question( $list ) {
		foreach ( $list as $r ) if ( 'user' === $r->role ) return true;
		return false;
	}

	protected static function subject_each( $list ) {
		$q = '';
		$err = false;
		foreach ( $list as $r ) {
			if ( 'user' === $r->role && '' === $q ) $q = (string) $r->body;
			if ( 'error' === $r->role ) $err = true;
		}
		$q = preg_replace( '/\s+/u', ' ', $q );
		if ( function_exists( 'mb_strimwidth' ) ) $q = mb_strimwidth( $q, 0, 40, '…', 'UTF-8' );
		return '【チャットボット】' . ( $err ? '[エラーあり] ' : '' ) . $q;
	}

	/** 1つの会話をメール本文にする */
	public static function thread_text( $sid, $list ) {
		$labels = array( 'user' => 'お客様', 'bot' => 'チャット', 'tool' => '（空車確認）', 'error' => '（エラー）' );
		$first = $list[0];
		$t  = '開始：' . substr( (string) $first->created_at, 0, 16 ) . '　言語：' . ( 'en' === $first->lang ? '英語' : '日本語' ) . "\n";
		$t .= '会話ログ：' . self::log_url( $sid ) . "\n\n";
		foreach ( $list as $r ) {
			$body = trim( (string) $r->body );
			if ( 'tool' === $r->role ) $body = self::tool_summary( $body );
			if ( function_exists( 'mb_strimwidth' ) && mb_strlen( $body, 'UTF-8' ) > self::MAX_BODY ) {
				$body = mb_substr( $body, 0, self::MAX_BODY, 'UTF-8' ) . '…（続きは会話ログで）';
			}
			$t .= '■ ' . ( $labels[ $r->role ] ?? $r->role ) . '（' . substr( (string) $r->created_at, 11, 5 ) . "）\n" . $body . "\n\n";
		}
		return $t;
	}

	/** 空車確認の記録（JSON）を短く読みやすくする */
	protected static function tool_summary( $body ) {
		$parts = explode( "\n→ ", $body, 2 );
		$in  = json_decode( $parts[0], true );
		$out = isset( $parts[1] ) ? json_decode( $parts[1], true ) : null;
		if ( ! is_array( $in ) ) return $body;
		$s = ( $in['pickup_dt'] ?? '' ) . ' 〜 ' . ( $in['return_dt'] ?? '' );
		if ( is_array( $out ) && isset( $out['store'] ) ) {
			$s = $out['store'] . '　' . $s;
			$c = array();
			foreach ( (array) ( $out['classes'] ?? array() ) as $row ) {
				$c[] = ( $row['class'] ?? '' ) . '：' . ( ! empty( $row['available'] ) ? '空きあり' : '満車' );
			}
			if ( $c ) $s .= "\n" . implode( '／', $c );
		} elseif ( isset( $parts[1] ) ) {
			$s .= "\n→ " . $parts[1];
		}
		return $s;
	}

	protected static function log_url( $sid ) {
		return admin_url( 'admin.php?page=bvcb&tab=logs&sid=' . rawurlencode( $sid ) );
	}

	protected static function footer() {
		return "――\nこのメールは「BV Chatbot」の会話通知です（" . home_url( '/' ) . "）。\n"
			. "お客様の入力内容がそのまま含まれます。転送する場合はご注意ください。\n"
			. "通知の設定：" . admin_url( 'admin.php?page=bvcb&tab=settings' ) . "\n";
	}

	protected static function send( $subject, $body ) {
		$to = self::recipients();
		if ( ! $to ) return false;
		return (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/** 設定画面の「テスト送信」：いちばん新しい会話を送る */
	public static function send_test() {
		global $wpdb;
		$to = self::recipients();
		if ( ! $to ) return '通知先のメールアドレスを入力して保存してください。';
		$t = BVCB_Log::table();
		$sid = (string) $wpdb->get_var( "SELECT sid FROM $t WHERE role = 'user' ORDER BY id DESC LIMIT 1" );
		if ( '' === $sid ) {
			$ok = self::send( '【チャットボット】通知のテスト', "通知メールのテストです。会話がまだ記録されていないため、内容はありません。\n\n" . self::footer() );
		} else {
			$list = BVCB_Log::thread( $sid );
			$ok = self::send( '【チャットボット・テスト】' . preg_replace( '/^【チャットボット】/u', '', self::subject_each( $list ) ), self::thread_text( $sid, $list ) . self::footer() );
		}
		return $ok ? 'テストメールを送りました（' . implode( ', ', $to ) . '）。' : 'メールを送れませんでした。サイトのメール送信設定（SMTPなど）をご確認ください。';
	}
}
