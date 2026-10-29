<template>
	<div class="settings-view">
		<header class="page-header">
			<div><h1>{{ currentSection.label }}</h1><p class="page-intro">{{ currentSection.description }}</p></div>
			<div class="settings-save-controls">
				<span class="saved-label" role="status" aria-live="polite">
					{{ loadError ? $t('Settings unavailable') : !formReady ? $t('Loading settings…') : saving ? $t('Saving changes…') : hasUnsavedSettings ? $t('Unsaved changes') : saved ? $t('All changes saved') : $t('Settings are up to date') }}
				</span>
				<NcButton v-if="formReady && hasUnsavedSettings" variant="primary" :loading="saving" :disabled="saveNowLocked" @click="saveNow">{{ $t('Save changes') }}</NcButton>
			</div>
		</header>

		<NcNoteCard v-if="loadError" type="error">
			<strong>{{ $t('Settings could not be loaded.') }}</strong>
			<span>{{ loadError }}</span>
			<NcButton variant="tertiary-no-background" @click="loadStatus(true)">{{ $t('Try again') }}</NcButton>
		</NcNoteCard>
		<NcNoteCard v-if="statusRefreshError" type="info">
			<span>{{ $t('The latest status could not be loaded. Showing the last successful status: {error}', { error: statusRefreshError }) }}</span>
			<NcButton variant="tertiary-no-background" @click="loadStatus()">{{ $t('Try again') }}</NcButton>
		</NcNoteCard>


		<NcNoteCard v-if="message.text" :type="message.type || 'info'">
			<strong>{{ message.type === 'error' ? $t('Something went wrong') : message.type === 'success' ? $t('Done') : $t('Notice') }}</strong>
			<span>{{ message.text }}</span>
		</NcNoteCard>
		<NcNoteCard v-if="validationErrors.length" type="error">
			<strong>{{ $t('Check these values') }}</strong>
			<ul><li v-for="error in validationErrors" :key="error">{{ error }}</li></ul>
		</NcNoteCard>

		<main class="settings-body">

			<div v-if="indexingActive" class="indexing-banner" role="status">
				<div>
					<strong>{{ status?.indexStopping ? $t('Stopping indexing…') : status?.indexMode === 'mail' ? $t('Email indexing is running') : $t('Indexing is running') }}</strong>
					<span>{{ $t('You can leave this page; the background job continues on the server. Settings stay locked until it finishes.') }}</span>
				</div>
				<NcButton variant="secondary" :loading="stopping" :disabled="status?.indexStopping" @click="stopIndex">{{ $t('Stop indexing') }}</NcButton>
			</div>
			<fieldset v-if="active" class="settings-fieldset" :disabled="(group === 'automation' ? briefingsLocked : settingsLocked) || !formReady">
				<GeneralSettings v-if="group === 'general'" />
				<KnowledgeSettings v-if="group === 'knowledge'" />
				<ToolsSettings v-if="group === 'tools'" />
				<AutomationSettings v-if="group === 'automation'" />
				<PersonalSettings v-if="group === 'personal'" />
				<AdvancedSettings v-if="group === 'advanced'" />
			</fieldset>
			<SettingsDiagnostics v-if="active && group === 'advanced'" :health="health" :health-error="healthError" :health-loading="healthLoading" @refresh="loadHealth" @navigate="$emit('navigate', $event)" />
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
import { computed, defineAsyncComponent, onBeforeUnmount, provide, toRef, watch } from 'vue'
import { NcButton, NcModal, NcNoteCard } from '@nextcloud/vue'
import SettingsDiagnostics from '../components/settings/SettingsDiagnostics.vue'
import { settingsSections } from '../lib/settings-sections'
import { useEvaSettings } from '../composables/useEvaSettings'
const GeneralSettings = defineAsyncComponent(() => import('../components/settings/GeneralSettings.vue'))
const KnowledgeSettings = defineAsyncComponent(() => import('../components/settings/KnowledgeSettings.vue'))
const ToolsSettings = defineAsyncComponent(() => import('../components/settings/ToolsSettings.vue'))
const AutomationSettings = defineAsyncComponent(() => import('../components/settings/AutomationSettings.vue'))
const PersonalSettings = defineAsyncComponent(() => import('../components/settings/PersonalSettings.vue'))
const AdvancedSettings = defineAsyncComponent(() => import('../components/settings/AdvancedSettings.vue'))

export default {
	name: 'SettingsView',
	components: { SettingsDiagnostics, NcButton, NcModal, NcNoteCard, GeneralSettings, KnowledgeSettings, ToolsSettings, AutomationSettings, PersonalSettings, AdvancedSettings },
	props: { section: { type: String, default: 'general' }, active: { type: Boolean, default: true } },
	emits: ['navigate'],
	setup(props) {
		const state = useEvaSettings({ section: toRef(props, 'section'), active: toRef(props, 'active') })
		const currentSection = computed(() => settingsSections.find(item => item.id === props.section) || settingsSections[0])
		provide('eva-settings', state)
		let unloadGuardInstalled = false
		const warnBeforeUnload = event => {
			if (!state.hasUnsavedSettings.value) return
			event.preventDefault()
			event.returnValue = ''
		}
		watch(state.hasUnsavedSettings, dirty => {
			if (dirty && !unloadGuardInstalled) {
				window.addEventListener('beforeunload', warnBeforeUnload)
				unloadGuardInstalled = true
			} else if (!dirty && unloadGuardInstalled) {
				window.removeEventListener('beforeunload', warnBeforeUnload)
				unloadGuardInstalled = false
			}
		}, { immediate: true })
		watch(() => props.active, active => {
			if (active && state.formReady.value) state.loadStatus()
			if (!active) state.closeConnectorRemoval()
		})
		onBeforeUnmount(() => {
			if (unloadGuardInstalled) window.removeEventListener('beforeunload', warnBeforeUnload)
		})
		return { ...state, currentSection }
	},
}
</script>

<style lang="scss">
@use '../styles/settings';
</style>
