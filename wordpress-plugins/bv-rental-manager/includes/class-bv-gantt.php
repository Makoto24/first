<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 予約ガントチャート（管理画面・スタッフポータル共用）
 * 1日 = 2マス（各マス=半日）。場所ごとに色分け。返却済みも表示。
 *
 * 対話操作：
 *  - 予約バーを上下ドラッグ → 車両の入れ替え
 *  - 予約バーを左右ドラッグ → 日程の移動（時刻は保持・1日単位）
 *  - バー右端をドラッグ    → 返却日の延長／短縮
 *  - 空き行をドラッグ      → 新規予約 または 貸出停止（整備・休車）を作成
 *  - 停止バーをクリック    → 削除
 */
class BV_Gantt {

	const CELL = 36; /* 半日マスの幅(px) */

	/* ================= データ操作（管理画面／スタッフ共用） ================= */

	/**
	 * @return array{ok:bool,message:string,reload:bool}
	 */
	public static function do_action( $action, $p, $scope = null ) {
		/* 店舗限定ポータルからの操作は、範囲外の予約・車両に触れさせない */
		if ( $scope ) {
			$rid = (int) ( $p['id'] ?? 0 );
			if ( in_array( $action, array( 'move', 'resize', 'unassign' ), true ) && $rid ) {
				$chk = BV_DB::get_reservation( $rid );
				if ( ! $chk || ! in_array( $chk->store, $scope['stores'], true ) ) return array( 'ok' => false, 'message' => 'この予約は操作できません。' );
			}
			if ( in_array( $action, array( 'block_move', 'block_resize', 'block_delete' ), true ) && $rid ) {
				$bk = self::get_block( $rid );
				$bv = $bk ? BV_DB::get_vehicle( (int) $bk->vehicle_id ) : null;
				if ( ! $bv || ! in_array( $bv->location, $scope['locations'], true ) ) return array( 'ok' => false, 'message' => 'この貸出停止は操作できません。' );
			}
			$vid = (int) ( $p['vehicle_id'] ?? 0 );
			if ( $vid ) {
				$vv = BV_DB::get_vehicle( $vid );
				if ( ! $vv || ! in_array( $vv->location, $scope['locations'], true ) ) return array( 'ok' => false, 'message' => 'この車両は操作できません。' );
			}
		}
		switch ( $action ) {

			case 'move': /* 予約の車両変更・日程移動 */
				$id  = (int) ( $p['id'] ?? 0 );
				$r   = BV_DB::get_reservation( $id );
				if ( ! $r ) return array( 'ok' => false, 'message' => '予約が見つかりません。' );
				$vid   = (int) ( $p['vehicle_id'] ?? $r->vehicle_id );
				$shift = (int) ( $p['shift_days'] ?? 0 );

				$pickup = date( 'Y-m-d H:i:s', strtotime( $r->pickup_dt ) + $shift * DAY_IN_SECONDS );
				$return = date( 'Y-m-d H:i:s', strtotime( $r->return_dt ) + $shift * DAY_IN_SECONDS );

				$data = array( 'vehicle_id' => $vid, 'pickup_dt' => $pickup, 'return_dt' => $return );
				$class_note = '';

				if ( $vid ) {
					$v = BV_DB::get_vehicle( $vid );
					if ( ! $v ) return array( 'ok' => false, 'message' => '車両が見つかりません。' );
					$conf = self::conflict( $vid, $pickup, $return, $id );
					if ( $conf ) return array( 'ok' => false, 'message' => $conf );

					/* 車両クラスが変わる場合は予約のクラスも合わせ、料金を再計算する */
					if ( $v->class !== $r->vehicle_class ) {
						$old_class = $r->vehicle_class;
						$data['vehicle_class'] = $v->class;

						$quote = BV_Pricing::quote( array(
							'vehicle_class' => $v->class,
							'pickup_dt' => $pickup, 'return_dt' => $return,
							'opt_child_seat' => (int) $r->opt_child_seat,
							'opt_junior_seat'=> (int) $r->opt_junior_seat,
							'opt_ski_rack'   => (int) $r->opt_ski_rack,
							'opt_navi'       => (int) $r->opt_navi,
							'opt_etc'        => (int) $r->opt_etc,
							'coverage'    => $r->coverage,
							'shuttle'     => $r->shuttle,
							'is_student'  => (int) $r->is_student,
							'coupon_code' => $r->coupon_code,
							'store'       => $r->store,
							'lang'        => $r->lang,
							'manual_discount'      => (int) $r->manual_discount,
							'manual_discount_note' => $r->manual_discount_note,
						) );

						$classes = BV_Util::classes();
						$class_note = '／クラスを「' . BV_Util::label( $classes, $old_class ) . '」→「' . BV_Util::label( $classes, $v->class ) . '」に変更';

						if ( ! is_wp_error( $quote ) ) {
							$old_total = (int) $r->price_total;
							$new_total = (int) $quote['total'];
							$data['price_breakdown'] = wp_json_encode( $quote );
							$data['price_total'] = $new_total;
							if ( $old_total !== $new_total ) {
								$diff = $new_total - $old_total;
								$class_note .= '／料金 ' . BV_Util::money( $old_total ) . ' → ' . BV_Util::money( $new_total )
									. '（' . ( $diff > 0 ? '+' : '' ) . BV_Util::money( $diff ) . '）';
								if ( $r->paid_at ) {
									$class_note .= ' ※支払済みのため' . ( $diff > 0 ? '追加請求' : '返金' ) . 'の手続きが必要です';
								}
							}
						}
					}
				}

				/* 申し込まれた装備が新しい車両に付いていない場合は知らせる（操作自体は許可する） */
				$equip_note = '';
				if ( $vid ) {
					$lack = self::missing_equipment( $r, $vid );
					if ( $lack ) $equip_note = '／⚠この車両には ' . implode( '・', $lack ) . ' が付いていません';
				}

				BV_DB::update_reservation( $id, $data );
				return array( 'ok' => true, 'message' => '予約 ' . $r->code . ' を更新しました' . $class_note . $equip_note );

			case 'resize': /* 返却日の延長・短縮 */
				$id = (int) ( $p['id'] ?? 0 );
				$r  = BV_DB::get_reservation( $id );
				if ( ! $r ) return array( 'ok' => false, 'message' => '予約が見つかりません。' );
				$shift  = (int) ( $p['shift_days'] ?? 0 );
				$return = date( 'Y-m-d H:i:s', strtotime( $r->return_dt ) + $shift * DAY_IN_SECONDS );
				if ( strtotime( $return ) <= strtotime( $r->pickup_dt ) ) {
					return array( 'ok' => false, 'message' => '返却日時は貸出日時より後にしてください。' );
				}
				if ( $r->vehicle_id ) {
					$conf = self::conflict( $r->vehicle_id, $r->pickup_dt, $return, $id );
					if ( $conf ) return array( 'ok' => false, 'message' => $conf );
				}
				BV_DB::update_reservation( $id, array( 'return_dt' => $return ) );
				return array( 'ok' => true, 'message' => '予約 ' . $r->code . ' の返却日を変更しました（料金は自動再計算されません。必要に応じて編集画面で再計算してください）' );

			case 'block_add': /* 貸出停止を追加 */
				$vid   = (int) ( $p['vehicle_id'] ?? 0 );
				$start = sanitize_text_field( $p['start'] ?? '' );
				$end   = sanitize_text_field( $p['end'] ?? '' );
				$reason = sanitize_text_field( $p['reason'] ?? '' );
				if ( ! $vid || ! $start || ! $end ) return array( 'ok' => false, 'message' => '入力が不足しています。' );
				$conf = self::conflict( $vid, $start, $end, 0 );
				if ( $conf ) return array( 'ok' => false, 'message' => $conf );
				BV_DB::insert_block( $vid, $start, $end, $reason );
				return array( 'ok' => true, 'message' => '貸出停止を登録しました。' );

			case 'block_delete':
				BV_DB::delete_block( (int) ( $p['id'] ?? 0 ) );
				return array( 'ok' => true, 'message' => '貸出停止を解除しました。' );

			case 'block_move': /* 貸出停止の車両変更・日程移動 */
				$id = (int) ( $p['id'] ?? 0 );
				$b  = self::get_block( $id );
				if ( ! $b ) return array( 'ok' => false, 'message' => '貸出停止が見つかりません。' );
				$vid   = (int) ( $p['vehicle_id'] ?? $b->vehicle_id );
				$shift = (int) ( $p['shift_days'] ?? 0 );
				$start = date( 'Y-m-d H:i:s', strtotime( $b->start_dt ) + $shift * DAY_IN_SECONDS );
				$end   = date( 'Y-m-d H:i:s', strtotime( $b->end_dt ) + $shift * DAY_IN_SECONDS );
				if ( $vid ) {
					$v = BV_DB::get_vehicle( $vid );
					if ( ! $v ) return array( 'ok' => false, 'message' => '車両が見つかりません。' );
					$conf = self::conflict( $vid, $start, $end, 0, $id );
					if ( $conf ) return array( 'ok' => false, 'message' => $conf );
				}
				BV_DB::update_block( $id, array( 'vehicle_id' => $vid, 'start_dt' => $start, 'end_dt' => $end ) );
				return array( 'ok' => true, 'message' => '貸出停止を移動しました。' );

			case 'block_resize': /* 貸出停止の終了日を延長・短縮 */
				$id = (int) ( $p['id'] ?? 0 );
				$b  = self::get_block( $id );
				if ( ! $b ) return array( 'ok' => false, 'message' => '貸出停止が見つかりません。' );
				$shift = (int) ( $p['shift_days'] ?? 0 );
				$end   = date( 'Y-m-d H:i:s', strtotime( $b->end_dt ) + $shift * DAY_IN_SECONDS );
				if ( strtotime( $end ) <= strtotime( $b->start_dt ) ) {
					return array( 'ok' => false, 'message' => '終了日時は開始日時より後にしてください。' );
				}
				$conf = self::conflict( (int) $b->vehicle_id, $b->start_dt, $end, 0, $id );
				if ( $conf ) return array( 'ok' => false, 'message' => $conf );
				BV_DB::update_block( $id, array( 'end_dt' => $end ) );
				return array( 'ok' => true, 'message' => '貸出停止の期間を変更しました（〜' . date( 'n/j H:i', strtotime( $end ) ) . '）。' );

			case 'unassign': /* 車両の割当を外す */
				$id = (int) ( $p['id'] ?? 0 );
				$r  = BV_DB::get_reservation( $id );
				if ( ! $r ) return array( 'ok' => false, 'message' => '予約が見つかりません。' );
				BV_DB::update_reservation( $id, array( 'vehicle_id' => 0 ) );
				return array( 'ok' => true, 'message' => '予約 ' . $r->code . ' の車両割当を外しました。' );
		}
		return array( 'ok' => false, 'message' => '不明な操作です。' );
	}

