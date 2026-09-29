const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')

const source = fs.readFileSync(`${__dirname}/../src/lib/voice-input.js`, 'utf8').replace(/^export /gm, '')
const context = vm.createContext({})
vm.runInContext(source + '\nthis.createVoiceInput = createVoiceInput', context)

function fixture(overrides = {}) {
	const calls = { starts: 0, stops: 0, aborts: 0, instances: [], states: [], interim: [], final: [], errors: [] }
	class Recognition {
		constructor() { calls.instances.push(this) }
		start() { calls.starts++; this.onstart?.() }
		stop() { calls.stops++; this.onend?.() }
		abort() { calls.aborts++; this.onend?.() }
	}
	const service = context.createVoiceInput({
		Recognition,
		lang: 'de-DE',
		onState: state => calls.states.push(state),
		onInterim: text => calls.interim.push(text),
		onFinal: (text, details) => calls.final.push([text, details]),
		onError: error => calls.errors.push(error),
		...overrides,
	})
	return { service, calls }
}

test('recognition uses locale and interim mode, delivers editable final text and supports stopping', () => {
	const { service, calls } = fixture()
	assert.equal(service.start('existing text'), true)
	assert.equal(service.recording, true)
	const recognition = calls.instances[0]
	assert.equal(calls.starts, 1)
	assert.equal(recognition.lang, 'de-DE')
	assert.equal(recognition.interimResults, true)
	assert.equal(recognition.continuous, false)
	recognition.onresult({ resultIndex: 0, results: [{ isFinal: false, 0: { transcript: 'guten tag' } }] })
	recognition.onresult({ resultIndex: 0, results: [{ isFinal: true, 0: { transcript: 'Guten Tag' } }] })	
	assert.deepEqual(calls.interim, ['guten tag', ''])
	assert.deepEqual(calls.final, [['Guten Tag', undefined]])
	assert.equal(service.stop(), true)
	assert.equal(calls.stops, 1)
	assert.deepEqual(calls.states, ['recording', 'idle'])
})

test('unsupported browsers, cancellation and permission errors are handled', () => {
	const unsupported = context.createVoiceInput({ Recognition: null })
	assert.equal(unsupported.supported, false)
	assert.equal(unsupported.start(), false)
	const { service, calls } = fixture()
	assert.equal(service.start('keep me'), true)
	assert.equal(service.cancel(), true)
	assert.equal(calls.final[0][0], '')
	assert.equal(calls.final[0][1].cancel, true)
	assert.equal(calls.final[0][1].restore, 'keep me')
	assert.equal(calls.aborts, 1)
	assert.deepEqual(calls.states, ['recording', 'idle'])
	class DeniedRecognition { start() { throw new Error('denied') } }
	const denied = fixture({ Recognition: DeniedRecognition })
	assert.equal(denied.service.start(), false)
	assert.deepEqual(denied.calls.errors, ['unavailable'])
})
