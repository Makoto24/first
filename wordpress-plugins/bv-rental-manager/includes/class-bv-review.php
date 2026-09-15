<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 返却後のお礼＋Googleレビュー依頼と、お礼クーポンの受け取り
 *
 * 流れ：
 *   返却処理の完了 → お客様へお礼メール（店舗ごとのレビューURL＋受け取りリンク）
 *   → お客様がレビュー投稿後に受け取りリンクをクリック
 *   → 500円引きクーポンを発行し、コードをメールで送付
 *
 * Googleはレビュー投稿の有無を外部から確認する手段を提供していないため、
 * 受け取りはお客様の自己申告になる。1予約につき1枚までとし、
 * 発行状況は管理画面（予約詳細・クーポン一覧）で確認できる。
 */
class BV_Review {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'router' ) );
	}

	/* ---------- 受け取りリンク ---------- */

	protected static function token( $r ) {
		return hash_hmac( 'sha256', 'bv-review-' . $r->id . '-' . $r->code, wp_salt( 'auth' ) );
	}

	/** お礼クーポンの受け取りURL */
	public static function claim_url( $r ) {
		return add_query_arg( array(
			'bv_review' => $r->code,
			't'         => self::token( $r ),
		), home_url( '/' ) );
	}

	/* ---------- 送信条件 ---------- */

	/**
	 * この予約にレビュー依頼メールを送ってよいか
	 * @return true|string true か、送らない理由
	 */
	public static function can_send( $r ) {
		if ( ! $r ) return '予約が見つかりません。';
		$s = BV_Util::settings();
		if ( empty( $s['review_mail_enabled'] ) ) return 'レビュー依頼メールが無効になっています（設定で変更できます）。';
		if ( 'returned' !== $r->status ) return '返却処理が完了していません。';
		if ( ! is_email( $r->email ) ) return 'メールアドレスが登録されていません。';
		if ( ! BV_Util::store_review_url( $r->store ) ) {
			return $r->store . ' のレビューURLが未設定です（設定 → レビュー依頼メール）。';
		}
		if ( ! empty( $r->review_mail_at ) ) return 'すでに送信済みです（' . $r->review_mail_at . '）。';
		return true;
	}

	/**
	 * 返却処理の完了時に呼ぶ。条件を満たすときだけ送信する。
	 * @return true|string 送信したら true、送らなかった場合は理由
	 */
	public static function maybe_send( $r ) {
		$why = self::can_send( $r );
		if ( true !== $why ) return $why;
		return self::send( $r );
	}

	/** 条件を確認せずに送る（管理画面からの手動送信・再送用） */
	public static function send( $r ) {
		BV_Mailer::send_review_request( $r );
		BV_DB::update_reservation( $r->id, array( 'review_mail_at' => current_time( 'mysql' ) ) );
		return true;
	}

	/* ---------- 受け取りページ ---------- */

	public static function router() {
		if ( ! isset( $_GET['bv_review'] ) ) return;

		$code  = sanitize_text_field( wp_unslash( $_GET['bv_review'] ) );
		$token = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : '';
		$r     = BV_DB::get_reservation( $code );

		nocache_headers();
		if ( ! $r || ! hash_equals( self::token( $r ), $token ) ) {
			self::page( 'リンクが正しくありません', '<p>お手数ですが、お送りしたメールのリンクをもう一度お確かめください。</p>', 'en' === ( $r->lang ?? 'ja' ) );
			exit;
		}

		$lang = ( 'en' === $r->lang ) ? 'en' : 'ja';
		$en   = ( 'en' === $lang );

		/* すでに発行済みなら、同じコードをもう一度表示する */
		$already = ! empty( $r->review_coupon_code );

		$coupon = BV_DB::issue_review_coupon( $r );
		if ( is_wp_error( $coupon ) ) {
			self::page(
				$en ? 'Something went wrong' : 'エラーが発生しました',
				'<p>' . esc_html( $en
					? 'We could not issue your coupon. Please contact us and we will send it to you.'
					: 'クーポンを発行できませんでした。お手数ですが当店までご連絡ください。' ) . '</p>',
				$en
			);
			exit;
		}

		/* 初回だけメールを送る（再訪問では画面に表示するだけ） */
		if ( ! $already ) {
			$r = BV_DB::get_reservation( $r->id );
			BV_Mailer::send_review_coupon( $r, $coupon );
		}

		$amount  = BV_Util::money( (int) $coupon->amount, $lang );
		$expires = $coupon->expires ? date_i18n( $en ? 'M j, Y' : 'Y年n月j日', strtotime( $coupon->expires ) ) : '';

		$html  = '<p>' . esc_html( $en
			? 'Thank you very much for taking the time to review us. Here is your coupon for your next rental.'
			: 'レビューへのご協力をありがとうございました。次回ご利用いただけるクーポンをお送りします。' ) . '</p>';
		$html .= '<div class="bvr-coupon"><div class="bvr-amount">' . esc_html( $amount )
			. esc_html( $en ? ' OFF' : ' 引き' ) . '</div>'
			. '<div class="bvr-label">' . esc_html( $en ? 'Coupon code' : 'クーポンコード' ) . '</div>'
			. '<div class="bvr-code">' . esc_html( $coupon->code ) . '</div></div>';
		if ( $expires ) {
			$html .= '<p class="bvr-note">' . esc_html( ( $en ? 'Valid until ' : '有効期限：' ) . $expires )
				. '　' . esc_html( $en ? 'One use per customer.' : 'お一人様1回限り有効です。' ) . '</p>';
		}
		$html .= '<p class="bvr-note">' . esc_html( $en
			? 'We have also emailed this code to you. Enter it in the coupon field when you book.'
			: 'このコードはメールでもお送りしました。次回のご予約時、クーポンコード欄にご入力ください。' ) . '</p>';

		$home = BV_Util::store_url( $r->store );
		$html .= '<p><a class="bvr-btn" href="' . esc_url( $home ) . '">'
			. esc_html( $en ? 'Book your next rental' : '次のご予約はこちら' ) . '</a></p>';

		self::page( $en ? 'Thank you!' : 'ありがとうございました', $html, $en );
		exit;
	}

	protected static function page( $title, $html, $en = false ) {
		$company = BV_Util::settings()['company_name'] ?? '';
		?><!doctype html>
<html lang="<?php echo $en ? 'en' : 'ja'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title ); ?></title>
<style>
 body{font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Noto Sans JP",sans-serif;background:#f4f4f6;margin:0;padding:24px 16px;color:#1a1a1a;line-height:1.7}
 .box{max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px 24px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
 h1{font-size:20px;margin:0 0 16px}
 p{margin:0 0 14px;font-size:15px}
 .bvr-coupon{border:2px dashed #4e79a7;border-radius:10px;padding:18px;text-align:center;margin:20px 0;background:#f7fafd}
 .bvr-amount{font-size:26px;font-weight:700;color:#4e79a7}
 .bvr-label{font-size:12px;color:#666;margin-top:10px}
 .bvr-code{font-size:23px;font-weight:700;letter-spacing:.08em;font-family:ui-monospace,Menlo,monospace;user-select:all;margin-top:2px}
 .bvr-note{font-size:13px;color:#555}
 .bvr-btn{display:inline-block;background:#4e79a7;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:700;margin-top:6px}
 .foot{max-width:520px;margin:16px auto 0;font-size:12px;color:#888;text-align:center}
</style>
</head>
<body>
<div class="box">
	<h1><?php echo esc_html( $title ); ?></h1>
	<?php echo $html; /* 各呼び出し側でエスケープ済み */ ?>
</div>
<?php if ( $company ) : ?><p class="foot"><?php echo esc_html( $company ); ?></p><?php endif; ?>
</body>
</html>
		<?php
	}
}
