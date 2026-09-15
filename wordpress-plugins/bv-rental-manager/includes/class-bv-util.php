<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 共通定義・設定・多言語ラベル
 */
class BV_Util {

	/* 車両クラス */
	public static function classes() {
		return array(
			'kei'     => array( 'ja' => '軽自動車',      'en' => 'Kei (Mini) Car', 'capacity' => 4 ),
			'compact' => array( 'ja' => 'コンパクトカー', 'en' => 'Compact Car',    'capacity' => 5 ),
			'suv'     => array( 'ja' => 'SUV',           'en' => 'SUV',            'capacity' => 5 ),
			'minivan' => array( 'ja' => 'ミニバン',      'en' => 'Minivan',        'capacity' => 7 ),
		);
	}

	/**
	 * キャンセルポリシー（貸出までの残り時間に応じたキャンセル料の割合）
	 * 上から順に判定し、最初に当てはまったものを適用する。
	 */
	public static function cancel_policy() {
		$s = self::settings();
		return array(
			array(
				'hours' => 720, /* 1か月前 */
				'pct'   => (int) $s['cancel_pct_month'],
				'ja'    => '出発1か月前まで',
				'en'    => 'Up to 1 month before pick-up',
			),
			array(
				'hours' => 168, /* 1週間前 */
				'pct'   => (int) $s['cancel_pct_week'],
				'ja'    => '出発1週間前まで',
				'en'    => 'Up to 1 week before pick-up',
			),
			array(
				'hours' => 48,
				'pct'   => (int) $s['cancel_pct_48h'],
				'ja'    => '出発48時間前まで',
				'en'    => 'Up to 48 hours before pick-up',
			),
			array(
				'hours' => 0,
				'pct'   => (int) $s['cancel_pct_late'],
				'ja'    => '出発48時間前以降',
				'en'    => 'Within 48 hours of pick-up',
			),
		);
	}

	/**
	 * キャンセル料の判定
	 * @param object $r      予約
	 * @param bool   $noshow 無断キャンセル（No-show）か
	 * @return array{pct:int,label_ja:string,label_en:string,fee:int,refund:int,paid:int}
	 */
	public static function cancel_charge( $r, $noshow = false ) {
		$s = self::settings();

		/* 実際にお預かりしている金額（すでに返金した分は差し引く） */
		$paid = $r->paid_at ? (int) $r->price_total : 0;
		$paid = max( 0, $paid - (int) $r->refund_amount );

		$policy = self::cancel_policy();
		if ( $noshow ) {
			$pct = (int) $s['cancel_pct_noshow'];
			$label_ja = '無断キャンセル（No-show）';
			$label_en = 'No-show';
		} else {
			/* 最終段（0時間）が必ず当てはまるので、既定値として使っておく */
			$last = $policy[ count( $policy ) - 1 ];
			$pct = (int) $last['pct']; $label_ja = $last['ja']; $label_en = $last['en'];

			$left = ( strtotime( $r->pickup_dt ) - current_time( 'timestamp' ) ) / HOUR_IN_SECONDS;
			foreach ( $policy as $tier ) {
				if ( $left >= $tier['hours'] ) {
					$pct = (int) $tier['pct']; $label_ja = $tier['ja']; $label_en = $tier['en'];
					break;
				}
			}
		}
		$pct = max( 0, min( 100, $pct ) );
		$fee = (int) round( (int) $r->price_total * $pct / 100 );
		$refund = max( 0, $paid - $fee );

		return array(
			'pct' => $pct, 'label_ja' => $label_ja, 'label_en' => $label_en,
			'fee' => $fee, 'refund' => $refund, 'paid' => $paid,
		);
	}

	/** キャンセルポリシーの表示用テキスト（改行区切り） */
	public static function cancel_policy_text( $lang = 'ja' ) {
		$s = self::settings();
		$out = array();
		foreach ( self::cancel_policy() as $tier ) {
			$label = ( 'en' === $lang ) ? $tier['en'] : $tier['ja'];
			$pct = max( 0, min( 100, (int) $tier['pct'] ) );
			$val = ( 0 === $pct )
				? ( ( 'en' === $lang ) ? 'Free' : '無料' )
				: $pct . '%';
			$out[] = ( 'en' === $lang ) ? $label . ': ' . $val : $label . '：' . $val;
		}
		$ns = max( 0, min( 100, (int) $s['cancel_pct_noshow'] ) );
		$out[] = ( 'en' === $lang )
			? 'No-show (no contact): ' . $ns . '%'
			: '無断キャンセル（ご連絡なし）：' . $ns . '%';
		return implode( "\n", $out );
	}

