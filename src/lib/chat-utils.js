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
	return text
		.replace(/`([^`]+)`/g, '<code>$1</code>')
		.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
		.replace(/~~([^~]+)~~/g, '<del>$1</del>')
		.replace(/\*([^*]+)\*/g, '<em>$1</em>')
		.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
		.replace(/(^|[\s(])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>')
}

/** Converts block-level markdown to HTML. */
export function mdToHtml(src) {
	const blocks = String(src || '').split(/(```+)/)
	let html = ''
	for (let i = 0; i < blocks.length; i++) {
		if (i % 2 === 1) {
			const fence = blocks[i]
			if (fence[0] !== '`') continue
			const body = blocks[++i] ?? ''
			const nl = body.indexOf('\n')
			const lang = (nl > 0 ? body.slice(0, nl) : '').trim().replace(/[^a-zA-Z0-9_+-]/g, '')
			const code = body.slice(nl > 0 ? nl + 1 : 0).replace(/[ \t]+\n?$/, '')
			html += '<pre class="md-pre"><code>' + escHtml(code) + '</code></pre>\n'
			continue
		}
		const block = blocks[i]
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
	const nums = new Set()
	if (text) {
		const re = /\[([\d,\s\-–]+)\]/g
		let m
		while ((m = re.exec(text)) !== null) {
			m[1].split(/[\s,]+/).forEach((tok) => {
				if (!tok) return
				const range = tok.match(/^(\d+)[-–](\d+)$/)
				if (range) {
					for (let n = parseInt(range[1], 10); n <= parseInt(range[2], 10); n++) nums.add(n)
				} else {
					const n = parseInt(tok, 10)
					if (!isNaN(n)) nums.add(n)
				}
			})
		}
	}
	return (sources || [])
		.map((src, i) => ({ ref: i + 1, src }))
		.filter((x) => nums.has(x.ref))
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
