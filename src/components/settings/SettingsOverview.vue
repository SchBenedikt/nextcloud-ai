<template>
	<section class="settings-overview" :aria-label="$t('Overview')">
		<div class="settings-overview__grid">
			<button v-for="section in sections" :key="section.id" class="settings-overview__card" type="button" @click="$emit('navigate', section.id)">
				<span class="settings-overview__icon"><NcIconSvgWrapper :path="section.icon" :size="22" aria-hidden="true" /></span>
				<span class="settings-overview__copy">
					<strong>{{ section.label }}</strong>
					<span>{{ section.description }}</span>
				</span>
				<NcIconSvgWrapper class="settings-overview__arrow" :path="mdiArrowRight" :size="18" aria-hidden="true" />
			</button>
		</div>
	</section>
</template>

<script>
import { NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiArrowRight } from '@mdi/js'

export default {
	name: 'SettingsOverview',
	components: { NcIconSvgWrapper },
	props: { sections: { type: Array, required: true } },
	emits: ['navigate'],
	setup() { return { mdiArrowRight } },
}
</script>

<style scoped>
.settings-overview { margin-block: 4px 28px; }
.settings-overview__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.settings-overview__card { display: flex; align-items: flex-start; gap: 14px; min-width: 0; padding: 18px; color: var(--color-main-text); text-align: start; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); cursor: pointer; transition: border-color 120ms ease, background-color 120ms ease; }
.settings-overview__card:hover, .settings-overview__card:focus-visible { background: var(--color-background-hover); border-color: var(--color-primary-element); }
.settings-overview__card:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
.settings-overview__icon { display: grid; place-items: center; width: 38px; height: 38px; flex: none; color: var(--color-primary-element); background: var(--color-background-hover); border-radius: var(--border-radius-large, 12px); }
.settings-overview__copy { display: grid; gap: 6px; min-width: 0; flex: 1; }
.settings-overview__copy strong { font-weight: 600; }
.settings-overview__copy > span { color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.45; }
.settings-overview__arrow { flex: none; margin-block-start: 2px; color: var(--color-text-maxcontrast); }
@media (max-width: 700px) { .settings-overview__grid { grid-template-columns: 1fr; } }
</style>
