// Issue #133: exercise the standalone chat in a real browser against a mocked
// API, so the send → stream → tool → confirmation → approve → persist flow and
// the stop button are covered without a Nextcloud or Ollama instance.
const { test, expect } = require('@playwright/test')
const { execFileSync } = require('node:child_process')
const fs = require('node:fs/promises')
const { openChat, line } = require('./standalone-harness.cjs')

function storedZipEntries(buffer) {
  const entries = new Map()
  let offset = 0
  while (buffer.readUInt32LE(offset) === 0x04034b50) {
    const nameLength = buffer.readUInt16LE(offset + 26)
    const extraLength = buffer.readUInt16LE(offset + 28)
    const size = buffer.readUInt32LE(offset + 18)
    const nameStart = offset + 30
    const name = buffer.toString('utf8', nameStart, nameStart + nameLength)
    const dataStart = nameStart + nameLength + extraLength
    entries.set(name, buffer.toString('utf8', dataStart, dataStart + size))
    offset = dataStart + size
  }
  return entries
}

// All helpers used inside page.evaluate must be defined in the browser: inline
// the filters instead of referencing Node-scope functions.
const messagePostsInPage = `window.__mock.saved.filter((r) => r.url.includes('/messages') && r.method === 'POST')`

test('streams an answer and persists the question/answer pair in order', async ({ page }) => {
  await openChat(page)
  const streamLines = [
    line({ type: 'content', delta: 'Hello' }),
    line({ type: 'content', delta: ' world' }),
    line({ type: 'done', answer: 'Hello world', sources: [], followups: ['And now?'] }),
  ]
  await page.evaluate((lines) => { window.__mock.streamLines = lines }, streamLines)

  await page.fill('#q', 'Hi')
  await page.click('#send')

  // Streaming renders into the user and assistant bubbles.
  const bubbles = page.locator('.rb')
  await expect(bubbles.first()).toHaveText('Hi')
  await expect(bubbles.last()).toContainText('Hello world')

  // The pair is persisted in conversation order: user message first, then the
  // assistant answer with its follow-up chips.
  await expect.poll(() => page.evaluate(messagePostsInPage)).toEqual([
    expect.objectContaining({ body: expect.objectContaining({ role: 'user', text: 'Hi' }) }),
    expect.objectContaining({ body: expect.objectContaining({ role: 'assistant', text: 'Hello world', followups: ['And now?'] }) }),
  ])
})

test('sends image attachments for analysis, including an image-only prompt', async ({ page }) => {
  await openChat(page)
  await page.evaluate((lines) => { window.__mock.streamLines = lines }, [
    line({ type: 'done', answer: 'The image is a tiny PNG.', sources: [], followups: [] }),
  ])
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', 'base64')
  await page.locator('#chat-image-input').setInputFiles({ name: 'tiny.png', mimeType: 'image/png', buffer: png })
  await expect(page.locator('.image-privacy-note')).toContainText('not stored in chat history')
  await page.click('#send')

  const streamRequest = await page.waitForFunction(() => window.__mock.saved.find((request) => request.url.includes('/chat/stream')) || null)
  const payload = await streamRequest.jsonValue()
  expect(payload.body.images).toHaveLength(1)
  expect(payload.body.images[0]).toMatchObject({ name: 'tiny.png', mime: 'image/png' })
  expect(payload.body.images[0].data).toBe(png.toString('base64'))
  await expect(page.locator('.rb').first()).toContainText('Describe these images.')
  await expect(page.locator('.rb').first()).toContainText('tiny.png')
  await expect(page.locator('.rb').last()).toContainText('The image is a tiny PNG.')
})

