<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once BVRM_DIR . 'includes/admin/class-bv-admin-pages.php';

/**
 * 管理画面：メニュー・予約一覧/編集/追加・ガント・CSV
 */
class BV_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'security_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_reservation_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_mark_paid' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_refund' ) );
		add_action( 'admin_init', array( 'BV_Admin_Pages', 'handle_vehicle_save' ) );
		add_action( 'admin_init', array( 'BV_Admin_Pages', 'handle_vehicle_delete' ) );
		add_action( 'wp_ajax_bvrm_gantt', array( __CLASS__, 'ajax_gantt' ) );
	}

	/** ガントの対話操作（管理画面） */
	public static function ajax_gantt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array( 'ok' => false, 'message' => '権限がありません。' ) );
		}
		$raw = file_get_contents( 'php://input' );
		$p = json_decode( $raw, true );
		if ( ! is_array( $p ) ) $p = array();
		if ( ! isset( $p['token'] ) || ! wp_verify_nonce( $p['token'], 'bvrm_gantt' ) ) {
			wp_send_json( array( 'ok' => false, 'message' => 'セッションの有効期限が切れました。画面を再読み込みしてください。' ) );
		}
		wp_send_json( BV_Gantt::do_action( sanitize_key( $p['action2'] ?? $p['action'] ?? '' ), $p ) );
	}

	public static function menu() {
		add_menu_page( 'レンタカー管理', 'レンタカー管理', 'manage_options', 'bvrm', array( 'BV_Admin_Pages', 'dashboard' ), 'dashicons-car', 26 );
		add_submenu_page( 'bvrm', '経営ダッシュボード', '経営ダッシュボード', 'manage_options', 'bvrm', array( 'BV_Admin_Pages', 'dashboard' ) );
		add_submenu_page( 'bvrm', '予約ガント', '予約ガント', 'manage_options', 'bvrm-gantt', array( __CLASS__, 'page_gantt' ) );
		add_submenu_page( 'bvrm', '予約一覧', '予約一覧', 'manage_options', 'bvrm-reservations', array( __CLASS__, 'page_reservations' ) );
		add_submenu_page( 'bvrm', '車両管理', '車両管理', 'manage_options', 'bvrm-vehicles', array( 'BV_Admin_Pages', 'vehicles' ) );
		add_submenu_page( 'bvrm', '顧客・会員', '顧客・会員', 'manage_options', 'bvrm-customers', array( 'BV_Admin_Pages', 'customers' ) );
		add_submenu_page( 'bvrm', 'クーポン', 'クーポン', 'manage_options', 'bvrm-coupons', array( 'BV_Admin_Pages', 'coupons' ) );
		add_submenu_page( 'bvrm', '料金カレンダー', '料金カレンダー', 'manage_options', 'bvrm-rates', array( 'BV_Admin_Pages', 'rates' ) );
		add_submenu_page( 'bvrm', '貸渡実績報告書', '貸渡実績報告書', 'manage_options', 'bvrm-report', array( 'BV_Admin_Pages', 'report' ) );
		add_submenu_page( 'bvrm', '設定', '設定', 'manage_options', 'bvrm-settings', array( 'BV_Admin_Pages', 'settings' ) );
	}

	/**
	 * 入金済みにする（手動）
	 * Square端末での決済、店頭現金、振込など、システムを通らないお支払いを記録する。
	 * Webhookの取りこぼしで未払いのままになっている予約の救済にも使う。
	 */
	public static function handle_mark_paid() {
		if ( ! isset( $_POST['bvrm_mark_paid'] ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		$id = (int) ( $_POST['reservation_id'] ?? 0 );
		check_admin_referer( 'bvrm_mark_paid_' . $id );

		$r = BV_DB::get_reservation( $id );
		if ( ! $r ) return;

		$back = admin_url( 'admin.php?page=bvrm-reservations&edit=' . $id );
		if ( $r->paid_at ) {
			set_transient( 'bvrm_notice', 'この予約はすでに支払済みです。', 60 );
			wp_safe_redirect( $back );
			exit;
		}

		$P = wp_unslash( $_POST );

		/* 受領方法 */
		$ways = self::paid_ways();
		$way = sanitize_key( $P['paid_way'] ?? 'square_terminal' );
		if ( ! isset( $ways[ $way ] ) ) $way = 'square_terminal';

		/* 入金日時（未入力なら現在時刻） */
		$when = sanitize_text_field( $P['paid_at'] ?? '' );
		$ts = $when ? strtotime( $when ) : 0;
		if ( ! $ts || $ts > current_time( 'timestamp' ) + DAY_IN_SECONDS ) $ts = current_time( 'timestamp' );
		$paid_at = date( 'Y-m-d H:i:s', $ts );

		$memo_in = sanitize_text_field( $P['paid_memo'] ?? '' );
		$memo = trim( (string) $r->admin_memo );
		$memo .= ( $memo ? "\n" : '' ) . '[入金を手動で記録 ' . current_time( 'Y-m-d H:i' ) . '] '
			. $ways[ $way ] . '／' . BV_Util::money( (int) $r->price_total )
			. '（入金日時 ' . date( 'Y-m-d H:i', $ts ) . '）'
			. ( $memo_in ? '／' . $memo_in : '' );

		$upd = array(
			'paid_at'     => $paid_at,
			'status'      => ( 'pending' === $r->status ) ? 'confirmed' : $r->status,
			'admin_memo'  => $memo,
			'paid_amount' => (int) $r->price_total,
		);
		/* 支払い方法もあわせて記録しておく（現金・振込を選んだ場合） */
		if ( 'cash' === $way )      $upd['payment_method'] = 'cash';
		elseif ( 'bank' === $way )  $upd['payment_method'] = 'bank';

		BV_DB::update_reservation( $id, $upd );
		$r = BV_DB::get_reservation( $id );

		$msg = BV_Util::money( (int) $r->price_total ) . 'を「' . $ways[ $way ] . '」として記録し、予約を確定にしました。';
		if ( ! empty( $P['notify_customer'] ) && $r->email ) {
			BV_Mailer::send_paid( $r );
			$msg .= 'お客様へ予約確定メールを送信しました。';
		}
		set_transient( 'bvrm_notice', $msg, 180 );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * 返金を実行する
	 * 割合・キャンセルポリシー・任意の金額から選べる。
	 */
	public static function handle_refund() {
		if ( ! isset( $_POST['bvrm_do_refund'] ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		$id = (int) ( $_POST['reservation_id'] ?? 0 );
		check_admin_referer( 'bvrm_refund_' . $id );

		$r = BV_DB::get_reservation( $id );
		if ( ! $r ) return;
		$back = admin_url( 'admin.php?page=bvrm-reservations&edit=' . $id );

		$total     = (int) $r->price_total;
		$already   = (int) $r->refund_amount;
		$available = max( 0, $total - $already );
		if ( $available < 1 ) {
			set_transient( 'bvrm_notice', 'この予約はすでに全額返金済みです。', 60 );
			wp_safe_redirect( $back ); exit;
		}

		$P = wp_unslash( $_POST );
		$mode = sanitize_key( $P['refund_mode'] ?? '' );
		$label = '';

		if ( 'policy' === $mode ) {
			$c = BV_Util::cancel_charge( $r );
			$amount = (int) $c['refund'];
			$label = 'キャンセルポリシー適用（' . $c['label_ja'] . '／キャンセル料 ' . $c['pct'] . '%）';
		} elseif ( 'manual' === $mode ) {
			$amount = (int) preg_replace( '/[^0-9]/', '', (string) ( $P['refund_manual'] ?? '' ) );
			$label = '金額を指定';
		} elseif ( preg_match( '/^pct(\d+)$/', $mode, $m ) ) {
			$pct = max( 1, min( 100, (int) $m[1] ) );
			$amount = (int) round( $total * $pct / 100 );
			$label = $pct . '%返金';
		} else {
			set_transient( 'bvrm_notice', '返金方法を選んでください。', 60 );
			wp_safe_redirect( $back ); exit;
		}

		if ( $amount < 1 ) {
			set_transient( 'bvrm_notice', '返金額が0円です。金額をご確認ください。', 60 );
			wp_safe_redirect( $back ); exit;
		}
		if ( $amount > $available ) {
			set_transient( 'bvrm_notice', '返金額が返金可能額（' . BV_Util::money( $available ) . '）を超えています。', 120 );
			wp_safe_redirect( $back ); exit;
		}

		$res = BV_Square::refund( $r, $amount );
		if ( is_wp_error( $res ) ) {
			set_transient( 'bvrm_notice', '返金エラー：' . $res->get_error_message(), 180 );
			wp_safe_redirect( $back ); exit;
		}

		$done = (int) $res;
		$memo = trim( (string) $r->admin_memo );
		$memo .= ( $memo ? "\n" : '' ) . '[返金 ' . current_time( 'Y-m-d H:i' ) . '] '
			. BV_Util::money( $done ) . '（' . $label . '）'
			. ( ! empty( $P['refund_memo'] ) ? '／' . sanitize_text_field( $P['refund_memo'] ) : '' );
		BV_DB::update_reservation( $id, array( 'admin_memo' => $memo ) );

		$r = BV_DB::get_reservation( $id );
		$rest = max( 0, $total - (int) $r->refund_amount );
		set_transient( 'bvrm_notice',
			BV_Util::money( $done ) . ' を返金しました（' . $label . '）。'
			. '返金合計 ' . BV_Util::money( (int) $r->refund_amount )
			. '／お預かり残 ' . BV_Util::money( $rest ) . '。', 180 );
		wp_safe_redirect( $back );
		exit;
	}

	/** 手動で入金を記録するときの受領方法 */
	public static function paid_ways() {
		return array(
			'square_terminal' => 'Square（店頭端末・手動リンクなど）',
			'square_link'     => 'Square決済リンク（Webhookの取りこぼし）',
			'cash'            => '現金',
			'bank'            => '銀行振込',
			'other'           => 'その他',
		);
	}

	/* ---------- アクション（CSV・返金・リンク送付など） ---------- */

	public static function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) || empty( $_GET['bvrm_action'] ) ) return;
		$action = sanitize_key( $_GET['bvrm_action'] );
		check_admin_referer( 'bvrm_' . $action );

		if ( 'export_reservations' === $action ) self::csv_reservations();
		if ( 'export_vehicles' === $action )     self::csv_vehicles();
		if ( 'export_analytics' === $action )    self::csv_analytics();
		if ( 'export_report' === $action )       BV_Report::download_xlsx( isset( $_GET['fy'] ) ? (int) $_GET['fy'] : (int) current_time( 'Y' ) );
		if ( 'export_backup' === $action )       self::export_backup();

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$r  = $id ? BV_DB::get_reservation( $id ) : null;

		/* 予約の完全削除 */
		if ( 'delete_reservation' === $action && $r ) {
			global $wpdb;
			$wpdb->delete( BV_DB::table( 'reservations' ), array( 'id' => $id ) );
			set_transient( 'bvrm_notice', '予約 ' . $r->code . '（' . trim( $r->sei . ' ' . $r->mei ) . '）を完全に削除しました。', 120 );
			wp_safe_redirect( admin_url( 'admin.php?page=bvrm-reservations' ) );
			exit;
		}

		if ( $r ) {
			/* 旧バージョンのブックマーク・戻るボタン対策（返金は予約編集画面のフォームから行う） */
			if ( 'refund_full' === $action || 'refund_half' === $action ) {
				set_transient( 'bvrm_notice', '返金の操作方法が変わりました。下の「返金する」欄から、割合・ポリシー適用・金額指定のいずれかをお選びください。', 120 );
			}
			if ( 'send_paylink' === $action ) {
				$link = BV_Square::create_payment_link( $r );
				if ( is_wp_error( $link ) ) {
					set_transient( 'bvrm_notice', '決済リンク生成エラー: ' . $link->get_error_message(), 60 );
				} else {
					BV_DB::update_reservation( $id, array( 'square_link' => $link['url'], 'square_order_id' => $link['order_id'] ) );
					BV_Mailer::send_reminder( BV_DB::get_reservation( $id ) );
					set_transient( 'bvrm_notice', '決済リンクを生成し、メールを送信しました。', 60 );
				}
			}
			/* Squareに直接問い合わせて支払状況を同期 */
			if ( 'sync_payment' === $action || 'sync_shuttle_payment' === $action ) {
				$target = ( 'sync_shuttle_payment' === $action ) ? 'shuttle' : 'car';
				$res = BV_Square::sync_payment_status( $r, $target );
				set_transient( 'bvrm_notice', is_wp_error( $res ) ? '確認エラー: ' . $res->get_error_message() : $res, 120 );
			}
			/* キャンセルポリシーを適用してキャンセル（返金も自動） */
			if ( 'policy_cancel' === $action || 'noshow_cancel' === $action ) {
				if ( 'cancelled' === $r->status ) {
					set_transient( 'bvrm_notice', 'この予約はすでにキャンセル済みです。', 60 );
				} else {
					$noshow = ( 'noshow_cancel' === $action );
					$res = BV_Members::do_cancel( $r, 'ja', 'admin', $noshow );
					$c = $res['charge'];
					$note = ( $noshow ? '無断キャンセル' : 'キャンセル' ) . 'として処理しました。'
						. 'キャンセル料 ' . $c['pct'] . '%（' . BV_Util::money( (int) $c['fee'] ) . '）';
					if ( $res['refunded'] > 0 ) $note .= '／返金 ' . BV_Util::money( (int) $res['refunded'] ) . ' を自動処理しました。';
					elseif ( $c['refund'] > 0 ) $note .= '／【要対応】返金 ' . BV_Util::money( (int) $c['refund'] ) . ' が必要です。';
					set_transient( 'bvrm_notice', $note, 180 );
				}
			}

			/* 現金・振込で受領 → 支払済みにして確定 */
			if ( 'mark_cash_paid' === $action ) {
				if ( $r->paid_at ) {
					set_transient( 'bvrm_notice', 'この予約はすでに支払済みです。', 60 );
				} else {
					$memo = trim( (string) $r->admin_memo );
					$pm_label = BV_Util::label( BV_Util::payment_methods(), $r->payment_method ?: 'cash' );
					$memo .= ( $memo ? "\n" : '' ) . '[' . $pm_label . 'で受領 ' . current_time( 'Y-m-d H:i' ) . '] '
						. BV_Util::money( (int) $r->price_total );
					BV_DB::update_reservation( $id, array(
						'paid_at'     => current_time( 'mysql' ),
						'status'      => ( 'pending' === $r->status ) ? 'confirmed' : $r->status,
						'admin_memo'  => $memo,
						'paid_amount' => (int) $r->price_total,
					) );
					$r = BV_DB::get_reservation( $id );
					if ( ! empty( $_GET['notify'] ) && $r->email ) BV_Mailer::send_paid( $r );
					set_transient( 'bvrm_notice', BV_Util::money( (int) $r->price_total ) . 'を' . $pm_label . 'で受領し、予約を確定にしました。', 120 );
				}
			}
			if ( 'send_reminder' === $action ) {
				BV_Mailer::send_reminder( $r );
				set_transient( 'bvrm_notice', 'リマインドメールを送信しました。', 60 );
			}
			/* 送迎リクエストの承認：料金を確定してSquareリンクを送信 */
			if ( 'approve_shuttle' === $action ) {
				$parts = BV_Staff_Portal::shuttle_fee_input( $r, $_GET );
				if ( is_wp_error( $parts ) ) {
					set_transient( 'bvrm_notice', $parts->get_error_message(), 60 );
				} elseif ( $parts['total'] < 1 ) {
					set_transient( 'bvrm_notice', '0円では決済リンクを作成できません。無料の場合は「支払済みにして確定」をお使いください。', 60 );
				} else {
					$fee = $parts['total'];
					BV_DB::update_reservation( $id, array(
						'shuttle_fee'         => $fee,
						'shuttle_fee_pickup'  => $parts['pickup'],
						'shuttle_fee_dropoff' => $parts['dropoff'],
						'shuttle_status'      => 'quoted',
					) );
					$r = BV_DB::get_reservation( $id );
					$link = BV_Square::create_shuttle_payment_link( $r );
					if ( is_wp_error( $link ) ) {
						set_transient( 'bvrm_notice', '送迎決済リンクの生成に失敗しました: ' . $link->get_error_message(), 120 );
					} else {
						BV_DB::update_reservation( $id, array( 'shuttle_link' => $link['url'], 'shuttle_order_id' => $link['order_id'] ) );
						$r = BV_DB::get_reservation( $id );
						BV_Mailer::send_shuttle_quote( $r );
						set_transient( 'bvrm_notice', '送迎を承認し、' . BV_Util::shuttle_fee_text( $r ) . 'の決済リンクをお客様へ送信しました。', 120 );
					}
				}
			}
			if ( 'resend_shuttle_link' === $action ) {
				if ( $r->shuttle_link ) {
					BV_Mailer::send_shuttle_quote( $r );
					set_transient( 'bvrm_notice', '送迎の決済リンクを再送しました。', 60 );
				}
			}
			if ( 'decline_shuttle' === $action ) {
				BV_DB::update_reservation( $id, array( 'shuttle_status' => 'declined' ) );
				$r = BV_DB::get_reservation( $id );
				BV_Mailer::send_shuttle_declined( $r );
				set_transient( 'bvrm_notice', '送迎不可としてお客様へご連絡しました。', 60 );
			}
			/* ---- 追加請求（差額） ---- */
			if ( 'charge_addon' === $action ) {
				$amount = (int) ( $_GET['addon_amount'] ?? 0 );
				$note   = sanitize_text_field( wp_unslash( $_GET['addon_note'] ?? '' ) );
				if ( $amount < 1 ) {
					set_transient( 'bvrm_notice', '追加請求の金額を1円以上で指定してください。', 60 );
				} elseif ( ! is_email( $r->email ) ) {
					set_transient( 'bvrm_notice', 'メールアドレスが登録されていないため送信できません。', 60 );
				} else {
					BV_DB::update_reservation( $id, array(
						'addon_amount' => $amount,
						'addon_note'   => $note,
						'addon_status' => 'quoted',
						'addon_link'   => '',
						'addon_order_id' => '',
						'addon_payment_id' => '',
						'addon_paid_at' => null,
					) );
					$r = BV_DB::get_reservation( $id );
					$link = BV_Square::create_addon_payment_link( $r );
					if ( is_wp_error( $link ) ) {
						/* リンクが作れなければ請求中のままにしない */
						BV_DB::update_reservation( $id, array( 'addon_status' => '', 'addon_amount' => 0, 'addon_note' => '' ) );
						set_transient( 'bvrm_notice', '追加請求の決済リンクを作成できませんでした: ' . $link->get_error_message(), 120 );
					} else {
						$memo = trim( (string) $r->admin_memo );
						$memo .= ( $memo ? "\n" : '' ) . '[追加請求を発行 ' . current_time( 'Y-m-d H:i' ) . '] '
							. BV_Util::money( $amount ) . ( $note ? '／' . $note : '' );
						BV_DB::update_reservation( $id, array(
							'addon_link' => $link['url'], 'addon_order_id' => $link['order_id'], 'admin_memo' => $memo,
						) );
						$r = BV_DB::get_reservation( $id );
						BV_Mailer::send_addon_request( $r );
						set_transient( 'bvrm_notice', '差額 ' . BV_Util::money( $amount ) . ' の決済リンクをお客様へ送信しました。', 120 );
					}
				}
			}
			if ( 'resend_addon' === $action ) {
				if ( BV_Util::has_pending_addon( $r ) && $r->addon_link && is_email( $r->email ) ) {
					BV_Mailer::send_addon_request( $r );
					set_transient( 'bvrm_notice', '追加請求のメールを再送しました。', 60 );
				} else {
					set_transient( 'bvrm_notice', '再送できる追加請求がありません。', 60 );
				}
			}
			if ( 'sync_addon' === $action ) {
				$res = BV_Square::sync_payment_status( $r, 'addon' );
				set_transient( 'bvrm_notice', is_wp_error( $res ) ? $res->get_error_message() : $res, 120 );
			}
			if ( 'mark_addon_paid' === $action ) {
				if ( BV_Util::has_pending_addon( $r ) ) {
					$res = BV_Square::mark_addon_paid( $r, '', '手動記録' );
					set_transient( 'bvrm_notice', $res, 120 );
				} else {
					set_transient( 'bvrm_notice', '入金待ちの追加請求がありません。', 60 );
				}
			}
			if ( 'cancel_addon' === $action ) {
				if ( BV_Util::has_pending_addon( $r ) ) {
					$memo = trim( (string) $r->admin_memo );
					$memo .= ( $memo ? "\n" : '' ) . '[追加請求を取り消し ' . current_time( 'Y-m-d H:i' ) . '] ' . BV_Util::money( (int) $r->addon_amount );
					BV_DB::update_reservation( $id, array(
						'addon_status' => '', 'addon_amount' => 0, 'addon_note' => '',
						'addon_link' => '', 'addon_order_id' => '', 'admin_memo' => $memo,
					) );
					set_transient( 'bvrm_notice', '追加請求を取り消しました。発行済みのリンクは使わないようお客様にご連絡ください。', 120 );
				}
			}

			/* お礼＋口コミ依頼メールの送信・再送 */
			if ( 'send_review' === $action ) {
				if ( ! is_email( $r->email ) ) {
					set_transient( 'bvrm_notice', 'メールアドレスが登録されていないため送信できません。', 60 );
				} elseif ( ! BV_Util::store_review_url( $r->store ) ) {
					set_transient( 'bvrm_notice', 'この店舗の口コミ投稿URLが未設定です。「設定 → 店舗別設定」で入力してください。', 120 );
				} else {
					BV_Review::send( $r );
					set_transient( 'bvrm_notice', 'お礼＋口コミ依頼メールを送信しました。', 60 );
				}
			}
			if ( 'mark_shuttle_paid' === $action ) {
				BV_DB::update_reservation( $id, array( 'shuttle_status' => 'paid', 'shuttle_paid_at' => current_time( 'mysql' ) ) );
				$r = BV_DB::get_reservation( $id );
				BV_Mailer::send_shuttle_confirmed( $r );
				set_transient( 'bvrm_notice', '送迎を確定にしました（現地払いなど手動確定）。', 60 );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $id ) );
			exit;
		}
	}

	/**
	 * 追加請求の「理由」欄の下書き
	 * 管理メモに残る変更履歴から、直近の変更内容を拾って初期値にする。
	 */
	protected static function guess_addon_note( $r ) {
		$memo = (string) $r->admin_memo;
		if ( '' === trim( $memo ) ) return '';
		$lines = array_reverse( array_filter( array_map( 'trim', explode( "\n", $memo ) ) ) );
		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, '日時' ) || false !== strpos( $line, '期間' ) ) return '貸出期間の変更';
			if ( false !== strpos( $line, '補償' ) ) return '補償プランの変更';
			if ( false !== strpos( $line, 'クラス' ) ) return '車両クラスの変更';
		}
		return '';
	}

	/* ---------- バックアップ ---------- */

	/** バックアップ対象のテーブル（接尾辞） */
	public static function backup_tables() {
		return array(
			'vehicles'     => '車両',
			'reservations' => '予約',
			'maintenance'  => '整備記録',
			'coupons'      => 'クーポン',
			'rate_days'    => '料金カレンダー',
			'blocks'       => '貸出停止',
			'otp'          => '認証コード',
		);
	}

	/** バックアップに含める設定（wp_options） */
	public static function backup_options() {
		return array( 'bvrm_settings', 'bvrm_mail_templates', 'bvrm_api_key', 'bvrm_db_version', 'bvrm_autocancel_from' );
	}

	/**
	 * 7つのテーブルと設定を1つのSQLファイルとして書き出す。
	 * phpMyAdmin等でそのまま取り込める形式にしている。
	 */
	protected static function export_backup() {
		global $wpdb;

		$stamp = current_time( 'Y-m-d_Hi' );
		$file  = 'bvrm-backup-' . $stamp . '.sql';

		/* 圧縮・バッファを解除してから出力する（大きくなるため逐次書き出し） */
		while ( ob_get_level() ) ob_end_clean();
		@ini_set( 'zlib.output_compression', 'Off' );
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );

		nocache_headers();
		header( 'Content-Type: application/sql; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );

		$site = home_url( '/' );
		echo "-- BV Rental Manager バックアップ\n";
		echo '-- 作成日時: ' . current_time( 'Y-m-d H:i:s' ) . " (日本時間)\n";
		echo '-- サイト: ' . $site . "\n";
		echo '-- プラグイン: ' . BVRM_VERSION . ' / DB: ' . get_option( 'bvrm_db_version', '不明' ) . "\n";
		echo "--\n";
		echo "-- 【復元のしかた】\n";
		echo "--  1. エックスサーバーのサーバーパネル →「phpMyAdmin」を開く\n";
		echo "--  2. 対象のデータベースを選び「インポート」タブへ\n";
		echo "--  3. このファイルを選んで実行する\n";
		echo "--\n";
		echo "-- ※このファイルは取り込むと、下記テーブルを現在の内容で置き換えます。\n";
		echo "--   別のサイトへ取り込まないようご注意ください。\n";
		echo "-- ※WordPress本体・記事・画像は含まれていません。\n\n";

		echo "SET NAMES utf8mb4;\n";
		echo "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
		echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

		foreach ( self::backup_tables() as $key => $label ) {
			$name = BV_DB::table( $key );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			if ( $exists !== $name ) continue;

			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" );
			echo "-- ------------------------------------------------\n";
			echo '-- ' . $label . ' (' . $name . ') ' . number_format( $total ) . "件\n";
			echo "-- ------------------------------------------------\n";

			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$name}`", ARRAY_N );
			echo "DROP TABLE IF EXISTS `{$name}`;\n";
			echo $create[1] . ";\n\n";

			$offset = 0;
			$limit  = 200;
			while ( true ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$name}` LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
				if ( ! $rows ) break;
				foreach ( $rows as $row ) {
					$vals = array();
					foreach ( $row as $v ) {
						$vals[] = ( null === $v ) ? 'NULL' : "'" . $wpdb->_real_escape( $v ) . "'";
					}
					echo 'INSERT INTO `' . $name . '` (`' . implode( '`,`', array_keys( $row ) ) . '`) VALUES (' . implode( ',', $vals ) . ");\n";
				}
				$offset += $limit;
				flush();
			}
			echo "\n";
		}

		/* 設定・メール文面 */
		echo "-- ------------------------------------------------\n";
		echo "-- 設定・メール文面 (" . $wpdb->options . ")\n";
		echo "-- ------------------------------------------------\n";
		foreach ( self::backup_options() as $ok ) {
			$val = get_option( $ok, null );
			if ( null === $val ) continue;
			$esc_name = $wpdb->_real_escape( $ok );
			$esc_val  = $wpdb->_real_escape( maybe_serialize( $val ) );
			echo "INSERT INTO `{$wpdb->options}` (`option_name`,`option_value`,`autoload`) VALUES ('{$esc_name}','{$esc_val}','yes')"
				. " ON DUPLICATE KEY UPDATE `option_value`=VALUES(`option_value`);\n";
		}

		echo "\nSET FOREIGN_KEY_CHECKS=1;\n";
		echo "-- ここまで\n";
		exit;
	}

	protected static function csv_out( $filename, $header, $rows ) {
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF"; /* Excel向けBOM */
		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, $header );
		foreach ( $rows as $row ) fputcsv( $fh, $row );
		exit;
	}

	protected static function csv_reservations() {
		$from = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) );
		$to   = sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) );
		$args = array();
		if ( $from ) $args['from'] = $from;
		if ( $to )   $args['to'] = $to;
		$list = BV_DB::get_reservations( $args );
		$stores = BV_Util::stores(); $classes = BV_Util::classes(); $statuses = BV_Util::statuses();

		/* 画面の絞り込みをCSVにも反映する */
		$lang_f   = sanitize_key( wp_unslash( $_GET['lang'] ?? '' ) );
		$store_f  = sanitize_key( wp_unslash( $_GET['store'] ?? '' ) );
		$status_f = sanitize_key( wp_unslash( $_GET['st'] ?? '' ) );
		$pay_f    = sanitize_key( wp_unslash( $_GET['pay'] ?? '' ) );
		$q_f      = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$list = array_values( array_filter( $list, function ( $r ) use ( $lang_f, $store_f, $status_f, $pay_f, $q_f ) {
			if ( $lang_f && $r->lang !== $lang_f ) return false;
			if ( $store_f && $r->store !== $store_f ) return false;
			if ( $status_f && $r->status !== $status_f ) return false;
			if ( 'paid' === $pay_f && ! $r->paid_at ) return false;
			if ( 'unpaid' === $pay_f && ( $r->paid_at || ( 'cancelled' === $r->status && 'cancelled' !== $status_f ) ) ) return false;
			if ( 'cash' === $pay_f && ! BV_Util::is_offline_payment( $r ) ) return false;
			if ( 'refunded' === $pay_f && (int) $r->refund_amount < 1 ) return false;
			if ( '' !== $q_f ) {
				$hay = $r->code . ' ' . $r->sei . $r->mei . ' ' . $r->email . ' ' . $r->phone;
				if ( false === mb_stripos( $hay, $q_f ) ) return false;
			}
			return true;
		} ) );

		$rows = array();
		foreach ( $list as $r ) {
			$rows[] = array(
				$r->code, BV_Util::label( $statuses, $r->status ), ( 'en' === $r->lang ? '英語' : '日本語' ), $r->pickup_dt, $r->return_dt,
				BV_Util::label( $stores, $r->store ), BV_Util::label( $classes, $r->vehicle_class ),
				$r->vehicle_id, $r->sei . ' ' . $r->mei, $r->email, $r->phone,
				$r->coverage, BV_Util::label( BV_Util::shuttles(), $r->shuttle ),
				BV_Util::label( BV_Util::shuttle_statuses(), $r->shuttle_status ?: 'none' ), (int) $r->shuttle_fee,
				$r->is_student ? '学割' : '', $r->coupon_code, $r->price_total,
				BV_Util::payment_state( $r )['text'], (int) $r->refund_amount, $r->trip_distance, $r->created_at,
			);
		}
		self::csv_out( 'reservations-' . date( 'Ymd' ) . '.csv',
			array( '予約番号', 'ステータス', '言語', '貸出日時', '返却日時', '店舗', 'クラス', '車両ID', '氏名', 'メール', '電話', '補償', '送迎', '送迎状態', '送迎料金', '学割', 'クーポン', '合計', '支払', '返金額', '走行距離', '作成日' ), $rows );
	}

	/** 分析CSV（店舗×月次、クラス別、車両別） */
	protected static function csv_analytics() {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$from = sanitize_text_field( wp_unslash( $_GET['from'] ?? date( 'Y-04-01' ) ) );
		$to   = sanitize_text_field( wp_unslash( $_GET['to'] ?? current_time( 'Y-m-d' ) ) );
		$store_filter = sanitize_key( wp_unslash( $_GET['store'] ?? '' ) );
		$stores = BV_Util::stores(); $classes = BV_Util::classes(); $locations = BV_Util::locations();
		$lang_filter = sanitize_key( wp_unslash( $_GET['lang'] ?? '' ) );
		if ( ! in_array( $lang_filter, array( 'ja', 'en' ), true ) ) $lang_filter = '';
		$where = $wpdb->prepare( "status != 'cancelled' AND pickup_dt BETWEEN %s AND %s", $from . ' 00:00:00', $to . ' 23:59:59' );
		if ( $store_filter && isset( $stores[ $store_filter ] ) ) $where .= $wpdb->prepare( ' AND store = %s', $store_filter );
		$where_nolang = $where;
		if ( $lang_filter ) $where .= $wpdb->prepare( ' AND lang = %s', $lang_filter );

		$rows = array();
		$rows[] = array( '対象期間', $from . ' 〜 ' . $to, '', '', '', '' );
		$rows[] = array( '店舗', $store_filter && isset( $stores[ $store_filter ] ) ? $stores[ $store_filter ]['ja'] : '全店舗' );
		$rows[] = array( '予約言語', $lang_filter ? ( 'en' === $lang_filter ? '英語予約' : '日本語予約' ) : '全体' );
		$rows[] = array();

		$rows[] = array( '【予約言語別】※言語フィルタ非適用' );
		$rows[] = array( '予約言語', '件数', '延貸渡日数', '走行km', '売上', '平均単価' );
		$lg = $wpdb->get_results( "SELECT lang, COUNT(*) cnt, COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days,
			COALESCE(SUM(trip_distance),0) km, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$where_nolang} GROUP BY lang" );
		foreach ( $lg as $r ) {
			$rows[] = array( 'en' === $r->lang ? '英語予約' : '日本語予約', (int) $r->cnt, (int) $r->days, (int) $r->km, (int) $r->revenue,
				$r->cnt ? (int) round( $r->revenue / $r->cnt ) : 0 );
		}
		$rows[] = array();

		$rows[] = array( '【店舗×予約言語】※言語フィルタ非適用' );
		$rows[] = array( '店舗', '予約言語', '件数', '延貸渡日数', '売上' );
		$sl = $wpdb->get_results( "SELECT store, lang, COUNT(*) cnt, COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days,
			COALESCE(SUM(price_total),0) revenue FROM {$t} WHERE {$where_nolang} GROUP BY store, lang ORDER BY store, lang" );
		foreach ( $sl as $r ) {
			$rows[] = array( BV_Util::label( $stores, $r->store ), 'en' === $r->lang ? '英語予約' : '日本語予約', (int) $r->cnt, (int) $r->days, (int) $r->revenue );
		}
		$rows[] = array();

		$rows[] = array( '【年月×予約言語】※言語フィルタ非適用' );
		$rows[] = array( '年月', '予約言語', '件数', '売上' );
		$mlg = $wpdb->get_results( "SELECT DATE_FORMAT(pickup_dt,'%Y-%m') ym, lang, COUNT(*) cnt, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$where_nolang} GROUP BY ym, lang ORDER BY ym, lang" );
		foreach ( $mlg as $r ) {
			$rows[] = array( $r->ym, 'en' === $r->lang ? '英語予約' : '日本語予約', (int) $r->cnt, (int) $r->revenue );
		}
		$rows[] = array();

		$rows[] = array( '【店舗別×月次】' );
		$rows[] = array( '年月', '店舗', '件数', '延貸渡日数', '走行km', '売上' );
		$sm = $wpdb->get_results( "SELECT DATE_FORMAT(pickup_dt,'%Y-%m') ym, store, COUNT(*) cnt,
			COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days,
			COALESCE(SUM(trip_distance),0) km, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$where} GROUP BY ym, store ORDER BY ym, store" );
		foreach ( $sm as $r ) {
			$rows[] = array( $r->ym, BV_Util::label( $stores, $r->store ), (int) $r->cnt, (int) $r->days, (int) $r->km, (int) $r->revenue );
		}
		$rows[] = array();

		$rows[] = array( '【店舗別合計】' );
		$rows[] = array( '店舗', '件数', '延貸渡日数', '走行km', '売上', '平均単価' );
		$st = $wpdb->get_results( "SELECT store, COUNT(*) cnt, COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days,
			COALESCE(SUM(trip_distance),0) km, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$where} GROUP BY store ORDER BY revenue DESC" );
		foreach ( $st as $r ) {
			$rows[] = array( BV_Util::label( $stores, $r->store ), (int) $r->cnt, (int) $r->days, (int) $r->km, (int) $r->revenue,
				$r->cnt ? (int) round( $r->revenue / $r->cnt ) : 0 );
		}
		$rows[] = array();

		$rows[] = array( '【店舗×クラス別】' );
		$rows[] = array( '店舗', 'クラス', '件数', '延貸渡日数', '売上' );
		$sc = $wpdb->get_results( "SELECT store, vehicle_class, COUNT(*) cnt,
			COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$where} GROUP BY store, vehicle_class ORDER BY store, revenue DESC" );
		foreach ( $sc as $r ) {
			$rows[] = array( BV_Util::label( $stores, $r->store ), BV_Util::label( $classes, $r->vehicle_class ), (int) $r->cnt, (int) $r->days, (int) $r->revenue );
		}
		$rows[] = array();

		$rows[] = array( '【車両別】' );
		$rows[] = array( '車両', 'クラス', '場所', '件数', '延貸渡日数', '走行km', '売上', '月次経費' );
		$bv = $wpdb->get_results( "SELECT vehicle_id, COUNT(*) cnt, COALESCE(SUM(CEIL(TIMESTAMPDIFF(HOUR,pickup_dt,return_dt)/24)),0) days,
			COALESCE(SUM(trip_distance),0) km, COALESCE(SUM(price_total),0) revenue
			FROM {$t} WHERE {$where} AND vehicle_id > 0 GROUP BY vehicle_id ORDER BY revenue DESC" );
		foreach ( $bv as $r ) {
			$v = BV_DB::get_vehicle( $r->vehicle_id );
			if ( ! $v ) continue;
			$rows[] = array( $v->name, BV_Util::label( $classes, $v->class ), BV_Util::label( $locations, $v->location ),
				(int) $r->cnt, (int) $r->days, (int) $r->km, (int) $r->revenue, (int) $v->monthly_cost );
		}

		self::csv_out( 'analytics-' . $from . '_' . $to . '.csv', array( 'レンタカー経営分析' ), $rows );
	}

	protected static function csv_vehicles() {
		$list = BV_DB::get_vehicles();
		$classes = BV_Util::classes(); $locations = BV_Util::locations();
		$rows = array();
		foreach ( $list as $v ) {
			$rows[] = array(
				$v->id, $v->name, $v->plate, BV_Util::label( $classes, $v->class ), BV_Util::label( $locations, $v->location ),
				$v->color_number, $v->mileage, $v->has_navi ? '○' : '', $v->has_etc ? '○' : '',
				$v->has_child_seat ? '○' : '', $v->has_junior_seat ? '○' : '', $v->has_ski_rack ? '○' : '',
				$v->shaken_date, $v->jibaiseki_date, $v->insurance_date, $v->purchase_date, $v->rental_reg_date, $v->disposal_date,
				$v->monthly_cost, $v->tire_size, $v->wiper_length, $v->notes,
			);
		}
		self::csv_out( 'vehicles-' . date( 'Ymd' ) . '.csv',
			array( 'ID', '車両名', 'ナンバー', 'クラス', '場所', 'カラー番号', '走行距離', 'ナビ', 'ETC', 'チャイルドシート', 'ジュニアシート', 'スキーラック', '車検日', '自賠責期限', '任意保険期限', '購入日', 'レンタカー登録日', '廃車・売却日', '月次経費', 'タイヤサイズ', 'ワイパー長', '備考' ), $rows );
	}

	/* ---------- ガント ---------- */

	public static function page_gantt() {
		echo '<div class="wrap"><h1>予約ガント</h1>';
		self::notice();
		BV_Gantt::render_with_nav(
			admin_url( 'admin.php?page=bvrm-gantt' ),
			admin_url( 'admin.php?page=bvrm-reservations&edit={id}' ),
			array(
				'endpoint' => admin_url( 'admin-ajax.php?action=bvrm_gantt' ),
				'token'    => wp_create_nonce( 'bvrm_gantt' ),
				'new_url'  => admin_url( 'admin.php?page=bvrm-reservations&new=1' ),
			)
		);
		echo '<p class="description">車両の並び順は「車両管理」の並び順で変更できます。</p></div>';
	}

	/** 診断モードの戻し忘れを防ぐ警告 */
	public static function security_notice() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$s = BV_Util::settings();

		/* Sandboxのまま運用すると、お客様に開けない決済リンクを送ってしまう */
		if ( 'production' !== $s['square_env'] && ! empty( $s['square_access_token'] ) ) {
			echo '<div class="notice notice-error"><p><strong>【レンタカー管理】Squareが「Sandbox（テスト）」環境になっています。</strong> ';
			echo 'このまま決済リンクを送ると、お客様の画面で「リンクの期限が切れています」等と表示され、お支払いできません。';
			echo '本番運用中の場合は <a href="' . esc_url( admin_url( 'admin.php?page=bvrm-settings' ) ) . '">設定画面</a> で環境を「本番」に切り替え、本番のアクセストークン・Location ID・Webhook署名キーを設定してください。</p></div>';
		}

		if ( empty( $s['square_skip_sig'] ) ) return;
		echo '<div class="notice notice-warning"><p><strong>【レンタカー管理】Webhookの署名検証が無効になっています（診断モード）。</strong> ';
		echo '不正な決済完了通知を受け付けてしまう恐れがあるため、動作確認が済んだら <a href="' . esc_url( admin_url( 'admin.php?page=bvrm-settings' ) ) . '">設定画面</a> でチェックを外してください。</p></div>';
	}

	public static function notice() {
		$n = get_transient( 'bvrm_notice' );
		if ( $n ) { delete_transient( 'bvrm_notice' ); echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $n ) . '</p></div>'; }
	}

	/* ---------- 予約一覧・編集・追加 ---------- */

	public static function page_reservations() {
		if ( isset( $_GET['edit'] ) )  { self::reservation_form( (int) $_GET['edit'] ); return; }
		if ( isset( $_GET['new'] ) )   { self::reservation_form( 0 ); return; }

		$from = sanitize_text_field( wp_unslash( $_GET['from'] ?? date( 'Y-m-d', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) ) );
		$to   = sanitize_text_field( wp_unslash( $_GET['to'] ?? date( 'Y-m-d', current_time( 'timestamp' ) + 30 * DAY_IN_SECONDS ) ) );
		$lang_f  = sanitize_key( wp_unslash( $_GET['lang'] ?? '' ) );
		if ( ! in_array( $lang_f, array( 'ja', 'en' ), true ) ) $lang_f = '';
		$store_f = sanitize_key( wp_unslash( $_GET['store'] ?? '' ) );
		$stores = BV_Util::stores(); $classes = BV_Util::classes(); $statuses = BV_Util::statuses();

		$status_f = sanitize_key( wp_unslash( $_GET['st'] ?? '' ) );
		if ( ! isset( $statuses[ $status_f ] ) ) $status_f = '';
		$pay_f = sanitize_key( wp_unslash( $_GET['pay'] ?? '' ) );
		if ( ! in_array( $pay_f, array( 'paid', 'unpaid', 'cash', 'refunded' ), true ) ) $pay_f = '';
		$q_f = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );

		$list = BV_DB::get_reservations( array( 'from' => $from, 'to' => $to ) );
		$total_before = count( $list );

		if ( $lang_f || $store_f || $status_f || $pay_f || '' !== $q_f ) {
			$list = array_values( array_filter( $list, function ( $r ) use ( $lang_f, $store_f, $status_f, $pay_f, $q_f ) {
				if ( $lang_f && $r->lang !== $lang_f ) return false;
				if ( $store_f && $r->store !== $store_f ) return false;
				if ( $status_f && $r->status !== $status_f ) return false;
				if ( 'paid' === $pay_f && ! $r->paid_at ) return false;
				/* 未払いの絞り込みでは、キャンセル済みは対象外（回収する必要がないため）。
				   キャンセルを見たい場合はステータス側で選ぶ。 */
				if ( 'unpaid' === $pay_f && ( $r->paid_at || ( 'cancelled' === $r->status && 'cancelled' !== $status_f ) ) ) return false;
				if ( 'cash' === $pay_f && ! BV_Util::is_offline_payment( $r ) ) return false;
				if ( 'refunded' === $pay_f && (int) $r->refund_amount < 1 ) return false;
				if ( '' !== $q_f ) {
					$hay = $r->code . ' ' . $r->sei . $r->mei . ' ' . $r->sei . ' ' . $r->mei . ' ' . $r->email . ' ' . $r->phone;
					if ( false === mb_stripos( $hay, $q_f ) ) return false;
				}
				return true;
			} ) );
		}

		echo '<div class="wrap"><h1 class="wp-heading-inline">予約一覧</h1> <a href="' . esc_url( admin_url( 'admin.php?page=bvrm-reservations&new=1' ) ) . '" class="page-title-action">新規予約を追加</a>';
		self::notice();
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="bvrm-reservations">';
		echo '貸渡日 <input type="date" name="from" value="' . esc_attr( $from ) . '"> 〜 <input type="date" name="to" value="' . esc_attr( $to ) . '"> ';
		echo '店舗 <select name="store"><option value="">全店舗</option>';
		foreach ( $stores as $sk => $stv ) echo '<option value="' . esc_attr( $sk ) . '"' . selected( $store_f, $sk, false ) . '>' . esc_html( $stv['ja'] ) . '</option>';
		echo '</select> 言語 <select name="lang"><option value="">全体</option>';
		echo '<option value="ja"' . selected( $lang_f, 'ja', false ) . '>日本語</option><option value="en"' . selected( $lang_f, 'en', false ) . '>英語</option></select> ';
		echo ' ステータス <select name="st"><option value="">すべて</option>';
		foreach ( $statuses as $stk => $stv ) {
			echo '<option value="' . esc_attr( $stk ) . '"' . selected( $status_f, $stk, false ) . '>' . esc_html( $stv['ja'] ) . '</option>';
		}
		echo '</select>';
		echo ' 支払 <select name="pay"><option value="">すべて</option>';
		foreach ( array( 'unpaid' => '未払い', 'paid' => '支払済み', 'cash' => '現金・振込', 'refunded' => '返金あり' ) as $pk => $pv ) {
			echo '<option value="' . esc_attr( $pk ) . '"' . selected( $pay_f, $pk, false ) . '>' . esc_html( $pv ) . '</option>';
		}
		echo '</select>';
		echo ' <input type="search" name="q" value="' . esc_attr( $q_f ) . '" placeholder="予約番号・氏名・メール・電話" style="width:220px"> ';
		submit_button( '絞り込み', 'secondary', '', false );
		$csv_args = array(
			'page' => 'bvrm-reservations', 'bvrm_action' => 'export_reservations',
			'from' => $from, 'to' => $to, 'store' => $store_f, 'lang' => $lang_f, 'st' => $status_f, 'pay' => $pay_f, 'q' => $q_f,
		);
		$csv = wp_nonce_url( add_query_arg( $csv_args, admin_url( 'admin.php' ) ), 'bvrm_export_reservations' );
		echo ' <a class="button" href="' . esc_url( $csv ) . '">CSVダウンロード</a>';
		if ( $store_f || $lang_f || $status_f || $pay_f || '' !== $q_f ) {
			$clear = add_query_arg( array( 'page' => 'bvrm-reservations', 'from' => $from, 'to' => $to ), admin_url( 'admin.php' ) );
			echo ' <a class="button" href="' . esc_url( $clear ) . '">絞り込みを解除</a>';
		}
		echo '</form>';

		/* 件数と合計（絞り込み結果） */
		$sum_rev = 0; $sum_unpaid = 0;
		foreach ( $list as $rr ) {
			if ( 'cancelled' === $rr->status ) continue;
			$sum_rev += (int) $rr->price_total;
			if ( ! $rr->paid_at ) $sum_unpaid += (int) $rr->price_total;
		}
		echo '<p class="description" style="margin:0 0 8px"><strong>' . count( $list ) . '件</strong>';
		if ( count( $list ) !== $total_before ) echo '（期間内 ' . (int) $total_before . '件中）';
		echo '｜売上合計（キャンセル除く）: <strong>' . esc_html( BV_Util::money( $sum_rev ) ) . '</strong>';
		if ( $sum_unpaid > 0 ) echo '｜うち未入金: <strong style="color:#b32d2e">' . esc_html( BV_Util::money( $sum_unpaid ) ) . '</strong>';
		echo '</p>';

		echo '<table class="wp-list-table widefat striped"><thead><tr><th>予約番号</th><th>ステータス</th><th>言語</th><th>貸出</th><th>返却</th><th>店舗</th><th>クラス</th><th>車両</th><th>氏名</th><th>合計</th><th>支払</th><th>操作</th></tr></thead><tbody>';
		foreach ( $list as $r ) {
			$v = $r->vehicle_id ? BV_DB::get_vehicle( $r->vehicle_id ) : null;
			echo '<tr>';
			echo '<td>' . esc_html( $r->code ) . '</td>';
			echo '<td>' . esc_html( BV_Util::label( $statuses, $r->status ) ) . '</td>';
			echo '<td>' . ( 'en' === $r->lang ? '<span style="background:#f28e2b;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px">EN</span>' : '<span style="background:#eee;padding:1px 6px;border-radius:3px;font-size:11px">JA</span>' ) . '</td>';
			echo '<td>' . esc_html( date( 'Y-m-d H:i', strtotime( $r->pickup_dt ) ) ) . '</td>';
			echo '<td>' . esc_html( date( 'Y-m-d H:i', strtotime( $r->return_dt ) ) ) . '</td>';
			echo '<td>' . esc_html( BV_Util::label( $stores, $r->store ) ) . '</td>';
			echo '<td>' . esc_html( BV_Util::label( $classes, $r->vehicle_class ) ) . '</td>';
			echo '<td>' . esc_html( $v ? $v->name : '未割当' ) . '</td>';
			echo '<td>' . esc_html( $r->sei . ' ' . $r->mei ) . '</td>';
			echo '<td>' . esc_html( BV_Util::money( $r->price_total ) ) . '</td>';
			/* 支払い状況（返金の有無まで表示する） */
			$ps = BV_Util::payment_state( $r );
			echo '<td><span style="color:' . esc_attr( $ps['color'] ) . ';font-weight:600">' . esc_html( $ps['short'] ) . '</span>';
			if ( 'unpaid' === $ps['key'] ) {
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $r->id ) ) . '#bvrm-markpaid" style="font-size:11px">記録</a>';
			}
			if ( '' !== $ps['sub'] ) {
				echo '<br><span style="color:#2271b1;font-size:11px">' . esc_html( $ps['sub'] ) . '</span>';
			}
			if ( ! $r->paid_at && BV_Util::is_offline_payment( $r ) && 'cancelled' !== $r->status ) {
				echo '<br><span style="color:#996800;font-size:11px">' . esc_html( BV_Util::label( BV_Util::payment_methods(), $r->payment_method ) ) . '</span>';
			}
			if ( 'none' !== $r->shuttle ) {
				$sh_color = array( 'requested' => '#d63638', 'quoted' => '#dba617', 'paid' => '#00a32a', 'declined' => '#999' );
				$st = $r->shuttle_status ?: 'requested';
				echo '<br><span style="font-size:11px;color:' . esc_attr( $sh_color[ $st ] ?? '#666' ) . '">送迎:' . esc_html( BV_Util::label( BV_Util::shuttle_statuses(), $st ) ) . '</span>';
			}
			echo '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $r->id ) ) . '">編集</a> | ';
			echo '<a href="' . esc_url( BV_Print::url( $r, 'voucher' ) ) . '" target="_blank">予約票</a> | ';
			echo '<a href="' . esc_url( BV_Print::url( $r, 'checkin' ) ) . '" target="_blank">受付表</a> | ';
			echo '<a href="' . esc_url( BV_Print::url( $r, 'receipt' ) ) . '" target="_blank">領収書</a>';
			if ( 'cancelled' === $r->status ) {
				$del_url = wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=delete_reservation&id=' . $r->id ), 'bvrm_delete_reservation' );
				echo ' | <a style="color:#b32d2e" onclick="return confirm(\'' . esc_js( '予約 ' . $r->code . ' を完全に削除します。取り消せません。よろしいですか？' ) . '\')" href="' . esc_url( $del_url ) . '">削除</a>';
			}
			echo '</td>';
			echo '</tr>';
		}
		if ( ! $list ) echo '<tr><td colspan="12">該当する予約がありません。</td></tr>';
		echo '</tbody></table></div>';
	}

	/** 予約保存処理（admin_init — 画面出力前に実行してリダイレクトエラーを防ぐ） */
	public static function handle_reservation_save() {
		if ( empty( $_POST['bvrm_save_reservation'] ) || ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_GET['page'] ) || 'bvrm-reservations' !== $_GET['page'] ) return;
		$id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$r  = $id ? BV_DB::get_reservation( $id ) : null;
		if ( check_admin_referer( 'bvrm_save_res' ) ) {
			$P = wp_unslash( $_POST );
			$data = array(
				'status' => sanitize_key( $P['status'] ), 'lang' => ( 'en' === $P['lang'] ? 'en' : 'ja' ),
				'store' => sanitize_key( $P['store'] ), 'vehicle_class' => sanitize_key( $P['vehicle_class'] ),
				'vehicle_id' => (int) $P['vehicle_id'],
				'pickup_dt' => sanitize_text_field( $P['pickup_date'] ) . ' ' . sanitize_text_field( $P['pickup_time'] ) . ':00',
				'return_dt' => sanitize_text_field( $P['return_date'] ) . ' ' . sanitize_text_field( $P['return_time'] ) . ':00',
				'sei' => sanitize_text_field( $P['sei'] ), 'mei' => sanitize_text_field( $P['mei'] ),
				'email' => sanitize_email( $P['email'] ), 'phone' => sanitize_text_field( $P['phone'] ),
				'address' => sanitize_textarea_field( $P['address'] ),
				'birthdate' => sanitize_text_field( $P['birthdate'] ) ?: null,
				'opt_child_seat' => (int) ( $P['opt_child_seat'] ?? 0 ), 'opt_junior_seat' => (int) ( $P['opt_junior_seat'] ?? 0 ),
				'opt_ski_rack' => (int) ( $P['opt_ski_rack'] ?? 0 ), 'opt_navi' => (int) ( $P['opt_navi'] ?? 0 ), 'opt_etc' => (int) ( $P['opt_etc'] ?? 0 ),
				/*
				 * その店舗で扱わない内容は保存側でも寄せる。
				 * 記録と料金計算が食い違わないようにするため（料金側も同じ判定をしている）。
				 */
				'coverage' => BV_Util::store_coverage_or_default( sanitize_key( $P['store'] ), $P['coverage'] ),
				'shuttle' => BV_Util::store_allows_shuttle( sanitize_key( $P['store'] ) ) ? sanitize_key( $P['shuttle'] ) : 'none',
				'shuttle_detail' => BV_Util::store_allows_shuttle( sanitize_key( $P['store'] ) ) ? sanitize_textarea_field( $P['shuttle_detail'] ) : '',
				'is_student' => ( ! empty( $P['is_student'] ) && BV_Util::store_allows_student( sanitize_key( $P['store'] ) ) ) ? 1 : 0,
				'coupon_code' => sanitize_text_field( $P['coupon_code'] ),
				'payment_method' => ( isset( $P['payment_method'] ) && isset( BV_Util::payment_methods()[ $P['payment_method'] ] ) ) ? sanitize_key( $P['payment_method'] ) : 'square',
				'manual_discount' => (int) ( $P['manual_discount'] ?? 0 ),
				'manual_discount_note' => sanitize_text_field( $P['manual_discount_note'] ?? '' ),
				'request_note' => sanitize_textarea_field( $P['request_note'] ),
				'admin_memo' => sanitize_textarea_field( $P['admin_memo'] ),
				'price_total' => (int) $P['price_total'],
			);

			/* 再計算指示があれば見積を出し直す */
			if ( ! empty( $P['recalc'] ) ) {
				$quote = BV_Pricing::quote( array(
					'vehicle_class' => $data['vehicle_class'], 'pickup_dt' => $data['pickup_dt'], 'return_dt' => $data['return_dt'],
					'opt_child_seat' => $data['opt_child_seat'], 'opt_junior_seat' => $data['opt_junior_seat'],
					'opt_ski_rack' => $data['opt_ski_rack'], 'opt_navi' => $data['opt_navi'], 'opt_etc' => $data['opt_etc'],
					'coverage' => $data['coverage'], 'shuttle' => $data['shuttle'],
					'is_student' => $data['is_student'], 'coupon_code' => $data['coupon_code'], 'lang' => $data['lang'],
					'manual_discount' => $data['manual_discount'], 'manual_discount_note' => $data['manual_discount_note'],
					'store' => $data['store'],
				) );
				if ( ! is_wp_error( $quote ) ) {
					$data['price_breakdown'] = wp_json_encode( $quote );
					$data['price_total'] = (int) $quote['total'];
				}
			}

			/* 車両を変更したら予約の車両クラスも合わせる */
			if ( ! empty( $P['sync_class'] ) && $data['vehicle_id'] ) {
				$sv = BV_DB::get_vehicle( $data['vehicle_id'] );
				if ( $sv && $sv->class !== $data['vehicle_class'] ) {
					$data['vehicle_class'] = $sv->class;
				}
			}

			/* 送迎の有無が変わったらステータスを追随させる */
			$prev_shuttle = $r ? $r->shuttle : 'none';
			$prev_sh_status = $r ? ( $r->shuttle_status ?: 'none' ) : 'none';
			if ( 'none' === $data['shuttle'] ) {
				if ( 'paid' !== $prev_sh_status ) $data['shuttle_status'] = 'none';
			} elseif ( 'none' === $prev_shuttle || 'none' === $prev_sh_status || 'declined' === $prev_sh_status ) {
				$data['shuttle_status'] = 'requested';
				$data['shuttle_fee'] = 0; $data['shuttle_fee_pickup'] = 0; $data['shuttle_fee_dropoff'] = 0;
				$data['shuttle_link'] = '';
				$data['shuttle_order_id'] = '';
			}

			$prev_status = $r ? $r->status : '';
			/*
			 * 金額が変わったのに未払いの古い決済リンクが残っていると、
			 * お客様が変更前の金額を支払ってしまうため、リンクを破棄して作り直す。
			 */
			$price_changed = false;
			if ( $r && ! $r->paid_at && $r->square_link && (int) $data['price_total'] !== (int) $r->price_total ) {
				$data['square_link'] = '';
				$data['square_order_id'] = '';
				$price_changed = true;
			}
			if ( $id ) {
				BV_DB::update_reservation( $id, $data );
			} else {
				$data['code'] = BV_Util::reservation_code();
				if ( empty( $data['price_breakdown'] ) ) {
					$quote = BV_Pricing::quote( array_merge( $data, array( 'lang' => $data['lang'] ) ) );
					if ( ! is_wp_error( $quote ) ) {
						$data['price_breakdown'] = wp_json_encode( $quote );
						if ( empty( $data['price_total'] ) ) $data['price_total'] = (int) $quote['total'];
					}
				}
				$id = BV_DB::insert_reservation( $data );
			}
			$r = BV_DB::get_reservation( $id );

			/* メール送信モード（電話予約対応：自動/手動/送らない） */
			$mail_mode = sanitize_key( $P['mail_mode'] ?? 'none' );
			if ( 'auto' === $mail_mode ) {
				if ( 'none' === $r->shuttle && ! $r->square_link && ! BV_Util::is_offline_payment( $r ) ) {
					$link = BV_Square::create_payment_link( $r );
					if ( ! is_wp_error( $link ) && $link['url'] ) {
						BV_DB::update_reservation( $id, array( 'square_link' => $link['url'], 'square_order_id' => $link['order_id'] ) );
						$r = BV_DB::get_reservation( $id );
					}
				}
				BV_Mailer::send_provisional( $r, 'auto' );
				set_transient( 'bvrm_notice', '保存し、予約メールを送信しました。', 60 );
			} elseif ( 'manual' === $mail_mode ) {
				BV_Mailer::send_provisional( $r, 'auto' ); /* 手動トリガー */
				set_transient( 'bvrm_notice', '保存し、メールを手動送信しました。', 60 );
			} else {
				set_transient( 'bvrm_notice', '保存しました。' . ( $price_changed ? '金額が変わったため、古い決済リンクを破棄しました。メール送信で新しいリンクを発行してください。' : '' ), 60 );
			}
			if ( 'cancelled' === $r->status && 'cancelled' !== $prev_status ) {
				if ( ! empty( $P['notify_cancel'] ) ) BV_Mailer::send_cancelled( $r );
				BV_Mailer::send_cancelled_admin( $r, 'admin' );
			}
			/* 返却済みになったら、お礼＋口コミ依頼メールを送る（条件を満たす場合のみ） */
			if ( 'returned' === $r->status && 'returned' !== $prev_status ) {
				$sent = BV_Review::maybe_send( $r );
				if ( true === $sent ) {
					set_transient( 'bvrm_notice', '保存し、お礼＋口コミ依頼メールを送信しました。', 60 );
				}
			}
			/* ガントから作成した場合は、同じ表示位置のガントへ戻る */
			if ( ! empty( $P['pf_gstart'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $P['pf_gstart'] ) ) {
				set_transient( 'bvrm_notice', '予約 ' . $r->code . ' を作成しました。', 60 );
				wp_safe_redirect( add_query_arg( array(
					'page' => 'bvrm-gantt',
					'gstart' => sanitize_text_field( $P['pf_gstart'] ),
					'gdays'  => max( 7, min( 60, (int) ( $P['pf_gdays'] ?? 14 ) ) ),
				), admin_url( 'admin.php' ) ) );
				exit;
			}
			wp_safe_redirect( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $id ) );
			exit;
		}
	}

	/** 予約フォーム（新規/編集） — 内容全体を編集できる */
	public static function reservation_form( $id ) {
		$addon_form = ''; /* 差額の請求欄を出したときだけ、本体フォームの外に実体を置く */
		$r = $id ? BV_DB::get_reservation( $id ) : null;
		$stores = BV_Util::stores(); $classes = BV_Util::classes(); $statuses = BV_Util::statuses();
		/* 補償・送迎は店舗によって扱いが異なる（From P出張所は A・B のみ、送迎なし） */
		$cur_store = $r ? $r->store : '';
		$coverages = $cur_store ? BV_Util::store_coverages( $cur_store ) : BV_Util::coverages();
		$shuttles  = $cur_store ? BV_Util::store_shuttles( $cur_store )  : BV_Util::shuttles();
		/* 既存の予約が、いま扱っていないプランのままでも選択肢から消えないようにする */
		if ( $r && $r->coverage && ! isset( $coverages[ $r->coverage ] ) ) {
			$all = BV_Util::coverages();
			if ( isset( $all[ $r->coverage ] ) ) $coverages[ $r->coverage ] = $all[ $r->coverage ];
		}
		if ( $r && $r->shuttle && ! isset( $shuttles[ $r->shuttle ] ) ) {
			$all_sh = BV_Util::shuttles();
			if ( isset( $all_sh[ $r->shuttle ] ) ) $shuttles[ $r->shuttle ] = $all_sh[ $r->shuttle ];
		}
		$vehicles = BV_DB::get_vehicles();

		$val = function ( $k, $d = '' ) use ( $r ) { return $r ? $r->$k : $d; };
		echo '<div class="wrap"><h1>' . ( $id ? '予約編集：' . esc_html( $r->code ) : '新規予約' ) . '</h1>';
		self::notice();
		echo '<form method="post">';
		wp_nonce_field( 'bvrm_save_res' );
		echo '<input type="hidden" name="bvrm_save_reservation" value="1">';
		/* ガントの表示位置を引き継ぐ */
		if ( ! empty( $_GET['pf_gstart'] ) ) {
			echo '<input type="hidden" name="pf_gstart" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['pf_gstart'] ) ) ) . '">';
			echo '<input type="hidden" name="pf_gdays" value="' . (int) ( $_GET['pf_gdays'] ?? 14 ) . '">';
		}
		echo '<table class="form-table">';

		echo '<tr><th>ステータス</th><td><select name="status">';
		foreach ( $statuses as $k => $v2 ) echo '<option value="' . $k . '"' . selected( $val( 'status', 'pending' ), $k, false ) . '>' . esc_html( $v2['ja'] ) . '</option>';
		echo '</select> <label><input type="checkbox" name="notify_cancel" value="1"> キャンセル時に顧客へ通知</label></td></tr>';

		echo '<tr><th>言語</th><td><select name="lang"><option value="ja"' . selected( $val( 'lang', 'ja' ), 'ja', false ) . '>日本語</option><option value="en"' . selected( $val( 'lang' ), 'en', false ) . '>英語</option></select></td></tr>';

		echo '<tr><th>店舗</th><td><select name="store">';
		foreach ( $stores as $k => $v2 ) echo '<option value="' . $k . '"' . selected( $val( 'store', 'hakuba' ), $k, false ) . '>' . esc_html( $v2['ja'] ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th>車両クラス</th><td><select name="vehicle_class">';
		foreach ( $classes as $k => $v2 ) echo '<option value="' . $k . '"' . selected( $val( 'vehicle_class', $pf_class ?: 'compact' ), $k, false ) . '>' . esc_html( BV_Util::class_label_with_capacity( $k ) ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th>車両割当</th><td><select name="vehicle_id"><option value="0">未割当</option>';
		$req_equip = $r ? BV_Availability::required_equipment( $r ) : array();
		$equip_all = BV_Util::equipment();
		foreach ( $vehicles as $v2 ) {
			$lbl = $v2->name . '（' . BV_Util::label( $classes, $v2->class ) . '）';
			/* 申し込まれた装備を積んでいない車両には印を付ける */
			$lack2 = array();
			foreach ( $req_equip as $ek2 ) {
				if ( empty( $v2->{ 'has_' . $ek2 } ) ) $lack2[] = $equip_all[ $ek2 ]['ja'];
			}
			if ( $lack2 ) $lbl .= ' ⚠' . implode( '・', $lack2 ) . 'なし';
			echo '<option value="' . (int) $v2->id . '"' . selected( (int) $val( 'vehicle_id', $pf_vehicle ), (int) $v2->id, false ) . '>' . esc_html( $lbl ) . '</option>';
		}
		echo '</select>';
		if ( $r ) {
			$lack_now = BV_Gantt::missing_equipment( $r );
			if ( $lack_now ) {
				echo '<p style="color:#b32d2e;font-weight:600;margin:6px 0 0">⚠ この予約は「' . esc_html( implode( '・', $lack_now ) )
					. '」をお申し込みですが、割当中の車両には付いていません。車両を変更するか、店頭でご案内してください。</p>';
			} elseif ( $req_equip ) {
				$names = array();
				foreach ( $req_equip as $ek2 ) $names[] = $equip_all[ $ek2 ]['ja'];
				echo '<p class="description" style="margin:6px 0 0">お申し込みの装備：' . esc_html( implode( '・', $names ) ) . '（割当車両に搭載あり）</p>';
			}
		}
		echo '<p><label><input type="checkbox" name="sync_class" value="1" checked> 車両を変更したとき、予約の車両クラスも自動で合わせる</label></p>';
		echo '</td></tr>';

		/* ガントからの引き継ぎ（新規作成時） */
		$pf_vehicle = isset( $_GET['pf_vehicle'] ) ? (int) $_GET['pf_vehicle'] : 0;
		$pf_from    = isset( $_GET['pf_from'] ) ? sanitize_text_field( wp_unslash( $_GET['pf_from'] ) ) : '';
		$pf_to      = isset( $_GET['pf_to'] ) ? sanitize_text_field( wp_unslash( $_GET['pf_to'] ) ) : '';
		$pf_class   = '';
		if ( ! $r && $pf_vehicle ) {
			$pv = BV_DB::get_vehicle( $pf_vehicle );
			if ( $pv ) $pf_class = $pv->class;
		}

		$pd = $r ? date( 'Y-m-d', strtotime( $r->pickup_dt ) ) : ( $pf_from ?: current_time( 'Y-m-d' ) );
		$pt = $r ? date( 'H:i', strtotime( $r->pickup_dt ) ) : '10:00';
		$rd = $r ? date( 'Y-m-d', strtotime( $r->return_dt ) ) : ( $pf_to ?: date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS ) );
		$rt = $r ? date( 'H:i', strtotime( $r->return_dt ) ) : '10:00';
		echo '<tr><th>貸出日時</th><td><input type="date" name="pickup_date" value="' . esc_attr( $pd ) . '"> <input type="time" name="pickup_time" step="1800" value="' . esc_attr( $pt ) . '"></td></tr>';
		echo '<tr><th>返却日時</th><td><input type="date" name="return_date" value="' . esc_attr( $rd ) . '"> <input type="time" name="return_time" step="1800" value="' . esc_attr( $rt ) . '"></td></tr>';

		echo '<tr><th>氏名</th><td>姓 <input type="text" name="sei" value="' . esc_attr( $val( 'sei' ) ) . '"> 名 <input type="text" name="mei" value="' . esc_attr( $val( 'mei' ) ) . '"></td></tr>';
		echo '<tr><th>メール</th><td><input type="email" name="email" class="regular-text" value="' . esc_attr( $val( 'email' ) ) . '"></td></tr>';
		echo '<tr><th>電話</th><td><input type="text" name="phone" value="' . esc_attr( $val( 'phone' ) ) . '"></td></tr>';
		echo '<tr><th>住所</th><td><textarea name="address" rows="2" class="large-text">' . esc_textarea( $val( 'address' ) ) . '</textarea></td></tr>';
		echo '<tr><th>生年月日</th><td><input type="date" name="birthdate" value="' . esc_attr( $val( 'birthdate' ) ) . '"></td></tr>';

		if ( $r && $r->license_files ) {
			$files = json_decode( $r->license_files, true ) ?: array();
			if ( $files ) {
				echo '<tr><th>免許証等</th><td>';
				foreach ( $files as $k => $u ) {
					echo '<a href="' . esc_url( BV_Files::url( $u, 'adm' ) ) . '" target="_blank" rel="noreferrer">' . esc_html( $k ) . '</a> ';
				}
				echo '<p class="description">閲覧リンクは2時間で失効し、管理者としてログインしている間だけ開けます。</p>';
				echo '</td></tr>';
			}
		}

		echo '<tr><th>装備オプション</th><td>';
		foreach ( BV_Util::equipment() as $ek => $ev ) {
			echo '<p style="margin:0 0 6px"><label style="display:inline-block;width:190px">' . esc_html( $ev['ja'] ) . '</label>';
			echo '<select name="opt_' . esc_attr( $ek ) . '" style="width:70px">';
			for ( $i = 0; $i <= (int) $ev['max']; $i++ ) echo '<option value="' . $i . '"' . selected( (int) $val( 'opt_' . $ek, 0 ), $i, false ) . '>' . $i . '</option>';
			echo '</select>';
			if ( ! empty( $ev['note_ja'] ) ) echo ' <span class="description">' . esc_html( $ev['note_ja'] ) . '</span>';
			echo '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th>追加補償</th><td><select name="coverage">';
		foreach ( $coverages as $k => $v2 ) echo '<option value="' . $k . '"' . selected( $val( 'coverage', 'A' ), $k, false ) . '>' . esc_html( $v2['ja'] ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th>送迎</th><td><select name="shuttle">';
		foreach ( $shuttles as $k => $v2 ) echo '<option value="' . $k . '"' . selected( $val( 'shuttle', 'none' ), $k, false ) . '>' . esc_html( $v2['ja'] ) . '</option>';
		echo '</select><br><textarea name="shuttle_detail" rows="2" class="large-text" placeholder="送迎詳細場所">' . esc_textarea( $val( 'shuttle_detail' ) ) . '</textarea></td></tr>';

		echo '<tr><th>学割</th><td><label><input type="checkbox" name="is_student" value="1"' . checked( (int) $val( 'is_student', 0 ), 1, false ) . '> 学割を適用（クラス別固定価格）</label></td></tr>';
		echo '<tr><th>クーポン</th><td><input type="text" name="coupon_code" style="width:320px" value="' . esc_attr( $val( 'coupon_code' ) ) . '"></td></tr>';
		echo '<tr><th>ご要望</th><td><textarea name="request_note" rows="2" class="large-text">' . esc_textarea( $val( 'request_note' ) ) . '</textarea></td></tr>';
		echo '<tr><th>管理メモ</th><td><textarea name="admin_memo" rows="3" class="large-text">' . esc_textarea( $val( 'admin_memo' ) ) . '</textarea></td></tr>';

		echo '<tr><th>特別値引き</th><td><input type="number" name="manual_discount" step="100" value="' . (int) $val( 'manual_discount', 0 ) . '"> 円'
			. ' <input type="text" name="manual_discount_note" style="width:320px" value="' . esc_attr( $val( 'manual_discount_note' ) ) . '" placeholder="理由（例：リピーター割引）">'
			. '<p class="description">プラスの数字＝値引き、マイナスの数字＝追加請求。明細に1行として表示され、領収書にも反映されます。自動再計算をONにしたまま使えます。</p></td></tr>';

		echo '<tr><th>合計金額</th><td><input type="number" name="price_total" value="' . (int) $val( 'price_total', 0 ) . '"> 円 <label><input type="checkbox" name="recalc" value="1" checked> 保存時に料金を自動再計算する</label>'
			. '<p class="description">再計算をOFFにすると、ここに入れた金額をそのまま合計として保存します（明細は変わりません）。値引きは上の「特別値引き」を使うのがおすすめです。</p>';
		if ( $r && $r->price_breakdown ) {
			$bd = json_decode( $r->price_breakdown, true );
			if ( ! empty( $bd['lines'] ) ) {
				echo '<ul style="margin-top:8px">';
				foreach ( $bd['lines'] as $l ) echo '<li>' . esc_html( $l['label'] ) . '：' . esc_html( BV_Util::money( $l['amount'] ) ) . '</li>';
				echo '</ul>';
			}
		}
		echo '</td></tr>';

		echo '<tr><th>お支払い方法</th><td><select name="payment_method">';
		foreach ( BV_Util::payment_methods() as $pm => $pv ) {
			echo '<option value="' . esc_attr( $pm ) . '"' . selected( $val( 'payment_method', 'square' ), $pm, false ) . '>' . esc_html( $pv['ja'] ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description"><strong>現金・銀行振込</strong>を選ぶと、決済リンクは発行されず、支払リマインドや自動キャンセルの対象外になります（電話予約・店頭払い向け）。'
			. 'お客様へのメールには「当日、店頭で○○円をお支払いください」という案内が入ります。<br>'
			. 'お金を受け取ったら、下の操作欄の「現金で受領して確定にする」を押してください。予約が確定になり、領収書も発行できます。</p></td></tr>';

		echo '<tr><th>メール送信</th><td><select name="mail_mode"><option value="none">送信しない</option><option value="auto">自動送信（送迎なしは決済リンク付き）</option><option value="manual">手動送信（今すぐ送る）</option></select><p class="description">電話予約の場合はここで送信方法を選べます。オンライン決済以外を選んでいる場合、決済リンクは付きません。</p></td></tr>';

		if ( $r ) {
			echo '<tr><th>決済</th><td>';
			$ps_edit = BV_Util::payment_state( $r );
			echo '<strong style="color:' . esc_attr( $ps_edit['color'] ) . '">' . esc_html( $ps_edit['text'] );
			if ( $r->paid_at ) echo '（入金 ' . esc_html( date( 'Y-m-d H:i', strtotime( $r->paid_at ) ) ) . '）';
			echo '</strong> ';
			if ( '' !== $ps_edit['sub'] ) {
				echo '<span style="color:#2271b1">／' . esc_html( $ps_edit['sub'] ) . '</span> ';
			}
			$loc_id = BV_Util::store_square_location( $r->store, $r->lang );
			echo '<br><span class="description">使用するSquare Location ID: <code>' . esc_html( $loc_id ?: '未設定' ) . '</code>（' . esc_html( BV_Util::label( $stores, $r->store ) . ' / ' . ( 'en' === $r->lang ? '英語予約' : '日本語予約' ) ) . '）</span>';
			if ( $r->square_link ) echo '<br>リンク: <a href="' . esc_url( $r->square_link ) . '" target="_blank">' . esc_html( $r->square_link ) . '</a>';
			echo '<br>';

			/* 現金・振込のときは、オンライン決済のボタンより現地精算を前面に出す */
			if ( BV_Util::is_offline_payment( $r ) && ! $r->paid_at ) {
				$pm_label = BV_Util::label( BV_Util::payment_methods(), $r->payment_method );
				echo '<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:10px;margin:8px 0;max-width:640px">';
				echo '<strong>お支払い方法：' . esc_html( $pm_label ) . '</strong>';
				echo '<p style="margin:6px 0">この予約はオンライン決済を使いません。自動キャンセル・支払リマインドの対象外です。<br>'
					. '受領したら下のボタンを押してください（合計 <strong>' . esc_html( BV_Util::money( (int) $r->price_total ) ) . '</strong>）。</p>';
				$cash_url = wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=mark_cash_paid&id=' . $r->id ), 'bvrm_mark_cash_paid' );
				echo '<a class="button button-primary" onclick="return confirm(\'' . esc_js( BV_Util::money( (int) $r->price_total ) . 'を受領済みとして、予約を確定にします。よろしいですか？' ) . '\')" href="' . esc_url( $cash_url ) . '">現金で受領して確定にする</a> ';
				echo '<a class="button" onclick="return confirm(\'受領済みとして確定し、お客様へ確定メールも送信します。よろしいですか？\')" href="' . esc_url( add_query_arg( 'notify', 1, $cash_url ) ) . '">受領＋確定メールを送る</a>';
				echo '</div>';
			}

			if ( ! BV_Util::is_offline_payment( $r ) ) {
				echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=send_paylink&id=' . $r->id ), 'bvrm_send_paylink' ) ) . '">決済リンクを生成して送信</a> ';
			}
			if ( $r->square_link && ! $r->paid_at ) {
				echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=send_reminder&id=' . $r->id ), 'bvrm_send_reminder' ) ) . '">支払リマインドを送信</a> ';
				echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=sync_payment&id=' . $r->id ), 'bvrm_sync_payment' ) ) . '">Squareで支払状況を確認</a> ';
				echo '<p class="description">お客様が支払ったのに未払いのままの場合は、このボタンでSquareに直接問い合わせて確定できます（Webhookの代替）。</p>';
			}
			/* ---- 追加請求（差額） ---- */
			$bal      = BV_Util::balance( $r );
			$paid_net = BV_Util::paid_net( $r );
			if ( $r->paid_at || 'paid' === $r->addon_status || $bal !== (int) $r->price_total ) {
				echo '<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid ' . ( $bal > 0 ? '#dba617' : '#2271b1' ) . ';padding:12px;margin:12px 0;max-width:720px">';
				echo '<strong>お支払いの過不足</strong>';
				echo '<p style="margin:6px 0 10px">現在の合計 <strong>' . esc_html( BV_Util::money( (int) $r->price_total ) ) . '</strong>'
					. '／収納済み <strong>' . esc_html( BV_Util::money( $paid_net ) ) . '</strong>';
				if ( (int) $r->refund_amount > 0 ) echo '<span class="description">（返金 ' . esc_html( BV_Util::money( (int) $r->refund_amount ) ) . ' を差し引いた額）</span>';
				echo '<br>';
				if ( $bal > 0 ) {
					echo '<span style="color:#b32d2e;font-size:15px">不足 <strong>' . esc_html( BV_Util::money( $bal ) ) . '</strong>（追加請求が必要です）</span>';
				} elseif ( $bal < 0 ) {
					echo '<span style="color:#2271b1;font-size:15px">過払い <strong>' . esc_html( BV_Util::money( -$bal ) ) . '</strong>（返金が必要です。下の「返金する」をお使いください）</span>';
				} else {
					echo '<span style="color:#00a32a">過不足はありません。</span>';
				}
				echo '</p>';

				if ( BV_Util::has_pending_addon( $r ) ) {
					echo '<div class="notice notice-warning inline" style="margin:0 0 10px"><p><strong>追加請求 ' . esc_html( BV_Util::money( (int) $r->addon_amount ) ) . ' を請求中です（入金待ち）。</strong>'
						. ( $r->addon_note ? '<br>理由：' . esc_html( $r->addon_note ) : '' ) . '</p></div>';
					if ( $r->addon_link ) {
						echo '<p style="margin:0 0 8px"><span class="description">決済リンク：</span><br><code style="user-select:all;font-size:11px">' . esc_html( $r->addon_link ) . '</code></p>';
					}
					$au = function ( $act ) use ( $r ) {
						return wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=' . $act . '&id=' . $r->id ), 'bvrm_' . $act );
					};
					echo '<a class="button" href="' . esc_url( $au( 'resend_addon' ) ) . '">追加請求のメールを再送</a> ';
					echo '<a class="button button-primary" href="' . esc_url( $au( 'sync_addon' ) ) . '">Squareで入金を確認</a> ';
					echo '<a class="button" onclick="return confirm(\'現金などで受領済みとして、追加請求の入金を記録します。よろしいですか？\')" href="' . esc_url( $au( 'mark_addon_paid' ) ) . '">受領済みにする</a> ';
					echo '<a class="button" style="color:#b32d2e;border-color:#b32d2e" onclick="return confirm(\'この追加請求を取り消します。発行済みの決済リンクは使えなくなります。よろしいですか？\')" href="' . esc_url( $au( 'cancel_addon' ) ) . '">追加請求を取り消す</a>';
				} elseif ( $bal > 0 ) {
					if ( ! is_email( $r->email ) ) {
						echo '<p class="description" style="margin:0">メールアドレスが登録されていないため、決済リンクを送信できません。</p>';
					} else {
						/*
						 * ここは予約編集フォームの内側なので <form> を置けない（HTMLはフォームの
						 * 入れ子を許さず、ブラウザが内側のタグを捨ててしまう）。返金・送迎と同じく、
						 * フォームの実体は本体フォームの外に出し、form属性で結び付ける。
						 */
						$addon_form = 'bvrm-addon-form';
						echo '<p style="margin:0 0 8px"><label style="display:inline-block;width:90px">請求額</label>';
						echo '<input type="number" form="' . esc_attr( $addon_form ) . '" name="addon_amount" min="1" step="1" style="width:130px" value="' . (int) $bal . '"> 円';
						echo ' <span class="description">既定は不足額です。必要に応じて変更できます。</span></p>';
						echo '<p style="margin:0 0 10px"><label style="display:inline-block;width:90px">理由</label>';
						echo '<input type="text" form="' . esc_attr( $addon_form ) . '" name="addon_note" class="regular-text" maxlength="120" placeholder="例：返却日を1日延長／補償プランをCに変更" value="' . esc_attr( self::guess_addon_note( $r ) ) . '"></p>';
						echo '<button class="button button-primary" form="' . esc_attr( $addon_form ) . '">差額の決済リンクを作成してお客様へ送る</button>';
						echo '<p class="description" style="margin:6px 0 0">お客様には<strong>差額だけ</strong>を請求します。お支払い済みの分を重ねて請求することはありません。</p>';
					}
				} elseif ( 'paid' === $r->addon_status && (int) $r->addon_amount > 0 ) {
					echo '<p class="description" style="margin:0">直近の追加請求 ' . esc_html( BV_Util::money( (int) $r->addon_amount ) ) . ' は入金済みです'
						. ( $r->addon_paid_at ? '（' . esc_html( date( 'Y-m-d H:i', strtotime( $r->addon_paid_at ) ) ) . '）' : '' ) . '。</p>';
				}
				echo '</div>';
			}

			/* ---- 返金 ---- */
			if ( $r->paid_at ) {
				/* 返金できるのは実際に収納した額まで（金額変更後も正しく上限がかかるようにする） */
				$rf_total = (int) $r->paid_amount ?: (int) $r->price_total;
				$rf_done  = (int) $r->refund_amount;
				$rf_left  = max( 0, $rf_total - $rf_done );
				$rf_form  = 'bvrm-refund-form';
				$cc_rf    = BV_Util::cancel_charge( $r );

				echo '<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:12px;margin:12px 0;max-width:720px">';
				echo '<strong>返金する</strong>';
				echo '<p style="margin:6px 0 10px">お預かり額 <strong>' . esc_html( BV_Util::money( $rf_total ) ) . '</strong>';
				if ( $rf_done > 0 ) echo '／返金済み <strong style="color:#2271b1">' . esc_html( BV_Util::money( $rf_done ) ) . '</strong>';
				echo '／<span style="color:#b32d2e">返金可能額 <strong>' . esc_html( BV_Util::money( $rf_left ) ) . '</strong></span></p>';

				if ( $rf_left < 1 ) {
					echo '<p class="description" style="margin:0">全額返金済みのため、これ以上返金できません。</p>';
				} elseif ( ! $r->square_payment_id ) {
					echo '<div class="notice notice-warning inline" style="margin:0"><p>この予約には決済IDが記録されていないため、システムからは返金できません。'
						. 'Square側で返金するか、現金・振込でご返金ください。</p></div>';
				} else {
					echo '<table class="form-table" style="margin:0"><tbody>';

					/* 割合ボタン */
					echo '<tr><th style="width:120px;padding:8px 10px 8px 0">割合で返金</th><td style="padding:8px 0">';
					foreach ( array( 100, 80, 70, 50 ) as $pct ) {
						$amt = (int) round( $rf_total * $pct / 100 );
						$dis = ( $amt > $rf_left ) ? ' disabled' : '';
						$cf  = BV_Util::money( $amt ) . ' を返金します（' . $pct . '%）。よろしいですか？';
						echo '<button class="button" form="' . esc_attr( $rf_form ) . '" name="refund_mode" value="pct' . $pct . '"'
							. $dis . ' style="margin:0 6px 6px 0" onclick="return confirm(\'' . esc_js( $cf ) . '\')">'
							. $pct . '%<small style="color:#666"> ' . esc_html( BV_Util::money( $amt ) ) . '</small></button>';
					}
					echo '<p class="description" style="margin:2px 0 0">ご予約金額に対する割合です。返金可能額を超えるものは押せません。</p></td></tr>';

					/* キャンセルポリシー適用 */
					echo '<tr><th style="padding:8px 10px 8px 0">ポリシー適用</th><td style="padding:8px 0">';
					$cf2 = 'キャンセルポリシー（' . $cc_rf['label_ja'] . '／キャンセル料 ' . $cc_rf['pct'] . '%）を適用し、'
						. BV_Util::money( (int) $cc_rf['refund'] ) . ' を返金します。よろしいですか？';
					$dis2 = ( (int) $cc_rf['refund'] < 1 || (int) $cc_rf['refund'] > $rf_left ) ? ' disabled' : '';
					echo '<button class="button button-primary" form="' . esc_attr( $rf_form ) . '" name="refund_mode" value="policy"'
						. $dis2 . ' onclick="return confirm(\'' . esc_js( $cf2 ) . '\')">ポリシーどおりに返金（'
						. esc_html( BV_Util::money( (int) $cc_rf['refund'] ) ) . '）</button>';
					echo '<p class="description" style="margin:4px 0 0">現在の適用区分：<strong>' . esc_html( $cc_rf['label_ja'] )
						. '（キャンセル料 ' . (int) $cc_rf['pct'] . '%＝' . esc_html( BV_Util::money( (int) $cc_rf['fee'] ) ) . '）</strong>'
						. ( (int) $cc_rf['refund'] < 1 ? '／返金額が0円のため実行できません。' : '' ) . '</p></td></tr>';

					/* 金額を指定 */
					echo '<tr><th style="padding:8px 10px 8px 0">金額を指定</th><td style="padding:8px 0">';
					echo '<input type="number" form="' . esc_attr( $rf_form ) . '" name="refund_manual" min="1" max="' . (int) $rf_left . '" step="1" style="width:140px" placeholder="例: 5000"> 円 ';
					echo '<button class="button" form="' . esc_attr( $rf_form ) . '" name="refund_mode" value="manual" onclick="return confirm(\'入力した金額を返金します。よろしいですか？\')">この金額を返金</button>';
					echo '<p class="description" style="margin:4px 0 0">1円〜' . esc_html( BV_Util::money( $rf_left ) ) . 'の範囲で指定できます。分割して複数回返金することもできます。</p></td></tr>';

					echo '<tr><th style="padding:8px 10px 8px 0">メモ</th><td style="padding:8px 0">'
						. '<input type="text" form="' . esc_attr( $rf_form ) . '" name="refund_memo" class="regular-text" placeholder="例：日程変更にともなう差額返金">'
						. '<p class="description" style="margin:4px 0 0">管理メモに返金額と一緒に残ります。</p></td></tr>';
					echo '</tbody></table>';
				}
				echo '</div>';
			}

			/* キャンセルポリシーを適用した取消（返金も自動） */
			if ( ! in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) {
				$cc = BV_Util::cancel_charge( $r );
				$nc = BV_Util::cancel_charge( $r, true );
				echo '<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #d63638;padding:10px;margin:12px 0 0;max-width:640px">';
				echo '<strong>キャンセルポリシーを適用して取消</strong>';
				echo '<p style="margin:6px 0">現在の適用区分：<strong>' . esc_html( $cc['label_ja'] ) . '（キャンセル料 ' . (int) $cc['pct'] . '%＝' . esc_html( BV_Util::money( (int) $cc['fee'] ) ) . '）</strong><br>';
				echo $r->paid_at
					? '返金予定額：<strong>' . esc_html( BV_Util::money( (int) $cc['refund'] ) ) . '</strong>（Square決済であれば自動で返金します）'
					: '未入金のため返金はありません。';
				echo '</p>';
				$pc_url = wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=policy_cancel&id=' . $r->id ), 'bvrm_policy_cancel' );
				$ns_url = wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=noshow_cancel&id=' . $r->id ), 'bvrm_noshow_cancel' );
				echo '<a class="button" onclick="return confirm(\'' . esc_js( 'キャンセル料 ' . $cc['pct'] . '%（' . BV_Util::money( (int) $cc['fee'] ) . '）を適用してキャンセルします。返金がある場合は自動で処理され、お客様にもメールが届きます。よろしいですか？' ) . '\')" href="' . esc_url( $pc_url ) . '">キャンセルする（ポリシー適用・返金自動）</a> ';
				echo '<a class="button" style="color:#b32d2e;border-color:#b32d2e" onclick="return confirm(\'' . esc_js( '無断キャンセル（No-show）として、キャンセル料 ' . $nc['pct'] . '%（' . BV_Util::money( (int) $nc['fee'] ) . '）を適用します。よろしいですか？' ) . '\')" href="' . esc_url( $ns_url ) . '">無断キャンセル（No-show）にする</a>';
				echo '<p class="description" style="margin-bottom:0">下の「ステータス」を手動で「キャンセル」に変えた場合、キャンセル料の計算と自動返金は行われません。通常はこちらのボタンをお使いください。</p>';
				echo '</div>';
			}
			echo '</td></tr>';
			/* ---- 送迎リクエストの管理 ---- */
			if ( 'none' !== $r->shuttle ) {
				$sh_statuses = BV_Util::shuttle_statuses();
				echo '<tr><th>送迎リクエスト</th><td style="background:#fffbe6">';
				echo '<p style="margin-top:0"><strong>' . esc_html( BV_Util::label( BV_Util::shuttles(), $r->shuttle ) ) . '</strong>　';
				echo 'ステータス: <strong>' . esc_html( BV_Util::label( $sh_statuses, $r->shuttle_status ?: 'requested' ) ) . '</strong>';
				if ( $r->shuttle_fee ) echo '　料金: <strong>' . esc_html( BV_Util::shuttle_fee_text( $r ) ) . '</strong>';
				if ( $r->shuttle_paid_at ) echo '　<span style="color:#00a32a">支払済み（' . esc_html( $r->shuttle_paid_at ) . '）</span>';
				echo '</p>';
				if ( $r->shuttle_detail ) echo '<p>送迎場所：' . nl2br( esc_html( $r->shuttle_detail ) ) . '</p>';

				if ( 'paid' !== $r->shuttle_status ) {
					$cur_p = BV_Util::shuttle_fee_parts( $r );
					echo '<p><strong>送迎料金を決めて承認</strong>'
						. ( 'round' === $r->shuttle ? '（往復：行き・帰りで別の料金にできます）' : '（片道）' ) . '</p>';
					/*
					 * 予約編集ページ全体が1つのフォームのため、ここでフォームを入れ子にすると
					 * ブラウザに無視されてボタンが効かなくなる。
					 * 実体はページ下部に置き、各入力は form 属性で紐づける。
					 */
					$sh_form = 'bvrm-shuttle-approve';

					$sel_html = function ( $name, $label, $cur ) use ( $sh_form ) {
						echo '<label style="display:inline-block;margin-right:14px">' . esc_html( $label ) . ' ';
						echo '<select form="' . esc_attr( $sh_form ) . '" name="' . esc_attr( $name ) . '">';
						echo '<option value="">選択</option>';
						foreach ( BV_Util::shuttle_fees() as $fee ) {
							echo '<option value="' . (int) $fee . '"' . selected( (int) $cur, (int) $fee, false ) . '>' . esc_html( number_format( $fee ) ) . '円</option>';
						}
						echo '<option value="0"' . selected( 0, (int) $cur, false ) . '>0円（無料）</option>';
						echo '</select></label>';
					};
					if ( BV_Util::shuttle_has_pickup( $r->shuttle ) )  $sel_html( 'fee_pickup', 'お迎え（行き）', $cur_p['pickup'] );
					if ( BV_Util::shuttle_has_dropoff( $r->shuttle ) ) $sel_html( 'fee_dropoff', 'お送り（帰り）', $cur_p['dropoff'] );
					echo ' <button class="button button-primary" form="' . esc_attr( $sh_form ) . '" onclick="return confirm(\'この料金で承認し、お客様に決済リンクを送信します。よろしいですか？\')">承認して決済リンクを送る</button>';
					if ( $r->shuttle_link ) {
						echo '<p>決済リンク: <a href="' . esc_url( $r->shuttle_link ) . '" target="_blank">' . esc_html( $r->shuttle_link ) . '</a><br>';
						echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=resend_shuttle_link&id=' . $r->id ), 'bvrm_resend_shuttle_link' ) ) . '">決済リンクを再送</a> ';
						echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=sync_shuttle_payment&id=' . $r->id ), 'bvrm_sync_shuttle_payment' ) ) . '">Squareで支払状況を確認</a> ';
					echo '<a class="button" onclick="return confirm(\'現地払いなどで受領済みとして送迎を確定します。よろしいですか？\')" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=mark_shuttle_paid&id=' . $r->id ), 'bvrm_mark_shuttle_paid' ) ) . '">支払済みにして確定</a></p>';
					}
					echo '<p><a class="button" style="color:#b32d2e;border-color:#b32d2e" onclick="return confirm(\'送迎不可としてお客様にご連絡します。よろしいですか？\')" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=decline_shuttle&id=' . $r->id ), 'bvrm_decline_shuttle' ) ) . '">送迎不可として連絡</a></p>';
				}
				echo '<p class="description">送迎料金は車両料金とは別決済です。お客様の決済完了（Webhook受信）で自動的に「送迎確定」になります。</p>';
				echo '</td></tr>';
			}

			if ( 'returned' === $r->status ) {
				/* お礼・口コミ依頼メールとお礼クーポンの状況 */
				echo '<tr><th>口コミ依頼</th><td>';
				if ( ! empty( $r->review_mail_at ) ) {
					echo '送信済み（' . esc_html( date( 'Y-m-d H:i', strtotime( $r->review_mail_at ) ) ) . '）';
				} else {
					echo '<span style="color:#666">未送信</span>';
					$why = BV_Review::can_send( $r );
					if ( true !== $why ) echo '　<span class="description">' . esc_html( $why ) . '</span>';
				}
				if ( ! empty( $r->review_coupon_code ) ) {
					echo '<br>お礼クーポン発行済み：<code>' . esc_html( $r->review_coupon_code ) . '</code>'
						. ( $r->review_done_at ? '（' . esc_html( date( 'Y-m-d H:i', strtotime( $r->review_done_at ) ) ) . '）' : '' );
				} else {
					echo '<br><span class="description">お礼クーポンは未発行です（お客様がメール内のリンクを押すと発行されます）。</span>';
				}
				if ( is_email( $r->email ) ) {
					$label = ! empty( $r->review_mail_at ) ? 'お礼・口コミ依頼メールを再送' : 'お礼・口コミ依頼メールを送信';
					echo '<p><a class="button" onclick="return confirm(\'お客様へお礼＋口コミ依頼メールを送信します。よろしいですか？\')" href="'
						. esc_url( wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=send_review&id=' . $r->id . '&edit=' . $r->id ), 'bvrm_send_review' ) )
						. '">' . esc_html( $label ) . '</a></p>';
				}
				echo '</td></tr>';

				echo '<tr><th>返却情報</th><td>メーター: ' . (int) $r->return_odometer . ' km ｜ 走行: ' . (int) $r->trip_distance . ' km ｜ 返却場所: ' . esc_html( BV_Util::label( BV_Util::locations(), $r->return_location ) ) . '<br>満タン: ' . ( $r->fuel_full ? '○' : '－' ) . ' ｜ 無事故: ' . ( $r->no_accident ? '○' : '－' ) . '<br>' . esc_html( $r->return_memo ) . '</td></tr>';
			}
			echo '<tr><th>印刷</th><td><a class="button" target="_blank" href="' . esc_url( BV_Print::url( $r, 'voucher' ) ) . '">予約票</a> <a class="button" target="_blank" href="' . esc_url( BV_Print::url( $r, 'checkin' ) ) . '">受付表</a> <a class="button" target="_blank" href="' . esc_url( BV_Print::url( $r, 'receipt' ) ) . '">領収書</a></td></tr>';
		}
		echo '</table>';
		submit_button( $id ? '予約を更新' : '予約を作成' );
		echo '</form>';

		/* 追加請求フォームの実体（上の欄の入力を form 属性で受け取る） */
		if ( ! empty( $addon_form ) ) {
			echo '<form id="' . esc_attr( $addon_form ) . '" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			echo '<input type="hidden" name="page" value="bvrm-reservations">';
			echo '<input type="hidden" name="bvrm_action" value="charge_addon">';
			echo '<input type="hidden" name="id" value="' . (int) $r->id . '">';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'bvrm_charge_addon' ) ) . '">';
			echo '</form>';
		}

		/* 返金フォームの実体（上の表の入力を form 属性で受け取る） */
		if ( $r && $r->paid_at && (int) $r->refund_amount < (int) $r->price_total && $r->square_payment_id ) {
			echo '<form id="bvrm-refund-form" method="post">';
			wp_nonce_field( 'bvrm_refund_' . $r->id );
			echo '<input type="hidden" name="reservation_id" value="' . (int) $r->id . '">';
			echo '<input type="hidden" name="bvrm_do_refund" value="1">';
			echo '</form>';
		}

		/* 送迎承認フォームの実体（上の表の入力を form 属性で受け取る） */
		if ( $r && 'none' !== $r->shuttle && 'paid' !== $r->shuttle_status ) {
			echo '<form id="bvrm-shuttle-approve" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			echo '<input type="hidden" name="page" value="bvrm-reservations">';
			echo '<input type="hidden" name="bvrm_action" value="approve_shuttle">';
			echo '<input type="hidden" name="id" value="' . (int) $r->id . '">';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'bvrm_approve_shuttle' ) ) . '">';
			echo '</form>';
		}

		/* ---- 入金の手動記録（未払いの予約のみ） ---- */
		if ( $r && ! $r->paid_at && 'cancelled' !== $r->status ) {
			echo '<hr id="bvrm-markpaid"><h2>入金を記録する（手動で支払済みにする）</h2>';
			echo '<p class="description">Squareの店頭端末でお支払いいただいた場合、電話でご案内して別途お支払いいただいた場合、'
				. 'Webhookの取りこぼしで未払いのままになっている場合などに使います。<br>'
				. '記録すると<strong>予約が確定になり、領収書も発行できる</strong>ようになります。管理メモに日時と受領方法が残ります。</p>';

			if ( $r->square_order_id ) {
				echo '<div class="notice notice-info inline" style="margin:8px 0"><p>'
					. 'この予約にはSquareの注文IDが記録されています。決済リンクからお支払いいただいた場合は、'
					. '先に上の「<strong>Squareで支払状況を確認</strong>」をお試しください。実際の決済IDと紐づけて記録できます。</p></div>';
			}

			echo '<form method="post" style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #00a32a;padding:12px;max-width:720px">';
			wp_nonce_field( 'bvrm_mark_paid_' . $r->id );
			echo '<input type="hidden" name="reservation_id" value="' . (int) $r->id . '">';
			echo '<p style="margin:0 0 10px;font-size:15px">請求額：<strong style="font-size:18px">' . esc_html( BV_Util::money( (int) $r->price_total ) ) . '</strong></p>';

			echo '<table class="form-table" style="margin:0"><tbody>';
			echo '<tr><th style="width:130px;padding:8px 10px 8px 0">受領方法</th><td style="padding:8px 0"><select name="paid_way">';
			foreach ( self::paid_ways() as $wk => $wv ) {
				echo '<option value="' . esc_attr( $wk ) . '">' . esc_html( $wv ) . '</option>';
			}
			echo '</select></td></tr>';
			echo '<tr><th style="padding:8px 10px 8px 0">入金日時</th><td style="padding:8px 0">'
				. '<input type="datetime-local" name="paid_at" value="' . esc_attr( current_time( 'Y-m-d\TH:i' ) ) . '">'
				. '<p class="description" style="margin:4px 0 0">実際にお支払いいただいた日時を入れてください（空欄なら現在時刻）。売上の計上日になります。</p></td></tr>';
			echo '<tr><th style="padding:8px 10px 8px 0">メモ</th><td style="padding:8px 0">'
				. '<input type="text" name="paid_memo" class="regular-text" placeholder="例：8/20 店頭端末で決済、レシート番号1234"></td></tr>';
			echo '<tr><th style="padding:8px 10px 8px 0">お客様への通知</th><td style="padding:8px 0">'
				. '<label><input type="checkbox" name="notify_customer" value="1"' . ( $r->email ? '' : ' disabled' ) . '> 予約確定メールを送る</label>';
			if ( ! $r->email ) echo '<p class="description" style="margin:4px 0 0">メールアドレスの登録がないため送信できません。</p>';
			else echo '<p class="description" style="margin:4px 0 0">すでに口頭やメールでご連絡済みの場合は、チェックを外してください。</p>';
			echo '</td></tr>';
			echo '</tbody></table>';

			$cf = 'この予約を「支払済み」として記録し、予約を確定にします。\n\n請求額：' . BV_Util::money( (int) $r->price_total ) . '\n\nよろしいですか？';
			echo '<p style="margin:10px 0 0"><button class="button button-primary" name="bvrm_mark_paid" value="1" onclick="return confirm(\'' . esc_js( $cf ) . '\')">入金済みにして予約を確定する</button></p>';
			echo '</form>';
		}

		/* 予約の削除 */
		if ( $r ) {
			echo '<hr><h2>この予約を削除</h2>';
			echo '<p class="description">通常は「ステータス」を<strong>キャンセル</strong>に変更してください。キャンセルなら履歴が残り、売上分析や貸渡実績報告書の整合性が保たれます。<br>';
			echo '完全削除は、テスト予約や誤登録など<strong>記録に残す必要がない予約</strong>にのみお使いください。<span style="color:#b32d2e">この操作は取り消せません。</span></p>';
			if ( $r->paid_at ) {
				echo '<div class="notice notice-warning inline"><p><strong>この予約は支払済みです（' . esc_html( $r->paid_at ) . '）。</strong>削除すると売上記録も消えます。返金処理が必要な場合は、削除前にSquare側で返金してください。</p></div>';
			}
			$del_url = wp_nonce_url( admin_url( 'admin.php?page=bvrm-reservations&bvrm_action=delete_reservation&id=' . $r->id ), 'bvrm_delete_reservation' );
			$confirm = '予約 ' . $r->code . '（' . trim( $r->sei . ' ' . $r->mei ) . '）を完全に削除します。\n\nこの操作は取り消せません。売上分析・報告書からも除外されます。\n\n本当に削除しますか？';
			echo '<p><a class="button" style="color:#b32d2e;border-color:#b32d2e" onclick="return confirm(\'' . esc_js( $confirm ) . '\')" href="' . esc_url( $del_url ) . '">この予約を完全に削除する</a></p>';
		}
		echo '</div>';
	}
}
