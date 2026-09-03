/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Vue 3 entry point for the personal settings page.
 */

import { createApp } from 'vue'
import App from './App.vue'
// The toast container carries its own positioning (bottom, clear of the
// navigation) and its success colour. Without this the container has no
// placement at all and collapses into the page's top-left corner.
import '@nextcloud/dialogs/style.css'
import '../settings-admin.css'

createApp(App).mount('#fcias-personal-settings')
