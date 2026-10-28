<template>
	<div class="chat-actions">
		<NcActions :aria-label="t('Conversation actions')">
			<NcActionButton :close-after-click="true" @click="$emit('action', 'images')"><template #icon><NcIconSvgWrapper :path="mdiImageOutline" :size="20" /></template>{{ t('Create images') }}</NcActionButton>
			<NcActionButton :close-after-click="true" @click="$emit('action', 'customize')"><template #icon><NcIconSvgWrapper :path="mdiTune" :size="20" /></template>{{ t('Customize EVA for this chat') }}</NcActionButton>
			<NcActionButton :close-after-click="true" @click="panel = 'prompts'"><template #icon><NcIconSvgWrapper :path="mdiTextBoxOutline" :size="20" /></template>{{ t('Prompt library') }}</NcActionButton>
			<NcActionSeparator />
			<NcActionButton :close-after-click="true" @click="panel = 'exports'"><template #icon><NcIconSvgWrapper :path="mdiDownload" :size="20" /></template>{{ t('Export conversation') }}</NcActionButton>
		</NcActions>
		<NcModal v-if="panel" size="normal" :label-id="titleId" @close="panel = ''">
			<div class="chat-tools-dialog"><h2 :id="titleId">{{ title }}</h2><div ref="host" class="chat-tools-dialog__content"></div></div>
		</NcModal>
	</div>
</template>

<script>
import { computed, ref, watch, useId } from 'vue'
import { NcActions, NcActionButton, NcActionSeparator, NcIconSvgWrapper, NcModal } from '@nextcloud/vue'
import { mdiDownload, mdiImageOutline, mdiTextBoxOutline, mdiTune } from '@mdi/js'
import { translate as t } from '../lib/i18n'

/** Native actions and focus-managed dialogs around the shared chat renderer. */
export default {
	name: 'ChatActions',
	components: { NcActions, NcActionButton, NcActionSeparator, NcIconSvgWrapper, NcModal },
	props: { panels: { type: Object, required: true } },
	emits: ['action'],
	setup(props) {
		const titleId = 'eva-chat-actions-' + useId()
		const panel = ref('')
		const host = ref(null)
		const title = computed(() => panel.value === 'exports' ? t('Export conversation') : t('Prompt library'))
		watch([host, panel], ([element, selected]) => {
			if (element && props.panels[selected]) {
				const content = props.panels[selected]
				if (selected === 'prompts') content.open = true
				element.replaceChildren(content)
			}
		}, { flush: 'post' })
		watch(host, (element, previous) => {
			previous?.removeEventListener('eva-prompt-chosen', closePrompts)
			element?.addEventListener('eva-prompt-chosen', closePrompts)
		}, { flush: 'post' })
		function closePrompts() { panel.value = '' }
		return { titleId, panel, host, title, t, mdiDownload, mdiImageOutline, mdiTextBoxOutline, mdiTune }
	},
}
</script>

<style>
.chat-tools-dialog { padding: 24px; }
.chat-tools-dialog h2 { font-size: 22px; font-weight: 600; margin: 0 0 20px; }
.chat-tools-dialog__content { display: grid; gap: 16px; }
.chat-tools-dialog__content .chat-export-panel { display: grid; gap: 16px; }
.chat-tools-dialog__content .export-options { display: grid; gap: 12px; }
.chat-tools-dialog__content .export-options > summary { display: none; }
.chat-tools-dialog__content label { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.chat-tools-dialog__content .prompt-history { position: static; width: 100%; }
.chat-tools-dialog__content .prompt-history > summary { display: none; }
.chat-tools-dialog__content .prompt-history-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; }
.chat-tools-dialog__content .prompt-history-use { flex: 1; text-align: start; }
.chat-tools-dialog__content .prompt-history-list { max-height: 45vh; overflow-y: auto; }
.chat-tools-dialog__content button, .chat-tools-dialog__content summary { cursor: pointer; }
.chat-tools-dialog__content .export { min-height: 44px; padding: 8px 16px; border-radius: var(--border-radius-pill); border: 1px solid var(--color-border); background: var(--color-main-background); color: var(--color-main-text); font: inherit; }
.chat-tools-dialog__content .export-icon { width: 20px; height: 20px; fill: currentColor; vertical-align: middle; margin-inline-end: 8px; }
</style>
