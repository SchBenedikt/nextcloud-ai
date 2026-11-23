<template>
	<section class="assistant-status" aria-live="polite">
		<header class="status-header">
			<div>
				<h2>{{ $t('Nextcloud Assistant integration') }}</h2>
				<p>{{ $t('See which native Assistant tasks are ready to run through EVA.') }}</p>
			</div>
			<NcButton v-if="!loading" type="tertiary" @click="load">
				{{ $t('Refresh status') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" :name="$t('Loading Assistant provider status')" />
		<NcNoteCard v-else-if="error" type="warning">
			{{ $t('Assistant provider status could not be loaded. Refresh the page or check the Nextcloud log.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!taskTypes.length" type="info">
			{{ $t('EVA has no TaskProcessing providers available on this Nextcloud version.') }}
		</NcNoteCard>
		<template v-else>
			<div class="summary" :class="summaryClass" role="status">
				<strong>{{ summaryTitle }}</strong>
				<span>{{ summaryDescription }}</span>
			</div>

			<div class="metrics" aria-label="Assistant integration summary">
				<div class="metric"><strong>{{ selectedCount }}</strong><span>{{ $t('Routed through EVA') }}</span></div>
				<div class="metric"><strong>{{ availableCount }}</strong><span>{{ $t('Enabled task types') }}</span></div>
				<div class="metric"><strong>{{ unknownCount }}</strong><span>{{ $t('Availability unknown') }}</span></div>
			</div>

			<div class="setup-hint">
				<div>
					<strong>{{ $t('Choose where Nextcloud sends each task') }}</strong>
					<p>{{ $t('Provider selection is managed by Nextcloud. Select an EVA provider for each enabled feature you want EVA to handle.') }}</p>
				</div>
				<a :href="settingsUrl">{{ $t('Open Nextcloud AI settings') }}<span aria-hidden="true"> ↗</span></a>
			</div>

			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th scope="col">{{ $t('Assistant feature') }}</th>
							<th scope="col">{{ $t('EVA provider') }}</th>
							<th scope="col">{{ $t('Routing status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in taskTypes" :key="item.providerId">
							<td>{{ item.taskTypeName }}</td>
							<td>{{ item.providerName }}</td>
							<td>
								<span class="state" :class="stateClass(item)">
									{{ stateLabel(item) }}
								</span>
								<span v-if="item.enabled && item.assignmentKnown && !item.evaPreferred" class="current-provider">
									{{ $t('Currently using {provider}', { provider: item.preferredProviderName || $t('another provider') }) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p class="updated">{{ $t('Status reflects the current Nextcloud provider configuration.') }}</p>
		</template>
	</section>
</template>

<script>
import { computed, ref } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '../../lib/i18n'
import { api as requestApi } from '../../lib/api'

export default {
	name: 'TaskProcessingStatus',
	components: { NcButton, NcLoadingIcon, NcNoteCard },
	setup() {
		const taskTypes = ref([])
		const loading = ref(true)
		const error = ref(false)
		const settingsUrl = generateUrl('/settings/admin/ai')
		const selectedCount = computed(() => taskTypes.value.filter(item => item.enabled && item.assignmentKnown && item.evaPreferred).length)
		const availableCount = computed(() => taskTypes.value.filter(item => item.enabledKnown && item.enabled).length)
		const unknownCount = computed(() => taskTypes.value.filter(item => !item.enabledKnown || !item.assignmentKnown).length)
		const needsAttentionCount = computed(() => taskTypes.value.filter(item => !item.enabledKnown || !item.assignmentKnown || (item.enabled && !item.evaPreferred)).length)
		const summaryClass = computed(() => selectedCount.value === 0 ? 'summary-neutral' : needsAttentionCount.value === 0 ? 'summary-ready' : 'summary-partial')
		const summaryTitle = computed(() => selectedCount.value === 0
			? unknownCount.value > 0
				? t('Some task type availability is unknown')
				: availableCount.value === 0 ? t('No Assistant task types are enabled') : t('EVA is available but not selected yet')
			: needsAttentionCount.value === 0
				? t('All available EVA providers are selected')
				: t('EVA is handling some Assistant tasks'))
		const summaryDescription = computed(() => selectedCount.value === 0
			? unknownCount.value > 0
				? t('Nextcloud could not confirm availability for every task type. Check the provider assignment and Nextcloud version.')
				: availableCount.value === 0 ? t('Enable a task type in Nextcloud AI settings before choosing its provider.') : t('Choose EVA in Nextcloud AI settings to start using it for Assistant tasks.')
			: needsAttentionCount.value === 0
				? t('Every detected and enabled task type currently uses EVA.')
				: t('Review the routing list below to enable or route more task types through EVA.'))

		function stateLabel(item) {
			if (!item.enabledKnown || !item.assignmentKnown) return t('Availability could not be determined')
			if (!item.enabled) return t('Disabled in Nextcloud')
			if (item.evaPreferred) return t('Routed through EVA')
			return t('Another provider selected')
		}

		function stateClass(item) {
			if (!item.enabledKnown || !item.assignmentKnown) return 'state-warning'
			if (!item.enabled) return 'state-muted'
			return item.evaPreferred ? 'state-ready' : 'state-pending'
		}

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
					|| typeof item?.enabledKnown !== 'boolean'
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
		return { taskTypes, loading, error, settingsUrl, load, selectedCount, availableCount, unknownCount, summaryClass, summaryTitle, summaryDescription, stateLabel, stateClass }
	},
}
</script>

<style scoped>
.assistant-status { display: grid; gap: 16px; }
.status-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.assistant-status h2 { margin: 0; font-size: 1.1rem; }
.assistant-status p { margin: 6px 0 0; color: var(--color-text-maxcontrast); }
.summary { display: grid; gap: 4px; padding: 14px 16px; border-inline-start: 4px solid var(--color-border); border-radius: var(--border-radius); background: var(--color-background-hover); }
.summary-ready { border-inline-start-color: var(--color-success); }
.summary-partial { border-inline-start-color: var(--color-warning); }
.summary-neutral { border-inline-start-color: var(--color-primary-element); }
.summary span, .current-provider, .updated { color: var(--color-text-maxcontrast); }
.metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.metric { display: grid; gap: 3px; padding: 12px 14px; border: 1px solid var(--color-border); border-radius: var(--border-radius); }
.metric strong { font-size: 1.25rem; }
.metric span { color: var(--color-text-maxcontrast); font-size: .9rem; }
.setup-hint { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 16px; border-radius: var(--border-radius); background: var(--color-background-dark); }
.setup-hint p { max-width: 760px; }
.setup-hint a { flex: 0 0 auto; }
.table-wrap { overflow-x: auto; }
.assistant-status table { width: 100%; border-collapse: collapse; }
.assistant-status th, .assistant-status td { padding: 10px 12px; text-align: start; border-bottom: 1px solid var(--color-border); }
.assistant-status th { font-weight: 600; }
.state { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: .85rem; font-weight: 600; }
.state-ready { color: var(--color-success); background: var(--color-success-hover); }
.state-pending { color: var(--color-primary-element); background: var(--color-background-hover); }
.state-warning { color: var(--color-warning); background: var(--color-background-hover); }
.state-muted { color: var(--color-text-maxcontrast); background: var(--color-background-hover); }
.current-provider { display: block; margin-top: 4px; font-size: .85rem; }
.updated { margin: -6px 0 0; font-size: .85rem; }
@media (max-width: 700px) {
	.metrics { grid-template-columns: 1fr; }
	.setup-hint { align-items: flex-start; flex-direction: column; }
	.status-header { flex-direction: column; }
}
</style>
