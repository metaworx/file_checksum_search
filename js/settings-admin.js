( function () {
	'use strict';

	const statusUrl = OC.generateUrl( '/apps/file_checksum_search/settings/status' );
	const compatUrl = OC.generateUrl( '/apps/file_checksum_search/settings/compatibility' );
	const purgeUrl = OC.generateUrl( '/apps/file_checksum_search/settings/purge' );
	const rebuildUrl = OC.generateUrl( '/apps/file_checksum_search/settings/rebuild' );
	const teardownUrl = OC.generateUrl( '/apps/file_checksum_search/settings/teardown' );
	const removeTableUrl = OC.generateUrl( '/apps/file_checksum_search/settings/remove-table' );
	const deployTriggersUrl = OC.generateUrl( '/apps/file_checksum_search/settings/deploy-triggers' );
	const createTableUrl = OC.generateUrl( '/apps/file_checksum_search/settings/create-table' );
	const cronDefinitionsUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/definitions' );
	const cronSaveUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/save' );
	const cronDeleteUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/delete' );
	const cronToggleUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/toggle' );
	const cronSnippetUrl = OC.generateUrl( '/apps/file_checksum_search/settings/cron/snippet' );
	const rehashBehaviorUrl = OC.generateUrl( '/apps/file_checksum_search/settings/rehash-behavior' );

	let editingDefinitionId = null;
	let supportedAlgos = [];
	let availableUsers = [];

	function setText( id, text ) {
		const el = document.getElementById( id );
		if ( el ) {
			el.textContent = text;
		}
	}

	function setHtml( id, html ) {
		const el = document.getElementById( id );
		if ( el ) {
			el.innerHTML = html;
		}
	}

	function loadStatus() {
		fetch( statusUrl )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				setText( 'fcias-status-version', data.version || '—' );
				setText( 'fcias-status-dbversion', data.dbVersion || '—' );
				setText( 'fcias-status-rowcount', String( data.rowCount || 0 ) );
				setText( 'fcias-status-pending', String( data.pendingRows || 0 ) );
var tablesHtml = '';
var tables = data.tables || [];
for ( var i = 0; i < tables.length; i++ ) {
	tablesHtml += '<span class="' + ( tables[ i ].ok ? 'fcias-compat-pass' : 'fcias-compat-fail' ) + '">' +
		escapeHtml( tables[ i ].name ) + ': ' + ( tables[ i ].ok ? 'OK' : 'MISSING' ) + '</span>';
	if ( i < tables.length - 1 ) {
		tablesHtml += '<br>';
	}
}
setHtml( 'fcias-status-tables', tablesHtml || '—' );

var sp = data.sp || {};
setHtml( 'fcias-status-sp',
	'<span class="' + ( sp.ok ? 'fcias-compat-pass' : 'fcias-compat-fail' ) + '">' +
	escapeHtml( sp.name || '—' ) + ': ' + ( sp.ok ? 'OK' : 'MISSING' ) +
	'</span>' );

var triggersHtml = '';
var triggers = data.triggers || [];
for ( var j = 0; j < triggers.length; j++ ) {
	triggersHtml += '<span class="' + ( triggers[ j ].ok ? 'fcias-compat-pass' : 'fcias-compat-fail' ) + '">' +
		escapeHtml( triggers[ j ].name ) + ': ' + ( triggers[ j ].ok ? 'OK' : 'MISSING' ) + '</span>';
	if ( j < triggers.length - 1 ) {
		triggersHtml += '<br>';
	}
}
setHtml( 'fcias-status-triggers', triggersHtml || '—' );
			} )
			.catch( function () {
				setHtml( 'fcias-msg', '<p class="fcias-error">Failed to load status.</p>' );
			} );
	}

	function runCompat() {
		setHtml( 'fcias-compat-results', '<p>Running…</p>' );
		fetch( compatUrl )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				let html = '';
				const checks = data.checks || {};
				Object.keys( checks ).forEach( function ( key ) {
					const c = checks[ key ];
					const cls = c.pass ? 'fcias-compat-pass' : 'fcias-compat-fail';
					html += '<p class="' + cls + '">' + escapeHtml( c.label ) + ': ' + escapeHtml( c.value ) + '</p>';
				} );
				html += '<p><strong>' + ( data.allPass ? 'All checks passed.' : 'Some checks failed.' ) + '</strong></p>';
				setHtml( 'fcias-compat-results', html );
			} )
			.catch( function () {
				setHtml( 'fcias-compat-results', '<p class="fcias-error">Compatibility test failed.</p>' );
			} );
	}

	function buildResultMessage( data ) {
		if ( data.total !== undefined ) {
			return data.processed + ' of ' + data.total + ' records processed.';
		}
		if ( data.before !== undefined ) {
			return 'Row count: ' + data.before + ' → ' + data.after + '.';
		}
		return 'Action completed successfully.';
	}

	function postAction( url, callback ) {
		setHtml( 'fcias-msg', '' );
		fetch( url, {
			method: 'POST',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json',
			},
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( data.success ) {
					OC.dialogs.message(
						buildResultMessage( data ),
						'Success',
						'notice',
						OC.dialogs.OK_BUTTONS,
						function () {
							if ( callback ) {
								callback();
							}
						}
					);
				} else {
					OC.dialogs.message(
						data.error || 'Action failed.',
						'Error',
						'warning',
						OC.dialogs.OK_BUTTONS,
						function () {
							if ( callback ) {
								callback();
							}
						}
					);
				}
			} )
			.catch( function () {
				OC.dialogs.message(
					'Request failed.',
					'Error',
					'warning',
					OC.dialogs.OK_BUTTONS,
					function () {
						if ( callback ) {
							callback();
						}
					}
				);
			} );
	}

	function confirmAndPost( url, message, callback ) {
		setHtml( 'fcias-msg', '' );
		OC.dialogs.confirm(
			message,
			'Confirm',
			function ( confirmed ) {
				if ( confirmed ) {
					postAction( url, callback );
				}
			},
			true
		);
	}

	function populateDropdowns() {
		const algoSelects = document.querySelectorAll( '#fcias-cron-algo, #fcias-snippet-algo' );
		algoSelects.forEach( function ( sel ) {
			sel.innerHTML = '';
			supportedAlgos.forEach( function ( algo ) {
				const opt = document.createElement( 'option' );
				opt.value = algo;
				opt.textContent = algo;
				sel.appendChild( opt );
			} );
		} );

		const userSelects = document.querySelectorAll( '#fcias-cron-userscope, #fcias-snippet-userscope' );
		userSelects.forEach( function ( sel ) {
			const currentValue = sel.value;
			sel.innerHTML = '<option value="all">All Users</option>';
			availableUsers.forEach( function ( uid ) {
				const opt = document.createElement( 'option' );
				opt.value = uid;
				opt.textContent = uid;
				sel.appendChild( opt );
			} );
			sel.value = currentValue;
		} );
	}

	function loadDefinitions() {
		fetch( cronDefinitionsUrl )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				supportedAlgos = data.supportedAlgos || [];
				availableUsers = data.users || [];
				populateDropdowns();
				const definitions = data.definitions || [];
				renderDefinitionList( definitions );
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Failed to load definitions.</p>' );
			} );
	}

	function renderDefinitionList( definitions ) {
		const container = document.getElementById( 'fcias-cron-list' );
		if ( !container ) {
			return;
		}
		if ( definitions.length === 0 ) {
			container.innerHTML = '<p>No definitions yet.</p>';
			return;
		}
		let html = '<table class="grid fcias-cron-table"><thead><tr>' +
			'<th>User</th><th>Path</th><th>Algo</th><th>Interval</th><th>Batch</th><th>Last Run</th><th>Duration</th><th>Status</th><th></th>' +
			'</tr></thead><tbody>';
		definitions.forEach( function ( def ) {
			const intervalLabel = getIntervalLabel( def.interval || 900 );
			const statusClass = def.enabled ? 'fcias-compat-pass' : 'fcias-compat-fail';
			const statusText = def.enabled ? 'Enabled' : 'Disabled';
			const lastRunText = formatTimestamp( def.lastRun );
			const durationText = formatDuration( def.execDuration );
			html += '<tr data-id="' + escapeHtml( def.id ) + '">' +
				'<td>' + escapeHtml( def.userScope || 'all' ) + '</td>' +
				'<td>' + escapeHtml( def.path || '/' ) + '</td>' +
				'<td>' + escapeHtml( def.algo || 'sha1' ) + '</td>' +
				'<td>' + escapeHtml( intervalLabel ) + '</td>' +
				'<td>' + ( def.batchSize || 100 ) + '</td>' +
				'<td>' + escapeHtml( lastRunText ) + '</td>' +
				'<td>' + escapeHtml( durationText ) + '</td>' +
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

	function formatTimestamp( ts ) {
		if ( !ts || ts === 0 ) {
			return '—';
		}
		const d = new Date( ts * 1000 );
		return d.toLocaleString();
	}

	function formatDuration( seconds ) {
		if ( !seconds || seconds === 0 ) {
			return '—';
		}
		if ( seconds < 60 ) {
			return seconds + 's';
		}
		if ( seconds < 3600 ) {
			return ( seconds / 60 ).toFixed( 1 ) + 'm';
		}
		return ( seconds / 3600 ).toFixed( 1 ) + 'h';
	}

	function getIntervalLabel( seconds ) {
		switch ( seconds ) {
			case 300:
				return '5 minutes';
			case 900:
				return '15 minutes';
			case 1800:
				return '30 minutes';
			case 3600:
				return '60 minutes';
			default:
				return seconds + 's';
		}
	}

	function showDefinitionForm( def ) {
		editingDefinitionId = def ? def.id : null;
		document.getElementById( 'fcias-cron-userscope' ).value = def ? ( def.userScope || 'all' ) : 'all';
		document.getElementById( 'fcias-cron-path' ).value = def ? ( def.path || '/' ) : '/';
		document.getElementById( 'fcias-cron-algo' ).value = def ? ( def.algo || 'sha1' ) : 'sha1';
		document.getElementById( 'fcias-cron-batchsize' ).value = def ? ( def.batchSize || 100 ) : 100;
		document.getElementById( 'fcias-cron-interval' ).value = def ? ( def.interval || 900 ) : 900;
		document.getElementById( 'fcias-cron-form' ).style.display = 'block';
	}

	function hideDefinitionForm() {
		editingDefinitionId = null;
		document.getElementById( 'fcias-cron-form' ).style.display = 'none';
	}

	function saveDefinition() {
		const def = {
			id: editingDefinitionId || undefined,
			enabled: true,
			userScope: document.getElementById( 'fcias-cron-userscope' ).value,
			path: document.getElementById( 'fcias-cron-path' ).value,
			algo: document.getElementById( 'fcias-cron-algo' ).value,
			batchSize: parseInt( document.getElementById( 'fcias-cron-batchsize' ).value, 10 ) || 100,
			interval: parseInt( document.getElementById( 'fcias-cron-interval' ).value, 10 ) || 900,
		};
		fetch( cronSaveUrl, {
			method: 'POST',
			headers: {
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( def ),
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( data.success ) {
					hideDefinitionForm();
					loadDefinitions();
					OC.Notification.showTemporary( 'Definition saved.' );
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
			'Delete this definition? The job will be removed from the NC job list.',
			'Confirm Delete',
			function ( confirmed ) {
				if ( !confirmed ) {
					return;
				}
				fetch( cronDeleteUrl, {
					method: 'POST',
					headers: {
						'requesttoken': OC.requestToken,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( { id: id } ),
				} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( data ) {
						if ( data.success ) {
							loadDefinitions();
							OC.Notification.showTemporary( 'Definition deleted.' );
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
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( data.success ) {
					loadDefinitions();
					OC.Notification.showTemporary( enabled ? 'Job enabled.' : 'Job disabled.' );
				} else {
					setHtml( 'fcias-cron-msg', '<p class="fcias-error">' + escapeHtml( data.error || 'Toggle failed.' ) + '</p>' );
				}
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Request failed.</p>' );
			} );
	}

	function generateSnippet() {
		const form = document.getElementById( 'fcias-snippet-form' );
		if ( form && form.style.display === 'none' ) {
			form.style.display = 'block';
			return;
		}
		const params = new URLSearchParams( {
			userScope: document.getElementById( 'fcias-snippet-userscope' ).value,
			path: document.getElementById( 'fcias-snippet-path' ).value,
			algo: document.getElementById( 'fcias-snippet-algo' ).value,
			batchSize: document.getElementById( 'fcias-snippet-batchsize' ).value,
			interval: document.getElementById( 'fcias-snippet-interval' ).value,
		} );
		fetch( cronSnippetUrl + '?' + params.toString() )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				document.getElementById( 'fcias-cron-snippet' ).textContent = data.snippet || '';
				document.getElementById( 'fcias-cron-snippet-container' ).style.display = 'block';
			} )
			.catch( function () {
				setHtml( 'fcias-cron-msg', '<p class="fcias-error">Failed to generate snippet.</p>' );
			} );
	}

	function copySnippet() {
		const pre = document.getElementById( 'fcias-cron-snippet' );
		const text = pre ? pre.textContent : '';
		if ( text ) {
			navigator.clipboard.writeText( text ).then( function () {
				OC.Notification.showTemporary( 'Copied to clipboard.' );
			} ).catch( function () {
				OC.Notification.showTemporary( 'Copy failed. Please copy manually.' );
			} );
		}
	}

	function escapeHtml( str ) {
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		loadStatus();

		const btnCompat = document.getElementById( 'fcias-btn-compat' );
		if ( btnCompat ) {
			btnCompat.addEventListener( 'click', runCompat );
		}

		const btnPurge = document.getElementById( 'fcias-btn-purge' );
		if ( btnPurge ) {
			btnPurge.addEventListener( 'click', function () {
				confirmAndPost( purgeUrl, 'This will delete ALL checksum index data. Continue?', loadStatus );
			} );
		}

		const btnRebuild = document.getElementById( 'fcias-btn-rebuild' );
		if ( btnRebuild ) {
			btnRebuild.addEventListener( 'click', function () {
				confirmAndPost( rebuildUrl, 'This will repopulate the hash table from existing filecache checksums. Continue?', loadStatus );
			} );
		}

		const btnTeardown = document.getElementById( 'fcias-btn-teardown' );
		if ( btnTeardown ) {
			btnTeardown.addEventListener( 'click', function () {
				confirmAndPost( teardownUrl, 'This will remove FCIAS triggers and stored procedure. Hash table is preserved. Continue?', loadStatus );
			} );
		}

		const btnRemoveTable = document.getElementById( 'fcias-btn-removetable' );
		if ( btnRemoveTable ) {
			btnRemoveTable.addEventListener( 'click', function () {
				confirmAndPost( removeTableUrl, 'This will permanently delete the hash table. Run teardown first. Continue?', loadStatus );
			} );
		}

		const btnDeploy = document.getElementById( 'fcias-btn-deploy' );
		if ( btnDeploy ) {
			btnDeploy.addEventListener( 'click', function () {
				confirmAndPost( deployTriggersUrl, 'This will create triggers and stored procedure if they are missing. Continue?', loadStatus );
			} );
		}

		const btnCreateTable = document.getElementById( 'fcias-btn-createtable' );
		if ( btnCreateTable ) {
			btnCreateTable.addEventListener( 'click', function () {
				confirmAndPost( createTableUrl, 'This will create the hash table if it does not exist. Continue?', loadStatus );
			} );
		}

		// Cron: load definitions
		loadDefinitions();

		// Cron: Add Definition button
		const btnAddDef = document.getElementById( 'fcias-btn-add-definition' );
		if ( btnAddDef ) {
			btnAddDef.addEventListener( 'click', function () {
				showDefinitionForm( null );
			} );
		}

		const btnRefresh = document.getElementById( 'fcias-btn-refresh-definitions' );
		if ( btnRefresh ) {
			btnRefresh.addEventListener( 'click', loadDefinitions );
		}

		// Cron: Save button
		const btnSaveDef = document.getElementById( 'fcias-btn-save-definition' );
		if ( btnSaveDef ) {
			btnSaveDef.addEventListener( 'click', saveDefinition );
		}

		// Cron: Cancel button
		const btnCancelDef = document.getElementById( 'fcias-btn-cancel-definition' );
		if ( btnCancelDef ) {
			btnCancelDef.addEventListener( 'click', hideDefinitionForm );
		}

		// Cron: Definition list actions (delegated)
		const cronList = document.getElementById( 'fcias-cron-list' );
		if ( cronList ) {
			cronList.addEventListener( 'click', function ( event ) {
				const btn = event.target.closest( 'button' );
				if ( !btn ) {
					return;
				}
				const action = btn.getAttribute( 'data-action' );
				const row = btn.closest( 'tr' );
				const id = row ? row.getAttribute( 'data-id' ) : null;
				if ( !id ) {
					return;
				}
				if ( action === 'edit' ) {
					// Find definition data from the row
					const def = {
						id: id,
						userScope: row.cells[ 0 ].textContent,
						path: row.cells[ 1 ].textContent,
						algo: row.cells[ 2 ].textContent,
						batchSize: parseInt( row.cells[ 4 ].textContent, 10 ) || 100,
						interval: getIntervalSeconds( row.cells[ 3 ].textContent ),
					};
					showDefinitionForm( def );
				} else if ( action === 'toggle' ) {
					const isEnabled = row.querySelector( '.fcias-compat-pass' ) !== null;
					toggleDefinition( id, !isEnabled );
				} else if ( action === 'delete' ) {
					deleteDefinition( id );
				}
			} );
		}

		// Cron: Generate Snippet button
		const btnGenSnippet = document.getElementById( 'fcias-btn-generate-snippet' );
		if ( btnGenSnippet ) {
			btnGenSnippet.addEventListener( 'click', generateSnippet );
		}

		// Cron: Copy Snippet button
		const btnCopySnippet = document.getElementById( 'fcias-btn-copy-snippet' );
		if ( btnCopySnippet ) {
			btnCopySnippet.addEventListener( 'click', copySnippet );
		}

		function getIntervalSeconds( label ) {
			switch ( label ) {
				case '5 minutes':
					return 300;
				case '15 minutes':
					return 900;
				case '30 minutes':
					return 1800;
				case '60 minutes':
					return 3600;
				default:
					return 900;
			}
		}

		function loadRehashBehavior() {
			fetch( rehashBehaviorUrl )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					var writeSel = document.getElementById( 'fcias-rehash-write' );
					var createSel = document.getElementById( 'fcias-rehash-create' );
					var deleteSel = document.getElementById( 'fcias-rehash-delete' );
					if ( writeSel ) {
						writeSel.value = data.write || 'auto';
					}
					if ( createSel ) {
						createSel.value = data.create || 'off';
					}
					if ( deleteSel ) {
						deleteSel.value = data.delete || 'off';
					}
				} )
				.catch( function () {
					setHtml( 'fcias-rehash-msg', '<p class="fcias-error">Failed to load rehash behavior settings.</p>' );
				} );
		}

		function saveRehashBehavior() {
			var writeVal = document.getElementById( 'fcias-rehash-write' )
				? document.getElementById( 'fcias-rehash-write' ).value
				: 'lazy';
			var createVal = document.getElementById( 'fcias-rehash-create' )
				? document.getElementById( 'fcias-rehash-create' ).value
				: 'off';
			var deleteVal = document.getElementById( 'fcias-rehash-delete' )
				? document.getElementById( 'fcias-rehash-delete' ).value
				: 'off';

			setHtml( 'fcias-rehash-msg', '' );

			fetch( rehashBehaviorUrl, {
				method: 'POST',
				headers: {
					'requesttoken': OC.requestToken,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( {
					write: writeVal,
					create: createVal,
					delete: deleteVal,
				} ),
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( data.success ) {
						OC.Notification.showTemporary( 'Rehash behavior settings saved.' );
					} else {
						setHtml( 'fcias-rehash-msg', '<p class="fcias-error">' + escapeHtml( data.error || 'Save failed.' ) + '</p>' );
					}
				} )
				.catch( function () {
					setHtml( 'fcias-rehash-msg', '<p class="fcias-error">Request failed.</p>' );
				} );
		}

		// Rehash behavior: load and save
		loadRehashBehavior();

		var btnSaveRehash = document.getElementById( 'fcias-btn-save-rehash' );
		if ( btnSaveRehash ) {
			btnSaveRehash.addEventListener( 'click', saveRehashBehavior );
		}
	} );
} )();
