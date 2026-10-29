<template>
	<div class="chatview-shell">
		<div ref="root" class="chatview-root"></div>
		<div v-if="pendingImages.length" class="chat-composer__attachments" :aria-label="$t('Attached images')">
			<div v-for="image in pendingImages" :key="image.id" class="chat-composer__attachment">
				<img :src="image.url" :alt="image.file.name">
				<span>{{ image.file.name }}</span>
				<NcButton type="button" variant="tertiary-no-background" :aria-label="$t('Remove image {name}', { name: image.file.name })" @click="removeImage(image.id)">×</NcButton>
			</div>
			<p class="chat-composer__privacy">{{ $t('Attached images are sent to the configured chat provider with your message.') }}</p>
		</div>
		<p v-if="imageError" class="chat-composer__error" role="alert">{{ imageError }}</p>
		<form ref="composerForm" class="chat-composer">
			<input ref="imageInput" class="chat-composer__file" type="file" accept="image/png,image/jpeg,image/webp" multiple @change="onImagesSelected">
			<NcButton class="chat-composer__attach" type="button" variant="tertiary" :aria-label="$t('Attach images')" :title="$t('Attach images')" :disabled="!ready || sending" @click="imageInput?.click()">
				<template #icon><NcIconSvgWrapper :path="mdiImageOutline" :size="20" /></template>
			</NcButton>
			<NcTextField
				ref="composerField"
				v-model="draft"
				id="chatinput"
				class="chat-composer__field"
				:label="$t('Message EVA')"
				:placeholder="$t('Ask a question or describe a task')"
				:disabled="!ready || sending"
				pill
			/>
			<NcButton
				ref="sendButton"
				class="chat-composer__send"
				size="small"
				:type="sending ? 'button' : 'submit'"
				:variant="sending ? 'error' : 'tertiary'"
				:aria-label="sending ? $t('Stop') : $t('Send message')"
				:disabled="!ready">
				<template #icon><NcIconSvgWrapper :path="sending ? mdiStopCircleOutline : mdiSendOutline" :size="20" /></template>
			</NcButton>
		</form>
	</div>
</template>

<script>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { NcButton, NcIconSvgWrapper, NcTextField } from '@nextcloud/vue'
import { mdiImageOutline, mdiSendOutline, mdiStopCircleOutline } from '@mdi/js'
import { translate as t } from '../lib/i18n'
import { mountChat } from '../lib/vanilla'

