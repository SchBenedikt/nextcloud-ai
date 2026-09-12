// Issue #133: exercise the standalone chat in a real browser against a mocked
// API, so the send → stream → tool → confirmation → approve → persist flow and
// the stop button are covered without a Nextcloud or Ollama instance.
const { test, expect } = require('@playwright/test')
const { openChat, line } = require('./standalone-harness.cjs')

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