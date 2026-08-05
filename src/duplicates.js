/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Global duplicate file browser — standalone page.
 */

import { escapeHtml } from './utils.js'

const TAG = 'fcias-duplicate-browser'

class DuplicateBrowser extends HTMLElement {

	#state = {
		algo: '',
		minCount: 2,
		limit: 50,
		offset: 0,
		groups: [],
		loading: false,
		hasMore: false,
	}

	connectedCallback() {
		this.render()
		this.load()
	}

	render() {
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

	#bindEvents() {
		this.querySelector( '#fcias-db-refresh' ).addEventListener( 'click', () => {
			this.#state.offset = 0
			this.load()
		} )
		this.querySelector( '#fcias-db-algo' ).addEventListener( 'change', ( e ) => {
			this.#state.algo = e.target.value
			this.#state.offset = 0
			this.load()
		} )
		this.querySelector( '#fcias-db-min-count' ).addEventListener( 'change', ( e ) => {
			this.#state.minCount = Math.max( 2, parseInt( e.target.value, 10 ) || 2 )
			this.#state.offset = 0
			this.load()
		} )
		this.querySelector( '#fcias-db-limit' ).addEventListener( 'change', ( e ) => {
			this.#state.limit = Math.max( 1, Math.min( 500, parseInt( e.target.value, 10 ) || 50 ) )
			this.#state.offset = 0
			this.load()
		} )
		this.querySelector( '#fcias-db-verify' ).addEventListener( 'click', () => {
			this.verifyGroups()
		} )
		this.querySelector( '#fcias-db-verified-only' ).addEventListener( 'change', ( e ) => {
			this.#renderResults(
				this.querySelector( '#fcias-db-results' ),
				this.querySelector( '#fcias-db-pagination' )
			)
		} )
	}

	#bindGroupClicks() {
		this.querySelectorAll( '.fcias-db-group-header' ).forEach( header => {
			header.addEventListener( 'click', () => {
				header.parentElement.classList.toggle( 'open' )
			} )
		} )
	}

	async load() {
		const resultsEl = this.querySelector( '#fcias-db-results' )
		const paginationEl = this.querySelector( '#fcias-db-pagination' )
		const refreshBtn = this.querySelector( '#fcias-db-refresh' )

		this.#state.loading = true
		refreshBtn.disabled = true
		resultsEl.innerHTML = '<div class="fcias-db-loading">Searching …</div>'
		paginationEl.innerHTML = ''

		try {
			const params = new URLSearchParams( {
				limit: String( this.#state.limit ),
				offset: String( this.#state.offset ),
				minCount: String( this.#state.minCount ),
			} )
			if ( this.#state.algo ) {
				params.set( 'algo', this.#state.algo )
			}

			const url = OC.generateUrl( '/apps/file_checksum_search/api/1.0/duplicates' ) + '?' + params.toString()
			const response = await fetch( url )
			if ( !response.ok ) throw new Error( 'HTTP ' + response.status )
			const data = await response.json()

			this.#state.groups = data.duplicates || []
			this.#state.hasMore = data.duplicates && data.duplicates.length >= this.#state.limit

			this.#renderResults( resultsEl, paginationEl )
		} catch ( err ) {
			resultsEl.innerHTML = '<div class="fcias-db-error">Failed to load duplicates.</div>'
		} finally {
			this.#state.loading = false
			refreshBtn.disabled = false
		}
	}

	#renderResults( resultsEl, paginationEl ) {
		const groups = this.#state.groups

		if ( groups.length === 0 ) {
			resultsEl.innerHTML = '<div class="fcias-db-empty">No duplicate files found.</div>'
			return
		}

		const verifiedOnly = this.querySelector( '#fcias-db-verified-only' )?.checked ?? false

		let html = ''
		for ( const group of groups ) {
			// Skip mismatched groups when "only verified" is checked
			if ( verifiedOnly && group.mismatch_count > 0 ) continue

			let statusHtml = ''
			if ( group.match_count !== undefined && group.mismatch_count !== undefined ) {
				if ( group.mismatch_count === 0 ) {
					statusHtml = '<span class="fcias-db-group-header-status verified">✓ Verified</span>'
				} else {
					statusHtml = `<span class="fcias-db-group-header-status mixed">${ group.match_count }✓ ${ group.mismatch_count }✗</span>`
				}
			}

			html += '<div class="fcias-db-group">'
			html += '<div class="fcias-db-group-header">'
			html += '<div>'
			html += `<span class="fcias-db-algo-badge">${ escapeHtml( group.algo.toUpperCase() ) }</span>`
			html += `<span class="fcias-db-hash">${ escapeHtml( group.hash_value ) }</span>`
			html += '</div>'
			html += `<span class="fcias-db-count">${ group.file_count } files ${ statusHtml }</span>`
			html += '</div>'
			html += '<div class="fcias-db-group-body"><ul class="fcias-db-file-list">'

			for ( const file of group.files ) {
				let fileTag = ''
				if ( file.verified === true ) {
					fileTag = ' <span class="fcias-db-verified">✓</span>'
				} else if ( file.verified === false ) {
					const info = file.verify_error || ( file.verified_hash ? 'now: ' + file.verified_hash : '?' )
					fileTag = ` <span class="fcias-db-mismatch">✗ (${ escapeHtml( info ) })</span>`
				}
				const dirPath = file.path ? file.path.substring( 0, file.path.lastIndexOf( '/' ) ) || '/' : '/'
				const fileUrl = OC.generateUrl( '/apps/files/files/{fileid}', { fileid: file.fileid } )
					+ '?dir=' + encodeURIComponent( dirPath )
					+ '&opendetails=true'
				html += `<li class="fcias-db-file-item"><a href="${ escapeHtml( fileUrl ) }" target="_blank" rel="noreferrer noopener">${ escapeHtml( file.path || file.name ) }</a>${ fileTag }</li>`
			}

			html += '</ul></div></div>'
		}
		if ( html === '' ) {
			html = '<div class="fcias-db-empty">No matching duplicate files found.</div>'
		}
		resultsEl.innerHTML = html
		this.#bindGroupClicks()

		let pagHtml = ''
		if ( this.#state.offset > 0 ) {
			pagHtml += '<button id="fcias-db-prev">← Previous</button>'
		}
		if ( this.#state.hasMore ) {
			pagHtml += '<button id="fcias-db-next">Next →</button>'
		}
		paginationEl.innerHTML = pagHtml

		const prevBtn = this.querySelector( '#fcias-db-prev' )
		const nextBtn = this.querySelector( '#fcias-db-next' )
		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', () => {
				this.#state.offset = Math.max( 0, this.#state.offset - this.#state.limit )
				this.load()
			} )
		}
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', () => {
				this.#state.offset += this.#state.limit
				this.load()
			} )
		}
	}

	async verifyGroups() {
		const verifyBtn = this.querySelector( '#fcias-db-verify' )
		const resultsEl = this.querySelector( '#fcias-db-results' )
		const verifiedLabel = this.querySelector( '#fcias-db-verified-label' )

		verifyBtn.disabled = true
		verifyBtn.textContent = 'Verifying …'

		for ( const group of this.#state.groups ) {
			let matchCount = 0
			let mismatchCount = 0

			for ( const file of group.files ) {
				try {
					const url = OC.generateUrl( '/apps/file_checksum_search/api/1.0/file/{fileId}/recalc', {
						fileId: file.fileid,
					} ) + '?algo=' + group.algo
					const res = await fetch( url, {
						method: 'POST',
						headers: { requesttoken: OC.requestToken },
					} )
					const result = await res.json()
					if ( result.success ) {
						file.verified_hash = result.hash
						if ( result.hash === group.hash_value ) {
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
		this.#renderResults( resultsEl, this.querySelector( '#fcias-db-pagination' ) )
	}
}

if ( !customElements.get( TAG ) ) {
	customElements.define( TAG, DuplicateBrowser )
}
