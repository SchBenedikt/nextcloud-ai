<template>
	<div class="settings-view">
		<header class="page-header">
			<div class="header-copy">
				<p class="eyebrow">EVA AI</p>
				<h2 class="settings-title">Settings</h2>
				<p class="page-intro">Control how EVA connects to Ollama, searches your files and is allowed to act in Nextcloud.</p>
			</div>
			<div class="header-actions">
				<span v-if="saved" class="saved-label" role="status">Saved</span>
				<NcButton type="primary" :loading="saving" :disabled="busy" @click="save">
					Save changes
				</NcButton>
			</div>
		</header>

		<div v-if="loadError" class="callout callout-error" role="alert">
			<strong>Settings could not be loaded.</strong>
			<span>{{ loadError }}</span>
			<NcButton type="tertiary-no-background" @click="loadStatus">Try again</NcButton>
		</div>

		<div class="summary-grid" aria-label="EVA status">
			<div class="summary-card" :class="status && status.ollamaOnline ? 'is-ok' : 'is-muted'">
				<span class="status-dot" aria-hidden="true"></span>
				<div>
					<span class="summary-label">Ollama</span>
					<strong>{{ status ? (status.ollamaOnline ? 'Connected' : 'Not connected') : 'Checking…' }}</strong>
					<small>{{ status?.ollamaError || status?.ollamaUrl || 'Local AI server' }}</small>
				</div>
			</div>
			<div class="summary-card">
				<span class="summary-icon" aria-hidden="true">▦</span>
				<div>
					<span class="summary-label">Knowledge base</span>
					<strong>{{ formatNumber(status?.documents) }} documents</strong>
					<small>{{ formatNumber(status?.chunks) }} searchable text chunks</small>
				</div>
			</div>
			<div class="summary-card" :class="status?.indexing ? 'is-working' : ''">
				<span class="summary-icon" aria-hidden="true">↻</span>
				<div>
					<span class="summary-label">Indexing</span>
					<strong>{{ status?.indexing ? 'In progress' : 'Ready' }}</strong>
					<small>{{ status?.lastFinished ? 'Last finished ' + status.lastFinished : 'Run indexing after changing scope' }}</small>
				</div>
			</div>
		</div>

		<div v-if="message.text" class="callout" :class="'callout-' + message.type" :role="message.type === 'error' ? 'alert' : 'status'">
			<strong>{{ message.type === 'error' ? 'Something went wrong' : message.type === 'success' ? 'Done' : 'Notice' }}</strong>
			<span>{{ message.text }}</span>
		</div>
		<div v-if="validationErrors.length" class="callout callout-error validation-summary" role="alert">
			<strong>Check these values</strong>
			<ul><li v-for="error in validationErrors" :key="error">{{ error }}</li></ul>
		</div>

		<main class="settings-body">
			<section class="settings-section">
				<div class="section-heading">
					<div class="section-icon" aria-hidden="true">◎</div>
					<div>
						<h3>Connection &amp; models</h3>
						<p>Tell EVA where Ollama is running and which models should answer questions.</p>
					</div>
				</div>

				<div class="field-grid field-grid-wide">
					<div class="field field-wide">
						<label for="ollama-url">Ollama server URL</label>
						<input id="ollama-url" v-model.trim="f.ollama_url" type="url" autocomplete="url" placeholder="http://127.0.0.1:11434" />
						<p class="field-help">The address of your Ollama HTTP API. The default works when Ollama runs on this same server.</p>
					</div>
					<div class="field">
						<label for="embedding-model">Embedding model</label>
						<input id="embedding-model" v-model.trim="f.embedding_model" type="text" placeholder="nomic-embed-text" />
						<p class="field-help">Turns file text into searchable vectors. This model must be installed in Ollama.</p>
					</div>
					<div class="field">
						<label for="chat-model">Chat model</label>
						<input id="chat-model" v-model.trim="f.chat_model" type="text" placeholder="gemma4:cloud" />
						<p class="field-help">Writes EVA’s answers. Use a model available in Ollama.</p>
					</div>
				</div>
				<div class="inline-actions">
					<NcButton type="secondary" :loading="checking" :disabled="busy" @click="checkOllama">Check connection</NcButton>
					<span class="action-hint">Saves the current values first, then tests the server and both models.</span>
				</div>
				<div v-if="checkOut" class="check-panel" :class="'check-' + checkOut.type" role="status">
					<div v-for="line in checkOut.lines" :key="line.label" class="check-line">
						<span class="check-mark" aria-hidden="true">{{ line.ok ? '✓' : '!' }}</span>
						<div><strong>{{ line.label }}</strong><span>{{ line.detail }}</span></div>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div class="section-icon" aria-hidden="true">⚿</div>
					<div>
						<h3>Safety &amp; actions</h3>
						<p>Choose what EVA may do beyond answering questions. Recommended defaults are shown below.</p>
					</div>
				</div>

				<label class="switch-row" for="actions-enabled">
					<input id="actions-enabled" v-model="f.actions_enabled" type="checkbox" true-value="1" false-value="0" />
					<span><strong>Allow file actions</strong><small>Let EVA create, read, rename and search files, plus work with supported contacts and notes.</small></span>
				</label>
				<div class="warning-note" :class="{ 'is-disabled': actionsDisabled }">
					<strong>{{ actionsDisabled ? 'Actions are disabled' : 'Actions can change your files' }}</strong>
					<span>{{ actionsDisabled ? 'The fields below are inactive until you enable file actions.' : 'EVA still requires confirmation for mutating or destructive operations.' }}</span>
				</div>

				<div class="field-grid" :class="{ 'is-disabled': actionsDisabled }">
					<div class="field">
						<label for="write-types">File types EVA may write</label>
						<input id="write-types" v-model.trim="f.exec_write_types" type="text" :disabled="actionsDisabled" placeholder="md, txt, csv" />
						<p class="field-help">Comma-separated extensions, for example <code>md, txt</code>. Empty means any supported text file.</p>
					</div>
					<div class="field">
						<label for="write-max-chars">Maximum characters per file</label>
						<input id="write-max-chars" v-model="f.exec_write_max_chars" type="number" min="1" max="10000000" :disabled="actionsDisabled" />
						<p class="field-help">A size guard for files created or overwritten by EVA.</p>
					</div>
				</div>

				<fieldset class="choice-group" :disabled="actionsDisabled">
					<legend>Delete permission</legend>
					<label class="choice-row"><input v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="off" /><span><strong>Never</strong><small>EVA cannot delete files.</small></span></label>
					<label class="choice-row"><input v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="own" /><span><strong>Only EVA-created files <em>Recommended</em></strong><small>EVA may delete files it created itself.</small></span></label>
					<label class="choice-row choice-danger"><input v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="all" /><span><strong>Any file in my Files</strong><small>Allows deletion of any file after explicit confirmation. Use with care.</small></span></label>
				</fieldset>
				<label class="switch-row compact-switch" for="notify-on-complete">
					<input id="notify-on-complete" v-model="f.notify_on_complete" type="checkbox" true-value="1" false-value="0" />
					<span><strong>Notify me when a long answer is ready</strong><small>Uses Nextcloud Notifications when background or Talk work finishes.</small></span>
				</label>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div class="section-icon" aria-hidden="true">⌕</div>
					<div>
						<h3>Search &amp; answer quality</h3>
						<p>These settings control how much indexed context EVA retrieves and how creative its answers are.</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<label for="top-k">Sources per answer</label>
						<input id="top-k" v-model="f.top_k" type="number" min="1" max="100" />
						<p class="field-help">Maximum number of relevant text snippets sent to the chat model. Default: 6.</p>
					</div>
					<div class="field">
						<label for="context-size">Model context size</label>
						<input id="context-size" v-model="f.context_size" type="number" min="256" max="131072" step="256" />
						<p class="field-help">Token window passed to Ollama. Match the model’s supported context size. Default: 12,288.</p>
					</div>
					<div class="field">
						<label for="temperature">Answer creativity</label>
						<input id="temperature" v-model="f.temperature" type="number" min="0" max="2" step="0.1" />
						<p class="field-help">0 is deterministic and factual; higher values are more varied. Default: 0.1.</p>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div class="section-icon" aria-hidden="true">▤</div>
					<div>
						<h3>Indexing &amp; scope</h3>
						<p>Define which files become part of EVA’s private knowledge base and how they are split for search.</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<label for="scope-path">Folder to index</label>
						<input id="scope-path" v-model.trim="f.scope_path" type="text" placeholder="Everything in Files" />
						<p class="field-help">Relative to your Files root. Empty indexes all files; for example use <code>Documents/Notes</code> for one folder.</p>
					</div>
					<div class="field">
						<label for="max-file-size">Maximum file size (MB)</label>
						<input id="max-file-size" v-model="maxFileSizeMb" type="number" min="1" max="2048" step="1" />
						<p class="field-help">Larger files are skipped during indexing. Default: 20 MB.</p>
					</div>
					<div class="field">
						<label for="max-files">Files per indexing run</label>
						<input id="max-files" v-model="f.max_files_per_run" type="number" min="1" max="10000" />
						<p class="field-help">Limits work per run so large accounts remain responsive. Default: 40.</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<label for="chunk-size">Chunk size (characters)</label>
						<input id="chunk-size" v-model="f.chunk_size" type="number" min="1" max="10000" />
						<p class="field-help">Target length of each searchable text section. Default: 900.</p>
					</div>
					<div class="field">
						<label for="chunk-overlap">Chunk overlap (characters)</label>
						<input id="chunk-overlap" v-model="f.chunk_overlap" type="number" min="1" max="5000" />
						<p class="field-help">Repeated context between sections so sentences are not split abruptly. Default: 120.</p>
					</div>
					<div class="field">
						<label for="mail-index-max">Emails per indexing run</label>
						<input id="mail-index-max" v-model="f.mail_index_max" type="number" min="1" max="10000" />
						<p class="field-help">Only used when Mail indexing is enabled. Default: 25.</p>
					</div>
				</div>
				<label class="switch-row compact-switch" for="mail-index-enabled">
					<input id="mail-index-enabled" v-model="f.mail_index_enabled" type="checkbox" true-value="1" false-value="0" />
					<span><strong>Index Mail messages</strong><small>Include subject, sender and message text from the Nextcloud Mail app in search results.</small></span>
				</label>

				<div class="exclude-paths">
					<div class="sub-heading"><strong>Excluded folders</strong><span>These folders and their subfolders are skipped.</span></div>
					<div v-if="excludeList.length" class="exclude-chips">
						<span v-for="(path, index) in excludeList" :key="path" class="exclude-chip">
							{{ path }}
							<button class="chip-remove" type="button" :aria-label="'Remove ' + path" @click="removeExclude(index)">×</button>
						</span>
					</div>
					<p v-else class="empty-help">No folders are excluded.</p>
					<div class="exclude-add-row">
						<input v-model="newExcludePath" type="text" placeholder="e.g. Photos or Documents/Archive" @keydown.enter.prevent="addExclude" />
						<NcButton type="secondary" :disabled="busy" @click="addExclude">Add folder</NcButton>
					</div>
					<p v-if="excludeError" class="inline-error" role="alert">{{ excludeError }}</p>
					<p class="field-help">Changes take effect the next time you start indexing. Use paths relative to your Files root.</p>
				</div>

				<div class="index-actions">
					<div>
						<strong>Apply indexing settings</strong>
						<p>Save first, then rebuild the knowledge base with the current scope.</p>
					</div>
					<div class="button-group">
						<NcButton type="primary" :loading="indexing" :disabled="busy" @click="startIndex">Save &amp; start indexing</NcButton>
						<NcButton type="tertiary-no-background" :disabled="busy" @click="resetConfirm = true">Delete index</NcButton>
					</div>
				</div>
				<div v-if="resetConfirm" class="confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="reset-title">
					<strong id="reset-title">Delete the complete index?</strong>
					<p>This removes indexed documents and vectors. Your original Nextcloud files stay untouched. You will need to start indexing again.</p>
					<div class="button-group">
						<NcButton type="tertiary-no-background" @click="resetConfirm = false">Cancel</NcButton>
						<NcButton type="primary" class="danger-button" :loading="resetting" @click="resetIndex">Delete index</NcButton>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div class="section-icon" aria-hidden="true">@</div>
					<div>
						<h3>Talk &amp; notifications</h3>
						<p>Configure how EVA behaves when she is used from Nextcloud Talk.</p>
					</div>
				</div>
				<div class="field-grid">
					<div class="field">
						<label for="talk-history">Talk history size</label>
						<input id="talk-history" v-model="f.talk_history_size" type="number" min="1" max="500" />
						<p class="field-help">Number of recent Talk messages sent as context. Default: 50.</p>
					</div>
					<div class="field">
						<label for="talk-trigger">Trigger name</label>
						<input id="talk-trigger" v-model.trim="f.talk_bot_trigger" type="text" placeholder="Eva" />
						<p class="field-help">The name people mention to address EVA in Talk. Example: <code>@Eva</code>.</p>
					</div>
				</div>
				<div class="help-box">
					<strong>Privacy reminder</strong>
					<span>Indexed content stays in Nextcloud and is sent to the Ollama server configured above. Review your indexing scope before enabling Mail or Talk features.</span>
				</div>
			</section>
		</main>
	</div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { api, errMsg } from '../lib/api'

