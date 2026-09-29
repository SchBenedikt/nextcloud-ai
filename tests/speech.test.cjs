const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')

const source = fs.readFileSync(`${__dirname}/../src/lib/speech.js`, 'utf8').replace(/^export /gm, '')
const context = vm.createContext({})
vm.runInContext(source + '\nthis.createSpeechService = createSpeechService', context)

function fixture() {
  class Utterance {
    constructor(text) { this.text = text }
  }
  const calls = { spoken: [], cancelled: 0, paused: 0, resumed: 0 }
  const voices = [
    { name: 'German Voice', lang: 'de-DE', voiceURI: 'de' },
    { name: 'English Voice', lang: 'en-US', voiceURI: 'en' },
  ]
  const synthesis = {
    getVoices: () => voices,
    speak: utterance => calls.spoken.push(utterance),
    cancel: () => { calls.cancelled++ },
    pause: () => { calls.paused++ },
    resume: () => { calls.resumed++ },
  }
  return { service: context.createSpeechService(synthesis, Utterance), calls }
}

test('speech playback applies language, voice and a bounded rate with controls', () => {
  const { service, calls } = fixture()
  const states = []
  assert.equal(service.speak('Hallo Welt', { rate: 3, lang: 'de-DE', voice: 'de', onState: state => states.push(state) }), true)
  const utterance = calls.spoken[0]
  assert.equal(utterance.text, 'Hallo Welt')
  assert.equal(utterance.rate, 2)
  assert.equal(utterance.lang, 'de-DE')
  assert.equal(utterance.voice.name, 'German Voice')
  utterance.onstart()
  service.pause()
  service.resume()
  utterance.onend()
  assert.deepEqual(states, ['speaking', 'idle'])
  assert.equal(calls.paused, 1)
  assert.equal(calls.resumed, 1)
})

test('starting another answer cancels the previous playback and resets its controls', () => {
  const { service, calls } = fixture()
  const states = []
  service.speak('First', { onState: state => states.push(['first', state]) })
  service.speak('Second', { onState: state => states.push(['second', state]) })
  calls.spoken[0].onend()
  assert.equal(calls.cancelled, 2)
  assert.deepEqual(states, [['first', 'idle']])
  assert.equal(calls.spoken[1].text, 'Second')
})

test('empty text and unsupported browsers fail without throwing', () => {
  const { service, calls } = fixture()
  assert.equal(service.speak('   '), false)
  assert.equal(calls.spoken.length, 0)
  const unsupported = context.createSpeechService(null, null)
  assert.equal(unsupported.supported, false)
  assert.equal(unsupported.speak('Hello'), false)
  unsupported.stop()
})

test('rates below the supported range are clamped', () => {
  const { service, calls } = fixture()
  service.speak('Hello', { rate: 0.1 })
  assert.equal(calls.spoken[0].rate, 0.5)
})
