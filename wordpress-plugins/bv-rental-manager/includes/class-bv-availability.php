<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 空き状況判定（クラス単位）
 * 車両は「店舗グループ」内でのみ融通できる。
 * 例）コルチナ乗鞍店の予約 → コルチナ乗鞍・白馬駅前の車両が対象
 *     松本島内店の予約     → 松本島内・松本信州大学前の車両が対象
 *     信濃大町駅前店の予約 → 信濃大町駅前・大町温泉郷の車両が対象
 */
class BV_Availability {

	/**
	 * 指定店舗で貸出可能な車両（クラス絞り込み・場所グループ制限・装備の絞り込み）
	 *
	 * @param array $require 必要な装備キー（例：array('navi','etc')）
	 */
	public static function candidate_vehicles( $class, $store = '', $require = array() ) {
		$vehicles = BV_DB::get_vehicles( array( 'class' => $class, 'active' => true ) );
		if ( ! $vehicles ) return array();

		$allowed = $store ? BV_Util::store_locations( $store ) : array();
		$require = BV_Util::filter_matched_equipment( $require );

		$out = array();
		foreach ( $vehicles as $v ) {
			/* 場所グループの制限（設定がなければ制限しない） */
			if ( $store && $allowed && ! in_array( $v->location, $allowed, true ) ) continue;
			/* お客様が申し込んだ装備を積んでいない車両は候補から外す */
			if ( ! self::vehicle_has_equipment( $v, $require ) ) continue;
			$out[] = $v;
		}
		return $out;
	}

	/** 車両が必要な装備をすべて備えているか */
	public static function vehicle_has_equipment( $v, $require ) {
		foreach ( (array) $require as $key ) {
			$col = 'has_' . $key;
			if ( ! isset( $v->{$col} ) || ! (int) $v->{$col} ) return false;
		}
		return true;
	}

	/** 予約が必要とする装備キー（数量1以上のもの。設定で対象を絞れる） */
	public static function required_equipment( $r ) {
		$need = array();
		foreach ( BV_Util::equipment_keys() as $key ) {
			$col = 'opt_' . $key;
			$qty = is_object( $r ) ? ( isset( $r->{$col} ) ? (int) $r->{$col} : 0 )
				: ( isset( $r[ $col ] ) ? (int) $r[ $col ] : 0 );
			if ( $qty > 0 ) $need[] = $key;
		}
		return BV_Util::filter_matched_equipment( $need );
	}

	/**
	 * 清掃・点検のインターバル（秒）
	 * 店舗を指定するとその店舗の設定を使う（時間貸しの出張所は短く設定している）。
	 */
	public static function turnaround_seconds( $store = '' ) {
		if ( $store ) return BV_Util::store_turnaround_hours( $store ) * HOUR_IN_SECONDS;
		$s = BV_Util::settings();
		return max( 0, (float) $s['turnaround_hours'] ) * HOUR_IN_SECONDS;
	}

	/** 指定クラス・期間・店舗で空いている車両IDの配列 */
	public static function free_vehicles( $class, $pickup_dt, $return_dt, $exclude_reservation = 0, $store = '', $require = array() ) {
		$vehicles = self::candidate_vehicles( $class, $store, $require );
		if ( ! $vehicles ) return array();

		$candidate_ids = array();
		foreach ( $vehicles as $v ) $candidate_ids[] = (int) $v->id;

		/* 前後にインターバルを足した範囲で重複を判定する */
		$gap = self::turnaround_seconds( $store );
		$check_from = date( 'Y-m-d H:i:s', strtotime( $pickup_dt ) - $gap );
		$check_to   = date( 'Y-m-d H:i:s', strtotime( $return_dt ) + $gap );

		$overlapping = BV_DB::get_reservations( array(
			'overlap'           => array( $check_from, $check_to ),
			'exclude_cancelled' => true,
		) );

		/* 同一グループ（＝候補車両を共有する店舗）の未割当予約は在庫を消費する */
		$group_stores = self::group_stores( $store );

		$busy = array();
		/* 貸出停止（整備・休車）中の車両を除外 */
		foreach ( BV_DB::get_blocks( $check_from, $check_to ) as $b ) {
			$busy[ (int) $b->vehicle_id ] = true;
		}
		$unassigned = 0;
		foreach ( $overlapping as $r ) {
			if ( (int) $r->id === (int) $exclude_reservation ) continue;
			/* 返却済みでも、実返却からインターバルを空ける */
			if ( 'returned' === $r->status && $r->returned_at
				&& strtotime( $r->returned_at ) + $gap <= strtotime( $pickup_dt ) ) continue;
			if ( $r->vehicle_id ) {
				$busy[ (int) $r->vehicle_id ] = true;
			} elseif ( $r->vehicle_class === $class ) {
				if ( ! $store || ! $group_stores || in_array( $r->store, $group_stores, true ) ) $unassigned++;
			}
		}

		$free = array();
		foreach ( $candidate_ids as $vid ) {
			if ( empty( $busy[ $vid ] ) ) $free[] = $vid;
		}
		for ( $i = 0; $i < $unassigned && ! empty( $free ); $i++ ) array_pop( $free );
		return $free;
	}