export default {
	name: 'SettingsView',
	setup() {
		const f = ref({
			ollama_url: 'http://127.0.0.1:11434',
			embedding_model: 'nomic-embed-text',
			chat_model: 'gemma4:cloud',
			temperature: '0.1',
			actions_enabled: '1',
			notify_on_complete: '1',
			exec_write_types: '',
			exec_write_max_chars: '100000',
			exec_delete_mode: 'own',
			top_k: '6',
			context_size: '12288',
			chunk_size: '900',
			chunk_overlap: '120',
			max_file_size: '20971520',
			max_files_per_run: '40',
			mail_index_max: '25',
			mail_index_enabled: '1',
			scope_path: '',
			talk_history_size: '50',
			talk_bot_trigger: 'Eva',
			exclude_paths: '',
		})
		const status = ref(null)
		const checkOut = ref(null)
		const saving = ref(false)
		const checking = ref(false)
		const indexing = ref(false)
		const resetting = ref(false)
		const saved = ref(false)
		const loadError = ref('')
		const message = ref({ type: '', text: '' })
		const validationErrors = ref([])
		const resetConfirm = ref(false)
		const newExcludePath = ref('')
		const excludeError = ref('')

		const excludeList = computed(() => {
			const raw = (f.value.exclude_paths || '').trim()
			return raw ? raw.split(',').map(path => path.trim()).filter(Boolean) : []
		})
		const actionsDisabled = computed(() => f.value.actions_enabled !== '1')
		const busy = computed(() => saving.value || checking.value || indexing.value || resetting.value)
		const maxFileSizeMb = computed({
			get: () => {
				const bytes = Number(f.value.max_file_size) || 0
				return String(Math.max(1, Math.round(bytes / (1024 * 1024))) )
			},
			set: value => {
				const mb = Math.max(1, Math.min(2048, Number(value) || 1))
				f.value.max_file_size = String(Math.round(mb * 1024 * 1024))
			},
		})

		function formatNumber(value) {
			return Number(value || 0).toLocaleString()
		}

		function setMessage(type, text) {
			message.value = { type, text }
		}

		function validate() {
			const errors = []
			const numberRules = [
				['top_k', 'Sources per answer', 1, 100],
				['context_size', 'Model context size', 256, 131072],
				['temperature', 'Answer creativity', 0, 2],
				['chunk_size', 'Chunk size', 1, 10000],
				['chunk_overlap', 'Chunk overlap', 1, 5000],
				['max_files_per_run', 'Files per indexing run', 1, 10000],
				['mail_index_max', 'Emails per indexing run', 1, 10000],
				['talk_history_size', 'Talk history size', 1, 500],
				['exec_write_max_chars', 'Maximum characters per file', 1, 10000000],
			]
			if (!/^https?:\/\//i.test(f.value.ollama_url.trim())) errors.push('Ollama server URL must start with http:// or https://.')
			if (!f.value.embedding_model.trim()) errors.push('Embedding model is required.')
			if (!f.value.chat_model.trim()) errors.push('Chat model is required.')
			for (const [key, label, min, max] of numberRules) {
				const value = Number(f.value[key])
				if (!Number.isFinite(value) || value < min || value > max) errors.push(`${label} must be between ${min} and ${max}.`)
			}
			const fileSizeMb = Number(maxFileSizeMb.value)
			if (!Number.isFinite(fileSizeMb) || fileSizeMb < 1 || fileSizeMb > 2048) errors.push('Maximum file size must be between 1 and 2048 MB.')
			if (Number(f.value.chunk_overlap) > Number(f.value.chunk_size)) errors.push('Chunk overlap cannot be larger than chunk size.')
			return errors
		}

		function fill(settings = status.value?.settings) {
			if (!settings) return
			Object.keys(f.value).forEach(key => {
				if (settings[key] !== undefined && settings[key] !== null) {
					f.value[key] = String(settings[key])
				}
			})
		}

		async function loadStatus() {
			loadError.value = ''
			try {
				status.value = await api('GET', 'status')
				fill()
			} catch (error) {
				loadError.value = errMsg(error)
			}
		}

		async function save() {
			if (saving.value) return false
			validationErrors.value = validate()
			if (validationErrors.value.length) {
				setMessage('error', 'Please correct the highlighted settings before saving.')
				return false
			}
			saving.value = true
			saved.value = false
			message.value = { type: '', text: '' }
			try {
				const settings = await api('PUT', 'settings', { ...f.value })
				validationErrors.value = []
				fill(settings)
				saved.value = true
				setMessage('success', 'Your settings were saved.')
				window.setTimeout(() => { saved.value = false }, 3000)
				return true
			} catch (error) {
				setMessage('error', 'The settings could not be saved: ' + errMsg(error))
				return false
			} finally {
				saving.value = false
			}
		}

		async function checkOllama() {
			if (checking.value) return
			checking.value = true
			checkOut.value = null
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				checkOut.value = { type: 'error', lines: [{ label: 'Connection test', ok: false, detail: 'Correct the settings above before testing the connection.' }] }
				checking.value = false
				return
			}
			try {
				const data = await api('POST', 'check')
				const lines = []
				const server = data.server || {}
				lines.push({ label: 'Server', ok: !!server.ok, detail: server.ok ? (server.url || 'Reachable') : (server.error || 'Not reachable') })
				if (data.embedding) {
					const embedding = data.embedding
					lines.push({ label: 'Embedding model', ok: !!embedding.ok, detail: embedding.ok ? `${embedding.model} · ${embedding.len} dimensions` : `${embedding.model || f.value.embedding_model}: ${embedding.error || 'Not available'}` })
				}
				if (data.chat) {
					const chat = data.chat
					lines.push({ label: 'Chat model', ok: !!chat.ok, detail: chat.ok ? `${chat.model} · responded in ${chat.seconds}s` : `${chat.model || f.value.chat_model}: ${chat.error || 'Not available'}` })
				}
				checkOut.value = { type: lines.every(line => line.ok) ? 'success' : 'error', lines }
			} catch (error) {
				checkOut.value = { type: 'error', lines: [{ label: 'Connection test', ok: false, detail: errMsg(error) }] }
			} finally {
				checking.value = false
			}
		}

		function addExclude() {
			excludeError.value = ''
			const path = newExcludePath.value.trim().replace(/^\/+|\/+$/g, '')
			if (!path) {
				excludeError.value = 'Enter a folder path first.'
				return
			}
			if (path.split('/').some(part => part === '.' || part === '..')) {
				excludeError.value = 'Use a path inside your Files folder; relative traversal is not allowed.'
				return
			}
			const list = excludeList.value.slice()
			if (!list.includes(path)) list.push(path)
			f.value.exclude_paths = list.join(', ')
			newExcludePath.value = ''
		}

		function removeExclude(index) {
			const list = excludeList.value.slice()
			list.splice(index, 1)
			f.value.exclude_paths = list.join(', ')
		}

		async function startIndex() {
			if (busy.value) return
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				setMessage('error', 'Indexing was not started because the settings could not be saved.')
				return
			}
			indexing.value = true
			setMessage('info', 'Indexing is running. This can take a while for large file collections.')
			try {
				const response = await api('POST', 'index')
				const result = response.result || {}
				let text = `Indexing finished: ${formatNumber(result.processed)} processed, ${formatNumber(result.skipped)} skipped, ${formatNumber(result.total_seen)} found.`
				if (result.error) text += ` ${result.error}`
				setMessage(result.error ? 'error' : 'success', text)
				await loadStatus()
			} catch (error) {
				setMessage('error', 'Indexing failed: ' + errMsg(error))
			} finally {
				indexing.value = false
			}
		}

		async function resetIndex() {
			if (resetting.value) return
			resetConfirm.value = false
			resetting.value = true
			setMessage('info', 'Deleting the index…')
			try {
				const response = await api('POST', 'indexReset')
				const result = response.result || {}
				setMessage('success', `Index deleted: ${formatNumber(result.documents)} documents and ${formatNumber(result.chunks)} chunks removed.`)
				await loadStatus()
			} catch (error) {
				setMessage('error', 'The index could not be deleted: ' + errMsg(error))
			} finally {
				resetting.value = false
			}
		}

		onMounted(loadStatus)

		return {
			f, status, checkOut, saving, checking, indexing, resetting, saved, loadError, message, validationErrors, resetConfirm,
			newExcludePath, excludeError, excludeList, actionsDisabled, busy, maxFileSizeMb,
			formatNumber, loadStatus, save, checkOllama, addExclude, removeExclude, startIndex, resetIndex,
		}
	},
}
</script>

