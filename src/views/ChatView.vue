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
.chatview-root {
	width: 100%;
	max-width: none;
	height: 100%;
	margin: 0 auto;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	padding: 24px clamp(16px, 3vw, 36px) 28px;
	overflow: hidden;
}

.chatview-root .head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	width: min(100%, 1540px);
	margin: 0 auto 20px;
	padding: 16px 20px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 16px;
	background: var(--color-main-background, #fff);
	box-shadow: 0 8px 24px color-mix(in srgb, var(--color-main-text) 8%, transparent);
	box-sizing: border-box;
}

.chatview-root .head h1 {
	margin: 0;
	font-size: clamp(22px, 3vw, 28px);
	font-weight: 700;
	letter-spacing: -.02em;
}

.chatview-root .head-right {
	display: flex;
	align-items: center;
	gap: 8px;
}

.chatview-root .refresh {
	border: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
	border-radius: 8px;
	padding: 7px 11px;
	cursor: pointer;
	font-size: 13px;
	line-height: 1.2;
	color: var(--color-main-text, #111);
	transition: background-color .15s ease, border-color .15s ease;
}

.chatview-root .refresh:hover:not(:disabled) {
	background: var(--color-background-hover, #f1f2f4);
	border-color: var(--color-primary-element, #00679c);
}

.chatview-root .pill {
	padding: 3px 12px;
	border-radius: 20px;
	font-size: 12px;
	font-weight: 600;
	background: var(--color-background-hover, #e5e5e5);
	color: var(--color-main-text, #333);
}

.chatview-root .pill-ok {
	background: var(--color-success, #2fb344);
	color: var(--color-primary-element-text, #fff);
}

.chatview-root .pill-bad {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-element-text, #fff);
}

.chatview-root .pill-warn {
	background: var(--color-warning, #f0a64a);
	color: #111;
}

.chatview-root .chat-log {
	flex: 1;
	min-height: 280px;
	background: var(--color-background-dark, var(--color-main-background, #fff));
	border: 1px solid var(--color-border, #ddd);
	border-radius: 14px;
	padding: clamp(14px, 2vw, 22px);
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 16px;
	width: min(100%, 1540px);
	margin: 0 auto 16px;
	box-shadow: 0 10px 28px color-mix(in srgb, var(--color-main-text) 7%, transparent);
	box-sizing: border-box;
}

.chatview-root .empty {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	text-align: center;
	gap: 6px;
	padding: 24px;
}

.chatview-root .empty .ico { font-size: 42px; filter: saturate(.8); }
.chatview-root .empty .t { font-size: 17px; font-weight: 700; color: var(--color-main-text, #222); }
.chatview-root .empty .d { font-size: 13px; color: var(--color-text-maxcontrast, #444); max-width: 480px; }
.chatview-root .rm { display: flex; flex-direction: column; align-items: flex-start; width: 100%; }
.chatview-root .rm.user { align-items: flex-end; }
.chatview-root .rb {
	position: relative;
	max-width: min(82%, 1120px);
	padding: 12px 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 14px;
	line-height: 1.5;
	font-size: 14px;
	word-break: break-word;
	transition: border-color .15s ease;
}
.chatview-root .rm.user .rb { border-color: color-mix(in srgb, var(--color-primary-element) 45%, transparent); }
.chatview-root .rm.assistant .rb { background: var(--color-main-background, #fff) !important; }
.chatview-root .rm.user .rb { border-bottom-right-radius: 4px; }
.chatview-root .rm.assistant .rb { border-bottom-left-radius: 4px; }
.chatview-root .rt { white-space: normal; font-size: 14px; line-height: 1.55; color: inherit; text-align: left; }

/* Markdown-Formatierung der Antwort */
.chatview-root .rt p { margin: 0 0 8px; }
.chatview-root .rt p:last-child { margin-bottom: 0; }
.chatview-root .rt ul, .chatview-root .rt ol { margin: 0 0 8px 22px; padding: 0; }
.chatview-root .rt ul { list-style: disc; }
.chatview-root .rt ol { list-style: decimal; }
.chatview-root .rt li { margin: 2px 0; }
.chatview-root .rt h1, .chatview-root .rt h2, .chatview-root .rt h3,
.chatview-root .rt h4, .chatview-root .rt h5, .chatview-root .rt h6 {
	margin: 10px 0 6px;
	font-weight: 600;
	line-height: 1.3;
}
.chatview-root .rt h1 { font-size: 17px; }
.chatview-root .rt h2 { font-size: 16px; }
.chatview-root .rt h3 { font-size: 15px; }
.chatview-root .rt h4, .chatview-root .rt h5, .chatview-root .rt h6 { font-size: 14px; }
.chatview-root .rt p code, .chatview-root .rt li code { font-family: var(--font-family-monospace, monospace); font-size: 85%; background: var(--color-background-dark, #eee); padding: 1px 5px; border-radius: 4px; }
.chatview-root .rt pre { background: var(--color-background-dark, #f0f0f0); padding: 10px 12px; border-radius: 8px; overflow-x: auto; margin: 0 0 8px; }
.chatview-root .rt pre code { font-family: var(--font-family-monospace, monospace); font-size: 13px; background: transparent; padding: 0; white-space: pre-wrap; }
.chatview-root .rt blockquote { margin: 6px 0; padding: 4px 12px; border-left: 3px solid var(--color-border, #ccc); color: var(--color-text-maxcontrast, #555); }
.chatview-root .rt a { color: var(--color-primary-element, #00679c); text-decoration: underline; }
.chatview-root .rt hr { border: none; border-top: 1px solid var(--color-border, #ddd); margin: 10px 0; }
.chatview-root .rm.assistant { position: relative; }
.chatview-root .rcopy {
	position: absolute;
	top: 8px;
	right: 8px;
	width: 24px;
	height: 24px;
	line-height: 1;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast, #888);
	border-radius: 6px;
	font-size: 13px;
	cursor: pointer;
	padding: 0;
	opacity: 0;
	transition: opacity .12s;
}

.chatview-root .rm.assistant:hover .rcopy { opacity: 1; }
.chatview-root .rcopy:hover { background: var(--color-background-hover, #e5e5e5); }

.chatview-root .head .export:disabled {
	opacity: .5;
	cursor: default;
}

.chatview-root .rth { margin-bottom: 8px; font-size: 12px; }
.chatview-root .rth summary { cursor: pointer; color: var(--color-text-maxcontrast, #555); font-weight: 600; user-select: none; }
.chatview-root .rth-c { margin-top: 6px; padding: 8px 10px; background: var(--color-background-dark, #eef1f4); border-radius: 6px; white-space: pre-wrap; word-break: break-word; color: var(--color-text-maxcontrast, #555); font-size: 12px; line-height: 1.5; max-height: 220px; overflow-y: auto; }
.chatview-root .rs { margin-top: 6px; padding: 6px 10px; background: var(--color-background-dark, #f3f3f3); border-radius: 6px; font-size: 12px; color: var(--color-main-text, #333); }
        .chatview-root .rtools { margin-top: 6px; display: flex; flex-direction: column; gap: 4px; max-width: 86%; }
        .chatview-root .rtools .tool { font-size: 12px; padding: 4px 10px; border-radius: 6px; background: var(--color-background-dark, #eef1f4); color: var(--color-text-maxcontrast, #555); font-family: var(--font-family-monospace, monospace); }
        .chatview-root .rtools .tool.running { color: #8a6d1a; }
        .chatview-root .rtools .tool.ok { color: #2f8f3f; }
        .chatview-root .rtools .tool.bad { color: var(--color-error, #e9322d); }
.chatview-root .rs .lab { color: var(--color-text-maxcontrast, #555); margin-bottom: 2px; }
.chatview-root .rs a { display: block; color: var(--color-primary-element, #00679c); text-decoration: none; margin: 2px 0; }

.chatview-root .chatform {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 12px;
	width: min(100%, 1540px);
	margin: 0 auto;
	box-sizing: border-box;
	box-shadow: 0 8px 24px color-mix(in srgb, var(--color-main-text) 7%, transparent);
}

.chatview-root .chatform input {
	flex: 1;
	min-width: 0;
	padding: 10px 12px;
	border: 1px solid transparent;
	border-radius: 8px;
	font-size: 14px;
	color: var(--color-main-text, #111);
	background: transparent;
}

.chatview-root .chatform input:focus {
	border-color: var(--color-primary-element, #00679c);
	outline: none;
	background: var(--color-background-hover, #f1f2f4);
}

.chatview-root .cbtn {
	padding: 10px 18px;
	border: 0;
	border-radius: 8px;
	background: var(--color-primary-element, #00679c);
	color: var(--color-primary-element-text, #fff);
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
}

.chatview-root .cbtn:disabled {
	opacity: 0.6;
	cursor: default;
}

.chatview-root .err {
	margin-left: 4px;
	color: var(--color-error, #e9322d);
	font-size: 13px;
	margin-top: 8px;
	white-space: pre-wrap;
}


@media (min-width: 1400px) {
	.chatview-root { padding: 32px clamp(28px, 4vw, 72px) 40px; }
	.chatview-root .head { padding: 18px 24px; }
	.chatview-root .chat-log { padding: 26px clamp(24px, 2.5vw, 42px); gap: 20px; }
	.chatview-root .rb { max-width: min(78%, 1160px); padding: 14px 18px; font-size: 15px; }
	.chatview-root .rt { font-size: 15px; line-height: 1.62; }
	.chatview-root .chatform { padding: 10px; }
}

@media (max-width: 600px) {
	.chatview-root { padding: 18px 12px 20px; }
	.chatview-root .head { align-items: flex-start; flex-direction: column; }
	.chatview-root .head-right { width: 100%; justify-content: flex-end; }
	.chatview-root .rb { max-width: 94%; }
	.chatview-root .cbtn { padding-inline: 14px; }
}
</style>