<?php
/**
 * Q&A集（ナレッジ）
 * 管理画面で入力・アップロードした文章を、Claudeへの指示に含めて回答の根拠にする。
 * 文章は毎回の問い合わせで送るため、長すぎると費用と応答時間が増える（上限あり）。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BVCB_Knowledge {

	const OPT = 'bvcb_knowledge';
	/** 文字数の上限（日本語はおよそ1文字＝1トークン前後） */
	const MAX_CHARS = 60000;
	/** アップロードできるファイル */
	const EXTS = array( 'txt', 'md', 'csv' );
	const MAX_BYTES = 1048576;

	public static function get() {
		return (string) get_option( self::OPT, '' );
	}

	public static function save( $text ) {
		$text = self::normalize( $text );
		if ( self::length( $text ) > self::MAX_CHARS ) {
			return new WP_Error( 'too_long', 'Q&A集が長すぎます（' . number_format( self::length( $text ) ) . '文字）。'
				. number_format( self::MAX_CHARS ) . '文字以内にしてください。' );
		}
		update_option( self::OPT, $text, false ); /* 自動読み込みしない（チャットのときだけ使う） */
		return true;
	}

	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/** 改行の統一・制御文字の除去 */
	public static function normalize( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		return trim( (string) $text );
	}

	/**
	 * アップロードされたファイルを文章にする
	 * Excelで保存したCSV（Shift_JIS）も読めるようにする。
	 * 2列のCSVは「Q：…／A：…」の形に整える。
	 */
	public static function from_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'ファイルを選んでください。' );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'too_big', 'ファイルが大きすぎます（1MBまで）。' );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::EXTS, true ) ) {
			return new WP_Error( 'bad_ext', 'アップロードできるのは .txt / .md / .csv です。Wordやエクセルの場合は「テキスト」または「CSV」で保存し直してください。' );
		}
		$raw = file_get_contents( $file['tmp_name'] );
		if ( false === $raw ) return new WP_Error( 'read', 'ファイルを読み込めませんでした。' );
		return self::parse( $raw, $ext );
	}

	public static function parse( $raw, $ext ) {
		/* 文字コード：UTF-8（BOM付き含む）以外は Shift_JIS とみなす */
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) $raw = substr( $raw, 3 );
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'SJIS-win' );
		}
		if ( 'csv' !== $ext ) return self::normalize( $raw );

		$out = array();
		$fh = fopen( 'php://memory', 'r+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		$first = true;
		while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) {
			$row = array_map( 'trim', array_map( 'strval', $row ) );
			if ( ! array_filter( $row, 'strlen' ) ) continue;
			/* 見出し行（質問,回答 / Q,A など）は飛ばす */
			if ( $first ) {
				$first = false;
				if ( preg_match( '/^(質問|問|q|question)$/iu', $row[0] ) ) continue;
			}
			if ( count( $row ) >= 2 && '' !== $row[0] && '' !== $row[1] ) {
				$extra = array_filter( array_slice( $row, 2 ), 'strlen' );
				$out[] = 'Q：' . $row[0] . "\nA：" . $row[1] . ( $extra ? "\n（" . implode( '／', $extra ) . '）' : '' );
			} else {
				$out[] = implode( ' ', array_filter( $row, 'strlen' ) );
			}
		}
		fclose( $fh );
		return self::normalize( implode( "\n\n", $out ) );
	}
}
