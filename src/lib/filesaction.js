/**
 * SPDX-FileCopyrightText: 2026 SchBenedikt
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Eva AI — "Mit AI öffnen" / "Mit diesen Dateien chatten".
 *
 * Wird im Files-Bereich der Nextcloud geladen und registriert eine
 * File-Action. Bei einer markierten Datei: "Open with Eva".
 * Bei mehreren: "Chat about these N files with Eva".
 *
 * Beim Klick wird die Eva-App in einem neuen Tab geöffnet (oder im
 * selben Tab, falls Eva schon offen ist). Die App liest die fileIds
 * aus der URL und öffnet den FileContextChatView.
 */
import { registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { loadTranslations } from '@nextcloud/l10n'
import { translate as t } from './i18n'

const EVA_APP_PATH = generateUrl('/apps/eva_ai/app')

function evaPageUrl(fileIds) {
	const params = new URLSearchParams()
	params.set('view', 'fileContext')
	if (fileIds && fileIds.length > 0) {
		params.set('fileIds', fileIds.join(','))
	}
	return EVA_APP_PATH + '?' + params.toString()
}

function fileIds(nodes) {
	const out = []
	for (const n of nodes) {
		const fid = n && (n.fileid !== undefined ? n.fileid : n.id)
		const num = parseInt(fid, 10)
		if (Number.isFinite(num) && num > 0) {
			out.push(num)
		}
	}
	return out
}

function dispatchOpen(fileIds) {
	window.dispatchEvent(new CustomEvent('eva-ai:file-context', {
		detail: { fileIds },
	}))
}

function openEva(fileIds) {
	const url = evaPageUrl(fileIds)
	// Wenn die Eva-App in einem neuen Tab geöffnet werden soll, machen wir das.
	// Wird sie im selben Tab aufgerufen (z.B. von einem Frame), wird sie
	// ersetzt.
	if (window.location.pathname.startsWith(EVA_APP_PATH)) {
		window.location.href = url
		return
	}
	const win = window.open(url, '_blank', 'noopener,noreferrer')
	if (!win) {
		window.location.href = url
	}
}

const ROBOT_ICON_SVG = '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M17.5 15.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm-11 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm9.42-7.18A2 2 0 0 0 14 7h-4a2 2 0 0 0-1.92 1.32L6 13h12l-2.08-4.68ZM4 15v3a2 2 0 0 0 2 2h1v3h2v-3h6v3h2v-3h1a2 2 0 0 0 2-2v-3H4Z"/></svg>'

const action = {
	id: 'eva-ai-open-with',
	displayName(context) {
		const nodes = (context && context.nodes) || []
		const n = nodes.length
		if (n === 1) {
			return t('Open with Eva')
		}
		return t('Chat about these {count} files with Eva', { count: n })
	},
	title(context) {
		const nodes = (context && context.nodes) || []
		if (nodes.length === 1) {
			return t('Open the selected file in Eva. Eva answers based only on its content.')
		}
		return t('Open all selected files in Eva. Eva answers based only on their content.')
	},
	iconSvgInline() {
		return ROBOT_ICON_SVG
	},
	enabled(context) {
		const nodes = (context && context.nodes) || []
		if (nodes.length === 0) return false
		if (nodes.length > 20) return false
		// Nur Dateien, keine Ordner.
		for (const n of nodes) {
			if (!n) return false
			if (n.type === 'folder' || n.mime === 'httpd/unix-directory') return false
		}
		return true
	},
	exec(context) {
		const nodes = (context && context.nodes) || []
		const ids = fileIds(nodes)
		if (ids.length === 0) return Promise.resolve(null)
		dispatchOpen(ids)
		openEva(ids)
		return Promise.resolve(true)
	},
	execBatch(context) {
		const nodes = (context && context.nodes) || []
		const ids = fileIds(nodes)
		if (ids.length === 0) return Promise.resolve(nodes.map(() => null))
		dispatchOpen(ids)
		openEva(ids)
		return Promise.resolve(nodes.map(() => true))
	},
	order: 100,
}

loadTranslations('eva_ai')
	.catch((error) => console.warn('[eva-ai] translation bundle could not be loaded', error))
	.finally(() => {
		registerFileAction(action)
		console.info('[eva-ai] registered file action: eva-ai-open-with')
	})