	/**
	 * 支払い状況の表示用ラベル
	 * 「支払済み」「未払い」だけでなく、返金の有無まで一目で分かるようにする。
	 * @return array{key:string,short:string,text:string,color:string,sub:string}
	 */
	public static function payment_state( $r ) {
		$total  = (int) $r->price_total;
		$refund = (int) $r->refund_amount;
		$kept   = max( 0, $total - $refund );

		if ( ! $r->paid_at ) {
			if ( 'cancelled' === $r->status ) {
				return array( 'key' => 'none', 'short' => '—', 'text' => '入金前にキャンセル', 'color' => '#8c8f94', 'sub' => '' );
			}
			return array( 'key' => 'unpaid', 'short' => '未', 'text' => '未払い', 'color' => '#b32d2e', 'sub' => '' );
		}

		if ( $refund < 1 ) {
			/* キャンセルなのに返金がない＝キャンセル料を全額いただいたケース */
			if ( 'cancelled' === $r->status ) {
				return array(
					'key' => 'cancel_fee_full', 'short' => 'キャンセル料', 'text' => 'キャンセル料として全額',
					'color' => '#996800', 'sub' => self::money( $total ) . '（返金なし）',
				);
			}
			return array( 'key' => 'paid', 'short' => '済', 'text' => '支払済み', 'color' => '#00a32a', 'sub' => '' );
		}
		if ( $refund >= $total ) {
			return array(
				'key' => 'refunded_full', 'short' => '全額返金', 'text' => '全額返金済み',
				'color' => '#2271b1', 'sub' => self::money( $refund ) . ' を返金',
			);
		}
		$kept_label = ( 'cancelled' === $r->status ) ? 'キャンセル料 ' : '差引 ';
		return array(
			'key' => 'refunded_part', 'short' => '一部返金', 'text' => '一部返金済み',
			'color' => '#2271b1',
			'sub' => self::money( $refund ) . ' を返金／' . $kept_label . self::money( $kept ),
		);
	}

	/** お支払い方法 */
	public static function payment_methods() {
		return array(
			'square' => array( 'ja' => 'オンライン決済（Square）', 'en' => 'Online payment (Square)' ),
			'cash'   => array( 'ja' => '現金（店頭でお支払い）',   'en' => 'Cash (pay at the branch)' ),
			'bank'   => array( 'ja' => '銀行振込',                 'en' => 'Bank transfer' ),
		);
	}

	/** 店頭・振込など、オンライン決済を使わない支払い方法か */
	public static function is_offline_payment( $r ) {
		$m = is_object( $r ) ? (string) $r->payment_method : (string) $r;
		return in_array( $m, array( 'cash', 'bank' ), true );
	}

	/** 学割の対象クラス */
	public static function student_classes() {
		return array( 'kei' );
	}

	public static function is_student_class( $class ) {
		return in_array( $class, self::student_classes(), true );
	}

	/** 定員つきのクラス名（例：軽自動車（定員4名）） */
	public static function class_label_with_capacity( $class, $lang = 'ja' ) {
		$c = self::classes();
		if ( ! isset( $c[ $class ] ) ) return $class;
		$cap = (int) $c[ $class ]['capacity'];
		return ( 'en' === $lang )
			? $c[ $class ]['en'] . ' (up to ' . $cap . ' passengers)'
			: $c[ $class ]['ja'] . '（定員' . $cap . '名）';
	}

	/* 場所（車両・予約の所在） */
	public static function locations() {
		return array(
			/* キーは車両データと紐づくため変更しない（表示名のみ変更可） */
			'hakuba_ekimae'   => array( 'ja' => '白馬駅前',        'en' => 'Hakuba Station',         'color' => '#76b7b2' ),
			'hakuba_norikura' => array( 'ja' => 'コルチナ乗鞍',    'en' => 'Cortina Norikura',       'color' => '#4e79a7' ),
			'shinano_omachi'  => array( 'ja' => '信濃大町駅前',    'en' => 'Shinano-Omachi Station', 'color' => '#f28e2b' ),
			'omachi_onsen'    => array( 'ja' => '大町温泉郷',      'en' => 'Omachi Onsenkyo',        'color' => '#e15759' ),
			'matsumoto'       => array( 'ja' => '松本島内',        'en' => 'Matsumoto Shimauchi',    'color' => '#59a14f' ),
			'matsumoto_univ'  => array( 'ja' => '松本信州大学前',  'en' => 'Matsumoto Shinshu Univ.','color' => '#b07aa1' ),
			'fromp'           => array( 'ja' => 'From P出張所',   'en' => 'From P Branch',          'color' => '#9c755f' ),
		);
	}

	/**
	 * 店舗の表示色（予約ガントのバー色）
	 * 店舗ごとに固定の色を持たせる。未定義の店舗は所属拠点の色を使う。
	 */
	public static function store_colors() {
		return array(
			'hakuba_ekimae'  => '#76b7b2', /* 白馬駅前店：青緑 */
			'hakuba'         => '#4e79a7', /* コルチナ乗鞍店：青 */
			'omachi'         => '#f28e2b', /* 信濃大町駅前店：オレンジ */
			'omachi_onsen'   => '#e15759', /* 大町温泉郷店：赤 */
			'matsumoto'      => '#59a14f', /* 松本島内店：緑 */
			'matsumoto_univ' => '#b07aa1', /* 松本信州大学前店：紫 */
			'hakuba_fromp'   => '#9c755f', /* From P出張所：茶 */
		);
	}

