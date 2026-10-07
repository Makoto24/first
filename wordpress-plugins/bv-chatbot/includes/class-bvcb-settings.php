<?php
/**
 * 設定値
 * Claude APIキーはこのサイトのデータベースにだけ保存し、ブラウザへは一切出さない。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BVCB_Settings {

	const OPT = 'bvcb_options';

	/** 選べるモデル（既定は最上位の汎用モデル） */
	public static function models() {
		return array(
			'claude-opus-5-5'   => 'Claude Opus 5.5（既定・いちばん賢い）',
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5（速い・費用はOpusの約半分）',
			'claude-haiku-4-5'  => 'Claude Haiku 4.5（最も安い・簡単な質問向け）',
		);
	}

	/** 考える深さ（低いほど速く安い。チャットは「低」で十分なことが多い） */
	public static function efforts() {
		return array(
			'low'    => '低（速い・おすすめ）',
			'medium' => '中',
			'high'   => '高（遅いが丁寧）',
		);
	}

	public static function defaults() {
		return array(
			'enabled'        => 0,
			'claude_api_key' => '',
			'model'          => 'claude-opus-5-5',
			'effort'         => 'low',
			/* 中央サイト（予約管理）への接続。予約フォームと同じURL・キー */
			'api_url'        => '',
			'api_key'        => '',
			'stores'         => array(),
			'booking_url_ja' => '',
			'booking_url_en' => '',
			'contact_ja'     => '',
			'contact_en'     => '',
			'bot_name'       => 'レンタカー案内チャット',
			'welcome_ja'     => "こんにちは。レンタカーについてのご質問にお答えします。\n空車確認もできます（例：「12月20日10時から22日18時まで、軽自動車は空いていますか？」）。",
			'welcome_en'     => "Hello! Ask me anything about our rental cars.\nI can also check availability (e.g. \"Is a compact car available from Dec 20, 10:00 to Dec 22, 18:00?\").",
			'floating'       => 1,   /* 全ページの右下に表示する */
			'rate_ip'        => 20,  /* 1つの接続元から10分間に送れる質問の数 */
			'daily_limit'    => 500, /* 1日あたりのClaude API呼び出しの上限（費用の歯止め） */
			'log_enabled'    => 1,
			'log_days'       => 30,
		);
	}

	public static function get() {
		$o = get_option( self::OPT, array() );
		$o = is_array( $o ) ? array_merge( self::defaults(), $o ) : self::defaults();

		/* 予約フォームプラグインが同じサイトにあれば、中央サイトの接続先を引き継ぐ */
		if ( ( '' === $o['api_url'] || '' === $o['api_key'] ) ) {
			$bf = get_option( 'bvbf_options', array() );
			if ( is_array( $bf ) ) {
				if ( '' === $o['api_url'] && ! empty( $bf['api_url'] ) ) $o['api_url'] = (string) $bf['api_url'];
				if ( '' === $o['api_key'] && ! empty( $bf['api_key'] ) ) $o['api_key'] = (string) $bf['api_key'];
				if ( empty( $o['stores'] ) && ! empty( $bf['stores'] ) && is_array( $bf['stores'] ) ) $o['stores'] = $bf['stores'];
			}
		}
		if ( ! isset( self::models()[ $o['model'] ] ) ) $o['model'] = 'claude-opus-5-5';
		if ( ! isset( self::efforts()[ $o['effort'] ] ) ) $o['effort'] = 'low';
		if ( ! is_array( $o['stores'] ) ) $o['stores'] = array();
		return $o;
	}

	public static function save( $new ) {
		update_option( self::OPT, $new, false );
	}

	/** チャットを動かせる状態か */
	public static function ready() {
		$o = self::get();
		return ! empty( $o['enabled'] ) && '' !== $o['claude_api_key'];
	}
}
