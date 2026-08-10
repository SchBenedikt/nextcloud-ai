<template>
	<div class="settings-view">
		<div class="settings-header">
			<h2 class="settings-title">Settings</h2>
		</div>

		<div class="settings-body">
			<section class="settings-section">
				<h3>Ollama (local)</h3>
				<NcTextField
					v-model="f.ollama_url"
					:label="'Ollama URL'"
					:label-outside="true"
					:placeholder="'http://127.0.0.1:11434'" />
				<p class="field-hint">Address of the Ollama server. Default: http://127.0.0.1:11434 (same machine).</p>
				<div class="field-row">
					<NcTextField
						v-model="f.embedding_model"
						:label="'Embedding model'"
						:label-outside="true"
						:placeholder="'nomic-embed-text'" />
					<NcTextField
						v-model="f.chat_model"
						:label="'Chat model'"
						:label-outside="true"
						:placeholder="'gemma4:cloud'" />
				</div>
				<p class="field-hint">
					<strong>Embedding model</strong>: creates the vectors for your files (must be installed in Ollama).<br>
					<strong>Chat model</strong>: the LLM that writes the answers (also available in Ollama — "Check Ollama" shows what is installed).
				</p>
				<div class="checkout">
					<NcButton type="secondary" :loading="checking" @click="checkOllama">
						Check Ollama
					</NcButton>
					<pre v-if="checkOut" class="checkout-out">{{ checkOut }}</pre>
				</div>
			</section>

			<section class="settings-section">
				<h3>Eva Actions</h3>
				<label class="action-toggle">
					<input
						type="checkbox"
						v-model="f.actions_enabled"
						true-value="1"
						false-value="0" />
					<span>Allow Eva to create and manage files on my Nextcloud</span>
				</label>
				<p class="field-hint">
					When enabled, the chat model can create, read, rename, search and list files anywhere in your Nextcloud home,
					plus manage notes (folder <strong>Notes</strong>) and address book contacts.
				</p>

				<div class="field-row">
					<NcTextField
						v-model="f.exec_write_types"
						:label="'Allowed file types for writing'"
						:label-outside="true"
						:placeholder="'md,txt,csv (leave empty = any text file)'" />
					<NcTextField
						v-model="f.exec_write_max_chars"
						:label="'Max. characters per file'"
						:label-outside="true" />
				</div>
				<p class="field-hint">
					<strong>Allowed file types</strong>: comma-separated extensions Eva may create/overwrite, e.g. <code>md,txt,csv</code>.
					Empty means any plain-text file. <strong>Max. characters per file</strong>: size guard for Eva-written files.
				</p>

				<fieldset class="perm-group">
					<legend>Delete permission</legend>
					<label class="action-toggle">
						<input type="radio" v-model="f.exec_delete_mode" value="off" />
						<span>Off — Eva may never delete files</span>
					</label>
					<label class="action-toggle">
						<input type="radio" v-model="f.exec_delete_mode" value="own" />
						<span>Only files Eva created itself</span>
					</label>
					<label class="action-toggle">
						<input type="radio" v-model="f.exec_delete_mode" value="all" />
						<span>All my files (only when I explicitly ask)</span>
					</label>
				</fieldset>

				<label class="action-toggle notify-toggle">
					<input
						type="checkbox"
						v-model="f.notify_on_complete"
						true-value="1"
						false-value="0" />
					<span>Show a Nextcloud notification when an Eva answer is finished</span>
				</label>
			</section>

			<section class="settings-section">
				<h3>Search &amp; context</h3>
				<div class="field-row">
					<NcTextField
						v-model="f.top_k"
						:label="'Max. sources (top-K)'"
						:label-outside="true" />
					<NcTextField
						v-model="f.context_size"
						:label="'Context characters'"
						:label-outside="true" />
					<NcTextField
						v-model="f.temperature"
						:label="'Temperature'"
						:label-outside="true" />
				</div>
				<p class="field-hint">
					<strong>Max. sources (top-K)</strong>: how many text snippets from your files may influence the answer at most.
					Higher = more sources and better coverage, but longer prompt times (e.g. 12).<br>
					<strong>Context characters</strong>: context window allowed for the model (should match the model, e.g. 12288).<br>
					<strong>Temperature</strong>: creativity of the answers. 0 = very factual, 1 = creative (default 0.1).
				</p>
			</section>

			<section class="settings-section">
				<h3>Indexing</h3>
				<div class="field-row">
					<NcTextField
						v-model="f.chunk_size"
						:label="'Chunk size'"
						:label-outside="true" />
					<NcTextField
						v-model="f.chunk_overlap"
						:label="'Chunk overlap'"
						:label-outside="true" />
					<NcTextField
						v-model="f.max_files_per_run"
						:label="'Max. files per run'"
						:label-outside="true" />
				</div>
				<p class="field-hint">
					<strong>Chunk size</strong>: characters per text snippet when indexing (default 900).<br>
					<strong>Chunk overlap</strong>: overlap between neighbouring snippets so context is not split apart (default 120).<br>
					<strong>Max. files per run</strong>: how many files are processed per "Start indexing" (protects against very large folders).
				</p>
				<NcTextField
					v-model="f.scope_path"
					:label="'Only subfolder (empty = everything)'"
					:label-outside="true"
					:placeholder="'e.g. Documents/Notes'" />
				<p class="field-hint">Empty = all your files; otherwise a relative subfolder as shown in "Files" (e.g. Documents/Notes).</p>
				<p class="field-hint">
					<strong>Supported file types:</strong> Text/Markdown (.md, .txt), HTML, JSON/YAML/XML/TOML, CSV/TSV,
					RTF/LaTeX/BibTeX, SVG, source code (JS, TS, Vue, Python, PHP, C/C++, Java, …), log/conf files,
					Word (.docx/.docm/.dotx), Excel (.xlsx/.xlsm/.xltx), PowerPoint (.pptx/.pptm/.ppsx/.potx),
					OpenDocument (.odt, .ods, .odp), EPUB and PDF (PDF requires the tool "pdftotext" on the server).
				</p>
				<div class="field-row">
					<NcTextField v-model="f.mail_index_max" :label="'Max. emails per run'" :label-outside="true" />
				</div>
				<label class="action-toggle">
					<input
						type="checkbox"
						v-model="f.mail_index_enabled"
						true-value="1"
						false-value="0" />
					<span>Index emails from the Mail app (subject, sender, body)</span>
				</label>
				<div class="index-row">
					<NcButton type="secondary" @click="save">
						Save
					</NcButton>
					<NcButton type="primary" :loading="indexing" @click="startIndex">
						Start indexing
					</NcButton>
					<NcButton type="tertiary-no-background" :loading="resetting" @click="resetIndex">
						Delete index
					</NcButton>
				</div>
				<p v-if="progress" class="progress-text">{{ progress }}</p>
			</section>
		</div>
	</div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { api } from '../lib/api'

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
			max_files_per_run: '40',
			mail_index_max: '25',
			mail_index_enabled: '1',
			scope_path: '',
		})
		const status = ref(null)
		const checkOut = ref('')
		const checking = ref(false)
		const indexing = ref(false)
		const resetting = ref(false)
		const progress = ref('')
		const saved = ref(false)

		function fill() {
			const s = status.value
			if (!s) return
			if (s.settings) {
				Object.keys(f.value).forEach((k) => {
					if (s.settings[k] !== undefined) f.value[k] = s.settings[k]
				})
			}
		}

		async function loadStatus() {
			try {
				status.value = await api('GET', 'status')
				fill()
			} catch (e) {
				console.error('[eva-ai] settings status error', e)
			}
		}

		async function save() {
			try {
				await api('PUT', 'settings', { ...f.value })
				saved.value = true
				progress.value = 'Saved ✓'
				setTimeout(() => (saved.value = false), 3000)
			} catch (e) {
				progress.value = 'Saving failed: ' + e
			}
		}

		async function checkOllama() {
			checking.value = true
			checkOut.value = 'Checking Ollama …'
			try {
				const d = await api('POST', 'check')
				const lines = []
				const srv = d.server || {}
				lines.push(srv.ok ? '✓ Ollama server reachable (' + srv.url + ')' : '✗ Ollama server: ' + (srv.error || 'offline'))
				if (d.embedding) {
					const emb = d.embedding
					lines.push(emb.ok
						? '✓ Embedding "' + emb.model + '" OK (vector: ' + emb.len + ' dimensions)'
						: '✗ Embedding "' + emb.model + '" ERROR: ' + emb.error)
				}
				if (d.chat) {
					const ch = d.chat
					lines.push(ch.ok
						? '✓ Chat "' + ch.model + '" OK → "' + ch.answer + '" (' + ch.seconds + 's)'
						: '✗ Chat "' + ch.model + '" ERROR: ' + ch.error)
				}
				checkOut.value = lines.join('\n')
			} catch (e) {
				checkOut.value = 'Error during test: ' + e
			} finally {
				checking.value = false
			}
		}

		async function resetIndex() {
			if (!confirm('Delete index? All documents and vectors will be removed. The index has to be re-created afterwards.')) {
				return
			}
			resetting.value = true
			progress.value = 'Deleting index …'
			try {
				const r = await api('POST', 'indexReset')
				const res = r.result || {}
				progress.value = 'Index deleted: ' + res.documents + ' documents, ' + res.chunks + ' chunks removed.'
				await loadStatus()
			} catch (e) {
				progress.value = 'Reset failed: ' + e
			} finally {
				resetting.value = false
			}
		}

		async function startIndex() {
			indexing.value = true
			progress.value = 'Indexing running … (may take a while depending on your files)'
			try {
				await save()
				const r = await api('POST', 'index')
				const res = r.result || {}
				let msg = 'Processed: ' + res.processed + ' · Skipped: ' + res.skipped + ' · Found: ' + res.total_seen
				if (res.error) msg += ' · Error: ' + res.error
				progress.value = msg
				await loadStatus()
			} catch (e) {
				progress.value = 'Indexing failed: ' + e
			} finally {
				indexing.value = false
			}
		}

		onMounted(loadStatus)

		return {
			f,
			checkOut,
			checking,
			indexing,
			progress,
			save,
			checkOllama,
			startIndex,
			resetIndex,
		}
	},
}
</script>

<style scoped lang="scss">
.perm-group {
	margin: 12px 0 0;
	padding: 0;
	border: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.code-hint code,
.field-hint code {
	background: var(--color-background-dark, #eee);
	padding: 1px 5px;
	border-radius: 4px;
	font-family: var(--font-family-monospace, monospace);
}
.settings-view {
	padding: 16px 20px;
	box-sizing: border-box;
	width: 100%;
}

.settings-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.settings-title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.settings-body {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.settings-section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 16px;
}

.settings-section h3 {
	margin: 0 0 12px;
	font-size: 15px;
}

.action-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
	cursor: pointer;
	margin-bottom: 6px;
}

.action-toggle input {
	margin: 0;
}

.field-row {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.field-row > * {
	flex: 1;
	min-width: 180px;
}

.field-hint {
	margin: -4px 0 12px;
	font-size: 12px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}

.checkout {
	margin-top: 12px;
}

.checkout-out {
	margin-top: 8px;
	white-space: pre-wrap;
	font-size: 12px;
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 10px;
}

.index-row {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.progress-text {
	margin-top: 10px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>