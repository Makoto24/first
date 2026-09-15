<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * メール送信（日英テンプレート、管理画面で編集可能）
 * プレースホルダー: {name} {code} {store} {class} {pickup} {return} {total}
 *                   {breakdown} {pay_link} {manage_link} {shuttle_note} {company}
 */
class BV_Mailer {

	public static function default_templates() {
		return array(
			'provisional_ja' => array(
				'subject' => '【{company}】ご予約リクエストありがとうございます（予約番号 {code}）',
				'body'    => "{name} 様\n\nこの度は、{company}へのご予約リクエストをいただき、誠にありがとうございます。\nご予約内容の確認とお支払いについてご案内いたします。\n\n────────────────────\n■ ご予約内容\n────────────────────\n予約番号：{code}\nお名前：{name} 様\n貸出日時：{pickup}\n返却日時：{return}\nご来店場所：{store_access}\n車種：{class}\n装備オプション：{equipment}\n補償オプション：{coverage}\n送迎サービス：{shuttle_text}\n\n────────────────────\n■ ご利用料金のお支払いについて\n────────────────────\n{breakdown}\n合計金額：{total}（税込）\n{pay_block}{deadline}\n{shuttle_note}\n────────────────────\n■ 当日のお持ち物・ご案内\n────────────────────\n・運転免許証（原本）を必ずご持参ください。\n・ご返却時は、ガソリン満タンにてお願いいたします。\n・全車禁煙となっております。\n\n────────────────────\n■ キャンセルポリシー\n────────────────────\n{cancel_policy}\n\nご予約内容の確認・変更・キャンセルは、以下のページからお手続きいただけます。\n{manage_link}\n\n内容にご変更やご不明な点がございましたら、お気軽にお問い合わせください。\n{name}様のご利用を心よりお待ちしております。\n\n{company}",
			),
			'provisional_en' => array(
				'subject' => '[{company}] Thank you for your reservation request (Ref: {code})',
				'body'    => "Dear {name},\n\nThank you very much for your reservation request with {company}.\nPlease find below the details of your booking and payment instructions.\n\n────────────────────\n[ Reservation Details ]\n────────────────────\nReservation No.: {code}\nName: {name}\nPick-up: {pickup}\nReturn: {return}\nPick-up location: {store_access}\nVehicle class: {class}\nEquipment options: {equipment}\nCoverage: {coverage}\nShuttle service: {shuttle_text}\n\n────────────────────\n[ Payment ]\n────────────────────\n{breakdown}\nTotal: {total} (tax incl.)\n{pay_block}{deadline}\n{shuttle_note}\n────────────────────\n[ What to bring / Notes ]\n────────────────────\n- Please bring your passport and a valid driving licence (an International Driving Permit under the Geneva Convention, or a Japanese licence).\n- Please return the vehicle with a full tank of fuel.\n- All our vehicles are strictly non-smoking.\n\n────────────────────\n[ Cancellation policy ]\n────────────────────\n{cancel_policy}\n\nYou can view, change or cancel your reservation here:\n{manage_link}\n\nIf you have any questions or need to make changes, please do not hesitate to contact us.\nWe look forward to welcoming you.\n\n{company}",
			),
			'paid_ja' => array(
				'subject' => '【{company}】ご予約が確定しました（予約番号 {code}）',
				'body'    => "{name} 様\n\nお支払いを確認いたしました。誠にありがとうございます。\nこれをもちまして、下記のとおりご予約が確定いたしました。\n\n────────────────────\n■ 確定したご予約内容\n────────────────────\n予約番号：{code}\nお名前：{name} 様\n貸出日時：{pickup}\n返却日時：{return}\nご来店場所：{store_access}\n車種：{class}\n装備オプション：{equipment}\n補償オプション：{coverage}\n送迎サービス：{shuttle_text}\nお支払い金額：{total}（税込・決済済み）\n{shuttle_note}\n────────────────────\n■ ご来店時のお願い\n────────────────────\n・運転免許証（原本）を必ずご持参ください。お忘れの場合、貸渡しができません。\n・貸出手続きに15分ほどお時間をいただきます。お時間に余裕をもってお越しください。\n・ご返却時は、ガソリン満タンにてお願いいたします。\n・当日ご到着が遅れる場合は、お手数ですがお電話にてご一報ください。\n\nご予約内容の確認は、以下のページからいつでもご覧いただけます。\n{manage_link}\n\n{name}様にお会いできますことを、スタッフ一同心よりお待ちしております。\n\n{company}",
			),
			'paid_en' => array(
				'subject' => '[{company}] Your reservation is confirmed (Ref: {code})',
				'body'    => "Dear {name},\n\nThank you very much — we have received your payment and your reservation is now confirmed.\n\n────────────────────\n[ Confirmed Reservation ]\n────────────────────\nReservation No.: {code}\nName: {name}\nPick-up: {pickup}\nReturn: {return}\nPick-up location: {store_access}\nVehicle class: {class}\nEquipment options: {equipment}\nCoverage: {coverage}\nShuttle service: {shuttle_text}\nAmount paid: {total} (tax incl.)\n{shuttle_note}\n────────────────────\n[ Before you arrive ]\n────────────────────\n- Please bring your passport and a valid driving licence (International Driving Permit under the Geneva Convention, or a Japanese licence). We cannot release the vehicle without it.\n- Please allow about 15 minutes for the rental paperwork.\n- Please return the vehicle with a full tank of fuel.\n- If you are running late on the day, please give us a call.\n\nYou can view your reservation at any time here:\n{manage_link}\n\nWe look forward to welcoming you.\n\n{company}",
			),
			'cancelled_ja' => array(
				'subject' => '【{company}】ご予約のキャンセルを承りました（予約番号 {code}）',
				'body'    => "{name} 様\n\nご予約のキャンセルを承りました。\n\n────────────────────\n■ キャンセルされたご予約\n────────────────────\n予約番号：{code}\n店舗：{store}\n車種：{class}\n貸出：{pickup}\n返却：{return}\nご予約金額：{total}\n\n────────────────────\n■ キャンセル料・ご返金について\n────────────────────\n適用区分：{cancel_tier}（キャンセル料 {cancel_pct}%）\n\n{refund_note}\n\n────────────────────\n■ キャンセルポリシー\n────────────────────\n{cancel_policy}\n\nご不明な点がございましたら、本メールにご返信ください。\nまたのご利用を心よりお待ちしております。\n\n{company}",
			),
			'cancelled_en' => array(
				'subject' => '[{company}] Reservation Cancelled (Ref: {code})',
				'body'    => "Dear {name},\n\nYour reservation has been cancelled.\n\n────────────────────\n[ Cancelled Reservation ]\n────────────────────\nReservation No.: {code}\nBranch: {store}\nVehicle class: {class}\nPick-up: {pickup}\nReturn: {return}\nBooking amount: {total}\n\n────────────────────\n[ Cancellation fee & refund ]\n────────────────────\nApplicable tier: {cancel_tier} (cancellation fee {cancel_pct}%)\n\n{refund_note}\n\n────────────────────\n[ Cancellation policy ]\n────────────────────\n{cancel_policy}\n\nIf you have any questions, please reply to this email.\nWe hope to welcome you another time.\n\n{company}",
			),
			'noshow_ja' => array(
				'subject' => '【{company}】ご予約について（予約番号 {code}）',
				'body'    => "{name} 様\n\n本日は貸出のご予定でしたが、お約束のお時間を過ぎてもご来店およびご連絡を確認できませんでした。\n誠に恐れ入りますが、下記のご予約をキャンセル扱いとさせていただきました。\n\n────────────────────\n■ 対象のご予約\n────────────────────\n予約番号：{code}\n店舗：{store}\n車種：{class}\n貸出：{pickup}\n返却：{return}\nご予約金額：{total}\n\n────────────────────\n■ キャンセル料について\n────────────────────\n適用区分：{cancel_tier}（キャンセル料 {cancel_pct}%）\n\n{refund_note}\n\n行き違いやご事情がございましたら、お手数ですが本メールにご返信いただくかお電話ください。あらためて確認いたします。\n\n{company}",
			),
			'noshow_en' => array(
				'subject' => '[{company}] About your reservation (Ref: {code})',
				'body'    => "Dear {name},\n\nYour rental was scheduled for today, but we were unable to confirm your arrival or hear from you after the agreed pick-up time.\nWe regret to inform you that the following reservation has been treated as a no-show cancellation.\n\n────────────────────\n[ Reservation ]\n────────────────────\nReservation No.: {code}\nBranch: {store}\nVehicle class: {class}\nPick-up: {pickup}\nReturn: {return}\nBooking amount: {total}\n\n────────────────────\n[ Cancellation fee ]\n────────────────────\nApplicable tier: {cancel_tier} (cancellation fee {cancel_pct}%)\n\n{refund_note}\n\nIf this is a misunderstanding or there were circumstances we should know about, please reply to this email or call us and we will look into it.\n\n{company}",
			),
			'reminder_ja' => array(
				'subject' => '【{company}】お支払いのご案内（予約番号 {code}）',
				'body'    => "{name} 様\n\n仮予約（{code}）のお支払いがまだ確認できておりません。\n以下のリンクよりお支払いをお願いいたします。\n\n{pay_link}\n\n{deadline_note}\n\n【ご予約内容】\n貸出：{pickup}\n返却：{return}\n車種：{class}\n合計：{total}\n\nすでにお支払いお済みの場合や、決済がうまくいかない場合は、お手数ですが本メールにご返信ください。お電話でのお支払いも承っております。\n\n{company}",
			),
			'reminder_en' => array(
				'subject' => '[{company}] Payment Reminder (Ref: {code})',
				'body'    => "Dear {name},\n\nWe have not yet received payment for your provisional reservation ({code}).\nPlease complete your payment via the link below.\n\n{pay_link}\n\n{deadline_note}\n\n[ Your booking ]\nPick-up: {pickup}\nReturn: {return}\nVehicle class: {class}\nTotal: {total}\n\nIf you have already paid, or if the payment does not go through, please reply to this email. We can also take payment by phone.\n\n{company}",
			),
			'otp_ja' => array(
				'subject' => '【{company}】メール認証コード',
				'body'    => "認証コード：{otp}\n\n15分以内に予約フォームへ入力してください。\n\n{company}",
			),
			'otp_en' => array(
				'subject' => '[{company}] Email Verification Code',
				'body'    => "Your verification code: {otp}\n\nPlease enter it in the booking form within 15 minutes.\n\n{company}",
			),
			'shuttle_quote_ja' => array(
				'subject' => '【{company}】送迎サービスのご案内とお支払いのお願い（予約番号 {code}）',
				'body'    => "{name} 様\n\nこの度は送迎サービスをご希望いただき、誠にありがとうございます。\nご希望の日時で送迎の手配が可能であることを確認いたしましたので、ご案内申し上げます。\n\n────────────────────\n■ 送迎の内容\n────────────────────\n予約番号：{code}\n送迎区分：{shuttle}\n送迎場所：{shuttle_detail}\n貸出日時：{pickup}\n送迎料金：{shuttle_fee}（税込）\n\n────────────────────\n■ お支払いのお願い\n────────────────────\n下記のリンクより、送迎料金のお支払いをお願いいたします。\n\n{shuttle_link}\n\n※車両レンタル料金とは別のお支払いとなります。\n※お支払いの確認をもちまして、送迎確定とさせていただきます。\n※お支払いが確認できるまでは、送迎車両の確保をお約束できかねますので、お早めのお手続きをお願いいたします。\n\nご不明な点がございましたら、お気軽にお問い合わせください。\n\n{company}",
			),
			'shuttle_quote_en' => array(
				'subject' => '[{company}] Shuttle service available – payment request (Ref: {code})',
				'body'    => "Dear {name},\n\nThank you for requesting our shuttle service.\nWe are pleased to confirm that we can arrange the shuttle for your requested date and time.\n\n────────────────────\n[ Shuttle Details ]\n────────────────────\nReservation No.: {code}\nShuttle type: {shuttle}\nLocation: {shuttle_detail}\nPick-up (car): {pickup}\nShuttle fee: {shuttle_fee} (tax incl.)\n\n────────────────────\n[ Payment ]\n────────────────────\nPlease complete the payment using the secure link below.\n\n{shuttle_link}\n\n* This is a separate payment from your car rental fee.\n* Your shuttle is confirmed only once this payment is received.\n* Until then we cannot guarantee the shuttle vehicle, so we kindly ask you to complete payment at your earliest convenience.\n\nPlease let us know if you have any questions.\n\n{company}",
			),
			'shuttle_paid_ja' => array(
				'subject' => '【{company}】送迎が確定しました（予約番号 {code}）',
				'body'    => "{name} 様\n\n送迎料金のお支払いを確認いたしました。誠にありがとうございます。\n下記のとおり、送迎が確定いたしました。\n\n────────────────────\n■ 確定した送迎内容\n────────────────────\n予約番号：{code}\n送迎区分：{shuttle}\n送迎場所：{shuttle_detail}\n送迎料金：{shuttle_fee}（税込・決済済み）\n貸出日時：{pickup}\n返却日時：{return}\nご来店場所：{store_access}\n\n────────────────────\n■ 当日のご案内\n────────────────────\n・当日は担当者がお迎えにあがります。お待ち合わせ場所でお待ちください。\n・交通状況により多少前後する場合がございます。あらかじめご了承ください。\n・当日ご到着が遅れる場合や、お待ち合わせ場所が分からない場合は、お手数ですがお電話にてご連絡ください。\n・運転免許証（原本）を必ずご持参ください。\n\nご予約内容の確認は、以下のページからご覧いただけます。\n{manage_link}\n\n{name}様にお会いできますことを、心よりお待ちしております。\n\n{company}",
			),
			'shuttle_paid_en' => array(
				'subject' => '[{company}] Your shuttle is confirmed (Ref: {code})',
				'body'    => "Dear {name},\n\nThank you — we have received your shuttle payment and your shuttle is now confirmed.\n\n────────────────────\n[ Confirmed Shuttle ]\n────────────────────\nReservation No.: {code}\nShuttle type: {shuttle}\nLocation: {shuttle_detail}\nShuttle fee: {shuttle_fee} (tax incl., paid)\nCar pick-up: {pickup}\nCar return: {return}\nBranch: {store_access}\n\n────────────────────\n[ On the day ]\n────────────────────\n- Our staff will meet you at the agreed location.\n- Please note that timing may vary slightly depending on traffic conditions.\n- If you are running late, or if you cannot find the meeting point, please call us.\n- Please remember to bring your passport and valid driving licence.\n\nYou can view your reservation here:\n{manage_link}\n\nWe look forward to meeting you.\n\n{company}",
			),
			'shuttle_declined_ja' => array(
				'subject' => '【{company}】送迎手配についてのご連絡（予約番号 {code}）',
				'body'    => "{name} 様\n\n誠に申し訳ございませんが、ご希望の日時では送迎の手配が難しい状況です。\n\n予約番号：{code}\n送迎区分：{shuttle}\n送迎場所：{shuttle_detail}\n\n車両のご予約はそのまま有効です。\n代替のご案内が可能な場合もございますので、お気軽にご相談ください。\n\n{company}",
			),
			'shuttle_declined_en' => array(
				'subject' => '[{company}] Regarding your shuttle request (Ref: {code})',
				'body'    => "Dear {name},\n\nWe are very sorry, but we are unable to arrange the shuttle service for your requested date and time.\n\nReservation No.: {code}\nShuttle type: {shuttle}\nLocation: {shuttle_detail}\n\nYour car reservation remains valid.\nPlease feel free to contact us — we may be able to suggest an alternative.\n\n{company}",
			),
			'admin_shuttle_ja' => array(
				'subject' => '【送迎リクエスト】{store} {code} {name}様 {pickup}〜',
				'body'    => "送迎のリクエストが届きました。内容を確認し、管理画面から料金を選んで承認してください。\n\n予約番号：{code}\n店舗：{store}\n送迎区分：{shuttle}\n送迎場所：{shuttle_detail}\n貸出：{pickup}\n返却：{return}\n氏名：{name}\nメール：{email}\n電話：{phone}\n\n管理画面で承認・料金設定：{admin_link}\n\n※このメールに返信すると、お客様へ直接返信できます。",
			),
			'change_customer_ja' => array(
				'subject' => '【{company}】変更申請を受け付けました（予約番号 {code}）',
				'body'    => "{name} 様\n\n以下のご予約について、変更のご希望を承りました。\n内容を確認のうえ、担当者よりご連絡いたします。\n\n【現在のご予約内容】\n予約番号：{code}\n店舗：{store}\n車両クラス：{class}\n貸出：{pickup}\n返却：{return}\n合計（現在）：{total}\n\n【ご希望の変更内容】\n{change_request}\n\n※変更はこの時点では確定していません。担当者の確認後、変更後の料金をご案内します。\n※追加料金が発生する場合は追加のお支払いを、減額となる場合は返金をご案内いたします。\n\nご予約内容の確認はこちら：\n{manage_link}\n\n{company}",
			),
			'change_customer_en' => array(
				'subject' => '[{company}] Change request received (Ref: {code})',
				'body'    => "Dear {name},\n\nWe have received your request to change the following reservation.\nOur staff will review it and contact you shortly.\n\n[Current reservation]\nReservation No.: {code}\nBranch: {store}\nVehicle class: {class}\nPick-up: {pickup}\nReturn: {return}\nCurrent total: {total}\n\n[Your requested change]\n{change_request}\n\n* This change is not confirmed yet. We will inform you of the revised price after our staff reviews it.\n* If the price increases, we will send an additional payment request; if it decreases, we will refund the difference.\n\nView your reservation:\n{manage_link}\n\n{company}",
			),
			'admin_change_ja' => array(
				'subject' => '【変更申請】{store} {code} {name}様 {pickup}〜',
				'body'    => "お客様から予約変更の申請がありました。\n\n【ご希望の変更内容】\n{change_request}\n\n【現在の予約内容】\n予約番号：{code}\n店舗：{store}\nクラス：{class}\n貸出：{pickup}\n返却：{return}\n氏名：{name}\nメール：{email}\n電話：{phone}\n合計：{total}\n{payment_status}\n\n管理画面で変更・再計算：{admin_link}\n\n※このメールに返信すると、お客様へ直接返信できます。",
			),
			'admin_cancel_ja' => array(
				'subject' => '【キャンセル】{store} {code} {name}様 {pickup}〜',
				'body'    => "お客様によりキャンセルされました。\n\n予約番号：{code}\n店舗：{store}\nクラス：{class}\n貸出：{pickup}\n返却：{return}\n氏名：{name}\nメール：{email}\n電話：{phone}\n合計：{total}\n\n{payment_status}\n\n管理画面：{admin_link}\n\n※このメールに返信すると、お客様へ直接返信できます。",
			),
			'inquiry_admin_ja' => array(
				'subject' => '【{store}】車両調整問い合わせ',
				'body'    => "車両調整の問い合わせが届きました。\n\n店舗：{store}\nクラス：{class}\n希望貸出：{pickup}\n希望返却：{return}\n氏名：{name}\nメール：{email}\n電話：{phone}\n言語：{lang}\n\n【お問い合わせ内容】\n{message}\n\n※このメールに返信すると、お客様へ直接返信できます。",
			),
			'inquiry_customer_ja' => array(
				'subject' => '【{company}】車両調整のお問い合わせを受け付けました',
				'body'    => "{name} 様\n\nお問い合わせありがとうございます。以下の内容で受け付けました。スタッフより折り返しご連絡いたします。\n\n店舗：{store}\nクラス：{class}\n希望貸出：{pickup}\n希望返却：{return}\n\nお問い合わせ内容:\n{message}\n\n{company}",
			),
			'inquiry_customer_en' => array(
				'subject' => '[{company}] We received your vehicle arrangement inquiry',
				'body'    => "Dear {name},\n\nThank you for your inquiry. We have received the following request and our staff will contact you shortly.\n\nBranch: {store}\nVehicle class: {class}\nRequested pick-up: {pickup}\nRequested return: {return}\n\nYour message:\n{message}\n\n{company}",
			),
			'pickup_week_ja' => array(
				'subject' => '【{company}】ご出発1週間前のご案内（予約番号 {code}）',
				'body'    => "{name} 様\n\nいつもお世話になっております。{company}です。\nご予約の日が近づいてまいりましたので、あらためてご案内いたします。\n\n────────────────────\n■ ご予約内容\n────────────────────\n予約番号：{code}\n貸出日時：{pickup}\n返却日時：{return}\nご来店場所：{store_access}\n車種：{class}\n装備オプション：{equipment}\n送迎：{shuttle_text}\n\n────────────────────\n■ 当日までにご確認ください\n────────────────────\n・運転免許証（原本）を必ずご持参ください。お忘れの場合、貸渡しができません。\n・冬期（12月〜3月）は路面が凍結します。冬用タイヤを装着しておりますが、運転にはくれぐれもお気をつけください。\n・チャイルドシート等の追加、日程のご変更をご希望の場合は、お早めにご連絡ください。\n\nご予約内容の確認・変更は、以下のページからお手続きいただけます。\n{manage_link}\n\n{name}様のお越しを心よりお待ちしております。\n\n{company}",
			),
			'pickup_week_en' => array(
				'subject' => '[{company}] Your rental is one week away (Ref: {code})',
				'body'    => "Dear {name},\n\nThank you for booking with {company}. Your rental date is approaching, so here is a reminder of your reservation.\n\n────────────────────\n[ Your Reservation ]\n────────────────────\nReservation No.: {code}\nPick-up: {pickup}\nReturn: {return}\nPick-up location: {store_access}\nVehicle class: {class}\nEquipment: {equipment}\nShuttle: {shuttle_text}\n\n────────────────────\n[ Before you arrive ]\n────────────────────\n- Please bring your passport and a valid driving licence (an International Driving Permit under the Geneva Convention, or a Japanese licence). We cannot release the vehicle without it.\n- In winter (December to March) roads are icy. Our vehicles have winter tyres, but please drive with care.\n- If you would like to add equipment or change your dates, please contact us as early as possible.\n\nYou can view or change your reservation here:\n{manage_link}\n\nWe look forward to welcoming you.\n\n{company}",
			),
			'pickup_day_ja' => array(
				'subject' => '【{company}】明日のご出発について（予約番号 {code}）',
				'body'    => "{name} 様\n\n明日のご来店をお待ちしております。{company}です。\n当日の流れをご案内いたします。\n\n────────────────────\n■ 明日のご予約\n────────────────────\n予約番号：{code}\n貸出日時：{pickup}\n返却日時：{return}\nご来店場所：{store_access}\n車種：{class}\n装備オプション：{equipment}\n送迎：{shuttle_text}\n\n────────────────────\n■ 当日のお持ち物\n────────────────────\n・運転免許証（原本）※お忘れの場合、貸渡しができません\n・運転される方が複数の場合は、全員分の免許証\n\n────────────────────\n■ ご来店時のお願い\n────────────────────\n・貸出手続きに15分ほどお時間をいただきます。お時間に余裕をもってお越しください。\n・ご返却はガソリン満タンでお願いいたします。\n・全車禁煙です。\n・到着が遅れそうな場合は、お手数ですがお電話にてご一報ください。\n\n{pay_block}ご予約内容の確認はこちらから。\n{manage_link}\n\n{name}様にお会いできますことを、スタッフ一同お待ちしております。\n\n{company}",
			),
			'pickup_day_en' => array(
				'subject' => '[{company}] Your rental starts tomorrow (Ref: {code})',
				'body'    => "Dear {name},\n\nWe look forward to seeing you tomorrow. Here is what you need to know for the day.\n\n────────────────────\n[ Tomorrow's Reservation ]\n────────────────────\nReservation No.: {code}\nPick-up: {pickup}\nReturn: {return}\nPick-up location: {store_access}\nVehicle class: {class}\nEquipment: {equipment}\nShuttle: {shuttle_text}\n\n────────────────────\n[ What to bring ]\n────────────────────\n- Your passport and a valid driving licence (International Driving Permit under the Geneva Convention, or a Japanese licence). We cannot release the vehicle without it.\n- If more than one person will drive, please bring the licence of every driver.\n\n────────────────────\n[ On arrival ]\n────────────────────\n- Please allow about 15 minutes for the rental paperwork.\n- Please return the vehicle with a full tank of fuel.\n- All our vehicles are strictly non-smoking.\n- If you are running late, please give us a call.\n\n{pay_block}You can view your reservation here:\n{manage_link}\n\nWe look forward to welcoming you.\n\n{company}",
			),
			'change_done_ja' => array(
				'subject' => '【{company}】ご予約の変更が完了しました（予約番号 {code}）',
				'body'    => "{name} 様\n\nいつもお世話になっております。{company}です。\n\nご予約の日時変更を承りました。変更後の内容は下記のとおりです。\n\n【変更前】\n貸出：{old_pickup}\n返却：{old_return}\n合計：{old_total}\n\n【変更後】\n予約番号：{code}\n店舗：{store_access}\n車両クラス：{class}\n貸出：{pickup}\n返却：{return}\n補償：{coverage}\n装備：{equipment}\n送迎：{shuttle_text}\n合計：{total}\n\n{price_note}\nご予約内容の確認・変更・キャンセルはこちらから承っております。\n{manage_link}\n\nご不明な点がございましたら、本メールにご返信ください。\nご来店を心よりお待ちしております。\n\n{company}",
			),
			'change_done_en' => array(
				'subject' => '[{company}] Your reservation has been updated (Ref: {code})',
				'body'    => "Dear {name},\n\nThank you for choosing {company}.\n\nYour reservation has been updated as follows.\n\n[Before]\nPick-up: {old_pickup}\nReturn: {old_return}\nTotal: {old_total}\n\n[After]\nReservation No.: {code}\nBranch: {store_access}\nVehicle class: {class}\nPick-up: {pickup}\nReturn: {return}\nCoverage: {coverage}\nEquipment: {equipment}\nShuttle: {shuttle_text}\nTotal: {total}\n\n{price_note}\nYou can view, change or cancel your reservation here:\n{manage_link}\n\nIf you have any questions, please reply to this email.\nWe look forward to welcoming you.\n\n{company}",
			),
			'admin_change_done_ja' => array(
				'subject' => '【日程変更】{store} {code} {name}様 {pickup}〜',
				'body'    => "お客様ご自身の操作で日程が変更されました。\n\n【変更前】\n貸出：{old_pickup}\n返却：{old_return}\n合計：{old_total}\n\n【変更後】\n予約番号：{code}\n店舗：{store}\nクラス：{class}\n車両：{vehicle}\n貸出：{pickup}\n返却：{return}\n合計：{total}\n\n{vehicle_note}{price_note}氏名：{name}\nメール：{email}\n電話：{phone}\n\n管理画面：{admin_link}\n\n※このメールに返信すると、お客様へ直接返信できます。",
			),
			'autocancel_ja' => array(
				'subject' => '【{company}】ご予約の受付期限が過ぎたためキャンセルとなりました（予約番号 {code}）',
				'body'    => "{name} 様\n\nこのたびは{company}にご予約のお申し込みをいただき、誠にありがとうございました。\n\n{immediate_note}\n以下のご予約につきまして、お申し込みから{deadline_text}以内にお支払いの確認ができなかったため、誠に恐れ入りますが、自動的にキャンセルとさせていただきました。\n\n予約番号：{code}\n店舗：{store}\n車両クラス：{class}\n貸出：{pickup}\n返却：{return}\n\n行き違いでお支払いをお済ませの場合や、お手続きの途中でご不明な点がございました場合は、大変お手数ですが本メールにご返信いただくかお電話にてご連絡ください。すぐに状況を確認いたします。\n\nあらためてご利用をご希望の場合は、お手数ですが下記より再度ご予約をお願いいたします。空き状況によってはご希望に添えない場合もございますので、あらかじめご了承ください。\n\nまたのご利用を心よりお待ちしております。\n\n{company}",
			),
			'autocancel_en' => array(
				'subject' => '[{company}] Your reservation has been cancelled (Ref: {code})',
				'body'    => "Dear {name},\n\nThank you very much for your reservation request with {company}.\n\nAs we were unable to confirm your payment within {deadline_text} of your request, we regret to inform you that the following reservation has been cancelled automatically.\n\nReservation No.: {code}\nBranch: {store}\nVehicle class: {class}\nPick-up: {pickup}\nReturn: {return}\n\nIf you have already completed your payment, or if you ran into any trouble during the process, please reply to this email or call us — we will look into it right away.\n\nIf you would still like to rent with us, please make a new booking. Please note that availability may have changed in the meantime.\n\nWe hope to welcome you another time.\n\n{company}",
			),
			'admin_autocancel_ja' => array(
				'subject' => '【自動キャンセル】{store} {code} {name}様 {pickup}〜',
				'body'    => "{immediate_note}支払期限（{deadline_text}）を過ぎたため、未入金の予約を自動キャンセルしました。車両の空き枠は解放されています。\n\n予約番号：{code}\n店舗：{store}\nクラス：{class}\n車両：{vehicle}\n貸出：{pickup}\n返却：{return}\n氏名：{name}\nメール：{email}\n電話：{phone}\n合計：{total}\n\nお客様にもキャンセルのご案内を送信しています（設定で無効にできます）。\n行き違いで入金があった場合は、管理画面からステータスを戻してください。\n\n管理画面：{admin_link}\n\n※このメールに返信すると、お客様へ直接返信できます。",
			),
			'admin_paid_ja' => array(
				'subject' => '【予約確定】{store} {code} {name}様 {pickup}〜',
				'body'    => "決済が完了し、予約が確定しました。\n\n予約番号：{code}\n店舗：{store}\nクラス：{class}\n車両：{vehicle}\n貸出：{pickup}\n返却：{return}\n氏名：{name}\nメール：{email}\n電話：{phone}\n補償：{coverage}\n装備：{equipment}\n送迎：{shuttle_text}\n合計：{total}\n入金日時：{paid_at}\n要望：{request}\n\n管理画面：{admin_link}\n\n※このメールに返信すると、お客様へ直接返信できます。",
			),
			'admin_new_ja' => array(
				'subject' => '【新規予約】{store} {code} {name}様 {pickup}〜',
				'body'    => "{immediate_note}新規のご予約が入りました。\n\n予約番号：{code}\n氏名：{name}\nメール：{email}\n電話：{phone}\n店舗：{store}\nクラス：{class}\n車両：{vehicle}\n貸出：{pickup}\n返却：{return}\n送迎：{shuttle}\n合計：{total}\n要望：{request}\n\n管理画面：{admin_link}",
			),
		);
	}