test('exports a plain text chat and a safe standalone HTML document', async ({ page }) => {
  await openChat(page)
  const answer = '<script>window.pwned=true</script> **safe answer**\n```js\nconst count = 2;\n```'
  await page.evaluate((lines) => { window.__mock.streamLines = lines }, [
    line({ type: 'content', delta: answer }),
    line({ type: 'done', answer, sources: [], followups: [] }),
  ])
  await page.fill('#q', 'Export me')
  await page.click('#send')
  await expect(page.locator('.rb').last()).toContainText('safe answer')

  await page.selectOption('#export-format', 'txt')
  const textDownloadPromise = page.waitForEvent('download')
  await page.click('#export')
  const textDownload = await textDownloadPromise
  expect(textDownload.suggestedFilename()).toMatch(/\.txt$/)
  const textContents = await fs.readFile(await textDownload.path(), 'utf8')
  expect(textContents).toContain('Export me')
  expect(textContents).toContain('safe answer')

  await page.selectOption('#export-format', 'html')
  const htmlDownloadPromise = page.waitForEvent('download')
  await page.click('#export')
  const htmlDownload = await htmlDownloadPromise
  expect(htmlDownload.suggestedFilename()).toMatch(/\.html$/)
  const htmlContents = await fs.readFile(await htmlDownload.path(), 'utf8')
  expect(htmlContents).toContain('<!doctype html>')
  expect(htmlContents).toContain('&lt;script&gt;window.pwned=true&lt;/script&gt;')
  expect(htmlContents).not.toContain('<script>window.pwned=true</script>')

  await page.selectOption('#export-format', 'docx')
  const wordDownloadPromise = page.waitForEvent('download')
  await page.click('#export')
  const wordDownload = await wordDownloadPromise
  expect(wordDownload.suggestedFilename()).toMatch(/\.docx$/)
  const wordPath = await wordDownload.path()
  expect(execFileSync('unzip', ['-t', wordPath], { encoding: 'utf8' })).toContain('No errors detected')
  const wordContents = await fs.readFile(wordPath)
  expect(wordContents.subarray(0, 4)).toEqual(Buffer.from([0x50, 0x4b, 0x03, 0x04]))
  const wordEntries = storedZipEntries(wordContents)
  expect(wordEntries.has('[Content_Types].xml')).toBe(true)
  expect(wordEntries.has('word/document.xml')).toBe(true)
  expect(wordEntries.get('word/document.xml')).toContain('&lt;script&gt;window.pwned=true&lt;/script&gt;')
  expect(wordEntries.get('word/document.xml')).toContain('<w:pStyle w:val="Title"/>')
  expect(wordEntries.get('word/document.xml')).toContain('<w:pStyle w:val="CodeBlock"/>')
  expect(wordEntries.get('word/document.xml')).toContain('const count = 2;')

  await page.selectOption('#export-format', 'pdf')
  const printPagePromise = page.waitForEvent('popup')
  await page.click('#export')
  const printPage = await printPagePromise
  await expect(printPage).toHaveTitle('Chat with your files')
  await expect(printPage.locator('body')).toContainText('safe answer')

  await page.check('#export-selection-toggle')
  const messageSelections = page.locator('.message-export-select input')
  await expect(messageSelections).toHaveCount(2)
  await expect(page.locator('#export')).toBeDisabled()
  await messageSelections.first().check()
  await expect(page.locator('#export')).toBeEnabled()
  await page.selectOption('#export-format', 'txt')
  const selectedDownloadPromise = page.waitForEvent('download')
  await page.click('#export')
  const selectedDownload = await selectedDownloadPromise
  const selectedContents = await fs.readFile(await selectedDownload.path(), 'utf8')
  expect(selectedContents).toContain('Export me')
  expect(selectedContents).not.toContain('safe answer')
  await messageSelections.first().uncheck()
  await expect(page.locator('#export')).toBeDisabled()
  await page.uncheck('#export-selection-toggle')
  await expect(page.locator('#export')).toBeEnabled()

  await page.evaluate(() => {
    window.__mock.chats = [{ id: 'alpha' }, { id: 'beta' }]
    window.__mock.detail = { id: 'alpha', title: 'Archived chat', messages: [{ role: 'user', text: 'Archive contents' }] }
  })
  const archiveDownloadPromise = page.waitForEvent('download')
  await page.click('#export-all')
  const archiveDownload = await archiveDownloadPromise
  expect(archiveDownload.suggestedFilename()).toMatch(/\.zip$/)
  const archivePath = await archiveDownload.path()
  expect(execFileSync('unzip', ['-t', archivePath], { encoding: 'utf8' })).toContain('No errors detected')
  const archiveEntries = storedZipEntries(await fs.readFile(archivePath))
  expect(archiveEntries.get('chat-alpha.txt')).toContain('Archive contents')
  expect(archiveEntries.get('chat-beta.txt')).toContain('Archive contents')
  await page.selectOption('#export-format', 'pdf')
  await page.click('#export-all')
  await expect(page.locator('.err')).toContainText('Print to PDF is available for one chat at a time')
})

