/* BV Booking Form – multi-step reservation form (ja/en) */
(function () {
	'use strict';
	var root = document.getElementById('bvbf-app');
	if (!root || typeof BVBF === 'undefined') return;
	var LANG = BVBF.lang === 'en' ? 'en' : 'ja';

	var T = {
		ja: {
			step1: '1. 空き状況の確認', step2: '2. オプション・料金', step3: '3. お客様情報', done: '予約完了',
			cls: '車両クラス', store: '店舗', pickup: '貸出日時', ret: '返却日時',
			ageNote: '<strong>%n歳未満の方はご利用いただけません。</strong>保険および各種補償は%n歳以上の運転者にのみ適用されるため、%n歳未満の方への貸渡はお断りしています。運転される方全員が%n歳以上であることをご確認ください。',
			ageTooYoung: '貸出日の時点で%n歳未満のため、ご予約を承ることができません。保険および各種補償が%n歳以上の運転者にのみ適用されるためです。',
			covNoCap: '<strong>1日あたりの上限が適用されるのは基本料金（時間料金）のみです。</strong>追加補償B・Cの料金に上限はなく、ご利用時間分（1時間単位・端数切り上げ）がそのまま加算されます。',
			date: '日付', time: '時刻', check: '空き状況を確認する', checking: '確認中…',
			available: '空きがあります！', estimate: '概算料金', days: '日数',
			proceed: 'この条件で仮予約へ進む',
			notAvailable: '申し訳ありません。この条件では満車です。日時の変更、または他の車両クラスをお試しください。車両調整のご相談も承ります。',
			inquiryTitle: '車両調整の問い合わせ', name: 'お名前', email: 'メールアドレス', phone: '電話番号',
			message: 'ご希望・ご相談内容', send: '問い合わせを送信', sent: '問い合わせを送信しました。担当者よりご連絡します。確認メールをお送りしましたのでご確認ください。',
			backTop: '予約のトップに戻る',
			lookupTitle: 'ご予約済みの方（確認・変更・キャンセル）',
			lookupNote: '予約番号を入力すると、ご予約内容の確認・変更・キャンセルができます。',
			lookupPlaceholder: '予約番号（例：BV260805ABCD）',
			lookupBtn: '予約を照会する', lookupEmpty: '予約番号を入力してください',
			equip: '装備オプション（1日あたり）', childSeat: 'チャイルドシート', skiRack: 'スキーラック', navi: 'カーナビ',
			coverage: '追加補償', shuttle: '送迎', shuttleDetail: '送迎の詳細場所（住所・施設名など）',
			shuttleDetailPh: '例：白馬駅前 / ホテル○○ロビー / 長野駅善光寺口 など。人数・希望時刻もご記入ください。',
			shuttleDetailRequired: '送迎をご希望の場合は、送迎場所のご入力が必須です。',
			shuttleRequestNote: '送迎は「リクエスト」です。手配可否をスタッフが確認のうえ、送迎料金のお支払いリンクを別途メールでお送りします。そのお支払い完了で送迎確定となります（車両料金とは別のお支払いです）。',
			shuttleNote: '送迎料金は車両料金とは別に、スタッフ確認後にご案内します（この概算には含まれません）。',
			student: '学割を利用する（学生証を貸出時にご提示ください）', coupon: 'クーポンコード',
			applyCoupon: '適用', couponOk: 'クーポンを適用しました', request: 'ご要望（任意）',
			breakdown: '料金内訳', total: '合計（概算）', next: 'お客様情報の入力へ', back: '戻る',
			memberLoginTitle: '会員の方（2回目以降のご利用）',
			memberLoginNote: 'メールアドレスとパスワードでログインすると、前回ご登録の情報が自動入力されます。',
			memberLogin: 'ログインして自動入力', loggingIn: 'ログイン中…', password: 'パスワード',
			welcomeBack: 'ようこそ %s 様。ご登録情報を読み込みました。',
			prefilled: '前回のご登録情報を自動入力しました。変更がある場合は修正してください。',
			licenseOnFile: '免許証画像はご登録済みです。変更がなければアップロード不要です。',
			licenseOptional: '（変更する場合のみ）',
			firstTimeTitle: '初めての方・パスワードをお持ちでない方',
			emailFirst: '最初にメールアドレスの認証を行います。認証コードをお送りします。',
			sendOtp: '認証コードを送る', otpSent: '認証コードをメールでお送りしました。届かない場合は迷惑メールフォルダをご確認ください。',
			otpCode: '認証コード（6桁）', verify: '認証する', verified: 'メール認証が完了しました。',
			sei: '姓', mei: '名', address: '住所', birth: '生年月日',
			year: '年', month: '月', day: '日', birthInvalid: '生年月日が正しくありません',
			licenseFront: '運転免許証（表面）またはマイナ免許証のスクリーンショット', licenseBack: '運転免許証（裏面）',
			passport: 'パスポート（顔写真ページ）', intl: '国際運転免許証',
			memberPass: '会員登録用パスワード（8文字以上）',
			memberNote: 'お支払いへ進むと同時に会員登録が行われ、次回から予約状況の確認・変更がかんたんになります。',
			submit: '仮予約を確定する', submitting: '送信中…',
			doneMsg: '仮予約を受け付けました。確認メールをお送りしましたのでご確認ください。',
			doneMsgNow: 'ご予約を受け付けました。<strong>お支払いの完了をもってご予約が確定</strong>します。下のボタンからお手続きください。',
			immediateNote: '貸出まで%tを切っているため、このご予約は<strong>お支払いの完了で確定</strong>となります。%m分以内にお支払いがない場合、お車は自動的に解放されます。',
			policyTitle: 'キャンセルポリシー',
			cdTitle: 'お支払い期限まで',
			cdExpired: 'お支払い期限を過ぎました。ご予約は解除されました。お手数ですが、あらためてご予約をお願いいたします。',
			submitNow: 'お支払いへ進む（この内容で申し込む）',
			finalTitle: 'お申し込み前の最終確認',
			final1: 'この時点ではまだご予約は確定しません。クレジットカードでの事前決済が完了して初めて確定します。',
			final2: 'お支払い期限はお申し込みから%h時間です。期限を過ぎると自動的にキャンセルとなります。',
			final2Now: 'ご出発まで1週間以内のため、次の画面のお支払いを%m分以内に完了してください。お支払いの確認後、予約確定メールをお送りします。',
			final3: '運転免許証（原本）を当日必ずご持参ください。お忘れの場合、貸渡しができません。',
			final4: '送迎をご希望の場合、送迎料金は車両料金とは別のお支払いです（スタッフ確認後にご案内します）。',
			flowTitle: 'ご予約の流れ（お申し込み前にご確認ください）',
			flow1: 'オプション・お客様情報をご入力いただきます。',
			flow2: '確認メールとお支払い用リンクをお送りします。',
			flow3: 'クレジットカードでの事前決済が完了した時点で、ご予約が確定します。（お申し込みだけでは確定しません）',
			flow4: '確定後、貸出日の1週間前と前日にご案内メールをお送りします。',
			flow2Now: 'そのままお支払い画面へお進みいただきます（%m分以内にお支払いください）。',
			flow3Now: 'クレジットカードでの事前決済が完了した時点で、ご予約が確定し、予約確定メールをお送りします。（お申し込みだけでは確定しません）',
			flow4Now: '確定後、貸出日の前日にご案内メールをお送りします。',
			policyIntro: 'ご予約成立後にキャンセルされる場合、出発日までの残り日数に応じてキャンセル料を申し受けます。出発日が近いほど料率が上がります。',
			policyFree: '無料',
			policyAxis: '← ご予約時期が早い　　　　出発日が近い →',
			policyNoshow: 'ご連絡なくご来店がなかった場合（無断キャンセル）は、キャンセル料%p%を申し受けます。ご都合が悪くなった場合は、必ずご連絡をお願いいたします。',
			policyFoot: '※キャンセル料はご予約金額に対する割合です。お支払い済みの場合、キャンセル料を差し引いた金額を自動でご返金します。',
			payDeadlineTitle: 'お支払い期限',
			payDeadlineNow: '貸出まで%tを切っているため、<strong>決済画面が表示されてから%m分以内</strong>にお支払いください。お支払いが確認できない場合、お車は自動的に解放され、ご予約はキャンセルとなります。',
			payDeadlineNormal: 'お申し込みから<strong>%h時間以内</strong>にお支払いをお願いします。期限までにお支払いが確認できない場合、ご予約は自動的にキャンセルとなります。',
			resNo: '予約番号', payNow: 'お支払いへ進む', manage: '予約内容を確認する',
			required: 'この項目は必須です', err: 'エラーが発生しました。時間をおいてお試しください。',
			fileBig: 'ファイルが大きすぎます（8MBまで）', pastDate: '本日以降の日時を選択してください',
			retAfter: '返却日時は貸出日時より後にしてください', free: '無料', perDay: '/日', yen: '円',
			leadNote: '※貸出は営業時間内のみ承ります。ご予約は現在より%h時間後以降、%d日先まで承ります。',
			return24: '※ご返却は24時間承っております（営業時間外は所定の場所へご返却ください）。',
			tooSoon: 'ご予約は現在より%h時間後以降の日時をお選びください',
			tooFar: 'ご予約は%d日先までとなります',
			hours: '時間数', perHour: '/時間',
			hourlyNote: '※この店舗は<strong>時間貸し</strong>です（1時間単位・端数切り上げ）。貸出・返却とも営業時間内（%o〜%c）にお願いします。装備オプションは1日単位です。',
			hourlyRate: '時間料金：%p/時間'
		},
		en: {
			step1: '1. Check Availability', step2: '2. Options & Price', step3: '3. Your Details', done: 'Reservation Complete',
			cls: 'Vehicle class', store: 'Branch', pickup: 'Pick-up', ret: 'Return',
			ageNote: '<strong>Drivers under %n cannot rent from us.</strong> Insurance and all coverage apply only to drivers aged %n and over, so we are unable to rent to anyone under %n. Please make sure every driver is %n or older.',
			ageTooYoung: 'You will be under %n years old on the pick-up date, so we are unable to accept this booking. Insurance and coverage apply only to drivers aged %n and over.',
			covNoCap: '<strong>The daily cap applies to the base hourly rate only.</strong> There is no cap on optional coverage B or C — it is charged for every hour of the rental (per hour, rounded up).',
			date: 'Date', time: 'Time', check: 'Check availability', checking: 'Checking…',
			available: 'Available!', estimate: 'Estimated price', days: 'Days',
			proceed: 'Continue with these conditions',
			notAvailable: 'Sorry, no vehicles are available for these conditions. Please try different dates or another vehicle class, or send us an inquiry — we may be able to arrange a vehicle.',
			inquiryTitle: 'Vehicle arrangement inquiry', name: 'Name', email: 'Email address', phone: 'Phone number',
			message: 'Your request', send: 'Send inquiry', sent: 'Your inquiry has been sent. We will contact you soon. A confirmation email has been sent to you.',
			backTop: 'Back to booking top',
			lookupTitle: 'Already booked? (View / change / cancel)',
			lookupNote: 'Enter your reservation number to view, change or cancel your booking.',
			lookupPlaceholder: 'Reservation No. (e.g. BV260805ABCD)',
			lookupBtn: 'Look up reservation', lookupEmpty: 'Please enter your reservation number',
			equip: 'Equipment options (per day)', childSeat: 'Child seat', skiRack: 'Ski rack', navi: 'Car navigation',
			coverage: 'Additional coverage', shuttle: 'Shuttle service', shuttleDetail: 'Shuttle location details (address, facility name, etc.)',
			shuttleDetailPh: 'e.g. Hakuba Station / Hotel ABC lobby / Nagano Station Zenkoji exit. Please also note the number of passengers and preferred time.',
			shuttleDetailRequired: 'Please enter the shuttle location. It is required when requesting a shuttle.',
			shuttleRequestNote: 'The shuttle is a REQUEST, not a confirmed booking. Our staff will check availability and email you a separate payment link for the shuttle fee. Your shuttle is confirmed once that payment is completed (separate from the car rental fee).',
			shuttleNote: 'The shuttle fee is charged separately from the car rental fee and is not included in this estimate.',
			student: 'Apply student discount (show your student ID at pick-up)', coupon: 'Coupon code',
			applyCoupon: 'Apply', couponOk: 'Coupon applied', request: 'Requests (optional)',
			breakdown: 'Price breakdown', total: 'Estimated total', next: 'Enter your details', back: 'Back',
			memberLoginTitle: 'Returning members',
			memberLoginNote: 'Log in with your email and password to auto-fill your saved details.',
			memberLogin: 'Log in & auto-fill', loggingIn: 'Logging in…', password: 'Password',
			welcomeBack: 'Welcome back, %s. Your saved details have been loaded.',
			prefilled: 'Your saved details have been filled in. Please edit if anything has changed.',
			licenseOnFile: 'Your ID documents are already on file. No need to upload again unless they have changed.',
			licenseOptional: ' (only if changed)',
			firstTimeTitle: 'First-time customers / no password',
			emailFirst: 'First, we verify your email address. We will send you a verification code.',
			sendOtp: 'Send verification code', otpSent: 'A verification code has been emailed to you. Please also check your spam folder.',
			otpCode: 'Verification code (6 digits)', verify: 'Verify', verified: 'Email verified.',
			sei: 'Family name', mei: 'Given name', address: 'Address', birth: 'Date of birth',
			year: 'Year', month: 'Month', day: 'Day', birthInvalid: 'Invalid date of birth',
			licenseFront: "Driver's license (front)", licenseBack: "Driver's license (back)",
			passport: 'Passport (photo page)', intl: 'International Driving Permit',
			memberPass: 'Password for member registration (8+ characters)',
			memberNote: 'A member account will be created when you proceed to payment, making it easy to view or change your reservations next time.',
			submit: 'Confirm provisional booking', submitting: 'Sending…',
			doneMsg: 'Your provisional reservation has been received. Please check your confirmation email.',
			doneMsgNow: 'Your booking has been received. <strong>It is confirmed once payment is completed.</strong> Please pay using the button below.',
			immediateNote: 'Your pick-up is less than %t away, so this booking is <strong>confirmed only once payment is completed</strong>. If we do not receive payment within %m minutes, the vehicle will be released.',
			policyTitle: 'Cancellation policy',
			cdTitle: 'Time left to pay',
			cdExpired: 'The payment window has closed and your booking has been released. We are sorry — please make a new reservation.',
			submitNow: 'Continue to payment',
			finalTitle: 'Before you submit',
			final1: 'Your reservation is NOT confirmed at this point. It is confirmed only when payment by credit card is completed.',
			final2: 'Payment is due within %h hours of booking. After that, your reservation is cancelled automatically.',
			final2Now: 'Your pick-up is within one week, so please complete payment on the next screen within %m minutes. Your confirmation email is sent once payment is received.',
			final3: 'Please bring your passport and valid driving licence on the day. We cannot release the vehicle without them.',
			final4: 'If you request a shuttle, the shuttle fee is paid separately from the car rental fee (we will contact you after checking availability).',
			flowTitle: 'How booking works (please read before you continue)',
			flow1: 'Choose your options and enter your details.',
			flow2: 'We email you a confirmation and a payment link.',
			flow3: 'Your reservation is confirmed only when payment by credit card is completed. Submitting the form alone does not secure the vehicle.',
			flow4: 'After confirmation, we send reminder emails one week and one day before your pick-up date.',
			flow2Now: 'You go straight to the payment screen (please pay within %m minutes).',
			flow3Now: 'Your reservation is confirmed the moment your card payment completes, and we email your confirmation. Submitting the form alone does not secure the vehicle.',
			flow4Now: 'After confirmation, we send a reminder email the day before your pick-up date.',
			policyIntro: 'If you cancel after your booking is confirmed, a cancellation fee applies based on how close it is to your pick-up date. The closer the date, the higher the fee.',
			policyFree: 'Free',
			policyAxis: '← Booked well in advance     Close to pick-up →',
			policyNoshow: 'If you do not arrive and do not contact us (no-show), a cancellation fee of %p% applies. Please always let us know if your plans change.',
			policyFoot: '* Fees are a percentage of your booking amount. If you have already paid, we refund the balance automatically after deducting the fee.',
			payDeadlineTitle: 'Payment deadline',
			payDeadlineNow: 'Your pick-up is less than %t away, so please pay <strong>within %m minutes</strong> of the payment page appearing. If payment is not received, the vehicle is released and your booking is cancelled.',
			payDeadlineNormal: 'Please complete payment <strong>within %h hours</strong> of booking. If payment is not received by then, your reservation is cancelled automatically.',
			resNo: 'Reservation No.', payNow: 'Proceed to payment', manage: 'View your reservation',
			required: 'This field is required', err: 'An error occurred. Please try again later.',
			fileBig: 'File is too large (max 8MB)', pastDate: 'Please select today or a later date',
			retAfter: 'Return must be after pick-up', free: 'Free', perDay: '/day', yen: 'JPY',
			leadNote: '* Pick-up is available during business hours only. Bookings are accepted from %h hours ahead, up to %d days in advance.',
			return24: '* Returns are accepted 24 hours a day (outside business hours, please return the vehicle to the designated place).',
			tooSoon: 'Please select a pick-up time at least %h hours from now',
			tooFar: 'Bookings can be made up to %d days in advance',
			hours: 'Hours', perHour: '/hour',
			hourlyNote: '* This branch offers <strong>hourly rentals</strong> (charged per hour, rounded up). Pick-up and return must be within business hours (%o-%c). Equipment options are charged per day.',
			hourlyRate: 'Hourly rate: %p/hour'
		}
	}[LANG];

	var state = {
		config: null, quote: null, otpToken: '', files: {},
		sel: { vehicle_class: 'minivan', store: BVBF.store, pickup_date: '', pickup_time: '10:00', return_date: '', return_time: '10:00',
			coverage: 'C', shuttle: 'none', shuttle_detail: '',
			is_student: false, coupon_code: '', request_note: '' }
	};

	function money(n) { n = Math.round(n); return LANG === 'en' ? 'JPY ' + n.toLocaleString() : '¥' + n.toLocaleString(); }
	function lbl(map, key) { return map && map[key] ? map[key][LANG] : key; }

	/* ---- 運転者の年齢制限（中央プラグインの設定に従う。未提供なら21歳） ---- */

	function minAge() {
		var c = state.config || {};
		return (c.min_driver_age === undefined || c.min_driver_age === null) ? 21 : +c.min_driver_age;
	}
	/** 文言の %n を下限年齢に置き換える */
	function ageText(key) { return T[key].split('%n').join(String(minAge())); }
	/** 基準日時点の満年齢（birth・onDate とも y-m-d） */
	function ageOn(birth, onDate) {
		var b = String(birth).split('-'), o = String(onDate).split('-');
		if (b.length !== 3 || o.length !== 3 || !o[0]) return null;
		var age = +o[0] - +b[0];
		var mdOn = +o[1] * 100 + +o[2], mdB = +b[1] * 100 + +b[2];
		if (mdOn < mdB) age--;
		return age;
	}
	/** 貸出日時点で年齢制限を満たすか（中央側でも同じ判定を行う） */
	function ageOk(birthdate) {
		var min = minAge();
		if (min < 1) return true;
		var age = ageOn(birthdate, state.sel.pickup_date);
		return age !== null && age >= min;
	}

	/* 定員（中央プラグインが未更新でも動くようフォールバックを持つ） */
	var CAP_FALLBACK = { kei: 4, compact: 5, suv: 5, minivan: 7 };
	function capacityOf(map, key) {
		if (map && map[key] && map[key].capacity) return +map[key].capacity;
		return CAP_FALLBACK[key] || 0;
	}
	/*
	 * 以下のヘルパーは state を直接参照する。
	 * 描画関数の中のローカル変数（c / s）はここからは見えないため、
	 * うっかり参照するとクリック処理ごと例外で止まってしまう。
	 */

	/** 時間数を読みやすい言葉にする（168 → 1週間） */
	function leadLabel(h) {
		h = +h || 0;
		if (LANG === 'en') {
			if (h >= 168) { var w = Math.round(h / 168); return w + (w === 1 ? ' week' : ' weeks'); }
			if (h >= 24)  { var d = Math.round(h / 24);  return d + (d === 1 ? ' day' : ' days'); }
			return h + ' hours';
		}
		if (h >= 168) return Math.round(h / 168) + '週間';
		if (h >= 24)  return Math.round(h / 24) + '日';
		return h + '時間';
	}

	/** 直前予約（お支払い完了で確定）かどうか */
	function isImmediate() {
		var c = state.config || {}, s = state.sel;
		var th = (c.immediate_pay_hours !== undefined) ? +c.immediate_pay_hours : 0;
		if (!th || !s.pickup_date || !s.pickup_time) return false;
		var pk = new Date((s.pickup_date + ' ' + s.pickup_time + ':00').replace(/-/g, '/'));
		var now = c.now ? new Date(c.now.replace(/-/g, '/')) : new Date();
		if (isNaN(pk.getTime())) return false;
		return (pk - now) <= th * 3600 * 1000;
	}

	/** お支払い期限（分）。直前予約は短い */
	function payHoldText() {
		var c = state.config || {};
		if (isImmediate()) {
			return String((c.immediate_hold_minutes === undefined) ? 10 : c.immediate_hold_minutes);
		}
		return '';
	}

	/**
	 * お支払い期限のカウントダウン。
	 * サーバーの現在時刻を基準にした残り秒数を求め、端末の時計ズレの影響を受けないようにする。
	 */
	function startCountdown(deadlineStr, nowStr) {
		var clock = document.getElementById('bv-cd-clock');
		var note  = document.getElementById('bv-cd-note');
		var box   = document.getElementById('bv-countdown');
		if (!clock) return;

		var dl = new Date(String(deadlineStr).replace(/-/g, '/'));
		var sv = nowStr ? new Date(String(nowStr).replace(/-/g, '/')) : new Date();
		if (isNaN(dl.getTime())) return;
		var left = Math.floor((dl - sv) / 1000);   /* 残り秒数 */
		var started = Date.now();

		function tick() {
			var rest = left - Math.floor((Date.now() - started) / 1000);
			if (rest <= 0) {
				clock.textContent = '0:00';
				if (box) { box.style.borderColor = '#8a8f94'; box.style.background = '#f0f0f1'; }
				clock.style.color = '#50575e';
				if (note) note.innerHTML = '<strong>' + T.cdExpired + '</strong>';
				var pay = document.getElementById('bv-paybtn');
				if (pay) {
					pay.removeAttribute('href');
					pay.style.opacity = '0.5';
					pay.style.pointerEvents = 'none';
				}
				clearInterval(timer);
				return;
			}
			var m = Math.floor(rest / 60), sec = rest % 60;
			clock.textContent = m + ':' + (sec < 10 ? '0' : '') + sec;
			/* 残り1分を切ったら点滅させて気づきやすくする */
			if (rest <= 60) clock.style.opacity = (rest % 2 === 0) ? '1' : '0.45';
			else clock.style.opacity = '1';
		}
		tick();
		var timer = setInterval(tick, 1000);
	}

	/** 「仮予約を確定する」直前の最終確認 */
	function finalNoticeHtml() {
		var c = state.config || {};
		var imm = isImmediate();
		var mins = (c.immediate_hold_minutes === undefined) ? 10 : c.immediate_hold_minutes;
		var hrs  = (c.autocancel_hours === undefined) ? 48 : c.autocancel_hours;

		var h = '<div style="background:' + (imm ? '#fff4f4' : '#f6f7f7') + ';border:1px solid ' + (imm ? '#d63638' : '#c3c4c7') + ';border-radius:8px;padding:14px;margin:16px 0;text-align:left">';
		h += '<p style="margin:0 0 8px;font-weight:600">' + T.finalTitle + '</p>';
		h += '<ul style="margin:0 0 10px;padding-left:1.3em;line-height:1.9">';
		h += '<li><strong>' + T.final1 + '</strong></li>';
		h += '<li>' + (imm ? T.final2Now.replace('%m', mins) : T.final2.replace('%h', hrs)) + '</li>';
		h += '<li>' + T.final3 + '</li>';
		h += '<li>' + T.final4 + '</li>';
		h += '</ul>';
		h += policyHtml();
		h += '</div>';
		return h;
	}

	/** 申し込みの流れ＋お支払い期限＋キャンセルポリシーの再確認 */
	function flowHtml() {
		var c = state.config || {};
		var imm = isImmediate();
		var mins = (c.immediate_hold_minutes === undefined) ? 10 : c.immediate_hold_minutes;
		var hrs  = (c.autocancel_hours === undefined) ? 48 : c.autocancel_hours;
		var thH  = (c.immediate_pay_hours === undefined) ? 168 : c.immediate_pay_hours;

		var h = '<div class="bvbf-flow" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:14px;margin:14px 0;text-align:left">';
		h += '<p style="margin:0 0 10px;font-weight:600">' + T.flowTitle + '</p>';
		h += '<ol style="margin:0 0 12px;padding-left:1.3em;line-height:1.9">';
		h += '<li>' + T.flow1 + '</li>';
		h += '<li>' + (imm ? T.flow2Now.replace('%m', mins) : T.flow2) + '</li>';
		h += '<li><strong>' + (imm ? T.flow3Now : T.flow3) + '</strong></li>';
		h += '<li>' + (imm ? T.flow4Now : T.flow4) + '</li>';
		h += '</ol>';

		h += '<div style="background:' + (imm ? '#fff4f4' : '#fff8e5') + ';border:1px solid ' + (imm ? '#d63638' : '#e0b900') + ';border-radius:6px;padding:10px;margin-bottom:10px">';
		h += '<strong>' + T.payDeadlineTitle + '</strong><br>';
		h += imm
			? T.payDeadlineNow.replace('%t', leadLabel(thH)).replace('%m', mins)
			: T.payDeadlineNormal.replace('%h', hrs);
		h += '</div>';

		h += policyHtml();
		h += '</div>';
		return h;
	}

	/**
	 * キャンセルポリシー。文章・図解・一覧の3点セットで示す。
	 * 図解は「出発日までの時間軸」を1本の帯で表し、期日が近いほど料率が上がることを直感的に伝える。
	 */
	function policyHtml() {
		var c = state.config || {};
		var tiers = c.cancel_tiers || [];
		var noshow = (c.cancel_noshow_pct === undefined) ? 100 : +c.cancel_noshow_pct;

		var h = '<div style="margin-top:14px">';
		h += '<p style="margin:0 0 4px;font-weight:600">' + T.policyTitle + '</p>';
		h += '<p style="margin:0 0 10px;font-size:13px;line-height:1.7;color:#444">' + T.policyIntro + '</p>';

		if (tiers.length) {
			/* 色は料率に応じて段階的に濃くする */
			var colors = ['#e6f4ea', '#fdf3d6', '#fbe3d5', '#f7d2d2'];
			var border = ['#2e7d4f', '#b8860b', '#c2571f', '#b32d2e'];
			var labels = (LANG === 'en')
				? ['1 month before', '1 week before', '48 hours before', 'Pick-up']
				: ['1か月前', '1週間前', '48時間前', '出発'];

			h += '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch">';
			h += '<div style="min-width:460px">';

			/* 帯（4区間） */
			h += '<div style="display:flex;border-radius:6px;overflow:hidden;border:1px solid #ddd">';
			for (var i = 0; i < tiers.length; i++) {
				var pct = Math.max(0, Math.min(100, +tiers[i].pct));
				var nm  = (LANG === 'en') ? tiers[i].en : tiers[i].ja;
				var txt = (pct === 0) ? T.policyFree : pct + '%';
				h += '<div style="flex:1;background:' + colors[i] + ';border-left:' + (i ? '1px solid #fff' : '0') + ';padding:9px 6px;text-align:center">'
					+ '<div style="font-size:11px;color:#555;line-height:1.35;min-height:2.7em">' + nm + '</div>'
					+ '<div style="font-size:17px;font-weight:700;color:' + border[i] + ';margin-top:2px">' + txt + '</div>'
					+ '</div>';
			}
			h += '</div>';

			/* 目盛り（区切りの日時ラベル） */
			h += '<div style="display:flex;font-size:11px;color:#777;margin-top:4px">';
			h += '<div style="flex:1;text-align:right;padding-right:2px">▲' + labels[0] + '</div>';
			h += '<div style="flex:1;text-align:right;padding-right:2px">▲' + labels[1] + '</div>';
			h += '<div style="flex:1;text-align:right;padding-right:2px">▲' + labels[2] + '</div>';
			h += '<div style="flex:1;text-align:right;padding-right:2px">▲' + labels[3] + '</div>';
			h += '</div>';

			h += '<div style="text-align:center;font-size:11px;color:#999;margin-top:2px">' + T.policyAxis + '</div>';
			h += '</div></div>';

			h += '<div style="background:#f7f7f7;border-left:3px solid #b32d2e;padding:8px 10px;margin-top:10px;font-size:12.5px;line-height:1.7">'
				+ T.policyNoshow.replace('%p', noshow) + '</div>';
		} else {
			var txt2 = (LANG === 'en') ? (c.cancel_policy_en || '') : (c.cancel_policy_ja || '');
			if (txt2) h += '<div class="bvbf-note">' + txt2.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>') + '</div>';
		}

		h += '<p style="margin:8px 0 0;font-size:12px;color:#777;line-height:1.7">' + T.policyFoot + '</p>';
		h += '</div>';
		return h;
	}

	/** 学割が使えるクラスか（中央サイトの設定に従う。未提供なら軽自動車のみ） */
	/* 店舗ごとの情報（営業時間・時間貸し・取扱クラス）。旧APIでは全店共通の値を返す */
	function storeInfo(store) {
		var c = state.config || {};
		var si = (c.store_info && c.store_info[store]) ? c.store_info[store] : {};
		return {
			hourly: !!si.hourly,
			classes: (si.classes && si.classes.length) ? si.classes : Object.keys(c.classes || {}),
			open: si.open || c.open_time,
			close: si.close || c.close_time,
			/* 以下は中央プラグインが未更新なら全店共通の値にフォールバックする */
			leadTime: (si.lead_time_hours === undefined) ? c.lead_time_hours : +si.lead_time_hours,
			coverages: (si.coverages && si.coverages.length) ? si.coverages : null,
			studentClasses: (si.student_classes === undefined)
				? ((c.student_classes && c.student_classes.length) ? c.student_classes : ['kei'])
				: si.student_classes,
			shuttle: (si.shuttle === undefined) ? true : !!si.shuttle
		};
	}
	function isHourly() { return storeInfo(state.sel.store).hourly; }

	/** この店舗の受付開始（現在時刻の何時間後から） */
	function leadHours() {
		var v = storeInfo(state.sel.store).leadTime;
		return (v === undefined || v === null) ? 2 : +v;
	}
	/** この店舗で選べる補償プラン（定義順） */
	function allowedCoverages() {
		var only = storeInfo(state.sel.store).coverages;
		return ['C', 'B', 'A'].filter(function (k) { return !only || only.indexOf(k) !== -1; });
	}
	/** この店舗で送迎を扱うか */
	function shuttleAllowed() { return storeInfo(state.sel.store).shuttle; }

	function studentAllowed() {
		var s = state.sel;
		var list = storeInfo(s.store).studentClasses || [];
		return list.indexOf(s.vehicle_class) !== -1;
	}

	/** 定員つきのクラス名 */
	function clsLabel(map, key) {
		if (!map || !map[key]) return key;
		var cap = capacityOf(map, key);
		var base = map[key][LANG];
		if (!cap) return base;
		return (LANG === 'en') ? base + ' (up to ' + cap + ' passengers)' : base + '（定員' + cap + '名）';
	}
	function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d; }
	function api(action, data, cb) {
		var body = Object.assign({ action: action, lang: LANG }, data || {});
		fetch(BVBF.proxy, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BVBF.nonce },
			body: JSON.stringify(body)
		}).then(function (r) { return r.json().then(function (j) { cb(r.ok, j); }); })
		.catch(function () { cb(false, { message: T.err }); });
	}
	function timeOptions(open, close) {
		var out = [], oh = parseInt(open.split(':')[0], 10), om = parseInt(open.split(':')[1], 10);
		var ch = parseInt(close.split(':')[0], 10), cm = parseInt(close.split(':')[1], 10);
		for (var m = oh * 60 + om; m <= ch * 60 + cm; m += 30) {
			var h = Math.floor(m / 60), mm = m % 60;
			out.push(('0' + h).slice(-2) + ':' + (mm ? mm : '00'));
		}
		return out;
	}
	function dt(dateStr, timeStr) { return dateStr + ' ' + timeStr + ':00'; }
	function fmtDate(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function addDays(iso, n) { var p = iso.split('-'); return fmtDate(new Date(+p[0], +p[1] - 1, +p[2] + n)); }

	/* サーバー時刻を基準にした受付可能範囲 */
	function serverNow() {
		var c = state.config || {};
		if (c.now) {
			var m = c.now.match(/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/);
			if (m) return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
		}
		return new Date();
	}
	function earliestPickup() {
		var lead = leadHours();
		var d = serverNow();
		d.setHours(d.getHours() + lead);
		/* 30分単位に切り上げ */
		if (d.getMinutes() % 30 !== 0 || d.getSeconds() > 0) {
			d.setMinutes(d.getMinutes() + (30 - (d.getMinutes() % 30)), 0, 0);
		}
		return d;
	}
	function latestPickup() {
		var c = state.config || {};
		var maxd = (c.max_advance_days === undefined) ? 365 : +c.max_advance_days;
		var d = serverNow();
		d.setDate(d.getDate() + maxd);
		return d;
	}
	/** 指定日で選択できる最小時刻（当日は受付開始時刻以降） */
	function minTimeFor(dateIso) {
		var e = earliestPickup();
		if (dateIso !== fmtDate(e)) return null;
		return ('0' + e.getHours()).slice(-2) + ':' + ('0' + e.getMinutes()).slice(-2);
	}
	function errBox(msg) { return '<div class="bvbf-err">' + msg + '</div>'; }

	/*
	 * 画面遷移時の自動スクロール。
	 *
	 * ステップ1「空き状況の確認」ではスクロールしない（renderStep1 参照）。
	 * ページの途中にフォームを置いている場合に、表示直後や店舗・クラスの
	 * 切り替えのたびに画面が動いてしまうため。
	 *
	 * 空き状況の結果（空きあり／満車のご案内）から先は、新しく表示された内容の
	 * 先頭が見えるようにスクロールする。
	 *
	 * すべて止めたい場合は AUTO_SCROLL を false にする。
	 */
	var AUTO_SCROLL = true;
	function scrollToEl(el) {
		if (!AUTO_SCROLL || !el) return;
		var y = el.getBoundingClientRect().top + window.pageYOffset - 80;
		try { window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' }); }
		catch (e) { window.scrollTo(0, Math.max(0, y)); }
	}
	function scrollTop() { scrollToEl(root); }

	/* 予約照会（予約番号で確認・変更・キャンセル） */
	function lookupBox() {
		var h = '<div class="bvbf-card bvbf-lookup"><h4>' + T.lookupTitle + '</h4>';
		h += '<p class="bvbf-note">' + T.lookupNote + '</p>';
		h += '<div class="bvbf-row"><input type="text" id="bv-lookupcode" placeholder="' + T.lookupPlaceholder + '">';
		h += '<button class="bvbf-btn" id="bv-lookupbtn" type="button">' + T.lookupBtn + '</button></div>';
		h += '<div id="bv-lookuperr"></div></div>';
		return h;
	}
	function bindLookup() {
		var btn = document.getElementById('bv-lookupbtn');
		if (!btn) return;
		var go = function () {
			var code = (document.getElementById('bv-lookupcode').value || '').trim().toUpperCase();
			if (!code) { document.getElementById('bv-lookuperr').innerHTML = errBox(T.lookupEmpty); return; }
			var base = (state.config && state.config.manage_url) ? state.config.manage_url : '/';
			var sep = base.indexOf('?') === -1 ? '?' : '&';
			window.location.href = base + sep + 'bv_manage=' + encodeURIComponent(code) + '&lang=' + LANG + '&store=' + encodeURIComponent(state.sel.store || '');
		};
		btn.addEventListener('click', go);
		document.getElementById('bv-lookupcode').addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); go(); }
		});
	}

	/* ---------- STEP 1 ---------- */
	function renderStep1(msg) {
		var c = state.config, s = state.sel;
		/* 店舗：このサイトで選べる店舗のみ表示。1つだけなら固定表示 */
		var allowed = (BVBF.stores && BVBF.stores.length) ? BVBF.stores : [s.store];
		allowed = allowed.filter(function (k) { return c.stores[k]; });
		if (!allowed.length) allowed = [s.store];
		if (allowed.indexOf(s.store) === -1) s.store = allowed[0];
		/* 店舗ごとの営業時間・取扱クラス・時間貸し */
		var si = storeInfo(s.store);
		var hourly = si.hourly;
		if (si.classes.indexOf(s.vehicle_class) === -1) s.vehicle_class = si.classes[0];

		var earliest = earliestPickup();
		var minDate = fmtDate(earliest);
		var maxDate = fmtDate(latestPickup());
		var today = minDate;
		if (!s.pickup_date || s.pickup_date < minDate) {
			s.pickup_date = minDate;
			var eh = ('0' + earliest.getHours()).slice(-2) + ':' + ('0' + earliest.getMinutes()).slice(-2);
			var oh = si.open, ch = si.close;
			s.pickup_time = (eh < oh) ? oh : ((eh > ch) ? oh : eh);
			if (eh > ch) s.pickup_date = addDays(minDate, 1); /* 営業終了後なら翌日 */
		}
		if (!s.return_date || s.return_date < s.pickup_date) { s.return_date = hourly ? s.pickup_date : addDays(s.pickup_date, 1); }
		var times = timeOptions(si.open, si.close);
		if (times.indexOf(s.pickup_time) === -1) s.pickup_time = times[0];
		/* 返却は24時間受付（設定による）。時間貸し店舗は営業時間内のみ */
		var retTimes = (c.return_24h && !hourly) ? timeOptions('00:00', '23:30') : times;
		if (retTimes.indexOf(s.return_time) === -1) s.return_time = times[0];
		var h = '<div class="bvbf-card"><h3>' + T.step1 + '</h3>' + (msg || '');
		if (hourly) {
			var hr = (c.hourly_rates && c.hourly_rates[s.vehicle_class]) ? c.hourly_rates[s.vehicle_class] : 0;
			h += '<p class="bvbf-note" style="background:#fff8e5;border:1px solid #e0b900;color:#6b5200;padding:8px 10px;border-radius:6px">' + T.hourlyNote.replace('%o', si.open).replace('%c', si.close) + (hr ? '<br>' + T.hourlyRate.replace('%p', money(hr)) : '') + '</p>';
		}
		h += '<label>' + T.cls + '</label><select id="bv-class">';
		si.classes.forEach(function (k) {
			if (!c.classes[k]) return;
			h += '<option value="' + k + '"' + (s.vehicle_class === k ? ' selected' : '') + '>' + clsLabel(c.classes, k) + '</option>';
		});
		h += '</select>';
		if (minAge() > 0) {
			h += '<p class="bvbf-note" style="background:#fff4f4;border:1px solid #d63638;color:#8a1f21;padding:8px 10px;border-radius:6px">' + ageText('ageNote') + '</p>';
		}
		h += '<label>' + T.store + '</label>';
		if (allowed.length === 1) {
			h += '<div class="bvbf-fixed">' + lbl(c.stores, allowed[0]) + '</div>';
			h += '<input type="hidden" id="bv-store" value="' + allowed[0] + '">';
		} else {
			h += '<select id="bv-store">';
			allowed.forEach(function (k) {
				h += '<option value="' + k + '"' + (s.store === k ? ' selected' : '') + '>' + lbl(c.stores, k) + '</option>';
			});
			h += '</select>';
		}
		h += '<label>' + T.pickup + '</label><div class="bvbf-row"><input type="date" id="bv-pd" min="' + minDate + '" max="' + maxDate + '" value="' + s.pickup_date + '"><select id="bv-pt">';
		times.forEach(function (t) { h += '<option' + (s.pickup_time === t ? ' selected' : '') + '>' + t + '</option>'; });
		h += '</select></div>';
		h += '<p class="bvbf-note">' + T.leadNote.replace('%h', leadHours()).replace('%d', (c.max_advance_days === undefined ? 365 : c.max_advance_days)) + '</p>';
		h += '<label>' + T.ret + '</label><div class="bvbf-row"><input type="date" id="bv-rd" min="' + minDate + '" value="' + s.return_date + '"><select id="bv-rt">';
		retTimes.forEach(function (t) { h += '<option' + (s.return_time === t ? ' selected' : '') + '>' + t + '</option>'; });
		h += '</select></div>';
		if (c.return_24h && !hourly) h += '<p class="bvbf-note">' + T.return24 + '</p>';
		h += '<button class="bvbf-btn" id="bv-check">' + T.check + '</button></div>';
		h += lookupBox();
		root.innerHTML = h;
		bindLookup();
		/*
		 * 通常の表示・再描画ではスクロールしない（AUTO_SCROLL の説明を参照）。
		 * ただしエラーはこの画面の先頭に出るため、そのときだけは見えるようにする。
		 * スクロールしないと、ボタンが画面外にある場合に「押しても何も起きない」
		 * ように見えてしまう。
		 */
		if (msg) scrollTop();

		/* 店舗を切り替えたら、その店舗の営業時間・クラスで描き直す */
		var stEl = document.getElementById('bv-store');
		if (stEl && stEl.tagName === 'SELECT') {
			stEl.addEventListener('change', function () {
				s.store = stEl.value;
				s.vehicle_class = document.getElementById('bv-class').value;
				s.pickup_date = document.getElementById('bv-pd').value;
				s.pickup_time = document.getElementById('bv-pt').value;
				s.return_date = document.getElementById('bv-rd').value;
				s.return_time = document.getElementById('bv-rt').value;
				renderStep1();
			});
		}

		/* 貸出日時を変えたら返却日時を自動調整 */
		var pdEl = document.getElementById('bv-pd'), ptEl = document.getElementById('bv-pt');
		var rdEl = document.getElementById('bv-rd'), rtEl = document.getElementById('bv-rt');
		/* 当日を選んだ場合、受付可能時刻より前を選べないようにする */
		function syncTimes() {
			var minT = minTimeFor(pdEl.value);
			var changed = false;
			Array.prototype.forEach.call(ptEl.options, function (o) {
				var disabled = !!(minT && o.value < minT);
				o.disabled = disabled;
				o.style.color = disabled ? '#bbb' : '';
			});
			if (minT && ptEl.value < minT) {
				for (var i = 0; i < ptEl.options.length; i++) {
					if (!ptEl.options[i].disabled) { ptEl.selectedIndex = i; changed = true; break; }
				}
				if (!changed) { /* 当日は受付不可 → 翌日へ */
					pdEl.value = addDays(pdEl.value, 1);
					syncTimes();
					return;
				}
			}
		}
		function syncReturn() {
			if (!pdEl.value) return;
			rdEl.min = pdEl.value;
			if (!rdEl.value || dt(rdEl.value, rtEl.value) <= dt(pdEl.value, ptEl.value)) {
				if (hourly) {
					/* 時間貸し：同日・2時間後を初期値に（営業終了を超えるなら閉店時刻） */
					var idx = times.indexOf(ptEl.value), ni = Math.min(times.length - 1, idx + 4);
					rdEl.value = pdEl.value;
					rtEl.value = (ni > idx) ? times[ni] : times[times.length - 1];
					if (rtEl.value === ptEl.value) { rdEl.value = addDays(pdEl.value, 1); }
				} else {
					rdEl.value = addDays(pdEl.value, 1);
					rtEl.value = ptEl.value;
				}
			}
		}
		pdEl.addEventListener('change', function () { syncTimes(); syncReturn(); });
		ptEl.addEventListener('change', syncReturn);
		syncTimes();
		syncReturn();

		document.getElementById('bv-check').addEventListener('click', function () {
			s.vehicle_class = document.getElementById('bv-class').value;
			/* 学割対象外のクラスに変えた場合は学割を外す */
			if (!studentAllowed()) s.is_student = false;
			s.store = document.getElementById('bv-store').value;
			s.pickup_date = document.getElementById('bv-pd').value;
			s.pickup_time = document.getElementById('bv-pt').value;
			s.return_date = document.getElementById('bv-rd').value;
			s.return_time = document.getElementById('bv-rt').value;
			if (!s.pickup_date || !s.return_date) return renderStep1(errBox(T.required));
			var e = earliestPickup();
			var pickIso = dt(s.pickup_date, s.pickup_time);
			var eIso = fmtDate(e) + ' ' + ('0' + e.getHours()).slice(-2) + ':' + ('0' + e.getMinutes()).slice(-2) + ':00';
			if (pickIso < eIso) return renderStep1(errBox(T.tooSoon.replace('%h', leadHours())));
            if (s.pickup_date > maxDate) return renderStep1(errBox(T.tooFar.replace('%d', (c.max_advance_days === undefined ? 365 : c.max_advance_days))));
			if (dt(s.return_date, s.return_time) <= dt(s.pickup_date, s.pickup_time)) return renderStep1(errBox(T.retAfter));
			this.textContent = T.checking; this.disabled = true;
			api('availability', {
				vehicle_class: s.vehicle_class, store: s.store,
				pickup_dt: dt(s.pickup_date, s.pickup_time), return_dt: dt(s.return_date, s.return_time)
			}, function (ok, j) {
				if (!ok) return renderStep1(errBox(j.message || T.err));
				if (j.available) { state.quote = j.quote || null; renderAvailable(); }
				else renderInquiry();
			});
		});
	}

	function renderAvailable() {
		var q = state.quote;
		var h = '<div class="bvbf-card bvbf-ok"><h3>' + T.available + '</h3>';
		if (q) h += '<p class="bvbf-total">' + T.estimate + ': <strong>' + money(q.total) + '</strong>（' + (q.hourly ? T.hours + ': ' + q.hours : T.days + ': ' + q.days) + '）</p>';
		h += flowHtml();
		h += '<button class="bvbf-btn bvbf-primary" id="bv-go2">' + T.proceed + '</button> ';
		h += '<button class="bvbf-btn bvbf-ghost" id="bv-back1">' + T.back + '</button></div>';
		root.innerHTML = h;
		scrollTop();
		document.getElementById('bv-go2').addEventListener('click', renderStep2);
		document.getElementById('bv-back1').addEventListener('click', function () { renderStep1(); });
	}

	/* ---------- 満車 → 問い合わせ ---------- */
	function renderInquiry(msg) {
		var s = state.sel;
		var h = '<div class="bvbf-card"><div class="bvbf-err">' + T.notAvailable + '</div>';
		h += '<h3>' + T.inquiryTitle + '</h3>' + (msg || '');
		h += '<label>' + T.name + '</label><input type="text" id="bv-iname">';
		h += '<label>' + T.email + '</label><input type="email" id="bv-iemail">';
		h += '<label>' + T.phone + '</label><input type="tel" id="bv-iphone">';
		h += '<label>' + T.message + '</label><textarea id="bv-imsg" rows="3"></textarea>';
		h += '<button class="bvbf-btn bvbf-primary" id="bv-isend">' + T.send + '</button> ';
		h += '<button class="bvbf-btn bvbf-ghost" id="bv-back1">' + T.back + '</button></div>';
		root.innerHTML = h;
		scrollTop();
		document.getElementById('bv-back1').addEventListener('click', function () { renderStep1(); });
		document.getElementById('bv-isend').addEventListener('click', function () {
			var email = document.getElementById('bv-iemail').value;
			if (!email) return renderInquiry(errBox(T.required));
			api('inquiry', {
				store: s.store, vehicle_class: s.vehicle_class,
				pickup_dt: dt(s.pickup_date, s.pickup_time), return_dt: dt(s.return_date, s.return_time),
				name: document.getElementById('bv-iname').value, email: email,
				phone: document.getElementById('bv-iphone').value, message: document.getElementById('bv-imsg').value
			}, function (ok, j) {
				if (ok) {
					root.innerHTML = '<div class="bvbf-card bvbf-ok"><p>' + T.sent + '</p><button class="bvbf-btn bvbf-primary" id="bv-backtop">' + T.backTop + '</button></div>';
					document.getElementById('bv-backtop').addEventListener('click', function () { renderStep1(); });
					scrollTop();
				} else {
					root.innerHTML = errBox(j.message || T.err);
				}
			});
		});
	}

	/* ---------- STEP 2 ---------- */
	function quoteParams() {
		var s = state.sel;
		var p = {
			vehicle_class: s.vehicle_class, store: s.store,
			pickup_dt: dt(s.pickup_date, s.pickup_time), return_dt: dt(s.return_date, s.return_time),
			coverage: s.coverage, shuttle: s.shuttle, is_student: s.is_student, coupon_code: s.coupon_code
		};
		/* 装備オプションを動的に付与 */
		Object.keys((state.config && state.config.equipment) || {}).forEach(function (k) {
			p['opt_' + k] = s['opt_' + k] || 0;
		});
		return p;
	}
	function refreshQuote(cb) {
		api('quote', quoteParams(), function (ok, j) {
			if (ok) state.quote = j;
			var box = document.getElementById('bv-quote');
			if (box && state.quote) box.innerHTML = quoteHtml();
			if (cb) cb(ok, j);
		});
	}
	function quoteHtml() {
		var q = state.quote;
		if (!q) return '';
		var h = '<h4>' + T.breakdown + '</h4><table class="bvbf-table">';
		(q.lines || []).forEach(function (l) {
			h += '<tr><td>' + l.label + '</td><td class="bvbf-r">' + money(l.amount) + '</td></tr>';
		});
		h += '<tr class="bvbf-totalrow"><td>' + T.total + '</td><td class="bvbf-r"><strong>' + money(q.total) + '</strong></td></tr></table>';
		if (q.coupon_error) h += '<div class="bvbf-err">' + q.coupon_error + '</div>';
		else if (q.discount > 0) h += '<div class="bvbf-okmsg">' + T.couponOk + '（-' + money(q.discount) + '）</div>';
		if (state.sel.shuttle !== 'none') h += '<div class="bvbf-note">※ ' + T.shuttleNote + '</div>';
		return h;
	}
	function renderStep2() {
		var c = state.config, s = state.sel;
		var h = '<div class="bvbf-card"><h3>' + T.step2 + '</h3>';
		h += '<h4>' + T.equip + '</h4>';
		Object.keys(c.equipment || {}).forEach(function (k) {
			var eq = c.equipment[k];
			var max = eq.max ? +eq.max : 1;
			var key = 'opt_' + k;
			if (s[key] === undefined) s[key] = 0;
			var note = (LANG === 'en') ? (eq.note_en || '') : (eq.note_ja || '');
			h += '<div class="bvbf-opt"><span>' + eq[LANG] + '（' + money(eq.price) + T.perDay + '）';
			if (note) h += '<br><small class="bvbf-optnote">' + note + '</small>';
			h += '</span><select data-opt="' + key + '">';
			for (var i = 0; i <= max; i++) h += '<option value="' + i + '"' + (s[key] === i ? ' selected' : '') + '>' + i + '</option>';
			h += '</select></div>';
		});
		h += '<h4>' + T.coverage + '</h4>';
		var covKeys = allowedCoverages();
		if (covKeys.indexOf(s.coverage) === -1) s.coverage = covKeys[covKeys.length - 1];
		covKeys.forEach(function (k) {
			var cov = c.coverages[k];
			var p = cov.price > 0 ? money(cov.price) + T.perDay : T.free;
			if (isHourly() && c.hourly_rates) {
				var hp = (k === 'B') ? c.hourly_rates.cov_b : ((k === 'C') ? c.hourly_rates.cov_c : 0);
				p = hp > 0 ? money(hp) + T.perHour : T.free;
			}
			h += '<label class="bvbf-radio"><input type="radio" name="bv-cov" value="' + k + '"' + (s.coverage === k ? ' checked' : '') + '> ' + cov[LANG] + '（' + p + '）</label>';
		});
		if (isHourly()) {
			var capTxt = T.covNoCap;
			var dc = (c.hourly_rates && c.hourly_rates.day_cap) ? +c.hourly_rates.day_cap : 0;
			if (dc > 0) capTxt = capTxt.replace(LANG === 'en' ? 'The daily cap' : '1日あたりの上限',
				(LANG === 'en' ? 'The daily cap (' + money(dc) + ')' : '1日あたりの上限（' + money(dc) + '）'));
			h += '<p class="bvbf-note" style="background:#fff8e5;border:1px solid #e0b900;color:#6b5200;padding:8px 10px;border-radius:6px">' + capTxt + '</p>';
		}
		/* 送迎を扱わない店舗では選択欄そのものを出さない */
		if (shuttleAllowed()) {
			h += '<h4>' + T.shuttle + '</h4><select id="bv-shuttle">';
			Object.keys(c.shuttles).forEach(function (k) {
				h += '<option value="' + k + '"' + (s.shuttle === k ? ' selected' : '') + '>' + c.shuttles[k][LANG] + '</option>';
			});
			h += '</select><div id="bv-shdet" style="display:' + (s.shuttle !== 'none' ? 'block' : 'none') + '">';
			h += '<div class="bvbf-okmsg" style="background:#fff8e5;border-color:#e0b900;color:#6b5200">' + T.shuttleRequestNote + '</div>';
			h += '<label>' + T.shuttleDetail + ' <span style="color:#d63638">*</span></label><textarea id="bv-shdetail" rows="3" placeholder="' + T.shuttleDetailPh + '">' + (s.shuttle_detail || '') + '</textarea>';
			h += '<div id="bv-sherr"></div></div>';
		} else {
			s.shuttle = 'none';
			s.shuttle_detail = '';
		}
		/* 学割は対象クラス（軽自動車など）を選んでいるときだけ表示する */
		if (studentAllowed()) {
			h += '<label class="bvbf-radio" style="margin-top:10px"><input type="checkbox" id="bv-student"' + (s.is_student ? ' checked' : '') + '> ' + T.student + '</label>';
		}
		h += '<label>' + T.coupon + '</label><div class="bvbf-row"><input type="text" id="bv-coupon" class="bvbf-coupon" value="' + (s.coupon_code || '') + '" placeholder="COUPON2026"><button class="bvbf-btn" id="bv-applycoupon" type="button">' + T.applyCoupon + '</button></div>';
		h += '<label>' + T.request + '</label><textarea id="bv-request" rows="2">' + (s.request_note || '') + '</textarea>';
		h += '<div id="bv-quote">' + quoteHtml() + '</div>';
		if (isImmediate()) {
			h += '<div class="bvbf-okmsg" style="background:#fff8e5;border-color:#e0b900;color:#6b5200;margin-top:10px">' +
				T.immediateNote.replace('%t', leadLabel(c.immediate_pay_hours === undefined ? 168 : c.immediate_pay_hours))
					.replace('%m', (c.immediate_hold_minutes === undefined ? 30 : c.immediate_hold_minutes)) + '</div>';
		}
		h += policyHtml();
		h += '<button class="bvbf-btn bvbf-primary" id="bv-go3">' + T.next + '</button> ';
		h += '<button class="bvbf-btn bvbf-ghost" id="bv-back2">' + T.back + '</button></div>';
		root.innerHTML = h;
		scrollTop();

		root.querySelectorAll('[data-opt]').forEach(function (sel) {
			sel.addEventListener('change', function () { s[this.dataset.opt] = parseInt(this.value, 10); refreshQuote(); });
		});
		root.querySelectorAll('input[name=bv-cov]').forEach(function (r) {
			r.addEventListener('change', function () { s.coverage = this.value; refreshQuote(); });
		});
		var shEl = document.getElementById('bv-shuttle'); /* 送迎を扱わない店舗では存在しない */
		if (shEl) shEl.addEventListener('change', function () {
			s.shuttle = this.value;
			document.getElementById('bv-shdet').style.display = s.shuttle !== 'none' ? 'block' : 'none';
			refreshQuote();
		});
		var stEl = document.getElementById('bv-student');
		if (stEl) stEl.addEventListener('change', function () { s.is_student = this.checked; refreshQuote(); });
		document.getElementById('bv-applycoupon').addEventListener('click', function () {
			s.coupon_code = document.getElementById('bv-coupon').value.trim(); refreshQuote();
		});
		document.getElementById('bv-back2').addEventListener('click', renderAvailable);
		document.getElementById('bv-go3').addEventListener('click', function () {
			s.shuttle_detail = ((document.getElementById('bv-shdetail') || {}).value || '').trim();
			/* 送迎ありの場合は詳細場所を必須にする */
			if (s.shuttle !== 'none' && !s.shuttle_detail) {
				var box = document.getElementById('bv-sherr');
				if (box) {
					box.innerHTML = errBox(T.shuttleDetailRequired);
					var el2 = document.getElementById('bv-shdetail');
					if (el2) {
						try { el2.focus({ preventScroll: !AUTO_SCROLL }); } catch (e) { el2.focus(); }
						scrollToEl(el2);
					}
				}
				return;
			}
			var errBoxEl = document.getElementById('bv-sherr');
			if (errBoxEl) errBoxEl.innerHTML = '';
			s.request_note = document.getElementById('bv-request').value;
			s.coupon_code = document.getElementById('bv-coupon').value.trim();
			refreshQuote(function () { renderStep3(); });
		});
		if (!state.quote) refreshQuote();
	}

	/* ---------- STEP 3 ---------- */
	function renderStep3(msg) {
		var h = '<div class="bvbf-card"><h3>' + T.step3 + '</h3>' + (msg || '');
		/* 会員ログイン（2回目以降） */
		h += '<div class="bvbf-login"><h4 style="margin-top:0">' + T.memberLoginTitle + '</h4>';
		h += '<p class="bvbf-note">' + T.memberLoginNote + '</p>';
		h += '<label>' + T.email + '</label><input type="email" id="bv-lemail" autocomplete="email">';
		h += '<label>' + T.password + '</label><input type="password" id="bv-lpass" autocomplete="current-password">';
		h += '<button class="bvbf-btn" id="bv-login">' + T.memberLogin + '</button>';
		h += '<div id="bv-loginmsg"></div></div>';
		h += '<h4>' + T.firstTimeTitle + '</h4>';
		h += '<p class="bvbf-note">' + T.emailFirst + '</p>';
		h += '<label>' + T.email + '</label><input type="email" id="bv-email" autocomplete="email">';
		h += '<button class="bvbf-btn" id="bv-sendotp">' + T.sendOtp + '</button>';
		h += '<div id="bv-otpzone"></div><div id="bv-details"></div></div>';
		root.innerHTML = h;
		scrollTop();

		document.getElementById('bv-login').addEventListener('click', function () {
			var email = document.getElementById('bv-lemail').value.trim();
			var pass = document.getElementById('bv-lpass').value;
			var box = document.getElementById('bv-loginmsg');
			if (!email || !pass) { box.innerHTML = errBox(T.required); return; }
			var btn = this; btn.disabled = true; btn.textContent = T.loggingIn;
			api('member_login', { email: email, password: pass }, function (ok, j) {
				btn.disabled = false; btn.textContent = T.memberLogin;
				if (!ok || !j.token) { box.innerHTML = errBox(j.message || T.err); return; }
				state.otpToken = j.token;
				state.email = j.email;
				state.member = j;
				box.innerHTML = '<div class="bvbf-okmsg">✔ ' + T.welcomeBack.replace('%s', (j.sei || '') + ' ' + (j.mei || '')) + '</div>';
				document.getElementById('bv-lemail').readOnly = true;
				document.getElementById('bv-lpass').style.display = 'none';
				btn.style.display = 'none';
				renderDetails(j);
				scrollToEl(document.getElementById('bv-details'));
			});
		});

		document.getElementById('bv-sendotp').addEventListener('click', function () {
			var email = document.getElementById('bv-email').value.trim();
			if (!email) return;
			var btn = this; btn.disabled = true;
			api('otp_send', { email: email, store: state.sel.store }, function (ok, j) {
				btn.disabled = false;
				var z = document.getElementById('bv-otpzone');
				if (!ok) { z.innerHTML = errBox(j.message || T.err); return; }
				z.innerHTML = '<div class="bvbf-okmsg">' + T.otpSent + '</div><label>' + T.otpCode + '</label><div class="bvbf-row"><input type="text" id="bv-otp" inputmode="numeric" maxlength="6"><button class="bvbf-btn" id="bv-verify">' + T.verify + '</button></div>';
				document.getElementById('bv-verify').addEventListener('click', function () {
					api('otp_verify', { email: email, code: document.getElementById('bv-otp').value }, function (ok2, j2) {
						var z2 = document.getElementById('bv-otpzone');
						if (!ok2 || !j2.token) { z2.insertBefore(el(errBox(j2.message || T.err)), z2.firstChild); return; }
						state.otpToken = j2.token;
						state.email = email;
						z2.innerHTML = '<div class="bvbf-okmsg">✔ ' + T.verified + '</div>';
						document.getElementById('bv-email').readOnly = true;
						document.getElementById('bv-sendotp').style.display = 'none';
						renderDetails();
					});
				});
			});
		});
	}

	function fileInput(id, label) {
		return '<label>' + label + '</label><input type="file" id="' + id + '" accept="image/jpeg,image/png,image/webp,application/pdf">';
	}
	function readFile(id, key, cb) {
		var inp = document.getElementById(id);
		if (!inp || !inp.files || !inp.files[0]) return cb(true);
		var f = inp.files[0];
		if (f.size > 8 * 1024 * 1024) return cb(false);
		var r = new FileReader();
		r.onload = function () { state.files[key] = r.result; cb(true); };
		r.onerror = function () { cb(false); };
		r.readAsDataURL(f);
	}

	function renderDetails(m) {
		m = m || null;
		function v(x) { return x ? String(x).replace(/"/g, '&quot;') : ''; }
		var bY = '', bM = '', bD = '';
		if (m && m.birthdate) { var bp = m.birthdate.split('-'); bY = +bp[0]; bM = +bp[1]; bD = +bp[2]; }

		var h = '<hr>';
		if (m) h += '<div class="bvbf-okmsg">' + T.prefilled + '</div>';
		h += '<div class="bvbf-row"><div style="flex:1"><label>' + T.sei + '</label><input type="text" id="bv-sei" autocomplete="family-name" value="' + v(m && m.sei) + '"></div>';
		h += '<div style="flex:1"><label>' + T.mei + '</label><input type="text" id="bv-mei" autocomplete="given-name" value="' + v(m && m.mei) + '"></div></div>';
		h += '<label>' + T.phone + '</label><input type="tel" id="bv-phone" autocomplete="tel" value="' + v(m && m.phone) + '">';
		h += '<label>' + T.address + '</label><textarea id="bv-address" rows="2" autocomplete="street-address">' + (m && m.address ? m.address : '') + '</textarea>';
		h += '<label>' + T.birth + '</label><div class="bvbf-row">';
		var nowY = new Date().getFullYear();
		h += '<select id="bv-by"><option value="">' + T.year + '</option>';
		for (var y = nowY - 18; y >= nowY - 100; y--) h += '<option value="' + y + '"' + (bY === y ? ' selected' : '') + '>' + y + (LANG === 'ja' ? '年' : '') + '</option>';
		h += '</select><select id="bv-bm"><option value="">' + T.month + '</option>';
		for (var mo = 1; mo <= 12; mo++) h += '<option value="' + mo + '"' + (bM === mo ? ' selected' : '') + '>' + mo + (LANG === 'ja' ? '月' : '') + '</option>';
		h += '</select><select id="bv-bd"><option value="">' + T.day + '</option>';
		for (var dd = 1; dd <= 31; dd++) h += '<option value="' + dd + '"' + (bD === dd ? ' selected' : '') + '>' + dd + (LANG === 'ja' ? '日' : '') + '</option>';
		h += '</select></div>';
		if (m && m.has_license) {
			h += '<div class="bvbf-okmsg">' + T.licenseOnFile + '</div>';
		}
		var fileLabelSuffix = (m && m.has_license) ? T.licenseOptional : '';
		if (LANG === 'en') {
			h += fileInput('bv-f1', T.passport + fileLabelSuffix) + fileInput('bv-f2', T.intl + fileLabelSuffix);
		} else {
			h += fileInput('bv-f1', T.licenseFront + fileLabelSuffix) + fileInput('bv-f2', T.licenseBack + fileLabelSuffix);
		}
		if (!m) {
			h += '<label>' + T.memberPass + '</label><input type="password" id="bv-mpass" minlength="8" autocomplete="new-password">';
			h += '<p class="bvbf-note">' + T.memberNote + '</p>';
		}
		h += finalNoticeHtml();
		h += '<div id="bv-suberr"></div>';
		h += '<button class="bvbf-btn bvbf-primary" id="bv-submit">' + (isImmediate() ? T.submitNow : T.submit) + '</button>';
		document.getElementById('bv-details').innerHTML = h;

		document.getElementById('bv-submit').addEventListener('click', function () {
			var btn = this;
			var required = { 'bv-sei': T.sei, 'bv-mei': T.mei, 'bv-phone': T.phone, 'bv-address': T.address };
			if (!m) required['bv-mpass'] = T.memberPass;
			for (var id in required) {
				var v = document.getElementById(id).value.trim();
				if (!v || (id === 'bv-mpass' && v.length < 8)) {
					document.getElementById('bv-suberr').innerHTML = errBox(T.required + ': ' + required[id]);
					return;
				}
			}
			var by = document.getElementById('bv-by').value, bm = document.getElementById('bv-bm').value, bdd = document.getElementById('bv-bd').value;
			if (!by || !bm || !bdd) {
				document.getElementById('bv-suberr').innerHTML = errBox(T.required + ': ' + T.birth);
				return;
			}
			var bCheck = new Date(+by, +bm - 1, +bdd);
			if (bCheck.getDate() !== +bdd || bCheck.getMonth() !== +bm - 1) {
				document.getElementById('bv-suberr').innerHTML = errBox(T.birthInvalid);
				return;
			}
			var birthdate = by + '-' + ('0' + bm).slice(-2) + '-' + ('0' + bdd).slice(-2);
			/* 貸出日時点の年齢を確認（中央側でも同じ判定を行うため、ここを迂回しても通らない） */
			if (!ageOk(birthdate)) {
				document.getElementById('bv-suberr').innerHTML = errBox(ageText('ageTooYoung'));
				return;
			}
			btn.disabled = true; btn.textContent = T.submitting;
			readFile('bv-f1', LANG === 'en' ? 'passport' : 'license_front', function (ok1) {
				if (!ok1) { btn.disabled = false; btn.textContent = T.submit; document.getElementById('bv-suberr').innerHTML = errBox(T.fileBig); return; }
				readFile('bv-f2', LANG === 'en' ? 'intl_license' : 'license_back', function (ok2) {
					if (!ok2) { btn.disabled = false; btn.textContent = T.submit; document.getElementById('bv-suberr').innerHTML = errBox(T.fileBig); return; }
					var s = state.sel;
					var payload = Object.assign(quoteParams(), state.files, {
						email: state.email, otp_token: state.otpToken,
						sei: document.getElementById('bv-sei').value.trim(),
						mei: document.getElementById('bv-mei').value.trim(),
						phone: document.getElementById('bv-phone').value.trim(),
						address: document.getElementById('bv-address').value.trim(),
						birthdate: birthdate,
						member_password: m ? '' : document.getElementById('bv-mpass').value,
						shuttle_detail: s.shuttle_detail, request_note: s.request_note
					});
					api('reserve', payload, function (ok, j) {
						if (!ok || !j.code) {
							btn.disabled = false; btn.textContent = T.submit;
							document.getElementById('bv-suberr').innerHTML = errBox(j.message || T.err);
							return;
						}
						var d = '<div class="bvbf-card bvbf-ok"><h3>' + T.done + '</h3><p>' + (isImmediate() ? T.doneMsgNow : T.doneMsg) + '</p>';
						d += '<p class="bvbf-total">' + T.resNo + ': <strong>' + j.code + '</strong><br>' + T.total + ': <strong>' + money(j.total) + '</strong></p>';
						if (j.immediate) {
							d += '<div id="bv-countdown" style="background:#fff4f4;border:2px solid #d63638;border-radius:8px;padding:14px;margin:12px 0;text-align:center">'
								+ '<div style="font-size:13px;color:#8a1f21;margin-bottom:4px">' + T.cdTitle + '</div>'
								+ '<div id="bv-cd-clock" style="font-size:40px;font-weight:700;color:#b32d2e;line-height:1.1;font-variant-numeric:tabular-nums">--:--</div>'
								+ '<div id="bv-cd-note" style="font-size:13px;color:#8a1f21;margin-top:6px">' + (j.message || '') + '</div>'
								+ '</div>';
						} else {
							d += '<p>' + (j.message || '') + '</p>';
						}
						if (j.pay_url) d += '<p><a class="bvbf-btn bvbf-primary" id="bv-paybtn" href="' + j.pay_url + '">' + T.payNow + '</a></p>';
						if (j.manage_url) d += '<p><a href="' + j.manage_url + '">' + T.manage + '</a></p>';
						d += '</div>';
						root.innerHTML = d;
						scrollTop();
						if (j.immediate && j.pay_deadline) startCountdown(j.pay_deadline, j.now);
					});
				});
			});
		});
	}

	/* ---------- boot (v1.0.1) ---------- */
	root.innerHTML = '<div class="bvbf-card">' + (LANG === 'en' ? 'Loading…' : '読み込み中…') + '</div>';
	api('config', {}, function (ok, j) {
		if (!ok || !j.classes) { root.innerHTML = errBox(T.err); return; }
		state.config = j;
		renderStep1();
	});
})();
