module.exports = {
	presets: [
		['@babel/preset-env', {
			modules: false,
			targets: { browsers: ['last 2 versions', 'Firefox ESR', 'not dead'] },
		}],
	],
}