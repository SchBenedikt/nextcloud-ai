import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Wraps the OCS JSON API used by the backend (same contract as before).
 * Uses the standard Nextcloud axios client, which automatically sends the
 * CSRF requesttoken and the OCS-APIRequest header, exactly like core apps.
 * Unwraps the ocs envelope and returns the data object.
 */
export async function api(method, path, data) {
	const normalizedPath = String(path || '').replace(/^\/+/, '')
	const url = generateOcsUrl('/apps/eva_ai/api/' + normalizedPath)
	const cfg = { method, url }
	if (method === 'GET' && data !== undefined && data !== null) {
		cfg.params = data
	} else if (method !== 'GET' && data !== undefined) {
		cfg.data = data
	}
	const res = await axios.request(cfg)
	const body = res && res.data
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