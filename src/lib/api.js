import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Wraps the OCS JSON API used by the backend (same contract as before).
 * Uses the standard Nextcloud axios client, which automatically sends the
 * CSRF requesttoken and the OCS-APIRequest header, exactly like core apps.
 * Unwraps the ocs envelope and returns the data object.
 */
export async function api(method, path, data) {
	const url = generateOcsUrl('/apps/eva-ai/api/' + path)
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
			detail = data && data.ocs && data.ocs.message ? data.ocs.message : ''
		} catch (_) { /* ignore */ }
		return (e.response.status || '') + (detail ? ' ' + detail : '')
	}
	return e && e.message ? e.message : String(e)
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