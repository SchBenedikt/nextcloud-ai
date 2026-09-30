import { escHtml, mdToHtml } from './chat-utils'

/** Build a downloadable or printable export from the messages currently loaded in a chat. */
export function createChatExport(messages, { format = 'md', title = 'Eva chat export', exportedAt = new Date().toISOString(), language = 'en', labels = {} } = {}) {
	const items = Array.isArray(messages) ? messages : []
	const roleName = (message) => message?.role === 'user' ? (labels.you || 'You') : (labels.eva || 'EVA')
	const exportedLine = labels.exportedAt ? labels.exportedAt(exportedAt) : 'Exported ' + exportedAt
	const messageText = (message) => {
		const lines = [String(message?.text || '')]
		if (message?.role === 'assistant' && message.reactions) {
			if (typeof message.reactions.helpful === 'boolean') lines.push(labels[message.reactions.helpful ? 'helpful' : 'notHelpful'] || (message.reactions.helpful ? 'Marked helpful' : 'Marked not helpful'))
			if (message.reactions.bookmarked) lines.push(labels.bookmarked || 'Bookmarked')
		}
		return lines.join('\n\n')
	}
	const markdown = ['# ' + title, '', '_' + exportedLine + '_', ...items.flatMap((message) => [
		'', '## ' + roleName(message), '', messageText(message),
	])].join('\n')
	const stem = 'eva-chat-export'
	if (format === 'md') return { content: markdown, mime: 'text/markdown;charset=utf-8', extension: 'md' }
	if (format === 'txt') {
		const plain = markdown
			.replace(/^#{1,6}\s+/gm, '')
			.replace(/^\s*[-*+]\s+/gm, '• ')
			.replace(/^\s*>\s?/gm, '')
			.replace(/`{1,3}/g, '')
			.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '$1 ($2)')
			.replace(/\*\*([^*]+)\*\*/g, '$1')
			.replace(/\*([^*]+)\*/g, '$1')
		return { content: plain, mime: 'text/plain;charset=utf-8', extension: 'txt' }
	}
	if (format === 'html' || format === 'pdf') {
		const documentHtml = '<!doctype html><html lang="' + escHtml(language) + '"><head><meta charset="utf-8">'
			+ '<meta name="viewport" content="width=device-width,initial-scale=1">'
			+ '<title>' + escHtml(title) + '</title><style>'
			+ 'body{font:16px/1.6 system-ui,sans-serif;max-width:850px;margin:3rem auto;padding:0 1.25rem;color:#202124}'
			+ 'h1{font-size:2rem;border-bottom:1px solid #ddd;padding-bottom:.5rem}h2{font-size:1.1rem;margin-bottom:.25rem}'
			+ 'header{color:#666;margin-bottom:2rem}article{border-bottom:1px solid #eee;padding:0 0 1.5rem;margin:1.5rem 0}'
			+ 'pre{white-space:pre-wrap;background:#f5f5f5;padding:1rem;overflow-wrap:anywhere}blockquote{border-left:3px solid #bbb;margin-left:0;padding-left:1rem;color:#555}'
			+ '@media print{body{max-width:none;margin:0;padding:0}article{break-inside:avoid}}'
			+ '</style></head><body><h1>' + escHtml(title) + '</h1><header>' + escHtml(exportedLine)
			+ '</header>' + items.map((message) => '<article><h2>' + escHtml(roleName(message)) + '</h2>' + mdToHtml(messageText(message)) + '</article>').join('')
			+ '</body></html>'
		return { content: documentHtml, mime: 'text/html;charset=utf-8', extension: format === 'pdf' ? 'html' : 'html', print: format === 'pdf' }
	}
	return { content: markdown, mime: 'text/markdown;charset=utf-8', extension: 'md' }
}
