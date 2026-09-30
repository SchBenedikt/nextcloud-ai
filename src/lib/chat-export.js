import { escHtml, mdToHtml } from './chat-utils'

const xmlEscape = (value) => String(value ?? '')
	.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
	.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[char])

function markdownParagraphs(markdown) {
	const paragraphs = []
	let inCode = false
	for (const line of String(markdown).split(/\r?\n/)) {
		if (/^\s*```/.test(line)) {
			inCode = !inCode
			continue
		}
		if (inCode) {
			paragraphs.push({ text: line || ' ', style: 'CodeBlock' })
			continue
		}
		if (!line.trim()) {
			paragraphs.push({ text: ' ', style: 'Normal' })
			continue
		}
		const heading = line.match(/^(#{1,3})\s+(.*)$/)
		if (heading) {
			paragraphs.push({ text: heading[2], style: heading[1].length === 1 ? 'Title' : 'Heading' + (heading[1].length - 1) })
			continue
		}
		const image = line.match(/^!\[([^\]]*)\]\((https?:\/\/[^)]+)\)$/)
		if (image) {
			paragraphs.push({ text: image[1] ? image[1] + ' (' + image[2] + ')' : image[2], style: 'Normal' })
			continue
		}
		const link = line.match(/^\[([^\]]+)\]\((https?:\/\/[^)]+)\)$/)
		let text = image ? image[1] : link ? link[1] + ' (' + link[2] + ')' : line
		let style = 'Normal'
		if (/^\s*>/.test(text)) { text = text.replace(/^\s*>\s?/, ''); style = 'Quote' }
		else if (/^\s*[-*+]\s+/.test(text)) text = '• ' + text.replace(/^\s*[-*+]\s+/, '')
		else if (/^\s*\d+[.)]\s+/.test(text)) text = text.replace(/^\s*(\d+)[.)]\s+/, '$1. ')
		text = text.replace(/`([^`]+)`/g, '$1').replace(/\*\*([^*]+)\*\*/g, '$1').replace(/\*([^*]+)\*/g, '$1').replace(/_([^_]+)_/g, '$1')
		paragraphs.push({ text, style })
	}
	return paragraphs
}

function crc32(bytes) {
	let crc = 0xffffffff
	for (const byte of bytes) {
		crc ^= byte
		for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0)
	}
	return (crc ^ 0xffffffff) >>> 0
}

/** Create a small standards-compliant, uncompressed ZIP package for a DOCX. */
function zipStore(entries) {
	const encoder = new TextEncoder()
	const chunks = []
	const central = []
	let offset = 0
	for (const [name, content] of Object.entries(entries)) {
		const nameBytes = encoder.encode(name)
		const data = content instanceof Uint8Array ? content : encoder.encode(content)
		const crc = crc32(data)
		const local = new Uint8Array(30 + nameBytes.length + data.length)
		const view = new DataView(local.buffer)
		view.setUint32(0, 0x04034b50, true); view.setUint16(4, 20, true); view.setUint16(6, 0x0800, true)
		view.setUint16(8, 0, true); view.setUint32(14, crc, true); view.setUint32(18, data.length, true); view.setUint32(22, data.length, true)
		view.setUint16(26, nameBytes.length, true); view.setUint16(28, 0, true)
		local.set(nameBytes, 30); local.set(data, 30 + nameBytes.length)
		chunks.push(local)

		const directory = new Uint8Array(46 + nameBytes.length)
		const directoryView = new DataView(directory.buffer)
		directoryView.setUint32(0, 0x02014b50, true); directoryView.setUint16(4, 20, true); directoryView.setUint16(6, 20, true)
		directoryView.setUint16(8, 0x0800, true); directoryView.setUint16(10, 0, true)
		directoryView.setUint32(16, crc, true); directoryView.setUint32(20, data.length, true); directoryView.setUint32(24, data.length, true)
		directoryView.setUint16(28, nameBytes.length, true); directoryView.setUint16(30, 0, true); directoryView.setUint16(32, 0, true)
		directoryView.setUint32(38, 0, true); directoryView.setUint32(42, offset, true)
		directory.set(nameBytes, 46); central.push(directory)
		offset += local.length
	}
	const centralSize = central.reduce((sum, chunk) => sum + chunk.length, 0)
	const end = new Uint8Array(22)
	const endView = new DataView(end.buffer)
	endView.setUint32(0, 0x06054b50, true); endView.setUint16(8, central.length, true); endView.setUint16(10, central.length, true)
	endView.setUint32(12, centralSize, true); endView.setUint32(16, offset, true)
	const output = new Uint8Array(offset + centralSize + end.length)
	let cursor = 0
	for (const chunk of [...chunks, ...central, end]) { output.set(chunk, cursor); cursor += chunk.length }
	return output
}

/** Package multiple conversations into one bounded archive. */
export function createChatArchive(chats, { format = 'md', ...options } = {}) {
	const conversations = Array.isArray(chats) ? chats : []
	if (conversations.length === 0) throw new Error('There are no conversations to export.')
	if (conversations.length > 100) throw new Error('A chat archive can contain up to 100 conversations at a time.')
	if (format === 'pdf') throw new Error('Print to PDF is available for one chat at a time; choose HTML for a batch archive.')
	const entries = {}
	const encoder = new TextEncoder()
	let totalTextBytes = 0
	const usedNames = new Set()
	conversations.forEach((chat, index) => {
		if (!chat || typeof chat.id !== 'string' || !Array.isArray(chat.messages)) throw new Error('A conversation in the archive is invalid.')
		for (const message of chat.messages) totalTextBytes += encoder.encode(String(message?.text || '')).length
		if (totalTextBytes > 50 * 1024 * 1024) throw new Error('The chat archive is larger than 50 MB.')
		const file = createChatExport(chat.messages, { ...options, format, title: chat.title || 'Eva chat export' })
		const id = chat.id.replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 80) || 'chat'
		let name = 'chat-' + id + '.' + file.extension
		if (usedNames.has(name)) name = 'chat-' + id + '-' + (index + 1) + '.' + file.extension
		usedNames.add(name)
		entries[name] = file.content
	})
	return zipStore(entries)
}

function createDocx(markdown) {
	const paragraphs = markdownParagraphs(markdown).map(({ text, style }) => '<w:p><w:pPr><w:pStyle w:val="' + style + '"/></w:pPr><w:r><w:t xml:space="preserve">' + xmlEscape(text) + '</w:t></w:r></w:p>').join('')
	const documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' + paragraphs + '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>'
	const stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="30"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="CodeBlock"><w:name w:val="Code Block"/><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:pPr><w:ind w:left="360"/></w:pPr></w:style></w:styles>'
	return zipStore({
		'[Content_Types].xml': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
		'_rels/.rels': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
		'word/document.xml': documentXml,
		'word/styles.xml': stylesXml,
		'word/_rels/document.xml.rels': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
	})
}

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
	if (format === 'docx') return { content: createDocx(markdown), mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', extension: 'docx' }
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
