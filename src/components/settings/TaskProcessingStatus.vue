<template>
	<section class="assistant-status" aria-live="polite">
		<header>
			<div>
				<h2>{{ $t('Nextcloud Assistant integration') }}</h2>
				<p>{{ $t('Check which native Assistant task types are enabled and routed to EVA.') }}</p>
			</div>
			<NcButton v-if="!loading" type="tertiary" @click="load">
				{{ $t('Refresh status') }}
			</NcButton>
		</header>
		<NcLoadingIcon v-if="loading" :name="$t('Loading Assistant provider status')" />
		<NcNoteCard v-else-if="error" type="warning">{{ $t('Assistant provider status could not be loaded.') }}</NcNoteCard>
		<NcNoteCard v-else-if="!taskTypes.length" type="info">
			{{ $t('EVA has no TaskProcessing providers available on this Nextcloud version.') }}
		</NcNoteCard>
		<template v-else>
			<p class="setup-hint">
				{{ $t('Provider selection is managed by Nextcloud. To route a feature through EVA, choose its Eva provider in Administration settings → AI → Unified task processing.') }}
				<a :href="settingsUrl">{{ $t('Open Nextcloud AI settings') }}</a>
			</p>
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th scope="col">{{ $t('Assistant feature') }}</th>
							<th scope="col">{{ $t('EVA provider') }}</th>
							<th scope="col">{{ $t('Nextcloud status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in taskTypes" :key="item.providerId">
							<td>{{ item.taskTypeName }}</td>
							<td>{{ item.providerName }}</td>
							<td>
								<span v-if="!item.enabled">{{ $t('Task type disabled') }}</span>
								<span v-else-if="!item.assignmentKnown">{{ $t('Provider assignment unknown') }}</span>
								<span v-else-if="item.evaPreferred" class="selected">{{ $t('Selected for this task type') }}</span>
								<span v-else>{{ $t('Using {provider}', { provider: item.preferredProviderName || $t('another provider') }) }}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</section>
</template>

<script>
import { ref } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { api as requestApi } from '../../lib/api'

export default {
	name: 'TaskProcessingStatus',
	components: { NcButton, NcLoadingIcon, NcNoteCard },
	setup() {
		const taskTypes = ref([])
		const loading = ref(true)
		const error = ref(false)
		const settingsUrl = generateUrl('/settings/admin/ai')

		async function load() {
			loading.value = true
			error.value = false
			try {
				const result = await requestApi('GET', 'admin/task-processing')
				if (!Array.isArray(result?.taskTypes) || result.taskTypes.some(item =>
					typeof item?.taskTypeId !== 'string'
					|| typeof item?.providerId !== 'string'
					|| typeof item?.providerName !== 'string'
					|| typeof item?.enabled !== 'boolean'
					|| typeof item?.assignmentKnown !== 'boolean'
					|| typeof item?.evaPreferred !== 'boolean')) {
					throw new Error('Invalid TaskProcessing status')
				}
				taskTypes.value = result.taskTypes
			} catch (_) {
				error.value = true
			} finally {
				loading.value = false
			}
		}

		load()
		return { taskTypes, loading, error, settingsUrl, load }
	},
}
</script>

<style scoped>
.assistant-status { display: grid; gap: 12px; }
.assistant-status header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.assistant-status h2 { margin: 0; font-size: 1.1rem; }
.assistant-status p { margin: 6px 0 0; color: var(--color-text-maxcontrast); }
.setup-hint { max-width: 900px; }
.setup-hint a { display: inline-block; margin-inline-start: 8px; }
.table-wrap { overflow-x: auto; }
.assistant-status table { width: 100%; border-collapse: collapse; }
.assistant-status th, .assistant-status td { padding: 10px 12px; text-align: start; border-bottom: 1px solid var(--color-border); }
.assistant-status th { font-weight: 600; }
.selected { color: var(--color-success); font-weight: 600; }
</style>
