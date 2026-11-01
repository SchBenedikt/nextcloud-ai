const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')

test('every web-search toggle exposed to the settings template is returned from setup', () => {
	const source = fs.readFileSync(path.join(__dirname, '..', 'src', 'components', 'settings', 'ToolsSettings.vue'), 'utf8')
	const logic = fs.readFileSync(path.join(__dirname, '..', 'src', 'composables', 'useEvaSettings.js'), 'utf8')
	for (const binding of [
		'userWebSearchEnabled',
		'userWebSearchSafeSearch',
		'userWebSearchFetchContent',
		'userWebSearchImages',
		'userWebSearchBrowser',
	]) {
		assert.match(source, new RegExp(`v-model="${binding}"`), `${binding} must be bound in the template`)
		const returned = logic.slice(logic.lastIndexOf('\treturn {'))
		assert.match(returned, new RegExp(`\\b${binding}\\b`), `${binding} must be returned from setup`)
	}
})
