<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Square API 連携（決済リンク生成・Webhook・返金）
 * Sandbox / 本番は設定画面で切替。
 */
class BV_Square {

	protected static function base_url() {
		$s = BV_Util::settings();
		return ( 'production' === $s['square_env'] )
			? 'https://connect.squareup.com'
			: 'https://connect.squareupsandbox.com';
	}

	protected static function request( $method, $path, $body = null ) {
		$s = BV_Util::settings();
		if ( empty( $s['square_access_token'] ) ) {
			return new WP_Error( 'square_not_configured', 'Squareアクセストークンが未設定です。' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $s['square_access_token'],
				'Content-Type'   => 'application/json',
				'Square-Version' => '2025-01-23',
			),
		);
		if ( null !== $body ) $args['body'] = wp_json_encode( $body );
		$res = wp_remote_request( self::base_url() . $path, $args );
		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $json['errors'][0]['detail'] ) ? $json['errors'][0]['detail'] : 'Square API error (' . $code . ')';
			return new WP_Error( 'square_error', $msg, $json );
		}
		return $json;
	}

	/** 決済リンク生成 → array(url, order_id) or WP_Error */
	public static function create_payment_link( $r ) {
		$s = BV_Util::settings();
		$lang = $r->lang;
		$location_id = BV_Util::store_square_location( $r->store, $lang );
		if ( empty( $location_id ) ) {
			return new WP_Error( 'no_location', 'Square Location IDが未設定です（設定 → Square決済、または店舗別設定）。' );
		}
		/* 明細名に予約番号を必ず含める（Webhook照合の保険になる） */
		$name = ( 'en' === $lang )
			? sprintf( 'Car Rental %s (%s)', $r->code, BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'en' ) )
			: sprintf( 'レンタカー予約 %s（%s）', $r->code, BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'ja' ) );
		$body = array(
			'idempotency_key' => 'bvrm-' . $r->code . '-' . time(),
			'quick_pay' => array(
				'name'        => $name,
				'price_money' => array( 'amount' => (int) $r->price_total, 'currency' => 'JPY' ),
				'location_id' => $location_id,
			),
			'payment_note' => $r->code,
			'checkout_options' => array( 'redirect_url' => add_query_arg( array( 'bv_manage' => $r->code, 'paid' => 1, 'lang' => $lang, 'store' => $r->store ), home_url( '/' ) ) ),
		);
		$json = self::request( 'POST', '/v2/online-checkout/payment-links', $body );
		if ( is_wp_error( $json ) ) return $json;
		return array(
			'url'      => self::pick_url( $json ),
			'order_id' => isset( $json['payment_link']['order_id'] ) ? $json['payment_link']['order_id'] : '',
		);
	}

	/**
	 * 使用する決済URLを選ぶ。
	 * 短縮URL（square.link）が「期限切れ」と表示される場合に備え、
	 * 設定で長いURL（checkout.square.site 等）に切り替えられるようにしている。
	 */
	protected static function pick_url( $json ) {
		$s = BV_Util::settings();
		$short = isset( $json['payment_link']['url'] ) ? $json['payment_link']['url'] : '';
		$long  = isset( $json['payment_link']['long_url'] ) ? $json['payment_link']['long_url'] : '';
		if ( ! empty( $s['square_use_long_url'] ) && $long ) return $long;
		return $short ? $short : $long;
	}

	/** 送迎料金の決済リンク生成 → array(url, order_id) or WP_Error */
	public static function create_shuttle_payment_link( $r ) {
		$s = BV_Util::settings();
		$lang = $r->lang;
		$location_id = BV_Util::store_square_location( $r->store, $lang );
		if ( empty( $location_id ) ) {
			return new WP_Error( 'no_location', 'Square Location IDが未設定です。' );
		}
		if ( (int) $r->shuttle_fee < 1 ) {
			return new WP_Error( 'no_fee', '送迎料金が設定されていません。' );
		}
		$shuttles = BV_Util::shuttles();
		$name = ( 'en' === $lang )
			? sprintf( 'Shuttle service %s (%s)', $r->code, BV_Util::label( $shuttles, $r->shuttle, 'en' ) )
			: sprintf( '送迎サービス %s（%s）', $r->code, BV_Util::label( $shuttles, $r->shuttle, 'ja' ) );

		/* 往復で行き帰りの料金が違う場合は、決済画面にも内訳を出す */
		if ( 'round' === $r->shuttle ) {
			$fp = BV_Util::shuttle_fee_parts( $r );
			if ( $fp['pickup'] !== $fp['dropoff'] ) {
				$name .= ( 'en' === $lang )
					? sprintf( ' pick-up %s + drop-off %s', BV_Util::money( $fp['pickup'], 'en' ), BV_Util::money( $fp['dropoff'], 'en' ) )
					: sprintf( '　お迎え %s ＋ お送り %s', BV_Util::money( $fp['pickup'] ), BV_Util::money( $fp['dropoff'] ) );
			}
		}
		$body = array(
			'idempotency_key' => 'bvrm-sh-' . $r->code . '-' . time(),
			'quick_pay' => array(
				'name'        => $name,
				'price_money' => array( 'amount' => (int) $r->shuttle_fee, 'currency' => 'JPY' ),
				'location_id' => $location_id,
			),
			'payment_note' => $r->code . '-SHUTTLE',
			'checkout_options' => array( 'redirect_url' => add_query_arg( array( 'bv_manage' => $r->code, 'shuttle_paid' => 1, 'lang' => $lang, 'store' => $r->store ), home_url( '/' ) ) ),
		);
		$json = self::request( 'POST', '/v2/online-checkout/payment-links', $body );
		if ( is_wp_error( $json ) ) return $json;
		return array(
			'url'      => self::pick_url( $json ),
			'order_id' => isset( $json['payment_link']['order_id'] ) ? $json['payment_link']['order_id'] : '',
		);
	}

	/** Squareに登録されているWebhook購読の一覧（診断用） */
	public static function list_webhook_subscriptions() {
		$json = self::request( 'GET', '/v2/webhooks/subscriptions?include_disabled=true' );
		if ( is_wp_error( $json ) ) return $json;
		$out = array();
		foreach ( (array) ( $json['subscriptions'] ?? array() ) as $sub ) {
			$out[] = array(
				'name'    => $sub['name'] ?? '',
				'url'     => $sub['notification_url'] ?? '',
				'enabled' => ! empty( $sub['enabled'] ),
				'events'  => implode( ', ', (array) ( $sub['event_types'] ?? array() ) ),
			);
		}
		return $out;
	}

	/** Squareアカウントのロケーション一覧を取得（設定画面の確認用） */
	public static function list_locations() {
		$json = self::request( 'GET', '/v2/locations' );
		if ( is_wp_error( $json ) ) return $json;
		$out = array();
		foreach ( (array) ( $json['locations'] ?? array() ) as $loc ) {
			$out[] = array(
				'id'     => $loc['id'] ?? '',
				'name'   => $loc['name'] ?? '',
				'status' => $loc['status'] ?? '',
				'address'=> isset( $loc['address']['address_line_1'] ) ? $loc['address']['address_line_1'] : '',
			);
		}
		return $out;
	}

	/**
	 * Squareに問い合わせて支払状況を確認する（Webhookが届かない場合の手動確認用）
	 * @return string 処理結果メッセージ or WP_Error
	 */
	public static function sync_payment_status( $r, $target = 'car' ) {
		$order_id = ( 'shuttle' === $target ) ? $r->shuttle_order_id : $r->square_order_id;
		if ( empty( $order_id ) ) return new WP_Error( 'no_order', '注文IDが記録されていないため確認できません。決済リンクを再生成してください。' );

		$json = self::request( 'GET', '/v2/orders/' . rawurlencode( $order_id ) );
		if ( is_wp_error( $json ) ) return $json;
		$order = isset( $json['order'] ) ? $json['order'] : array();
		$state = $order['state'] ?? '';
		$paid  = ( 'COMPLETED' === $state );

		/* テンダー（支払）情報から決済IDを取得 */
		$payment_id = '';
		if ( ! empty( $order['tenders'][0]['payment_id'] ) ) $payment_id = $order['tenders'][0]['payment_id'];
		elseif ( ! empty( $order['tenders'][0]['id'] ) ) $payment_id = $order['tenders'][0]['id'];

		if ( ! $paid ) return 'Square上ではまだ支払いが完了していません（状態: ' . ( $state ?: '不明' ) . '）。';

		if ( 'shuttle' === $target ) {
			if ( 'paid' === $r->shuttle_status ) return 'すでに送迎確定済みです。';
			BV_DB::update_reservation( $r->id, array(
				'shuttle_status' => 'paid', 'shuttle_payment_id' => $payment_id, 'shuttle_paid_at' => current_time( 'mysql' ),
			) );
			BV_Mailer::send_shuttle_confirmed( BV_DB::get_reservation( $r->id ) );
			return '送迎の支払いを確認し、送迎確定にしました。';
		}

		if ( $r->paid_at ) return 'すでに支払済みとして記録されています。';
		BV_DB::update_reservation( $r->id, array(
			'status' => 'confirmed', 'square_payment_id' => $payment_id, 'paid_at' => current_time( 'mysql' ),
		) );
		BV_Mailer::send_paid( BV_DB::get_reservation( $r->id ) );
		return '支払いを確認し、予約を確定にしました（確定メールを送信）。';
	}

	/**
	 * 未払い予約の支払状況をまとめてSquareに問い合わせて同期する
	 * Webhookが届かない環境でも支払いを取りこぼさないための保険（cron・手動実行）
	 * @return array 結果メッセージの配列
	 */
	public static function sync_pending_payments( $limit = 40 ) {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$results = array();

		/* 車両料金が未払いで、決済リンク発行済みのもの（直近60日） */
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t}
			 WHERE paid_at IS NULL AND square_order_id != '' AND status NOT IN ('cancelled','returned')
			 AND created_at >= DATE_SUB(%s, INTERVAL 60 DAY)
			 ORDER BY id DESC LIMIT %d",
			current_time( 'mysql' ), $limit
		) );
		foreach ( $rows as $r ) {
			$res = self::sync_payment_status( $r, 'car' );
			if ( ! is_wp_error( $res ) && false !== strpos( $res, '確定' ) ) {
				$results[] = $r->code . '：' . $res;
			}
		}

		/* 送迎料金が未払いのもの */
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t}
			 WHERE shuttle_status = 'quoted' AND shuttle_order_id != ''
			 AND created_at >= DATE_SUB(%s, INTERVAL 60 DAY)
			 ORDER BY id DESC LIMIT %d",
			current_time( 'mysql' ), $limit
		) );
		foreach ( $rows as $r ) {
			$res = self::sync_payment_status( $r, 'shuttle' );
			if ( ! is_wp_error( $res ) && false !== strpos( $res, '確定' ) ) {
				$results[] = $r->code . '（送迎）：' . $res;
			}
		}

		update_option( 'bvrm_last_sync', current_time( 'mysql' ), false );
		return $results;
	}

	/** 返金 mode: full|half */
	/**
	 * 返金する。
	 * @param string|int $mode 'full' / 'half' / 金額（円）を直接指定
	 */
	public static function refund( $r, $mode = 'full' ) {
		if ( ! $r->square_payment_id ) return new WP_Error( 'no_payment', '決済IDが記録されていません（オンライン決済以外のお支払いは、店頭または銀行振込でご返金ください）。' );
		if ( is_numeric( $mode ) ) {
			$amount = (int) $mode;
		} else {
			$amount = ( 'half' === $mode ) ? (int) floor( $r->price_total / 2 ) : (int) $r->price_total;
		}
		$already = (int) $r->refund_amount;
		$max = max( 0, (int) $r->price_total - $already );
		if ( $amount > $max ) $amount = $max;
		if ( $amount < 1 ) return new WP_Error( 'no_amount', '返金できる金額がありません（すでに返金済みの可能性があります）。' );
		$json = self::request( 'POST', '/v2/refunds', array(
			'idempotency_key' => 'bvrm-refund-' . $r->code . '-' . time(),
			'payment_id'      => $r->square_payment_id,
			'amount_money'    => array( 'amount' => $amount, 'currency' => 'JPY' ),
			'reason'          => 'Reservation ' . $r->code . ' cancellation (' . $mode . ')',
		) );
		if ( is_wp_error( $json ) ) return $json;
		BV_DB::update_reservation( $r->id, array( 'refund_amount' => $already + $amount ) );
		return $amount;
	}

	/**
	 * Webhook署名検証
	 * Squareは「Square側に登録された通知URL＋本文」で署名するため、
	 * サイト側のURL表記ゆれ（末尾スラッシュ・www有無・?rest_route形式など）を
	 * 吸収するために複数の候補URLで照合する。
	 */
	public static function verify_signature( $body, $signature, $notification_url = '' ) {
		$s = BV_Util::settings();
		/* 署名キー未設定・診断モード中は「検証できなかった」として扱う。
		   以前はここで true を返していたため、偽の通知をそのまま受理していた。 */
		if ( self::skip_sig_active() ) return false;
		if ( empty( $s['square_webhook_sig_key'] ) ) return false;
		if ( empty( $signature ) ) return false;

		$key = $s['square_webhook_sig_key'];
		foreach ( self::signature_url_candidates( $notification_url ) as $url ) {
			$hash = base64_encode( hash_hmac( 'sha256', $url . $body, $key, true ) );
			if ( hash_equals( $hash, (string) $signature ) ) return true;
		}
		return false;
	}

	/**
	 * 診断用の「署名検証を一時停止」が有効か
	 * 戻し忘れを防ぐため、オンにしてから60分で自動的に失効する。
	 */
	public static function skip_sig_active() {
		$s = BV_Util::settings();
		if ( empty( $s['square_skip_sig'] ) ) return false;
		$at = (int) ( $s['square_skip_sig_at'] ?? 0 );
		if ( ! $at ) return true; /* 時刻が記録されていない旧設定 */
		return ( time() - $at ) < HOUR_IN_SECONDS;
	}

	/** 署名検証ができない状態か（キー未設定 or 診断モード中） */
	public static function signature_unavailable() {
		$s = BV_Util::settings();
		return self::skip_sig_active() || empty( $s['square_webhook_sig_key'] );
	}

	/**
	 * 署名を検証できない通知の処理
	 *
	 * 本文の内容（支払済みかどうか）は信用せず、予約の特定だけに使い、
	 * 実際の入金状況はアクセストークンを使ってSquareへ直接問い合わせて確認する。
	 * これにより、署名キーが未設定でも偽の通知で予約が確定することはない。
	 *
	 * @return string 処理結果メッセージ
	 */
	public static function handle_webhook_unverified( $payload ) {
		if ( empty( $payload['type'] ) ) return '種別なし';
		if ( ! in_array( $payload['type'], array( 'payment.updated', 'payment.created' ), true ) ) {
			return '対象外イベント（' . $payload['type'] . '）';
		}
		$payment  = isset( $payload['data']['object']['payment'] ) ? $payload['data']['object']['payment'] : null;
		$order_id = $payment['order_id'] ?? '';
		$note     = isset( $payment['note'] ) ? trim( (string) $payment['note'] ) : '';
		if ( ! $order_id && ! $note ) return '署名未検証：予約を特定できる情報がありません';

		global $wpdb;
		$t = BV_DB::table( 'reservations' );

		/* 予約の特定（order_id または 予約番号） */
		$r = null; $target = 'car';
		if ( $order_id ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE square_order_id = %s", $order_id ) );
			if ( ! $r ) {
				$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE shuttle_order_id = %s", $order_id ) );
				if ( $r ) $target = 'shuttle';
			}
		}
		if ( ! $r && $note ) {
			$is_shuttle = ( '-SHUTTLE' === substr( $note, -8 ) );
			$code = $is_shuttle ? substr( $note, 0, -8 ) : $note;
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ) );
			if ( $r && $is_shuttle ) $target = 'shuttle';
		}
		/* それでも見つからない場合は、Squareから注文を取得して予約番号を抽出する */
		if ( ! $r && $order_id ) {
			$found = self::find_code_from_order( $order_id );
			if ( $found ) {
				$is_shuttle = ( '-SHUTTLE' === substr( $found, -8 ) );
				$code = $is_shuttle ? substr( $found, 0, -8 ) : $found;
				$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ) );
				if ( $r && $is_shuttle ) $target = 'shuttle';
			}
		}
		if ( ! $r ) {
			return '署名未検証：該当予約が見つかりません（order_id=' . ( $order_id ?: '-' ) . ' / note=' . ( $note ?: '-' ) . '）';
		}

		/*
		 * 照会にはorder_idが必要。記録がない場合は通知の値を使うが、
		 * 検証前の値をDBに書き込むと（偽の通知で）正規の注文IDを埋められてしまうため、
		 * 照会用の複製にだけ載せ、Squareで入金が確認できたときにだけ保存する。
		 */
		$col   = ( 'shuttle' === $target ) ? 'shuttle_order_id' : 'square_order_id';
		$probe = $r;
		$borrowed = false;
		if ( $order_id && empty( $r->$col ) ) {
			$probe = clone $r;
			$probe->$col = $order_id;
			$borrowed = true;
		}

		/* 入金の有無はSquareに直接確認する（通知の中身は信用しない） */
		$was_paid = ( 'shuttle' === $target ) ? ( 'paid' === $r->shuttle_status ) : (bool) $r->paid_at;
		$res = self::sync_payment_status( $probe, $target );
		if ( is_wp_error( $res ) ) {
			return '署名未検証：Squareへの確認に失敗（' . $res->get_error_message() . '）';
		}

		/* 確認が取れて状態が変わったときだけ、注文IDを記録する */
		if ( $borrowed ) {
			$after = BV_DB::get_reservation( $r->id );
			$now_paid = ( 'shuttle' === $target ) ? ( 'paid' === $after->shuttle_status ) : (bool) $after->paid_at;
			if ( ! $was_paid && $now_paid ) {
				BV_DB::update_reservation( $r->id, array( $col => $order_id ) );
			}
		}
		return '署名未検証のためSquareへ直接確認 → ' . $res;
	}

	/** 署名照合に使う通知URLの候補 */
	public static function signature_url_candidates( $explicit = '' ) {
		$c = array();
		if ( $explicit ) $c[] = $explicit;

		$base = rest_url( 'bvrm/v1/square/webhook' );
		$c[] = $base;
		$c[] = untrailingslashit( $base );
		$c[] = trailingslashit( $base );

		/* 実際にリクエストされたURL（プロキシ・別ホスト名対策） */
		if ( ! empty( $_SERVER['HTTP_HOST'] ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
			$actual = $scheme . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$c[] = $actual;
			$c[] = untrailingslashit( $actual );
		}

		/* www の有無を入れ替えた候補 */
		foreach ( array_values( $c ) as $u ) {
			if ( false !== strpos( $u, '://www.' ) ) {
				$c[] = str_replace( '://www.', '://', $u );
			} else {
				$c[] = preg_replace( '#^(https?://)#', '$1www.', $u );
			}
		}
		/* http/https 入れ替え */
		foreach ( array_values( $c ) as $u ) {
			$c[] = ( 0 === strpos( $u, 'https://' ) ) ? 'http://' . substr( $u, 8 ) : 'https://' . substr( $u, 7 );
		}
		return array_values( array_unique( array_filter( $c ) ) );
	}

	/**
	 * 注文IDから予約番号を推測する（note や order_id で照合できない場合の保険）
	 * 明細名・reference_id・note に含まれる BVxxxxxxXXXX を拾う
	 */
	public static function find_code_from_order( $order_id ) {
		$json = self::request( 'GET', '/v2/orders/' . rawurlencode( $order_id ) );
		if ( is_wp_error( $json ) ) return '';
		$order = isset( $json['order'] ) ? $json['order'] : array();

		$haystack = array();
		if ( ! empty( $order['reference_id'] ) ) $haystack[] = $order['reference_id'];
		if ( ! empty( $order['note'] ) )         $haystack[] = $order['note'];
		foreach ( (array) ( $order['line_items'] ?? array() ) as $li ) {
			if ( ! empty( $li['name'] ) ) $haystack[] = $li['name'];
			if ( ! empty( $li['note'] ) ) $haystack[] = $li['note'];
		}
		foreach ( (array) ( $order['tenders'] ?? array() ) as $td ) {
			if ( ! empty( $td['note'] ) ) $haystack[] = $td['note'];
		}

		$text = implode( ' ', $haystack );
		if ( preg_match( '/(BV\d{6}[A-Z0-9]{4})(-SHUTTLE)?/i', $text, $m ) ) {
			return strtoupper( $m[1] ) . ( ! empty( $m[2] ) ? '-SHUTTLE' : '' );
		}
		return '';
	}

	/** Webhook処理：payment.updated → COMPLETED で予約確定 */
	public static function handle_webhook( $payload ) {
		if ( empty( $payload['type'] ) ) return '種別なし';
		if ( ! in_array( $payload['type'], array( 'payment.updated', 'payment.created' ), true ) ) {
			return '対象外イベント（' . $payload['type'] . '）';
		}
		$payment = isset( $payload['data']['object']['payment'] ) ? $payload['data']['object']['payment'] : null;
		if ( ! $payment ) return '決済データなし';
		if ( 'COMPLETED' !== ( $payment['status'] ?? '' ) ) {
			return '決済未完了（' . ( $payment['status'] ?? '不明' ) . '）のため処理なし';
		}

		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$note = isset( $payment['note'] ) ? trim( $payment['note'] ) : '';

		/* --- 送迎料金の決済か判定 --- */
		$shuttle_r = null;
		if ( ! empty( $payment['order_id'] ) ) {
			$shuttle_r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE shuttle_order_id = %s", $payment['order_id'] ) );
		}
		if ( ! $shuttle_r && $note && '-SHUTTLE' === substr( $note, -8 ) ) {
			$code = substr( $note, 0, -8 );
			$shuttle_r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ) );
		}
		if ( $shuttle_r ) {
			if ( 'paid' === $shuttle_r->shuttle_status ) return '送迎はすでに確定済み（' . $shuttle_r->code . '）';
			BV_DB::update_reservation( $shuttle_r->id, array(
				'shuttle_status'     => 'paid',
				'shuttle_payment_id' => $payment['id'],
				'shuttle_paid_at'    => current_time( 'mysql' ),
			) );
			$shuttle_r = BV_DB::get_reservation( $shuttle_r->id );
			BV_Mailer::send_shuttle_confirmed( $shuttle_r );
			return '送迎を確定しました（' . $shuttle_r->code . '）';
		}

		/* --- 車両料金の決済 --- */
		$r = null;
		if ( ! empty( $payment['order_id'] ) ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE square_order_id = %s", $payment['order_id'] ) );
		}
		if ( ! $r && $note ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $note ) );
		}

		/* フォールバック：Squareから注文を取得し、明細名などから予約番号を抽出して照合 */
		if ( ! $r && ! empty( $payment['order_id'] ) ) {
			$found = self::find_code_from_order( $payment['order_id'] );
			if ( $found ) {
				$is_shuttle = ( '-SHUTTLE' === substr( $found, -8 ) );
				$code = $is_shuttle ? substr( $found, 0, -8 ) : $found;
				$cand = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", $code ) );
				if ( $cand && $is_shuttle ) {
					if ( 'paid' === $cand->shuttle_status ) return '送迎はすでに確定済み（' . $cand->code . '）';
					BV_DB::update_reservation( $cand->id, array(
						'shuttle_status' => 'paid', 'shuttle_payment_id' => $payment['id'],
						'shuttle_paid_at' => current_time( 'mysql' ), 'shuttle_order_id' => $payment['order_id'],
					) );
					BV_Mailer::send_shuttle_confirmed( BV_DB::get_reservation( $cand->id ) );
					return '送迎を確定しました（' . $cand->code . '・注文明細から照合）';
				}
				if ( $cand ) {
					$r = $cand;
					/* 次回以降のためにorder_idを保存 */
					BV_DB::update_reservation( $r->id, array( 'square_order_id' => $payment['order_id'] ) );
				}
			}
		}

		if ( ! $r ) {
			return '該当予約が見つかりません（order_id=' . ( $payment['order_id'] ?? '-' ) . ' / note=' . ( $note ?: '-' ) . '）※Squareのテストイベントの場合は正常です';
		}
		if ( $r->paid_at ) return 'すでに支払済み（' . $r->code . '）';

		BV_DB::update_reservation( $r->id, array(
			'status'            => 'confirmed',
			'square_payment_id' => $payment['id'],
			'paid_at'           => current_time( 'mysql' ),
		) );
		$r = BV_DB::get_reservation( $r->id );
		BV_Mailer::send_paid( $r );
		return '予約を確定しました（' . $r->code . '）';
	}
}
