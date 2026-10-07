<?php
namespace RCAC;

/**
 * サイト側の表示（チャットボタン・ショートコード）。
 *
 * [rcac_chatbot]      ページ内にチャット画面を埋め込む
 * [rcac_contact_form] ページ内に問い合わせフォームを埋め込む
 *
 * 画面は Shadow DOM の中に描画するため、テーマ（Lightning など）の CSS の影響を受けない。
 */
final class Frontend {

	private static bool $needs_assets = false;

	public static function init(): void {
		add_shortcode( 'rcac_chatbot', array( self::class, 'shortcode_chatbot' ) );
		add_shortcode( 'rcac_contact_form', array( self::class, 'shortcode_form' ) );
		add_action( 'wp_footer', array( self::class, 'footer' ), 5 );
	}

	private static function floating_enabled(): bool {
		if ( ! Settings::get( 'widget_enabled' ) || is_admin() ) {
			return false;
		}
		$excluded = array_filter( array_map( 'intval', explode( ',', (string) Settings::get( 'exclude_pages' ) ) ) );
		if ( $excluded && is_singular() && in_array( (int) get_queried_object_id(), $excluded, true ) ) {
			return false;
		}
		/**
		 * 右下のチャットボタンを表示するかどうか。
		 */
		return (bool) apply_filters( 'rcac_show_floating_widget', true );
	}

	public static function shortcode_chatbot(): string {
		self::$needs_assets = true;
		return '<div class="rcac-embed" data-rcac-mode="chat"></div>';
	}

	public static function shortcode_form(): string {
		self::$needs_assets = true;
		return '<div class="rcac-embed" data-rcac-mode="form"></div>';
	}

	public static function footer(): void {
		$floating = self::floating_enabled();
		if ( ! $floating && ! self::$needs_assets ) {
			return;
		}
		if ( $floating ) {
			echo '<div class="rcac-embed" data-rcac-mode="floating"></div>';
		}

		wp_enqueue_script( 'rcac-widget', RCAC_URL . 'assets/js/widget.js', array(), RCAC_VERSION, true );
		wp_add_inline_script( 'rcac-widget', 'window.RCAC_CONFIG = ' . wp_json_encode( self::config(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		return array(
			'restUrl'        => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'cssUrl'         => RCAC_URL . 'assets/css/widget.css?ver=' . RCAC_VERSION,
			'botName'        => (string) Settings::get( 'bot_name' ),
			'greeting'       => (string) Settings::get( 'greeting' ),
			'quickReplies'   => Settings::lines( 'quick_replies' ),
			'color'          => (string) Settings::get( 'primary_color' ),
			'position'       => (string) Settings::get( 'widget_position' ),
			'privacyUrl'     => (string) Settings::get( 'privacy_url' ),
			'reservationUrl' => (string) Settings::get( 'reservation_url' ),
			'inquiryTypes'   => Settings::lines( 'inquiry_types' ),
			'maxChars'       => (int) Settings::get( 'max_input_chars' ),
			'chatAvailable'  => '' !== Settings::api_key() && Plugin::vendor_installed(),
		);
	}
}
