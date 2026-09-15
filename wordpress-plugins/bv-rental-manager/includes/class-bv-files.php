<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 免許証・パスポート等の本人確認書類ファイルの保管と配信
 *
 * これらの画像は wp-content/uploads の公開領域に置くと、URLを知っていれば
 * 誰でも開ける状態になる。そのため
 *   - 保存先を uploads/bv-private/ （.htaccess で直接アクセスを禁止）に変える
 *   - DBにはURLではなく「キー」だけを持つ
 *   - 閲覧は署名付き・期限付きのリンク経由に限り、閲覧者の権限も配信時に確認する
 * という形にしている。
 *
 * キーの形式： bvpriv:2026/09/bv-license-front-XXXXXXXXXXXXXXXX.jpg
 */
class BV_Files {

	const PREFIX  = 'bvpriv:';
	const DIRNAME = 'bv-private';

	/** 署名付きリンクの既定有効期間（秒） */
	const TTL_ADMIN    = 7200;  /* 管理画面・スタッフポータル：2時間 */
	const TTL_CUSTOMER = 7200;  /* お客様の予約確認ページ：2時間 */

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
	}

	/* ---------- 保管場所 ---------- */

	/** 非公開ディレクトリの絶対パス（末尾スラッシュなし） */
	public static function base_dir() {
		$up = wp_upload_dir();
		return untrailingslashit( $up['basedir'] ) . '/' . self::DIRNAME;
	}

	/**
	 * 非公開ディレクトリを用意し、直接アクセスを禁止する設定ファイルを置く
	 * @return string|WP_Error ディレクトリの絶対パス
	 */
	public static function ensure_dir( $sub = '' ) {
		$base = self::base_dir();
		if ( ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'mkdir_failed', '保存先ディレクトリを作成できませんでした。' );
		}

		/* Apache（エックスサーバー等）向け */
		$ht = $base . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			$rules = "# BV Rental Manager: 本人確認書類の直接アクセスを禁止\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
				. "<IfModule mod_php.c>\n\tphp_flag engine off\n</IfModule>\n";
			file_put_contents( $ht, $rules );
		}
		/* IIS向け */
		$wc = $base . '/web.config';
		if ( ! file_exists( $wc ) ) {
			file_put_contents( $wc, "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security><authorization>\n<deny users=\"*\" />\n</authorization></security></system.webServer></configuration>\n" );
		}
		/* ディレクトリ一覧の抑止 */
		if ( ! file_exists( $base . '/index.html' ) ) file_put_contents( $base . '/index.html', '' );

		if ( '' === $sub ) return $base;

		$dir = $base . '/' . trim( $sub, '/' );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mkdir_failed', '保存先ディレクトリを作成できませんでした。' );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) file_put_contents( $dir . '/index.html', '' );
		return $dir;
	}

	/** .htaccess 等が現存するか（設定画面の自己診断用） */
	public static function dir_protected() {
		$base = self::base_dir();
		return is_dir( $base ) && file_exists( $base . '/.htaccess' );
	}

	/* ---------- キーの取り扱い ---------- */

	public static function is_key( $v ) {
		return is_string( $v ) && 0 === strpos( $v, self::PREFIX );
	}

	/** キー → 絶対パス。非公開ディレクトリの外を指すキーは拒否する */
	public static function path( $key ) {
		if ( ! self::is_key( $key ) ) return '';
		$rel = substr( $key, strlen( self::PREFIX ) );
		/* ディレクトリ遡行・NULLバイト等を弾く */
		if ( '' === $rel || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) ) return '';
		if ( ! preg_match( '#^[0-9]{4}/[0-9]{2}/[A-Za-z0-9._-]+$#', $rel ) ) return '';

		$base = self::base_dir();
		$path = $base . '/' . $rel;
		if ( ! file_exists( $path ) ) return '';

		/* realpath で最終確認（シンボリックリンク対策） */
		$real_base = realpath( $base );
		$real_path = realpath( $path );
		if ( ! $real_base || ! $real_path ) return '';
		if ( 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) return '';
		return $real_path;
	}

	public static function allowed_ext() {
		return array( 'jpg', 'jpeg', 'png', 'webp', 'pdf' );
	}

	protected static function mime_for( $ext ) {
		$map = array(
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
			'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
		);
		$ext = strtolower( $ext );
		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';
	}

	/* ---------- 保存 ---------- */

	/** 新しいファイル名（推測されにくく、用途も名前から分からないようにする） */
	protected static function new_name( $ext ) {
		return 'bvdoc-' . wp_generate_password( 24, false, false ) . '.' . strtolower( $ext );
	}

	/**
	 * バイト列を非公開領域に保存する
	 * @return string|WP_Error キー
	 */
	public static function save_bytes( $data, $ext ) {
		$ext = strtolower( (string) $ext );
		if ( ! in_array( $ext, self::allowed_ext(), true ) ) {
			return new WP_Error( 'bad_type', '対応していないファイル形式です。' );
		}
		$sub = gmdate( 'Y/m', current_time( 'timestamp' ) );
		$dir = self::ensure_dir( $sub );
		if ( is_wp_error( $dir ) ) return $dir;

		$name = self::new_name( $ext );
		$path = $dir . '/' . $name;
		if ( false === file_put_contents( $path, $data ) ) {
			return new WP_Error( 'write_failed', 'ファイルを保存できませんでした。' );
		}
		@chmod( $path, 0600 );
		return self::PREFIX . $sub . '/' . $name;
	}

	/**
	 * $_FILES の1件を検証して非公開領域に保存する
	 * @return string|WP_Error キー
	 */
	public static function save_upload( $file, $max_bytes = 10485760 ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_failed', 'アップロードに失敗しました。' );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_failed', 'アップロードに失敗しました。' );
		}
		if ( (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'too_big', 'ファイルが大きすぎます。' );
		}
		/* 拡張子と中身の両方を確認する */
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$ext   = strtolower( (string) ( $check['ext'] ?: '' ) );
		if ( ! $ext ) {
			$t = wp_check_filetype( $file['name'] );
			$ext = strtolower( (string) $t['ext'] );
		}
		if ( ! in_array( $ext, self::allowed_ext(), true ) ) {
			return new WP_Error( 'bad_type', '対応していないファイル形式です。' );
		}
		$data = file_get_contents( $file['tmp_name'] );
		if ( false === $data ) return new WP_Error( 'upload_failed', 'アップロードに失敗しました。' );
		return self::save_bytes( $data, $ext );
	}

	/** キーで示されたファイルを削除する */
	public static function delete( $key ) {
		$path = self::path( $key );
		if ( $path ) @unlink( $path );
	}

	/* ---------- 署名付きリンク ---------- */

	protected static function sign( $key, $aud, $exp ) {
		return hash_hmac( 'sha256', $key . '|' . $aud . '|' . $exp, wp_salt( 'auth' ) );
	}

	/**
	 * 閲覧用リンクを作る
	 * @param string $key   保存キー（旧形式の公開URLはそのまま返す）
	 * @param string $aud   adm=管理者 / staff=スタッフポータル / cust=お客様
	 */
	public static function url( $key, $aud = 'adm', $ttl = 0 ) {
		if ( ! self::is_key( $key ) ) return (string) $key; /* 移行前の公開URL */
		if ( ! in_array( $aud, array( 'adm', 'staff', 'cust' ), true ) ) $aud = 'adm';
		if ( $ttl < 1 ) $ttl = ( 'cust' === $aud ) ? self::TTL_CUSTOMER : self::TTL_ADMIN;
		$exp = time() + (int) $ttl;
		return add_query_arg( array(
			'bv_doc' => $key, /* add_query_arg 側でエンコードされるため、ここでは行わない */
			'a'      => $aud,
			'e'      => $exp,
			's'      => self::sign( $key, $aud, $exp ),
		), home_url( '/' ) );
	}

	/** 画像として表示できる形式か */
	public static function is_image( $key ) {
		$name = self::is_key( $key ) ? $key : (string) $key;
		return (bool) preg_match( '/\.(jpe?g|png|webp)$/i', $name );
	}

	/* ---------- 配信 ---------- */

	public static function serve() {
		if ( ! isset( $_GET['bv_doc'] ) ) return;

		$key = wp_unslash( (string) $_GET['bv_doc'] );
		$aud = isset( $_GET['a'] ) ? sanitize_key( wp_unslash( $_GET['a'] ) ) : '';
		$exp = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0;
		$sig = isset( $_GET['s'] ) ? (string) wp_unslash( $_GET['s'] ) : '';

		if ( ! $exp || $exp < time() ) self::deny( 410, 'リンクの有効期限が切れています。画面を再読み込みしてください。' );
		if ( ! hash_equals( self::sign( $key, $aud, $exp ), $sig ) ) self::deny( 403, 'リンクが正しくありません。' );

		/* 署名が正しくても、閲覧者の権限を配信時にもう一度確認する */
		if ( 'adm' === $aud && ! current_user_can( 'manage_options' ) ) {
			self::deny( 403, '管理者としてログインしてください。' );
		}
		if ( 'staff' === $aud && ! BV_Staff_Portal::any_authed() ) {
			self::deny( 403, 'スタッフポータルにログインしてください。' );
		}
		/* cust：お客様本人はログイン状態を持たないため、短期間の署名のみで判定する */

		$path = self::path( $key );
		if ( ! $path ) self::deny( 404, 'ファイルが見つかりません。' );

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		nocache_headers();
		header( 'Content-Type: ' . self::mime_for( $ext ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="document.' . $ext . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
		readfile( $path );
		exit;
	}

	protected static function deny( $code, $msg ) {
		status_header( (int) $code );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><meta charset="utf-8"><title>' . (int) $code . '</title>'
			. '<p style="font-family:sans-serif;padding:24px">' . esc_html( $msg ) . '</p>';
		exit;
	}

	/* ---------- 既存ファイルの移行 ---------- */

	/**
	 * uploads 直下に置かれている既存の免許証画像を非公開領域へ移動し、
	 * DBのURLをキーに置き換える。
	 * @return array 結果サマリ
	 */
	public static function migrate_legacy( $limit = 200 ) {
		global $wpdb;
		$t   = BV_DB::table( 'reservations' );
		$up  = wp_upload_dir();
		$out = array( 'scanned' => 0, 'moved' => 0, 'missing' => 0, 'failed' => 0, 'remaining' => 0 );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, license_files FROM {$t} WHERE license_files != '' AND license_files != '[]' AND license_files LIKE %s ORDER BY id DESC",
			'%' . $wpdb->esc_like( 'http' ) . '%'
		) );
		if ( ! $rows ) return $out;

		/* 同じURLが複数予約で共有されているため、移行済みURLを覚えておく */
		$done = array();

		foreach ( $rows as $row ) {
			$files = json_decode( (string) $row->license_files, true );
			if ( ! is_array( $files ) || ! $files ) continue;
			$changed = false;

			foreach ( $files as $k => $u ) {
				if ( ! is_string( $u ) || '' === $u || self::is_key( $u ) ) continue;
				$out['scanned']++;

				if ( isset( $done[ $u ] ) ) {
					$files[ $k ] = $done[ $u ];
					$changed = true;
					continue;
				}
				if ( $out['moved'] >= $limit ) { $out['remaining']++; continue; }

				/* URL → サーバー上のパス */
				if ( 0 !== strpos( $u, $up['baseurl'] ) ) { $out['failed']++; continue; }
				$src = untrailingslashit( $up['basedir'] ) . substr( $u, strlen( untrailingslashit( $up['baseurl'] ) ) );
				$src = explode( '?', $src )[0];
				if ( ! file_exists( $src ) ) { $out['missing']++; continue; }

				$ext  = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );
				$data = file_get_contents( $src );
				if ( false === $data ) { $out['failed']++; continue; }

				$key = self::save_bytes( $data, $ext );
				if ( is_wp_error( $key ) ) { $out['failed']++; continue; }

				@unlink( $src );
				$done[ $u ]  = $key;
				$files[ $k ] = $key;
				$changed = true;
				$out['moved']++;
			}

			if ( $changed ) {
				$wpdb->update( $t, array( 'license_files' => wp_json_encode( $files ) ), array( 'id' => $row->id ) );
			}
		}
		return $out;
	}

	/**
	 * 保持期間を過ぎた本人確認書類を削除する（設定でオンのときだけ動く）
	 * 返却済／キャンセルから指定日数を過ぎた予約が対象。
	 * @return int 削除した予約件数
	 */
	public static function purge_expired_documents() {
		$s    = BV_Util::settings();
		$days = (int) ( $s['doc_retention_days'] ?? 0 );
		if ( $days < 1 ) return 0;

		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$cut = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, license_files FROM {$t}
			  WHERE license_files != '' AND license_files != '[]'
			    AND status IN ('returned','cancelled') AND return_dt < %s
			  LIMIT 200",
			$cut
		) );
		if ( ! $rows ) return 0;

		/* 進行中の予約でまだ使われているキーは消さない */
		$in_use = array();
		$live = $wpdb->get_col(
			"SELECT license_files FROM {$t} WHERE status IN ('pending','confirmed','in_use') AND license_files != ''"
		);
		foreach ( (array) $live as $lf ) {
			$d = json_decode( (string) $lf, true );
			if ( is_array( $d ) ) foreach ( $d as $v ) if ( is_string( $v ) ) $in_use[ $v ] = true;
		}

		$n = 0;
		foreach ( $rows as $row ) {
			$files = json_decode( (string) $row->license_files, true );
			if ( ! is_array( $files ) ) continue;
			foreach ( $files as $v ) {
				if ( is_string( $v ) && self::is_key( $v ) && empty( $in_use[ $v ] ) ) self::delete( $v );
			}
			$wpdb->update( $t, array( 'license_files' => '' ), array( 'id' => $row->id ) );
			$n++;
		}
		return $n;
	}
}
