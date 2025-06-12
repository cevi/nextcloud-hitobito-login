<template>
	<NcAppContent>
		<NcSettingsSection v-if="generalSettings"
			:name="t(appName, 'General')"
			:description="t(appName, 'General options regarding the login with Hitobito')"
			doc-url="https://github.com/cevi/nextcloud-hitobito-login#general-settings">
			<NcCheckboxRadioSwitch v-model="generalSettings.options" value="prune_groups" name="generalSettings">
				{{ t(appName, 'Automatically remove groups from user') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="generalSettings.options" value="block_unmapped" name="generalSettings">
				{{ t(appName, 'Block users without a mapped group/role match') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="generalSettings.options" value="email_lookup" name="generalSettings">
				{{ t(appName, 'Search for existing users by email') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="generalSettings.options" value="use_as_default_login" name="generalSettings">
				{{ t(appName, 'Use Hitobito as default login') }}
			</NcCheckboxRadioSwitch>

			<NcTextField v-model="generalSettings.base_url"
				type="url"
				:label="t(appName, 'Base-URL')"
				:error="!!generalSettingsErrors.base_url"
				:helper-text="generalSettingsErrors.base_url" />
			<NcTextField v-model="generalSettings.client_id"
				:label="t(appName, 'Client-ID')"
				:error="!!generalSettingsErrors.client_id"
				:helper-text="generalSettingsErrors.client_id" />
			<NcPasswordField v-model="generalSettings.client_secret"
				:label="t(appName, 'Client-Secret')"
				:error="!!generalSettingsErrors.client_secret"
				:helper-text="generalSettingsErrors.client_secret" />
			<NcTextField v-model="generalSettings.login_button_text"
				:label="t(appName, 'Login-Button text')"
				:error="!!generalSettingsErrors.login_button_text"
				:helper-text="generalSettingsErrors.login_button_text" />
		</NcSettingsSection>

		<NcSettingsSection :name="t(appName, 'Group mapping')"
			:description="t(appName, 'In this section the mapping between group/role combination with an existing Nextcloud group can be done')"
			doc-url="https://github.com/cevi/nextcloud-hitobito-login#group-mapping">
			<ul v-if="groupMappings?.length > 0" class="group-mappings">
				<li v-for="(mapping, index) in groupMappings" :key="mapping.id" class="mapping">
					<NcTextField v-model="mapping.group"
						:label="t(appName, 'Hitobito-Group')"
						:error="!!groupMappingsErrors[`group-${index}`]"
						:helper-text="groupMappingsErrors[`group-${index}`]" />
					<NcTextField v-model="mapping.role"
						:label="t(appName, 'Hitobito-Role')"
						:error="!!groupMappingsErrors[`role-${index}`]"
						:helper-text="groupMappingsErrors[`role-${index}`]" />
					<NcSettingsSelectGroup v-model="mapping.targets"
						label="Test"
						:placeholder="t(appName, 'Groups to map to')" />
					<NcButton :aria-label="t(appName, 'Remove mapping')" type="secondary" @click="removeMapping(index)">
						<template #icon>
							<Minus :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>

			<NcButton :aria-label="t(appName, 'Add new mapping')" type="secondary" @click="addMapping()">
				<template #icon>
					<Plus :size="20" />
				</template>
				<template #default>
					{{ t(appName, 'Add new mapping') }}
				</template>
			</NcButton>
		</NcSettingsSection>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSelectGroup from '@nextcloud/vue/components/NcSettingsSelectGroup'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcButton from '@nextcloud/vue/components/NcButton'
import Plus from 'vue-material-design-icons/Plus.vue'
import Minus from 'vue-material-design-icons/Minus.vue'

export default {
	name: 'AdminSettings',
	components: {
		NcAppContent,
		NcSettingsSection,
		NcCheckboxRadioSwitch,
		NcSettingsSelectGroup,
		NcTextField,
		NcPasswordField,
		NcButton,
		Plus,
		Minus,
	},
	props: {
		initialState: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			appName,
			generalSettings: undefined,
			groupMappings: undefined,
			saveSettingsTimeout: undefined,
		}
	},
	computed: {
		generalSettingsErrors() {
			const errors = {}

			if (!this.generalSettings) {
				return errors
			}

			if (!this.generalSettings.base_url.trim()) {
				errors.base_url = t(appName, 'Base-URL is required')
			} else if (!URL.canParse(this.generalSettings.base_url)) {
				errors.base_url = t(appName, 'Base-URL is not a valid URL')
			}
			if (!this.generalSettings.client_id.trim()) {
				errors.client_id = t(appName, 'Client-ID is required')
			}
			if (!this.generalSettings.client_secret.trim()) {
				errors.client_secret = t(appName, 'Client-Secret is required')
			}
			if (!this.generalSettings.login_button_text.trim()) {
				errors.login_button_text = t(appName, 'Login-Button text is required')
			}

			return errors
		},
		groupMappingsErrors() {
			const errors = {}

			if (!this.groupMappings) {
				return errors
			}

			this.groupMappings.forEach((mapping, index) => {
				if (!mapping.group.trim()) {
					errors[`group-${index}`] = t(appName, 'Group is required')
				}
				if (!mapping.role.trim()) {
					errors[`role-${index}`] = t(appName, 'Role is required')
				}
			})

			return errors
		},
	},
	watch: {
		generalSettings: {
			deep: true,
			handler(_, oldGeneralSettings) {
				if (oldGeneralSettings === undefined) {
					return
				}

				this.debouncedSaveSettings()
			},
		},
		groupMappings: {
			deep: true,
			handler(_, oldGroupMappings) {
				if (oldGroupMappings === undefined) {
					return
				}

				this.debouncedSaveSettings()
			},
		},
	},
	mounted() {
		this.generalSettings = this.initialState.general_settings
		this.groupMappings = this.initialState.group_mappings
	},
	methods: {
		addMapping() {
			this.groupMappings.push({
				group: '',
				role: '',
				targets: [],
			})
		},
		removeMapping(index) {
			this.groupMappings.splice(index, 1)
		},
		debouncedSaveSettings() {
			window.clearTimeout(this.saveSettingsTimeout)

			this.saveSettingsTimeout = window.setTimeout(() => {
				this.saveSettings()
			}, 3000)
		},
		async saveSettings() {
			try {
				if (Object.keys(this.generalSettingsErrors).length > 0 || Object.keys(this.groupMappingsErrors).length > 0) {
					return
				}

				const response = await axios.put(this.initialState.save_admin_settings_url, {
					general_settings: this.generalSettings,
					group_mappings: this.groupMappings,
				})

				if (response.data.success) {
					showSuccess(t(appName, 'Settings saved successfully'))
				} else {
					throw new Error('Response not successful')
				}
			} catch (e) {
				showError(t(appName, 'Failed to save settings'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.group-mappings {
    display: flex;
    flex-direction: column;
    gap: var(--default-grid-baseline);
    margin-bottom: 0.5em;

    &::v-deep {
        .input-field {
            margin-top: 0;
        }

        .v-select {
            &.select {
                margin-bottom: 0;
            }
        }
    }

    .mapping {
        display: flex;
        gap: var(--default-grid-baseline);
        align-items: flex-start;
    }
}
</style>
