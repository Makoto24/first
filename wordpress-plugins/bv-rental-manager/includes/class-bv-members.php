<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 会員機能＋予約確認・変更・キャンセルページ（?bv_manage=予約番号）
 * 認証: メール＋会員パスワード、または メール認証コード（OTP）
 */
class BV_Members {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_role' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_manage_page' ) );
	}

	public static function register_role() {
		if ( ! get_role( 'bv_member' ) ) {
			add_role( 'bv_member', 'レンタカー会員', array( 'read' => true ) );
		}
	}

	/** 会員登録（メール認証済み前提）。既存ならIDを返す */
	public static function register( $email, $password, $sei, $mei, $phone, $address, $birthdate ) {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			update_user_meta( $user->ID, 'bv_phone', $phone );
			return $user->ID;
		}
		$user_id = wp_insert_user( array(
			'user_login' => sanitize_user( 'bv_' . preg_replace( '/[^a-z0-9]/i', '', strtolower( strtok( $email, '@' ) ) ) . wp_rand( 100, 999 ), true ),
			'user_email' => $email,
			'user_pass'  => $password,
			'last_name'  => $sei,
			'first_name' => $mei,
			'role'       => 'bv_member',
		) );
		if ( is_wp_error( $user_id ) ) return $user_id;
		update_user_meta( $user_id, 'bv_phone', $phone );
		update_user_meta( $user_id, 'bv_address', $address );
		update_user_meta( $user_id, 'bv_birthdate', $birthdate );
		return $user_id;
	}

	/* ---------- 照会用トークン（認証成功後1〜2時間有効） ---------- */

	protected static function token( $r, $hour_offset = 0 ) {
		$hour = date( 'YmdH', time() - $hour_offset * HOUR_IN_SECONDS );
		return substr( hash_hmac( 'sha256', 'bvmanage|' . $r->code . '|' . $hour, wp_salt( 'auth' ) ), 0, 24 );
	}

	protected static function check_token( $r, $token ) {
		if ( ! $token ) return false;
		return hash_equals( self::token( $r, 0 ), $token ) || hash_equals( self::token( $r, 1 ), $token );
	}

	/** 免許証等の画像アップロード処理。更新があれば true */
	protected static function handle_license_upload( $r ) {
		if ( empty( $_FILES ) ) return false;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$fields = ( 'en' === $r->lang )
			? array( 'passport' => 'パスポート', 'intl_license' => '国際免許証' )
			: array( 'license_front' => '免許証（表）', 'license_back' => '免許証（裏）' );

		$files = json_decode( (string) $r->license_files, true );
		if ( ! is_array( $files ) ) $files = array();
		$changed = false;

		foreach ( $fields as $key => $label ) {
			$fk = 'bv_file_' . $key;
			if ( empty( $_FILES[ $fk ]['name'] ) ) continue;
			if ( ! empty( $_FILES[ $fk ]['error'] ) ) continue;
			if ( $_FILES[ $fk ]['size'] > 10 * 1024 * 1024 ) {
				return new WP_Error( 'too_big', ( 'en' === $r->lang ) ? 'File is too large (max 10MB).' : 'ファイルが大きすぎます（10MBまで）。' );
			}
			$type = wp_check_filetype( $_FILES[ $fk ]['name'] );
			if ( ! in_array( strtolower( (string) $type['ext'] ), array( 'jpg', 'jpeg', 'png', 'webp', 'pdf' ), true ) ) {
				return new WP_Error( 'bad_type', ( 'en' === $r->lang ) ? 'Unsupported file type (JPG, PNG, WEBP or PDF only).' : '対応していないファイル形式です（JPG・PNG・WEBP・PDFのみ）。' );
			}
			/* 公開領域ではなく、直接アクセスを禁止した領域に保存する */
			$new_key = BV_Files::save_upload( $_FILES[ $fk ] );
			if ( is_wp_error( $new_key ) ) {
				return new WP_Error( 'upload_failed', ( 'en' === $r->lang ) ? 'Upload failed.' : 'アップロードに失敗しました。' );
			}
			/* 差し替え前のファイルは残さない */
			if ( ! empty( $files[ $key ] ) && BV_Files::is_key( $files[ $key ] ) ) {
				BV_Files::delete( $files[ $key ] );
			}
			$files[ $key ] = $new_key;
			$changed = true;
		}

		if ( $changed ) {
			BV_DB::update_reservation( $r->id, array( 'license_files' => wp_json_encode( $files ) ) );
			/* 同一メールの進行中予約にも反映 */
			global $wpdb;
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . BV_DB::table( 'reservations' ) . " SET license_files = %s WHERE email = %s AND status IN ('pending','confirmed','in_use')",
				wp_json_encode( $files ), $r->email
			) );
			$s = BV_Util::settings();
			$subject = '【書類更新】' . BV_Util::label( BV_Util::stores(), $r->store, 'ja' ) . ' ' . $r->code . ' ' . trim( $r->sei . ' ' . $r->mei ) . '様';
			$body = "お客様が免許証等の画像を更新しました。\n\n"
				. '予約番号：' . $r->code . "\n"
				. '店舗：' . BV_Util::label( BV_Util::stores(), $r->store, 'ja' ) . "\n"
				. '氏名：' . trim( $r->sei . ' ' . $r->mei ) . "\n"
				. '貸出：' . date( 'Y-m-d H:i', strtotime( $r->pickup_dt ) ) . "\n\n"
				. '管理画面：' . admin_url( 'admin.php?page=bvrm-reservations&edit=' . $r->id );
			BV_Mailer::send_store_mail( $s['admin_email'], $subject, $body, $r->store );
		}
		return $changed;
	}

	/* ---------- 予約確認・変更・キャンセルページ ---------- */

	/**
	 * キャンセルを実行する（キャンセル料の判定・自動返金・メール送信）
	 * @param string $by 'customer' / 'admin' / 'staff'
	 */
	public static function do_cancel( $r, $lang = 'ja', $by = 'customer', $noshow = false ) {
		$s = BV_Util::settings();
		$L = function ( $ja, $en ) use ( $lang ) { return ( 'en' === $lang ) ? $en : $ja; };
		$c = BV_Util::cancel_charge( $r, $noshow );

		$memo = trim( (string) $r->admin_memo );
		$memo .= ( $memo ? "\n" : '' ) . '[キャンセル ' . current_time( 'Y-m-d H:i' ) . '] '
			. ( $noshow ? '無断キャンセル' : ( 'customer' === $by ? 'お客様による取消' : 'スタッフによる取消' ) )
			. '／' . $c['label_ja'] . '＝キャンセル料 ' . $c['pct'] . '%（' . BV_Util::money( $c['fee'] ) . '）';

		/* 自動返金（Squareでお支払い済みの場合のみ） */
		$refunded = 0;
		$refund_error = '';
		if ( $c['refund'] > 0 && ! empty( $s['cancel_auto_refund'] ) && $r->square_payment_id ) {
			$res = BV_Square::refund( $r, $c['refund'] );
			if ( is_wp_error( $res ) ) {
				$refund_error = $res->get_error_message();
				$memo .= "\n　※自動返金に失敗：" . $refund_error . '（手動で返金してください）';
			} else {
				$refunded = (int) $res;
				$memo .= "\n　返金 " . BV_Util::money( $refunded ) . ' を自動処理しました。';
			}
		} elseif ( $c['refund'] > 0 ) {
			$memo .= "\n　※返金 " . BV_Util::money( $c['refund'] ) . ' が必要です（オンライン決済以外のため手動対応）。';
		}

		BV_DB::update_reservation( $r->id, array(
			'status'     => 'cancelled',
			'admin_memo' => $memo,
		) );
		$r = BV_DB::get_reservation( $r->id );

		BV_Mailer::send_cancelled( $r, $c, $refunded, $noshow );
		BV_Mailer::send_cancelled_admin( $r, $by, $c, $refunded, $refund_error );

		/* お客様向けの完了メッセージ */
		$m = $L( 'キャンセルを承りました。確認メールをお送りしましたのでご確認ください。', 'Your reservation has been cancelled. A confirmation email has been sent to you.' );
		if ( $c['fee'] > 0 ) {
			$m .= ' ' . sprintf(
				$L( 'キャンセル料は %s（%d%%）です。', 'The cancellation fee is %s (%d%%).' ),
				BV_Util::money( $c['fee'], $lang ), $c['pct']
			);
		}
		if ( $refunded > 0 ) {
			$m .= ' ' . sprintf(
				$L( '%s を返金いたしました。カード会社の処理により、口座への反映まで数日かかる場合があります。', 'We have refunded %s. Depending on your card issuer, it may take a few days to appear on your statement.' ),
				BV_Util::money( $refunded, $lang )
			);
		} elseif ( $c['refund'] > 0 ) {
			$m .= ' ' . sprintf(
				$L( '%s の返金につきましては、担当者より別途ご案内いたします。', 'We will contact you separately regarding your refund of %s.' ),
				BV_Util::money( $c['refund'], $lang )
			);
		}
		return array( 'ok' => true, 'message' => $m, 'charge' => $c, 'refunded' => $refunded );
	}

	/* ---------- お客様ご自身での日程変更 ---------- */

	/** 自分で変更できる期限（貸出のN時間前）を過ぎていないか */
	public static function self_change_allowed( $r ) {
		$s = BV_Util::settings();
		$h = (int) $s['self_change_hours'];
		if ( $h < 1 ) return false;
		if ( in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) return false;
		return ( strtotime( $r->pickup_dt ) - current_time( 'timestamp' ) ) >= $h * HOUR_IN_SECONDS;
	}

	/**
	 * 日程変更を実行する。
	 * ・貸出のN時間前まで
	 * ・空き状況を確認し、必要なら同クラスの別車両へ振り替え
	 * ・お支払い済みの場合、料金が上がる変更は受け付けない（変更申請へ誘導）
	 */
	protected static function self_change( $r, $POST, $lang ) {
		$s = BV_Util::settings();
		$L = function ( $ja, $en ) use ( $lang ) { return ( 'en' === $lang ) ? $en : $ja; };
		$fail = function ( $m ) { return array( 'ok' => false, 'message' => $m ); };

		if ( ! self::self_change_allowed( $r ) ) {
			return $fail( $L(
				'この予約はご自身での変更期限を過ぎています。お手数ですが下の「変更申請」からご依頼ください。',
				'The self-service change period for this reservation has passed. Please use the change request form below.'
			) );
		}

		$pickup = sanitize_text_field( wp_unslash( $POST['bv_sc_pickup_date'] ?? '' ) ) . ' ' . sanitize_text_field( wp_unslash( $POST['bv_sc_pickup_time'] ?? '' ) ) . ':00';
		$return = sanitize_text_field( wp_unslash( $POST['bv_sc_return_date'] ?? '' ) ) . ' ' . sanitize_text_field( wp_unslash( $POST['bv_sc_return_time'] ?? '' ) ) . ':00';
		if ( ! strtotime( $pickup ) || ! strtotime( $return ) ) {
			return $fail( $L( '日時の指定が正しくありません。', 'The date and time you entered are not valid.' ) );
		}
		if ( $pickup === $r->pickup_dt && $return === $r->return_dt ) {
			return $fail( $L( '現在のご予約と同じ日時です。変更する日時をお選びください。', 'These are the same dates as your current reservation.' ) );
		}

		/* 予約フォームと同じ受付ルールを適用する */
		$hrs = BV_Util::store_hours( $r->store );
		if ( ! BV_Util::validate_slot( $pickup, 'pickup', $r->store ) ) {
			return $fail( sprintf( $L( '貸出時刻は営業時間内（%s〜%s）の30分単位でお選びください。', 'Pick-up must be within business hours (%s-%s) in 30-minute steps.' ), $hrs['open'], $hrs['close'] ) );
		}
		if ( ! BV_Util::validate_slot( $return, 'return', $r->store ) ) {
			return $fail( BV_Util::is_hourly_store( $r->store )
				? sprintf( $L( '返却時刻は営業時間内（%s〜%s）の30分単位でお選びください。', 'Return must be within business hours (%s-%s) in 30-minute steps.' ), $hrs['open'], $hrs['close'] )
				: $L( '返却時刻は30分単位でお選びください。', 'Return time must be in 30-minute steps.' ) );
		}
		if ( strtotime( $return ) <= strtotime( $pickup ) ) {
			return $fail( $L( '返却日時は貸出日時より後にしてください。', 'The return date and time must be after the pick-up date and time.' ) );
		}
		$now  = current_time( 'timestamp' );
		$lead = max( 0, (int) $s['lead_time_hours'] );
		if ( strtotime( $pickup ) < $now + $lead * HOUR_IN_SECONDS ) {
			return $fail( sprintf( $L( '貸出日時は現在より%d時間以降でお選びください。', 'Pick-up must be at least %d hours from now.' ), $lead ) );
		}
		$max_days = max( 1, (int) $s['max_advance_days'] );
		if ( strtotime( $pickup ) > $now + $max_days * DAY_IN_SECONDS ) {
			return $fail( sprintf( $L( 'ご予約は%d日先までとなります。', 'Reservations can be made up to %d days in advance.' ), $max_days ) );
		}

		/* 空き状況：まず今の車両で確認し、だめなら同クラスの別車両を探す */
		$require = BV_Availability::required_equipment( $r );
		$vehicle_id = (int) $r->vehicle_id;
		$keep = $vehicle_id && BV_Availability::is_vehicle_free( $vehicle_id, $pickup, $return, $r->id );
		if ( ! $keep ) {
			$alt = BV_Availability::auto_assign( $r->vehicle_class, $pickup, $return, $r->store, $r->id, $require );
			if ( ! $alt ) {
				return $fail( $L(
					'申し訳ございません。ご希望の日時は満車のため、この内容では変更できません。日時を変えてお試しいただくか、下の「変更申請」からご相談ください。',
					'We are sorry — no vehicle is available for those dates, so this change cannot be made. Please try different dates, or use the change request form below.'
				) );
			}
			$vehicle_id = $alt;
		}

		/* 料金の再計算（クラス・オプション・特別値引きはそのまま） */
		$q_args = array(
			'vehicle_class' => $r->vehicle_class, 'pickup_dt' => $pickup, 'return_dt' => $return,
			'coverage' => $r->coverage, 'shuttle' => $r->shuttle,
			'is_student' => (int) $r->is_student, 'coupon_code' => $r->coupon_code, 'lang' => $r->lang,
			'manual_discount' => (int) $r->manual_discount, 'manual_discount_note' => $r->manual_discount_note,
			'store' => $r->store,
		);
		foreach ( BV_Util::equipment_keys() as $ek ) {
			$q_args[ 'opt_' . $ek ] = isset( $r->{ 'opt_' . $ek } ) ? (int) $r->{ 'opt_' . $ek } : 0;
		}
		$quote = BV_Pricing::quote( $q_args );
		if ( is_wp_error( $quote ) ) return $fail( $quote->get_error_message() );

		$old_total = (int) $r->price_total;
		$new_total = (int) $quote['total'];

		/* お支払い済みで金額が上がる変更は、こちらで受けずに変更申請へ回す */
		if ( $r->paid_at && $new_total > $old_total ) {
			return $fail( sprintf(
				$L(
					'ご希望の日時ですと料金が %s から %s に上がるため、この画面では変更できません。お手数ですが下の「変更申請」からご依頼ください。担当者より差額のお支払い方法をご案内いたします。',
					'With those dates the price would increase from %s to %s, so the change cannot be completed here. Please use the change request form below and our staff will arrange the additional payment.'
				),
				BV_Util::money( $old_total, $lang ),
				BV_Util::money( $new_total, $lang )
			) );
		}

		$old_pickup = $r->pickup_dt;
		$old_return = $r->return_dt;

		$memo = trim( (string) $r->admin_memo );
		$memo .= ( $memo ? "\n" : '' ) . '[お客様による日程変更 ' . current_time( 'Y-m-d H:i' ) . '] '
			. date( 'Y-m-d H:i', strtotime( $old_pickup ) ) . '〜' . date( 'Y-m-d H:i', strtotime( $old_return ) )
			. ' → ' . date( 'Y-m-d H:i', strtotime( $pickup ) ) . '〜' . date( 'Y-m-d H:i', strtotime( $return ) )
			. '／料金 ' . BV_Util::money( $old_total ) . ' → ' . BV_Util::money( $new_total );

		$upd = array(
			'pickup_dt' => $pickup, 'return_dt' => $return,
			'vehicle_id' => $vehicle_id,
			'price_breakdown' => wp_json_encode( $quote ),
			'price_total' => $new_total,
			'admin_memo' => $memo,
		);

		/* 未入金で金額が変わった場合は、古い決済リンクを破棄して作り直す */
		$relink = ( ! $r->paid_at && $r->square_link && $new_total !== $old_total );
		if ( $relink ) {
			$upd['square_link'] = '';
			$upd['square_order_id'] = '';
		}
		BV_DB::update_reservation( $r->id, $upd );
		$r = BV_DB::get_reservation( $r->id );

		if ( $relink ) {
			$link = BV_Square::create_payment_link( $r );
			if ( ! is_wp_error( $link ) && ! empty( $link['url'] ) ) {
				BV_DB::update_reservation( $r->id, array( 'square_link' => $link['url'], 'square_order_id' => $link['order_id'] ) );
				$r = BV_DB::get_reservation( $r->id );
			}
		}

		/* 返金が必要な場合はスタッフが手動対応する */
		$refund_due = ( $r->paid_at && $new_total < $old_total ) ? ( $old_total - $new_total ) : 0;

		BV_Mailer::send_change_done( $r, $old_pickup, $old_return, $old_total, $refund_due );
		BV_Mailer::send_change_done_admin( $r, $old_pickup, $old_return, $old_total, $refund_due, $keep ? 0 : $vehicle_id );

		$m = $L( 'ご予約の日時を変更しました。変更後の内容をメールでお送りしましたのでご確認ください。', 'Your reservation dates have been changed. A confirmation email has been sent to you.' );
		if ( $refund_due > 0 ) {
			$m .= ' ' . sprintf(
				$L( '料金が %s お安くなりました。差額は担当者より返金のご案内をいたします。', 'The price decreased by %s. Our staff will contact you about the refund.' ),
				BV_Util::money( $refund_due, $lang )
			);
		} elseif ( ! $r->paid_at && $new_total !== $old_total ) {
			$m .= ' ' . sprintf(
				$L( 'お支払い金額が %s になりました。メールのリンクよりお支払いください。', 'The amount due is now %s. Please pay using the link in the email.' ),
				BV_Util::money( $new_total, $lang )
			);
		}
		return array( 'ok' => true, 'message' => $m );
	}

	public static function maybe_render_manage_page() {
		if ( empty( $_GET['bv_manage'] ) ) return;
		$code = sanitize_text_field( wp_unslash( $_GET['bv_manage'] ) );
		$r = BV_DB::get_reservation( $code );
		$lang = ( isset( $_GET['lang'] ) && 'en' === $_GET['lang'] ) ? 'en' : ( $r ? $r->lang : 'ja' );
		$L = function ( $ja, $en ) use ( $lang ) { return 'en' === $lang ? $en : $ja; };

		$verified = false;
		$msg = '';
		$is_err = false;
		$otp_stage = false;
		$cancel_stage = false;

		if ( $r ) {
			$posted_email = isset( $_POST['bv_verify_email'] ) ? strtolower( trim( (string) wp_unslash( $_POST['bv_verify_email'] ) ) ) : '';
			$email_ok = $posted_email && $posted_email === strtolower( $r->email );

			/* 1) パスワード認証 */
			if ( isset( $_POST['bv_auth_pass'] ) ) {
				$user = $email_ok ? get_user_by( 'email', $r->email ) : false;
				if ( $user && wp_check_password( (string) wp_unslash( $_POST['bv_auth_pass'] ), $user->user_pass, $user->ID ) ) {
					$verified = true;
				} else {
					$msg = $L( 'メールアドレスまたはパスワードが正しくありません。', 'Incorrect email address or password.' );
					$is_err = true;
				}
			}
			/* 2) 認証コード送信 */
			if ( isset( $_POST['bv_auth_otp_send'] ) ) {
				if ( $email_ok ) {
					if ( ! get_transient( 'bvrm_mng_rl_' . md5( $r->email ) ) ) {
						set_transient( 'bvrm_mng_rl_' . md5( $r->email ), 1, MINUTE_IN_SECONDS );
						$otp = BV_DB::create_otp( $r->email );
						BV_Mailer::send_otp( $r->email, $otp['code'], $lang );
					}
					$otp_stage = true;
					$msg = $L( '認証コードをメールでお送りしました。', 'A verification code has been emailed to you.' );
				} else {
					$msg = $L( 'ご予約時のメールアドレスと一致しません。', 'Email does not match the reservation.' );
					$is_err = true;
				}
			}
			/* 3) 認証コード確認 */
			if ( isset( $_POST['bv_auth_otp_code'] ) ) {
				$otp_code = preg_replace( '/\D/', '', (string) wp_unslash( $_POST['bv_auth_otp_code'] ) );
				if ( $email_ok && BV_DB::verify_otp( $r->email, $otp_code ) ) {
					$verified = true;
				} else {
					$otp_stage = true;
					$msg = $L( '認証コードが正しくないか期限切れです。', 'The code is incorrect or expired.' );
					$is_err = true;
				}
			}
			/* 4) 認証済みトークンによる操作 */
			$token = isset( $_POST['bv_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bv_token'] ) ) : '';
			if ( ! $verified && self::check_token( $r, $token ) ) {
				$verified = true;
			}

			if ( $verified && isset( $_POST['bv_action'] ) && check_admin_referer( 'bv_manage_' . $r->code ) ) {
				$s = BV_Util::settings();
				$action = sanitize_key( $_POST['bv_action'] );

				/* キャンセルの確認画面（ポリシーと金額を提示） */
				if ( 'cancel_confirm' === $action && ! in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) {
					$cancel_stage = true;
				}
				if ( 'cancel' === $action && ! in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) {
					if ( empty( $_POST['bv_agree_policy'] ) ) {
						$cancel_stage = true;
						$msg = $L( 'キャンセルポリシーへの同意にチェックを入れてください。', 'Please tick the box to confirm you agree to the cancellation policy.' );
						$is_err = true;
					} else {
						$res = self::do_cancel( $r, $lang, 'customer' );
						$r = BV_DB::get_reservation( $r->id );
						$msg = $res['message'];
						$is_err = false;
					}
				}
					/* お客様ご自身での日程変更（貸出の一定時間前まで） */
					if ( 'selfchange' === $action ) {
						$res = self::self_change( $r, $_POST, $lang );
						$r = BV_DB::get_reservation( $r->id );
						$msg = $res['message'];
						$is_err = ! $res['ok'];
					}
					if ( 'change' === $action ) {
					$req = sanitize_textarea_field( wp_unslash( $_POST['bv_change_request'] ?? '' ) );
					if ( $req ) {
						BV_DB::update_reservation( $r->id, array( 'admin_memo' => trim( $r->admin_memo . "\n[変更申請 " . current_time( 'Y-m-d H:i' ) . "] " . $req ) ) );
						$r = BV_DB::get_reservation( $r->id );
						BV_Mailer::send_change_request( $r, $req );
						$msg = $L( '変更申請を送信しました。確認メールをお送りしましたのでご確認ください。担当者の確認後、変更後の料金をご案内します（追加料金がある場合は追加請求、減額の場合は返金いたします）。', 'Your change request has been sent. A confirmation email has been sent to you. Our staff will contact you with the revised price.' );
						$is_err = false;
					}
				}
				/* 送迎リクエスト（後からの申込・変更） */
				if ( 'shuttle' === $action ) {
					$sh = sanitize_key( wp_unslash( $_POST['bv_shuttle'] ?? 'none' ) );
					$detail = sanitize_textarea_field( wp_unslash( $_POST['bv_shuttle_detail'] ?? '' ) );
					if ( ! isset( BV_Util::shuttles()[ $sh ] ) ) $sh = 'none';
					if ( 'paid' === $r->shuttle_status ) {
						$msg = $L( 'お支払い済みの送迎は、こちらから変更できません。お手数ですが店舗までご連絡ください。', 'Paid shuttle bookings cannot be changed here. Please contact the branch.' );
						$is_err = true;
					} elseif ( 'none' !== $sh && '' === $detail ) {
						$msg = $L( '送迎をご希望の場合は、送迎場所のご入力が必須です。', 'Please enter the shuttle location. It is required when requesting a shuttle.' );
						$is_err = true;
					} else {
						$upd = array( 'shuttle' => $sh, 'shuttle_detail' => $detail );
						if ( 'none' === $sh ) {
							$upd['shuttle_status'] = 'none';
							$upd['shuttle_fee'] = 0; $upd['shuttle_fee_pickup'] = 0; $upd['shuttle_fee_dropoff'] = 0;
							$upd['shuttle_link'] = '';
							$upd['shuttle_order_id'] = '';
							$msg = $L( '送迎のリクエストを取り消しました。', 'Your shuttle request has been cancelled.' );
						} else {
							$upd['shuttle_status'] = 'requested';
							$upd['shuttle_fee'] = 0; $upd['shuttle_fee_pickup'] = 0; $upd['shuttle_fee_dropoff'] = 0;
							$upd['shuttle_link'] = '';
							$upd['shuttle_order_id'] = '';
							$msg = $L(
								'送迎のリクエストを承りました。手配可否を確認のうえ、送迎料金のお支払いリンクをメールでお送りします。お支払い完了で送迎確定となります。',
								'Your shuttle request has been received. We will check availability and email you a payment link. The shuttle is confirmed once payment is completed.'
							);
						}
						BV_DB::update_reservation( $r->id, $upd );
						$r = BV_DB::get_reservation( $r->id );
						if ( 'none' !== $sh ) BV_Mailer::send_shuttle_request_admin( $r );
						$is_err = false;
					}
				}

				if ( 'profile' === $action ) {
					/* 免許証・パスポート画像の再アップロード */
					$uploaded = self::handle_license_upload( $r );
					if ( is_wp_error( $uploaded ) ) {
						$msg = ( 'en' === $lang ) ? $uploaded->get_error_message() : $uploaded->get_error_message();
						$is_err = true;
					} elseif ( $uploaded ) {
						$msg = $L( '書類画像を更新しました。', 'Your documents have been updated.' );
					}
					$user = get_user_by( 'email', $r->email );
					if ( $user ) {
						$upd = array(
							'ID'         => $user->ID,
							'last_name'  => sanitize_text_field( wp_unslash( $_POST['bv_p_sei'] ?? '' ) ),
							'first_name' => sanitize_text_field( wp_unslash( $_POST['bv_p_mei'] ?? '' ) ),
						);
						$newpass = (string) wp_unslash( $_POST['bv_p_pass'] ?? '' );
						if ( '' !== $newpass ) {
							if ( strlen( $newpass ) < 8 ) {
								$msg = $L( 'パスワードは8文字以上にしてください。', 'Password must be at least 8 characters.' );
								$is_err = true;
							} else {
								$upd['user_pass'] = $newpass;
							}
						}
						if ( ! $is_err ) {
							wp_update_user( $upd );
							update_user_meta( $user->ID, 'bv_phone', sanitize_text_field( wp_unslash( $_POST['bv_p_phone'] ?? '' ) ) );
							update_user_meta( $user->ID, 'bv_address', sanitize_textarea_field( wp_unslash( $_POST['bv_p_address'] ?? '' ) ) );
							$msg = $L( '会員情報を更新しました。', 'Your member profile has been updated.' );
						}
					}
				}
			}
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html( $L( '予約確認', 'Manage Reservation' ) ) . '</title>';
		echo '<style>body{font-family:sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222}h1{font-size:1.3em}h2{font-size:1.1em;margin-top:26px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:8px;text-align:left;font-size:.95em}th{background:#f5f5f5;width:38%}.msg{background:#e8f4e8;border:1px solid #9c9;padding:10px;border-radius:6px;margin:12px 0}.warn{background:#fdeaea;border-color:#e99}input[type=email],input[type=password],input[type=text],textarea{width:100%;padding:8px;box-sizing:border-box;margin:4px 0 10px}label{font-weight:600;font-size:.9em}button{padding:10px 18px;border:0;border-radius:6px;background:#2271b1;color:#fff;font-size:1em;cursor:pointer;margin-top:4px}button.danger{background:#b32d2e}button.ghost{background:#fff;color:#2271b1;border:1px solid #2271b1}.home{display:inline-block;margin:8px 0;padding:10px 18px;border-radius:6px;background:#646970;color:#fff;text-decoration:none}.note{font-size:.85em;color:#666}</style></head><body>';
		echo '<h1>' . esc_html( $L( '予約確認・変更・キャンセル', 'View / Change / Cancel Reservation' ) ) . '</h1>';
		/* 戻り先：予約の店舗 → URLのstoreパラメータ（予約フォームから来た場合）→ 中央サイト */
		$ref_store = isset( $_GET['store'] ) ? sanitize_key( wp_unslash( $_GET['store'] ) ) : '';
		if ( ! isset( BV_Util::stores()[ $ref_store ] ) ) $ref_store = '';
		if ( $r ) {
			$home_url = BV_Util::store_url( $r->store );
		} elseif ( $ref_store ) {
			$home_url = BV_Util::store_url( $ref_store );
		} else {
			$home_url = home_url( '/' );
		}
		echo '<a class="home" href="' . esc_url( $home_url ) . '">' . esc_html( $L( 'ホームに戻る', 'Back to Home' ) ) . '</a>';

		if ( ! $r ) {
			echo '<p class="msg warn">' . esc_html( $L( '予約が見つかりません。予約番号をご確認のうえ、もう一度お試しください。', 'Reservation not found. Please check your reservation number and try again.' ) ) . '</p>';
			echo '<form method="get" action="' . esc_url( home_url( '/' ) ) . '">';
			if ( $ref_store ) echo '<input type="hidden" name="store" value="' . esc_attr( $ref_store ) . '">';
			echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
			echo '<label>' . esc_html( $L( '予約番号', 'Reservation No.' ) ) . '</label>';
			echo '<input type="text" name="bv_manage" required placeholder="' . esc_attr( $L( '例：BV260805ABCD', 'e.g. BV260805ABCD' ) ) . '" value="' . esc_attr( $code ) . '">';
			echo '<button>' . esc_html( $L( 'もう一度照会する', 'Try again' ) ) . '</button></form>';
			echo '<p class="note">' . esc_html( $L( '予約番号は仮予約完了メールに記載されています。お分かりにならない場合は店舗までお問い合わせください。', 'Your reservation number is in the confirmation email. Please contact the branch if you cannot find it.' ) ) . '</p>';
			echo '<p><a class="home" href="' . esc_url( $home_url ) . '">' . esc_html( $L( 'ホームに戻る', 'Back to Home' ) ) . '</a></p>';
			echo '</body></html>';
			exit;
		}
		if ( $msg ) echo '<p class="msg' . ( $is_err ? ' warn' : '' ) . '">' . esc_html( $msg ) . '</p>';
		if ( isset( $_GET['paid'] ) ) echo '<p class="msg">' . esc_html( $L( 'お支払いありがとうございました。確認後、確定メールをお送りします。', 'Thank you for your payment. A confirmation email will follow.' ) ) . '</p>';
		if ( isset( $_GET['shuttle_paid'] ) ) echo '<p class="msg">' . esc_html( $L( '送迎料金のお支払いありがとうございました。確認後、送迎確定のメールをお送りします。', 'Thank you for your shuttle payment. A confirmation email will follow.' ) ) . '</p>';

		if ( ! $verified ) {
			$posted_email_attr = isset( $_POST['bv_verify_email'] ) ? esc_attr( wp_unslash( $_POST['bv_verify_email'] ) ) : '';
			if ( $otp_stage ) {
				/* 認証コード入力 */
				echo '<h2>' . esc_html( $L( '認証コードの入力', 'Enter Verification Code' ) ) . '</h2>';
				echo '<form method="post">';
				echo '<input type="hidden" name="bv_verify_email" value="' . $posted_email_attr . '">';
				echo '<label>' . esc_html( $L( '認証コード（6桁）', 'Verification code (6 digits)' ) ) . '</label>';
				echo '<input type="text" name="bv_auth_otp_code" inputmode="numeric" maxlength="6" required>';
				echo '<button>' . esc_html( $L( '認証して照会する', 'Verify & view' ) ) . '</button></form>';
			} else {
				echo '<p>' . esc_html( $L( 'ご予約時のメールアドレスと会員パスワードを入力してください。', 'Please enter your reservation email address and member password.' ) ) . '</p>';
				echo '<form method="post">';
				echo '<label>' . esc_html( $L( 'メールアドレス', 'Email address' ) ) . '</label>';
				echo '<input type="email" name="bv_verify_email" required value="' . $posted_email_attr . '" placeholder="you@example.com">';
				echo '<label>' . esc_html( $L( 'パスワード', 'Password' ) ) . '</label>';
				echo '<input type="password" name="bv_auth_pass" required>';
				echo '<button>' . esc_html( $L( '照会する', 'Look up' ) ) . '</button></form>';
				echo '<h2>' . esc_html( $L( 'パスワードをお持ちでない方・お忘れの方', "Don't have a password / forgot your password?" ) ) . '</h2>';
				echo '<p class="note">' . esc_html( $L( 'お電話でのご予約などでパスワードがない場合や、パスワードをお忘れの場合は、ご予約時のメールアドレスに認証コードをお送りします。認証後、画面下部でパスワードを再設定できます。', 'If you booked by phone and have no password, or if you have forgotten it, we can email you a verification code. After verifying, you can set a new password at the bottom of the page.' ) ) . '</p>';
				echo '<form method="post">';
				echo '<label>' . esc_html( $L( 'メールアドレス', 'Email address' ) ) . '</label>';
				echo '<input type="email" name="bv_verify_email" required placeholder="you@example.com">';
				echo '<button class="ghost" name="bv_auth_otp_send" value="1">' . esc_html( $L( '認証コードを送る', 'Send verification code' ) ) . '</button></form>';
			}
		} else {
			$token = self::token( $r );
			$statuses = BV_Util::statuses();
			echo '<table>';
			echo '<tr><th>' . esc_html( $L( '予約番号', 'Reservation No.' ) ) . '</th><td>' . esc_html( $r->code ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( 'ステータス', 'Status' ) ) . '</th><td>' . esc_html( BV_Util::label( $statuses, $r->status, $lang ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '店舗', 'Branch' ) ) . '</th><td>' . esc_html( BV_Util::label( BV_Util::stores(), $r->store, $lang ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '車両クラス', 'Vehicle class' ) ) . '</th><td>' . esc_html( BV_Util::label( BV_Util::classes(), $r->vehicle_class, $lang ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '貸出', 'Pick-up' ) ) . '</th><td>' . esc_html( date( 'Y-m-d H:i', strtotime( $r->pickup_dt ) ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '返却', 'Return' ) ) . '</th><td>' . esc_html( date( 'Y-m-d H:i', strtotime( $r->return_dt ) ) ) . '</td></tr>';
			/* 装備オプション */
			$opts = array();
			$eq = BV_Util::equipment();
			foreach ( $eq as $ek => $ev ) {
				$n = isset( $r->{ 'opt_' . $ek } ) ? (int) $r->{ 'opt_' . $ek } : 0;
				if ( $n > 0 ) $opts[] = $ev[ $lang ] . ' × ' . $n;
			}
			echo '<tr><th>' . esc_html( $L( '装備オプション', 'Equipment options' ) ) . '</th><td>' . esc_html( $opts ? implode( '　/　', $opts ) : $L( 'なし', 'None' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '追加補償', 'Coverage' ) ) . '</th><td>' . esc_html( BV_Util::label( BV_Util::coverages(), $r->coverage, $lang ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html( $L( '送迎', 'Shuttle' ) ) . '</th><td>' . esc_html( BV_Util::label( BV_Util::shuttles(), $r->shuttle, $lang ) );
			if ( 'none' !== $r->shuttle ) {
				if ( $r->shuttle_detail ) echo '<br><span class="note">' . esc_html( $r->shuttle_detail ) . '</span>';
				echo '<br><strong>' . esc_html( BV_Util::label( BV_Util::shuttle_statuses(), $r->shuttle_status ?: 'requested', $lang ) ) . '</strong>';
				if ( $r->shuttle_fee ) echo '　' . esc_html( BV_Util::shuttle_fee_text( $r, $lang ) );
				if ( 'quoted' === $r->shuttle_status && $r->shuttle_link ) {
					echo '<br><a href="' . esc_url( $r->shuttle_link ) . '"><button type="button">' . esc_html( $L( '送迎料金をお支払いへ', 'Pay shuttle fee' ) ) . '</button></a>';
				}
				if ( 'requested' === $r->shuttle_status ) {
					echo '<br><span class="note">' . esc_html( $L( '※送迎は確約ではなくリクエストです。手配可否を確認のうえ、料金のお支払いリンクをメールでお送りします。', '* The shuttle is a request, not confirmed. We will email a payment link after checking availability.' ) ) . '</span>';
				}
			}
			echo '</td></tr>';
			if ( $r->is_student ) {
				echo '<tr><th>' . esc_html( $L( '学割', 'Student discount' ) ) . '</th><td>' . esc_html( $L( '適用（貸出時に学生証をご提示ください）', 'Applied (please show your student ID at pick-up)' ) ) . '</td></tr>';
			}
			if ( $r->coupon_code ) {
				echo '<tr><th>' . esc_html( $L( 'クーポン', 'Coupon' ) ) . '</th><td>' . esc_html( $r->coupon_code ) . '</td></tr>';
			}
			if ( $r->request_note ) {
				echo '<tr><th>' . esc_html( $L( 'ご要望', 'Your requests' ) ) . '</th><td>' . nl2br( esc_html( $r->request_note ) ) . '</td></tr>';
			}

			/* 料金内訳 */
			$bd = json_decode( (string) $r->price_breakdown, true );
			if ( is_array( $bd ) && ! empty( $bd['lines'] ) ) {
				echo '<tr><th>' . esc_html( $L( '料金内訳', 'Price breakdown' ) ) . '</th><td>';
				echo '<table style="width:100%;border:0"><tbody>';
				foreach ( $bd['lines'] as $line ) {
					echo '<tr><td style="border:0;padding:2px 0;font-size:.9em">' . esc_html( $line['label'] ) . '</td>';
					echo '<td style="border:0;padding:2px 0;text-align:right;white-space:nowrap;font-size:.9em">' . esc_html( BV_Util::money( $line['amount'], $lang ) ) . '</td></tr>';
				}
				echo '</tbody></table></td></tr>';
			}
			$shuttle_amount = ( (int) $r->shuttle_fee > 0 && 'none' !== $r->shuttle ) ? (int) $r->shuttle_fee : 0;
			if ( $shuttle_amount > 0 ) {
				echo '<tr><th>' . esc_html( $L( '車両料金', 'Car rental' ) ) . '</th><td>' . esc_html( BV_Util::money( $r->price_total, $lang ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html( $L( '送迎料金', 'Shuttle fee' ) ) . '</th><td>' . esc_html( BV_Util::money( $shuttle_amount, $lang ) )
					. '<span class="note">' . esc_html( $r->shuttle_paid_at ? $L( '（お支払い済み）', ' (paid)' ) : $L( '（未払い）', ' (unpaid)' ) ) . '</span></td></tr>';
				echo '<tr><th>' . esc_html( $L( '総合計', 'Grand total' ) ) . '</th><td><strong style="font-size:1.15em">'
					. esc_html( BV_Util::money( (int) $r->price_total + $shuttle_amount, $lang ) ) . '</strong></td></tr>';
			} else {
				echo '<tr><th>' . esc_html( $L( '合計（概算）', 'Estimated total' ) ) . '</th><td><strong style="font-size:1.15em">' . esc_html( BV_Util::money( $r->price_total, $lang ) ) . '</strong></td></tr>';
			}
			echo '<tr><th>' . esc_html( $L( 'お支払い', 'Payment' ) ) . '</th><td>' . esc_html( $r->paid_at ? $L( '支払済み', 'Paid' ) . '（' . date( 'Y-m-d H:i', strtotime( $r->paid_at ) ) . '）' : $L( '未払い', 'Unpaid' ) ) . '</td></tr>';
			if ( $r->refund_amount > 0 ) {
				echo '<tr><th>' . esc_html( $L( '返金額', 'Refunded' ) ) . '</th><td>' . esc_html( BV_Util::money( $r->refund_amount, $lang ) ) . '</td></tr>';
			}
			echo '<tr><th>' . esc_html( $L( 'ご連絡先', 'Contact' ) ) . '</th><td>' . esc_html( $r->phone . ' / ' . $r->email ) . '</td></tr>';
			echo '</table>';

			if ( ! $r->paid_at && $r->square_link && 'pending' === $r->status ) {
				echo '<p><a href="' . esc_url( $r->square_link ) . '"><button type="button">' . esc_html( $L( 'お支払いへ進む', 'Proceed to payment' ) ) . '</button></a></p>';
			}

			/* 送迎リクエスト（後からの申込・変更・取消） */
			if ( ! in_array( $r->status, array( 'cancelled', 'returned' ), true ) && 'paid' !== $r->shuttle_status ) {
				echo '<h2>' . esc_html( $L( '送迎のリクエスト', 'Shuttle request' ) ) . '</h2>';
				echo '<p class="note">' . esc_html( $L(
					'送迎をご希望の場合はこちらからお申し込みください。送迎は確約ではなくリクエストです。スタッフが手配可否を確認のうえ、送迎料金（車両料金とは別）のお支払いリンクをメールでお送りします。お支払い完了で送迎確定となります。',
					'Request a shuttle here. The shuttle is a request, not a confirmed booking. Our staff will check availability and email you a payment link for the shuttle fee (separate from the car rental fee). The shuttle is confirmed once payment is completed.'
				) ) . '</p>';
				echo '<form method="post">';
				wp_nonce_field( 'bv_manage_' . $r->code );
				echo '<input type="hidden" name="bv_token" value="' . esc_attr( $token ) . '"><input type="hidden" name="bv_action" value="shuttle">';
				echo '<label>' . esc_html( $L( '送迎区分', 'Shuttle type' ) ) . '</label><select name="bv_shuttle" id="bv-sh-sel">';
				foreach ( BV_Util::shuttles() as $k => $v ) {
					echo '<option value="' . esc_attr( $k ) . '"' . selected( $r->shuttle, $k, false ) . '>' . esc_html( $v[ $lang ] ) . '</option>';
				}
				echo '</select>';
				echo '<label>' . esc_html( $L( '送迎の詳細場所（住所・施設名・人数・希望時刻など）', 'Shuttle details (address, facility, passengers, preferred time)' ) ) . '</label>';
				echo '<textarea name="bv_shuttle_detail" id="bv-sh-detail" rows="3">' . esc_textarea( $r->shuttle_detail ) . '</textarea>';
				echo '<button>' . esc_html( $L( '送迎をリクエストする', 'Request shuttle' ) ) . '</button></form>';
			}

			/* ご自身での日程変更（貸出のN時間前まで） */
			$sc_hours = (int) BV_Util::settings()['self_change_hours'];
			if ( self::self_change_allowed( $r ) ) {
				$deadline = strtotime( $r->pickup_dt ) - $sc_hours * HOUR_IN_SECONDS;
				echo '<h2>' . esc_html( $L( '日時の変更', 'Change your dates' ) ) . '</h2>';
				echo '<p class="note">' . esc_html( sprintf(
					$L(
						'貸出の%d時間前（%s まで）は、こちらから日時を変更していただけます。空き状況を確認のうえ、その場で確定します。',
						'You can change your dates here until %2$s (%1$d hours before pick-up). Availability is checked and the change is confirmed immediately.'
					),
					$sc_hours,
					date_i18n( ( 'en' === $lang ? 'M j, Y H:i' : 'Y年n月j日 H:i' ), $deadline )
				) ) . '</p>';
				if ( $r->paid_at ) {
					echo '<p class="note">' . esc_html( $L(
						'※お支払い済みのため、料金が高くなる日程には変更できません（その場合は下の「変更申請」からご依頼ください）。料金が安くなる場合は、差額を返金いたします。',
						'* As your payment is complete, you cannot change to dates that would increase the price (please use the change request form below). If the price decreases, we will refund the difference.'
					) ) . '</p>';
				}
				echo '<form method="post">';
				wp_nonce_field( 'bv_manage_' . $r->code );
				echo '<input type="hidden" name="bv_token" value="' . esc_attr( $token ) . '"><input type="hidden" name="bv_action" value="selfchange">';
				$open = BV_Util::settings()['open_time'];
				$close = BV_Util::settings()['close_time'];
				echo '<label>' . esc_html( $L( '貸出日時', 'Pick-up' ) ) . '</label>';
				echo '<div style="display:flex;gap:8px"><input type="date" name="bv_sc_pickup_date" required value="' . esc_attr( date( 'Y-m-d', strtotime( $r->pickup_dt ) ) ) . '">';
				echo '<input type="time" step="1800" name="bv_sc_pickup_time" required value="' . esc_attr( date( 'H:i', strtotime( $r->pickup_dt ) ) ) . '"></div>';
				echo '<label>' . esc_html( $L( '返却日時', 'Return' ) ) . '</label>';
				echo '<div style="display:flex;gap:8px"><input type="date" name="bv_sc_return_date" required value="' . esc_attr( date( 'Y-m-d', strtotime( $r->return_dt ) ) ) . '">';
				echo '<input type="time" step="1800" name="bv_sc_return_time" required value="' . esc_attr( date( 'H:i', strtotime( $r->return_dt ) ) ) . '"></div>';
				echo '<p class="note">' . esc_html( sprintf(
					$L( '貸出は営業時間内（%s〜%s）、30分単位でお選びください。返却は24時間承っております。車両クラス・オプションの変更をご希望の場合は、下の「変更申請」よりご依頼ください。',
						'Pick-up must be within business hours (%s-%s) in 30-minute steps. Returns are accepted 24 hours. To change the vehicle class or options, please use the change request form below.' ),
					$open, $close
				) ) . '</p>';
				echo '<button>' . esc_html( $L( 'この内容で変更する', 'Change my reservation' ) ) . '</button></form>';
			} elseif ( $sc_hours > 0 && ! in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) {
				echo '<h2>' . esc_html( $L( '日時の変更', 'Change your dates' ) ) . '</h2>';
				echo '<p class="note">' . esc_html( sprintf(
					$L( '貸出の%d時間前を過ぎたため、この画面からの変更は承れません。お手数ですが下の「変更申請」よりご依頼ください。',
						'Self-service changes are no longer available (within %d hours of pick-up). Please use the change request form below.' ),
					$sc_hours
				) ) . '</p>';
			}

			if ( ! in_array( $r->status, array( 'cancelled', 'returned' ), true ) ) {
				echo '<h2>' . esc_html( $L( '変更申請', 'Request a change' ) ) . '</h2>';
				echo '<form method="post">';
				wp_nonce_field( 'bv_manage_' . $r->code );
				echo '<input type="hidden" name="bv_token" value="' . esc_attr( $token ) . '"><input type="hidden" name="bv_action" value="change">';
				echo '<textarea name="bv_change_request" rows="3" required placeholder="' . esc_attr( $L( '変更したい内容（日時・オプション等）', 'Describe the change (dates, options, etc.)' ) ) . '"></textarea>';
				echo '<button>' . esc_html( $L( '変更を申請する', 'Send change request' ) ) . '</button></form>';

			echo '<h2>' . esc_html( $L( 'キャンセル', 'Cancel' ) ) . '</h2>';

			$cc = BV_Util::cancel_charge( $r );
			if ( ! empty( $cancel_stage ) ) {
				/* 2段階目：ポリシーと金額を提示して同意を求める */
				echo '<div class="warn" style="margin-bottom:12px">';
				echo '<p style="margin:0 0 8px;font-weight:600">' . esc_html( $L( 'キャンセル内容のご確認', 'Please confirm your cancellation' ) ) . '</p>';
				echo '<table style="width:100%;font-size:14px;border-collapse:collapse">';
				echo '<tr><td style="padding:3px 0">' . esc_html( $L( 'ご予約金額', 'Booking amount' ) ) . '</td><td style="text-align:right">' . esc_html( BV_Util::money( (int) $r->price_total, $lang ) ) . '</td></tr>';
				echo '<tr><td style="padding:3px 0">' . esc_html( $L( '適用区分', 'Applicable tier' ) ) . '</td><td style="text-align:right">' . esc_html( ( 'en' === $lang ) ? $cc['label_en'] : $cc['label_ja'] ) . '</td></tr>';
				echo '<tr><td style="padding:3px 0"><strong>' . esc_html( $L( 'キャンセル料', 'Cancellation fee' ) ) . '（' . (int) $cc['pct'] . '%）</strong></td><td style="text-align:right"><strong>' . esc_html( BV_Util::money( (int) $cc['fee'], $lang ) ) . '</strong></td></tr>';
				if ( $r->paid_at ) {
					echo '<tr><td style="padding:3px 0;border-top:1px solid #d63638">' . esc_html( $L( 'ご返金額', 'Refund' ) ) . '</td><td style="text-align:right;border-top:1px solid #d63638"><strong>' . esc_html( BV_Util::money( (int) $cc['refund'], $lang ) ) . '</strong></td></tr>';
				}
				echo '</table>';
				if ( ! $r->paid_at && $cc['fee'] > 0 ) {
					echo '<p class="note" style="margin:8px 0 0">' . esc_html( $L( '※お支払い前のため、この場でのご請求はありません。', '* As payment has not been completed, nothing will be charged now.' ) ) . '</p>';
				}
				echo '</div>';

				echo '<p style="font-weight:600;margin:0 0 4px">' . esc_html( $L( 'キャンセルポリシー', 'Cancellation policy' ) ) . '</p>';
				echo '<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;background:#f6f7f7;padding:10px;border-radius:6px;margin:0 0 12px">' . esc_html( BV_Util::cancel_policy_text( $lang ) ) . '</pre>';

				echo '<form method="post">';
				wp_nonce_field( 'bv_manage_' . $r->code );
				echo '<input type="hidden" name="bv_token" value="' . esc_attr( $token ) . '"><input type="hidden" name="bv_action" value="cancel">';
				echo '<label style="font-weight:normal;display:block;margin-bottom:10px"><input type="checkbox" name="bv_agree_policy" value="1"> '
					. esc_html( $L( 'キャンセルポリシーの内容を確認し、上記のキャンセル料に同意します。', 'I have read the cancellation policy and agree to the cancellation fee shown above.' ) ) . '</label>';
				echo '<button class="danger" onclick="return confirm(\'' . esc_js( $L( 'この内容でキャンセルします。よろしいですか？', 'Cancel this reservation with the terms shown?' ) ) . '\')">'
					. esc_html( $L( '同意してキャンセルする', 'Agree and cancel' ) ) . '</button></form>';
				echo '<p class="note" style="margin-top:8px">' . esc_html( $L( 'キャンセルをやめる場合は、このページを閉じるか再読み込みしてください。', 'To keep your reservation, simply close or reload this page.' ) ) . '</p>';
			} else {
				echo '<p class="note" style="margin:0 0 8px">' . esc_html( $L(
					'キャンセルには、貸出日までの日数に応じたキャンセル料がかかる場合があります。次の画面で金額をご確認いただけます。',
					'A cancellation fee may apply depending on how far ahead of your pick-up date you cancel. The exact amount is shown on the next screen.'
				) ) . '</p>';
				echo '<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;background:#f6f7f7;padding:10px;border-radius:6px;margin:0 0 12px">' . esc_html( BV_Util::cancel_policy_text( $lang ) ) . '</pre>';
				echo '<form method="post">';
				wp_nonce_field( 'bv_manage_' . $r->code );
				echo '<input type="hidden" name="bv_token" value="' . esc_attr( $token ) . '"><input type="hidden" name="bv_action" value="cancel_confirm">';
				echo '<button class="danger">' . esc_html( $L( 'キャンセルの手続きに進む', 'Proceed to cancellation' ) ) . '</button></form>';
			}
			}

			/* 会員情報の本人編集 */
			$user = get_user_by( 'email', $r->email );
			echo '<h2>' . esc_html( $L( '会員情報・ご提出書類の確認・変更', 'Your Profile & Documents' ) ) . '</h2>';
			echo '<form method="post" enctype="multipart/form-data">';
			wp_nonce_field( 'bv_manage_' . $r->code );
			echo '<input type="hidden" name="bv_token" value="' . esc_attr( $token ) . '"><input type="hidden" name="bv_action" value="profile">';
			if ( $user ) {
				echo '<label>' . esc_html( $L( '姓', 'Family name' ) ) . '</label><input type="text" name="bv_p_sei" value="' . esc_attr( $user->last_name ) . '">';
				echo '<label>' . esc_html( $L( '名', 'Given name' ) ) . '</label><input type="text" name="bv_p_mei" value="' . esc_attr( $user->first_name ) . '">';
				echo '<label>' . esc_html( $L( '電話番号', 'Phone' ) ) . '</label><input type="text" name="bv_p_phone" value="' . esc_attr( get_user_meta( $user->ID, 'bv_phone', true ) ) . '">';
				echo '<label>' . esc_html( $L( '住所', 'Address' ) ) . '</label><textarea name="bv_p_address" rows="2">' . esc_textarea( get_user_meta( $user->ID, 'bv_address', true ) ) . '</textarea>';
				echo '<label>' . esc_html( $L( '新しいパスワード（変更する場合のみ・8文字以上）', 'New password (only if changing, 8+ chars)' ) ) . '</label><input type="password" name="bv_p_pass" autocomplete="new-password">';
			}

			/* 提出書類の表示＋再アップロード */
			$cur_files = json_decode( (string) $r->license_files, true );
			if ( ! is_array( $cur_files ) ) $cur_files = array();
			$doc_fields = ( 'en' === $lang )
				? array( 'passport' => $L( 'パスポート（顔写真ページ）', 'Passport (photo page)' ), 'intl_license' => $L( '国際運転免許証', 'International Driving Permit' ) )
				: array( 'license_front' => $L( '運転免許証（表面）またはマイナ免許証', "Driver's license (front)" ), 'license_back' => $L( '運転免許証（裏面）', "Driver's license (back)" ) );

			echo '<h3 style="font-size:1em;margin-top:20px">' . esc_html( $L( 'ご提出書類', 'Submitted documents' ) ) . '</h3>';
			foreach ( $doc_fields as $dk => $dlabel ) {
				echo '<label>' . esc_html( $dlabel ) . '</label>';
				if ( ! empty( $cur_files[ $dk ] ) ) {
					$url = BV_Files::url( $cur_files[ $dk ], 'cust' );
					if ( BV_Files::is_image( $cur_files[ $dk ] ) ) {
						echo '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $url ) . '" style="max-height:110px;border:1px solid #ccc;border-radius:6px;display:block;margin-bottom:6px"></a>';
					} else {
						echo '<p><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $L( '提出済みファイルを開く', 'View submitted file' ) ) . '</a></p>';
					}
					echo '<p class="note">' . esc_html( $L( '変更する場合のみ、新しい画像を選択してください。', 'Select a new file only if it has changed.' ) ) . '</p>';
				} else {
					echo '<p class="note">' . esc_html( $L( '未提出です。ご登録をお願いします。', 'Not submitted yet. Please upload.' ) ) . '</p>';
				}
				echo '<input type="file" name="bv_file_' . esc_attr( $dk ) . '" accept="image/jpeg,image/png,image/webp,application/pdf">';
			}
			echo '<p class="note">' . esc_html( $L( '※JPG・PNG・WEBP・PDF、1ファイル10MBまで。', '* JPG, PNG, WEBP or PDF. Max 10MB per file.' ) ) . '</p>';
			echo '<button>' . esc_html( $L( '会員情報・書類を更新する', 'Update profile & documents' ) ) . '</button></form>';

			echo '<p><a class="home" href="' . esc_url( $home_url ) . '">' . esc_html( $L( 'ホームに戻る', 'Back to Home' ) ) . '</a></p>';
		}
		echo '</body></html>';
		exit;
	}
}
