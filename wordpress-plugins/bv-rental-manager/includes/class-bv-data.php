<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 外部連携用の読み取り専用データAPI（Claude等のMCP連携で使う）
 *
 * 方針：
 * - 読み取りのみ。このクラスからデータを書き換えることはない。
 * - 個人情報は出さない。氏名は姓のみ、連絡先・住所・生年月日・免許証は返さない。
 * - 店舗サイト用のAPIキーとは別のキーを使う（用途ごとに失効させられるようにするため）。
 *
 * エンドポイント： /wp-json/bvrm/v1/data/<name>
 */
class BV_Data {

	const NS = 'bvrm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ---------- 認証 ---------- */

	/** 連携用のキー（未発行なら作る） */
	public static function get_key() {
		$key = get_option( 'bvrm_data_key' );
		if ( ! $key ) {
			$key = wp_generate_password( 48, false, false );
			update_option( 'bvrm_data_key', $key );
		}
		return $key;
	}

	/** キーを作り直す（漏れたとき用） */
	public static function rotate_key() {
		$key = wp_generate_password( 48, false, false );
		update_option( 'bvrm_data_key', $key );
		return $key;
	}

	/** 連携が有効か（既定は無効。設定画面で明示的に有効化する） */
	public static function enabled() {
		return (bool) get_option( 'bvrm_data_enabled', 0 );
	}

	public static function check_key( $req ) {
		if ( ! self::enabled() ) return false;
		$key = $req->get_header( 'X-BV-Data-Key' );
		if ( ! $key ) {
			/* Authorization: Bearer <key> でも受け付ける */
			$auth = (string) $req->get_header( 'Authorization' );
			if ( 0 === stripos( $auth, 'bearer ' ) ) $key = trim( substr( $auth, 7 ) );
		}
		if ( ! $key ) return false;
		return hash_equals( self::get_key(), (string) $key );
	}

	/* ---------- ルート ---------- */

