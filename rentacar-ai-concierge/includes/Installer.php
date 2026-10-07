<?php
namespace RCAC;

/**
 * 有効化・無効化・DB テーブル作成。
 */
final class Installer {

	public const DB_VERSION = '1';

	public static function activate(): void {
		self::create_tables();
		Inquiries::register_post_type();
		KnowledgeBase::register_post_type();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( 'rcac_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rcac_daily_cleanup' );
		}
		if ( false === get_option( 'rcac_hmac_secret' ) ) {
			add_option( 'rcac_hmac_secret', wp_generate_password( 64, true, true ), '', false );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rcac_daily_cleanup' );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'rcac_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$conv    = $wpdb->prefix . 'rcac_conversations';
		$msgs    = $wpdb->prefix . 'rcac_messages';

		dbDelta(
			"CREATE TABLE {$conv} (
  id char(32) NOT NULL,
  ip_hash char(64) NOT NULL DEFAULT '',
  page_url varchar(255) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  message_count int(10) unsigned NOT NULL DEFAULT 0,
  input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  cache_read_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  cache_write_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  cost_usd decimal(12,6) NOT NULL DEFAULT 0,
  inquiry_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY updated_at (updated_at)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$msgs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id char(32) NOT NULL,
  role varchar(20) NOT NULL,
  content longtext NOT NULL,
  meta longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id)
) {$charset};"
		);

		update_option( 'rcac_db_version', self::DB_VERSION, false );
	}
}
