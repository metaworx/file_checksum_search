( function () {
	'use strict';

	const statusUrl = OC.generateUrl( '/apps/file_checksum_search/settings/status' );
	const cronDefinitionsUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/definitions' );
	const cronSaveUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/save' );
	const cronDeleteUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/delete' );
	const cronToggleUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/toggle' );
	const cronSnippetUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/snippet' );

	let editingDefinitionId = null;
	let editingIsGlobal = false;
	let supportedAlgos = [];
	let availableUsers = [];

	function setText( id, text ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.textContent = text;
		}
	}

	function setHtml( id, html ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.innerHTML = html;
		}
	}

	function loadStatus() {
		fetch( statusUrl )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				setText( 'fcias-status-version', data.version || '—' );
				setText( 'fcias-status-dbversion', data.dbVersion || '—' );
				setText( 'fcias-status-rowcount', String( data.rowCount || 0 ) );

				var stats = data.pendingStats || {};
				var lines = [];
				Object.keys( stats ).forEach( function ( key ) {
					lines.push( escapeHtml( key ) + ': ' + stats[ key ] );
				} );
				if ( lines.length === 0 ) {
					lines.push( 'None' );
				}
				setHtml( 'fcias-status-pending', lines.join( '<br>' ) );
			} )
			.catch( function () {
				setHtml( 'fcias-msg', '<p class="fcias-error">Failed to load status.</p>' );
			} );
	}

	function buildAlgoCheckboxes( containerId, selectedAlgos ) {
		var container = document.getElementById( containerId );
		if ( !container ) return;
		selectedAlgos = selectedAlgos || [];
		var html = '';
		supportedAlgos.forEach( function ( algo ) {
			var checked = selectedAlgos.indexOf( algo ) !== -1 ? ' checked' : '';
			html += '<label class="fcias-checkbox-label"><input type="checkbox" name="fcias-algo" value="' + escapeHtml( algo ) + '"' + checked + '> ' + escapeHtml( algo ) + '</label> ';
		} );
		container.innerHTML = html;
	}

	function getCheckedAlgos( containerId ) {
		var container = document.getElementById( containerId );
		if ( !container ) return [];
		var boxes = container.querySelectorAll( 'input[type="checkbox"]:checked' );
		var algos = [];
		for ( var i = 0; i < boxes.length; i++ ) {
			algos.push( boxes[ i ].value );
		}
		return algos;
	}

	function populateDropdowns() {
		var userSelects = document.querySelectorAll( '#fcias-cron-userscope, #fcias-snippet-userscope' );
		userSelects.forEach( function ( sel ) {
			var currentValue = sel.value;
			sel.innerHTML = '<option value="all">All Users</option>';
			availableUsers.forEach( function ( uid ) {
				var opt = document.createElement( 'option' );
				opt.value = uid;
				opt.textContent = uid;
				sel.appendChild( opt );
			} );
			sel.value = currentValue;
		} );

		var algoSnippetSelects = document.querySelectorAll( '#fcias-snippet-algo' );
		algoSnippetSelects.forEach( function ( sel ) {
			sel.innerHTML = '';
			supportedAlgos.forEach( function ( algo ) {
				var opt = document.createElement( 'option' );
				opt.value = algo;
				opt.textContent = algo;
				sel.appendChild( opt );
			} );
		} );
	}

	function loadDefinitions() {
		fetch( cronDefinitionsUrl )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				supportedAlgos = data.supportedAlgos || [];
				availableUsers = data.users || [];
				populateDropdowns();
				var definitions = data.definitions || [];
				renderGlobalRule( definitions.length > 0 ? definitions[ 0 ] : null );
				renderAdditionalRules( definitions.length > 1 ? definitions.slice( 1 ) : [] );
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Failed to load definitions.</p>' );
			} );
	}

	function renderGlobalRule( def ) {
		var container = document.getElementById( 'fcias-global-rule' );
		if ( !container ) return;

		if ( !def ) {
			def = { enabled: true, mode: 'auto', algos: [ 'sha1' ], path: '**', userScope: 'all' };
		}

		var html = '<div class="fcias-global-rule-form">' +
			'<h5>Global Rule (priority 0)</h5>' +
			'<div class="fcias-cron-form-row">' +
			'<label>User Scope</label>' +
			'<span>' + escapeHtml( def.userScope || 'all' ) + '</span>' +
			'</div>' +
			'<div class="fcias-cron-form-row">' +
			'<label>Path</label>' +
			'<span>' + escapeHtml( def.path || '**' ) + '</span>' +
			'</div>' +
			'<div class="fcias-cron-form-row">' +
			'<label>Algorithms</label>' +
			'<div id="fcias-global-algos" class="fcias-checkbox-group"></div>' +
			'</div>' +
			'<div class="fcias-cron-form-row">' +
			'<label for="fcias-global-mode">Mode</label>' +
			'<select id="fcias-global-mode">' +
			'<option value="auto"' + ( def.mode === 'auto' ? ' selected' : '' ) + '>Auto (recalc existing only if stale)</option>' +
			'<option value="missing"' + ( def.mode === 'missing' ? ' selected' : '' ) + '>Missing (recalc existing + missing)</option>' +
			'<option value="force"' + ( def.mode === 'force' ? ' selected' : '' ) + '>Force (delete all, recalc all)</option>' +
			'<option value="lazy"' + ( def.mode === 'lazy' ? ' selected' : '' ) + '>Lazy (delete hashes, recalc later)</option>' +
			'</select>' +
			'</div>' +
			'<div class="fcias-cron-form-row">' +
			'<label>Status</label>' +
			'<span class="' + ( def.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail' ) + '">' + ( def.enabled ? 'Enabled' : 'Disabled' ) + '</span>' +
			'</div>' +
			'<div class="fcias-cron-form-actions">' +
			'<button class="fcias-btn" id="fcias-btn-save-global">Save Global Rule</button> ' +
			'<button class="fcias-btn" id="fcias-btn-toggle-global">' + ( def.enabled ? 'Disable' : 'Enable' ) + '</button>' +
			'</div>' +
			'</div>';
		container.innerHTML = html;

		buildAlgoCheckboxes( 'fcias-global-algos', def.algos || [ def.algo ] || [ 'sha1' ] );

		var globalDefId = def.id || null;
		var globalEnabled = def.enabled !== false;

		document.getElementById( 'fcias-btn-save-global' ).addEventListener( 'click', function () {
			saveGlobalRule( globalDefId );
		} );
		document.getElementById( 'fcias-btn-toggle-global' ).addEventListener( 'click', function () {
			if ( globalDefId ) {
				toggleDefinition( globalDefId, !globalEnabled );
			} else {
				// No ID yet — save first, then toggle will work after reload
				saveGlobalRule( null );
			}
		} );
	}

	function saveGlobalRule( id ) {
		var def = {
			id: id || undefined,
			enabled: true,
			mode: document.getElementById( 'fcias-global-mode' ).value,
			algos: getCheckedAlgos( 'fcias-global-algos' ),
			userScope: 'all',
			path: '**',
		};
		fetch( cronSaveUrl, {
			method: 'POST',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( def ),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					loadDefinitions();
					OC.Notification.showTemporary( 'Global rule saved.' );
				} else {
					setHtml( 'fcias-cron-msg', '<p class="fcias-error">' + escapeHtml( data.error || 'Save failed.' ) + '</p>' );
				}
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Request failed.</p>' );
			} );
	}

	function renderAdditionalRules( definitions ) {
		var container = document.getElementById( 'fcias-cron-list' );
		if ( !container ) return;

		if ( definitions.length === 0 ) {
			container.innerHTML = '<p>No additional rules.</p>';
			return;
		}

		var html = '<table class="grid fcias-cron-table"><thead><tr>' +
			'<th>Priority</th><th>User</th><th>Path</th><th>Algos</th><th>Mode</th><th>Status</th><th></th>' +
			'</tr></thead><tbody>';
		definitions.forEach( function ( def, index ) {
			var statusClass = def.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail';
			var statusText = def.enabled ? 'Enabled' : 'Disabled';
			var algosText = ( def.algos || [ def.algo ] ).join( ', ' );
			html += '<tr data-id="' + escapeHtml( def.id ) + '">' +
				'<td>' + ( index + 1 ) + '</td>' +
				'<td>' + escapeHtml( def.userScope || 'all' ) + '</td>' +
				'<td>' + escapeHtml( def.path || '/' ) + '</td>' +
				'<td>' + escapeHtml( algosText ) + '</td>' +
				'<td>' + escapeHtml( def.mode || 'auto' ) + '</td>' +
				'<td><span class="' + statusClass + '">' + statusText + '</span></td>' +
				'<td class="fcias-cron-actions">' +
				'<button class="fcias-btn fcias-btn-edit" data-action="edit">Edit</button> ' +
				'<button class="fcias-btn fcias-btn-toggle" data-action="toggle">' + ( def.enabled ? 'Disable' : 'Enable' ) + '</button> ' +
				'<button class="fcias-btn fcias-btn-danger fcias-btn-delete" data-action="delete">Delete</button>' +
				'</td>' +
				'</tr>';
		} );
		html += '</tbody></table>';
		container.innerHTML = html;
	}

	function showDefinitionForm( def ) {
		editingDefinitionId = def ? def.id : null;
		editingIsGlobal = false;
		document.getElementById( 'fcias-cron-userscope' ).value = def ? ( def.userScope || 'all' ) : 'all';
		document.getElementById( 'fcias-cron-path' ).value = def ? ( def.path || '/' ) : '/';
		document.getElementById( 'fcias-cron-mode' ).value = def ? ( def.mode || 'auto' ) : 'auto';
		buildAlgoCheckboxes( 'fcias-cron-algos', def ? ( def.algos || [ def.algo ] ) : [ 'sha1' ] );
		document.getElementById( 'fcias-cron-form' ).style.display = 'block';
	}

	function hideDefinitionForm() {
		editingDefinitionId = null;
		editingIsGlobal = false;
		document.getElementById( 'fcias-cron-form' ).style.display = 'none';
	}

	function saveDefinition() {
		var def = {
			id: editingDefinitionId || undefined,
			enabled: true,
			mode: document.getElementById( 'fcias-cron-mode' ).value,
			algos: getCheckedAlgos( 'fcias-cron-algos' ),
			userScope: document.getElementById( 'fcias-cron-userscope' ).value,
			path: document.getElementById( 'fcias-cron-path' ).value,
		};
		fetch( cronSaveUrl, {
			method: 'POST',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( def ),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					hideDefinitionForm();
					loadDefinitions();
					OC.Notification.showTemporary( 'Rule saved.' );
				} else {
					setHtml( 'fcias-cron-msg', '<p class="fcias-error">' + escapeHtml( data.error || 'Save failed.' ) + '</p>' );
				}
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Request failed.</p>' );
			} );
	}

	function deleteDefinition( id ) {
		OC.dialogs.confirm(
			'Delete this rule definition?',
			'Confirm Delete',
			function ( confirmed ) {
				if ( !confirmed ) return;
				fetch( cronDeleteUrl, {
					method: 'POST',
					headers: {
						'requesttoken': OC.requestToken,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( { id: id } ),
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						if ( data.success ) {
							loadDefinitions();
							OC.Notification.showTemporary( 'Rule deleted.' );
						} else {
							setHtml( 'fcias-cron-msg', '<p class="fcias-error">' + escapeHtml( data.error || 'Delete failed.' ) + '</p>' );
						}
					} )
					.catch( function () {
						setHtml( 'fcias-cron-msg', '<p class="fcias-error">Request failed.</p>' );
					} );
			},
			true
		);
	}

	function toggleDefinition( id, enabled ) {
		fetch( cronToggleUrl, {
			method: 'POST',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( { id: id, enabled: enabled } ),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					loadDefinitions();
					OC.Notification.showTemporary( enabled ? 'Rule enabled.' : 'Rule disabled.' );
				} else {
					setHtml( 'fcias-cron-msg', '<p class="fcias-error">' + escapeHtml( data.error || 'Toggle failed.' ) + '</p>' );
				}
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Request failed.</p>' );
			} );
	}

	function generateSnippet() {
		var form = document.getElementById( 'fcias-snippet-form' );
		if ( form && form.style.display === 'none' ) {
			form.style.display = 'block';
			return;
		}
		var params = new URLSearchParams( {
			userScope: document.getElementById( 'fcias-snippet-userscope' ).value,
			path: document.getElementById( 'fcias-snippet-path' ).value,
			algo: document.getElementById( 'fcias-snippet-algo' ).value,
			batchSize: document.getElementById( 'fcias-snippet-batchsize' ).value,
			interval: document.getElementById( 'fcias-snippet-interval' ).value,
		} );
		fetch( cronSnippetUrl + '?' + params.toString() )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				document.getElementById( 'fcias-cron-snippet' ).textContent = data.snippet || '';
				document.getElementById( 'fcias-cron-snippet-container' ).style.display = 'block';
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Failed to generate snippet.</p>' );
			} );
	}

	function copySnippet() {
		var pre = document.getElementById( 'fcias-cron-snippet' );
		var text = pre ? pre.textContent : '';
		if ( text ) {
			navigator.clipboard.writeText( text ).then( function () {
				OC.Notification.showTemporary( 'Copied to clipboard.' );
			} ).catch( function () {
				OC.Notification.showTemporary( 'Copy failed. Please copy manually.' );
			} );
		}
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		loadStatus();
		loadDefinitions();

		var btnAddDef = document.getElementById( 'fcias-btn-add-definition' );
		if ( btnAddDef ) {
			btnAddDef.addEventListener( 'click', function () { showDefinitionForm( null ); } );
		}

		var btnSaveDef = document.getElementById( 'fcias-btn-save-definition' );
		if ( btnSaveDef ) {
			btnSaveDef.addEventListener( 'click', saveDefinition );
		}

		var btnCancelDef = document.getElementById( 'fcias-btn-cancel-definition' );
		if ( btnCancelDef ) {
			btnCancelDef.addEventListener( 'click', hideDefinitionForm );
		}

		var cronList = document.getElementById( 'fcias-cron-list' );
		if ( cronList ) {
			cronList.addEventListener( 'click', function ( event ) {
				var btn = event.target.closest( 'button' );
				if ( !btn ) return;
				var action = btn.getAttribute( 'data-action' );
				var row = btn.closest( 'tr' );
				var id = row ? row.getAttribute( 'data-id' ) : null;
				if ( !id ) return;
				if ( action === 'edit' ) {
					var algosText = row.cells[ 3 ].textContent;
					var def = {
						id: id,
						userScope: row.cells[ 1 ].textContent,
						path: row.cells[ 2 ].textContent,
						algos: algosText.split( ',' ).map( function ( s ) { return s.trim(); } ),
						mode: row.cells[ 4 ].textContent.trim(),
					};
					showDefinitionForm( def );
				} else if ( action === 'toggle' ) {
					var isEnabled = row.querySelector( '.fcias-compat-pass' ) !== null;
					toggleDefinition( id, !isEnabled );
				} else if ( action === 'delete' ) {
					deleteDefinition( id );
				}
			} );
		}

		var btnGenSnippet = document.getElementById( 'fcias-btn-generate-snippet' );
		if ( btnGenSnippet ) {
			btnGenSnippet.addEventListener( 'click', generateSnippet );
		}

		var btnCopySnippet = document.getElementById( 'fcias-btn-copy-snippet' );
		if ( btnCopySnippet ) {
			btnCopySnippet.addEventListener( 'click', copySnippet );
		}
	} );
} )();
