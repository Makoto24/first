<?php
namespace RCAC;

/**
 * 問い合わせフォームの受付（カスタム投稿タイプ rcac_inquiry に保存し、メール通知する）。
 */
final class Inquiries {

	public const POST_TYPE = 'rcac_inquiry';

	/** @var array<string,string> */
	public const STATUSES = array(
		'new'         => '未対応',
		'in_progress' => '対応中',
		'done'        => '完了',
	);

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => '問い合わせ',
					'singular_name' => '問い合わせ',
					'all_items'     => '問い合わせ一覧',
					'edit_item'     => '問い合わせ詳細',
					'search_items'  => '問い合わせを検索',
					'not_found'     => '問い合わせはまだありません。',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'rcac',
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
			)
		);
	}

	public static function init_admin(): void {
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( self::class, 'meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'save_status' ) );
	}

	/**
	 * フォームの入力値を検証・保存し、通知メールを送る。
	 *
	 * @param array<string,mixed> $data
	 * @return int|\WP_Error 作成した投稿 ID
	 */
	public static function submit( array $data ) {
		$f = array(
			'name'         => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'email'        => sanitize_email( (string) ( $data['email'] ?? '' ) ),
			'phone'        => preg_replace( '/[^0-9+\-() ]/', '', mb_convert_kana( (string) ( $data['phone'] ?? '' ), 'a', 'UTF-8' ) ),
			'inquiry_type' => sanitize_text_field( (string) ( $data['inquiry_type'] ?? '' ) ),
			'start'        => sanitize_text_field( (string) ( $data['start'] ?? '' ) ),
			'end'          => sanitize_text_field( (string) ( $data['end'] ?? '' ) ),
			'vehicle'      => sanitize_text_field( (string) ( $data['vehicle'] ?? '' ) ),
			'message'      => sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ),
		);

		$errors = array();
		if ( '' === $f['name'] ) {
			$errors['name'] = 'お名前を入力してください。';
		}
		if ( ! is_email( $f['email'] ) ) {
			$errors['email'] = 'メールアドレスを正しく入力してください。';
		}
		if ( '' === trim( $f['message'] ) ) {
			$errors['message'] = 'お問い合わせ内容を入力してください。';
		}
		if ( mb_strlen( $f['message'] ) > 5000 || mb_strlen( $f['name'] ) > 100 || mb_strlen( $f['vehicle'] ) > 200 ) {
			$errors['message'] = '入力が長すぎます。';
		}
		if ( '' !== (string) Settings::get( 'privacy_url' ) && empty( $data['consent'] ) ) {
			$errors['consent'] = 'プライバシーポリシーへの同意が必要です。';
		}
		$types = Settings::lines( 'inquiry_types' );
		if ( '' !== $f['inquiry_type'] && ! in_array( $f['inquiry_type'], $types, true ) ) {
			$f['inquiry_type'] = '';
		}
		foreach ( array( 'start', 'end' ) as $k ) {
			if ( '' !== $f[ $k ] && ! preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $f[ $k ] ) ) {
				$f[ $k ] = '';
			}
		}
		if ( $errors ) {
			return new \WP_Error( 'rcac_invalid', '入力内容を確認してください。', array( 'fields' => $errors ) );
		}

		$conversation_id = Guard::verify( (string) ( $data['token'] ?? '' ) );

		$title   = sprintf( '【%s】%s 様', '' !== $f['inquiry_type'] ? $f['inquiry_type'] : 'お問い合わせ', $f['name'] );
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $f['message'],
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		foreach ( $f as $key => $value ) {
			update_post_meta( $post_id, '_rcac_' . $key, $value );
		}
		update_post_meta( $post_id, '_rcac_status', 'new' );
		if ( $conversation_id ) {
			update_post_meta( $post_id, '_rcac_conversation_id', $conversation_id );
			ConversationStore::link_inquiry( $conversation_id, (int) $post_id );
		}

		self::send_mails( (int) $post_id, $f, $conversation_id );

		/**
		 * 問い合わせ受付後（外部システム連携などに）。
		 *
		 * @param int                  $post_id
		 * @param array<string,string> $fields
		 */
		do_action( 'rcac_inquiry_submitted', (int) $post_id, $f );

		return (int) $post_id;
	}

	/**
	 * @param array<string,string> $f
	 */
	private static function summary_text( array $f ): string {
		$period = trim( self::format_dt( $f['start'] ) . ' 〜 ' . self::format_dt( $f['end'] ), ' 〜' );
		$lines  = array(
			'お名前: ' . $f['name'],
			'メール: ' . $f['email'],
			'電話番号: ' . ( '' !== $f['phone'] ? $f['phone'] : '-' ),
			'種別: ' . ( '' !== $f['inquiry_type'] ? $f['inquiry_type'] : '-' ),
			'ご利用期間: ' . ( '' !== $period ? $period : '-' ),
			'希望車種: ' . ( '' !== $f['vehicle'] ? $f['vehicle'] : '-' ),
			'',
			'【お問い合わせ内容】',
			$f['message'],
		);
		return implode( "\n", $lines );
	}

	private static function format_dt( string $v ): string {
		return str_replace( 'T', ' ', $v );
	}

	/**
	 * @param array<string,string> $f
	 */
	private static function send_mails( int $post_id, array $f, ?string $conversation_id ): void {
		$company = (string) Settings::get( 'company_name' );
		$summary = self::summary_text( $f );
		$name    = str_replace( array( "\r", "\n", '<', '>', ',', '"' ), '', $f['name'] );

		$to = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'notify_email' ) ) ), 'is_email' );
		if ( $to ) {
			$body  = "サイトからお問い合わせがありました。\n\n" . $summary . "\n\n";
			$body .= '管理画面: ' . admin_url( 'post.php?post=' . $post_id . '&action=edit' ) . "\n";
			if ( $conversation_id ) {
				$body .= 'チャットの会話ログ: ' . admin_url( 'admin.php?page=rcac-logs&conversation=' . $conversation_id ) . "\n";
			}
			wp_mail(
				$to,
				sprintf( '[%s] お問い合わせ: %s 様', $company, $name ),
				$body,
				array( 'Reply-To: ' . $name . ' <' . $f['email'] . '>' )
			);
		}

		if ( Settings::get( 'autoreply_enabled' ) ) {
			$replace = array(
				'{name}'    => $name,
				'{company}' => $company,
				'{inquiry}' => $summary,
			);
			wp_mail(
				$f['email'],
				strtr( (string) Settings::get( 'autoreply_subject' ), $replace ),
				strtr( (string) Settings::get( 'autoreply_body' ), $replace )
			);
		}
	}

	// ---------------- 管理画面 ----------------

	/**
	 * @param array<string,string> $cols
	 * @return array<string,string>
	 */
	public static function columns( array $cols ): array {
		return array(
			'cb'           => $cols['cb'] ?? '',
			'title'        => '件名',
			'rcac_status'  => 'ステータス',
			'rcac_contact' => '連絡先',
			'rcac_period'  => 'ご利用期間',
			'date'         => '受付日時',
		);
	}

	public static function column( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'rcac_status':
				$s = (string) get_post_meta( $post_id, '_rcac_status', true );
				$c = array(
					'new'         => '#d63638',
					'in_progress' => '#dba617',
					'done'        => '#00a32a',
				);
				printf( '<span style="color:%s;font-weight:600;">%s</span>', esc_attr( $c[ $s ] ?? '#50575e' ), esc_html( self::STATUSES[ $s ] ?? '-' ) );
				break;
			case 'rcac_contact':
				echo esc_html( (string) get_post_meta( $post_id, '_rcac_email', true ) );
				$phone = (string) get_post_meta( $post_id, '_rcac_phone', true );
				if ( '' !== $phone ) {
					echo '<br>' . esc_html( $phone );
				}
				break;
			case 'rcac_period':
				$s = self::format_dt( (string) get_post_meta( $post_id, '_rcac_start', true ) );
				$e = self::format_dt( (string) get_post_meta( $post_id, '_rcac_end', true ) );
				echo esc_html( trim( $s . ' 〜 ' . $e, ' 〜' ) );
				break;
		}
	}

	public static function meta_boxes(): void {
		add_meta_box( 'rcac_inquiry_detail', 'お問い合わせ内容', array( self::class, 'render_detail' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'rcac_inquiry_status', '対応ステータス', array( self::class, 'render_status' ), self::POST_TYPE, 'side', 'high' );
	}

	public static function render_detail( \WP_Post $post ): void {
		$rows = array(
			'name'         => 'お名前',
			'email'        => 'メール',
			'phone'        => '電話番号',
			'inquiry_type' => '種別',
			'start'        => '貸出希望',
			'end'          => '返却希望',
			'vehicle'      => '希望車種',
		);
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $rows as $key => $label ) {
			$v = (string) get_post_meta( $post->ID, '_rcac_' . $key, true );
			if ( 'email' === $key && '' !== $v ) {
				$v = sprintf( '<a href="mailto:%1$s">%2$s</a>', esc_attr( $v ), esc_html( $v ) );
			} else {
				$v = esc_html( '' !== $v ? self::format_dt( $v ) : '-' );
			}
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), $v ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		printf( '<tr><th scope="row">内容</th><td><div style="white-space:pre-wrap;">%s</div></td></tr>', esc_html( $post->post_content ) );
		$conv = (string) get_post_meta( $post->ID, '_rcac_conversation_id', true );
		if ( '' !== $conv ) {
			printf(
				'<tr><th scope="row">チャット</th><td><a href="%s">この問い合わせの前のチャット会話を見る</a></td></tr>',
				esc_url( admin_url( 'admin.php?page=rcac-logs&conversation=' . $conv ) )
			);
		}
		echo '</tbody></table>';
	}

	public static function render_status( \WP_Post $post ): void {
		wp_nonce_field( 'rcac_inquiry_status', 'rcac_inquiry_status_nonce' );
		$current = (string) get_post_meta( $post->ID, '_rcac_status', true );
		echo '<select name="rcac_status" style="width:100%">';
		foreach ( self::STATUSES as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">変更後「更新」を押してください。</p>';
	}

	public static function save_status( int $post_id ): void {
		if ( ! isset( $_POST['rcac_inquiry_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rcac_inquiry_status_nonce'] ) ), 'rcac_inquiry_status' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = isset( $_POST['rcac_status'] ) ? sanitize_key( wp_unslash( $_POST['rcac_status'] ) ) : '';
		if ( isset( self::STATUSES[ $status ] ) ) {
			update_post_meta( $post_id, '_rcac_status', $status );
		}
	}

	public static function count_new(): int {
		$q = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => '_rcac_status',
				'meta_value'     => 'new',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) $q->found_posts;
	}
}