export default {
	name: 'ChatView',
	components: { NcButton, NcIconSvgWrapper, NcTextField },
	props: {
		chatId: { type: String, default: null },
		// A just-created chat can be rendered from the create response instead
		// of immediately reading the same record back from storage.
		initialChat: { type: Object, default: null },
		initialPrompt: { type: String, default: '' },
		// When true the initialPrompt is sent immediately after the chat
		// mounts instead of only being pre-filled into the input (Issue:
		// start a new chat directly from the dashboard).
		autoSend: { type: Boolean, default: false },
	},
	emits: ['chat-updated', 'prompt-consumed'],
	setup(props, { emit }) {
		const root = ref(null)
		const composerForm = ref(null)
		const composerField = ref(null)
		const sendButton = ref(null)
		const imageInput = ref(null)
		const draft = ref('')
		const pendingImages = ref([])
		const imageError = ref('')
		const ready = ref(false)
		const sending = ref(false)
		let mounted = false

		const removeImage = id => {
			const image = pendingImages.value.find(item => item.id === id)
			if (image) URL.revokeObjectURL(image.url)
			pendingImages.value = pendingImages.value.filter(item => item.id !== id)
			imageError.value = ''
		}

		const onImagesSelected = event => {
			const files = Array.from(event.target?.files || [])
			if (event.target) event.target.value = ''
			const totalCount = pendingImages.value.length + files.length
			const totalBytes = pendingImages.value.reduce((sum, image) => sum + image.file.size, 0) + files.reduce((sum, file) => sum + file.size, 0)
			if (totalCount > 4) { imageError.value = t('Attach up to four supported images at a time.'); return }
			if (files.some(file => !['image/png', 'image/jpeg', 'image/webp'].includes(file.type))) { imageError.value = t('Choose PNG, JPEG, or WebP images.'); return }
			if (totalBytes > 4 * 1024 * 1024) { imageError.value = t('Attached images must total no more than 4 MB.'); return }
			pendingImages.value = pendingImages.value.concat(files.map(file => ({ id: `${Date.now()}-${Math.random()}`, file, url: URL.createObjectURL(file) })))
			imageError.value = ''
		}

		const applyInitialPrompt = () => {
			if (!mounted || !ready.value || !composerField.value || !props.initialPrompt) return
			const input = composerField.value.$el?.querySelector('input')
			const form = composerForm.value
			if (!input || !form) return
			draft.value = props.initialPrompt
			input.value = props.initialPrompt
			input.dispatchEvent(new Event('input', { bubbles: true }))
			input.focus()
			if (props.autoSend && input.value.trim()) {
				// The vanilla chat listens on the form's submit event, so
				// dispatching it runs the regular send flow (including chat
				// creation fallback and streaming).
				form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
			}
			emit('prompt-consumed')
		}

		const mount = () => {
			if (!root.value) return
			if (root.value.__evaAi && typeof root.value.__evaAi.destroy === 'function') root.value.__evaAi.destroy()
			root.value.innerHTML = ''
			ready.value = false
			sending.value = false
			delete root.value.__evaAi
			mountChat(root.value, {
				chatId: props.chatId || null,
				initialChat: props.initialChat?.id === props.chatId ? props.initialChat : null,
				form: composerForm.value,
				input: composerField.value?.$el?.querySelector('input'),
				sendBtn: sendButton.value?.$el,
				getPendingImages: () => pendingImages.value.map(image => ({ file: image.file, name: image.file.name, url: image.url })),
				consumePendingImages: () => { pendingImages.value = []; imageError.value = '' },
				onInputValueChange: value => { draft.value = value },
				onStreamingChange: value => { sending.value = value },
				onRecent: () => emit('chat-updated'),
			})
			mounted = true
			const renderer = root.value.__evaAi
			renderer.ready.then(loaded => {
				if (!mounted || root.value?.__evaAi !== renderer || !loaded) return
				ready.value = true
				applyInitialPrompt()
			})
		}

		onBeforeUnmount(() => {
			mounted = false
			root.value?.__evaAi?.destroy()
			pendingImages.value.forEach(image => URL.revokeObjectURL(image.url))
			pendingImages.value = []
		})
		onMounted(mount)
		watch(() => props.initialPrompt, () => {
			applyInitialPrompt()
		})
		watch(() => props.chatId, () => {
			pendingImages.value.forEach(image => URL.revokeObjectURL(image.url))
			pendingImages.value = []
			imageError.value = ''
			if (mounted) mount()
		})

		return { root, composerForm, composerField, sendButton, imageInput, pendingImages, imageError, removeImage, onImagesSelected, draft, ready, sending, mdiImageOutline, mdiSendOutline, mdiStopCircleOutline }
	},
}
</script>

