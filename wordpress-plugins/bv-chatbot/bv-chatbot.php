<?php
/**
 * Plugin Name: BV Chatbot（レンタカー問い合わせチャット）
 * Description: Claude（Anthropic）を使ったお問い合わせ専用チャット。Q&A集をもとに回答し、空車確認は中央サイトの予約管理（ガントチャート）と同じ判定で答えます。画面右下に表示するか、ショートコード [bv_chatbot] で設置します。
 * Version:     0.4.0
 * Author:      Be Village株式会社
 * Text Domain: bv-chatbot
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BVCB_VERSION', '0.4.0' );
define( 'BVCB_DB_VERSION', '1' );
define( 'BVCB_URL', plugin_dir_url( __FILE__ ) );
define( 'BVCB_DIR', plugin_dir_path( __FILE__ ) );

require_once BVCB_DIR . 'includes/class-bvcb-settings.php';
require_once BVCB_DIR . 'includes/class-bvcb-knowledge.php';
require_once BVCB_DIR . 'includes/class-bvcb-central.php';
require_once BVCB_DIR . 'includes/class-bvcb-claude.php';
require_once BVCB_DIR . 'includes/class-bvcb-log.php';
require_once BVCB_DIR . 'includes/class-bvcb-chat.php';
require_once BVCB_DIR . 'includes/class-bvcb-notify.php';
if ( is_admin() ) {
	require_once BVCB_DIR . 'includes/class-bvcb-admin.php';
	BVCB_Admin::init();
}

BVCB_Chat::init();
BVCB_Log::init();
BVCB_Notify::init();

register_activation_hook( __FILE__, array( 'BVCB_Log', 'install' ) );
register_deactivation_hook( __FILE__, array( 'BVCB_Log', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'BVCB_Notify', 'unschedule' ) );