	public static function store_color( $store ) {
		$c = self::store_colors();
		if ( isset( $c[ $store ] ) ) return $c[ $store ];
		$stores = self::stores();
		if ( isset( $stores[ $store ]['location'] ) ) {
			$locs = self::locations();
			$lk = $stores[ $store ]['location'];
			if ( isset( $locs[ $lk ]['color'] ) ) return $locs[ $lk ]['color'];
		}
		return '#888888';
	}

	/* 店舗（予約フォームで選択） */
	public static function stores() {
		return array(
			/* キーは既存の予約データと紐づくため変更しない（表示名のみ変更可） */
			'hakuba_ekimae'  => array( 'ja' => '白馬レンタカー白馬駅前店', 'en' => 'Hakuba Rent a Car Hakuba Station Branch', 'location' => 'hakuba_ekimae' ),
			'hakuba'         => array( 'ja' => '白馬レンタカー コルチナ乗鞍店', 'en' => 'Hakuba Rent a Car Cortina Norikura Branch', 'location' => 'hakuba_norikura' ),
			'omachi'         => array( 'ja' => '大町レンタカー 信濃大町駅前店', 'en' => 'Omachi Rent a Car Shinano-Omachi Station Branch', 'location' => 'shinano_omachi' ),
			'omachi_onsen'   => array( 'ja' => '大町レンタカー 大町温泉郷店', 'en' => 'Omachi Rent a Car Omachi Onsenkyo Branch', 'location' => 'omachi_onsen' ),
			'matsumoto'      => array( 'ja' => '松本レンタカー島内店',     'en' => 'Matsumoto Rent a Car Shimauchi Branch',    'location' => 'matsumoto' ),
			'matsumoto_univ' => array( 'ja' => '松本レンタカー信州大学前店', 'en' => 'Matsumoto Rent a Car Shinshu Univ. Branch','location' => 'matsumoto_univ' ),
			/* 時間貸し専用の出張所。車両・料金・ポータルを他店と分けて運用する */
			'hakuba_fromp'   => array( 'ja' => '長野カーシェアFrom P出張所', 'en' => 'Nagano Car Share From P Branch', 'location' => 'fromp',
				'hourly' => true, 'classes' => array( 'kei' ), 'open' => '08:00', 'close' => '22:00' ),
		);
	}

	/** 時間貸し料金の店舗か（From P出張所） */
	public static function is_hourly_store( $store ) {
		$st = self::stores();
		return ! empty( $st[ $store ]['hourly'] );
	}

	/** その店舗で予約できる車両クラス（制限がなければ全クラス） */
	public static function store_classes( $store ) {
		$st = self::stores();
		if ( ! empty( $st[ $store ]['classes'] ) ) return $st[ $store ]['classes'];
		return array_keys( self::classes() );
	}

	/** その店舗の営業時間（店舗固有の設定がなければ全体設定） */
	public static function store_hours( $store ) {
		$s  = self::settings();
		$st = self::stores();
		$open  = ! empty( $st[ $store ]['open'] )  ? $st[ $store ]['open']  : $s['open_time'];
		$close = ! empty( $st[ $store ]['close'] ) ? $st[ $store ]['close'] : $s['close_time'];
		/* 設定画面で上書きしていればそちらを優先 */
		if ( ! empty( $s[ 'store_open_' . $store ] ) )  $open  = $s[ 'store_open_' . $store ];
		if ( ! empty( $s[ 'store_close_' . $store ] ) ) $close = $s[ 'store_close_' . $store ];
		return array( 'open' => $open, 'close' => $close );
	}

	/** 店舗宛のスタッフ通知先（カンマ区切り）。空なら全体設定を使う */
	public static function store_staff_cc( $store ) {
		$s = self::settings();
		$own = isset( $s[ 'store_staff_cc_' . $store ] ) ? trim( (string) $s[ 'store_staff_cc_' . $store ] ) : '';
		return $own;
	}

	/**
	 * 店舗グループ（同一グループ内の車両は相互に貸出可能）
	 * コルチナ乗鞍店・白馬駅前店は共通、信濃大町駅前店・大町温泉郷店は共通、
	 * 松本島内店・松本信州大学前店は共通。
	 */
	public static function store_groups() {
		return array(
			'hakuba'    => array(
				'label'     => '白馬エリア',
				'stores'    => array( 'hakuba_ekimae', 'hakuba' ),
				'locations' => array( 'hakuba_ekimae', 'hakuba_norikura' ),
			),
			'omachi'    => array(
				'label'     => '大町エリア',
				'stores'    => array( 'omachi', 'omachi_onsen' ),
				'locations' => array( 'shinano_omachi', 'omachi_onsen' ),
			),
			'matsumoto' => array(
				'label'     => '松本エリア',
				'stores'    => array( 'matsumoto', 'matsumoto_univ' ),
				'locations' => array( 'matsumoto', 'matsumoto_univ' ),
			),
			'fromp'     => array(
				'label'     => 'From P出張所',
				'stores'    => array( 'hakuba_fromp' ),
				'locations' => array( 'fromp' ),
			),
		);
	}

