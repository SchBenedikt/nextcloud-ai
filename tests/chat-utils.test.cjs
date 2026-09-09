const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const source = fs.readFileSync(`${__dirname}/../src/lib/chat-utils.js`, 'utf8').replace(/^export /gm, '')
function context() {
  const ctx = vm.createContext({})
  vm.runInContext(source, ctx)
  return ctx
}
test('citation ranges are bounded by available sources even beyond safe integers', () => {
  const ctx = context()
  vm.runInContext(`result = citedSources('[9007199254740992-9007199254740994] [1-99999999999999999999999999999999]', ['a', 'b', 'c'])`, ctx, { timeout: 200 })
  assert.deepEqual(JSON.parse(JSON.stringify(ctx.result)), [{ ref: 1, src: 'a' }, { ref: 2, src: 'b' }, { ref: 3, src: 'c' }])
})
test('citations preserve first mention order and reject invalid references', () => {
  const ctx = context()
  assert.equal(JSON.stringify(ctx.citedSources('[3] [1–2] [3] [0] [5-2] [9]', ['a', 'b', 'c'])), JSON.stringify([{ ref: 3, src: 'c' }, { ref: 1, src: 'a' }, { ref: 2, src: 'b' }]))
})
test('text after fenced code remains prose and multiple blocks remain separate', () => {
  const html = context().mdToHtml('Before\n\n```js\nconst x = 1\n```\nAfter **bold**\n\n```\n<safe>\n```\nEnd')
  assert.match(html, /<p>After <strong>bold<\/strong><\/p>/)
  assert.match(html, /<p>End<\/p>/)
  assert.equal((html.match(/<pre /g) || []).length, 2)
  assert.match(html, /&lt;safe&gt;/)
})
test('fences preserve nested shorter fences and unfinished streaming code', () => {
  const render = context().mdToHtml
  assert.match(render('````md\n```js\ncode\n```\n````\nEnd'), /<code>```js\ncode\n```\n<\/code>/)
  assert.match(render('```js\nconst x = 1'), /<code>const x = 1<\/code>/)
})
test('inline code is literal and URL emphasis cannot inject markup into href', () => {
  const render = context().mdToHtml
  assert.match(render('`**literal** https://example.com`'), /<code>\*\*literal\*\* https:\/\/example.com<\/code>/)
  const html = render('[site](https://example.com/**path**)')
  assert.match(html, /href="https:\/\/example.com\/\*\*path\*\*"/)
  assert.doesNotMatch(html, /href="[^"\n]*</)
})
