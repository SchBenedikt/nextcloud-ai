<template>
	<div class="settings-view">
		<header class="page-header">
			<div class="header-copy">
				<p class="eyebrow">EVA AI</p>
				<h2 class="settings-title">{{ $t('Settings') }}</h2>
				<p class="page-intro">{{ $t('Control how EVA connects to Ollama, searches your files and is allowed to act in Nextcloud.') }}</p>
			</div>
			<div class="header-actions">
				<span v-if="saved" class="saved-label" role="status">{{ $t('Saved') }}</span>
				<span v-else class="saved-label" role="status">{{ $t('Changes save automatically') }}</span>
			</div>
		</header>

		<div v-if="loadError" class="callout callout-error" role="alert">
			<strong>{{ $t('Settings could not be loaded.') }}</strong>
			<span>{{ loadError }}</span>
			<NcButton type="tertiary-no-background" @click="loadStatus(true)">{{ $t('Try again') }}</NcButton>
		</div>

		<div class="summary-grid" :aria-label="$t('EVA status')">
			<div class="summary-card" :class="status && status.ollamaOnline ? 'is-ok' : 'is-muted'">
				<span class="status-dot" aria-hidden="true"></span>
				<div>
					<span class="summary-label">{{ $t('Ollama') }}</span>
					<strong>{{ status ? (status.ollamaOnline ? $t('Connected') : $t('Not connected')) : $t('Checking…') }}</strong>
					<small>{{ status?.ollamaError || status?.ollamaUrl || $t('Local AI server') }}</small>
				</div>
			</div>
			<div class="summary-card">
				<div>
					<span class="summary-label">{{ $t('Knowledge base') }}</span>
					<strong>{{ $t('{count} documents', { count: formatNumber(status?.documents) }) }}</strong>
					<small>{{ $t('{count} searchable text chunks', { count: formatNumber(status?.chunks) }) }}</small>
				</div>
			</div>
			<div class="summary-card" :class="status?.indexing ? 'is-working' : ''">
				<div>
					<span class="summary-label">{{ $t('Indexing') }}</span>
					<strong>{{ status?.indexing ? $t('In progress') : $t('Ready') }}</strong>
					<small>{{ status?.lastFinished ? $t('Last finished {time}', { time: status.lastFinished }) : $t('Run indexing after changing scope') }}</small>
				</div>
			</div>
		</div>

		<div v-if="message.text" class="callout" :class="'callout-' + message.type" :role="message.type === 'error' ? 'alert' : 'status'">
			<strong>{{ message.type === 'error' ? $t('Something went wrong') : message.type === 'success' ? $t('Done') : $t('Notice') }}</strong>
			<span>{{ message.text }}</span>
		</div>
		<div v-if="validationErrors.length" class="callout callout-error validation-summary" role="alert">
			<strong>{{ $t('Check these values') }}</strong>
			<ul><li v-for="error in validationErrors" :key="error">{{ error }}</li></ul>
		</div>

		<main class="settings-body">
			<div v-if="indexingActive" class="indexing-banner" role="status">
				<div>
					<strong>{{ status?.indexStopping ? $t('Stopping indexing…') : status?.indexMode === 'mail' ? $t('Email indexing is running') : $t('Indexing is running') }}</strong>
					<span>{{ $t('You can leave this page; the background job continues on the server. Settings stay locked until it finishes.') }}</span>
				</div>
				<NcButton type="secondary" :loading="stopping" :disabled="status?.indexStopping" @click="stopIndex">{{ $t('Stop indexing') }}</NcButton>
			</div>
			<fieldset class="settings-fieldset" :disabled="settingsLocked">
			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Connection & models') }}</h3>
						<p>{{ $t('Tell EVA where Ollama is running and which models should answer questions.') }}</p>
					</div>
				</div>

				<div class="field">
					<label class="native-label" for="chat-provider">{{ $t('Chat provider') }}</label>
					<select id="chat-provider" v-model="f.chat_provider" class="native-select">
						<option value="ollama">Ollama</option><option value="groq">Groq</option><option value="custom">{{ $t('Custom OpenAI-compatible provider') }}</option>
					</select>
				</div>
				<div v-if="f.chat_provider !== 'ollama' && f.chat_provider !== 'groq'" class="field-grid">
					<NcTextField id="custom-provider-id" v-model="f.chat_provider" :label="$t('Provider ID')" :label-outside="true" placeholder="openai" />
					<NcTextField id="custom-provider-url" v-model="f.custom_provider_url" type="url" :label="$t('OpenAI-compatible endpoint')" :label-outside="true" placeholder="https://api.openai.com/v1" />
					<NcTextField id="custom-provider-model" v-model="f.custom_provider_model" :label="$t('Model')" :label-outside="true" placeholder="gpt-4o-mini" />
					<NcTextField id="custom-provider-key" v-model="customProviderKey" type="password" autocomplete="new-password" :label="$t('Provider API key')" :label-outside="true" />
					<p class="field-help">{{ $t('Works with OpenAI, Azure OpenAI, Mistral, Together, DeepSeek, OpenRouter and any compatible self-hosted endpoint. Credentials are encrypted per user.') }}</p>
				</div>
				<div v-if="f.chat_provider === 'groq'" class="field-grid">
					<p class="field-help">{{ $t('Groq sends your messages, retrieved file excerpts and tool results to Groq. Embeddings and document indexing still use Ollama.') }}</p>
					<div class="field">
						<label class="native-label" for="groq-model">{{ $t('Groq free-plan model') }}</label>
						<select id="groq-model" v-model="f.groq_model" class="native-select">
							<option value="openai/gpt-oss-20b">GPT OSS 20B</option><option value="openai/gpt-oss-120b">GPT OSS 120B</option>
						</select>
					</div>
					<div class="field">
						<NcTextField id="groq-api-key" v-model="groqKey" type="password" autocomplete="new-password" :label="$t('Groq API key')" :label-outside="true" />
						<p class="field-help">{{ status?.groq?.keyConfigured ? $t('A key is saved. Leave blank to keep it.') : $t('Create a free Groq account and save your API key here.') }}</p>
						<NcCheckboxRadioSwitch v-model="removeGroqKey" type="switch">{{ $t('Remove saved Groq API key on save') }}</NcCheckboxRadioSwitch>
					</div>
					<p class="field-help">{{ $t('Free usage depends on your Groq account and quotas. No automatic paid-model fallback is used.') }} <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">{{ $t('Create API key') }}</a> · <a href="https://console.groq.com/docs/rate-limits" target="_blank" rel="noopener noreferrer">{{ $t('Groq limits') }}</a></p>
				</div>
				<div class="field-grid generic-api-settings">
					<div class="field field-wide">
						<NcTextField id="nextcloud-api-token" v-model="nextcloudApiToken" type="password" autocomplete="new-password" :label="$t('Nextcloud app token for background API actions')" :label-outside="true" />
						<p class="field-help">{{ status?.genericApi?.tokenConfigured ? $t('A token is saved. Leave blank to keep it.') : $t('Optional: create a Nextcloud app password to let scheduled EVA tasks call enabled app APIs without an open browser session. It is encrypted and never sent to the AI model.') }}</p>
						<NcCheckboxRadioSwitch v-model="removeNextcloudApiToken" type="switch">{{ $t('Remove saved Nextcloud app token on save') }}</NcCheckboxRadioSwitch>
					</div>
				</div>

				<div class="field-grid field-grid-wide">
					<div class="field field-wide">
						<NcTextField id="ollama-url" :label="$t('Ollama server URL')" :label-outside="true" v-model="f.ollama_url" type="url" :placeholder="$t('http://127.0.0.1:11434')" />
						<p class="field-help">{{ $t('The address of your Ollama HTTP API. The default works when Ollama runs on this same server.') }}</p>
					</div>
					<div class="field">
						<label class="native-label" for="embedding-model">{{ $t('Embedding model') }}</label>
						<select id="embedding-model" v-model="f.embedding_model" class="native-select" :disabled="modelLoading || !embeddingModels.length">
							<option v-if="!embeddingModels.length" :value="f.embedding_model">{{ modelLoading ? $t('Loading models…') : $t('No embedding model found') }}</option>
							<option v-for="model in embeddingModels" :key="model" :value="model">{{ model }}</option>
						</select>
						<p class="field-help">{{ $t('EVA discovers installed models automatically from the Ollama endpoint and separates embedding from chat models by their declared capabilities. Embedding models turn file text into searchable vectors.') }}</p>
						<div v-if="!modelLoading && embeddingInstalledHint" class="model-hint">{{ embeddingInstalledHint }}</div>
					</div>
					<div v-if="f.chat_provider !== 'groq'" class="field">
						<label class="native-label" for="chat-model">{{ $t('Chat model') }}</label>
						<select id="chat-model" v-model="f.chat_model" class="native-select" :disabled="modelLoading || !chatModels.length">
							<option v-if="!chatModels.length" :value="f.chat_model">{{ modelLoading ? $t('Loading models…') : $t('No chat model found') }}</option>
							<option v-for="model in chatModels" :key="model" :value="model">{{ model }}</option>
						</select>
						<p class="field-help">{{ $t('EVA discovers installed chat models automatically from the Ollama endpoint.') }}</p>
						<div v-if="!modelLoading && chatInstalledHint" class="model-hint">{{ chatInstalledHint }}</div>
					</div>
					<div v-if="f.chat_provider !== 'groq'" class="field">
						<label class="native-label" for="chat-model-fallback">{{ $t('Chat model fallbacks') }}</label>
						<NcTextField id="chat-model-fallback" :label-outside="true" v-model="f.chat_model_fallback" :placeholder="$t('Optional, comma-separated')" />
						<p class="field-help">{{ $t('If the chat model above is not installed, EVA tries these models in order before failing. (E.g. llama3.1, qwen2.5)') }}</p>
					</div>
					<div class="field">
						<label class="native-label" for="embedding-model-fallback">{{ $t('Embedding model fallbacks') }}</label>
						<NcTextField id="embedding-model-fallback" :label-outside="true" v-model="f.embedding_model_fallback" :placeholder="$t('Optional, comma-separated')" />
						<p class="field-help">{{ $t('If the embedding model above is not installed, EVA tries these models in order before failing.') }}</p>
					</div>
					<div v-if="f.chat_provider !== 'groq'" class="field">
						<label class="native-label" for="summary-model">{{ $t('Heavy task model (optional)') }}</label>
						<select id="summary-model" v-model="f.summary_model" class="native-select" :disabled="modelLoading">
							<option value="">{{ $t('Use the chat model') }}</option>
							<option v-for="model in chatModels" :key="model" :value="model">{{ model }}</option>
						</select>
						<p class="field-help">{{ $t('Optionally use a separate, usually larger model for summaries, translations and proofreading. Leave empty to reuse the chat model.') }}</p>
					</div>
				</div>
				<div class="inline-actions">
					<NcButton type="secondary" :loading="checking" :disabled="busy" @click="checkOllama">{{ $t('Check connection') }}</NcButton>
					<span v-if="modelError" class="action-hint action-error">{{ modelError }}</span>
					<span v-else-if="modelLoading" class="action-hint">{{ $t('Discovering models from Ollama…') }}</span>
					<span v-else class="action-hint">{{ $t('Models are loaded automatically from the configured endpoint.') }}</span>
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
					<div>
						<h3>{{ $t('Safety & actions') }}</h3>
						<p>{{ $t('Choose what EVA may do beyond answering questions. Recommended defaults are shown below.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="actionsEnabled" type="switch" class="native-toggle" :description="$t('Let EVA create, read, rename and search files, plus work with supported contacts and notes.')">{{ $t('Allow file actions') }}
					</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="backgroundActionsEnabled" type="switch" class="native-toggle" :disabled="actionsDisabled" :description="$t('When a chat continues after you close the page, EVA may execute requested changes without an open confirmation dialog.')">
					{{ $t('Allow background actions after I close the page') }}
				</NcCheckboxRadioSwitch>
				<div class="field" style="max-width: 360px; margin-top: 12px;">
					<NcTextField id="agent-max-tool-rounds" v-model="f.agent_max_tool_rounds" type="number" :label="$t('Maximum agent steps per request')" :label-outside="true" :disabled="actionsDisabled" />
					<p class="field-help">{{ $t('How many model/tool steps EVA may chain before it must summarize. Allowed range: 4–32; higher values help complex tasks but use more time and tokens.') }}</p>
				</div>
				<div class="warning-note" :class="{ 'is-disabled': actionsDisabled }">
					<strong>{{ actionsDisabled ? $t('Actions are disabled') : $t('Actions can change your files') }}</strong>
					<span>{{ actionsDisabled ? $t('The fields below are inactive until you enable file actions.') : $t('Complete, explicit requests run directly. EVA only asks when information is missing or a target is unclear.') }}</span>
				</div>

				<div class="field-grid" :class="{ 'is-disabled': actionsDisabled }">
					<div class="field">
						<NcTextField id="write-types" :label="$t('File types EVA may write')" :label-outside="true" v-model="f.exec_write_types" :disabled="actionsDisabled" :placeholder="$t('md, txt, csv')" />
						<p class="field-help">{{ $t('Comma-separated extensions, for example {types}. Empty means any supported text file.', { types: 'md, txt' }) }}</p>
					</div>
					<div class="field">
						<NcTextField id="write-max-chars" :label="$t('Maximum characters per file')" :label-outside="true" v-model="f.exec_write_max_chars" type="number" :disabled="actionsDisabled" />
						<p class="field-help">{{ $t('A size guard for files created or overwritten by EVA.') }}</p>
					</div>
				</div>

				<div class="choice-group" :class="{ 'is-disabled': actionsDisabled }">
					<strong class="choice-label">{{ $t('Delete permission') }}</strong>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="off" :disabled="actionsDisabled" :description="$t('EVA cannot delete files.')">
						{{ $t('Never') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="own" :disabled="actionsDisabled" :description="$t('EVA may delete files it created itself.')">
						{{ $t('Only EVA-created files') }} <em>{{ $t('Recommended') }}</em>
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="all" :disabled="actionsDisabled" class="choice-danger" :description="$t('Allows EVA to delete any file in your Files when you explicitly ask for it. Use with care.')">
						{{ $t('Any file in my Files') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcCheckboxRadioSwitch v-model="notificationsEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Uses Nextcloud Notifications when background or Talk work finishes.')">
					{{ $t('Notify me when a long answer is ready') }}
				</NcCheckboxRadioSwitch>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Search & answer quality') }}</h3>
						<p>{{ $t('These settings control how much indexed context EVA retrieves and how creative its answers are.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="top-k" :label="$t('Sources per answer')" :label-outside="true" v-model="f.top_k" type="number" />
						<p class="field-help">{{ $t('Maximum number of relevant text snippets sent to the chat model. Default: 6.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="context-size" :label="$t('Model context size')" :label-outside="true" v-model="f.context_size" type="number" />
						<p class="field-help">{{ $t('Token window passed to Ollama. Match the model’s supported context size. Default: 12,288.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="temperature" :label="$t('Answer creativity')" :label-outside="true" v-model="f.temperature" type="number" />
						<p class="field-help">{{ $t('0 is deterministic and factual; higher values are more varied. Default: 0.1.') }}</p>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Indexing & scope') }}</h3>
						<p>{{ $t('Define which files become part of EVA’s private knowledge base and how they are split for search.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="scope-path" :label="$t('Folder to index')" :label-outside="true" v-model="f.scope_path" :placeholder="$t('Everything in Files')" />
						<p class="field-help">{{ $t('Relative to your Files root. Empty indexes all files; for example use {folder} for one folder.', { folder: 'Documents/Notes' }) }}</p>
					</div>
					<div class="field">
						<NcTextField id="max-file-size" :label="$t('Maximum file size (MB)')" :label-outside="true" v-model="maxFileSizeMb" type="number" />
						<p class="field-help">{{ $t('Larger files are skipped during indexing. Default: 20 MB.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="max-files" :label="$t('Files per indexing run')" :label-outside="true" v-model="f.max_files_per_run" type="number" />
						<p class="field-help">{{ $t('Limits work per run so large accounts remain responsive. Default: 40.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="embed-batch" :label="$t('Embeddings per batch')" :label-outside="true" v-model="f.embed_batch_size" type="number" />
						<p class="field-help">{{ $t('Text chunks are embedded in batches to keep memory bounded. Default: 24.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="chunk-size" :label="$t('Chunk size (characters)')" :label-outside="true" v-model="f.chunk_size" type="number" />
						<p class="field-help">{{ $t('Target length of each searchable text section. Default: 900.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="chunk-overlap" :label="$t('Chunk overlap (characters)')" :label-outside="true" v-model="f.chunk_overlap" type="number" />
						<p class="field-help">{{ $t('Repeated context between sections so sentences are not split abruptly. Default: 120.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="mail-index-max" :label="$t('Emails per indexing run')" :label-outside="true" v-model="f.mail_index_max" type="number" />
						<p class="field-help">{{ $t('Only used when Mail indexing is enabled. Default: 25.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="talk-index-max-rooms" :label="$t('Nextcloud Talk chats per indexing run')" :label-outside="true" v-model="f.talk_index_max_rooms" type="number" />
						<p class="field-help">{{ $t('Only used when Talk indexing is enabled. Default: 20.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="talk-index-max-messages" :label="$t('Messages per Nextcloud Talk chat')" :label-outside="true" v-model="f.talk_index_max_messages" type="number" />
						<p class="field-help">{{ $t('How far back in each chat to index. Default: 200.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="ocrEnabled" type="switch" :disabled="busy">
					{{ $t('Read scanned documents with local OCR') }}
				</NcCheckboxRadioSwitch>
				<NcTextField v-if="ocrEnabled" v-model="f.ocr_language" :label="$t('OCR languages (for example eng or deu+eng)')" :disabled="busy" />
				<p v-if="ocrEnabled && !status?.dependencies?.tesseract" class="field-help">{{ $t('OCR is unavailable: ask your administrator to install Tesseract and its language data.') }}</p>
				<p v-if="ocrEnabled && (!status?.dependencies?.pdftoppm || !status?.dependencies?.pdfinfo)" class="field-help">{{ $t('Scanned PDFs also require Poppler (pdfinfo and pdftoppm).') }}</p>
				<p class="field-help">{{ $t('OCR limits: 20 MiB, 30 PDF pages, 25 megapixels and 60 seconds per file. Failed extraction keeps the previous index.') }}</p>
				<NcCheckboxRadioSwitch v-model="mailIndexEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Include subject, sender and message text from the Nextcloud Mail app in search results.')">{{ $t('Index Mail messages') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="talkIndexEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Include the chat histories of your Nextcloud Talk conversations in search results, so older parts of a conversation can be quoted in an answer. Only chats you are a member of are indexed, and only your own chat becomes context in a Talk answer.')">{{ $t('Index Nextcloud Talk chat histories') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="talkWriteEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Let EVA answer and write in your Nextcloud Talk conversations for you. A message posted this way appears under your name, exactly as if you had typed it, so EVA only posts when you explicitly ask it to. Enabling this also lets EVA read a chat on request.')">{{ $t('Let EVA post to Nextcloud Talk for me') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="indexEnrolled" type="switch" class="native-toggle compact-switch" :disabled="busy" :description="$t('Keep this account in the recurring background schedule, even when its index is currently empty. Starting indexing enables this automatically.')">{{ $t('Keep indexing this account in the background') }}
				</NcCheckboxRadioSwitch>

				<div class="exclude-paths">
					<div class="sub-heading"><strong>{{ $t('Excluded folders') }}</strong><span>{{ $t('These folders and their subfolders are skipped.') }}</span></div>
					<div v-if="excludeList.length" class="exclude-chips">
						<span v-for="(path, index) in excludeList" :key="path" class="exclude-chip">
							{{ path }}
							<NcButton class="chip-remove" type="tertiary-no-background" :aria-label="$t('Remove {path}', { path })" :disabled="busy" @click="removeExclude(index)">×</NcButton>
						</span>
					</div>
					<p v-else class="empty-help">{{ $t('No folders are excluded.') }}</p>
					<div class="exclude-add-row">
						<NcTextField v-model="newExcludePath" :label="$t('Folder path')" :label-outside="true" :placeholder="$t('e.g. Photos or Documents/Archive')" @keydown.enter.prevent="addExclude" />
						<NcButton type="secondary" :disabled="busy" @click="addExclude">{{ $t('Add folder') }}</NcButton>
					</div>
					<p v-if="excludeError" class="inline-error" role="alert">{{ excludeError }}</p>
					<p class="field-help">{{ $t('Changes take effect the next time you start indexing. Use paths relative to your Files root.') }}</p>
				</div>

				<div class="index-actions">
					<div>
						<strong>{{ $t('Apply indexing settings') }}</strong>
						<p>{{ $t('Save first, then rebuild the knowledge base with the current scope.') }}</p>
					</div>
					<div class="button-group">
						<NcButton type="primary" :loading="indexing" :disabled="settingsLocked" @click="startIndex">{{ $t('Save & start indexing') }}</NcButton>
						<NcButton type="secondary" :disabled="settingsLocked" @click="startMailIndex">{{ $t('Only index emails') }}</NcButton>
						<NcButton type="secondary" :disabled="settingsLocked" @click="startTalkIndex">{{ $t('Only index Nextcloud Talk chats') }}</NcButton>
						<NcButton type="tertiary-no-background" :disabled="settingsLocked" @click="resetConfirm = true">{{ $t('Delete index') }}</NcButton>
					</div>
				</div>
				<div v-if="resetConfirm" class="confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="reset-title">
					<strong id="reset-title">{{ $t('Delete the complete index?') }}</strong>
					<p>{{ $t('This removes indexed documents and vectors. Your original Nextcloud files stay untouched. You will need to start indexing again.') }}</p>
					<div class="button-group">
						<NcButton type="tertiary-no-background" @click="resetConfirm = false">{{ $t('Cancel') }}</NcButton>
						<NcButton type="primary" class="danger-button" :loading="resetting" @click="resetIndex">{{ $t('Delete index') }}</NcButton>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Chat history') }}</h3>
						<p>{{ $t('Manage the conversations stored for your Nextcloud account. This does not affect indexed files.') }}</p>
					</div>
				</div>
				<div class="field">
					<label class="native-label" for="chat-retention">{{ $t('Automatically delete old chats') }}</label>
					<select id="chat-retention" v-model="f.chat_retention_days" class="native-select">
						<option value="0">{{ $t('Never delete automatically') }}</option>
						<option value="7">{{ $t('After 7 days') }}</option>
						<option value="14">{{ $t('After 14 days') }}</option>
						<option value="30">{{ $t('After 30 days') }}</option>
						<option value="60">{{ $t('After 60 days') }}</option>
						<option value="90">{{ $t('After 90 days') }}</option>
						<option value="180">{{ $t('After 180 days') }}</option>
						<option value="365">{{ $t('After 365 days') }}</option>
					</select>
					<p class="field-help">{{ $t('Chats that have not been used for this many days are deleted automatically by the background job. 0 keeps everything.') }}</p>
				</div>
				<div class="index-actions chat-history-actions">
					<div>
						<strong>{{ $t('Delete all chats') }}</strong>
						<p>{{ $t('Permanently removes your saved EVA conversations and messages.') }}</p>
					</div>
					<NcButton type="tertiary-no-background" :disabled="settingsLocked" @click="chatsDeleteConfirm = true">{{ $t('Delete all chats') }}</NcButton>
				</div>
				<div v-if="chatsDeleteConfirm" class="confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="chats-delete-title">
					<strong id="chats-delete-title">{{ $t('Delete all chat history?') }}</strong>
					<p>{{ $t('This cannot be undone. Your indexed documents and files will remain untouched.') }}</p>
					<div class="button-group">
						<NcButton type="tertiary-no-background" @click="chatsDeleteConfirm = false">{{ $t('Cancel') }}</NcButton>
						<NcButton type="primary" class="danger-button" :loading="deletingChats" @click="deleteAllChats">{{ $t('Delete all chats') }}</NcButton>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Personal knowledge') }}</h3>
						<p>{{ $t('Edit the facts EVA remembers about you. This file is read before every answer to personalise responses.') }}</p>
					</div>
				</div>
				<div class="field field-wide">
					<textarea
						v-model="knowledgeContent"
						class="knowledge-editor"
						:placeholder="$t('No knowledge file yet. EVA will create one with your profile on first use.')"
						rows="12"
						:disabled="settingsLocked"
					></textarea>
					<p class="field-help">
						{{ $t('{count} of {max} characters', { count: formatNumber(knowledgeContent.length), max: '60,000' }) }}
					</p>
				</div>
				<div class="inline-actions">
					<NcButton type="primary" :loading="savingKnowledge" :disabled="settingsLocked || knowledgeContent === knowledgeOriginal" @click="saveKnowledgeContent">
						{{ $t('Save knowledge') }}
					</NcButton>
					<span v-if="knowledgeSaved" class="action-hint" style="color: var(--color-success);">{{ $t('Saved') }}</span>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Talk & notifications') }}</h3>
						<p>{{ $t('Configure how EVA behaves when she is used from Nextcloud Talk.') }}</p>
					</div>
				</div>
				<div class="field-grid">
					<div class="field">
						<NcTextField id="talk-history" :label="$t('Talk history size')" :label-outside="true" v-model="f.talk_history_size" type="number" />
						<p class="field-help">{{ $t('Number of recent Talk messages sent as context. Default: 50.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="talk-trigger" :label="$t('Trigger name')" :label-outside="true" v-model="f.talk_bot_trigger" :placeholder="$t('Eva')" />
						<p class="field-help">{{ $t('The name people mention to address EVA in Talk. Example: {mention}.', { mention: '@Eva' }) }}</p>
					</div>
				</div>
				<div class="help-box">
					<strong>{{ $t('Privacy reminder') }}</strong>
					<span>{{ $t('Indexed content stays in Nextcloud and is sent to the Ollama server configured above. Review your indexing scope before enabling Mail or Talk features.') }}</span>
				</div>
				<div class="admin-subsection">
					<div class="briefing-heading"><div><h4>{{ $t('Scheduled briefings') }}</h4><p>{{ $t('EVA can prepare recurring answers and deliver them in your Nextcloud notifications. They are read-only unless you explicitly enable actions per briefing.') }}</p></div><span class="briefing-count">{{ proactiveBriefings.length }} / 20</span></div>
					<NcCheckboxRadioSwitch v-model="proactiveEnabled" type="switch" class="native-toggle">{{ $t('Let EVA send scheduled notifications') }}</NcCheckboxRadioSwitch>
					<div v-if="proactiveEnabled" class="briefing-note"><strong>{{ $t('How this works') }}</strong><span>{{ $t('Briefings use your server cron and account timezone. Every briefing is read-only by default. If you enable actions on one briefing, EVA may perform the requested changes automatically and reports the result in the notification.') }}</span></div>
					<div v-if="proactiveEnabled" class="briefing-editor">
						<div v-if="!proactiveBriefings.length" class="briefing-empty"><strong>{{ $t('No briefings yet') }}</strong><span>{{ $t('Add your first briefing below, for example a morning calendar summary or a weekly file digest.') }}</span></div>
						<div v-for="briefing in proactiveBriefings" :key="briefing.id" class="briefing-card">
							<div class="briefing-card-top"><div class="briefing-time"><span>{{ briefing.time }}</span><small>{{ briefing.days.map(dayName).join(' · ') }}</small></div><div class="briefing-card-actions"><button type="button" class="briefing-toggle" :class="{ active: briefing.enabled !== false }" :aria-label="$t('Toggle briefing')" @click="toggleBriefing(briefing.id)"><span></span></button><NcButton type="tertiary-no-background" @click="toggleBriefingActions(briefing.id)">{{ briefing.allow_actions ? $t('Disable actions') : $t('Enable actions') }}</NcButton><NcButton type="tertiary-no-background" @click="removeBriefing(briefing.id)">{{ $t('Remove') }}</NcButton></div></div>
							<p class="briefing-prompt">{{ briefing.prompt }}</p><small class="briefing-next">{{ briefing.enabled === false ? $t('Paused') : $t('Runs on {days} at {time}', { days: briefing.days.map(dayName).join(', '), time: briefing.time }) }} <span v-if="briefing.allow_actions" class="briefing-action-badge">{{ $t('Actions enabled') }}</span></small>
						</div>
						<div class="briefing-form"><div class="briefing-form-title"><strong>{{ $t('Create a briefing') }}</strong><span>{{ $t('Choose what EVA should prepare and when you want it.') }}</span></div>
						<div class="field-grid">
							<div class="field field-wide"><NcTextField v-model="briefingDraft.prompt" :label="$t('What should EVA do?')" :label-outside="true" :placeholder="$t('For example: summarize my calendar for today')" /><p class="field-help">{{ $t('Write a question or instruction in plain language. You can ask about files, calendar, tasks or your knowledge base.') }}</p></div>
							<NcTextField v-model="briefingDraft.time" type="time" :label="$t('Time')" :label-outside="true" />
						</div>
						<NcCheckboxRadioSwitch v-model="briefingDraft.allow_actions" type="switch">{{ $t('Allow EVA to perform requested actions automatically') }}</NcCheckboxRadioSwitch>
						<p v-if="briefingDraft.allow_actions" class="field-help briefing-action-warning">{{ $t('Use only for prompts you trust. EVA will execute needed changes in the background without a second dialog; generic app APIs still need the encrypted Nextcloud app token.') }}</p>
						<div><span class="native-label">{{ $t('Repeat on') }}</span><div class="weekday-picker"><label v-for="day in weekdays" :key="day.value" :class="{ selected: briefingDraft.days.includes(day.value) }"><input v-model="briefingDraft.days" type="checkbox" :value="day.value" /> <span>{{ day.label }}</span></label></div></div>
						<div class="briefing-form-actions"><NcButton type="primary" :disabled="!briefingDraft.prompt.trim() || !briefingDraft.days.length" @click="addBriefing">{{ $t('Add briefing') }}</NcButton><span>{{ $t('{count} slots remaining', { count: Math.max(0, 20 - proactiveBriefings.length) }) }}</span></div></div>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Web search') }}</h3>
						<p>{{ $t('Let EVA search the web when your indexed files cannot answer a question. DuckDuckGo works out of the box with no API key.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="userWebSearchEnabled" type="switch" class="native-toggle" :description="$t('Web search sends your question to an external search engine. The model only uses it as a fallback when your indexed files cannot answer.')">
					{{ $t('Enable web search') }}
				</NcCheckboxRadioSwitch>
				<div v-if="userWebSearchEnabled" class="admin-subsection">
					<div class="field">
						<label class="native-label" for="user-web-search-provider">{{ $t('Search provider') }}</label>
						<select id="user-web-search-provider" v-model="f.web_search_provider" class="native-select">
							<option value="duckduckgo">{{ $t('DuckDuckGo (free, no API key)') }}</option>
							<option value="bing">{{ $t('Bing (free, no API key)') }}</option>
							<option value="searxng">{{ $t('SearxNG (self-hosted)') }}</option>
							<option value="brave">{{ $t('Brave Search (requires admin setup)') }}</option>
							<option value="tavily">{{ $t('Tavily (requires admin setup)') }}</option>
						</select>
						<p class="field-help">{{ $t('News articles come from free news feeds and work with every provider; the web index is what differs. SearxNG, Brave and Tavily require the administrator to configure the URL or API key in the Eva AI admin settings.') }}</p>
					</div>
					<div class="field-grid field-grid-three">
						<div class="field"><NcTextField id="user-web-max" v-model="f.web_search_max_results" type="number" :label="$t('Results per search')" :label-outside="true" /><p class="field-help">{{ $t('How many search results are considered.') }}</p></div>
						<div class="field"><NcTextField id="user-web-timeout" v-model="f.web_search_timeout" type="number" :label="$t('Search timeout (seconds)')" :label-outside="true" /><p class="field-help">{{ $t('Maximum time for a provider request.') }}</p></div>
						<div class="field"><NcTextField id="user-web-candidates" v-model="f.web_search_candidates" type="number" :label="$t('Pages compared')" :label-outside="true" /><p class="field-help">{{ $t('Candidate pages read before ranking.') }}</p></div>
						<div class="field"><NcTextField id="user-web-content" v-model="f.web_search_content_chars" type="number" :label="$t('Search content limit')" :label-outside="true" /><p class="field-help">{{ $t('Maximum text returned per search result. Opening a page reads the full page.') }}</p></div>
						<div class="field"><NcTextField id="user-web-browser-timeout" v-model="f.web_search_browser_timeout" type="number" :label="$t('Browser timeout (seconds)')" :label-outside="true" /><p class="field-help">{{ $t('Maximum time for a rendered page.') }}</p></div>
					</div>
					<NcCheckboxRadioSwitch v-model="userWebSearchFetchContent" type="switch" class="native-toggle compact-switch">{{ $t('Read page content during searches') }}</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="userWebSearchImages" type="switch" class="native-toggle compact-switch">{{ $t('Collect and show images') }}</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="userWebSearchSafeSearch" type="switch" class="native-toggle compact-switch">{{ $t('Safe search') }}</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="userWebSearchBrowser" type="switch" class="native-toggle compact-switch">{{ $t('Use the full browser for JavaScript pages') }}</NcCheckboxRadioSwitch>
				</div>
			</section>

			<section v-if="isAdminMode" class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Instance-wide settings') }}</h3>
						<p>{{ $t('These settings apply to all users. Web search provider selection is per-user above.') }}</p>
					</div>
				</div>
				<div class="admin-subsection">
					<NcCheckboxRadioSwitch v-model="admin.weather_tool_enabled" type="switch" class="native-toggle compact-switch" :disabled="savingAdmin">
						{{ $t('Allow weather forecasts for all users') }}
					</NcCheckboxRadioSwitch>
					<p class="field-help">{{ $t('Weather uses the external Open-Meteo geocoding and forecast service. This is an instance-wide privacy switch.') }}</p>
					<p class="field-help" style="margin-bottom:12px;">{{ $t('Instance-level web search infrastructure: configure the SearxNG URL, API keys for Brave/Tavily, and result limits below. Individual users choose their provider in the Web search section above.') }}</p>
					<div v-if="admin.web_search_provider === 'searxng' || true" class="field">
						<NcTextField id="web-search-url" v-model="admin.web_search_url" type="url" :label="$t('SearxNG base URL')" :label-outside="true" :disabled="savingAdmin" :placeholder="$t('https://searx.example.org')" />
						<p class="field-help">{{ $t('Required when users choose SearxNG as their provider.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="web-search-key" v-model="webSearchKey" @blur="saveAdminSettings" type="password" autocomplete="new-password" :label="$t('Brave / Tavily API key')" :label-outside="true" :disabled="savingAdmin" :placeholder="webSearchKeyStored ? $t('A key is stored - leave empty to keep it') : $t('Paste the API key')" />
						<p class="field-help">{{ $t('Required when users choose Brave or Tavily. Stored encrypted, never shown.') }}</p>
					</div>
					<NcCheckboxRadioSwitch v-model="removeWebSearchKey" type="checkbox" :disabled="savingAdmin">
						{{ $t('Remove the stored API key') }}
					</NcCheckboxRadioSwitch>
					<p class="field-help">{{ $t('Result limits, page reading, safe search, images and browser rendering are now personal settings above. This section only contains shared provider infrastructure.') }}</p>
				</div>
				<p class="field-help auto-save-note">{{ $t('Instance settings save automatically.') }}</p>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Privacy & data') }}</h3>
						<p>{{ $t('Export everything EVA stores about you. Sensitive values are redacted before anything is saved.') }}</p>
					</div>
				</div>
				<div class="index-actions">
					<div>
						<strong>{{ $t('Download my data') }}</strong>
						<p>{{ $t('Your chats, personal knowledge and a metadata list of indexed documents as one JSON file (GDPR export).') }}</p>
					</div>
					<NcButton type="secondary" :disabled="exporting" :loading="exporting" @click="downloadExport">{{ $t('Download') }}</NcButton>
				</div>
			</section>
			</fieldset>
		</main>
	</div>
</template>

<script>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

export default {
	name: 'SettingsView',
	components: { NcCheckboxRadioSwitch },
	setup() {
		const f = ref({
			chat_provider: 'ollama',
			groq_model: 'openai/gpt-oss-20b',
			custom_provider_url: '',
			custom_provider_model: '',
			ollama_url: 'http://127.0.0.1:11434',
			embedding_model: 'nomic-embed-text',
			chat_model: 'gemma4:cloud',
			chat_model_fallback: '',
			embedding_model_fallback: '',
			summary_model: '',
			temperature: '0.1',
			actions_enabled: '1',
			background_actions_enabled: '0',
			agent_max_tool_rounds: '16',
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
			embed_batch_size: '24',
			mail_index_max: '25',
			mail_index_enabled: '1',
			talk_index_enabled: '0',
			talk_write_enabled: '0',
			talk_index_max_rooms: '20',
			talk_index_max_messages: '200',
			index_enrolled: '0',
			scope_path: '',
			talk_history_size: '50',
			talk_bot_trigger: 'Eva',
			exclude_paths: '',
			ocr_enabled: '0',
			ocr_language: 'eng',
			chat_retention_days: '0',
			web_search_enabled: '0',
			web_search_provider: 'duckduckgo',
			web_search_max_results: '8',
			web_search_timeout: '10',
			web_search_safe_search: '1',
			web_search_fetch_content: '1',
			web_search_content_chars: '8000',
			web_search_candidates: '12',
			web_search_images: '1',
			web_search_browser: '1',
			web_search_browser_timeout: '30',
			proactive_enabled: '0',
			proactive_schedules: '[]',
		})
		const groqKey = ref('')
		const customProviderKey = ref('')
		const nextcloudApiToken = ref('')
		const removeGroqKey = ref(false)
		const removeNextcloudApiToken = ref(false)
		const status = ref(null)
		const limits = ref({})
		const availableModels = ref([])
		const modelRoles = ref({})
		const modelLoading = ref(false)
		const modelError = ref('')
		const checkOut = ref(null)
		const saving = ref(false)
		const checking = ref(false)
		const indexing = ref(false)
		const resetting = ref(false)
		const deletingChats = ref(false)
		const stopping = ref(false)
		const saved = ref(false)
		const loadError = ref('')
		const message = ref({ type: '', text: '' })
		const validationErrors = ref([])
		// Keep the last server-confirmed form state. Autosave sends only values that
		// changed since then, so a broken or incomplete unrelated field cannot stop
		// a user from enabling a browser, images, or another independent tool.
		const persistedSettings = ref({})
		const resetConfirm = ref(false)
		const chatsDeleteConfirm = ref(false)
		const newExcludePath = ref('')
		const excludeError = ref('')

		const excludeList = computed(() => {
			const raw = (f.value.exclude_paths || '').trim()
			return raw ? raw.split(',').map(path => path.trim()).filter(Boolean) : []
		})
		const actionsEnabled = computed({
			get: () => f.value.actions_enabled === '1',
			set: value => { f.value.actions_enabled = value ? '1' : '0' },
		})
		const backgroundActionsEnabled = computed({
			get: () => f.value.background_actions_enabled === '1',
			set: value => { f.value.background_actions_enabled = value ? '1' : '0' },
		})
		const notificationsEnabled = computed({
			get: () => f.value.notify_on_complete === '1',
			set: value => { f.value.notify_on_complete = value ? '1' : '0' },
		})
		const proactiveEnabled = computed({
			get: () => f.value.proactive_enabled === '1',
			set: value => { f.value.proactive_enabled = value ? '1' : '0' },
		})
		const weekdays = [
			{ value: 1, label: t('Mon') }, { value: 2, label: t('Tue') }, { value: 3, label: t('Wed') },
			{ value: 4, label: t('Thu') }, { value: 5, label: t('Fri') }, { value: 6, label: t('Sat') }, { value: 7, label: t('Sun') },
		]
		const briefingDraft = ref({ prompt: '', time: '08:00', days: [1, 2, 3, 4, 5], allow_actions: false })
		const proactiveBriefings = computed(() => {
			try { const rows = JSON.parse(f.value.proactive_schedules || '[]'); return Array.isArray(rows) ? rows : [] } catch (_) { return [] }
		})
		const dayName = day => (weekdays.find(item => item.value === Number(day)) || {}).label || String(day)
		function writeBriefings(rows) { f.value.proactive_schedules = JSON.stringify(rows.slice(0, 20)) }
		function addBriefing() {
			const prompt = briefingDraft.value.prompt.trim()
			if (!prompt || !/^([01]\\d|2[0-3]):[0-5]\\d$/.test(briefingDraft.value.time) || !briefingDraft.value.days.length) return
			writeBriefings([...proactiveBriefings.value, { id: `briefing-${Date.now()}`, prompt, time: briefingDraft.value.time, days: [...new Set(briefingDraft.value.days)].sort(), enabled: true, allow_actions: briefingDraft.value.allow_actions === true }])
			briefingDraft.value.prompt = ''
		}
		function removeBriefing(id) { writeBriefings(proactiveBriefings.value.filter(item => item.id !== id)) }
		function toggleBriefing(id) { writeBriefings(proactiveBriefings.value.map(item => item.id === id ? { ...item, enabled: item.enabled === false } : item)) }
		function toggleBriefingActions(id) { writeBriefings(proactiveBriefings.value.map(item => item.id === id ? { ...item, allow_actions: item.allow_actions !== true } : item)) }
		const userWebSearchEnabled = computed({
			get: () => f.value.web_search_enabled === '1',
			set: value => { f.value.web_search_enabled = value ? '1' : '0' },
		})
		const userWebSearchImages = computed({ get: () => f.value.web_search_images === '1', set: v => { f.value.web_search_images = v ? '1' : '0' } })
		const userWebSearchBrowser = computed({ get: () => f.value.web_search_browser === '1', set: v => { f.value.web_search_browser = v ? '1' : '0' } })
		const userWebSearchFetchContent = computed({ get: () => f.value.web_search_fetch_content === '1', set: v => { f.value.web_search_fetch_content = v ? '1' : '0' } })
		const userWebSearchSafeSearch = computed({ get: () => f.value.web_search_safe_search === '1', set: v => { f.value.web_search_safe_search = v ? '1' : '0' } })
		// Admin settings form (Issue #82/#187): the same bundle is mounted inside
		// the Nextcloud admin settings with data-admin="1". Only shared provider
		// infrastructure is loaded and saved through the admin endpoint; tool
		// permissions and search behavior remain personal settings.
		const isAdminMode = (() => {
			const rootEl = document.getElementById('eva_ai-root')
			return !!(rootEl && rootEl.dataset && rootEl.dataset.admin === '1')
		})()
		const admin = ref({
			weather_tool_enabled: '1',
			web_search_url: '',
		})
		const webSearchKey = ref('')
		const removeWebSearchKey = ref(false)
		const webSearchKeyStored = ref(false)
		const webSearchReady = ref(false)
			const savingAdmin = ref(false)
			const formReady = ref(false)
			const adminReady = ref(false)
			let autoSaveTimer = null
			let adminAutoSaveTimer = null
			let autoSaveDirty = false
			let adminAutoSaveDirty = false
			let ignoreNextFormChange = false
			let ignoreNextAdminChange = false
		function fillAdmin(data) {
			if (!data || typeof data !== 'object') return
			if (adminReady.value) ignoreNextAdminChange = true
			Object.keys(admin.value).forEach(key => {
				if (data[key] !== undefined && data[key] !== null) admin.value[key] = String(data[key])
			})
			webSearchKeyStored.value = !!data.web_search_key_configured
			webSearchReady.value = !!data.web_search_configured
		}

		async function loadAdminSettings() {
			if (!isAdminMode) return
			try {
				fillAdmin(await api('GET', 'admin/settings'))
			} catch (error) {
				setMessage('error', t('The instance-wide settings could not be loaded: {error}', { error: errMsg(error) }))
			}
		}

		async function saveAdminSettings() {
			if (savingAdmin.value) return false
			adminAutoSaveDirty = false
			savingAdmin.value = true
			try {
				const payload = { ...admin.value, remove_web_search_api_key: removeWebSearchKey.value }
				if (webSearchKey.value) payload.web_search_api_key = webSearchKey.value
				fillAdmin(await api('PUT', 'admin/settings', payload))
				webSearchKey.value = ''
				removeWebSearchKey.value = false
				setMessage('success', t('Instance-wide settings were saved.'))
				return true
			} catch (error) {
				setMessage('error', t('The instance-wide settings could not be saved: {error}', { error: errMsg(error) }))
				return false
			} finally {
				savingAdmin.value = false
			}
		}

		function changedSettingKeys() {
			return Object.keys(f.value).filter(key => String(f.value[key]) !== String(persistedSettings.value[key] ?? ''))
		}

		function queueAutoSave() {
			if (ignoreNextFormChange) {
				ignoreNextFormChange = false
				return
			}
			if (!formReady.value) return
			autoSaveDirty = true
			window.clearTimeout(autoSaveTimer)
			autoSaveTimer = window.setTimeout(async () => {
				if (!autoSaveDirty) return
				if (settingsLocked.value) {
					queueAutoSave()
					return
				}
				autoSaveDirty = false
				await save({ changedOnly: true })
			}, 700)
		}

		function queueAdminAutoSave() {
			if (ignoreNextAdminChange) {
				ignoreNextAdminChange = false
				return
			}
			if (!adminReady.value) return
			adminAutoSaveDirty = true
			window.clearTimeout(adminAutoSaveTimer)
			adminAutoSaveTimer = window.setTimeout(() => {
				if (!adminAutoSaveDirty) return
				if (savingAdmin.value) {
					queueAdminAutoSave()
					return
				}
				saveAdminSettings()
			}, 700)
		}
		const mailIndexEnabled = computed({
			get: () => f.value.mail_index_enabled === '1',
			set: value => { f.value.mail_index_enabled = value ? '1' : '0' },
		})
		const talkIndexEnabled = computed({
			get: () => f.value.talk_index_enabled === '1',
			set: value => { f.value.talk_index_enabled = value ? '1' : '0' },
		})
		const talkWriteEnabled = computed({
			get: () => f.value.talk_write_enabled === '1',
			set: value => { f.value.talk_write_enabled = value ? '1' : '0' },
		})
		const indexEnrolled = computed({
			get: () => f.value.index_enrolled === '1',
			set: value => { f.value.index_enrolled = value ? '1' : '0' },
		})
		const actionsDisabled = computed(() => f.value.actions_enabled !== '1')
		// Role classification first comes from the provider capability metadata
		// (status.provider.roles, Issue #148); the name regex only kicks in for
		// older Ollama servers that do not report capabilities.
		const rolesOf = (name) => {
			const roles = (modelRoles.value[name] || {}).roles
			return Array.isArray(roles) ? roles : []
		}
		const EMBEDDING_NAME = /embed|bge|e5|gte|jina|minilm|nomic|snowflake|mxbai|arctic|retriev|instructor|rerank/i
		const embeddingModels = computed(() => {
			const fromRoles = availableModels.value.filter((name) => rolesOf(name).includes('embedding'))
			if (fromRoles.length || (availableModels.value.length && Object.keys(modelRoles.value).length)) return fromRoles
			return availableModels.value.filter((name) => EMBEDDING_NAME.test(name))
		})
		const chatModels = computed(() => {
			const fromRoles = availableModels.value.filter((name) => rolesOf(name).includes('chat'))
			if (fromRoles.length || (availableModels.value.length && Object.keys(modelRoles.value).length)) return fromRoles
			return availableModels.value.filter((name) => !EMBEDDING_NAME.test(name))
		})
		const providerRoles = computed(() => status.value?.provider?.roles || {})
		const installedHintFor = (configured, role) => {
			if (!configured) return ''
			const provider = status.value?.provider
			if (!provider || provider.online === false) return ''
			const key = role === 'embedding' ? 'embeddingModel' : 'chatModel'
			if (provider[key] && provider[key].usedFallback) {
				return t('{configured} is not installed - using {resolved} instead.', { configured, resolved: provider[key].resolved })
			}
			const listed = Object.keys(providerRoles.value).some((name) => name === configured || name.replace(/:latest$/, '') === configured)
			if (!listed) {
				return t('{model} is not installed on this Ollama endpoint yet.', { model: configured })
			}
			const roles = providerRoles.value[Object.keys(providerRoles.value).find((name) => name === configured || name.replace(/:latest$/, '') === configured)]?.roles || []
			if (roles.length && !roles.includes(role)) {
				return t('{model} is installed but is not usable as the {role} model.', { model: configured, role })
			}
			return ''
		}
		const embeddingInstalledHint = computed(() => installedHintFor(f.value.embedding_model, 'embedding'))
		const chatInstalledHint = computed(() => installedHintFor(f.value.chat_model, 'chat'))
		const indexingActive = computed(() => indexing.value || status.value?.indexing === true)
		const busy = computed(() => saving.value || checking.value || indexing.value || resetting.value || deletingChats.value || stopping.value)
		const settingsLocked = computed(() => busy.value || indexingActive.value)
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

		function validate(keys = null) {
			const errors = []
			const includes = key => keys === null || keys.includes(key)
			const effective = (key, fallback) => limits.value[key] || fallback
			const numberRules = [
				['top_k', 'Sources per answer', ...effective('top_k', [1, 8])],
				['context_size', 'Model context size', ...effective('context_size', [256, 131072])],
				['temperature', 'Answer creativity', ...effective('temperature', [0, 2])],
				['chunk_size', 'Chunk size', ...effective('chunk_size', [128, 10000])],
				['chunk_overlap', 'Chunk overlap', ...effective('chunk_overlap', [0, 5000])],
				['max_files_per_run', 'Files per indexing run', ...effective('max_files_per_run', [1, 10000])],
				['embed_batch_size', 'Embeddings per batch', ...effective('embed_batch_size', [1, 200])],
				['mail_index_max', 'Emails per indexing run', ...effective('mail_index_max', [1, 500])],
				['talk_index_max_rooms', 'Chats per indexing run', ...effective('talk_index_max_rooms', [1, 200])],
				['talk_index_max_messages', 'Messages per chat', ...effective('talk_index_max_messages', [10, 1000])],
				['talk_history_size', 'Talk history size', ...effective('talk_history_size', [1, 500])],
				['exec_write_max_chars', 'Maximum characters per file', ...effective('exec_write_max_chars', [1, 10000000])],
			]
			if (includes('ollama_url') && !/^https?:\/\//i.test(f.value.ollama_url.trim())) errors.push('Ollama server URL must start with http:// or https://.')
			if (includes('embedding_model') && !f.value.embedding_model.trim()) errors.push('Embedding model is required.')
			if ((includes('chat_provider') || includes('chat_model')) && f.value.chat_provider !== 'groq' && !f.value.chat_model.trim()) errors.push('Chat model is required.')
			for (const [key, label, min, max] of numberRules) {
				if (!includes(key)) continue
				const value = Number(f.value[key])
				if (!Number.isFinite(value) || value < min || value > max) errors.push(`${label} must be between ${min} and ${max}.`)
			}
			const fileSizeMb = Number(maxFileSizeMb.value)
			if (includes('max_file_size') && (!Number.isFinite(fileSizeMb) || fileSizeMb < 1 || fileSizeMb > 2048)) errors.push('Maximum file size must be between 1 and 2048 MB.')
			if ((includes('chunk_overlap') || includes('chunk_size')) && Number(f.value.chunk_overlap) > Number(f.value.chunk_size)) errors.push('Chunk overlap cannot be larger than chunk size.')
			return errors
		}

		function applyModelDiscovery(names, roles = {}) {
			availableModels.value = [...new Set((names || []).map((name) => String(name || '').trim()).filter(Boolean))]
			if (roles && typeof roles === 'object') modelRoles.value = roles
			const embeddings = embeddingModels.value
			const chats = chatModels.value
			if (embeddings.length && !embeddings.includes(f.value.embedding_model)) {
				f.value.embedding_model = embeddings[0]
			}
			if (chats.length && !chats.includes(f.value.chat_model)) {
				f.value.chat_model = chats[0]
			}
		}

		async function discoverModels(endpoint = f.value.ollama_url) {
			const url = String(endpoint || '').trim()
			if (!/^https?:\/\//i.test(url)) return
			modelLoading.value = true
			modelError.value = ''
			try {
				const data = await api('GET', 'models', { endpoint: url })
					applyModelDiscovery(data?.models || [], data?.roles || {})
					if (!availableModels.value.length) modelError.value = t('No models are installed in this Ollama endpoint.')
				} catch (error) {
					modelError.value = t('Models could not be loaded: {error}', { error: errMsg(error) })
				} finally {
					modelLoading.value = false
				}
			}

		function fill(settings = status.value?.settings) {
			if (!settings) return
			if (formReady.value) ignoreNextFormChange = true
			Object.keys(f.value).forEach(key => {
				if (settings[key] !== undefined && settings[key] !== null) {
					f.value[key] = String(settings[key])
					persistedSettings.value[key] = String(settings[key])
				}
			})
		}

		async function loadStatus(syncForm = false) {
			loadError.value = ''
			try {
				status.value = await api('GET', 'status')
				limits.value = status.value?.limits || {}
				if (syncForm) fill()
				if (Array.isArray(status.value?.models)) {
					applyModelDiscovery(status.value.models, status.value?.provider?.roles || {})
				}
				if (syncForm) await discoverModels(f.value.ollama_url)
			} catch (error) {
				loadError.value = errMsg(error)
			}
		}

		async function save({ changedOnly = false } = {}) {
			if (saving.value) return false
			const keys = changedOnly ? changedSettingKeys() : Object.keys(f.value)
			if (keys.length === 0 && !groqKey.value && !removeGroqKey.value && !nextcloudApiToken.value && !removeNextcloudApiToken.value) return true
			validationErrors.value = validate(changedOnly ? keys : null)
			if (validationErrors.value.length) {
				setMessage('error', t('Please correct the highlighted settings before saving.'))
				return false
			}
			saving.value = true
			saved.value = false
			message.value = { type: '', text: '' }
			try {
			const values = changedOnly
				? Object.fromEntries(keys.map(key => [key, f.value[key]]))
				: { ...f.value }
				const settings = await api('PUT', 'settings', { ...values, ...(groqKey.value ? { groq_api_key: groqKey.value } : {}), ...(customProviderKey.value ? { custom_provider_api_key: customProviderKey.value } : {}), ...(nextcloudApiToken.value ? { nextcloud_api_token: nextcloudApiToken.value } : {}), remove_groq_api_key: removeGroqKey.value, remove_nextcloud_api_token: removeNextcloudApiToken.value })
				if (status.value && (groqKey.value || removeGroqKey.value)) status.value.groq = { ...(status.value.groq || {}), keyConfigured: !removeGroqKey.value }
				if (status.value && (nextcloudApiToken.value || removeNextcloudApiToken.value)) status.value.genericApi = { ...(status.value.genericApi || {}), tokenConfigured: !removeNextcloudApiToken.value }
				groqKey.value = ''
				customProviderKey.value = ''
				nextcloudApiToken.value = ''
				removeGroqKey.value = false
				removeNextcloudApiToken.value = false
				validationErrors.value = []
				if (settings) fill(settings)
				saved.value = true
				setMessage('success', t('Your settings were saved.'))
				window.setTimeout(() => { saved.value = false }, 3000)
				return true
			} catch (error) {
				setMessage('error', t('The settings could not be saved: {error}', { error: errMsg(error) }))
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
				checkOut.value = { type: 'error', lines: [{ label: t('Connection test'), ok: false, detail: t('Correct the settings above before testing the connection.') }] }
				checking.value = false
				return
			}
			try {
				const data = await api('POST', 'check')
				if (data.provider === 'groq') {
					checkOut.value = { type: data.groq.ok ? 'success' : 'error', lines: [{ label: 'Groq', ok: data.groq.ok, detail: data.groq.ok ? t('Connected') : data.groq.error }] }
					return
				}
				const lines = []
				const server = data.server || {}
				lines.push({ label: t('Server'), ok: !!server.ok, detail: server.ok ? (server.url || t('Reachable')) : (server.error || t('Not reachable')) })
				if (data.embedding) {
					const embedding = data.embedding
					lines.push({ label: t('Embedding model'), ok: !!embedding.ok, detail: embedding.ok ? t('{model} · {count} dimensions', { model: embedding.model, count: embedding.len }) : `${embedding.model || f.value.embedding_model}: ${embedding.error || t('Not available')}` })
				}
				if (data.chat) {
					const chat = data.chat
					lines.push({ label: t('Chat model'), ok: !!chat.ok, detail: chat.ok ? t('{model} · responded in {seconds}s', { model: chat.model, seconds: chat.seconds }) : `${chat.model || f.value.chat_model}: ${chat.error || t('Not available')}` })
				}
				checkOut.value = { type: lines.every(line => line.ok) ? 'success' : 'error', lines }
			} catch (error) {
				checkOut.value = { type: 'error', lines: [{ label: t('Connection test'), ok: false, detail: errMsg(error) }] }
			} finally {
				checking.value = false
			}
		}

		async function persistExcludeList(list, previous) {
			f.value.exclude_paths = list.join(', ')
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				f.value.exclude_paths = previous
				excludeError.value = t('The folder exclusion could not be saved. Your previous exclusions were restored.')
				return false
			}
			return true
		}

		async function addExclude() {
			excludeError.value = ''
			const path = newExcludePath.value.trim().replace(/^\/+|\/+$/g, '')
			if (!path) {
				excludeError.value = t('Enter a folder path first.')
				return
			}
			if (path.split('/').some(part => part === '.' || part === '..')) {
				excludeError.value = t('Use a path inside your Files folder; relative traversal is not allowed.')
				return
			}
			const list = excludeList.value.slice()
			if (list.includes(path)) {
				excludeError.value = t('This folder is already excluded.')
				return
			}
			const previous = f.value.exclude_paths
			list.push(path)
			if (await persistExcludeList(list, previous)) newExcludePath.value = ''
		}

		async function removeExclude(index) {
			excludeError.value = ''
			const list = excludeList.value.slice()
			list.splice(index, 1)
			await persistExcludeList(list, f.value.exclude_paths)
		}

		async function deleteAllChats() {
			if (deletingChats.value) return
			chatsDeleteConfirm.value = false
			deletingChats.value = true
			setMessage('info', t('Deleting all chat history…'))
			try {
				const response = await api('DELETE', 'chats')
				window.dispatchEvent(new CustomEvent('eva-ai:chats-cleared'))
				setMessage('success', t('{count} chats deleted.', { count: formatNumber(response?.deleted) }))
			} catch (error) {
				setMessage('error', t('The chat history could not be deleted: {error}', { error: errMsg(error) }))
			} finally {
				deletingChats.value = false
			}
		}

		async function startIndex() {
			if (settingsLocked.value) return
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				setMessage('error', t('Indexing was not started because the settings could not be saved.'))
				return
			}
			indexing.value = true
			setMessage('info', t('Indexing is running. This can take a while for large file collections.'))
			try {
				const response = await api('POST', 'index')
				status.value = response?.status || status.value
				setMessage('info', t('Indexing was queued in the background. You can leave this page safely.'))
			} catch (error) {
				setMessage('error', t('Indexing could not be queued: {error}', { error: errMsg(error) }))
			} finally {
				indexing.value = false
			}
		}

		async function startMailIndex() {
			if (settingsLocked.value) return
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				setMessage('error', t('Email indexing was not started because the settings could not be saved.'))
				return
			}
			indexing.value = true
			setMessage('info', t('Email indexing is being queued in the background.'))
			try {
				const response = await api('POST', 'mailIndex')
				status.value = response?.status || status.value
				setMessage('info', t('Email indexing was queued. You can leave this page safely.'))
			} catch (error) {
				setMessage('error', t('Email indexing could not be queued: {error}', { error: errMsg(error) }))
			} finally {
				indexing.value = false
			}
		}

		async function startTalkIndex() {
			if (settingsLocked.value) return
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				setMessage('error', t('Chat indexing was not started because the settings could not be saved.'))
				return
			}
			indexing.value = true
			setMessage('info', t('Indexing your Nextcloud Talk chat histories is being queued in the background.'))
			try {
				const response = await api('POST', 'talkIndex')
				status.value = response?.status || status.value
				setMessage('info', t('Chat indexing was queued. You can leave this page safely.'))
			} catch (error) {
				setMessage('error', t('Chat indexing could not be queued: {error}', { error: errMsg(error) }))
			} finally {
				indexing.value = false
			}
		}

		async function stopIndex() {
			if (stopping.value) return
			stopping.value = true
			try {
				const response = await api('POST', 'indexStop')
				status.value = response?.status || status.value
				setMessage('info', response?.stopping ? t('Stop requested. Indexing will finish the current cancellable request and then release its lock.') : t('Indexing is stopped.'))
			} catch (error) {
				setMessage('error', t('Indexing could not be stopped: {error}', { error: errMsg(error) }))
			} finally {
				stopping.value = false
				await loadStatus()
			}
		}

		async function resetIndex() {
			if (resetting.value) return
			resetConfirm.value = false
			resetting.value = true
			setMessage('info', t('Deleting the index…'))
			try {
				const response = await api('POST', 'indexReset')
				const result = response.result || {}
				setMessage('success', t('Index deleted: {documents} documents and {chunks} chunks removed.', { documents: formatNumber(result.documents), chunks: formatNumber(result.chunks) }))
				await loadStatus()
			} catch (error) {
				setMessage('error', t('The index could not be deleted: {error}', { error: errMsg(error) }))
			} finally {
				resetting.value = false
			}
		}

		const exporting = ref(false)
		const knowledgeContent = ref('')
		const knowledgeOriginal = ref('')
		const savingKnowledge = ref(false)
		const knowledgeSaved = ref(false)

		async function loadKnowledge() {
			try {
				const data = await api('GET', 'knowledge')
				knowledgeContent.value = data?.content || ''
				knowledgeOriginal.value = knowledgeContent.value
			} catch (e) {
				// Silently fail - knowledge is optional
			}
		}

		async function saveKnowledgeContent() {
			if (savingKnowledge.value) return
			savingKnowledge.value = true
			try {
				await api('PUT', 'knowledge', { content: knowledgeContent.value })
				knowledgeOriginal.value = knowledgeContent.value
				knowledgeSaved.value = true
				setMessage('success', t('Your personal knowledge was saved.'))
				window.setTimeout(() => { knowledgeSaved.value = false }, 3000)
			} catch (error) {
				setMessage('error', t('Could not save knowledge: {error}', { error: errMsg(error) }))
			} finally {
				savingKnowledge.value = false
			}
		}

		async function downloadExport() {
			if (exporting.value) return
			exporting.value = true
			setMessage('info', t('Preparing your data export…'))
			try {
				const data = await api('GET', 'export')
				const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
				const url = URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = 'eva_ai_export_' + new Date().toISOString().slice(0, 10) + '.json'
				document.body.appendChild(link)
				link.click()
				link.remove()
				URL.revokeObjectURL(url)
				setMessage('success', t('Your data export was downloaded.'))
			} catch (error) {
				setMessage('error', t('The data export could not be created: {error}', { error: errMsg(error) }))
			} finally {
				exporting.value = false
			}
		}

		let statusTimer = null
		let modelTimer = null
		watch(f, queueAutoSave, { deep: true })
		watch(admin, queueAdminAutoSave, { deep: true })
		watch(() => f.value.ollama_url, (value) => {
			window.clearTimeout(modelTimer)
			modelTimer = window.setTimeout(() => discoverModels(value), 500)
		})
		onMounted(async () => {
			await loadStatus(true)
			await loadAdminSettings()
			await loadKnowledge()
			formReady.value = true
			adminReady.value = isAdminMode
			statusTimer = window.setInterval(loadStatus, 3000)
		})
		onUnmounted(() => {
			if (statusTimer !== null) window.clearInterval(statusTimer)
			if (modelTimer !== null) window.clearTimeout(modelTimer)
			window.clearTimeout(autoSaveTimer)
			window.clearTimeout(adminAutoSaveTimer)
		})

		const ocrEnabled = computed({ get: () => f.value.ocr_enabled === '1', set: value => { f.value.ocr_enabled = value ? '1' : '0' } })
		return {
			groqKey, customProviderKey, removeGroqKey, ocrEnabled, f, status, limits, availableModels, embeddingModels, chatModels, embeddingInstalledHint, chatInstalledHint, modelLoading, modelError, checkOut, saving, checking, indexing, resetting, deletingChats, stopping, saved, loadError, message, validationErrors, resetConfirm, chatsDeleteConfirm,
			newExcludePath, excludeError, excludeList, actionsEnabled, backgroundActionsEnabled, notificationsEnabled, mailIndexEnabled, talkIndexEnabled, talkWriteEnabled, indexEnrolled, actionsDisabled, busy, indexingActive, settingsLocked, maxFileSizeMb,
			isAdminMode, admin, userWebSearchEnabled, userWebSearchSafeSearch, userWebSearchImages, userWebSearchBrowser, userWebSearchFetchContent, webSearchKey, removeWebSearchKey, webSearchKeyStored, webSearchReady, savingAdmin, saveAdminSettings, loadAdminSettings,
			proactiveEnabled, proactiveBriefings, briefingDraft, weekdays, dayName, addBriefing, removeBriefing, toggleBriefing, toggleBriefingActions,
			exporting, downloadExport,
			knowledgeContent, knowledgeOriginal, savingKnowledge, knowledgeSaved, saveKnowledgeContent,
			formatNumber, loadStatus, save, checkOllama, addExclude, removeExclude, startIndex, startMailIndex, startTalkIndex, stopIndex, resetIndex, deleteAllChats,
		}
	},
}
</script>

<style scoped lang="scss">
.settings-view {
	width: 100%;
	max-width: var(--eva-content-width, 1180px);
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
.settings-fieldset { min-inline-size: 0; margin: 0; padding: 0; border: 0; }
.settings-fieldset:disabled { opacity: .72; }
.indexing-banner { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 4px; padding: 13px 14px; border: 1px solid color-mix(in srgb, var(--color-primary-element) 42%, var(--color-border)); background: color-mix(in srgb, var(--color-primary-element) 7%, var(--color-main-background)); }
.indexing-banner strong, .indexing-banner span { display: block; }
.indexing-banner span { margin-top: 3px; color: var(--color-text-maxcontrast); font-size: 12px; }
.settings-section { padding: 20px 0; border-bottom: 1px solid var(--color-border); }
.settings-section:first-child { padding-top: 0; }
.settings-section:last-child { border-bottom: 0; }
.section-heading { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 20px; }
.section-heading h3 { margin: 0; font-size: 17px; }
.section-heading p { margin: 4px 0 0; color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.5; }
.field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 16px; }
.field-grid-three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.field-grid-wide { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.field-wide { grid-column: 1 / -1; }
.field { min-width: 0; }
.native-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
.native-select { width: 100%; min-height: 42px; padding: 8px 34px 8px 10px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large, 8px); background: var(--color-main-background); color: var(--color-main-text); font: inherit; }
.native-select:focus { border-color: var(--color-primary-element); outline: 2px solid color-mix(in srgb, var(--color-primary-element) 25%, transparent); outline-offset: 1px; }
.native-select:disabled { opacity: .65; }
.sub-heading strong { display: block; margin-bottom: 4px; font-size: 14px; font-weight: 600; }
.field-help { margin: 6px 0 0; color: var(--color-text-maxcontrast); font-size: 12px; line-height: 1.5; }
.field-help code, .help-box code { padding: 1px 4px; border-radius: 4px; background: var(--color-background-dark); font-family: var(--font-family-monospace, monospace); }
.inline-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
.action-hint { color: var(--color-text-maxcontrast); font-size: 12px; }
.action-error { color: var(--color-error); }
.model-hint { margin: 6px 0 0; font-size: 12px; color: var(--color-warning, #d4a72c); line-height: 1.5; }

.check-panel { display: grid; gap: 8px; margin-top: 14px; padding: 12px; border: 1px solid var(--color-border); border-radius: 10px; }
.check-success { border-color: color-mix(in srgb, var(--color-success) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-success) 6%, var(--color-main-background)); }
.check-error { border-color: color-mix(in srgb, var(--color-error) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-error) 6%, var(--color-main-background)); }
.check-line { display: flex; gap: 9px; align-items: flex-start; font-size: 13px; }
.check-mark { display: grid; place-items: center; width: 18px; height: 18px; border-radius: 50%; background: var(--color-error); color: #fff; font-weight: 700; flex: 0 0 auto; }
.check-success .check-mark { background: var(--color-success); }
.check-line strong { display: block; }
.check-line div span { display: block; margin-top: 2px; color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }

.native-toggle { display: flex; margin-top: 8px; }
.native-toggle :deep(.checkbox-radio-switch__text) { font-weight: 600; }
.native-toggle :deep(.checkbox-content__description) { color: var(--color-text-maxcontrast); font-size: 12px; line-height: 1.45; }
.warning-note { display: flex; flex-wrap: wrap; gap: 5px 9px; margin: 12px 0 16px; padding: 10px 12px; border-left: 3px solid var(--color-warning); background: color-mix(in srgb, var(--color-warning) 9%, var(--color-main-background)); font-size: 12px; }
.warning-note strong { color: var(--color-warning-text, var(--color-main-text)); }
.warning-note span { color: var(--color-text-maxcontrast); }
.warning-note.is-disabled { border-left-color: var(--color-text-maxcontrast); background: var(--color-background-hover); }
.is-disabled { opacity: .7; }
.choice-group { display: grid; gap: 8px; margin: 18px 0 0; padding: 0; }
.choice-label { font-size: 14px; font-weight: 600; }
.choice-danger :deep(.checkbox-radio-switch__text) { color: var(--color-error); }
.choice-group em { color: var(--color-success); font-size: 11px; font-style: normal; font-weight: 600; }
.compact-switch { margin-top: 18px; }
.admin-subsection { margin: 16px 0 4px; padding: 14px 16px; border: 1px solid var(--color-border); border-radius: 10px; background: var(--color-background-hover); }
.admin-subsection .field + .field { margin-top: 14px; }
.briefing-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
.briefing-heading h4 { margin:0; font-size:15px; }
.briefing-heading p { margin:4px 0 0; color:var(--color-text-maxcontrast); font-size:12px; line-height:1.5; }
.briefing-count { padding:4px 9px; border-radius:999px; background:var(--color-main-background); color:var(--color-text-maxcontrast); font-size:11px; font-weight:700; white-space:nowrap; }
.briefing-note { display:flex; gap:8px; margin:16px 0; padding:11px 12px; border-left:3px solid var(--color-primary-element); background:color-mix(in srgb,var(--color-primary-element) 8%,var(--color-main-background)); font-size:12px; line-height:1.5; }
.briefing-note strong { color:var(--color-primary-element); white-space:nowrap; }
.briefing-note span { color:var(--color-text-maxcontrast); }
.briefing-editor { display:grid; gap:10px; margin-top:16px; }
.briefing-empty { display:flex; flex-direction:column; gap:3px; padding:18px; border:1px dashed var(--color-border); border-radius:10px; text-align:center; color:var(--color-text-maxcontrast); font-size:12px; }
.briefing-empty strong { color:var(--color-main-text); font-size:13px; }
.briefing-card { padding:14px; border:1px solid var(--color-border); border-radius:11px; background:var(--color-main-background); box-shadow:0 1px 2px color-mix(in srgb,var(--color-main-text) 5%,transparent); }
.briefing-card-top,.briefing-card-actions,.briefing-form-actions { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.briefing-time { display:flex; align-items:baseline; gap:10px; }
.briefing-time span { font-size:20px; font-weight:750; letter-spacing:-.03em; }
.briefing-time small,.briefing-next { color:var(--color-text-maxcontrast); font-size:11px; }
.briefing-prompt { margin:10px 0 5px; font-size:14px; line-height:1.45; }
.briefing-toggle { width:36px; height:21px; padding:2px; border:0; border-radius:999px; background:var(--color-border); cursor:pointer; }
.briefing-toggle span { display:block; width:17px; height:17px; border-radius:50%; background:var(--color-main-background); transition:transform .15s; }
.briefing-toggle.active { background:var(--color-primary-element); }
.briefing-toggle.active span { transform:translateX(15px); }
.briefing-form { display:grid; gap:14px; margin-top:6px; padding:16px; border:1px solid color-mix(in srgb,var(--color-primary-element) 30%,var(--color-border)); border-radius:11px; background:color-mix(in srgb,var(--color-primary-element) 4%,var(--color-main-background)); }
.briefing-form-title { display:flex; flex-direction:column; gap:3px; }
.briefing-form-title span { color:var(--color-text-maxcontrast); font-size:12px; }
.weekday-picker { display:flex; flex-wrap:wrap; gap:7px; margin-top:7px; }
.weekday-picker label { display:inline-flex; align-items:center; gap:5px; padding:7px 10px; border:1px solid var(--color-border); border-radius:999px; background:var(--color-main-background); color:var(--color-text-maxcontrast); font-size:12px; cursor:pointer; }
.weekday-picker label.selected { border-color:var(--color-primary-element); background:color-mix(in srgb,var(--color-primary-element) 14%,var(--color-main-background)); color:var(--color-main-text); font-weight:700; }
.weekday-picker input { accent-color:var(--color-primary-element); }
.briefing-form-actions { justify-content:flex-start; }
.briefing-form-actions span { color:var(--color-text-maxcontrast); font-size:11px; }

.exclude-paths { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--color-border); }
.sub-heading span { display: block; margin-top: -2px; color: var(--color-text-maxcontrast); font-size: 12px; }
.exclude-chips { display: flex; flex-wrap: wrap; gap: 7px; margin: 14px 0 8px; }
.exclude-chip { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 5px 8px 5px 10px; border: 1px solid var(--color-border); border-radius: 999px; background: var(--color-background-hover); font-size: 12px; overflow-wrap: anywhere; }
.chip-remove { min-width: 24px; margin: -4px -5px -4px 0; padding: 0; }
.empty-help { margin: 14px 0 8px; color: var(--color-text-maxcontrast); font-size: 12px; }
.exclude-add-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.exclude-add-row > :first-child { flex: 1; min-width: 0; }
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

.audit-list { display: grid; gap: 6px; margin: 10px 0 0; padding: 0; list-style: none; }
.audit-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 4px 10px; padding: 8px 10px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); font-size: 12px; }
.audit-outcome { padding: 1px 7px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: lowercase; }
.outcome-executed { background: color-mix(in srgb, var(--color-success) 16%, transparent); color: var(--color-success); }
.outcome-rejected, .outcome-failed { background: color-mix(in srgb, var(--color-warning) 18%, transparent); color: var(--color-warning-text, var(--color-main-text)); }
.audit-tool { font-weight: 600; }
.audit-detail { color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }
.audit-time { margin-left: auto; color: var(--color-text-maxcontrast); font-size: 11px; white-space: nowrap; }

.knowledge-editor { width: 100%; min-height: 200px; padding: 12px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large, 8px); background: var(--color-main-background); color: var(--color-main-text); font: inherit; font-size: 13px; line-height: 1.6; resize: vertical; box-sizing: border-box; font-family: var(--font-family-monospace, monospace); }
.knowledge-editor:focus { border-color: var(--color-primary-element); outline: 2px solid color-mix(in srgb, var(--color-primary-element) 25%, transparent); outline-offset: 1px; }
.knowledge-editor:disabled { opacity: .65; cursor: not-allowed; }

@media (max-width: 800px) {
	.page-header { align-items: flex-start; flex-direction: column; }
	.header-actions { width: 100%; justify-content: space-between; }
	.summary-grid, .field-grid-three, .field-grid-wide { grid-template-columns: 1fr; }
	.field-wide { grid-column: auto; }
	.indexing-banner { align-items: flex-start; flex-direction: column; }
	.index-actions { align-items: flex-start; flex-direction: column; }
	.button-group { justify-content: flex-start; width: 100%; }
}

@media (max-width: 500px) {
	.settings-view { padding: 18px 12px 36px; }
	.settings-section { padding: 18px 0; }
	.exclude-add-row { align-items: stretch; flex-direction: column; }
	.exclude-add-row :deep(.button-vue) { width: 100%; }
}
</style>
