<template>
	<div class="file-context-root">
		<div v-if="statusLoading" class="selection-state" role="status">{{ $t('Loading selected files…') }}</div>
		<div v-else-if="apiError" class="api-error" role="alert">
			<span>{{ apiError }}</span>
			<NcButton variant="tertiary" :loading="statusLoading" :disabled="statusLoading" @click="loadStatus">{{ $t('Try again') }}</NcButton>
		</div>
		<div v-else-if="fileIds.length === 0" class="selection-state">{{ $t('Select files in Nextcloud Files to start a file-context chat.') }}</div>
		<div v-else-if="statusLoaded && files.length === 0" class="selection-state" role="status">{{ $t('None of the selected files is indexed yet.') }}</div>
		<header class="head">
			<div class="head-info">
				<h1>{{ $t('File context chat') }}</h1>
				<p class="subtitle">
					{{ $t('EVA uses selected files as document evidence. Personal knowledge can add context, but is not evidence from those files.') }}
				</p>
			</div>
			<div class="file-chips">
				<span v-for="f in files" :key="f.fileId" class="chip">
					{{ f.name }}
				</span>
				<span v-if="missing.length" class="chip missing" :title="$t('{count} file(s) not yet indexed', { count: missing.length })">
					{{ $t('{count} not indexed', { count: missing.length }) }}
				</span>
			</div>
		</header>

		<div class="messages" ref="messagesEl">
			<div v-if="messages.length === 0" class="empty">
				<div class="empty-icon"><NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="24" /></div>
				<strong v-if="statusLoading">{{ $t('Loading selected files…') }}</strong>
				<strong v-else-if="fileIds.length === 0">{{ $t('No files were selected.') }}</strong>
				<strong v-else-if="apiError">{{ $t('Selected files could not be loaded.') }}</strong>
				<strong v-else-if="files.length === 0">{{ $t('None of the selected files is indexed yet.') }}</strong>
				<strong v-else>{{ $t('Ask about the selected files') }}</strong>
				<span v-if="files.length">{{ $t('EVA answers from the selected files. Personal knowledge can add context, but does not serve as evidence about those files.') }}</span>
				<span v-else-if="fileIds.length === 0">{{ $t('Select files in Nextcloud Files to start a file-context chat.') }}</span>
				<span v-else-if="!statusLoading && !apiError">{{ $t('Index the selected files before starting a file-context chat.') }}</span>
			</div>
			<div v-for="(m, i) in messages" :key="i" :class="['msg', m.role]">
				<div class="msg-author">{{ m.role === 'user' ? $t('You') : 'EVA' }}</div>
				<div class="msg-body">{{ m.content }}</div>
				<div v-if="m.sources && m.sources.length" class="msg-sources">
					<template v-for="(s, index) in m.sources" :key="s.url || s.path || index">
						<a v-if="s.url" :href="s.url" target="_blank" rel="noopener noreferrer">{{ s.ref !== undefined ? '[' + s.ref + '] ' : '' }}{{ s.name }}</a>
						<span v-else>{{ s.ref !== undefined ? '[' + s.ref + '] ' : '' }}{{ s.name }}</span>
					</template>
				</div>
			</div>
			<div v-if="busy" class="msg assistant pending">
				<div class="msg-author">EVA</div>
				<div class="msg-body">…</div>
			</div>
		</div>

		<form class="input-row" @submit.prevent="ask">
			<NcTextField
				v-model="input"
				:placeholder="statusLoading ? $t('Loading selected files…') : apiError ? $t('Selected files could not be loaded.') : files.length === 0 ? $t('No indexed files available') : $t('Ask about these files…')"
				:disabled="busy || !canAsk"
				@keydown.enter.exact.prevent="ask" />
			<NcButton variant="primary" type="submit" :disabled="busy || !canAsk || !input.trim()">
				<template #icon>
					<svg width="18" height="18" viewBox="0 0 24 24"><path :d="mdiSend" fill="currentColor" /></svg>
				</template>
				{{ $t('Send') }}
			</NcButton>
		</form>
	</div>
</template>

<script>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { NcButton, NcTextField } from '@nextcloud/vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiSend, mdiFileDocumentOutline } from '@mdi/js'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'
import { citedSources } from '../lib/chat-utils'

