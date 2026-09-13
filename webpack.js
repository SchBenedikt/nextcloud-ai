const path = require('path')
const webpack = require('webpack')
const TerserPlugin = require('terser-webpack-plugin')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = (env, argv) => {
	const isProd = argv?.mode === 'production' || process.env.NODE_ENV === 'production'
	return {
		// Opt-in bounded build for small Nextcloud hosts.
		...(process.env.EVA_LOW_MEMORY_BUILD === '1' ? {
			parallelism: 1,
			optimization: { minimizer: [new TerserPlugin({ parallel: false })] },
		} : {}),
		entry: {
			'eva_ai-main': path.resolve(__dirname, 'src', 'main.js'),
			eva_ai_filesaction: path.resolve(__dirname, 'src', 'lib', 'filesaction.js'),
			eva_ai_standalone: path.resolve(__dirname, 'src', 'standalone-chat.js'),
		},
		output: {
			path: path.resolve(__dirname, 'js'),
			filename: (chunkData) => {
				// Statische Namen ohne Hash: NC rendert das Skript nur dann,
				// wenn es exakt unter apps/{app}/js/{name}.js liegt. Gehashte
				// Namen brauchen eine webpack-theme.json-Combiner-Datei, die
				// wir hier nicht erzeugen. Cache-Busting übernimmt der
				// NC-JSCombiner (Cachebuster-Query-String).
				return chunkData.chunk.name + '.js'
			},
			// Der Installationspfad ist in Nextcloud konfigurierbar (z. B.
			// /nextcloud). `auto` leitet die Chunk-URL aus dem tatsächlich
			// geladenen Hauptbundle ab; ein fester `/apps/...`-Pfad bricht jede
			// Lazy-View auf Installationen in einem Unterverzeichnis.
			publicPath: 'auto',
			// Clean before emitting, keeping only the files that are *not* build
			// output: `header.js` and the admin page's own script. The previous
			// pattern also kept every `.map` and `.LICENSE.txt`, so a source map
			// from a removed entry point survived every later build and stayed in
			// the repository (issue #193). Build output is regenerated, never kept.
			clean: { keep: /^header\.js$|^admin-settings|^15\.js$/ },
		},
		devtool: isProd ? 'source-map' : 'eval-cheap-module-source-map',
		module: {
			rules: [
				{
					test: /\.vue$/,
					loader: 'vue-loader',
				},
				{
					test: /\.js$/,
					exclude: /node_modules/,
					use: { loader: 'babel-loader' },
				},
				{
					test: /\.(css|scss)$/,
					use: ['style-loader', 'css-loader', 'sass-loader'],
				},
				{ test: /\.(woff2?|eot|ttf|otf)$/, type: 'asset/inline' },
				{ test: /\.(png|jpe?g|gif|webp)$/, type: 'asset' },
				{ test: /\.svg$/, type: 'asset/inline' },
			],
		},
		resolve: {
			extensions: ['.js', '.vue', '.scss', '.css'],
			// SAX uses its non-streaming parser for browser SVG detection.
			fallback: { stream: false },
			alias: { vue: 'vue/dist/vue.esm-bundler.js' },
		},
		plugins: [
			new VueLoaderPlugin(),
			new webpack.DefinePlugin({
				appName: JSON.stringify('eva_ai'),
				appVersion: JSON.stringify(require('./package.json').version),
			}),
		],
		performance: { hints: false },
	}
}
