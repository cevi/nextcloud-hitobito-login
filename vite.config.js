import { defineConfig } from 'vite'
import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

const customConfig = defineConfig({
	css: {
		preprocessorOptions: {
			scss: {
				api: 'modern-compiler',
			},
		},
	},
})

export default createAppConfig(
	{
		'settings-admin': resolve(join('src', 'settings-admin.js')),
	},
	{
		inlineCSS: { relativeCSSInjection: true },
		config: customConfig,
	},
)
