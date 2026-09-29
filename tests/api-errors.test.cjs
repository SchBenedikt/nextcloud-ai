const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const source = fs.readFileSync(`${__dirname}/../src/lib/api.js`, 'utf8')
  .replace(/^import .*$/gm, '')
  .replace(/^export /gm, '')
const context = vm.createContext({ setTimeout })
vm.runInContext(source, context)
const chatUtils = fs.readFileSync(`${__dirname}/../src/lib/chat-utils.js`, 'utf8')
const apiErrorHelper = chatUtils.match(/export function apiErrorMessage\([\s\S]*?\n\}/)?.[0]
if (!apiErrorHelper) throw new Error('apiErrorMessage helper was not found')
vm.runInContext(apiErrorHelper.replace(/^export /, '') + '\nthis.apiErrorMessage = apiErrorMessage', context)

test('API errors prefer structured Nextcloud messages', () => {
  assert.equal(context.errMsg({ response: { status: 400, data: { ocs: { data: { message: 'Invalid setting' }, meta: { message: 'Bad request' } } } } }), '400 Invalid setting')
})

test('busy responses show their human-readable recovery message', () => {
  assert.equal(context.errMsg({ response: { status: 503, data: { error: 'busy', message: 'Another chat request is already running. Please retry shortly.' } } }), '503 Another chat request is already running. Please retry shortly.')
})

test('normalized API errors show the nested human-readable message', () => {
  assert.equal(context.errMsg({ response: { status: 409, data: { ocs: { data: { error: { code: 'conflict', message: 'This chat changed in another tab.' } } } } } }), '409 This chat changed in another tab.')
})

test('fetch clients extract legacy and normalized OCS error messages', () => {
  assert.equal(context.apiErrorMessage({ ocs: { data: { error: { code: 'invalid_request', message: 'Choose a provider.' } } } }), 'Choose a provider.')
  assert.equal(context.apiErrorMessage({ ocs: { data: { error: 'busy', message: 'Retry shortly.' } } }), 'Retry shortly.')
})

test('gateway HTML is replaced with a useful timeout message', () => {
  const message = context.errMsg({ response: { status: 504, data: '<!DOCTYPE html><html><body>upstream secret</body></html>' } })
  assert.match(message, /^504 The AI service took too long to respond/)
  assert.doesNotMatch(message, /DOCTYPE|upstream secret/)
})

test('server failures without details get a retryable message', () => {
  assert.equal(context.errMsg({ response: { status: 503, data: { error: '<html>proxy</html>' } } }), '503 The AI service is temporarily unavailable. Please retry shortly.')
})