	public static function get_template( $key ) {
		$saved = get_option( 'bvrm_mail_templates', array() );
		$defaults = self::default_templates();
		if ( isset( $saved[ $key ]['subject'], $saved[ $key ]['body'] ) && '' !== trim( $saved[ $key ]['body'] ) ) {
			return $saved[ $key ];
		}
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : array( 'subject' => '', 'body' => '' );
	}

	protected static function render( $tpl, $vars ) {
		$search = array(); $replace = array();
		foreach ( $vars as $k => $v ) { $search[] = '{' . $k . '}'; $replace[] = $v; }
		return array(
			'subject' => str_replace( $search, $replace, $tpl['subject'] ),
			'body'    => str_replace( $search, $replace, $tpl['body'] ),
		);
	}

	/**
	 * @param string $store 店舗キー。指定すると店舗の差出人アドレスで送信
	 */
	protected static function send( $to, $subject, $body, $cc = '', $store = '', $reply_to_override = '' ) {
		$s = BV_Util::settings();
		$from = $store ? BV_Util::store_from( $store )
			: array( 'name' => $s['mail_from_name'], 'email' => $s['admin_email'], 'reply_to' => $s['admin_email'] );
		if ( $reply_to_override && is_email( $reply_to_override ) ) {
			$from['reply_to'] = $reply_to_override;
		}

		$headers = array( 'From: ' . self::encode_name( $from['name'] ) . ' <' . $from['email'] . '>' );
		if ( ! empty( $from['reply_to'] ) && $from['reply_to'] !== $from['email'] ) {
			$headers[] = 'Reply-To: ' . $from['reply_to'];
		}
		/* 管理者・スタッフ宛の通知は、店舗ごとのスタッフ通知先（From P出張所など）にもCCする */
		if ( $store && $to === $s['admin_email'] ) {
			$own = BV_Util::store_staff_cc( $store );
			if ( $own ) $cc = trim( $cc . ',' . $own, ',' );
		}
		if ( $cc ) {
			$seen = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $cc ) ) ) as $c ) {
				if ( ! is_email( $c ) || isset( $seen[ strtolower( $c ) ] ) || strtolower( $c ) === strtolower( $to ) ) continue;
				$seen[ strtolower( $c ) ] = 1;
				$headers[] = 'Cc: ' . $c;
			}
		}

		/* Fromを最優先で適用（他プラグイン/テーマの上書きを防ぐ） */
		$sender_filter = function ( $email ) use ( $from ) { return $from['email']; };
		$name_filter   = function ( $name ) use ( $from ) { return $from['name']; };
		add_filter( 'wp_mail_from', $sender_filter, PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', $name_filter, PHP_INT_MAX );

		/* PHPMailer初期化後にも強制設定（SMTPプラグイン対策） */
		$pm_filter = function ( $phpmailer ) use ( $from, $reply_to_override ) {
			try {
				$phpmailer->setFrom( $from['email'], $from['name'], false );
				$phpmailer->Sender = $from['email']; /* Return-Path */
				if ( ! empty( $from['reply_to'] ) ) {
					$phpmailer->clearReplyTos();
					$phpmailer->addReplyTo( $from['reply_to'], $reply_to_override ? '' : $from['name'] );
				}
			} catch ( Exception $e ) { /* 無視して既定値で送信 */ }
		};
		add_action( 'phpmailer_init', $pm_filter, PHP_INT_MAX );

		$result = wp_mail( $to, $subject, $body, $headers );

		remove_filter( 'wp_mail_from', $sender_filter, PHP_INT_MAX );
		remove_filter( 'wp_mail_from_name', $name_filter, PHP_INT_MAX );
		remove_action( 'phpmailer_init', $pm_filter, PHP_INT_MAX );
		return $result;
	}

	/** 文面プレビュー（サンプルデータで差し込み結果を確認） */
	public static function preview( $key, $store = '' ) {
		$s = BV_Util::settings();
		$lang = ( '_en' === substr( $key, -3 ) ) ? 'en' : 'ja';
		if ( ! $store ) {
			$sk = array_keys( BV_Util::stores() );
			$store = $sk[0];
		}
		$company = BV_Util::store_company( $store, $lang );
		$pickup = date( 'Y-m-d 11:00', strtotime( '+10 days' ) );
		$return = date( 'Y-m-d 18:00', strtotime( '+10 days' ) );
		$vars = array(
			'name' => ( 'en' === $lang ) ? 'Taro Yamada' : '山田 太郎',
			'code' => 'BV' . current_time( 'ymd' ) . 'SAMP',
			'email' => 'sample@example.com', 'phone' => '090-1234-5678',
			'store' => BV_Util::label( BV_Util::stores(), $store, $lang ),
			'store_access' => BV_Util::store_with_access( $store, $lang ),
			'class' => BV_Util::class_label_with_capacity( 'kei', $lang ),
			'vehicle' => ( 'en' === $lang ) ? 'Sample Car (松本 500 あ 12-34)' : 'サンプル車両（松本 500 あ 12-34）',
			'plate' => '松本 500 あ 12-34',
			'paid_at' => current_time( 'Y-m-d H:i' ),
			'deadline_hours' => (int) $s['autocancel_hours'],
			'old_pickup' => BV_Util::format_dt( date( 'Y-m-d 10:00', strtotime( '+7 days' ) ), $lang ),
			'old_return' => BV_Util::format_dt( date( 'Y-m-d 17:00', strtotime( '+8 days' ) ), $lang ),
			'old_total'  => BV_Util::money( 13200, $lang ),
			'price_note' => ( 'en' === $lang ) ? "Your payment has already been received; no further payment is required.\n" : "お支払いはお済みですので、追加のお手続きは必要ございません。\n",
			'vehicle_note' => '',
			'pickup' => BV_Util::format_dt( $pickup, $lang ),
			'return' => BV_Util::format_dt( $return, $lang ),
			'days' => 1,
			'coverage' => BV_Util::label( BV_Util::coverages(), 'B', $lang ),
			'equipment' => ( 'en' === $lang ) ? 'None' : 'なし',
			'shuttle_text' => ( 'en' === $lang ) ? 'Not required (you will come to our branch)' : 'なし（直接ご来店）',
			'shuttle' => BV_Util::label( BV_Util::shuttles(), 'round', $lang ),
			'shuttle_detail' => ( 'en' === $lang ) ? 'Hotel ABC lobby, 2 persons, around 10:00' : 'ホテル○○ロビー／2名／10:00頃',
			'shuttle_fee' => ( 'en' === $lang )
				? BV_Util::money( 15000, $lang ) . ' (pick-up ' . BV_Util::money( 6000, $lang ) . ' + drop-off ' . BV_Util::money( 9000, $lang ) . ')'
				: BV_Util::money( 15000 ) . '（お迎え ' . BV_Util::money( 6000 ) . ' ＋ お送り ' . BV_Util::money( 9000 ) . '）',
			'shuttle_link' => 'https://square.link/u/SAMPLE',
			'breakdown' => ( 'en' === $lang )
				? "・Green-season rate: JPY 6,600 × 1 day(s)：JPY 6,600\n・Option B (Vehicle Coverage)：JPY 3,300\n"
				: "・グリーンシーズン料金：¥6,600 × 1日：¥6,600\n・オプションB（車両補償）：¥3,300\n",
			'total' => BV_Util::money( 9900, $lang ),
			'pay_link' => 'https://square.link/u/SAMPLE',
			'pay_block' => ( 'en' === $lang )
				? "\nPlease complete your payment using the secure link below.\n\n【Payment link】\nhttps://square.link/u/SAMPLE\n\n* Your reservation is confirmed once payment is completed.\n"
				: "\n以下のオンライン決済用リンクより、お支払い手続きをお願いいたします。\n\n【お支払い用リンク】\nhttps://square.link/u/SAMPLE\n\n※決済完了をもちまして、ご予約確定となります。\n",
			'deadline' => (int) $s['pay_deadline_hours'] > 0
				? ( ( 'en' === $lang )
					? sprintf( '* If we do not receive payment or hear from you within %d hours of your request, the reservation may be released automatically.', (int) $s['pay_deadline_hours'] )
					: sprintf( '※ご予約リクエストから%d時間以内にお支払いまたはご連絡がない場合、自動キャンセルとなりますのでご注意ください。', (int) $s['pay_deadline_hours'] ) )
				: '',
			'shuttle_note' => '',
			'cancel_policy' => BV_Util::cancel_policy_text( $lang ),
			'cancel_pct'   => 30,
			'cancel_fee'   => BV_Util::money( 5940, $lang ),
			'cancel_tier'  => ( 'en' === $lang ) ? 'Up to 48 hours before pick-up' : '出発48時間前まで',
			'refund_note'  => ( 'en' === $lang )
				? "Amount paid: JPY 19,800\nCancellation fee (30%): JPY 5,940\nRefunded: JPY 13,860"
				: "お預かり金額：¥19,800\nキャンセル料（30%）：¥5,940\n返金額：¥13,860",
			'deadline_note' => ( 'en' === $lang )
				? 'Your provisional booking will be released automatically in about 12 hour(s) if payment is not received.'
				: 'お支払いが確認できない場合、あと約12時間で仮予約は自動的に解除されます。',
			'hours_left'   => 12,
			'manage_link' => home_url( '/?bv_manage=SAMPLE' ),
			'otp' => '123456',
			'message' => ( 'en' === $lang ) ? '(sample message)' : '（サンプルのお問い合わせ内容）',
			'change_request' => ( 'en' === $lang ) ? '(sample change request)' : '（サンプルの変更希望内容）',
			'payment_status' => ( 'en' === $lang ) ? '(payment status)' : '（支払状況の案内）',
			'lang' => ( 'en' === $lang ) ? '英語' : '日本語',
			'request' => '', 'admin_link' => admin_url(),
			'company' => $company, 'company_legal' => $s['company_name'],
		);
		return self::render( self::get_template( $key ), $vars );
	}

	/** テスト送信（設定画面から） */
	public static function send_test( $to, $store ) {
		$s = BV_Util::settings();
		$from = $store ? BV_Util::store_from( $store ) : array( 'name' => $s['mail_from_name'], 'email' => $s['admin_email'] );
		$company = $store ? BV_Util::store_company( $store, 'ja' ) : $s['company_name'];
		$body = "これはテスト送信です。\n\n"
			. '差出人名：' . $from['name'] . "\n"
			. '差出人アドレス：' . $from['email'] . "\n"
			. 'メール内の表示名（件名・署名）：' . $company . "\n\n"
			. "受信メールの送信元が上記アドレスになっているかご確認ください。\n"
			. "迷惑メールフォルダに入っている場合は、SPF/DKIMの設定またはSMTPプラグインの導入をご検討ください。";
		return self::send( $to, '【' . $company . '】メール送信テスト', $body, '', $store );
	}

	/** 日本語の差出人名をMIMEエンコード */
	protected static function encode_name( $name ) {
		if ( preg_match( '/[^\x20-\x7E]/', $name ) ) {
			return '=?UTF-8?B?' . base64_encode( $name ) . '?=';
		}
		return $name;
	}

	public static function reservation_vars( $r ) {
		$s = BV_Util::settings();
		$lang = $r->lang;
		/* すべてのメールで {cancel_policy} を使えるようにする */
		$policy_text = BV_Util::cancel_policy_text( $lang );
		$breakdown = '';
		$bd = json_decode( $r->price_breakdown, true );
		if ( is_array( $bd ) && ! empty( $bd['lines'] ) ) {
			foreach ( $bd['lines'] as $l ) {
				$breakdown .= '・' . $l['label'] . '：' . BV_Util::money( $l['amount'], $lang ) . "\n";
			}
		}
		$name = ( 'en' === $lang ) ? trim( $r->mei . ' ' . $r->sei ) : trim( $r->sei . ' ' . $r->mei );
		$manage = add_query_arg( array( 'bv_manage' => $r->code, 'lang' => $lang, 'store' => $r->store ), home_url( '/' ) );

		/* 割当車両（未割当のこともある） */
		$vehicle_text = ( 'en' === $lang ) ? 'To be assigned' : '未割当';
		$vehicle_plate = '';
		if ( ! empty( $r->vehicle_id ) ) {
			$v = BV_DB::get_vehicle( (int) $r->vehicle_id );
			if ( $v ) {
				$vehicle_text  = $v->name;
				$vehicle_plate = $v->plate;
				if ( $v->plate ) $vehicle_text .= '（' . $v->plate . '）';
			}
		}

		/* 装備オプションの一覧 */
		$eq = BV_Util::equipment();
		$opts = array();
		foreach ( $eq as $ek => $ev ) {
			$n = isset( $r->{ 'opt_' . $ek } ) ? (int) $r->{ 'opt_' . $ek } : 0;
			if ( $n > 0 ) $opts[] = $ev[ $lang ] . ' × ' . $n;
		}
		$equipment_text = $opts ? implode( '、', $opts ) : ( 'en' === $lang ? 'None' : 'なし' );

		/* 送迎の表示 */
		if ( 'none' === $r->shuttle ) {
			$shuttle_text = ( 'en' === $lang ) ? 'Not required (you will come to our branch)' : 'なし（直接ご来店）';
		} else {
			$shuttle_text = BV_Util::label( BV_Util::shuttles(), $r->shuttle, $lang );
			if ( $r->shuttle_detail ) $shuttle_text .= ( 'en' === $lang ) ? ' / ' . $r->shuttle_detail : '／' . $r->shuttle_detail;
		}

		/* お支払い期限の案内文 */
		$dl = (int) $s['pay_deadline_hours'];
		$deadline_text = '';
		if ( $dl > 0 ) {
			$deadline_text = ( 'en' === $lang )
				? sprintf( '* If we do not receive payment or hear from you within %d hours of your request, the reservation may be released automatically.', $dl )
				: sprintf( '※ご予約リクエストから%d時間以内にお支払いまたはご連絡がない場合、自動キャンセルとなりますのでご注意ください。', $dl );
		}

		return array(
			'name'        => $name,
			'code'        => $r->code,
			'email'       => $r->email,
			'phone'       => $r->phone,
			'lang'        => ( 'en' === $lang ) ? '英語' : '日本語',
			'cancel_policy' => $policy_text,
			'store'       => BV_Util::label( BV_Util::stores(), $r->store, $lang ),
			'store_access'=> BV_Util::store_with_access( $r->store, $lang ),
			'class'       => BV_Util::class_label_with_capacity( $r->vehicle_class, $lang ),
			'vehicle'     => $vehicle_text,
			'plate'       => $vehicle_plate,
			'pickup'      => BV_Util::format_dt( $r->pickup_dt, $lang ),
			'return'      => BV_Util::format_dt( $r->return_dt, $lang ),
			'days'        => BV_Pricing::rental_days( $r->pickup_dt, $r->return_dt ),
			'coverage'    => BV_Util::label( BV_Util::coverages(), $r->coverage, $lang ),
			'equipment'   => $equipment_text,
			'shuttle_text'=> $shuttle_text,
			'deadline'    => $deadline_text,
			'total'       => BV_Util::money( $r->price_total, $lang ),
			'breakdown'   => $breakdown,
			'pay_link'    => $r->square_link,
			'manage_link' => $manage,
			'shuttle'     => BV_Util::label( BV_Util::shuttles(), $r->shuttle, $lang ),
			'shuttle_note'=> ( 'none' !== $r->shuttle )
				? ( ( 'en' === $lang )
					? "\n[Shuttle service]\nYour shuttle request has been received. It is a request and is not yet confirmed.\nOur staff will check availability and email you a separate payment link for the shuttle fee.\nThe shuttle is confirmed once that payment is completed.\n"
					: "\n【送迎について】\n送迎のリクエストを承りました。送迎は確約ではなくリクエストとなります。\nスタッフが手配可否を確認のうえ、送迎料金のお支払いリンクを別途メールでお送りします。\nそのお支払いが完了した時点で送迎確定となります。\n" )
				: '',
			'request'     => $r->request_note,
			/* お客様向けメールでは店舗名を表示（{company_legal} で法人名） */
			'company'     => BV_Util::store_company( $r->store, $lang ),
			'company_legal' => $s['company_name'],
			'admin_link'  => admin_url( 'admin.php?page=bvrm-reservations&edit=' . $r->id ),
		);
	}

	/** 仮予約メール（顧客＋管理者） mode: auto|manual|none */
	/** 現金・振込のお客様向けのお支払い案内文 */
	public static function offline_pay_block( $r ) {
		$total = BV_Util::money( (int) $r->price_total, $r->lang );
		if ( 'cash' === $r->payment_method ) {
			return ( 'en' === $r->lang )
				? "\nPayment method: cash at the branch.\nPlease pay " . $total . " when you pick up the vehicle. No online payment is required.\n"
				: "\nお支払い方法：店頭で現金にてお支払い\n貸出当日、店頭で " . $total . " をお支払いください。オンラインでのお手続きは不要です。\n";
		}
		return ( 'en' === $r->lang )
			? "\nPayment method: bank transfer.\nWe will send you our bank details separately. Please complete the transfer before your rental date.\n"
			: "\nお支払い方法：銀行振込\n振込先は別途ご案内いたします。貸出日までにお振込みをお願いいたします。\n";
	}

	public static function send_provisional( $r, $mode = 'auto' ) {
		$s = BV_Util::settings();
		$vars = self::reservation_vars( $r );

		/*
		 * 直前予約（出発まで1週間以内）は「仮予約」ではなく、
		 * その場でお支払いいただいて確定する流れ。
		 * 仮予約メールは送らず、決済完了時の確定メールだけを送る。
		 * 管理者への新規予約通知は、枠が押さえられた事実を知る必要があるため送る。
		 */
		if ( self::is_immediate( $r ) && ! $r->paid_at ) {
			$mode = 'none';
		}

		if ( 'none' !== $mode ) {
			$lang = $r->lang;
			if ( BV_Util::is_offline_payment( $r ) ) {
				$vars['pay_block'] = self::offline_pay_block( $r );
			} elseif ( $r->square_link ) {
				$vars['pay_block'] = ( 'en' === $lang )
					? "\nPlease complete your payment using the secure link below.\n\n【Payment link】\n" . $r->square_link . "\n\n* Your reservation is confirmed once payment is completed.\n"
					: "\n以下のオンライン決済用リンクより、お支払い手続きをお願いいたします。\n\n【お支払い用リンク】\n" . $r->square_link . "\n\n※決済完了をもちまして、ご予約確定となります。\n";
			} else {
				$vars['pay_block'] = ( 'en' === $lang )
					? "\nOur staff will review your request and send you a payment link shortly.\n"
					: "\nスタッフが内容を確認のうえ、お支払い用リンクを別途お送りいたします。\n";
			}
			$m = self::render( self::get_template( 'provisional_' . $lang ), $vars );
			self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
		}
		/* 管理者通知は常に送る（差出人は予約店舗の設定、返信先はお客様） */
		$vars['pay_block'] = '';
		$vars['store'] = BV_Util::label( BV_Util::stores(), $r->store, 'ja' );
		$vars['class'] = BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'ja' );
		$vars['name']  = trim( $r->sei . ' ' . $r->mei );
		$vars['shuttle'] = BV_Util::label( BV_Util::shuttles(), $r->shuttle, 'ja' );
		$vars['lang'] = ( 'en' === $r->lang ) ? '英語' : '日本語';
		if ( self::is_immediate( $r ) && ! $r->paid_at ) {
			$dl = self::deadline_labels( $r );
			$vars['immediate_note'] = '【直前予約】出発まで1週間以内のため、お客様には仮予約メールを送らず、'
				. 'お支払い完了で確定する流れです。' . $dl['text_ja'] . '以内にお支払いがなければ自動解除されます。' . "\n\n";
		} else {
			$vars['immediate_note'] = '';
		}
		$m = self::render( self::get_template( 'admin_new_ja' ), $vars );
		$to = $s['admin_email'];
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		self::send( $to, $m['subject'], $m['body'], $cc, $r->store, $r->email );
	}

	public static function send_paid( $r ) {
		$s = BV_Util::settings();
		$vars = self::reservation_vars( $r );
		$m = self::render( self::get_template( 'paid_' . $r->lang ), $vars );
		self::send( $r->email, $m['subject'], $m['body'], '', $r->store );

		/* 管理者・スタッフへの確定通知（返信先＝お客様） */
		$av = $vars;
		$av['store']    = BV_Util::label( BV_Util::stores(), $r->store, 'ja' );
		$av['class']    = BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'ja' );
		$av['name']     = trim( $r->sei . ' ' . $r->mei );
		$av['pickup']   = BV_Util::format_dt( $r->pickup_dt, 'ja' );
		$av['return']   = BV_Util::format_dt( $r->return_dt, 'ja' );
		$av['coverage'] = BV_Util::label( BV_Util::coverages(), $r->coverage, 'ja' );
		$av['shuttle_text'] = ( 'none' === $r->shuttle )
			? 'なし'
			: BV_Util::label( BV_Util::shuttles(), $r->shuttle, 'ja' ) . ( $r->shuttle_detail ? '／' . $r->shuttle_detail : '' );
		$av['total']    = BV_Util::money( (int) $r->price_total );
		$av['paid_at']  = $r->paid_at ? date( 'Y-m-d H:i', strtotime( $r->paid_at ) ) : current_time( 'Y-m-d H:i' );
		$av['request']  = $r->request_note ? $r->request_note : 'なし';

		$m2 = self::render( self::get_template( 'admin_paid_ja' ), $av );
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		self::send( $s['admin_email'], $m2['subject'], $m2['body'], $cc, $r->store, $r->email );
	}

	public static function send_cancelled( $r, $charge = null, $refunded = 0, $noshow = false ) {
		$lang = $r->lang;
		$vars = self::reservation_vars( $r );
		if ( null === $charge ) $charge = BV_Util::cancel_charge( $r, $noshow );

		$vars['cancel_policy'] = BV_Util::cancel_policy_text( $lang );
		$vars['cancel_pct']    = (int) $charge['pct'];
		$vars['cancel_fee']    = BV_Util::money( (int) $charge['fee'], $lang );
		$vars['cancel_tier']   = ( 'en' === $lang ) ? $charge['label_en'] : $charge['label_ja'];

		/* お支払い・返金の状況をまとめた説明文 */
		if ( ! $r->paid_at ) {
			$vars['refund_note'] = ( 'en' === $lang )
				? ( $charge['fee'] > 0
					? "As payment had not been completed, no refund applies. A cancellation fee of " . $vars['cancel_fee'] . " may be invoiced separately."
					: "As payment had not been completed, no charge applies." )
				: ( $charge['fee'] > 0
					? 'お支払い前のため返金はございません。キャンセル料 ' . $vars['cancel_fee'] . ' につきましては、別途ご案内する場合がございます。'
					: 'お支払い前のため、ご請求は発生いたしません。' );
		} elseif ( $refunded > 0 ) {
			$vars['refund_note'] = ( 'en' === $lang )
				? "Amount paid: " . BV_Util::money( (int) $charge['paid'], $lang ) . "\nCancellation fee (" . $charge['pct'] . "%): " . $vars['cancel_fee'] . "\nRefunded: " . BV_Util::money( $refunded, $lang ) . "\n\nThe refund has been processed. Depending on your card issuer, it may take a few days to appear on your statement."
				: 'お預かり金額：' . BV_Util::money( (int) $charge['paid'] ) . "\nキャンセル料（" . $charge['pct'] . "%）：" . $vars['cancel_fee'] . "\n返金額：" . BV_Util::money( $refunded ) . "\n\n返金の手続きは完了しております。カード会社の処理により、ご利用口座への反映まで数日かかる場合がございます。";
		} elseif ( $charge['refund'] > 0 ) {
			$vars['refund_note'] = ( 'en' === $lang )
				? "Amount paid: " . BV_Util::money( (int) $charge['paid'], $lang ) . "\nCancellation fee (" . $charge['pct'] . "%): " . $vars['cancel_fee'] . "\nTo be refunded: " . BV_Util::money( (int) $charge['refund'], $lang ) . "\n\nWe will contact you separately to arrange the refund."
				: 'お預かり金額：' . BV_Util::money( (int) $charge['paid'] ) . "\nキャンセル料（" . $charge['pct'] . "%）：" . $vars['cancel_fee'] . "\n返金予定額：" . BV_Util::money( (int) $charge['refund'] ) . "\n\n返金の方法につきましては、担当者より別途ご案内いたします。";
		} else {
			$vars['refund_note'] = ( 'en' === $lang )
				? "Amount paid: " . BV_Util::money( (int) $charge['paid'], $lang ) . "\nCancellation fee (" . $charge['pct'] . "%): " . $vars['cancel_fee'] . "\n\nAs the cancellation fee covers the full amount paid, there is no refund."
				: 'お預かり金額：' . BV_Util::money( (int) $charge['paid'] ) . "\nキャンセル料（" . $charge['pct'] . "%）：" . $vars['cancel_fee'] . "\n\nキャンセル料が全額に相当するため、ご返金はございません。";
		}

		$key = $noshow ? ( 'noshow_' . $lang ) : ( 'cancelled_' . $lang );
		$tpl = self::get_template( $key );
		if ( empty( $tpl['body'] ) ) $tpl = self::get_template( 'cancelled_' . $lang );
		$m = self::render( $tpl, $vars );
		self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
	}

	/* ---------- 送迎リクエスト関連 ---------- */

	protected static function shuttle_vars( $r ) {
		$vars = self::reservation_vars( $r );
		$vars['shuttle_detail'] = $r->shuttle_detail ? $r->shuttle_detail : '—';
		$vars['shuttle_fee']    = BV_Util::shuttle_fee_text( $r, $r->lang );
		$vars['shuttle_link']   = $r->shuttle_link;
		return $vars;
	}

	/** 送迎リクエスト受付 → 管理者通知（返信先＝お客様） */
	public static function send_shuttle_request_admin( $r ) {
		$s = BV_Util::settings();
		$vars = self::shuttle_vars( $r );
		$vars['store'] = BV_Util::label( BV_Util::stores(), $r->store, 'ja' );
		$vars['shuttle'] = BV_Util::label( BV_Util::shuttles(), $r->shuttle, 'ja' );
		$vars['name'] = trim( $r->sei . ' ' . $r->mei );
		$m = self::render( self::get_template( 'admin_shuttle_ja' ), $vars );
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		return self::send( $s['admin_email'], $m['subject'], $m['body'], $cc, $r->store, $r->email );
	}

	/** 送迎承認 → お客様へ決済リンク */
	public static function send_shuttle_quote( $r ) {
		$vars = self::shuttle_vars( $r );
		$key = ( 'en' === $r->lang ) ? 'shuttle_quote_en' : 'shuttle_quote_ja';
		$m = self::render( self::get_template( $key ), $vars );
		return self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
	}

	/** 送迎決済完了 → 送迎確定 */
	public static function send_shuttle_confirmed( $r ) {
		$s = BV_Util::settings();
		$vars = self::shuttle_vars( $r );
		$key = ( 'en' === $r->lang ) ? 'shuttle_paid_en' : 'shuttle_paid_ja';
		$m = self::render( self::get_template( $key ), $vars );
		self::send( $r->email, $m['subject'], $m['body'], '', $r->store );

		/* 管理者にも確定を通知 */
		$subject = '【送迎確定】' . BV_Util::label( BV_Util::stores(), $r->store, 'ja' ) . ' ' . $r->code . ' ' . trim( $r->sei . ' ' . $r->mei ) . '様';
		$body = "送迎料金のお支払いが完了し、送迎が確定しました。\n\n"
			. '予約番号：' . $r->code . "\n"
			. '送迎区分：' . BV_Util::label( BV_Util::shuttles(), $r->shuttle, 'ja' ) . "\n"
			. '送迎場所：' . $r->shuttle_detail . "\n"
			. '送迎料金：' . BV_Util::shuttle_fee_text( $r ) . "\n"
			. '貸出：' . date( 'Y-m-d H:i', strtotime( $r->pickup_dt ) ) . "\n\n"
			. admin_url( 'admin.php?page=bvrm-reservations&edit=' . $r->id );
		return self::send( $s['admin_email'], $subject, $body, trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' ), $r->store );
	}

	/** 送迎不可の連絡 */
	public static function send_shuttle_declined( $r ) {
		$vars = self::shuttle_vars( $r );
		$key = ( 'en' === $r->lang ) ? 'shuttle_declined_en' : 'shuttle_declined_ja';
		$m = self::render( self::get_template( $key ), $vars );
		return self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
	}

	/**
	 * 変更申請メール（お客様への受付確認＋管理者・スタッフ通知）
	 * 管理者宛は Reply-To をお客様のアドレスにする。
	 */
	public static function send_change_request( $r, $request ) {
		$s = BV_Util::settings();

		/* お客様への受付確認 */
		$vars = self::reservation_vars( $r );
		$vars['change_request'] = $request;
		$key = ( 'en' === $r->lang ) ? 'change_customer_en' : 'change_customer_ja';
		$m = self::render( self::get_template( $key ), $vars );
		self::send( $r->email, $m['subject'], $m['body'], '', $r->store );

		/* 管理者・スタッフ通知（返信先＝お客様） */
		$av = $vars;
		$av['store'] = BV_Util::label( BV_Util::stores(), $r->store, 'ja' );
		$av['class'] = BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'ja' );
		$av['name']  = trim( $r->sei . ' ' . $r->mei );
		$av['payment_status'] = $r->paid_at
			? '支払状況：支払済み（' . date( 'Y-m-d H:i', strtotime( $r->paid_at ) ) . '）　※変更後に差額が生じる場合は追加請求または返金が必要です。'
			: '支払状況：未入金　※変更後の金額で決済リンクを送り直せます。';
		$m2 = self::render( self::get_template( 'admin_change_ja' ), $av );
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		self::send( $s['admin_email'], $m2['subject'], $m2['body'], $cc, $r->store, $r->email );

		return true;
	}

	/** キャンセルの管理者・スタッフ通知（返信先＝お客様） */
	public static function send_cancelled_admin( $r, $by = 'customer', $charge = null, $refunded = 0, $refund_error = '' ) {
		$s = BV_Util::settings();
		$vars = self::reservation_vars( $r );
		$vars['store'] = BV_Util::label( BV_Util::stores(), $r->store, 'ja' );
		$vars['class'] = BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'ja' );
		$vars['name']  = trim( $r->sei . ' ' . $r->mei );

		if ( $charge ) {
			$lines = array(
				'キャンセル区分：' . $charge['label_ja'] . '（キャンセル料 ' . $charge['pct'] . '%）',
				'ご請求：' . BV_Util::money( (int) $charge['fee'] ),
			);
			if ( ! $r->paid_at ) {
				$lines[] = '未入金のため返金はありません。';
			} elseif ( $refund_error ) {
				$lines[] = '【要対応】自動返金に失敗しました：' . $refund_error;
				$lines[] = '返金予定額 ' . BV_Util::money( (int) $charge['refund'] ) . ' を管理画面から手動で返金してください。';
			} elseif ( $refunded > 0 ) {
				$lines[] = '返金 ' . BV_Util::money( $refunded ) . ' を自動処理しました。';
			} elseif ( $charge['refund'] > 0 ) {
				$lines[] = '【要対応】返金 ' . BV_Util::money( (int) $charge['refund'] ) . ' が必要です（オンライン決済以外のため手動対応）。';
			} else {
				$lines[] = 'キャンセル料が全額に相当するため、返金はありません。';
			}
			$vars['payment_status'] = implode( "\n", $lines );
		} elseif ( $r->paid_at ) {
			$vars['payment_status'] = '【要対応】支払済みの予約です（' . date( 'Y-m-d H:i', strtotime( $r->paid_at ) ) . ' 決済）。'
				. "\n返金が必要な場合は管理画面から返金を実行してください。";
			if ( $r->refund_amount > 0 ) {
				$vars['payment_status'] .= "\n※すでに " . BV_Util::money( $r->refund_amount ) . ' を返金済みです。';
			}
		} else {
			$vars['payment_status'] = '未入金のため返金対応は不要です。';
		}

		$m = self::render( self::get_template( 'admin_cancel_ja' ), $vars );
		$prefix = ( 'customer' === $by ) ? '' : '［管理側操作］';
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		return self::send( $s['admin_email'], $prefix . $m['subject'], $m['body'], $cc, $r->store, $r->email );
	}

	public static function send_otp( $email, $code, $lang = 'ja', $store = '' ) {
		$s = BV_Util::settings();
		$company = $store ? BV_Util::store_company( $store, $lang ) : $s['company_name'];
		$m = self::render( self::get_template( 'otp_' . ( 'en' === $lang ? 'en' : 'ja' ) ), array(
			'otp' => $code, 'company' => $company, 'company_legal' => $s['company_name'],
		) );
		return self::send( $email, $m['subject'], $m['body'], '', $store );
	}

	/** 汎用（問い合わせ確認など）：店舗の差出人で送る */
	public static function send_store_mail( $to, $subject, $body, $store = '' ) {
		return self::send( $to, $subject, $body, '', $store );
	}

	/**
	 * 車両調整の問い合わせメール（管理者宛＋お客様宛）
	 * 管理者宛は Reply-To をお客様のアドレスにして、そのまま返信できるようにする。
	 */
	public static function send_inquiry( $data ) {
		$s = BV_Util::settings();
		$store = isset( $data['store'] ) ? $data['store'] : '';
		$lang  = ( isset( $data['lang'] ) && 'en' === $data['lang'] ) ? 'en' : 'ja';
		$stores = BV_Util::stores();
		$classes = BV_Util::classes();

		$vars_admin = array(
			'store'   => BV_Util::label( $stores, $store, 'ja' ),
			'class'   => BV_Util::label( $classes, $data['vehicle_class'], 'ja' ),
			'pickup'  => $data['pickup_dt'],
			'return'  => $data['return_dt'],
			'name'    => $data['name'],
			'email'   => $data['email'],
			'phone'   => $data['phone'],
			'lang'    => ( 'en' === $lang ) ? '英語' : '日本語',
			'message' => $data['message'],
			'company' => $store ? BV_Util::store_company( $store, 'ja' ) : $s['company_name'],
			'company_legal' => $s['company_name'],
		);

		/* 管理者・スタッフ宛（返信先＝お客様） */
		$m = self::render( self::get_template( 'inquiry_admin_ja' ), $vars_admin );
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		self::send( $s['admin_email'], $m['subject'], $m['body'], $cc, $store, $data['email'] );

		/* お客様宛の受付確認 */
		$vars_cust = $vars_admin;
		$vars_cust['store']   = BV_Util::label( $stores, $store, $lang );
		$vars_cust['class']   = BV_Util::label( $classes, $data['vehicle_class'], $lang );
		$vars_cust['company'] = $store ? BV_Util::store_company( $store, $lang ) : $s['company_name'];
		$key = ( 'en' === $lang ) ? 'inquiry_customer_en' : 'inquiry_customer_ja';
		$m2 = self::render( self::get_template( $key ), $vars_cust );
		self::send( $data['email'], $m2['subject'], $m2['body'], '', $store );

		return true;
	}

	public static function send_reminder( $r ) {
		if ( ! $r->square_link ) return false;
		$vars = self::reservation_vars( $r );
		/* 期限までの残り時間を文面に入れられるようにする */
		$dl = self::payment_deadline( $r );
		$left_h = max( 0, (int) ceil( ( $dl - current_time( 'timestamp' ) ) / HOUR_IN_SECONDS ) );
		$vars['deadline_at'] = BV_Util::format_dt( date( 'Y-m-d H:i:s', $dl ), $r->lang );
		$vars['hours_left']  = $left_h;
		$vars['deadline_note'] = ( 'en' === $r->lang )
			? ( $left_h > 0
				? "Your provisional booking will be released automatically in about " . $left_h . " hour(s) (by " . $vars['deadline_at'] . ") if payment is not received."
				: "Your provisional booking is about to be released automatically." )
			: ( $left_h > 0
				? 'お支払いが確認できない場合、あと約' . $left_h . '時間（' . $vars['deadline_at'] . 'まで）で仮予約は自動的に解除されます。'
				: 'まもなく仮予約が自動的に解除されます。' );
		$m = self::render( self::get_template( 'reminder_' . $r->lang ), $vars );
		return self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
	}

	/** cron: 未払い仮予約のリマインド */
	/**
	 * 出発前のご案内メール（1週間前・前日）
	 * 送信済みかどうかは transient で管理し、二重送信を防ぐ。
	 */
	public static function send_pickup_reminders() {
		$s = BV_Util::settings();
		$now = current_time( 'timestamp' );
		$today = current_time( 'Y-m-d' );

		$jobs = array();
		if ( ! empty( $s['remind_week_enabled'] ) ) {
			$jobs['week'] = array(
				'days' => 7,
				'hour' => (int) $s['remind_week_hour'],
				'tpl'  => 'pickup_week_',
			);
		}
		if ( ! empty( $s['remind_day_enabled'] ) ) {
			$jobs['day'] = array(
				'days' => 1,
				'hour' => (int) $s['remind_day_hour'],
				'tpl'  => 'pickup_day_',
			);
		}
		if ( ! $jobs ) return 0;

		$sent = 0;
		foreach ( $jobs as $key => $job ) {
			/* 送信予定時刻を過ぎていなければ、その日はまだ送らない */
			if ( (int) current_time( 'H' ) < $job['hour'] ) continue;

			$target = date( 'Y-m-d', $now + $job['days'] * DAY_IN_SECONDS );
			$list = BV_DB::get_reservations( array(
				'from' => $target, 'to' => $target,
				'exclude_cancelled' => true,
			) );
			foreach ( $list as $r ) {
				if ( ! $r->email ) continue;
				if ( in_array( $r->status, array( 'cancelled', 'returned', 'in_use' ), true ) ) continue;
				/* 未入金のオンライン決済予約には送らない（督促メールと役割が重なるため） */
				if ( ! $r->paid_at && ! BV_Util::is_offline_payment( $r ) ) continue;

				$flag = 'bvrm_pk_' . $key . '_' . $r->id;
				if ( get_transient( $flag ) ) continue;

				self::send_pickup_reminder( $r, $job['tpl'] );
				set_transient( $flag, 1, 30 * DAY_IN_SECONDS );
				$sent++;
			}
		}
		if ( $sent > 0 ) update_option( 'bvrm_pickup_remind_last', current_time( 'mysql' ) . '／' . $sent . '件' );
		return $sent;
	}

	/** 出発前のご案内メール1通 */
	public static function send_pickup_reminder( $r, $tpl_prefix ) {
		$vars = self::reservation_vars( $r );
		/* 現金・振込のお客様には、当日のお支払いをあらためて案内する */
		$vars['pay_block'] = ( ! $r->paid_at && BV_Util::is_offline_payment( $r ) )
			? self::offline_pay_block( $r ) . "\n"
			: '';
		$key = $tpl_prefix . ( ( 'en' === $r->lang ) ? 'en' : 'ja' );
		$m = self::render( self::get_template( $key ), $vars );
		return self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
	}

	/**
	 * 未入金の仮予約に督促メールを送る。
	 * created_at は JST（current_time）で保存されているため、比較も current_time で行う。
	 */
	public static function send_payment_reminders() {
		$stages = self::reminder_stages();
		if ( ! $stages ) return;
		$now = current_time( 'timestamp' );
		$list = BV_DB::get_reservations( array( 'status' => 'pending' ) );
		foreach ( $list as $r ) {
			if ( ! self::is_awaiting_payment( $r ) ) continue;
			/* 直前予約（お支払い完了で確定）は保留時間が短いため、督促は送らない */
			if ( self::is_immediate( $r ) ) continue;

			$age = $now - strtotime( $r->created_at );
			foreach ( $stages as $h ) {
				if ( $age < $h * HOUR_IN_SECONDS ) continue;
				$flag = 'bvrm_remind_' . $r->id . '_' . $h;
				if ( get_transient( $flag ) ) continue;
				self::send_reminder( $r );
				set_transient( $flag, 1, 30 * DAY_IN_SECONDS );
			}
		}
	}

	/** 督促を送る時間（仮予約からの経過時間）の配列 */
	public static function reminder_stages() {
		$s = BV_Util::settings();
		$raw = (string) $s['reminder_stages'];
		$out = array();
		foreach ( explode( ',', $raw ) as $v ) {
			$h = (int) trim( $v );
			if ( $h > 0 ) $out[] = $h;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/** 直前予約か（お支払い完了で確定するタイプ） */
	public static function is_immediate( $r ) {
		$s = BV_Util::settings();
		$th = (int) $s['immediate_pay_hours'];
		if ( $th < 1 ) return false;
		$lead = strtotime( $r->pickup_dt ) - strtotime( $r->created_at );
		return $lead <= $th * HOUR_IN_SECONDS;
	}

	/** お支払い期限（タイムスタンプ）。直前予約は短い保留時間を使う */
	public static function payment_deadline( $r ) {
		$s = BV_Util::settings();
		$created = strtotime( $r->created_at );
		if ( self::is_immediate( $r ) ) {
			return $created + max( 5, (int) $s['immediate_hold_minutes'] ) * MINUTE_IN_SECONDS;
		}
		return $created + max( 1, (int) $s['autocancel_hours'] ) * HOUR_IN_SECONDS;
	}

	/**
	 * 自動キャンセルや督促の対象になる「お支払い待ち」の予約か判定する。
	 * 電話予約など決済リンクを出していないものや、こちらの返答待ち（送迎リクエスト中）は対象外。
	 */
	public static function is_awaiting_payment( $r ) {
		if ( 'pending' !== $r->status ) return false;
		if ( $r->paid_at ) return false;
		if ( BV_Util::is_offline_payment( $r ) ) return false; /* 現金・振込は店頭精算のため督促・自動キャンセルの対象外 */
		if ( ! $r->square_link ) return false; /* 決済リンク未発行（電話予約の仮押さえ等） */
		if ( in_array( (string) $r->shuttle_status, array( 'requested', 'quoted' ), true ) ) return false; /* 送迎の回答待ち */
		return true;
	}

	/**
	 * お支払い期限を過ぎた仮予約を自動キャンセルする。
	 * 有効化した日時より後に作成された予約のみを対象とし、
	 * 過去に溜まっている仮予約を一斉キャンセルしないようにしている。
	 */
	public static function auto_cancel_expired() {
		$s = BV_Util::settings();
		if ( empty( $s['autocancel_enabled'] ) ) return 0;
		$hours = (int) $s['autocancel_hours'];
		if ( $hours < 1 ) return 0;

		$since = (int) get_option( 'bvrm_autocancel_from', 0 );
		if ( ! $since ) {
			$since = current_time( 'timestamp' );
			update_option( 'bvrm_autocancel_from', $since );
		}

		$now  = current_time( 'timestamp' );
		$list = BV_DB::get_reservations( array( 'status' => 'pending' ) );
		$count = 0;
		foreach ( $list as $r ) {
			if ( ! self::is_awaiting_payment( $r ) ) continue;
			/* 送迎代を先に頂いている場合は返金判断が必要なので、自動では落とさない */
			if ( $r->shuttle_paid_at ) continue;
			$created = strtotime( $r->created_at );
			if ( $created < $since ) continue;
			/* 直前予約は保留時間が短い。それ以外は設定の時間 */
			if ( $now < self::payment_deadline( $r ) ) continue;

			/* 直前に入金がないか Square に確認してから落とす（Webhook 取りこぼし対策） */
			if ( ! empty( $s['square_access_token'] ) ) {
				BV_Square::sync_payment_status( $r, 'car' );
				$fresh = BV_DB::get_reservation( $r->id );
				if ( $fresh && ( $fresh->paid_at || 'pending' !== $fresh->status ) ) continue;
			}

			$memo = trim( (string) $r->admin_memo );
			$limit_txt = self::is_immediate( $r )
				? '直前予約の保留時間（' . (int) $s['immediate_hold_minutes'] . '分）'
				: '仮予約から' . $hours . '時間';
			$memo .= ( $memo ? "\n" : '' ) . '【自動キャンセル】' . current_time( 'Y-m-d H:i' )
				. ' ' . $limit_txt . '以内にお支払いが確認できなかったため自動キャンセルしました。';
			BV_DB::update_reservation( $r->id, array(
				'status'     => 'cancelled',
				'admin_memo' => $memo,
			) );
			$r = BV_DB::get_reservation( $r->id );

			if ( ! empty( $s['autocancel_notify_customer'] ) && $r->email ) {
				self::send_autocancel( $r );
			}
			self::send_autocancel_admin( $r );
			$count++;
		}
		if ( $count > 0 ) {
			update_option( 'bvrm_autocancel_last', current_time( 'mysql' ) . '／' . $count . '件' );
		}
		return $count;
	}

	/** お客様ご自身の日程変更が完了したときの通知 */
	public static function send_change_done( $r, $old_pickup, $old_return, $old_total, $refund_due = 0 ) {
		$lang = $r->lang;
		$vars = self::reservation_vars( $r );
		$vars['old_pickup'] = BV_Util::format_dt( $old_pickup, $lang );
		$vars['old_return'] = BV_Util::format_dt( $old_return, $lang );
		$vars['old_total']  = BV_Util::money( (int) $old_total, $lang );

		$new_total = (int) $r->price_total;
		$note = '';
		if ( $refund_due > 0 ) {
			$note = ( 'en' === $lang )
				? sprintf( "The price decreased by %s. Our staff will contact you separately regarding the refund.\n\n", BV_Util::money( $refund_due, 'en' ) )
				: sprintf( "今回の変更により料金が %s お安くなりました。差額の返金につきましては、担当者より別途ご案内いたします。\n\n", BV_Util::money( $refund_due ) );
		} elseif ( ! $r->paid_at && $r->square_link ) {
			$note = ( 'en' === $lang )
				? sprintf( "The amount due is now %s. Please complete your payment using the link below.\n%s\n\n", BV_Util::money( $new_total, 'en' ), $r->square_link )
				: sprintf( "変更後のお支払い金額は %s です。お手数ですが下記のリンクよりお支払いをお願いいたします。\n%s\n\n", BV_Util::money( $new_total ), $r->square_link );
		} elseif ( $r->paid_at ) {
			$note = ( 'en' === $lang )
				? "Your payment has already been received; no further payment is required.\n\n"
				: "お支払いはお済みですので、追加のお手続きは必要ございません。\n\n";
		}
		$vars['price_note'] = $note;

		$key = ( 'en' === $lang ) ? 'change_done_en' : 'change_done_ja';
		$m = self::render( self::get_template( $key ), $vars );
		return self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
	}

	/** 同・管理者/スタッフ通知（返信先＝お客様） */
	public static function send_change_done_admin( $r, $old_pickup, $old_return, $old_total, $refund_due = 0, $new_vehicle_id = 0 ) {
		$s = BV_Util::settings();
		$vars = self::reservation_vars( $r );
		$vars['store'] = BV_Util::label( BV_Util::stores(), $r->store, 'ja' );
		$vars['class'] = BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'ja' );
		$vars['name']  = trim( $r->sei . ' ' . $r->mei );
		$vars['pickup'] = BV_Util::format_dt( $r->pickup_dt, 'ja' );
		$vars['return'] = BV_Util::format_dt( $r->return_dt, 'ja' );
		$vars['total']  = BV_Util::money( (int) $r->price_total );
		$vars['old_pickup'] = BV_Util::format_dt( $old_pickup, 'ja' );
		$vars['old_return'] = BV_Util::format_dt( $old_return, 'ja' );
		$vars['old_total']  = BV_Util::money( (int) $old_total );

		$vars['vehicle_note'] = $new_vehicle_id
			? "※空き状況の都合で、割当車両を自動で変更しました。ガントでご確認ください。\n"
			: '';

		$new_total = (int) $r->price_total;
		if ( $refund_due > 0 ) {
			$vars['price_note'] = '【要対応】お支払い済みで料金が ' . BV_Util::money( $refund_due ) . " 減額になりました。管理画面から返金手続きをお願いします。\n";
		} elseif ( ! $r->paid_at ) {
			$vars['price_note'] = ( $new_total !== (int) $old_total )
				? "未入金のため、新しい金額の決済リンクを発行してお客様にお送りしました。\n"
				: "未入金です。金額に変更はありません。\n";
		} else {
			$vars['price_note'] = "お支払い済み・金額の変更はありません。\n";
		}

		$m = self::render( self::get_template( 'admin_change_done_ja' ), $vars );
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		return self::send( $s['admin_email'], $m['subject'], $m['body'], $cc, $r->store, $r->email );
	}

	/**
	 * 期限の表現。直前予約は「10分」、通常予約は「48時間」のように出す。
	 * @return array{text:string,hours:int}
	 */
	public static function deadline_labels( $r ) {
		$s = BV_Util::settings();
		if ( self::is_immediate( $r ) ) {
			$min = max( 5, (int) $s['immediate_hold_minutes'] );
			return array(
				'text'    => ( 'en' === $r->lang ) ? $min . ' minutes' : $min . '分',
				'text_ja' => $min . '分',
				'hours'   => 0,
			);
		}
		$h = max( 1, (int) $s['autocancel_hours'] );
		return array(
			'text'    => ( 'en' === $r->lang ) ? $h . ' hours' : $h . '時間',
			'text_ja' => $h . '時間',
			'hours'   => $h,
		);
	}

	/**
	 * 古いテンプレート（{deadline_hours}時間 と書かれているもの）でも
	 * 「10分」と正しく表示できるように、置き換えてから描画する。
	 */
	protected static function fix_deadline_tpl( $tpl ) {
		foreach ( array( 'subject', 'body' ) as $k ) {
			if ( empty( $tpl[ $k ] ) ) continue;
			$tpl[ $k ] = str_replace(
				array( '{deadline_hours}時間', '{deadline_hours} hours', '{deadline_hours}' ),
				'{deadline_text}',
				$tpl[ $k ]
			);
		}
		return $tpl;
	}

	/** 自動キャンセルのお客様通知 */
	public static function send_autocancel( $r ) {
		$vars = self::reservation_vars( $r );
		$dl = self::deadline_labels( $r );
		$vars['deadline_text']  = $dl['text'];
		$vars['deadline_hours'] = $dl['hours'];
		$vars['immediate_note'] = self::is_immediate( $r )
			? ( ( 'en' === $r->lang )
				? 'As your pick-up was within one week, this booking required payment to be completed on the spot.'
				: 'ご出発まで1週間を切っていたため、このご予約はお支払いの完了が必要なお申し込みでした。' )
			: '';
		$key = ( 'en' === $r->lang ) ? 'autocancel_en' : 'autocancel_ja';
		$m = self::render( self::fix_deadline_tpl( self::get_template( $key ) ), $vars );
		return self::send( $r->email, $m['subject'], $m['body'], '', $r->store );
	}

	/** 自動キャンセルの管理者・スタッフ通知（返信先＝お客様） */
	public static function send_autocancel_admin( $r ) {
		$s = BV_Util::settings();
		$vars = self::reservation_vars( $r );
		$vars['store'] = BV_Util::label( BV_Util::stores(), $r->store, 'ja' );
		$vars['class'] = BV_Util::label( BV_Util::classes(), $r->vehicle_class, 'ja' );
		$vars['name']  = trim( $r->sei . ' ' . $r->mei );
		$vars['pickup'] = BV_Util::format_dt( $r->pickup_dt, 'ja' );
		$vars['return'] = BV_Util::format_dt( $r->return_dt, 'ja' );
		$vars['total']  = BV_Util::money( (int) $r->price_total );
		$dl = self::deadline_labels( $r );
		$vars['deadline_text']  = $dl['text_ja'];
		$vars['deadline_hours'] = $dl['hours'];
		$vars['immediate_note'] = self::is_immediate( $r )
			? '※出発まで1週間以内の「直前予約」です（お支払い完了で確定するタイプ）。' . "\n"
			: '';
		$m = self::render( self::fix_deadline_tpl( self::get_template( 'admin_autocancel_ja' ) ), $vars );
		$cc = trim( $s['admin_cc'] . ',' . $s['staff_notify'], ',' );
		return self::send( $s['admin_email'], $m['subject'], $m['body'], $cc, $r->store, $r->email );
	}
}