	/**
	 * 指定店舗で貸出可能な車両の「場所」一覧
	 * 設定で店舗ごとに上書き可能。未設定ならグループの既定値。
	 */
	public static function store_locations( $store ) {
		$s = self::settings();
		$key = 'store_locations_' . $store;
		if ( ! empty( $s[ $key ] ) && is_array( $s[ $key ] ) ) {
			return array_values( array_intersect( $s[ $key ], array_keys( self::locations() ) ) );
		}
		foreach ( self::store_groups() as $g ) {
			if ( in_array( $store, $g['stores'], true ) ) return $g['locations'];
		}
		/* グループ未定義なら店舗の所在地のみ */
		$stores = self::stores();
		return isset( $stores[ $store ] ) ? array( $stores[ $store ]['location'] ) : array();
	}

	/* 装備オプション */
	public static function equipment() {
		$p = self::settings();
		return array(
			'child_seat'  => array(
				'ja' => 'チャイルドシート', 'en' => 'Child Seat',
				'note_ja' => '新生児〜4歳ごろ', 'note_en' => 'newborn to approx. 4 years',
				'price' => (int) $p['opt_child_seat'], 'max' => 2,
			),
			'junior_seat' => array(
				'ja' => 'ジュニアシート', 'en' => 'Junior (Booster) Seat',
				'note_ja' => '3歳ごろ〜12歳ごろ', 'note_en' => 'approx. 3 to 12 years',
				'price' => (int) $p['opt_junior_seat'], 'max' => 2,
			),
			'ski_rack'    => array(
				'ja' => 'スキーラック', 'en' => 'Ski Rack',
				'note_ja' => '', 'note_en' => '',
				'price' => (int) $p['opt_ski_rack'], 'max' => 1,
			),
			'navi'        => array(
				'ja' => 'カーナビ', 'en' => 'Car Navigation',
				'note_ja' => '', 'note_en' => '',
				'price' => (int) $p['opt_navi'], 'max' => 1,
			),
			'etc'         => array(
				'ja' => 'ETCユニット', 'en' => 'ETC Unit',
				'note_ja' => 'ETCカードのレンタルは行っておりません。お客様のETCカードをご持参ください',
				'note_en' => 'ETC cards are NOT available for rent. Please bring your own ETC card',
				'price' => (int) $p['opt_etc'], 'max' => 1,
			),
		);
	}

	/** 装備オプションのキー一覧 */
	public static function equipment_keys() {
		return array_keys( self::equipment() );
	}

	/**
	 * 車両割当のときに「車両に付いていること」を条件にする装備。
	 * カーナビ・ETC・スキーラックは車に固定されているため既定でON。
	 * チャイルドシート等の持ち運べる備品は、設定でONにできる。
	 */
	public static function equipment_match_keys() {
		$s = self::settings();
		$sel = isset( $s['equip_match'] ) && is_array( $s['equip_match'] ) ? $s['equip_match'] : array();
		return array_values( array_intersect( $sel, self::equipment_keys() ) );
	}

	/** 渡された装備キーのうち、割当条件にするものだけを残す */
	public static function filter_matched_equipment( $keys ) {
		if ( ! $keys ) return array();
		return array_values( array_intersect( (array) $keys, self::equipment_match_keys() ) );
	}

	/* 追加補償 */
	public static function coverages() {
		$s = self::settings();
		return array(
			'C' => array( 'ja' => 'オプションC（車両補償＋NOC免除）', 'en' => 'Option C (Vehicle Coverage + NOC Waiver)', 'price' => (int) $s['cov_c'] ),
			'B' => array( 'ja' => 'オプションB（車両補償）',           'en' => 'Option B (Vehicle Coverage)',              'price' => (int) $s['cov_b'] ),
			'A' => array( 'ja' => 'オプションA（基本補償のみ）',       'en' => 'Option A (Basic Coverage Only)',           'price' => 0 ),
		);
	}

	/* 送迎 */
	public static function shuttles() {
		return array(
			'none'     => array( 'ja' => '送迎不要',        'en' => 'No shuttle needed' ),
			'round'    => array( 'ja' => '往復',            'en' => 'Round trip' ),
			'pickup'   => array( 'ja' => '片道（お迎え）',  'en' => 'One way (pick-up)' ),
			'dropoff'  => array( 'ja' => '片道（お送り）',  'en' => 'One way (drop-off)' ),
		);
	}

