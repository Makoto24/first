<?php
/* プラグインを「削除」したときに、設定・Q&A集・会話ログを消す */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;
delete_option( 'bvcb_options' );
delete_option( 'bvcb_knowledge' );
delete_option( 'bvcb_usage' );
delete_option( 'bvcb_notify_state' );
wp_clear_scheduled_hook( 'bvcb_notify' );
delete_option( 'bvcb_db_version' );
delete_transient( 'bvcb_central_config' );
delete_transient( 'bvcb_central_rates' );
wp_clear_scheduled_hook( 'bvcb_purge_logs' );
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bvcb_logs' );
