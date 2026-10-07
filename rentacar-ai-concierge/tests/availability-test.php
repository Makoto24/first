<?php
/**
 * 空車判定アダプターの動作確認スクリプト（開発用。配布 zip には含まれない）。
 * 予約プラグインを模したテーブル・投稿タイプを一時的に作り、判定結果を確認して削除する。
 *
 *   wp eval-file wp-content/plugins/rentacar-ai-concierge/tests/availability-test.php
 *
 * 注意: 実行中は空車連携の設定を書き換え、最後にデモデータに戻します。本番サイトでは実行しないでください。
 */
global $wpdb;
use RCAC\Availability\AvailabilityService;
use RCAC\Settings;

$GLOBALS['rcac_fail'] = 0;
function check( $label, $cond ) { echo ( $cond ? "OK  " : "NG  " ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['rcac_fail']++; } }
function avail_names( $r ) { return array_values( array_map( fn( $v ) => $v['name'], array_filter( $r['vehicles'], fn( $v ) => $v['available'] ) ) ); }
function set( array $kv ) { $o = get_option( 'rcac_settings', array() ); update_option( 'rcac_settings', array_merge( $o, $kv ) ); Settings::flush(); }
$tz = wp_timezone();
$d = fn( $s ) => new DateTimeImmutable( $s, $tz );

// ---------- テーブル型（DATETIME 列）
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}car_vehicles" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}car_bookings" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}car_vehicles (vid int NOT NULL, car_name varchar(100), grade varchar(50), seats varchar(20), active tinyint, PRIMARY KEY (vid))" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}car_bookings (id int NOT NULL, car_id int, customer varchar(100), rent_from datetime, rent_to datetime, state varchar(20), PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}car_vehicles", array( 'vid' => 1, 'car_name' => 'プリウス', 'grade' => 'ハイブリッド', 'seats' => '5人', 'active' => 1 ) );
$wpdb->insert( "{$wpdb->prefix}car_vehicles", array( 'vid' => 2, 'car_name' => 'アクア', 'grade' => 'ハイブリッド', 'seats' => '５人', 'active' => 1 ) );
$wpdb->insert( "{$wpdb->prefix}car_vehicles", array( 'vid' => 3, 'car_name' => '廃車', 'grade' => 'ハイブリッド', 'seats' => '5', 'active' => 0 ) );
$wpdb->insert( "{$wpdb->prefix}car_bookings", array( 'id' => 1, 'car_id' => 1, 'customer' => '山田', 'rent_from' => '2030-05-01 10:00:00', 'rent_to' => '2030-05-03 17:00:00', 'state' => 'confirmed' ) );
$wpdb->insert( "{$wpdb->prefix}car_bookings", array( 'id' => 2, 'car_id' => 2, 'customer' => '佐藤', 'rent_from' => '2030-05-01 10:00:00', 'rent_to' => '2030-05-03 17:00:00', 'state' => 'キャンセル' ) );

set( array(
	'availability_provider' => 'table', 'buffer_minutes' => 60, 'booking_timezone' => 'site',
	'tbl_vehicle_table' => 'car_vehicles', 'tbl_vehicle_id_col' => 'vid', 'tbl_vehicle_name_col' => 'car_name',
	'tbl_vehicle_class_col' => 'grade', 'tbl_vehicle_capacity_col' => 'seats', 'tbl_vehicle_store_col' => '', 'tbl_vehicle_where' => 'active=1',
	'tbl_booking_table' => 'wp_car_bookings', 'tbl_booking_vehicle_col' => 'car_id', 'tbl_booking_start_col' => 'rent_from',
	'tbl_booking_end_col' => 'rent_to', 'tbl_booking_status_col' => 'state',
) );
$v = AvailabilityService::provider()->vehicles();
check( 'table: where 条件で車両を絞り込み (2台)', 2 === count( $v ) );
check( 'table: 全角数字の定員を解釈', 5 === $v[1]['capacity'] );
$r = AvailabilityService::check( $d( '2030-05-02 09:00' ), $d( '2030-05-02 18:00' ) );
check( 'table: 予約中のプリウスは不可、キャンセル済みのアクアは可', array( 'アクア' ) === avail_names( $r ) );
$r = AvailabilityService::check( $d( '2030-05-03 17:30' ), $d( '2030-05-04 10:00' ) );
check( 'table: 返却30分後の貸出は準備時間60分にかかるので不可', array( 'アクア' ) === avail_names( $r ) );
$r = AvailabilityService::check( $d( '2030-05-03 18:00' ), $d( '2030-05-04 10:00' ) );
check( 'table: 返却60分後なら可', array( 'プリウス', 'アクア' ) === avail_names( $r ) );
$r = AvailabilityService::check( $d( '2030-04-30 08:00' ), $d( '2030-05-01 09:30' ) );
check( 'table: 貸出30分前に返却する予約は不可', array( 'アクア' ) === avail_names( $r ) );
$r = AvailabilityService::check( $d( '2030-05-02 09:00' ), $d( '2030-05-02 18:00' ), 'プリウスを借りたい' );
check( 'table: 車種名キーワードで絞り込み', 1 === $r['matched'] );
$r = AvailabilityService::check( $d( '2030-05-02 09:00' ), $d( '2030-05-02 18:00' ), '', 6 );
check( 'table: 定員で絞り込み (6人なら0台)', 0 === $r['matched'] );

