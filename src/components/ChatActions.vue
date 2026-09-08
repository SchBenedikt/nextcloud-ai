<template>
	<template>
		<NcActionButton
			:aria-label="chat.pinned ? $t('Unpin chat') : $t('Pin chat')"
			:close-after-click="true"
			@click.stop="emit('meta', { pinned: !chat.pinned })">
			<template #icon><NcIconSvgWrapper :path="chat.pinned ? mdiPinOffOutline : mdiPinOutline" :size="16" aria-hidden="true" /></template>
			{{ chat.pinned ? $t('Unpin chat') : $t('Pin chat') }}
		</NcActionButton>
		<NcActionButton
			v-if="chat.folder"
			:aria-label="$t('Remove from folder')"
			:close-after-click="true"
			@click.stop="emit('meta', { folder: '' })">
			<template #icon><NcIconSvgWrapper :path="mdiFolderRemoveOutline" :size="16" aria-hidden="true" /></template>
			{{ $t('Remove from folder') }}
		</NcActionButton>
		<NcActionButton
			:aria-label="$t('Move to folder')"
			:close-after-click="true"
			@click.stop="pickFolder()">
			<template #icon><NcIconSvgWrapper :path="mdiFolderPlusOutline" :size="16" aria-hidden="true" /></template>
			{{ chat.folder ? $t('Move to folder') : $t('Add to folder') }}
		</NcActionButton>
		<NcActionButton
			:aria-label="chat.archived ? $t('Unarchive chat') : $t('Archive chat')"
			:close-after-click="true"
			@click.stop="emit('meta', { archived: !chat.archived })">
			<template #icon><NcIconSvgWrapper :path="chat.archived ? mdiArchiveArrowUpOutline : mdiArchiveOutline" :size="16" aria-hidden="true" /></template>
			{{ chat.archived ? $t('Unarchive chat') : $t('Archive chat') }}
		</NcActionButton>
		<NcActionSeparator />
		<NcActionButton
			:aria-label="$t('Rename chat')"
			:close-after-click="true"
			@click.stop="emit('rename')">
			<template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="16" aria-hidden="true" /></template>
			{{ $t('Rename chat') }}
		</NcActionButton>
		<NcActionButton
			:aria-label="$t('Delete chat')"
			:close-after-click="true"
			@click.stop="emit('delete')">
			<template #icon><NcIconSvgWrapper :path="mdiTrashCanOutline" :size="16" aria-hidden="true" /></template>
			{{ $t('Delete chat') }}
		</NcActionButton>
	</template>
</template>

<script>
import { mdiPinOutline, mdiPinOffOutline, mdiFolderPlusOutline, mdiFolderRemoveOutline, mdiArchiveOutline, mdiArchiveArrowUpOutline, mdiPencilOutline, mdiTrashCanOutline } from '@mdi/js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

export default {
	name: 'ChatActions',
	props: {
		chat: { type: Object, required: true },
		folders: { type: Array, default: () => [] },
	},
	emits: ['rename', 'delete', 'meta'],
	components: { NcActionButton, NcActionSeparator, NcIconSvgWrapper },		setup(props, { emit }) {
			const pickFolder = () => {
				// Existing folder names are offered as the default input; typing
				// an unknown name creates that folder, an empty value removes the
				// chat from its current folder (Issue #87).
				const known = props.folders.map((f) => f.name).filter((n) => n !== props.chat.folder)
				const hint = known.length
					? ' (' + known.join(', ') + ')'
					: ''
				const value = window.prompt('Move to folder' + hint, props.chat.folder || '')
				if (value === null) return
				emit('meta', { folder: value.trim() })
			}
			return { pickFolder, mdiPinOutline, mdiPinOffOutline, mdiFolderPlusOutline, mdiFolderRemoveOutline, mdiArchiveOutline, mdiArchiveArrowUpOutline, mdiPencilOutline, mdiTrashCanOutline }
		},
}
</script>