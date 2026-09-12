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
						throw new Error('the server did not return JSON (HTTP ' + res.status + ')')
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
			setStatus(statusId, 'info', 'Saving…')
			var payload = collect()
			api('PUT', 'admin/settings', payload)
				.then(function () {
					setStatus(statusId, 'success', 'Saved.')
					if (typeof onSuccess === 'function') {
						onSuccess()
					}
				})
				.catch(function (err) {
					setStatus(statusId, 'error', 'Could not save: ' + err.message)
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
		}
		return payload
	})

	// ── Web search infrastructure ────────────────────────────────────────

	bindSave('eva-websearch-save', 'eva-websearch-status', function () {
		var payload = {
			web_search_url: value('eva-websearch-url'),
			web_search_max_results: value('eva-websearch-max'),
			web_search_timeout: value('eva-websearch-timeout'),
			web_search_content_chars: value('eva-content-chars'),
			web_search_candidates: value('eva-candidates'),
			web_search_safe_search: checked('eva-safesearch-toggle') ? '1' : '0',
			web_search_fetch_content: checked('eva-fetch-content-toggle') ? '1' : '0',
			web_search_images: checked('eva-images-toggle') ? '1' : '0',
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

	// ── Tools ────────────────────────────────────────────────────────────

	bindSave('eva-tools-save', 'eva-tools-status', function () {
		return {
			weather_tool_enabled: checked('eva-weather-toggle') ? '1' : '0',
		}
	})

	// ── Per-user enrollment toggle ───────────────────────────────────────

	root.addEventListener('change', function (event) {
		var toggle = event.target
		if (!toggle || !toggle.classList.contains('eva-enroll-toggle')) {
			return
		}
		var userId = toggle.dataset.user
		if (!userId) {
			return
		}
		var enabled = toggle.checked
		toggle.disabled = true
		api('POST', 'admin/users/' + encodeURIComponent(userId) + '/enrollment', { enabled: enabled })
			.then(function () {
				if (window.OC && OC.Notification && OC.Notification.showTemporary) {
					OC.Notification.showTemporary(
						enabled ? 'Indexing enabled for ' + userId : 'Indexing disabled for ' + userId
					)
				}
			})
			.catch(function (err) {
				toggle.checked = !enabled
				window.alert('Could not change enrollment: ' + err.message)
			})
			.finally(function () {
				toggle.disabled = false
			})
	})

	// ── Per-user re-index / delete ───────────────────────────────────────

	root.addEventListener('click', function (event) {
		var button = event.target.closest('.eva-btn-reindex, .eva-btn-reset')
		if (!button) {
			return
		}
		var userId = button.dataset.user
		if (!userId) {
			return
		}
		var isReset = button.classList.contains('eva-btn-reset')
		if (isReset && !window.confirm(
			'Delete the complete index for ' + userId + '? '
			+ 'This removes indexed documents and their vectors. Original Nextcloud files stay untouched.'
		)) {
			return
		}

		button.disabled = true
		var action = isReset
			? 'admin/users/' + encodeURIComponent(userId) + '/reset'
			: 'admin/users/' + encodeURIComponent(userId) + '/reindex'
		api('POST', action)
			.then(function (data) {
				if (isReset) {
					var result = data.result || {}
					window.alert(
						'Index deleted: ' + (result.documents || 0) + ' documents and '
						+ (result.chunks || 0) + ' chunks removed.'
					)
				} else {
					window.alert('Re-index queued for ' + userId + '.')
				}
				window.location.reload()
			})
			.catch(function (err) {
				window.alert('Action failed: ' + err.message)
				button.disabled = false
			})
	})

	// ── Stop background indexing ─────────────────────────────────────────

	var stopButton = el('eva-stop-background')
	if (stopButton !== null) {
		stopButton.addEventListener('click', function () {
			stopButton.disabled = true
			setStatus('eva-background-status', 'info', 'Stopping…')
			api('POST', 'admin/stop')
				.then(function (data) {
					var users = (data.requestedFor || [])
					setStatus(
						'eva-background-status',
						'success',
						'Stop requested for ' + (users.length ? users.join(', ') : 'the next run') + '.'
					)
					window.setTimeout(function () {
						window.location.reload()
					}, 1500)
				})
				.catch(function (err) {
					setStatus('eva-background-status', 'error', 'Could not stop: ' + err.message)
					stopButton.disabled = false
				})
		})
	}
})()
