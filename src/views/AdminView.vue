<template>
	<div class="admin-view">
		<NcSettingsSection :name="$t('Knowledge and indexing')"
			:description="$t('EVA uses the built-in Nextcloud database for search. An external vector service is optional.')">
			<p class="default-storage">{{ $t('The SQL generation index is prepared and maintained in the background. Installation does not require Qdrant or another database.') }}</p>
			<details class="advanced" @toggle="onAdvancedToggle">
				<summary>{{ $t('Optional scale and migration tools') }}</summary>
				<div v-if="advancedOpen" class="advanced-content">
					<p>{{ $t('Configure an external vector service or inspect generation tools only when your installation needs them. These settings do not change the default SQL search path.') }}</p>
					<VectorStorageStatus />
					<NcLoadingIcon v-if="usersLoading" :name="$t('Loading indexed accounts')" />
					<NcNoteCard v-else-if="usersError" type="warning">
						{{ $t('Generation management could not load the account list. Close and reopen this section to retry.') }}
					</NcNoteCard>
					<GenerationEmbeddingSettings v-else-if="generationUsers.length" :users="generationUsers" />
					<p v-else-if="usersLoaded" class="empty-state">{{ $t('No indexed or enrolled accounts are available for generation management.') }}</p>
				</div>
			</details>
		</NcSettingsSection>
	</div>
</template>

<script>
import { ref } from 'vue'
import { NcLoadingIcon, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import { api as requestApi } from '../lib/api'
import VectorStorageStatus from '../components/settings/VectorStorageStatus.vue'
import GenerationEmbeddingSettings from '../components/settings/GenerationEmbeddingSettings.vue'

const apiGet = (path) => requestApi('GET', path)

export default {
	name: 'AdminView',
	components: { NcLoadingIcon, NcNoteCard, NcSettingsSection, VectorStorageStatus, GenerationEmbeddingSettings },
	setup() {
		const advancedOpen = ref(false)
		const generationUsers = ref([])
		const usersLoading = ref(false)
		const usersLoaded = ref(false)
		const usersError = ref(false)

		function onAdvancedToggle(event) {
			advancedOpen.value = event.target.open
			if (advancedOpen.value && !usersLoaded.value && !usersLoading.value) loadGenerationUsers()
		}

		async function loadGenerationUsers() {
			usersLoading.value = true
			usersError.value = false
			try {
				const result = await apiGet('admin/overview')
				if (!Array.isArray(result?.users) || result.users.some(user =>
					typeof user?.userId !== 'string' || !user.userId
					|| typeof user.displayName !== 'string')) throw new Error('Invalid generation account list')
				generationUsers.value = result.users.map(user => ({ userId: user.userId, displayName: user.displayName }))
				usersLoaded.value = true
			} catch (_) {
				usersError.value = true
			} finally {
				usersLoading.value = false
			}
		}

		return { advancedOpen, generationUsers, usersLoading, usersLoaded, usersError, onAdvancedToggle }
	},
}
</script>

<style scoped>
.admin-view {
	box-sizing: border-box;
	width: min(100%, 960px);
	margin: 0 auto;
	padding: 24px 20px 48px;
}
.default-storage { margin: 0 0 20px; }
.advanced { border-top: 1px solid var(--color-border); padding-top: 16px; }
.advanced summary { width: fit-content; cursor: pointer; font-weight: 600; }
.advanced-content { margin-top: 16px; }
.advanced-content > p { color: var(--color-text-maxcontrast); }
.empty-state { padding: 24px; text-align: center; color: var(--color-text-maxcontrast); }
@media (max-width: 520px) { .admin-view { padding: 20px 12px 36px; } }
</style>
