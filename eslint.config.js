'use strict';

// Flat ESLint config for EVA (Vue 3 SFCs + plain JS + Node CJS runners).
// This is advisory tooling: it runs in CI but does not block merges (AGENTS.md).
// It is deliberately kept focused on correctness signals rather than
// formatting opinions - the codebase uses tabs in templates and multiple
// attributes per line, so those style rules are off.
const js = require('@eslint/js');
const pluginVue = require('eslint-plugin-vue');
const globals = require('globals');

// Globals injected at build time by webpack DefinePlugin (see webpack.js).
const defineGlobals = {
	appName: 'readonly',
	appVersion: 'readonly',
	// Provided at runtime by the Nextcloud page context.
	OC: 'readonly',
	REQUEST_TOKEN: 'readonly',
	meta: 'readonly',
};

module.exports = [
	{
		ignores: [
			'node_modules/**',
			'js/**', // committed build output (rebuilt with webpack)
			'coverage/**',
			'dist/**',
		],
	},
	js.configs.recommended,
	{
		// Baseline for app sources: browser + node + DefinePlugin globals.
		languageOptions: {
			ecmaVersion: 2023,
			globals: { ...globals.browser, ...globals.node, ...defineGlobals },
		},
	},
	{
		// CommonJS runners: node --test suites and Playwright harnesses use
		// require/module/__dirname/process/Buffer.
		files: ['**/*.cjs', 'babel.config.js', 'webpack.js'],
		languageOptions: {
			sourceType: 'commonjs',
			globals: { ...globals.node, ...globals.browser },
		},
	},
	{
		files: ['**/*.js', '**/*.mjs'],
		languageOptions: {
			sourceType: 'module',
			globals: { ...globals.browser, ...globals.node, ...defineGlobals },
		},
	},
	{
		// Playwright specs run code inside page.evaluate, where the browser
		// context provides globals unknown to this Node-scoped lint pass.
		files: ['tests/browser/**'],
		languageOptions: { sourceType: 'commonjs', globals: { ...globals.node, ...globals.browser } },
		rules: { 'no-undef': 'off' },
	},
	...pluginVue.configs['flat/recommended'],
	{
		rules: {
			// Correctness stays on. no-undef comes from js.configs.recommended; it is
			// turned off for tests/browser above (page.evaluate browser globals).
			'vue/valid-v-if': 'error',
			'vue/valid-v-else': 'error',
			'vue/valid-v-else-if': 'error',
			'no-redeclare': 'warn',
			'no-regex-spaces': 'error',
			// Intentional patterns: flag as warnings, never errors.
			'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_', caughtErrors: 'none' }],
			'no-empty': ['warn', { allowEmptyCatch: true }],
			'no-control-regex': 'warn',
			// Formatting opinions - off, the codebase has its own style.
			'vue/html-indent': 'off',
			'vue/max-attributes-per-line': 'off',
			'vue/singleline-html-element-content-newline': 'off',
			'vue/multiline-html-element-content-newline': 'off',
			'vue/attributes-order': 'off',
			'vue/html-self-closing': 'off',
			'vue/html-closing-bracket-newline': 'off',
			'vue/first-attribute-linebreak': 'off',
			'vue/multi-word-component-names': 'off',
		},
	},
	{
		// Base no-unused-vars cannot see <script setup> variables that are only
		// used in the template (e.g. @click="loadMoreChunks(id)"), so it
		// false-positives on them. Disable it for .vue; the Vue plugin rules
		// still run, and no-unused-vars stays on for plain JS.
		files: ['**/*.vue'],
		rules: { 'no-unused-vars': 'off' },
	},
];