<style scoped lang="scss">
.settings-view {
	width: 100%;
	max-width: 1120px;
	margin: 0 auto;
	padding: 24px clamp(16px, 3vw, 36px) 48px;
	box-sizing: border-box;
}

.page-header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 24px;
	margin-bottom: 24px;
}

.header-copy { max-width: 700px; }
.eyebrow {
	margin: 0 0 4px;
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: .08em;
	text-transform: uppercase;
}
.settings-title { margin: 0; font-size: clamp(24px, 3vw, 32px); font-weight: 700; letter-spacing: -.02em; }
.page-intro { margin: 8px 0 0; color: var(--color-text-maxcontrast); font-size: 14px; line-height: 1.55; }
.header-actions { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.saved-label { color: var(--color-success); font-size: 13px; font-weight: 600; }

.summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.summary-card {
	display: flex; align-items: flex-start; gap: 12px; min-height: 76px; padding: 14px;
	border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-main-background);
	box-sizing: border-box;
}
.summary-card.is-ok { border-color: color-mix(in srgb, var(--color-success) 40%, var(--color-border)); }
.summary-card.is-working { border-color: color-mix(in srgb, var(--color-primary-element) 45%, var(--color-border)); }
.status-dot { width: 10px; height: 10px; margin-top: 5px; border-radius: 50%; background: var(--color-warning); flex: 0 0 auto; }
.is-ok .status-dot { background: var(--color-success); }
.summary-icon { width: 22px; color: var(--color-primary-element); font-size: 22px; line-height: 1; text-align: center; }
.summary-label { display: block; color: var(--color-text-maxcontrast); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.summary-card strong { display: block; margin-top: 3px; font-size: 15px; }
.summary-card small { display: block; margin-top: 4px; color: var(--color-text-maxcontrast); font-size: 12px; overflow-wrap: anywhere; }

.callout { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; margin: 0 0 16px; padding: 12px 14px; border: 1px solid var(--color-border); border-radius: 10px; font-size: 13px; }
.callout strong { font-weight: 700; }
.callout span { color: var(--color-text-maxcontrast); }
.callout-success { border-color: color-mix(in srgb, var(--color-success) 48%, var(--color-border)); background: color-mix(in srgb, var(--color-success) 8%, var(--color-main-background)); }
.callout-success strong { color: var(--color-success); }
.callout-error { border-color: color-mix(in srgb, var(--color-error) 50%, var(--color-border)); background: color-mix(in srgb, var(--color-error) 8%, var(--color-main-background)); }
.callout-error strong { color: var(--color-error); }
.callout-info { border-color: color-mix(in srgb, var(--color-primary-element) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-primary-element) 7%, var(--color-main-background)); }
.callout-info strong { color: var(--color-primary-element); }
.validation-summary ul { margin: 0; padding-left: 18px; color: var(--color-text-maxcontrast); }
.validation-summary li { margin: 2px 0; }

