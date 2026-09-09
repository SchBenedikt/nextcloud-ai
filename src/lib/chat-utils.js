import MarkdownIt from 'markdown-it'

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

// One parser for both chat surfaces. HTML is displayed as text; images are
// rendered as links so assistant output cannot trigger remote tracking loads.
const markdown = new MarkdownIt({ html: false, linkify: true, breaks: true, typographer: false })
markdown.renderer.rules.link_open = (tokens, index, options, env, self) => {
	tokens[index].attrSet('target', '_blank')
	tokens[index].attrSet('rel', 'noopener noreferrer')
	return self.renderToken(tokens, index, options)
}
markdown.renderer.rules.image = (tokens, index) => {
	const token = tokens[index]
	const href = token.attrGet('src') || ''
	const label = token.content || href
	if (!markdown.validateLink(href) || /^data:/i.test(href)) return escHtml(label)
	return '<a href="' + escHtml(href) + '" target="_blank" rel="noopener noreferrer">' + escHtml(label) + '</a>'
}
const fence = markdown.renderer.rules.fence
markdown.renderer.rules.fence = (...args) => fence(...args).replace('<pre>', '<pre class="md-pre">')
markdown.renderer.rules.table_open = () => '<div class="md-table-scroll" tabindex="0"><table>\n'
markdown.renderer.rules.table_close = () => '</table></div>\n'

/** Render raw inline Markdown, escaping HTML and rejecting unsafe links. */
export function mdInline(text) {
	return markdown.renderInline(String(text ?? ''))
}

/** Render Markdown, including incomplete fenced code during streaming. */
export function mdToHtml(src) {
	return markdown.render(String(src ?? ''))
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
