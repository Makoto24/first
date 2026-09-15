<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 印刷物：予約票・受付表・領収書（A4 1枚最適化、日英切替）
 */
class BV_Print {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	public static function url( $r, $type ) {
		return add_query_arg( array(
			'bv_print' => $type,
			'res'      => $r->id,
			'key'      => self::key( $r ),
		), home_url( '/' ) );
	}

	protected static function key( $r ) {
		return substr( hash_hmac( 'sha256', 'bvprint-' . $r->id . '-' . $r->code, wp_salt( 'auth' ) ), 0, 20 );
	}

	public static function maybe_render() {
		if ( empty( $_GET['bv_print'] ) || empty( $_GET['res'] ) ) return;
		$r = BV_DB::get_reservation( (int) $_GET['res'] );
		if ( ! $r ) wp_die( 'Not found' );
		$key_ok = isset( $_GET['key'] ) && hash_equals( self::key( $r ), (string) $_GET['key'] );
		if ( ! $key_ok && ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		$type = sanitize_key( $_GET['bv_print'] );
		if ( ! in_array( $type, array( 'voucher', 'checkin', 'receipt' ), true ) ) wp_die( 'Bad type' );

		$s = BV_Util::settings();
		$lang = $r->lang;
		$L = function ( $ja, $en ) use ( $lang ) { return 'en' === $lang ? $en : $ja; };
		$seal = BV_Util::company_seal_url();
		$v = $r->vehicle_id ? BV_DB::get_vehicle( $r->vehicle_id ) : null;
		$bd = json_decode( $r->price_breakdown, true );
		$is_invoice_issuer = BV_Util::is_invoice_issuer();
		$titles = array(
			'voucher' => $L( '予約票', 'Reservation Voucher' ),
			'checkin' => $L( '受付表（貸渡証）', 'Rental Agreement / Check-in Sheet' ),
			'receipt' => $is_invoice_issuer ? $L( '領収書（適格請求書）', 'Receipt (Qualified Invoice)' ) : $L( '領収書', 'Receipt' ),
		);

		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8"><title>' . esc_html( $titles[ $type ] . ' ' . $r->code ) . '</title>';
		echo '<style>
		@page{size:A4;margin:12mm}
		body{font-family:"Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;color:#111;font-size:11px;line-height:1.5;max-width:186mm;margin:0 auto}
		h1{font-size:18px;border-bottom:2px solid #333;padding-bottom:4px;margin:0 0 8px}
		table{border-collapse:collapse;width:100%;margin-bottom:8px}
		th,td{border:1px solid #666;padding:4px 6px;text-align:left;vertical-align:top}
		th{background:#f0f0f0;width:26%;font-weight:600}
		.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px}
		.company{text-align:right;font-size:10px;position:relative}
		.company .seal{position:absolute;right:0;top:14px;width:60px;opacity:.9}
		.total{font-size:15px;font-weight:bold}
		.small{font-size:9px;color:#333}
		.sign{border:1px solid #666;height:56px;margin-top:4px}
		.noprint{margin:10px 0}
		.bvbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0 12px}
		.bvbar button{padding:10px 22px;font-size:14px;border:0;border-radius:6px;background:#2271b1;color:#fff;cursor:pointer}
		.bvbar button.sub{background:#646970}
		.bvbar button:disabled{opacity:.6;cursor:default}
		.bvhint{background:#fff8e5;border:1px solid #e0b900;color:#6b5200;padding:8px 12px;border-radius:6px;font-size:12px;margin:0 0 12px;line-height:1.6}
		.bvp-ed{font:inherit;color:inherit;padding:2px 4px;border:1px dashed #b7bcc2;border-radius:3px;background:#fffdf5;box-sizing:border-box}
		.bvp-ed:focus{outline:2px solid #2271b1;background:#fff}
		.bvp-amt{width:92px;text-align:right}
		.bvp-del{border:0;background:#f0f0f0;color:#b32d2e;border-radius:4px;cursor:pointer;font-size:13px;padding:3px 8px}
		.bvp-addbtn{border:0;background:#f0f0f0;color:#2271b1;border-radius:4px;cursor:pointer;font-size:12px;padding:5px 10px;margin-right:6px}
		@media print{
			.noprint{display:none}
			.bvp-ed{border:0;background:transparent;padding:0}
		}
		</style></head><body>';

		/* 操作バー（印刷・PDF保存） */
		echo '<div class="noprint bvbar">';
		echo '<button type="button" id="bvp-print">' . esc_html( $L( '印刷する', 'Print' ) ) . '</button>';
		echo '<button type="button" class="sub" id="bvp-pdf">' . esc_html( $L( 'PDFをダウンロード', 'Download PDF' ) ) . '</button>';
		echo '<span class="small" id="bvp-msg"></span>';
		echo '</div>';
		if ( 'receipt' === $type ) {
			echo '<p class="noprint bvhint">' . esc_html( $L(
				'点線枠の項目（宛名・但し書き・取引年月日・内訳の項目名と金額）はこの画面で書き換えられます。金額を変えると、合計・税抜金額・消費税額を自動で計算し直します。変更内容は保存されません（この印刷・PDFだけの一時的な修正です）。',
				'Fields with a dashed border (recipient, description, date, item names and amounts) can be edited on this screen. Totals and tax are recalculated automatically. Edits are not saved - they apply to this printout / PDF only.'
			) ) . '</p>';
		}
		echo '<div id="bvp-doc">';

		echo '<div class="header"><h1>' . esc_html( $titles[ $type ] ) . '</h1><div class="company">';
		echo esc_html( $s['company_name'] ) . '<br>' . esc_html( $s['company_address'] ) . '<br>';
		echo esc_html( $L( '代表者：', 'Representative: ' ) . $s['company_rep'] ) . '　TEL: ' . esc_html( $s['company_tel'] );
		if ( $is_invoice_issuer ) echo '<br>' . esc_html( $L( '登録番号：', 'Invoice Reg. No.: ' ) . $s['company_invoice'] );
		if ( $seal ) echo '<img class="seal" src="' . esc_url( $seal ) . '" alt="">';
		echo '</div></div>';

		$name = ( 'en' === $lang ) ? trim( $r->mei . ' ' . $r->sei ) : trim( $r->sei . ' ' . $r->mei );

		/* 領収書の宛名は URL の to= でも初期値を指定できる */
		if ( 'receipt' === $type ) {
			$to_name = isset( $_GET['to'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';
			if ( '' !== $to_name ) $name = mb_substr( $to_name, 0, 60 );
		}

		if ( 'receipt' === $type ) {
			/* ---- 領収書（適格請求書＝インボイス対応は設定で切替） ---- */
			$is_invoice = BV_Util::is_invoice_issuer();
			$tax_rate = 10; /* レンタカー料金・送迎とも標準税率10% */

			/* 送迎料金は支払済みの場合のみ合算する */
			$car_incl = (int) $r->price_total;
			$shuttle_incl = ( $r->shuttle_paid_at && (int) $r->shuttle_fee > 0 ) ? (int) $r->shuttle_fee : 0;
			$total_incl = $car_incl + $shuttle_incl;

			/* 税率ごとに1回だけ端数処理（切り捨て） */
			$tax_amount = (int) floor( $total_incl * $tax_rate / ( 100 + $tax_rate ) );
			$total_excl = $total_incl - $tax_amount;

			/* 発行日：両方支払済みなら遅い方、未払いなら本日 */
			$paid_ts = array();
			if ( $r->paid_at ) $paid_ts[] = strtotime( $r->paid_at );
			if ( $r->shuttle_paid_at ) $paid_ts[] = strtotime( $r->shuttle_paid_at );
			$issue_ts = $paid_ts ? max( $paid_ts ) : current_time( 'timestamp' );
			$issue_date = date_i18n( ( 'en' === $lang ? 'M j, Y' : 'Y年n月j日' ), $issue_ts );
			$receipt_no = $r->code . '-R';


			echo '<table>';
			echo '<tr><th>' . esc_html( $L( '宛名（書類の交付を受ける事業者の氏名又は名称）', 'To (recipient)' ) ) . '</th><td class="total">';
			echo '<input type="text" class="bvp-ed bvp-bold" id="bvp-to" value="' . esc_attr( $name ) . '" maxlength="60" style="width:62%;font-weight:bold"> ' . esc_html( $L( '様', '' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '領収書番号', 'Receipt No.' ) ) . '</th><td>' . esc_html( $receipt_no ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '取引年月日', 'Transaction date' ) ) . '</th><td><input type="text" class="bvp-ed" id="bvp-date" value="' . esc_attr( $issue_date ) . '" maxlength="40" style="width:220px"></td></tr>';
			$desc = $shuttle_incl > 0
				? $L( 'レンタカー貸渡料金および送迎料金（予約番号 ', 'Car rental and shuttle service (Reservation No. ' )
				: $L( 'レンタカー貸渡料金（予約番号 ', 'Car rental service (Reservation No. ' );
			echo '<tr><th>' . esc_html( $L( '取引内容（但し書き）', 'Description' ) ) . '</th><td>';
			echo '<input type="text" class="bvp-ed" id="bvp-desc" value="' . esc_attr( $desc . $r->code . '）' ) . '" maxlength="120" style="width:96%"><br>';
			echo esc_html( $L( '貸渡期間：', 'Rental period: ' ) . date( 'Y-m-d H:i', strtotime( $r->pickup_dt ) ) . ' 〜 ' . date( 'Y-m-d H:i', strtotime( $r->return_dt ) ) );
			echo '<br>' . esc_html( $L( '車両クラス：', 'Vehicle class: ' ) . BV_Util::label( BV_Util::classes(), $r->vehicle_class, $lang ) );
			echo '<br>' . esc_html( $L( '貸渡店舗：', 'Branch: ' ) . BV_Util::label( BV_Util::stores(), $r->store, $lang ) ) . '</td></tr>';
			echo '</table>';

			/* 税率ごとに区分した対価の額・消費税額 */
			echo '<table style="margin-top:6px">';
			echo '<tr><th style="width:34%">' . esc_html( $L( '税率区分', 'Tax rate' ) ) . '</th>';
			echo '<th style="width:22%;text-align:right">' . esc_html( $L( '税抜金額', 'Amount (excl. tax)' ) ) . '</th>';
			echo '<th style="width:22%;text-align:right">' . esc_html( $L( '消費税額', 'Consumption tax' ) ) . '</th>';
			echo '<th style="width:22%;text-align:right">' . esc_html( $L( '税込金額', 'Amount (incl. tax)' ) ) . '</th></tr>';
			echo '<tr><td>' . esc_html( $L( '10% 対象（標準税率）', '10% (standard rate)' ) ) . '</td>';
			echo '<td style="text-align:right" id="bvp-excl">' . esc_html( BV_Util::money( $total_excl, $lang ) ) . '</td>';
			echo '<td style="text-align:right" id="bvp-tax">' . esc_html( BV_Util::money( $tax_amount, $lang ) ) . '</td>';
			echo '<td style="text-align:right" id="bvp-incl">' . esc_html( BV_Util::money( $total_incl, $lang ) ) . '</td></tr>';
			echo '<tr><td>' . esc_html( $L( '8% 対象（軽減税率）', '8% (reduced rate)' ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( BV_Util::money( 0, $lang ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( BV_Util::money( 0, $lang ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( BV_Util::money( 0, $lang ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '合計', 'Total' ) ) . '</th>';
			echo '<td style="text-align:right" id="bvp-texcl">' . esc_html( BV_Util::money( $total_excl, $lang ) ) . '</td>';
			echo '<td style="text-align:right" id="bvp-ttax">' . esc_html( BV_Util::money( $tax_amount, $lang ) ) . '</td>';
			echo '<td style="text-align:right" class="total" id="bvp-tincl">' . esc_html( BV_Util::money( $total_incl, $lang ) ) . '</td></tr>';
			echo '</table>';

			/* 明細（項目名・金額を画面上で修正できる） */
			$bd = json_decode( (string) $r->price_breakdown, true );
			$items = array();
			if ( is_array( $bd ) && ! empty( $bd['lines'] ) ) {
				foreach ( $bd['lines'] as $line ) {
					$items[] = array( 'label' => (string) $line['label'], 'amount' => (int) $line['amount'] );
				}
			} elseif ( $car_incl > 0 ) {
				$items[] = array( 'label' => $L( 'レンタカー貸渡料金', 'Car rental' ), 'amount' => $car_incl );
			}
			if ( $shuttle_incl > 0 ) {
				$sh_label = $L( '送迎料金（', 'Shuttle service (' ) . BV_Util::label( BV_Util::shuttles(), $r->shuttle, $lang ) . '）';
				$items[] = array( 'label' => $sh_label, 'amount' => $shuttle_incl );
			}
			$cur = ( 'en' === $lang ) ? 'JPY ' : '¥';

			echo '<table style="margin-top:6px" id="bvp-items">';
			echo '<tr><th colspan="2" style="width:auto">' . esc_html( $L( '内訳（すべて10%対象・税込）', 'Breakdown (all 10% rate, tax incl.)' ) ) . '</th>';
			echo '<th class="noprint" style="width:46px"></th></tr>';
			foreach ( $items as $it ) {
				echo '<tr class="bvp-row"><td style="width:70%"><input type="text" class="bvp-ed bvp-lbl" value="' . esc_attr( $it['label'] ) . '" maxlength="120" style="width:98%"></td>';
				echo '<td style="text-align:right;white-space:nowrap">' . esc_html( $cur ) . '<input type="text" inputmode="numeric" class="bvp-ed bvp-amt" value="' . (int) $it['amount'] . '"></td>';
				echo '<td class="noprint" style="text-align:center"><button type="button" class="bvp-del">×</button></td></tr>';
			}
			echo '<tr class="noprint" id="bvp-tools"><td colspan="3">';
			echo '<button type="button" class="bvp-addbtn" id="bvp-add">＋ ' . esc_html( $L( '項目を追加', 'Add item' ) ) . '</button>';
			echo '<button type="button" class="bvp-addbtn" id="bvp-reset">↺ ' . esc_html( $L( '元に戻す', 'Reset' ) ) . '</button>';
			echo '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '合計（税込）', 'Total (incl. tax)' ) ) . '</th>';
			echo '<td style="text-align:right" class="total" id="bvp-sum">' . esc_html( BV_Util::money( $total_incl, $lang ) ) . '</td>';
			echo '<td class="noprint"></td></tr>';
			echo '</table>';

			echo '<table style="margin-top:6px">';
			$pay_lines = array();
			if ( $r->paid_at ) {
				$pay_lines[] = $L( '車両料金：クレジットカード（Square）', 'Car rental: credit card (Square)' ) . ' ' . date( 'Y-m-d H:i', strtotime( $r->paid_at ) );
			} else {
				$pay_lines[] = $L( '車両料金：未収', 'Car rental: unpaid' );
			}
			if ( 'none' !== $r->shuttle && (int) $r->shuttle_fee > 0 ) {
				$pay_lines[] = $r->shuttle_paid_at
					? $L( '送迎料金：クレジットカード（Square）', 'Shuttle: credit card (Square)' ) . ' ' . date( 'Y-m-d H:i', strtotime( $r->shuttle_paid_at ) )
					: $L( '送迎料金：未収（本領収書には含みません）', 'Shuttle: unpaid (not included in this receipt)' );
			}
			echo '<tr><th>' . esc_html( $L( '支払方法', 'Payment method' ) ) . '</th><td>' . esc_html( implode( ' / ', $pay_lines ) ) . '</td></tr>';

			if ( $is_invoice ) {
				echo '<tr><th>' . esc_html( $L( '適格請求書発行事業者', 'Qualified invoice issuer' ) ) . '</th><td>' . esc_html( $s['company_name'] );
				echo '<br><strong>' . esc_html( $L( '登録番号：', 'Registration No.: ' ) . $s['company_invoice'] ) . '</strong>';
				echo '<br>' . esc_html( $s['company_address'] ) . '</td></tr>';
			} else {
				echo '<tr><th>' . esc_html( $L( '発行事業者', 'Issuer' ) ) . '</th><td>' . esc_html( $s['company_name'] );
				echo '<br>' . esc_html( $s['company_address'] ) . '</td></tr>';
			}
			echo '</table>';

			if ( $is_invoice ) {
				echo '<p class="small">' . esc_html( $L(
					'※本書は消費税法第57条の4に規定する適格請求書（インボイス）です。消費税額は税率ごとに1回の端数処理（切捨て）を行っています。',
					'* This document is a qualified invoice under the Japanese Consumption Tax Act. Tax is rounded down once per tax rate.'
				) ) . '</p>';
			} else {
				echo '<p class="small">' . esc_html( $L(
					'※当社は適格請求書発行事業者ではないため、本書は適格請求書（インボイス）には該当しません。記載の消費税額は参考値です。',
					'* We are not a registered qualified invoice issuer, so this document is not a qualified invoice. The tax amount shown is for reference only.'
				) ) . '</p>';
			}
			echo '<p class="small">' . esc_html( $L( '本領収書は再発行いたしません。', 'This receipt will not be reissued.' ) ) . '</p>';
			echo '</div>'; /* #bvp-doc */
			self::receipt_script( $lang, $cur );
			self::pdf_script( $r, $type, $lang );
			echo '</body></html>'; exit;
		}

		/* 共通：予約情報 */
		echo '<table>';
		echo '<tr><th>' . esc_html( $L( '予約番号', 'Reservation No.' ) ) . '</th><td><strong>' . esc_html( $r->code ) . '</strong></td></tr>';
		echo '<tr><th>' . esc_html( $L( 'お名前', 'Name' ) ) . '</th><td>' . esc_html( $name ) . '</td></tr>';
		echo '<tr><th>' . esc_html( $L( '連絡先', 'Contact' ) ) . '</th><td>' . esc_html( $r->phone . ' / ' . $r->email ) . '</td></tr>';
		echo '<tr><th>' . esc_html( $L( '店舗', 'Branch' ) ) . '</th><td>' . esc_html( BV_Util::label( BV_Util::stores(), $r->store, $lang ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html( $L( '車両クラス', 'Vehicle class' ) ) . '</th><td>' . esc_html( BV_Util::class_label_with_capacity( $r->vehicle_class, $lang ) ) . ( $v ? '（' . esc_html( $v->name . ' ' . $v->plate ) . '）' : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html( $L( '貸出日時', 'Pick-up' ) ) . '</th><td>' . esc_html( date( 'Y-m-d H:i', strtotime( $r->pickup_dt ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html( $L( '返却日時', 'Return' ) ) . '</th><td>' . esc_html( date( 'Y-m-d H:i', strtotime( $r->return_dt ) ) ) . '</td></tr>';
		$opts = array();
		foreach ( BV_Util::equipment() as $ek => $ev ) {
			$n = isset( $r->{ 'opt_' . $ek } ) ? (int) $r->{ 'opt_' . $ek } : 0;
			if ( $n > 0 ) $opts[] = $ev[ $lang ] . '×' . $n;
		}
		echo '<tr><th>' . esc_html( $L( 'オプション', 'Options' ) ) . '</th><td>' . esc_html( $opts ? implode( '、', $opts ) : $L( 'なし', 'None' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html( $L( '補償', 'Coverage' ) ) . '</th><td>' . esc_html( BV_Util::label( BV_Util::coverages(), $r->coverage, $lang ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html( $L( '送迎', 'Shuttle' ) ) . '</th><td>' . esc_html( BV_Util::label( BV_Util::shuttles(), $r->shuttle, $lang ) . ( $r->shuttle_detail ? '：' . $r->shuttle_detail : '' ) );
		if ( 'none' !== $r->shuttle ) {
			echo '<br>' . esc_html( BV_Util::label( BV_Util::shuttle_statuses(), $r->shuttle_status ?: 'requested', $lang ) );
			if ( $r->shuttle_fee ) {
				echo '　' . esc_html( BV_Util::shuttle_fee_text( $r, $lang ) . ( $r->shuttle_paid_at ? $L( '（支払済）', ' (paid)' ) : $L( '（未払）', ' (unpaid)' ) ) );
			}
		}
		echo '</td></tr>';
		echo '</table>';

		if ( 'voucher' === $type ) {
			/* 予約票：料金内訳＋決済状況（返却情報は載せない） */
			echo '<h1 style="font-size:14px">' . esc_html( $L( '料金内訳', 'Price Breakdown' ) ) . '</h1><table>';
			if ( is_array( $bd ) && ! empty( $bd['lines'] ) ) {
				foreach ( $bd['lines'] as $line ) {
					echo '<tr><td>' . esc_html( $line['label'] ) . '</td><td style="text-align:right;width:30%">' . esc_html( BV_Util::money( $line['amount'], $lang ) ) . '</td></tr>';
				}
			}
			$vtax = (int) floor( (int) $r->price_total * 10 / 110 );
			echo '<tr><th>' . esc_html( $L( '合計（税込）', 'Total (incl. tax)' ) ) . '</th><td class="total" style="text-align:right">' . esc_html( BV_Util::money( $r->price_total, $lang ) ) . '</td></tr>';
			echo '<tr><td style="font-size:10px">' . esc_html( $L( '（うち消費税10%対象：', '(incl. 10% consumption tax: ' ) . BV_Util::money( $vtax, $lang ) . '）' ) . '</td><td></td></tr>';
			echo '<tr><th>' . esc_html( $L( 'お支払い状況', 'Payment status' ) ) . '</th><td><strong>' . esc_html( $r->paid_at ? $L( '決済済み（', 'Paid (' ) . $r->paid_at . '）' : $L( '未決済', 'Unpaid' ) ) . '</strong></td></tr>';
			echo '</table>';
			if ( 'none' !== $r->shuttle ) {
				$sh_note = $r->shuttle_paid_at
					? $L( '※送迎料金 ', '* Shuttle fee ' ) . BV_Util::money( (int) $r->shuttle_fee, $lang ) . $L( '（お支払い済み・別決済）', ' (paid separately)' )
					: ( (int) $r->shuttle_fee > 0
						? $L( '※送迎料金 ', '* Shuttle fee ' ) . BV_Util::money( (int) $r->shuttle_fee, $lang ) . $L( '（未払い・別決済）', ' (unpaid, separate payment)' )
						: $L( '※送迎料金は別途申し受けます（金額は確認後にご案内）。', '* A separate shuttle fee applies (amount to be advised).' ) );
				echo '<p class="small">' . esc_html( $sh_note ) . '</p>';
			}
		}

		if ( 'checkin' === $type ) {
			/* 受付表：重要事項説明・約款抜粋・署名欄 */
			echo '<h1 style="font-size:13px">' . esc_html( $L( '重要事項説明（抜粋）', 'Important Terms (Summary)' ) ) . '</h1>';
			if ( 'en' === $lang ) {
				echo '<ol class="small" style="margin:4px 0 8px 18px">
				<li>The renter must present a valid driver\'s license (International Driving Permit under the Geneva Convention plus passport for visitors). Only registered drivers may drive.</li>
				<li>Please return the vehicle with a full tank of fuel by the agreed date and time to the agreed location. Late returns incur additional charges.</li>
				<li>In case of an accident, immediately contact the police and our office. Failure to do so may void insurance coverage.</li>
				<li>Smoking is prohibited in all vehicles. Cleaning fees apply for violations.</li>
				<li>If damage occurs, a Non-Operation Charge (NOC) may apply in addition to repair costs, unless Option C has been purchased.</li>
				<li>Cancellation fees apply according to our cancellation policy (up to 50% refund / full refund depending on timing).</li>
				</ol>';
			} else {
				echo '<ol class="small" style="margin:4px 0 8px 18px">
				<li>運転者は有効な運転免許証を提示してください。登録された運転者以外は運転できません。</li>
				<li>返却は所定の日時・場所に、ガソリン満タンでお願いします。返却遅延は超過料金が発生します。</li>
				<li>事故の際は直ちに警察および当社へ連絡してください。連絡がない場合、保険・補償が適用されないことがあります。</li>
				<li>全車禁煙です。違反時は清掃費用を申し受けます。</li>
				<li>車両損害発生時は、修理費のほかノンオペレーションチャージ（NOC）を申し受けます（オプションC加入時は免除）。</li>
				<li>キャンセル料は当社規定（時期により全額返金〜50%返金）に基づきます。</li>
				</ol>';
			}
			echo '<h1 style="font-size:13px">' . esc_html( $L( '貸渡約款（抜粋）', 'Rental Terms & Conditions (Excerpt)' ) ) . '</h1>';
			echo '<p class="small">' . esc_html( $L(
				'当社所定の貸渡約款に基づき車両を貸し渡します。借受人は約款の内容を確認し、これに同意のうえ署名するものとします。約款全文は店舗に備え付けのほか、当社ウェブサイトでご確認いただけます。',
				'The vehicle is rented under the company\'s standard rental terms and conditions. By signing below, the renter confirms that they have read and agreed to these terms. The full terms are available at the branch and on our website.'
			) ) . '</p>';
			echo '<table><tr><th style="width:50%">' . esc_html( $L( '署名日', 'Date' ) ) . '</th><th>' . esc_html( $L( '借受人署名', 'Renter\'s signature' ) ) . '</th></tr>';
			echo '<tr><td style="height:52px"></td><td></td></tr></table>';
		}

		echo '</div>'; /* #bvp-doc */
		self::pdf_script( $r, $type, $lang );
		echo '</body></html>';
		exit;
	}

	/**
	 * 領収書の再計算スクリプト
	 * 明細の金額を書き換えると、合計・税抜金額・消費税額を計算し直す（税率ごとに1回の切捨て）。
	 */
	protected static function receipt_script( $lang, $cur ) {
		$labels = array(
			'confirm' => ( 'en' === $lang ) ? 'Reset all edits?' : '編集した内容を元に戻します。よろしいですか？',
		);
		echo '<script>(function(){';
		echo 'var CUR=' . wp_json_encode( $cur ) . ',L=' . wp_json_encode( $labels ) . ';';
		echo <<<'JS'
	var tbl = document.getElementById('bvp-items');
	if (!tbl) return;
	var initial = tbl.innerHTML;
	function num(v) { v = String(v).replace(/[^0-9\-]/g, ''); var n = parseInt(v, 10); return isNaN(n) ? 0 : n; }
	function fmt(n) { return CUR + Math.round(n).toLocaleString('en-US'); }
	function put(id, v) { var e = document.getElementById(id); if (e) e.textContent = fmt(v); }
	function calc() {
		var total = 0;
		Array.prototype.forEach.call(tbl.querySelectorAll('.bvp-amt'), function (i) { total += num(i.value); });
		/* 消費税は税率ごとに1回だけ端数処理（切捨て） */
		var tax = Math.floor(Math.abs(total) * 10 / 110) * (total < 0 ? -1 : 1);
		var excl = total - tax;
		put('bvp-sum', total); put('bvp-excl', excl); put('bvp-tax', tax); put('bvp-incl', total);
		put('bvp-texcl', excl); put('bvp-ttax', tax); put('bvp-tincl', total);
	}
	function addRow() {
		var tools = document.getElementById('bvp-tools');
		var tr = document.createElement('tr');
		tr.className = 'bvp-row';
		var td1 = document.createElement('td');
		td1.style.width = '70%';
		var lbl = document.createElement('input');
		lbl.type = 'text'; lbl.className = 'bvp-ed bvp-lbl'; lbl.maxLength = 120; lbl.style.width = '98%';
		td1.appendChild(lbl);
		var td2 = document.createElement('td');
		td2.style.textAlign = 'right'; td2.style.whiteSpace = 'nowrap';
		td2.appendChild(document.createTextNode(CUR));
		var amt = document.createElement('input');
		amt.type = 'text'; amt.setAttribute('inputmode', 'numeric');
		amt.className = 'bvp-ed bvp-amt'; amt.value = '0';
		td2.appendChild(amt);
		var td3 = document.createElement('td');
		td3.className = 'noprint'; td3.style.textAlign = 'center';
		td3.innerHTML = '<button type="button" class="bvp-del">×</button>';
		tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
		tools.parentNode.insertBefore(tr, tools);
		lbl.focus();
	}
	tbl.addEventListener('input', function (e) {
		if (e.target.className && String(e.target.className).indexOf('bvp-amt') !== -1) calc();
	});
	tbl.addEventListener('click', function (e) {
		if (e.target.className && String(e.target.className).indexOf('bvp-del') !== -1) {
			var row = e.target.parentNode.parentNode;
			if (row && row.parentNode) { row.parentNode.removeChild(row); calc(); }
		}
	});
	tbl.addEventListener('blur', function (e) {
		if (e.target.className && String(e.target.className).indexOf('bvp-amt') !== -1) { e.target.value = num(e.target.value); calc(); }
	}, true);
	document.getElementById('bvp-add').addEventListener('click', addRow);
	document.getElementById('bvp-reset').addEventListener('click', function () {
		if (!window.confirm(L.confirm)) return;
		tbl.innerHTML = initial;
		calc();
	});
	calc();
JS;
		echo '})();</script>';
	}

	/**
	 * 印刷ボタンとPDFダウンロードボタン
	 * PDFは html2pdf.js（html2canvas + jsPDF）で作成する。
	 * 読み込めない環境では印刷ダイアログ（PDFに保存）へ案内する。
	 */
	protected static function pdf_script( $r, $type, $lang ) {
		$names = array(
			'voucher' => ( 'en' === $lang ) ? 'voucher' : '予約票',
			'checkin' => ( 'en' === $lang ) ? 'checkin' : '受付表',
			'receipt' => ( 'en' === $lang ) ? 'receipt' : '領収書',
		);
		$file = $names[ $type ] . '_' . $r->code . '.pdf';
		$msgs = array(
			'making'   => ( 'en' === $lang ) ? 'Generating PDF...' : 'PDFを作成しています…',
			'done'     => ( 'en' === $lang ) ? 'Saved to your downloads folder.' : 'ダウンロードフォルダーに保存しました。',
			'fallback' => ( 'en' === $lang )
				? 'Could not load the PDF library. Use the print dialog and choose "Save as PDF".'
				: 'PDF作成機能を読み込めませんでした。印刷画面の「送信先：PDFに保存」でも保存できます。',
			'error'    => ( 'en' === $lang ) ? 'Failed to create the PDF.' : 'PDFの作成に失敗しました。',
		);
		echo '<script>(function(){';
		echo 'var FILE=' . wp_json_encode( $file ) . ',M=' . wp_json_encode( $msgs ) . ',';
		echo 'CDN="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js";';
		echo <<<'JS'
	var btnP = document.getElementById('bvp-print'), btnD = document.getElementById('bvp-pdf'), msg = document.getElementById('bvp-msg');
	if (btnP) btnP.addEventListener('click', function () { window.print(); });
	if (!btnD) return;
	function say(t) { if (msg) msg.textContent = t || ''; }
	var failed = false;
	function fail() {
		if (failed) return;
		failed = true;
		say(M.fallback); btnD.disabled = false; window.print();
	}
	function load(cb) {
		if (window.html2pdf) return cb();
		var sc = document.createElement('script');
		sc.src = CDN;
		sc.onload = function () { window.html2pdf ? cb() : fail(); };
		sc.onerror = fail;
		document.head.appendChild(sc);
		/* 社内ネットワーク等で読み込めないまま止まる場合の保険 */
		setTimeout(function () { if (!window.html2pdf) fail(); }, 10000);
	}
	function make() {
		var doc = document.getElementById('bvp-doc');
		if (!doc) return fail();
		var clone = doc.cloneNode(true);
		/* 入力欄は画像化されないことがあるため、文字に置き換える */
		Array.prototype.forEach.call(clone.querySelectorAll('input'), function (i) {
			var sp = document.createElement('span');
			sp.textContent = i.value;
			if (String(i.className).indexOf('bvp-bold') !== -1) sp.style.fontWeight = 'bold';
			i.parentNode.replaceChild(sp, i);
		});
		Array.prototype.forEach.call(clone.querySelectorAll('.noprint'), function (e) {
			if (e.parentNode) e.parentNode.removeChild(e);
		});
		var wrap = document.createElement('div');
		wrap.style.cssText = 'position:fixed;left:-10000px;top:0;width:186mm;background:#fff;color:#111;font-size:11px;line-height:1.5';
		wrap.appendChild(clone);
		document.body.appendChild(wrap);
		var done = function (ok) {
			if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
			btnD.disabled = false;
			say(ok ? M.done : M.error);
		};
		window.html2pdf().set({
			margin: [10, 10, 12, 10],
			filename: FILE,
			image: { type: 'jpeg', quality: 0.98 },
			html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
			jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
			pagebreak: { mode: ['avoid-all', 'css'] }
		}).from(wrap).save().then(function () { done(true); }, function () { done(false); });
	}
	btnD.addEventListener('click', function () { btnD.disabled = true; say(M.making); load(make); });
JS;
		echo '})();</script>';
	}
}
