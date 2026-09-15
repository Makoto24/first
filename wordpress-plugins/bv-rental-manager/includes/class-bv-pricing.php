<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 料金計算エンジン
 * - 24時間単位（端数切り上げ）
 * - 日ごとに通常/グリーンの料金区分を積算
 * - 30日以上は月額料金（超過分は月額÷30/日）
 * - 学割はクラス別固定価格
 * - 装備オプション・追加補償は1日単位
 * - クーポン（固定額/割引率）
 */
class BV_Pricing {

	/** 貸出日数（24時間単位、端数切り上げ） */
	public static function rental_days( $pickup, $return ) {
		$sec = strtotime( $return ) - strtotime( $pickup );
		if ( $sec <= 0 ) return 0;
		return (int) ceil( $sec / DAY_IN_SECONDS );
	}

	/** 月数に応じたマンスリー割引率（％） */
	public static function monthly_discount_rate( $months ) {
		$s = BV_Util::settings();
		if ( $months >= 3 ) return (int) $s['monthly_3m'];
		if ( $months >= 2 ) return (int) $s['monthly_2m'];
		return 0;
	}

	/** 貸出日数に応じた長期割引率（％） */
	public static function longterm_rate( $days ) {
		$s = BV_Util::settings();
		if ( $days >= 14 ) return (int) $s['longterm_14days'];
		if ( $days >= 7 )  return (int) $s['longterm_7days'];
		if ( $days >= 3 )  return (int) $s['longterm_3days'];
		return 0;
	}