test('a failed chat request shows the server error instead of leaving the user without feedback', async ({ page }) => {
  await openChat(page, JSON.stringify({
    apiFailure: {
      method: 'POST',
      path: '/chat/stream',
      status: 503,
      data: { error: { code: 'busy', message: 'EVA is handling another request. Try again shortly.' } },
    },
  }))

  await page.fill('#q', 'Hello EVA')
  await page.click('#send')

  await expect(page.locator('.err')).toBeVisible()
  await expect(page.locator('.err')).toContainText('EVA is handling another request. Try again shortly.')
})

// A web answer the user cannot check is half an answer: the pages the tools
// retrieved have to be visible under it, labelled as web sources so they are
// never mistaken for the user's own files.
test('an answer shows its cited files and its web sources', async ({ page }) => {
  await openChat(page)
  const streamLines = [
    line({ type: 'content', delta: 'Laut [1] und [2] kostet es 5 Euro.' }),
    line({
      type: 'done',
      answer: 'Laut [1] und [2] kostet es 5 Euro.',
      sources: [
        { path: 'Budget.md', name: 'Budget.md', url: '/f/1', excerpts: ['Budget: 5 Euro'] },
        // An indexed Talk room has no page to open, so it must be named and not
        // linked - '#' would look clickable and only jump to the top of the page.
        { path: 'Talk: Projekt Alpha', name: 'Projekt Alpha', url: '', excerpts: ['Wir sollten das Budget erhoehen.'] },
        {
          path: 'Nextcloud Hub 26',
          name: 'Nextcloud Hub 26',
          url: 'https://nextcloud.com/hub26/',
          host: 'nextcloud.com',
          external: true,
          excerpts: ['Die neue Version ist verfuegbar.'],
        },
      ],
      followups: [],
    }),
  ]
  await page.evaluate((lines) => { window.__mock.streamLines = lines }, streamLines)

  await page.fill('#q', 'Was kostet es?')
  await page.click('#send')

  const summary = page.locator('.rs-sum')
  await expect(summary).toContainText('Sources')
  await summary.click()

  // The cited file keeps its number; the web page is listed after it.
  await expect(page.locator('.rs-item a').first()).toHaveText('[1] Budget.md')
  // The room source is shown by name, and it is not a link at all.
  const plain = page.locator('.rs-item .rs-plain')
  await expect(plain).toHaveText('[2] Talk: Projekt Alpha')
  await expect(page.locator('.rs-item a[href="#"]')).toHaveCount(0)
  const external = page.locator('.rs-item-external')
  await expect(external).toHaveCount(1)
  await expect(external.locator('a')).toHaveAttribute('href', 'https://nextcloud.com/hub26/')
  await expect(external.locator('a')).toHaveAttribute('target', '_blank')
  await expect(external.locator('.rs-badge')).toHaveText('Web')
  await expect(external.locator('.rs-host')).toHaveText('nextcloud.com')
  await expect(external.locator('.rs-excerpt')).toContainText('Die neue Version')
})

