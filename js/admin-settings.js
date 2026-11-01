/**
 * Eva AI admin settings — behaviour for the native Nextcloud admin page.
 *
 * Uses only native DOM APIs and, when available, Nextcloud's own OC.Notification
 * for feedback. Every interaction talks to the admin-only OCS endpoints under
 * /ocs/v2.php/apps/eva_ai/api/admin/, so a non-admin cannot reach them.
 */
(function () {
	'use strict'

	var root = document.getElementById('eva-ai-admin')
	if (!root) {
		return
	}
	var apiBase = root.dataset.apiBase || '/ocs/v2.php/apps/eva_ai/api/'
	function tr(text, vars) {
		var translated = window.OC && OC.L10N && OC.L10N.translate ? OC.L10N.translate('eva_ai', text, vars || {}) : text
		return String(translated).replace(/\{([^{}]+)\}/g, function (match, key) {
			return vars && Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match
		})
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	function ocsToken() {
		return document.head.dataset.requesttoken || ''
	}

	function api(method, path, body) {
		// OCS answers with XML unless JSON is requested, and a failed XML parse
		// used to surface as "Could not save: Unexpected token '<'…" even while
		// the write had already succeeded. Ask for JSON explicitly and treat a
		// non-JSON body as an error on its own terms.
		var url = apiBase + path + (path.indexOf('?') === -1 ? '?format=json' : '&format=json')
		var opts = {
			method: method,
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
				requesttoken: ocsToken(),
			},
		}
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json'
			opts.body = JSON.stringify(body)
		}
		return fetch(url, opts).then(function (res) {
			return res.text().then(function (raw) {
				var data = null
				try {
					data = raw === '' ? null : JSON.parse(raw)
				} catch (parseError) {
					if (res.ok) {
						throw new Error(tr('The server did not return JSON (HTTP {status}).', { status: res.status }))
					}
					data = null
				}
				if (!res.ok || (data && data.ocs && data.ocs.meta && data.ocs.meta.status === 'failure')) {
					var payload = data && data.ocs ? data.ocs.data : data
					var detail = payload && payload.validationErrors && payload.validationErrors.length
						? ' ' + payload.validationErrors.join(' ')
						: ''
					var message = payload && payload.error ? payload.error : 'HTTP ' + res.status
					throw new Error(message + detail)
				}
				// Unwrap the OCS envelope so callers see the data payload directly.
				return data && data.ocs ? data.ocs.data : data
			})
		})
	}

	function el(id) {
		return document.getElementById(id)
	}

	function value(id) {
		var node = el(id)
		return node === null ? null : node.value
	}

	function checked(id) {
		var node = el(id)
		return node !== null && node.checked
	}

	/**
	 * Feedback in the inline status span, plus a native notification when the
	 * Nextcloud toast API is present. The span is always updated so feedback
	 * survives even if notifications are unavailable.
	 */
	function setStatus(id, type, message) {
		var node = el(id)
		if (node !== null) {
			node.textContent = message
			node.classList.remove('eva-status-text--ok', 'eva-status-text--error')
			if (type === 'success') {
				node.classList.add('eva-status-text--ok')
			} else if (type === 'error') {
				node.classList.add('eva-status-text--error')
			}
		}
		if (type === 'success' && window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(message)
		}
	}

	/**
	 * Wire one save button. Field getters that return null are omitted from the
	 * payload, so a section only ever writes the settings it actually shows.
	 */
	function bindSave(buttonId, statusId, collect, onSuccess) {
		var button = el(buttonId)
		if (button === null) {
			return
		}
		button.addEventListener('click', function () {
			button.disabled = true
			setStatus(statusId, 'info', tr('Saving…'))
			var payload = collect()
			api('PUT', 'admin/settings', payload)
				.then(function () {
					setStatus(statusId, 'success', tr('Saved.'))
					if (typeof onSuccess === 'function') {
						onSuccess()
					}
				})
				.catch(function (err) {
					setStatus(statusId, 'error', tr('Could not save: ') + err.message)
				})
				.finally(function () {
					button.disabled = false
				})
		})
	}

	// ── Indexing performance ─────────────────────────────────────────────

	bindSave('eva-index-save', 'eva-index-status', function () {
		var payload = {}
		var concurrent = value('eva-max-concurrent')
		var budget = value('eva-job-budget')
		if (concurrent !== null) {
			payload.index_max_concurrent = concurrent
		}
		if (budget !== null) {
			payload.index_job_max_seconds = budget
		}
		var interval = value('eva-job-interval')
		if (interval !== null) {
		payload.index_job_interval_minutes = interval
		payload.rate_limit_chat_per_minute = Number(document.getElementById('eva-rate-chat')?.value || 30)
		payload.rate_limit_stream_per_minute = Number(document.getElementById('eva-rate-stream')?.value || 10)
		payload.rate_limit_background_per_minute = Number(document.getElementById('eva-rate-background')?.value || 5)
		}
		return payload
	})

	// ── Web search infrastructure ────────────────────────────────────────

	bindSave('eva-websearch-save', 'eva-websearch-status', function () {
		var payload = {
			web_search_url: value('eva-websearch-url'),
			web_search_browser_node: value('eva-browser-node'),
			web_search_browser_browsers_path: value('eva-browser-browsers-path'),
		}
		var apiKey = value('eva-websearch-key')
		if (apiKey) {
			payload.web_search_api_key = apiKey
		}
		if (checked('eva-remove-websearch-key')) {
			payload.remove_web_search_api_key = true
		}
		return payload
	}, function () {
		// Clear the secret fields once the key was stored, so a second save
		// cannot resend a key the administrator already saved.
		var key = el('eva-websearch-key')
		var remove = el('eva-remove-websearch-key')
		if (key !== null) {
			key.value = ''
		}
		if (remove !== null) {
			remove.checked = false
		}
	})

	// ── Live web search test ─────────────────────────────────────────────

	/**
	 * Runs the real search service and renders what the assistant would get:
	 * the ranked results with their source, date, amount of page text and number
	 * of pictures. Without this the only way to find out whether a provider
	 * answers at all was to ask the assistant and guess from its reply.
	 */
	function renderSearchTest(data) {
		var box = el('eva-test-results')
		if (box === null) {
			return
		}
		box.textContent = ''
		box.hidden = false

		if (!data || data.ok !== true) {
			var problem = document.createElement('div')
			problem.className = 'eva-search-problem'
			problem.textContent = (data && data.error) ? data.error : 'The search failed.'
			if (data && data.enabled === false) {
				var hint = document.createElement('p')
				hint.className = 'settings-hint'
				hint.textContent = tr('Web search is switched off for your account. Turn it on in your personal Eva AI settings and choose a provider.')
				box.appendChild(hint)
			}
			box.appendChild(problem)
			return
		}

		var summary = document.createElement('p')
		summary.className = 'eva-test-summary'
		if (data.mode === 'images') {
			var images = Array.isArray(data.images) ? data.images : []
			summary.textContent = tr('Results: {count} · Provider: {provider} · Mode: {mode}', { count: images.length, provider: data.provider || '?', mode: tr('images') })
			box.appendChild(summary)
			if (images.length === 0) {
				var noImages = document.createElement('p')
				noImages.className = 'settings-hint'
				noImages.textContent = tr('The image provider returned no usable pictures. Try a different query.')
				box.appendChild(noImages)
				return
			}
			var gallery = document.createElement('div')
			gallery.className = 'eva-image-results'
			var renderedImages = 0
			images.forEach(function (hit) {
				if (!hit || typeof hit.preview !== 'string' || !/^https:\/\//i.test(hit.preview)
					|| typeof hit.page !== 'string' || !/^https?:\/\//i.test(hit.page)) return
				var card = document.createElement('article')
				card.className = 'eva-image-result'
				var link = document.createElement('a')
				link.href = hit.page
				link.target = '_blank'
				link.rel = 'noopener noreferrer'
				var image = document.createElement('img')
				image.src = hit.preview
				image.alt = hit.title || tr('Search result image')
				image.loading = 'lazy'
				image.decoding = 'async'
				image.referrerPolicy = 'no-referrer'
				image.addEventListener('error', function () {
					image.hidden = true
					var unavailable = document.createElement('span')
					unavailable.className = 'eva-image-preview-unavailable'
					unavailable.textContent = tr('Preview unavailable; open the source page.')
					card.insertBefore(unavailable, link)
				})
				link.appendChild(image)
				card.appendChild(link)
				var title = document.createElement('p')
				title.textContent = hit.title || hit.page
				card.appendChild(title)
				gallery.appendChild(card)
				renderedImages++
			})
			box.appendChild(gallery)
			if (renderedImages === 0) {
				var unusable = document.createElement('p')
				unusable.className = 'settings-hint'
				unusable.textContent = tr('The provider returned image records, but none had a safe preview and source page.')
				box.appendChild(unusable)
			}
			return
		}

		var results = data.results || []
		summary.textContent = tr('Results: {count} · Provider: {provider} · Mode: {mode}', { count: results.length, provider: data.provider || '?', mode: data.mode || 'web' })
		box.appendChild(summary)

		// Whether the browser did any work is the one fact a search result list
		// cannot show: rendering can be switched on and silently never used.
		var rendered = typeof data.renderedPages === 'number' ? data.renderedPages : 0
		var browserNote = document.createElement('p')
		browserNote.className = 'settings-hint'
		if (data.browserStatus) {
			browserNote.textContent = 'No page was read in a browser: ' + data.browserStatus
		} else if (rendered > 0) {
			browserNote.textContent = tr('{count} pages needed the browser because their text only exists after JavaScript runs.', { count: rendered })
		} else {
			browserNote.textContent = tr('No page needed the browser this time. Every result was readable with a plain request; this does not indicate whether rendering works.')
		}
		box.appendChild(browserNote)

		if (results.length === 0) {
			var none = document.createElement('p')
			none.className = 'settings-hint'
			none.textContent = tr('No results. Try another query, another mode, or a different provider.')
			box.appendChild(none)
			return
		}

		var table = document.createElement('table')
		table.className = 'grid eva-search-table'
		var head = document.createElement('thead')
		var headRow = document.createElement('tr')
		;['#', tr('Result'), tr('Source'), tr('Read'), tr('Pictures')].forEach(function (label) {
			var th = document.createElement('th')
			th.scope = 'col'
			th.textContent = label
			headRow.appendChild(th)
		})
		head.appendChild(headRow)
		table.appendChild(head)

		var body = document.createElement('tbody')
		results.forEach(function (hit, index) {
			var row = document.createElement('tr')

			var rank = document.createElement('td')
			rank.textContent = String(index + 1)
			row.appendChild(rank)

			var cell = document.createElement('td')
			var link = document.createElement('a')
			link.href = hit.url
			link.target = '_blank'
			link.rel = 'noopener noreferrer'
			link.textContent = hit.title || hit.url
			cell.appendChild(link)
			var snippet = document.createElement('div')
			snippet.className = 'eva-search-snippet'
			snippet.textContent = hit.snippet || ''
			cell.appendChild(snippet)
			row.appendChild(cell)

			var source = document.createElement('td')
			var parts = []
			if (hit.source) {
				parts.push(hit.source)
			}
			if (hit.published) {
				parts.push(new Date(hit.published * 1000).toLocaleDateString())
			}
			if (hit.news) {
				parts.push(tr('news'))
			}
			source.textContent = parts.join(' · ')
			row.appendChild(source)

			var read = document.createElement('td')
			read.textContent = hit.chars > 0
				? hit.chars + ' ' + tr('characters') + (hit.highlightChars > 0 ? ' + ' + hit.highlightChars + ' ' + tr('highlighted') : '')
				: tr('snippet only')
			row.appendChild(read)

			var images = document.createElement('td')
			images.textContent = String(hit.images || 0)
			row.appendChild(images)

			body.appendChild(row)
		})
		table.appendChild(body)
		box.appendChild(table)
	}

	var runTest = el('eva-test-run')
	if (runTest !== null) {
		runTest.addEventListener('click', function () {
			var input = el('eva-test-query')
			var modeSelect = el('eva-test-mode')
			var query = input === null ? '' : input.value.trim()
			if (query === '') {
				setStatus('eva-test-status', 'error', tr('Enter a query first.'))
				return
			}
			runTest.disabled = true
			setStatus('eva-test-status', 'info', tr('Searching…'))
			api('POST', 'admin/websearch/test', {
				query: query,
				mode: modeSelect === null ? 'web' : modeSelect.value,
			})
				.then(function (data) {
					setStatus('eva-test-status', data.ok ? 'success' : 'error', data.ok ? tr('Done') : tr('No results'))
					renderSearchTest(data)
				})
				.catch(function (err) {
					setStatus('eva-test-status', 'error', err.message)
					renderSearchTest({ ok: false, error: err.message })
				})
				.finally(function () {
					runTest.disabled = false
				})
		})
		// Enter in the query field starts the search.
		var input = el('eva-test-query')
		if (input !== null) {
			input.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault()
					runTest.click()
				}
			})
		}
	}

	// ── Tools ────────────────────────────────────────────────────────────

	// ── Stop background indexing ─────────────────────────────────────────

	var stopButton = el('eva-stop-background')
	if (stopButton !== null) {
		stopButton.addEventListener('click', function () {
			stopButton.disabled = true
			setStatus('eva-background-status', 'info', tr('Stopping…'))
			api('POST', 'admin/stop')
				.then(function (data) {
					setStatus(
						'eva-background-status',
						'success',
						data.requestedCount > 0
							? tr('Stop requested for {count} running pass(es).', { count: data.requestedCount })
							: tr('Background indexing stop requested.')
					)
					window.setTimeout(function () {
						window.location.reload()
					}, 1500)
				})
				.catch(function (err) {
					setStatus('eva-background-status', 'error', tr('Could not stop: ') + err.message)
					stopButton.disabled = false
				})
		})
	}

	// Keep the large optional storage interface out of the normal admin page
	// load. Its dedicated bundle is requested only after the disclosure opens.
	var storageTools = el('eva-ai-storage-tools')
	if (storageTools !== null) {
		storageTools.addEventListener('toggle', function () {
			if (!storageTools.open || storageTools.dataset.loadState === 'loading' || storageTools.dataset.loadState === 'loaded') {
				return
			}
			var status = el('eva-ai-storage-load-status')
			if (status !== null) status.textContent = tr('Loading optional controls…')
			storageTools.dataset.loadState = 'loading'
			var script = document.createElement('script')
			script.src = window.OC.filePath('eva_ai', '', 'js/eva_ai-admin.js')
			script.onload = function () {
				storageTools.dataset.loadState = 'loaded'
				if (status !== null) status.textContent = ''
			}
			script.onerror = function () {
				storageTools.dataset.loadState = ''
				if (status !== null) status.textContent = tr('Optional controls could not be loaded. Reopen this section to retry.')
			}
			document.head.appendChild(script)
		})
	}
})()
