<?php
/**
 * Plugin Name: BV Rental Manager（Be Village レンタカー統合管理）
 * Plugin URI:  https://be-village.com
 * Description: レンタカー予約・車両・顧客・売上・業績の一元管理。地域サイトの予約フォームプラグインとREST APIで連携。Square決済、スタッフポータル、予約ガント、貸渡実績報告書出力対応。
 * Version:     1.33.1
 * Author:      Be Village株式会社
 * Text Domain: bv-rental
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BVRM_VERSION', '1.33.1' );
define( 'BVRM_FILE', __FILE__ );
define( 'BVRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'BVRM_URL', plugin_dir_url( __FILE__ ) );

require_once BVRM_DIR . 'includes/class-bv-util.php';
require_once BVRM_DIR . 'includes/class-bv-files.php';
require_once BVRM_DIR . 'includes/class-bv-db.php';
require_once BVRM_DIR . 'includes/class-bv-pricing.php';
require_once BVRM_DIR . 'includes/class-bv-availability.php';
require_once BVRM_DIR . 'includes/class-bv-gantt.php';
require_once BVRM_DIR . 'includes/class-bv-mailer.php';
require_once BVRM_DIR . 'includes/class-bv-square.php';
require_once BVRM_DIR . 'includes/class-bv-members.php';
require_once BVRM_DIR . 'includes/class-bv-api.php';
require_once BVRM_DIR . 'includes/class-bv-print.php';
require_once BVRM_DIR . 'includes/class-bv-report.php';
require_once BVRM_DIR . 'includes/class-bv-staff-portal.php';
require_once BVRM_DIR . 'includes/class-bv-review.php';
if ( is_admin() ) {
	require_once BVRM_DIR . 'includes/admin/class-bv-admin.php';
}

register_activation_hook( __FILE__, array( 'BV_DB', 'install' ) );
/* 本人確認書類の非公開ディレクトリと、直接アクセス禁止の設定を用意する */
register_activation_hook( __FILE__, array( 'BV_Files', 'ensure_dir' ) );

add_action( 'plugins_loaded', function () {
	BV_DB::maybe_upgrade();
	BV_Files::init();
	BV_API::init();
	BV_Members::init();
	BV_Print::init();
	BV_Staff_Portal::init();
	BV_Review::init();
	if ( is_admin() ) BV_Admin::init();
} );

/* 支払いリマインダー用 cron */
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'bvrm_daily_tasks' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 09:00' ), 'daily', 'bvrm_daily_tasks' );
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'bvrm_daily_tasks' );
	wp_clear_scheduled_hook( 'bvrm_sync_payments' );
	wp_clear_scheduled_hook( 'bvrm_pending_tasks' );
} );
add_action( 'bvrm_daily_tasks', array( 'BV_Mailer', 'send_payment_reminders' ) );

/* 日次の後片付け：期限切れ認証コードの削除と、保持期間を過ぎた本人確認書類の削除 */
add_action( 'bvrm_daily_tasks', function () {
	BV_DB::purge_expired_otp( 1 );
	BV_Files::purge_expired_documents();
} );

/*
 * 支払い督促・自動キャンセルの判定。
 * 支払期限は数時間単位で設定できるため、1日1回ではなく15分ごとに確認する。
 */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'bvrm_pending_tasks' ) ) {
		wp_schedule_event( time() + 120, 'bvrm_15min', 'bvrm_pending_tasks' );
	}
} );
add_action( 'bvrm_pending_tasks', function () {
	BV_Mailer::send_payment_reminders();
	BV_Mailer::auto_cancel_expired();
	BV_Mailer::send_pickup_reminders();
} );

/* 支払状況の自動同期（Webhookが届かない場合の保険） */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['bvrm_15min'] ) ) {
		$schedules['bvrm_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => '15分ごと（BVレンタカー）' );
	}
	return $schedules;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'bvrm_sync_payments' ) ) {
		wp_schedule_event( time() + 300, 'bvrm_15min', 'bvrm_sync_payments' );
	}
} );
add_action( 'bvrm_sync_payments', function () {
	$s = BV_Util::settings();
	if ( empty( $s['square_access_token'] ) ) return;
	BV_Square::sync_pending_payments( 40 );
} );
