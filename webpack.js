const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = (env) => {
	const isProd = process.env.NODE_ENV === 'production'
	return {
		entry: {
			'eva-ai-main': path.resolve(__dirname, 'src', 'main.js'),
			eva_ai_filesaction: path.resolve(__dirname, 'src', 'lib', 'filesaction.js'),
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
			publicPath: '/apps/eva-ai/js/',
			clean: { keep: /^chat\.js$|^header\.js$|^eva-ai-main|^eva_ai_filesaction|\.map$|\.LICENSE\.txt$/ },
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
			alias: { vue: 'vue/dist/vue.esm-bundler.js' },
		},
		plugins: [
			new VueLoaderPlugin(),
			new webpack.DefinePlugin({
				appName: JSON.stringify('eva-ai'),
				appVersion: JSON.stringify('1.3.0'),
			}),
		],
		performance: { hints: false },
	}
}