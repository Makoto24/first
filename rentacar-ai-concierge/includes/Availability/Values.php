<?php
namespace RCAC\Availability;

use RCAC\Settings;

/**
 * 予約データの値の解釈（日時・ステータス・名前の正規化）。
 */
final class Values {

	/** 予約データが保存されているタイムゾーン。 */
	public static function storage_timezone(): \DateTimeZone {
		return 'utc' === Settings::get( 'booking_timezone' ) ? new \DateTimeZone( 'UTC' ) : wp_timezone();
	}

	/**
	 * DB の値（日時文字列・UNIX タイムスタンプ）を日時に変換する。解釈できない値は null。
	 */
	public static function parse_datetime( mixed $value, bool $is_end = false ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		try {
			if ( preg_match( '/^\d{12,13}$/', $value ) ) {
				return ( new \DateTimeImmutable( '@' . intdiv( (int) $value, 1000 ) ) )->setTimezone( wp_timezone() );
			}
			if ( preg_match( '/^\d{9,11}$/', $value ) ) {
				return ( new \DateTimeImmutable( '@' . $value ) )->setTimezone( wp_timezone() );
			}
			$dt = new \DateTimeImmutable( $value, self::storage_timezone() );
			// 日付だけの返却日は、その日いっぱい使うものとして扱う。
			if ( $is_end && preg_match( '#^\d{4}[-/]\d{1,2}[-/]\d{1,2}$#', $value ) ) {
				$dt = $dt->setTime( 23, 59, 59 );
			}
			return $dt->setTimezone( wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/** DB 検索用に日時を保存形式の文字列にする。 */
	public static function to_storage_string( \DateTimeImmutable $dt ): string {
		return $dt->setTimezone( self::storage_timezone() )->format( 'Y-m-d H:i:s' );
	}

	public static function is_excluded_status( mixed $status ): bool {
		if ( null === $status || '' === $status ) {
			return false;
		}
		$status = self::normalize( (string) $status );
		foreach ( explode( ',', (string) Settings::get( 'excluded_statuses' ) ) as $ex ) {
			$ex = self::normalize( $ex );
			if ( '' !== $ex && $ex === $status ) {
				return true;
			}
		}
		return false;
	}

	/** 全角・半角や大文字小文字の違いを吸収した比較用の文字列。 */
	public static function normalize( string $s ): string {
		$s = mb_convert_kana( trim( $s ), 'KVas', 'UTF-8' );
		return mb_strtolower( $s, 'UTF-8' );
	}

	public static function to_capacity( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( preg_match( '/\d+/', mb_convert_kana( (string) $value, 'n', 'UTF-8' ), $m ) ) {
			return (int) $m[0];
		}
		return null;
	}
}
