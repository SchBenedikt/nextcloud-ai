import { mdiRobotOutline, mdiFileDocumentOutline, mdiShieldCheckOutline, mdiCalendarClockOutline, mdiAccountOutline, mdiPuzzleOutline } from '@mdi/js'
import { translate as t } from './i18n'

export const settingsSections = [
	{ id: 'general', label: t('AI models'), description: t('Choose the AI services EVA uses for conversations and documents.'), icon: mdiRobotOutline },
	{ id: 'knowledge', label: t('Documents'), description: t('Choose what EVA can search and how your files are indexed.'), icon: mdiFileDocumentOutline },
	{ id: 'tools', label: t('Tools & permissions'), description: t('Choose which actions and connected Nextcloud apps EVA can use.'), icon: mdiShieldCheckOutline },
	{ id: 'automation', label: t('Automation'), description: t('Manage scheduled briefings and their delivery.'), icon: mdiCalendarClockOutline },
	{ id: 'personal', label: t('Personal & data'), description: t('Manage personal knowledge, conversation history and your data.'), icon: mdiAccountOutline },
	{ id: 'advanced', label: t('Advanced'), description: t('Manage external services, API access and diagnostics.'), icon: mdiPuzzleOutline },
]

export const resolveSettingsSection = value => settingsSections.some(section => section.id === value) ? value : 'general'