	public static function routes() {
		$names = array( 'summary', 'by_store', 'by_class', 'by_vehicle', 'monthly', 'reservations', 'vehicles' );
		foreach ( $names as $n ) {
			register_rest_route( self::NS, '/data/' . $n, array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'handle_' . $n ),
				'permission_callback' => array( __CLASS__, 'check_key' ),
			) );
		}
	}

	/* ---------- 共通のパラメータ処理 ---------- */

	/** 期間（既定は当月） */
	protected static function period( $req ) {
		$from = sanitize_text_field( (string) $req->get_param( 'from' ) );
		$to   = sanitize_text_field( (string) $req->get_param( 'to' ) );
		$ok = function ( $d ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ); };
		if ( ! $ok( $from ) ) $from = date( 'Y-m-01', current_time( 'timestamp' ) );
		if ( ! $ok( $to ) )   $to   = date( 'Y-m-t', current_time( 'timestamp' ) );
		if ( $from > $to ) { $t = $from; $from = $to; $to = $t; }
		return array( $from, $to );
	}

	/** 店舗の絞り込み（未指定なら全店舗） */
	protected static function store( $req ) {
		$s = sanitize_key( (string) $req->get_param( 'store' ) );
		return isset( BV_Util::stores()[ $s ] ) ? $s : '';
	}

	/**
	 * 集計対象の条件（キャンセルを除く貸出日ベース）
	 * @return array array( $where, $params )
	 */
	protected static function where( $from, $to, $store ) {
		$where  = " WHERE status != 'cancelled' AND pickup_dt >= %s AND pickup_dt <= %s";
		$params = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		if ( $store ) { $where .= ' AND store = %s'; $params[] = $store; }
		return array( $where, $params );
	}

	/** 貸渡日数（1日未満は1日として数える） */
	protected static function days_expr() {
		return 'GREATEST(1, CEIL(TIMESTAMPDIFF(HOUR, pickup_dt, return_dt) / 24))';
	}

	protected static function ok( $data, $req, $extra = array() ) {
		list( $from, $to ) = self::period( $req );
		return array_merge( array(
			'period' => array( 'from' => $from, 'to' => $to ),
			'store'  => self::store( $req ) ?: 'all',
			'currency' => 'JPY',
			'data'   => $data,
		), $extra );
	}

	/* ---------- 集計 ---------- */

	/** 期間全体のまとめ（件数・売上・平均単価・稼働率） */
	public static function handle_summary( $req ) {
		global $wpdb;
		list( $from, $to ) = self::period( $req );
		$store = self::store( $req );
		list( $where, $params ) = self::where( $from, $to, $store );
		$t = BV_DB::table( 'reservations' );
		$d = self::days_expr();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue,
			        COALESCE(SUM(trip_distance),0) km, COALESCE(SUM({$d}),0) rental_days,
			        SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END) paid_cnt,
			        COALESCE(SUM(refund_amount),0) refunded
			   FROM {$t}{$where}", $params
		) );

		$span = max( 1, (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 );
		$fleet = 0;
		foreach ( BV_DB::get_vehicles( array( 'active' => true ) ) as $v ) {
			if ( ! $store || in_array( $v->location, BV_Util::store_locations( $store ), true ) ) $fleet++;
		}
		$capacity = max( 1, $fleet * $span );
		$cnt = (int) $row->cnt;

		return self::ok( array(
			'reservations'    => $cnt,
			'revenue'         => (int) $row->revenue,
			'refunded'        => (int) $row->refunded,
			'net_revenue'     => (int) $row->revenue - (int) $row->refunded,
			'average_price'   => $cnt ? (int) round( $row->revenue / $cnt ) : 0,
			'paid_reservations' => (int) $row->paid_cnt,
			'unpaid_reservations' => $cnt - (int) $row->paid_cnt,
			'rental_days'     => (int) $row->rental_days,
			'distance_km'     => (int) $row->km,
			'active_vehicles' => $fleet,
			'period_days'     => $span,
			'utilization_pct' => round( (int) $row->rental_days / $capacity * 100, 1 ),
		), $req );
	}

	/** 店舗別 */
	public static function handle_by_store( $req ) {
		global $wpdb;
		list( $from, $to ) = self::period( $req );
		list( $where, $params ) = self::where( $from, $to, self::store( $req ) );
		$t = BV_DB::table( 'reservations' );
		$d = self::days_expr();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT store, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue,
			        COALESCE(SUM({$d}),0) rental_days, COALESCE(SUM(trip_distance),0) km
			   FROM {$t}{$where} GROUP BY store ORDER BY revenue DESC", $params
		) );
		$stores = BV_Util::stores();
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'store' => $r->store,
				'store_name' => BV_Util::label( $stores, $r->store ),
				'reservations' => (int) $r->cnt,
				'revenue' => (int) $r->revenue,
				'average_price' => (int) $r->cnt ? (int) round( $r->revenue / $r->cnt ) : 0,
				'rental_days' => (int) $r->rental_days,
				'distance_km' => (int) $r->km,
			);
		}
		return self::ok( $out, $req );
	}

	/** 車両クラス別 */
	public static function handle_by_class( $req ) {
		global $wpdb;
		list( $from, $to ) = self::period( $req );
		list( $where, $params ) = self::where( $from, $to, self::store( $req ) );
		$t = BV_DB::table( 'reservations' );
		$d = self::days_expr();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT vehicle_class, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue,
			        COALESCE(SUM({$d}),0) rental_days
			   FROM {$t}{$where} GROUP BY vehicle_class ORDER BY revenue DESC", $params
		) );
		$classes = BV_Util::classes();
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'class' => $r->vehicle_class,
				'class_name' => BV_Util::label( $classes, $r->vehicle_class ),
				'reservations' => (int) $r->cnt,
				'revenue' => (int) $r->revenue,
				'rental_days' => (int) $r->rental_days,
			);
		}
		return self::ok( $out, $req );
	}

	/** 車両別（売上・稼働・経費・収支） */
	public static function handle_by_vehicle( $req ) {
		global $wpdb;
		list( $from, $to ) = self::period( $req );
		$store = self::store( $req );
		list( $where, $params ) = self::where( $from, $to, $store );
		$t = BV_DB::table( 'reservations' );
		$d = self::days_expr();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT vehicle_id, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue,
			        COALESCE(SUM({$d}),0) rental_days, COALESCE(SUM(trip_distance),0) km
			   FROM {$t}{$where} AND vehicle_id > 0 GROUP BY vehicle_id", $params
		), OBJECT_K );

		$span = max( 1, (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 );
		$months = max( 1, $span / 30.4 );
		$locations = BV_Util::locations();
		$classes   = BV_Util::classes();
		$mt = BV_DB::table( 'maintenance' );

		$out = array();
		foreach ( BV_DB::get_vehicles( array( 'active' => true ) ) as $v ) {
			if ( $store && ! in_array( $v->location, BV_Util::store_locations( $store ), true ) ) continue;
			$r = isset( $rows[ $v->id ] ) ? $rows[ $v->id ] : null;
			$revenue = $r ? (int) $r->revenue : 0;
			$rdays   = $r ? (int) $r->rental_days : 0;
			$cost = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(cost),0) FROM {$mt} WHERE vehicle_id = %d AND mdate BETWEEN %s AND %s",
				$v->id, $from, $to
			) );
			$lease = (int) round( (int) $v->monthly_cost * $months );
			$out[] = array(
				'vehicle_id' => (int) $v->id,
				'name' => $v->name,
				'class' => $v->class,
				'class_name' => BV_Util::label( $classes, $v->class ),
				'location' => $v->location,
				'location_name' => BV_Util::label( $locations, $v->location ),
				'reservations' => $r ? (int) $r->cnt : 0,
				'revenue' => $revenue,
				'rental_days' => $rdays,
				'distance_km' => $r ? (int) $r->km : 0,
				'utilization_pct' => round( $rdays / $span * 100, 1 ),
				'maintenance_cost' => $cost,
				'lease_cost' => $lease,
				'balance' => $revenue - $cost - $lease,
			);
		}
		usort( $out, function ( $a, $b ) { return $b['balance'] - $a['balance']; } );
		return self::ok( $out, $req );
	}

	/** 月次推移 */
	public static function handle_monthly( $req ) {
		global $wpdb;
		list( $from, $to ) = self::period( $req );
		list( $where, $params ) = self::where( $from, $to, self::store( $req ) );
		$t = BV_DB::table( 'reservations' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(pickup_dt,'%%Y-%%m') ym, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue
			   FROM {$t}{$where} GROUP BY ym ORDER BY ym ASC", $params
		) );
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'month' => $r->ym,
				'reservations' => (int) $r->cnt,
				'revenue' => (int) $r->revenue,
				'average_price' => (int) $r->cnt ? (int) round( $r->revenue / $r->cnt ) : 0,
			);
		}
		return self::ok( $out, $req );
	}

	/**
	 * 予約の見出し一覧
	 * 個人情報は出さない：氏名は姓のみ。連絡先・住所・生年月日・免許証は含めない。
	 */
	public static function handle_reservations( $req ) {
		global $wpdb;
		list( $from, $to ) = self::period( $req );
		$store = self::store( $req );

		$where  = ' WHERE pickup_dt >= %s AND pickup_dt <= %s';
		$params = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		if ( $store ) { $where .= ' AND store = %s'; $params[] = $store; }

		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		if ( isset( BV_Util::statuses()[ $status ] ) ) { $where .= ' AND status = %s'; $params[] = $status; }
		else { $where .= " AND status != 'cancelled'"; }

		$limit = (int) $req->get_param( 'limit' );
		$limit = ( $limit > 0 && $limit <= 200 ) ? $limit : 100;

		$t = BV_DB::table( 'reservations' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t}{$where} ORDER BY pickup_dt ASC LIMIT {$limit}", $params
		) );

		$stores = BV_Util::stores(); $classes = BV_Util::classes(); $statuses = BV_Util::statuses();
		$out = array();
		foreach ( $rows as $r ) {
			$v = $r->vehicle_id ? BV_DB::get_vehicle( $r->vehicle_id ) : null;
			$out[] = array(
				'code' => $r->code,
				'status' => $r->status,
				'status_name' => BV_Util::label( $statuses, $r->status ),
				'store' => $r->store,
				'store_name' => BV_Util::label( $stores, $r->store ),
				'pickup_place' => BV_Util::store_place_label( $r->store ),
				'pickup_dt' => $r->pickup_dt,
				'return_dt' => $r->return_dt,
				'vehicle_class' => $r->vehicle_class,
				'vehicle_class_name' => BV_Util::label( $classes, $r->vehicle_class ),
				'vehicle' => $v ? $v->name : null,
				/* 個人の特定を避けるため姓のみ */
				'family_name' => $r->sei,
				'lang' => $r->lang,
				'price_total' => (int) $r->price_total,
				'paid' => (bool) $r->paid_at,
				'paid_amount' => (int) $r->paid_amount,
				'balance' => BV_Util::balance( $r ),
				'shuttle' => $r->shuttle,
				'shuttle_status' => $r->shuttle_status,
			);
		}
		return self::ok( $out, $req, array( 'count' => count( $out ), 'limit' => $limit ) );
	}

	/** 車両台帳（個人情報なし） */
	public static function handle_vehicles( $req ) {
		$store = self::store( $req );
		$locations = BV_Util::locations(); $classes = BV_Util::classes();
		$out = array();
		foreach ( BV_DB::get_vehicles() as $v ) {
			if ( $store && ! in_array( $v->location, BV_Util::store_locations( $store ), true ) ) continue;
			$out[] = array(
				'vehicle_id' => (int) $v->id,
				'name' => $v->name,
				'plate' => $v->plate,
				'class' => $v->class,
				'class_name' => BV_Util::label( $classes, $v->class ),
				'location' => $v->location,
				'location_name' => BV_Util::label( $locations, $v->location ),
				'active' => (bool) $v->active,
				'mileage' => (int) $v->mileage,
				'shaken_date' => $v->shaken_date,          /* 車検満了日 */
				'insurance_date' => $v->insurance_date,    /* 任意保険満了日 */
				'monthly_cost' => (int) $v->monthly_cost,
			);
		}
		return array( 'store' => $store ?: 'all', 'count' => count( $out ), 'data' => $out );
	}
}
