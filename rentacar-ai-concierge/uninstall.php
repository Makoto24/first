<?php
/**
 * プラグイン削除時の処理。
 * 「削除時にデータを削除する」設定が有効な場合のみ、設定・会話ログ・Q&A・問い合わせを削除する。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rcac_settings = get_option( 'rcac_settings', array() );
if ( empty( $rcac_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'rcac_faq', 'rcac_inquiry' ) as $rcac_post_type ) {
	$rcac_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $rcac_post_type ) );
	foreach ( $rcac_ids as $rcac_id ) {
		wp_delete_post( (int) $rcac_id, true );
	}
}

$rcac_terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'rcac_faq_cat' ) );
foreach ( $rcac_terms as $rcac_term ) {
	wp_delete_term( (int) $rcac_term, 'rcac_faq_cat' );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rcac_messages" );      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rcac_conversations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

foreach ( array( 'rcac_settings', 'rcac_db_version', 'rcac_hmac_secret', 'rcac_last_error' ) as $rcac_option ) {
	delete_option( $rcac_option );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_rcac\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rcac\\_%'" );
