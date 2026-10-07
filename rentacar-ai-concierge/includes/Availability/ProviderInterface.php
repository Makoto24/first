<?php
namespace RCAC\Availability;

/**
 * 予約プラグインのデータを読み出すアダプター。
 *
 * 既存の予約プラグインに合わせた独自アダプターを作る場合は、このインターフェースを実装したクラスを
 * rcac_availability_provider フィルターで返してください（README 参照）。
 */
interface ProviderInterface {

	/**
	 * 貸出対象の車両一覧。
	 *
	 * @return list<array{id:string,name:string,class:string,capacity:?int,store:string}>
	 */
	public function vehicles(): array;

	/**
	 * 指定期間に重なる予約。キャンセル済みの予約は含めない。
	 * vehicle には車両 ID（または車両名）を入れる。
	 *
	 * @return list<array{vehicle:string,start:\DateTimeImmutable,end:\DateTimeImmutable}>
	 */
	public function bookings( \DateTimeImmutable $from, \DateTimeImmutable $to ): array;
}
