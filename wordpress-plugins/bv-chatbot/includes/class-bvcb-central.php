<?php
/**
 * 中央サイト（予約管理）との通信
 * 店舗情報・料金表は /config、空き状況は /availability/summary（ガントチャートと同じ判定）。
 * どちらも読み取り専用で、予約やお客様の情報は扱わない。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BVCB_Central {

	const CONFIG_CACHE = 'bvcb_central_config';

	protected static function request( $method, $path, $body = null ) {
		$o = BVCB_Settings::get();
		if ( '' === $o['api_url'] || '' === $o['api_key'] ) {
			return new WP_Error( 'no_central', '中央サイトの接続先が設定されていません。' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array( 'X-BV-Api-Key' => $o['api_key'], 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) $args['body'] = wp_json_encode( $body );
		$res = wp_remote_request( trailingslashit( $o['api_url'] ) . $path, $args );
		if ( is_wp_error( $res ) ) return new WP_Error( 'central_down', '中央サイトに接続できませんでした。' );
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 404 === $code && 'availability/summary' === $path ) {
			return new WP_Error( 'central_old', '中央サイトのプラグインを 1.37.0 以上に更新してください（空車確認に必要です）。' );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && ! empty( $json['message'] ) ? (string) $json['message'] : '中央サイトからエラーが返りました（' . $code . '）。';
			return new WP_Error( 'central_error', $msg, array( 'status' => $code ) );
		}
		return is_array( $json ) ? $json : new WP_Error( 'central_bad', '中央サイトの応答を読み取れませんでした。' );
	}

	/** 店舗・クラス・料金などの設定（10分キャッシュ） */
	public static function config( $force = false ) {
		if ( ! $force ) {
			$c = get_transient( self::CONFIG_CACHE );
			if ( is_array( $c ) ) return $c;
		}
		$c = self::request( 'GET', 'config' );
		if ( is_wp_error( $c ) ) return $c;
		unset( $c['now'] ); /* 毎回変わる値は持たない（指示文のキャッシュが効かなくなるため） */
		set_transient( self::CONFIG_CACHE, $c, 10 * MINUTE_IN_SECONDS );
		return $c;
	}

	/** このサイトで案内する店舗（設定がなければ中央の全店舗） */
	public static function stores( $config ) {
		$all = isset( $config['stores'] ) && is_array( $config['stores'] ) ? $config['stores'] : array();
		$want = BVCB_Settings::get()['stores'];
		if ( ! $want ) return $all;
		$out = array();
		foreach ( $want as $k ) if ( isset( $all[ $k ] ) ) $out[ $k ] = $all[ $k ];
		return $out ?: $all;
	}

	/** 空き状況（店舗の全クラス、またはクラス指定） */
	public static function availability( $store, $pickup, $return, $class = '', $lang = 'ja' ) {
		return self::request( 'POST', 'availability/summary', array(
			'store'         => $store,
			'pickup_dt'     => $pickup,
			'return_dt'     => $return,
			'vehicle_class' => $class,
			'lang'          => ( 'en' === $lang ) ? 'en' : 'ja',
		) );
	}
}