test('confirmation panel approves with a claim token and persists the resolved answer', async ({ page }) => {
  await openChat(page)
  const streamLines = [
    line({ type: 'content', delta: 'Checking…' }),
    line({ type: 'tool', name: 'create_share' }),
    line({ type: 'confirmation', name: 'create_share', arguments: { path: '/Notes' }, risk: 'mutating', missing: [] }),
  ]
  await page.evaluate((lines) => { window.__mock.streamLines = lines }, streamLines)

  await page.fill('#q', 'Create a share for my notes')
  await page.click('#send')

  const panel = page.locator('.rconfirm')
  await expect(panel).toBeVisible()
  await expect(panel.locator('.rconfirm-approve')).toHaveText('Confirm and run')

  // The placeholder (with its idempotency token) is persisted before the
  // action can run.
  const pendingPlaceholder = `(${messagePostsInPage}).filter((r) => r.body && r.body.role === 'assistant' && r.body.confirmation && !r.body.confirmation.resolved)`
  await expect.poll(() => page.evaluate(pendingPlaceholder)).toHaveLength(1)
  const token = await page.evaluate(`(${pendingPlaceholder})[0].body.confirmation.token`)
  expect(token).toBeTruthy()

  await page.click('.rconfirm-approve')

  // Approve carries the same token to the server.
  await expect.poll(() => page.evaluate(`window.__mock.saved.find((r) => r.url.includes('/confirmTool'))`)).toEqual(
    expect.objectContaining({
      body: expect.objectContaining({
        name: 'create_share',
        // The share form syncs its editable fields into the arguments.
        arguments: expect.objectContaining({ path: '/Notes' }),
        chatId: 'c1',
        confirmationToken: token,
      }),
    })
  )

  // The answer replaces the placeholder (same token, resolved, with result).
  const resolvedAnswer = `(${messagePostsInPage}).filter((r) => r.body && r.body.role === 'assistant' && r.body.confirmation && r.body.confirmation.resolved)`
  await expect.poll(() => page.evaluate(resolvedAnswer)).toHaveLength(1)
  const saved = await page.evaluate(`(${resolvedAnswer})[0].body`)
  expect(saved.text).toContain('Share created: https://cloud.example/s/abc')
  expect(saved.confirmation.token).toBe(token)
  await expect(page.locator('.rb').last()).toContainText('Share created: https://cloud.example/s/abc')
})

test('file change confirmation displays a text diff and submits its snapshot token', async ({ page }) => {
  await openChat(page)
  const preview = {
    path: 'Documents/settings.txt',
    action: 'update',
    added: 2,
    removed: 2,
    previewable: true,
    diff: '--- current\n+++ proposed\n@@ -1,4 +1,4 @@\n-old value\n+<script>new value</script>\n keep this\n-old ending\n+new ending',
    ends_with_newline: false,
    hunks: [
      { id: 0, diff: '@@ -1,1 +1,1 @@\n-old value\n+<script>new value</script>', added: 1, removed: 1, old_start: 0, old_count: 1, new_lines: ['<script>new value</script>'] },
      { id: 1, diff: '@@ -3,1 +3,1 @@\n-old ending\n+new ending', added: 1, removed: 1, old_start: 2, old_count: 1, new_lines: ['new ending'] },
    ],
  }
  const streamLines = [line({
    type: 'confirmation',
    name: 'create_file',
    arguments: {
      path: 'Documents/settings.txt',
      content: '<script>new value</script>',
      _eva_expected_sha256: 'snapshot-sha256',
      _eva_preview_path: 'Documents/settings.txt',
      _eva_preview: preview,
    },
    preview,
    risk: 'mutating',
    missing: [],
  })]
  await page.evaluate((lines) => { window.__mock.streamLines = lines }, streamLines)
  await page.fill('#q', 'Update settings')
  await page.click('#send')

  const panel = page.locator('.rconfirm')
  await expect(panel.locator('.rconfirm-diff').first()).toContainText('-old value')
  await expect(panel.locator('.rconfirm-diff').first()).toContainText('+<script>new value</script>')
  await expect(panel.locator('.rconfirm-diff script')).toHaveCount(0)
  await expect(panel.locator('.rconfirm-form')).toHaveCount(0)
  await expect(panel.locator('.rconfirm-hunk input')).toHaveCount(2)
  await panel.locator('.rconfirm-hunk input').nth(1).uncheck()
  await page.click('.rconfirm-approve')

  await expect.poll(() => page.evaluate(`window.__mock.saved.find((r) => r.url.includes('/confirmTool'))`)).toEqual(
    expect.objectContaining({
      body: expect.objectContaining({
        name: 'create_file',
        arguments: expect.objectContaining({
          _eva_expected_sha256: 'snapshot-sha256',
          _eva_preview_path: 'Documents/settings.txt',
          _eva_selected_hunks: [0],
        }),
      }),
    })
  )
})

