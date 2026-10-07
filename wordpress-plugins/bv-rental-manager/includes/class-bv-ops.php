<?php
/**
 * 予約への操作（管理画面・スタッフポータル共通）
 *
 * 差額の追加請求・返金・お客様へのメッセージ送信を、どちらの画面からでも
 * 同じ手順・同じチェックで行えるようにまとめたもの。
 * 各メソッドは array( 'ok' => bool, 'msg' => 画面に出す文言 ) を返す。
 * 権限・nonce の確認は呼び出し側で行うこと。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BV_Ops {

	/** 操作した画面（管理メモに残す） */
	protected static function via_label( $via ) {
		return ( 'staff' === $via ) ? '（スタッフポータル）' : '';
	}

	protected static function add_memo( $r, $line ) {
		$memo = trim( (string) $r->admin_memo );
		$memo .= ( $memo ? "\n" : '' ) . $line;
		BV_DB::update_reservation( $r->id, array( 'admin_memo' => $memo ) );
	}

	protected static function res( $ok, $msg ) {
		return array( 'ok' => (bool) $ok, 'msg' => (string) $msg );
	}

	/* ---------- 追加請求（差額） ---------- */

	/** 差額の決済リンクを作ってお客様へ送る */
	public static function charge_addon( $r, $amount, $note, $via = 'admin' ) {
		$amount = (int) $amount;
		$note   = sanitize_text_field( (string) $note );
		if ( $amount < 1 ) return self::res( false, '追加請求の金額を1円以上で指定してください。' );
		if ( ! is_email( $r->email ) ) return self::res( false, 'メールアドレスが登録されていないため送信できません。' );
		if ( BV_Util::has_pending_addon( $r ) ) return self::res( false, 'すでに入金待ちの追加請求があります。取り消してから作り直してください。' );

		BV_DB::update_reservation( $r->id, array(
			'addon_amount'     => $amount,
			'addon_note'       => $note,
			'addon_status'     => 'quoted',
			'addon_link'       => '',
			'addon_order_id'   => '',
			'addon_payment_id' => '',
			'addon_paid_at'    => null,
		) );
		$r = BV_DB::get_reservation( $r->id );
		$link = BV_Square::create_addon_payment_link( $r );
		if ( is_wp_error( $link ) ) {
			/* リンクが作れなければ請求中のままにしない */
			BV_DB::update_reservation( $r->id, array( 'addon_status' => '', 'addon_amount' => 0, 'addon_note' => '' ) );
			return self::res( false, '追加請求の決済リンクを作成できませんでした: ' . $link->get_error_message() );
		}
		BV_DB::update_reservation( $r->id, array( 'addon_link' => $link['url'], 'addon_order_id' => $link['order_id'] ) );
		self::add_memo( $r, '[追加請求を発行 ' . current_time( 'Y-m-d H:i' ) . self::via_label( $via ) . '] '
			. BV_Util::money( $amount ) . ( $note ? '／' . $note : '' ) );
		$r = BV_DB::get_reservation( $r->id );
		BV_Mailer::send_addon_request( $r );
		return self::res( true, '差額 ' . BV_Util::money( $amount ) . ' の決済リンクをお客様へ送信しました。' );
	}

	public static function resend_addon( $r ) {
		if ( BV_Util::has_pending_addon( $r ) && $r->addon_link && is_email( $r->email ) ) {
			BV_Mailer::send_addon_request( $r );
			return self::res( true, '追加請求のメールを再送しました。' );
		}
		return self::res( false, '再送できる追加請求がありません。' );
	}

	public static function sync_addon( $r ) {
		$res = BV_Square::sync_payment_status( $r, 'addon' );
		return is_wp_error( $res ) ? self::res( false, $res->get_error_message() ) : self::res( true, $res );
	}

	public static function mark_addon_paid( $r, $via = 'admin' ) {
		if ( ! BV_Util::has_pending_addon( $r ) ) return self::res( false, '入金待ちの追加請求がありません。' );
		return self::res( true, BV_Square::mark_addon_paid( $r, '', 'staff' === $via ? '手動記録（スタッフポータル）' : '手動記録' ) );
	}

	public static function cancel_addon( $r, $via = 'admin' ) {
		if ( ! BV_Util::has_pending_addon( $r ) ) return self::res( false, '取り消せる追加請求がありません。' );
		self::add_memo( $r, '[追加請求を取り消し ' . current_time( 'Y-m-d H:i' ) . self::via_label( $via ) . '] ' . BV_Util::money( (int) $r->addon_amount ) );
		BV_DB::update_reservation( $r->id, array(
			'addon_status' => '', 'addon_amount' => 0, 'addon_note' => '',
			'addon_link' => '', 'addon_order_id' => '',
		) );
		return self::res( true, '追加請求を取り消しました。発行済みのリンクは使わないようお客様にご連絡ください。' );
	}

	/* ---------- 返金 ---------- */

	/** 実際に収納した額（返金の上限の基準） */
	public static function refund_base( $r ) {
		return (int) $r->paid_amount ?: (int) $r->price_total;
	}

	/** 返金できる残り */
	public static function refund_left( $r ) {
		return max( 0, self::refund_base( $r ) - (int) $r->refund_amount );
	}

	/** この予約をシステムから返金できるか（Square決済済み・残りあり） */
	public static function can_refund( $r ) {
		return $r && $r->paid_at && $r->square_payment_id && self::refund_left( $r ) > 0;
	}

	/**
	 * 返金を実行する
	 * @param string $mode policy / manual / pct100 など
	 */
	public static function refund( $r, $mode, $manual = '', $memo_note = '', $via = 'admin' ) {
		if ( ! $r->paid_at ) return self::res( false, 'この予約は未入金のため返金できません。' );
		$base      = self::refund_base( $r );
		$available = self::refund_left( $r );
		if ( $available < 1 ) return self::res( false, 'この予約はすでに全額返金済みです。' );

		$mode = sanitize_key( (string) $mode );
		if ( 'policy' === $mode ) {
			$c = BV_Util::cancel_charge( $r );
			$amount = (int) $c['refund'];
			$label = 'キャンセルポリシー適用（' . $c['label_ja'] . '／キャンセル料 ' . $c['pct'] . '%）';
		} elseif ( 'manual' === $mode ) {
			$amount = (int) preg_replace( '/[^0-9]/', '', (string) $manual );
			$label = '金額を指定';
		} elseif ( preg_match( '/^pct(\d+)$/', $mode, $m ) ) {
			$pct = max( 1, min( 100, (int) $m[1] ) );
			$amount = (int) round( $base * $pct / 100 );
			$label = $pct . '%返金';
		} else {
			return self::res( false, '返金方法を選んでください。' );
		}

		if ( $amount < 1 ) return self::res( false, '返金額が0円です。金額をご確認ください。' );
		if ( $amount > $available ) return self::res( false, '返金額が返金可能額（' . BV_Util::money( $available ) . '）を超えています。' );

		$res = BV_Square::refund( $r, $amount );
		if ( is_wp_error( $res ) ) return self::res( false, '返金エラー：' . $res->get_error_message() );

		$done = (int) $res;
		$r = BV_DB::get_reservation( $r->id );
		$memo_note = sanitize_text_field( (string) $memo_note );
		self::add_memo( $r, '[返金 ' . current_time( 'Y-m-d H:i' ) . self::via_label( $via ) . '] '
			. BV_Util::money( $done ) . '（' . $label . '）' . ( '' !== $memo_note ? '／' . $memo_note : '' ) );

		$r = BV_DB::get_reservation( $r->id );
		return self::res( true, BV_Util::money( $done ) . ' を返金しました（' . $label . '）。'
			. '返金合計 ' . BV_Util::money( (int) $r->refund_amount )
			. '／お預かり残 ' . BV_Util::money( self::refund_left( $r ) ) . '。' );
	}

	/* ---------- お客様へのメッセージ ---------- */

	/** 管理メモから直近の変更申請（お客様の文面）を拾う */
	public static function latest_change_request( $r ) {
		$lines = array_reverse( explode( "\n", (string) $r->admin_memo ) );
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\[変更申請 ([^\]]+)\]\s*(.*)$/u', trim( $line ), $m ) ) {
				return array( 'at' => $m[1], 'text' => $m[2] );
			}
		}
		return null;
	}

	/**
	 * 店舗のアドレスからお客様へメッセージを送る
	 * 本文は入力されたものだけ（管理画面へのリンクなどは含めない）。
	 */
	public static function message_customer( $r, $subject, $body, $via = 'admin' ) {
		$subject = sanitize_text_field( (string) $subject );
		$body    = sanitize_textarea_field( (string) $body );
		if ( ! is_email( $r->email ) ) return self::res( false, 'メールアドレスが登録されていないため送信できません。' );
		if ( '' === trim( $body ) ) return self::res( false, '本文を入力してください。' );
		if ( ! BV_Mailer::send_customer_message( $r, $subject, $body ) ) {
			return self::res( false, 'メールを送信できませんでした。時間をおいて再度お試しください。' );
		}
		$short = function_exists( 'mb_strimwidth' ) ? mb_strimwidth( preg_replace( '/\s+/u', ' ', $body ), 0, 160, '…', 'UTF-8' ) : substr( $body, 0, 160 );
		self::add_memo( $r, '[お客様へ連絡 ' . current_time( 'Y-m-d H:i' ) . self::via_label( $via ) . '] '
			. ( '' !== $subject ? '件名「' . $subject . '」 ' : '' ) . $short );
		return self::res( true, 'お客様（' . $r->email . '）へメッセージを送信しました。' );
	}
}