	/** 日別の料金区分（normal/green）リスト */
	public static function day_categories( $pickup, $days ) {
		$s = BV_Util::settings();
		$default = $s['rate_default_category'];
		$start = date( 'Y-m-d', strtotime( $pickup ) );
		$end   = date( 'Y-m-d', strtotime( $pickup ) + ( $days - 1 ) * DAY_IN_SECONDS );
		$overrides = BV_DB::get_rate_categories( $start, $end );
		$out = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d = date( 'Y-m-d', strtotime( $pickup ) + $i * DAY_IN_SECONDS );
			$out[ $d ] = isset( $overrides[ $d ] ) ? $overrides[ $d ]->category : $default;
		}
		return $out;
	}

	/**
	 * 見積計算
	 * $args: vehicle_class, pickup_dt, return_dt, opt_child_seat, opt_ski_rack, opt_navi,
	 *        coverage(A/B/C), shuttle, is_student, coupon_code, lang
	 * @return array|WP_Error
	 */
	public static function quote( $args ) {
		$s    = BV_Util::settings();
		$lang = ( isset( $args['lang'] ) && 'en' === $args['lang'] ) ? 'en' : 'ja';
		$cls  = $args['vehicle_class'];
		$classes = BV_Util::classes();
		if ( ! isset( $classes[ $cls ] ) ) {
			return new WP_Error( 'bad_class', ( 'en' === $lang ) ? 'Invalid vehicle class.' : '車両クラスの指定が正しくありません。' );
		}

		$days = self::rental_days( $args['pickup_dt'], $args['return_dt'] );
		if ( $days < 1 ) {
			return new WP_Error( 'bad_period', ( 'en' === $lang ) ? 'The return date and time must be after the pick-up date and time.' : '返却日時は貸出日時より後にしてください。' );
		}

		$lines = array();
		$base  = 0;
		/* ---- 時間貸し店舗（From P出張所）は別料金体系 ---- */
		$store  = isset( $args['store'] ) ? sanitize_key( $args['store'] ) : '';
		$hourly = $store && BV_Util::is_hourly_store( $store );
		if ( $hourly ) {
			if ( ! in_array( $cls, BV_Util::store_classes( $store ), true ) ) {
				return new WP_Error( 'bad_class', ( 'en' === $lang )
					? 'This vehicle class is not available at this branch.'
					: 'この店舗ではお選びいただけない車両クラスです。' );
			}
			return self::quote_hourly( $args, $cls, $store, $lang );
		}

		/* 学割は対象クラス（既定：軽自動車）のみ */
		$is_student = ! empty( $args['is_student'] ) && BV_Util::is_student_class( $cls );

		if ( $is_student ) {
			/* 学割も日ごとに通常／グリーンシーズンを判定する（長期割引・月額は併用しない） */
			$cats = self::day_categories( $args['pickup_dt'], $days );
			$count = array( 'normal' => 0, 'green' => 0 );
			foreach ( $cats as $cat ) $count[ $cat ]++;

			foreach ( array( 'normal', 'green' ) as $cat ) {
				if ( $count[ $cat ] < 1 ) continue;
				$unit = ( 'green' === $cat )
					? (int) $s[ 'rate_student_green_' . $cls ]
					: (int) $s[ 'rate_student_' . $cls ];
				$amt  = $unit * $count[ $cat ];
				$base += $amt;
				$cat_ja = ( 'normal' === $cat ) ? '学割料金' : '学割料金（グリーンシーズン）';
				$cat_en = ( 'normal' === $cat ) ? 'Student rate' : 'Student rate (green season)';
				$lines[] = array(
					'key'   => 'base_student_' . $cat,
					'label' => ( 'en' === $lang )
						? sprintf( '%s: %s × %d day(s)', $cat_en, BV_Util::money( $unit, 'en' ), $count[ $cat ] )
						: sprintf( '%s：%s × %d日', $cat_ja, BV_Util::money( $unit ), $count[ $cat ] ),
					'amount'=> $amt,
				);
			}
		} else {
			$monthly = (int) $s[ 'rate_month_' . $cls ];
			$months  = ( $days >= 30 ) ? (int) floor( $days / 30 ) : 0;
			$rem     = $days - $months * 30;

			/* 30日ごとの月額ブロック（長期は割引） */
			if ( $months > 0 ) {
				$m_disc = self::monthly_discount_rate( $months );
				$unit_m = (int) round( $monthly * ( 100 - $m_disc ) / 100 );
				$amt = $unit_m * $months;
				$base += $amt;
				$disc_ja = $m_disc > 0 ? sprintf( '（%dか月以上 %d%%OFF）', ( $months >= 3 ? 3 : 2 ), $m_disc ) : '';
				$disc_en = $m_disc > 0 ? sprintf( ' (%d+ months, %d%% off)', ( $months >= 3 ? 3 : 2 ), $m_disc ) : '';
				$lines[] = array(
					'key'   => 'base_monthly',
					'label' => ( 'en' === $lang )
						? sprintf( 'Monthly rate%s: %s × %d month(s)', $disc_en, BV_Util::money( $unit_m, 'en' ), $months )
						: sprintf( '月額料金%s：%s × %dか月', $disc_ja, BV_Util::money( $unit_m ), $months ),
					'amount'=> $amt,
				);
			}

			/* 端数日は日額計算（長期割引適用） */
			if ( $rem > 0 ) {
				$offset_days = $months * 30;
				$cats = self::day_categories( $args['pickup_dt'], $days );
				$cats = array_slice( $cats, $offset_days, $rem, true );
				$count = array( 'normal' => 0, 'green' => 0 );
				foreach ( $cats as $cat ) $count[ $cat ]++;

				/* 割引率は「全体の貸出日数」で判定 */
				$disc = self::longterm_rate( $days );
				$sub = 0;
				foreach ( array( 'normal', 'green' ) as $cat ) {
					if ( $count[ $cat ] < 1 ) continue;
					$unit_base = (int) $s[ 'rate_' . $cat . '_' . $cls ];
					$apply = ( 'normal' === $cat || ! empty( $s['longterm_green'] ) ) ? $disc : 0;
					$unit = (int) round( $unit_base * ( 100 - $apply ) / 100 );
					$amt  = $unit * $count[ $cat ];
					$sub += $amt;
					$cat_ja = ( 'normal' === $cat ) ? '通常料金' : 'グリーンシーズン料金';
					$cat_en = ( 'normal' === $cat ) ? 'Regular rate' : 'Green-season rate';
					$disc_ja = $apply > 0 ? sprintf( '（長期割引 %d%%OFF）', $apply ) : '';
					$disc_en = $apply > 0 ? sprintf( ' (long-term %d%% off)', $apply ) : '';
					$lines[] = array(
						'key'   => 'base_' . $cat,
						'label' => ( 'en' === $lang )
							? sprintf( '%s%s: %s × %d day(s)', $cat_en, $disc_en, BV_Util::money( $unit, 'en' ), $count[ $cat ] )
							: sprintf( '%s%s：%s × %d日', $cat_ja, $disc_ja, BV_Util::money( $unit ), $count[ $cat ] ),
						'amount'=> $amt,
					);
				}

				$base += $sub;

				/*
				 * 月額上限：「1か月分多く借りたことにした場合」の総額と比較して安いほうを採用。
				 * これにより 45日 > 60日 のような料金の逆転が起きない。
				 */
				if ( ! empty( $s['monthly_cap'] ) && $monthly > 0 ) {
					$m2      = $months + 1;
					$d2      = self::monthly_discount_rate( $m2 );
					$unit2   = (int) round( $monthly * ( 100 - $d2 ) / 100 );
					$alt     = $unit2 * $m2;
					if ( $alt < $base ) {
						$saving = $base - $alt;
						$lines[] = array(
							'key'   => 'base_monthly_cap',
							'label' => ( 'en' === $lang )
								? sprintf( 'Monthly rate cap applied (%s × %d month(s))', BV_Util::money( $unit2, 'en' ), $m2 )
								: sprintf( '月額料金の上限を適用（%s × %dか月）', BV_Util::money( $unit2 ), $m2 ),
							'amount'=> -$saving,
						);
						$base = $alt;
					}
				}
			}
		}

		/* 装備オプション */
		$equip = BV_Util::equipment();
		foreach ( $equip as $k => $eq ) {
			$max = isset( $eq['max'] ) ? (int) $eq['max'] : 1;
			$qty = isset( $args[ 'opt_' . $k ] ) ? min( $max, max( 0, (int) $args[ 'opt_' . $k ] ) ) : 0;
			if ( $qty < 1 ) continue;
			$amt = $eq['price'] * $days * $qty;
			$unit = ( 'en' === $lang ) ? '' : ( in_array( $k, array( 'child_seat', 'junior_seat' ), true ) ? '台' : '個' );
			$lines[] = array(
				'key'   => 'opt_' . $k,
				'label' => ( 'en' === $lang )
					? sprintf( '%s: %s × %d day(s) × %d', $eq['en'], BV_Util::money( $eq['price'], 'en' ), $days, $qty )
					: sprintf( '%s：%s × %d日 × %d%s', $eq['ja'], BV_Util::money( $eq['price'] ), $days, $qty, $unit ),
				'amount'=> $amt,
			);
		}

		/* 追加補償 */
		$cov = isset( $args['coverage'] ) ? strtoupper( $args['coverage'] ) : 'A';
		$coverages = BV_Util::coverages();
		if ( ! isset( $coverages[ $cov ] ) ) $cov = 'A';
		if ( $coverages[ $cov ]['price'] > 0 ) {
			$amt = $coverages[ $cov ]['price'] * $days;
			$lines[] = array(
				'key'   => 'coverage',
				'label' => ( 'en' === $lang )
					? sprintf( '%s: %s × %d day(s)', $coverages[ $cov ]['en'], BV_Util::money( $coverages[ $cov ]['price'], 'en' ), $days )
					: sprintf( '%s：%s × %d日', $coverages[ $cov ]['ja'], BV_Util::money( $coverages[ $cov ]['price'] ), $days ),
				'amount'=> $amt,
			);
		} else {
			$lines[] = array( 'key' => 'coverage', 'label' => BV_Util::label( $coverages, $cov, $lang ), 'amount' => 0 );
		}

		return self::finish( $args, $lines, $base, $days, $lang );
	}


	/** 見積の共通部分（クーポン・手動調整・送迎・合計） */
	protected static function finish( $args, $lines, $base, $days, $lang, $extra = array() ) {
		$subtotal = $base;
		foreach ( $lines as $l ) { if ( 0 !== strpos( $l['key'], 'base' ) ) $subtotal += $l['amount']; }

		/* クーポン */
		$discount = 0;
		$coupon_msg = '';
		if ( ! empty( $args['coupon_code'] ) ) {
			$coupon = BV_DB::get_coupon( $args['coupon_code'] );
			if ( $coupon ) {
				$discount = ( 'percent' === $coupon->discount_type )
					? (int) round( $subtotal * $coupon->amount / 100 )
					: (int) $coupon->amount;
				$discount = min( $discount, $subtotal );
				$lines[] = array(
					'key'   => 'coupon',
					'label' => ( 'en' === $lang )
						? sprintf( 'Coupon (%s)', $coupon->code )
						: sprintf( 'クーポン（%s）', $coupon->code ),
					'amount'=> -$discount,
				);
			} else {
				$coupon_msg = ( 'en' === $lang ) ? 'Coupon code is invalid or expired.' : 'クーポンコードが無効か期限切れです。';
			}
		}

		$total = $subtotal - $discount;

		/*
		 * 手動調整（特別値引き／追加請求）
		 * 管理画面・スタッフポータルからのみ指定できる。プラス＝値引き、マイナス＝追加請求。
		 */
		$manual = isset( $args['manual_discount'] ) ? (int) $args['manual_discount'] : 0;
		if ( 0 !== $manual ) {
			if ( $manual > $total ) $manual = $total; /* 合計がマイナスにならないように */
			$note = isset( $args['manual_discount_note'] ) ? trim( (string) $args['manual_discount_note'] ) : '';
			if ( $manual > 0 ) {
				$label = ( 'en' === $lang ) ? 'Special discount' : '特別割引';
			} else {
				$label = ( 'en' === $lang ) ? 'Additional charge' : '追加料金';
			}
			if ( '' !== $note ) $label .= ( 'en' === $lang ) ? ' (' . $note . ')' : '（' . $note . '）';
			$lines[] = array( 'key' => 'manual_discount', 'label' => $label, 'amount' => -$manual );
			$total -= $manual;
			$discount += $manual;
		}

		/* 送迎 */
		$shuttle = isset( $args['shuttle'] ) ? $args['shuttle'] : 'none';
		$shuttle_note = '';
		if ( 'none' !== $shuttle && isset( BV_Util::shuttles()[ $shuttle ] ) ) {
			$shuttle_note = ( 'en' === $lang )
				? 'A separate shuttle fee is required (staff will contact you).'
				: '送迎費用が別途必要です（スタッフよりご連絡します）。';
		}

		$out = array(
			'days'         => $days,
			'lines'        => $lines,
			'subtotal'     => $subtotal,
			'discount'     => $discount,
			'total'        => $total,
			'coupon_error' => $coupon_msg,
			'shuttle_note' => $shuttle_note,
			'currency'     => 'JPY',
		);
		return array_merge( $out, $extra );
	}

	/** 時間数（切り上げ） */
	public static function rental_hours( $pickup, $return ) {
		$sec = strtotime( $return ) - strtotime( $pickup );
		if ( $sec <= 0 ) return 0;
		return (int) ceil( $sec / HOUR_IN_SECONDS );
	}

	/**
	 * 時間貸し店舗（From P出張所）の見積
	 * 車両：1時間単位、補償：1時間単位、装備：1日単位（他店と同じ）
	 * 学割・長期割引・月額は適用しない。
	 */
	protected static function quote_hourly( $args, $cls, $store, $lang ) {
		$s = BV_Util::settings();
		$hours = self::rental_hours( $args['pickup_dt'], $args['return_dt'] );
		if ( $hours < 1 ) {
			return new WP_Error( 'bad_period', ( 'en' === $lang ) ? 'The return date and time must be after the pick-up date and time.' : '返却日時は貸出日時より後にしてください。' );
		}
		$days = (int) ceil( $hours / 24 );
		$lines = array();

		/* 車両：時間料金 */
		$unit = (int) $s[ 'hourly_rate_' . $cls ];
		if ( $unit < 1 ) {
			return new WP_Error( 'bad_class', ( 'en' === $lang ) ? 'Hourly rate is not set for this class.' : 'このクラスの時間料金が設定されていません。' );
		}
		$base = $unit * $hours;
		$cap  = (int) $s['hourly_day_cap'];
		$capped = false;
		if ( $cap > 0 && $base > $cap * $days ) { $base = $cap * $days; $capped = true; }
		$lines[] = array(
			'key'   => 'base_hourly',
			'label' => ( 'en' === $lang )
				? sprintf( 'Hourly rate: %s × %d hour(s)', BV_Util::money( $unit, 'en' ), $hours ) . ( $capped ? ' (daily cap applied)' : '' )
				: sprintf( '時間料金：%s × %d時間', BV_Util::money( $unit ), $hours ) . ( $capped ? '（1日上限を適用）' : '' ),
			'amount'=> $base,
		);

		/* 装備：1日単位（他店と同じ） */
		$equip = BV_Util::equipment();
		foreach ( $equip as $k => $ev ) {
			$max = (int) $ev['max'];
			$qty = isset( $args[ 'opt_' . $k ] ) ? min( $max, max( 0, (int) $args[ 'opt_' . $k ] ) ) : 0;
			if ( $qty < 1 || (int) $ev['price'] < 1 ) continue;
			$amt = (int) $ev['price'] * $qty * $days;
			$lines[] = array(
				'key'   => 'opt_' . $k,
				'label' => ( 'en' === $lang )
					? sprintf( '%s: %s × %d × %d day(s)', $ev['en'], BV_Util::money( $ev['price'], 'en' ), $qty, $days )
					: sprintf( '%s：%s × %d × %d日', $ev['ja'], BV_Util::money( $ev['price'] ), $qty, $days ),
				'amount'=> $amt,
			);
		}

		/* 補償：1時間単位 */
		$cov = isset( $args['coverage'] ) ? strtoupper( $args['coverage'] ) : 'A';
		$coverages = BV_Util::coverages();
		if ( ! isset( $coverages[ $cov ] ) ) $cov = 'A';
		$cov_unit = ( 'B' === $cov ) ? (int) $s['hourly_cov_b'] : ( ( 'C' === $cov ) ? (int) $s['hourly_cov_c'] : 0 );
		if ( $cov_unit > 0 ) {
			$lines[] = array(
				'key'   => 'coverage',
				'label' => ( 'en' === $lang )
					? sprintf( '%s: %s × %d hour(s)', $coverages[ $cov ]['en'], BV_Util::money( $cov_unit, 'en' ), $hours )
					: sprintf( '%s：%s × %d時間', $coverages[ $cov ]['ja'], BV_Util::money( $cov_unit ), $hours ),
				'amount'=> $cov_unit * $hours,
			);
		} else {
			$lines[] = array( 'key' => 'coverage', 'label' => BV_Util::label( $coverages, $cov, $lang ), 'amount' => 0 );
		}

		return self::finish( $args, $lines, $base, $days, $lang, array( 'hours' => $hours, 'hourly' => true ) );
	}
}
