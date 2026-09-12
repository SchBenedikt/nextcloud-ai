# Reading pages in a real browser

A plain HTTP request gets the bytes a server sends. It does not run the page's
scripts, and a growing share of the web only has its text after they have run:
client-rendered sites, news portals that fetch the article body, documentation
with a client-side shell, and anything behind a consent wall. From such a page a
static fetch gets an empty frame, and an answer built on it is a guess dressed up
as a quotation.

With browser rendering enabled, a result page whose fetched text comes back thin
is loaded in Chromium through `bin/render-page.mjs` and the finished DOM is run
through exactly the same extraction as any other page.

Three pieces have to be on the server. The settings page checks all three and
names the one that is missing, because "switched on" and "actually working" are
not the same thing:

| Piece | Where it comes from |
| --- | --- |
| Node.js | The server's package manager, or a manual install |
| The `playwright` npm package | Ships with the app's `node_modules` |
| A Chromium build | Downloaded by Playwright, **separately from the package** |

## Install

The browser download is the step that is missed, because `npm install playwright`
does not perform it. Run this as the user the web server runs as — usually
`www-data` — so the build lands in a directory that user can read:

```bash
cd /var/www/html/nextcloud/apps/eva_ai
sudo -u www-data env PLAYWRIGHT_BROWSERS_PATH=/var/www/.cache/ms-playwright \
  npx playwright install chromium
```

Notes that save time:

- Omitting `PLAYWRIGHT_BROWSERS_PATH` installs into the home directory of the
  user you ran it as. Installing as `root` therefore puts the browser where
  `www-data` can never read it, and the settings page keeps reporting a missing
  browser. Use the variable, or run the command as the web server user.
- `--with-deps` (Debian/Ubuntu) also installs the shared libraries Chromium needs.
  It requires root and package-manager access. If the browser is found but pages
  fail to load, the shared libraries are the first thing to check.
- The download is roughly 170 MB unpacked, plus the same again for the headless
  shell Playwright uses for a headless launch.
- Upgrading the `playwright` package changes the revision it looks for. After an
  app upgrade that changes Playwright, run the install command again.

If the browser lives somewhere else (a shared directory, a container path, a
different account's home), point the app at it instead:

```bash
sudo -u www-data php /var/www/html/nextcloud/occ config:app:set eva_ai \
  web_search_browser_browsers_path --value=/var/www/.cache/ms-playwright
```

Leaving that setting empty uses Playwright's own location for the web server
account, which is the same directory the command above installs into.

## Enable

```bash
sudo -u www-data php /var/www/html/nextcloud/occ config:app:set eva_ai \
  web_search_browser --value=1
```

Or use the switch on the Eva AI admin settings page, which reports the same
diagnosis in the "Read pages that need JavaScript in a real browser" section.

## Verify

The server-side diagnosis, the paths it found and why, without touching the
network:

```bash
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:browser
```

It exits non-zero when rendering is not usable, so it can be used as a
deployment check. Its output looks like this:

```
Browser rendering
  Status:          ready
  Node.js:         /usr/bin/node
  Renderer script: /var/www/html/nextcloud/apps/eva_ai/bin/render-page.mjs (found)
  Browsers path:   /var/www/.cache/ms-playwright
  Chromium:        /var/www/.cache/ms-playwright/chromium-1243/chrome-linux-arm64/chrome
  Page timeout:    20 s
```

Render real pages through the same component the search uses:

```bash
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:browser https://example.org/
```

For a page whose text only exists after JavaScript has run, the DOM character
count is the proof: a static fetch sees a few hundred bytes of shell, the browser
sees the article. A page that cannot be loaded is reported as `FAILED` and the
command exits non-zero.

Then run a real search end to end as a user:

```bash
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:tool <user> web_search \
  '{"query":"your query","mode":"web"}'
```

The result carries the ranked hits with their readable text, so a result that was
read in the browser is one with a large amount of text. The admin settings page
goes one step further and states how many pages of the last test search needed the
browser, which is the only way to tell "switched on" from "actually used".

## Troubleshooting

| What the settings page says | What to do |
| --- | --- |
| `Browser rendering is switched off.` | Turn the switch on, or set `web_search_browser` to `1`. |
| `The renderer script bin/render-page.mjs is missing` | The app directory is incomplete; reinstall or re-sync the app. |
| `The configured Node.js path is not an executable file: …` | The path in `web_search_browser_node` is wrong. Clear it to use `PATH`. |
| `Node.js was not found.` | Install Node.js, or set an absolute path in `web_search_browser_node`. |
| `The Playwright package is missing.` | Run `npm install` in the app directory (`npm ci --omit=dev` for a production install). |
| `…its Chromium browser is not, so nothing can be rendered.` | Run the install command above as the web server user, with `PLAYWRIGHT_BROWSERS_PATH` set. |

Further things worth knowing when it still misbehaves:

- **A page renders in the terminal but not from the web request.** The web server
  process has a different environment than your shell (often no `HOME`). The app
  pins `PLAYWRIGHT_BROWSERS_PATH` for the renderer, so the path it checked is the
  path it uses — set `web_search_browser_browsers_path` explicitly if the browser
  lives outside the web server account's home.
- **The browser is found, but every page fails.** Check the Chromium shared
  libraries (run the install with `--with-deps`), and the Nextcloud log: the app
  logs the renderer's error under `eva_ai: browser render failed`.
- **A single page is slow.** Rendering costs seconds, which is why only pages
  whose statically fetched text is thin are rendered, and at most four per search,
  in one browser process. `web_search_browser_timeout` (3–60 s, default 20) bounds
  a single page; the batch is killed at its own deadline either way, so a runaway
  page can never hold a PHP worker open.
