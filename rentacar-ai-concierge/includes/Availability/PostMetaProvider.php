<?php
namespace RCAC\Availability;

use RCAC\Settings;

/**
 * 車両・予約をカスタム投稿タイプ＋カスタムフィールド（post meta）で保存している予約プラグイン用。
 */
final class PostMetaProvider implements ProviderInterface {

	private const MAX_ROWS = 5000;

	public function vehicles(): array {
		$post_type = (string) Settings::get( 'pm_vehicle_post_type' );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			throw new ConfigException( '車両の投稿タイプ「' . $post_type . '」が見つかりません。' );
		}
		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'numberposts'      => 1000,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'       => (string) $post->ID,
				'name'     => get_the_title( $post ),
				'class'    => $this->field( $post->ID, (string) Settings::get( 'pm_vehicle_class_meta' ) ),
				'capacity' => Values::to_capacity( $this->field( $post->ID, (string) Settings::get( 'pm_vehicle_capacity_meta' ) ) ),
				'store'    => $this->field( $post->ID, (string) Settings::get( 'pm_vehicle_store_meta' ) ),
			);
		}
		return $out;
	}

	public function bookings( \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$post_type   = (string) Settings::get( 'pm_booking_post_type' );
		$vehicle_key = (string) Settings::get( 'pm_booking_vehicle_meta' );
		$start_key   = (string) Settings::get( 'pm_booking_start_meta' );
		$end_key     = (string) Settings::get( 'pm_booking_end_meta' );
		$status_key  = (string) Settings::get( 'pm_booking_status_meta' );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			throw new ConfigException( '予約の投稿タイプ「' . $post_type . '」が見つかりません。' );
		}
		if ( '' === $vehicle_key || '' === $start_key || '' === $end_key ) {
			throw new ConfigException( '予約の車両・貸出日時・返却日時のカスタムフィールドを指定してください。' );
		}

		$args = array(
			'post_type'        => $post_type,
			'post_status'      => array( 'publish', 'private', 'pending', 'future' ),
			'numberposts'      => self::MAX_ROWS,
			'fields'           => 'ids',
			'suppress_filters' => true,
		);

		// 保存形式が分かる場合は DB 側で期間を絞り込む。
		$kind = $this->date_kind( $post_type, $start_key );
		if ( 'datetime' === $kind ) {
			$args['meta_query'] = array(
				array(
					'key'     => $start_key,
					'value'   => Values::to_storage_string( $to->modify( '+1 day' ) ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => $end_key,
					'value'   => Values::to_storage_string( $from->modify( '-1 day' ) ),
					'compare' => '>',
					'type'    => 'DATETIME',
				),
			);
		} elseif ( 'unix' === $kind ) {
			$args['meta_query'] = array(
				array(
					'key'     => $start_key,
					'value'   => $to->getTimestamp(),
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => $end_key,
					'value'   => $from->getTimestamp(),
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			);
		}

		$out = array();
		foreach ( get_posts( $args ) as $id ) {
			if ( '' !== $status_key && Values::is_excluded_status( get_post_meta( $id, $status_key, true ) ) ) {
				continue;
			}
			$s = Values::parse_datetime( get_post_meta( $id, $start_key, true ) );
			$e = Values::parse_datetime( get_post_meta( $id, $end_key, true ), true );
			if ( ! $s || ! $e || ! ( $s < $to && $e > $from ) ) {
				continue;
			}
			$out[] = array(
				'vehicle' => (string) get_post_meta( $id, $vehicle_key, true ),
				'start'   => $s,
				'end'     => $e,
			);
		}
		return $out;
	}

	/** カスタムフィールド、または「tax:タクソノミー名」の値を文字列で返す。 */
	private function field( int $post_id, string $spec ): string {
		if ( '' === $spec ) {
			return '';
		}
		if ( str_starts_with( $spec, 'tax:' ) ) {
			$terms = get_the_terms( $post_id, substr( $spec, 4 ) );
			return is_array( $terms ) ? implode( '・', wp_list_pluck( $terms, 'name' ) ) : '';
		}
		$value = get_post_meta( $post_id, $spec, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** 1件目の値から日時の保存形式を推定する: datetime / unix / text */
	private function date_kind( string $post_type, string $key ): string {
		global $wpdb;
		$sample = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> '' LIMIT 1",
				$post_type,
				$key
			)
		);
		$sample = trim( (string) $sample );
		if ( preg_match( '/^\d{9,11}$/', $sample ) ) {
			return 'unix';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $sample ) ) {
			return 'datetime';
		}
		return 'text';
	}
}
