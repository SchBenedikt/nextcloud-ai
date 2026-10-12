const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const MarkdownIt = require('markdown-it')

// Load the ESM sources as a classic script so the pure builders can run in
// node (same technique as tests/ndjson.test.cjs). markdown-it is injected
// because chat-utils imports it; TextDecoder/TextEncoder come from node.
const read = (file) => fs
	.readFileSync(`${__dirname}/../src/lib/${file}`, 'utf8')
	.replace(/^import .*$/gm, '')
	.replace(/^export /gm, '')

function context() {
	const ctx = vm.createContext({ MarkdownIt, TextEncoder, console })
	vm.runInContext(
		`${read('chat-utils.js')}\n${read('chat-export.js')}`
		+ '\nthis.createChatExport = createChatExport'
		+ '\nthis.createChatArchive = createChatArchive',
		ctx,
	)
	return ctx
}

const messages = [
	{ role: 'user', text: 'How do I reset it?', createdAt: 1_700_000_000 },
	{ role: 'assistant', text: 'Use the reset command.', model: 'gpt-test', createdAt: 1_700_000_001 },
]

test('markdown export builds the title, roles and optional model line', () => {
	const { content } = context().createChatExport(messages, { title: 'My chat', exportedAt: '2026-01-01T00:00:00.000Z' })
	assert.ok(content.startsWith('# My chat'))
	assert.match(content, /## You/)
	assert.match(content, /## EVA/)
	assert.match(content, /How do I reset it\?/)
	assert.match(content, /Model: gpt-test/)
})

test('model line and per-message timestamps can be omitted', () => {
	const { content } = context().createChatExport(messages, { includeTimestamps: false, includeModelInfo: false })
	assert.doesNotMatch(content, /Sent /)
	assert.doesNotMatch(content, /Model: gpt-test/)
	assert.ok(content.startsWith('# Eva chat export'))
})

test('plain text export flattens markdown to readable text', () => {
	const { content, extension } = context().createChatExport(messages, { format: 'txt' })
	assert.equal(extension, 'txt')
	assert.doesNotMatch(content, /^#{1,6} /m)
	assert.doesNotMatch(content, /\*\*/)
	assert.match(content, /You/)
	assert.match(content, /Use the reset command\./)
})

test('html export is a self-contained document and escapes message markup', () => {
	const { content, extension } = context().createChatExport([{ role: 'assistant', text: '<b>x</b>' }], { format: 'html', title: 'T' })
	assert.equal(extension, 'html')
	assert.ok(content.startsWith('<!doctype html>'))
	assert.match(content, /<title>T<\/title>/)
	assert.ok(!content.includes('<b>x</b>'))
})

test('pdf returns the print document with the html extension', () => {
	const { extension, print } = context().createChatExport(messages, { format: 'pdf' })
	assert.equal(extension, 'html')
	assert.equal(print, true)
})

test('docx export is a zip package', () => {
	const { content, extension, mime } = context().createChatExport(messages, { format: 'docx' })
	assert.equal(extension, 'docx')
	assert.match(mime, /wordprocessingml/)
	assert.ok(ArrayBuffer.isView(content))
	assert.equal(content[0], 0x50)
	assert.equal(content[1], 0x4b)
})

test('createChatArchive packages chats as a zip and rejects invalid input', () => {
	const { createChatArchive } = context()
	const zip = createChatArchive([{ id: 'a', title: 'A', messages }, { id: 'b', title: 'B', messages }])
	assert.ok(ArrayBuffer.isView(zip))
	assert.equal(zip[0], 0x50)
	assert.equal(zip[1], 0x4b)
	assert.throws(() => createChatArchive([]), /no conversations/)
	assert.throws(() => createChatArchive([{ id: 'a', messages }], { format: 'pdf' }), /one chat/)
	assert.throws(() => createChatArchive([{ id: 'a', text: 'x' }]), /invalid/)
	assert.throws(() => createChatArchive(Array.from({ length: 101 }, (_, i) => ({ id: String(i), messages }))), /100 conversations/)
})

test('date boundaries filter messages and reject invalid ranges', () => {
	const { createChatExport } = context()
	const dated = [
		{ role: 'user', text: 'InRange', createdAt: Date.UTC(2026, 5, 15) },
		{ role: 'user', text: 'NoDate', createdAt: 0 },
	]
	assert.match(createChatExport(dated, { fromDate: '2020-01-01', toDate: '2030-12-31' }).content, /InRange/)
	assert.doesNotMatch(createChatExport(dated, { fromDate: '2020-01-01', toDate: '2030-12-31' }).content, /NoDate/)
	assert.match(createChatExport(dated).content, /NoDate/)
	assert.throws(() => createChatExport(messages, { fromDate: 'nope' }), /date is invalid/)
	assert.throws(() => createChatExport(messages, { fromDate: '2026-02-01', toDate: '2026-01-01' }), /on or before/)
})
