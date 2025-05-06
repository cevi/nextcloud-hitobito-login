const webpackConfig = require('@nextcloud/webpack-vue-config')
const ESLintPlugin = require('eslint-webpack-plugin')
const StyleLintPlugin = require('stylelint-webpack-plugin')
const path = require('path')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'

const appName = process.env.npm_package_name

webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'settings-admin.js'),
		filename: appName + '-settings-admin.js',
	},
}

webpackConfig.plugins.push(
	new ESLintPlugin({
		extensions: ['js', 'vue'],
		files: 'src',
		failOnError: !isDev,
	}),
)
webpackConfig.plugins.push(
	new StyleLintPlugin({
		files: 'src/**/*.{css,scss,vue}',
		failOnError: !isDev,
	}),
)

webpackConfig.module.rules.push({
	test: /\.svg$/i,
	type: 'asset/source',
})

module.exports = webpackConfig
