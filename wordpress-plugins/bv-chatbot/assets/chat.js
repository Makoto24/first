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
			error: '通信できませんでした。時間をおいてお試しください。'
		},
		en: {
			open: 'Questions?', close: 'Close', send: 'Send', placeholder: 'Type your question',
			reset: 'New chat', thinking: 'Writing a reply…',
			note: 'Automated replies by AI. Please confirm details on the booking form or with our staff. Do not enter personal information (license or card numbers).',
			error: 'Could not connect. Please try again later.'
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
			'<div class="bvcb-log" aria-live="polite"></div>' +
			'<form class="bvcb-form"><textarea rows="2" maxlength="1000"></textarea><button type="submit" class="bvcb-send"></button></form>' +
			'<div class="bvcb-note"></div>';
		panel.querySelector('.bvcb-title').textContent = CFG.botName || '';
		panel.querySelector('.bvcb-reset').textContent = T.reset;
		panel.querySelector('.bvcb-send').textContent = T.send;
		panel.querySelector('textarea').setAttribute('placeholder', T.placeholder);
		panel.querySelector('.bvcb-note').textContent = T.note;
		this.panel = panel;
		this.log = panel.querySelector('.bvcb-log');
		this.input = panel.querySelector('textarea');

		if (this.mode === 'floating') {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'bvcb-launcher';
			btn.textContent = T.open;
			btn.addEventListener('click', function () { self.toggle(true); });
			var cl = panel.querySelector('.bvcb-close');
			cl.textContent = '×';
			cl.setAttribute('aria-label', T.close);
			cl.addEventListener('click', function () { self.toggle(false); });
			this.launcher = btn;
			this.host.appendChild(btn);
			panel.hidden = true;
		}
		this.host.appendChild(panel);

		panel.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); self.send(); });
		this.input.addEventListener('keydown', function (e) {
			/* Enterで送信、Shift+Enterで改行（日本語入力の確定中は送らない） */
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); self.send(); }
		});
		panel.querySelector('.bvcb-reset').addEventListener('click', function () {
			self.state = { lang: self.lang, token: '', msgs: [] };
			save(self.state);
			self.log.innerHTML = '';
			self.start();
		});

		this.state.msgs.forEach(function (m) { self.add(m.who, m.text, false); });
		if (this.mode !== 'floating') this.start();
	};

	Chat.prototype.toggle = function (open) {
		this.panel.hidden = !open;
		this.launcher.hidden = open;
		if (open) { this.start(); this.input.focus(); }
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
		this.log.scrollTop = this.log.scrollHeight;
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
		this.add('user', q, true);
		var wait = this.add('bot', this.T.thinking, false);
		wait.classList.add('bvcb-wait');

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
			self.input.focus();
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
