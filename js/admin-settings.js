/**
 * Eva AI admin settings — lightweight JS for the native Nextcloud admin page.
 *
 * Handles form submissions, per-user reindex/reset, and the background
 * index stop action via OCS AJAX requests. Uses only native DOM APIs.
 */
(function () {
	'use strict'

	var root = document.getElementById('eva-ai-admin')
	if (!root) return
	var apiBase = root.dataset.apiBase || '/ocs/v2.php/apps/eva_ai/api/'

	// ── Helpers ──────────────────────────────────────────────────────

	function ocsToken() {
		return document.head.dataset.requesttoken || ''
	}

	function api(method, path, body) {
		var opts = {
			method: method,
			headers: {
				'OCS-APIREQUEST': 'true',
				requesttoken: ocsToken(),
			},
		}
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json'
			opts.body = JSON.stringify(body)
		}
		return fetch(apiBase + path, opts).then(function (res) {
			if (!res.ok) throw new Error('HTTP ' + res.status)
			return res.json()
		})
	}

	function setStatus(el, type, msg) {
		if (!el) return
		el.textContent = msg
		el.style.color = type === 'success'
			? 'var(--color-success, #46ba61)'
			: type === 'error'
				? 'var(--color-error, #e9322d)'
				: 'var(--color-text-maxcontrast, #999)'
		window.setTimeout(function () {
			el.textContent = ''
			el.style.color = ''
		}, 4000)
	}

	// ── Tool settings save ───────────────────────────────────────────

	var toolsSaveBtn = document.getElementById('eva-tools-save')
	if (toolsSaveBtn) {
		toolsSaveBtn.addEventListener('click', function () {
			var statusEl = document.getElementById('eva-tools-status')
			toolsSaveBtn.disabled = true
			setStatus(statusEl, 'info', 'Saving\u2026')

			var payload = {
				weather_tool_enabled: document.getElementById('eva-weather-toggle').checked ? '1' : '0',
				web_search_url: document.getElementById('eva-websearch-url').value,
				web_search_max_results: document.getElementById('eva-websearch-max').value,
				web_search_safe_search: document.getElementById('eva-safesearch-toggle').checked ? '1' : '0',
			}

			var apiKey = document.getElementById('eva-websearch-key')
			if (apiKey && apiKey.value) {
				payload.web_search_api_key = apiKey.value
			}
			var removeKey = document.getElementById('eva-remove-websearch-key')
			if (removeKey) {
				payload.remove_web_search_api_key = removeKey.checked
			}

			api('PUT', 'admin/settings', payload)
				.then(function () {
					setStatus(statusEl, 'success', 'Saved.')
					if (apiKey) apiKey.value = ''
					if (removeKey) removeKey.checked = false
				})
				.catch(function (err) {
					setStatus(statusEl, 'error', 'Could not save: ' + err.message)
				})
				.finally(function () {
					toolsSaveBtn.disabled = false
				})
		})
	}

	// ── Scheduler settings save ──────────────────────────────────────

	var schedSaveBtn = document.getElementById('eva-scheduler-save')
	if (schedSaveBtn) {
		schedSaveBtn.addEventListener('click', function () {
			var statusEl = document.getElementById('eva-scheduler-status')
			schedSaveBtn.disabled = true
			setStatus(statusEl, 'info', 'Saving\u2026')

			var concurrent = document.getElementById('eva-max-concurrent')
			var budget = document.getElementById('eva-job-budget')
			var payload = {}
			if (concurrent) payload.index_max_concurrent = concurrent.value
			if (budget) payload.index_job_max_seconds = budget.value

			api('PUT', 'admin/settings', payload)
				.then(function () {
					setStatus(statusEl, 'success', 'Saved.')
				})
				.catch(function (err) {
					setStatus(statusEl, 'error', 'Could not save: ' + err.message)
				})
				.finally(function () {
					schedSaveBtn.disabled = false
				})
		})
	}

	// ── Per-user reindex / reset ─────────────────────────────────────

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('.eva-btn-reindex, .eva-btn-reset')
		if (!btn) return
		var userId = btn.dataset.user
		if (!userId) return

		var isReset = btn.classList.contains('eva-btn-reset')
		if (isReset && !window.confirm('Delete the complete index for ' + userId + '? This removes indexed documents and vectors. Original Nextcloud files stay untouched.')) {
			return
		}

		btn.disabled = true
		var action = isReset
			? 'users/' + encodeURIComponent(userId) + '/reset'
			: 'users/' + encodeURIComponent(userId) + '/reindex'
		api('POST', action)
			.then(function (data) {
				if (isReset) {
					var result = data.result || {}
					alert('Index deleted: ' + (result.documents || 0) + ' documents and ' + (result.chunks || 0) + ' chunks removed.')
				} else {
					alert('Reindex queued for ' + userId + '.')
				}
				window.location.reload()
			})
			.catch(function (err) {
				alert('Action failed: ' + err.message)
				btn.disabled = false
			})
	})

	// ── Stop background indexing ─────────────────────────────────────

	var stopBtn = document.getElementById('eva-stop-background')
	if (stopBtn) {
		stopBtn.addEventListener('click', function () {
			var statusEl = document.getElementById('eva-background-status')
			stopBtn.disabled = true
			setStatus(statusEl, 'info', 'Stopping\u2026')

			api('POST', 'admin/stop')
				.then(function (data) {
					setStatus(statusEl, 'success', 'Stop requested. Active passes: ' + (data.requestedFor || []).join(', '))
					window.setTimeout(function () { window.location.reload() }, 1500)
				})
				.catch(function (err) {
					setStatus(statusEl, 'error', 'Could not stop: ' + err.message)
					stopBtn.disabled = false
				})
		})
	}
})()
