<?php
namespace RCAC\Availability;

use RCAC\Settings;

/**
 * 車両一覧と予約から、指定期間の空車を判定する。
 */
final class AvailabilityService {

	public static function enabled(): bool {
		return 'none' !== Settings::get( 'availability_provider' );
	}

	public static function provider(): ProviderInterface {
		$type = (string) Settings::get( 'availability_provider' );
		switch ( $type ) {
			case 'table':
				$provider = new TableProvider();
				break;
			case 'postmeta':
				$provider = new PostMetaProvider();
				break;
			case 'demo':
				$provider = new DemoProvider();
				break;
			default:
				$provider = null;
		}

		/**
		 * 独自の予約プラグインに対応するアダプターを返すためのフィルター。
		 *
		 * @param ProviderInterface|null $provider 設定に応じた標準アダプター
		 * @param string                 $type     設定値（demo / table / postmeta / custom / none）
		 */
		$provider = apply_filters( 'rcac_availability_provider', $provider, $type );
		if ( ! $provider instanceof ProviderInterface ) {
			throw new ConfigException( '空車データの取得方法が設定されていません。' );
		}
		return $provider;
	}

	private static function buffer(): \DateInterval {
		return new \DateInterval( 'PT' . max( 0, (int) Settings::get( 'buffer_minutes' ) ) . 'M' );
	}

	/**
	 * 期間内に使える車両を判定する。
	 *
	 * @return array{vehicles:list<array<string,mixed>>,matched:int}
	 */
	public static function check( \DateTimeImmutable $start, \DateTimeImmutable $end, string $keyword = '', int $passengers = 0, string $store = '' ): array {
		$provider = self::provider();
		$vehicles = $provider->vehicles();
		$buffer   = self::buffer();

		// 予約の前後に準備時間を足して重なりを判定するため、取得範囲も広げる。
		$bookings = $provider->bookings( $start->sub( $buffer ), $end->add( $buffer ) );
		$busy     = self::busy_vehicle_keys( $bookings, $start->sub( $buffer ), $end->add( $buffer ) );

		$keyword = Values::normalize( $keyword );
		$store   = Values::normalize( $store );
		$out     = array();
		foreach ( $vehicles as $v ) {
			if ( '' !== $keyword && ! self::matches( $keyword, $v['class'] ) && ! self::matches( $keyword, $v['name'] ) ) {
				continue;
			}
			if ( '' !== $store && '' !== $v['store'] && ! str_contains( Values::normalize( $v['store'] ), $store ) ) {
				continue;
			}
			if ( $passengers > 0 && null !== $v['capacity'] && $v['capacity'] < $passengers ) {
				continue;
			}
			$v['available'] = ! isset( $busy[ self::key( $v['id'] ) ] ) && ! isset( $busy[ self::key( $v['name'] ) ] );
			$out[]          = $v;
		}
		return array(
			'vehicles' => $out,
			'matched'  => count( $out ),
		);
	}

	/**
	 * 管理画面の確認用：日ごとの予約状況（簡易ガントチャート）。
	 *
	 * @return array{days:list<\DateTimeImmutable>,rows:list<array{vehicle:array<string,mixed>,cells:list<bool>}>,bookings:int}
	 */
	public static function timeline( \DateTimeImmutable $from, int $days ): array {
		$provider = self::provider();
		$vehicles = $provider->vehicles();
		$from     = $from->setTime( 0, 0 );
		$to       = $from->modify( "+{$days} days" );
		$bookings = $provider->bookings( $from, $to );

		$day_list = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$day_list[] = $from->modify( "+{$i} days" );
		}

		$rows = array();
		foreach ( $vehicles as $v ) {
			$cells = array();
			foreach ( $day_list as $d ) {
				$busy    = self::busy_vehicle_keys( $bookings, $d, $d->modify( '+1 day' ) );
				$cells[] = isset( $busy[ self::key( $v['id'] ) ] ) || isset( $busy[ self::key( $v['name'] ) ] );
			}
			$rows[] = array(
				'vehicle' => $v,
				'cells'   => $cells,
			);
		}
		return array(
			'days'     => $day_list,
			'rows'     => $rows,
			'bookings' => count( $bookings ),
		);
	}

	/**
	 * @param list<array{vehicle:string,start:\DateTimeImmutable,end:\DateTimeImmutable}> $bookings
	 * @return array<string,true>
	 */
	private static function busy_vehicle_keys( array $bookings, \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$busy = array();
		foreach ( $bookings as $b ) {
			if ( $b['start'] < $to && $b['end'] > $from ) {
				$busy[ self::key( $b['vehicle'] ) ] = true;
			}
		}
		return $busy;
	}

	/** 「ミニバン」「ミニバンタイプ」「ヤリスを借りたい」のような表記揺れを部分一致で吸収する。 */
	private static function matches( string $keyword, string $value ): bool {
		$value = Values::normalize( $value );
		if ( '' === $value ) {
			return false;
		}
		return str_contains( $value, $keyword ) || str_contains( $keyword, $value );
	}

	private static function key( string $value ): string {
		return Values::normalize( $value );
	}
}
