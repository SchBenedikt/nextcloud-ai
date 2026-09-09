const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const source = fs.readFileSync(`${__dirname}/../src/lib/ndjson.js`, 'utf8').replace(/^export /gm, '')
const ctx = vm.createContext({ TextDecoder })
vm.runInContext(source, ctx)

test('stream delivers split UTF-8 and an unterminated final done event', async () => {
  const bytes = new TextEncoder().encode('\n{"type":"content","delta":"Grüße 😀"}\r\n{"type":"done","answer":"Fertig"}')
  const body = new ReadableStream({ start(controller) { for (const byte of bytes) controller.enqueue(Uint8Array.of(byte)); controller.close() } })
  const events = []
  await ctx.readNdjson(body, event => events.push(event))
  assert.equal(JSON.stringify(events), JSON.stringify([{ type: 'content', delta: 'Grüße 😀' }, { type: 'done', answer: 'Fertig' }]))
  assert.equal(body.locked, false)
})
test('malformed events cancel the stream and release its reader', async () => {
  let cancelled = false
  const body = new ReadableStream({ start(controller) { controller.enqueue(new TextEncoder().encode('{broken}\n')) }, cancel() { cancelled = true } })
  await assert.rejects(ctx.readNdjson(body, () => {}))
  assert.equal(cancelled, true)
  assert.equal(body.locked, false)
})
test('transport and consumer errors propagate and release the reader', async () => {
  const failure = new Error('disconnected')
  const broken = new ReadableStream({ start(controller) { controller.error(failure) } })
  await assert.rejects(ctx.readNdjson(broken, () => {}), /disconnected/)
  assert.equal(broken.locked, false)
  const body = new ReadableStream({ start(controller) { controller.enqueue(new TextEncoder().encode('{}\n')) } })
  await assert.rejects(ctx.readNdjson(body, () => { throw new Error('render failed') }), /render failed/)
  assert.equal(body.locked, false)
})
