import { recommendedJavascript } from '@nextcloud/eslint-config'
import eslintConfigPrettier from 'eslint-config-prettier/flat'

export default [
	...recommendedJavascript,

	{
		languageOptions: {
			globals: {
				appName: true,
			},
		},
	},

	{
		name: 'nextcloud-hitobito-login-overrides',
		rules: {
			'no-unused-vars': ['error', { caughtErrors: 'none' }],
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
		},
	},

	eslintConfigPrettier,
]
