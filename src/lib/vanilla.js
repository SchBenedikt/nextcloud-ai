import './markdown.css'
import { createApp } from 'vue'
import ChatActions from '../components/ChatActions.vue'
import { readNdjson } from './ndjson'
import { mdiDownload } from '@mdi/js'
import { translate as t } from './i18n'
import { buildConfirmForm } from './confirmForms'
import { mdToHtml, citedSources, formatToolName, copyText, installImageFallback, apiErrorMessage } from './chat-utils'
import { createChatExport, createChatArchive } from './chat-export'
import { createPromptHistory } from './prompt-history'

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
	let pendingTemplatePersona = ''
	// Title of the open chat (displayed in the header once known; null until
	// a restored/new chat has told us its real title).
	let chatTitle = null

	const meta = (name) => {
		const el = document.head.querySelector('meta[name="' + name + '"]')
		return el ? el.getAttribute('content') : ''
	}
	const API_BASE = meta('eva-ai-api')
	const REQUEST_TOKEN = meta('requesttoken')
	const STREAM_URL = meta('eva-ai-stream') || ''
	let promptStorage
	try { promptStorage = window.localStorage } catch (_) { promptStorage = { getItem: () => null, setItem: () => {} } }
	const promptHistory = createPromptHistory(promptStorage, 'eva-ai.prompts.' + encodeURIComponent(meta('oc-userid') || window.OC?.currentUser || 'default'))

	const STORE_KEY = 'eva-ai.conv'
	const messages = []
	const selectedMessageIndexes = new Set()
	let selectionMode = false
	let selectionChatId = null
	let sending = false
	let destroyed = false
	let currentAbort = null
	let stoppedByUser = false
	let queuedOnUnload = false
	let backgroundRequestId = null
	// Monotonic per-chat revision (Issue #182): every persisted change bumps it
	// server-side, and regenerate/edit requests validate against it so two tabs
	// cannot silently overwrite each other. null until a chat is known.
	let chatRev = null
	// Revision handed back by the /regenerate stream; the persisted answer must
	// carry it so the server commits the deferred truncation atomically.
	let pendingRegenerateRev = null
	const refs = []
	let lastMd = 0
	let trimmedMessages = 0

	// Coalesce DOM updates during token streaming: several NDJSON events can
	// arrive within one animation frame, and every updateMessage rebuilds the
	// tool rows and re-renders Markdown. Scheduling one update per frame keeps
	// the perceived latency identical while removing redundant reflows on
	// fast models and high-frequency streams.
	let pendingUpdateIdx = null
	let updateScheduled = false
	let pendingUpdateFrame = null
	function scheduleUpdate(i) {
		pendingUpdateIdx = i
		if (updateScheduled) return
		updateScheduled = true
		const flush = () => {
			updateScheduled = false
			const idx = pendingUpdateIdx
			pendingUpdateIdx = null
			if (idx !== null && Number.isInteger(idx)) updateMessage(idx)
		}
		if (typeof requestAnimationFrame === 'function') pendingUpdateFrame = requestAnimationFrame(() => { pendingUpdateFrame = null; flush() })
		else flush()
	}

	function api(method, path, body, { timeout = 0 } = {}) {
		return new Promise((resolve, reject) => {
			const historyWrite = method === 'POST' && /\/(messages|turns)$/.test(path)
			const deadline = historyWrite ? 15000 : timeout
			const controller = deadline > 0 ? new AbortController() : null
			const timer = controller ? setTimeout(() => { controller.abort(); reject(new Error('Chat request timed out')) }, deadline) : null
			const opts = {
				method,
				credentials: 'same-origin',
				headers: {
					'OCS-APIRequest': 'true',
					'Accept': 'application/json',
					'requesttoken': REQUEST_TOKEN,
				},
			}
			if (controller) opts.signal = controller.signal
			if (body) {
				opts.headers['Content-Type'] = 'application/json'
				opts.body = JSON.stringify(body)
				// Bounded history writes may finish when the page closes.
				if (historyWrite && new TextEncoder().encode(opts.body).length < 60000) opts.keepalive = true
			}
			const request = async (attempt = 0) => {
				try {
					const r = await fetch(API_BASE + path, opts)
					const text = await r.text()
					let json = null
					try { json = text ? JSON.parse(text) : null } catch (_) {}
					if (!r.ok) {
						// Only repeat idempotent reads for brief lock/rate contention.
						// Never replay a write or a model-generation request.
						if (method === 'GET' && [408, 423, 429, 503].includes(r.status) && attempt < 2) {
							await new Promise((done) => setTimeout(done, 200 * (attempt + 1) + Math.floor(Math.random() * 100)))
							return request(attempt + 1)
						}
						const detail = apiErrorMessage(json) || text
						throw new Error(('HTTP ' + r.status + ' ' + String(detail || '')).trim().slice(0, 260))
					}
					const data = json && json.ocs && typeof json.ocs.data !== 'undefined' ? json.ocs.data : json
					if (path.endsWith('/meta') && Number.isSafeInteger(data?.rev)) chatRev = Math.max(chatRev ?? 0, data.rev)
					resolve(data)
				} catch (error) {
					if (method === 'GET' && attempt < 2 && error?.name === 'TypeError') {
						await new Promise((done) => setTimeout(done, 200 * (attempt + 1) + Math.floor(Math.random() * 100)))
						return request(attempt + 1)
					}
					reject(error)
				} finally {
					if (timer) clearTimeout(timer)
				}
			}
			request()
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

	function exportChat() {
		const format = exportFormat.value
		const exportMessages = selectionMode ? messages.filter((_, index) => selectedMessageIndexes.has(index)) : messages
		if (selectionMode && exportMessages.length === 0) return
		let file
		try { file = createChatExport(exportMessages, {
			format,
			title: t('Eva chat export'),
			language: document.documentElement.lang || 'en',
			includeTimestamps: exportTimestampToggle.checked,
			includeModelInfo: exportModelToggle.checked,
			includeUserName: exportUserNameToggle.checked,
			userName: document.querySelector('meta[name="eva-ai-display-name"]')?.content || '',
			fromDate: exportFromDate.value,
			toDate: exportToDate.value,
				labels: {
					you: t('You'), eva: 'Eva',
				exportedAt: (date) => t('Exported {date}', { date }),
				timestamp: (date) => t('Sent {date}', { date }),
				model: (name) => t('Model: {name}', { name }),
			},
		}) } catch (error) { showErr(t(error?.message || 'Could not export this chat.')); return }
		if (file.print) {
			const printWindow = window.open('', '_blank')
			if (printWindow) {
				printWindow.document.open()
				printWindow.document.write(file.content)
				printWindow.document.close()
				printWindow.focus()
				setTimeout(() => printWindow.print(), 250)
			}
			return
		}
		const blob = new Blob([file.content], { type: file.mime })
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = 'eva-chat-' + (chatId || 'export') + '.' + file.extension
		document.body.appendChild(a)
		a.click()
		a.remove()
		setTimeout(() => URL.revokeObjectURL(url), 1000)
	}

	async function exportAllChats() {
		exportAllBtn.disabled = true
		showErr('')
		try {
			const summaries = await api('GET', '/chats')
			if (!Array.isArray(summaries)) throw new Error(t('The chat list response was invalid.'))
			const chats = new Array(summaries.length)
			let next = 0; let failed = false
			const worker = async () => {
				while (!failed && next < summaries.length) {
					const index = next++
					try {
						chats[index] = await api('GET', '/chats/' + encodeURIComponent(summaries[index].id))
					} catch (error) {
						failed = true
						throw error
					}
				}
			}
			await Promise.all(Array.from({ length: Math.min(4, summaries.length) }, worker))
			const format = exportFormat.value
			const archive = createChatArchive(chats, {
				format,
				language: document.documentElement.lang || 'en',
				includeTimestamps: exportTimestampToggle.checked,
				includeModelInfo: exportModelToggle.checked,
				includeUserName: exportUserNameToggle.checked,
				userName: document.querySelector('meta[name="eva-ai-display-name"]')?.content || '',
				fromDate: exportFromDate.value,
				toDate: exportToDate.value,
				labels: { you: t('You'), eva: 'Eva', exportedAt: (date) => t('Exported {date}', { date }), timestamp: (date) => t('Sent {date}', { date }), model: (name) => t('Model: {name}', { name }) },
			})
			const url = URL.createObjectURL(new Blob([archive], { type: 'application/zip' }))
			const link = document.createElement('a')
			link.href = url
			link.download = 'eva-chats-' + new Date().toISOString().slice(0, 10) + '.zip'
			document.body.appendChild(link)
			link.click()
			link.remove()
			setTimeout(() => URL.revokeObjectURL(url), 1000)
		} catch (error) {
			const message = error?.message || String(error)
			const knownErrors = [
				'Print to PDF is available for one chat at a time; choose HTML for a batch archive.',
				'There are no conversations to export.',
				'A chat archive can contain up to 100 conversations at a time.',
				'The chat archive is larger than 50 MB.',
				'A conversation in the archive is invalid.',
				'The export date is invalid.',
				'The start date must be on or before the end date.',
			]
			const detail = knownErrors.includes(message) ? t(message) : message
			showErr(t('Could not export chats: {error}', { error: detail }))
		} finally {
			exportAllBtn.disabled = false
		}
	}

	function renderAnswerActions(m, idx) {
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
		return actBar
	}

	function renderMsg(scroll, emptyEl, m, idx) {
		const wrap = document.createElement('div')
		wrap.className = 'rm ' + m.role
		const selectionLabel = document.createElement('label')
		selectionLabel.className = 'message-export-select'
		selectionLabel.hidden = !selectionMode
		const selectionCheckbox = document.createElement('input')
		selectionCheckbox.type = 'checkbox'
		selectionCheckbox.checked = selectedMessageIndexes.has(idx)
		selectionCheckbox.setAttribute('aria-label', t('Select message {number}', { number: idx + 1 }))
		selectionCheckbox.addEventListener('change', () => {
			if (selectionCheckbox.checked) selectedMessageIndexes.add(idx)
			else selectedMessageIndexes.delete(idx)
			exportBtn.disabled = !messages.length || (selectionMode && selectedMessageIndexes.size === 0)
		})
		selectionLabel.append(selectionCheckbox, document.createTextNode(t('Select')))
		wrap.appendChild(selectionLabel)

		const b = document.createElement('div')
		b.className = 'rb'
		b.style.background = m.role === 'user' ? 'var(--color-primary-element, #00679c)' : 'var(--color-background-hover, #f1f2f4)'
		b.style.color = m.role === 'user' ? 'var(--color-primary-element-text, #fff)' : 'var(--color-main-text, #111)'

		if (m.role === 'assistant') {
			const det = document.createElement('details')
			det.className = 'rth'
			det.style.display = 'none'
			const sum = document.createElement('summary')
			sum.textContent = t('Thinking…')
			const th = document.createElement('div')
			th.className = 'rth-c'
			det.append(sum, th)
			b.appendChild(det)
		}

		const textEl = document.createElement('div')
		textEl.className = 'rt'
		if (m.role === 'user' && Array.isArray(m.attachments) && m.attachments.length) {
			const images = document.createElement('div')
			images.className = 'message-images'
			for (const attachment of m.attachments) {
				if (!attachment || typeof attachment.url !== 'string') continue
				const image = document.createElement('img')
				image.src = attachment.url
				image.alt = attachment.name || t('Attached image')
				images.appendChild(image)
			}
			if (images.childElementCount) b.appendChild(images)
		}
		if (m.role === 'assistant' && m.text && m.done) {
			textEl.innerHTML = mdToHtml(m.text)
			installImageFallback(textEl)
		} else {
			textEl.textContent = m.text || (m.role === 'assistant' ? '…' : '')
		}
		b.appendChild(textEl)
		if (m.role === 'assistant' && m.done && m.model) {
			const modelLabel = document.createElement('div')
			modelLabel.className = 'rmodel'
			modelLabel.textContent = t('Model: {name}', { name: m.model })
			b.appendChild(modelLabel)
		}
		if (m.role === 'assistant' && m.done) b.appendChild(renderAnswerActions(m, idx))
		if (m.role === 'user' && m.done) {
			const actBar = document.createElement('div')
			actBar.className = 'racts'
			const cb2 = document.createElement('button')
			cb2.className = 'ract'
			cb2.title = t('Copy message')
			cb2.textContent = '⧉'
			cb2.addEventListener('click', () => copyText(String(m.text || ''), cb2))
			actBar.appendChild(cb2)
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
				const terminalTrace = c.name === 'run_terminal_command' || c.name === 'run_terminal_sequence'
				const row = document.createElement('div')
				row.className = 'tool ' + (c.state === 'running' ? 'running' : c.state === 'ok' ? 'ok' : 'bad')
				const label = document.createElement('span')
				label.textContent = t(c.state === 'running' ? 'Running' : c.state === 'ok' ? 'Completed' : 'Failed') + ' · ' + formatToolName(c.name) + (c.state === 'running' ? ' …' : '') + (c.elapsed_ms != null ? ' · ' + c.elapsed_ms + ' ms' : '')
				row.appendChild(label)
				if (c.arguments && Object.keys(c.arguments).length) {
					const details = document.createElement('details')
					const summary = document.createElement('summary')
					summary.textContent = t('Details')
					const pre = document.createElement('pre')
					pre.textContent = JSON.stringify(c.arguments, null, 2)
					details.open = terminalTrace
					details.appendChild(summary)
					details.appendChild(pre)
					row.appendChild(details)
				}
				if (c.result !== null && typeof c.result !== 'undefined') {
					const details = document.createElement('details')
					const summary = document.createElement('summary')
					summary.textContent = t('Result')
					const pre = document.createElement('pre')
					pre.textContent = typeof c.result === 'string' ? c.result : JSON.stringify(c.result, null, 2)
					details.open = terminalTrace
					details.appendChild(summary)
					details.appendChild(pre)
					row.appendChild(details)
				}
				if (c.error) {
					const error = document.createElement('div')
					error.className = 'tool-error'
					error.textContent = String(c.error)
					row.appendChild(error)
				}
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
			const conf = m.confirmation.preview ? null : buildConfirmForm(m.confirmation)
			const label = document.createElement('div')
			label.className = 'rconfirm-label'
			label.textContent = conf ? t(conf.title) : t('EVA wants to run: {tool}', { tool: m.confirmation.name })
			panel.appendChild(label)
			const missing = Array.isArray(m.confirmation.missing) ? m.confirmation.missing : []
			const summary = document.createElement('div')
			summary.className = 'rconfirm-summary'
			summary.textContent = missing.length
				? t('Some required details are missing - please complete them below.')
				: (m.confirmation.preview
					? t('Review the file diff before applying this change.')
					: (danger
					? t('This action cannot be undone.')
					: t('Please review this action and confirm it explicitly.')))
			panel.appendChild(summary)
			const errEl = document.createElement('div')
			errEl.className = 'rconfirm-error'
			errEl.style.display = 'none'
			if (conf) {
				panel.appendChild(conf.element)
				// Show which fields still need input right away (reason: missing).
				if (missing.length) conf.validate()
			} else if (m.confirmation.preview) {
				const preview = m.confirmation.preview
				const path = document.createElement('div')
				path.className = 'rconfirm-preview-path'
				path.textContent = t('Proposed change: {path}', { path: preview.path || '' })
				const counts = document.createElement('div')
				counts.className = 'rconfirm-summary'
				counts.textContent = preview.previewable
					? t('Changes: +{added} -{removed} lines', { added: preview.added || 0, removed: preview.removed || 0 })
					: t('A text diff is unavailable for this file; review its path and action before approving.')
				panel.append(path, counts)
				if (preview.previewable) {
					if (Array.isArray(preview.hunks) && preview.hunks.length) {
						for (const hunk of preview.hunks) {
							const row = document.createElement('div')
							row.className = 'rconfirm-hunk'
							const choice = document.createElement('label')
							const input = document.createElement('input')
							input.type = 'checkbox'
							input.checked = true
							input.dataset.hunkId = String(hunk.id)
							const title = document.createElement('span')
							title.textContent = t('Changes: +{added} -{removed} lines', { added: hunk.added || 0, removed: hunk.removed || 0 })
							choice.append(input, title)
							const diff = document.createElement('pre')
							diff.className = 'rconfirm-args rconfirm-diff'
							diff.textContent = String(hunk.diff || '')
							row.append(choice, diff)
							panel.appendChild(row)
						}
					} else {
						const diff = document.createElement('pre')
						diff.className = 'rconfirm-args rconfirm-diff'
						diff.textContent = String(preview.diff || '')
						panel.appendChild(diff)
					}
				}
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
				// A regenerate confirmation must still commit the deferred
				// truncation, so the revision token travels with the answer. The
				// resolved payload replaces the stored pending placeholder
				// (Issue #185) and keeps the result link for a later reload.
				saveMessage('assistant', m.text, null, m.confirmation && m.confirmation.regenerateRev, m.confirmation, m.tools)
					.then((saved) => {
						if (saved && onRecent) onRecent()
						else if (!saved) showHistorySaveWarning()
					})
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
				const approvalArguments = conf ? conf.getArguments() : { ...(m.confirmation.arguments || {}) }
				if (!conf && m.confirmation.preview?.previewable && Array.isArray(m.confirmation.preview.hunks) && m.confirmation.preview.hunks.length) {
					approvalArguments._eva_selected_hunks = Array.from(panel.querySelectorAll('.rconfirm-hunk input:checked')).map((input) => Number(input.dataset.hunkId))
				}
				// Wait for the pending placeholder (and its idempotency token) to
				// be stored before running the action so the server-side claim can
				// reject a duplicate approve after a reload (Issue #185).
				Promise.resolve(m._pendingSave || true).then((saved) => {
					if (saved === false) {
						approve.disabled = true
						reject.disabled = false
						errEl.textContent = t('This action has not run because its confirmation could not be saved.')
						errEl.style.display = ''
						return null
					}
					return api('POST', '/confirmTool', {
						name: m.confirmation.name,
						arguments: approvalArguments,
						chatId,
						confirmationToken: m.confirmation.token || '',
					})
				}).then((result) => {
					if (result === null) return
					if (!result || !result.ok) {
						finish(apiErrorMessage(result) || t('The action could not be completed.'))
						return
					}
					let value = t('The action was completed.')
					let url = ''
					if (typeof result.result === 'string') value = result.result
					else if (result.result && result.result.url) {
						url = result.result.url
						value = t('Share created: {url}', { url })
					}
					finish(value, url)
				}).catch((error) => {
					if (Number(error?.response?.status) === 409) {
						finish(t('This action was already processed. Check the target item before trying again.'))
						return
					}
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
	const imageModeBtn = document.createElement('button')
	imageModeBtn.type = 'button'
	imageModeBtn.className = 'export image-mode-toggle'
	imageModeBtn.textContent = t('Create images')
	imageModeBtn.setAttribute('aria-expanded', 'false')
	const imagePanel = document.createElement('section')
	imagePanel.className = 'image-generator'
	imagePanel.hidden = true
	const imagePanelTitle = document.createElement('h2')
	imagePanelTitle.textContent = t('Generate images')
	const imagePrompt = document.createElement('textarea')
	imagePrompt.maxLength = 1000
	imagePrompt.rows = 3
	imagePrompt.placeholder = t('Describe the image you want to create')
	imagePrompt.setAttribute('aria-label', t('Image prompt'))
	const imageCount = document.createElement('select')
	imageCount.setAttribute('aria-label', t('Number of images'))
	for (let count = 1; count <= 4; count++) {
		const option = document.createElement('option')
		option.value = String(count)
		option.textContent = String(count)
		imageCount.appendChild(option)
	}
	const generateBtn = document.createElement('button')
	generateBtn.type = 'button'
	generateBtn.className = 'cbtn'
	generateBtn.textContent = t('Generate')
	const imagePanelClose = document.createElement('button')
	imagePanelClose.type = 'button'
	imagePanelClose.className = 'cbtn cbtn-ghost'
	imagePanelClose.textContent = t('Close')
	const imagePanelStatus = document.createElement('p')
	imagePanelStatus.className = 'image-generator-status'
	imagePanelStatus.setAttribute('role', 'status')
	const imagePrivacy = document.createElement('p')
	imagePrivacy.className = 'image-generator-privacy'
	imagePrivacy.textContent = t('The prompt is sent to your configured image provider. Generated images are saved in Files / EVA.')
	const imageGallery = document.createElement('div')
	imageGallery.className = 'image-gallery'
	imagePanel.append(imagePanelTitle, imagePrivacy, imagePrompt, imageCount, generateBtn, imagePanelClose, imagePanelStatus, imageGallery)
	const renderGeneratedImages = (images) => {
		imageGallery.replaceChildren()
		for (const image of images) {
			if (!image || typeof image.previewUrl !== 'string') continue
			const card = document.createElement('article')
			card.className = 'generated-image'
			const download = document.createElement('a')
			download.href = typeof image.downloadUrl === 'string' ? image.downloadUrl : image.previewUrl
			download.download = image.name || 'eva-generated.png'
			download.setAttribute('aria-label', t('Download {name}', { name: image.name || t('generated image') }))
			const preview = document.createElement('img')
			preview.src = image.previewUrl
			preview.alt = image.name || t('Generated image')
			download.appendChild(preview)
			const name = document.createElement('span')
			name.textContent = image.name || t('Generated image')
			card.append(download, name)
			imageGallery.appendChild(card)
		}
	}
	const loadGeneratedImages = () => api('GET', '/images').then((result) => {
		const images = Array.isArray(result?.images) ? result.images : []
		renderGeneratedImages(images)
	}).catch((error) => {
		imagePanelStatus.textContent = t('Could not load generated images: {error}', { error: apiErrorMessage(error) })
	})
	imageModeBtn.addEventListener('click', () => {
		imagePanel.hidden = !imagePanel.hidden
		imageModeBtn.setAttribute('aria-expanded', String(!imagePanel.hidden))
		if (!imagePanel.hidden) { imagePrompt.focus(); loadGeneratedImages() }
	})
	imagePanelClose.addEventListener('click', () => {
		imagePanel.hidden = true
		imageModeBtn.setAttribute('aria-expanded', 'false')
		imageModeBtn.focus()
	})
	generateBtn.addEventListener('click', async () => {
		const prompt = imagePrompt.value.trim()
		if (!prompt || prompt.length > 1000) {
			imagePanelStatus.textContent = t('Enter an image prompt between 1 and 1,000 characters.')
			imagePrompt.focus()
			return
		}
		generateBtn.disabled = true
		imagePanelStatus.textContent = t('Generating images…')
		try {
			const result = await api('POST', '/images/generate', { prompt, count: Number(imageCount.value) })
			const images = Array.isArray(result?.images) ? result.images : []
			renderGeneratedImages(images)
			imagePanelStatus.textContent = images.length
				? t('Saved {count} generated images to your EVA folder.', { count: images.length })
				: t('The image provider returned no images.')
		} catch (error) {
			imagePanelStatus.textContent = t('Could not generate images: {error}', { error: apiErrorMessage(error?.response?.data) || error?.message || String(error) })
		} finally {
			generateBtn.disabled = false
		}
	})
	const exportBtn = document.createElement('button')
	exportBtn.id = 'export'
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
	const exportFormat = document.createElement('select')
	exportFormat.className = 'export-format'
	exportFormat.setAttribute('aria-label', t('Export format'))
	;[['md', 'Markdown'], ['txt', t('Plain text')], ['html', 'HTML'], ['docx', t('Word (DOCX)')], ['pdf', t('Print / Save as PDF')]].forEach(([value, label]) => {
		const option = document.createElement('option')
		option.value = value
		option.textContent = label
		exportFormat.append(option)
	})
	const exportSelectionLabel = document.createElement('label')
	exportSelectionLabel.className = 'export-selection-mode'
	const exportSelectionToggle = document.createElement('input')
	exportSelectionToggle.type = 'checkbox'
	exportSelectionToggle.setAttribute('aria-label', t('Select messages for export'))
	const exportSelectionText = document.createElement('span')
	exportSelectionText.textContent = t('Select messages for export')
	exportSelectionLabel.append(exportSelectionToggle, exportSelectionText)
	const exportOptions = document.createElement('details')
	exportOptions.className = 'export-options'
	const exportOptionsSummary = document.createElement('summary')
	exportOptionsSummary.textContent = t('Export options')
	const exportTimestampToggle = document.createElement('input')
	exportTimestampToggle.id = 'export-include-timestamps'
	exportTimestampToggle.type = 'checkbox'
	exportTimestampToggle.checked = true
	exportTimestampToggle.setAttribute('aria-label', t('Include timestamps'))
	const exportTimestampLabel = document.createElement('label')
	exportTimestampLabel.append(exportTimestampToggle, document.createTextNode(t('Include timestamps')))
	const exportModelToggle = document.createElement('input')
	exportModelToggle.id = 'export-include-model'
	exportModelToggle.type = 'checkbox'
	exportModelToggle.checked = true
	exportModelToggle.setAttribute('aria-label', t('Include model information'))
	const exportModelLabel = document.createElement('label')
	exportModelLabel.append(exportModelToggle, document.createTextNode(t('Include model information')))
	const exportUserNameToggle = document.createElement('input')
	exportUserNameToggle.id = 'export-include-user-name'
	exportUserNameToggle.type = 'checkbox'
	exportUserNameToggle.setAttribute('aria-label', t('Include your name'))
	const exportUserNameLabel = document.createElement('label')
	exportUserNameLabel.append(exportUserNameToggle, document.createTextNode(t('Include your name')))
	const exportFromDate = document.createElement('input')
	exportFromDate.id = 'export-from-date'
	exportFromDate.type = 'date'
	exportFromDate.setAttribute('aria-label', t('From date'))
	const exportFromLabel = document.createElement('label')
	exportFromLabel.append(document.createTextNode(t('From date')), exportFromDate)
	const exportToDate = document.createElement('input')
	exportToDate.id = 'export-to-date'
	exportToDate.type = 'date'
	exportToDate.setAttribute('aria-label', t('To date'))
	const exportToLabel = document.createElement('label')
	exportToLabel.append(document.createTextNode(t('To date')), exportToDate)
	exportOptions.append(exportOptionsSummary, exportTimestampLabel, exportModelLabel, exportUserNameLabel, exportFromLabel, exportToLabel)
	exportSelectionToggle.addEventListener('change', () => {
		selectionMode = exportSelectionToggle.checked
		selectedMessageIndexes.clear()
		renderAll(messages)
	})
	const exportAllBtn = document.createElement('button')
	exportAllBtn.className = 'export export-all'
	exportAllBtn.type = 'button'
	exportAllBtn.textContent = t('Export all chats')
	exportAllBtn.addEventListener('click', exportAllChats)
	exportBtn.append(exportIcon, exportLabel)
	exportBtn.disabled = true
	exportBtn.addEventListener('click', exportChat)
	const promptPanel = document.createElement('details')
	promptPanel.className = 'prompt-history'
	const promptSummary = document.createElement('summary')
	promptSummary.textContent = t('Prompt history')
	const promptSearch = document.createElement('input')
	promptSearch.type = 'search'
	promptSearch.placeholder = t('Search prompts')
	promptSearch.setAttribute('aria-label', t('Search prompts'))
	const promptList = document.createElement('div')
	promptList.className = 'prompt-history-list'
	const promptExport = document.createElement('button')
	promptExport.type = 'button'
	promptExport.className = 'cbtn cbtn-ghost'
	promptExport.textContent = t('Export prompt library')
	const renderPromptHistory = () => {
		promptList.replaceChildren()
		const items = promptHistory.list(promptSearch.value)
		if (!items.length) {
			const empty = document.createElement('p')
			empty.className = 'prompt-history-empty'
			empty.textContent = t('No saved prompts match this search.')
			promptList.appendChild(empty)
			return
		}
		items.forEach((item) => {
			const row = document.createElement('div')
			row.className = 'prompt-history-row'
			const reuse = document.createElement('button')
			reuse.type = 'button'
			reuse.className = 'prompt-history-reuse'
			reuse.textContent = item.text
			reuse.title = t('Use prompt')
			reuse.addEventListener('click', () => {
				setInputValue(item.text)
				promptPanel.open = false
				promptPanel.dispatchEvent(new CustomEvent('eva-prompt-chosen', { bubbles: true }))
				input.focus()
			})
			const metaText = document.createElement('span')
			metaText.className = 'prompt-history-meta'
			metaText.textContent = (item.category ? item.category + ' · ' : '') + t('Used {count} times', { count: item.usageCount || 1 })
			const favorite = document.createElement('button')
			favorite.type = 'button'
			favorite.className = 'prompt-history-action'
			favorite.textContent = item.favorite ? '★' : '☆'
			favorite.setAttribute('aria-label', item.favorite ? t('Remove prompt favorite') : t('Favorite prompt'))
			favorite.addEventListener('click', () => { promptHistory.update(item.id, { favorite: !item.favorite }); renderPromptHistory() })
			const category = document.createElement('button')
			category.type = 'button'
			category.className = 'prompt-history-action'
			category.textContent = t('Category')
			category.addEventListener('click', () => {
				const value = window.prompt(t('Prompt category'), item.category || '')
				if (value !== null) { promptHistory.update(item.id, { category: value }); renderPromptHistory() }
			})
			row.append(reuse, metaText, favorite, category)
			promptList.appendChild(row)
		})
	}
	promptSearch.addEventListener('input', renderPromptHistory)
	promptPanel.addEventListener('toggle', () => { if (promptPanel.open) renderPromptHistory() })
	promptExport.addEventListener('click', () => {
		const blob = new Blob([promptHistory.export()], { type: 'application/json' })
		const url = URL.createObjectURL(blob)
		const anchor = document.createElement('a')
		anchor.href = url
		anchor.download = 'eva-prompt-library.json'
		anchor.click()
		URL.revokeObjectURL(url)
	})
	const templatePanel = document.createElement('details')
	templatePanel.className = 'prompt-templates'
	const templateSummary = document.createElement('summary')
	templateSummary.textContent = t('Saved templates')
	const templateSearch = document.createElement('input')
	templateSearch.type = 'search'
	templateSearch.placeholder = t('Search templates')
	templateSearch.setAttribute('aria-label', t('Search templates'))
	const templateList = document.createElement('div')
	templateList.className = 'prompt-template-list'
	const templateStatus = document.createElement('p')
	templateStatus.className = 'prompt-history-meta'
	templateStatus.setAttribute('role', 'status')
	const templateSave = document.createElement('button')
	templateSave.type = 'button'
	templateSave.className = 'cbtn cbtn-ghost'
	templateSave.textContent = t('Save current prompt as template')
	const templateExport = document.createElement('button')
	templateExport.type = 'button'
	templateExport.className = 'cbtn cbtn-ghost'
	templateExport.textContent = t('Export templates')
	const templateImport = document.createElement('button')
	templateImport.type = 'button'
	templateImport.className = 'cbtn cbtn-ghost'
	templateImport.textContent = t('Import templates')
	const templateFile = document.createElement('input')
	templateFile.type = 'file'
	templateFile.accept = 'application/json,.json'
	templateFile.hidden = true
	let savedTemplates = []
	const renderTemplates = async () => {
		templateList.replaceChildren()
		try {
			const result = await api('GET', '/templates')
			savedTemplates = Array.isArray(result) ? result : []
		} catch (error) {
			templateStatus.textContent = t('Templates could not be loaded: {error}', { error: String(error.message || error) })
			return
		}
		const query = templateSearch.value.trim().toLocaleLowerCase()
		const matches = savedTemplates.filter((item) => !query || [item.name, item.description, item.category, item.body].some((value) => String(value || '').toLocaleLowerCase().includes(query)))
		if (!matches.length) {
			const empty = document.createElement('p')
			empty.className = 'prompt-history-empty'
			empty.textContent = t('No saved templates match this search.')
			templateList.appendChild(empty)
			return
		}
		matches.forEach((template) => {
			const row = document.createElement('div')
			row.className = 'prompt-history-row'
			const use = document.createElement('button')
			use.type = 'button'
			use.className = 'prompt-history-reuse'
			use.textContent = template.name
			use.title = template.description || template.name
			use.addEventListener('click', async () => {
				try {
					const values = []
					for (const variable of template.variables || []) {
						const value = window.prompt(t('Value for {name}', { name: variable }), '')
						if (value === null) return
						values.push([variable, value])
					}
					const current = await api('POST', '/templates/' + encodeURIComponent(template.id) + '/use', {})
					let prompt = String(current.body || '')
					for (const [variable, value] of values) prompt = prompt.replace(new RegExp('\\{' + variable + '\\}', 'g'), value)
					setInputValue(prompt)
					if (current.persona) {
						if (chatId) await api('POST', '/chats/' + encodeURIComponent(chatId) + '/meta', { instructions: current.persona })
						else pendingTemplatePersona = current.persona
						refreshCustomizePill({ instructions: current.persona })
					}
					templatePanel.open = false
					input.focus()
					templateStatus.textContent = t('Template ready to use.')
					await renderTemplates()
				} catch (error) { templateStatus.textContent = t('The template could not be used: {error}', { error: String(error.message || error) }) }
			})
			const details = document.createElement('span')
			details.className = 'prompt-history-meta'
			details.textContent = [template.category, template.description, t('Used {count} times', { count: template.usageCount || 0 })].filter(Boolean).join(' · ')
			const edit = document.createElement('button')
			edit.type = 'button'; edit.className = 'prompt-history-action'; edit.textContent = t('Edit')
			edit.addEventListener('click', async () => {
				const name = window.prompt(t('Template name'), template.name)
				if (name === null) return
				const body = window.prompt(t('Template prompt body'), template.body)
				if (body === null) return
				const description = window.prompt(t('Template description'), template.description || '')
				if (description === null) return
				const category = window.prompt(t('Template category'), template.category || '')
				if (category === null) return
				const persona = window.prompt(t('Persona instructions (optional)'), template.persona || '')
				if (persona === null) return
				try {
					await api('POST', '/templates', { id: template.id, name, body, description, category, persona })
					templateStatus.textContent = t('Template saved.')
					await renderTemplates()
				} catch (error) { templateStatus.textContent = t('The template could not be saved: {error}', { error: String(error.message || error) }) }
			})
			const remove = document.createElement('button')
			remove.type = 'button'; remove.className = 'prompt-history-action'; remove.textContent = t('Delete')
			remove.addEventListener('click', async () => {
				if (!window.confirm(t('Delete template “{name}”?', { name: template.name }))) return
				try {
					await api('DELETE', '/templates/' + encodeURIComponent(template.id))
					templateStatus.textContent = t('Template deleted.')
					await renderTemplates()
				} catch (error) { templateStatus.textContent = t('The template could not be deleted: {error}', { error: String(error.message || error) }) }
			})
			row.append(use, details, edit, remove)
			templateList.appendChild(row)
		})
	}
	templateSave.addEventListener('click', async () => {
		const body = input.value.trim()
		if (!body) { templateStatus.textContent = t('Enter a prompt before saving it as a template.'); return }
		const name = window.prompt(t('Template name'), '')
		if (name === null) return
		const description = window.prompt(t('Template description'), '')
		if (description === null) return
		const category = window.prompt(t('Template category'), '')
		if (category === null) return
		const persona = window.prompt(t('Persona instructions (optional)'), '')
		if (persona === null) return
		try {
			await api('POST', '/templates', { name, description, category, body, persona })
			templateStatus.textContent = t('Template saved.')
			await renderTemplates()
		} catch (error) { templateStatus.textContent = t('The template could not be saved: {error}', { error: String(error.message || error) }) }
	})
	templateSearch.addEventListener('input', renderTemplates)
	templatePanel.addEventListener('toggle', () => { if (templatePanel.open) renderTemplates() })
	templateExport.addEventListener('click', async () => {
		try {
			const templates = await api('GET', '/templates/export')
			const blob = new Blob([JSON.stringify(templates, null, 2)], { type: 'application/json' })
			const url = URL.createObjectURL(blob); const anchor = document.createElement('a')
			anchor.href = url; anchor.download = 'eva-prompt-templates.json'; anchor.click(); URL.revokeObjectURL(url)
		} catch (error) { templateStatus.textContent = t('Templates could not be exported: {error}', { error: String(error.message || error) }) }
	})
	templateImport.addEventListener('click', () => templateFile.click())
	templateFile.addEventListener('change', async () => {
		const file = templateFile.files && templateFile.files[0]
		if (!file) return
		try {
			const parsed = JSON.parse(await file.text())
			const templates = Array.isArray(parsed) ? parsed : parsed.templates
			if (!Array.isArray(templates)) throw new Error(t('The file does not contain a template list.'))
			const result = await api('POST', '/templates/import', { templates })
			templateStatus.textContent = t('Imported {count} templates.', { count: result.imported || 0 })
			await renderTemplates()
		} catch (error) { templateStatus.textContent = t('Templates could not be imported: {error}', { error: String(error.message || error) }) }
		finally { templateFile.value = '' }
	})
	templatePanel.append(templateSummary, templateSearch, templateList, templateStatus, templateSave, templateExport, templateImport, templateFile)
	const shareHelp = document.createElement('p')
	shareHelp.className = 'prompt-history-meta'
	shareHelp.textContent = t('Export a JSON template library to share it with teammates; import their file to reuse it.')
	promptPanel.append(promptSummary, promptSearch, promptList, promptExport, templatePanel, shareHelp)
	const scopePill = document.createElement('span')
	scopePill.className = 'pill pill-warn'
	scopePill.hidden = true

	// Per-chat custom instructions (Issue #90): a small header action that
	// opens a dialog to pick a preset persona and/or free-text instructions.
	const customizePill = document.createElement('span')
	customizePill.className = 'pill pill-ok'
	customizePill.hidden = true
	const agentStatusPill = document.createElement('button')
	agentStatusPill.type = 'button'
	agentStatusPill.className = 'pill pill-warn'
	agentStatusPill.hidden = true
	agentStatusPill.setAttribute('aria-live', 'polite')
	const exportPanel = document.createElement('div')
	exportPanel.className = 'chat-export-panel'
	exportOptions.open = true
	exportPanel.append(exportSelectionLabel, exportOptions, exportFormat, exportBtn, exportAllBtn)
	promptPanel.open = true
	const actionsHost = document.createElement('div')
	actionsHost.className = 'chat-actions-host'
	const actionsApp = createApp(ChatActions, {
		panels: { exports: exportPanel, prompts: promptPanel },
		onAction: (action) => {
			if (action === 'images') imageModeBtn.click()
			if (action === 'customize') openCustomizeDialog()
		},
	})
	actionsApp.mount(actionsHost)
	head.append(h1, agentStatusPill, actionsHost)
	// Scope and custom instructions remain visible below the compact header.
	const contextBar = document.createElement('div')
	contextBar.className = 'chat-context'
	contextBar.append(scopePill, customizePill)

	const scroll = document.createElement('div')
	scroll.className = 'chat-log'

	const emptyEl = document.createElement('div')
	emptyEl.className = 'empty'
	const eico = document.createElement('div')
	eico.className = 'ico'
	eico.setAttribute('aria-hidden', 'true')
	const emptyIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
	emptyIcon.setAttribute('viewBox', '0 0 24 24')
	emptyIcon.setAttribute('width', '28')
	emptyIcon.setAttribute('height', '28')
	emptyIcon.setAttribute('fill', 'none')
	emptyIcon.setAttribute('stroke', 'currentColor')
	emptyIcon.setAttribute('stroke-width', '1.7')
	emptyIcon.setAttribute('stroke-linecap', 'round')
	emptyIcon.setAttribute('stroke-linejoin', 'round')
	const emptyIconPath = document.createElementNS('http://www.w3.org/2000/svg', 'path')
	emptyIconPath.setAttribute('d', 'M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 8.7 3.9a8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z')
	emptyIcon.appendChild(emptyIconPath)
	eico.appendChild(emptyIcon)
	const et = document.createElement('div')
	et.className = 't'
	et.textContent = t('Ask a question about your files')
	const ed = document.createElement('div')
	ed.className = 'd'
	ed.textContent = t('Ask about files, notes or plans. Attach files to include them as context.')
	emptyEl.append(eico, et, ed)
	scroll.appendChild(emptyEl)

	const form = opts.form || document.createElement('form')
	if (!opts.form) form.className = 'chatform'
	const input = opts.input || document.createElement('input')
	if (!opts.input) {
		input.id = 'chatinput'
		input.type = 'text'
		input.autocomplete = 'off'
		input.placeholder = t('Ask a question or describe a task')
		input.setAttribute('aria-label', t('Ask a question or describe a task'))
	}
	const sendBtn = opts.sendBtn || document.createElement('button')
	if (!opts.sendBtn) {
		sendBtn.type = 'submit'
		sendBtn.className = 'cbtn'
		sendBtn.textContent = t('Send message')
	}
	sendBtn.addEventListener('click', () => {
		if (sending) stopStream()
	})
	if (!opts.form) form.append(input, sendBtn)
	const setInputValue = (value) => {
		input.value = String(value ?? '')
		if (typeof opts.onInputValueChange === 'function') opts.onInputValueChange(input.value)
	}

	const err = document.createElement('div')
	err.className = 'err'
	err.style.display = 'none'

	// Show or clear the chat error line. The export handlers use this helper.
	function showErr(msg) {
		err.textContent = msg || ''
		err.style.display = msg ? 'block' : 'none'
	}

	root.append(head, contextBar, imagePanel, scroll, err)

	const renderAll = (list) => {
		if (selectionChatId !== chatId) {
			selectedMessageIndexes.clear()
			selectionChatId = chatId
		}
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
		exportBtn.disabled = !list.length || (selectionMode && selectedMessageIndexes.size === 0)
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
		let modelLabel = wrap.querySelector('.rmodel')
		if (m.done && m.model) {
			if (!modelLabel) {
				modelLabel = document.createElement('div')
				modelLabel.className = 'rmodel'
				const text = wrap.querySelector('.rt')
				if (text) text.insertAdjacentElement('afterend', modelLabel)
				else wrap.appendChild(modelLabel)
			}
			modelLabel.textContent = t('Model: {name}', { name: m.model })
		} else if (modelLabel) modelLabel.remove()
		let ta = wrap.querySelector('.rtools')
		if (m.tools && m.tools.length) {
			if (!ta) {
				ta = document.createElement('div')
				ta.className = 'rtools'
				wrap.appendChild(ta)
			}
			ta.innerHTML = ''
			m.tools.forEach((c) => {
				const terminalTrace = c.name === 'run_terminal_command' || c.name === 'run_terminal_sequence'
				const row = document.createElement('div')
				row.className = 'tool ' + (c.state === 'running' ? 'running' : c.state === 'ok' ? 'ok' : 'bad')
				const label = document.createElement('span')
				label.textContent = t(c.state === 'running' ? 'Running' : c.state === 'ok' ? 'Completed' : 'Failed') + ' · ' + formatToolName(c.name) + (c.state === 'running' ? ' …' : '') + (c.elapsed_ms != null ? ' · ' + c.elapsed_ms + ' ms' : '')
				row.appendChild(label)
				if (c.arguments && Object.keys(c.arguments).length) {
					const details = document.createElement('details')
					const summary = document.createElement('summary')
					summary.textContent = t('Details')
					const pre = document.createElement('pre')
					pre.textContent = JSON.stringify(c.arguments, null, 2)
					details.open = terminalTrace
					details.appendChild(summary)
					details.appendChild(pre)
					row.appendChild(details)
				}
				if (c.result !== null && typeof c.result !== 'undefined') {
					const details = document.createElement('details')
					const summary = document.createElement('summary')
					summary.textContent = t('Result')
					const pre = document.createElement('pre')
					pre.textContent = typeof c.result === 'string' ? c.result : JSON.stringify(c.result, null, 2)
					details.open = terminalTrace
					details.appendChild(summary)
					details.appendChild(pre)
					row.appendChild(details)
				}
				if (c.error) {
					const error = document.createElement('div')
					error.className = 'tool-error'
					error.textContent = String(c.error)
					row.appendChild(error)
				}
				ta.appendChild(row)
			})
		} else if (ta) {
			ta.remove()
		}
		// Once the answer is complete, (re-)render sources and follow-up
		// chips: they only exist after the stream is done, and updateMessage
		// runs incrementally while the DOM was built for an in-flight answer.
		if (m.done) {
			if (m.role === 'assistant' && !wrap.querySelector('.racts')) (wrap.querySelector('.rb') || wrap).appendChild(renderAnswerActions(m, i))
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
		stickToBottom()
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
			row.className = 'rs-item' + (src.external ? ' rs-item-external' : '')
			// A source that has no page to open - an indexed mail message or Talk
			// room, which live outside the file tree - is named, not linked. Falling
			// back to '#' produced a link that looks clickable and only jumps to the
			// top of the page, which reads as a broken source.
			const label = document.createElement(src.url ? 'a' : 'span')
			if (src.url) {
				label.href = src.url
				label.target = '_blank'
				label.rel = 'noopener'
			} else {
				label.className = 'rs-plain'
			}
			// Keep the displayed label identical to the reference in the answer.
			const prefix = item.ref !== undefined ? '[' + item.ref + '] ' : ''
			label.textContent = prefix + (src.path || src.name || '')
			row.appendChild(label)
			// A web source is labelled as such and shows the site it came from, so
			// it is never mistaken for one of the user's own files.
			if (src.external) {
				const badge = document.createElement('span')
				badge.className = 'rs-badge'
				badge.textContent = t('Web')
				row.insertBefore(badge, label)
				if (src.host) {
					const host = document.createElement('span')
					host.className = 'rs-host'
					host.textContent = src.host
					row.appendChild(host)
				}
			}
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
				setInputValue(q)
				send()
			})
			chips.appendChild(btn)
		})
		return chips
	}

	function apiStream(path, body, onLine, signal) {
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
			signal,
		}).then((r) => {
			if (!r.ok || !r.body) {
				return r.text().then((text) => {
					let payload = null
					try { payload = text ? JSON.parse(text) : null } catch (_) {}
					const detail = apiErrorMessage(payload) || (text && text.length < 240 ? text : '')
					throw new Error(('HTTP ' + r.status + (detail ? ': ' + String(detail) : '')).slice(0, 260))
				})
			}
			return readNdjson(r.body, onLine)
		})
	}

	function streamFailureMessage(error) {
		const raw = String(error && error.message ? error.message : error || '').replace(/\s+/g, ' ').trim()
		const isNetwork = error?.name === 'TypeError' || /failed to fetch|networkerror|load failed/i.test(raw)
		const detail = isNetwork ? t('Check your connection and try again.') : raw || t('Check your connection and try again.')
		return t('The response could not be completed: {error}', { error: detail })
	}

	async function applyPendingTemplatePersona() {
		if (!chatId || !pendingTemplatePersona) return
		const instructions = pendingTemplatePersona
		await api('POST', '/chats/' + encodeURIComponent(chatId) + '/meta', { instructions })
		pendingTemplatePersona = ''
		refreshCustomizePill({ instructions })
	}

	async function ensureChat() {
		if (chatId) {
			try { await applyPendingTemplatePersona(); return true } catch (_) { return false }
		}
		try {
			const c = await api('POST', '/chats', {})
			chatId = (c && c.id) || null
			if (c && c.rev != null) chatRev = parseInt(c.rev, 10) || null
			await applyPendingTemplatePersona()
			return !!chatId
		} catch (_) {
			return false
		}
	}

	// A queued request can outlive this tab. Surface its server-side state when
	// the chat is open; the endpoint intentionally returns no conversation data.
	const refreshBackgroundStatus = () => {
		if (document.hidden) return
		if (!chatId) { agentStatusPill.hidden = true; return }
		api('GET', '/backgroundChat').then((payload) => {
			const items = payload && Array.isArray(payload.items) ? payload.items : []
			const item = items.find((entry) => entry && entry.chatId === chatId && ['pending', 'running', 'failed', 'paused'].includes(entry.status))
			if (!item) { agentStatusPill.hidden = true; return }
			const state = String(item.status || 'pending')
			const progress = state === 'running' && item.phase === 'tool' && item.tool
				? ` · ${String(item.tool)} · step ${Number(item.steps || 0)}`
				: ''
			agentStatusPill.title = state === 'running'
				? t('EVA is continuing this chat in the background') + progress
				: state === 'failed'
					? t('Background EVA run failed — click to retry')
					: state === 'paused'
						? t('Background EVA run paused — click to resume')
						: state === 'pending'
							? t('Background EVA run queued — click to pause')
					: t('EVA will continue this chat in the background')
			agentStatusPill.textContent = t('Background tasks') + ': ' + ({ running: t('Running'), failed: t('Failed'), paused: t('Paused'), pending: t('Queued') }[state] || t('Completed'))
			agentStatusPill.disabled = !item.id || !['failed', 'paused', 'pending'].includes(state)
			if (item.id && ['failed', 'paused', 'pending'].includes(state)) {
				agentStatusPill.style.cursor = 'pointer'
				agentStatusPill.onclick = () => {
					const action = state === 'failed' ? '/backgroundChat/retry' : state === 'paused' ? '/backgroundChat/resume' : '/backgroundChat/pause'
					api('POST', action, { id: item.id }).then(refreshBackgroundStatus).catch(() => {})
				}
			} else {
				agentStatusPill.style.cursor = ''
				agentStatusPill.onclick = null
			}
			agentStatusPill.hidden = false
		}).catch(() => { /* optional status must never block chat use */ })
	}
	const backgroundStatusTimer = setInterval(refreshBackgroundStatus, 15000)

	// While a stream is running the send button turns into a Stop button.
	// The server checks connection_aborted() between events, so aborting the
	// fetch also stops the generation server-side.
	const setStreamingUI = (active) => {
		if (typeof opts.onStreamingChange === 'function') opts.onStreamingChange(active)
		else {
			sendBtn.type = active ? 'button' : 'submit'
			sendBtn.textContent = active ? t('Stop') : t('Send message')
			sendBtn.classList.toggle('cbtn-stop', active)
		}
	}
	const stopStream = () => {
		if (!sending) return
		stoppedByUser = true
		if (currentAbort) currentAbort.abort()
	}

	// Only follow the stream down while the user is already near the bottom:
	// scrolling up to read earlier context must not be yanked back on every
	// streamed delta.
	const stickToBottom = (force = false) => {
		const nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 120
		if (force || nearBottom) scroll.scrollTop = scroll.scrollHeight
	}

	function saveMessage(role, text, followups, regenerateRev, confirmation, tools, model = null) {
		if (!chatId) return Promise.resolve(false)
		const body = { role, text }
		// Follow-up suggestions are persisted for assistant messages so the
		// chips survive a page reload.
		if (role === 'assistant' && Array.isArray(followups) && followups.length) {
			body.followups = followups
		}
		// A regenerate answer carries the stream's revision token so the server
		// commits the deferred truncation with this message (Issue #182).
		if (regenerateRev != null) body.regenerateRev = regenerateRev
		// A pending (or resolved) tool confirmation rides on the assistant
		// message so a reload rebuilds the panel (Issue #185).
		if (role === 'assistant' && confirmation) body.confirmation = confirmation
		if (role === 'assistant' && Array.isArray(tools) && tools.length) body.tools = tools
		if (role === 'assistant' && typeof model === 'string' && model.length <= 128) body.model = model
		return api('POST', '/chats/' + encodeURIComponent(chatId) + '/messages', body)
			.then((resp) => {
				// Keep the client's revision in sync so the next regenerate/edit
				// validates against the current stored state.
				if (Number.isSafeInteger(resp?.rev)) chatRev = Math.max(chatRev ?? 0, resp.rev)
				return true
			})
			.catch(() => false)
	}

	// The server derives the title of an untitled chat from its first user
	// message, so the header follows once that message has been persisted.
	const refreshTitle = () => {
		if (chatTitle !== null || !chatId) return
		api('GET', '/chats/' + chatId).then((chat) => {
			if (chat && chat.title) {
				chatTitle = chat.title
				h1.textContent = chat.title
			}
		}).catch(() => {})
	}
	const saveUserMessage = (text) => saveMessage('user', text).then((ok) => {
		if (ok) refreshTitle()
		return ok
	})

	// One request carries both messages, including during page dismissal.
	// The server's revision guard rejects a save overtaken by another tab/turn.
	const saveTurn = (question, answer) => {
		if (!chatId) return Promise.resolve(false)
		// Background-job cancellation clears its own ID before persistence.
		// Keep the turn receipt independent and stable for this exact payload.
		answer._turnRequestId ||= (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('turn_' + Date.now() + '_' + Math.random().toString(36).slice(2))
		return api('POST', '/chats/' + encodeURIComponent(chatId) + '/turns', {
			requestId: answer._turnRequestId, expectedRev: chatRev ?? 0, question,
			answer: { text: answer.text, followups: answer.followups || [], confirmation: answer.confirmation || null, tools: answer.tools || [], model: answer.model || null },
		}).then((resp) => {
			if (resp?.ok !== true || !Number.isSafeInteger(resp.rev)) return false
			if (Number.isSafeInteger(resp?.rev)) chatRev = Math.max(chatRev ?? 0, resp.rev)
			refreshTitle()
			return true
		}).catch(() => false)
	}

	// A page/tab can disappear while the streaming request is still running.
	// keepalive lets the server persist a durable worker job without waiting for
	// the browser to render another response. The worker deliberately waits a
	// few seconds, avoiding a race with a stream that is about to finish.
	const queueOnPageHide = () => {
		if (!sending || queuedOnUnload || !chatId) return
		if (messages[messages.length - 1]?.done) return
		const lastUser = [...messages].reverse().find((m) => m.role === 'user')
		if (!lastUser || !lastUser.text) return
		const history = messages.slice(0, Math.max(0, messages.length - 2))
			.filter((m) => m && (m.role === 'user' || m.role === 'assistant') && m.text)
			.slice(-100).map((m) => ({ role: m.role, content: String(m.text).slice(0, 20000) }))
		queuedOnUnload = true
		try {
			fetch(API_BASE + '/backgroundChat', {
				method: 'POST', credentials: 'same-origin', keepalive: true,
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json', 'Accept': 'application/json', 'requesttoken': REQUEST_TOKEN },
				body: JSON.stringify({ chatId, message: lastUser.text, history, requestId: backgroundRequestId }),
			}).catch(() => {})
		} catch (_) {}
	}
	const warnBeforeUnload = (event) => {
		if (!sending) return
		event.preventDefault()
		event.returnValue = ''
	}
	if (typeof window !== 'undefined') window.addEventListener('beforeunload', warnBeforeUnload)
	if (typeof window !== 'undefined') window.addEventListener('pagehide', queueOnPageHide)
	const cancelBackgroundJob = () => {
		if (!backgroundRequestId) return
		const id = backgroundRequestId
		backgroundRequestId = null
		api('DELETE', '/backgroundChat', { id }).catch(() => {})
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

	function applyServerChat(chat, requestOptions = {}) {
		if (destroyed) return false
		if (requestOptions.requireRevision && (!Number.isSafeInteger(chat?.rev) || !Array.isArray(chat.messages))) throw new Error('Missing chat state')
		// Per-chat folder scope (Issue #88): visible in the header so the
		// user knows the answer only uses documents from that folder.
		if (chat && chat.scopePath) {
			scopePill.textContent = t('Scoped to {path}', { path: chat.scopePath })
			scopePill.hidden = false
		} else {
			scopePill.hidden = true
		}
		refreshCustomizePill(chat)
		// The header shows the real chat title instead of the generic
		// placeholder once the conversation has been given one.
		if (chat && chat.title) {
			chatTitle = chat.title
			h1.textContent = chat.title
		}
		if (chat && chat.rev != null) chatRev = parseInt(chat.rev, 10) || null
		messages.length = 0
		trimmedMessages = (chat && chat.trimmed) ? parseInt(chat.trimmed, 10) || 0 : 0
		;(chat.messages || []).forEach((m) => messages.push({
			role: m.role === 'user' || m.role === 'assistant' ? m.role : 'assistant',
			text: m.text || '',
			createdAt: Number.isSafeInteger(m.createdAt) ? m.createdAt : null,
			model: typeof m.model === 'string' ? m.model : null,
			thinking: '',
			followups: Array.isArray(m.followups) ? m.followups : [],
			tools: Array.isArray(m.tools) ? m.tools : [],
			// A persisted pending confirmation re-renders the inline panel so
			// approving after a reload still works (Issue #185).
			confirmation: m.confirmation || null,
			done: true,
		}))
		renderAll(messages)
		return true
	}

	function restoreServerChat(id, requestOptions = {}) {
		const initialChat = opts.initialChat
		if (initialChat && initialChat.id === id && Number.isSafeInteger(initialChat.rev) && Array.isArray(initialChat.messages)) {
			try {
				applyServerChat(initialChat)
				return Promise.resolve(true)
			} catch (_) {
				// Fall back to the canonical detail endpoint if the create payload
				// is incomplete; it remains the source of truth for old clients.
			}
		}
		return api('GET', '/chats/' + encodeURIComponent(id), null, requestOptions).then((chat) => applyServerChat(chat, requestOptions))
	}

	function showHistorySaveWarning() {
		err.textContent = t('The response is visible, but saving to chat history could not be confirmed. Reload before sending another message.')
		err.style.display = 'block'
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

	let ready = Promise.resolve(true)
	if (chatId) {
		input.disabled = true
		sendBtn.disabled = true
		ready = restoreServerChat(chatId).then(() => {
			if (destroyed) return false
			input.disabled = false
			sendBtn.disabled = false
			return true
		}).catch((loadError) => {
			if (!destroyed) {
				const detail = String(loadError && loadError.message ? loadError.message : '').trim()
				showErr(t('Chat history could not be loaded. Reopen this conversation to try again.') + (detail ? ' ' + detail : ''))
				// Keep the composer disabled: sending without the restored revision
				// could overwrite newer history. A retry stays in this conversation.
				const retry = document.createElement('button')
				retry.type = 'button'
				retry.className = 'button'
				retry.textContent = t('Retry loading chat')
				retry.addEventListener('click', () => {
					retry.disabled = true
					restoreServerChat(chatId, { timeout: 10000, requireRevision: true }).then(() => {
						if (destroyed) return
						showErr('')
						retry.remove()
						input.disabled = false
						sendBtn.disabled = false
					}).catch(() => {
						retry.disabled = false
					})
				})
				err.appendChild(document.createTextNode(' '))
				err.appendChild(retry)
			}
			return false
		})
	}

	// Streams a server-side re-run (regenerate or edit) and persists the new
	// answer. The /regenerate endpoint validates the request and hands back a
	// revision token without truncating anything; the truncation and the
	// optional user-text edit are committed atomically with the persisted
	// answer (Issue #182), so a failed model call never destroys the stored
	// history. The server builds the history from the stored messages, so we
	// never duplicate the user message or run the model twice. Confirmation
	// events are surfaced as an inline panel exactly like in the normal send
	// flow.
	const streamRegenerateAnswer = (body) => {
		if (!chatId) {
			err.textContent = t('Chat could not be updated.')
			err.style.display = 'block'
			return
		}
		sending = true
		backgroundRequestId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/[^A-Za-z0-9_-]/g, '') : ('bg_' + Date.now() + '_' + Math.random().toString(36).slice(2))
		queuedOnUnload = false
		currentAbort = new AbortController()
		stoppedByUser = false
		setStreamingUI(true)
		err.style.display = 'none'
		messages.push({ role: 'assistant', text: '', thinking: '', done: false, tools: [] })
		let persistedOrPending = false
		renderAll(messages)
		const unlock = () => {
			sending = false
			setStreamingUI(false)
		}
		apiStream(API_BASE + '/chats/' + chatId + '/regenerate', { ...body, rev: chatRev }, (ev) => {
			const last = messages[messages.length - 1]
			if (ev.type === 'regenerate') {
				// The server commits the deferred truncation only when the
				// persisted answer carries this revision token (Issue #182).
				pendingRegenerateRev = ev.rev != null ? ev.rev : null
				if (Number.isSafeInteger(ev.rev)) chatRev = Math.max(chatRev ?? 0, ev.rev)
				return
			}
			if (!last || last.role !== 'assistant' || last.done) return
			if (ev.type === 'thinking') {
				last.thinking = (last.thinking || '') + (ev.delta || '')
				scheduleUpdate(messages.length - 1)
			} else if (ev.type === 'content') {
				last.text += (ev.delta || '')
				scheduleUpdate(messages.length - 1)
			} else if (ev.type === 'tool') {
				last.tools = last.tools || []
				last.tools.push({ name: ev.name || '?', arguments: ev.arguments || {}, state: 'running' })
				scheduleUpdate(messages.length - 1)
			} else if (ev.type === 'tool_result') {
				last.tools = last.tools || []
				for (let t = last.tools.length - 1; t >= 0; t--) {
					if (last.tools[t].name === ev.name && last.tools[t].state === 'running') {
						last.tools[t].state = ev.ok ? 'ok' : 'bad'
						last.tools[t].error = ev.error || ''
						last.tools[t].url = ev.url || ''
						last.tools[t].result = ev.result
						last.tools[t].elapsed_ms = ev.elapsed_ms
						break
					}
				}
				// Direct (no-dialog) executions still surface a created share
				// link as a copyable chip in the bubble.
				if (ev.ok && ev.url) last.linkUrl = ev.url
				scheduleUpdate(messages.length - 1)
			} else if (ev.type === 'confirmation') {
				// The server already committed truncation + edit; the inline
				// panel below runs the tool on approve and persists the answer.
				const missing = Array.isArray(ev.missing) ? ev.missing : []
				last.confirmation = {
					name: ev.name || '?',
					arguments: ev.arguments || {},
					risk: ev.risk || 'mutating',
					missing,
					preview: ev.preview || null,
					resolved: false,
					// The answer persisted after approval must still commit the
					// deferred truncation (Issue #182).
					regenerateRev: pendingRegenerateRev,
				}
				last.text = missing.length
					? t('Some required details are missing - please complete them below.')
					: t('Please review this action and confirm it explicitly.')
				last.done = true
				persistedOrPending = true
				renderAll(messages)
				scroll.scrollTop = scroll.scrollHeight
				return
			} else if (ev.type === 'done') {
				last.text = ev.answer || last.text
				last.createdAt = Math.floor(Date.now() / 1000)
				last.model = typeof ev.model === 'string' ? ev.model : null
				last.sources = citedSources(last.text, ev.sources || [])
				last.followups = ev.followups || []
				last.done = true
				updateMessage(messages.length - 1)
				// Persist with the regeneration token: the server truncates and
				// applies the edit atomically with this message. Without the
				// token (or after a failure) the stored history stays intact.
				last._pendingSave = saveMessage('assistant', last.text, last.followups, pendingRegenerateRev, null, last.tools, last.model)
					.then((saved) => {
						persistedOrPending = saved
						if (saved && onRecent) onRecent()
						else if (!saved) showHistorySaveWarning()
					})
				pendingRegenerateRev = null
			} else if (ev.type === 'error') {
				last.text = streamFailureMessage(ev.message)
				last.done = true
				err.textContent = last.text
				err.style.display = 'block'
				pendingRegenerateRev = null
			}
			stickToBottom()
		}, currentAbort.signal).catch((e) => {
			const last = messages[messages.length - 1]
			if (last && last.role === 'assistant' && !last.done) {
				if (!stoppedByUser) {
					last.text = (last.text || '') + '\n\n' + streamFailureMessage(e)
				}
				last.done = true
				updateMessage(messages.length - 1)
				// Persist the partial answer when the user stopped the stream so
				// a reload keeps the conversation instead of dropping it. The
				// token commits the truncation with the partial text.
					if (stoppedByUser && last.text.trim() !== '') {
						last._pendingSave = saveMessage('assistant', last.text, null, pendingRegenerateRev, null, last.tools).then((saved) => {
							persistedOrPending = saved
							if (!saved) showHistorySaveWarning()
						})
				}
			}
			pendingRegenerateRev = null
			if (!stoppedByUser) {
				err.textContent = streamFailureMessage(e)
				err.style.display = 'block'
			}
		}).finally(async () => {
			const attemptedSave = messages[messages.length - 1]?._pendingSave
			try { await attemptedSave } catch (_) { showHistorySaveWarning() }
			if (!persistedOrPending) {
				// beginRegenerate advances SQL/AppData state even if no model
				// event reaches us. Refresh before the next revision-guarded send.
				try {
					if (!attemptedSave) {
						// Restore the original answer/context after a model failure or
						// empty stop. A failed write keeps its visible answer for copying.
						await restoreServerChat(chatId, { timeout: 10000, requireRevision: true })
					} else {
						const chat = await api('GET', '/chats/' + encodeURIComponent(chatId), null, { timeout: 10000 })
						if (!Number.isSafeInteger(chat?.rev)) throw new Error('Missing chat revision')
						chatRev = Math.max(chatRev ?? 0, chat.rev)
					}
				} catch (_) { showHistorySaveWarning() }
			}
			stoppedByUser = false
			currentAbort = null
			pendingRegenerateRev = null
			unlock()
			input.focus()
			stickToBottom()
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

	const encodeImage = (attachment) => new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onerror = () => reject(new Error(t('An attached image could not be read.')))
		reader.onload = () => {
			const match = String(reader.result || '').match(/^data:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$/)
			if (!match) { reject(new Error(t('An attached image could not be read.'))); return }
			resolve({ name: attachment.name, mime: match[1], data: match[2] })
		}
		reader.readAsDataURL(attachment.file)
	})

	const send = () => {
		const typedMessage = input.value.trim()
		const outgoingAttachments = typeof opts.getPendingImages === 'function' ? opts.getPendingImages() : []
		if ((!typedMessage && !outgoingAttachments.length) || sending) return
		const msg = typedMessage || t('Describe these images.')
		const savedQuestion = outgoingAttachments.length
			? msg + '\n\n[' + t('Attached images') + ': ' + outgoingAttachments.map(image => image.name).join(', ') + ']'
			: msg
		promptHistory.record(msg)
		renderPromptHistory()
		sending = true
		currentAbort = new AbortController()
		stoppedByUser = false
		setStreamingUI(true)
		setInputValue('')
		err.style.display = 'none'
		if (emptyEl.parentNode) scroll.removeChild(emptyEl)

		messages.push({ role: 'user', text: savedQuestion, attachments: outgoingAttachments.map(({ name, url }) => ({ name, url })), createdAt: Math.floor(Date.now() / 1000) })
		messages.push({ role: 'assistant', text: '', thinking: '', done: false, tools: [] })
		renderAll(messages)
		const assistantIdx = messages.length - 1
		renderAll(messages)

		const history = []
		for (let i = 0; i < messages.length - 2; i++) {
			const m = messages[i]
			history.push({ role: m.role, content: m.text })
		}

		ensureChat().then(async (chatReady) => {
			if (!chatReady) throw new Error(t('Chat could not be created.'))
			const images = await Promise.all(outgoingAttachments.map(encodeImage))
			if (outgoingAttachments.length && typeof opts.consumePendingImages === 'function') opts.consumePendingImages()
			return apiStream(STREAM_URL, { message: msg, history, chatId, images }, (ev) => {
				const last = messages[assistantIdx]
				if (!last || last.role !== 'assistant' || last.done) return
				if (ev.type === 'thinking') {
					last.thinking += ev.delta || ''
				} else if (ev.type === 'content') {
					last.text += ev.delta || ''
				} else if (ev.type === 'tool') {
					last.tools = last.tools || []
					last.tools.push({ name: ev.name || '?', arguments: ev.arguments || {}, state: 'running' })
				} else if (ev.type === 'tool_result') {
					// Match by name from the end: several tools can run in the
					// same round, and their results may arrive in any order.
					if (last.tools && last.tools.length) {
						for (let t = last.tools.length - 1; t >= 0; t--) {
							if (last.tools[t].name === ev.name && last.tools[t].state === 'running') {
								last.tools[t].state = ev.ok ? 'ok' : 'bad'
								last.tools[t].error = ev.error || ''
								last.tools[t].url = ev.url || ''
								last.tools[t].result = ev.result
								last.tools[t].elapsed_ms = ev.elapsed_ms
								break
							}
						}
					}
					// Direct (no-dialog) executions still surface a created share
					// link as a copyable chip in the bubble.
					if (ev.ok && ev.url) last.linkUrl = ev.url
				} else if (ev.type === 'confirmation') {
					cancelBackgroundJob()
					const missing = Array.isArray(ev.missing) ? ev.missing : []
					last.confirmation = {
						name: ev.name || '?',
						arguments: ev.arguments || {},
						risk: ev.risk || 'mutating',
						missing,
						preview: ev.preview || null,
						resolved: false,
					}
					last.text = missing.length
						? t('Some required details are missing - please complete them below.')
						: t('Please review this action and confirm it explicitly.')
					last.done = true
					// Persist the question and confirmation placeholder together.
					last.confirmation.token = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2)
					last._pendingSave = saveTurn(savedQuestion, last).then((saved) => { if (!saved) showHistorySaveWarning(); return saved })
					renderAll(messages)
				} else if (ev.type === 'done') {
					cancelBackgroundJob()
					last.text = ev.answer || last.text
					last.createdAt = Math.floor(Date.now() / 1000)
					last.model = typeof ev.model === 'string' ? ev.model : null
					last.sources = citedSources(last.text, ev.sources || [])
					last.followups = ev.followups || []
					last.done = true
					last._pendingSave = saveTurn(savedQuestion, last)
						.then((saved) => {
							if (saved && onRecent) onRecent()
							else if (!saved) showHistorySaveWarning()
						})
				} else if (ev.type === 'error') {
					cancelBackgroundJob()
					last.text = streamFailureMessage(ev.message)
					last.done = true
					last._pendingSave = saveUserMessage(savedQuestion).then((saved) => { if (!saved) showHistorySaveWarning(); return saved })
				}
				// One coalesced update per frame instead of one DOM rebuild per
				// NDJSON event; terminal states below still update immediately.
				scheduleUpdate(assistantIdx)
			}, currentAbort.signal).catch((e) => {
				const last = messages[messages.length - 1]
				if (last && last.role === 'assistant' && !last.done) {
					if (!stoppedByUser) {
						last.text = (last.text || '') + '\n\n' + streamFailureMessage(e)
					}
					last.done = true
					updateMessage(messages.length - 1)
					// Persist the partial answer when the user stopped the stream so
					// a reload keeps the conversation instead of dropping it.
					if (stoppedByUser && last.text.trim() !== '') {
						last._pendingSave = saveTurn(savedQuestion, last)
							.then((saved) => { if (!saved) showHistorySaveWarning() })
					}
				}
				if (!stoppedByUser) {
					cancelBackgroundJob()
					err.textContent = streamFailureMessage(e)
					err.style.display = 'block'
				}
			}).finally(async () => {
				// Completion includes ordered history writes, so another turn
				// cannot overtake them and completed chats survive reload.
				try { await messages[assistantIdx]?._pendingSave } catch (_) { showHistorySaveWarning() }
				stoppedByUser = false
				currentAbort = null
				sending = false
				if (destroyed) return
				setStreamingUI(false)
				input.focus()
				stickToBottom()
			})
		}).catch((error) => {
			// The image remains in the composer if chat creation or encoding fails.
			// Remove optimistic bubbles so the user can retry without duplicate text.
			messages.splice(Math.max(0, assistantIdx - 1), 2)
			renderAll(messages)
			setInputValue(typedMessage)
			stoppedByUser = false
			currentAbort = null
			sending = false
			setStreamingUI(false)
			err.textContent = String(error?.message || t('Chat could not be created.'))
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

	refreshBackgroundStatus()
	input.focus()
	form.addEventListener('submit', (e) => { e.preventDefault(); send() })
	root.__evaAi = { ready, destroy: () => {
		if (destroyed) return
		destroyed = true
		// Preserve the durable background handoff before releasing the stream.
		queueOnPageHide()
		window.removeEventListener('pagehide', queueOnPageHide)
		window.removeEventListener('beforeunload', warnBeforeUnload)
		if (currentAbort) currentAbort.abort()
		messages.forEach((message) => (message.attachments || []).forEach((attachment) => URL.revokeObjectURL(attachment.url)))
		if (pendingUpdateFrame !== null && typeof cancelAnimationFrame === 'function') cancelAnimationFrame(pendingUpdateFrame)
		pendingUpdateFrame = null
		updateScheduled = false
		pendingUpdateIdx = null
		clearInterval(backgroundStatusTimer)
		actionsApp.unmount()
	} }
}
