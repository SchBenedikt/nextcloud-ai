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

// One parser for both chat surfaces. Raw HTML is displayed as text, so
// assistant output can never inject markup.
const markdown = new MarkdownIt({ html: false, linkify: true, breaks: true, typographer: false })
markdown.renderer.rules.link_open = (tokens, index, options, env, self) => {
	tokens[index].attrSet('target', '_blank')
	tokens[index].attrSet('rel', 'noopener noreferrer')
	return self.renderToken(tokens, index, options)
}
/**
 * Render a Markdown image as an actual picture.
 *
 * Images were previously downgraded to plain links, so the figures a web
 * search returns never appeared in an answer. They are shown now, but with the
 * two guards that make a remote picture safe to load:
 *
 *  - `referrerpolicy="no-referrer"` so the image host never learns which
 *    Nextcloud page (or which user) is reading the answer, and
 *  - `loading="lazy"` so only images the user actually scrolls to are fetched.
 *
 * The picture is wrapped in a link to the full-size original, keeping the
 * previous "open the source" behaviour available. Only http(s) sources are
 * rendered; `data:`, `javascript:` and every other scheme fall back to text,
 * exactly like an unsafe link.
 */
markdown.renderer.rules.image = (tokens, index, options, env, self) => {
	const token = tokens[index]
	const src = token.attrGet('src') || ''
	const label = token.content || src
	if (!markdown.validateLink(src) || /^data:/i.test(src)) return escHtml(label)
	const srcAttr = escHtml(src)
	const altAttr = escHtml(label)
	const title = token.attrGet('title')
	return '<a class="md-image-link" href="' + srcAttr + '" target="_blank" rel="noopener noreferrer">'
		+ '<img class="md-image" src="' + srcAttr + '" alt="' + altAttr + '"'
		+ (title ? ' title="' + escHtml(title) + '"' : '')
		+ ' loading="lazy" decoding="async" referrerpolicy="no-referrer">'
		+ '</a>'
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
