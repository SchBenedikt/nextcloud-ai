import './lib/markdown.css'
import { readNdjson } from './lib/ndjson'
/**
 * Standalone chat entry point (Issue #75).
 *
 * Replaces the hand-written js/chat.js with a webpack-built bundle that
 * shares core rendering utilities (escHtml, mdToHtml, mdInline,
 * citedSources, copyText) with the Vue vanilla mount via chat-utils.js.
 *
 * The standalone page pre-defines its DOM in standalone.php; this script
 * wires up event handlers, sidebar management and chat persistence.
 */
import { escHtml, mdInline, mdToHtml, citedSources, copyText, installImageFallback } from './lib/chat-utils'

function buildCalendarForm(args, tr) {
	var form = document.createElement('div')
	form.className = 'rconfirm-form'
	var fields = {}
	function add(key, label, type, value, required) {
		var row = document.createElement('label')
		row.className = 'rconfirm-field'
		var caption = document.createElement('span')
		caption.textContent = label + (required ? ' *' : '')
		var input = document.createElement('input')
		input.type = type
		input.value = value || ''
		row.appendChild(caption)
		row.appendChild(input)
		form.appendChild(row)
		fields[key] = input
	}
	var startMatch = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/.exec(String(args.start || ''))
	if (startMatch) { args.start = startMatch[1]; args.start_time = startMatch[2] }
	var endMatch = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/.exec(String(args.end || ''))
	if (endMatch) { args.end = endMatch[1]; args.end_time = endMatch[2] }
	add('start', tr('Start'), 'date', args.start, true)
	add('start_time', tr('Start time'), 'time', args.start_time, false)
	add('end', tr('End'), 'date', args.end, false)
	add('end_time', tr('End time'), 'time', args.end_time, false)
	var calendar = document.createElement('label')
	calendar.className = 'rconfirm-field'
	var calendarLabel = document.createElement('span')
	calendarLabel.textContent = tr('Calendar')
	var select = document.createElement('select')
	var loading = document.createElement('option')
	loading.textContent = tr('Loading…')
	select.appendChild(loading)
	calendar.appendChild(calendarLabel)
	calendar.appendChild(select)
	form.appendChild(calendar)
	fields.calendar = select
	fetch((meta('eva-ai-api') || '') + '/calendars', { credentials: 'same-origin', headers: { 'OCS-APIRequest': 'true', 'Accept': 'application/json', 'requesttoken': REQUEST_TOKEN } })
		.then(function (r) { return r.json() })
		.then(function (payload) {
			var calendars = payload && payload.ocs && payload.ocs.data && payload.ocs.data.calendars || payload && payload.calendars || []
			select.innerHTML = ''
			calendars.filter(function (cal) { return !cal.readOnly }).forEach(function (cal) {
				var option = document.createElement('option')
				option.value = cal.uri || cal.id || cal.displayname
				option.textContent = cal.displayname || cal.uri
				select.appendChild(option)
			})
			if (args.calendar) select.value = args.calendar
		})
		.catch(function () {})
	form.__sync = function () {
		if (!fields.start.value.trim()) return false
		args.start = fields.start.value + (fields.start_time.value ? ' ' + fields.start_time.value : '')
		args.end = fields.end.value ? fields.end.value + (fields.end_time.value ? ' ' + fields.end_time.value : '') : ''
		args.calendar = fields.calendar.value
		return true
	}
	return form
}

