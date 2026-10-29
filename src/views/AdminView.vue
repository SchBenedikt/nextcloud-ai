<template>
	<div class="admin-tools">
		<p>{{ $t('These optional tools do not change the built-in SQL search path. Installation, indexing and chat work without an external vector service.') }}</p>
		<VectorStorageStatus />
		<NcLoadingIcon v-if="usersLoading" :name="$t('Loading indexed accounts')" />
		<NcNoteCard v-else-if="usersError" type="warning">
			{{ $t('Generation management could not load the account list. Close and reopen this section to retry.') }}
		</NcNoteCard>
		<GenerationEmbeddingSettings v-else-if="generationUsers.length" :users="generationUsers" />
		<p v-else-if="usersLoaded" class="empty-state">{{ $t('No indexed or enrolled accounts are available for generation management.') }}</p>
	</div>
</template>

<script>
import { onMounted, ref } from 'vue'
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

		onMounted(loadGenerationUsers)
		return { generationUsers, usersLoading, usersLoaded, usersError }
	},
}
</script>

<style scoped>
.admin-tools { display: grid; gap: 16px; }
.admin-tools > p { color: var(--color-text-maxcontrast); }
.empty-state { padding: 24px; text-align: center; color: var(--color-text-maxcontrast); }
</style>
