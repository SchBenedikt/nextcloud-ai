import { translate as t } from './i18n'

/*
 * Tool confirmation forms (web chat).
 *
 * Every mutating/destructive tool the model may propose gets a small,
 * schema-driven form. The chat renders one schema per confirmation request
 * (instead of dumping raw JSON), so users see a readable title, review the
 * concrete values and adjust them before approving.
 *
 * Field types:
 *   text / textarea / password / number / date / datetime / checkbox / select
 * Fields can declare `required`, `full` (span the whole grid row) and
 * `showWhen` (value-dependent visibility, e.g. the link-share password field).
 */

const OPTIONS = (pairs) => pairs.map(([value, labelKey]) => ({ value, label: t(labelKey) }))

const F = (key, labelKey, type = 'text', extra = {}) => ({ key, label: t(labelKey), type, ...extra })

/**
 * Per-tool confirmation form definitions.
 * `title` is a translation key describing the action for the heading.
 */
const FORMS = {
	create_share: {
		title: 'Create a share',
		fields: [
			F('path', 'File or folder path', 'text', { required: true, full: true }),
			F('type', 'Share type', 'select', { options: OPTIONS([['link', 'Public link'], ['user', 'Nextcloud user'], ['group', 'Nextcloud group']]) }),
			F('target', 'Recipient user or group', 'text', { required: true, showWhen: { key: 'type', values: ['user', 'group'] } }),
			F('write', 'Allow editing', 'checkbox'),
			F('share', 'Allow resharing', 'checkbox'),
			F('password', 'Link password (optional)', 'password', { showWhen: { key: 'type', values: ['link'] } }),
			F('expiration', 'Expiration date (optional)', 'date', { showWhen: { key: 'type', values: ['link'] } }),
			F('note', 'Message or note (optional)', 'textarea', { full: true }),
		],
	},
	update_share: {
		title: 'Update a share',
		fields: [
			F('share_id', 'Share id', 'text', { required: true }),
			F('note', 'Message or note (optional)', 'textarea', { full: true }),
			F('expiration', 'Expiration date (optional)', 'date'),
			F('permissions', 'Permissions', 'text'),
		],
	},
	delete_share: {
		title: 'Delete a share',
		fields: [F('share_id', 'Share id', 'text', { required: true })],
	},
	create_calendar_event: {
		title: 'Create a calendar event',
		fields: [
			F('summary', 'Summary', 'text', { required: true, full: true }),
			F('start', 'Start date', 'date', { required: true }),
			F('start_time', 'Start time', 'time'),
			F('end', 'End date', 'date'),
			F('end_time', 'End time', 'time'),
			F('duration_minutes', 'Duration (minutes)', 'number'),
			F('location', 'Location', 'text'),
			F('calendar', 'Calendar', 'calendar'),
			F('categories', 'Categories', 'text'),
			F('reminder_minutes', 'Reminder (minutes before)', 'number'),
			F('description', 'Description', 'textarea', { full: true }),
		],
	},

	update_calendar_event: {
		title: 'Update a calendar event',
		fields: [
			F('event_id', 'Event id', 'text', { required: true }),
			F('summary', 'Summary', 'text'),
			F('start', 'Start', 'datetime'),
			F('end', 'End', 'datetime'),
			F('location', 'Location', 'text'),
			F('categories', 'Categories', 'text'),
			F('reminder_minutes', 'Reminder (minutes before)', 'number'),
			F('description', 'Description', 'textarea', { full: true }),
		],
	},
	delete_calendar_event: {
		title: 'Delete a calendar event',
		fields: [F('event_id', 'Event id', 'text', { required: true })],
	},
	create_task: {
		title: 'Create a task',
		fields: [
			F('title', 'Title', 'text', { required: true, full: true }),
			F('due', 'Due', 'datetime'),
			F('priority', 'Priority', 'number'),
			F('categories', 'Categories', 'text'),
			F('description', 'Description', 'textarea', { full: true }),
		],
	},
	update_task: {
		title: 'Update a task',
		fields: [
			F('task_id', 'Task id', 'text', { required: true }),
			F('title', 'Title', 'text'),
			F('status', 'Status', 'select', { options: OPTIONS([['NEEDS-ACTION', 'To do'], ['IN-PROCESS', 'In progress'], ['COMPLETED', 'Done'], ['CANCELLED', 'Cancelled']]) }),
			F('due', 'Due', 'datetime'),
			F('priority', 'Priority', 'number'),
			F('categories', 'Categories', 'text'),
			F('description', 'Description', 'textarea', { full: true }),
		],
	},
	complete_task: {
		title: 'Complete a task',
		fields: [F('task_id', 'Task id', 'text', { required: true })],
	},
	delete_task: {
		title: 'Delete a task',
		fields: [F('task_id', 'Task id', 'text', { required: true })],
	},
	create_file: {
		title: 'Create a file',
		fields: [
			F('path', 'File or folder path', 'text', { required: true }),
			F('content', 'Content', 'textarea', { required: true, full: true }),
		],
	},
	create_note: {
		title: 'Create a note',
		fields: [
			F('title', 'Title', 'text', { required: true }),
			F('content', 'Content', 'textarea', { required: true, full: true }),
		],
	},
	create_folder: {
		title: 'Create a folder',
		fields: [F('path', 'Folder path', 'text', { required: true })],
	},
	rename_file: {
		title: 'Rename a file or folder',
		fields: [
			F('path', 'Current path', 'text', { required: true }),
			F('new_name', 'New name', 'text', { required: true }),
		],
	},
	delete_file: {
		title: 'Delete a file',
		fields: [F('path', 'File or folder path', 'text', { required: true })],
	},
	update_knowledge: {
		title: 'Remember a fact',
		fields: [F('fact', 'Fact', 'textarea', { required: true, full: true })],
	},
	create_contact: {
		title: 'Create a contact',
		fields: [
			F('name', 'Name', 'text', { required: true }),
			F('email', 'E-mail', 'text'),
			F('phone', 'Phone', 'text'),
			F('org', 'Organisation', 'text'),
		],
	},
	update_contact: {
		title: 'Update a contact',
		fields: [
			F('query', 'Query', 'text', { required: true }),
			F('name', 'Name', 'text'),
			F('email', 'E-mail', 'text'),
			F('phone', 'Phone', 'text'),
			F('org', 'Organisation', 'text'),
		],
	},
	delete_contact: {
		title: 'Delete a contact',
		fields: [F('query', 'Query', 'text', { required: true })],
	},
	update_profile: {
		title: 'Update your profile',
		fields: [
			F('display_name', 'Display name', 'text'),
			F('email', 'E-mail', 'text'),
			F('phone', 'Phone', 'text'),
			F('website', 'Website', 'text'),
			F('address', 'Address', 'text'),
			F('organisation', 'Organisation', 'text'),
			F('role', 'Role', 'text'),
			F('headline', 'Headline', 'text'),
			F('pronouns', 'Pronouns', 'text'),
			F('biography', 'Biography', 'textarea', { full: true }),
		],
	},
}

