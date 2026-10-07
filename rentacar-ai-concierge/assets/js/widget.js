/**
 * レンタカー AIコンシェルジュ - チャット／問い合わせフォーム
 *
 * テーマの CSS と干渉しないよう Shadow DOM の中に描画する。
 * 会話トークンと表示中の会話はタブを閉じるまで sessionStorage に保持する（ページ移動しても続きから話せる）。
 */
(function () {
	'use strict';

	var CFG = window.RCAC_CONFIG;
	if (!CFG) {
		return;
	}
	var STORE_KEY = 'rcac_chat_v1';

	// ---------------------------------------------------------------- 保存
	function loadState() {
		try {
			var raw = window.sessionStorage.getItem(STORE_KEY);
			if (raw) {
				var s = JSON.parse(raw);
				if (s && Array.isArray(s.messages)) {
					return s;
				}
			}
		} catch (e) {}
		return { token: '', messages: [], open: false };
	}
	function saveState(state) {
		try {
			window.sessionStorage.setItem(STORE_KEY, JSON.stringify({
				token: state.token,
				messages: state.messages.slice(-60),
				open: state.open
			}));
		} catch (e) {}
	}

	// ---------------------------------------------------------------- DOM ヘルパー
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) {
					return;
				}
				if (k === 'text') {
					node.textContent = v;
				} else if (k === 'class') {
					node.className = v;
				} else if (k.indexOf('on') === 0) {
					node.addEventListener(k.slice(2), v);
				} else {
					node.setAttribute(k, v === true ? '' : v);
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c) {
				node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
			}
		});
		return node;
	}

	var ICON_CHAT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm3 6.5a1.5 1.5 0 1 0 0 .01Zm5 0a1.5 1.5 0 1 0 0 .01Zm5 0a1.5 1.5 0 1 0 0 .01Z"/></svg>';
	var ICON_CAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11h.5A1.5 1.5 0 0 1 21 12.5V17a1 1 0 0 1-1 1h-1a2 2 0 0 1-4 0H9a2 2 0 0 1-4 0H4a1 1 0 0 1-1-1v-4.5A1.5 1.5 0 0 1 4.5 11H5Zm2.1 0h9.8l-1.2-3.6a.6.6 0 0 0-.6-.4H8.9a.6.6 0 0 0-.6.4L7.1 11ZM7 15.5a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Zm10 0a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z"/></svg>';
	var ICON_CLOSE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.4 5 12 10.6 17.6 5 19 6.4 13.4 12l5.6 5.6-1.4 1.4L12 13.4 6.4 19 5 17.6 10.6 12 5 6.4Z"/></svg>';
	var ICON_RESET = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 5V2L7 6l5 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z"/></svg>';
	var ICON_SEND = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 20.5V14l9-2-9-2V3.5L22 12Z"/></svg>';

	function icon(svg) {
		var span = el('span', { class: 'icon' });
		span.innerHTML = svg; // 固定の SVG 文字列のみ
		return span;
	}

	/** ボットの回答を安全に表示する（HTML は解釈せず、URL だけリンクにする）。 */
	function renderText(target, text) {
		var re = /(https?:\/\/[^\s<>"'、。）)」]+)/g;
		var last = 0;
		var m;
		while ((m = re.exec(text)) !== null) {
			if (m.index > last) {
				target.appendChild(document.createTextNode(text.slice(last, m.index)));
			}
			target.appendChild(el('a', { href: m[1], target: '_blank', rel: 'noopener', text: m[1] }));
			last = m.index + m[1].length;
		}
		if (last < text.length) {
			target.appendChild(document.createTextNode(text.slice(last)));
		}
	}

	function post(path, body) {
		return fetch(CFG.restUrl + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'omit',
			body: JSON.stringify(body)
		}).then(function (res) {
			return res.json().catch(function () {
				return {};
			}).then(function (data) {
				return { ok: res.ok, status: res.status, data: data || {} };
			});
		});
	}

	// ---------------------------------------------------------------- ウィジェット
	function Widget(host, mode, state) {
		this.mode = mode; // floating | chat | form
		this.state = state;
		this.busy = false;
		this.draft = null;
		this.shownAt = Date.now();

		var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
		host.style.setProperty('--rcac-color', CFG.color || '#0b6bcb');
		var link = el('link', { rel: 'stylesheet', href: CFG.cssUrl });
		var wrap = el('div', { class: 'rcac mode-' + mode + ' pos-' + (CFG.position === 'left' ? 'left' : 'right'), hidden: true });
		link.addEventListener('load', function () { wrap.hidden = false; });
		link.addEventListener('error', function () { wrap.hidden = false; });
		root.appendChild(link);
		root.appendChild(wrap);
		this.wrap = wrap;

		if (mode === 'form') {
			wrap.appendChild(this.buildForm(false));
			return;
		}

		this.panel = this.buildPanel();
		wrap.appendChild(this.panel);

		if (mode === 'floating') {
			this.launcher = el('button', {
				class: 'launcher',
				type: 'button',
				'aria-label': CFG.botName + 'に質問する',
				'aria-expanded': 'false',
				onclick: this.toggle.bind(this)
			}, [icon(ICON_CHAT), el('span', { class: 'launcher-label', text: 'チャットで質問' })]);
			wrap.appendChild(this.launcher);
			// スマートフォンでは全画面表示になるため、ページを移動したときに自動では開かない
			this.setOpen(!!state.open && window.innerWidth > 600, false);
		}

		this.restoreMessages();
		if (!CFG.chatAvailable) {
			this.showView('form');
		}
	}

	Widget.prototype.buildPanel = function () {
		var self = this;
		var closeBtn = this.mode === 'floating'
			? el('button', { class: 'icon-btn', type: 'button', 'aria-label': '閉じる', onclick: function () { self.setOpen(false, true); } }, [icon(ICON_CLOSE)])
			: null;

		var header = el('div', { class: 'header' }, [
			el('div', { class: 'avatar' }, [icon(ICON_CAR)]),
			el('div', { class: 'title' }, [
				el('strong', { text: CFG.botName }),
				el('small', { text: 'AIが自動でお答えします' })
			]),
			el('button', { class: 'icon-btn', type: 'button', title: '新しい会話', 'aria-label': '新しい会話を始める', onclick: this.reset.bind(this) }, [icon(ICON_RESET)]),
			closeBtn
		]);

		this.tabChat = el('button', { class: 'tab', type: 'button', role: 'tab', 'aria-selected': 'true', text: 'チャット', onclick: function () { self.showView('chat'); } });
		this.tabForm = el('button', { class: 'tab', type: 'button', role: 'tab', 'aria-selected': 'false', text: 'お問い合わせ', onclick: function () { self.showView('form'); } });
		var tabs = el('div', { class: 'tabs', role: 'tablist' }, [this.tabChat, this.tabForm]);

		this.log = el('div', { class: 'log', role: 'log', 'aria-live': 'polite' });
		this.quick = el('div', { class: 'quick' });
		this.input = el('textarea', {
			rows: '1',
			placeholder: CFG.chatAvailable ? 'メッセージを入力' : 'チャットは準備中です',
			'aria-label': 'メッセージ',
			maxlength: String(CFG.maxChars || 800),
			disabled: !CFG.chatAvailable
		});
		this.input.addEventListener('keydown', function (e) {
			// 日本語入力の変換確定の Enter では送信しない
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
				e.preventDefault();
				self.send(self.input.value);
			}
		});
		this.input.addEventListener('input', function () {
			self.input.style.height = 'auto';
			self.input.style.height = Math.min(self.input.scrollHeight, 120) + 'px';
		});
		this.sendBtn = el('button', { class: 'send', type: 'submit', 'aria-label': '送信', disabled: !CFG.chatAvailable }, [icon(ICON_SEND)]);
		var composer = el('form', {
			class: 'composer',
			onsubmit: function (e) {
				e.preventDefault();
				self.send(self.input.value);
			}
		}, [this.input, this.sendBtn]);

		this.chatView = el('div', { class: 'view view-chat' }, [
			this.log,
			this.quick,
			composer,
			el('p', { class: 'note', text: 'AIの回答には誤りが含まれる場合があります。予約内容・料金は予約画面でご確認ください。' })
		]);
		this.formView = el('div', { class: 'view view-form', hidden: true }, [this.buildForm(true)]);

		return el('div', {
			class: 'panel',
			role: this.mode === 'floating' ? 'dialog' : 'region',
			'aria-label': CFG.botName
		}, [header, tabs, this.chatView, this.formView]);
	};

	Widget.prototype.showView = function (view) {
		var isChat = view === 'chat';
		this.chatView.hidden = !isChat;
		this.formView.hidden = isChat;
		this.tabChat.setAttribute('aria-selected', String(isChat));
		this.tabForm.setAttribute('aria-selected', String(!isChat));
		if (!isChat) {
			this.shownAt = Date.now();
			if (this.draft) {
				this.applyDraft(this.draft);
				this.draft = null;
			}
		}
	};

	Widget.prototype.toggle = function () {
		this.setOpen(!this.state.open, true);
	};

	Widget.prototype.setOpen = function (open, focus) {
		this.state.open = open;
		saveState(this.state);
		this.panel.classList.toggle('is-open', open);
		this.launcher.setAttribute('aria-expanded', String(open));
		this.launcher.classList.toggle('is-hidden', open);
		if (open && focus && !this.input.disabled && !this.chatView.hidden) {
			var input = this.input;
			setTimeout(function () { input.focus(); }, 50);
		}
		if (!open && focus) {
			this.launcher.focus();
		}
	};

	Widget.prototype.restoreMessages = function () {
		this.log.innerHTML = '';
		this.addBubble('bot', CFG.greeting, false);
		if (!CFG.chatAvailable) {
			this.addBubble('system', '現在チャットは準備中です。お手数ですが「お問い合わせ」タブのフォームからご連絡ください。', false);
		}
		var self = this;
		this.state.messages.forEach(function (m) {
			self.addBubble(m.role, m.text, false);
		});
		this.renderQuickReplies();
	};

	Widget.prototype.renderQuickReplies = function () {
		var self = this;
		this.quick.innerHTML = '';
		if (this.state.messages.length || !CFG.chatAvailable) {
			return;
		}
		(CFG.quickReplies || []).forEach(function (q) {
			self.quick.appendChild(el('button', { type: 'button', class: 'chip', text: q, onclick: function () { self.send(q); } }));
		});
	};

	Widget.prototype.addBubble = function (role, text, remember) {
		var bubble = el('div', { class: 'msg msg-' + role });
		var body = el('div', { class: 'bubble' });
		renderText(body, text);
		bubble.appendChild(body);
		this.log.appendChild(bubble);
		this.log.scrollTop = this.log.scrollHeight;
		if (remember) {
			this.state.messages.push({ role: role, text: text });
			saveState(this.state);
		}
		return bubble;
	};

	Widget.prototype.addActionCard = function (label, onClick) {
		var card = el('div', { class: 'msg msg-action' }, [
			el('button', { type: 'button', class: 'action-btn', text: label, onclick: onClick })
		]);
		this.log.appendChild(card);
		this.log.scrollTop = this.log.scrollHeight;
	};

	Widget.prototype.setBusy = function (busy) {
		this.busy = busy;
		this.sendBtn.disabled = busy || !CFG.chatAvailable;
		if (busy) {
			this.typing = el('div', { class: 'msg msg-bot typing', 'aria-label': '回答を作成中' }, [
				el('div', { class: 'bubble' }, [el('span', { class: 'dot' }), el('span', { class: 'dot' }), el('span', { class: 'dot' })])
			]);
			this.log.appendChild(this.typing);
			this.log.scrollTop = this.log.scrollHeight;
		} else if (this.typing) {
			this.typing.remove();
			this.typing = null;
		}
	};

	Widget.prototype.send = function (text) {
		var self = this;
		text = (text || '').trim();
		if (!text || this.busy || !CFG.chatAvailable) {
			return;
		}
		this.input.value = '';
		this.input.style.height = 'auto';
		this.quick.innerHTML = '';
		this.addBubble('user', text, true);
		this.setBusy(true);

		post('chat', { message: text, token: this.state.token, page: window.location.href })
			.then(function (res) {
				self.setBusy(false);
				var d = res.data;
				if (d.token) {
					self.state.token = d.token;
				}
				if (!res.ok || !d.reply) {
					self.addBubble('system', d.message || '通信エラーが発生しました。時間をおいて再度お試しください。', false);
					return;
				}
				self.addBubble('bot', d.reply, true);
				(d.actions || []).forEach(function (a) { self.handleAction(a); });
			})
			.catch(function () {
				self.setBusy(false);
				self.addBubble('system', '通信エラーが発生しました。電波の良い場所で再度お試しください。', false);
			});
	};

	Widget.prototype.handleAction = function (a) {
		var self = this;
		if (a.type === 'open_form') {
			this.draft = a;
			this.addActionCard('お問い合わせフォームに入力する', function () { self.showView('form'); });
		} else if (a.type === 'conversation_limit') {
			this.addActionCard('新しい会話を始める', this.reset.bind(this));
		}
	};

	Widget.prototype.reset = function () {
		this.draft = null;
		this.state.token = '';
		this.state.messages = [];
		saveState(this.state);
		this.restoreMessages();
		// 送信済みのフォームも新しい会話では入力できる状態に戻す
		this.formView.innerHTML = '';
		this.formView.appendChild(this.buildForm(true));
		this.showView(CFG.chatAvailable ? 'chat' : 'form');
	};

	// ---------------------------------------------------------------- 問い合わせフォーム
	Widget.prototype.buildForm = function (inPanel) {
		var self = this;
		var f = {};
		var idBase = 'rcac-' + Math.random().toString(36).slice(2, 8) + '-';

		function field(name, label, input, required, hint) {
			input.id = idBase + name;
			input.name = name;
			if (required) {
				input.required = true;
			}
			f[name] = input;
			var err = el('div', { class: 'field-error', id: idBase + name + '-err', 'aria-live': 'polite' });
			input.setAttribute('aria-describedby', err.id);
			return el('div', { class: 'field field-' + name }, [
				el('label', { for: input.id }, [label, required ? el('span', { class: 'req', text: '必須' }) : null]),
				input,
				hint ? el('div', { class: 'hint', text: hint }) : null,
				err
			]);
		}

		var typeSelect = el('select', {}, [el('option', { value: '', text: '選択してください' })]);
		(CFG.inquiryTypes || []).forEach(function (t) {
			typeSelect.appendChild(el('option', { value: t, text: t }));
		});

		var consent = null;
		if (CFG.privacyUrl) {
			var box = el('input', { type: 'checkbox', id: idBase + 'consent', name: 'consent', value: '1', required: true });
			f.consent = box;
			consent = el('div', { class: 'field field-consent' }, [
				el('label', { for: box.id, class: 'check' }, [
					box,
					el('span', {}, [
						el('a', { href: CFG.privacyUrl, target: '_blank', rel: 'noopener', text: 'プライバシーポリシー' }),
						'に同意する'
					])
				]),
				el('div', { class: 'field-error', id: idBase + 'consent-err' })
			]);
		}

		var honeypot = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', class: 'hp', 'aria-hidden': 'true' });
		f.website = honeypot;

		var status = el('div', { class: 'form-status', role: 'status' });
		var submit = el('button', { type: 'submit', class: 'submit', text: '送信する' });

		var form = el('form', { class: 'contact', novalidate: true }, [
			inPanel ? el('p', { class: 'form-lead', text: 'スタッフが内容を確認し、メールまたはお電話でご連絡します。' }) : null,
			field('name', 'お名前', el('input', { type: 'text', autocomplete: 'name' }), true),
			field('email', 'メールアドレス', el('input', { type: 'email', autocomplete: 'email', inputmode: 'email' }), true),
			field('phone', '電話番号', el('input', { type: 'tel', autocomplete: 'tel', inputmode: 'tel' }), false),
			field('inquiry_type', 'お問い合わせ種別', typeSelect, false),
			el('div', { class: 'row' }, [
				field('start', '貸出希望日時', el('input', { type: 'datetime-local' }), false),
				field('end', '返却希望日時', el('input', { type: 'datetime-local' }), false)
			]),
			field('vehicle', '希望の車種・クラス', el('input', { type: 'text' }), false),
			field('message', 'お問い合わせ内容', el('textarea', { rows: '5' }), true),
			consent,
			honeypot,
			status,
			submit
		]);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.submitForm(form, f, status, submit);
		});
		this.formFields = f;
		this.formEl = form;
		return el('div', { class: 'form-wrap' }, [form]);
	};

	Widget.prototype.applyDraft = function (a) {
		var f = this.formFields;
		if (!f) {
			return;
		}
		if (a.draft && !f.message.value.trim()) {
			f.message.value = a.draft;
		}
		if (a.inquiry_type && !f.inquiry_type.value) {
			f.inquiry_type.value = a.inquiry_type;
		}
	};

	Widget.prototype.submitForm = function (form, f, status, submit) {
		var self = this;
		Array.prototype.forEach.call(form.querySelectorAll('.field-error'), function (n) { n.textContent = ''; });
		Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (n) { n.removeAttribute('aria-invalid'); });

		var payload = {
			name: f.name.value,
			email: f.email.value,
			phone: f.phone.value,
			inquiry_type: f.inquiry_type.value,
			start: f.start.value,
			end: f.end.value,
			vehicle: f.vehicle.value,
			message: f.message.value,
			consent: f.consent ? f.consent.checked : true,
			website: f.website.value,
			token: this.state ? this.state.token : '',
			elapsed: Date.now() - this.shownAt
		};

		submit.disabled = true;
		status.textContent = '送信しています…';
		status.className = 'form-status';

		post('contact', payload).then(function (res) {
			submit.disabled = false;
			var d = res.data;
			if (res.ok && d.ok) {
				var done = el('div', { class: 'form-done', role: 'status' });
				renderText(done, d.message || '送信しました。');
				form.replaceWith(done);
				if (self.log) {
					self.addBubble('system', 'お問い合わせを送信しました。担当者からの連絡をお待ちください。', false);
				}
				return;
			}
			status.textContent = d.message || '送信できませんでした。時間をおいて再度お試しください。';
			status.className = 'form-status is-error';
			var fields = d.fields || {};
			Object.keys(fields).forEach(function (name) {
				var input = f[name];
				var err = form.querySelector('#' + (input ? input.id : '') + '-err');
				if (input) {
					input.setAttribute('aria-invalid', 'true');
				}
				if (err) {
					err.textContent = fields[name];
				}
			});
			var first = form.querySelector('[aria-invalid]');
			if (first) {
				first.focus();
			}
		}).catch(function () {
			submit.disabled = false;
			status.textContent = '通信エラーが発生しました。時間をおいて再度お試しください。';
			status.className = 'form-status is-error';
		});
	};

	// ---------------------------------------------------------------- 起動
	function boot() {
		var hosts = document.querySelectorAll('.rcac-embed');
		var hasInlineChat = document.querySelector('.rcac-embed[data-rcac-mode="chat"]') !== null;
		var state = loadState();
		var floatingWidget = null;

		Array.prototype.forEach.call(hosts, function (host) {
			if (host.getAttribute('data-rcac-ready')) {
				return;
			}
			var mode = host.getAttribute('data-rcac-mode') || 'chat';
			if (mode === 'floating' && hasInlineChat) {
				return; // ページ内にチャットがある場合は右下のボタンを出さない
			}
			host.setAttribute('data-rcac-ready', '1');
			var w = new Widget(host, mode, state);
			if (mode === 'floating') {
				floatingWidget = w;
			}
		});

		if (floatingWidget) {
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && floatingWidget.state.open) {
					floatingWidget.setOpen(false, true);
				}
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
