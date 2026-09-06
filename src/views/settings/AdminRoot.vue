<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAdminSettingsShell
		appId="decidiq"
		appName="Decidiq"
		data-testid="admin-root"
		:showSetup="true"
		:setupSteps="setupSteps"
		@reimported="onReimported">
		<Settings v-if="storesReady" />

		<PublicationSettings v-if="storesReady" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import PublicationSettings from './PublicationSettings.vue'
import Settings from './Settings.vue'
import manifest from '../../manifest.json'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		Settings,
		PublicationSettings,
	},

	data() {
		return {
			storesReady: false,
			/*
			 * 🔴 SETUP HAD NO WAY BACK IN. `CnAppRoot` opens the wizard while an
			 * optional step is outstanding and never again once it is settled, so
			 * an administrator who picked "None" on install, or who inherited an
			 * instance somebody else set up, had no way to load example data at
			 * all. The steps are the ones the app bundles, so this button and the
			 * first-run wizard always ask the same question.
			 */
			setupSteps: (manifest.setup && manifest.setup.steps) || [],
		}
	},

	/** @spec exclude lifecycle hook; only boots Pinia stores then flips the storesReady flag, framework setup */
	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/** @spec exclude re-init stores after a configuration re-import; no business logic */
		onReimported() {
			initializeStores()
		},
	},
}
</script>