test('a duplicate approve after reload is rejected and resolves the panel', async ({ page }) => {
  // Restored chat carrying an unresolved pending confirmation from a previous
  // run (exactly what chatDetail returns after a mid-confirmation reload).
  await openChat(page, JSON.stringify({
    chats: [{ id: 'c1', title: 'Shares', rev: 7 }],
    detail: {
      id: 'c1',
      title: 'Shares',
      rev: 7,
      messages: [
        { role: 'user', text: 'Create a share for my notes' },
        { role: 'assistant', text: 'Please review this action and confirm it explicitly.', confirmation: {
          name: 'create_share', arguments: { path: '/Notes' }, risk: 'mutating', missing: [], resolved: false, token: 'tok-restored',
        } },
      ],
    },
    confirmTool: {
      status: 409,
      data: { ok: false, alreadyProcessed: true, error: 'This action was already processed - reload the chat to see its result.' },
    },
  }))

  const panel = page.locator('.rconfirm')
  await expect(panel).toBeVisible()

  await page.click('.rconfirm-approve')

  // The 409 surfaces in the bubble and the panel resolves without running the
  // action a second time.
  await expect(page.locator('.rb').last()).toContainText('already processed')
  const resolvedAnswer = `(${messagePostsInPage}).filter((r) => r.body && r.body.role === 'assistant' && r.body.confirmation && r.body.confirmation.resolved)`
  await expect.poll(() => page.evaluate(resolvedAnswer)).toHaveLength(1)
  const saved = await page.evaluate(`(${resolvedAnswer})[0].body`)
  expect(saved.confirmation.token).toBe('tok-restored')
  expect(saved.confirmation.resolved).toBe(true)
})

test('the stop button aborts the stream and persists the partial answer', async ({ page }) => {
  await openChat(page)
  await page.evaluate(() => {
    window.__mock.streamMode = 'open'
  })

  await page.fill('#q', 'Write a long story')
  await page.click('#send')

  // Wait until the stream request reached the mock, then send one delta while
  // the stream stays open.
  await expect.poll(() => page.evaluate(() => !!window.__mock.streamController)).toBe(true)
  const delta = new TextEncoder().encode(line({ type: 'content', delta: 'Once upon a time' }) + '\n')
  await page.evaluate((bytes) => window.__mock.streamController.enqueue(bytes), delta)
  await expect(page.locator('.rb').last()).toContainText('Once upon a time')

  // The send button turns into Stop while streaming.
  const sendBtn = page.locator('#send')
  await expect(sendBtn).toHaveText('Stop')

  await sendBtn.click()

  // The in-flight fetch was aborted and the partial answer persisted.
  await expect.poll(() => page.evaluate(() => window.__mock.aborted)).toBe(1)
  const assistantPosts = `(${messagePostsInPage}).filter((r) => r.body && r.body.role === 'assistant')`
  await expect.poll(() => page.evaluate(assistantPosts)).toHaveLength(1)
  const saved = await page.evaluate(`(${assistantPosts})[0].body`)
  expect(saved.text).toContain('Once upon a time')

  // The send button returns to Send for the next question.
  await expect(sendBtn).toHaveText('Send')
})
