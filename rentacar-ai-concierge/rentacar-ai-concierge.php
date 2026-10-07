<?php
/**
 * Plugin Name:       レンタカー AIコンシェルジュ（Chatbot & 問い合わせフォーム）
 * Description:       Claude API を使ったレンタカーサイト向けチャットボットと問い合わせフォーム。Q&A集の取り込み、既存予約プラグインの予約データと連動した空車案内に対応。
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Rentacar AI Concierge
 * License:           GPL-2.0-or-later
 * Text Domain:       rentacar-ai-concierge
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RCAC_VERSION', '0.1.0' );
define( 'RCAC_FILE', __FILE__ );
define( 'RCAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RCAC_URL', plugin_dir_url( __FILE__ ) );
define( 'RCAC_MIN_PHP', '8.1' );

// このファイルは古い PHP でも構文エラーにならないように書き、バージョン確認後に本体を読み込む。
if ( version_compare( PHP_VERSION, RCAC_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html( sprintf( 'レンタカー AIコンシェルジュ は PHP %1$s 以上が必要です（現在: %2$s）。サーバーの PHP バージョンを更新してください。', RCAC_MIN_PHP, PHP_VERSION ) );
			echo '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	function ( $class ) {
		$prefix = 'RCAC\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = RCAC_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'RCAC\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RCAC\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'RCAC\\Plugin', 'boot' ) );
