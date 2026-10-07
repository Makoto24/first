<?php
namespace RCAC;

/**
 * 公開エンドポイントの保護（会話トークンの署名、IP ごとの回数制限、サイト全体の上限）。
 */
final class Guard {

	private static function secret(): string {
		$secret = get_option( 'rcac_hmac_secret' );
		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'rcac_hmac_secret', $secret, false );
		}
		return $secret . wp_salt( 'nonce' );
	}

	/** 新しい会話 ID と、改ざん防止の署名付きトークンを発行する。 */
	public static function new_conversation_token(): array {
		$id = bin2hex( random_bytes( 16 ) );
		return array( $id, self::sign( $id ) );
	}

	public static function sign( string $id ): string {
		return $id . '.' . substr( hash_hmac( 'sha256', $id, self::secret() ), 0, 32 );
	}

	/** トークンを検証し、正しければ会話 ID を返す。 */
	public static function verify( string $token ): ?string {
		if ( ! preg_match( '/^([a-f0-9]{32})\.([a-f0-9]{32})$/', $token, $m ) ) {
			return null;
		}
		return hash_equals( self::sign( $m[1] ), $token ) ? $m[1] : null;
	}

	/** 個人を特定しない形で IP を記録する。 */
	public static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * リバースプロキシ配下で実 IP を使いたい場合に上書きする。
		 */
		$ip = (string) apply_filters( 'rcac_client_ip', $ip );
		return hash_hmac( 'sha256', $ip, self::secret() );
	}

	/**
	 * 固定ウィンドウ方式の回数制限。上限内なら true を返してカウントを1増やす。
	 */
	public static function hit( string $bucket, int $limit, int $window ): bool {
		if ( ! self::allowed( $bucket, $limit, $window ) ) {
			return false;
		}
		self::record( $bucket, $limit, $window );
		return true;
	}

	/** 上限に達していなければ true（カウントは増やさない）。 */
	public static function allowed( string $bucket, int $limit, int $window ): bool {
		return $limit <= 0 || self::state( $bucket, $window )['count'] < $limit;
	}

	public static function record( string $bucket, int $limit, int $window ): void {
		if ( $limit <= 0 ) {
			return;
		}
		$state = self::state( $bucket, $window );
		++$state['count'];
		set_transient( self::rl_key( $bucket ), $state, max( 1, $state['reset'] - time() ) );
	}

	/**
	 * @return array{count:int,reset:int}
	 */
	private static function state( string $bucket, int $window ): array {
		$state = get_transient( self::rl_key( $bucket ) );
		$now   = time();
		if ( ! is_array( $state ) || ( $state['reset'] ?? 0 ) <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}
		return $state;
	}

	private static function rl_key( string $bucket ): string {
		return 'rcac_rl_' . md5( $bucket . '|' . self::ip_hash() );
	}

	/** サイト全体の 1 日あたりのチャット回数上限。 */
	public static function hit_global_daily( int $limit ): bool {
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = 'rcac_daily_' . wp_date( 'Ymd' );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		return true;
	}
}
