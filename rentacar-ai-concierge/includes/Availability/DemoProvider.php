<?php
namespace RCAC\Availability;

/**
 * 動作確認用のデモデータ。今日を基準に、いくつかの車両が埋まっている状態を作る。
 */
final class DemoProvider implements ProviderInterface {

	public function vehicles(): array {
		$rows = array(
			array( '1', 'N-BOX', '軽自動車', 4 ),
			array( '2', 'タント', '軽自動車', 4 ),
			array( '3', 'ヤリス', 'コンパクト', 5 ),
			array( '4', 'ノート', 'コンパクト', 5 ),
			array( '5', 'シエンタ', 'ミニバン', 7 ),
			array( '6', 'ヴォクシー', 'ミニバン', 8 ),
			array( '7', 'RAV4', 'SUV', 5 ),
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'       => $r[0],
				'name'     => $r[1],
				'class'    => $r[2],
				'capacity' => $r[3],
				'store'    => '本店',
			);
		}
		return $out;
	}

	public function bookings( \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$spec  = array(
			// 車両ID, 開始日オフセット, 開始時刻, 終了日オフセット, 終了時刻
			array( '1', 0, '10:00', 2, '18:00' ),
			array( '3', 1, '09:00', 4, '17:00' ),
			array( '5', 3, '10:00', 5, '10:00' ),
			array( '6', 0, '09:00', 1, '20:00' ),
			array( '6', 6, '09:00', 8, '18:00' ),
			array( '7', 2, '08:00', 9, '19:00' ),
			array( '2', 5, '10:00', 6, '17:00' ),
		);
		$out = array();
		foreach ( $spec as $s ) {
			$start = $today->modify( "+{$s[1]} days {$s[2]}" );
			$end   = $today->modify( "+{$s[3]} days {$s[4]}" );
			if ( $start < $to && $end > $from ) {
				$out[] = array(
					'vehicle' => $s[0],
					'start'   => $start,
					'end'     => $end,
				);
			}
		}
		return $out;
	}
}
