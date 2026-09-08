import { mdiDownload, mdiTune } from '@mdi/js'
import { translate as t } from './i18n'
import { buildConfirmForm } from './confirmForms'
import { escHtml, mdInline, mdToHtml, citedSources, copyText } from './chat-utils'

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
	let trimmedMessages = 0

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
				.then(async (r) => {
					const text = await r.text()
					let json = null
					try { json = text ? JSON.parse(text) : null } catch (_) {}
					if (!r.ok) {
						const detail = json?.ocs?.message || json?.ocs?.data?.error || json?.error || text
						throw new Error(('HTTP ' + r.status + ' ' + String(detail || '')).trim().slice(0, 260))
					}
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

	function exportMarkdown() {
		const lines = []
		lines.push('# ' + t('Eva chat export'))
		lines.push('')
		const d = new Date()
		lines.push('_' + t('Exported {date}', { date: d.toISOString() }) + '_')
		lines.push('')
		messages.forEach((m) => {
			lines.push('')
			lines.push('## ' + (m.role === 'user' ? t('You') : 'Eva'))
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
			sum.textContent = '🧠 ' + t('Thinking…')
			const th = document.createElement('div')
			th.className = 'rth-c'
			det.append(sum, th)
			b.appendChild(det)
		}

		const textEl = document.createElement('div')
		textEl.className = 'rt'
		if (m.role === 'assistant' && m.text && m.done) {
			textEl.innerHTML = mdToHtml(m.text)
		} else {
			textEl.textContent = m.text || (m.role === 'assistant' ? '…' : '')
		}
		b.appendChild(textEl)
		if (m.role === 'assistant' && m.done) {
			const actBar = document.createElement('div')
			actBar.className = 'racts'
			const cb = document.createElement('button')
			cb.className = 'rcopy'
			cb.title = t('Copy answer')
			cb.textContent = '⧉'
			cb.addEventListener('click', () => copyText(String(m.text || ''), cb))
			actBar.appendChild(cb)
			const rb = document.createElement('button')
			rb.className = 'ract'
			rb.title = t('Regenerate')
			rb.textContent = '↻'
			rb.addEventListener('click', () => regenerateMessage(idx))
			actBar.appendChild(rb)
			b.appendChild(actBar)
		}
		if (m.role === 'user' && m.done) {
			const actBar = document.createElement('div')
			actBar.className = 'racts'
			const eb = document.createElement('button')
			eb.className = 'ract'
			eb.title = t('Edit message')
			eb.textContent = '✎'
			eb.addEventListener('click', () => editMessage(idx))
			actBar.appendChild(eb)
			b.appendChild(actBar)
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
			wrap.appendChild(renderSources(m))
		}

		if (m.followups && m.followups.length) {
			wrap.appendChild(renderFollowups(m))
		}

		const linkUrl = (m.confirmation && m.confirmation.resolved && m.confirmation.resultUrl) || m.linkUrl
		if (linkUrl) {
			const linkRow = document.createElement('div')
			linkRow.className = 'rconfirm-link'
			const link = document.createElement('a')
			link.href = linkUrl
			link.target = '_blank'
			link.rel = 'noopener'
			link.textContent = linkUrl
			link.title = t('Open link')
			const copyBtn = document.createElement('button')
			copyBtn.type = 'button'
			copyBtn.textContent = t('Copy link')
			copyBtn.addEventListener('click', () => copyText(linkUrl, copyBtn))
			linkRow.append(link, copyBtn)
			wrap.appendChild(linkRow)
		}

		if (m.confirmation && !m.confirmation.resolved) {
			const panel = document.createElement('div')
			panel.className = 'rconfirm'
			const danger = (m.confirmation.risk || 'mutating') === 'destructive'
			if (danger) panel.classList.add('rconfirm--danger')
			const conf = buildConfirmForm(m.confirmation)
			const label = document.createElement('div')
			label.className = 'rconfirm-label'
			label.textContent = conf ? t(conf.title) : t('EVA wants to run: {tool}', { tool: m.confirmation.name })
			panel.appendChild(label)
			const missing = Array.isArray(m.confirmation.missing) ? m.confirmation.missing : []
			const summary = document.createElement('div')
			summary.className = 'rconfirm-summary'
			summary.textContent = missing.length
				? t('Some required details are missing - please complete them below.')
				: (danger
					? t('This action cannot be undone.')
					: t('Please review this action and confirm it explicitly.'))
			panel.appendChild(summary)
			const errEl = document.createElement('div')
			errEl.className = 'rconfirm-error'
			errEl.style.display = 'none'
			if (conf) {
				panel.appendChild(conf.element)
				// Show which fields still need input right away (reason: missing).
				if (missing.length) conf.validate()
			} else {
				const details = document.createElement('pre')
				details.className = 'rconfirm-args'
				details.textContent = JSON.stringify(m.confirmation.arguments || {}, null, 2)
				panel.appendChild(details)
			}
			const actions = document.createElement('div')
			actions.className = 'rconfirm-actions'
			const approve = document.createElement('button')
			approve.type = 'button'
			approve.className = 'rconfirm-approve'
			approve.textContent = t('Confirm and run')
			const reject = document.createElement('button')
			reject.type = 'button'
			reject.className = 'rconfirm-reject'
			reject.textContent = t('Cancel')
			const finish = (text, url) => {
				m.confirmation.resolved = true
				if (url) m.confirmation.resultUrl = url
				m.text = text
				m.done = true
				renderAll(messages)
				saveMessage('assistant', m.text).then(() => { if (onRecent) onRecent() })
			}
			const disableButtons = (disabled) => {
				approve.disabled = disabled
				reject.disabled = disabled
				approve.textContent = disabled ? t('Running…') : t('Confirm and run')
			}
			approve.addEventListener('click', () => {
				if (conf && !conf.validate()) {
					errEl.textContent = t('Please fill in all required fields.')
					errEl.style.display = ''
					return
				}
				errEl.style.display = 'none'
				disableButtons(true)
				api('POST', '/confirmTool', {
					name: m.confirmation.name,
					arguments: conf ? conf.getArguments() : (m.confirmation.arguments || {}),
				}).then((result) => {
					if (!result || !result.ok) {
						finish('⚠️ ' + (result?.error || t('The action could not be completed.')))
						return
					}
					let value = t('The action was completed.')
					let url = ''
					if (typeof result.result === 'string') value = result.result
					else if (result.result && result.result.url) {
						url = result.result.url
						value = t('Share created: {url}', { url })
					}
					finish('✅ ' + value, url)
				}).catch((error) => {
					disableButtons(false)
					errEl.textContent = String(error?.message || error)
					errEl.style.display = ''
				})
			})
			reject.addEventListener('click', () => finish(t('Action cancelled.')))
			actions.append(approve, reject)
			panel.append(errEl, actions)
			wrap.appendChild(panel)
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
	h1.textContent = t('Chat with your files')
	const exportBtn = document.createElement('button')
	exportBtn.className = 'export'
	exportBtn.type = 'button'
	exportBtn.setAttribute('aria-label', t('Export chat as Markdown'))
	exportBtn.title = t('Export chat as Markdown')
	const exportIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
	exportIcon.classList.add('export-icon')
	exportIcon.setAttribute('viewBox', '0 0 24 24')
	exportIcon.setAttribute('aria-hidden', 'true')
	const exportPath = document.createElementNS('http://www.w3.org/2000/svg', 'path')
	exportPath.setAttribute('d', mdiDownload)
	exportIcon.append(exportPath)
	const exportLabel = document.createElement('span')
	exportLabel.textContent = t('Export')
	exportBtn.append(exportIcon, exportLabel)
	exportBtn.disabled = true
	exportBtn.addEventListener('click', exportMarkdown)
	const scopePill = document.createElement('span')
	scopePill.className = 'pill pill-warn'
	scopePill.hidden = true

	// Per-chat custom instructions (Issue #90): a small header action that
	// opens a dialog to pick a preset persona and/or free-text instructions.
	const customizeBtn = document.createElement('button')
	customizeBtn.className = 'export customize-btn'
	customizeBtn.type = 'button'
	customizeBtn.setAttribute('aria-label', t('Customize EVA for this chat'))
	customizeBtn.title = t('Customize EVA for this chat')
	const customizeIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
	customizeIcon.classList.add('export-icon')
	customizeIcon.setAttribute('viewBox', '0 0 24 24')
	customizeIcon.setAttribute('aria-hidden', 'true')
	const customizePath = document.createElementNS('http://www.w3.org/2000/svg', 'path')
	customizePath.setAttribute('d', mdiTune)
	customizeIcon.append(customizePath)
	const customizeLabel = document.createElement('span')
	customizeLabel.textContent = t('Customize')
	customizeBtn.append(customizeIcon, customizeLabel)
	const customizePill = document.createElement('span')
	customizePill.className = 'pill pill-ok'
	customizePill.hidden = true
	customizeBtn.addEventListener('click', () => openCustomizeDialog())
	head.append(h1, scopePill, customizePill, customizeBtn, exportBtn)

	const scroll = document.createElement('div')
	scroll.className = 'chat-log'

	const emptyEl = document.createElement('div')
	emptyEl.className = 'empty'
	const eico = document.createElement('div')
	eico.className = 'ico'
	eico.textContent = '💬'
	const et = document.createElement('div')
	et.className = 't'
	et.textContent = t('Ask a question about your files')
	const ed = document.createElement('div')
	ed.className = 'd'
	ed.textContent = t('Ask about notes, plans or files — I can even create files, write notes and remember personal facts in a KNOWLEDGE.md.')
	emptyEl.append(eico, et, ed)
	scroll.appendChild(emptyEl)

	const form = document.createElement('form')
	form.className = 'chatform'
	const input = document.createElement('input')
	input.id = 'chatinput'
	input.type = 'text'
	input.autocomplete = 'off'
	input.placeholder = t('What do you want to do or know?')
	const sendBtn = document.createElement('button')
	sendBtn.type = 'submit'
	sendBtn.className = 'cbtn'
	sendBtn.textContent = t('Send')
	form.append(input, sendBtn)

	const err = document.createElement('div')
	err.className = 'err'
	err.style.display = 'none'

	root.append(head, scroll, form, err)

	const renderAll = (list) => {
		refs.length = 0
		while (scroll.firstChild) scroll.removeChild(scroll.firstChild)
		if (trimmedMessages > 0) {
			// Oldest messages of a very long conversation were dropped on the
			// server. Make that visible instead of silently missing context.
			const note = document.createElement('div')
			note.className = 'rtrimmed'
			note.textContent = t('This conversation is very long: some of the oldest messages were trimmed to keep it manageable. Use Export if you need the full history.')
			scroll.appendChild(note)
		}
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
		// Once the answer is complete, (re-)render sources and follow-up
		// chips: they only exist after the stream is done, and updateMessage
		// runs incrementally while the DOM was built for an in-flight answer.
		if (m.done) {
			const oldSrc = wrap.querySelector('.rs')
			if (oldSrc) oldSrc.remove()
			const oldFu = wrap.querySelector('.rfu')
			if (oldFu) oldFu.remove()
			const anchor = wrap.querySelector('.rconfirm-link, .rconfirm')
			if (m.sources && m.sources.length) {
				const details = renderSources(m)
				if (anchor) wrap.insertBefore(details, anchor)
				else wrap.appendChild(details)
			}
			if (m.followups && m.followups.length) {
				const chips = renderFollowups(m)
				if (anchor) wrap.insertBefore(chips, anchor)
				else wrap.appendChild(chips)
			}
		}
		scroll.scrollTop = scroll.scrollHeight
	}

	function renderSources(m) {
		const details = document.createElement('details')
		details.className = 'rs'
		const summary = document.createElement('summary')
		summary.className = 'rs-sum'
		summary.textContent = t('Sources') + ' (' + m.sources.length + ')'
		details.appendChild(summary)
		const list = document.createElement('div')
		list.className = 'rs-list'
		m.sources.forEach((item) => {
			const src = item.src || item
			const row = document.createElement('div')
			row.className = 'rs-item'
			const a = document.createElement('a')
			a.href = src.url || '#'
			a.target = '_blank'
			a.rel = 'noopener'
			const prefix = item.ref !== undefined ? '[' + item.ref + '] ' : ''
			a.textContent = prefix + (src.path || src.name || '')
			row.appendChild(a)
			if (src.excerpts && src.excerpts.length) {
				const ex = document.createElement('div')
				ex.className = 'rs-excerpt'
				ex.textContent = src.excerpts[0]
				row.appendChild(ex)
			}
			list.appendChild(row)
		})
		details.appendChild(list)
		return details
	}

	function renderFollowups(m) {
		const chips = document.createElement('div')
		chips.className = 'rfu'
		m.followups.forEach((q) => {
			const btn = document.createElement('button')
			btn.type = 'button'
			btn.className = 'rfu-btn'
			btn.textContent = q
			btn.addEventListener('click', () => {
				if (sending) return
				input.value = q
				send()
			})
			chips.appendChild(btn)
		})
		return chips
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

	function saveMessage(role, text, followups) {
		if (!chatId) return Promise.resolve(false)
		const body = { role, text }
		// Follow-up suggestions are persisted for assistant messages so the
		// chips survive a page reload.
		if (role === 'assistant' && Array.isArray(followups) && followups.length) {
			body.followups = followups
		}
		return api('POST', '/chats/' + chatId + '/messages', body)
			.then(() => true)
			.catch(() => false)
	}

	function refreshCustomizePill(chat) {
		// Per-chat custom instructions (Issue #90): a subtle header indicator
		// when a persona or free-text instructions are active on this chat.
		const active = !!(chat && ((chat.persona && chat.persona !== 'default') || (chat.instructions && chat.instructions.trim())))
		customizePill.hidden = !active
		if (active) {
			customizePill.textContent = chat.persona && chat.persona !== 'default' ? t('Persona: {name}', { name: personaLabel(chat.persona) }) : t('Customized')
		}
	}

	function restoreServerChat(id) {
		return api('GET', '/chats/' + id).then((chat) => {
			// Per-chat folder scope (Issue #88): visible in the header so the
			// user knows the answer only uses documents from that folder.
			if (chat && chat.scopePath) {
				scopePill.textContent = t('Scoped to {path}', { path: chat.scopePath })
				scopePill.hidden = false
			} else {
				scopePill.hidden = true
			}
			refreshCustomizePill(chat)
			messages.length = 0
			trimmedMessages = (chat && chat.trimmed) ? parseInt(chat.trimmed, 10) || 0 : 0
		;(chat.messages || []).forEach((m) => messages.push({
			role: m.role === 'user' || m.role === 'assistant' ? m.role : 'assistant',
			text: m.text || '',
			thinking: '',
			followups: Array.isArray(m.followups) ? m.followups : [],
			done: true,
		}))
		renderAll(messages)
	})
}

	function personaLabel(slug) {
		const labels = {
			'concise': t('Concise'),
			'structured': t('Structured'),
			'creative': t('Creative'),
			'expert': t('Expert'),
		}
		return labels[slug] || t('Default')
	}

	function openCustomizeDialog() {
		// Only meaningful once a chat exists; without one the instructions
		// have nowhere to be stored.
		if (!chatId) return
		let current = { persona: '', instructions: '' }
		try {
			const raw = localStorage.getItem('eva-ai.customize.' + chatId)
			if (raw) current = JSON.parse(raw) || current
		} catch (_) { /* ignore */ }

		const overlay = document.createElement('div')
		overlay.className = 'customize-overlay'
		const box = document.createElement('div')
		box.className = 'customize-box'
		box.setAttribute('role', 'dialog')
		box.setAttribute('aria-modal', 'true')
		box.setAttribute('aria-label', t('Customize EVA for this chat'))

		const close = () => {
			overlay.remove()
			document.removeEventListener('keydown', onKey)
		}
		const onKey = (e) => {
			if (e.key === 'Escape') close()
		}

		const h = document.createElement('h3')
		h.textContent = t('Customize EVA for this chat')
		const sub = document.createElement('p')
		sub.className = 'customize-sub'
		sub.textContent = t('Choose a preset style or write your own instructions. They are only applied to this chat.')

		const personaField = document.createElement('label')
		personaField.className = 'customize-field'
		const personaLabelEl = document.createElement('span')
		personaLabelEl.textContent = t('Style')
		const personaSelect = document.createElement('select')
		const personas = [
			['default', t('Default')],
			['concise', t('Concise')],
			['structured', t('Structured')],
			['creative', t('Creative')],
			['expert', t('Expert')],
		]
		personas.forEach(([value, label]) => {
			const opt = document.createElement('option')
			opt.value = value
			opt.textContent = label
			personaSelect.append(opt)
		})
		personaSelect.value = current.persona && current.persona !== 'default' ? current.persona : 'default'
		personaField.append(personaLabelEl, personaSelect)

		const instrField = document.createElement('label')
		instrField.className = 'customize-field'
		const instrLabelEl = document.createElement('span')
		instrLabelEl.textContent = t('Your instructions')
		const instrTa = document.createElement('textarea')
		instrTa.rows = 5
		instrTa.maxLength = 2000
		instrTa.placeholder = t('e.g. Always answer in German, structured with headings…')
		instrTa.value = current.instructions || ''
		instrField.append(instrLabelEl, instrTa)

		const actions = document.createElement('div')
		actions.className = 'customize-actions'
		const saveBtn = document.createElement('button')
		saveBtn.type = 'button'
		saveBtn.className = 'cbtn'
		saveBtn.textContent = t('Save')
		const cancelBtn = document.createElement('button')
		cancelBtn.type = 'button'
		cancelBtn.className = 'cbtn cbtn-ghost'
		cancelBtn.textContent = t('Cancel')
		cancelBtn.addEventListener('click', close)
		saveBtn.addEventListener('click', async () => {
			const persona = personaSelect.value === 'default' ? '' : personaSelect.value
			const instructions = instrTa.value.trim()
			saveBtn.disabled = true
			try {
				await api('POST', '/chats/' + chatId + '/meta', { persona, instructions })
				localStorage.setItem('eva-ai.customize.' + chatId, JSON.stringify({ persona, instructions }))
				// Re-fetch to update the header pill with server truth.
				api('GET', '/chats/' + chatId).then((chat) => refreshCustomizePill(chat)).catch(() => {})
				close()
			} catch (err) {
				saveBtn.disabled = false
				const errEl = document.createElement('div')
				errEl.className = 'customize-err'
				errEl.textContent = String(err && err.message ? err.message : err)
				box.appendChild(errEl)
			}
		})
		actions.append(cancelBtn, saveBtn)
		box.append(h, sub, personaField, instrField, actions)
		overlay.append(box)
		document.body.appendChild(overlay)
		document.addEventListener('keydown', onKey)
		instrTa.focus()
	}

	if (chatId) {
		restoreServerChat(chatId).catch(() => { /* falls Chat nicht existiert: leer starten */ })
	}

	// Streams a server-side re-run (regenerate or edit) and persists the new
	// answer. The /regenerate endpoint truncates the chat and applies the
	// edited user text *before* the stream starts, so this is the single
	// generation: we never duplicate the user message in the history (the
	// server builds it from the stored messages) and never run Ollama twice.
	// Confirmation events are surfaced as an inline panel exactly like in the
	// normal send flow.
	const streamRegenerateAnswer = (body) => {
		if (!chatId) {
			err.textContent = t('Chat could not be updated.')
			err.style.display = 'block'
			return
		}
		sending = true
		sendBtn.disabled = true
		err.style.display = 'none'
		messages.push({ role: 'assistant', text: '', thinking: '', done: false, tools: [] })
		renderAll(messages)
		const unlock = () => {
			sending = false
			sendBtn.disabled = false
		}
		apiStream(API_BASE + '/chats/' + chatId + '/regenerate', body, (ev) => {
			const last = messages[messages.length - 1]
			if (!last || last.role !== 'assistant' || last.done) return
			if (ev.type === 'thinking') {
				last.thinking = (last.thinking || '') + (ev.delta || '')
				updateMessage(messages.length - 1)
			} else if (ev.type === 'content') {
				last.text += (ev.delta || '')
				updateMessage(messages.length - 1)
			} else if (ev.type === 'tool') {
				last.tools = last.tools || []
				last.tools.push({ name: ev.name || '?', state: 'running' })
				updateMessage(messages.length - 1)
			} else if (ev.type === 'tool_result') {
				last.tools = last.tools || []
				for (let t = last.tools.length - 1; t >= 0; t--) {
					if (last.tools[t].name === ev.name && last.tools[t].state === 'running') {
						last.tools[t].state = ev.ok ? 'ok' : 'bad'
						break
					}
				}
				// Direct (no-dialog) executions still surface a created share
				// link as a copyable chip in the bubble.
				if (ev.ok && ev.url) last.linkUrl = ev.url
				updateMessage(messages.length - 1)
			} else if (ev.type === 'confirmation') {
				// The server already committed truncation + edit; the inline
				// panel below runs the tool on approve and persists the answer.
				const missing = Array.isArray(ev.missing) ? ev.missing : []
				last.confirmation = {
					name: ev.name || '?',
					arguments: ev.arguments || {},
					risk: ev.risk || 'mutating',
					missing,
					resolved: false,
				}
				last.text = missing.length
					? t('Some required details are missing - please complete them below.')
					: t('Please review this action and confirm it explicitly.')
				last.done = true
				renderAll(messages)
				scroll.scrollTop = scroll.scrollHeight
				return
			} else if (ev.type === 'done') {
				last.text = ev.answer || last.text
				last.sources = citedSources(last.text, ev.sources || [])
				last.followups = ev.followups || []
				last.done = true
				updateMessage(messages.length - 1)
				saveMessage('assistant', last.text, last.followups)
					.then(() => { if (onRecent) onRecent() })
					.catch(() => {})
			} else if (ev.type === 'error') {
				last.text = '⚠️ ' + ev.message
				last.done = true
			}
			scroll.scrollTop = scroll.scrollHeight
		}).catch((e) => {
			const last = messages[messages.length - 1]
			if (last && last.role === 'assistant' && !last.done) {
				last.text = (last.text || '') + '⚠️ Error: ' + String(e && e.message ? e.message : e)
				last.done = true
				updateMessage(messages.length - 1)
			}
			err.textContent = t('Network error — see console.')
			err.style.display = 'block'
		}).finally(() => {
			unlock()
			input.focus()
			scroll.scrollTop = scroll.scrollHeight
		})
	}

	const regenerateMessage = (assistantIdx) => {
		if (sending) return
		const userIdx = assistantIdx - 1
		if (userIdx < 0 || !messages[userIdx] || messages[userIdx].role !== 'user') return
		if (!messages[assistantIdx] || messages[assistantIdx].role !== 'assistant') return
		// Remove the assistant message and everything after it; the server
		// commits the same truncation when the /regenerate stream starts.
		messages.length = assistantIdx
		renderAll(messages)
		streamRegenerateAnswer({ messageIndex: userIdx, message: null })
	}

	const editMessage = (userIdx) => {
		if (sending) return
		if (!messages[userIdx] || messages[userIdx].role !== 'user') return
		const oldText = messages[userIdx].text
		const newText = prompt(t('Edit your message:'), oldText)
		if (newText === null || newText.trim() === '' || newText.trim() === oldText) return
		// Truncate locally after the edited message; the server applies the
		// same truncation and replacement before the /regenerate stream runs.
		messages.length = userIdx + 1
		messages[userIdx].text = newText.trim()
		renderAll(messages)
		streamRegenerateAnswer({ messageIndex: userIdx, message: newText.trim() })
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
			apiStream(STREAM_URL, { message: msg, history, chatId }, (ev) => {
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
					// Direct (no-dialog) executions still surface a created share
					// link as a copyable chip in the bubble.
					if (ev.ok && ev.url) last.linkUrl = ev.url
				} else if (ev.type === 'confirmation') {
					const missing = Array.isArray(ev.missing) ? ev.missing : []
					last.confirmation = {
						name: ev.name || '?',
						arguments: ev.arguments || {},
						risk: ev.risk || 'mutating',
						missing,
						resolved: false,
					}
					last.text = missing.length
						? t('Some required details are missing - please complete them below.')
						: t('Please review this action and confirm it explicitly.')
					last.done = true
					saveMessage('user', msg)
					renderAll(messages)
				} else if (ev.type === 'done') {
					last.text = ev.answer || last.text
					last.sources = citedSources(last.text, ev.sources || [])
					last.followups = ev.followups || []
					last.done = true
					// Persist the pair in conversation order. Sending both requests at
					// once lets the per-user file lock acquire them in either order,
					// which can swap the question and answer after a reload.
					saveMessage('user', msg)
						.then((savedUser) => savedUser ? saveMessage('assistant', last.text, last.followups) : false)
						.then((saved) => { if (saved && onRecent) onRecent() })
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
				err.textContent = t('Network error — see console.')
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
			err.textContent = t('Chat could not be created.')
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