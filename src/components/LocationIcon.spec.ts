/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LocationIcon, { type LocationKind } from './LocationIcon.vue'
import {
	ICON_ACCOUNT,
	ICON_ACCOUNT_GROUP,
	ICON_ASTERISK,
	ICON_FOLDER_ACCOUNT,
	ICON_HARDDISK,
	ICON_HOME,
	ICON_HOME_GROUP,
} from './icons'

const EXPECTED: Array<[LocationKind, string, string]> = [
	['own', ICON_HOME, 'Your own file'],
	['home', ICON_ACCOUNT, 'A home folder'],
	['user', ICON_ACCOUNT, 'One account\'s home folder'],
	['group', ICON_ACCOUNT_GROUP, 'The home folders of a group\'s members'],
	['homeAll', ICON_HOME_GROUP, 'All home folders'],
	['groupfolder', ICON_FOLDER_ACCOUNT, 'A group folder'],
	['storage', ICON_HARDDISK, 'A storage'],
	['universal', ICON_ASTERISK, 'Everything'],
]

describe('LocationIcon', () => {
	it.each(EXPECTED)('draws %s as its glyph, titled', (kind, path, title) => {
		const wrapper = mount(LocationIcon, { props: { kind } })

		expect(wrapper.find('path').attributes('d')).toBe(path)
		expect(wrapper.attributes('title')).toBe(title)
		expect(wrapper.attributes('aria-label')).toBe(title)
		expect(wrapper.attributes('data-kind')).toBe(kind)
	})

	it('draws every kind there is, and no two the same but the two homes', () => {
		const paths = EXPECTED.map(([, path]) => path)
		expect(new Set(paths).size).toBe(paths.length - 1)
	})
})