	/** 同一グループに属する店舗キーの配列 */
	public static function group_stores( $store ) {
		if ( ! $store ) return array();
		foreach ( BV_Util::store_groups() as $g ) {
			if ( in_array( $store, $g['stores'], true ) ) return $g['stores'];
		}
		return array( $store );
	}

	public static function is_available( $class, $pickup_dt, $return_dt, $exclude_reservation = 0, $store = '', $require = array() ) {
		return count( self::free_vehicles( $class, $pickup_dt, $return_dt, $exclude_reservation, $store, $require ) ) > 0;
	}

	/**
	 * 特定の車両がその期間に空いているか（インターバル・貸出停止も考慮）
	 * 日程変更時に「今の車両のまま動かせるか」を判定するのに使う。
	 */
	public static function is_vehicle_free( $vehicle_id, $pickup_dt, $return_dt, $exclude_reservation = 0, $store = '' ) {
		$vehicle_id = (int) $vehicle_id;
		if ( ! $vehicle_id ) return false;

		$gap = self::turnaround_seconds( $store );
		$check_from = date( 'Y-m-d H:i:s', strtotime( $pickup_dt ) - $gap );
		$check_to   = date( 'Y-m-d H:i:s', strtotime( $return_dt ) + $gap );

		foreach ( BV_DB::get_blocks( $check_from, $check_to, $vehicle_id ) as $b ) {
			return false;
		}

		$overlapping = BV_DB::get_reservations( array(
			'overlap'           => array( $check_from, $check_to ),
			'exclude_cancelled' => true,
			'vehicle_id'        => $vehicle_id,
		) );
		foreach ( $overlapping as $r ) {
			if ( (int) $r->id === (int) $exclude_reservation ) continue;
			if ( 'returned' === $r->status && $r->returned_at
				&& strtotime( $r->returned_at ) + $gap <= strtotime( $pickup_dt ) ) continue;
			return false;
		}
		return true;
	}

	/** 予約に車両を自動割当（管理者は後から変更可） */
	public static function auto_assign( $class, $pickup_dt, $return_dt, $store = '', $exclude_reservation = 0, $require = array() ) {
		$free = self::free_vehicles( $class, $pickup_dt, $return_dt, $exclude_reservation, $store, $require );
		/* 装備を満たす車両が無い場合は、装備条件なしで割り当てる（未割当のまま放置しない） */
		if ( ! $free && $require ) {
			$free = self::free_vehicles( $class, $pickup_dt, $return_dt, $exclude_reservation, $store );
		}
		if ( ! $free ) return 0;

		/* 予約店舗と同じ場所の車両を優先 */
		$stores = BV_Util::stores();
		$primary = isset( $stores[ $store ] ) ? $stores[ $store ]['location'] : '';
		if ( $primary ) {
			foreach ( $free as $vid ) {
				$v = BV_DB::get_vehicle( $vid );
				if ( $v && $v->location === $primary ) return $vid;
			}
		}
		return $free[0];
	}
}
