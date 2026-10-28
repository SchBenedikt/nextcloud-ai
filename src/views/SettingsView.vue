<template>
	<div class="settings-view">
		<header class="page-header">
			<div><h1>{{ $t('Settings') }}</h1><p class="page-intro">{{ $t('Make EVA work for you. Start with a model, then choose the tools you need.') }}</p></div>
			<span class="saved-label" role="status">{{ saving ? $t('Saving…') : saved ? $t('Saved') : $t('Core settings save automatically') }}</span>
		</header>
		<nav class="settings-sections" :aria-label="$t('Settings sections')">
			<NcButton v-for="item in groups" :key="item.id" :variant="group === item.id ? 'primary' : 'tertiary'" :aria-pressed="group === item.id" @click="group = item.id">{{ item.label }}</NcButton>
		</nav>

		<div v-if="loadError" class="callout callout-error" role="alert">
			<strong>{{ $t('Settings could not be loaded.') }}</strong>
			<span>{{ loadError }}</span>
			<NcButton variant="tertiary-no-background" @click="loadStatus(true)">{{ $t('Try again') }}</NcButton>
		</div>
		<div v-if="statusRefreshError" class="callout callout-info" role="status">
			<span>{{ $t('The latest status could not be loaded. Showing the last successful status: {error}', { error: statusRefreshError }) }}</span>
			<NcButton variant="tertiary-no-background" @click="loadStatus()">{{ $t('Try again') }}</NcButton>
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
				<NcButton variant="secondary" :loading="stopping" :disabled="status?.indexStopping" @click="stopIndex">{{ $t('Stop indexing') }}</NcButton>
			</div>
			<fieldset class="settings-fieldset" :disabled="(group === 'automation' ? briefingsLocked : settingsLocked) || !formReady">
				<GeneralSettings v-if="group === 'general'" />
				<KnowledgeSettings v-if="group === 'knowledge'" />
				<ToolsSettings v-if="group === 'tools'" />
				<AutomationSettings v-if="group === 'automation'" />
				<PersonalSettings v-if="group === 'personal'" />
				<AdvancedSettings v-if="group === 'advanced'" />
			</fieldset>
			<template v-if="group === 'advanced'">
				<section class="settings-section"><h2>{{ $t('Diagnostics & activity') }}</h2>
					<p v-if="health" role="status">{{ health.ok ? $t('System healthy') : $t('System needs attention') }}</p>
					<p v-if="healthError" role="alert">{{ healthError }}</p>
					<div class="button-group"><NcButton :disabled="healthLoading" @click="loadHealth">{{ $t('Refresh diagnosis') }}</NcButton><NcButton @click="$emit('navigate', 'metrics')">{{ $t('Usage metrics') }}</NcButton><NcButton @click="$emit('navigate', 'runs')">{{ $t('Background tasks') }}</NcButton></div>
				</section>
			</template>
		</main>

		<NcModal v-if="connectorToRemove" size="small" :name="$t('Remove connector')" @close="closeConnectorRemoval">
			<div class="connector-removal-dialog">
				<p>{{ $t('Remove {name}? This also deletes its saved credentials and discovered API routes. EVA will no longer be able to use this service.', { name: connectorToRemove.name || connectorToRemove.id }) }}</p>
				<p class="field-help">{{ connectorToRemove.id }} · {{ connectorToRemove.base_url }}</p>
				<p v-if="connectorRemovalError" class="connector-removal-error" role="alert">{{ connectorRemovalError }}</p>
				<div class="connector-removal-actions">
					<NcButton type="button" variant="tertiary" :disabled="connectorsBusy" @click="closeConnectorRemoval">{{ $t('Cancel') }}</NcButton>
					<NcButton type="button" variant="error" :loading="connectorsBusy" :disabled="connectorsBusy" @click="confirmRemoveConnector">{{ $t('Remove connector') }}</NcButton>
				</div>
			</div>
		</NcModal>
	</div>

</template>

<script>
import { defineAsyncComponent, provide } from 'vue'
import { NcButton, NcModal } from '@nextcloud/vue'
import { useEvaSettings } from '../composables/useEvaSettings'
const GeneralSettings = defineAsyncComponent(() => import('../components/settings/GeneralSettings.vue'))
const KnowledgeSettings = defineAsyncComponent(() => import('../components/settings/KnowledgeSettings.vue'))
const ToolsSettings = defineAsyncComponent(() => import('../components/settings/ToolsSettings.vue'))
const AutomationSettings = defineAsyncComponent(() => import('../components/settings/AutomationSettings.vue'))
const PersonalSettings = defineAsyncComponent(() => import('../components/settings/PersonalSettings.vue'))
const AdvancedSettings = defineAsyncComponent(() => import('../components/settings/AdvancedSettings.vue'))

export default {
	name: 'SettingsView',
	components: { NcButton, NcModal, GeneralSettings, KnowledgeSettings, ToolsSettings, AutomationSettings, PersonalSettings, AdvancedSettings },
	emits: ['navigate'],
	setup() {
		const state = useEvaSettings()
		provide('eva-settings', state)
		return state
	},
}
</script>

<style lang="scss">
@use '../styles/settings';
</style>
