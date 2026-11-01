<template>
	<div class="admin-tools">
		<VectorStorageStatus />
		<details @toggle="onGenerationToggle">
			<summary>{{ $t('Advanced index administration') }}</summary>
			<NcLoadingIcon v-if="usersLoading" :name="$t('Loading indexed accounts')" />
			<NcNoteCard v-else-if="usersError" type="warning">
				{{ $t('Generation management could not load the account list. Close and reopen this section to retry.') }}
			</NcNoteCard>
			<GenerationEmbeddingSettings v-else-if="generationUsers.length" :users="generationUsers" />
			<p v-else-if="usersLoaded" class="empty-state">{{ $t('No indexed or enrolled accounts are available for generation management.') }}</p>
			<p v-else class="empty-state">{{ $t('Generation controls load only when opened.') }}</p>
		</details>
	</div>
</template>

<script>
	import { ref } from 'vue'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { api as requestApi } from '../lib/api'
import VectorStorageStatus from '../components/settings/VectorStorageStatus.vue'
import GenerationEmbeddingSettings from '../components/settings/GenerationEmbeddingSettings.vue'

const apiGet = (path) => requestApi('GET', path)

export default {
	name: 'AdminView',
	components: { NcLoadingIcon, NcNoteCard, VectorStorageStatus, GenerationEmbeddingSettings },
	setup() {
		const generationUsers = ref([])
		const usersLoading = ref(false)
		const usersLoaded = ref(false)
		const usersError = ref(false)

		async function loadGenerationUsers() {
			if (usersLoaded.value || usersLoading.value) return
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

		function onGenerationToggle(event) {
			if (event.target.open) loadGenerationUsers()
		}
		return { generationUsers, usersLoading, usersLoaded, usersError, onGenerationToggle }
	},
}
</script>

<style scoped>
.admin-tools { display: grid; gap: 16px; }
.admin-tools > p { color: var(--color-text-maxcontrast); }
.admin-tools details { border-top: 1px solid var(--color-border); padding-top: 12px; }
.admin-tools summary { cursor: pointer; font-weight: 600; }
.admin-tools details > *:not(summary) { margin-top: 16px; }
.empty-state { padding: 24px; text-align: center; color: var(--color-text-maxcontrast); }
</style>
