<template>
<div class="settings-group">
<section id="settings-briefings" class="settings-section briefing-section">
				<div class="section-heading"><div><h3>{{ $t('Scheduled briefings') }}</h3><p>{{ $t('EVA can prepare recurring answers and deliver them by notification, email or Talk. They are read-only unless you explicitly enable actions per briefing.') }}</p></div><span class="briefing-count">{{ proactiveBriefings.length }} / 20</span></div>
					<NcCheckboxRadioSwitch v-model="proactiveEnabled" type="switch" class="native-toggle">{{ $t('Enable scheduled briefings') }}</NcCheckboxRadioSwitch>
					<div v-if="proactiveEnabled" class="briefing-note"><strong>{{ $t('How this works') }}</strong><span>{{ $t('Briefings use your server cron and account timezone. Every briefing is read-only by default. If you enable actions on one briefing, EVA may perform the requested changes automatically and reports the result through the selected delivery channels.') }}</span></div>
					<div v-if="proactiveEnabled" class="briefing-editor">
						<div v-if="!proactiveBriefings.length" class="briefing-empty"><strong>{{ $t('No briefings yet') }}</strong><span>{{ $t('Add your first briefing below, for example a morning calendar summary or a weekly file digest.') }}</span></div>
						<div v-for="briefing in proactiveBriefings" :key="briefing.id" class="briefing-card">
							<div class="briefing-card-top"><div class="briefing-time"><span>{{ briefing.time }}</span><small>{{ briefing.days.map(dayName).join(' · ') }}</small></div><div class="briefing-card-actions"><NcCheckboxRadioSwitch :model-value="briefing.enabled !== false" type="switch" :aria-label="$t('Toggle briefing')" @update:model-value="toggleBriefing(briefing.id)">{{ briefing.enabled === false ? $t('Paused') : $t('Active') }}</NcCheckboxRadioSwitch><NcButton variant="tertiary-no-background" @click="editBriefing(briefing)">{{ $t('Edit') }}</NcButton><NcButton variant="tertiary-no-background" :disabled="briefing.enabled === false" @click="runBriefingNow(briefing.id)">{{ $t('Run now') }}</NcButton><NcButton variant="tertiary-no-background" @click="toggleBriefingActions(briefing.id)">{{ briefing.allow_actions ? $t('Disable actions') : $t('Enable actions') }}</NcButton><NcButton variant="tertiary-no-background" @click="removeBriefing(briefing.id)">{{ $t('Remove') }}</NcButton></div></div>
							<p class="briefing-prompt">{{ briefing.prompt }}</p><small class="briefing-next">{{ briefing.enabled === false ? $t('Paused') : $t('Runs on {days} at {time}', { days: briefing.days.map(dayName).join(', '), time: briefing.time }) }} · {{ briefingTypeName(briefing.type) }} · {{ (briefing.channels || ['notification']).map(channelName).join(', ') }} <span v-if="briefing.allow_actions" class="briefing-action-badge">{{ $t('Actions enabled') }}</span></small>
						</div>
						<div class="briefing-form"><div class="briefing-form-title"><strong>{{ editingBriefingId ? $t('Edit briefing') : $t('Create a briefing') }}</strong><span>{{ $t('Choose what EVA should prepare and when you want it.') }}</span></div>
						<div class="field-grid">
							<div class="field field-wide"><NcTextField v-model="briefingDraft.prompt" :label="$t('What should EVA do?')" :placeholder="$t('For example: summarize my calendar for today')" /><p class="field-help">{{ $t('Write a question or instruction in plain language. You can ask about files, calendar, tasks or your knowledge base.') }}</p></div>
							<div class="field"><NcSelect input-id="briefing-type" v-model="briefingDraft.type" :options="briefingTypeOptions" label="label" :reduce="option => option.value" :input-label="$t('Briefing type')" :label-outside="true" /></div>
							<div class="briefing-time-field"><NcTextField v-model="briefingDraft.time" type="time" :label="$t('Time')" /></div>
						</div>
						<div><span class="native-label">{{ $t('Delivery channels') }}</span><div class="weekday-picker"><NcCheckboxRadioSwitch v-model="briefingDraft.channels" type="checkbox" value="notification">{{ $t('Nextcloud notification') }}</NcCheckboxRadioSwitch><NcCheckboxRadioSwitch v-model="briefingDraft.channels" type="checkbox" value="email">{{ $t('Email') }}</NcCheckboxRadioSwitch><NcCheckboxRadioSwitch v-model="briefingDraft.channels" type="checkbox" value="talk">{{ $t('Nextcloud Talk') }}</NcCheckboxRadioSwitch></div></div>
						<div v-if="briefingDraft.channels.includes('talk')" class="field"><NcTextField v-model="briefingDraft.talk_room" :label="$t('Talk room name or token')" /><p class="field-help">{{ $t('EVA checks that you are still a member of this room and that Talk posting is enabled in your settings.') }}</p></div>
						<NcCheckboxRadioSwitch v-model="briefingDraft.allow_actions" type="switch">{{ $t('Allow EVA to perform requested actions automatically') }}</NcCheckboxRadioSwitch>
						<p v-if="briefingDraft.allow_actions" class="field-help briefing-action-warning">{{ $t('Use only for prompts you trust. EVA will execute needed changes in the background without a second dialog; generic app APIs still need the encrypted Nextcloud app token.') }}</p>
						<div><span class="native-label">{{ $t('Repeat on') }}</span><div class="weekday-picker"><NcCheckboxRadioSwitch v-for="day in weekdays" :key="day.value" v-model="briefingDraft.days" type="checkbox" :value="day.value">{{ day.label }}</NcCheckboxRadioSwitch></div></div>
						<div class="briefing-form-actions"><NcButton variant="primary" :disabled="saving || (!editingBriefingId && proactiveBriefings.length >= 20)" @click="saveBriefing">{{ editingBriefingId ? $t('Save changes') : $t('Add briefing') }}</NcButton><NcButton v-if="editingBriefingId" variant="tertiary" @click="cancelEditBriefing">{{ $t('Cancel') }}</NcButton><span>{{ $t('{count} slots remaining', { count: Math.max(0, 20 - proactiveBriefings.length) }) }}</span></div><p v-if="briefingFormError" class="field-help briefing-action-warning" role="alert">{{ briefingFormError }}</p></div>
					</div>
					<div v-if="briefingHistory.length" class="briefing-history"><h4>{{ $t('Recent briefing history') }}</h4><article v-for="(run, index) in briefingHistory.slice(0, 10)" :key="run.id + '-' + run.time + '-' + index" class="briefing-history-row"><div><strong>{{ briefingTypeName(run.type) }}</strong><small>{{ formatDateTime(run.time) }} · {{ run.manual ? $t('Run now') : $t('Scheduled') }} · {{ $t(run.status) }} · {{ (run.channels || []).map(channelName).join(', ') }}<span v-if="failureSummary(run)"> · {{ failureSummary(run) }}</span></small></div><p>{{ run.text || failureSummary(run) || $t('No response was delivered.') }}</p></article></div>
			</section>
</div>
</template>

<script>
import { inject } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
 name: 'AutomationSettings',
 components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField },
 setup() { return inject('eva-settings') },
}
</script>