	/** 送迎リクエストの状態 */
	public static function shuttle_statuses() {
		return array(
			'none'      => array( 'ja' => '送迎なし',           'en' => 'No shuttle' ),
			'requested' => array( 'ja' => '送迎リクエスト受付', 'en' => 'Shuttle requested' ),
			'quoted'    => array( 'ja' => '送迎料金お支払い待ち', 'en' => 'Awaiting shuttle payment' ),
			'paid'      => array( 'ja' => '送迎確定',           'en' => 'Shuttle confirmed' ),
			'declined'  => array( 'ja' => '送迎不可',           'en' => 'Shuttle unavailable' ),
		);
	}

	/** 送迎料金の選択肢（片道。往復は倍額） */
	public static function shuttle_fees() {
		return array( 1500, 3000, 6000, 9000, 12000, 15000, 18000, 21000 );
	}

	/** 送迎に「お迎え」が含まれるか */
	public static function shuttle_has_pickup( $shuttle ) {
		return in_array( $shuttle, array( 'round', 'pickup' ), true );
	}

	/** 送迎に「お送り」が含まれるか */
	public static function shuttle_has_dropoff( $shuttle ) {
		return in_array( $shuttle, array( 'round', 'dropoff' ), true );
	}

	/**
	 * 送迎料金の内訳。往復は行き・帰りで別料金を設定できる。
	 * @return array{pickup:int,dropoff:int,total:int}
	 */
	public static function shuttle_fee_parts( $r ) {
		$pu = isset( $r->shuttle_fee_pickup ) ? (int) $r->shuttle_fee_pickup : 0;
		$dr = isset( $r->shuttle_fee_dropoff ) ? (int) $r->shuttle_fee_dropoff : 0;
		$total = (int) $r->shuttle_fee;

		/* 内訳が未設定の古いデータは、片道なら合計をそのまま充てる（往復は推定しない） */
		if ( ! $pu && ! $dr && $total > 0 ) {
			if ( 'pickup' === $r->shuttle )       $pu = $total;
			elseif ( 'dropoff' === $r->shuttle )  $dr = $total;
		}
		return array( 'pickup' => $pu, 'dropoff' => $dr, 'total' => $total );
	}

	/**
	 * 送迎料金の表示用テキスト。
	 * 往復で行き帰りの金額が違う場合だけ内訳を出す。
	 */
	public static function shuttle_fee_text( $r, $lang = 'ja' ) {
		$total = self::money( (int) $r->shuttle_fee, $lang );
		if ( 'round' !== $r->shuttle ) return $total;

		/* 内訳は実際に登録されている場合のみ表示する（推定値は出さない） */
		$pu = isset( $r->shuttle_fee_pickup ) ? (int) $r->shuttle_fee_pickup : 0;
		$dr = isset( $r->shuttle_fee_dropoff ) ? (int) $r->shuttle_fee_dropoff : 0;
		if ( ( $pu + $dr ) !== (int) $r->shuttle_fee || ( ! $pu && ! $dr ) ) return $total;

		if ( 'en' === $lang ) {
			return $total . ' (pick-up ' . self::money( $pu, $lang ) . ' + drop-off ' . self::money( $dr, $lang ) . ')';
		}
		return $total . '（お迎え ' . self::money( $pu ) . ' ＋ お送り ' . self::money( $dr ) . '）';
	}

	/** 往復は倍額 */
	public static function shuttle_multiplier( $shuttle ) {
		return ( 'round' === $shuttle ) ? 2 : 1;
	}

	public static function statuses() {
		return array(
			'pending'   => array( 'ja' => '仮予約',     'en' => 'Provisional' ),
			'confirmed' => array( 'ja' => '予約確定',   'en' => 'Confirmed' ),
			'in_use'    => array( 'ja' => '貸出中',     'en' => 'In use' ),
			'returned'  => array( 'ja' => '返却済',     'en' => 'Returned' ),
			'cancelled' => array( 'ja' => 'キャンセル', 'en' => 'Cancelled' ),
		);
	}

