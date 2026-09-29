const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const source = fs.readFileSync(`${__dirname}/../src/lib/api.js`, 'utf8')
  .replace(/^import .*$/gm, '')
  .replace(/^export /gm, '')
const context = vm.createContext({ setTimeout })
vm.runInContext(source, context)

test('API errors prefer structured Nextcloud messages', () => {
  assert.equal(context.errMsg({ response: { status: 400, data: { ocs: { data: { message: 'Invalid setting' }, meta: { message: 'Bad request' } } } } }), '400 Invalid setting')
})

test('gateway HTML is replaced with a useful timeout message', () => {
  const message = context.errMsg({ response: { status: 504, data: '<!DOCTYPE html><html><body>upstream secret</body></html>' } })
  assert.match(message, /^504 The AI service took too long to respond/)
  assert.doesNotMatch(message, /DOCTYPE|upstream secret/)
})

test('server failures without details get a retryable message', () => {
  assert.equal(context.errMsg({ response: { status: 503, data: { error: '<html>proxy</html>' } } }), '503 The AI service is temporarily unavailable. Please retry shortly.')
})
