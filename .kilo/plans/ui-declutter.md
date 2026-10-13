# UI declutter — chat composer + sidebar (READY TO APPLY)

Context: user reports the app feels overloaded / "AI-generated"; the bottom
text input (composer) and the sidebar are too busy. Goal: less visible noise,
keep all functionality, keep every test selector intact so CI stays green.

Blocker encountered: during goal execution the Edit tool was restricted to
plan markdown only, so these changes could NOT be written to source this run.
Apply them once source-write is available, then `npm run build`, `node --test`,
open a PR, merge when CI (browser tests) is green.

## 1) Composer declutter — `src/lib/vanilla.js`
The "Run in background" button is a niche text button that is always visible.
Hide it until the input has a prompt. Do NOT remove `queueInBackground`
(`tests/FrontendContractTest.php:229` asserts the strings `queueInBackground`
and `'/backgroundChat'` must stay in `src/lib/vanilla.js`).

After the line:
```
	form.append(filesBtn, imageBtn, imageInput, micBtn, modelSelect, input, cancelVoiceBtn, sendBtn, backgroundBtn)
```
add:
```
	// Keep the composer minimal: the niche "Run in background" action only
	// appears once there is a prompt to queue. The feature (queueInBackground)
	// is unchanged.
	const toggleBackgroundBtn = () => { backgroundBtn.hidden = !input.value.trim() }
	input.addEventListener('input', toggleBackgroundBtn)
	toggleBackgroundBtn()
```

## 2) Composer calm — `src/views/ChatView.vue` (append inside `<style>`)
Quiet the secondary icon buttons and tighten the row (appearance only;
`.cbtn-files`/`.cbtn-images`/`.cbtn-mic`/`.chat-model-select` selectors stay):
```
/* Composer declutter: quieter secondary actions, tighter row. */
.chatview-root .chatform { gap: 6px; padding: 8px 0 12px; }
.chatview-root .chatform .cbtn-ghost { border-color: transparent; background: transparent; min-width: 38px; padding: 0 10px; }
.chatview-root .chatform .cbtn-ghost:hover:not(:disabled) { background: var(--color-background-hover, #eee); filter: none; }
.chatview-root .chat-model-select { max-width: 150px; min-height: 38px; padding: 6px 9px; }
.chatview-root .chatform .cbtn:not(.cbtn-ghost) { padding: 10px 14px; }
```

## 3) Sidebar calm — `src/App.vue` (separate, larger PR)
Reduce visual density only:
- Tighter list row spacing and smaller, quieter pills/counters.
- Group secondary entries (Archived, Agent runs) into a calmer overflow so the
  primary set is just Home / Chat / Documents / Metrics / Settings.
- Keep all nav item click handlers and DOM selectors unchanged (browser tests +
  `FrontendContractTest` cover App.vue).

## 4) Suggested extra (permission allows editing docs/config only right now)
Dependabot is likely re-introducing the pinned `@nextcloud/vue 3.5.48` because
`package.json` pins it exactly; consider relaxing to `^3.5.48` to reduce PR
noise (optional, verify `npm ci` + bundle rebuild).

## Verify before merge
- `npm run build` (bundle matches on CI), `node --test` (43 pass / 4 Chromium-skip).
- CI browser tests (composer selectors `#chatinput`, `.chatform`,
  `.chat-model-select`, image generator, export) — all preserved by the above.
