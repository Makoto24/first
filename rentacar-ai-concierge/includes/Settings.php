<?php
namespace RCAC;

/**
 * プラグイン設定（wp_options の rcac_settings に1つの配列として保存）。
 */
final class Settings {

	public const OPTION = 'rcac_settings';

	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	/**
	 * Claude のモデル選択肢。値は API のモデル ID。
	 *
	 * @return array<string,string>
	 */
	public static function models(): array {
		return array(
			'claude-opus-5-5'   => 'Claude Opus 5.5（推奨・最も高精度）',
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5（バランス型・低コスト）',
			'claude-haiku-4-5'  => 'Claude Haiku 4.5（最速・最も低コスト）',
		);
	}

	/**
	 * 全設定項目の定義。tab ごとに管理画面のフォームが分かれる。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		$admin_email = get_option( 'admin_email' );

		return array(
			// ---------------- 基本設定 ----------------
			'api_key'                  => array(
				'tab'         => 'general',
				'type'        => 'secret',
				'label'       => 'Claude API キー',
				'default'     => '',
				'description' => 'Claude Console で発行した API キー（sk-ant-…）。wp-config.php に define( \'RCAC_ANTHROPIC_API_KEY\', \'…\' ); と書いた場合はそちらが優先されます（推奨）。',
			),
			'model'                    => array(
				'tab'     => 'general',
				'type'    => 'select',
				'label'   => 'モデル',
				'default' => 'claude-opus-5-5',
				'options' => self::models(),
				'description' => '料金は利用量（トークン数）に応じて Anthropic から請求されます。会話ログ画面で推定コストを確認できます。',
			),
			'effort'                   => array(
				'tab'         => 'general',
				'type'        => 'select',
				'label'       => '思考の深さ（effort）',
				'default'     => 'low',
				'options'     => array(
					'low'    => 'low（速い・チャット向け）',
					'medium' => 'medium',
					'high'   => 'high（遅いが慎重）',
				),
				'description' => 'Haiku 4.5 ではこの設定は使われません。',
			),
			'bot_name'                 => array(
				'tab'     => 'general',
				'type'    => 'text',
				'label'   => 'ボットの名前',
				'default' => 'AIコンシェルジュ',
			),
			'company_name'             => array(
				'tab'     => 'general',
				'type'    => 'text',
				'label'   => '会社名・店舗名',
				'default' => get_bloginfo( 'name' ),
			),
			'greeting'                 => array(
				'tab'     => 'general',
				'type'    => 'textarea',
				'label'   => '最初のあいさつ',
				'default' => "こんにちは！レンタカーのご予約・空車状況・料金などについて、お気軽にご質問ください。\n※AIによる自動応答です。正確な内容は予約画面またはスタッフにご確認ください。",
			),
			'quick_replies'            => array(
				'tab'         => 'general',
				'type'        => 'textarea',
				'label'       => 'クイック返信ボタン',
				'default'     => "空車を確認したい\n料金について知りたい\n営業時間・アクセス\nスタッフに問い合わせる",
				'description' => '1行に1つ。チャット開始時にボタンとして表示されます。',
			),
			'widget_enabled'           => array(
				'tab'     => 'general',
				'type'    => 'checkbox',
				'label'   => '全ページに右下のチャットボタンを表示',
				'default' => 1,
			),
			'widget_position'          => array(
				'tab'     => 'general',
				'type'    => 'select',
				'label'   => 'チャットボタンの位置',
				'default' => 'right',
				'options' => array(
					'right' => '右下',
					'left'  => '左下',
				),
			),
			'exclude_pages'            => array(
				'tab'         => 'general',
				'type'        => 'text',
				'label'       => 'チャットボタンを出さないページID',
				'default'     => '',
				'description' => 'カンマ区切り（例: 12,34）。予約フォームのページなどで非表示にしたい場合に。',
			),
			'primary_color'            => array(
				'tab'     => 'general',
				'type'    => 'color',
				'label'   => 'テーマカラー',
				'default' => '#0b6bcb',
			),
			'reservation_url'          => array(
				'tab'         => 'general',
				'type'        => 'url',
				'label'       => '予約ページの URL',
				'default'     => '',
				'description' => '空車があった場合にボットが案内する予約ページ。',
			),
			'extra_instructions'       => array(
				'tab'         => 'general',
				'type'        => 'textarea',
				'label'       => 'ボットへの追加指示',
				'default'     => '',
				'description' => '口調や、必ず案内してほしいこと・案内してほしくないことなど（例:「電話番号は 0120-xxx-xxx と案内する」）。',
			),

			// ---------------- 知識・Q&A ----------------
			'business_info'            => array(
				'tab'         => 'knowledge',
				'type'        => 'textarea_large',
				'label'       => '店舗情報・基本情報',
				'default'     => "【店舗名】\n【住所・アクセス】\n【営業時間】\n【定休日】\n【電話番号】\n【料金の概要】\n【保険・補償】\n【キャンセル規定】\n【その他】",
				'description' => 'ボットが回答の根拠にする基本情報です。料金表や規約の要点もここに書けます。Q&A は「Q&A集」メニューで個別に登録・CSV取り込みできます。',
			),

			// ---------------- 空車連携 ----------------
			'availability_provider'    => array(
				'tab'     => 'availability',
				'type'    => 'select',
				'label'   => '予約データの取得方法',
				'default' => 'demo',
				'options' => array(
					'demo'     => 'デモデータ（動作確認用）',
					'table'    => 'データベースのテーブルから取得（独自テーブル型の予約プラグイン）',
					'postmeta' => '投稿タイプ＋カスタムフィールドから取得（投稿型の予約プラグイン）',
					'none'     => '空車案内を使わない',
					'custom'   => 'カスタム（rcac_availability_provider フィルターで実装）',
				),
			),
			'buffer_minutes'           => array(
				'tab'         => 'availability',
				'type'        => 'number',
				'label'       => '貸出間の準備時間（分）',
				'default'     => 60,
				'description' => '返却から次の貸出までに必要な清掃・点検の時間。予約の前後にこの時間を加えて判定します。',
			),
			'booking_timezone'         => array(
				'tab'     => 'availability',
				'type'    => 'select',
				'label'   => '予約日時のタイムゾーン',
				'default' => 'site',
				'options' => array(
					'site' => 'サイトのタイムゾーン（日本時間で保存している場合）',
					'utc'  => 'UTC で保存されている',
				),
				'description' => '数値（UNIX タイムスタンプ）で保存されている場合は自動判定するため、この設定は影響しません。',
			),
			'excluded_statuses'        => array(
				'tab'         => 'availability',
				'type'        => 'text',
				'label'       => '空車扱いにする予約ステータス',
				'default'     => 'cancel,cancelled,canceled,キャンセル,trash',
				'description' => 'カンマ区切り。予約ステータス列／フィールドの値がこれらの場合、その予約は無視します（大文字小文字を区別しません）。',
			),
			// テーブル型
			'tbl_vehicle_table'        => array(
				'tab'   => 'availability',
				'type'  => 'text',
				'label' => '車両テーブル名',
				'group' => 'table',
				'default' => '',
			),
			'tbl_vehicle_id_col'       => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '車両ID の列',
				'group'   => 'table',
				'default' => 'id',
			),
			'tbl_vehicle_name_col'     => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '車両名（車種名）の列',
				'group'   => 'table',
				'default' => 'name',
			),
			'tbl_vehicle_class_col'    => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => 'クラスの列（任意）',
				'group'   => 'table',
				'default' => '',
			),
			'tbl_vehicle_capacity_col' => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '定員の列（任意）',
				'group'   => 'table',
				'default' => '',
			),
			'tbl_vehicle_store_col'    => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '店舗の列（任意）',
				'group'   => 'table',
				'default' => '',
			),
			'tbl_vehicle_where'        => array(
				'tab'         => 'availability',
				'type'        => 'text',
				'label'       => '車両の絞り込み（任意）',
				'group'       => 'table',
				'default'     => '',
				'description' => '「列名=値」の形式。例: status=active',
			),
			'tbl_booking_table'        => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '予約テーブル名',
				'group'   => 'table',
				'default' => '',
			),
			'tbl_booking_vehicle_col'  => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '予約の車両ID の列',
				'group'   => 'table',
				'default' => 'vehicle_id',
			),
			'tbl_booking_start_col'    => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '貸出日時の列',
				'group'   => 'table',
				'default' => 'start_at',
			),
			'tbl_booking_end_col'      => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '返却日時の列',
				'group'   => 'table',
				'default' => 'end_at',
			),
			'tbl_booking_status_col'   => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '予約ステータスの列（任意）',
				'group'   => 'table',
				'default' => '',
			),
			// 投稿型
			'pm_vehicle_post_type'     => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '車両の投稿タイプ',
				'group'   => 'postmeta',
				'default' => '',
			),
			'pm_vehicle_class_meta'    => array(
				'tab'         => 'availability',
				'type'        => 'text',
				'label'       => 'クラス（任意）',
				'group'       => 'postmeta',
				'default'     => '',
				'description' => 'カスタムフィールドのキー、またはタクソノミーなら「tax:タクソノミー名」。',
			),
			'pm_vehicle_capacity_meta' => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '定員のカスタムフィールド（任意）',
				'group'   => 'postmeta',
				'default' => '',
			),
			'pm_vehicle_store_meta'    => array(
				'tab'         => 'availability',
				'type'        => 'text',
				'label'       => '店舗（任意）',
				'group'       => 'postmeta',
				'default'     => '',
				'description' => 'カスタムフィールドのキー、または「tax:タクソノミー名」。',
			),
			'pm_booking_post_type'     => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '予約の投稿タイプ',
				'group'   => 'postmeta',
				'default' => '',
			),
			'pm_booking_vehicle_meta'  => array(
				'tab'         => 'availability',
				'type'        => 'text',
				'label'       => '予約の車両 のカスタムフィールド',
				'group'       => 'postmeta',
				'default'     => '',
				'description' => '車両の投稿ID、または車両名が入っているフィールドのキー。',
			),
			'pm_booking_start_meta'    => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '貸出日時のカスタムフィールド',
				'group'   => 'postmeta',
				'default' => '',
			),
			'pm_booking_end_meta'      => array(
				'tab'     => 'availability',
				'type'    => 'text',
				'label'   => '返却日時のカスタムフィールド',
				'group'   => 'postmeta',
				'default' => '',
			),
			'pm_booking_status_meta'   => array(
				'tab'         => 'availability',
				'type'        => 'text',
				'label'       => '予約ステータスのカスタムフィールド（任意）',
				'group'       => 'postmeta',
				'default'     => '',
				'description' => '空欄の場合は投稿ステータス（ゴミ箱・下書きは除外）で判定します。',
			),

			// ---------------- 問い合わせフォーム ----------------
			'notify_email'             => array(
				'tab'         => 'form',
				'type'        => 'text',
				'label'       => '通知先メールアドレス',
				'default'     => $admin_email,
				'description' => 'カンマ区切りで複数指定できます。',
			),
			'inquiry_types'            => array(
				'tab'         => 'form',
				'type'        => 'textarea',
				'label'       => 'お問い合わせ種別',
				'default'     => "ご予約について\n料金について\n車両について\n予約の変更・キャンセル\nその他",
				'description' => '1行に1つ。',
			),
			'privacy_url'              => array(
				'tab'         => 'form',
				'type'        => 'url',
				'label'       => 'プライバシーポリシーの URL',
				'default'     => '',
				'description' => '指定するとフォームに同意チェックが表示されます。',
			),
			'autoreply_enabled'        => array(
				'tab'     => 'form',
				'type'    => 'checkbox',
				'label'   => 'お客様に自動返信メールを送る',
				'default' => 1,
			),
			'autoreply_subject'        => array(
				'tab'     => 'form',
				'type'    => 'text',
				'label'   => '自動返信の件名',
				'default' => '【{company}】お問い合わせを受け付けました',
			),
			'autoreply_body'           => array(
				'tab'         => 'form',
				'type'        => 'textarea_large',
				'label'       => '自動返信の本文',
				'default'     => "{name} 様\n\nこの度は {company} にお問い合わせいただき、誠にありがとうございます。\n以下の内容でお問い合わせを受け付けました。担当者より順次ご連絡いたしますので、今しばらくお待ちください。\n\n――――――――――\n{inquiry}\n――――――――――\n\n※本メールは送信専用アドレスから自動送信しています。\n\n{company}",
				'description' => '使える差し込み: {name} {company} {inquiry}',
			),
			'form_thanks'              => array(
				'tab'     => 'form',
				'type'    => 'textarea',
				'label'   => '送信完了メッセージ',
				'default' => "お問い合わせありがとうございました。\n担当者より順次ご連絡いたします。",
			),

			// ---------------- 利用制限・ログ ----------------
			'rate_per_10min'           => array(
				'tab'         => 'limits',
				'type'        => 'number',
				'label'       => '1人あたりの送信上限（10分間）',
				'default'     => 20,
				'description' => '同じ IP アドレスからのチャット送信回数の上限。',
			),
			'rate_per_day'             => array(
				'tab'     => 'limits',
				'type'    => 'number',
				'label'   => '1人あたりの送信上限（1日）',
				'default' => 100,
			),
			'global_daily_limit'       => array(
				'tab'         => 'limits',
				'type'        => 'number',
				'label'       => 'サイト全体の1日の上限',
				'default'     => 1000,
				'description' => 'API 料金の上限対策。超えると当日はチャットが停止し、フォームへ案内します。',
			),
			'max_input_chars'          => array(
				'tab'     => 'limits',
				'type'    => 'number',
				'label'   => '1回の送信の最大文字数',
				'default' => 800,
			),
			'max_turns'                => array(
				'tab'         => 'limits',
				'type'        => 'number',
				'label'       => '1つの会話の最大往復数',
				'default'     => 30,
				'description' => '超えると新しい会話を始めるよう案内します。',
			),
			'log_retention_days'       => array(
				'tab'         => 'limits',
				'type'        => 'number',
				'label'       => '会話ログの保存日数',
				'default'     => 90,
				'description' => '期間を過ぎた会話は毎日自動で削除されます（最短1日）。',
			),
			'delete_on_uninstall'      => array(
				'tab'     => 'limits',
				'type'    => 'checkbox',
				'label'   => 'プラグイン削除時に設定・ログ・Q&A・問い合わせデータをすべて削除する',
				'default' => 0,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::fields() as $key => $field ) {
			$out[ $key ] = $field['default'];
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( string $key ): mixed {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	public static function api_key(): string {
		if ( defined( 'RCAC_ANTHROPIC_API_KEY' ) && is_string( RCAC_ANTHROPIC_API_KEY ) && '' !== RCAC_ANTHROPIC_API_KEY ) {
			return RCAC_ANTHROPIC_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	public static function api_key_from_constant(): bool {
		return defined( 'RCAC_ANTHROPIC_API_KEY' ) && '' !== RCAC_ANTHROPIC_API_KEY;
	}

	/**
	 * 改行区切りの設定値を配列にする。
	 *
	 * @return list<string>
	 */
	public static function lines( string $key ): array {
		$raw = (string) self::get( $key );
		$out = array();
		foreach ( preg_split( '/\R/u', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * register_setting の sanitize_callback。送信されたタブの項目だけを更新し、他のタブの値は保持する。
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$tab = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		if ( '' === $tab ) {
			// 初回保存時、WordPress は add_option 経由でサニタイズ済みの値をもう一度渡してくる。
			return array_merge( $current, array_intersect_key( $input, self::fields() ) );
		}
		foreach ( self::fields() as $key => $field ) {
			if ( $field['tab'] !== $tab ) {
				continue;
			}
			$value = $input[ $key ] ?? null;
			switch ( $field['type'] ) {
				case 'checkbox':
					$current[ $key ] = empty( $value ) ? 0 : 1;
					break;
				case 'number':
					$current[ $key ] = max( 0, (int) $value );
					break;
				case 'url':
					$current[ $key ] = esc_url_raw( trim( (string) $value ) );
					break;
				case 'color':
					$color           = sanitize_hex_color( (string) $value );
					$current[ $key ] = $color ? $color : $field['default'];
					break;
				case 'select':
					$value           = (string) $value;
					$current[ $key ] = array_key_exists( $value, $field['options'] ) ? $value : $field['default'];
					break;
				case 'secret':
					if ( ! empty( $input[ $key . '_clear' ] ) ) {
						$current[ $key ] = '';
					} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
						$current[ $key ] = trim( $value );
					}
					break;
				case 'textarea':
				case 'textarea_large':
					$current[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$current[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		self::flush();
		return $current;
	}
}
