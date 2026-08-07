/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Global duplicate file browser — standalone page.
 */

import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { OCS_API_V1, FRONTEND } from './routes'
import { escapeHtml } from './utils'

declare const OC: {
	requestToken: string
}

interface DuplicateFileItem {
	fileid: number
	path: string
	name: string
	verified?: boolean
	verified_hash?: string
	verify_error?: string
}

interface DuplicateGroup {
	algo: string
	hash_value: string
	file_count: number
	files: DuplicateFileItem[]
	match_count?: number
	mismatch_count?: number
}

interface State {
	algo: string
	minCount: number
	limit: number
	offset: number
	groups: DuplicateGroup[]
	loading: boolean
	hasMore: boolean
}

const TAG = 'fcias-duplicate-browser'

class DuplicateBrowser extends HTMLElement {

	#state: State = {
		algo: '',
		minCount: 2,
		limit: 50,
		offset: 0,
		groups: [],
		loading: false,
		hasMore: false,
	}

	connectedCallback(): void {
		this.render()
		this.load()
	}

	render(): void {
		this.innerHTML = `
			<style>
				.fcias-db-wrap {
					max-width: 960px; margin: 0 auto; padding: 16px;
				}
				.fcias-db-controls {
					display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; align-items: center;
				}
				.fcias-db-controls select,
				.fcias-db-controls input,
				.fcias-db-controls button {
					padding: 6px 10px; font-size: 13px;
					border: 1px solid var(--color-border);
					border-radius: var(--border-radius);
					background: var(--color-main-background);
					color: var(--color-main-text);
				}
				.fcias-db-controls button {
					cursor: pointer;
				}
				.fcias-db-controls button:disabled {
					opacity: 0.5; cursor: default;
				}
				.fcias-db-controls button.primary {
					background: var(--color-primary-element); color: #fff;
					border-color: var(--color-primary-element);
				}
				.fcias-db-scroll {
					max-height: calc(100vh - 180px); overflow-y: auto;
					background: var(--color-main-background);
					border: 1px solid var(--color-border);
					border-radius: var(--border-radius);
				}
				.fcias-db-group {
					border-bottom: 1px solid var(--color-border);
				}
				.fcias-db-group:last-child {
					border-bottom: none;
				}
				.fcias-db-group-header {
					padding: 10px 14px; background: var(--color-background-dark);
					cursor: pointer; display: flex; justify-content: space-between;
					align-items: center;
				}
				.fcias-db-group-header:hover {
					background: var(--color-background-hover);
				}
				.fcias-db-algo-badge {
					display: inline-block; padding: 2px 8px; border-radius: var(--border-radius);
					background: var(--color-background-darker); font-weight: 600; font-size: 12px;
					margin-right: 8px;
				}
				.fcias-db-hash {
					font-family: var(--font-face-monospace); font-size: 12px;
					color: var(--color-text-maxcontrast); word-break: break-all;
				}
				.fcias-db-count {
					font-size: 12px; color: var(--color-text-maxcontrast);
					white-space: nowrap; margin-left: 12px;
				}
				.fcias-db-group-body {
					display: none;
				}
				.fcias-db-group.open .fcias-db-group-body {
					display: block;
				}
				.fcias-db-file-list {
					margin: 0; padding: 0; list-style: none;
				}
				.fcias-db-file-item {
					padding: 6px 14px; border-top: 1px solid var(--color-border);
					font-size: 13px; background: var(--color-main-background);
				}
				.fcias-db-file-item a {
					color: var(--color-main-text);
				}
				.fcias-db-file-item a:hover {
					color: var(--color-primary-element);
				}
				.fcias-db-pagination {
					display: flex; gap: 8px; justify-content: center; margin-top: 16px;
				}
				.fcias-db-empty, .fcias-db-loading {
					text-align: center; padding: 32px; color: var(--color-text-maxcontrast);
				}
				.fcias-db-error {
					color: var(--color-error); text-align: center; padding: 16px;
				}
				.fcias-db-verified {
					color: var(--color-success); margin-left: 4px; font-weight: bold;
				}
				.fcias-db-mismatch {
					color: var(--color-error); margin-left: 4px;
				}
				.fcias-db-group-header-status {
					font-size: 12px; font-weight: 600; white-space: nowrap;
				}
				.fcias-db-group-header-status.verified { color: var(--color-success); }
				.fcias-db-group-header-status.mixed { color: var(--color-warning); }
			</style>
			<div class="fcias-db-wrap">
				<div class="fcias-db-controls">
					<select id="fcias-db-algo">
						<option value="">All algorithms</option>
						<option value="sha1">SHA-1</option>
						<option value="md5">MD5</option>
						<option value="sha256">SHA-256</option>
						<option value="sha512">SHA-512</option>
						<option value="sha3-256">SHA3-256</option>
						<option value="sha3-512">SHA3-512</option>
						<option value="crc32">CRC32</option>
					</select>
					<label>
						Min: <input type="number" id="fcias-db-min-count" value="2" min="2" max="100" style="width:50px">
					</label>
					<label>
						Limit: <input type="number" id="fcias-db-limit" value="50" min="1" max="500" style="width:55px">
					</label>
					<button class="primary" id="fcias-db-refresh">Refresh</button>
					<button id="fcias-db-verify" title="Recalculate all hashes from file content">Verify hashes</button>
					<label id="fcias-db-verified-label" title="Show only groups where all files were confirmed matching">
						<input type="checkbox" id="fcias-db-verified-only"> Only matching
					</label>
				</div>
				<div id="fcias-db-results" class="fcias-db-scroll">
					<div class="fcias-db-loading">Searching …</div>
				</div>
				<div id="fcias-db-pagination" class="fcias-db-pagination"></div>
			</div>
		`
		this.#bindEvents()
	}

	#bindEvents(): void {
		const refreshBtn = this.querySelector<HTMLButtonElement>('#fcias-db-refresh')
		const algoSelect = this.querySelector<HTMLSelectElement>('#fcias-db-algo')
		const minCountInput = this.querySelector<HTMLInputElement>('#fcias-db-min-count')
		const limitInput = this.querySelector<HTMLInputElement>('#fcias-db-limit')
		const verifyBtn = this.querySelector<HTMLButtonElement>('#fcias-db-verify')
		const verifiedOnly = this.querySelector<HTMLInputElement>('#fcias-db-verified-only')
		const resultsEl = this.querySelector<HTMLDivElement>('#fcias-db-results')
		const paginationEl = this.querySelector<HTMLDivElement>('#fcias-db-pagination')

		refreshBtn?.addEventListener('click', () => {
			this.#state.offset = 0
			this.load()
		})
		algoSelect?.addEventListener('change', (e: Event) => {
			this.#state.algo = (e.target as HTMLSelectElement).value
			this.#state.offset = 0
			this.load()
		})
		minCountInput?.addEventListener('change', (e: Event) => {
			this.#state.minCount = Math.max(2, parseInt((e.target as HTMLInputElement).value, 10) || 2)
			this.#state.offset = 0
			this.load()
		})
		limitInput?.addEventListener('change', (e: Event) => {
			this.#state.limit = Math.max(1, Math.min(500, parseInt((e.target as HTMLInputElement).value, 10) || 50))
			this.#state.offset = 0
			this.load()
		})
		verifyBtn?.addEventListener('click', () => {
			this.verifyGroups()
		})
		verifiedOnly?.addEventListener('change', () => {
			if (resultsEl && paginationEl) {
				this.#renderResults(resultsEl, paginationEl)
			}
		})
	}

	#bindGroupClicks(): void {
		this.querySelectorAll('.fcias-db-group-header').forEach(header => {
			header.addEventListener('click', () => {
				header.parentElement?.classList.toggle('open')
			})
		})
	}

	async load(): Promise<void> {
		const resultsEl = this.querySelector<HTMLDivElement>('#fcias-db-results')
		const paginationEl = this.querySelector<HTMLDivElement>('#fcias-db-pagination')
		const refreshBtn = this.querySelector<HTMLButtonElement>('#fcias-db-refresh')

		if (!resultsEl || !paginationEl || !refreshBtn) return

		this.#state.loading = true
		refreshBtn.disabled = true
		resultsEl.innerHTML = '<div class="fcias-db-loading">Searching …</div>'
		paginationEl.innerHTML = ''

		try {
			const params = new URLSearchParams({
				limit: String(this.#state.limit),
				offset: String(this.#state.offset),
				minCount: String(this.#state.minCount),
			})
			if (this.#state.algo) {
				params.set('algo', this.#state.algo)
			}

			const url = `${generateOcsUrl(OCS_API_V1.findAllDuplicates)}?${params.toString()}`
			const response = await fetch(url)
			if (!response.ok) throw new Error(`HTTP ${response.status}`)
			const data = await response.json() as { duplicates?: DuplicateGroup[] }

			this.#state.groups = data.duplicates || []
			this.#state.hasMore = data.duplicates ? data.duplicates.length >= this.#state.limit : false

			this.#renderResults(resultsEl, paginationEl)
		} catch {
			resultsEl.innerHTML = '<div class="fcias-db-error">Failed to load duplicates.</div>'
		} finally {
			this.#state.loading = false
			refreshBtn.disabled = false
		}
	}

	#renderResults(resultsEl: HTMLElement, paginationEl: HTMLElement): void {
		const groups = this.#state.groups

		if (groups.length === 0) {
			resultsEl.innerHTML = '<div class="fcias-db-empty">No duplicate files found.</div>'
			return
		}

		const verifiedOnly = (this.querySelector<HTMLInputElement>('#fcias-db-verified-only'))?.checked ?? false

		let html = ''
		for (const group of groups) {
			if (verifiedOnly && (group.mismatch_count ?? 0) > 0) continue

			let statusHtml = ''
			if (group.match_count !== undefined && group.mismatch_count !== undefined) {
				if (group.mismatch_count === 0) {
					statusHtml = '<span class="fcias-db-group-header-status verified">✓ Verified</span>'
				} else {
					statusHtml = `<span class="fcias-db-group-header-status mixed">${group.match_count}✓ ${group.mismatch_count}✗</span>`
				}
			}

			html += '<div class="fcias-db-group">'
			html += '<div class="fcias-db-group-header">'
			html += '<div>'
			html += `<span class="fcias-db-algo-badge">${escapeHtml(group.algo.toUpperCase())}</span>`
			html += `<span class="fcias-db-hash">${escapeHtml(group.hash_value)}</span>`
			html += '</div>'
			html += `<span class="fcias-db-count">${group.file_count} files ${statusHtml}</span>`
			html += '</div>'
			html += '<div class="fcias-db-group-body"><ul class="fcias-db-file-list">'

			for (const file of group.files) {
				let fileTag = ''
				if (file.verified === true) {
					fileTag = ' <span class="fcias-db-verified">✓</span>'
				} else if (file.verified === false) {
					const info = file.verify_error || (file.verified_hash ? `now: ${file.verified_hash}` : '?')
					fileTag = ` <span class="fcias-db-mismatch">✗ (${escapeHtml(info)})</span>`
				}
				const dirPath = file.path ? (file.path.substring(0, file.path.lastIndexOf('/')) || '/') : '/'
				const fileUrl = `${generateUrl(FRONTEND.fileLink, { fileid: file.fileid })}?dir=${encodeURIComponent(dirPath)}&opendetails=true`
				html += `<li class="fcias-db-file-item"><a href="${escapeHtml(fileUrl)}" target="_blank" rel="noreferrer noopener">${escapeHtml(file.path || file.name)}</a>${fileTag}</li>`
			}

			html += '</ul></div></div>'
		}
		if (html === '') {
			html = '<div class="fcias-db-empty">No matching duplicate files found.</div>'
		}
		resultsEl.innerHTML = html
		this.#bindGroupClicks()

		let pagHtml = ''
		if (this.#state.offset > 0) {
			pagHtml += '<button id="fcias-db-prev">← Previous</button>'
		}
		if (this.#state.hasMore) {
			pagHtml += '<button id="fcias-db-next">Next →</button>'
		}
		paginationEl.innerHTML = pagHtml

		const prevBtn = this.querySelector<HTMLButtonElement>('#fcias-db-prev')
		const nextBtn = this.querySelector<HTMLButtonElement>('#fcias-db-next')
		prevBtn?.addEventListener('click', () => {
			this.#state.offset = Math.max(0, this.#state.offset - this.#state.limit)
			this.load()
		})
		nextBtn?.addEventListener('click', () => {
			this.#state.offset += this.#state.limit
			this.load()
		})
	}

	async verifyGroups(): Promise<void> {
		const verifyBtn = this.querySelector<HTMLButtonElement>('#fcias-db-verify')
		const resultsEl = this.querySelector<HTMLDivElement>('#fcias-db-results')
		const paginationEl = this.querySelector<HTMLDivElement>('#fcias-db-pagination')

		if (!verifyBtn || !resultsEl || !paginationEl) return

		verifyBtn.disabled = true
		verifyBtn.textContent = 'Verifying …'

		for (const group of this.#state.groups) {
			let matchCount = 0
			let mismatchCount = 0

			for (const file of group.files) {
				try {
					const url = `${generateOcsUrl(OCS_API_V1.recalcHash, { fileId: file.fileid })}?algo=${group.algo}`
					const res = await fetch(url, {
						method: 'POST',
						headers: { requesttoken: OC.requestToken },
					})
					const result = await res.json() as { success?: boolean; hash?: string; error?: string }
					if (result.success) {
						file.verified_hash = result.hash
						if (result.hash === group.hash_value) {
							file.verified = true
							matchCount++
						} else {
							file.verified = false
							mismatchCount++
						}
					} else {
						file.verified = false
						file.verify_error = result.error || 'Failed'
						mismatchCount++
					}
				} catch {
					file.verified = false
					file.verify_error = 'Network error'
					mismatchCount++
				}
			}

			group.match_count = matchCount
			group.mismatch_count = mismatchCount
		}

		verifyBtn.textContent = '✓ Verified'
		this.#renderResults(resultsEl, paginationEl)
	}
}

if (!customElements.get(TAG)) {
	customElements.define(TAG, DuplicateBrowser)
}