/** @return {{title:string, fields:Array}|null} */
export function confirmForm(name) {
	return FORMS[name] || null
}

/**
 * Convert a stored value into a native-picker value where possible.
 * Unparseable date/time strings (e.g. "morgen 10:00") stay as editable text so
 * the original wording survives; the backend parser accepts natural language.
 */
function toPickerValue(value, fieldType) {
	const raw = value === undefined || value === null ? '' : String(value).trim()
	if (fieldType === 'date') {
		const iso = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(raw)
		if (iso) return { type: 'date', value: iso[1] + '-' + iso[2].padStart(2, '0') + '-' + iso[3].padStart(2, '0') }
		const dateTime = /^(\d{4})-(\d{1,2})-(\d{1,2})[T ]/.exec(raw)
		if (dateTime) return { type: 'date', value: dateTime[1] + '-' + dateTime[2].padStart(2, '0') + '-' + dateTime[3].padStart(2, '0') }
		const de = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(raw)
		if (de) return { type: 'date', value: de[3] + '-' + de[2].padStart(2, '0') + '-' + de[1].padStart(2, '0') }
		return { type: 'text', value: raw }
	}
	if (fieldType === 'time') {
		const time = /^(\d{1,2}):(\d{2})/.exec(raw)
		return time ? { type: 'time', value: time[1].padStart(2, '0') + ':' + time[2] } : { type: 'time', value: '' }
	}
	if (fieldType === 'datetime') {
		const m = /^(\d{4})-(\d{1,2})-(\d{1,2})[T ](\d{1,2}):(\d{2})/.exec(raw)
		if (m) {
			return {
				type: 'datetime',
				value: m[1] + '-' + m[2].padStart(2, '0') + '-' + m[3].padStart(2, '0') + 'T' + m[4].padStart(2, '0') + ':' + m[5],
			}
		}
		const de = /^(\d{1,2})\.(\d{1,2})\.(\d{4})\s+(\d{1,2}):(\d{2})/.exec(raw)
		if (de) {
			return {
				type: 'datetime',
				value: de[3] + '-' + de[2].padStart(2, '0') + '-' + de[1].padStart(2, '0') + 'T' + de[4].padStart(2, '0') + ':' + de[5],
			}
		}
		return { type: 'text', value: raw }
	}
	return { type: fieldType, value: raw }
}

