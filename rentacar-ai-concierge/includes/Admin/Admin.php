<?php
namespace RCAC\Admin;

use RCAC\Availability\AvailabilityService;
use RCAC\ChatEngine;
use RCAC\ConversationStore;
use RCAC\Inquiries;
use RCAC\KnowledgeBase;
use RCAC\Plugin;
use RCAC\Settings;

/**
 * 管理画面のメニュー・設定ページ・通知。
 */
final class Admin {

	private const TABS = array(
		'general'      => '基本設定',
		'knowledge'    => '店舗情報・Q&A',
		'availability' => '空車連携',
		'form'         => '問い合わせフォーム',
		'limits'       => '利用制限・ログ',
		'test'         => '動作テスト',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 9 );
		add_action( 'admin_menu', array( self::class, 'submenus' ), 11 );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RCAC_FILE ), array( self::class, 'action_links' ) );
		ImportExport::init();
		Logs::init();
	}

	public static function menu(): void {
		$new   = Inquiries::count_new();
		$badge = $new ? ' <span class="awaiting-mod">' . (int) $new . '</span>' : '';
		add_menu_page( 'AIコンシェルジュ', 'AIコンシェルジュ' . $badge, 'manage_options', 'rcac', array( self::class, 'render_settings' ), 'dashicons-format-chat', 58 );
		add_submenu_page( 'rcac', '設定', '設定', 'manage_options', 'rcac', array( self::class, 'render_settings' ) );
	}

	public static function submenus(): void {
		add_submenu_page( 'rcac', 'Q&A CSV取り込み', 'Q&A CSV取り込み', 'manage_options', 'rcac-import', array( ImportExport::class, 'render' ) );
		add_submenu_page( 'rcac', '会話ログ', '会話ログ', 'manage_options', 'rcac-logs', array( Logs::class, 'render' ) );
	}

	/**
	 * @param array<string,string> $links
	 * @return array<string,string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=rcac' ) ) . '">設定</a>' );
		return $links;
	}

	public static function register_settings(): void {
		register_setting(
			'rcac_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => array(),
			)
		);
		foreach ( array_keys( self::TABS ) as $tab ) {
			add_settings_section( 'rcac_' . $tab, '', '__return_false', 'rcac_' . $tab );
		}
		foreach ( Settings::fields() as $key => $field ) {
			$class = isset( $field['group'] ) ? 'rcac-group rcac-group-' . $field['group'] : '';
			add_settings_field(
				$key,
				esc_html( $field['label'] ),
				array( self::class, 'render_field' ),
				'rcac_' . $field['tab'],
				'rcac_' . $field['tab'],
				array(
					'key'       => $key,
					'field'     => $field,
					'class'     => $class,
					'label_for' => 'checkbox' === $field['type'] ? null : 'rcac_' . $key,
				)
			);
		}
	}

	/**
	 * @param array{key:string,field:array<string,mixed>} $args
	 */
	public static function render_field( array $args ): void {
		$key   = $args['key'];
		$field = $args['field'];
		$name  = Settings::OPTION . '[' . $key . ']';
		$id    = 'rcac_' . $key;
		$value = Settings::get( $key );

		switch ( $field['type'] ) {
			case 'secret':
				if ( 'api_key' === $key && Settings::api_key_from_constant() ) {
					echo '<p><strong>wp-config.php の RCAC_ANTHROPIC_API_KEY を使用中です。</strong></p>';
					break;
				}
				$has = '' !== (string) $value;
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $has ? '設定済み（' . substr( (string) $value, 0, 10 ) . '…）変更する場合のみ入力' : 'sk-ant-...' )
				);
				if ( $has ) {
					printf( '<br><label><input type="checkbox" name="%s" value="1"> 保存済みのキーを削除する</label>', esc_attr( Settings::OPTION . '[' . $key . '_clear]' ) );
				}
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $opt => $label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $opt ), selected( (string) $value, (string) $opt, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> 有効にする</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (int) $value, 1, false )
				);
				break;
			case 'textarea':
			case 'textarea_large':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text">%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					'textarea_large' === $field['type'] ? 16 : 4,
					esc_textarea( (string) $value )
				);
				break;
			case 'number':
				printf( '<input type="number" min="0" id="%1$s" name="%2$s" value="%3$d" class="small-text">', esc_attr( $id ), esc_attr( $name ), (int) $value );
				break;
			case 'color':
				printf( '<input type="color" id="%1$s" name="%2$s" value="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			case 'url':
				printf( '<input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="https://">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			default:
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
		}
		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
		}
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'rcac' ) ) {
			return;
		}
		wp_enqueue_script( 'rcac-admin', RCAC_URL . 'assets/js/admin.js', array(), RCAC_VERSION, true );
		wp_add_inline_script(
			'rcac-admin',
			'window.RCAC_ADMIN = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'rcac/v1/admin/test-chat' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
		wp_add_inline_style( 'wp-admin', self::admin_css() );
	}

	private static function admin_css(): string {
		return '.rcac-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 8px;max-width:1000px}'
			. '.rcac-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px}.rcac-card b{display:block;font-size:22px;margin-top:4px}'
			. '.rcac-gantt{border-collapse:collapse;margin-top:8px;background:#fff}.rcac-gantt th,.rcac-gantt td{border:1px solid #dcdcde;padding:4px 6px;font-size:12px;text-align:center;white-space:nowrap}'
			. '.rcac-gantt td.busy{background:#f0b849}.rcac-gantt td.free{background:#e7f6ec}.rcac-gantt th.name{text-align:left}'
			. '.rcac-chatlog{max-width:760px}.rcac-chatlog .m{margin:8px 0;padding:10px 12px;border-radius:8px;white-space:pre-wrap;background:#fff;border:1px solid #dcdcde}'
			. '.rcac-chatlog .m.user{background:#eaf2fb;border-color:#c5d9ed;margin-left:60px}.rcac-chatlog .m.error{background:#fcf0f1;border-color:#f0b8bd}.rcac-chatlog .meta{color:#646970;font-size:12px;margin-bottom:4px}'
			. '.rcac-test-out{white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px;max-width:760px;min-height:40px}'
			. '.rcac-mono{font-family:Menlo,Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;max-height:420px;overflow:auto;max-width:1000px}';
	}

	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ours = str_contains( (string) $screen->id, 'rcac' ) || in_array( $screen->post_type, array( Inquiries::POST_TYPE, KnowledgeBase::POST_TYPE ), true ) || 'plugins' === $screen->id;
		if ( ! $ours ) {
			return;
		}
		if ( ! Plugin::vendor_installed() ) {
			echo '<div class="notice notice-error"><p><strong>AIコンシェルジュ:</strong> 必要なライブラリ（vendor フォルダ）がありません。GitHub の Actions で作成した配布用 zip からインストールするか、プラグインのフォルダで <code>composer install --no-dev</code> を実行してください。</p></div>';
		}
		if ( '' === Settings::api_key() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>AIコンシェルジュ:</strong> Claude API キーが未設定のため、チャットは停止中です（問い合わせフォームは使えます）。<a href="%s">設定する</a></p></div>',
				esc_url( admin_url( 'admin.php?page=rcac' ) )
			);
		}
		$err = get_option( 'rcac_last_error' );
		if ( is_array( $err ) && ( $err['time'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
			printf(
				'<div class="notice notice-error"><p><strong>AIコンシェルジュ: 直近のエラー（%1$s）</strong><br>%2$s</p></div>',
				esc_html( wp_date( 'Y-m-d H:i', (int) $err['time'] ) ),
				esc_html( (string) $err['message'] )
			);
		}
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'general';
		}

		echo '<div class="wrap"><h1>レンタカー AIコンシェルジュ</h1>';
		self::render_summary();
		settings_errors();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( admin_url( 'admin.php?page=rcac&tab=' . $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'test' === $tab ) {
			self::render_test_tab();
			echo '</div>';
			return;
		}

		if ( 'knowledge' === $tab ) {
			self::render_knowledge_intro();
		}
		if ( 'availability' === $tab ) {
			echo '<p>既存の予約プラグインが保存している予約データ（ガントチャートの元データ）を読み取り、空車を判定します。お客様の氏名などの個人情報は読み取りません。設定後、下の「接続テスト」で既存のガントチャートと同じ予約状況になっているか確認してください。</p>';
		}
		if ( 'general' === $tab ) {
			echo '<p>サイトへの表示: 全ページ右下のチャットボタンのほか、固定ページなどに <code>[rcac_chatbot]</code>（チャット埋め込み）、<code>[rcac_contact_form]</code>（問い合わせフォーム）を書くと表示されます。</p>';
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'rcac_settings_group' );
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( Settings::OPTION . '[_tab]' ), esc_attr( $tab ) );
		do_settings_sections( 'rcac_' . $tab );
		submit_button( '保存' );
		echo '</form>';

		if ( 'availability' === $tab ) {
			AvailabilityTools::render();
		}
		echo '</div>';
	}

	private static function render_summary(): void {
		$month = ConversationStore::stats_since( gmdate( 'Y-m-01 00:00:00' ) );
		$today = ConversationStore::stats_since( get_gmt_from_date( wp_date( 'Y-m-d 00:00:00' ) ) );
		$cards = array(
			'今日の会話'                 => number_format( $today['conversations'] ),
			'今月の会話'                 => number_format( $month['conversations'] ),
			'今月の API 推定コスト'       => '$' . number_format( $month['cost'], 2 ),
			'未対応の問い合わせ'         => number_format( Inquiries::count_new() ),
			'登録済み Q&A'              => number_format( (int) wp_count_posts( KnowledgeBase::POST_TYPE )->publish ),
		);
		echo '<div class="rcac-cards">';
		foreach ( $cards as $label => $value ) {
			printf( '<div class="rcac-card">%s<b>%s</b></div>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</div>';
	}

	private static function render_knowledge_intro(): void {
		printf(
			'<p>ボットは、ここに書いた「店舗情報」と「Q&A集」の内容をもとに回答します。<a href="%1$s">Q&A集を編集</a> ／ <a href="%2$s">CSVで一括取り込み</a></p>',
			esc_url( admin_url( 'edit.php?post_type=' . KnowledgeBase::POST_TYPE ) ),
			esc_url( admin_url( 'admin.php?page=rcac-import' ) )
		);
	}

	private static function render_test_tab(): void {
		$engine = new ChatEngine();
		$prompt = $engine->static_prompt();
		?>
		<h2>動作テスト</h2>
		<p>保存済みの設定で実際に Claude API を呼び出し、回答を確認します（このテストの会話はログに残りません）。</p>
		<p><textarea id="rcac-test-message" rows="3" class="large-text" style="max-width:760px">来週の土曜日の10時から日曜日の18時まで、7人乗れる車は空いていますか？</textarea></p>
		<p><button type="button" class="button button-primary" id="rcac-test-run">送信してテスト</button> <span id="rcac-test-status"></span></p>
		<div id="rcac-test-out" class="rcac-test-out" aria-live="polite"></div>
		<h2 style="margin-top:32px">ボットに渡している指示（システムプロンプト）</h2>
		<p>文字数: <?php echo esc_html( number_format( mb_strlen( $prompt ) ) ); ?> 文字。この部分はキャッシュされるため、2回目以降の料金は大幅に安くなります。</p>
		<details><summary>内容を表示</summary><div class="rcac-mono"><?php echo esc_html( $prompt ); ?></div></details>
		<?php
		if ( AvailabilityService::enabled() ) {
			echo '<p class="description">空車確認は「空車連携」タブの設定で動作します（現在: ' . esc_html( (string) ( Settings::fields()['availability_provider']['options'][ Settings::get( 'availability_provider' ) ] ?? '' ) ) . '）。</p>';
		}
	}
}
