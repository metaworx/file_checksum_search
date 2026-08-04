import { getSidebar, registerFileAction } from '@nextcloud/files'
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
            } else {
                let html = '<table class="fcias-hash-table"><tbody>'
                for (const entry of hashes) {
                    html += '<tr>'
                    html += `<td><span class="fcias-algo-badge">${escapeHtml(entry.algo)}</span></td>`
                    html += `<td class="fcias-hash-value"><span class="fcias-selectable-hash" data-hash="${escapeHtml(entry.hash)}">${escapeHtml(entry.hash)}</span></td>`
                    html += '</tr>'
                }
                html += '</tbody></table>'
                container.innerHTML = html

                // Copy-to-clipboard handlers
                container.querySelectorAll('.fcias-selectable-hash').forEach(el => {
                    el.addEventListener('click', () => {
                        const hash = el.getAttribute('data-hash')
                        navigator.clipboard.writeText(hash).then(() => {
                            this.showToast(t('file_checksum_search', 'Copied!'))
                        })
                    })
                })
            }

            // Recalc buttons
            const btnContainer = document.createElement('div')
            btnContainer.innerHTML = `
                <button class="fcias-recalc-btn" data-algo="sha1">${escapeHtml(t('file_checksum_search', 'Recalc SHA-1'))}</button>
                <button class="fcias-recalc-btn" data-algo="md5">${escapeHtml(t('file_checksum_search', 'Recalc MD5'))}</button>
            `
            container.appendChild(btnContainer)

            btnContainer.querySelectorAll('.fcias-recalc-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const algo = btn.getAttribute('data-algo')
                    btn.disabled = true
                    btn.textContent = '…'
                    try {
                        const recalcUrl = OC.generateUrl('/apps/file_checksum_search/api/1.0/file/{fileId}/recalc', { fileId })
                        const res = await fetch(recalcUrl + '?algo=' + algo, {
                            method: 'POST',
                            headers: { 'requesttoken': OC.requestToken },
                        })
                        const result = await res.json()
                        if (result.success) {
                            this.loadHashes()
                        } else {
                            btn.textContent = t('file_checksum_search', 'Error')
                            btn.disabled = false
                        }
                    } catch {
                        btn.textContent = t('file_checksum_search', 'Error')
                        btn.disabled = false
                    }
                })
            })
        } catch (err) {
            if (err.name === 'AbortError') return
            container.innerHTML = `<p class="fcias-error">${escapeHtml(t('file_checksum_search', 'Failed to load checksums.'))}</p>`
        }
    }

    showToast(message) {
        const toast = document.createElement('div')
        toast.className = 'fcias-copied-toast'
        toast.textContent = message
        document.body.appendChild(toast)
        setTimeout(() => toast.remove(), 2000)
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

// File menu action: opens sidebar directly to Checksums tab
const checksumIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12,1L3,5V11C3,16.55 6.84,21.74 12,23C17.16,21.74 21,16.55 21,11V5L12,1Z" /></svg>'
const checksumName = t('file_checksum_search', 'Checksums')

registerFileAction({
    id: 'file_checksum_search-checksums',
    displayName() {
        return checksumName
    },
    iconSvgInline() {
        return checksumIcon
    },
    order: 55,
    enabled({ nodes }) {
        if (nodes.length !== 1) return false
        return nodes[0]?.type === 'file'
    },
    async exec({ nodes }) {
        getSidebar().open(nodes[0], 'file_checksum_search-checksums')
        return null
    },
})
