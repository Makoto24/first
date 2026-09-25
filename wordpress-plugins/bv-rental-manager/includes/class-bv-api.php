<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API（地域サイトの予約フォームプラグインが利用）
 * 認証: X-BV-Api-Key ヘッダー
 */
class BV_API {

	const NS = 'bvrm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function get_api_key() {
		$key = get_option( 'bvrm_api_key' );
		if ( ! $key ) {
			$key = wp_generate_password( 40, false, false );
			update_option( 'bvrm_api_key', $key );
		}
		return $key;
	}

	public static function check_key( $req ) {
		$key = $req->get_header( 'X-BV-Api-Key' );
		return $key && hash_equals( self::get_api_key(), $key );
	}

	public static function routes() {
		register_rest_route( self::NS, '/config', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'config' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/availability', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'availability' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/quote', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'quote' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/otp/send', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'otp_send' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/otp/verify', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'otp_verify' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/reservations', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'create_reservation' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/member/login', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'member_login' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/inquiry', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'inquiry' ),
			'permission_callback' => array( __CLASS__, 'check_key' ),
		) );
		register_rest_route( self::NS, '/square/webhook', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'square_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** リクエストの言語を判定 */
	protected static function req_lang( $p ) {
		return ( isset( $p['lang'] ) && 'en' === $p['lang'] ) ? 'en' : 'ja';
	}

	/** 言語別のエラーメッセージ */
	protected static function msg( $key, $lang = 'ja', $args = array() ) {
		$m = array(
			'bad_slot' => array(
				'ja' => '貸出日時は営業時間内（30分単位）で指定してください。',
				'en' => 'Please choose a pick-up time within business hours, in 30-minute increments.',
			),
			'bad_slot_return' => array(
				'ja' => '返却日時は30分単位で指定してください。',
				'en' => 'Please choose a return time in 30-minute increments.',
			),
			'bad_slot_return_hours' => array(
				'ja' => '返却日時は営業時間内（%s〜%s・30分単位）で指定してください。',
				'en' => 'Please choose a return time within business hours (%s-%s), in 30-minute increments.',
			),
			'too_soon' => array(
				'ja' => 'ご予約は現在より%d時間後以降の日時をお選びください。',
				'en' => 'Please select a pick-up time at least %d hours from now.',
			),
			'too_far' => array(
				'ja' => 'ご予約は%d日先までとなります。',
				'en' => 'Bookings can be made up to %d days in advance.',
			),
			'order' => array(
				'ja' => '返却日時は貸出日時より後にしてください。',
				'en' => 'The return date and time must be after the pick-up date and time.',
			),
			'bad_class' => array(
				'ja' => '車両クラスの指定が正しくありません。',
				'en' => 'Invalid vehicle class.',
			),
			'bad_params' => array(
				'ja' => '車両クラスまたは店舗の指定が正しくありません。',
				'en' => 'Invalid vehicle class or branch.',
			),
			'too_young' => array(
				'ja' => '%s歳未満の方へのお貸出しはできません。保険および各種補償が%s歳以上の運転者にのみ適用されるためです。',
				'en' => 'We are unable to rent to drivers under %s years old. Insurance and coverage apply only to drivers aged %s and over.',
			),
			'need_birthdate' => array(
				'ja' => '生年月日をご入力ください。',
				'en' => 'Please enter your date of birth.',
			),
			'bad_email' => array(
				'ja' => 'メールアドレスの形式が正しくありません。',
				'en' => 'Please enter a valid email address.',
			),
			'rate_limited_otp' => array(
				'ja' => 'しばらく待ってから再送してください。',
				'en' => 'Please wait a moment before requesting another code.',
			),
			'bad_code' => array(
				'ja' => '認証コードが正しくないか期限切れです。',
				'en' => 'The verification code is incorrect or has expired.',
			),
			'not_verified' => array(
				'ja' => 'メール認証が完了していません。お手数ですが最初からやり直してください。',
				'en' => 'Email verification has not been completed. Please start again.',
			),
			'shuttle_detail_required' => array(
				'ja' => '送迎をご希望の場合は、送迎場所のご入力が必須です。',
				'en' => 'Please enter the shuttle location. It is required when requesting a shuttle.',
			),
			'no_stock' => array(
				'ja' => '申し訳ありません。この条件では満車です。日時やクラスを変更してお試しください。',
				'en' => 'Sorry, no vehicles are available for these conditions. Please try different dates or another class.',
			),
			'equipment_full' => array(
				'ja' => '申し訳ありません。ご希望の装備（カーナビ・ETC等）を搭載した車両が、この日程では満車です。装備の選択を外すか、日時・クラスを変更してお試しください。',
				'en' => 'Sorry, all vehicles equipped with your selected options (car navigation, ETC, etc.) are booked for these dates. Please remove the option, or try different dates or another class.',
			),
			'bad_period' => array(
				'ja' => '貸出期間の指定が正しくありません。',
				'en' => 'The rental period is invalid.',
			),
			'server_error' => array(
				'ja' => 'エラーが発生しました。時間をおいてお試しください。',
				'en' => 'An error occurred. Please try again later.',
			),
		);
		$lang = ( 'en' === $lang ) ? 'en' : 'ja';
		$text = isset( $m[ $key ][ $lang ] ) ? $m[ $key ][ $lang ] : $key;
		if ( $args ) $text = vsprintf( $text, $args );
		return $text;
	}

	/* ---------- endpoints ---------- */

	public static function config( $req ) {
		$s = BV_Util::settings();
		return array(
			'classes'   => BV_Util::classes(),
			'stores'    => BV_Util::stores(),
			'equipment' => BV_Util::equipment(),
			'coverages' => BV_Util::coverages(),
			'shuttles'  => BV_Util::shuttles(),
			/* 学割を選べる車両クラス（フォームでチェックボックスの出し分けに使う） */
			'student_classes' => BV_Util::student_classes(),
			/* 直前予約（お支払い完了で確定）の判定と、キャンセルポリシーの表示用 */
			'immediate_pay_hours'    => (int) $s['immediate_pay_hours'],
			'immediate_hold_minutes' => (int) $s['immediate_hold_minutes'],
			'autocancel_hours'       => ! empty( $s['autocancel_enabled'] ) ? (int) $s['autocancel_hours'] : 0,
			'cancel_policy_ja' => BV_Util::cancel_policy_text( 'ja' ),
			'cancel_policy_en' => BV_Util::cancel_policy_text( 'en' ),
			/* 図解用の構造化データ */
			'cancel_tiers'     => BV_Util::cancel_policy(),
			'cancel_noshow_pct'=> (int) $s['cancel_pct_noshow'],
			'open_time' => $s['open_time'],
			'close_time'=> $s['close_time'],
			'return_24h'=> ! empty( $s['return_24h'] ) ? 1 : 0,
			'manage_url'=> home_url( '/' ), /* 予約照会ページのベースURL */
			'lead_time_hours'  => (int) $s['lead_time_hours'],
			'max_advance_days' => (int) $s['max_advance_days'],
			/* サーバーの現在日時（サイト時刻・利用者端末の時計ズレ対策） */
			'now' => date( 'Y-m-d H:i', current_time( 'timestamp' ) ),
			/* 店舗ごとの営業時間・時間貸し・取扱クラス */
			'store_info' => self::store_info(),
			'hourly_rates' => array(
				'kei' => (int) $s['hourly_rate_kei'], 'compact' => (int) $s['hourly_rate_compact'],
				'suv' => (int) $s['hourly_rate_suv'], 'minivan' => (int) $s['hourly_rate_minivan'],
				'cov_b' => (int) $s['hourly_cov_b'], 'cov_c' => (int) $s['hourly_cov_c'],
				/* 24時間ごとの上限（基本料金のみに適用。予約フォームの注意書きで使用） */
				'day_cap' => (int) $s['hourly_day_cap'],
			),
			/* 運転者の下限年齢（0＝制限なし）。フォーム側でも入力時に確認する */
			'min_driver_age' => BV_Util::min_driver_age(),
		);
	}

	protected static function store_info() {
		$out = array();
		foreach ( BV_Util::stores() as $k => $st ) {
			$h = BV_Util::store_hours( $k );
			$out[ $k ] = array(
				'hourly'  => BV_Util::is_hourly_store( $k ) ? 1 : 0,
				'classes' => array_values( BV_Util::store_classes( $k ) ),
				'open'    => $h['open'],
				'close'   => $h['close'],
				/* 店舗ごとの受付条件・取扱内容（予約フォームの出し分けに使う） */
				'lead_time_hours'  => BV_Util::store_lead_time_hours( $k ),
				'coverages'        => array_keys( BV_Util::store_coverages( $k ) ),
				'student_classes'  => array_values( BV_Util::store_student_classes( $k ) ),
				'shuttle'          => BV_Util::store_allows_shuttle( $k ) ? 1 : 0,
			);
		}
		return $out;
	}

	protected static function validate_period( $p ) {
		$lang   = self::req_lang( $p );
		$pickup = sanitize_text_field( $p['pickup_dt'] ?? '' );
		$return = sanitize_text_field( $p['return_dt'] ?? '' );
		$store  = sanitize_key( $p['store'] ?? '' );
		if ( ! isset( BV_Util::stores()[ $store ] ) ) $store = '';
		if ( ! BV_Util::validate_slot( $pickup, 'pickup', $store ) ) {
			return new WP_Error( 'bad_slot', self::msg( 'bad_slot', $lang ), array( 'status' => 400 ) );
		}
		if ( ! BV_Util::validate_slot( $return, 'return', $store ) ) {
			if ( $store && BV_Util::is_hourly_store( $store ) ) {
				$hh = BV_Util::store_hours( $store );
				return new WP_Error( 'bad_slot_return', self::msg( 'bad_slot_return_hours', $lang, array( $hh['open'], $hh['close'] ) ), array( 'status' => 400 ) );
			}
			return new WP_Error( 'bad_slot_return', self::msg( 'bad_slot_return', $lang ), array( 'status' => 400 ) );
		}
		$s = BV_Util::settings();
		$now = current_time( 'timestamp' );
		/* 受付開始は店舗ごと（時間貸しの出張所は直前の予約も受け付ける） */
		$lead = BV_Util::store_lead_time_hours( $store );
		if ( strtotime( $pickup ) < $now + $lead * HOUR_IN_SECONDS ) {
			return new WP_Error( 'too_soon', self::msg( 'too_soon', $lang, array( $lead ) ), array( 'status' => 400 ) );
		}
		$max_days = max( 1, (int) $s['max_advance_days'] );
		if ( strtotime( $pickup ) > $now + $max_days * DAY_IN_SECONDS ) {
			return new WP_Error( 'too_far', self::msg( 'too_far', $lang, array( $max_days ) ), array( 'status' => 400 ) );
		}
		if ( strtotime( $return ) <= strtotime( $pickup ) ) {
			return new WP_Error( 'order', self::msg( 'order', $lang ), array( 'status' => 400 ) );
		}
		return array( $pickup, $return );
	}

	public static function availability( $req ) {
		$p = $req->get_json_params();
		$period = self::validate_period( $p );
		if ( is_wp_error( $period ) ) return $period;
		$lang  = self::req_lang( $p );
		$class = sanitize_key( $p['vehicle_class'] ?? '' );
		if ( ! isset( BV_Util::classes()[ $class ] ) ) return new WP_Error( 'bad_class', self::msg( 'bad_class', $lang ), array( 'status' => 400 ) );
		$store = sanitize_key( $p['store'] ?? '' );
		if ( ! isset( BV_Util::stores()[ $store ] ) ) $store = '';
		if ( $store && ! in_array( $class, BV_Util::store_classes( $store ), true ) ) return new WP_Error( 'bad_class', self::msg( 'bad_class', $lang ), array( 'status' => 400 ) );
		/* 申し込まれた装備を積んでいる車両があるかどうかも判定に含める */
		$require = BV_Availability::required_equipment( self::equipment_from_request( $p ) );
		$available = BV_Availability::is_available( $class, $period[0], $period[1], 0, $store, $require );
		$out = array( 'available' => $available );
		if ( ! $available && $require && BV_Availability::is_available( $class, $period[0], $period[1], 0, $store ) ) {
			/* 車両自体は空いているが、装備付きの車両が埋まっている場合 */
			$out['equipment_full'] = true;
			$out['message'] = self::msg( 'equipment_full', $lang );
		}
		if ( $available ) {
			$quote = BV_Pricing::quote( array_merge( self::strip_admin_args( $p ), array( 'pickup_dt' => $period[0], 'return_dt' => $period[1], 'vehicle_class' => $class ) ) );
			if ( ! is_wp_error( $quote ) ) $out['quote'] = $quote;
		}
		return $out;
	}

	/** リクエストから装備の数量だけを取り出す（opt_navi など） */
	protected static function equipment_from_request( $p ) {
		$out = array();
		foreach ( BV_Util::equipment_keys() as $ek ) {
			$out[ 'opt_' . $ek ] = isset( $p[ 'opt_' . $ek ] ) ? (int) $p[ 'opt_' . $ek ] : 0;
		}
		return $out;
	}

	/** 管理者専用の料金引数を外部リクエストから取り除く */
	protected static function strip_admin_args( $p ) {
		if ( ! is_array( $p ) ) return array();
		unset( $p['manual_discount'], $p['manual_discount_note'] );
		return $p;
	}

	public static function quote( $req ) {
		$p = $req->get_json_params();
		$period = self::validate_period( $p );
		if ( is_wp_error( $period ) ) return $period;
		/* 見積も予約作成と同じ条件で計算する（店舗で扱わない内容は寄せる） */
		$quote = BV_Pricing::quote( array_merge( self::strip_admin_args( $p ), array( 'pickup_dt' => $period[0], 'return_dt' => $period[1] ) ) );
		if ( is_wp_error( $quote ) ) { $quote->add_data( array( 'status' => 400 ) ); return $quote; }
		return $quote;
	}

	public static function otp_send( $req ) {
		$p = $req->get_json_params();
		$email = sanitize_email( $p['email'] ?? '' );
		$lang  = ( 'en' === ( $p['lang'] ?? 'ja' ) ) ? 'en' : 'ja';
		if ( ! is_email( $email ) ) return new WP_Error( 'bad_email', self::msg( 'bad_email', $lang ), array( 'status' => 400 ) );
		if ( get_transient( 'bvrm_otp_rl_' . md5( $email ) ) ) {
			return new WP_Error( 'rate_limited', self::msg( 'rate_limited_otp', $lang ), array( 'status' => 429 ) );
		}
		set_transient( 'bvrm_otp_rl_' . md5( $email ), 1, MINUTE_IN_SECONDS );
		$store = sanitize_key( $p['store'] ?? '' );
		if ( ! isset( BV_Util::stores()[ $store ] ) ) $store = '';
		$otp = BV_DB::create_otp( $email );
		BV_Mailer::send_otp( $email, $otp['code'], $lang, $store );
		return array( 'sent' => true );
	}

	public static function otp_verify( $req ) {
		$p = $req->get_json_params();
		$lang  = self::req_lang( $p );
		$email = sanitize_email( $p['email'] ?? '' );
		$code  = preg_replace( '/\D/', '', (string) ( $p['code'] ?? '' ) );
		$token = BV_DB::verify_otp( $email, $code );
		if ( ! $token ) return new WP_Error( 'bad_code', self::msg( 'bad_code', $lang ), array( 'status' => 400 ) );
		return array( 'verified' => true, 'token' => $token );
	}

	/**
	 * base64ファイル保存 → 保存キー
	 * 本人確認書類は公開領域（wp-content/uploads直下）ではなく、
	 * 直接アクセスを禁止した uploads/bv-private/ に保存する（BV_Files参照）。
	 */
	protected static function save_license_file( $b64, $name_hint ) {
		if ( ! $b64 || ! preg_match( '#^data:(image/(jpeg|png|webp)|application/pdf);base64,#', $b64, $m ) ) return '';
		$data = base64_decode( substr( $b64, strpos( $b64, ',' ) + 1 ) );
		if ( ! $data || strlen( $data ) > 10 * 1024 * 1024 ) return '';
		$ext = ( 'application/pdf' === $m[1] ) ? 'pdf' : str_replace( 'jpeg', 'jpg', $m[2] );
		$key = BV_Files::save_bytes( $data, $ext );
		return is_wp_error( $key ) ? '' : $key;
	}

	public static function create_reservation( $req ) {
		$p = $req->get_json_params();
		$period = self::validate_period( $p );
		if ( is_wp_error( $period ) ) return $period;

		$lang  = self::req_lang( $p );
		$email = sanitize_email( $p['email'] ?? '' );
		if ( ! BV_DB::is_email_verified( $email, sanitize_text_field( $p['otp_token'] ?? '' ) ) ) {
			return new WP_Error( 'not_verified', self::msg( 'not_verified', $lang ), array( 'status' => 403 ) );
		}

		/*
		 * 運転者の年齢制限（貸出日時点で判定）
		 * 生年月日を受け取るのはこの処理だけなので、ここで確認する。
		 * 空き状況の確認・見積では判定しない（あちらに生年月日の入力欄はない）。
		 */
		$age_ok = BV_Util::check_driver_age( sanitize_text_field( $p['birthdate'] ?? '' ), $period[0], $lang );
		if ( true !== $age_ok ) {
			$min = BV_Util::min_driver_age();
			$key = ( '' === trim( (string) ( $p['birthdate'] ?? '' ) ) ) ? 'need_birthdate' : 'too_young';
			return new WP_Error( $key, self::msg( $key, $lang, array( $min, $min ) ), array( 'status' => 400 ) );
		}

		$class = sanitize_key( $p['vehicle_class'] ?? '' );
		$store = sanitize_key( $p['store'] ?? '' );
		if ( ! isset( BV_Util::classes()[ $class ] ) || ! isset( BV_Util::stores()[ $store ] ) || ! in_array( $class, BV_Util::store_classes( $store ), true ) ) {
			return new WP_Error( 'bad_params', self::msg( 'bad_params', $lang ), array( 'status' => 400 ) );
		}
		$require = BV_Availability::required_equipment( self::equipment_from_request( $p ) );
		if ( ! BV_Availability::is_available( $class, $period[0], $period[1], 0, $store, $require ) ) {
			$key = ( $require && BV_Availability::is_available( $class, $period[0], $period[1], 0, $store ) )
				? 'equipment_full' : 'no_stock';
			return new WP_Error( $key, self::msg( $key, $lang ), array( 'status' => 409 ) );
		}

		/*
		 * 店舗で扱わない内容は、ここで受け付けない形に寄せる。
		 * 補償は選べるプランへ、学割・送迎は扱わない店舗では無効にする。
		 */
		$coverage   = BV_Util::store_coverage_or_default( $store, $p['coverage'] ?? 'A' );
		$shuttle_in = BV_Util::store_allows_shuttle( $store ) ? sanitize_key( $p['shuttle'] ?? 'none' ) : 'none';
		if ( ! isset( BV_Util::shuttles()[ $shuttle_in ] ) ) $shuttle_in = 'none';
		$is_student = ! empty( $p['is_student'] )
			&& BV_Util::is_student_class( $class )
			&& BV_Util::store_allows_student( $store );

		/* 送迎希望時は詳細場所を必須にする */
		if ( 'none' !== $shuttle_in && '' === trim( (string) ( $p['shuttle_detail'] ?? '' ) ) ) {
			return new WP_Error( 'shuttle_detail_required', self::msg( 'shuttle_detail_required', $lang ), array( 'status' => 400 ) );
		}

		$quote_args = array(
			'vehicle_class' => $class, 'pickup_dt' => $period[0], 'return_dt' => $period[1],
			'coverage'       => $coverage,
			'shuttle'        => $shuttle_in,
			'is_student'     => $is_student,
			'coupon_code'    => sanitize_text_field( $p['coupon_code'] ?? '' ),
			'lang'           => $lang,
			'store'          => $store,
		);
		foreach ( BV_Util::equipment_keys() as $ek ) {
			$quote_args[ 'opt_' . $ek ] = (int) ( $p[ 'opt_' . $ek ] ?? 0 );
		}
		$quote = BV_Pricing::quote( $quote_args );
		if ( is_wp_error( $quote ) ) { $quote->add_data( array( 'status' => 400 ) ); return $quote; }

		/* 免許証等ファイル */
		$files = array();
		$file_keys = array( 'license_front', 'license_back', 'passport', 'intl_license' );
		foreach ( $file_keys as $fk ) {
			if ( ! empty( $p[ $fk ] ) ) {
				$url = self::save_license_file( $p[ $fk ], $fk );
				if ( $url ) $files[ $fk ] = $url;
			}
		}

		/* 新規アップロードがない場合は、過去の予約から免許証画像を引き継ぐ */
		if ( ! $files ) {
			global $wpdb;
			$prev = $wpdb->get_var( $wpdb->prepare(
				'SELECT license_files FROM ' . BV_DB::table( 'reservations' ) . " WHERE email = %s AND license_files != '' AND license_files != '[]' ORDER BY id DESC LIMIT 1",
				$email
			) );
			if ( $prev ) {
				$prev_files = json_decode( $prev, true );
				if ( is_array( $prev_files ) ) $files = $prev_files;
			}
		}

		/* 会員登録（パスワードが来ていれば） */
		$sei = sanitize_text_field( $p['sei'] ?? '' );
		$mei = sanitize_text_field( $p['mei'] ?? '' );
		$customer_id = 0;
		if ( ! empty( $p['member_password'] ) ) {
			$cid = BV_Members::register( $email, (string) $p['member_password'], $sei, $mei,
				sanitize_text_field( $p['phone'] ?? '' ), sanitize_textarea_field( $p['address'] ?? '' ),
				sanitize_text_field( $p['birthdate'] ?? '' ) );
			if ( ! is_wp_error( $cid ) ) $customer_id = (int) $cid;
		} else {
			/* 既存会員（ログイン済み）の場合は紐付けし、連絡先を最新化 */
			$u = get_user_by( 'email', $email );
			if ( $u ) {
				$customer_id = (int) $u->ID;
				if ( ! empty( $p['phone'] ) )     update_user_meta( $u->ID, 'bv_phone', sanitize_text_field( $p['phone'] ) );
				if ( ! empty( $p['address'] ) )   update_user_meta( $u->ID, 'bv_address', sanitize_textarea_field( $p['address'] ) );
				if ( ! empty( $p['birthdate'] ) ) update_user_meta( $u->ID, 'bv_birthdate', sanitize_text_field( $p['birthdate'] ) );
			}
		}

		$code = BV_Util::reservation_code();
		$shuttle = $shuttle_in; /* 店舗で扱わない場合は 'none' に寄せてある */
		$equip_data = array();
		foreach ( BV_Util::equipment_keys() as $ek ) {
			$equip_data[ 'opt_' . $ek ] = (int) ( $p[ 'opt_' . $ek ] ?? 0 );
		}
		$id = BV_DB::insert_reservation( array_merge( $equip_data, array(
			'code' => $code, 'status' => 'pending', 'lang' => $lang, 'store' => $store,
			'vehicle_class' => $class,
			'vehicle_id' => BV_Availability::auto_assign( $class, $period[0], $period[1], $store, 0, $require ),
			'pickup_dt' => $period[0], 'return_dt' => $period[1],
			'customer_id' => $customer_id, 'sei' => $sei, 'mei' => $mei,
			'email' => $email,
			'phone' => sanitize_text_field( $p['phone'] ?? '' ),
			'address' => sanitize_textarea_field( $p['address'] ?? '' ),
			'birthdate' => sanitize_text_field( $p['birthdate'] ?? '' ) ?: null,
			'license_files' => wp_json_encode( $files ),
			'coverage' => $coverage,
			'shuttle' => $shuttle,
			'shuttle_detail' => ( 'none' === $shuttle ) ? '' : sanitize_textarea_field( $p['shuttle_detail'] ?? '' ),
			'shuttle_status' => ( 'none' === $shuttle ) ? 'none' : 'requested',
			'is_student' => $is_student ? 1 : 0,
			'coupon_code' => sanitize_text_field( $p['coupon_code'] ?? '' ),
			'request_note' => sanitize_textarea_field( $p['request_note'] ?? '' ),
			'price_breakdown' => wp_json_encode( $quote ),
			'price_total' => (int) $quote['total'],
		) ) );

		if ( $quote['discount'] > 0 && ! empty( $quote_args['coupon_code'] ) ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . BV_DB::table( 'coupons' ) . ' SET used_count = used_count + 1 WHERE code = %s', $quote_args['coupon_code'] ) );
		}

		$r = BV_DB::get_reservation( $id );

		/* 車両料金はまず先に決済してもらう（送迎の有無に関わらず） */
		$link = BV_Square::create_payment_link( $r );
		if ( ! is_wp_error( $link ) && ! empty( $link['url'] ) ) {
			BV_DB::update_reservation( $id, array( 'square_link' => $link['url'], 'square_order_id' => $link['order_id'] ) );
			$r = BV_DB::get_reservation( $id );
		}
		BV_Mailer::send_provisional( $r, 'auto' );

		/* 送迎リクエストがあれば管理者へ別途通知 */
		if ( 'none' !== $shuttle ) {
			BV_Mailer::send_shuttle_request_admin( $r );
		}

		$s_now = BV_Util::settings();
		return array(
			'code'   => $code,
			'total'  => (int) $quote['total'],
			'pay_url'=> $r->square_link ?: '',
			'manage_url' => add_query_arg( array( 'bv_manage' => $code, 'lang' => $lang, 'store' => $store ), home_url( '/' ) ),
			/* 直前予約は仮予約メールを送らないため、その旨を案内する */
			'immediate' => BV_Mailer::is_immediate( $r ) ? 1 : 0,
			'hold_minutes' => (int) $s_now['immediate_hold_minutes'],
			/* カウントダウン表示用（サイト時刻・端末の時計ズレ対策で now も返す） */
			'pay_deadline' => date( 'Y-m-d H:i:s', BV_Mailer::payment_deadline( $r ) ),
			'now'          => current_time( 'mysql' ),
			'message'=> ( 'en' === $lang )
				? ( BV_Mailer::is_immediate( $r )
					? sprintf( 'Please complete your payment within %d minutes. Your confirmation email will be sent once payment is received.', (int) $s_now['immediate_hold_minutes'] )
					: ( $r->square_link ? 'A payment link has been emailed to you.' : 'Our staff will contact you with payment details.' ) )
				: ( BV_Mailer::is_immediate( $r )
					? sprintf( '%d分以内にお支払いをお願いします。お支払いの確認後、予約確定メールをお送りします。', (int) $s_now['immediate_hold_minutes'] )
					: ( $r->square_link ? '決済リンク付きのメールをお送りしました。' : 'スタッフ確認後、お支払いのご案内をお送りします。' ) ),
		);
	}

	/** 会員ログイン（メール＋パスワード）→ 過去の登録情報を返す */
	public static function member_login( $req ) {
		$p = $req->get_json_params();
		$email = sanitize_email( $p['email'] ?? '' );
		$pass  = (string) ( $p['password'] ?? '' );
		$lang  = ( 'en' === ( $p['lang'] ?? 'ja' ) ) ? 'en' : 'ja';
		$fail_msg = ( 'en' === $lang ) ? 'Incorrect email address or password.' : 'メールアドレスまたはパスワードが正しくありません。';

		if ( ! is_email( $email ) || '' === $pass ) {
			return new WP_Error( 'bad_login', $fail_msg, array( 'status' => 400 ) );
		}
		/* ブルートフォース対策：10回/15分 */
		$rl = 'bvrm_login_' . md5( $email );
		$tries = (int) get_transient( $rl );
		if ( $tries >= 10 ) {
			return new WP_Error( 'rate_limited',
				( 'en' === $lang ) ? 'Too many attempts. Please try again later.' : '試行回数が多すぎます。しばらくしてからお試しください。',
				array( 'status' => 429 ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user || ! wp_check_password( $pass, $user->user_pass, $user->ID ) ) {
			set_transient( $rl, $tries + 1, 15 * MINUTE_IN_SECONDS );
			return new WP_Error( 'bad_login', $fail_msg, array( 'status' => 403 ) );
		}
		delete_transient( $rl );

		/* 過去の予約から不足情報と免許証画像を補完 */
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE email = %s ORDER BY id DESC LIMIT 1", $email
		) );

		$phone     = get_user_meta( $user->ID, 'bv_phone', true );
		$address   = get_user_meta( $user->ID, 'bv_address', true );
		$birthdate = get_user_meta( $user->ID, 'bv_birthdate', true );
		if ( $last ) {
			if ( ! $phone )     $phone = $last->phone;
			if ( ! $address )   $address = $last->address;
			if ( ! $birthdate ) $birthdate = $last->birthdate;
		}

		$has_license = false;
		if ( $last && $last->license_files ) {
			$f = json_decode( $last->license_files, true );
			$has_license = is_array( $f ) && ! empty( $f );
		}

		return array(
			'token'       => BV_DB::create_verified_token( $email ),
			'email'       => $email,
			'sei'         => $user->last_name,
			'mei'         => $user->first_name,
			'phone'       => $phone,
			'address'     => $address,
			'birthdate'   => ( $birthdate && '0000-00-00' !== $birthdate ) ? $birthdate : '',
			'has_license' => $has_license,
		);
	}

	/** 満車時の車両調整問い合わせ */
	public static function inquiry( $req ) {
		$p = $req->get_json_params();
		$s = BV_Util::settings();
		$lang  = self::req_lang( $p );
		$email = sanitize_email( $p['email'] ?? '' );
		if ( ! is_email( $email ) ) return new WP_Error( 'bad_email', self::msg( 'bad_email', $lang ), array( 'status' => 400 ) );
		$store = sanitize_key( $p['store'] ?? '' );
		if ( ! isset( BV_Util::stores()[ $store ] ) ) $store = '';
		$class = sanitize_key( $p['vehicle_class'] ?? '' );
		if ( ! isset( BV_Util::classes()[ $class ] ) ) $class = '';

		BV_Mailer::send_inquiry( array(
			'store'         => $store,
			'vehicle_class' => $class,
			'pickup_dt'     => sanitize_text_field( $p['pickup_dt'] ?? '' ),
			'return_dt'     => sanitize_text_field( $p['return_dt'] ?? '' ),
			'name'          => sanitize_text_field( $p['name'] ?? '' ),
			'email'         => $email,
			'phone'         => sanitize_text_field( $p['phone'] ?? '' ),
			'message'       => sanitize_textarea_field( $p['message'] ?? '' ),
			'lang'          => ( 'en' === ( $p['lang'] ?? 'ja' ) ) ? 'en' : 'ja',
		) );

		return array( 'sent' => true );
	}

	public static function square_webhook( $req ) {
		/* 到達性チェック用の自己テスト（ログには残さない） */
		if ( $req->get_header( 'x-bvrm-probe' ) ) {
			return new WP_REST_Response( array( 'probe' => true ), 200 );
		}

		$body = $req->get_body();
		$sig  = $req->get_header( 'x-square-hmacsha256-signature' );
		$json = json_decode( $body, true );

		/* 受信ログ（直近20件・設定画面で確認可能） */
		$log = get_option( 'bvrm_webhook_log', array() );
		if ( ! is_array( $log ) ) $log = array();

		$ok_sig = BV_Square::verify_signature( $body, $sig );
		$entry = array(
			'time'   => current_time( 'mysql' ),
			'type'   => isset( $json['type'] ) ? $json['type'] : '(不明)',
			'sig'    => $ok_sig ? 'OK' : 'NG',
			'status' => isset( $json['data']['object']['payment']['status'] ) ? $json['data']['object']['payment']['status'] : '',
			'note'   => isset( $json['data']['object']['payment']['note'] ) ? $json['data']['object']['payment']['note'] : '',
			'order'  => isset( $json['data']['object']['payment']['order_id'] ) ? $json['data']['object']['payment']['order_id'] : '',
			'result' => '',
		);

		if ( ! $ok_sig ) {
			/* 署名キーが設定されているのに一致しない＝偽装または設定間違い。受理しない。 */
			if ( ! BV_Square::signature_unavailable() ) {
				$entry['result'] = '署名検証に失敗（署名キーが違う可能性）'
					. ( $sig ? '' : '／署名ヘッダーが送られていません' );
				array_unshift( $log, $entry );
				update_option( 'bvrm_webhook_log', array_slice( $log, 0, 20 ), false );
				return new WP_Error( 'bad_sig', 'Invalid signature', array( 'status' => 403 ) );
			}

			/*
			 * 署名キー未設定・診断モード中：通知の中身は信用せず、Squareへ直接確認する。
			 * 1件ごとにSquareへ問い合わせるため、大量送信で負荷をかけられないよう回数を制限する。
			 */
			$entry['sig'] = '未検証';
			$rl_key = 'bvrm_wh_unverified_count';
			$hits   = (int) get_transient( $rl_key );
			if ( $hits >= 60 ) {
				$entry['result'] = '署名未検証の通知が短時間に集中したため保留しました（署名キーを設定してください）。入金は15分ごとの自動同期で反映されます。';
				array_unshift( $log, $entry );
				update_option( 'bvrm_webhook_log', array_slice( $log, 0, 20 ), false );
				return new WP_Error( 'rate_limited', 'Too many unverified webhooks', array( 'status' => 429 ) );
			}
			set_transient( $rl_key, $hits + 1, 5 * MINUTE_IN_SECONDS );

			$entry['result'] = BV_Square::handle_webhook_unverified( $json ?: array() );
			array_unshift( $log, $entry );
			update_option( 'bvrm_webhook_log', array_slice( $log, 0, 20 ), false );
			return array( 'ok' => true );
		}

		$handled = BV_Square::handle_webhook( $json ?: array() );
		$entry['result'] = $handled ? $handled : '該当予約なし／処理対象外';
		array_unshift( $log, $entry );
		update_option( 'bvrm_webhook_log', array_slice( $log, 0, 20 ), false );

		return array( 'ok' => true );
	}
}