<style>
/* App.vue provides a responsive shared content width for all workspace views. */
.chatview-root {
	width: 100%;
	height: 100%;
	flex: 1 1 auto;
	min-height: 0;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	padding: 0;
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
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
	font-size: 18px;
	font-weight: 650;
	letter-spacing: -.01em;
	color: var(--color-main-text, #222);
}

.chatview-root .chat-actions-host { flex: 0 0 auto; }
.chatview-root .head h1 { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chatview-root .chat-context { width: min(100%, 800px); margin: 8px auto 0; display: flex; gap: 8px; flex-wrap: wrap; }
.chatview-root .chat-context:not(:has(.pill:not([hidden]))) { display: none; }
.chatview-root .head > .pill { max-width: 40%; overflow-wrap: anywhere; }
.chatview-root .chat-log { max-width: 800px; }






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
	padding-right: 58px;
	background: transparent !important;
	color: var(--color-main-text, #222) !important;
}

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

.chatview-root .racts { position: absolute; top: -2px; right: 0; display: flex; gap: 2px; opacity: 0; transition: opacity .12s; }
.chatview-root .rm:hover .racts { opacity: 1; }
.chatview-root .rm:focus-within .racts { opacity: 1; }
.chatview-root .racts .rcopy,
.chatview-root .racts .ract {
	position: static; opacity: 1;
	width: 24px; height: 24px; padding: 0; border: 0; border-radius: 5px;
	background: transparent; color: var(--color-text-maxcontrast, #888);
	font-size: 13px; line-height: 1; cursor: pointer;
	transition: background-color .12s;
}
.chatview-root .racts .ract:hover,
.chatview-root .racts .rcopy:hover { background: var(--color-background-hover, #e5e5e5); }
.chatview-root .racts button:disabled { opacity: .55; cursor: not-allowed; }
@media (prefers-reduced-motion: reduce) { .chatview-root .racts { transition: none; } }


.chatview-root .rth { margin: 0 0 9px; font-size: 12px; }
.chatview-root .rth summary { cursor: pointer; color: var(--color-text-maxcontrast, #555); font-weight: 600; user-select: none; }
.chatview-root .rth-c { margin-top: 6px; padding: 8px 10px; border-left: 2px solid var(--color-border, #ccc); background: transparent; white-space: pre-wrap; word-break: break-word; color: var(--color-text-maxcontrast, #555); font-size: 12px; line-height: 1.5; max-height: 220px; overflow-y: auto; }
.chatview-root .rtrimmed { margin-bottom: 10px; padding: 8px 10px; max-width: min(100%, 560px); border: 1px solid color-mix(in srgb, var(--color-warning) 45%, var(--color-border)); border-radius: 6px; background: color-mix(in srgb, var(--color-warning) 8%, transparent); color: var(--color-text-maxcontrast, #555); font-size: 12px; }
.chatview-root .rs { margin-top: 8px; font-size: 12px; color: var(--color-text-maxcontrast, #555); }
.chatview-root .rs-sum { cursor: pointer; font-weight: 600; user-select: none; }
.chatview-root .rs-list { margin-top: 4px; padding: 6px 0 0 10px; border-left: 2px solid var(--color-border, #ccc); }
.chatview-root .rs-item { margin-bottom: 6px; }
.chatview-root .rs-item a { color: var(--color-primary-element, #00679c); text-decoration: none; }
/* A source with nothing to open is named, not linked, so it must not look like a link. */
.chatview-root .rs-item .rs-plain { color: var(--color-main-text, #222); }
.chatview-root .rs-excerpt { margin-top: 2px; padding: 4px 8px; background: var(--color-background-dark, #f5f5f5); border-radius: 4px; font-size: 11px; line-height: 1.4; white-space: pre-wrap; word-break: break-word; }
.chatview-root .rs-badge { display: inline-block; margin-right: 6px; padding: 1px 6px; border-radius: 8px; background: var(--color-primary-element-light, #e5f0f7); color: var(--color-primary-element, #00679c); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; vertical-align: 1px; }
.chatview-root .rs-host { display: block; margin-left: 0; color: var(--color-text-maxcontrast, #555); font-size: 11px; }
.chatview-root .rfu { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.chatview-root .rfu-btn { padding: 4px 10px; border: 1px solid var(--color-border, #ccc); border-radius: 14px; background: var(--color-background-hover, #f6f7f8); color: var(--color-main-text, #222); font: inherit; font-size: 12px; cursor: pointer; transition: background .15s; }
.chatview-root .rfu-btn:hover { background: var(--color-primary-element, #00679c); color: #fff; border-color: var(--color-primary-element, #00679c); }
.chatview-root .rconfirm { margin-top: 10px; max-width: min(100%, 560px); padding: 12px; border: 1px solid var(--color-border, #ccd0d4); border-left: 3px solid var(--color-warning, #eab308); border-radius: 8px; background: var(--color-background-hover, #f6f7f8); }
.chatview-root .rconfirm--danger { border-left-color: var(--color-error, #e9322d); }
.chatview-root .rconfirm--danger .rconfirm-label { color: var(--color-error, #e9322d); }
.chatview-root .rconfirm-label { font-size: 13px; font-weight: 650; color: var(--color-main-text, #222); }
.chatview-root .rconfirm-summary { margin: 3px 0 0; font-size: 12px; color: var(--color-text-maxcontrast, #555); }
.chatview-root .rconfirm-args { max-height: 150px; margin: 8px 0; padding: 8px; overflow: auto; white-space: pre-wrap; word-break: break-word; font: 12px/1.45 var(--font-family-monospace, monospace); color: var(--color-text-maxcontrast, #555); background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #ddd); border-radius: 5px; }
.chatview-root .rconfirm-diff { max-height: 280px; white-space: pre; }
.chatview-root .rconfirm-hunk { margin-top: 8px; }
.chatview-root .rconfirm-hunk label { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; }
.chatview-root .rconfirm-hunk input { margin: 0; }
.chatview-root .rconfirm-preview-path { margin-top: 8px; overflow-wrap: anywhere; font-size: 12px; font-weight: 600; }
.chatview-root .rconfirm-error { margin: 8px 0 0; color: var(--color-error, #e9322d); font-size: 12px; white-space: pre-wrap; word-break: break-word; }
.chatview-root .rconfirm-share-form, .chatview-root .rconfirm-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 10px 0 12px; }
.chatview-root .rconfirm-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.chatview-root .rconfirm-field--full, .chatview-root .rconfirm-field:first-child { grid-column: 1 / -1; }
.chatview-root .rconfirm-field.rconfirm-invalid input, .chatview-root .rconfirm-field.rconfirm-invalid select, .chatview-root .rconfirm-field.rconfirm-invalid textarea { border-color: var(--color-error, #e9322d); }
.chatview-root .rconfirm-field span, .chatview-root .rconfirm-check span { color: var(--color-text-maxcontrast, #555); font-size: 12px; font-weight: 600; }
.chatview-root .rconfirm-field input, .chatview-root .rconfirm-field select, .chatview-root .rconfirm-field textarea { width: 100%; min-height: 34px; padding: 6px 8px; border: 1px solid var(--color-border, #bbb); border-radius: var(--border-radius-element, 6px); background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: inherit; font-size: 13px; box-sizing: border-box; }
.chatview-root .rconfirm-field textarea { min-height: 58px; resize: vertical; }
.chatview-root .rconfirm-check { display: flex; align-items: center; gap: 7px; min-width: 0; }
.chatview-root .rconfirm-check input { margin: 0; }
.chatview-root .rconfirm-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.chatview-root .rconfirm-actions button { min-height: var(--default-clickable-area, 34px); padding: 6px 12px; border: 1px solid var(--color-border, #ccd0d4); border-radius: var(--border-radius-element, 6px); cursor: pointer; font: inherit; font-size: 13px; }
.chatview-root .rconfirm-approve { color: var(--color-primary-element-text, #fff); background: var(--color-primary-element, #00679c); border-color: var(--color-primary-element, #00679c) !important; }
.chatview-root .rconfirm--danger .rconfirm-approve { background: var(--color-error, #e9322d); border-color: var(--color-error, #e9322d) !important; }
.chatview-root .rconfirm-reject { color: var(--color-main-text, #222); background: var(--color-main-background, #fff); }
.chatview-root .rconfirm-actions button:disabled { opacity: .6; cursor: default; }
.chatview-root .rconfirm-link { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 8px; max-width: min(100%, 560px); }
.chatview-root .rconfirm-link a { color: var(--color-primary-element, #00679c); font-size: 12px; word-break: break-all; text-decoration: none; }
.chatview-root .rconfirm-link button { min-height: var(--default-clickable-area, 34px); padding: 4px 10px; border: 1px solid var(--color-border, #ccd0d4); border-radius: var(--border-radius-element, 6px); cursor: pointer; font: inherit; font-size: 12px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); }
.chatview-root .rconfirm-link button:disabled { opacity: .6; cursor: default; }
.chatview-root .rtools { display: flex; flex-direction: column; gap: 4px; max-width: 86%; margin-top: 8px; }
.chatview-root .rtools .tool { padding: 3px 0; font-size: 12px; color: var(--color-text-maxcontrast, #555); font-family: var(--font-family-monospace, monospace); }
.chatview-root .rtools .tool.running { color: #8a6d1a; }
.chatview-root .rtools .tool.ok { color: #2f8f3f; }
.chatview-root .rtools .tool.bad { color: var(--color-error, #e9322d); }
.chatview-root .rtools details { display: inline-block; margin-left: 6px; font-family: var(--font-family-sans-serif, sans-serif); }
.chatview-root .rtools summary { cursor: pointer; color: var(--color-primary-element, #00679c); font-size: 11px; }
.chatview-root .rtools pre { margin: 4px 0 0; padding: 6px 8px; max-width: min(720px, 80vw); max-height: 180px; overflow: auto; white-space: pre-wrap; word-break: break-word; border: 1px solid var(--color-border, #ddd); border-radius: 4px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: 11px/1.4 var(--font-family-monospace, monospace); }
.chatview-root .rtools .tool-error { margin: 3px 0 0 18px; color: var(--color-error, #e9322d); font-family: var(--font-family-sans-serif, sans-serif); font-size: 11px; white-space: pre-wrap; word-break: break-word; }

.chatview-root .chat-model-select { flex: 0 0 auto; max-width: min(210px, 34vw); min-width: 100px; min-height: 40px; padding: 8px 10px; border: 1px solid var(--color-border, #bbb); border-radius: 8px; color: var(--color-main-text, #222); background: var(--color-main-background, #fff); font: inherit; font-size: 13px; }
.chatview-root .chat-model-select[hidden] { display: none; }
.chatview-root .rmodel { margin-top: 6px; color: var(--color-text-maxcontrast, #666); font-size: 11px; }
.chatview-root .voice-status { width: min(100%, var(--eva-content-width, 1180px)); box-sizing: border-box; margin: -6px auto 10px; color: var(--color-text-maxcontrast, #666); font-size: 12px; }
.chatview-root .image-attachments { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; width: min(100%, var(--eva-content-width, 1180px)); box-sizing: border-box; margin: 0 auto 8px; }
.chatview-root .image-attachments[hidden] { display: none; }
.chatview-root .image-privacy-note { flex: 1 0 100%; margin: 0; color: var(--color-text-maxcontrast, #666); font-size: 12px; }
.chatview-root .image-attachment { display: flex; align-items: center; gap: 6px; max-width: 240px; padding: 4px 6px; border: 1px solid var(--color-border, #ddd); border-radius: 8px; background: var(--color-main-background, #fff); font-size: 12px; }
.chatview-root .image-attachment img { width: 36px; height: 36px; object-fit: cover; border-radius: 4px; }
.chatview-root .image-attachment span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chatview-root .image-attachment button { border: 0; border-radius: 50%; background: transparent; color: var(--color-main-text, #333); cursor: pointer; font-size: 18px; line-height: 1; }
.chatview-root .cbtn-images { flex: 0 0 auto; min-width: 42px; padding-inline: 10px; font-size: 20px; }
.chatview-root .message-images { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.chatview-root .message-images img { max-width: min(220px, 62vw); max-height: 180px; object-fit: contain; border-radius: 8px; }
.chatview-root .cbtn-mic { flex: 0 0 auto; min-width: 42px; padding-inline: 10px; font-size: 18px; }
.chatview-root .cbtn-mic.is-recording { color: var(--color-error, #e9322d); animation: eva-mic-pulse 1.2s ease-in-out infinite; }
.chatview-root .cbtn-voice-cancel { padding-inline: 10px; white-space: nowrap; }
@keyframes eva-mic-pulse { 50% { box-shadow: 0 0 0 5px color-mix(in srgb, var(--color-error, #e9322d) 18%, transparent); } }
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
.chatview-root .cbtn-stop { background: var(--color-error, #e9322d); }
.chatview-root .err { margin: 0 0 8px; color: var(--color-error, #e9322d); font-size: 13px; white-space: pre-wrap; }

@media (min-width: 1400px) {
	.chatview-root .chat-log { padding-top: 30px; padding-bottom: 24px; }
	.chatview-root .rt { font-size: 15px; line-height: 1.65; }
}

@media (max-width: 600px) {
	.chatview-root .head { min-height: 48px; flex-wrap: nowrap; }
	.chatview-root .head h1 { font-size: 16px; }

	.chatview-root .chat-log { padding-top: 18px; gap: 16px; }
	.chatview-root .rb { max-width: 94%; }
	.chatview-root .rm.assistant .rb { padding-right: 28px; }
	.chatview-root .rconfirm-share-form { grid-template-columns: 1fr; }
	.chatview-root .rconfirm-field:first-child { grid-column: auto; }
	.chatview-root .cbtn { padding-inline: 13px; }
	.chatview-root .chat-model-select { flex: 1 1 120px; }
}
.chatview-root .image-generator { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 8px; width: min(100%, var(--eva-chat-column-width, 900px)); box-sizing: border-box; margin: 10px auto; padding: 12px; border: 1px solid var(--color-border, #ddd); border-radius: 12px; background: var(--color-background-dark, #f7f7f7); }
.chatview-root .image-generator[hidden] { display: none; }
.chatview-root .image-generator h2, .chatview-root .image-generator-privacy, .chatview-root .image-generator-status, .chatview-root .image-gallery { grid-column: 1 / -1; margin: 0; }
.chatview-root .image-generator h2 { font-size: 16px; }
.chatview-root .image-generator-privacy, .chatview-root .image-generator-status { color: var(--color-text-maxcontrast, #666); font-size: 12px; }
.chatview-root .image-generator textarea { width: 100%; min-width: 0; min-height: 72px; box-sizing: border-box; padding: 8px; border: 1px solid var(--color-border, #ccc); border-radius: 8px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: inherit; resize: vertical; }
.chatview-root .image-generator select { align-self: start; min-height: 36px; padding: 6px; border: 1px solid var(--color-border, #ccc); border-radius: 8px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); }
.chatview-root .image-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
.chatview-root .generated-image { display: grid; gap: 5px; min-width: 0; font-size: 12px; }
.chatview-root .generated-image img { width: 100%; aspect-ratio: 1; object-fit: contain; border: 1px solid var(--color-border, #ddd); border-radius: 8px; background: var(--color-main-background, #fff); }
.chatview-root .generated-image span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.chatview-root [hidden] { display: none !important; }
.chatview-root .message-export-select { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; font-size: 12px; color: var(--color-text-maxcontrast); }

.customize-overlay { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(0, 0, 0, .45); }
.customize-box { width: min(480px, 100%); max-height: 90vh; overflow: auto; padding: 22px 24px; border-radius: var(--border-radius-large, 12px); background: var(--color-main-background, #fff); box-shadow: 0 8px 32px rgba(0, 0, 0, .25); color: var(--color-main-text, #222); }
.customize-box h3 { margin: 0 0 6px; font-size: 17px; font-weight: 650; }
.customize-sub { margin: 0 0 16px; font-size: 13px; line-height: 1.5; color: var(--color-text-maxcontrast, #555); }
.customize-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; font-size: 13px; font-weight: 600; }
.customize-field select, .customize-field textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--color-border, #ccd0d4); border-radius: var(--border-radius-element, 8px); background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: inherit; font-size: 13px; font-weight: 400; }
.customize-field textarea { resize: vertical; min-height: 90px; }
.customize-field select:focus-visible, .customize-field textarea:focus-visible { outline: 2px solid var(--color-main-text, #222); outline-offset: 1px; }
.customize-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }
.customize-actions .cbtn { padding-inline: 16px; }
.chatview-root .cbtn-ghost { background: transparent; border: 1px solid var(--color-border, #ccd0d4); color: var(--color-main-text, #222); }
.customize-err { margin-top: 10px; color: var(--color-error, #e9322d); font-size: 12px; white-space: pre-wrap; }

/* Keep the conversation and composer on one readable, centered column. */
.chatview-root {
	padding: 0 clamp(16px, 3vw, 40px);
}

.chatview-root .head {
	width: min(100%, var(--eva-chat-column-width, 900px));
	min-height: 56px;
	padding-block: 8px;
	border-bottom-color: color-mix(in srgb, var(--color-border, #ddd) 65%, transparent);
}

.chatview-root .head h1 {
	font-size: 16px;
	font-weight: 600;
}

.chatview-root .chat-context,
.chatview-root .chat-log,
.chatview-root .voice-status,
.chatview-root .image-attachments {
	width: min(100%, var(--eva-chat-column-width, 900px));
	max-width: var(--eva-chat-column-width, 900px);
}

.chatview-root .chat-context {
	margin-top: 8px;
}

.chatview-root .chat-log {
	gap: 28px;
	padding: 32px 0 24px;
}

.chatview-root .rm {
	width: 100%;
	max-width: 100%;
	margin-inline: auto;
}

.chatview-root .rb {
	max-width: 100%;
	font-size: 15px;
	line-height: 1.7;
}

.chatview-root .rm.user .rb {
	max-width: min(82%, 720px);
	padding: 10px 16px;
	border-radius: 20px 20px 5px 20px;
}

.chatview-root .rm.assistant .rb {
	padding-right: 0;
}

.chatview-shell {
	--eva-chat-column-width: 900px;
	width: 100%;
	height: 100%;
	min-height: 0;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	padding: 0 clamp(16px, 3vw, 40px);
	overflow: hidden;
	background: var(--color-main-background, #fff);
}

.chatview-root {
	height: auto;
	min-height: 0;
	padding: 0;
}

.chat-composer {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
	flex: 0 0 auto;
	width: min(100%, var(--eva-chat-column-width, 900px));
	margin: 0 auto;
	padding: 4px 0 max(8px, env(safe-area-inset-bottom));
	box-sizing: border-box;
}

.chat-composer__file { display: none; }
.chat-composer__attach { flex: 0 0 auto; }
.chat-composer__attachments {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	width: min(100%, var(--eva-chat-column-width, 900px));
	margin: 0 auto 8px;
}
.chat-composer__privacy { flex: 1 0 100%; margin: 0; color: var(--color-text-maxcontrast, #666); font-size: 12px; }
.chat-composer__attachment {
	display: flex;
	align-items: center;
	gap: 8px;
	max-width: 260px;
	padding: 4px 8px 4px 4px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-pill, 24px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	font-size: 12px;
}
.chat-composer__attachment img { width: 32px; height: 32px; object-fit: cover; border-radius: 50%; }
.chat-composer__attachment span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-composer__error { width: min(100%, var(--eva-chat-column-width, 900px)); margin: 0 auto 6px; color: var(--color-error, #e9322d); font-size: 12px; }

.chat-composer__field {
	flex: 1 1 auto;
	min-width: 0;
}

.chat-composer__field :deep(.input-field) {
	width: 100%;
	margin: 0;
}

.chat-composer__send {
	flex: 0 0 auto;
}

@media (max-width: 600px) {
	.chatview-shell { padding-inline: 12px; }
	.chatview-root .head { min-height: 48px; }
	.chatview-root .chat-log { gap: 20px; padding-top: 18px; padding-bottom: 16px; }
	.chatview-root .rm.user .rb { max-width: 90%; }
	.chatview-root .rb { font-size: 15px; }
}

@media (hover: none) {
	.chatview-root .racts { opacity: 1; }
}
</style>
