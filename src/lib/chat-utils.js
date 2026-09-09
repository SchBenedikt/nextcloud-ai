/**
 * Shared chat rendering utilities used by both the Vue vanilla mount
 * and the standalone page.  Extracted to eliminate duplication
 * (Issue #75).
 */

/** Escapes a string for safe injection in raw HTML. */
export function escHtml(s) {
	return String(s || '').replace(/[&<>"']/g, (c) =>
		({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
}

/** Applies inline markdown formatting (code, bold, strikethrough, italic, links). */
export function mdInline(text) {
	// Tokenize before emitting HTML: later substitutions must never rewrite
	// code contents, generated link labels, or href attributes.
	const tokens = /`([^`]+)`|\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<]+)|\*\*([^*]+)\*\*|~~([^~]+)~~|\*([^*]+)\*/g
	return text.replace(tokens, (match, code, label, href, url, bold, deleted, italic) => {
		if (code !== undefined) return '<code>' + code + '</code>'
		if (href !== undefined) return '<a href="' + href + '" target="_blank" rel="noopener">' + label + '</a>'
		if (url !== undefined) return '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>'
		if (bold !== undefined) return '<strong>' + bold + '</strong>'
		if (deleted !== undefined) return '<del>' + deleted + '</del>'
		return '<em>' + italic + '</em>'
	})
}

/** Converts block-level markdown to HTML. */
export function mdToHtml(src) {
	const blocks = []
	let prose = []
	let code = null
	let fenceLength = 0
	for (const line of String(src || '').split('\n')) {
		const fence = /^\s{0,3}(`{3,})(.*)$/.exec(line)
		if (code !== null) {
			if (fence && fence[1].length >= fenceLength && fence[2].trim() === '') {
				blocks.push({ code: code.join('\n') + (code.length ? '\n' : '') })
				code = null
			} else {
				code.push(line)
			}
		} else if (fence) {
			blocks.push({ text: prose.join('\n') })
			prose = []
			code = []
			fenceLength = fence[1].length
		} else {
			prose.push(line)
		}
	}
	if (code !== null) blocks.push({ code: code.join('\n') })
	blocks.push({ text: prose.join('\n') })
	let html = ''
	for (const item of blocks) {
		if (item.code !== undefined) {
			html += '<pre class="md-pre"><code>' + escHtml(item.code) + '</code></pre>\n'
			continue
		}
		const block = item.text
		if (!block) continue
		const lines = block.split('\n')
		let para = []
		let listType = null
		const flushPara = () => {
			if (para.length) {
				html += '<p>' + para.join('<br>') + '</p>\n'
				para = []
			}
		}
		const flushList = () => {
			if (listType === 'ul' || listType === 'ol') {
				html += '</' + listType + '>\n'
				listType = null
			}
		}
		for (const rawLine of lines) {
			const s = rawLine.trim()
			if (s === '') { flushPara(); flushList(); continue }
			const h = /^(#{1,6})\s+(.*)$/.exec(s)
			if (h) { flushPara(); flushList(); html += '<h' + h[1].length + '>' + mdInline(escHtml(h[2])) + '</h' + h[1].length + '>\n'; continue }
			if (/^(-{3,}|\*{3,}|_{3,})$/.test(s)) { flushPara(); flushList(); html += '<hr>\n'; continue }
			if (s[0] === '>') { flushPara(); flushList(); html += '<blockquote>' + mdInline(escHtml(s.slice(1).trim())) + '</blockquote>\n'; continue }
			const ul = /^[-*+]\s+(.*)$/.exec(s)
			const ol = /^(\d+)[.):]\s+(.*)$/.exec(s)
			if (ul || ol) {
				flushPara()
				const type = ul ? 'ul' : 'ol'
				if (listType !== type) { flushList(); html += '<' + type + '>\n'; listType = type }
				html += '<li>' + mdInline(escHtml((ul ? ul[1] : ol[2]) || '')) + '</li>\n'
				continue
			}
			flushList()
			para.push(mdInline(escHtml(s)))
		}
		flushPara()
		flushList()
	}
	return html
}

/**
 * Returns cited sources in the order they appear in the text.
 * Supports both single `[N]` and range `[1-3]` syntax.
 */
export function citedSources(text, sources) {
	sources = Array.isArray(sources) ? sources : []
	const nums = new Set()
	if (text) {
		const re = /\[([\d,\s\-–]+)\]/g
		let m
		while ((m = re.exec(text)) !== null) {
			m[1].split(/[\s,]+/).forEach((tok) => {
				if (!tok) return
				const range = tok.match(/^(\d+)[-–](\d+)$/)
				if (range) {
					const start = Math.max(1, Number(range[1]))
					const end = Math.min(sources.length, Number(range[2]))
					for (let n = start; n <= end; n++) nums.add(n)
				} else {
					const n = parseInt(tok, 10)
					if (n >= 1 && n <= sources.length) nums.add(n)
				}
			})
		}
	}
	return Array.from(nums, ref => ({ ref, src: sources[ref - 1] }))
}

/** Copies text to clipboard with fallback. */
export function copyText(txt, el) {
	const done = () => {
		if (!el) return
		el.textContent = '✓'
		setTimeout(() => { el.textContent = '⧉' }, 1200)
	}
	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(txt).then(done).catch(done)
	} else {
		const ta = document.createElement('textarea')
		ta.value = txt
		ta.style.position = 'fixed'
		ta.style.opacity = '0'
		document.body.appendChild(ta)
		ta.select()
		try { document.execCommand('copy') } catch (_) {}
		ta.remove()
		done()
	}
}