/**
 * Build the editable confirmation form for one tool call.
 *
 * @param {{name:string, arguments:Object}} conf confirmation payload from the stream
 * @returns {{title:string, element:HTMLElement, getArguments:()=>Object, validate:()=>boolean}|null}
 */
export function buildConfirmForm(conf) {
	const form = confirmForm(conf && conf.name)
	if (!form) return null

	const args = { ...((conf && conf.arguments) || {}) }
	// The backend accepts "public" as an alias for "link"; normalize it for the
	// select so the "link" option is actually selected.
	if (args.type === 'public') args.type = 'link'
	if ((conf && conf.name) === 'create_share' && !args.type) args.type = 'link'

	const state = { ...args }
	// Split model-provided ISO/local datetimes into native date and time fields
	// so the editable form remains useful even when only `start` was supplied.
	for (const prefix of ['start', 'end']) {
		const raw = String(state[prefix] || '')
		const match = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/.exec(raw)
		if (match) {
			state[prefix] = match[1]
			state[prefix + '_time'] = match[2]
		}
	}
	const wrap = document.createElement('div')
	wrap.className = 'rconfirm-form'
	const registry = []

	const nativeType = (field, picker) => {
		if (field.type === 'password') return 'password'
		if (field.type === 'number') return 'number'
		if (field.type === 'time') return 'time'
		if (picker.type === 'date') return 'date'
		if (picker.type === 'datetime') return 'datetime-local'
		return 'text'
	}

	form.fields.forEach((field) => {
		const row = document.createElement('label')
		const visible = (() => {
			if (!field.showWhen) return true
			return field.showWhen.values.includes(state[field.showWhen.key])
		})()
		const isCheckbox = field.type === 'checkbox'
		row.className = (isCheckbox ? 'rconfirm-check' : 'rconfirm-field') + (field.full ? ' rconfirm-field--full' : '')
		row.style.display = visible ? '' : 'none'

		let control
		if (isCheckbox) {
			control = document.createElement('input')
			control.type = 'checkbox'
			control.checked = !!state[field.key]
			row.append(control, document.createElement('span'))
			row.lastChild.textContent = field.label
		} else if (field.type === 'select' || field.type === 'calendar') {
			control = document.createElement('select')
			if (field.type === 'calendar') {
				const loading = document.createElement('option')
				loading.value = state[field.key] || ''
				loading.textContent = t('Loading…')
				control.appendChild(loading)
				const apiMeta = document.head.querySelector('meta[name="eva-ai-api"]')
				const tokenMeta = document.head.querySelector('meta[name="requesttoken"]')
				const apiUrl = apiMeta ? apiMeta.getAttribute('content') : ''
				fetch(apiUrl + '/calendars', { credentials: 'same-origin', headers: { 'OCS-APIRequest': 'true', 'Accept': 'application/json', 'requesttoken': tokenMeta ? tokenMeta.getAttribute('content') : '' } })
					.then((response) => response.json())
					.then((payload) => {
						const calendars = payload?.ocs?.data?.calendars || payload?.calendars || []
						control.innerHTML = ''
						calendars.filter((cal) => !cal.readOnly).forEach((cal) => {
							const option = document.createElement('option')
							option.value = cal.uri || cal.id || cal.displayname
							option.textContent = cal.displayname || cal.uri
							control.appendChild(option)
						})
						if (state[field.key]) control.value = state[field.key]
						updateState()
					})
					.catch(() => {
						control.innerHTML = ''
						const option = document.createElement('option')
						option.value = state[field.key] || ''
						option.textContent = state[field.key] || t('Calendar')
						control.appendChild(option)
					})
			} else {
				;(field.options || []).forEach((opt) => {
					const o = document.createElement('option')
					o.value = opt.value
					o.textContent = opt.label
					control.appendChild(o)
				})
			}
			if (state[field.key] !== undefined && state[field.key] !== null && field.type !== 'calendar') control.value = state[field.key]
			const cap = document.createElement('span')
			cap.textContent = field.label + (field.required ? ' *' : '')
			row.append(cap, control)
		} else {
			const picker = toPickerValue(state[field.key], field.type)
			const tag = field.type === 'textarea' ? 'textarea' : 'input'
			control = document.createElement(tag)
			if (tag === 'input') control.type = nativeType(field, picker)
			control.value = picker.value
			const cap = document.createElement('span')
			cap.textContent = field.label + (field.required ? ' *' : '')
			row.append(cap, control)
		}

		const getValue = () => {
			if (field.type === 'checkbox') return control.checked
			const raw = (control.value || '').trim()
			if (field.type === 'number') return raw === '' ? '' : Number(raw)
			return raw
		}
		const updateState = () => {
			state[field.key] = getValue()
			// Re-evaluate visibility (e.g. share type toggles target/password).
			form.fields.forEach((f) => {
				if (!f.showWhen) return
				const targetRow = registry.find((r) => r.field === f)
				if (!targetRow) return
				const isVisible = f.showWhen.values.includes(state[f.showWhen.key])
				targetRow.row.style.display = isVisible ? '' : 'none'
			})
		}
		control.addEventListener('change', updateState)
		control.addEventListener('input', updateState)

		registry.push({ field, row, control, getValue })
		wrap.appendChild(row)
	})

	const getArguments = () => {
		const out = { ...state }
		registry.forEach(({ field, row, control, getValue }) => {
			if (row.style.display === 'none') {
				delete out[field.key]
				return
			}
			out[field.key] = getValue()
		})
		// Combine native date/time controls into the backend's supported values.
		if (conf && conf.name === 'create_calendar_event') {
			if (out.start) out.start = out.start_time ? out.start + ' ' + out.start_time : out.start
			if (out.end) out.end = out.end_time ? out.end + ' ' + out.end_time : out.end
			delete out.start_time
			delete out.end_time
		}
		// "public" is the backend alias for link shares (legacy form behavior).
		if ((conf && conf.name) === 'create_share' && out.type === 'link') out.type = 'public'
		return out
	}

	const validate = () => {
		let ok = true
		registry.forEach(({ field, row, control, getValue }) => {
			if (row.style.display === 'none') return
			const empty = (() => {
				if (field.type === 'checkbox') return false
				const v = getValue()
				return v === '' || v === undefined || v === null
			})()
			const bad = field.required && empty
			row.classList.toggle('rconfirm-invalid', bad)
			if (bad) ok = false
		})
		return ok
	}

	return {
		title: form.title,
		element: wrap,
		getArguments,
		validate,
	}
}
