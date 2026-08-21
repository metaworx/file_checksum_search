/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Files sidebar entry point: registers the checksums tab (a Vue custom
 * element) and the corresponding file action.
 */
import { getSidebar, registerFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { defineCustomElement } from 'vue'
import ChecksumsSidebarTab from './sidebar-vue/ChecksumsSidebarTab.vue'
import appIconSvg from '../img/app.svg?raw'
import type { FileNode } from './sidebar-vue/types'

const APP_ICON = appIconSvg.replace('fill="#fff"', 'fill="currentColor"')
const TAG = 'file_checksum_search-files-sidebar-tab'

try {
	const sidebar = getSidebar()
	if (sidebar) {
		sidebar.registerTab({
			id: 'file_checksum_search-checksums',
			displayName: t('file_checksum_search', 'Checksums'),
			iconSvgInline: APP_ICON,
			order: 55,
			tagName: TAG,
			enabled({ node }: { node?: FileNode }) {
				return node?.type === 'file'
			},
			onInit() {
				if (!customElements.get(TAG)) {
					customElements.define(TAG, defineCustomElement(ChecksumsSidebarTab, { shadowRoot: false }))
				}
				return Promise.resolve()
			},
		})
	} else {
		console.warn('[FCIAS] getSidebar() returned null/undefined — sidebar tab not registered')
	}
} catch (err) {
	console.error('[FCIAS] Failed to register sidebar tab:', err)
}

const checksumIcon = APP_ICON
const checksumName = t('file_checksum_search', 'Checksums')

try {
	registerFileAction({
		id: 'file_checksum_search-checksums',
		displayName() {
			return checksumName
		},
		iconSvgInline() {
			return checksumIcon
		},
		order: 55,
		enabled({ nodes }: { nodes: FileNode[] }) {
			if (nodes.length !== 1) return false
			return nodes[0]?.type === 'file'
		},
		async exec({ nodes }: { nodes: FileNode[] }) {
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			getSidebar().open(nodes[0] as any, 'file_checksum_search-checksums')
			return null
		},
	})
} catch (err) {
	console.error('[FCIAS] Failed to register file action:', err)
}
