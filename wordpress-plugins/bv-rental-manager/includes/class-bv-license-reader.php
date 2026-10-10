<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 運転免許証の自動読み取り（貸渡簿の「運転免許の種類・免許証番号」用）
 *
 * お客様がアップロードした免許証の画像を Claude（Anthropic Messages API）で読み取り、
 * 種類・番号・有効期限を予約に記録する。読み取った文字情報は貸渡簿として保存し、
 * 画像そのものは従来どおり返却・キャンセル後に自動で消去する。
 *
 * WordPressのプラグインとして配布するため、Composer の公式SDKは使わず
 * WordPress標準のHTTP関数（wp_remote_post）で Messages API を直接呼ぶ。
 *
 * 読み取りのきっかけ
 *   - 予約の作成・書類の差し替え直後（数秒後にバックグラウンドで）
 *   - 15分ごとの定期処理（まだ読み取っていない画像を少しずつ。既存の画像もこれで読む）
 *   - 書類を手動で削除する直前
 *   - 管理画面・スタッフポータルの「読み取り直す」
 */
class BV_License_Reader {

	const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';
	const HOOK        = 'bvrm_read_license';
	/** 画像の長辺（これより大きい画像は縮小して送る。費用と送信量の節約） */
	const MAX_EDGE = 1600;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'read_reservation' ) );
	}

	public static function models() {
		return array(
			'claude-opus-5-5'   => 'Claude Opus 5.5（既定・最も正確）',
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
			'claude-haiku-5-5'  => 'Claude Haiku 5.5（安い）',
		);
	}

	/** 自動読み取りが使える状態か */
	public static function enabled() {
		$s = BV_Util::settings();
		return ! empty( $s['license_ocr'] ) && '' !== trim( (string) ( $s['claude_api_key'] ?? '' ) );
	}

	/** アップロード直後に、バックグラウンドで読み取る予約を入れる */
	public static function queue( $id ) {
		if ( ! self::enabled() || ! $id ) return;
		if ( ! wp_next_scheduled( self::HOOK, array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK, array( (int) $id ) );
		}
	}

	/** 書類が差し替わったときは、読み取り済みの印を外して読み直す */
	public static function reset_and_queue( $id ) {
		BV_DB::update_reservation( $id, array( 'license_read_at' => null, 'license_source' => '', 'license_read_note' => '' ) );
		self::queue( $id );
	}

	/**
	 * まだ読み取っていない予約を少しずつ読む（15分ごと）
	 * 返却・キャンセル済み（＝画像の消去が近いもの）を先に読む。
	 * @return int 処理した件数
	 */
	public static function batch( $limit = 3 ) {
		if ( ! self::enabled() ) return 0;
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$t}
			  WHERE license_files LIKE '%%bvpriv:%%' AND license_read_at IS NULL
			  ORDER BY ( status IN ('returned','cancelled') ) DESC, id DESC LIMIT %d",
			(int) $limit
		) );
		$n = 0;
		foreach ( (array) $ids as $id ) {
			self::read_reservation( (int) $id );
			$n++;
		}
		return $n;
	}

	/** まだ読み取っていない件数（設定画面の表示用） */
	public static function pending_count() {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE license_files LIKE '%bvpriv:%' AND license_read_at IS NULL" );
	}

	/**
	 * 1件の予約の書類を読み取り、結果を記録する
	 * 同じお客様（同じメールアドレス・同じ画像）のほかの予約にも同じ結果を記録する。
	 * @return array { ok, msg }
	 */
	public static function read_reservation( $id ) {
		$r = BV_DB::get_reservation( (int) $id );
		if ( ! $r ) return array( 'ok' => false, 'msg' => '予約が見つかりません。' );
		if ( ! self::enabled() ) return array( 'ok' => false, 'msg' => '免許証の自動読み取りが設定されていません（設定 → 本人確認書類）。' );

		/* 同じ画像を前の予約で読み取り済みなら、その結果を使う（同じ画像を二度読まない） */
		$copied = self::copy_from_sibling( $r );
		if ( $copied ) return $copied;

		$files = json_decode( (string) $r->license_files, true );
		$parts = is_array( $files ) ? self::content_blocks( $files ) : array();
		if ( ! $parts ) {
			/* 公開領域に残った古い形式の画像は、移行が済むまで読み取らない（失敗扱いにもしない） */
			if ( false === strpos( (string) $r->license_files, BV_Files::PREFIX ) ) {
				return array( 'ok' => false, 'msg' => '画像が古い保存形式のため読み取れません。設定画面で非公開領域へ移行してください。' );
			}
			return self::save( $r, array(), 'failed', '画像ファイルが見つからないため読み取れませんでした。来店時に原本で確認してください。' );
		}

		$res = self::call( $parts );
		if ( is_wp_error( $res ) ) {
			/* 通信エラー・混雑は次の回にやり直す。画像の不備など送り直しても同じものは「失敗」として止める */
			$code = (int) ( $res->get_error_data()['status'] ?? 0 );
			if ( in_array( $code, array( 400, 413, 422 ), true ) ) {
				return self::save( $r, array(), 'failed', '読み取りできませんでした：' . $res->get_error_message() );
			}
			return array( 'ok' => false, 'msg' => '読み取りに失敗しました（あとで自動でやり直します）：' . $res->get_error_message() );
		}
		return self::apply( $r, $res );
	}

	protected static function copy_from_sibling( $r ) {
		if ( ! is_email( (string) $r->email ) || '' === (string) $r->license_files || '[]' === (string) $r->license_files ) return null;
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$src = $wpdb->get_row( $wpdb->prepare(
			"SELECT license_type, license_number, license_expiry, license_source, license_read_at, license_read_note FROM {$t}
			  WHERE email = %s AND license_files = %s AND id != %d AND license_read_at IS NOT NULL ORDER BY license_read_at DESC LIMIT 1",
			$r->email, $r->license_files, (int) $r->id
		) );
		if ( ! $src ) return null;
		/* スタッフが手で入力した値はそのまま（読み取り済みの印だけ付ける） */
		if ( 'manual' === $r->license_source ) {
			BV_DB::update_reservation( $r->id, array( 'license_read_at' => current_time( 'mysql' ) ) );
			return array( 'ok' => true, 'msg' => 'スタッフが入力した免許情報をそのまま使います。' );
		}
		BV_DB::update_reservation( $r->id, array(
			'license_type' => $src->license_type, 'license_number' => $src->license_number, 'license_expiry' => $src->license_expiry,
			'license_source' => $src->license_source, 'license_read_at' => $src->license_read_at, 'license_read_note' => $src->license_read_note,
		) );
		return array( 'ok' => 'failed' !== $src->license_source, 'msg' => '以前の予約で読み取った結果を使いました。' );
	}

	/** 書類の画像を、APIに送る形（画像・PDF）にする */
	protected static function content_blocks( $files ) {
		$labels = array(
			'license_front' => '運転免許証（表面）またはマイナ免許証', 'license_back' => '運転免許証（裏面）',
			'passport' => 'パスポート', 'intl_license' => '国際運転免許証',
		);
		$out = array();
		foreach ( $files as $k => $key ) {
			if ( ! is_string( $key ) || ! BV_Files::is_key( $key ) ) continue;
			$path = BV_Files::path( $key );
			if ( ! $path || ! is_readable( $path ) ) continue;
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$out[] = array( 'type' => 'text', 'text' => '次の画像：' . ( $labels[ $k ] ?? $k ) );
			if ( 'pdf' === $ext ) {
				$out[] = array( 'type' => 'document', 'source' => array(
					'type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode( (string) file_get_contents( $path ) ),
				) );
				continue;
			}
			$img = self::image_data( $path, $ext );
			if ( $img ) $out[] = array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $img[0], 'data' => $img[1] ) );
		}
		/* 画像が1つもなければ空 */
		foreach ( $out as $b ) if ( 'text' !== $b['type'] ) return $out;
		return array();
	}

	/** 画像を読み込み、大きければ縮小する → array( media_type, base64 ) */
	protected static function image_data( $path, $ext ) {
		$types = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
		if ( ! isset( $types[ $ext ] ) ) return null;
		$size = @getimagesize( $path );
		$big  = filesize( $path ) > 3 * 1024 * 1024 || ( $size && max( $size[0], $size[1] ) > self::MAX_EDGE );
		if ( $big && function_exists( 'wp_get_image_editor' ) ) {
			$ed = wp_get_image_editor( $path );
			if ( ! is_wp_error( $ed ) ) {
				$ed->resize( self::MAX_EDGE, self::MAX_EDGE, false );
				$ed->set_quality( 85 );
				$tmp = wp_tempnam( 'bvrm-lic' ) . '.jpg';
				$saved = $ed->save( $tmp, 'image/jpeg' );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && is_readable( $saved['path'] ) ) {
					$data = base64_encode( (string) file_get_contents( $saved['path'] ) );
					@unlink( $saved['path'] );
					@unlink( $tmp );
					return array( 'image/jpeg', $data );
				}
				@unlink( $tmp );
			}
		}
		return array( $types[ $ext ], base64_encode( (string) file_get_contents( $path ) ) );
	}

	/** 読み取り結果の形（構造化出力） */
	public static function schema() {
		$str = array( 'type' => 'string' );
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_type'   => array( 'type' => 'string', 'enum' => array( 'japanese_license', 'my_number_license', 'international_permit', 'foreign_license', 'passport', 'other', 'unreadable' ) ),
				'license_number'  => $str,
				'license_types'   => array( 'type' => 'array', 'items' => $str ),
				'conditions'      => $str,
				'expiry_date'     => $str,
				'name'            => $str,
				'address'         => $str,
				'birth_date'      => $str,
				'issuing_country' => $str,
				'note'            => $str,
			),
			'required'             => array( 'document_type', 'license_number', 'license_types', 'conditions', 'expiry_date', 'name', 'address', 'birth_date', 'issuing_country', 'note' ),
			'additionalProperties' => false,
		);
	}

	public static function system_prompt() {
		return "あなたはレンタカー会社の受付で、法令で義務付けられた貸渡簿に記録するため、お客様の運転免許証などの画像から記載事項を書き写す係です。\n"
			. "画像に書かれているとおりに読み取ってください。読めない・写っていない項目は推測せず空文字にし、note にその旨を書いてください。\n\n"
			. "項目\n"
			. "- document_type：日本の運転免許証＝japanese_license、マイナンバーカードの免許情報（マイナ免許証・アプリの画面）＝my_number_license、国際運転免許証＝international_permit、外国の運転免許証＝foreign_license、パスポート＝passport、それ以外＝other、判読できない＝unreadable。\n"
			. "- license_number：免許証番号。日本の免許証は12桁の数字（「第 012345678900 号」の数字部分）。空白・記号は入れない。パスポート番号は入れない。\n"
			. "- license_types：運転できる免許の種類。日本の免許証は「種類」欄で取得済みの区分を、次の表記で並べる：大型、中型、準中型、普通、大特、大自二、普自二、小特、原付、け引、大二、中二、普二、大特二、け引二。外国・国際免許は記載どおりの区分（A、B など）。\n"
			. "- conditions：免許の条件等（例：眼鏡等、普通車はAT車に限る）。なければ空。\n"
			. "- expiry_date：有効期限。和暦（令和・平成）は西暦に直して YYYY-MM-DD で。\n"
			. "- name：氏名（記載どおり）。address：住所（記載どおり。裏面に住所変更の記載があれば新しい住所）。birth_date：生年月日を YYYY-MM-DD で。\n"
			. "- issuing_country：発行国（日本の免許証なら「日本」）。\n"
			. "- note：読み取れなかった項目、画像のぼやけ・一部隠れ・有効期限切れなど、スタッフが確認すべきこと。なければ空。";
	}

	/** server-side fallback（安全判定で断られたとき別モデルで自動再実行）に対応するモデル */
	protected static function supports_fallback( $model ) {
		return in_array( $model, array( 'claude-opus-5-5', 'claude-sonnet-5-5' ), true );
	}

	public static function build_body( $model, $parts ) {
		$content = $parts;
		$content[] = array( 'type' => 'text', 'text' => '上の書類から、貸渡簿に記録する項目を読み取ってください。' );
		$body = array(
			'model'         => $model,
			'max_tokens'    => 8000,
			'system'        => self::system_prompt(),
			'messages'      => array( array( 'role' => 'user', 'content' => $content ) ),
			'output_config' => array(
				'effort' => 'low',
				'format' => array( 'type' => 'json_schema', 'schema' => self::schema() ),
			),
		);
		if ( self::supports_fallback( $model ) ) $body['fallbacks'] = 'default';
		return $body;
	}

	/**
	 * Messages API を呼ぶ。混雑（429・5xx）なら1回だけ待って再試行する。
	 * @return array|WP_Error 読み取り結果（schema の形）
	 */
	public static function call( $parts ) {
		$s = BV_Util::settings();
		$model = isset( self::models()[ $s['claude_model'] ?? '' ] ) ? $s['claude_model'] : 'claude-opus-5-5';
		$body = self::build_body( $model, $parts );
		$headers = array(
			'x-api-key'         => trim( (string) $s['claude_api_key'] ),
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);
		if ( isset( $body['fallbacks'] ) ) $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';

		$err = null;
		for ( $try = 0; $try < 2; $try++ ) {
			$res = wp_remote_post( self::ENDPOINT, array( 'timeout' => 90, 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
			if ( is_wp_error( $res ) ) {
				$err = new WP_Error( 'network', 'Claude APIに接続できませんでした：' . $res->get_error_message() );
			} else {
				$code = (int) wp_remote_retrieve_response_code( $res );
				$json = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( 200 === $code && is_array( $json ) ) {
					if ( 'refusal' === ( $json['stop_reason'] ?? '' ) ) {
						return new WP_Error( 'refusal', '画像を読み取れませんでした（安全判定）。', array( 'status' => 422 ) );
					}
					$text = '';
					foreach ( (array) ( $json['content'] ?? array() ) as $b ) {
						if ( 'text' === ( $b['type'] ?? '' ) ) $text .= $b['text'];
					}
					$data = json_decode( trim( $text ), true );
					if ( ! is_array( $data ) ) return new WP_Error( 'bad_output', '読み取り結果を解釈できませんでした。', array( 'status' => 422 ) );
					return $data;
				}
				$msg = is_array( $json ) && isset( $json['error']['message'] ) ? (string) $json['error']['message'] : 'HTTP ' . $code;
				$err = new WP_Error( 'api_' . $code, $msg, array( 'status' => $code ) );
				if ( ! in_array( $code, array( 429, 500, 502, 503, 504, 529 ), true ) ) return $err;
			}
			if ( 0 === $try ) sleep( 2 );
		}
		return $err;
	}

	/* ---------- 結果の記録 ---------- */

	/** 読み取り結果を整えて、確認すべき点を洗い出し、予約に記録する */
	public static function apply( $r, $d ) {
		$type = (string) ( $d['document_type'] ?? '' );
		$notes = array();
		if ( '' !== trim( (string) ( $d['note'] ?? '' ) ) ) $notes[] = trim( (string) $d['note'] );

		if ( 'unreadable' === $type ) {
			return self::save( $r, array(), 'failed', '画像を判読できませんでした' . ( $notes ? '：' . implode( '／', $notes ) : '' ) . '。来店時に原本で確認してください。' );
		}

		$num = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', mb_convert_kana( (string) ( $d['license_number'] ?? '' ), 'a', 'UTF-8' ) ) );
		$jp  = in_array( $type, array( 'japanese_license', 'my_number_license' ), true );
		if ( $jp && '' !== $num && ! preg_match( '/^\d{12}$/', $num ) ) $notes[] = '免許証番号が12桁ではありません（要確認）';
		if ( 'passport' === $type ) $notes[] = 'パスポートのため免許証番号はありません。国際運転免許証などを確認してください';
		if ( '' === $num && 'passport' !== $type ) $notes[] = '免許証番号を読み取れませんでした';

		$types = array();
		foreach ( (array) ( $d['license_types'] ?? array() ) as $t ) {
			$t = trim( (string) $t );
			if ( '' !== $t ) $types[] = $t;
		}
		$label = implode( '・', array_unique( $types ) );
		if ( 'international_permit' === $type ) $label = '国際' . ( $label ? '（' . $label . '）' : '' );
		if ( 'foreign_license' === $type && '' !== trim( (string) ( $d['issuing_country'] ?? '' ) ) ) $label = trim( (string) $d['issuing_country'] ) . ( $label ? '（' . $label . '）' : '' );
		$cond = trim( (string) ( $d['conditions'] ?? '' ) );
		if ( $cond ) $label .= '／条件：' . $cond;

		$exp = (string) ( $d['expiry_date'] ?? '' );
		$exp = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $exp ) ? $exp : '';
		if ( $exp && strtotime( $exp . ' 23:59:59' ) < strtotime( (string) $r->return_dt ) ) {
			$notes[] = '有効期限（' . $exp . '）が返却日より前です';
		}

		/* 予約の氏名と免許証の氏名が明らかに違う場合（別の方が運転する可能性） */
		$name = (string) ( $d['name'] ?? '' );
		$norm = function ( $x ) { return preg_replace( '/[\s　・,.]/u', '', mb_convert_kana( mb_strtolower( (string) $x, 'UTF-8' ), 'asKV', 'UTF-8' ) ); };
		$res_name = $norm( $r->sei . $r->mei );
		$res_name_rev = $norm( $r->mei . $r->sei );
		$lic_name = $norm( $name );
		if ( '' !== $lic_name && '' !== $res_name && $lic_name !== $res_name && $lic_name !== $res_name_rev
			&& false === strpos( $lic_name, $res_name ) && false === strpos( $lic_name, $res_name_rev ) ) {
			$notes[] = '免許証の氏名（' . $name . '）が予約の氏名と一致しません（運転者が別の方の可能性）';
		}

		$fields = array(
			'license_type'   => mb_substr( $label, 0, 160, 'UTF-8' ),
			'license_number' => mb_substr( $num, 0, 40, 'UTF-8' ),
			'license_expiry' => $exp ?: null,
		);
		/* 借受人の住所が未入力なら、免許証の住所で補う（貸渡簿の記載事項） */
		$addr = trim( (string) ( $d['address'] ?? '' ) );
		if ( '' === trim( (string) $r->address ) && '' !== $addr ) {
			$fields['address'] = $addr;
			$notes[] = '住所は免許証から記入しました';
		}
		return self::save( $r, $fields, 'ocr', implode( '／', $notes ) );
	}

	/**
	 * 記録する（スタッフが手で直した番号は上書きしない）
	 * 同じメールアドレスで同じ画像を使っている予約のうち、まだ読み取っていないものにも記録する。
	 */
	protected static function save( $r, $fields, $source, $note ) {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$base = array(
			'license_read_at'   => current_time( 'mysql' ),
			'license_read_note' => mb_substr( (string) $note, 0, 255, 'UTF-8' ),
		);
		$ids = array( (int) $r->id );
		if ( is_email( (string) $r->email ) && '' !== (string) $r->license_files ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$t} WHERE email = %s AND license_files = %s AND ( license_read_at IS NULL OR id = %d )",
				$r->email, $r->license_files, (int) $r->id
			) ) );
			if ( ! in_array( (int) $r->id, $ids, true ) ) $ids[] = (int) $r->id;
		}
		foreach ( $ids as $id ) {
			$cur = BV_DB::get_reservation( $id );
			if ( ! $cur ) continue;
			$upd = $base;
			if ( 'manual' !== $cur->license_source ) {
				$upd['license_source'] = $source;
				foreach ( $fields as $k => $v ) {
					if ( 'address' === $k && '' !== trim( (string) $cur->address ) ) continue;
					$upd[ $k ] = $v;
				}
			}
			BV_DB::update_reservation( $id, $upd );
		}
		$ok = ( 'ocr' === $source );
		$msg = $ok
			? '免許証を読み取りました' . ( ! empty( $fields['license_number'] ) ? '（番号 ' . self::mask( $fields['license_number'] ) . '）' : '' ) . ( $note ? '。確認事項：' . $note : '。' )
			: (string) $note;
		return array( 'ok' => $ok, 'msg' => $msg );
	}

	/** 画面表示用に番号の中ほどを伏せる（例：0123****8900） */
	public static function mask( $num ) {
		$num = (string) $num;
		$len = strlen( $num );
		if ( $len <= 6 ) return str_repeat( '*', $len );
		return substr( $num, 0, 4 ) . str_repeat( '*', $len - 8 > 0 ? $len - 8 : 2 ) . substr( $num, -4 );
	}
}
