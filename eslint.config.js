'use strict';

// Flat ESLint config for EVA (Vue 3 SFCs + plain JS + Node test/runners).
// This is advisory tooling: it is surfaced in CI but does not block merges.
// See AGENTS.md and CONTRIBUTING.md.
const js = require('@eslint/js');
const pluginVue = require('eslint-plugin-vue');
const globals = require('globals');

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
    files: ['**/*.js', '**/*.mjs'],
    languageOptions: {
      ecmaVersion: 2023,
      sourceType: 'module',
      globals: { ...globals.browser, ...globals.node },
    },
    rules: {
      // Advisory: surface unused locals without failing on _-prefixed args.
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      // Non-blocking project; globals are provided explicitly above.
      'no-undef': 'off',
    },
  },
  {
    // CommonJS runners (node --test suites, webpack/babel config).
    files: ['**/*.cjs', 'babel.config.js', 'webpack.js'],
    languageOptions: { sourceType: 'commonjs' },
  },
  ...pluginVue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    languageOptions: {
      globals: { ...globals.browser },
    },
    rules: {
      'vue/multi-word-component-names': 'off',
    },
  },
];
