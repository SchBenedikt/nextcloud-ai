import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

/** Shared settings state survives section switches; optional data loads on demand. */
export function useEvaSettings() {
	let disposed = false
	const f = ref({
		chat_provider: 'ollama',
		groq_model: 'openai/gpt-oss-20b',
		custom_provider_url: '',
		custom_provider_model: '',
		provider_profiles: '[]',
		ollama_url: 'http://127.0.0.1:11434',
		embedding_model: 'nomic-embed-text',
		chat_model: 'gemma4:cloud',
		chat_model_fallback: '',
		embedding_model_fallback: '',
		summary_model: '',
		temperature: '0.1',
		 actions_enabled: '1',
		voice_input_enabled: '0',
		learning_enabled: '1',
		safe_commands_enabled: '0',
		terminal_commands_enabled: '0',
		terminal_command_any: '0',
		terminal_command_allowlist: 'date,uptime,php,node,git,ls,find,grep,rg,cat,head,tail,df,du,free,uname',
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
	const providerProfiles = computed(() => {
		try { const rows = JSON.parse(f.value.provider_profiles || '[]'); return Array.isArray(rows) ? rows.filter(p => p && p.id && p.name) : [] } catch (_) { return [] }
	})
	const selectedChatProvider = computed(() => {
		if (f.value.chat_provider === 'ollama') return 'Ollama'
		if (f.value.chat_provider === 'groq') return 'Groq'
		return providerProfiles.value.find(profile => profile.id === f.value.chat_provider)?.name || f.value.chat_provider || t('the configured provider')
	})
	// All settings controls use the official Nextcloud Vue components. Keep
	// their models as plain strings so autosave and server validation remain
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
	let credentialAutoSaveTimer = null
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
	const proactiveEnabled = computed({
		get: () => f.value.proactive_enabled === '1',
		set: value => { f.value.proactive_enabled = value ? '1' : '0' },
	})
	const weekdays = [
		{ value: 1, label: t('Mon') }, { value: 2, label: t('Tue') }, { value: 3, label: t('Wed') },
		{ value: 4, label: t('Thu') }, { value: 5, label: t('Fri') }, { value: 6, label: t('Sat') }, { value: 7, label: t('Sun') },
	]
	const briefingDraft = ref({ prompt: '', time: '08:00', days: [1, 2, 3, 4, 5], allow_actions: false, type: 'custom', channels: ['notification'], talk_room: '' })
	const briefingFormError = ref('')
	const editingBriefingId = ref(null)
	const briefingHistory = ref([])
	const briefingTypeOptions = [
		{ value: 'morning', label: t('Morning briefing') },
		{ value: 'document_digest', label: t('Document digest') },
		{ value: 'custom', label: t('Custom report') },
	]
	const proactiveBriefings = computed(() => {
		try { const rows = JSON.parse(f.value.proactive_schedules || '[]'); return Array.isArray(rows) ? rows : [] } catch (_) { return [] }
	})
	const dayName = day => (weekdays.find(item => item.value === Number(day)) || {}).label || String(day)
	const briefingTypeName = type => briefingTypeOptions.find(item => item.value === type)?.label || t('Custom report')
	const channelName = channel => ({ notification: t('Nextcloud notification'), email: t('Email'), talk: t('Nextcloud Talk') }[channel] || String(channel))
	const formatDateTime = timestamp => new Date(Number(timestamp || 0) * 1000).toLocaleString()
	const failureSummary = run => Object.values(run?.failures || {}).join('; ')
	function writeBriefings(rows) { f.value.proactive_schedules = JSON.stringify(rows.slice(0, 20)) }
	async function persistBriefings(rows, messageKey) {
		writeBriefings(rows)
		setMessage('info', t('Saving briefing changes…'))
		if (await saveBriefingSchedule()) setMessage('success', t(messageKey))
	}
	async function saveBriefingSchedule() {
		// Save the schedule explicitly.  The general settings watcher may be
		// saving another field at the same time; relying on changedSettingKeys()
		// here made the Add button appear to do nothing when that save raced.
		const payload = {
			proactive_enabled: f.value.proactive_enabled,
			proactive_schedules: f.value.proactive_schedules,
		}
		try {
			const settings = await api('PUT', 'settings', payload)
			if (settings) fill(settings)
			return true
		} catch (error) {
			setMessage('error', t('The briefing could not be saved: {error}', { error: errMsg(error) }))
			return false
		}
	}
	async function saveBriefing() {
		const prompt = briefingDraft.value.prompt.trim()
		const rawTime = String(briefingDraft.value.time || '').trim()
		briefingFormError.value = ''
		if (!prompt) { briefingFormError.value = t('Enter a question or instruction for this briefing.'); return }
		// Native time inputs already constrain the value to HH:MM. Keep a
		// safe default for browsers that expose an empty value during the
		// first interaction instead of making Add appear to do nothing.
		const time = rawTime || '08:00'
		if (!briefingDraft.value.days.length) { briefingFormError.value = t('Choose at least one weekday.'); return }
		if (!briefingDraft.value.channels.length) { briefingFormError.value = t('Choose at least one delivery channel.'); return }
		if (briefingDraft.value.channels.includes('talk') && !briefingDraft.value.talk_room.trim()) { briefingFormError.value = t('Enter a Talk room name or token.'); return }
		if (!editingBriefingId.value && proactiveBriefings.value.length >= 20) { briefingFormError.value = t('You can create up to 20 briefings.'); return }
		const current = proactiveBriefings.value.find(item => item.id === editingBriefingId.value)
		const item = { ...(current || {}), id: editingBriefingId.value || `briefing-${Date.now()}`, prompt, time, days: [...new Set(briefingDraft.value.days)].sort(), enabled: current?.enabled !== false, allow_actions: briefingDraft.value.allow_actions === true, type: briefingDraft.value.type, channels: [...new Set(briefingDraft.value.channels)], talk_room: briefingDraft.value.talk_room.trim() }
		writeBriefings(editingBriefingId.value ? proactiveBriefings.value.map(entry => entry.id === editingBriefingId.value ? item : entry) : [...proactiveBriefings.value, item])
		setMessage('info', t('Saving briefing changes…'))
		if (await saveBriefingSchedule()) {
			editingBriefingId.value = null
			briefingDraft.value = { prompt: '', time: '08:00', days: [1, 2, 3, 4, 5], allow_actions: false, type: 'custom', channels: ['notification'], talk_room: '' }
			setMessage('success', t('Briefing saved.'))
		}
	}
	function editBriefing(item) {
		editingBriefingId.value = item.id
		briefingDraft.value = { prompt: item.prompt || '', time: item.time || '08:00', days: [...(item.days || [])], allow_actions: item.allow_actions === true, type: item.type || 'custom', channels: [...(item.channels || ['notification'])], talk_room: item.talk_room || '' }
		briefingFormError.value = ''
		document.querySelector('.briefing-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
	}
	function cancelEditBriefing() {
		editingBriefingId.value = null
		briefingDraft.value = { prompt: '', time: '08:00', days: [1, 2, 3, 4, 5], allow_actions: false, type: 'custom', channels: ['notification'], talk_room: '' }
		briefingFormError.value = ''
	}
	async function removeBriefing(id) { await persistBriefings(proactiveBriefings.value.filter(item => item.id !== id), 'Briefing changes saved.') }
	async function toggleBriefing(id) { await persistBriefings(proactiveBriefings.value.map(item => item.id === id ? { ...item, enabled: item.enabled === false } : item), 'Briefing changes saved.') }
	async function toggleBriefingActions(id) { await persistBriefings(proactiveBriefings.value.map(item => item.id === id ? { ...item, allow_actions: item.allow_actions !== true } : item), 'Briefing changes saved.') }
	async function loadBriefingHistory() {
		try { const result = await api('GET', 'briefings/history'); briefingHistory.value = Array.isArray(result?.items) ? result.items.reverse() : [] } catch (_) { briefingHistory.value = [] }
	}
	async function runBriefingNow(id) {
		try {
			await api('POST', `briefings/${encodeURIComponent(id)}/run`, {})
			setMessage('success', t('Briefing queued. It will appear in the history when delivery finishes.'))
			window.setTimeout(loadBriefingHistory, 5000)
		} catch (error) { setMessage('error', t('The briefing could not be started: {error}', { error: errMsg(error) })) }
	}
	const userWebSearchEnabled = computed({
		get: () => f.value.web_search_enabled === '1',
		set: value => { f.value.web_search_enabled = value ? '1' : '0' },
	})
	const userWebSearchImages = computed({ get: () => f.value.web_search_images === '1', set: v => { f.value.web_search_images = v ? '1' : '0' } })
	const userWebSearchBrowser = computed({ get: () => f.value.web_search_browser === '1', set: v => { f.value.web_search_browser = v ? '1' : '0' } })
	const userWebSearchFetchContent = computed({ get: () => f.value.web_search_fetch_content === '1', set: v => { f.value.web_search_fetch_content = v ? '1' : '0' } })
	const userWebSearchSafeSearch = computed({ get: () => f.value.web_search_safe_search === '1', set: v => { f.value.web_search_safe_search = v ? '1' : '0' } })
	const connectors = ref([])
	const connectorEndpointQuery = ref('')
	const plugins = ref([])
	const pluginsSaving = ref([])
	const pluginsLoading = ref(false)
	const pluginLoadError = ref('')
	const connectorsLoading = ref(false)
	const connectorLoadError = ref('')
	const connectorsBusy = ref(false)
	const connectorDiagnostics = ref({})
	const apiKeys = ref([])
	const apiKeysLoading = ref(false)
	const apiKeysBusy = ref(false)
	const apiKeyError = ref('')
	const createdApiKey = ref('')
	const apiKeyDraft = ref({ name: '', scope: 'read', expires: '', ips: '' })
	const apiKeyScopeOptions = computed(() => [
		{ value: 'read', label: t('Read only · 100 requests/min') },
		{ value: 'write', label: t('Read and write · 30 requests/min') },
	])
	const apiDocsUrl = 'https://github.com/SchBenedikt/nextcloud-ai/blob/main/docs/API.md'
	const connectorToRemove = ref(null)
	const connectorRemovalError = ref('')
	const connectorDraft = ref({ id: '', name: '', base_url: '', openapi_url: '', auth_type: 'bearer', token: '', username: '', password: '', api_key: '', api_key_header: 'X-API-Key' })
	function applyConnectorExample(type) {
		connectorDraft.value = type === 'immich'
			? { id: 'immich', name: 'Immich', base_url: 'http://immich.local:2283', openapi_url: '', auth_type: 'api_key', token: '', username: '', password: '', api_key: '', api_key_header: 'x-api-key' }
			: type === 'vaultwarden'
				? { id: 'vaultwarden', name: 'Vaultwarden', base_url: 'https://vaultwarden.local', openapi_url: '', auth_type: 'bearer', token: '', username: '', password: '', api_key: '', api_key_header: 'X-API-Key' }
				: type === 'truenas'
			? { id: 'truenas', name: 'TrueNAS', base_url: 'https://truenas.local', openapi_url: '', auth_type: 'bearer', token: '', username: '', password: '', api_key: '', api_key_header: 'X-API-Key' }
			: type === 'github'
				? { id: 'github', name: 'GitHub', base_url: 'https://api.github.com', openapi_url: '', auth_type: 'bearer', token: '', username: '', password: '', api_key: '', api_key_header: 'X-API-Key' }
				: { id: 'homeassistant', name: 'Home Assistant', base_url: 'http://homeassistant.local:8123', openapi_url: '', auth_type: 'bearer', token: '', username: '', password: '', api_key: '', api_key_header: 'X-API-Key' }
	}
	async function loadConnectors() {
		connectorsLoading.value = true
		connectorLoadError.value = ''
		try {
			const data = await api('GET', 'connectors')
			if (!Array.isArray(data?.result?.connectors)) throw new Error(t('The connector list response was incomplete.'))
			connectors.value = data.result.connectors
			return true
		} catch (error) {
			connectorLoadError.value = errMsg(error)
			return false
		} finally { connectorsLoading.value = false }
	}
	async function loadApiKeys() {
		apiKeysLoading.value = true
		apiKeyError.value = ''
		try {
			const result = await api('GET', 'keys')
			if (!Array.isArray(result?.keys)) throw new Error(t('The API key list response was incomplete.'))
			apiKeys.value = result.keys
		} catch (error) { apiKeyError.value = errMsg(error) } finally { apiKeysLoading.value = false }
	}
	async function createApiKey() {
		apiKeysBusy.value = true
		apiKeyError.value = ''
		createdApiKey.value = ''
		try {
			const expiresAt = apiKeyDraft.value.expires ? Math.floor(new Date(apiKeyDraft.value.expires + 'T23:59:59').getTime() / 1000) : null
			const created = await api('POST', 'keys', { name: apiKeyDraft.value.name, scope: apiKeyDraft.value.scope, expiresAt, ipWhitelist: apiKeyDraft.value.ips })
			if (!created?.key || !created?.record?.id) throw new Error(t('The API key response was incomplete.'))
			createdApiKey.value = created.key
			apiKeyDraft.value = { name: '', scope: 'read', expires: '', ips: '' }
			await loadApiKeys()
		} catch (error) { apiKeyError.value = errMsg(error) } finally { apiKeysBusy.value = false }
	}
	async function revokeApiKey(key) {
		if (!window.confirm(t('Revoke the API key “{name}”? Integrations using it will stop working.', { name: key.name }))) return
		apiKeysBusy.value = true
		apiKeyError.value = ''
		try { await api('DELETE', 'keys/' + encodeURIComponent(key.id)); await loadApiKeys() } catch (error) { apiKeyError.value = errMsg(error) } finally { apiKeysBusy.value = false }
	}
	async function copyApiKey() {
		try { await navigator.clipboard.writeText(createdApiKey.value); setMessage('success', t('API key copied to clipboard.')) } catch (_) { apiKeyError.value = t('Clipboard access failed. Select and copy the key manually.') }
	}
	async function loadPlugins() {
		pluginsLoading.value = true
		pluginLoadError.value = ''
		try {
			const data = await api('GET', 'plugins')
			if (!Array.isArray(data?.plugins)) throw new Error(t('The extension list response was incomplete.'))
			plugins.value = data.plugins
		} catch (error) { pluginLoadError.value = errMsg(error) } finally { pluginsLoading.value = false }
	}
	async function setPluginEnabled(plugin, enabled) {
		if (pluginsSaving.value.includes(plugin.name)) return
		pluginsSaving.value = [...pluginsSaving.value, plugin.name]
		try {
			const result = await api('PUT', 'plugins', { name: plugin.name, enabled: Boolean(enabled) })
			plugin.enabled = result?.enabled === true
		} catch (error) {
			setMessage('error', t('Could not update plugin setting: {error}', { error: errMsg(error) }))
		} finally {
			pluginsSaving.value = pluginsSaving.value.filter(name => name !== plugin.name)
		}
	}
	async function saveConnector() {
		connectorsBusy.value = true
		try {
			const draft = { ...connectorDraft.value }
			if (!draft.id.trim()) { try { draft.id = new URL(draft.base_url).hostname.replace(/^www\./, '').split('.')[0].toLowerCase().replace(/[^a-z0-9_-]/g, '-') } catch (_) {} }
			if (!draft.name.trim()) draft.name = draft.id
			await api('PUT', 'connectors', draft)
			connectorDraft.value = { id: '', name: '', base_url: '', openapi_url: '', auth_type: 'bearer', token: '', username: '', password: '', api_key: '', api_key_header: 'X-API-Key' }
			await loadConnectors()
			try {
				const discovered = await api('POST', 'connectors/discover', { id: draft.id })
				setMessage('success', t('External connector saved.') + ' (' + String(discovered?.result?.endpoints?.length || 0) + ' endpoints discovered.)')
				await loadConnectors()
			} catch (discoveryError) {
				setMessage('error', t('API discovery failed: {error}', { error: errMsg(discoveryError) }))
			}
		} catch (error) { setMessage('error', t('Could not save connector: {error}', { error: errMsg(error) })) } finally { connectorsBusy.value = false }
	}
	function removeConnector(id) {
		const connector = connectors.value.find(item => item.id === id)
		if (!connector || connectorsBusy.value) return
		connectorToRemove.value = connector
		connectorRemovalError.value = ''
	}
	function closeConnectorRemoval() {
		if (!connectorsBusy.value) connectorToRemove.value = null
	}
	async function confirmRemoveConnector() {
		const connector = connectorToRemove.value
		if (!connector || connectorsBusy.value) return
		connectorsBusy.value = true
		connectorRemovalError.value = ''
		try {
			await api('DELETE', 'connectors', { id: connector.id })
			connectors.value = connectors.value.filter(item => item.id !== connector.id)
			connectorToRemove.value = null
			setMessage('success', t('External connector removed.'))
			await loadConnectors()
		} catch (error) {
			connectorRemovalError.value = t('Could not remove connector: {error}', { error: errMsg(error) })
		} finally { connectorsBusy.value = false }
	}
	function editConnector(connector) {
		connectorDraft.value = { id: connector.id || '', name: connector.name || '', base_url: connector.base_url || '', openapi_url: connector.openapi_url || '', auth_type: connector.auth_type || 'bearer', token: '', username: '', password: '', api_key: '', api_key_header: connector.api_key_header || 'X-API-Key' }
		setMessage('info', t('Connector loaded for editing. Leave secret fields empty to keep saved credentials.'))
	}
	function connectorCredentialLabel(connector) {
		if (connector.auth_type === 'none') return t('No credentials required')
		const configured = connector.auth_type === 'basic' ? connector.username_configured && connector.password_configured : connector.auth_type === 'api_key' ? connector.api_key_configured : connector.token_configured
		return configured ? t('Credential saved') : t('Credential missing')
	}
	function connectorCredentialClass(connector) {
		return connector.auth_type === 'none' || connectorCredentialLabel(connector) === t('Credential saved') || connectorCredentialLabel(connector) === t('No credentials required') ? 'connector-credential connector-credential-ok' : 'connector-credential connector-credential-warning'
	}
	function pluginRiskLabel(risk) {
		return ({ readonly: t('Read-only'), mutating: t('Creates or updates data'), destructive: t('Deletes data') })[risk] || String(risk || t('Read-only'))
	}
	function pluginSurfacesLabel(surfaces) {
		const labels = { web: t('Web chat'), talk: t('Nextcloud Talk'), rag: t('File and knowledge search'), taskprocessing: t('Nextcloud Assistant'), taskprocessing_confirmed: t('Confirmed Nextcloud Assistant actions') }
		return (Array.isArray(surfaces) && surfaces.length ? surfaces : ['web']).map(surface => labels[surface] || String(surface)).join(' · ')
	}
	function filteredConnectorEndpoints(connector) {
		const endpoints = Array.isArray(connector?.learned_endpoints) ? connector.learned_endpoints : []
		const query = String(connectorEndpointQuery.value || '').trim().toLowerCase()
		if (!query) return endpoints.slice(0, 200)
		return endpoints.filter(endpoint => [endpoint?.method, endpoint?.path, endpoint?.operation_id].some(value => String(value || '').toLowerCase().includes(query))).slice(0, 200)
	}
	async function discoverConnector(id) {
		connectorsBusy.value = true
		setMessage('info', t('API discovery is running…'))
		try { const result = await api('POST', 'connectors/discover', { id }); setMessage('success', t('Discovered {count} endpoints.', { count: result?.result?.endpoints?.length || 0 })); await loadConnectors() } catch (error) { setMessage('error', t('API discovery failed: {error}', { error: errMsg(error) })) } finally { connectorsBusy.value = false }
	}
	async function testConnector(id) {
		connectorsBusy.value = true
		setMessage('info', t('Connector test is running…'))
		try {
			const result = await api('POST', 'connectors/test', { id })
			if (result?.result && typeof result.result === 'object') connectorDiagnostics.value = { ...connectorDiagnostics.value, [id]: result.result }
			const status = Number(result?.result?.data?.status || result?.result?.status || 0)
			const detail = status === 401
				? 'Authentication is required. Configure the connector secret; for Immich, use an API key with the x-api-key header.'
				: status === 403 ? 'The connector rejected the credentials or permission.' : ''
			setMessage(result?.ok ? 'success' : 'error', t('Connector responded with HTTP {status}.', { status: status || t('unknown') }) + (detail ? ' ' + t(detail) : ''))
		} catch (error) { setMessage('error', t('Connector test failed: {error}', { error: errMsg(error) })) } finally { connectorsBusy.value = false }
	}
	const formReady = ref(false)
	let autoSaveTimer = null
	let autoSaveDirty = false
	let ignoreNextFormChange = false
	let statusRequestId = 0
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
			const changedKeys = changedSettingKeys()
			const briefingOnly = changedKeys.length > 0 && changedKeys.every(key => key === 'proactive_enabled' || key === 'proactive_schedules')
			if (settingsLocked.value && !(indexingActive.value && briefingOnly)) {
				queueAutoSave()
				return
			}
			autoSaveDirty = false
			await save({ changedOnly: true })
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
				for (const p of profiles) if (!p || !/^[a-z][a-z0-9_-]{1,31}$/.test(String(p.id || '')) || !String(p.name || '').trim() || !/^https?:\/\/\S+$/i.test(String(p.url || '')) || !String(p.model || '').trim()) errors.push(t('Each provider profile needs an ID, name, URL and model.'))
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
		if (!syncForm && !formReady.value) return false
		const currentRequest = ++statusRequestId
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
				fill(response.settings)
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

	async function save({ changedOnly = false } = {}) {
		if (saving.value) return false
		const keys = changedOnly ? changedSettingKeys() : Object.keys(f.value)
		if (keys.length === 0 && !nextcloudApiToken.value && !removeNextcloudApiToken.value) return true
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
			const settings = await api('PUT', 'settings', { ...values, ...(nextcloudApiToken.value ? { nextcloud_api_token: nextcloudApiToken.value } : {}), remove_nextcloud_api_token: removeNextcloudApiToken.value })
			if (!settings || typeof settings !== 'object' || keys.some(key => settings[key] === undefined || settings[key] === null)) {
				throw new Error(t('The settings save response was incomplete.'))
			}
			if (status.value && (nextcloudApiToken.value || removeNextcloudApiToken.value)) status.value.genericApi = { ...(status.value.genericApi || {}), tokenConfigured: !removeNextcloudApiToken.value }
			nextcloudApiToken.value = ''
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
	function queueCredentialAutoSave() {
		if (!formReady.value) return
		window.clearTimeout(credentialAutoSaveTimer)
		credentialAutoSaveTimer = window.setTimeout(() => {
			if (settingsLocked.value) {
				queueCredentialAutoSave()
				return
			}
			save({ changedOnly: true })
		}, 700)
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
	const knowledgeContent = ref('')
	const knowledgeOriginal = ref('')
	const savingKnowledge = ref(false)
	const knowledgeSaved = ref(false)
	const knowledgeLoading = ref(false)
	const knowledgeLoadError = ref(false)
	const knowledgeReady = ref(false)

	async function loadKnowledge() {
		knowledgeLoading.value = true
		knowledgeLoadError.value = false
		try {
			const data = await api('GET', 'knowledge')
			if (!data || typeof data.content !== 'string') throw new Error('Invalid knowledge response')
			knowledgeContent.value = data.content
			knowledgeOriginal.value = knowledgeContent.value
			knowledgeReady.value = true
			return true
		} catch (e) {
			knowledgeLoadError.value = true
			return false
		} finally {
			knowledgeLoading.value = false
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
	// Watch the scalar settings values without Vue's recursive deep traversal.
	// The form is flat, so a shallow value snapshot preserves autosave while
	// avoiding a full object walk on every keystroke.
	watch(() => Object.values(f.value), queueAutoSave)
	watch([nextcloudApiToken, removeNextcloudApiToken], queueCredentialAutoSave)
	watch(() => f.value.ollama_url, (value) => {
		window.clearTimeout(modelTimer)
		modelTimer = window.setTimeout(() => discoverModels(value), 500)
	})

	const group = ref('general')
	const groups = [
		{ id: 'general', label: t('Models') },
		{ id: 'knowledge', label: t('Documents') },
		{ id: 'tools', label: t('Tools & permissions') },
		{ id: 'automation', label: t('Automation') },
		{ id: 'personal', label: t('Personal & data') },
		{ id: 'advanced', label: t('Advanced') },
	]
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
		if (disposed) return
		statusTimer = window.setInterval(() => {
			if (!document.hidden && indexingActive.value) loadIndexStatus()
		}, 10000)
	})
	onUnmounted(() => {
		disposed = true
		if (statusTimer !== null) window.clearInterval(statusTimer)
		if (modelTimer !== null) window.clearTimeout(modelTimer)
		window.clearTimeout(autoSaveTimer)
		window.clearTimeout(credentialAutoSaveTimer)
	})

	const ocrEnabled = computed({ get: () => f.value.ocr_enabled === '1', set: value => { f.value.ocr_enabled = value ? '1' : '0' } })
	return {
		group, groups, formReady, chatRetentionOptions, nextcloudApiToken, removeNextcloudApiToken, ocrEnabled, f, providerProfiles, selectedChatProvider, embeddingModelOptions, chatModelOptions, summaryModelOptions, webSearchProviderOptions, status, health, healthLoading, statusTimer, limits, availableModels, embeddingModels, chatModels, embeddingInstalledHint, chatInstalledHint, modelLoading, modelError, checkOut, saving, checking, indexing, resetting, deletingChats, stopping, saved, loadError, statusRefreshError, message, validationErrors, resetConfirm, chatsDeleteConfirm,
		newExcludePath, excludeError, excludeList, actionsEnabled, backgroundActionsEnabled, voiceInputEnabled, notificationsEnabled, learningEnabled, safeCommandsEnabled, terminalCommandsEnabled, terminalCommandAny, mailIndexEnabled, talkIndexEnabled, talkWriteEnabled, indexEnrolled, actionsDisabled, busy, indexingActive, settingsLocked, briefingsLocked, maxFileSizeMb,
		userWebSearchEnabled, userWebSearchSafeSearch, userWebSearchImages, userWebSearchBrowser, userWebSearchFetchContent,
		connectors, connectorsLoading, connectorsBusy, connectorDiagnostics, connectorDraft, connectorToRemove, connectorRemovalError, connectorEndpointQuery, filteredConnectorEndpoints, applyConnectorExample, saveConnector, editConnector, connectorCredentialLabel, connectorCredentialClass, removeConnector, closeConnectorRemoval, confirmRemoveConnector, discoverConnector, testConnector, loadConnectors, connectorLoadError, plugins, pluginsLoading, pluginsSaving, setPluginEnabled, loadPlugins, pluginLoadError, pluginRiskLabel, pluginSurfacesLabel,
		apiKeys, apiKeysLoading, apiKeysBusy, apiKeyError, createdApiKey, apiKeyDraft, apiKeyScopeOptions, apiDocsUrl, loadApiKeys, createApiKey, revokeApiKey, copyApiKey,
		proactiveEnabled, proactiveBriefings, briefingDraft, briefingFormError, editingBriefingId, briefingHistory, briefingTypeOptions, weekdays, dayName, briefingTypeName, channelName, formatDateTime, failureSummary, saveBriefing, editBriefing, cancelEditBriefing, removeBriefing, toggleBriefing, toggleBriefingActions, runBriefingNow,
		exporting, downloadExport,
		knowledgeContent, knowledgeOriginal, savingKnowledge, knowledgeSaved, knowledgeLoading, knowledgeLoadError, knowledgeReady, loadKnowledge, saveKnowledgeContent,
		formatNumber, loadStatus, loadHealth, healthError, save, checkOllama, addExclude, removeExclude, startIndex, startMailIndex, startTalkIndex, stopIndex, resetIndex, deleteAllChats,
	}
}
