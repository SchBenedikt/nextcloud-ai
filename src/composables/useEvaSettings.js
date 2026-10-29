import { createSettingsDefaults } from '../lib/settings-defaults'
import { useKnowledgeSettings } from './useKnowledgeSettings'
import { useIntegrationSettings } from './useIntegrationSettings'
import { useBriefingSettings } from './useBriefingSettings'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

/** Shared settings state survives section switches; optional data loads on demand. */
export function useEvaSettings({ section = ref('general'), active = ref(true) } = {}) {
	let disposed = false
	const f = ref(createSettingsDefaults())

	const providerProfiles = computed(() => {
		try { const rows = JSON.parse(f.value.provider_profiles || '[]'); return Array.isArray(rows) ? rows.filter(p => p && p.id && p.name) : [] } catch (_) { return [] }
	})
	const selectedChatProvider = computed(() => {
		if (f.value.chat_provider === 'ollama') return 'Ollama'
		if (f.value.chat_provider === 'groq') return 'Groq'
		return providerProfiles.value.find(profile => profile.id === f.value.chat_provider)?.name || f.value.chat_provider || t('the configured provider')
	})
	// All settings controls use the official Nextcloud Vue components. Keep
	// their models as plain strings so saving and server validation remain
	// backwards compatible with the existing API contract.
	const embeddingModelOptions = computed(() => [...new Set([...embeddingModels.value, f.value.embedding_model].filter(Boolean))])
	const chatModelOptions = computed(() => [...new Set([...chatModels.value, f.value.chat_model].filter(Boolean))])
	const summaryModelOptions = computed(() => ['', ...chatModels.value])
	const chatRetentionOptions = [
		{ value: '0', label: t('Never delete automatically') }, { value: '7', label: t('After 7 days') },
		{ value: '14', label: t('After 14 days') }, { value: '30', label: t('After 30 days') },
		{ value: '60', label: t('After 60 days') }, { value: '90', label: t('After 90 days') },
		{ value: '180', label: t('After 180 days') }, { value: '365', label: t('After 365 days') },
	]
	const webSearchProviderOptions = [
		{ value: 'duckduckgo', label: t('DuckDuckGo (free, no API key)') },
		{ value: 'bing', label: t('Bing (free, no API key)') },
		{ value: 'searxng', label: t('SearxNG (self-hosted)') },
		{ value: 'brave', label: t('Brave Search (requires admin setup)') },
		{ value: 'tavily', label: t('Tavily (requires admin setup)') },
	]
	const nextcloudApiToken = ref('')
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
	const statusRefreshError = ref('')
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
	const voiceInputEnabled = computed({
		get: () => f.value.voice_input_enabled === '1',
		set: value => { f.value.voice_input_enabled = value ? '1' : '0' },
	})
	const notificationsEnabled = computed({
		get: () => f.value.notify_on_complete === '1',
		set: value => { f.value.notify_on_complete = value ? '1' : '0' },
	})
	const learningEnabled = computed({
		get: () => f.value.learning_enabled === '1',
		set: value => { f.value.learning_enabled = value ? '1' : '0' },
	})
	const safeCommandsEnabled = computed({
		get: () => f.value.safe_commands_enabled === '1',
		set: value => { f.value.safe_commands_enabled = value ? '1' : '0' },
	})
	const terminalCommandsEnabled = computed({
		get: () => f.value.terminal_commands_enabled === '1',
		set: value => { f.value.terminal_commands_enabled = value ? '1' : '0' },
	})
	const terminalCommandAny = computed({
		get: () => f.value.terminal_command_any === '1',
		set: value => { f.value.terminal_command_any = value ? '1' : '0' },
	})
	const { proactiveEnabled, weekdays, briefingDraft, briefingFormError, editingBriefingId, briefingHistory, briefingTypeOptions, proactiveBriefings, dayName, briefingTypeName, channelName, formatDateTime, failureSummary, saveBriefing, editBriefing, cancelEditBriefing, removeBriefing, toggleBriefing, toggleBriefingActions, loadBriefingHistory, runBriefingNow } = useBriefingSettings({ f, setMessage, save })
	const userWebSearchEnabled = computed({
		get: () => f.value.web_search_enabled === '1',
		set: value => { f.value.web_search_enabled = value ? '1' : '0' },
	})
	const userWebSearchImages = computed({ get: () => f.value.web_search_images === '1', set: v => { f.value.web_search_images = v ? '1' : '0' } })
	const userWebSearchBrowser = computed({ get: () => f.value.web_search_browser === '1', set: v => { f.value.web_search_browser = v ? '1' : '0' } })
	const userWebSearchFetchContent = computed({ get: () => f.value.web_search_fetch_content === '1', set: v => { f.value.web_search_fetch_content = v ? '1' : '0' } })
	const userWebSearchSafeSearch = computed({ get: () => f.value.web_search_safe_search === '1', set: v => { f.value.web_search_safe_search = v ? '1' : '0' } })
	const { connectors, connectorEndpointQuery, plugins, pluginsSaving, pluginsLoading, pluginLoadError, connectorsLoading, connectorLoadError, connectorsBusy, connectorDiagnostics, apiKeys, apiKeysLoading, apiKeysBusy, apiKeyError, createdApiKey, apiKeyDraft, apiKeyScopeOptions, apiDocsUrl, connectorToRemove, connectorRemovalError, connectorDraft, applyConnectorExample, loadConnectors, loadApiKeys, createApiKey, revokeApiKey, copyApiKey, loadPlugins, setPluginEnabled, saveConnector, removeConnector, closeConnectorRemoval, confirmRemoveConnector, editConnector, connectorCredentialLabel, connectorCredentialClass, pluginRiskLabel, pluginSurfacesLabel, filteredConnectorEndpoints, discoverConnector, testConnector } = useIntegrationSettings({ setMessage })
	const formReady = ref(false)
	let pendingSettingsSaves = 0
	let settingsSaveQueue = Promise.resolve()
	let applyingServerState = false
	let statusRequestId = 0
	function changedSettingKeys() {
		return Object.keys(f.value).filter(key => String(f.value[key]) !== String(persistedSettings.value[key] ?? ''))
	}
	const hasUnsavedSettings = computed(() => changedSettingKeys().length > 0 || nextcloudApiToken.value !== '' || removeNextcloudApiToken.value)

	function markSettingsDirty() {
		// Keep form edits local until the user presses the shared Save changes
		// button. The previous debounce wrote values during typing, which made
		// the visible save action and the reported persistence state misleading.
		if (disposed || applyingServerState || !formReady.value) return
		saved.value = false
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
	const saveNowLocked = computed(() => {
		const changed = changedSettingKeys()
		const briefingOnly = changed.length > 0 && changed.every(key => key === 'proactive_enabled' || key === 'proactive_schedules')
		return saving.value || checking.value || indexing.value || resetting.value || deletingChats.value || stopping.value
			|| (indexingActive.value && !briefingOnly)
	})
	// Scheduled briefings stay editable during indexing, but avoid racing
	// another settings write against their independent save request.
	const briefingsLocked = computed(() => saving.value || checking.value || resetting.value || deletingChats.value || stopping.value || !formReady.value)
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
			['top_k', t('Sources per answer'), ...effective('top_k', [1, 8])],
			['context_size', t('Model context size'), ...effective('context_size', [256, 131072])],
			['temperature', t('Answer creativity'), ...effective('temperature', [0, 2])],
			['agent_max_tool_rounds', t('Maximum agent steps per request'), ...effective('agent_max_tool_rounds', [2, 8])],
			['chunk_size', t('Chunk size'), ...effective('chunk_size', [128, 10000])],
			['chunk_overlap', t('Chunk overlap'), ...effective('chunk_overlap', [0, 5000])],
			['max_files_per_run', t('Files per indexing run'), ...effective('max_files_per_run', [1, 10000])],
			['embed_batch_size', t('Embeddings per batch'), ...effective('embed_batch_size', [1, 200])],
			['mail_index_max', t('Emails per indexing run'), ...effective('mail_index_max', [1, 500])],
			['talk_index_max_rooms', t('Chats per indexing run'), ...effective('talk_index_max_rooms', [1, 200])],
			['talk_index_max_messages', t('Messages per chat'), ...effective('talk_index_max_messages', [10, 1000])],
			['talk_history_size', t('Talk history size'), ...effective('talk_history_size', [1, 500])],
			['exec_write_max_chars', t('Maximum characters per file'), ...effective('exec_write_max_chars', [1, 10000000])],
		]
		if (includes('ollama_url') && !/^https?:\/\//i.test(f.value.ollama_url.trim())) errors.push(t('Ollama server URL must start with http:// or https://.'))
		if (includes('provider_profiles')) {
			try {
				const profiles = JSON.parse(f.value.provider_profiles || '[]')
				if (!Array.isArray(profiles) || profiles.length > 20) errors.push(t('Provider profiles must be a JSON array with at most {max} entries.', { max: 20 }))
				for (const p of profiles) {
					if (!p || !/^[a-z][a-z0-9_-]{1,31}$/.test(String(p.id || '')) || !String(p.name || '').trim() || !/^https?:\/\/\S+$/i.test(String(p.url || '')) || !String(p.model || '').trim()) errors.push(t('Each provider profile needs an ID, name, URL and model.'))
					if (!!p.embedding_model !== (p.embedding_dimension !== undefined) || (p.embedding_dimension !== undefined && (!Number.isInteger(Number(p.embedding_dimension)) || Number(p.embedding_dimension) < 1 || Number(p.embedding_dimension) > 16384))) errors.push(t('Embedding profiles need a model and dimensions from 1 to 16384.'))
				}
			} catch (_) { errors.push(t('Provider profiles must contain valid JSON.')) }
		}
		if (includes('embedding_model') && !f.value.embedding_model.trim()) errors.push(t('Embedding model is required.'))
		if ((includes('chat_provider') || includes('chat_model')) && f.value.chat_provider !== 'groq' && !f.value.chat_model.trim()) errors.push(t('Chat model is required.'))
		for (const [key, label, min, max] of numberRules) {
			if (!includes(key)) continue
			const value = Number(f.value[key])
			if (!Number.isFinite(value) || value < min || value > max) errors.push(t('{setting} must be between {min} and {max}.', { setting: label, min, max }))
		}
		const fileSizeMb = Number(maxFileSizeMb.value)
		if (includes('max_file_size') && (!Number.isFinite(fileSizeMb) || fileSizeMb < 1 || fileSizeMb > 2048)) errors.push(t('Maximum file size must be between {min} and {max} MB.', { min: 1, max: 2048 }))
		if ((includes('chunk_overlap') || includes('chunk_size')) && Number(f.value.chunk_overlap) > Number(f.value.chunk_size)) errors.push(t('Chunk overlap cannot be larger than chunk size.'))
		return errors
	}

	function applyModelDiscovery(names, roles = {}) {
		availableModels.value = [...new Set((names || []).map((name) => String(name || '').trim()).filter(Boolean))]
		if (roles && typeof roles === 'object') modelRoles.value = roles
	}

	let modelDiscoveryRequestId = 0
	async function discoverModels(endpoint = f.value.ollama_url) {
		const currentRequest = ++modelDiscoveryRequestId
		const url = String(endpoint || '').trim()
		if (!/^https?:\/\//i.test(url)) {
			modelLoading.value = false
			modelError.value = ''
			return
		}
		modelLoading.value = true
		modelError.value = ''
		try {
			const data = await api('GET', 'models', { endpoint: url })
			if (currentRequest !== modelDiscoveryRequestId) return
				applyModelDiscovery(data?.models || [], data?.roles || {})
				if (!availableModels.value.length) modelError.value = t('No models are installed in this Ollama endpoint.')
			} catch (error) {
				if (currentRequest === modelDiscoveryRequestId) modelError.value = t('Models could not be loaded: {error}', { error: errMsg(error) })
			} finally {
				if (currentRequest === modelDiscoveryRequestId) modelLoading.value = false
			}
		}

	function fill(settings = status.value?.settings, snapshot = null) {
		if (!settings) return
		const baseline = snapshot || (formReady.value ? { ...persistedSettings.value } : null)
		applyingServerState = true
		Object.keys(f.value).forEach(key => {
			if (settings[key] !== undefined && settings[key] !== null) {
				if (!baseline || String(f.value[key]) === String(baseline[key])) f.value[key] = String(settings[key])
				persistedSettings.value[key] = String(settings[key])
			}
		})
		applyingServerState = false
		if (formReady.value && changedSettingKeys().length) markSettingsDirty()
	}

	async function loadStatus(syncForm = false) {
		if (!syncForm && !formReady.value) return false
		const currentRequest = ++statusRequestId
		const snapshot = formReady.value ? { ...persistedSettings.value } : null
		if (syncForm) loadError.value = ''
		try {
			const response = await api('GET', 'status')
			if (!response || typeof response !== 'object' || !response.settings || typeof response.settings !== 'object'
				|| Object.keys(f.value).some(key => !(key in response.settings))) {
				throw new Error(t('The settings response was incomplete.'))
			}
			if (currentRequest !== statusRequestId) return false
			status.value = response
			statusRefreshError.value = ''
			limits.value = response.limits || {}
			if (syncForm) {
				fill(response.settings, snapshot)
				formReady.value = true
			}
			if (Array.isArray(response.models)) {
				applyModelDiscovery(response.models, response?.provider?.roles || {})
			}
			if (syncForm && !Array.isArray(response.models)) await discoverModels(f.value.ollama_url)
			return true
		} catch (error) {
			if (currentRequest === statusRequestId) {
				if (syncForm) {
					loadError.value = errMsg(error)
					formReady.value = false
				} else {
					statusRefreshError.value = errMsg(error)
				}
			}
			return false
		}
	}

	let indexPollInFlight = false
	async function loadIndexStatus() {
		if (indexPollInFlight) return
		indexPollInFlight = true
		try {
			const progress = await api('GET', 'index/status')
			if (!progress || typeof progress.indexing !== 'boolean') throw new Error(t('The indexing status response was invalid.'))
			status.value = { ...status.value, ...progress }
			statusRefreshError.value = ''
		} catch (error) { statusRefreshError.value = errMsg(error) }
		finally { indexPollInFlight = false }
	}

	const health = ref(null)
	const healthLoading = ref(false)
	const healthError = ref('')
	let healthRequestId = 0
	async function loadHealth() {
		const currentRequest = ++healthRequestId
		healthLoading.value = true
		try {
			const data = await api('GET', 'health')
			if (!data || typeof data.ok !== 'boolean' || !data.checks || typeof data.checks.chat_model !== 'boolean'
				|| !data.index || !data.provider || typeof data.provider.online !== 'boolean'
				|| !data.queue || !Number.isFinite(Number(data.queue.active))) {
				throw new Error(t('The diagnostics response was incomplete.'))
			}
			if (currentRequest === healthRequestId) {
				health.value = data
				healthError.value = ''
			}
		} catch (error) {
			const body = error?.response?.data?.ocs?.data || error?.response?.data
			if (body && typeof body.ok === 'boolean' && body.checks && typeof body.checks.chat_model === 'boolean'
				&& body.index && body.provider && typeof body.provider.online === 'boolean'
				&& body.queue && Number.isFinite(Number(body.queue.active))) {
				if (currentRequest === healthRequestId) {
					health.value = body
					healthError.value = ''
				}
			} else if (currentRequest === healthRequestId) {
				health.value = null
				healthError.value = errMsg(error)
			}
		} finally {
			if (currentRequest === healthRequestId) healthLoading.value = false
		}
	}

	function save(options = {}) {
		// Settings sections can trigger saves from explicit actions,
		// credentials, and provider controls at nearly the same time. Serialize
		// writes so an older response cannot overwrite a newer server value.
		pendingSettingsSaves++
		saving.value = true
		const task = settingsSaveQueue.then(() => persistSettings(options))
		settingsSaveQueue = task.then(() => undefined, () => undefined)
		return task.finally(() => {
			pendingSettingsSaves = Math.max(0, pendingSettingsSaves - 1)
			saving.value = pendingSettingsSaves > 0
		})
	}

	/** Save pending form edits from the shared settings header. */
	function saveNow() {
		if (!formReady.value || saveNowLocked.value) return Promise.resolve(false)
		return save({ changedOnly: true })
	}

	/** Serialize explicit provider-profile writes with form settings. */
	function savePatch(values) {
		pendingSettingsSaves++
		saving.value = true
		const task = settingsSaveQueue.then(async () => {
			const settings = await api('PUT', 'settings', values)
			if (!settings || typeof settings !== 'object' || Array.isArray(settings)) {
				throw new Error(t('The server did not confirm the saved settings.'))
			}

			// Provider actions use this patch path instead of the form Save button.
			// Confirm each form setting against the server response and immediately
			// reconcile the shared form state. This prevents a success notice from
			// masking a stale or incomplete response, while preserving unrelated
			// edits the user made during the request.
			const confirmed = {}
			for (const [key, expected] of Object.entries(values)) {
				if (!Object.prototype.hasOwnProperty.call(f.value, key)) continue
				if (!Object.prototype.hasOwnProperty.call(settings, key) || !settingValueMatches(key, expected, settings[key])) {
					const setting = key === 'provider_profiles' ? t('AI providers') : key === 'chat_provider' ? t('Chat provider') : t('provider settings')
					throw new Error(t('The server did not confirm the saved setting: {setting}.', { setting }))
				}
				confirmed[key] = String(settings[key])
			}
			if (Object.keys(confirmed).length) fill(confirmed, { ...persistedSettings.value })
			return settings
		})
		settingsSaveQueue = task.then(() => undefined, () => undefined)
		return task.finally(() => {
			pendingSettingsSaves = Math.max(0, pendingSettingsSaves - 1)
			saving.value = pendingSettingsSaves > 0
		})
	}

	function settingValueMatches(key, expected, actual) {
		if (key === 'provider_profiles') {
			try {
				const canonical = value => Array.isArray(value)
					? value.map(canonical)
					: value && typeof value === 'object'
						? Object.fromEntries(Object.keys(value).sort().map(name => [name, canonical(value[name])]))
						: value
				return JSON.stringify(canonical(JSON.parse(String(expected)))) === JSON.stringify(canonical(JSON.parse(String(actual))))
			} catch (_) {
				return false
			}
		}
		return String(expected) === String(actual)
	}

	async function persistSettings({ changedOnly = false, onlyKeys = null } = {}) {
		const keys = Array.isArray(onlyKeys)
			? onlyKeys.filter(key => Object.prototype.hasOwnProperty.call(f.value, key))
			: changedOnly ? changedSettingKeys() : Object.keys(f.value)
		if (keys.length === 0 && !nextcloudApiToken.value && !removeNextcloudApiToken.value) return true
		validationErrors.value = validate(Array.isArray(onlyKeys) ? keys : changedOnly ? keys : null)
		if (validationErrors.value.length) {
			setMessage('error', t('Please correct the highlighted settings before saving.'))
			return false
		}
		saved.value = false
		message.value = { type: '', text: '' }
		const snapshot = { ...f.value }
		try {
			const values = changedOnly || Array.isArray(onlyKeys)
				? Object.fromEntries(keys.map(key => [key, f.value[key]]))
				: { ...f.value }
			const settings = await api('PUT', 'settings', { ...values, ...(nextcloudApiToken.value ? { nextcloud_api_token: nextcloudApiToken.value } : {}), remove_nextcloud_api_token: removeNextcloudApiToken.value })
			if (!settings || typeof settings !== 'object' || keys.some(key => settings[key] === undefined || settings[key] === null)) {
				throw new Error(t('The settings save response was incomplete.'))
			}
			if (status.value && (nextcloudApiToken.value || removeNextcloudApiToken.value)) status.value.genericApi = { ...(status.value.genericApi || {}), tokenConfigured: !removeNextcloudApiToken.value }
			nextcloudApiToken.value = ''
			removeNextcloudApiToken.value = false
			validationErrors.value = []
			const savedSettings = Object.fromEntries(keys.map(key => [key, settings[key]]))
			fill(savedSettings, snapshot)
			saved.value = true
			setMessage('success', t('Your settings were saved.'))
			window.setTimeout(() => { saved.value = false }, 3000)
			return true
		} catch (error) {
			const payload = error?.response?.data?.ocs?.data ?? error?.response?.data
			validationErrors.value = Array.isArray(payload?.validationErrors)
				? payload.validationErrors.filter(value => typeof value === 'string' && value.trim()).slice(0, 5)
				: []
			setMessage('error', validationErrors.value.length
				? t('The server rejected these settings. Review the validation messages above.')
				: t('The settings could not be saved: {error}', { error: errMsg(error) }))
			return false
		}
	}
	async function checkOllama() {
		if (checking.value) return
		checking.value = true
		checkOut.value = null
		const savedSuccessfully = await save({ changedOnly: true })
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
			if (data.custom) {
				checkOut.value = { type: data.custom.ok ? 'success' : 'error', lines: [{ label: selectedChatProvider.value, ok: !!data.custom.ok, detail: data.custom.ok ? t('Connected') : data.custom.error || t('Not reachable') }] }
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
		const savedSuccessfully = await save({ onlyKeys: ['exclude_paths'] })
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
		const savedSuccessfully = await save({ changedOnly: true })
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
		const savedSuccessfully = await save({ changedOnly: true })
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
		const savedSuccessfully = await save({ changedOnly: true })
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
			// Keep the stop button pending while the worker releases its claim;
			// a single immediate poll otherwise makes the UI look stopped while
			// indexing is still writing files.
			for (let i = 0; i < 30 && status.value?.indexStopping; i++) {
				await new Promise(resolve => window.setTimeout(resolve, 1000))
				await loadStatus()
			}
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
	const { knowledgeContent, knowledgeOriginal, savingKnowledge, knowledgeSaved, knowledgeLoading, knowledgeLoadError, knowledgeReady, loadKnowledge, saveKnowledgeContent } = useKnowledgeSettings({ setMessage })
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
	// Watch the scalar settings values without Vue's recursive deep traversal.
	// The form is flat, so a shallow value snapshot tracks dirty state without
	// a full object walk on every keystroke. Changes remain local until saved.
	watch(() => Object.values(f.value), markSettingsDirty, { flush: 'sync' })
	watch(() => f.value.ollama_url, (value) => {
		window.clearTimeout(modelTimer)
		modelTimer = window.setTimeout(() => discoverModels(value), 500)
	})

	const group = section
	const loadedGroups = new Set()
	watch([group, formReady], async ([selected, ready]) => {
		if (disposed || !ready || loadedGroups.has(selected)) return
		loadedGroups.add(selected)
		const loaders = {
			automation: [loadBriefingHistory],
			personal: [loadKnowledge],
			advanced: [loadConnectors, loadApiKeys, loadPlugins, loadHealth],
		}
		await Promise.all((loaders[selected] || []).map(load => load()))
	})
	onMounted(async () => {
		await loadStatus(true)
		if (disposed) return
		statusTimer = window.setInterval(() => {
			if (active.value && !document.hidden && indexingActive.value) loadIndexStatus()
		}, 10000)
	})
	onUnmounted(() => {
		disposed = true
		if (statusTimer !== null) window.clearInterval(statusTimer)
		if (modelTimer !== null) window.clearTimeout(modelTimer)
	})

	const ocrEnabled = computed({ get: () => f.value.ocr_enabled === '1', set: value => { f.value.ocr_enabled = value ? '1' : '0' } })
	return {
		group, formReady, chatRetentionOptions, nextcloudApiToken, removeNextcloudApiToken, hasUnsavedSettings, ocrEnabled, f, providerProfiles, selectedChatProvider, embeddingModelOptions, chatModelOptions, summaryModelOptions, webSearchProviderOptions, status, health, healthLoading, statusTimer, limits, availableModels, embeddingModels, chatModels, embeddingInstalledHint, chatInstalledHint, modelLoading, modelError, checkOut, saving, checking, indexing, resetting, deletingChats, stopping, saved, loadError, statusRefreshError, message, validationErrors, resetConfirm, chatsDeleteConfirm,
		newExcludePath, excludeError, excludeList, actionsEnabled, backgroundActionsEnabled, voiceInputEnabled, notificationsEnabled, learningEnabled, safeCommandsEnabled, terminalCommandsEnabled, terminalCommandAny, mailIndexEnabled, talkIndexEnabled, talkWriteEnabled, indexEnrolled, actionsDisabled, busy, indexingActive, settingsLocked, saveNowLocked, briefingsLocked, maxFileSizeMb,
		userWebSearchEnabled, userWebSearchSafeSearch, userWebSearchImages, userWebSearchBrowser, userWebSearchFetchContent,
		connectors, connectorsLoading, connectorsBusy, connectorDiagnostics, connectorDraft, connectorToRemove, connectorRemovalError, connectorEndpointQuery, filteredConnectorEndpoints, applyConnectorExample, saveConnector, editConnector, connectorCredentialLabel, connectorCredentialClass, removeConnector, closeConnectorRemoval, confirmRemoveConnector, discoverConnector, testConnector, loadConnectors, connectorLoadError, plugins, pluginsLoading, pluginsSaving, setPluginEnabled, loadPlugins, pluginLoadError, pluginRiskLabel, pluginSurfacesLabel,
		apiKeys, apiKeysLoading, apiKeysBusy, apiKeyError, createdApiKey, apiKeyDraft, apiKeyScopeOptions, apiDocsUrl, loadApiKeys, createApiKey, revokeApiKey, copyApiKey,
		proactiveEnabled, proactiveBriefings, briefingDraft, briefingFormError, editingBriefingId, briefingHistory, briefingTypeOptions, weekdays, dayName, briefingTypeName, channelName, formatDateTime, failureSummary, saveBriefing, editBriefing, cancelEditBriefing, removeBriefing, toggleBriefing, toggleBriefingActions, runBriefingNow,
		exporting, downloadExport, savePatch, saveNow,
		knowledgeContent, knowledgeOriginal, savingKnowledge, knowledgeSaved, knowledgeLoading, knowledgeLoadError, knowledgeReady, loadKnowledge, saveKnowledgeContent,
		formatNumber, loadStatus, loadIndexStatus, loadHealth, healthError, save, checkOllama, addExclude, removeExclude, startIndex, startMailIndex, startTalkIndex, stopIndex, resetIndex, deleteAllChats,
	}
}
