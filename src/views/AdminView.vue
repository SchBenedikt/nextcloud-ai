<template>
	<div class="admin-view">
		<header class="page-header">
			<div>
				<h1>{{ $t('Index overview') }}</h1>
				<p class="page-intro">{{ $t('EVA uses the built-in Nextcloud database automatically. Optional vector storage and generation tools are available for larger installations.') }}</p>
			</div>
			<NcButton variant="secondary" :loading="loading" @click="load">{{ $t('Refresh') }}</NcButton>
		</header>

		<NcNoteCard v-if="loadError" type="error">
			<p>{{ $t('The admin overview could not be loaded.') }}</p>
			<p>{{ loadError }}</p>
		</NcNoteCard>

		<NcLoadingIcon v-else-if="loading && !data" :name="$t('Loading admin overview')" />

		<template v-else-if="data">
			<section class="overview" :aria-label="$t('Index overview')">
				<div class="overview-heading">
					<h2>{{ $t('Index overview') }}</h2>
					<p>{{ $t('{count} accounts have indexed content', { count: data.index.accounts }) }}</p>
				</div>
				<dl class="metrics">
					<div><dt>{{ $t('Documents') }}</dt><dd>{{ data.index.documents }}</dd></div>
					<div><dt>{{ $t('Search chunks') }}</dt><dd>{{ data.index.chunks }}</dd></div>
					<div><dt>{{ $t('Index tasks running') }}</dt><dd>{{ data.scheduler.running }} / {{ data.scheduler.limit }}</dd></div>
					<div><dt>{{ $t('Tasks waiting') }}</dt><dd>{{ data.scheduler.queued }}</dd></div>
				</dl>
			</section>
		</template>

		<p v-else class="empty-state">{{ $t('The index overview is unavailable. Use Refresh to try again.') }}</p>

		<details class="advanced" @toggle="onAdvancedToggle">
			<summary>{{ $t('Advanced vector storage and migration') }}</summary>
			<div v-if="advancedOpen" class="advanced-content">
				<p>{{ $t('The built-in database remains the default. Configure an external vector service and prepare a verified generation only when you need additional scale.') }}</p>
				<VectorStorageStatus />
				<NcLoadingIcon v-if="usersLoading" :name="$t('Loading indexed accounts')" />
				<NcNoteCard v-else-if="usersError" type="warning">
					{{ $t('Generation management could not load the account list. Close and reopen this section to retry.') }}
				</NcNoteCard>
				<GenerationEmbeddingSettings v-else-if="generationUsers.length" :users="generationUsers" />
				<p v-else-if="usersLoaded" class="empty-state">{{ $t('No indexed or enrolled accounts are available for generation management.') }}</p>
			</div>
		</details>
	</div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { api as requestApi, errMsg } from '../lib/api'
import VectorStorageStatus from '../components/settings/VectorStorageStatus.vue'
import GenerationEmbeddingSettings from '../components/settings/GenerationEmbeddingSettings.vue'

const apiGet = (path) => requestApi('GET', path)

export default {
	name: 'AdminView',
	components: { NcButton, NcLoadingIcon, NcNoteCard, VectorStorageStatus, GenerationEmbeddingSettings },
	setup() {
		const data = ref(null)
		const loading = ref(false)
		const loadError = ref('')
		const advancedOpen = ref(false)
		const generationUsers = ref([])
		const usersLoading = ref(false)
		const usersLoaded = ref(false)
		const usersError = ref(false)

		async function load() {
			loading.value = true
			loadError.value = ''
			try {
				const result = await apiGet('admin/summary')
				if (!result?.index || !result?.scheduler
					|| !['accounts', 'documents', 'chunks'].every((key) => Number.isSafeInteger(result.index[key]) && result.index[key] >= 0)
					|| !['running', 'queued', 'limit'].every((key) => Number.isSafeInteger(result.scheduler[key]) && result.scheduler[key] >= 0)) {
					throw new Error('The admin summary response was incomplete.')
				}
				data.value = result
			} catch (error) {
				loadError.value = errMsg(error)
			} finally {
				loading.value = false
			}
		}

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

		onMounted(load)
		return { data, loading, loadError, advancedOpen, generationUsers, usersLoading, usersLoaded, usersError, load, onAdvancedToggle }
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
.page-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 28px;
}
h1 { margin: 0; font-size: 24px; }
.page-intro { max-width: 620px; margin: 8px 0 0; color: var(--color-text-maxcontrast); }
.overview {
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
}
.overview-heading h2 { margin: 0; font-size: 18px; }
.overview-heading p { margin: 6px 0 0; color: var(--color-text-maxcontrast); }
.metrics {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 12px;
	margin: 20px 0 0;
}
.metrics > div { min-width: 0; padding: 14px; border-radius: var(--border-radius, 8px); background: var(--color-background-dark); }
.metrics dt { color: var(--color-text-maxcontrast); font-size: 13px; }
.metrics dd { margin: 8px 0 0; font-size: 24px; font-weight: 600; font-variant-numeric: tabular-nums; }
.advanced { margin-top: 24px; border-top: 1px solid var(--color-border); padding-top: 18px; }
.advanced summary { width: fit-content; cursor: pointer; font-weight: 600; }
.advanced-content { margin-top: 16px; }
.advanced-content > p { color: var(--color-text-maxcontrast); }
.empty-state { padding: 24px; text-align: center; color: var(--color-text-maxcontrast); }
@media (max-width: 760px) {
	.metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 520px) {
	.admin-view { padding: 20px 12px 36px; }
	.page-header { align-items: center; }
	.metrics > div { padding: 12px; }
	.metrics dd { font-size: 20px; }
}
</style>
