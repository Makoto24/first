<?php
/**
 * 会話ログと利用量
 * ログはQ&A集を育てるため（よくある質問・答えられなかった質問の把握）に使う。
 * 保存期間を過ぎたものは毎日自動で削除する。IPアドレスは保存しない。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BVCB_Log {

	const USAGE = 'bvcb_usage';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bvcb_logs';
	}

	public static function init() {
		add_action( 'bvcb_purge_logs', array( __CLASS__, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sid varchar(32) NOT NULL DEFAULT '',
			role varchar(12) NOT NULL DEFAULT '',
			body mediumtext NOT NULL,
			lang varchar(5) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY sid (sid),
			KEY created_at (created_at)
		) $charset;" );
		update_option( 'bvcb_db_version', BVCB_DB_VERSION, false );
		if ( ! wp_next_scheduled( 'bvcb_purge_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bvcb_purge_logs' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'bvcb_db_version' ) !== BVCB_DB_VERSION ) self::install();
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( 'bvcb_purge_logs' );
	}

	/** role: user / bot / tool / error */
	public static function add( $sid, $role, $body, $lang = '' ) {
		$o = BVCB_Settings::get();
		if ( empty( $o['log_enabled'] ) ) return;
		global $wpdb;
		$wpdb->insert( self::table(), array(
			'sid'        => substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $sid ), 0, 32 ),
			'role'       => substr( (string) $role, 0, 12 ),
			/* お客様が免許証番号・カード番号を書き込んでも、記録には残さない */
			'body'       => BVCB_Settings::redact_sensitive( (string) $body ),
			'lang'       => ( 'en' === $lang ) ? 'en' : 'ja',
			'created_at' => BVCB_Settings::now(),
		) );
	}

	public static function purge() {
		global $wpdb;
		$days = max( 1, (int) BVCB_Settings::get()['log_days'] );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s',
			gmdate( 'Y-m-d H:i:s', strtotime( BVCB_Settings::now() . ' UTC' ) - $days * DAY_IN_SECONDS ) ) );
	}

	/** 直近の会話（セッションごと） */
	public static function sessions( $limit = 50 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT sid, MIN(created_at) AS started, MAX(created_at) AS last_at, COUNT(*) AS n,
				SUM(role = 'user') AS questions, SUM(role = 'tool') AS tool_uses, SUM(role = 'error') AS errors
			FROM $t GROUP BY sid ORDER BY last_at DESC LIMIT %d", (int) $limit ) );
	}

	public static function thread( $sid ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE sid = %s ORDER BY id ASC', (string) $sid ) );
	}

	/* ---------- 利用量（1日あたりの呼び出し回数・トークン） ---------- */

	public static function usage() {
		$u = get_option( self::USAGE, array() );
		$today = BVCB_Settings::now( 'Y-m-d' );
		if ( ! is_array( $u ) || ( $u['date'] ?? '' ) !== $today ) {
			$u = array( 'date' => $today, 'calls' => 0, 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0 );
		}
		return $u;
	}

	public static function count_usage( $resp ) {
		$u = self::usage();
		$us = isset( $resp['usage'] ) && is_array( $resp['usage'] ) ? $resp['usage'] : array();
		$u['calls']++;
		$u['input']       += (int) ( $us['input_tokens'] ?? 0 );
		$u['output']      += (int) ( $us['output_tokens'] ?? 0 );
		$u['cache_read']  += (int) ( $us['cache_read_input_tokens'] ?? 0 );
		$u['cache_write'] += (int) ( $us['cache_creation_input_tokens'] ?? 0 );
		update_option( self::USAGE, $u, false );
	}

	public static function within_daily_limit() {
		$limit = (int) BVCB_Settings::get()['daily_limit'];
		return $limit < 1 || self::usage()['calls'] < $limit;
	}
}