	/**
	 * 予約が申し込んだ装備のうち、その車両に付いていないものの名称一覧
	 * @return string[] 例：array('カーナビ')
	 */
	public static function missing_equipment( $r, $vehicle_id = 0 ) {
		$vehicle_id = $vehicle_id ? (int) $vehicle_id : (int) $r->vehicle_id;
		if ( ! $vehicle_id ) return array();
		$v = BV_DB::get_vehicle( $vehicle_id );
		if ( ! $v ) return array();

		$equip = BV_Util::equipment();
		$out = array();
		foreach ( BV_Availability::required_equipment( $r ) as $key ) {
			$col = 'has_' . $key;
			if ( empty( $v->{$col} ) ) $out[] = $equip[ $key ]['ja'];
		}
		return $out;
	}

	/** 貸出停止を1件取得 */
	public static function get_block( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BV_DB::table( 'blocks' ) . ' WHERE id = %d', (int) $id ) );
	}

	/** 重複チェック。問題があればメッセージを返す（清掃インターバルを考慮） */
	protected static function conflict( $vehicle_id, $start, $end, $exclude_reservation = 0, $exclude_block = 0 ) {
		global $wpdb;
		$gap = BV_Availability::turnaround_seconds();
		$cf  = date( 'Y-m-d H:i:s', strtotime( $start ) - $gap );
		$ct  = date( 'Y-m-d H:i:s', strtotime( $end ) + $gap );

		$t = BV_DB::table( 'reservations' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT code, pickup_dt, return_dt FROM {$t}
			 WHERE vehicle_id = %d AND id != %d AND status != 'cancelled'
			 AND pickup_dt < %s AND return_dt > %s LIMIT 1",
			$vehicle_id, $exclude_reservation, $ct, $cf
		) );
		if ( $row ) {
			$msg = '他の予約（' . $row->code . '：' . date( 'n/j H:i', strtotime( $row->pickup_dt ) ) . '〜' . date( 'n/j H:i', strtotime( $row->return_dt ) ) . '）と重複します。';
			if ( $gap > 0 ) $msg .= '（清掃・点検インターバル ' . self::gap_label( $gap ) . ' を含む）';
			return $msg;
		}
		foreach ( BV_DB::get_blocks( $cf, $ct, $vehicle_id ) as $b ) {
			if ( $exclude_block && (int) $b->id === (int) $exclude_block ) continue;
			return '貸出停止期間（' . date( 'n/j', strtotime( $b->start_dt ) ) . '〜' . date( 'n/j', strtotime( $b->end_dt ) ) . ( $b->reason ? '：' . $b->reason : '' ) . '）と重複します。';
		}
		return '';
	}

	/** インターバルの表示用ラベル */
	public static function gap_label( $sec ) {
		$h = $sec / HOUR_IN_SECONDS;
		return ( $h == (int) $h ) ? (int) $h . '時間' : rtrim( rtrim( number_format( $h, 1 ), '0' ), '.' ) . '時間';
	}

	/* ================= 描画 ================= */

	/**
	 * @param string $start Y-m-d
	 * @param int    $days  表示日数
	 * @param string $edit_base 予約バーのリンク先（{id}置換）
	 * @param array  $opts  new_url:新規予約URL, endpoint:操作用URL, token:認証用
	 */
	public static function render( $start, $days, $edit_base, $opts = array() ) {
		$vehicles  = BV_DB::get_vehicles();
		/* 店舗限定ポータル：表示する車両の場所・予約の店舗を絞る */
		if ( ! empty( $opts['locations'] ) ) {
			$vehicles = array_values( array_filter( $vehicles, function ( $v ) use ( $opts ) { return in_array( $v->location, $opts['locations'], true ); } ) );
		}
		$locations = BV_Util::locations();
		$classes   = BV_Util::classes();
		$range_s   = $start . ' 00:00:00';
		$range_e   = date( 'Y-m-d', strtotime( $start ) + $days * DAY_IN_SECONDS ) . ' 00:00:00';
		$total_w   = $days * 2 * self::CELL;
		$range_sec = strtotime( $range_e ) - strtotime( $range_s );

		$reservations = BV_DB::get_reservations( array(
			'overlap' => array( $range_s, $range_e ),
			'exclude_cancelled' => true,
		) );
		$by_vehicle = array();
		foreach ( $reservations as $r ) {
			if ( ! empty( $opts['stores'] ) && ! in_array( $r->store, $opts['stores'], true ) ) continue;
			$by_vehicle[ (int) $r->vehicle_id ][] = $r;
		}

		$blocks_by_vehicle = array();
		foreach ( BV_DB::get_blocks( $range_s, $range_e ) as $b ) $blocks_by_vehicle[ (int) $b->vehicle_id ][] = $b;

		$cell2 = self::CELL * 2;
		$interactive = ! empty( $opts['endpoint'] );
		$gap_sec = BV_Availability::turnaround_seconds();

		self::styles( $total_w, $cell2 );

		/* 凡例と操作ヒント（バーの色＝貸渡店舗） */
		echo '<div class="bvg-legend">';
		echo '<span style="font-weight:600">バーの色＝貸渡店舗：</span>';
		foreach ( BV_Util::stores() as $sk => $st ) {
			echo '<span><i style="background:' . esc_attr( BV_Util::store_color( $sk ) ) . '"></i>' . esc_html( $st['ja'] ) . '</span>';
		}
		echo '<span><i class="bvg-lg-pending"></i>仮予約（斜線）</span>';
		echo '<span><i class="bvg-lg-returned"></i>返却済み</span>';
		echo '<span><i class="bvg-lg-block"></i>貸出停止</span>';
		if ( $gap_sec > 0 ) {
			echo '<span><i class="bvg-lg-gap"></i>清掃・点検インターバル（' . esc_html( self::gap_label( $gap_sec ) ) . '）</span>';
		}
		echo '</div>';

		if ( $interactive ) {
			echo '<div class="bvg-help">';
			echo '<strong>操作方法：</strong>バーを<b>上下にドラッグ</b>で車両変更／<b>左右にドラッグ</b>で日程移動／<b>右端をドラッグ</b>で期間を伸縮／<b>空き枠をドラッグ</b>で新規予約・貸出停止／予約バーを<b>クリック</b>で詳細編集';
			echo '<br>貸出停止（グレーのバー）も同じように<b>ドラッグで移動・期間変更</b>できます。<b>クリック</b>すると解除されます。';
			echo '</div>';
			echo '<div id="bvg-msg" class="bvg-msg" style="display:none"></div>';
		}

		echo '<div class="bvg-wrap' . ( $interactive ? ' bvg-interactive' : '' ) . '"';
		if ( $interactive ) {
			echo ' data-endpoint="' . esc_attr( $opts['endpoint'] ) . '"';
			echo ' data-token="' . esc_attr( $opts['token'] ?? '' ) . '"';
			echo ' data-newurl="' . esc_attr( $opts['new_url'] ?? '' ) . '"';
			echo ' data-start="' . esc_attr( $start ) . '" data-days="' . (int) $days . '" data-cell="' . self::CELL . '"';
		}
		echo '>';

		/* 車両名の列 */
		echo '<div class="bvg-names"><div class="bvg-head">車両</div>';
		foreach ( $vehicles as $v ) {
			$lc = isset( $locations[ $v->location ] ) ? $locations[ $v->location ]['color'] : '#888';
			$lname = isset( $locations[ $v->location ] ) ? $locations[ $v->location ]['ja'] : '';
			echo '<div class="bvg-row" title="' . esc_attr( $v->name . '／' . BV_Util::label( $classes, $v->class ) . ( $lname ? '／所属：' . $lname : '' ) ) . '">';
			echo '<i style="background:' . esc_attr( $lc ) . '"></i>' . esc_html( $v->name );
			echo '<small>' . esc_html( BV_Util::label( $classes, $v->class ) ) . '</small></div>';
		}
		echo '</div>';

		echo '<div class="bvg-scroll"><div class="bvg-inner">';

		/* 日付ヘッダー */
		echo '<div class="bvg-datehead">';
		$wd_ja = array( '日', '月', '火', '水', '木', '金', '土' );
		$today = current_time( 'Y-m-d' );
		for ( $i = 0; $i < $days; $i++ ) {
			$ts = strtotime( $start ) + $i * DAY_IN_SECONDS;
			$w  = (int) date( 'w', $ts );
			$cls = ( 6 === $w ) ? ' sat' : ( ( 0 === $w ) ? ' sun' : '' );
			if ( date( 'Y-m-d', $ts ) === $today ) $cls .= ' today';
			echo '<div class="d' . $cls . '">' . esc_html( date( 'n/j', $ts ) ) . '<small>' . esc_html( $wd_ja[ $w ] ) . '</small></div>';
		}
		echo '</div>';

		/* 車両レーン */
		foreach ( $vehicles as $v ) {
			echo '<div class="bvg-lane" data-vehicle="' . (int) $v->id . '" data-vname="' . esc_attr( $v->name ) . '"';
			echo ' data-vclass="' . esc_attr( $v->class ) . '" data-vclassname="' . esc_attr( BV_Util::label( $classes, $v->class ) ) . '">';

			/* 貸出停止 */
			if ( ! empty( $blocks_by_vehicle[ (int) $v->id ] ) ) {
				foreach ( $blocks_by_vehicle[ (int) $v->id ] as $b ) {
					$s_ts = max( strtotime( $b->start_dt ), strtotime( $range_s ) );
					$e_ts = min( strtotime( $b->end_dt ), strtotime( $range_e ) );
					if ( $e_ts <= $s_ts ) continue;
					$left  = ( $s_ts - strtotime( $range_s ) ) / $range_sec * $total_w;
					$width = max( 16, ( $e_ts - $s_ts ) / $range_sec * $total_w - 2 );
					$label = '停止' . ( $b->reason ? '：' . $b->reason : '' );
					$btip = date( 'n/j H:i', strtotime( $b->start_dt ) ) . '〜' . date( 'n/j H:i', strtotime( $b->end_dt ) ) . ' ' . $label
						. '（ドラッグで移動・右端で期間変更／クリックで解除）';
					echo '<div class="bvg-block" data-block="' . (int) $b->id . '" data-reason="' . esc_attr( $b->reason ) . '"';
					echo ' style="left:' . round( $left ) . 'px;width:' . round( $width ) . 'px" title="' . esc_attr( $btip ) . '">';
					echo '<span class="bvg-block-label">' . esc_html( $label ) . '</span>';
					echo '<span class="bvg-handle"></span></div>';
				}
			}

			/* 予約バー */
			if ( ! empty( $by_vehicle[ (int) $v->id ] ) ) {
				foreach ( $by_vehicle[ (int) $v->id ] as $r ) {
					$s_ts = max( strtotime( $r->pickup_dt ), strtotime( $range_s ) );
					$e_ts = min( strtotime( $r->return_dt ), strtotime( $range_e ) );
					if ( $e_ts <= $s_ts ) continue;
					$left  = ( $s_ts - strtotime( $range_s ) ) / $range_sec * $total_w;
					$width = max( 20, ( $e_ts - $s_ts ) / $range_sec * $total_w - 2 );
					/* バーの色は「貸渡店舗」の色。車両の所属ではなく、どの店舗の予約かが分かるようにする */
					$loc   = BV_Util::store_color( $r->store );
					$who = trim( $r->sei . $r->mei );
					if ( '' === $who ) $who = '（名前未入力）';
					$label = date( 'n/j H:i', strtotime( $r->pickup_dt ) ) . '〜' . date( 'n/j H:i', strtotime( $r->return_dt ) ) . ' ' . $who;
					$tip   = $label . '（' . BV_Util::label( BV_Util::statuses(), $r->status ) . '／' . BV_Util::label( $classes, $r->vehicle_class ) . '）';
					if ( 'none' !== $r->shuttle ) $tip .= '／送迎あり';
					/* 返却後インターバル（清掃・点検）の帯 */
					if ( $gap_sec > 0 && strtotime( $r->return_dt ) < strtotime( $range_e ) ) {
						$g_s = strtotime( $r->return_dt );
						$g_e = min( $g_s + $gap_sec, strtotime( $range_e ) );
						if ( $g_e > $g_s ) {
							$gl = ( $g_s - strtotime( $range_s ) ) / $range_sec * $total_w;
							$gw = max( 3, ( $g_e - $g_s ) / $range_sec * $total_w );
							echo '<div class="bvg-gap" style="left:' . round( $gl ) . 'px;width:' . round( $gw ) . 'px" title="' . esc_attr( '清掃・点検インターバル（' . self::gap_label( $gap_sec ) . '）' ) . '"></div>';
						}
					}
					$url   = str_replace( '{id}', $r->id, $edit_base );
					echo '<div class="bvg-bar ' . esc_attr( $r->status ) . '" data-res="' . (int) $r->id . '" data-code="' . esc_attr( $r->code ) . '"';
					echo ' data-url="' . esc_url( $url ) . '" data-class="' . esc_attr( $r->vehicle_class ) . '"';
					echo ' data-classname="' . esc_attr( BV_Util::label( $classes, $r->vehicle_class ) ) . '"';
					echo ' style="left:' . round( $left ) . 'px;width:' . round( $width ) . 'px;background-color:' . esc_attr( $loc ) . '" title="' . esc_attr( $tip ) . '">';
					echo '<span class="bvg-bar-label">' . esc_html( $label ) . '</span>';
					if ( 'none' !== $r->shuttle ) echo '<span class="bvg-sh">送</span>';
					echo '<span class="bvg-handle"></span></div>';
				}
			}
			echo '</div>';
		}

		/* 未割当の予約 */
		if ( ! empty( $by_vehicle[0] ) ) {
			echo '</div></div></div>';
			echo '<div class="bvg-unassigned"><strong>車両未割当の予約：</strong>';
			foreach ( $by_vehicle[0] as $r ) {
				$url = str_replace( '{id}', $r->id, $edit_base );
				echo '<a class="bvg-un-item" href="' . esc_url( $url ) . '">' . esc_html( $r->code . ' ' . $r->sei . $r->mei . ' ' . date( 'n/j', strtotime( $r->pickup_dt ) ) . '〜' . date( 'n/j', strtotime( $r->return_dt ) ) . '（' . BV_Util::label( $classes, $r->vehicle_class ) . '）' ) . '</a>';
			}
			echo '</div>';
		} else {
			echo '</div></div></div>';
		}

		if ( $interactive ) self::script();
	}

	protected static function styles( $total_w, $cell2 ) {
		echo '<style>
		.bvg-wrap{display:flex;border:1px solid #ccd0d4;background:#fff;max-width:100%;overflow:hidden;border-radius:4px}
		.bvg-names{flex:0 0 170px;border-right:2px solid #999}
		.bvg-names .bvg-row,.bvg-names .bvg-head{height:44px;padding:4px 8px;border-bottom:1px solid #e2e4e7;font-size:12px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;background:#fff;box-sizing:border-box}
		.bvg-names .bvg-row i{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px}
		.bvg-names .bvg-row small{display:block;color:#888;font-size:10px;margin-left:14px;line-height:1.1}
		.bvg-names .bvg-head{background:#f0f0f1;font-weight:600;height:46px;line-height:38px}
		.bvg-scroll{overflow-x:auto;flex:1}
		.bvg-inner{position:relative;width:' . $total_w . 'px}
		.bvg-datehead{display:flex;height:46px;border-bottom:1px solid #999;background:#f0f0f1}
		.bvg-datehead .d{flex:0 0 ' . $cell2 . 'px;width:' . $cell2 . 'px;box-sizing:border-box;border-right:1px solid #c3c4c7;font-size:11px;text-align:center;padding-top:6px}
		.bvg-datehead .d small{display:block;color:#666}
		.bvg-datehead .d.sat{background:#eaf3fb}.bvg-datehead .d.sun{background:#fbeaea}
		.bvg-datehead .d.today{background:#fff3cd;font-weight:700}
		.bvg-lane{position:relative;height:44px;border-bottom:1px solid #e2e4e7;box-sizing:border-box;
			background-image:repeating-linear-gradient(to right,transparent,transparent ' . ( self::CELL - 1 ) . 'px,#eee ' . ( self::CELL - 1 ) . 'px,#eee ' . self::CELL . 'px),repeating-linear-gradient(to right,transparent,transparent ' . ( $cell2 - 1 ) . 'px,#c3c4c7 ' . ( $cell2 - 1 ) . 'px,#c3c4c7 ' . $cell2 . 'px)}
		.bvg-bar{position:absolute;top:6px;height:30px;border-radius:4px;color:#fff;font-size:11px;line-height:30px;padding:0 6px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;box-sizing:border-box;text-decoration:none;display:block;box-shadow:0 1px 2px rgba(0,0,0,.3)}
		.bvg-bar:hover{opacity:.9;color:#fff}
		.bvg-bar.returned{opacity:.45}
		.bvg-bar.pending{background-image:repeating-linear-gradient(45deg,rgba(255,255,255,.25),rgba(255,255,255,.25) 6px,transparent 6px,transparent 12px)}
		.bvg-bar .bvg-sh{background:rgba(0,0,0,.35);border-radius:3px;padding:0 4px;margin-left:4px;font-size:10px}
		.bvg-block{position:absolute;top:6px;height:30px;border-radius:4px;background:#9aa0a6;color:#fff;font-size:11px;line-height:30px;padding:0 6px;overflow:hidden;white-space:nowrap;box-sizing:border-box;
			background-image:repeating-linear-gradient(135deg,rgba(0,0,0,.15),rgba(0,0,0,.15) 5px,transparent 5px,transparent 10px)}
		.bvg-legend{margin:8px 0;font-size:12px}
		.bvg-legend span{display:inline-block;margin-right:12px}
		.bvg-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;vertical-align:-1px;margin-right:4px;background:#888}
		.bvg-lg-pending{background-image:repeating-linear-gradient(45deg,rgba(255,255,255,.35),rgba(255,255,255,.35) 4px,transparent 4px,transparent 8px)}
		.bvg-lg-returned{opacity:.45}
		.bvg-lg-block{background:#9aa0a6;background-image:repeating-linear-gradient(135deg,rgba(0,0,0,.2),rgba(0,0,0,.2) 3px,transparent 3px,transparent 6px)}
		.bvg-lg-gap{background:#f0c987}
		.bvg-gap{position:absolute;top:6px;height:30px;background:repeating-linear-gradient(90deg,#f0c987,#f0c987 3px,#fbe6c6 3px,#fbe6c6 6px);border-radius:0 3px 3px 0;opacity:.85;pointer-events:none}
		.bvg-help{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px;font-size:12px;margin-bottom:8px;line-height:1.7}
		.bvg-msg{padding:10px 14px;border-radius:4px;margin-bottom:8px;font-size:13px}
		.bvg-msg.ok{background:#edfaef;border:1px solid #00a32a}
		.bvg-msg.ng{background:#fcf0f1;border:1px solid #d63638}
		.bvg-unassigned{margin-top:8px;padding:8px 12px;background:#fff8e5;border:1px solid #e6c34a;border-radius:4px;font-size:12px}
		.bvg-un-item{display:inline-block;margin:2px 8px 2px 0;padding:2px 8px;background:#fff;border:1px solid #ccc;border-radius:3px;text-decoration:none}
		/* 対話操作 */
		.bvg-interactive .bvg-bar{cursor:grab}
		.bvg-interactive .bvg-bar.dragging{cursor:grabbing;opacity:.75;z-index:20;box-shadow:0 4px 10px rgba(0,0,0,.4)}
		.bvg-interactive .bvg-handle{position:absolute;right:0;top:0;width:8px;height:100%;cursor:ew-resize;background:rgba(255,255,255,.35);border-radius:0 4px 4px 0}
		.bvg-interactive .bvg-block{cursor:move}
		.bvg-block-label{pointer-events:none}
		.bvg-interactive .bvg-lane.drop-target{background-color:#e8f4ff}
		.bvg-sel{position:absolute;top:6px;height:30px;border-radius:4px;background:rgba(34,113,177,.35);border:2px dashed #2271b1;pointer-events:none}
		</style>';
	}

	protected static function script() {
		?>
<script>
(function () {
	var wrap = document.querySelector('.bvg-interactive');
	if (!wrap || wrap.dataset.bound) return;
	wrap.dataset.bound = '1';

	var CELL = parseInt(wrap.dataset.cell, 10) || 36;
	var DAY_W = CELL * 2;                       /* 1日の幅 */
	var START = wrap.dataset.start;
	var ENDPOINT = wrap.dataset.endpoint;
	var TOKEN = wrap.dataset.token;
	var NEWURL = wrap.dataset.newurl;
	var lanes = Array.prototype.slice.call(wrap.querySelectorAll('.bvg-lane'));
	var msgBox = document.getElementById('bvg-msg');

	function msg(text, ok) {
		if (!msgBox) { alert(text); return; }
		msgBox.className = 'bvg-msg ' + (ok ? 'ok' : 'ng');
		msgBox.textContent = text;
		msgBox.style.display = '';
		if (ok) setTimeout(function () { location.reload(); }, 700);
	}
	function post(action, data) {
		var body = Object.assign({ action: action, token: TOKEN }, data);
		return fetch(ENDPOINT, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify(body)
		}).then(function (r) { return r.json(); })
		  .then(function (j) { msg(j.message || '', !!j.ok); })
		  .catch(function () { msg('通信に失敗しました。', false); });
	}
	function addDaysStr(iso, n) {
		var p = iso.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2] + n);
		return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
	}

	/* タッチをマウスイベントに変換（スマホ対応） */
	function toMouse(type, t) {
		var ev = new MouseEvent(type, {
			bubbles: true, cancelable: true, clientX: t.clientX, clientY: t.clientY
		});
		return ev;
	}
	var touchTarget = null;
	wrap.addEventListener('touchstart', function (e) {
		if (e.touches.length !== 1) return;
		var t = e.touches[0];
		touchTarget = document.elementFromPoint(t.clientX, t.clientY);
		if (touchTarget && (touchTarget.closest('.bvg-bar') || touchTarget.closest('.bvg-block'))) {
			e.preventDefault();
			touchTarget.dispatchEvent(toMouse('mousedown', t));
		}
	}, { passive: false });
	wrap.addEventListener('touchmove', function (e) {
		if (!drag || e.touches.length !== 1) return;
		e.preventDefault();
		document.dispatchEvent(toMouse('mousemove', e.touches[0]));
	}, { passive: false });
	wrap.addEventListener('touchend', function (e) {
		if (!drag) return;
		var t = e.changedTouches[0];
		document.dispatchEvent(toMouse('mouseup', t));
	});

	/* ---------- 予約バーのドラッグ ---------- */
	var drag = null;

	wrap.addEventListener('mousedown', function (e) {
		var handle = e.target.closest('.bvg-handle');
		var bar = e.target.closest('.bvg-bar') || e.target.closest('.bvg-block');
		if (!bar) return;
		e.preventDefault();
		drag = {
			bar: bar, kind: bar.classList.contains('bvg-block') ? 'block' : 'res',
			mode: handle ? 'resize' : 'move',
			startX: e.clientX, startY: e.clientY,
			origLeft: parseFloat(bar.style.left), origWidth: parseFloat(bar.style.width),
			lane: bar.parentNode, moved: false, shiftDays: 0, targetLane: null
		};
		bar.classList.add('dragging');
	});

	document.addEventListener('mousemove', function (e) {
		if (!drag) return;
		var dx = e.clientX - drag.startX;
		var dy = e.clientY - drag.startY;
		if (Math.abs(dx) > 3 || Math.abs(dy) > 3) drag.moved = true;

		var days = Math.round(dx / DAY_W);
		drag.shiftDays = days;

		if (drag.mode === 'resize') {
			drag.bar.style.width = Math.max(20, drag.origWidth + days * DAY_W) + 'px';
			return;
		}
		drag.bar.style.left = (drag.origLeft + days * DAY_W) + 'px';

		/* 縦方向：ドロップ先レーンの判定 */
		var el = document.elementFromPoint(e.clientX, e.clientY);
		var lane = el ? el.closest('.bvg-lane') : null;
		lanes.forEach(function (l) { l.classList.remove('drop-target'); });
		if (lane && lane !== drag.lane) { lane.classList.add('drop-target'); drag.targetLane = lane; }
		else drag.targetLane = null;
	});

	document.addEventListener('mouseup', function (e) {
		if (!drag) return;
		var d = drag; drag = null;
		d.bar.classList.remove('dragging');
		lanes.forEach(function (l) { l.classList.remove('drop-target'); });

		if (!d.moved) { /* 動かさなければクリック扱い */
			if (d.kind === 'block') {
				if (confirm('この貸出停止を解除しますか？')) post('block_delete', { id: d.bar.dataset.block });
			} else if (d.bar.dataset.url) {
				window.location.href = d.bar.dataset.url;
			}
			return;
		}

		/* ---- 貸出停止バー ---- */
		if (d.kind === 'block') {
			var bname = d.bar.dataset.reason ? '貸出停止（' + d.bar.dataset.reason + '）' : '貸出停止';
			if (d.mode === 'resize') {
				if (!d.shiftDays) { d.bar.style.width = d.origWidth + 'px'; return; }
				var bw = d.shiftDays > 0 ? d.shiftDays + '日延長' : Math.abs(d.shiftDays) + '日短縮';
				if (!confirm(bname + 'の期間を' + bw + 'します。よろしいですか？')) {
					d.bar.style.width = d.origWidth + 'px'; return;
				}
				post('block_resize', { id: d.bar.dataset.block, shift_days: d.shiftDays });
				return;
			}
			var bVehicle = d.targetLane ? d.targetLane.dataset.vehicle : d.lane.dataset.vehicle;
			if (!d.shiftDays && !d.targetLane) { d.bar.style.left = d.origLeft + 'px'; return; }
			var bparts = [];
			if (d.targetLane) bparts.push('車両を「' + d.targetLane.dataset.vname + '」に変更');
			if (d.shiftDays) bparts.push('日程を' + (d.shiftDays > 0 ? '+' : '') + d.shiftDays + '日移動');
			if (!confirm(bname + 'の' + bparts.join('、') + 'します。よろしいですか？')) {
				d.bar.style.left = d.origLeft + 'px'; return;
			}
			post('block_move', { id: d.bar.dataset.block, vehicle_id: bVehicle, shift_days: d.shiftDays });
			return;
		}

		if (d.mode === 'resize') {
			if (!d.shiftDays) { d.bar.style.width = d.origWidth + 'px'; return; }
			var word = d.shiftDays > 0 ? d.shiftDays + '日延長' : Math.abs(d.shiftDays) + '日短縮';
			if (!confirm('予約 ' + d.bar.dataset.code + ' の返却日を' + word + 'します。よろしいですか？')) {
				d.bar.style.width = d.origWidth + 'px'; return;
			}
			post('resize', { id: d.bar.dataset.res, shift_days: d.shiftDays });
			return;
		}

		var newVehicle = d.targetLane ? d.targetLane.dataset.vehicle : d.lane.dataset.vehicle;
		if (!d.shiftDays && !d.targetLane) { d.bar.style.left = d.origLeft + 'px'; return; }

		var parts = [];
		if (d.targetLane) parts.push('車両を「' + d.targetLane.dataset.vname + '」に変更');
		if (d.shiftDays) parts.push('日程を' + (d.shiftDays > 0 ? '+' : '') + d.shiftDays + '日移動');

		/* クラスが変わる場合は事前に警告 */
		var extra = '';
		if (d.targetLane) {
			var newCls = d.targetLane.dataset.vclass, newClsName = d.targetLane.dataset.vclassname;
			var oldCls = d.bar.dataset.class, oldClsName = d.bar.dataset.classname;
			if (newCls && oldCls && newCls !== oldCls) {
				extra = '\n\n※車両クラスが「' + oldClsName + '」→「' + newClsName + '」に変わります。\n　予約のクラスと料金も自動で再計算されます。';
			}
		}
		if (!confirm('予約 ' + d.bar.dataset.code + ' の' + parts.join('、') + 'します。よろしいですか？' + extra)) {
			d.bar.style.left = d.origLeft + 'px'; return;
		}
		post('move', { id: d.bar.dataset.res, vehicle_id: newVehicle, shift_days: d.shiftDays });
	});

	/* 貸出停止の解除は、上のmouseup（動かさずクリック）で処理している */

	/* ---------- 空き枠のドラッグで新規作成 ---------- */
	var sel = null;
	wrap.addEventListener('mousedown', function (e) {
		if (e.target.closest('.bvg-bar') || e.target.closest('.bvg-block')) return;
		var lane = e.target.closest('.bvg-lane');
		if (!lane) return;
		e.preventDefault();
		var rect = lane.getBoundingClientRect();
		var x = e.clientX - rect.left;
		var box = document.createElement('div');
		box.className = 'bvg-sel';
		lane.appendChild(box);
		sel = { lane: lane, startX: x, box: box, rect: rect };
	});
	document.addEventListener('mousemove', function (e) {
		if (!sel) return;
		var x = e.clientX - sel.rect.left;
		var a = Math.min(sel.startX, x), b = Math.max(sel.startX, x);
		a = Math.floor(a / DAY_W) * DAY_W;
		b = Math.ceil(b / DAY_W) * DAY_W;
		sel.box.style.left = a + 'px';
		sel.box.style.width = Math.max(DAY_W, b - a) + 'px';
		sel.from = a / DAY_W; sel.to = Math.max(1, (b - a) / DAY_W);
	});
	document.addEventListener('mouseup', function () {
		if (!sel) return;
		var s = sel; sel = null;
		if (s.box.parentNode) s.box.parentNode.removeChild(s.box);
		if (s.from === undefined) return;

		var d1 = addDaysStr(START, s.from);
		var d2 = addDaysStr(START, s.from + s.to);        /* 翌日（返却日として使う） */
		var dLast = addDaysStr(START, s.from + s.to - 1); /* 選択した最終日 */

		var label = s.lane.dataset.vname + '\n' + d1 + ' 〜 ' + dLast + '（' + s.to + '日間）';

		var choice = prompt(label
			+ "\n\n1 = 新規予約を作成（貸出 " + d1 + " → 返却 " + d2 + "）"
			+ "\n2 = 貸出停止にする（" + d1 + " 00:00 → " + dLast + " 23:30）"
			+ "\n\n番号を入力してください", '1');
		if (!choice) return;
		if (choice.trim() === '2') {
			var reason = prompt('停止の理由（例：定期点検、車検、修理、貸切）', '整備');
			if (reason === null) return;
			post('block_add', {
				vehicle_id: s.lane.dataset.vehicle,
				start: d1 + ' 00:00:00', end: dLast + ' 23:30:00', reason: reason
			});
		} else if (choice.trim() === '1') {
			if (!NEWURL) { msg('この画面からは新規予約を作成できません。', false); return; }
			/* 作成後にガントの同じ表示位置へ戻れるよう、現在の表示範囲も渡す */
			var u = NEWURL + (NEWURL.indexOf('?') === -1 ? '?' : '&') +
				'pf_vehicle=' + encodeURIComponent(s.lane.dataset.vehicle) +
				'&pf_from=' + encodeURIComponent(d1) + '&pf_to=' + encodeURIComponent(d2) +
				'&pf_gstart=' + encodeURIComponent(START) +
				'&pf_gdays=' + encodeURIComponent(wrap.dataset.days || 14);
			window.location.href = u;
		}
	});
})();
</script>
		<?php
	}

	/** 期間ナビゲーション付きラッパー */
	public static function render_with_nav( $base_url, $edit_base, $opts = array() ) {
		$start = isset( $_GET['gstart'] ) ? sanitize_text_field( wp_unslash( $_GET['gstart'] ) ) : date( 'Y-m-d', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) $start = current_time( 'Y-m-d' );
		$days = isset( $_GET['gdays'] ) ? max( 7, min( 60, (int) $_GET['gdays'] ) ) : 14;
		$prev = date( 'Y-m-d', strtotime( $start ) - $days * DAY_IN_SECONDS );
		$next = date( 'Y-m-d', strtotime( $start ) + $days * DAY_IN_SECONDS );
		echo '<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'gstart' => $prev, 'gdays' => $days ), $base_url ) ) . '">« 前へ</a>';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'gstart' => date( 'Y-m-d', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS ), 'gdays' => $days ), $base_url ) ) . '">今日</a>';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'gstart' => $next, 'gdays' => $days ), $base_url ) ) . '">次へ »</a>';
		echo '<span>表示: ';
		foreach ( array( 7, 14, 30, 60 ) as $d ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'gstart' => $start, 'gdays' => $d ), $base_url ) ) . '"' . ( $d === $days ? ' style="font-weight:bold"' : '' ) . '>' . $d . '日</a> ';
		}
		echo '</span></p>';
		self::render( $start, $days, $edit_base, $opts );
	}
}
