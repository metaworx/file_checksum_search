( function () {
	'use strict';

	/**
	 * Escape HTML to prevent XSS.
	 */
	function escapeHtml( str ) {
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	if ( !OCA.Files || !OCA.Files.Sidebar ) {
		return;
	}

	const ChecksumSidebarTab = function () {
		OCA.Files.Sidebar.Tab.call( this, 'checksums', {
			label: t( 'file_checksum_search', 'Checksums' ),
			icon: 'icon-info',
		} );
	};

	ChecksumSidebarTab.prototype = Object.create( OCA.Files.Sidebar.Tab.prototype );
	ChecksumSidebarTab.prototype.constructor = ChecksumSidebarTab;

	ChecksumSidebarTab.prototype.update = function ( fileInfo ) {
		if ( !fileInfo || !fileInfo.id ) {
			this.setContent( '<p class="fcias-empty">' + escapeHtml( t( 'file_checksum_search', 'No file selected.' ) ) + '</p>' );
			return;
		}

		this.setContent( '<p class="fcias-loading">' + escapeHtml( t( 'file_checksum_search', 'Loading checksums…' ) ) + '</p>' );

		var self = this;
		var url = OC.generateUrl( '/apps/file_checksum_search/api/1.0/file/{fileId}/hashes', {
			fileId: fileInfo.id,
		} );

		fetch( url )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( !data.hashes || data.hashes.length === 0 ) {
					self.setContent( '<p class="fcias-empty">' + escapeHtml( t( 'file_checksum_search', 'No checksums available for this file.' ) ) + '</p>' );
					return;
				}

				var html = '<table class="fcias-hash-table"><tbody>';
				data.hashes.forEach( function ( entry ) {
					html += '<tr>';
					html += '<td><span class="fcias-algo-badge">' + escapeHtml( entry.algo ) + '</span></td>';
					html += '<td class="fcias-hash-value"><span class="fcias-selectable-hash">' + escapeHtml( entry.hash ) + '</span></td>';
					html += '</tr>';
				} );
				html += '</tbody></table>';

				self.setContent( html );
			} )
			.catch( function () {
				self.setContent( '<p class="fcias-error">' + escapeHtml( t( 'file_checksum_search', 'Failed to load checksums.' ) ) + '</p>' );
			} );
	};

	OCA.Files.Sidebar.registerTab( new ChecksumSidebarTab() );
} )();
