/**
 * SPDX-FileCopyrightText: 2026 SchBenedikt
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * EVA AI — "Mit AI öffnen" / "Mit diesen Dateien chatten".
 *
 * Wird im Files-Bereich der Nextcloud geladen und registriert zwei
 * File-Actions:
 *  - "Open with EVA": sichtbar bei genau einer markierten Datei
 *  - "Chat über diese Dateien": sichtbar bei einer oder mehreren
 *
 * Beim Klick wird ein Custom-Event `eva-ai:file-context` mit den
 * fileIds gefeuert und die EVA-App im selben Tab geöffnet. Die App
 * liest das Event und schaltet in den FileContextChatView.
 */
import { registerFileAction, DefaultType } from '@nextcloud/files'
import { mdiRobotOutline } from '@mdi/js'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

function evaPageUrl() {
	// Wir öffnen die EVA-Standalone-App mit view=fileContext. Die App
	// selbst liest die fileIds aus dem Custom-Event, ergänzt die URL
	// und schaltet die View um.
	return generateUrl('/apps/eva-ai/app') + '?view=fileContext'
}

function dispatchOpen(fileIds) {
	window.dispatchEvent(new CustomEvent('eva-ai:file-context', {
		detail: { fileIds },
	}))
}

function openInSameTab(fileIds) {
	// Wenn die EVA-App in einem anderen Tab liegt, in neuem Tab öffnen.
	// Im selben Tab einfach Event dispatchen.
	if (window.location.pathname.indexOf('/apps/eva-ai/') === 0) {
		dispatchOpen(fileIds)
		return
	}
	const url = evaPageUrl() + '&fileIds=' + encodeURIComponent(fileIds.join(','))
	window.open(url, '_blank', 'noopener,noreferrer')
}

function onlySupportedFiles(nodes) {
	if (!Array.isArray(nodes) || nodes.length === 0) return false
	// Alles was einen File (nicht Folder) ist, ist OK — die KI kann
	// auch mit Bildern umgehen, der Service entscheidet selbst.
	return nodes.every((n) => n && n.type !== 'folder' && typeof n.fileid !== 'undefined')
}

function fileIds(nodes) {
	return nodes.map((n) => parseInt(n.fileid, 10)).filter((x) => Number.isFinite(x) && x > 0)
}

function buildLabel(context) {
	const n = context.nodes.length
	if (n === 1) {
		return 'Open with EVA'
	}
	return `Chat about these ${n} files with EVA`
}

function buildTitle() {
	return 'Open the selected file(s) in EVA. EVA answers based only on their content.'
}

function hasIndexableFile(nodes) {
	return nodes.some((n) => {
		if (!n || n.type === 'folder') return false
		const mime = (n.mime || '').toLowerCase()
		const name = (n.basename || n.name || '').toLowerCase()
		// Bilder, Videos, kurze Notizen: lieber nicht, das verbraucht nur
		// Index-Platz und liefert meist leere Chunks.
		if (mime.startsWith('image/')) return false
		if (mime.startsWith('video/')) return false
		if (mime.startsWith('audio/')) return false
		if (/\.(png|jpe?g|gif|bmp|webp|svg|ico|heic|mp4|mov|avi|mkv|webm|mp3|wav|ogg|flac)$/i.test(name)) {
			return false
		}
		return true
	})
}

function showNoIndexHint(nodes) {
	// Datei wurde noch nicht indexiert -> freundlich darauf hinweisen.
	// Wir feuern kein Alert, sondern nur eine stille Warnung im EVA-Chat.
	return nodes.some((n) => !n || n.type === 'folder')
}

const action = {
	id: 'eva-ai-open-with',
	displayName: (context) => buildLabel(context),
	title: () => buildTitle(),
	iconSvgInline: () => `<svg viewBox="0 0 24 24" width="24" height="24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="${mdiRobotOutline}"/></svg>`,
	enabled: (context) => {
		const nodes = context && context.nodes ? context.nodes : []
		if (nodes.length === 0) return false
		if (!onlySupportedFiles(nodes)) return false
		if (nodes.length > 20) return false
		return hasIndexableFile(nodes)
	},
	exec: (context) => {
		const ids = fileIds(context.nodes)
		if (ids.length === 0) return
		openInSameTab(ids)
	},
	default: DefaultType.DEFAULT,
	order: -50,
}

registerFileAction(action)

// Hinweis-Logger für Entwickler / zur Diagnose
console.debug('[eva-ai] registered file action: eva-ai-open-with')