export default {
	name: 'FileContextChatView',
	components: { NcButton, NcTextField, NcIconSvgWrapper },
	props: {
		fileIds: { type: Array, default: () => [] },
	},
	setup(props) {
		const files = ref([])
		const missing = ref([])
		const statusLoading = ref(true)
		const statusLoaded = ref(false)
		const messages = ref([])
		const input = ref('')
		const busy = ref(false)
		const messagesEl = ref(null)
		const apiError = ref('')
		const canAsk = computed(() => statusLoaded.value && !apiError.value && files.value.length > 0)
		let statusRequestId = 0

		const scrollDown = async () => {
			await nextTick()
			if (messagesEl.value) {
				messagesEl.value.scrollTop = messagesEl.value.scrollHeight
			}
		}

		const loadStatus = async () => {
			const currentRequest = ++statusRequestId
			const fileIds = [...new Set((props.fileIds || []).map(Number).filter(id => Number.isInteger(id) && id > 0))]
			files.value = []
			missing.value = []
			if (fileIds.length === 0) {
				statusLoaded.value = true
				statusLoading.value = false
				apiError.value = ''
				return
			}
			statusLoading.value = true
			statusLoaded.value = false
			apiError.value = ''
			try {
				const r = await api('POST', '/fileContextStatus', { fileIds })
				if (!r || !Array.isArray(r.files) || !Array.isArray(r.missing)
					|| r.files.some(file => !file || !Number.isInteger(Number(file.fileId)) || Number(file.fileId) < 1 || typeof file.name !== 'string')) {
					throw new Error(t('The file-context response was incomplete.'))
				}
				if (currentRequest !== statusRequestId) return
				const selected = new Set(fileIds)
				files.value = r.files.filter(file => selected.has(Number(file.fileId)))
				missing.value = r.missing.map(Number).filter(id => selected.has(id))
				statusLoaded.value = true
			} catch (e) {
				if (currentRequest === statusRequestId) apiError.value = t('The selected files could not be loaded: {error}', { error: errMsg(e) })
				console.error('[eva-ai] fileContextStatus failed', e)
			} finally {
				if (currentRequest === statusRequestId) statusLoading.value = false
			}
		}

		const ask = async () => {
			const text = input.value.trim()
			if (!text || busy.value || !canAsk.value) return
			busy.value = true
			messages.value.push({ role: 'user', content: text })
			input.value = ''
			await scrollDown()
			try {
				const history = messages.value.slice(0, -1).map((m) => ({
					role: m.role,
					content: m.content,
				}))
				const r = await api('POST', '/fileContextChat', {
					fileIds: props.fileIds,
					message: text,
					history,
				})
				if (r && r.error) {
					messages.value.push({ role: 'assistant', content: t('Error: {error}', { error: r.error }), sources: [] })
				} else if (!r || typeof r.answer !== 'string' || r.answer.trim() === '') {
					messages.value.push({ role: 'assistant', content: t('The model returned an empty response. Try asking a more specific question.'), sources: [] })
				} else {
					const availableSources = Array.isArray(r.sources) ? r.sources : []
					messages.value.push({
						role: 'assistant',
						content: r.answer,
						sources: citedSources(r.answer, availableSources)
							.filter(item => item.src && typeof item.src.name === 'string')
							.map(item => ({ ...item.src, ref: item.ref })),
					})
				}
			} catch (e) {
				messages.value.push({ role: 'assistant', content: t('The file-context request failed: {error}', { error: errMsg(e) }), sources: [] })
			} finally {
				busy.value = false
				await scrollDown()
			}
		}

		watch(() => (props.fileIds || []).join(','), () => {
			files.value = []
			missing.value = []
			messages.value = []
			input.value = ''
			loadStatus()
		})
		onMounted(loadStatus)

		return { files, missing, messages, input, busy, messagesEl, apiError, statusLoading, statusLoaded, canAsk, loadStatus, ask, mdiSend, mdiFileDocumentOutline }
	},
}
</script>

<style scoped>
.api-error {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 12px;
	border: 1px solid color-mix(in srgb, var(--color-error, #c00) 45%, var(--color-border));
	border-radius: 10px;
	background: color-mix(in srgb, var(--color-error, #c00) 8%, var(--color-main-background));
	color: var(--color-error, #c00);
	font-size: 13px;
}

.selection-state {
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.file-context-root {
	width: 100%;
	max-width: var(--eva-content-width, 1180px);
	height: 100%;
	margin: 0 auto;
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	padding: 24px clamp(16px, 3vw, 36px) 28px;
	gap: 18px;
	overflow: hidden;
}
.head {
	display: flex;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	align-items: flex-start;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}
.head h1 {
	margin: 0;
	font-size: clamp(22px, 3vw, 28px);
	font-weight: 700;
	letter-spacing: -.02em;
}
.head .subtitle {
	margin: 4px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
.file-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	max-width: 60%;
	justify-content: flex-end;
	padding-top: 2px;
}
.chip {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 14px;
	padding: 5px 10px;
	font-size: 12px;
}
.chip.missing {
	background: var(--color-error-rgb, 255 0 0 / 12%);
	color: var(--color-error, #c00);
}
.messages {
	flex: 1;
	min-height: 280px;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: clamp(14px, 2vw, 22px);
	border: 1px solid var(--color-border);
	border-radius: 14px;
	background: var(--color-background-dark, var(--color-main-background));
}
.empty {
	flex: 1;
	min-height: 150px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
.empty-icon {
	display: grid;
	place-items: center;
	width: 44px;
	height: 44px;
	margin-bottom: 4px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	color: var(--color-primary-element);
	font-size: 24px;
}
.empty strong { color: var(--color-main-text); font-size: 16px; }
.empty span { max-width: 420px; font-size: 13px; }
.msg {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 14px;
	padding: 12px 16px;
	max-width: min(80%, 820px);
	line-height: 1.5;
}
.msg.user {
	align-self: flex-end;
	background: var(--color-primary-element, #006aa3);
	border-color: color-mix(in srgb, var(--color-primary-element) 45%, transparent);
	color: var(--color-primary-text, #fff);
	border-bottom-right-radius: 4px;
}
.msg.assistant {
	align-self: flex-start;
	border-bottom-left-radius: 4px;
}
.msg.pending .msg-body {
	opacity: 0.6;
}
.msg-author {
	font-size: 11px;
	font-weight: 600;
	opacity: 0.7;
	margin-bottom: 4px;
}
.msg-body {
	white-space: pre-wrap;
	word-wrap: break-word;
}
.msg-sources {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 6px;
	font-size: 12px;
}
.msg-sources a,
.msg-sources span {
	background: rgba(0, 0, 0, 0.08);
	background: color-mix(in srgb, currentColor 10%, transparent);
	padding: 3px 9px;
	border-radius: 10px;
	text-decoration: none;
}
.input-row {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
}
.input-row :deep(.input-field) {
	flex: 1;
	min-width: 0;
}

@media (max-width: 600px) {
	.file-context-root { padding: 18px 12px 20px; }
	.file-chips { max-width: 100%; justify-content: flex-start; }
	.msg { max-width: 94%; }
}
</style>
