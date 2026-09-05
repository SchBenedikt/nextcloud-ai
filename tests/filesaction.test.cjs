const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')

test('Files action loads translations and opens a subpath-safe single/batch context', async () => {
	const opened = []
	const events = []
	let action
	let loaded = false
	const source = fs.readFileSync(`${__dirname}/../src/lib/filesaction.js`, 'utf8').replace(/^import .+$/gm, '')
	vm.runInNewContext(source, {
		URLSearchParams,
		console: { info() {}, warn() {} },
		generateUrl: path => `/nextcloud/index.php${path}`,
		loadTranslations: async () => { loaded = true },
		t: text => text,
		registerFileAction: value => { assert.ok(loaded); action = value },
		CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail } },
		window: {
			location: { pathname: '/nextcloud/index.php/apps/files' },
			open: url => { opened.push(url); return {} },
			dispatchEvent: event => events.push(event),
		},
	})
	await new Promise(resolve => setImmediate(resolve))
	assert.ok(action)
	assert.equal(action.enabled({ nodes: [] }), false)
	assert.equal(action.enabled({ nodes: [{ type: 'folder' }] }), false)
	assert.equal(action.enabled({ nodes: Array(21).fill({ fileid: 1 }) }), false)
	assert.equal(await action.exec({ nodes: [{ fileid: 42 }] }), true)
	assert.equal(opened[0], '/nextcloud/index.php/apps/eva_ai/app?view=fileContext&fileIds=42')
	const result = await action.execBatch({ nodes: [{ fileid: 42 }, { fileid: 43 }] })
	assert.deepEqual(Array.from(result), [true, true])
	assert.equal(opened.length, 2)
	assert.equal(new URL(opened[1], 'http://localhost').searchParams.get('fileIds'), '42,43')
	assert.deepEqual(Array.from(events[1].detail.fileIds), [42, 43])
	assert.equal(await action.exec({ nodes: [{ fileid: 'invalid' }] }), null)
	assert.equal(opened.length, 2)
})
