/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the runner's DOM must not do for the real Nextcloud components is
 * set up by the package: a ResizeObserver that observes nothing, teleports
 * rendered in place, every wrapper unmounted after its test and the session
 * store cleared. See `@metaworx/nextcloud-vue/testing`.
 */
import { setupNextcloudDom } from '@metaworx/nextcloud-vue/testing'

setupNextcloudDom()
