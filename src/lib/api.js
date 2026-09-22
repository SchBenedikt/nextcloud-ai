import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Wraps the OCS JSON API used by the backend (same contract as before).
 * Uses the standard Nextcloud axios client, which automatically sends the
 * CSRF requesttoken and the OCS-APIRequest header, exactly like core apps.
 * Unwraps the ocs envelope and returns the data object.
 */
export async function api(method, path, data, options = {}) {
	const normalizedPath = String(path || '').replace(/^\/+/, '')
	const url = generateOcsUrl('/apps/eva_ai/api/' + normalizedPath)
	const cfg = {
		method,
		url,
		timeout: normalizedPath.toLowerCase().includes('chat') ? 60000 : 30000,
		...(options && typeof options === 'object' ? options : {}),
	}
	if (method === 'GET' && data !== undefined && data !== null) {
		cfg.params = data
	} else if (method !== 'GET' && data !== undefined) {
		cfg.data = data
	}
	const maxRetries = Number.isInteger(options?.retries)
		? Math.max(0, Math.min(3, options.retries))
		: (method === 'GET' || method === 'HEAD' ? 2 : 0)
	let res
	let lastError
	for (let attempt = 0; attempt <= maxRetries; attempt++) {
		try {
			res = await axios.request(cfg)
			break
		} catch (error) {
			lastError = error
			const status = Number(error?.response?.status || 0)
			const retryable = status === 408 || status === 429 || status >= 500 || !error?.response
			if (!retryable || attempt >= maxRetries) throw error
			await new Promise(resolve => setTimeout(resolve, 150 * (attempt + 1)))
		}
	}
	if (!res) throw lastError || new Error('API request failed')
	const body = res && res.data
	const ocsStatus = Number(body?.ocs?.meta?.statuscode)
	if (Number.isFinite(ocsStatus) && ocsStatus >= 400) {
		const error = new Error(body?.ocs?.meta?.message || body?.ocs?.message || 'OCS request failed')
		error.response = { status: ocsStatus, data: body }
		throw error
	}
	if (body && body.ocs && typeof body.ocs.data !== 'undefined') {
		return body.ocs.data
	}
	return body
}

/** Extracts a human-readable error from any thrown value (axios or other). */
export function errMsg(e) {
	if (e && e.response) {
		const data = e.response.data
		let detail = ''
		try {
			detail = data?.ocs?.message || data?.ocs?.data?.error || data?.error || (typeof data === 'string' ? data : '')
		} catch (_) { /* ignore */ }
		detail = String(detail || '').replace(/\s+/g, ' ').trim().slice(0, 240)
		return String(e.response.status || 'HTTP error') + (detail ? ' ' + detail : '')
	}
	return e && e.message ? String(e.message).replace(/\s+/g, ' ').trim().slice(0, 240) : String(e)
}

/** Escapes a string for safe injection in raw HTML. */
export function esc(s) {
	return String(s || '').replace(/[&<>"']/g, (c) => ({
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;',
	}[c]))
}