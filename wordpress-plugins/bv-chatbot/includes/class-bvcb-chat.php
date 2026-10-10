<?php
/**
 * チャットの受け口（REST API）と画面への表示
 *
 *   POST /wp-json/bvcb/v1/session  … 会話を始める（署名付きの会話IDを返す）
 *   POST /wp-json/bvcb/v1/chat     … 質問を送って回答を受け取る
 *
 * 会話の履歴はサーバー側（一時データ）に持ち、ブラウザからは会話IDと新しい質問だけを受け取る。
 * ブラウザ側で過去のやり取りを書き換えて送り込むことはできない。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BVCB_Chat {

	const NS = 'bvcb/v1';
	const MAX_LEN = 1000;      /* 1回の質問の文字数 */
	const MAX_TURNS = 20;      /* 1つの会話で送れる質問の数 */
	const SESSION_TTL = 7200;  /* 会話を覚えておく時間（秒） */

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( 'bv_chatbot', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'floating' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, '/session', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_session' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/chat', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_chat' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* ---------- 会話ID（署名付き） ---------- */

	protected static function sign( $sid, $exp ) {
		return substr( hash_hmac( 'sha256', $sid . '|' . $exp, wp_salt( 'auth' ) . 'bvcb' ), 0, 32 );
	}

	public static function new_token() {
		$sid = wp_generate_password( 24, false );
		$exp = time() + self::SESSION_TTL;
		return $sid . '.' . $exp . '.' . self::sign( $sid, $exp );
	}

	/** 正しい会話IDなら sid を返す */
	public static function verify_token( $token ) {
		$p = explode( '.', (string) $token );
		if ( 3 !== count( $p ) || ! preg_match( '/^[A-Za-z0-9]{24}$/', $p[0] ) || ! ctype_digit( $p[1] ) ) return false;
		if ( (int) $p[1] < time() ) return false;
		if ( ! hash_equals( self::sign( $p[0], (int) $p[1] ), $p[2] ) ) return false;
		return $p[0];
	}

	/* ---------- 不正利用対策 ---------- */

	/** 他のサイトから直接呼ばれていないか（ブラウザは別サイトからの送信に Origin を付ける） */
	protected static function origin_ok( $req ) {
		$origin = (string) $req->get_header( 'origin' );
		if ( '' === $origin ) return true;
		$home = wp_parse_url( home_url() );
		$o = wp_parse_url( $origin );
		return isset( $o['host'], $home['host'] ) && strtolower( $o['host'] ) === strtolower( $home['host'] );
	}

	/** 接続元ごとの回数制限（10分間）。IPそのものは保存しない */
	protected static function rate_ok() {
		$limit = (int) BVCB_Settings::get()['rate_ip'];
		if ( $limit < 1 ) return true;
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'bvcb_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
		$n = (int) get_transient( $key );
		if ( $n >= $limit ) return false;
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	protected static function lang( $req ) {
		return ( 'en' === $req->get_param( 'lang' ) ) ? 'en' : 'ja';
	}

	protected static function err( $code, $msg_ja, $msg_en, $lang, $status = 400 ) {
		return new WP_Error( $code, 'en' === $lang ? $msg_en : $msg_ja, array( 'status' => $status ) );
	}

	/* ---------- REST ---------- */

	public static function rest_session( $req ) {
		$lang = self::lang( $req );
		if ( ! BVCB_Settings::ready() ) return self::err( 'disabled', '現在チャットはご利用いただけません。', 'The chat is currently unavailable.', $lang, 503 );
		if ( ! self::origin_ok( $req ) ) return self::err( 'origin', '許可されていない送信元です。', 'Forbidden.', $lang, 403 );
		$o = BVCB_Settings::get();
		return array(
			'token'   => self::new_token(),
			'welcome' => 'en' === $lang ? $o['welcome_en'] : $o['welcome_ja'],
		);
	}

	public static function rest_chat( $req ) {
		$lang = self::lang( $req );
		if ( ! BVCB_Settings::ready() ) return self::err( 'disabled', '現在チャットはご利用いただけません。', 'The chat is currently unavailable.', $lang, 503 );
		if ( ! self::origin_ok( $req ) ) return self::err( 'origin', '許可されていない送信元です。', 'Forbidden.', $lang, 403 );

		$sid = self::verify_token( $req->get_param( 'token' ) );
		if ( ! $sid ) return self::err( 'session', '会話の有効期限が切れました。もう一度お試しください。', 'Your chat session has expired. Please try again.', $lang, 401 );

		/* 免許証番号・カード番号などは、AIにも記録にも渡さない */
		$q = BVCB_Settings::redact_sensitive( self::clean( (string) $req->get_param( 'message' ) ) );
		if ( '' === $q ) return self::err( 'empty', 'ご質問を入力してください。', 'Please type your question.', $lang );
		if ( self::len( $q ) > self::MAX_LEN ) {
			return self::err( 'too_long', 'ご質問は' . self::MAX_LEN . '文字以内でお願いします。', 'Please keep your question under ' . self::MAX_LEN . ' characters.', $lang );
		}
		if ( ! self::rate_ok() ) {
			return self::err( 'rate', '短時間に多くのご質問をいただいたため、少し時間をおいてお試しください。', 'Too many messages. Please wait a few minutes and try again.', $lang, 429 );
		}

		$key = 'bvcb_conv_' . $sid;
		$hist = get_transient( $key );
		$hist = is_array( $hist ) ? $hist : array();
		if ( count( $hist ) >= self::MAX_TURNS * 2 ) {
			return self::err( 'turns', '会話が長くなったため、ここで一区切りとさせてください。「新しい会話」からもう一度お尋ねください。',
				'This conversation has become long. Please start a new chat.', $lang, 400 );
		}

		/* 同じ会話で同時に送られた場合の二重処理を避ける */
		$lock = 'bvcb_lock_' . $sid;
		if ( get_transient( $lock ) ) return self::err( 'busy', 'ただいま回答を作成中です。少しお待ちください。', 'Still answering your previous message.', $lang, 409 );
		set_transient( $lock, 1, 90 );
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 180 );

		BVCB_Log::add( $sid, 'user', $q, $lang );
		$res = BVCB_Claude::answer( $hist, $q, $lang );
		delete_transient( $lock );

		foreach ( $res['tools'] as $t ) {
			BVCB_Log::add( $sid, 'tool', wp_json_encode( $t['input'], JSON_UNESCAPED_UNICODE ) . "\n→ " . $t['result'], $lang );
		}
		if ( ! empty( $res['error'] ) ) BVCB_Log::add( $sid, 'error', $res['error'], $lang );

		if ( $res['ok'] ) {
			BVCB_Log::add( $sid, 'bot', $res['reply'], $lang );
			$hist[] = array( 'role' => 'user', 'text' => $q );
			$hist[] = array( 'role' => 'assistant', 'text' => $res['reply'] );
			set_transient( $key, $hist, self::SESSION_TTL );
		}
		/* エラーの詳細（APIキーの不備など）はお客様には見せない */
		return array( 'reply' => $res['reply'], 'ok' => (bool) $res['ok'] );
	}

	public static function clean( $s ) {
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		return trim( (string) $s );
	}

	protected static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	/* ---------- 画面への表示 ---------- */

	public static function assets() {
		wp_register_script( 'bvcb-chat', BVCB_URL . 'assets/chat.js', array(), BVCB_VERSION, true );
		wp_register_style( 'bvcb-chat', BVCB_URL . 'assets/chat.css', array(), BVCB_VERSION );
	}

	protected static function enqueue( $lang ) {
		static $done = false;
		if ( $done ) return;
		$done = true;
		$o = BVCB_Settings::get();
		wp_enqueue_style( 'bvcb-chat' );
		wp_enqueue_script( 'bvcb-chat' );
		wp_localize_script( 'bvcb-chat', 'BVCB', array(
			'api'     => esc_url_raw( rest_url( self::NS . '/' ) ),
			'lang'    => $lang,
			'botName' => $o['bot_name'],
		) );
	}

	protected static function page_lang( $atts_lang = '' ) {
		if ( in_array( $atts_lang, array( 'ja', 'en' ), true ) ) return $atts_lang;
		return ( 0 === strpos( strtolower( (string) get_locale() ), 'en' ) ) ? 'en' : 'ja';
	}

	/** ショートコード [bv_chatbot lang="ja"]：ページ内に埋め込む */
	public static function shortcode( $atts ) {
		if ( ! BVCB_Settings::ready() ) return '';
		$atts = shortcode_atts( array( 'lang' => '' ), $atts );
		$lang = self::page_lang( $atts['lang'] );
		self::enqueue( $lang );
		return '<div class="bvcb-inline" data-bvcb="inline" data-lang="' . esc_attr( $lang ) . '"' . ( '' !== $atts['lang'] ? ' data-lang-fixed="1"' : '' ) . '></div>';
	}

	/** 画面右下のボタン（設定で表示する場合） */
	public static function floating() {
		if ( is_admin() || ! BVCB_Settings::ready() || empty( BVCB_Settings::get()['floating'] ) ) return;
		$lang = self::page_lang();
		self::enqueue( $lang );
		/* wp_footer の時点で登録したスクリプトはフッターで出力される */
		echo '<div data-bvcb="floating" data-lang="' . esc_attr( $lang ) . '"></div>';
	}
}
