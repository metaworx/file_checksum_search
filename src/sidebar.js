import { getSidebar } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'

const TAG = 'file_checksum_search-files-sidebar-tab'

function escapeHtml(str) {
    const div = document.createElement('div')
    div.appendChild(document.createTextNode(String(str ?? '')))
    return div.innerHTML
}

class ChecksumsSidebarTab extends HTMLElement {

    node = null
    folder = null
    view = null
    active = false
    #abortController = null

    connectedCallback() {
        this.render()
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
                .fcias-selectable-hash { font-family: var(--font-face-monospace); word-break: break-all; user-select: all; }
                .fcias-loading, .fcias-empty, .fcias-error { padding: 12px; color: var(--color-text-maxcontrast); }
                .fcias-error { color: var(--color-error); }
            </style>
            <div class="fcias-container">
                <p class="fcias-loading">${escapeHtml(t('file_checksum_search', 'Loading checksums…'))}</p>
            </div>
        `
    }

    async loadHashes() {
        const container = this.querySelector('.fcias-container')
        if (!container) return

        const fileId = this.node?.fileid ?? this.node?.attributes?.fileid
        if (!fileId) {
            container.innerHTML = `<p class="fcias-empty">${escapeHtml(t('file_checksum_search', 'No file selected.'))}</p>`
            return
        }

        this.#abortController?.abort()
        this.#abortController = new AbortController()

        container.innerHTML = `<p class="fcias-loading">${escapeHtml(t('file_checksum_search', 'Loading checksums…'))}</p>`

        try {
            const url = OC.generateUrl('/apps/file_checksum_search/api/1.0/file/{fileId}/hashes', {
                fileId,
            })
            const response = await fetch(url, { signal: this.#abortController.signal })
            if (!response.ok) throw new Error('HTTP ' + response.status)
            const data = await response.json()

            const hashes = data.hashes || []
            if (hashes.length === 0) {
                container.innerHTML = `<p class="fcias-empty">${escapeHtml(t('file_checksum_search', 'No checksums available for this file.'))}</p>`
                return
            }

            let html = '<table class="fcias-hash-table"><tbody>'
            for (const entry of hashes) {
                html += '<tr>'
                html += `<td><span class="fcias-algo-badge">${escapeHtml(entry.algo)}</span></td>`
                html += `<td class="fcias-hash-value"><span class="fcias-selectable-hash">${escapeHtml(entry.hash)}</span></td>`
                html += '</tr>'
            }
            html += '</tbody></table>'
            container.innerHTML = html
        } catch (err) {
            if (err.name === 'AbortError') return
            container.innerHTML = `<p class="fcias-error">${escapeHtml(t('file_checksum_search', 'Failed to load checksums.'))}</p>`
        }
    }

}

if (!customElements.get(TAG)) {
    customElements.define(TAG, ChecksumsSidebarTab)
}

getSidebar().registerTab({
    id: 'file_checksum_search-checksums',
    displayName: t('file_checksum_search', 'Checksums'),
    iconSvgInline: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12,1L3,5V11C3,16.55 6.84,21.74 12,23C17.16,21.74 21,16.55 21,11V5L12,1Z" /></svg>',
    order: 55,
    tagName: TAG,
    enabled({ node }) {
        return node?.type === 'file' || !!node?.fileid
    },
})
