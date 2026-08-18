<template>
	<div ref="root" class="chatview-root"></div>
</template>

<script>
import { ref, onMounted, watch } from 'vue'
import { mountChat } from '../lib/vanilla'

export default {
	name: 'ChatView',
	props: {
		chatId: { type: String, default: null },
		initialPrompt: { type: String, default: '' },
	},
	emits: ['chat-updated'],
	setup(props, { emit }) {
		const root = ref(null)
		let mounted = false

		const mount = () => {
			if (!root.value) return
			root.value.innerHTML = ''
			delete root.value.__evaAi
			mountChat(root.value, {
				chatId: props.chatId || null,
				onRecent: () => emit('chat-updated'),
			})
			mounted = true
		}

		onMounted(mount)
		watch(() => props.initialPrompt, (v) => {
			if (!mounted || !v) return
			const input = root.value && root.value.querySelector('#chatinput')
			if (input) {
				input.value = v
				input.focus()
			}
		})
		watch(() => props.chatId, () => {
			if (mounted) mount()
		})

		return { root }
	},
}
</script>

<style>
/* App.vue provides a responsive shared content width for all workspace views. */
.chatview-root {
	width: 100%;
	height: 100%;
	min-height: 0;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	padding: 0 clamp(16px, 3vw, 44px);
	overflow: hidden;
	background: var(--color-main-background, #fff);
}

.chatview-root .head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex: 0 0 auto;
	width: min(100%, var(--eva-content-width, 1180px));
	min-height: 52px;
	margin: 0 auto;
	padding: 8px 0 9px;
	border-bottom: 1px solid var(--color-border, #ddd);
	box-sizing: border-box;
}

.chatview-root .head h1 {
	margin: 0;
	font-size: 18px;
	font-weight: 650;
	letter-spacing: -.01em;
	color: var(--color-main-text, #222);
}

.chatview-root .head-right {
	display: flex;
	align-items: center;
	gap: 8px;
}





.chatview-root .pill {
	padding: 2px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	background: transparent;
	color: var(--color-text-maxcontrast, #555);
}

.chatview-root .pill-ok { border-color: var(--color-success, #2fb344); color: var(--color-success, #2fb344); }
.chatview-root .pill-bad { border-color: var(--color-error, #e9322d); color: var(--color-error, #e9322d); }
.chatview-root .pill-warn { border-color: var(--color-warning, #f0a64a); color: var(--color-warning, #a66a00); }

.chatview-root .chat-log {
	flex: 1;
	min-height: 180px;
	width: min(100%, var(--eva-content-width, 1180px));
	margin: 0 auto;
	padding: 24px 0 20px;
	box-sizing: border-box;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 20px;
	background: transparent;
}

.chatview-root .empty {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	text-align: center;
	gap: 5px;
	padding: 24px 12px;
	color: var(--color-text-maxcontrast, #555);
}

.chatview-root .empty .ico { font-size: 30px; opacity: .72; }
.chatview-root .empty .t { font-size: 16px; font-weight: 650; color: var(--color-main-text, #222); }
.chatview-root .empty .d { max-width: 440px; font-size: 13px; line-height: 1.5; }
.chatview-root .rm { display: flex; flex-direction: column; align-items: flex-start; width: 100%; }
.chatview-root .rm.user { align-items: flex-end; }
.chatview-root .rb {
	position: relative;
	max-width: min(88%, 1200px);
	padding: 0;
	border: 0;
	border-radius: 0;
	line-height: 1.55;
	font-size: 14px;
	word-break: break-word;
}

.chatview-root .rm.user .rb {
	padding: 9px 13px;
	border-radius: 14px 14px 4px 14px;
	background: color-mix(in srgb, var(--color-primary-element, #00679c) 12%, transparent) !important;
	color: var(--color-main-text, #222) !important;
}

.chatview-root .rm.assistant .rb {
	padding-right: 34px;
	background: transparent !important;
	color: var(--color-main-text, #222) !important;
}

.chatview-root .rt { white-space: normal; font-size: 14px; line-height: 1.6; color: inherit; text-align: left; }
.chatview-root .rt p { margin: 0 0 9px; }
.chatview-root .rt p:last-child { margin-bottom: 0; }
.chatview-root .rt ul, .chatview-root .rt ol { margin: 0 0 9px 22px; padding: 0; }
.chatview-root .rt ul { list-style: disc; }
.chatview-root .rt ol { list-style: decimal; }
.chatview-root .rt li { margin: 3px 0; }
.chatview-root .rt h1, .chatview-root .rt h2, .chatview-root .rt h3,
.chatview-root .rt h4, .chatview-root .rt h5, .chatview-root .rt h6 {
	margin: 12px 0 6px;
	font-weight: 650;
	line-height: 1.3;
}
.chatview-root .rt h1 { font-size: 18px; }
.chatview-root .rt h2 { font-size: 16px; }
.chatview-root .rt h3 { font-size: 15px; }
.chatview-root .rt h4, .chatview-root .rt h5, .chatview-root .rt h6 { font-size: 14px; }
.chatview-root .rt p code, .chatview-root .rt li code {
	font-family: var(--font-family-monospace, monospace);
	font-size: 85%;
	background: var(--color-background-hover, #eee);
	padding: 1px 5px;
	border-radius: 4px;
}
.chatview-root .rt pre {
	margin: 0 0 9px;
	padding: 11px 13px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 6px;
	background: var(--color-background-hover, #f3f3f3);
	overflow-x: auto;
}
.chatview-root .rt pre code {
	font-family: var(--font-family-monospace, monospace);
	font-size: 13px;
	background: transparent;
	padding: 0;
	white-space: pre-wrap;
}
.chatview-root .rt blockquote { margin: 7px 0; padding: 4px 12px; border-left: 3px solid var(--color-border, #ccc); color: var(--color-text-maxcontrast, #555); }
.chatview-root .rt a { color: var(--color-primary-element, #00679c); text-decoration: underline; }
.chatview-root .rt hr { border: 0; border-top: 1px solid var(--color-border, #ddd); margin: 12px 0; }
.chatview-root .rm.assistant { position: relative; }

.chatview-root .rcopy {
	position: absolute;
	top: -2px;
	right: 0;
	width: 24px;
	height: 24px;
	padding: 0;
	border: 0;
	border-radius: 5px;
	background: transparent;
	color: var(--color-text-maxcontrast, #888);
	font-size: 13px;
	line-height: 1;
	cursor: pointer;
	opacity: 0;
	transition: opacity .12s, background-color .12s;
}
.chatview-root .rm.assistant:hover .rcopy,
.chatview-root .rcopy:focus-visible { opacity: 1; }
.chatview-root .rcopy:hover { background: var(--color-background-hover, #e5e5e5); }


.chatview-root .rth { margin: 0 0 9px; font-size: 12px; }
.chatview-root .rth summary { cursor: pointer; color: var(--color-text-maxcontrast, #555); font-weight: 600; user-select: none; }
.chatview-root .rth-c { margin-top: 6px; padding: 8px 10px; border-left: 2px solid var(--color-border, #ccc); background: transparent; white-space: pre-wrap; word-break: break-word; color: var(--color-text-maxcontrast, #555); font-size: 12px; line-height: 1.5; max-height: 220px; overflow-y: auto; }
.chatview-root .rs { margin-top: 8px; padding: 6px 0 0 10px; border-left: 2px solid var(--color-border, #ccc); font-size: 12px; color: var(--color-text-maxcontrast, #555); }
.chatview-root .rs .lab { margin-bottom: 2px; font-weight: 600; }
.chatview-root .rs a { display: block; color: var(--color-primary-element, #00679c); text-decoration: none; margin: 2px 0; }
.chatview-root .rconfirm { margin-top: 10px; max-width: min(100%, 560px); padding: 12px; border: 1px solid var(--color-border, #ccd0d4); border-left: 3px solid var(--color-warning, #eab308); border-radius: 8px; background: var(--color-background-hover, #f6f7f8); }
.chatview-root .rconfirm-label { font-size: 13px; font-weight: 650; color: var(--color-main-text, #222); }
.chatview-root .rconfirm-args { max-height: 150px; margin: 8px 0; padding: 8px; overflow: auto; white-space: pre-wrap; word-break: break-word; font: 12px/1.45 var(--font-family-monospace, monospace); color: var(--color-text-maxcontrast, #555); background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #ddd); border-radius: 5px; }
.chatview-root .rconfirm-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.chatview-root .rconfirm-actions button { min-height: var(--default-clickable-area, 34px); padding: 6px 12px; border: 1px solid var(--color-border, #ccd0d4); border-radius: var(--border-radius-element, 6px); cursor: pointer; font: inherit; font-size: 13px; }
.chatview-root .rconfirm-approve { color: var(--color-primary-element-text, #fff); background: var(--color-primary-element, #00679c); border-color: var(--color-primary-element, #00679c) !important; }
.chatview-root .rconfirm-reject { color: var(--color-main-text, #222); background: var(--color-main-background, #fff); }
.chatview-root .rconfirm-actions button:disabled { opacity: .6; cursor: default; }
.chatview-root .rtools { display: flex; flex-direction: column; gap: 4px; max-width: 86%; margin-top: 8px; }
.chatview-root .rtools .tool { padding: 3px 0; font-size: 12px; color: var(--color-text-maxcontrast, #555); font-family: var(--font-family-monospace, monospace); }
.chatview-root .rtools .tool.running { color: #8a6d1a; }
.chatview-root .rtools .tool.ok { color: #2f8f3f; }
.chatview-root .rtools .tool.bad { color: var(--color-error, #e9322d); }

.chatview-root .chatform {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 0 0 auto;
	width: min(100%, var(--eva-content-width, 1180px));
	margin: 0 auto;
	padding: 12px 0 16px;
	border-top: 1px solid var(--color-border, #ddd);
	box-sizing: border-box;
	background: transparent;
}
.chatview-root .chatform input {
	flex: 1;
	min-width: 0;
	padding: 10px 12px;
	border: 1px solid var(--color-border, #bbb);
	border-radius: 8px;
	font-size: 14px;
	color: var(--color-main-text, #111);
	background: var(--color-main-background, #fff);
}
.chatview-root .chatform input:focus {
	border-color: var(--color-primary-element, #00679c);
	outline: 2px solid color-mix(in srgb, var(--color-primary-element, #00679c) 22%, transparent);
	outline-offset: 0;
}
.chatview-root .cbtn {
	padding: 10px 16px;
	border: 0;
	border-radius: 8px;
	background: var(--color-primary-element, #00679c);
	color: var(--color-primary-element-text, #fff);
	font-size: 14px;
	font-weight: 650;
	cursor: pointer;
}
.chatview-root .cbtn:hover:not(:disabled) { filter: brightness(.95); }
.chatview-root .cbtn:disabled { opacity: .6; cursor: default; }
.chatview-root .err { margin: 0 0 8px; color: var(--color-error, #e9322d); font-size: 13px; white-space: pre-wrap; }

@media (min-width: 1400px) {
	.chatview-root { padding-inline: clamp(28px, 7vw, 120px); }
	.chatview-root .chat-log { padding-top: 30px; padding-bottom: 24px; }
	.chatview-root .rt { font-size: 15px; line-height: 1.65; }
}

@media (max-width: 600px) {
	.chatview-root { padding-inline: 12px; }
	.chatview-root .head { min-height: 48px; }
	.chatview-root .head h1 { font-size: 16px; }
	.chatview-root .head-right { gap: 4px; }
	.chatview-root .chat-log { padding-top: 18px; gap: 16px; }
	.chatview-root .rb { max-width: 94%; }
	.chatview-root .rm.assistant .rb { padding-right: 28px; }
	.chatview-root .cbtn { padding-inline: 13px; }
}
.chatview-root .head .export { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: var(--default-clickable-area, 34px); padding: 6px 12px; border: 1px solid var(--color-border, #ccd0d4); border-radius: var(--border-radius-element, 8px); background: var(--color-main-background, #fff); color: var(--color-main-text, #222); cursor: pointer; font: inherit; font-size: 13px; font-weight: 500; line-height: 1.2; transition: background-color var(--animation-quick, .2s), border-color var(--animation-quick, .2s), color var(--animation-quick, .2s); }
.chatview-root .head .export:hover:not(:disabled) { border-color: var(--color-border-dark, #b5b9bd); background: var(--color-background-hover, #f1f2f4); }
.chatview-root .head .export:focus-visible { outline: 2px solid var(--color-main-text, #222); outline-offset: 2px; }
.chatview-root .head .export:disabled { opacity: .5; cursor: default; }
.chatview-root .head .export-icon { width: 16px; height: 16px; fill: currentColor; }
</style>