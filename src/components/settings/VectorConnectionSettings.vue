<template>
	<div class="vector-connection">
		<div class="connection-actions">
			<NcButton variant="secondary" :disabled="!enabled || !!busy" :loading="busy === 'check'" @click="check">
				{{ $t('Check saved connection') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="!!busy" :loading="busy === 'disable'" @click="disable">
				{{ $t('Disable storage requests') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="notice.text" :type="notice.type" role="status">{{ notice.text }}</NcNoteCard>
		<details @toggle="onToggle">
			<summary>{{ $t('Configure optional storage') }}</summary>
			<p>{{ $t('Saving changes the connection. Allowing requests can resume enabled workers; it does not backfill files, create collections or switch SQL search.') }}</p>
			<NcButton v-if="!formReady" variant="tertiary" :disabled="!!busy" :loading="busy === 'load'" @click="loadConfiguration">
				{{ $t('Load connection settings') }}
			</NcButton>
			<form v-if="formReady" @submit.prevent="save">
				<fieldset :disabled="!!busy">
					<NcTextField v-model="endpoint" type="url" :label="$t('Qdrant server URL')" placeholder="https://vectors.example.org:6333" :maxlength="2048" required />
					<NcTextArea v-model="privateOriginsText" :label="$t('Approved private network origins')" :placeholder="'https://qdrant.internal:6333\nhttp://127.0.0.1:6333'" :rows="3" :maxlength="8192" />
					<p class="connection-help">{{ $t('Optional exact origins, one per line, for private or local Qdrant servers. Include scheme and port, with no path. Other private destinations stay blocked. Remote Qdrant still requires HTTPS.') }}</p>
					<NcCheckboxRadioSwitch v-model:checked="noKey" @update:checked="apiKey = ''">{{ $t('Use without API-key authentication') }}</NcCheckboxRadioSwitch>
					<NcPasswordField v-if="!noKey" v-model="apiKey" :label="$t('New API key')" :maxlength="4096" autocomplete="new-password" :check-password-strength="false" required />
					<p class="connection-help">{{ keyConfigured ? $t('An API key is stored. Enter it again to save changes; stored keys are never returned.') : $t('The API key is encrypted on the server and cleared from this form after saving.') }}</p>
					<NcCheckboxRadioSwitch v-model:checked="allowRequests">{{ $t('Allow Qdrant requests') }}</NcCheckboxRadioSwitch>
					<p class="connection-help">{{ $t('Allowing requests resumes enabled point delivery and generation cleanup jobs, including cleanup on retained servers. Disabling requests pauses delivery; it does not discard pending operations.') }}</p>
					<p class="connection-help">{{ $t('Remote servers require HTTPS. Private and local servers need an exact origin in the allowlist above and must also pass Nextcloud network policy; saving does not test connectivity.') }}</p>
					<NcButton type="submit" variant="primary" :loading="busy === 'save'">{{ $t('Save connection') }}</NcButton>
				</fieldset>
			</form>
		</details>
	</div>
</template>

<script>
import { ref, onBeforeUnmount } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcPasswordField, NcTextArea, NcTextField } from '@nextcloud/vue'
import { api } from '../../lib/api'
import { translate as t } from '../../lib/i18n'

export default {
	name: 'VectorConnectionSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcPasswordField, NcTextArea, NcTextField },
	props: { enabled: Boolean },
	emits: ['changed'],
	setup(props, { emit }) {
		const endpoint = ref('')
		const privateOriginsText = ref('')
		const apiKey = ref('')
		const noKey = ref(false)
		const allowRequests = ref(false)
		const keyConfigured = ref(false)
		const formReady = ref(false)
		const busy = ref('')
		const notice = ref({ text: '', type: 'info' })
		const controller = new AbortController()
		const request = (method, path, body) => api(method, 'admin/vector-storage/' + path, body, { retries: 0, timeout: 10000, signal: controller.signal })
		function acceptConfiguration(data) {
			if (typeof data?.endpoint !== 'string' || !['api_key', 'none'].includes(data.auth)
				|| typeof data.enabled !== 'boolean' || typeof data.key_configured !== 'boolean'
				|| !Array.isArray(data.private_origins) || data.private_origins.some(origin => typeof origin !== 'string')) throw new Error('Invalid storage configuration')
			endpoint.value = data.endpoint
			privateOriginsText.value = data.private_origins.join('\n')
			noKey.value = data.auth === 'none'
			allowRequests.value = data.enabled
			keyConfigured.value = data.key_configured
			formReady.value = true
		}
		async function loadConfiguration() {
			if (busy.value) return
			busy.value = 'load'
			notice.value.text = ''
			try { acceptConfiguration(await request('GET', 'connection')) }
			catch (e) { if (!controller.signal.aborted) notice.value = { type: 'error', text: t('Connection settings could not be loaded. Retry after checking the instance configuration.') } }
			finally { busy.value = '' }
		}
		async function save() {
			if (busy.value || !formReady.value) return
			busy.value = 'save'
			notice.value.text = ''
			try {
				const privateOrigins = privateOriginsText.value.split(/\r?\n/).map(origin => origin.trim()).filter(Boolean)
				acceptConfiguration(await request('PUT', 'connection', { endpoint: endpoint.value, private_origins: privateOrigins, auth: noKey.value ? 'none' : 'api_key', api_key: noKey.value ? null : apiKey.value, enabled: allowRequests.value }))
				notice.value = { type: 'success', text: t('Connection saved. Search still uses SQL. Use the connection check to verify the saved server.') }
			} catch (e) {
				if (!controller.signal.aborted) notice.value = { type: 'error', text: t('Connection could not be saved. Check the URL, HTTPS and authentication. Reload the settings before retrying; a failed save may have disabled requests.') }
				formReady.value = false
			} finally {
				apiKey.value = ''
				busy.value = ''
				if (!controller.signal.aborted) emit('changed')
			}
		}
		async function disable() {
			if (busy.value) return
			busy.value = 'disable'
			notice.value.text = ''
			try {
				const result = await request('POST', 'disable', {})
				if (result?.enabled !== false) throw new Error('Invalid disable response')
				allowRequests.value = false
				notice.value = { type: 'success', text: t('Storage requests disabled. Pending operations are retained. Search continues to use SQL.') }
			} catch (e) {
				if (!controller.signal.aborted) notice.value = { type: 'error', text: t('Storage requests could not be disabled. Refresh the status before retrying.') }
			} finally {
				busy.value = ''
				if (!controller.signal.aborted) emit('changed')
			}
		}
		async function check() {
			if (busy.value || !props.enabled) return
			busy.value = 'check'
			notice.value.text = ''
			try {
				const result = await request('POST', 'check', {})
				if (typeof result?.available !== 'boolean') throw new Error('Invalid check response')
				notice.value = result.available
					? { type: 'success', text: t('The saved Qdrant API is reachable and supports the required version. Collections, indexed data and migration readiness are not verified.') }
					: { type: 'warning', text: t('The saved connection check failed. Check server availability, supported version, credentials, TLS and the instance network policy.') }
			} catch (e) {
				if (!controller.signal.aborted) notice.value = { type: 'error', text: t('The connection check could not be completed. Refresh the status and retry.') }
			} finally { busy.value = '' }
		}
		function onToggle(event) { if (event.target.open && !formReady.value) loadConfiguration() }
		onBeforeUnmount(() => { controller.abort(); apiKey.value = '' })
		return { endpoint, apiKey, noKey, allowRequests, keyConfigured, formReady, busy, notice, onToggle, loadConfiguration, save, disable, check }
	},
}
</script>

<style scoped>
.vector-connection { margin-top: 16px; }
.connection-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
details { margin-top: 16px; }
summary { cursor: pointer; font-weight: 600; }
form { max-width: 640px; margin-top: 12px; }
fieldset { border: 0; padding: 0; min-width: 0; display: grid; gap: 12px; }
.connection-help { color: var(--color-text-maxcontrast); }
</style>
