import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

const app = createApp(AdminSettings)

app.mount(`#${appName}-settings-admin`)
