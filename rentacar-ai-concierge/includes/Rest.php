<?php
namespace RCAC;

/**
 * REST API。
 *
 * - POST /wp-json/rcac/v1/chat     チャット（公開）
 * - POST /wp-json/rcac/v1/contact  問い合わせフォーム（公開）
 * - POST /wp-json/rcac/v1/admin/test-chat  管理画面の動作テスト（管理者のみ）
 *
 * 公開エンドポイントはページキャッシュで古い nonce が配られても動くよう nonce を使わず、
 * 署名付き会話トークン・回数制限・ハニーポットで保護する。
 */
final class Rest {

	public const NS = 'rcac/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'chat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
					'token'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/contact',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'contact' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/admin/test-chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'admin_test_chat' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	public static function chat( \WP_REST_Request $request ): \WP_REST_Response {
		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		$max     = max( 50, (int) Settings::get( 'max_input_chars' ) );
		if ( '' === $message ) {
			return self::error( 'empty', 'メッセージを入力してください。', 400 );
		}
		if ( mb_strlen( $message ) > $max ) {
			return self::error( 'too_long', sprintf( 'メッセージは %d 文字以内でお願いします。', $max ), 400 );
		}

		if ( ! Guard::hit( 'chat10', (int) Settings::get( 'rate_per_10min' ), 10 * MINUTE_IN_SECONDS )
			|| ! Guard::hit( 'chatday', (int) Settings::get( 'rate_per_day' ), DAY_IN_SECONDS ) ) {
			return self::error( 'rate_limited', '短時間に多くのメッセージが送信されました。しばらく時間をおいてからお試しください。', 429 );
		}
		if ( ! Guard::hit_global_daily( (int) Settings::get( 'global_daily_limit' ) ) ) {
			return new \WP_REST_Response(
				array(
					'reply'   => '申し訳ありません。本日のチャット受付は終了しました。お問い合わせフォームからご連絡ください。',
					'actions' => array( array( 'type' => 'open_form', 'draft' => '', 'inquiry_type' => '' ) ),
				)
			);
		}

		$token           = (string) $request->get_param( 'token' );
		$conversation_id = '' !== $token ? Guard::verify( $token ) : null;
		if ( null === $conversation_id ) {
			list( $conversation_id, $token ) = Guard::new_conversation_token();
		}

		$max_turns = (int) Settings::get( 'max_turns' );
		if ( $max_turns > 0 && ConversationStore::user_turns( $conversation_id ) >= $max_turns ) {
			return new \WP_REST_Response(
				array(
					'reply'   => '会話が長くなったため、ここで一度区切らせてください。画面上部の「新しい会話」から改めてご質問ください。',
					'token'   => $token,
					'actions' => array( array( 'type' => 'conversation_limit' ) ),
				)
			);
		}

		ConversationStore::ensure_conversation( $conversation_id, esc_url_raw( (string) $request->get_param( 'page' ) ) );
		$result = ( new ChatEngine() )->respond( $conversation_id, $message );

		return new \WP_REST_Response(
			array(
				'reply'   => $result['reply'],
				'token'   => $token,
				'actions' => $result['actions'],
			)
		);
	}

	public static function contact( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_body_params();
		}

		// ハニーポット（人には見えない欄）に入力があればボットとみなし、成功したふりをする。
		if ( ! empty( $data['website'] ) ) {
			return new \WP_REST_Response( array( 'ok' => true, 'message' => (string) Settings::get( 'form_thanks' ) ) );
		}
		// 表示から 3 秒未満の送信は機械的な送信とみなす。
		$elapsed = isset( $data['elapsed'] ) ? (int) $data['elapsed'] : 0;
		if ( $elapsed > 0 && $elapsed < 3000 ) {
			return self::error( 'too_fast', '送信が早すぎます。もう一度お試しください。', 400 );
		}
		// 入力ミスでの再送信は数えず、受け付けた送信だけを 1 時間 5 件までに制限する。
		if ( ! Guard::allowed( 'contact', 5, HOUR_IN_SECONDS ) ) {
			return self::error( 'rate_limited', '短時間に何度も送信されています。しばらく時間をおいてからお試しください。', 429 );
		}

		$result = Inquiries::submit( $data );
		if ( is_wp_error( $result ) ) {
			$err = $result->get_error_data();
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $result->get_error_message(),
					'fields'  => is_array( $err ) ? ( $err['fields'] ?? array() ) : array(),
				),
				400
			);
		}
		Guard::record( 'contact', 5, HOUR_IN_SECONDS );
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => (string) Settings::get( 'form_thanks' ),
			)
		);
	}

	/** 管理画面の「動作テスト」。ログは保存しない。 */
	public static function admin_test_chat( \WP_REST_Request $request ): \WP_REST_Response {
		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( '' === $message ) {
			$message = 'こんにちは。どんなことを質問できますか？';
		}
		list( $conversation_id ) = Guard::new_conversation_token();
		ConversationStore::ensure_conversation( $conversation_id, 'admin-test' );
		$start  = microtime( true );
		$result = ( new ChatEngine() )->respond( $conversation_id, $message );
		$conv   = ConversationStore::conversation( $conversation_id );
		ConversationStore::delete( $conversation_id );

		$error = get_option( 'rcac_last_error' );
		return new \WP_REST_Response(
			array(
				'reply'   => $result['reply'],
				'actions' => $result['actions'],
				'seconds' => round( microtime( true ) - $start, 1 ),
				'usage'   => $conv ? array(
					'input'       => (int) $conv['input_tokens'],
					'output'      => (int) $conv['output_tokens'],
					'cache_read'  => (int) $conv['cache_read_tokens'],
					'cache_write' => (int) $conv['cache_write_tokens'],
					'cost_usd'    => (float) $conv['cost_usd'],
				) : null,
				'error'   => is_array( $error ) && ( $error['time'] ?? 0 ) >= (int) $start ? $error['message'] : null,
			)
		);
	}

	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
