/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Vue 3 entry point for the duplicates page.
 */

import { createApp } from 'vue'
import { setTexts } from '@metaworx/nextcloud-vue'
import '@metaworx/nextcloud-vue/style.css'
import { appTexts } from '../texts'
import App from './App.vue'

setTexts(appTexts())
createApp(App).mount('#fcias-duplicates')
