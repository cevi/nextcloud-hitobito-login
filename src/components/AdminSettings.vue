<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
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

const initialState = loadState(appName, 'admin_settings_state')

const myAppName = ref('')
const generalSettings = ref()
const groupMappings = ref()

let saveSettingsTimeout

const generalSettingsErrors = computed(() => {
	const errors = {}

	if (!generalSettings.value) {
		return errors
	}

	if (!generalSettings.value.base_url.trim()) {
		errors.base_url = t(appName, 'Base-URL is required')
	} else if (!URL.canParse(generalSettings.value.base_url)) {
		errors.base_url = t(appName, 'Base-URL is not a valid URL')
	}
	if (!generalSettings.value.client_id.trim()) {
		errors.client_id = t(appName, 'Client-ID is required')
	}
	if (!generalSettings.value.client_secret.trim()) {
		errors.client_secret = t(appName, 'Client-Secret is required')
	}
	if (!generalSettings.value.login_button_text.trim()) {
		errors.login_button_text = t(appName, 'Login-Button text is required')
	}

	return errors
})

const groupMappingsErrors = computed(() => {
	const errors = {}

	if (!groupMappings.value) {
		return errors
	}

	groupMappings.value.forEach((mapping, index) => {
		if (!mapping.group.trim()) {
			errors[`group-${index}`] = t(appName, 'Group is required')
		}
		if (!mapping.role.trim()) {
			errors[`role-${index}`] = t(appName, 'Role is required')
		}
	})

	return errors
})

const addMapping = () => {
	groupMappings.value.push({
		group: '',
		role: '',
		targets: [],
	})
}

const removeMapping = (index) => {
	groupMappings.value.splice(index, 1)
}

const debouncedSaveSettings = () => {
	window.clearTimeout(saveSettingsTimeout)

	saveSettingsTimeout = window.setTimeout(() => {
		saveSettings()
	}, 1000)
}

const saveSettings = async () => {
	try {
		if (
			Object.keys(generalSettingsErrors.value).length > 0
			|| Object.keys(groupMappingsErrors.value).length > 0
		) {
			return
		}

		const response = await axios.put(initialState.save_admin_settings_url, {
			general_settings: generalSettings.value,
			group_mappings: groupMappings.value,
		})

		if (response.data.success) {
			showSuccess(t(appName, 'Settings saved successfully'))
		} else {
			throw new Error('Response not successful')
		}
	} catch (e) {
		showError(t(appName, 'Failed to save settings'))
	}
}

watch(
	generalSettings,
	(_, oldGeneralSettings) => {
		if (oldGeneralSettings === undefined) {
			return
		}

		debouncedSaveSettings()
	},
	{ deep: true },
)

watch(
	groupMappings,
	(_, oldGroupMappings) => {
		if (oldGroupMappings === undefined) {
			return
		}

		debouncedSaveSettings()
	},
	{ deep: true },
)

onMounted(() => {
	myAppName.value = appName
	generalSettings.value = { ...initialState.general_settings }
	groupMappings.value = [...initialState.group_mappings]
})
</script>

<template>
	<NcAppContent>
		<NcSettingsSection
			v-if="generalSettings"
			:name="t(myAppName, 'General')"
			:description="
				t(myAppName, 'General options regarding the login with Hitobito')
			"
			doc-url="https://github.com/cevi/nextcloud-hitobito-login#general-settings">
			<NcCheckboxRadioSwitch
				v-model="generalSettings.options"
				value="prune_groups"
				name="generalSettings">
				{{ t(myAppName, 'Automatically remove groups from user') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="generalSettings.options"
				value="block_unmapped"
				name="generalSettings">
				{{ t(myAppName, 'Block users without a mapped group/role match') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="generalSettings.options"
				value="email_lookup"
				name="generalSettings">
				{{ t(myAppName, 'Search for existing users by email') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="generalSettings.options"
				value="use_as_default_login"
				name="generalSettings">
				{{ t(myAppName, 'Use Hitobito as default login') }}
			</NcCheckboxRadioSwitch>

			<NcTextField
				v-model="generalSettings.base_url"
				type="url"
				:label="t(myAppName, 'Base-URL')"
				:error="!!generalSettingsErrors.base_url"
				:helper-text="generalSettingsErrors.base_url" />
			<NcTextField
				v-model="generalSettings.client_id"
				:label="t(myAppName, 'Client-ID')"
				:error="!!generalSettingsErrors.client_id"
				:helper-text="generalSettingsErrors.client_id" />
			<NcPasswordField
				v-model="generalSettings.client_secret"
				:label="t(myAppName, 'Client-Secret')"
				:error="!!generalSettingsErrors.client_secret"
				:helper-text="generalSettingsErrors.client_secret" />
			<NcTextField
				v-model="generalSettings.login_button_text"
				:label="t(myAppName, 'Login-Button text')"
				:error="!!generalSettingsErrors.login_button_text"
				:helper-text="generalSettingsErrors.login_button_text" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t(myAppName, 'Group mapping')"
			:description="
				t(
					myAppName,
					'In this section the mapping between group/role combination with an existing Nextcloud group can be done',
				)
			"
			doc-url="https://github.com/cevi/nextcloud-hitobito-login#group-mapping">
			<ul v-if="groupMappings?.length > 0" class="group-mappings">
				<li
					v-for="(mapping, index) in groupMappings"
					:key="mapping.id"
					class="mapping">
					<NcTextField
						v-model="mapping.group"
						:label="t(myAppName, 'Hitobito-Group')"
						:error="!!groupMappingsErrors[`group-${index}`]"
						:helper-text="groupMappingsErrors[`group-${index}`]" />
					<NcTextField
						v-model="mapping.role"
						:label="t(myAppName, 'Hitobito-Role')"
						:error="!!groupMappingsErrors[`role-${index}`]"
						:helper-text="groupMappingsErrors[`role-${index}`]" />
					<NcSettingsSelectGroup
						v-model="mapping.targets"
						label="Test"
						:placeholder="t(myAppName, 'Groups to map to')" />
					<NcButton
						:aria-label="t(myAppName, 'Remove mapping')"
						type="secondary"
						@click="removeMapping(index)">
						<template #icon>
							<Minus :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>

			<NcButton
				:aria-label="t(myAppName, 'Add new mapping')"
				type="secondary"
				@click="addMapping()">
				<template #icon>
					<Plus :size="20" />
				</template>
				<template #default>
					{{ t(myAppName, 'Add new mapping') }}
				</template>
			</NcButton>
		</NcSettingsSection>
	</NcAppContent>
</template>

<style lang="scss" scoped>
.group-mappings {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-bottom: 0.5em;

	&:deep(.input-field) {
		margin-top: 0;
	}

	&:deep(.v-select) {
		&.select {
			margin-bottom: 0;
		}
	}

	.mapping {
		display: flex;
		gap: var(--default-grid-baseline);
		align-items: flex-start;
	}
}
</style>
