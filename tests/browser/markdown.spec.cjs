const { test, expect } = require('@playwright/test')
const fs = require('node:fs')
const path = require('node:path')
const root = path.resolve(__dirname, '../..')
const source = fs.readFileSync(path.join(root, 'src/lib/chat-utils.js'), 'utf8').replace(/^import MarkdownIt.*$/m, '').replace(/^export /gm, '')
const sharedCss = fs.readFileSync(path.join(root, 'src/lib/markdown.css'), 'utf8')
const sample = '## Markdown\n\n**Fett und *kursiv***, __auch fett__, ~~gestrichen~~.\n\n| Name | Wert |\n| --- | ---: |\n| Eintrag | **42** |\n\n3. Hauptpunkt\n   - Unterpunkt\n\n> Zitat\n> Zweite Zeile\n\n~~~js\nconst html = "<b>literal</b>";\n~~~\n\n[Dokumentation](https://example.com/a_(b))\n\n![Kein Tracking](https://example.com/pixel.png)\n\n<script>window.markdownXss = true</script>'
for (const surface of ['vue', 'standalone']) {
  for (const width of [375, 1280]) {
    test(`${surface} markdown at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 })
      const component = fs.readFileSync(path.join(root, surface === 'vue' ? 'src/views/ChatView.vue' : 'templates/standalone.php'), 'utf8')
      const styles = [...component.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n')
      await page.setContent('<main class="chatview-root"><article class="rt"></article></main>')
      await page.addStyleTag({ content: styles + '\n' + sharedCss + '\nmain { max-width:100%; } body { margin:16px; }' })
      await page.addScriptTag({ path: require.resolve('markdown-it/browser') })
      await page.addScriptTag({ content: 'const MarkdownIt = window.markdownit;\n' + source })
      const remoteRequests = []
      page.on('request', request => remoteRequests.push(request.url()))
      await page.evaluate(text => { document.querySelector('.rt').innerHTML = mdToHtml(text) }, sample)
      await expect(page.locator('.rt h2')).toHaveText('Markdown')
      await expect(page.locator('.rt strong em')).toHaveText('kursiv')
      await expect(page.locator('.rt th').last()).toHaveCSS('text-align', 'right')
      await expect(page.locator('.rt ol')).toHaveAttribute('start', '3')
      await expect(page.locator('.rt ol li ul li')).toHaveText('Unterpunkt')
      await expect(page.locator('.rt pre code')).toHaveText('const html = "<b>literal</b>";\n')
      await expect(page.locator('.rt pre code')).toHaveCSS('white-space', 'pre')
      // Raw HTML stays inert: no script element, and nothing executed.
      await expect(page.locator('.rt script')).toHaveCount(0)
      expect(await page.evaluate(() => window.markdownXss)).toBeUndefined()
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)

      // A picture from a web search really is displayed, wrapped in a link to
      // the original, and carries the two guards that make a remote image safe:
      // no referrer, and no load until the user scrolls to it.
      const picture = page.locator('.rt a.md-image-link img.md-image')
      await expect(picture).toHaveCount(1)
      await expect(picture).toHaveAttribute('src', 'https://example.com/pixel.png')
      await expect(picture).toHaveAttribute('alt', 'Kein Tracking')
      await expect(picture).toHaveAttribute('loading', 'lazy')
      await expect(picture).toHaveAttribute('referrerpolicy', 'no-referrer')
      await expect(page.locator('.rt a.md-image-link')).toHaveAttribute('href', 'https://example.com/pixel.png')
      // Bounded so a large figure cannot overflow the chat column.
      expect(await picture.evaluate(el => el.getBoundingClientRect().width <= window.innerWidth)).toBe(true)

      // A picture with an unsafe source degrades to text instead of loading.
      for (const unsafe of ['![x](javascript:alert(1))', '![x](data:image/gif;base64,R0lGOD)']) {
        await page.evaluate(text => { document.querySelector('.rt').innerHTML = mdToHtml(text) }, unsafe)
        await expect(page.locator('.rt img')).toHaveCount(0)
        await expect(page.locator('.rt')).toContainText('x')
      }
      expect(remoteRequests.filter(url => url.startsWith('data:') || url.startsWith('javascript:'))).toEqual([])

      // Growing a streamed code fence must never turn its contents into HTML.
      for (const part of ['```html\n<img', '```html\n<img src=x>\n```\n**Fertig**']) {
        await page.evaluate(text => { document.querySelector('.rt').innerHTML = mdToHtml(text) }, part)
        await expect(page.locator('.rt img')).toHaveCount(0)
      }
      await expect(page.locator('.rt > p strong')).toHaveText('Fertig')
    })
  }
}
