/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * The German `scripts/l10n.sh build` writes to l10n/, read as a page reads
 * it: Nextcloud registers the app's bundle before the app's script runs,
 * and t() and n() look each text up there.
 */
import { register, setLanguage, unregister } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import de from '../l10n/de.json'
import deDE from '../l10n/de_DE.json'
import DuplicateGroup from './duplicates-vue/components/DuplicateGroup.vue'
import { n, t } from './l10n'

const APP = 'file_checksum_search'

const BUNDLES = { de, de_DE: deDE }

function load(language: keyof typeof BUNDLES): void {
	setLanguage(language)
	register(APP, BUNDLES[language].translations)
}

describe('the German translation', () => {
	afterEach(() => {
		unregister(APP)
		setLanguage('en')
	})

	it('fills a placeholder', () => {
		load('de')
		expect(t(APP, 'Band {band}', { band: 3 })).toBe('Ebene 3')
	})

	it('takes the plural form the count asks for', () => {
		load('de')
		expect(n(APP, '%n file', '%n files', 1)).toBe('1 Datei')
		expect(n(APP, '%n file', '%n files', 2)).toBe('2 Dateien')
	})

	it('renders a component in German', () => {
		load('de')
		const wrapper = mount(DuplicateGroup, {
			props: {
				group: {
					algo: 'sha1',
					hash_value: '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33',
					file_count: 2,
					files: [
						{ fileid: 1, path: 'a.txt', name: 'a.txt', verified: true },
						{ fileid: 2, path: 'b.txt', name: 'b.txt', verified: false, verified_hash: '62cdb7020ff920e5aa642c3d4066950dd1f01f4d' },
					],
					match_count: 1,
					mismatch_count: 1,
				},
				fileUrl: () => '#',
			},
		})
		const text = wrapper.text()
		expect(text).toContain('2 Dateien')
		expect(text).toContain('Alle prüfen')
		expect(text).toContain('jetzt: 62cdb7020ff920e5aa642c3d4066950dd1f01f4d')
	})

	it('shows an ampersand as it is', () => {
		load('de')
		expect(t(APP, 'Groups & group folders')).toBe('Gruppen & Team-Ordner')
	})

	// de is Nextcloud's informal German and de_DE its formal one.
	it('says du in de and Sie in de_DE', () => {
		load('de')
		expect(t(APP, 'Rules applying to your files')).toBe('Regeln für deine Dateien')
		unregister(APP)
		load('de_DE')
		expect(t(APP, 'Rules applying to your files')).toBe('Regeln für Ihre Dateien')
	})
})
