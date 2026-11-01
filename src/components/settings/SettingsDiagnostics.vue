<template>
	<NcSettingsSection class="settings-section" :name="$t('Diagnostics & activity')">
					<p v-if="health" role="status">{{ health.ok ? $t('System healthy') : $t('System needs attention') }}</p>
					<p v-if="healthError" role="alert">{{ healthError }}</p>
					<div class="button-group"><NcButton :disabled="healthLoading" @click="$emit('refresh')">{{ $t('Refresh diagnosis') }}</NcButton><NcButton @click="$emit('navigate', 'metrics')">{{ $t('Usage metrics') }}</NcButton><NcButton @click="$emit('navigate', 'runs')">{{ $t('Background tasks') }}</NcButton></div>
				</NcSettingsSection>
</template>

<script>
import { NcButton, NcSettingsSection } from '@nextcloud/vue'

export default {
	name: 'SettingsDiagnostics',
	components: { NcButton, NcSettingsSection },
	props: {
		health: { type: Object, default: null },
		healthError: { type: String, default: '' },
		healthLoading: Boolean,
	},
	emits: ['refresh', 'navigate'],
}
</script>
