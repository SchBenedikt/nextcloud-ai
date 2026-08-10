/* EvaAi – Vanilla-Chat-Mount.
 * Wird von ChatView.vue aufgerufen und rendert den kompletten Chat
 * (Status, Blasen, Formular) rein über textContent/DOM-API – dadurch
 * ist die Text-Darstellung deterministisch und frameworkunabhängig.
 *
 * Multi-Chat: die Opfer-Chats werden serverseitig über /api/chats
 * persistiert. Ohne chatId wird vor der ersten Nachricht automatisch
 * ein neuer Chat angelegt (sofern die API erreichbar ist).
 */
export function mountChat(root, opts = {}) {
	if (!root || root.__evaAi) return
	root.__evaAi = true

	const { onRecent } = opts
	let chatId = opts.chatId || null

	const meta = (name) => {
		const el = document.head.querySelector('meta[name="' + name + '"]')
		return el ? el.getAttribute('content') : ''
	}
	const API_BASE = meta('eva-ai-api')
	const REQUEST_TOKEN = meta('requesttoken')
	const STREAM_URL = meta('eva-ai-stream') || ''

	const STORE_KEY = 'eva-ai.conv'
	const history = []
	const messages = []
	let sending = false
	const refs = []
	let lastMd = 0

	function api(method, path, body) {
		return new Promise((resolve, reject) => {
			const opts = {
				method,
				credentials: 'same-origin',
				headers: {
					'OCS-APIRequest': 'true',
					'Accept': 'application/json',
					'requesttoken': REQUEST_TOKEN,
				},
			}
			if (body) {
				opts.headers['Content-Type'] = 'application/json'
				opts.body = JSON.stringify(body)
			}
			fetch(API_BASE + path, opts)
				.then((r) => r.json())
				.then((json) => {
					const data = json && json.ocs && typeof json.ocs.data !== 'undefined' ? json.ocs.data : json
					resolve(data)
				})
				.catch(reject)
		})
	}

	function persistedMessages() {
		try {
			const raw = localStorage.getItem(STORE_KEY)
			if (!raw) return []
			const d = JSON.parse(raw)
			return Array.isArray(d) ? d : []
		} catch (_) { return [] }
	}

	/**
	 * Nur Quellen anzeigen, die wirklich im Antworttext mit [n] zitiert sind.
	 * Liefert [{ref:n, src:{...}}, ...] in der Reihenfolge der Zitierung.
	 */
	function citedSources(text, sources) {
		const nums = new Set()
		if (text) {
			const re = /\[([\d,\s\-–]+)\]/g
			let m
			while ((m = re.exec(text)) !== null) {
				m[1].split(/[\s,]+/).forEach((tok) => {
					if (!tok) return
					const range = tok.match(/^(\d+)[-–](\d+)$/)
					if (range) {
						for (let n = parseInt(range[1], 10); n <= parseInt(range[2], 10); n++) nums.add(n)
					} else {
						const n = parseInt(tok, 10)
						if (!isNaN(n)) nums.add(n)
					}
				})
			}
		}
		return (sources || [])
			.map((src, i) => ({ ref: i + 1, src }))
			.filter((x) => nums.has(x.ref))
	}

	function exportMarkdown() {
		const lines = []
		lines.push('# Eva chat export')
		lines.push('')
		const d = new Date()
		lines.push('_Exported ' + d.toISOString() + '_')
		lines.push('')
		messages.forEach((m) => {
			lines.push('')
			lines.push('## ' + (m.role === 'user' ? 'You' : 'Eva'))
			lines.push('')
			lines.push(m.text || '')
		})
		const blob = new Blob([lines.join('\n')], { type: 'text/markdown' })
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = 'eva-chat-' + (chatId || 'export') + '.md'
		document.body.appendChild(a)
		a.click()
		a.remove()
		URL.revokeObjectURL(url)
	}

	function copyText(txt, el) {
		const done = () => {
			if (!el) return
			el.textContent = '✓'
			setTimeout(() => { el.textContent = '⧉' }, 1200)
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(txt).then(done).catch(done)
		} else {
			const ta = document.createElement('textarea')
			ta.value = txt
			ta.style.position = 'fixed'
			ta.style.opacity = '0'
			document.body.appendChild(ta)
			ta.select()
			try { document.execCommand('copy') } catch (_) {}
			ta.remove()
			done()
		}
	}

	function escHtml(s) {
		return String(s).replace(/[&<>"']/g, (c) =>
			({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
	}

	function mdInline(text) {
		text = text
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/~~([^~]+)~~/g, '<del>$1</del>')
			.replace(/\*([^*]+)\*/g, '<em>$1</em>')
			.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
			.replace(/(^|[\s(])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>')
		return text
	}

	function mdToHtml(src) {
		const blocks = String(src || '').split(/(```+)/)
		let html = ''
		for (let i = 0; i < blocks.length; i++) {
			if (i % 2 === 1) {
				const fence = blocks[i]
				if (fence[0] !== '`') continue
				const body = blocks[++i] ?? ''
				const nl = body.indexOf('\n')
				const lang = (nl > 0 ? body.slice(0, nl) : '').trim().replace(/[^a-zA-Z0-9_+-]/g, '')
				const code = body.slice(nl > 0 ? nl + 1 : 0).replace(/[ \t]+\n?$/, '')
				html += '<pre class="md-pre"><code>' + escHtml(code) + '</code></pre>\n'
				continue
			}
			const block = blocks[i]
			if (!block) continue
			const lines = block.split('\n')
			let para = []
			let listType = null
			const flushPara = () => {
				if (para.length) {
					html += '<p>' + para.join('<br>') + '</p>\n'
					para = []
				}
			}
			const flushList = () => {
				if (listType === 'ul' || listType === 'ol') {
					html += '</' + listType + '>\n'
					listType = null
				}
			}
			for (const rawLine of lines) {
				const s = rawLine.trim()
				if (s === '') { flushPara(); flushList(); continue }
				const h = /^(#{1,6})\s+(.*)$/.exec(s)
				if (h) { flushPara(); flushList(); html += '<h' + h[1].length + '>' + mdInline(escHtml(h[2])) + '</h' + h[1].length + '>\n'; continue }
				if (/^(-{3,}|\*{3,}|_{3,})$/.test(s)) { flushPara(); flushList(); html += '<hr>\n'; continue }
				if (s[0] === '>') { flushPara(); flushList(); html += '<blockquote>' + mdInline(escHtml(s.slice(1).trim())) + '</blockquote>\n'; continue }
				const ul = /^[-*+]\s+(.*)$/.exec(s)
				const ol = /^(\d+)[.):]\s+(.*)$/.exec(s)
				if (ul || ol) {
					flushPara()
					const type = ul ? 'ul' : 'ol'
					if (listType !== type) { flushList(); html += '<' + type + '>\n'; listType = type }
					html += '<li>' + mdInline(escHtml((ul ? ul[1] : ol[2]) || '')) + '</li>\n'
					continue
				}
				flushList()
				para.push(mdInline(escHtml(s)))
			}
			flushPara()
			flushList()
		}
		return html
	}

	function renderMsg(scroll, emptyEl, m, idx) {
		const wrap = document.createElement('div')
		wrap.className = 'rm ' + m.role

		const b = document.createElement('div')
		b.className = 'rb'
		b.style.background = m.role === 'user' ? 'var(--color-primary-element, #00679c)' : 'var(--color-background-hover, #f1f2f4)'
		b.style.color = m.role === 'user' ? 'var(--color-primary-element-text, #fff)' : 'var(--color-main-text, #111)'

		if (m.role === 'assistant') {
			const det = document.createElement('details')
			det.className = 'rth'
			det.style.display = 'none'
			const sum = document.createElement('summary')
			sum.textContent = '🧠 Thinking…'
			const th = document.createElement('div')
			th.className = 'rth-c'
			det.append(sum, th)
			b.appendChild(det)
		}

		const t = document.createElement('div')
		t.className = 'rt'
		if (m.role === 'assistant' && m.text && m.done) {
			t.innerHTML = mdToHtml(m.text)
		} else {
			t.textContent = m.text || (m.role === 'assistant' ? '…' : '')
		}
		b.appendChild(t)
		if (m.role === 'assistant') {
			const cb = document.createElement('button')
			cb.className = 'rcopy'
			cb.title = 'Copy answer'
			cb.textContent = '⧉'
			cb.addEventListener('click', () => copyText(String(m.text || ''), cb))
			b.appendChild(cb)
		}
		wrap.appendChild(b)

		if (m.tools && m.tools.length) {
			const ta = document.createElement('div')
			ta.className = 'rtools'
			m.tools.forEach((c) => {
				const row = document.createElement('div')
				row.className = 'tool ' + (c.state === 'running' ? 'running' : c.state === 'ok' ? 'ok' : 'bad')
				row.textContent = (c.state === 'running' ? '🛠 ' : c.state === 'ok' ? '✅ ' : '❌ ') + c.name + (c.state === 'running' ? ' …' : '')
				ta.appendChild(row)
			})
			wrap.appendChild(ta)
		}

		if (m.sources && m.sources.length) {
			const s = document.createElement('div')
			s.className = 'rs'
			const lab = document.createElement('div')
			lab.className = 'lab'
			lab.textContent = 'Sources:'
			s.appendChild(lab)
			m.sources.forEach((item) => {
				const src = item.src || item
				const a = document.createElement('a')
				a.href = src.url || '#'
				a.target = '_blank'
				a.rel = 'noopener'
				const prefix = item.ref !== undefined ? '[' + item.ref + '] ' : ''
				a.textContent = prefix + (src.path || src.name || '')
				s.appendChild(a)
			})
			wrap.appendChild(s)
		}

		scroll.appendChild(wrap)
		if (emptyEl) {
			emptyEl.style.display = 'none'
			scroll.removeChild(emptyEl)
		}
		if (idx !== undefined) refs[idx] = wrap
		scroll.scrollTop = scroll.scrollHeight
		return wrap
	}

	// ---- Aufbau des DOM ----
	root.innerHTML = ''
	const head = document.createElement('div')
	head.className = 'head'
	head.innerHTML = ''
	const h1 = document.createElement('h1')
	h1.textContent = 'Chat with your files'
	const exportBtn = document.createElement('button')
	exportBtn.className = 'refresh export'
	exportBtn.textContent = '⬇ Export'
	exportBtn.title = 'Download this chat as Markdown'
	exportBtn.disabled = true
	exportBtn.addEventListener('click', exportMarkdown)
	head.append(h1, exportBtn)

	const scroll = document.createElement('div')
	scroll.className = 'chat-log'

	const emptyEl = document.createElement('div')
	emptyEl.className = 'empty'
	const eico = document.createElement('div')
	eico.className = 'ico'
	eico.textContent = '💬'
	const et = document.createElement('div')
	et.className = 't'
	et.textContent = 'Ask a question about your files'
	const ed = document.createElement('div')
	ed.className = 'd'
	ed.textContent = 'Ask about notes, plans or files — I can even create files, write notes and remember personal facts in a KNOWLEDGE.md.'
	emptyEl.append(eico, et, ed)
	scroll.appendChild(emptyEl)

	const form = document.createElement('form')
	form.className = 'chatform'
	const input = document.createElement('input')
	input.id = 'chatinput'
	input.type = 'text'
	input.autocomplete = 'off'
	input.placeholder = 'What do you want to do or know?'
	const sendBtn = document.createElement('button')
	sendBtn.type = 'submit'
	sendBtn.className = 'cbtn'
	sendBtn.textContent = 'Send'
	form.append(input, sendBtn)

	const err = document.createElement('div')
	err.className = 'err'
	err.style.display = 'none'

	root.append(head, scroll, form, err)

	const renderAll = (list) => {
		refs.length = 0
		while (scroll.firstChild) scroll.removeChild(scroll.firstChild)
		list.forEach((m, i) => renderMsg(scroll, null, m, i))
		exportBtn.disabled = !list.length
		scroll.scrollTop = scroll.scrollHeight
	}

	function updateMessage(i) {
		const m = messages[i]
		const wrap = refs[i]
		if (!wrap || !m) return
		const rt = wrap.querySelector('.rt')
		const det = wrap.querySelector('.rth')
		const th = wrap.querySelector('.rth-c')
		if (rt) {
			const now = Date.now()
			if (m.done) {
				rt.innerHTML = m.text ? mdToHtml(m.text) : ''
			} else if (now - lastMd > 200) {
				lastMd = now
				rt.innerHTML = m.text ? mdToHtml(m.text) : '…'
			} else {
				rt.textContent = m.text || '…'
			}
		}
		if (th && det) {
			th.textContent = m.thinking || ''
			det.style.display = m.thinking ? '' : 'none'
			if (m.done) det.open = false
		}
		let ta = wrap.querySelector('.rtools')
		if (m.tools && m.tools.length) {
			if (!ta) {
				ta = document.createElement('div')
				ta.className = 'rtools'
				wrap.appendChild(ta)
			}
			ta.innerHTML = ''
			m.tools.forEach((c) => {
				const row = document.createElement('div')
				row.className = 'tool ' + (c.state === 'running' ? 'running' : c.state === 'ok' ? 'ok' : 'bad')
				row.textContent = (c.state === 'running' ? '🛠 ' : c.state === 'ok' ? '✅ ' : '❌ ') + c.name + (c.state === 'running' ? ' …' : '')
				ta.appendChild(row)
			})
		} else if (ta) {
			ta.remove()
		}
		scroll.scrollTop = scroll.scrollHeight
	}

	function apiStream(path, body, onLine) {
		if (!path) return Promise.reject(new Error('No streaming endpoint'))
		return fetch(path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
				'requesttoken': REQUEST_TOKEN,
			},
			body: JSON.stringify(body),
		}).then((r) => {
			if (!r.ok || !r.body) {
				return r.text().then((t) => { throw new Error('HTTP ' + r.status + ' ' + (t || '').slice(0, 200)) })
			}
			const reader = r.body.getReader()
			const dec = new TextDecoder()
			let buf = ''
			const pump = () => reader.read().then(({ done, value }) => {
				if (done) return
				buf += dec.decode(value, { stream: true })
				let nl
				while ((nl = buf.indexOf('\n')) >= 0) {
					const line = buf.slice(0, nl).trim()
					buf = buf.slice(nl + 1)
					if (!line) continue
					let ev
					try { ev = JSON.parse(line) } catch (_) { continue }
					if (ev) onLine(ev)
				}
				return pump()
			})
			return pump()
		})
	}

	async function ensureChat() {
		if (chatId) return true
		try {
			const c = await api('POST', '/chats', {})
			chatId = (c && c.id) || null
			return !!chatId
		} catch (_) {
			return false
		}
	}

	function saveMessage(role, text) {
		if (!chatId) return Promise.resolve(false)
		return api('POST', '/chats/' + chatId + '/messages', { role, text })
			.then(() => true)
			.catch(() => false)
	}

	function restoreServerChat(id) {
		return api('GET', '/chats/' + id).then((chat) => {
			messages.length = 0
			;(chat.messages || []).forEach((m) => messages.push({
				role: m.role === 'user' || m.role === 'assistant' ? m.role : 'assistant',
				text: m.text || '',
				thinking: '',
				done: true,
			}))
			renderAll(messages)
		})
	}

	if (chatId) {
		restoreServerChat(chatId).catch(() => { /* falls Chat nicht existiert: leer starten */ })
	}

	const send = () => {
		const msg = input.value.trim()
		if (!msg || sending) return
		sending = true
		input.value = ''
		sendBtn.disabled = true
		err.style.display = 'none'
		if (emptyEl.parentNode) scroll.removeChild(emptyEl)

		messages.push({ role: 'user', text: msg })
		messages.push({ role: 'assistant', text: '', thinking: '', done: false, tools: [] })
		renderAll(messages)

		const history = []
		for (let i = 0; i < messages.length - 2; i++) {
			const m = messages[i]
			history.push({ role: m.role, content: m.text })
		}

		ensureChat().then(() => {
			apiStream(STREAM_URL, { message: msg, history }, (ev) => {
				const last = messages[messages.length - 1]
				if (!last || last.role !== 'assistant' || last.done) return
				if (ev.type === 'thinking') {
					last.thinking += ev.delta || ''
				} else if (ev.type === 'content') {
					last.text += ev.delta || ''
				} else if (ev.type === 'tool') {
					last.tools = last.tools || []
					last.tools.push({ name: ev.name || '?', state: 'running' })
				} else if (ev.type === 'tool_result') {
					if (last.tools && last.tools.length) {
						const t = last.tools[last.tools.length - 1]
						t.state = ev.ok ? 'ok' : 'bad'
					}
				} else if (ev.type === 'done') {
					last.text = ev.answer || last.text
					last.sources = citedSources(last.text, ev.sources || [])
					last.done = true
					Promise.all([saveMessage('user', msg), saveMessage('assistant', last.text)])
						.then(() => { if (onRecent) onRecent() })
						.catch(() => {})
				} else if (ev.type === 'error') {
					last.text = '⚠️ ' + ev.message
					last.done = true
					saveMessage('user', msg)
				}
				updateMessage(messages.length - 1)
			}).catch((e) => {
				const last = messages[messages.length - 1]
				if (last && last.role === 'assistant' && !last.done) {
					last.text = (last.text || '') + '⚠️ Error: ' + String(e && e.message ? e.message : e)
					last.done = true
					updateMessage(messages.length - 1)
				}
				err.textContent = 'Network error — see console.'
				err.style.display = 'block'
			}).finally(() => {
				sending = false
				sendBtn.disabled = false
				input.focus()
				scroll.scrollTop = scroll.scrollHeight
			})
		}).catch(() => {
			sending = false
			sendBtn.disabled = false
			err.textContent = 'Chat konnte nicht erstellt werden.'
			err.style.display = 'block'
		})
	}

	// Ohne Server-Chat: lokale Nachrichten aus dem alten localStorage zeigen
	if (!chatId && opts.fallbackLocal) {
		const data = persistedMessages()
		if (data.length) {
			data.forEach((m) => messages.push({
				role: m.role,
				text: m.text || '',
				thinking: m.thinking || '',
				sources: m.sources,
				done: true,
			}))
			renderAll(messages)
		}
	}

	input.focus()
	form.addEventListener('submit', (e) => { e.preventDefault(); send() })
}