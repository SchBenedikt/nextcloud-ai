import { translate as t, translatePlural as n } from '@nextcloud/l10n'

const APP_ID = 'eva_ai'

export function translate(text, placeholders) {
	return t(APP_ID, text, placeholders)
}

export function translatePlural(singular, plural, count, placeholders) {
	return n(APP_ID, singular, plural, count, placeholders)
}

export { APP_ID }
