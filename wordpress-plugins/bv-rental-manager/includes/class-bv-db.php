<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BV_DB {

	const DB_VERSION = '1.7.0';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'bvrm_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$vehicles = self::table( 'vehicles' );
		$sql = "CREATE TABLE {$vehicles} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			plate VARCHAR(60) DEFAULT '',
			class VARCHAR(20) NOT NULL DEFAULT 'compact',
			location VARCHAR(40) NOT NULL DEFAULT 'hakuba_norikura',
			color_number VARCHAR(40) DEFAULT '',
			mileage INT UNSIGNED DEFAULT 0,
			has_navi TINYINT(1) DEFAULT 0,
			has_child_seat TINYINT(1) DEFAULT 0,
			has_junior_seat TINYINT(1) DEFAULT 0,
			has_ski_rack TINYINT(1) DEFAULT 0,
			has_etc TINYINT(1) DEFAULT 0,
			photos LONGTEXT,
			shaken_date DATE NULL,
			jibaiseki_date DATE NULL,
			insurance_date DATE NULL,
			purchase_date DATE NULL,
			rental_reg_date DATE NULL,
			disposal_date DATE NULL,
			monthly_cost INT UNSIGNED DEFAULT 0,
			tire_size VARCHAR(40) DEFAULT '',
			wiper_length VARCHAR(40) DEFAULT '',
			notes TEXT,
			sort_order INT DEFAULT 0,
			active TINYINT(1) DEFAULT 1,
			PRIMARY KEY (id),
			KEY class_loc (class, location)
		) {$charset};";
		dbDelta( $sql );

		$maint = self::table( 'maintenance' );
		$sql = "CREATE TABLE {$maint} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			vehicle_id BIGINT UNSIGNED NOT NULL,
			mdate DATE NOT NULL,
			mtype VARCHAR(20) NOT NULL DEFAULT 'maintenance',
			odometer INT UNSIGNED DEFAULT 0,
			cost INT UNSIGNED DEFAULT 0,
			memo TEXT,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY veh (vehicle_id, mdate)
		) {$charset};";
		dbDelta( $sql );

		$res = self::table( 'reservations' );
		$sql = "CREATE TABLE {$res} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			lang VARCHAR(5) NOT NULL DEFAULT 'ja',
			store VARCHAR(40) NOT NULL,
			vehicle_class VARCHAR(20) NOT NULL,
			vehicle_id BIGINT UNSIGNED DEFAULT 0,
			pickup_dt DATETIME NOT NULL,
			return_dt DATETIME NOT NULL,
			customer_id BIGINT UNSIGNED DEFAULT 0,
			sei VARCHAR(80) DEFAULT '',
			mei VARCHAR(80) DEFAULT '',
			email VARCHAR(160) DEFAULT '',
			phone VARCHAR(40) DEFAULT '',
			address TEXT,
			birthdate DATE NULL,
			license_files LONGTEXT,
			opt_child_seat TINYINT UNSIGNED DEFAULT 0,
			opt_junior_seat TINYINT UNSIGNED DEFAULT 0,
			opt_ski_rack TINYINT UNSIGNED DEFAULT 0,
			opt_navi TINYINT UNSIGNED DEFAULT 0,
			opt_etc TINYINT UNSIGNED DEFAULT 0,
			coverage VARCHAR(2) DEFAULT 'A',
			shuttle VARCHAR(10) DEFAULT 'none',
			shuttle_detail TEXT,
			shuttle_status VARCHAR(20) DEFAULT 'none',
			shuttle_fee INT UNSIGNED DEFAULT 0,
			shuttle_fee_pickup INT UNSIGNED DEFAULT 0,
			shuttle_fee_dropoff INT UNSIGNED DEFAULT 0,
			shuttle_link TEXT,
			shuttle_order_id VARCHAR(120) DEFAULT '',
			shuttle_payment_id VARCHAR(120) DEFAULT '',
			shuttle_paid_at DATETIME NULL,
			is_student TINYINT(1) DEFAULT 0,
			payment_method VARCHAR(20) DEFAULT 'square',
			coupon_code VARCHAR(60) DEFAULT '',
			review_mail_at DATETIME NULL,
			review_done_at DATETIME NULL,
			review_coupon_code VARCHAR(60) DEFAULT '',
			manual_discount INT DEFAULT 0,
			manual_discount_note VARCHAR(160) DEFAULT '',
			request_note TEXT,
			price_breakdown LONGTEXT,
			price_total INT DEFAULT 0,
			square_link TEXT,
			square_order_id VARCHAR(120) DEFAULT '',
			square_payment_id VARCHAR(120) DEFAULT '',
			paid_at DATETIME NULL,
			refund_amount INT DEFAULT 0,
			return_odometer INT UNSIGNED DEFAULT 0,
			return_location VARCHAR(40) DEFAULT '',
			fuel_full TINYINT(1) DEFAULT 0,
			no_accident TINYINT(1) DEFAULT 0,
			return_memo TEXT,
			trip_distance INT DEFAULT 0,
			returned_at DATETIME NULL,
			admin_memo TEXT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY code (code),
			KEY period (pickup_dt, return_dt),
			KEY veh (vehicle_id),
			KEY status (status)
		) {$charset};";
		dbDelta( $sql );

		$coupons = self::table( 'coupons' );
		$sql = "CREATE TABLE {$coupons} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(60) NOT NULL,
			label VARCHAR(120) DEFAULT '',
			discount_type VARCHAR(10) NOT NULL DEFAULT 'fixed',
			amount INT UNSIGNED NOT NULL DEFAULT 0,
			expires DATE NULL,
			active TINYINT(1) DEFAULT 1,
			used_count INT UNSIGNED DEFAULT 0,
			max_uses INT UNSIGNED DEFAULT 0,
			note VARCHAR(160) DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY code (code)
		) {$charset};";
		dbDelta( $sql );

		$rates = self::table( 'rate_days' );
		$sql = "CREATE TABLE {$rates} (
			rdate DATE NOT NULL,
			category VARCHAR(10) NOT NULL DEFAULT 'normal',
			PRIMARY KEY (rdate)
		) {$charset};";
		dbDelta( $sql );

		/* 貸出停止（整備・休車・貸切など） */
		$blocks = self::table( 'blocks' );
		$sql = "CREATE TABLE {$blocks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			vehicle_id BIGINT UNSIGNED NOT NULL,
			start_dt DATETIME NOT NULL,
			end_dt DATETIME NOT NULL,
			reason VARCHAR(200) DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY veh (vehicle_id, start_dt, end_dt)
		) {$charset};";
		dbDelta( $sql );

		$otp = self::table( 'otp' );
		$sql = "CREATE TABLE {$otp} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(160) NOT NULL,
			code VARCHAR(10) NOT NULL,
			token VARCHAR(64) DEFAULT '',
			verified TINYINT(1) DEFAULT 0,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY email (email)
		) {$charset};";
		dbDelta( $sql );

		update_option( 'bvrm_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'bvrm_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ---------- 車両 ---------- */

	public static function get_vehicles( $args = array() ) {
		global $wpdb;
		$t = self::table( 'vehicles' );
		$where = ' WHERE 1=1';
		$params = array();
		if ( ! empty( $args['class'] ) )    { $where .= ' AND class = %s';    $params[] = $args['class']; }
		if ( ! empty( $args['active'] ) )   { $where .= ' AND active = 1 AND (disposal_date IS NULL OR disposal_date = "0000-00-00")'; }
		$sql = "SELECT * FROM {$t} {$where} ORDER BY sort_order ASC, id ASC";
		if ( $params ) $sql = $wpdb->prepare( $sql, $params );
		return $wpdb->get_results( $sql );
	}

	public static function get_vehicle( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'vehicles' ) . ' WHERE id = %d', $id ) );
	}

	public static function save_vehicle( $data, $id = 0 ) {
		global $wpdb;
		$t = self::table( 'vehicles' );
		if ( $id ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) );
			return $id;
		}
		$wpdb->insert( $t, $data );
		return $wpdb->insert_id;
	}

	/* ---------- 予約 ---------- */

	public static function get_reservation( $id_or_code ) {
		global $wpdb;
		$t = self::table( 'reservations' );
		if ( is_numeric( $id_or_code ) ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id_or_code ) );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $id_or_code ) );
	}

	/**
	 * ORDER BY 句をホワイトリストで組み立てる
	 * （列名は $wpdb->prepare でプレースホルダに置けないため、許可した組み合わせだけを通す）
	 */
	protected static function sanitize_order( $order ) {
		$allowed_cols = array(
			'id', 'code', 'pickup_dt', 'return_dt', 'created_at', 'paid_at',
			'status', 'store', 'vehicle_id', 'vehicle_class', 'price_total', 'email',
		);
		$default = 'pickup_dt ASC';
		$order   = trim( (string) $order );
		if ( '' === $order ) return $default;

		$parts = array();
		foreach ( explode( ',', $order ) as $piece ) {
			$piece = trim( $piece );
			if ( ! preg_match( '/^([a-z_]+)(?:\s+(ASC|DESC))?$/i', $piece, $m ) ) return $default;
			$col = strtolower( $m[1] );
			if ( ! in_array( $col, $allowed_cols, true ) ) return $default;
			$dir = ( ! empty( $m[2] ) && 0 === strcasecmp( $m[2], 'DESC' ) ) ? 'DESC' : 'ASC';
			$parts[] = $col . ' ' . $dir;
		}
		return $parts ? implode( ', ', $parts ) : $default;
	}

	public static function get_reservations( $args = array() ) {
		global $wpdb;
		$t = self::table( 'reservations' );
		$where = ' WHERE 1=1';
		$params = array();
		if ( ! empty( $args['status'] ) )   { $where .= ' AND status = %s'; $params[] = $args['status']; }
		if ( ! empty( $args['exclude_cancelled'] ) ) { $where .= " AND status != 'cancelled'"; }
		if ( ! empty( $args['from'] ) )     { $where .= ' AND pickup_dt >= %s'; $params[] = $args['from'] . ' 00:00:00'; }
		if ( ! empty( $args['to'] ) )       { $where .= ' AND pickup_dt <= %s'; $params[] = $args['to'] . ' 23:59:59'; }
		if ( ! empty( $args['vehicle_id'] ) ) { $where .= ' AND vehicle_id = %d'; $params[] = $args['vehicle_id']; }
		if ( ! empty( $args['overlap'] ) ) { /* array(start, end) */
			$where .= ' AND pickup_dt < %s AND return_dt > %s';
			$params[] = $args['overlap'][1]; $params[] = $args['overlap'][0];
		}
		$order = self::sanitize_order( $args['order'] ?? '' );
		$sql = "SELECT * FROM {$t} {$where} ORDER BY {$order}";
		if ( ! empty( $args['limit'] ) ) $sql .= ' LIMIT ' . (int) $args['limit'];
		if ( $params ) $sql = $wpdb->prepare( $sql, $params );
		return $wpdb->get_results( $sql );
	}

	public static function insert_reservation( $data ) {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table( 'reservations' ), $data );
		return $wpdb->insert_id;
	}

	public static function update_reservation( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table( 'reservations' ), $data, array( 'id' => $id ) );
	}

	/* ---------- 貸出停止（ブロック） ---------- */

	public static function get_blocks( $from = '', $to = '', $vehicle_id = 0 ) {
		global $wpdb;
		$t = self::table( 'blocks' );
		$where = ' WHERE 1=1';
		$params = array();
		if ( $from && $to ) { $where .= ' AND start_dt < %s AND end_dt > %s'; $params[] = $to; $params[] = $from; }
		if ( $vehicle_id )  { $where .= ' AND vehicle_id = %d'; $params[] = $vehicle_id; }
		$sql = "SELECT * FROM {$t}{$where} ORDER BY start_dt ASC";
		if ( $params ) $sql = $wpdb->prepare( $sql, $params );
		return $wpdb->get_results( $sql );
	}

	public static function insert_block( $vehicle_id, $start, $end, $reason = '' ) {
		global $wpdb;
		$wpdb->insert( self::table( 'blocks' ), array(
			'vehicle_id' => (int) $vehicle_id,
			'start_dt'   => $start,
			'end_dt'     => $end,
			'reason'     => $reason,
			'created_at' => current_time( 'mysql' ),
		) );
		return $wpdb->insert_id;
	}

	public static function update_block( $id, $data ) {
		global $wpdb;
		return $wpdb->update( self::table( 'blocks' ), $data, array( 'id' => (int) $id ) );
	}

	public static function delete_block( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table( 'blocks' ), array( 'id' => (int) $id ) );
	}

	/* ---------- クーポン ---------- */

	public static function get_coupon( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table( 'coupons' ) . ' WHERE code = %s AND active = 1'
				. ' AND (expires IS NULL OR expires >= CURDATE())'
				. ' AND (max_uses = 0 OR used_count < max_uses)',
			trim( $code )
		) );
	}

	/**
	 * レビューのお礼クーポンを発行する
	 * 1予約につき1枚・1回限り。すでに発行済みならその内容を返す。
	 * @return object|WP_Error クーポン行
	 */
	public static function issue_review_coupon( $r ) {
		global $wpdb;
		$t = self::table( 'coupons' );

		/* 発行済みならそれを返す（ボタンの二度押し・メールの再クリック対策） */
		if ( ! empty( $r->review_coupon_code ) ) {
			$exist = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $r->review_coupon_code ) );
			if ( $exist ) return $exist;
		}

		$s      = BV_Util::settings();
		$amount = max( 1, (int) ( $s['review_coupon_amount'] ?? 500 ) );
		$days   = max( 1, (int) ( $s['review_coupon_days'] ?? 365 ) );

		/* 既存コードと重ならないコードを作る */
		$code = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$try = 'REV' . strtoupper( wp_generate_password( 7, false, false ) );
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE code = %s", $try ) ) ) { $code = $try; break; }
		}
		if ( ! $code ) return new WP_Error( 'code_failed', 'クーポンコードを発行できませんでした。' );

		$ok = $wpdb->insert( $t, array(
			'code'          => $code,
			'label'         => 'レビューのお礼',
			'discount_type' => 'fixed',
			'amount'        => $amount,
			'expires'       => date( 'Y-m-d', current_time( 'timestamp' ) + $days * DAY_IN_SECONDS ),
			'active'        => 1,
			'used_count'    => 0,
			'max_uses'      => 1,
			'note'          => '予約 ' . $r->code . '（' . trim( $r->sei . ' ' . $r->mei ) . '）',
		) );
		if ( ! $ok ) return new WP_Error( 'insert_failed', 'クーポンを保存できませんでした。' );

		self::update_reservation( $r->id, array(
			'review_coupon_code' => $code,
			'review_done_at'     => current_time( 'mysql' ),
		) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ) );
	}

	/* ---------- 料金カレンダー ---------- */

	public static function get_rate_categories( $from, $to ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT rdate, category FROM ' . self::table( 'rate_days' ) . ' WHERE rdate BETWEEN %s AND %s',
			$from, $to
		), OBJECT_K );
		return $rows ?: array();
	}

	public static function set_rate_day( $date, $category ) {
		global $wpdb;
		$t = self::table( 'rate_days' );
		if ( 'default' === $category ) {
			$wpdb->delete( $t, array( 'rdate' => $date ) );
			return;
		}
		$wpdb->replace( $t, array( 'rdate' => $date, 'category' => $category ) );
	}

	/* ---------- OTP ---------- */

	public static function create_otp( $email ) {
		global $wpdb;
		$code  = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$token = wp_generate_password( 32, false, false );
		$wpdb->insert( self::table( 'otp' ), array(
			'email'      => $email,
			'code'       => $code,
			'token'      => $token,
			'expires_at' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 15 * MINUTE_IN_SECONDS ),
		) );
		return array( 'code' => $code, 'token' => $token );
	}

	/** パスワード認証済みなど、OTPを経ずに検証済みトークンを発行する */
	public static function create_verified_token( $email ) {
		global $wpdb;
		$token = wp_generate_password( 32, false, false );
		$wpdb->insert( self::table( 'otp' ), array(
			'email'      => $email,
			'code'       => '',
			'token'      => $token,
			'verified'   => 1,
			'expires_at' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 2 * HOUR_IN_SECONDS ),
		) );
		return $token;
	}

	public static function verify_otp( $email, $code ) {
		global $wpdb;
		$t = self::table( 'otp' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE email = %s AND code = %s AND expires_at > %s ORDER BY id DESC LIMIT 1",
			$email, $code, current_time( 'mysql' )
		) );
		if ( ! $row ) return false;
		$wpdb->update( $t, array( 'verified' => 1 ), array( 'id' => $row->id ) );
		return $row->token;
	}

	/**
	 * 期限切れの認証コード行を削除する（日次cronから呼ばれる）
	 * 認証コードにはメールアドレスが残るため、役目を終えた行は残さない。
	 * @return int 削除件数
	 */
	public static function purge_expired_otp( $grace_days = 1 ) {
		global $wpdb;
		$t   = self::table( 'otp' );
		$cut = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - max( 0, (int) $grace_days ) * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE expires_at < %s", $cut ) );
	}

	public static function is_email_verified( $email, $token ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table( 'otp' ) . ' WHERE email = %s AND token = %s AND verified = 1 AND expires_at > DATE_SUB(%s, INTERVAL 2 HOUR)',
			$email, $token, current_time( 'mysql' )
		) );
	}
}