// 不正な列名
set( array( 'tbl_booking_start_col' => 'rent_from`; DROP TABLE x; --' ) );
try { AvailabilityService::check( $d( '2030-05-02 09:00' ), $d( '2030-05-02 18:00' ) ); check( 'table: 不正な列名を拒否', false ); }
catch ( RCAC\Availability\ConfigException $e ) { check( 'table: 不正な列名を拒否 (' . $e->getMessage() . ')', true ); }

// ---------- テーブル型（UNIX タイムスタンプ列・日付だけの列）
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}car_bookings2" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}car_bookings2 (id int NOT NULL, car varchar(50), s bigint, e bigint, PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}car_bookings2", array( 'id' => 1, 'car' => 'アクア', 's' => $d( '2030-06-01 10:00' )->getTimestamp(), 'e' => $d( '2030-06-02 10:00' )->getTimestamp() ) );
set( array( 'tbl_booking_table' => 'car_bookings2', 'tbl_booking_vehicle_col' => 'car', 'tbl_booking_start_col' => 's', 'tbl_booking_end_col' => 'e', 'tbl_booking_status_col' => '' ) );
$r = AvailabilityService::check( $d( '2030-06-01 12:00' ), $d( '2030-06-01 15:00' ) );
check( 'table(unix): 車両名で紐づく予約を判定', array( 'プリウス' ) === avail_names( $r ) );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}car_bookings3" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}car_bookings3 (id int NOT NULL, car int, s date, e date, PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}car_bookings3", array( 'id' => 1, 'car' => 1, 's' => '2030-07-01', 'e' => '2030-07-02' ) );
set( array( 'tbl_booking_table' => 'car_bookings3', 'tbl_booking_vehicle_col' => 'car', 'tbl_booking_start_col' => 's', 'tbl_booking_end_col' => 'e' ) );
$r = AvailabilityService::check( $d( '2030-07-02 15:00' ), $d( '2030-07-02 18:00' ) );
check( 'table(date): 日付だけの返却日はその日いっぱい使用中', array( 'アクア' ) === avail_names( $r ) );

// ---------- 投稿タイプ＋カスタムフィールド型
register_post_type( 'car', array( 'public' => false ) );
register_post_type( 'car_booking', array( 'public' => false ) );
register_taxonomy( 'car_class', 'car' );
$c1 = wp_insert_post( array( 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'セレナ' ) );
$c2 = wp_insert_post( array( 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'ステップワゴン' ) );
wp_set_object_terms( $c1, array( 'ミニバン' ), 'car_class' );
wp_set_object_terms( $c2, array( 'ミニバン' ), 'car_class' );
update_post_meta( $c1, 'seats', '8' ); update_post_meta( $c2, 'seats', '8' );
$b1 = wp_insert_post( array( 'post_type' => 'car_booking', 'post_status' => 'publish', 'post_title' => '予約1' ) );
update_post_meta( $b1, '_car', (string) $c1 ); update_post_meta( $b1, '_from', '2030-08-10 09:00' ); update_post_meta( $b1, '_to', '2030-08-12 18:00' );
$b2 = wp_insert_post( array( 'post_type' => 'car_booking', 'post_status' => 'trash', 'post_title' => '予約2' ) );
update_post_meta( $b2, '_car', (string) $c2 ); update_post_meta( $b2, '_from', '2030-08-10 09:00' ); update_post_meta( $b2, '_to', '2030-08-12 18:00' );
set( array(
	'availability_provider' => 'postmeta', 'pm_vehicle_post_type' => 'car', 'pm_vehicle_class_meta' => 'tax:car_class', 'pm_vehicle_capacity_meta' => 'seats',
	'pm_booking_post_type' => 'car_booking', 'pm_booking_vehicle_meta' => '_car', 'pm_booking_start_meta' => '_from', 'pm_booking_end_meta' => '_to', 'pm_booking_status_meta' => '',
) );
$r = AvailabilityService::check( $d( '2030-08-11 10:00' ), $d( '2030-08-11 18:00' ), 'ミニバン', 7 );
check( 'postmeta: タクソノミーのクラス・定員で絞り込み (2台)', 2 === $r['matched'] );
check( 'postmeta: 予約中のセレナは不可、ゴミ箱の予約は無視', array( 'ステップワゴン' ) === avail_names( $r ) );
$t = AvailabilityService::timeline( $d( '2030-08-09' ), 5 );
$row = array_values( array_filter( $t['rows'], fn( $r ) => 'セレナ' === $r['vehicle']['name'] ) )[0];
check( 'postmeta: 簡易ガントチャート (8/10〜8/12 が予約あり)', array( false, true, true, true, false ) === $row['cells'] );

// ---------- デモ
set( array( 'availability_provider' => 'demo' ) );
check( 'demo: 7台', 7 === count( AvailabilityService::provider()->vehicles() ) );

// 後始末
foreach ( array( 'car_vehicles', 'car_bookings', 'car_bookings2', 'car_bookings3' ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); }
foreach ( array( $c1, $c2, $b1, $b2 ) as $id ) { wp_delete_post( $id, true ); }
echo $GLOBALS['rcac_fail'] ? "\nFAILED: " . $GLOBALS['rcac_fail'] . "\n" : "\nALL PASSED\n";