	/* 設定（デフォルト込み） */
	public static function settings() {
		$defaults = array(
			/* 24時間単位 通常料金 */
			'rate_normal_kei'     => 8800,  'rate_normal_compact' => 11000,
			'rate_normal_suv'     => 16500, 'rate_normal_minivan' => 16500,
			/* グリーンシーズン料金 */
			'rate_green_kei'      => 6600,  'rate_green_compact'  => 8800,
			'rate_green_suv'      => 13200, 'rate_green_minivan'  => 13200,
			/* 1か月料金 */
			'rate_month_kei'      => 120000, 'rate_month_compact' => 150000,
			'rate_month_suv'      => 220000, 'rate_month_minivan' => 220000,
			/* 学割（クラス別固定・24時間単位） */
			'rate_student_kei'     => 5500,  'rate_student_compact' => 7700,
			'rate_student_suv'     => 11000, 'rate_student_minivan' => 11000,
			/* 時間貸し（From P出張所）：1時間あたり */
			'hourly_rate_kei'     => 2200,  'hourly_rate_compact' => 0,
			'hourly_rate_suv'     => 0,     'hourly_rate_minivan' => 0,
			'hourly_cov_b'        => 550,   /* 補償B／時間 */
			'hourly_cov_c'        => 1100,  /* 補償C／時間 */
			'hourly_day_cap'      => 0,     /* 1日あたりの上限額（0＝上限なし） */
			/* 学割（グリーンシーズン） */
			'rate_student_green_kei'     => 4400,  'rate_student_green_compact' => 6600,
			'rate_student_green_suv'     => 9900,  'rate_student_green_minivan' => 9900,
			/* デフォルト料金区分：normal または green */
			'rate_default_category' => 'green',
			/* 長期割引（通常料金のみに適用・％） */
			'longterm_3days'  => 10,  /* 3日以上 */
			'longterm_7days'  => 20,  /* 7日以上 */
			'longterm_14days' => 20,  /* 14日以上 */
			'longterm_green'  => 0,   /* グリーンシーズンにも適用する場合は1 */
			/* マンスリー割引（月額料金に対する％） */
			'monthly_2m' => 10, /* 2か月以上 */
			'monthly_3m' => 20, /* 3か月以上 */
			/* 月額上限（日額合計が月額を超えたら月額に丸める） */
			'monthly_cap' => 1,
			/* オプション（1日あたり） */
			'opt_child_seat' => 1100, 'opt_junior_seat' => 1100, 'opt_ski_rack' => 1100,
			'opt_navi' => 1100, 'opt_etc' => 1100,
			'cov_c' => 5500, 'cov_b' => 3300,
			/* 営業時間（貸出はこの時間内のみ） */
			'open_time' => '08:00', 'close_time' => '21:00',
			/* 返却は24時間受付にするか */
			'return_24h' => 1,
			/* 受付制限 */
			'lead_time_hours'  => 2,   /* 現在時刻から何時間後以降を受付可能にするか */
			'max_advance_days' => 365, /* 何日先まで予約を受け付けるか */
			/* 返却後インターバル（清掃・点検のため次の貸出までに空ける時間） */
			'turnaround_hours' => 1,
			/* メール */
			'admin_email'   => get_option( 'admin_email' ),
			'admin_cc'      => '',
			'staff_notify'  => '',
			'mail_from_name'=> 'Be Village レンタカー',
			/* Square */
			'square_env'            => 'sandbox',
			'square_access_token'   => '',
			'square_location_id'    => '',
			'square_location_id_en' => '', /* 英語予約用の共通Location ID */
			'square_webhook_sig_key'=> '',
			'square_skip_sig'       => 0, /* 署名検証を一時的に無効化（診断用・60分で自動失効） */
			'square_skip_sig_at'    => 0, /* 上記をオンにした時刻 */
			/* 本人確認書類の保持日数（0＝削除しない）。返却済・キャンセルの予約が対象 */
			'doc_retention_days'    => 0,
			/* 返却後のお礼＋Googleレビュー依頼メール */
			'review_mail_enabled'   => 1,   /* 返却処理の完了時に自動送信する */
			'review_coupon_amount'  => 500, /* お礼クーポンの割引額（円） */
			'review_coupon_days'    => 365, /* お礼クーポンの有効日数 */
			/* 会社情報（印刷物） */
			'company_name'    => 'Be Village株式会社',
			'company_address' => '',
			'company_rep'     => '',
			'company_tel'     => '',
			'company_invoice' => '',
			'invoice_enabled' => 1, /* 適格請求書発行事業者かどうか（登録番号が空なら自動的に無効） */
			'company_seal_id'  => 0,  /* 社印PNG attachment ID（メディアID指定・任意） */
			'company_seal_url' => '', /* 社印PNGの画像URL直接指定（推奨・こちらがあれば優先） */
			/* スタッフポータル */
			'staff_pass' => '',
			'staff_pass_fromp' => '',
			/* 運輸支局 */
			'transport_office' => '長野',
			'office_count'     => 4,
			/* リマインド（仮予約から何時間後に督促メールを送るか。0で送らない・旧設定） */
			'reminder_hours' => 3,
			/* 督促を送るタイミング（仮予約からの経過時間・カンマ区切り） */
			'reminder_stages' => '12,24,36,47',
			/* お支払い期限（時間）：メール文面に表示。0で非表示 */
			'pay_deadline_hours' => 6,
			/* お客様ご自身での日程変更（貸出の何時間前まで可能か。0で無効＝すべて変更申請） */
			'self_change_hours' => 48,
			/* 車両割当のときに装備の有無を条件にするオプション（車載固定のものを既定でON） */
			'equip_match' => array( 'navi', 'etc', 'ski_rack' ),
			/* 決済リンクに短縮URL（square.link）ではなく長いURLを使う */
			'square_use_long_url' => 0,
			/* キャンセルポリシー（キャンセル料の割合） */
			'cancel_pct_month'  => 0,   /* 1か月前まで */
			'cancel_pct_week'   => 20,  /* 1週間前まで */
			'cancel_pct_48h'    => 30,  /* 48時間前まで */
			'cancel_pct_late'   => 50,  /* 48時間前以降 */
			'cancel_pct_noshow' => 100, /* 無断キャンセル */
			'cancel_auto_refund' => 1,  /* お客様のキャンセル時にSquareへ自動返金する */
			/* 直前予約（この時間を切っていれば、お支払い完了で予約確定＝仮予約なし） */
			'immediate_pay_hours'   => 168,
			'immediate_hold_minutes'=> 10,
			/* 出発前のご案内メール */
			'remind_week_enabled' => 1,
			'remind_week_hour'    => 10, /* 1週間前の送信時刻（時） */
			'remind_day_enabled'  => 1,
			'remind_day_hour'     => 17, /* 前日の送信時刻（時） */
			/* 自動キャンセル */
			'autocancel_enabled' => 0,  /* 1で有効 */
			'autocancel_hours'   => 6,  /* 仮予約から何時間で自動キャンセルするか */
			'autocancel_notify_customer' => 1, /* お客様へも通知するか */
		);
		/* 店舗ごとのサイトURL・差出人設定 */
		foreach ( array_keys( self::stores() ) as $sk ) {
			$defaults[ 'store_url_' . $sk ]        = '';
			$defaults[ 'store_from_email_' . $sk ] = '';
			$defaults[ 'store_from_name_' . $sk ]  = '';
			$defaults[ 'store_reply_to_' . $sk ]   = '';
			$defaults[ 'store_staff_cc_' . $sk ]   = '';
			$defaults[ 'store_open_' . $sk ]       = '';
			$defaults[ 'store_close_' . $sk ]      = '';
			$defaults[ 'store_access_ja_' . $sk ] = ''; /* 来店場所の説明（日本語） */
			$defaults[ 'store_access_en_' . $sk ] = ''; /* 同（英語） */
			$defaults[ 'store_company_ja_' . $sk ] = ''; /* メール件名・署名に使う表示名（日本語） */
			$defaults[ 'store_company_en_' . $sk ] = '';
			$defaults[ 'store_locations_' . $sk ]            = array(); /* 貸出可能な車両の場所（空ならグループ既定） */
			$defaults[ 'store_square_location_' . $sk ]      = ''; /* 店舗ごとのSquare Location ID（日本語予約） */
			$defaults[ 'store_square_location_en_' . $sk ]   = ''; /* 同（英語予約） */
			$defaults[ 'store_review_url_' . $sk ]           = ''; /* Googleマップのレビュー投稿URL（店舗ごとに異なる） */
		}
		$saved = get_option( 'bvrm_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * 店舗のサイトURL
	 * 設定が未入力の場合は、遷移元（予約フォームを置いた店舗サイト）へ戻す。
	 * それも取得できなければ中央サイト。
	 */
	public static function store_url( $store ) {
		$s = self::settings();
		$key = 'store_url_' . $store;
		if ( ! empty( $s[ $key ] ) ) return $s[ $key ];

		$ref = self::referer_origin();
		if ( $ref ) return $ref;

		return home_url( '/' );
	}

	/** その店舗のGoogleレビュー投稿URL（未設定なら空） */
	public static function store_review_url( $store ) {
		$s = self::settings();
		return (string) ( $s[ 'store_review_url_' . $store ] ?? '' );
	}

	/** 遷移元サイトのオリジン（中央サイト以外の場合のみ返す） */
	public static function referer_origin() {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( ! $ref ) return '';
		$p = wp_parse_url( $ref );
		if ( empty( $p['host'] ) || empty( $p['scheme'] ) ) return '';
		if ( ! in_array( $p['scheme'], array( 'http', 'https' ), true ) ) return '';
		$self = wp_parse_url( home_url( '/' ) );
		if ( ! empty( $self['host'] ) && strtolower( $p['host'] ) === strtolower( $self['host'] ) ) return '';
		return $p['scheme'] . '://' . $p['host'] . '/';
	}

	/** 店舗の来店場所の説明 */
	public static function store_access( $store, $lang = 'ja' ) {
		$s = self::settings();
		$lang = ( 'en' === $lang ) ? 'en' : 'ja';
		$key = 'store_access_' . $lang . '_' . $store;
		return isset( $s[ $key ] ) ? $s[ $key ] : '';
	}

	/** 店舗名＋アクセス説明（例：大町レンタカー（竹のや旅館内、JR信濃大町駅より徒歩2分）） */
	public static function store_with_access( $store, $lang = 'ja' ) {
		$name = self::label( self::stores(), $store, $lang );
		$acc  = self::store_access( $store, $lang );
		if ( ! $acc ) return $name;
		return ( 'en' === $lang ) ? $name . ' (' . $acc . ')' : $name . '（' . $acc . '）';
	}

	/** 曜日つき日時（例：2026年9月9日（水）11:00 / Wed, Sep 9, 2026 11:00） */
	public static function format_dt( $dt, $lang = 'ja' ) {
		$ts = strtotime( $dt );
		if ( ! $ts ) return $dt;
		if ( 'en' === $lang ) return date( 'D, M j, Y H:i', $ts );
		$wd = array( '日', '月', '火', '水', '木', '金', '土' );
		return date( 'Y年n月j日', $ts ) . '（' . $wd[ (int) date( 'w', $ts ) ] . '）' . date( 'H:i', $ts );
	}

	/**
	 * 店舗の差出人情報（未設定なら共通設定にフォールバック）
	 * @return array name, email, reply_to
	 */
	public static function store_from( $store ) {
		$s = self::settings();
		$email = ! empty( $s[ 'store_from_email_' . $store ] ) ? $s[ 'store_from_email_' . $store ] : $s['admin_email'];
		$name  = ! empty( $s[ 'store_from_name_' . $store ] )  ? $s[ 'store_from_name_' . $store ]  : self::store_company( $store, 'ja' );
		$reply = ! empty( $s[ 'store_reply_to_' . $store ] )   ? $s[ 'store_reply_to_' . $store ]   : $email;
		return array( 'name' => $name, 'email' => $email, 'reply_to' => $reply );
	}

	/**
	 * Square Location ID を解決する
	 * 優先順位（英語予約）: 店舗×英語 → 共通×英語 → 店舗×日本語 → 共通
	 * 優先順位（日本語予約）: 店舗×日本語 → 共通
	 */
	public static function store_square_location( $store, $lang = 'ja' ) {
		$s = self::settings();
		if ( 'en' === $lang ) {
			if ( ! empty( $s[ 'store_square_location_en_' . $store ] ) ) return $s[ 'store_square_location_en_' . $store ];
			if ( ! empty( $s['square_location_id_en'] ) ) return $s['square_location_id_en'];
		}
		if ( ! empty( $s[ 'store_square_location_' . $store ] ) ) return $s[ 'store_square_location_' . $store ];
		return $s['square_location_id'];
	}

	/** 適格請求書（インボイス）発行事業者かどうか。登録番号が未設定なら常に false */
	public static function is_invoice_issuer() {
		$s = self::settings();
		return ! empty( $s['invoice_enabled'] ) && ! empty( trim( (string) $s['company_invoice'] ) );
	}

	/** 社印PNGのURL（URL直接指定を優先、なければメディアIDから解決） */
	public static function company_seal_url() {
		$s = self::settings();
		if ( ! empty( $s['company_seal_url'] ) ) return $s['company_seal_url'];
		if ( ! empty( $s['company_seal_id'] ) ) {
			$url = wp_get_attachment_image_url( (int) $s['company_seal_id'], 'medium' );
			if ( $url ) return $url;
		}
		return '';
	}

	/**
	 * メールの件名・署名に使う店舗表示名
	 * 設定 → 店舗名 → 会社名 の順にフォールバック
	 */
	public static function store_company( $store, $lang = 'ja' ) {
		$s = self::settings();
		$lang = ( 'en' === $lang ) ? 'en' : 'ja';
		$key = 'store_company_' . $lang . '_' . $store;
		if ( ! empty( $s[ $key ] ) ) return $s[ $key ];
		$stores = self::stores();
		if ( isset( $stores[ $store ] ) ) return $stores[ $store ][ $lang ];
		return $s['company_name'];
	}

	public static function update_settings( $new ) {
		$s = array_merge( get_option( 'bvrm_settings', array() ) ?: array(), $new );
		update_option( 'bvrm_settings', $s );
	}

	public static function label( $map, $key, $lang = 'ja' ) {
		$lang = ( 'en' === $lang ) ? 'en' : 'ja';
		return isset( $map[ $key ] ) ? $map[ $key ][ $lang ] : $key;
	}

	public static function money( $n, $lang = 'ja' ) {
		$n = (int) round( $n );
		return ( 'en' === $lang ) ? 'JPY ' . number_format( $n ) : '¥' . number_format( $n );
	}

	/* 予約番号生成 */
	public static function reservation_code() {
		return 'BV' . current_time( 'ymd' ) . strtoupper( wp_generate_password( 4, false, false ) );
	}

	/**
	 * 30分単位で妥当な日時か
	 * @param string $type 'pickup'（営業時間内のみ）／'return'（24時間可）
	 */
	public static function validate_slot( $dt, $type = 'pickup', $store = '' ) {
		$s = self::settings();
		$ts = strtotime( $dt );
		if ( ! $ts ) return false;
		$min = (int) date( 'i', $ts );
		if ( 0 !== $min % 30 ) return false;

		/* 時間貸し店舗は返却も営業時間内に限る（24時間返却の例外は適用しない） */
		$hourly = $store && self::is_hourly_store( $store );

		/* 返却は24時間受付（キーボックス返却等） */
		if ( 'return' === $type && ! empty( $s['return_24h'] ) && ! $hourly ) return true;

		$h = $store ? self::store_hours( $store ) : array( 'open' => $s['open_time'], 'close' => $s['close_time'] );
		$t = date( 'H:i', $ts );
		return ( $t >= $h['open'] && $t <= $h['close'] );
	}
}
