<?php
namespace RCAC;

/**
 * フックの登録をまとめる。
 */
final class Plugin {

	public static function boot(): void {
		load_plugin_textdomain( 'rentacar-ai-concierge', false, dirname( plugin_basename( RCAC_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();

		add_action( 'init', array( Inquiries::class, 'register_post_type' ) );
		add_action( 'init', array( KnowledgeBase::class, 'register_post_type' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_action( 'rcac_daily_cleanup', array( ConversationStore::class, 'cleanup' ) );

		Frontend::init();

		if ( is_admin() ) {
			Admin\Admin::init();
			Inquiries::init_admin();
			KnowledgeBase::init_admin();
		}
	}

	/**
	 * Composer の依存（Anthropic SDK）を読み込む。チャット実行時にだけ呼ぶ。
	 */
	public static function load_vendor(): bool {
		static $loaded = null;
		if ( null !== $loaded ) {
			return $loaded;
		}
		$autoload = RCAC_DIR . 'vendor/autoload.php';
		$loaded   = is_readable( $autoload );
		if ( $loaded ) {
			require_once $autoload;
		}
		return $loaded;
	}

	public static function vendor_installed(): bool {
		return is_readable( RCAC_DIR . 'vendor/autoload.php' );
	}
}
