import { getSidebar, registerFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { escapeHtml } from './utils.js'
import appIconSvg from '../img/app.svg'

const APP_ICON = appIconSvg.replace( 'fill="#fff"', 'fill="currentColor"' )
const TAG = 'file_checksum_search-files-sidebar-tab'

class ChecksumsSidebarTab extends HTMLElement {

	#node = null
	folder = null
	view = null
	active = false
	#abortController = null
	#rendered = false

	get node() {
		return this.#node
	}

	set node( val ) {
		this.#node = val
		if ( this.#rendered && val ) {
			this.loadHashes()
		}
	}

	connectedCallback() {
		this.render()
		this.#rendered = true
		this.loadHashes()
	}

	disconnectedCallback() {
		this.#abortController?.abort()
	}

	render() {
		this.innerHTML = `
            <style>
                .fcias-hash-table { width: 100%; border-collapse: collapse; font-size: 13px; }
                .fcias-hash-table td { padding: 6px 8px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
                .fcias-algo-badge {
                    display: inline-block; padding: 2px 6px; border-radius: 4px;
                    background: var(--color-background-dark); font-weight: 600; font-size: 11px;
                }
                .fcias-selectable-hash { font-family: var(--font-face-monospace); word-break: break-all; user-select: all; cursor: pointer; }
                .fcias-selectable-hash:hover { background: var(--color-background-hover); }
                .fcias-loading, .fcias-empty, .fcias-error { padding: 12px; color: var(--color-text-maxcontrast); }
                .fcias-error { color: var(--color-error); }
                .fcias-copied-toast {
                    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
                    background: var(--color-success); color: #fff; padding: 8px 16px;
                    border-radius: 4px; font-size: 13px; z-index: 9999; pointer-events: none;
                }
                .fcias-recalc-btn {
                    display: inline-block; margin: 8px 8px 0 0; padding: 4px 10px;
                    font-size: 12px; cursor: pointer;
                }
                .fcias-recalc-btn:disabled { opacity: 0.5; cursor: default; }
                .fcias-dup-section { margin-top: 12px; }
                .fcias-dup-btn { padding: 4px 12px; font-size: 12px; cursor: pointer; }
                .fcias-dup-btn:disabled { opacity: 0.5; cursor: default; }
                .fcias-dup-results { margin-top: 8px; }
                .fcias-dup-group { margin-bottom: 10px; }
                .fcias-dup-group-header { margin-bottom: 4px; }
                .fcias-dup-hash-label { font-family: var(--font-face-monospace); font-size: 11px; word-break: break-all; color: var(--color-text-maxcontrast); }
                .fcias-dup-list { margin: 4px 0 0 0; padding: 0; list-style: none; font-size: 12px; }
                .fcias-dup-item { padding: 3px 0 3px 8px; border-left: 2px solid var(--color-border); margin-bottom: 2px; }
                .fcias-dup-item-link { font-weight: 500; word-break: break-all; color: var(--color-main-text); }
                .fcias-dup-item-link:hover { color: var(--color-primary-element); }
            </style>
            <div class="fcias-container">
                <p class="fcias-loading">${ escapeHtml( t( 'file_checksum_search', 'Loading checksums …' ) ) }</p>
            </div>
        `
	}

	async loadHashes() {
		const container = this.querySelector( '.fcias-container' )
		if ( !container ) return

		const fileId = this.node?.fileid ?? this.node?.attributes?.fileid
		if ( !fileId ) {
			container.innerHTML = `<p class="fcias-empty">${ escapeHtml( t( 'file_checksum_search', 'No file selected.' ) ) }</p>`
			return
		}

		this.#abortController?.abort()
		this.#abortController = new AbortController()

		container.innerHTML = `<p class="fcias-loading">${ escapeHtml( t( 'file_checksum_search', 'Loading checksums …' ) ) }</p>`

		try {
			const url = OC.generateUrl( 'file_checksum_search.publicapi.gethashes', {
				fileId,
			} )
			const response = await fetch( url, { signal: this.#abortController.signal } )
			if ( !response.ok ) throw new Error( 'HTTP ' + response.status )
			const data = await response.json()

			const hashes = data.hashes || []
			if ( hashes.length === 0 ) {
				container.innerHTML = `<p class="fcias-empty">${ escapeHtml( t( 'file_checksum_search', 'No checksums available for this file.' ) ) }</p>`
			} else {
				let html = '<table class="fcias-hash-table"><tbody>'
				for ( const entry of hashes ) {
					const titleAttr = entry.updated_at
						? ` title="Last computed: ${entry.updated_at}"`
						: ''
					html += '<tr>'
					html += `<td><span class="fcias-algo-badge">${ escapeHtml( entry.algo ) }</span></td>`
					html += `<td class="fcias-hash-value"><span class="fcias-selectable-hash" data-hash="${ escapeHtml( entry.hash ) }"${titleAttr}>${ escapeHtml( entry.hash ) }</span></td>`
					html += '</tr>'
				}
				// Debug: log first entry to verify updated_at
				if (hashes.length > 0) {
					console.log('FCIAS sidebar: first hash entry', JSON.stringify(hashes[0]))
				}
				html += '</tbody></table>'
				container.innerHTML = html

				// Copy-to-clipboard handlers
				container.querySelectorAll( '.fcias-selectable-hash' ).forEach( el => {
					el.addEventListener( 'click', () => {
						const hash = el.getAttribute( 'data-hash' )
						navigator.clipboard.writeText( hash ).then( () => {
							this.showToast( t( 'file_checksum_search', 'Copied!' ) )
						} )
					} )
				} )
			}

			// Recalc buttons
			const btnContainer = document.createElement( 'div' )
			btnContainer.innerHTML = `
                <button class="fcias-recalc-btn" data-algo="sha1">${ escapeHtml( t( 'file_checksum_search', 'Recalc SHA-1' ) ) }</button>
                <button class="fcias-recalc-btn" data-algo="md5">${ escapeHtml( t( 'file_checksum_search', 'Recalc MD5' ) ) }</button>
            `
			container.appendChild( btnContainer )

			btnContainer.querySelectorAll( '.fcias-recalc-btn' ).forEach( btn => {
				btn.addEventListener( 'click', async () => {
					const algo = btn.getAttribute( 'data-algo' )
					btn.disabled = true
					btn.textContent = '…'
					try {
						const recalcUrl = OC.generateUrl( 'file_checksum_search.publicapi.recalchash', { fileId } )
						const res = await fetch( recalcUrl + '?algo=' + algo, {
							method: 'POST',
							headers: { 'requesttoken': OC.requestToken },
						} )
						const result = await res.json()
						if ( result.success ) {
							this.loadHashes()
						} else {
							btn.textContent = t( 'file_checksum_search', 'Error' )
							btn.disabled = false
						}
					} catch {
						btn.textContent = t( 'file_checksum_search', 'Error' )
						btn.disabled = false
					}
				} )
			} )

			// Find duplicates button + results container
			const dupSection = document.createElement( 'div' )
			dupSection.className = 'fcias-dup-section'
			dupSection.innerHTML = `
                <button class="fcias-dup-btn">${ escapeHtml( t( 'file_checksum_search', 'Find duplicates' ) ) }</button>
                <div class="fcias-dup-results" style="display:none"></div>
            `
			container.appendChild( dupSection )

			dupSection.querySelector( '.fcias-dup-btn' ).addEventListener( 'click', () => {
				this.toggleDuplicates()
			} )
		} catch ( err ) {
			if ( err.name === 'AbortError' ) return
			container.innerHTML = `<p class="fcias-error">${ escapeHtml( t( 'file_checksum_search', 'Failed to load checksums.' ) ) }</p>`
		}
	}

	async toggleDuplicates() {
		const container = this.querySelector( '.fcias-container' )
		if ( !container ) return

		const results = container.querySelector( '.fcias-dup-results' )
		const btn = container.querySelector( '.fcias-dup-btn' )
		if ( !results || !btn ) return

		// If already loaded, just toggle visibility
		if ( results.hasAttribute( 'data-loaded' ) ) {
			results.style.display = results.style.display === 'none' ? '' : 'none'
			return
		}

		btn.disabled = true
		btn.textContent = '…'
		results.style.display = ''
		results.innerHTML = `<span class="fcias-loading">${ escapeHtml( t( 'file_checksum_search', 'Searching …' ) ) }</span>`
		results.setAttribute( 'data-loaded', 'true' )

		try {
			const fileId = this.node?.fileid ?? this.node?.attributes?.fileid
			const url = OC.generateUrl( 'file_checksum_search.publicapi.findduplicates', { fileId } )
			const response = await fetch( url )
			if ( !response.ok ) throw new Error( 'HTTP ' + response.status )
			const data = await response.json()

			const duplicates = data.duplicates || []

			if ( duplicates.length === 0 ) {
				results.innerHTML = `<span class="fcias-empty">${ escapeHtml( t( 'file_checksum_search', 'No other files share checksums with this file.' ) ) }</span>`
				return
			}

			let html = ''
			for ( const group of duplicates ) {
				html += '<div class="fcias-dup-group">'
				html += `<div class="fcias-dup-group-header"><span class="fcias-algo-badge">${ escapeHtml( group.algo ) }</span> <span class="fcias-dup-hash-label">${ escapeHtml( group.hash_value ) }</span></div>`
				html += '<ul class="fcias-dup-list">'
				for ( const f of group.files ) {
					const dirPath = f.path ? f.path.substring( 0, f.path.lastIndexOf( '/' ) ) || '/' : '/'
					const fileUrl = OC.generateUrl( '/apps/files/files/{fileid}', { fileid: f.fileid } )
						+ '?dir=' + encodeURIComponent( dirPath )
						+ '&opendetails=true'
					html += `<li class="fcias-dup-item"><a class="fcias-dup-item-link" href="${ escapeHtml( fileUrl ) }" target="_blank" rel="noreferrer noopener">${ escapeHtml( f.path ) }</a></li>`
				}
				html += '</ul></div>'
			}
			results.innerHTML = html
		} catch {
			results.innerHTML = `<span class="fcias-error">${ escapeHtml( t( 'file_checksum_search', 'Failed to load duplicates.' ) ) }</span>`
		} finally {
			btn.disabled = false
			btn.textContent = t( 'file_checksum_search', 'Find duplicates' )
		}
	}

	showToast( message ) {
		const toast = document.createElement( 'div' )
		toast.className = 'fcias-copied-toast'
		toast.textContent = message
		document.body.appendChild( toast )
		setTimeout( () => toast.remove(), 2000 )
	}

}

if ( !customElements.get( TAG ) ) {
	customElements.define( TAG, ChecksumsSidebarTab )
}

getSidebar().registerTab( {
	id: 'file_checksum_search-checksums',
	displayName: t( 'file_checksum_search', 'Checksums' ),
	iconSvgInline: APP_ICON,
	order: 55,
	tagName: TAG,
	enabled( { node } ) {
		return node?.type === 'file'
	},
} )

// File menu action: opens sidebar directly to Checksums tab
const checksumIcon = APP_ICON
const checksumName = t( 'file_checksum_search', 'Checksums' )

registerFileAction( {
	id: 'file_checksum_search-checksums',
	displayName() {
		return checksumName
	},
	iconSvgInline() {
		return checksumIcon
	},
	order: 55,
	enabled( { nodes } ) {
		if ( nodes.length !== 1 ) return false
		return nodes[ 0 ]?.type === 'file'
	},
	async exec( { nodes } ) {
		getSidebar().open( nodes[ 0 ], 'file_checksum_search-checksums' )
		return null
	},
} )
