/*
 * BV Chatbot：お問い合わせチャットの画面
 * 回答は文字列として表示し（HTMLとしては解釈しない）、http(s) のURLだけをリンクにする。
 */
(function () {
	'use strict';
	if (typeof window.BVCB === 'undefined') return;
	var CFG = window.BVCB;

	var TXT = {
		ja: {
			open: 'お問い合わせ', close: '閉じる', send: '送信', placeholder: 'ご質問を入力してください',
			reset: '新しい会話', thinking: '回答を作成しています…',
			note: 'AIによる自動応答です。正確な内容は予約フォームや店舗でご確認ください。個人情報（免許証番号・カード番号など）は入力しないでください。',
			error: '通信できませんでした。時間をおいてお試しください。',
			chips: ['空車を確認したい', '料金を知りたい', '営業時間と場所', 'キャンセルについて']
		},
		en: {
			open: 'Questions?', close: 'Close', send: 'Send', placeholder: 'Type your question',
			reset: 'New chat', thinking: 'Writing a reply…',
			note: 'Automated replies by AI. Please confirm details on the booking form or with our staff. Do not enter personal information (license or card numbers).',
			error: 'Could not connect. Please try again later.',
			chips: ['Check availability', 'Prices', 'Opening hours & location', 'Cancellation']
		}
	};

	/* ---- 表示用の文字列処理 ---- */

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	/** 回答をHTMLにする：エスケープ → URLをリンク化 → 改行 */
	function format(text) {
		var s = String(text).replace(/\*\*(.+?)\*\*/g, '$1'); /* 太字の記号が混ざっても消す */
		var out = '', last = 0, re = /https?:\/\/[^\s<>"'）)」』、。]+/g, m;
		while ((m = re.exec(s)) !== null) {
			out += esc(s.slice(last, m.index));
			var url = m[0].replace(/[.,!?]+$/, '');
			out += '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + esc(url) + '</a>';
			last = m.index + url.length;
			re.lastIndex = last;
		}
		out += esc(s.slice(last));
		return out.replace(/\n/g, '<br>');
	}

	/* ---- 保存（ページを移動しても会話を続けられるように。閉じたタブには残さない） ---- */

	var STORE = 'bvcb_state_v1';
	function load() {
		try { return JSON.parse(window.sessionStorage.getItem(STORE)) || null; } catch (e) { return null; }
	}
	function save(st) {
		try { window.sessionStorage.setItem(STORE, JSON.stringify(st)); } catch (e) { /* 保存できなくても動く */ }
	}

	function api(path, body) {
		return fetch(CFG.api + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'omit',
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (j) { return { status: r.status, json: j }; }, function () { return { status: r.status, json: {} }; });
		});
	}

	function pageLang(el) {
		var l = el.getAttribute('data-lang') || CFG.lang || 'ja';
		var html = (document.documentElement.getAttribute('lang') || '').toLowerCase();
		if (!el.hasAttribute('data-lang-fixed') && html.indexOf('en') === 0) l = 'en';
		return l === 'en' ? 'en' : 'ja';
	}

	/* ---- スマホ向けの判定 ---- */

	/** 指で操作する端末（スマホ・タブレット）。キーボードが画面を覆うので、自動でフォーカスしない */
	function isTouch() {
		return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
	}
	/** 右下のボタンから開いたチャットを全画面にする幅 */
	function isSmall() {
		return !!(window.matchMedia && window.matchMedia('(max-width: 600px)').matches);
	}

	/* ---- チャット本体 ---- */

	function Chat(host, mode) {
		this.host = host;
		this.mode = mode;
		this.lang = pageLang(host);
		this.T = TXT[this.lang];
		var st = load();
		this.state = (st && st.lang === this.lang) ? st : { lang: this.lang, token: '', msgs: [] };
		this.busy = false;
		this.build();
	}

	Chat.prototype.build = function () {
		var T = this.T, self = this;
		var panel = document.createElement('div');
		panel.className = 'bvcb-panel' + (this.mode === 'floating' ? ' bvcb-floating-panel' : '');
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', CFG.botName || 'Chat');
		panel.innerHTML =
			'<div class="bvcb-head"><span class="bvcb-title"></span>' +
			'<button type="button" class="bvcb-reset"></button>' +
			(this.mode === 'floating' ? '<button type="button" class="bvcb-close" aria-label=""></button>' : '') + '</div>' +
			'<div class="bvcb-log" aria-live="polite"><div class="bvcb-note"></div></div>' +
			'<div class="bvcb-chips"></div>' +
			'<form class="bvcb-form"><textarea rows="1" maxlength="1000" enterkeyhint="send"></textarea>' +
			'<button type="submit" class="bvcb-send"></button></form>';
		panel.querySelector('.bvcb-title').textContent = CFG.botName || '';
		panel.querySelector('.bvcb-reset').textContent = T.reset;
		panel.querySelector('.bvcb-send').textContent = T.send;
		panel.querySelector('textarea').setAttribute('placeholder', T.placeholder);
		panel.querySelector('.bvcb-note').textContent = T.note;
		this.panel = panel;
		this.log = panel.querySelector('.bvcb-log');
		this.input = panel.querySelector('textarea');
		this.chips = panel.querySelector('.bvcb-chips');
		T.chips.forEach(function (c) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'bvcb-chip';
			b.textContent = c;
			b.addEventListener('click', function () { self.input.value = c; self.send(); });
			self.chips.appendChild(b);
		});

		if (this.mode === 'floating') {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'bvcb-launcher';
			btn.textContent = T.open;
			btn.addEventListener('click', function () { self.toggle(true); });
			var cl = panel.querySelector('.bvcb-close');
			cl.textContent = '×';
			cl.setAttribute('aria-label', T.close);
			cl.addEventListener('click', function () { self.close(); });
			/* スマホの「戻る」でチャットを閉じる（ページは移動しない） */
			window.addEventListener('popstate', function () { if (!self.panel.hidden) self.toggle(false); });
			/* Escキーで閉じる */
			panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') self.close(); });
			this.launcher = btn;
			this.host.appendChild(btn);
			panel.hidden = true;
		}
		this.host.appendChild(panel);

		panel.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); self.send(); });
		this.input.addEventListener('input', function () { self.grow(); });
		this.input.addEventListener('keydown', function (e) {
			/* Enterで送信、Shift+Enterで改行（日本語入力の確定中は送らない） */
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); self.send(); }
		});
		panel.querySelector('.bvcb-reset').addEventListener('click', function () {
			self.state = { lang: self.lang, token: '', msgs: [] };
			save(self.state);
			self.log.querySelectorAll('.bvcb-msg').forEach(function (m) { m.remove(); });
			self.updateChips();
			self.start();
		});

		this.state.msgs.forEach(function (m) { self.add(m.who, m.text, false); });
		this.updateChips();
		if (this.mode !== 'floating') this.start();
	};

	Chat.prototype.toggle = function (open) {
		this.panel.hidden = !open;
		this.launcher.hidden = open;
		var full = open && isSmall();
		/* 全画面のあいだは、後ろのページがスクロールしないようにする */
		this.lockPage(full);
		this.fitViewport(full);
		if (open) {
			if (isSmall() && !(window.history.state && window.history.state.bvcb)) {
				try {
					if ('scrollRestoration' in window.history) window.history.scrollRestoration = 'manual';
					window.history.pushState({ bvcb: 1 }, '');
				} catch (e) { /* 履歴が使えなくても動く */ }
			}
			this.start();
			this.log.scrollTop = this.log.scrollHeight;
			if (!isTouch()) this.input.focus();
		}
	};

	/** ×ボタン：戻る用の履歴を積んでいれば、それを消費して閉じる */
	Chat.prototype.close = function () {
		if (window.history.state && window.history.state.bvcb) window.history.back();
		else this.toggle(false);
	};

	/**
	 * 後ろのページを固定する。iPhoneは overflow:hidden だけでは入力時にページが動くため、
	 * body を今のスクロール位置のまま固定し、閉じたときに元の位置へ戻す。
	 */
	Chat.prototype.lockPage = function (on) {
		var html = document.documentElement, body = document.body;
		if (on && !this._lockedY && !html.classList.contains('bvcb-lock')) {
			this._lockedY = window.pageYOffset || 0;
			html.classList.add('bvcb-lock');
			body.style.top = -this._lockedY + 'px';
		} else if (!on && html.classList.contains('bvcb-lock')) {
			var y = this._lockedY || 0;
			html.classList.remove('bvcb-lock');
			body.style.top = '';
			this._lockedY = 0;
			window.scrollTo(0, y);
			/* 「戻る」で閉じたとき、ブラウザのスクロール位置の復元に上書きされないよう、もう一度戻す */
			setTimeout(function () { window.scrollTo(0, y); }, 0);
		}
	};

	/**
	 * スマホでキーボードが出たとき、見えている範囲（visualViewport）に合わせて高さを変え、
	 * 入力欄がキーボードの後ろに隠れないようにする。
	 * キーボードが閉じたら画面いっぱいに戻す。iPhoneはキーボードが閉じきる前に最後の通知が来ることがあるため、
	 * 少し時間をおいて何度か測り直す（送信直後に背景のページが見えてしまう崩れの対策）。
	 */
	Chat.prototype.fitViewport = function (on) {
		var vv = window.visualViewport, self = this;
		if (!vv) return;
		if (!this._fit) {
			this._fit = function () {
				if (self.panel.hidden) return;
				var keyboard = vv.height < window.innerHeight - 80;
				if (keyboard) {
					self.panel.style.height = vv.height + 'px';
					self.panel.style.top = vv.offsetTop + 'px';
				} else {
					self.panel.style.height = '';
					self.panel.style.top = '';
					if (window.pageYOffset) window.scrollTo(0, 0);
				}
			};
			this._settle = function () {
				clearTimeout(self._t1); clearTimeout(self._t2); clearTimeout(self._t3);
				self._fit();
				self._t1 = setTimeout(self._fit, 100);
				self._t2 = setTimeout(self._fit, 350);
				self._t3 = setTimeout(self._fit, 800);
			};
			this.input.addEventListener('focus', this._settle);
			this.input.addEventListener('blur', this._settle);
		}
		vv.removeEventListener('resize', this._fit);
		vv.removeEventListener('scroll', this._fit);
		window.removeEventListener('orientationchange', this._settle);
		if (on) {
			vv.addEventListener('resize', this._fit);
			vv.addEventListener('scroll', this._fit);
			window.addEventListener('orientationchange', this._settle);
			this._settle();
		} else {
			this.panel.style.height = '';
			this.panel.style.top = '';
		}
	};

	/** 入力欄を内容に合わせて1〜5行で伸ばす */
	Chat.prototype.grow = function () {
		var t = this.input;
		t.style.height = 'auto';
		t.style.height = Math.min(t.scrollHeight, 120) + 'px';
	};

	/** 最初の質問を送るまでは、よくある質問のボタンを出す */
	Chat.prototype.updateChips = function () {
		this.chips.hidden = this.state.msgs.some(function (m) { return m.who === 'user'; });
	};

	/** 会話IDの取得と、最初のあいさつ */
	Chat.prototype.start = function () {
		var self = this;
		if (this.state.token) return Promise.resolve();
		if (this.startP) return this.startP; /* 取得中なら同じ結果を待つ */
		this.startP = api('session', { lang: this.lang }).then(function (r) {
			self.startP = null;
			if (r.status !== 200 || !r.json.token) { self.add('bot', (r.json && r.json.message) || self.T.error, false); return; }
			self.state.token = r.json.token;
			if (!self.state.msgs.length && r.json.welcome) self.add('bot', r.json.welcome, true);
			save(self.state);
		}, function () { self.startP = null; self.add('bot', self.T.error, false); });
		return this.startP;
	};

	Chat.prototype.add = function (who, text, remember) {
		var d = document.createElement('div');
		d.className = 'bvcb-msg bvcb-' + who;
		d.innerHTML = format(text);
		this.log.appendChild(d);
		/* 長い回答は、読み始めの位置（回答の先頭）を見せる */
		if (who === 'bot' && d.offsetHeight > this.log.clientHeight * 0.6) {
			this.log.scrollTop = Math.max(0, d.offsetTop - 12);
		} else {
			this.log.scrollTop = this.log.scrollHeight;
		}
		if (remember) {
			this.state.msgs.push({ who: who, text: String(text) });
			if (this.state.msgs.length > 60) this.state.msgs = this.state.msgs.slice(-60);
			save(this.state);
		}
		return d;
	};

	Chat.prototype.send = function () {
		var self = this, q = this.input.value.trim();
		if (!q || this.busy) return;
		this.busy = true;
		this.input.value = '';
		this.grow();
		this.add('user', q, true);
		this.updateChips();
		/* スマホでは送信後にキーボードを閉じて、回答を広く見せる */
		if (isTouch()) this.input.blur();
		var wait = this.add('bot', '', false);
		wait.classList.add('bvcb-wait');
		wait.setAttribute('aria-label', this.T.thinking);
		wait.innerHTML = '<span class="bvcb-dot"></span><span class="bvcb-dot"></span><span class="bvcb-dot"></span>';

		var go = function () {
			return api('chat', { token: self.state.token, message: q, lang: self.lang });
		};
		this.start().then(go).then(function (r) {
			/* 会話IDの期限切れは、取り直して1回だけ送り直す */
			if (r.status === 401) {
				self.state.token = '';
				return self.start().then(go);
			}
			return r;
		}).then(function (r) {
			wait.remove();
			var text = (r.json && (r.json.reply || r.json.message)) || self.T.error;
			self.add('bot', text, r.status === 200);
		}, function () {
			wait.remove();
			self.add('bot', self.T.error, false);
		}).then(function () {
			self.busy = false;
			if (!isTouch()) self.input.focus();
		});
	};

	function boot() {
		var inline = document.querySelectorAll('[data-bvcb="inline"]');
		inline.forEach(function (el) { new Chat(el, 'inline'); });
		/* ページ内に埋め込んだチャットがあれば、右下のボタンは出さない */
		if (!inline.length) {
			var f = document.querySelector('[data-bvcb="floating"]');
			if (f) new Chat(f, 'floating');
		}
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();

	/* テスト用 */
	window.BVCB._format = format;
})();
