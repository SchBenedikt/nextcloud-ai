/* RagChat – Standalone-Client (bewusst ohne Framework, Fallback-Seite).
 * Liest requesttoken + API-Basis aus <meta>-Tags und spricht die
 * OCS-API des Backends direkt per fetch an. Rendert rein über
 * textContent – Texte sind immer sichtbar.
 * Multi-Chat: Chats werden serverseitig über /api/chats persistiert,
 * die Sidebar listet sie auf (Neuer Chat, Wechsel, Löschen).
 */
(function () {
	'use strict';

	function meta(name) {
		var el = document.head.querySelector('meta[name="' + name + '"]');
		return el ? el.getAttribute('content') : '';
	}

	var API_BASE = meta('ragchat-api') || '';
	var REQUEST_TOKEN = meta('requesttoken') || '';
	var STREAM_URL = meta('ragchat-stream') || '';

	var els = {
		form: document.getElementById('form'),
		input: document.getElementById('q'),
		send: document.getElementById('send'),
		msgs: document.getElementById('msgs'),
		err: document.getElementById('err'),
		empty: document.getElementById('empty'),
		newchat: document.getElementById('newchat'),
		chatlist: document.getElementById('chatlist'),
	};

	var messages = [];
	var refs = [];
	var sending = false;
	var lastMd = 0;
	var chatId = null;

	function copyText(txt, el) {
		var done = function () {
			if (!el) return;
			el.textContent = '✓';
			setTimeout(function () { el.textContent = '⧉'; }, 1200);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(txt).then(done).catch(done);
		} else {
			var ta = document.createElement('textarea');
			ta.value = txt;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); } catch (e) {}
			ta.remove();
			done();
		}
	}

	function exportMarkdown() {
		var lines = ['# AI chat export', ''];
		lines.push('_Exported ' + new Date().toISOString() + '_');
		messages.forEach(function (m) {
			lines.push('', '## ' + (m.role === 'user' ? 'You' : 'AI'), '', m.text || '');
		});
		var blob = new Blob([lines.join('\n')], { type: 'text/markdown' });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = 'ai-chat-' + (chatId || 'export') + '.md';
		document.body.appendChild(a);
		a.click();
		a.remove();
		URL.revokeObjectURL(url);
	}

	function api(method, path, body) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'OCS-APIRequest': 'true',
				'Accept': 'application/json',
				'requesttoken': REQUEST_TOKEN,
			},
		};
		if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(API_BASE + path, opts)
			.then(function (r) { return r.json(); })
			.then(function (json) {
				return json && json.ocs && typeof json.ocs.data !== 'undefined' ? json.ocs.data : json;
			});
	}

	function log() {
		if (typeof console !== 'undefined' && console.log) console.log.apply(console, arguments);
	}

	function citedSources(text, sources) {
		var nums = new Set();
		var re = /\[(\d+)\]/g;
		var m;
		while ((m = re.exec(text || '')) !== null) {
			var n = parseInt(m[1], 10);
			if (n >= 1 && n <= (sources || []).length) nums.add(n);
		}
		return (sources || [])
			.map(function (src, i) { return { ref: i + 1, src: src }; })
			.filter(function (x) { return nums.has(x.ref); });
	}

	function escHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]);
		});
	}

	function mdInline(text) {
		text = text
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/~~([^~]+)~~/g, '<del>$1</del>')
			.replace(/\*([^*]+)\*/g, '<em>$1</em>')
			.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
			.replace(/(^|[\s(])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
		return text;
	}

	function mdToHtml(src) {
		var blocks = String(src || '').split(/(```+)/);
		var html = '';
		for (var i = 0; i < blocks.length; i++) {
			if (i % 2 === 1) {
				var fence = blocks[i];
				if (fence[0] !== '`') continue;
				var body = blocks[++i] || '';
				var nl = body.indexOf('\n');
				var code = body.slice(nl > 0 ? nl + 1 : 0).replace(/[ \t]+\n?$/, '');
				html += '<pre class="md-pre"><code>' + escHtml(code) + '</code></pre>\n';
				continue;
			}
			var block = blocks[i];
			if (!block) continue;
			var lines = block.split('\n');
			var para = [];
			var listType = null;
			function flushPara() {
				if (para.length) { html += '<p>' + para.join('<br>') + '</p>\n'; para = []; }
			}
			function flushList() {
				if (listType === 'ul' || listType === 'ol') { html += '</' + listType + '>\n'; listType = null; }
			}
			for (var li = 0; li < lines.length; li++) {
				var s = lines[li].trim();
				if (s === '') { flushPara(); flushList(); continue; }
				var h = /^(#{1,6})\s+(.*)$/.exec(s);
				if (h) { flushPara(); flushList(); html += '<h' + h[1].length + '>' + mdInline(escHtml(h[2])) + '</h' + h[1].length + '>\n'; continue; }
				if (/^(-{3,}|\*{3,}|_{3,})$/.test(s)) { flushPara(); flushList(); html += '<hr>\n'; continue; }
				if (s[0] === '>') { flushPara(); flushList(); html += '<blockquote>' + mdInline(escHtml(s.slice(1).trim())) + '</blockquote>\n'; continue; }
				var ul = /^[-*+]\s+(.*)$/.exec(s);
				var ol = /^(\d+)[.):]\s+(.*)$/.exec(s);
				if (ul || ol) {
					flushPara();
					var type = ul ? 'ul' : 'ol';
					if (listType !== type) { flushList(); html += '<' + type + '>\n'; listType = type; }
					html += '<li>' + mdInline(escHtml((ul ? ul[1] : ol[2]) || '')) + '</li>\n';
					continue;
				}
				flushList();
				para.push(mdInline(escHtml(s)));
			}
			flushPara();
			flushList();
		}
		return html;
	}

	function toolRow(c) {
		var row = document.createElement('div');
		row.className = 'tool ' + (c.state === 'running' ? 'running' : c.state === 'ok' ? 'ok' : 'bad');
		row.textContent = (c.state === 'running' ? '🛠 ' : c.state === 'ok' ? '✅ ' : '❌ ') + c.name + (c.state === 'running' ? ' …' : '');
		return row;
	}

	function renderMsg(m, idx) {
		var wrap = document.createElement('div');
		wrap.className = 'rm ' + m.role;

		var b = document.createElement('div');
		b.className = 'rb';
		b.style.background = m.role === 'user' ? 'var(--color-primary-element, #00679c)' : 'var(--color-background-hover, #f1f2f4)';
		b.style.color = m.role === 'user' ? 'var(--color-primary-element-text, #fff)' : 'var(--color-main-text, #111)';

		if (m.role === 'assistant') {
			var det = document.createElement('details');
			det.className = 'rth';
			det.style.display = 'none';
			var sum = document.createElement('summary');
			sum.textContent = '🧠 Thinking…';
			var th = document.createElement('div');
			th.className = 'rth-c';
			det.appendChild(sum);
			det.appendChild(th);
			b.appendChild(det);
		}

		var t = document.createElement('div');
		t.className = 'rt';
		if (m.role === 'assistant' && m.text && m.done) {
			t.innerHTML = mdToHtml(m.text);
		} else {
			t.textContent = m.text || (m.role === 'assistant' ? '…' : '');
		}
			b.appendChild(t);
			if (m.role === 'assistant') {
				var cb = document.createElement('button');
				cb.className = 'rcopy';
				cb.title = 'Copy answer';
				cb.textContent = '⧉';
				cb.addEventListener('click', function () { copyText(String(m.text || ''), cb); });
				b.appendChild(cb);
			}
			wrap.appendChild(b);

		if (m.tools && m.tools.length) {
			var ta = document.createElement('div');
			ta.className = 'rtools';
			m.tools.forEach(function (c) {
				ta.appendChild(toolRow(c));
			});
			wrap.appendChild(ta);
		}

		if (m.sources && m.sources.length) {
			var s = document.createElement('div');
			s.className = 'rs';
			var lab = document.createElement('div');
			lab.className = 'lab';
			lab.textContent = 'Sources:';
			s.appendChild(lab);
			m.sources.forEach(function (item) {
				var src = item.src || item;
				var a = document.createElement('a');
				a.href = src.url || '#';
				a.target = '_blank';
				a.rel = 'noopener';
				var prefix = item.ref !== undefined ? '[' + item.ref + '] ' : '';
				a.textContent = prefix + (src.path || src.name || '');
				s.appendChild(a);
			});
			wrap.appendChild(s);
		}

		els.msgs.appendChild(wrap);
		if (els.empty) {
			els.empty.style.display = 'none';
			els.empty = null;
		}
		if (idx !== undefined) refs[idx] = wrap;
		els.msgs.scrollTop = els.msgs.scrollHeight;
	}

	function renderAll(list) {
		refs.length = 0;
		while (els.msgs.firstChild) {
			els.msgs.removeChild(els.msgs.firstChild);
		}
		list.forEach(function (m, i) { renderMsg(m, i); });
		els.msgs.scrollTop = els.msgs.scrollHeight;
	}

	function updateMessage(i) {
		var m = messages[i];
		var wrap = refs[i];
		if (!wrap || !m) return;
		var rt = wrap.querySelector('.rt');
		var det = wrap.querySelector('.rth');
		var th = wrap.querySelector('.rth-c');
		if (rt) {
			var now = Date.now();
			if (m.done) {
				rt.innerHTML = m.text ? mdToHtml(m.text) : '';
			} else if (now - lastMd > 200) {
				lastMd = now;
				rt.innerHTML = m.text ? mdToHtml(m.text) : '…';
			} else {
				rt.textContent = m.text || '…';
			}
		}
		if (th && det) {
			th.textContent = m.thinking || '';
			det.style.display = m.thinking ? '' : 'none';
			if (m.done) det.open = false;
		}
		var ta = wrap.querySelector('.rtools');
		if (m.tools && m.tools.length) {
			if (!ta) {
				ta = document.createElement('div');
				ta.className = 'rtools';
				wrap.appendChild(ta);
			}
			ta.innerHTML = '';
			m.tools.forEach(function (c) {
				ta.appendChild(toolRow(c));
			});
		} else if (ta) {
			ta.remove();
		}
		els.msgs.scrollTop = els.msgs.scrollHeight;
	}

	function apiStream(body, onLine) {
		if (!STREAM_URL) {
			return Promise.reject(new Error('No streaming endpoint'));
		}
		return fetch(STREAM_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
				'requesttoken': REQUEST_TOKEN,
			},
			body: JSON.stringify(body),
		}).then(function (r) {
			if (!r.ok || !r.body) {
				return r.text().then(function (t) { throw new Error('HTTP ' + r.status + ' ' + (t || '').slice(0, 200)); });
			}
			var reader = r.body.getReader();
			var dec = new TextDecoder();
			var buf = '';
			function pump() {
				return reader.read().then(function (res) {
					if (res.done) return;
					buf += dec.decode(res.value, { stream: true });
					var nl;
					while ((nl = buf.indexOf('\n')) >= 0) {
						var line = buf.slice(0, nl).trim();
						buf = buf.slice(nl + 1);
						if (!line) continue;
						var ev;
						try { ev = JSON.parse(line); } catch (e) { continue; }
						if (ev) onLine(ev);
					}
					return pump();
				});
			}
			return pump();
		});
	}

	function showErr(msg) {
		els.err.textContent = msg;
		els.err.style.display = msg ? 'block' : 'none';
	}

	function saveMessage(role, text) {
		if (!chatId) return Promise.resolve(false);
		return api('POST', '/chats/' + encodeURIComponent(chatId) + '/messages', { role: role, text: text })
			.then(function () { return true; })
			.catch(function () { return false; });
	}

	function ensureChat() {
		if (chatId) return Promise.resolve(true);
		return api('POST', '/chats', {})
			.then(function (c) {
				chatId = (c && c.id) || null;
				return !!chatId;
			})
			.catch(function () { return false; });
	}

	function loadChat(id) {
		return api('GET', '/chats/' + encodeURIComponent(id)).then(function (chat) {
			messages.length = 0;
			(chat.messages || []).forEach(function (m) {
				messages.push({
					role: m.role === 'user' || m.role === 'assistant' ? m.role : 'assistant',
					text: m.text || '',
					thinking: '',
					done: true,
				});
			});
			renderAll(messages);
		});
	}

	function renderChatList(chats) {
		if (!els.chatlist) return;
		while (els.chatlist.firstChild) els.chatlist.removeChild(els.chatlist.firstChild);
		if (!chats || !chats.length) {
			var empty = document.createElement('div');
			empty.className = 'chat-empty';
			empty.textContent = 'No chats yet.';
			els.chatlist.appendChild(empty);
			return;
		}
		chats.forEach(function (c) {
			var entry = document.createElement('div');
			entry.className = 'chat-entry' + (c.id === chatId ? ' active' : '');
			var t = document.createElement('span');
			t.className = 't';
			t.textContent = c.title;
			t.addEventListener('click', function () {
				chatId = c.id;
				loadChat(c.id).then(renderChatListAgain);
			});
			var x = document.createElement('button');
			x.className = 'x';
			x.textContent = '✕';
			x.title = 'Delete chat';
			x.addEventListener('click', function (e) {
				e.stopPropagation();
				if (!window.confirm('Delete chat "' + c.title + '"?')) return;
				api('DELETE', '/chats/' + encodeURIComponent(c.id)).then(function () {
					if (chatId === c.id) {
						chatId = null;
						messages.length = 0;
						renderAll(messages);
					}
					refreshChats();
				});
			});
			entry.appendChild(t);
			entry.appendChild(x);
			els.chatlist.appendChild(entry);
		});
	}

	function refreshChats() {
		api('GET', '/chats').then(function (list) {
			if (els.newchat) els.newchat.disabled = false;
			renderChatList(Array.isArray(list) ? list : []);
			if (!chatId && Array.isArray(list) && list.length) {
				chatId = list[0].id;
				loadChat(chatId);
			}
		}).catch(function () {
			if (els.newchat) els.newchat.disabled = false;
		});
	}

	function renderChatListAgain() {
		api('GET', '/chats').then(function (list) {
			renderChatList(Array.isArray(list) ? list : []);
		}).catch(function () {});
	}

	function send() {
		var msg = els.input.value.trim();
		if (!msg || sending) return;
		sending = true;
		els.input.value = '';
		els.send.disabled = true;
		showErr('');

		messages.push({ role: 'user', text: msg });
		messages.push({ role: 'assistant', text: '', thinking: '', done: false, tools: [] });
		renderAll(messages);
		log('sende:', msg);

		var history = [];
		for (var i = 0; i < messages.length - 2; i++) {
			history.push({ role: messages[i].role, content: messages[i].text });
		}

		ensureChat().then(function () {
			return apiStream({ message: msg, history: history }, function (ev) {
				var last = messages[messages.length - 1];
				if (!last || last.role !== 'assistant' || last.done) return;
				if (ev.type === 'thinking') {
					last.thinking += ev.delta || '';
				} else if (ev.type === 'content') {
					last.text += ev.delta || '';
				} else if (ev.type === 'tool') {
					last.tools = last.tools || [];
					last.tools.push({ name: ev.name || '?', state: 'running' });
				} else if (ev.type === 'tool_result') {
					if (last.tools && last.tools.length) {
						var t = last.tools[last.tools.length - 1];
						t.state = ev.ok ? 'ok' : 'bad';
					}
				} else if (ev.type === 'done') {
					last.text = ev.answer || last.text;
					last.sources = citedSources(last.text, ev.sources || []);
					last.done = true;
					Promise.all([saveMessage('user', msg), saveMessage('assistant', last.text)])
						.then(renderChatListAgain)
						.catch(function () {});
				} else if (ev.type === 'error') {
					last.text = '⚠️ ' + ev.message;
					last.done = true;
					saveMessage('user', msg);
				}
				updateMessage(messages.length - 1);
			});
		}).catch(function (e) {
			var last = messages[messages.length - 1];
			if (last && last.role === 'assistant' && !last.done) {
				last.text = (last.text || '') + '⚠️ Error: ' + String(e && e.message ? e.message : e);
				last.done = true;
				updateMessage(messages.length - 1);
			}
			showErr('Network error – see console.');
		}).finally(function () {
			sending = false;
			els.send.disabled = false;
			els.input.focus();
			els.msgs.scrollTop = els.msgs.scrollHeight;
		});
	}

	if (els.form) els.form.addEventListener('submit', function (e) {
		e.preventDefault();
		send();
	});
	if (els.send) els.send.addEventListener('click', send);
	if (els.newchat) els.newchat.addEventListener('click', function () {
		if (els.newchat.disabled) return;
		els.newchat.disabled = true;
		api('POST', '/chats', {}).then(function (c) {
			if (!c || !c.id) throw new Error('no id');
			chatId = c.id;
			messages.length = 0;
			renderAll(messages);
			return refreshChats();
		}).catch(function () {
			els.newchat.disabled = false;
		});
	});

	refreshChats();
})();