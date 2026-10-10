/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import DuplicateGroup from './DuplicateGroup.vue'
import type { DuplicateGroup as GroupType } from '../composables/useDuplicates'

function group(extra: Partial<GroupType> = {}): GroupType {
	return {
		algo: 'sha1',
		hash_value: 'aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d',
		file_count: 2,
		files: [
			{ fileid: 1, path: '/a.pdf', name: 'a.pdf' },
			{ fileid: 2, path: '/b.jpg', name: 'b.jpg' },
		],
		...extra,
	}
}

function mounted(extra: Partial<GroupType> = {}) {
	return mount(DuplicateGroup, {
		props: { group: group(extra), fileUrl: () => '#' },
	})
}

describe('DuplicateGroup, the MIME type', () => {
	// One per group: its files share their content, and so its type.
	it('shows the type of the group\'s content on its header, after the hash', () => {
		const badge = mounted({ mimetype: 'application/pdf' }).find('.db-group-header [data-testid="fcias-group-mimetype"]')

		expect(badge.text()).toBe('application/pdf')
		expect(badge.attributes('title')).toBe('MIME type, detected from the content')
	})

	it('shows nothing for a group without one, or while types are not asked for', () => {
		expect(mounted({ mimetype: null }).find('[data-testid="fcias-group-mimetype"]').exists()).toBe(false)
		expect(mounted().find('[data-testid="fcias-group-mimetype"]').exists()).toBe(false)
	})
})
