module.exports = {
	extends: ['@nextcloud/eslint-config/vue3', 'prettier'],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
	globals: {
		appName: true,
	},
}