.settings-body { display: flex; flex-direction: column; gap: 16px; }
.settings-section { padding: 20px; border: 1px solid var(--color-border); border-radius: 14px; background: var(--color-main-background); box-shadow: 0 2px 8px rgb(0 0 0 / 3%); }
.section-heading { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 20px; }
.section-icon { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 10px; background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background)); color: var(--color-primary-element); font-size: 21px; flex: 0 0 auto; }
.section-heading h3 { margin: 0; font-size: 17px; }
.section-heading p { margin: 4px 0 0; color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.5; }
.field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 16px; }
.field-grid-three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.field-grid-wide { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.field-wide { grid-column: 1 / -1; }
.field { min-width: 0; }
.field label, .sub-heading strong { display: block; margin-bottom: 7px; font-size: 13px; font-weight: 650; }
.field input, .exclude-add-row input {
	width: 100%; min-height: 42px; padding: 9px 11px; box-sizing: border-box; border: 1px solid var(--color-border-dark, var(--color-border)); border-radius: 8px;
	background: var(--color-main-background); color: var(--color-main-text); font: inherit; font-size: 14px; transition: border-color .15s ease, box-shadow .15s ease;
}
.field input:focus, .exclude-add-row input:focus { border-color: var(--color-primary-element); box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary-element) 20%, transparent); outline: none; }
.field input:disabled { opacity: .55; cursor: not-allowed; }
.field-help { margin: 6px 0 0; color: var(--color-text-maxcontrast); font-size: 12px; line-height: 1.5; }
.field-help code, .help-box code { padding: 1px 4px; border-radius: 4px; background: var(--color-background-dark); font-family: var(--font-family-monospace, monospace); }
.inline-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
.action-hint { color: var(--color-text-maxcontrast); font-size: 12px; }

