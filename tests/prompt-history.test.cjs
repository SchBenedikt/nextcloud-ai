const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')

function loadModule() {
	let source = fs.readFileSync(path.join(__dirname, '../src/lib/prompt-history.js'), 'utf8')
	source = source.replace('export function createPromptHistory', 'function createPromptHistory') + '\nmodule.exports = { createPromptHistory }'
	const module = { exports: {} }
	vm.runInNewContext(source, { module, Math, Date, JSON, String, Number, Boolean, Object })
	return module.exports.createPromptHistory
}

function memoryStorage() {
	const data = new Map()
	return { getItem: (key) => data.get(key) ?? null, setItem: (key, value) => data.set(key, value) }
}

test('prompt history records usage, searches, favorites, categorizes and removes prompts', () => {
	const history = loadModule()(memoryStorage(), 'prompts')
	history.record('Summarize this report')
	history.record('Translate this report')
	history.record('Summarize this report')
	let prompts = history.list('summarize')
	assert.equal(prompts.length, 1)
	assert.equal(prompts[0].usageCount, 2)
	history.update(prompts[0].id, { favorite: true, category: 'Work' })
	assert.equal(history.list()[0].category, 'Work')
	assert.equal(history.list('work').length, 1)
	history.remove(prompts[0].id)
	assert.equal(history.list().length, 1)
})

test('prompt history survives malformed storage without throwing', () => {
	const storage = memoryStorage()
	storage.setItem('prompts', '{bad json')
	const history = loadModule()(storage, 'prompts')
	assert.equal(history.list().length, 0)
	history.record('A valid prompt')
	assert.equal(history.list().length, 1)
})
