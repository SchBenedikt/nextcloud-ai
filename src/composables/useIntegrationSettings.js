import { computed, ref } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

export function useIntegrationSettings({ setMessage }) {
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
	return { connectors, connectorEndpointQuery, plugins, pluginsSaving, pluginsLoading, pluginLoadError, connectorsLoading, connectorLoadError, connectorsBusy, connectorDiagnostics, apiKeys, apiKeysLoading, apiKeysBusy, apiKeyError, createdApiKey, apiKeyDraft, apiKeyScopeOptions, apiDocsUrl, connectorToRemove, connectorRemovalError, connectorDraft, applyConnectorExample, loadConnectors, loadApiKeys, createApiKey, revokeApiKey, copyApiKey, loadPlugins, setPluginEnabled, saveConnector, removeConnector, closeConnectorRemoval, confirmRemoveConnector, editConnector, connectorCredentialLabel, connectorCredentialClass, pluginRiskLabel, pluginSurfacesLabel, filteredConnectorEndpoints, discoverConnector, testConnector }
}