;(function () {
	'use strict'

	function tr(text, vars) {
		if (typeof window !== 'undefined' && typeof window.t === 'function') {
			return window.t('eva_ai', text, vars)
		}
		return String(text).replace(/\{([^{}]+)\}/g, function (match, key) {
			return vars && Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match
		})
	}

	function meta(name) {
		var el = document.head.querySelector('meta[name="' + name + '"]')
		return el ? el.getAttribute('content') : ''
	}

	var API_BASE = meta('eva-ai-api') || ''
	var REQUEST_TOKEN = meta('requesttoken') || ''
	var STREAM_URL = meta('eva-ai-stream') || ''

	var els = {
		form: document.getElementById('form'),
		input: document.getElementById('q'),
		send: document.getElementById('send'),
		msgs: document.getElementById('msgs'),
		err: document.getElementById('err'),
		empty: document.getElementById('empty'),
		newchat: document.getElementById('newchat'),
		chatlist: document.getElementById('chatlist'),
	}

	var messages = []
	var refs = []
	var sending = false
	var currentAbort = null
	var stoppedByUser = false
	var lastMd = 0
	// Coalesce DOM updates during token streaming: several NDJSON events can
	// arrive within one animation frame, and every updateMessage rebuilds the
	// tool rows and re-renders Markdown. One update per frame keeps the
	// perceived latency identical while removing redundant reflows.
	var pendingUpdateIdx = null
	var updateScheduled = false
	function scheduleUpdate(i) {
		pendingUpdateIdx = i
		if (updateScheduled) return
		updateScheduled = true
		var flush = function () {
			updateScheduled = false
			var idx = pendingUpdateIdx
			pendingUpdateIdx = null
			if (idx !== null) updateMessage(idx)
		}
		if (typeof requestAnimationFrame === 'function') requestAnimationFrame(flush)
		else flush()
	}
	var chatId = null
	// Title of the open chat (null until a restored/new chat reported one).
	var chatTitle = null
	var exportButton = document.getElementById('export')

	function localizePage() {
		document.title = tr('Chat with your files')
		var topLink = document.querySelector('#topbar .toplink')
		if (topLink) topLink.textContent = tr('Back to overview')
		if (els.newchat) els.newchat.textContent = '+ ' + tr('New chat')
		var navItems = document.querySelectorAll('#sidebar .nav-item')
		if (navItems[0]) navItems[0].lastChild.textContent = ' ' + tr('Documents')
		if (navItems[1]) navItems[1].lastChild.textContent = ' ' + tr('Settings')
		var heading = document.querySelector('.head h1')
		if (heading) heading.textContent = tr('Chat with your files')
		var exportButton = document.getElementById('export')
		if (exportButton) {
			exportButton.title = tr('Export chat as Markdown')
			exportButton.innerHTML = '&#11015; ' + tr('Export')
		}
		var emptyTitle = document.querySelector('#empty .t')
		if (emptyTitle) emptyTitle.textContent = tr('Ask a question about your files')
		var emptyDescription = document.querySelector('#empty .d')
		if (emptyDescription) emptyDescription.textContent = tr('Ask about notes, plans or files — I can even create files, write notes and remember personal facts in a KNOWLEDGE.md.')
		if (els.input) els.input.placeholder = tr('What does my note about X say?')
		if (els.send) els.send.textContent = tr('Send')
	}
	localizePage()

	function exportMarkdown() {
		var lines = ['# ' + tr('Chat with your files'), '']
		lines.push('_' + tr('Exported {date}', { date: new Date().toISOString() }) + '_')
		messages.forEach(function (m) {
			lines.push('', '## ' + (m.role === 'user' ? tr('You') : 'EVA'), '', m.text || '')
		})
		var blob = new Blob([lines.join('\n')], { type: 'text/markdown' })
		var url = URL.createObjectURL(blob)
		var a = document.createElement('a')
		a.href = url
		a.download = 'eva-chat-' + (chatId || 'export') + '.md'
		document.body.appendChild(a)
		a.click()
		a.remove()
		URL.revokeObjectURL(url)
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
		}
		if (body) {
			opts.headers['Content-Type'] = 'application/json'
			opts.body = JSON.stringify(body)
		}
		return fetch(API_BASE + path, opts)
			.then(function (r) {
				return r.text().then(function (text) {
					var json = null
					try { json = text ? JSON.parse(text) : null } catch (e) {}
					if (!r.ok) {
						var detail = json && json.ocs && json.ocs.message || json && json.ocs && json.ocs.data && json.ocs.data.error || json && json.error || text
						throw new Error(('HTTP ' + r.status + ' ' + String(detail || '')).trim().slice(0, 260))
					}
					return json && json.ocs && typeof json.ocs.data !== 'undefined' ? json.ocs.data : json
				})
			})
	}

	function toolRow(c) {
		var row = document.createElement('div')
		row.className = 'tool ' + (c.state === 'running' ? 'running' : c.state === 'ok' ? 'ok' : 'bad')
		row.textContent = (c.state === 'running' ? '🛠 ' : c.state === 'ok' ? '✅ ' : '❌ ') + c.name + (c.state === 'running' ? ' …' : '')
		return row
	}

	function buildShareForm(args) {
		var form = document.createElement('div')
		form.className = 'rconfirm-share-form'
		var inputs = {}
		function field(key, labelText, type, value) {
			var wrap = document.createElement('label')
			wrap.className = 'rconfirm-field'
			var caption = document.createElement('span')
			caption.textContent = labelText
			var input = document.createElement(type === 'textarea' ? 'textarea' : 'input')
			if (type !== 'textarea') input.type = type
			input.value = value || ''
			if (key === 'path') input.required = true
			wrap.appendChild(caption)
			wrap.appendChild(input)
			form.appendChild(wrap)
			inputs[key] = input
			return input
		}
		field('path', tr('File or folder path'), 'text', args.path)
		var typeWrap = document.createElement('label')
		typeWrap.className = 'rconfirm-field'
		var typeCaption = document.createElement('span')
		typeCaption.textContent = tr('Share type')
		var typeSelect = document.createElement('select')
		;[['link', tr('Public link')], ['user', tr('Nextcloud user')], ['group', tr('Nextcloud group')]].forEach(function (entry) {
			var option = document.createElement('option')
			option.value = entry[0]
			option.textContent = entry[1]
			typeSelect.appendChild(option)
		})
		typeSelect.value = args.type === 'public' ? 'link' : (args.type || 'link')
		typeWrap.appendChild(typeCaption)
		typeWrap.appendChild(typeSelect)
		form.appendChild(typeWrap)
		inputs.type = typeSelect
		var target = field('target', tr('Recipient user or group'), 'text', args.target)
		var password = field('password', tr('Link password (optional)'), 'password', args.password)
		password.autocomplete = 'new-password'
		field('expiration', tr('Expiration date (optional)'), 'date', args.expiration)
		field('note', tr('Message or note (optional)'), 'textarea', args.note)
		function check(key, text, checked) {
			var wrap = document.createElement('label')
			wrap.className = 'rconfirm-check'
			var input = document.createElement('input')
			input.type = 'checkbox'
			input.checked = !!checked
			var caption = document.createElement('span')
			caption.textContent = text
			wrap.appendChild(input)
			wrap.appendChild(caption)
			form.appendChild(wrap)
			inputs[key] = input
		}
		check('write', tr('Allow editing'), args.write)
		check('share', tr('Allow resharing'), args.share)
		function sync() {
			var link = typeSelect.value === 'link'
			target.parentNode.style.display = link ? 'none' : ''
			password.parentNode.style.display = link ? '' : 'none'
			args.type = typeSelect.value
			args.path = inputs.path.value.trim()
			args.target = target.value.trim()
			args.password = password.value
			args.expiration = inputs.expiration.value
			args.note = inputs.note.value
			args.write = inputs.write.checked
			args.share = inputs.share.checked
			return args.path !== '' && (link || args.target !== '')
		}
		typeSelect.addEventListener('change', sync)
		form.addEventListener('input', sync)
		form.__sync = sync
		sync()
		return form
	}

	function renderMsg(m, idx) {
		var wrap = document.createElement('div')
		wrap.className = 'rm ' + m.role

		var b = document.createElement('div')
		b.className = 'rb'
		b.style.background = m.role === 'user' ? 'var(--color-primary-element, #00679c)' : 'var(--color-background-hover, #f1f2f4)'
		b.style.color = m.role === 'user' ? 'var(--color-primary-element-text, #fff)' : 'var(--color-main-text, #111)'

		if (m.role === 'assistant') {
			var det = document.createElement('details')
			det.className = 'rth'
			det.style.display = 'none'
			var sum = document.createElement('summary')
			sum.textContent = '🧠 ' + tr('Thinking…')
			var th = document.createElement('div')
			th.className = 'rth-c'
			det.appendChild(sum)
			det.appendChild(th)
			b.appendChild(det)
		}

		var t = document.createElement('div')
		t.className = 'rt'
		if (m.role === 'assistant' && m.text && m.done) {
			t.innerHTML = mdToHtml(m.text)
			installImageFallback(t)
		} else {
			t.textContent = m.text || (m.role === 'assistant' ? '…' : '')
		}
		b.appendChild(t)
		if (m.role === 'assistant') {
			var cb = document.createElement('button')
			cb.className = 'rcopy'
			cb.title = tr('Copy answer')
			cb.textContent = '⧉'
			cb.addEventListener('click', function () { copyText(String(m.text || ''), cb) })
			b.appendChild(cb)
		}
		if (m.role === 'user' && m.done) {
			var cb2 = document.createElement('button')
			cb2.className = 'rcopy'
			cb2.title = tr('Copy message')
			cb2.textContent = '⧉'
			cb2.addEventListener('click', function () { copyText(String(m.text || ''), cb2) })
			b.appendChild(cb2)
		}
		wrap.appendChild(b)

		if (m.tools && m.tools.length) {
			var ta = document.createElement('div')
			ta.className = 'rtools'
			m.tools.forEach(function (c) {
				ta.appendChild(toolRow(c))
			})
			wrap.appendChild(ta)
		}

		if (m.sources && m.sources.length) {
			var details = document.createElement('details')
			details.className = 'rs'
			var sumEl = document.createElement('summary')
			sumEl.className = 'rs-sum'
			sumEl.textContent = tr('Sources') + ' (' + m.sources.length + ')'
			details.appendChild(sumEl)
			var list = document.createElement('div')
			list.className = 'rs-list'
			m.sources.forEach(function (item) {
				var src = item.src || item
				var row = document.createElement('div')
				row.className = 'rs-item'
				var a = document.createElement('a')
				a.href = src.url || '#'
				a.target = '_blank'
				a.rel = 'noopener'
				var prefix = item.ref !== undefined ? '[' + item.ref + '] ' : ''
				a.textContent = prefix + (src.path || src.name || '')
				row.appendChild(a)
				if (src.excerpts && src.excerpts.length) {
					var ex = document.createElement('div')
					ex.className = 'rs-excerpt'
					ex.textContent = src.excerpts[0]
					row.appendChild(ex)
				}
				list.appendChild(row)
			})
			details.appendChild(list)
			wrap.appendChild(details)
		}
		if (m.followups && m.followups.length) {
			var chips = document.createElement('div')
			chips.className = 'rfu'
			m.followups.forEach(function (q) {
				var btn = document.createElement('button')
				btn.type = 'button'
				btn.className = 'rfu-btn'
				btn.textContent = q
				btn.addEventListener('click', function () {
					// Fill the input and use the same send path as a manual submit.
					if (sending) return
					els.input.value = q
					send()
				})
				chips.appendChild(btn)
			})
			wrap.appendChild(chips)
		}
		if (m.confirmation && !m.confirmation.resolved) {
			var panel = document.createElement('div')
			panel.className = 'rconfirm'
			var danger = (m.confirmation.risk || 'mutating') === 'destructive'
			if (danger) panel.classList.add('rconfirm--danger')
			var label = document.createElement('div')
			label.className = 'rconfirm-label'
			label.textContent = tr('EVA wants to run: {tool}', { tool: m.confirmation.name })
			var args = m.confirmation.arguments || {}
			var shareForm = m.confirmation.name === 'create_share' ? buildShareForm(args) : null
			var calendarForm = m.confirmation.name === 'create_calendar_event' ? buildCalendarForm(args, tr) : null
			var details = document.createElement('pre')
			details.className = 'rconfirm-args'
			if (shareForm || calendarForm) {
				details.textContent = shareForm
					? tr('Review the share details before creating it. You can change the path, recipient, password and expiration date.')
					: tr('Please review the calendar event details before creating it.')
			} else {
				details.textContent = JSON.stringify(args, null, 2)
			}
			var actions = document.createElement('div')
			actions.className = 'rconfirm-actions'
			var approve = document.createElement('button')
			approve.type = 'button'
			approve.className = 'rconfirm-approve'
			approve.textContent = tr('Confirm and run')
			var reject = document.createElement('button')
			reject.type = 'button'
			reject.className = 'rconfirm-reject'
			reject.textContent = tr('Cancel')
			var finish = function (text) {
				m.confirmation.resolved = true
				m.text = text
				m.done = true
				renderAll(messages)
				// The resolved payload replaces the stored pending placeholder so
				// approving after a reload does not duplicate the answer
				// (Issue #185).
				saveMessage('assistant', m.text, null, m.confirmation).then(renderChatListAgain)
			}
			approve.addEventListener('click', function () {
				approve.disabled = true
				reject.disabled = true
				approve.textContent = tr('Running…')
				var editableForm = shareForm || calendarForm
				if (editableForm && editableForm.__sync && !editableForm.__sync()) {
					approve.disabled = false
					reject.disabled = false
					approve.textContent = tr('Confirm and run')
					return
				}
				// Wait for the pending placeholder (and its idempotency token) to
				// be stored before running the action so the server-side claim can
				// reject a duplicate approve after a reload (Issue #185).
				Promise.resolve(m._pendingSave || true).then(function () {
					return api('POST', '/confirmTool', {
						name: m.confirmation.name,
						arguments: args,
						chatId: chatId,
						confirmationToken: m.confirmation.token || '',
					})
				}).then(function (result) {
						if (!result || !result.ok) {
							finish('⚠️ ' + (result && result.error || tr('The action could not be completed.')))
							return
						}
						var value = tr('The action was completed.')
						if (typeof result.result === 'string') value = result.result
						else if (result.result && result.result.url) value = tr('Share created: {url}', { url: result.result.url })
						finish('✅ ' + value)
					})
					.catch(function (error) { finish('⚠️ ' + String(error && error.message || error)) })
			})
			reject.addEventListener('click', function () { finish(tr('Action cancelled.')) })
			actions.appendChild(approve)
			actions.appendChild(reject)
			panel.appendChild(label)
			panel.appendChild(details)
			if (shareForm) panel.appendChild(shareForm)
			if (calendarForm) panel.appendChild(calendarForm)
			panel.appendChild(actions)
			wrap.appendChild(panel)
		}

		els.msgs.appendChild(wrap)
		if (els.empty) {
			els.empty.style.display = 'none'
			els.empty = null
		}
		if (idx !== undefined) refs[idx] = wrap
		els.msgs.scrollTop = els.msgs.scrollHeight
	}

	function renderAll(list) {
		refs.length = 0
		while (els.msgs.firstChild) {
			els.msgs.removeChild(els.msgs.firstChild)
		}
		if (!list.length && els.empty) {
			els.msgs.appendChild(els.empty)
			els.empty.style.display = ''
		}
		list.forEach(function (m, i) { renderMsg(m, i) })
		if (exportButton) exportButton.disabled = list.length === 0
		els.msgs.scrollTop = els.msgs.scrollHeight
	}

	function updateMessage(i) {
		var m = messages[i]
		var wrap = refs[i]
		if (!wrap || !m) return
		var rt = wrap.querySelector('.rt')
		var det = wrap.querySelector('.rth')
		var th = wrap.querySelector('.rth-c')
		if (rt) {
			var now = Date.now()
			if (m.done) {
				rt.innerHTML = m.text ? mdToHtml(m.text) : ''
				installImageFallback(rt)
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
		var ta = wrap.querySelector('.rtools')
		if (m.tools && m.tools.length) {
			if (!ta) {
				ta = document.createElement('div')
				ta.className = 'rtools'
				wrap.appendChild(ta)
			}
			ta.innerHTML = ''
			m.tools.forEach(function (c) {
				ta.appendChild(toolRow(c))
			})
		} else if (ta) {
			ta.remove()
		}
		// Once the answer is complete, (re-)render sources and follow-up
		// chips: they only exist after the stream is done.
		if (m.done) {
			var oldSrc = wrap.querySelector('.rs')
			if (oldSrc) oldSrc.remove()
			var oldFu = wrap.querySelector('.rfu')
			if (oldFu) oldFu.remove()
			var anchor = wrap.querySelector('.rconfirm-link, .rconfirm')
			if (m.sources && m.sources.length) {
				var ds = renderSources(m)
				if (anchor) wrap.insertBefore(ds, anchor)
				else wrap.appendChild(ds)
			}
			if (m.followups && m.followups.length) {
				var fu = renderFollowups(m)
				if (anchor) wrap.insertBefore(fu, anchor)
				else wrap.appendChild(fu)
			}
		}
		stickToBottom()
	}

	function renderSources(m) {
		var details = document.createElement('details')
		details.className = 'rs'
		var sumEl = document.createElement('summary')
		sumEl.className = 'rs-sum'
		sumEl.textContent = tr('Sources') + ' (' + m.sources.length + ')'
		details.appendChild(sumEl)
		var list = document.createElement('div')
		list.className = 'rs-list'
		m.sources.forEach(function (item) {
			var src = item.src || item
			var row = document.createElement('div')
			row.className = 'rs-item' + (src.external ? ' rs-item-external' : '')
			var a = document.createElement('a')
			a.href = src.url || '#'
			a.target = '_blank'
			a.rel = 'noopener'
			var prefix = item.ref !== undefined ? '[' + item.ref + '] ' : ''
			a.textContent = prefix + (src.path || src.name || '')
			row.appendChild(a)
			// A web source is labelled and shows the site, so it is never mistaken
			// for one of the user's own files.
			if (src.external) {
				var badge = document.createElement('span')
				badge.className = 'rs-badge'
				badge.textContent = tr('Web')
				row.insertBefore(badge, a)
				if (src.host) {
					var hostEl = document.createElement('span')
					hostEl.className = 'rs-host'
					hostEl.textContent = src.host
					row.appendChild(hostEl)
				}
			}
			if (src.excerpts && src.excerpts.length) {
				var ex = document.createElement('div')
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
		var chips = document.createElement('div')
		chips.className = 'rfu'
		m.followups.forEach(function (q) {
			var btn = document.createElement('button')
			btn.type = 'button'
			btn.className = 'rfu-btn'
			btn.textContent = q
			btn.addEventListener('click', function () {
				if (sending) return
				els.input.value = q
				send()
			})
			chips.appendChild(btn)
		})
		return chips
	}

	function apiStream(body, onLine, signal) {
		if (!STREAM_URL) {
			return Promise.reject(new Error(tr('No streaming endpoint')))
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
			signal: signal,
		}).then(function (r) {
			if (!r.ok || !r.body) {
				return r.text().then(function (t) { throw new Error('HTTP ' + r.status + ' ' + (t || '').slice(0, 200)) })
			}
			return readNdjson(r.body, onLine)
		})
	}

	function showErr(msg) {
		els.err.textContent = msg
		els.err.style.display = msg ? 'block' : 'none'
	}

	function saveMessage(role, text, followups, confirmation) {
		if (!chatId) return Promise.resolve(false)
		var body = { role: role, text: text }
		// Follow-up suggestions are persisted for assistant messages so the
		// chips survive a page reload.
		if (role === 'assistant' && Array.isArray(followups) && followups.length) {
			body.followups = followups
		}
		// A pending tool confirmation rides on the assistant message so a
		// reload rebuilds the inline panel (Issue #185).
		if (role === 'assistant' && confirmation) body.confirmation = confirmation
		return api('POST', '/chats/' + encodeURIComponent(chatId) + '/messages', body)
			.then(function () { return true })
			.catch(function () { return false })
	}

	// Only follow the stream down while the user is already near the bottom:
	// scrolling up to read earlier context must not be yanked back on every
	// streamed delta.
	function stickToBottom(force) {
		var nearBottom = els.msgs.scrollHeight - els.msgs.scrollTop - els.msgs.clientHeight < 120
		if (force || nearBottom) els.msgs.scrollTop = els.msgs.scrollHeight
	}

	// The server derives the title of an untitled chat from its first user
	// message, so the header follows once that message has been persisted.
	function refreshTitle() {
		if (chatTitle !== null || !chatId) return
		api('GET', '/chats/' + encodeURIComponent(chatId)).then(function (chat) {
			if (chat && chat.title) {
				chatTitle = chat.title
				var heading = document.querySelector('.head h1')
				if (heading) heading.textContent = chat.title
			}
		}).catch(function () {})
	}
	function saveUserMessage(text) {
		return saveMessage('user', text).then(function (ok) {
			if (ok) refreshTitle()
			return ok
		})
	}

	function ensureChat() {
		if (chatId) return Promise.resolve(true)
		return api('POST', '/chats', {})
			.then(function (c) {
				chatId = (c && c.id) || null
				return !!chatId
			})
			.catch(function () { return false })
	}

	function loadChat(id) {
		return api('GET', '/chats/' + encodeURIComponent(id)).then(function (chat) {
			messages.length = 0
			;(chat.messages || []).forEach(function (m) {
				messages.push({
					role: m.role === 'user' || m.role === 'assistant' ? m.role : 'assistant',
					text: m.text || '',
					thinking: '',
					followups: Array.isArray(m.followups) ? m.followups : [],
					// A persisted pending confirmation re-renders the inline panel so
					// approving after a reload still works (Issue #185).
					confirmation: m.confirmation || null,
					done: true,
				})
			})
			// The header shows the real chat title instead of the generic
			// placeholder once the conversation has been given one.
			chatTitle = (chat && chat.title) || null
			var heading = document.querySelector('.head h1')
			if (heading) heading.textContent = chatTitle || tr('Chat with your files')
			renderAll(messages)
		})
	}

	function renderChatList(chats) {
		if (!els.chatlist) return
		while (els.chatlist.firstChild) els.chatlist.removeChild(els.chatlist.firstChild)
		if (!chats || !chats.length) {
			var empty = document.createElement('div')
			empty.className = 'chat-empty'
			empty.textContent = tr('No chats yet.')
			els.chatlist.appendChild(empty)
			return
		}
		chats.forEach(function (c) {
			var entry = document.createElement('div')
			entry.className = 'chat-entry' + (c.id === chatId ? ' active' : '')
			var t = document.createElement('span')
			t.className = 't'
			t.textContent = c.title || tr('New chat')
			t.addEventListener('click', function () {
				chatId = c.id
				loadChat(c.id).then(renderChatListAgain)
			})
			var x = document.createElement('button')
			x.className = 'x'
			x.textContent = '✕'
			x.title = tr('Delete chat')
			x.addEventListener('click', function (e) {
				e.stopPropagation()
				if (!window.confirm(tr('Delete chat "{title}"?', { title: c.title }))) return
				api('DELETE', '/chats/' + encodeURIComponent(c.id)).then(function () {
					if (chatId === c.id) {
						chatId = null
						messages.length = 0
						renderAll(messages)
					}
					refreshChats()
				})
			})
			entry.appendChild(t)
			entry.appendChild(x)
			els.chatlist.appendChild(entry)
		})
	}

	function refreshChats() {
		api('GET', '/chats').then(function (list) {
			if (els.newchat) els.newchat.disabled = false
			renderChatList(Array.isArray(list) ? list : [])
			if (!chatId && Array.isArray(list) && list.length) {
				chatId = list[0].id
				loadChat(chatId)
			}
		}).catch(function () {
			if (els.newchat) els.newchat.disabled = false
		})
	}

	function renderChatListAgain() {
		api('GET', '/chats').then(function (list) {
			renderChatList(Array.isArray(list) ? list : [])
		}).catch(function () {})
	}

	function send() {
		var msg = els.input.value.trim()
		if (!msg || sending) return
		sending = true
		currentAbort = new AbortController()
		stoppedByUser = false
		els.input.value = ''
		els.send.disabled = false
		els.send.textContent = tr('Stop')
		els.send.classList.add('stop')
		showErr('')

		messages.push({ role: 'user', text: msg })
		messages.push({ role: 'assistant', text: '', thinking: '', done: false, tools: [] })
		renderAll(messages)

		var history = []
		for (var i = 0; i < messages.length - 2; i++) {
			history.push({ role: messages[i].role, content: messages[i].text })
		}

		ensureChat().then(function () {
			return apiStream({ message: msg, history: history, chatId: chatId }, function (ev) {
				var last = messages[messages.length - 1]
				if (!last || last.role !== 'assistant' || last.done) return
				if (ev.type === 'thinking') {
					last.thinking += ev.delta || ''
				} else if (ev.type === 'content') {
					last.text += ev.delta || ''
				} else if (ev.type === 'tool') {
					last.tools = last.tools || []
					last.tools.push({ name: ev.name || '?', state: 'running' })
				} else if (ev.type === 'tool_result') {
					// Match by name from the end: several tools can run in the
					// same round, and their results may arrive in any order.
					if (last.tools && last.tools.length) {
						for (var ti = last.tools.length - 1; ti >= 0; ti--) {
							if (last.tools[ti].name === ev.name && last.tools[ti].state === 'running') {
								last.tools[ti].state = ev.ok ? 'ok' : 'bad'
								break
							}
						}
					}
				} else if (ev.type === 'confirmation') {
					last.confirmation = {
						name: ev.name || '?',
						arguments: ev.arguments || {},
						risk: ev.risk || 'mutating',
						resolved: false,
					}
					last.text = tr('Please review this action and confirm it explicitly.')
					last.done = true
					// Persist the pending confirmation with the placeholder so a
					// reload rebuilds the panel instead of leaving a dangling
					// question (Issue #185). The idempotency token makes approve
					// safe: the same action cannot run twice after a reload.
					last.confirmation.token = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2)
					last._pendingSave = saveUserMessage(msg)
						.then(function (savedUser) { return savedUser ? saveMessage('assistant', last.text, null, {
							name: last.confirmation.name,
							arguments: last.confirmation.arguments,
							risk: last.confirmation.risk,
							token: last.confirmation.token,
							resolved: false,
						}) : false })
						.catch(function () { return false })
					renderAll(messages)
				} else if (ev.type === 'done') {
					last.text = ev.answer || last.text
					last.sources = citedSources(last.text, ev.sources || [])
					last.followups = ev.followups || []
					last.done = true
					// Persist the pair in conversation order. Sending both requests
					// at once lets the per-user file lock acquire them in either
					// order, which can swap the question and answer after a reload.
					saveUserMessage(msg)
						.then(function (savedUser) { return savedUser ? saveMessage('assistant', last.text, last.followups) : false })
						.then(renderChatListAgain)
						.catch(function () {})
				} else if (ev.type === 'error') {
					last.text = '⚠️ ' + ev.message
					last.done = true
					saveUserMessage(msg)
				}
				// One coalesced update per frame instead of one DOM rebuild per
				// NDJSON event; terminal states still update immediately.
				scheduleUpdate(messages.length - 1)
			}, currentAbort.signal).catch(function (e) {
				var last = messages[messages.length - 1]
				if (last && last.role === 'assistant' && !last.done) {
					if (!stoppedByUser) {
						last.text = (last.text || '') + '⚠️ ' + tr('Error: {error}', { error: String(e && e.message ? e.message : e) })
					}
					last.done = true
					updateMessage(messages.length - 1)
					// Persist the partial answer when the user stopped the stream so
					// a reload keeps the conversation instead of dropping it.
					if (stoppedByUser && last.text.trim() !== '') {
						saveUserMessage(msg)
							.then(function (ok) { return ok ? saveMessage('assistant', last.text) : false })
							.catch(function () {})
					}
				}
				if (!stoppedByUser) {
					showErr(tr('Network error — see console.'))
				}
			}).finally(function () {
				stoppedByUser = false
				currentAbort = null
				sending = false
				els.send.disabled = false
				els.send.textContent = tr('Send')
				els.send.classList.remove('stop')
				els.input.focus()
				stickToBottom()
			})
		})
	}

	if (els.form) els.form.addEventListener('submit', function (e) {
		e.preventDefault()
		send()
	})
	if (els.send) els.send.addEventListener('click', function () {
		if (sending) {
			stoppedByUser = true
			if (currentAbort) currentAbort.abort()
			return
		}
		send()
	})
	if (els.newchat) els.newchat.addEventListener('click', function () {
		if (els.newchat.disabled) return
		els.newchat.disabled = true
		api('POST', '/chats', {}).then(function (c) {
			if (!c || !c.id) throw new Error('no id')
			chatId = c.id
			messages.length = 0
			renderAll(messages)
			return refreshChats()
		}).catch(function () {
			els.newchat.disabled = false
		})
	})
	if (exportButton) exportButton.addEventListener('click', exportMarkdown)
	refreshChats()
})()
