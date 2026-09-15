<?php
/**
 * Plugin Name: BV Booking Form（レンタカー予約フォーム）
 * Description: Be Village中央管理サイトと連携するレンタカー予約フォーム。ショートコード [bv_booking_form lang="ja"] / [bv_booking_form lang="en"] を予約ページに設置してください。
 * Version:     1.13.0
 * Author:      Be Village株式会社
 * Text Domain: bv-booking
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BVBF_VERSION', '1.13.0' );
define( 'BVBF_URL', plugin_dir_url( __FILE__ ) );
define( 'BVBF_DIR', plugin_dir_path( __FILE__ ) );

class BV_Booking_Form {

	public static function init() {
		add_shortcode( 'bv_booking_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function opts() {
		$o = wp_parse_args( get_option( 'bvbf_options', array() ), array(
			'api_url'       => '',   /* 例: https://be-village.com/wp-json/bvrm/v1/ */
			'api_key'       => '',
			'default_store' => 'hakuba',
			'stores'        => array(), /* このサイトで選択できる店舗（複数可） */
		) );
		if ( empty( $o['stores'] ) || ! is_array( $o['stores'] ) ) {
			$o['stores'] = array( $o['default_store'] ); /* 旧設定からの移行 */
		}
		return $o;
	}

	/** 全店舗リスト（中央サイトと同じキー） */
	public static function all_stores() {
		return array(
			'hakuba'         => '白馬レンタカー コルチナ乗鞍店',
			'hakuba_ekimae'  => '白馬レンタカー白馬駅前店',
			'hakuba_fromp'   => '白馬レンタカー From P出張所',
			'omachi'         => '大町レンタカー 信濃大町駅前店',
			'omachi_onsen'   => '大町レンタカー 大町温泉郷店',
			'matsumoto'      => '松本レンタカー島内店',
			'matsumoto_univ' => '松本レンタカー信州大学前店',
		);
	}

	public static function assets() {
		wp_register_script( 'bvbf-form', BVBF_URL . 'assets/form.js', array(), BVBF_VERSION, true );
		wp_register_style( 'bvbf-form', BVBF_URL . 'assets/form.css', array(), BVBF_VERSION );
	}

	/* ---------- ショートコード ---------- */

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'lang' => 'ja', 'store' => '', 'stores' => '' ), $atts );
		$o = self::opts();
		$lang = ( 'en' === $atts['lang'] ) ? 'en' : 'ja';
		if ( ! $o['api_url'] || ! $o['api_key'] ) {
			return '<p>' . ( 'en' === $lang ? 'Booking form is not configured yet.' : '予約フォームが未設定です（管理画面 → 設定 → BV予約フォーム）。' ) . '</p>';
		}

		/* 選択可能な店舗：shortcode属性 > 設定 */
		$allowed = $o['stores'];
		if ( $atts['stores'] ) {
			$allowed = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $atts['stores'] ) ) ) );
		} elseif ( $atts['store'] ) {
			$allowed = array( sanitize_key( $atts['store'] ) );
		}
		$allowed = array_values( array_intersect( $allowed, array_keys( self::all_stores() ) ) );
		if ( ! $allowed ) $allowed = array( $o['default_store'] );

		wp_enqueue_script( 'bvbf-form' );
		wp_enqueue_style( 'bvbf-form' );
		wp_localize_script( 'bvbf-form', 'BVBF', array(
			'proxy'  => esc_url_raw( rest_url( 'bvbf/v1/proxy' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'lang'   => $lang,
			'store'  => $allowed[0],
			'stores' => $allowed,
		) );
		return '<div id="bvbf-app" data-lang="' . esc_attr( $lang ) . '"><noscript>' . ( 'en' === $lang ? 'Please enable JavaScript to book.' : '予約にはJavaScriptを有効にしてください。' ) . '</noscript></div>';
	}

	/* ---------- 中央APIへのプロキシ（APIキーを秘匿） ---------- */

	public static function routes() {
		register_rest_route( 'bvbf/v1', '/proxy', array(
			'methods'  => 'POST',
			'callback' => array( __CLASS__, 'proxy' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function proxy( $req ) {
		$o = self::opts();
		$p = $req->get_json_params();
		$action = sanitize_key( $p['action'] ?? '' );
		$map = array(
			'config'       => array( 'GET',  'config' ),
			'availability' => array( 'POST', 'availability' ),
			'quote'        => array( 'POST', 'quote' ),
			'member_login' => array( 'POST', 'member/login' ),
			'otp_send'     => array( 'POST', 'otp/send' ),
			'otp_verify'   => array( 'POST', 'otp/verify' ),
			'reserve'      => array( 'POST', 'reservations' ),
			'inquiry'      => array( 'POST', 'inquiry' ),
		);
		$lang = ( isset( $p['lang'] ) && 'en' === $p['lang'] ) ? 'en' : 'ja';
		if ( ! isset( $map[ $action ] ) ) {
			return new WP_Error( 'bad_action',
				( 'en' === $lang ) ? 'An error occurred. Please try again later.' : 'エラーが発生しました。時間をおいてお試しください。',
				array( 'status' => 400 ) );
		}

		/* 簡易レート制限 */
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rl_key = 'bvbf_rl_' . md5( $ip );
		$hits = (int) get_transient( $rl_key );
		if ( $hits > 60 ) {
			return new WP_Error( 'rate_limited',
				( 'en' === $lang ) ? 'Too many requests. Please wait a moment and try again.' : 'アクセスが集中しています。しばらく待ってからお試しください。',
				array( 'status' => 429 ) );
		}
		set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

		list( $method, $path ) = $map[ $action ];
		unset( $p['action'] );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array( 'X-BV-Api-Key' => $o['api_key'], 'Content-Type' => 'application/json' ),
		);
		if ( 'POST' === $method ) $args['body'] = wp_json_encode( $p );
		$res = wp_remote_request( trailingslashit( $o['api_url'] ) . $path, $args );
		if ( is_wp_error( $res ) ) return new WP_Error( 'upstream', $res->get_error_message(), array( 'status' => 502 ) );
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return new WP_REST_Response( $body, $code );
	}

	/* ---------- 設定画面 ---------- */

	public static function menu() {
		add_options_page( 'BV予約フォーム', 'BV予約フォーム', 'manage_options', 'bvbf', array( __CLASS__, 'settings_page' ) );
	}

	public static function settings_page() {
		if ( isset( $_POST['bvbf_save'] ) && check_admin_referer( 'bvbf_save' ) ) {
			$sel = isset( $_POST['stores'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['stores'] ) ) : array();
			$sel = array_values( array_intersect( $sel, array_keys( self::all_stores() ) ) );
			if ( ! $sel ) $sel = array( 'hakuba' );
			update_option( 'bvbf_options', array(
				'api_url'       => esc_url_raw( wp_unslash( $_POST['api_url'] ) ),
				'api_key'       => sanitize_text_field( wp_unslash( $_POST['api_key'] ) ),
				'stores'        => $sel,
				'default_store' => $sel[0],
			) );
			echo '<div class="notice notice-success"><p>保存しました。</p></div>';
		}
		$o = self::opts();

		/* 接続テスト */
		$test = '';
		if ( $o['api_url'] && $o['api_key'] ) {
			$res = wp_remote_get( trailingslashit( $o['api_url'] ) . 'config', array(
				'timeout' => 15, 'headers' => array( 'X-BV-Api-Key' => $o['api_key'] ),
			) );
			if ( is_wp_error( $res ) ) {
				$test = '<span style="color:#b32d2e">接続エラー: ' . esc_html( $res->get_error_message() ) . '</span>';
			} elseif ( 200 === wp_remote_retrieve_response_code( $res ) ) {
				$test = '<span style="color:green">✔ 中央サイトに接続できています</span>';
			} else {
				$test = '<span style="color:#b32d2e">応答コード: ' . (int) wp_remote_retrieve_response_code( $res ) . '（APIキーを確認してください）</span>';
			}
		}

		$stores = self::all_stores();
		echo '<div class="wrap"><h1>BV予約フォーム設定</h1><form method="post">';
		wp_nonce_field( 'bvbf_save' );
		echo '<table class="form-table">';
		echo '<tr><th>中央サイトAPI URL</th><td><input type="url" name="api_url" class="large-text" value="' . esc_attr( $o['api_url'] ) . '" placeholder="https://be-village.com/wp-json/bvrm/v1/"></td></tr>';
		echo '<tr><th>APIキー</th><td><input type="text" name="api_key" class="large-text" value="' . esc_attr( $o['api_key'] ) . '"><p class="description">中央サイトの「レンタカー管理 → 設定」に表示されるAPIキー</p></td></tr>';
		echo '<tr><th>このサイトで選べる店舗</th><td>';
		foreach ( $stores as $k => $label ) {
			echo '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="stores[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $o['stores'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">複数選択すると、予約フォームの「店舗」欄でお客様が選べるようになります（1つだけの場合は自動的にその店舗になります）。<br>車両・予約は中央サイトで一元管理されるため、どの店舗を選んでも在庫は共通です。</p></td></tr>';
		if ( $test ) echo '<tr><th>接続状態</th><td>' . $test . '</td></tr>';
		echo '</table>';
		submit_button( '保存', 'primary', 'bvbf_save' );
		echo '</form>';
		echo '<h2>使い方</h2><p>日本語予約ページに <code>[bv_booking_form lang="ja"]</code>、英語予約ページに <code>[bv_booking_form lang="en"]</code> を貼り付けてください。</p>';
		echo '<p class="description">特定のページだけ店舗を限定したい場合は <code>[bv_booking_form lang="ja" store="hakuba_ekimae"]</code>、複数指定は <code>[bv_booking_form lang="ja" stores="hakuba,hakuba_ekimae"]</code> のように指定できます。<br>店舗キー: ';
		$keys = array();
		foreach ( $stores as $k => $label ) $keys[] = '<code>' . esc_html( $k ) . '</code>=' . esc_html( $label );
		echo implode( '、', $keys ) . '</p></div>';
	}
}

BV_Booking_Form::init();