.check-panel { display: grid; gap: 8px; margin-top: 14px; padding: 12px; border: 1px solid var(--color-border); border-radius: 10px; }
.check-success { border-color: color-mix(in srgb, var(--color-success) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-success) 6%, var(--color-main-background)); }
.check-error { border-color: color-mix(in srgb, var(--color-error) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-error) 6%, var(--color-main-background)); }
.check-line { display: flex; gap: 9px; align-items: flex-start; font-size: 13px; }
.check-mark { display: grid; place-items: center; width: 18px; height: 18px; border-radius: 50%; background: var(--color-error); color: #fff; font-weight: 700; flex: 0 0 auto; }
.check-success .check-mark { background: var(--color-success); }
.check-line strong { display: block; }
.check-line div span { display: block; margin-top: 2px; color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }

.switch-row, .choice-row { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
.switch-row { padding: 12px; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-background-hover); }
.switch-row input, .choice-row input { margin-top: 3px; accent-color: var(--color-primary-element); flex: 0 0 auto; }
.switch-row strong, .choice-row strong { display: block; font-size: 14px; }
.switch-row small, .choice-row small { display: block; margin-top: 3px; color: var(--color-text-maxcontrast); font-size: 12px; line-height: 1.45; }
.warning-note { display: flex; flex-wrap: wrap; gap: 5px 9px; margin: 12px 0 16px; padding: 10px 12px; border-left: 3px solid var(--color-warning); background: color-mix(in srgb, var(--color-warning) 9%, var(--color-main-background)); font-size: 12px; }
.warning-note strong { color: var(--color-warning-text, var(--color-main-text)); }
.warning-note span { color: var(--color-text-maxcontrast); }
.warning-note.is-disabled { border-left-color: var(--color-text-maxcontrast); background: var(--color-background-hover); }
.is-disabled { opacity: .7; }
.choice-group { display: grid; gap: 8px; margin: 16px 0 0; padding: 14px; border: 1px solid var(--color-border); border-radius: 10px; }
.choice-group legend { padding: 0 5px; font-size: 13px; font-weight: 650; }
.choice-row { padding: 5px 0; }
.choice-danger strong { color: var(--color-error); }
.choice-row em { color: var(--color-success); font-size: 11px; font-style: normal; font-weight: 600; }
.compact-switch { margin-top: 16px; }

.exclude-paths { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--color-border); }
.sub-heading span { display: block; margin-top: -2px; color: var(--color-text-maxcontrast); font-size: 12px; }
.exclude-chips { display: flex; flex-wrap: wrap; gap: 7px; margin: 14px 0 8px; }
.exclude-chip { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 5px 8px 5px 10px; border: 1px solid var(--color-border); border-radius: 999px; background: var(--color-background-hover); font-size: 12px; overflow-wrap: anywhere; }
.chip-remove { width: 20px; height: 20px; padding: 0; border: 0; border-radius: 50%; background: transparent; color: var(--color-text-maxcontrast); cursor: pointer; font-size: 17px; line-height: 1; }
.chip-remove:hover, .chip-remove:focus { background: var(--color-error); color: #fff; }
.empty-help { margin: 14px 0 8px; color: var(--color-text-maxcontrast); font-size: 12px; }
.exclude-add-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.exclude-add-row input { flex: 1; min-width: 0; }
.inline-error { margin: 7px 0 0; color: var(--color-error); font-size: 12px; }

.index-actions { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-top: 24px; padding: 16px; border: 1px solid color-mix(in srgb, var(--color-primary-element) 28%, var(--color-border)); border-radius: 11px; background: color-mix(in srgb, var(--color-primary-element) 5%, var(--color-main-background)); }
.index-actions strong { font-size: 14px; }
.index-actions p { margin: 4px 0 0; color: var(--color-text-maxcontrast); font-size: 12px; }
.button-group { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
.confirm-panel { margin-top: 12px; padding: 14px; border: 1px solid var(--color-error); border-radius: 10px; background: color-mix(in srgb, var(--color-error) 7%, var(--color-main-background)); }
.confirm-panel strong { color: var(--color-error); }
.confirm-panel p { margin: 5px 0 12px; color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.5; }
.danger-button { --color-primary-element: var(--color-error); }
.help-box { display: flex; flex-wrap: wrap; gap: 6px 10px; margin-top: 18px; padding: 12px; border-radius: 9px; background: var(--color-background-hover); font-size: 12px; line-height: 1.5; }
.help-box strong { color: var(--color-primary-element); }
.help-box span { color: var(--color-text-maxcontrast); }

@media (max-width: 800px) {
	.page-header { align-items: flex-start; flex-direction: column; }
	.header-actions { width: 100%; justify-content: space-between; }
	.summary-grid, .field-grid-three, .field-grid-wide { grid-template-columns: 1fr; }
	.field-wide { grid-column: auto; }
	.index-actions { align-items: flex-start; flex-direction: column; }
	.button-group { justify-content: flex-start; width: 100%; }
}

@media (max-width: 500px) {
	.settings-view { padding: 18px 12px 36px; }
	.settings-section { padding: 15px; }
	.exclude-add-row { align-items: stretch; flex-direction: column; }
	.exclude-add-row :deep(button) { width: 100%; }
}
</style>
