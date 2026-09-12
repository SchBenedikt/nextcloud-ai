const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const source = fs.readFileSync(`${__dirname}/../src/lib/chat-utils.js`, 'utf8').replace(/^import MarkdownIt.*$/m, '').replace(/^export /gm, '')
function context() {
  const ctx = vm.createContext({ MarkdownIt: require('markdown-it') })
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
  assert.match(render('````md\n```js\ncode\n```\n````\nEnd'), /<code class="language-md">```js\ncode\n```\n<\/code>/)
  assert.match(render('```js\nconst x = 1'), /<code class="language-js">const x = 1<\/code>/)
})
test('inline code is literal and URL emphasis cannot inject markup into href', () => {
  const render = context().mdToHtml
  assert.match(render('`**literal** https://example.com`'), /<code>\*\*literal\*\* https:\/\/example.com<\/code>/)
  const html = render('[site](https://example.com/**path**)')
  assert.match(html, /href="https:\/\/example.com\/\*\*path\*\*"/)
  assert.doesNotMatch(html, /href="[^"\n]*</)
})

test('tables, nested lists, ordered starts and nested emphasis render structurally', () => {
  const render = context().mdToHtml
  const html = render('## Title\n\n| Name | Value |\n| --- | ---: |\n| **Bold** | `42` |\n\n3. Parent\n   - Child with **bold and *italic***\n\n> first\n> second')
  assert.match(html, /<table>/)
  assert.match(html, /<th style="text-align:right">Value<\/th>/)
  assert.match(html, /<strong>Bold<\/strong>/)
  assert.match(html, /<ol start="3">[\s\S]*<li>Parent[\s\S]*<ul>/)
  assert.match(html, /<strong>bold and <em>italic<\/em><\/strong>/)
  assert.equal((html.match(/<blockquote>/g) || []).length, 1)
})
test('tilde fences, underscore emphasis, escapes and multi-backtick inline code work', () => {
  const render = context().mdToHtml
  assert.match(render('~~~js\n**literal**\n~~~\nAfter'), /<code class="language-js">\*\*literal\*\*/)
  assert.match(render('__bold__ and _italic_ and ``a ` b``'), /<strong>bold<\/strong> and <em>italic<\/em> and <code>a ` b<\/code>/)
  assert.match(render('\\*literal\\*'), /<p>\*literal\*<\/p>/)
})
test('raw HTML and script links cannot become active content', () => {
  const render = context().mdToHtml
  const html = render('<img src=x onerror=alert(1)>\n\n[x](javascript:alert(1))\n\n[x](data:text/html,bad)')
  assert.doesNotMatch(html, /<img|<script|href="(?:javascript|data):/i)
  assert.match(html, /&lt;img/)
})
test('Markdown images render as pictures with privacy guards', () => {
  const render = context().mdToHtml
  const html = render('![A figure](https://example.com/figure.png "Caption")\n\n![x](javascript:alert(1))\n\n![y](data:image/png;base64,AAAA)')
  // A real picture, with the guards that make a remote load safe.
  assert.match(html, /<img class="md-image" src="https:\/\/example.com\/figure\.png" alt="A figure" title="Caption"/)
  assert.match(html, /loading="lazy"/)
  assert.match(html, /referrerpolicy="no-referrer"/)
  assert.match(html, /href="https:\/\/example.com\/figure\.png"[^>]*rel="noopener noreferrer"/)
  // Unsafe schemes never become a picture source.
  assert.doesNotMatch(html, /<img[^>]*(?:javascript|data):/i)
})
test('balanced link parentheses and punctuation stay outside bare URLs', () => {
  const html = context().mdToHtml('[**docs**](https://example.com/a_(b)) https://example.com/path.')
  assert.match(html, /href="https:\/\/example.com\/a_\(b\)"[^>]*><strong>docs<\/strong><\/a>/)
  assert.match(html, /href="https:\/\/example.com\/path"/)
})
