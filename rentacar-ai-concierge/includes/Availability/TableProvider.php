<?php
namespace RCAC\Availability;

use RCAC\Settings;

/**
 * 独自テーブルに車両・予約を保存している予約プラグイン用。
 * テーブル名・列名は管理画面で指定し、実在確認したものだけを SQL に使う。
 * 予約テーブルからは車両・日時・ステータスの列だけを読み、お客様の個人情報は読まない。
 */
final class TableProvider implements ProviderInterface {

	private const MAX_ROWS = 20000;

	public function vehicles(): array {
		global $wpdb;
		$table = Schema::resolve_table( (string) Settings::get( 'tbl_vehicle_table' ) );
		if ( ! $table ) {
			throw new ConfigException( '車両テーブルが見つかりません。テーブル名を確認してください。' );
		}
		$id   = $this->required_column( $table, 'tbl_vehicle_id_col', '車両ID' );
		$name = $this->required_column( $table, 'tbl_vehicle_name_col', '車両名' );

		$select = array( Schema::quote( $id ) . ' AS rcac_id', Schema::quote( $name ) . ' AS rcac_name' );
		foreach ( array(
			'class'    => 'tbl_vehicle_class_col',
			'capacity' => 'tbl_vehicle_capacity_col',
			'store'    => 'tbl_vehicle_store_col',
		) as $alias => $key ) {
			$col = Schema::resolve_column( $table, (string) Settings::get( $key ) );
			if ( $col ) {
				$select[] = Schema::quote( $col ) . ' AS rcac_' . $alias;
			}
		}

		list( $where, $args ) = $this->where_clause( $table, (string) Settings::get( 'tbl_vehicle_where' ) );
		$sql  = 'SELECT ' . implode( ', ', $select ) . ' FROM ' . Schema::quote( $table ) . $where . ' LIMIT 1000';
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );

		$out = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[] = array(
				'id'       => (string) $row['rcac_id'],
				'name'     => (string) $row['rcac_name'],
				'class'    => (string) ( $row['rcac_class'] ?? '' ),
				'capacity' => Values::to_capacity( $row['rcac_capacity'] ?? null ),
				'store'    => (string) ( $row['rcac_store'] ?? '' ),
			);
		}
		return $out;
	}

	public function bookings( \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		global $wpdb;
		$table = Schema::resolve_table( (string) Settings::get( 'tbl_booking_table' ) );
		if ( ! $table ) {
			throw new ConfigException( '予約テーブルが見つかりません。テーブル名を確認してください。' );
		}
		$vehicle = $this->required_column( $table, 'tbl_booking_vehicle_col', '予約の車両ID' );
		$start   = $this->required_column( $table, 'tbl_booking_start_col', '貸出日時' );
		$end     = $this->required_column( $table, 'tbl_booking_end_col', '返却日時' );
		$status  = Schema::resolve_column( $table, (string) Settings::get( 'tbl_booking_status_col' ) );

		$select = array(
			Schema::quote( $vehicle ) . ' AS rcac_vehicle',
			Schema::quote( $start ) . ' AS rcac_start',
			Schema::quote( $end ) . ' AS rcac_end',
		);
		if ( $status ) {
			$select[] = Schema::quote( $status ) . ' AS rcac_status';
		}
		$sql = 'SELECT ' . implode( ', ', $select ) . ' FROM ' . Schema::quote( $table );

		// 列の型に応じて DB 側で期間を絞り込む。文字列型など形式が不明な場合は PHP 側で判定する。
		$kind = Schema::date_kind( $table, $start );
		if ( 'datetime' === $kind && 'datetime' === Schema::date_kind( $table, $end ) ) {
			$sql = $wpdb->prepare(
				$sql . ' WHERE ' . Schema::quote( $start ) . ' < %s AND ' . Schema::quote( $end ) . ' > %s LIMIT ' . self::MAX_ROWS,
				Values::to_storage_string( $to->modify( '+1 day' ) ),
				Values::to_storage_string( $from->modify( '-1 day' ) )
			);
		} elseif ( 'unix' === $kind && 'unix' === Schema::date_kind( $table, $end ) ) {
			$sql = $wpdb->prepare(
				$sql . ' WHERE ' . Schema::quote( $start ) . ' < %d AND ' . Schema::quote( $end ) . ' > %d LIMIT ' . self::MAX_ROWS,
				$to->getTimestamp(),
				$from->getTimestamp()
			);
		} else {
			$sql .= ' ORDER BY ' . Schema::quote( $start ) . ' DESC LIMIT ' . self::MAX_ROWS;
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			if ( Values::is_excluded_status( $row['rcac_status'] ?? null ) ) {
				continue;
			}
			$s = Values::parse_datetime( $row['rcac_start'] );
			$e = Values::parse_datetime( $row['rcac_end'], true );
			if ( ! $s || ! $e || ! ( $s < $to && $e > $from ) ) {
				continue;
			}
			$out[] = array(
				'vehicle' => (string) $row['rcac_vehicle'],
				'start'   => $s,
				'end'     => $e,
			);
		}
		return $out;
	}

	private function required_column( string $table, string $setting, string $label ): string {
		$col = Schema::resolve_column( $table, (string) Settings::get( $setting ) );
		if ( ! $col ) {
			throw new ConfigException( sprintf( '%1$s の列「%2$s」がテーブル %3$s にありません。', $label, (string) Settings::get( $setting ), $table ) );
		}
		return $col;
	}

	/**
	 * 「列=値&列=値」形式の絞り込み条件を WHERE 句にする。
	 *
	 * @return array{0:string,1:list<string>}
	 */
	private function where_clause( string $table, string $spec ): array {
		$parts = array();
		$args  = array();
		foreach ( explode( '&', $spec ) as $cond ) {
			if ( ! str_contains( $cond, '=' ) ) {
				continue;
			}
			list( $col, $val ) = array_map( 'trim', explode( '=', $cond, 2 ) );
			$col               = Schema::resolve_column( $table, $col );
			if ( ! $col ) {
				throw new ConfigException( '車両の絞り込み条件の列名がテーブルにありません。' );
			}
			$parts[] = Schema::quote( $col ) . ' = %s';
			$args[]  = $val;
		}
		return array( $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '', $args );
	}
}
