const path = require('node:path')
const webpack = require('webpack')

const root = path.resolve(__dirname, '../..')
const config = require(path.join(root, 'webpack.js'))({}, { mode: 'development' })
config.entry = { chatviewTest: path.join(__dirname, 'chatview-entry.js') }
config.output.path = path.join(root, 'node_modules', '.cache', 'eva-ai-browser')
config.output.filename = 'chatview.js'
config.output.clean = true
config.devtool = false
config.optimization = { ...(config.optimization || {}), splitChunks: false, runtimeChunk: false }

webpack(config, (error, stats) => {
  if (error) {
    process.stderr.write(String(error) + '\n')
    process.exitCode = 1
    return
  }
  if (stats.hasErrors()) {
    process.stderr.write(stats.toString({ all: false, errors: true }) + '\n')
    process.exitCode = 1
  }
})
