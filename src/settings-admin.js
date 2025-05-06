import Vue from 'vue'
import { loadState } from '@nextcloud/initial-state'

import AdminSettings from './components/AdminSettings.vue'

Vue.mixin({
	methods: {
		t,
		n,
	},
})

const View = Vue.extend(AdminSettings)
new View({
	propsData: {
		initialState: loadState(appName, 'admin_settings_state'),
	},
}).$mount(`#${appName}-settings-admin`)
