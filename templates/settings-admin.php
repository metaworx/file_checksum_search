<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

use OCP\Util;

Util::addScript( 'file_checksum_search', 'settings-admin' );
Util::addStyle( 'file_checksum_search', 'settings-admin' );

/** @var IL10N $l is auto-injected by NC's TemplateResponse renderer via \OCP\Util::getL10N('file_checksum_search'). */
?>

<div id="fcias-admin-settings">
	<h3><?php
		p( $l->t( 'File Checksum Index & Search' ) ); ?></h3>

	<div class="fcias-section">
		<h4><?php
			p( $l->t( 'Status' ) ); ?></h4>
		<table class="grid">
			<tbody>
			<tr>
				<td><?php
					p( $l->t( 'App Version' ) ); ?></td>
				<td id="fcias-status-version">—</td>
			</tr>
			<tr>
				<td><?php
					p( $l->t( 'Database Version' ) ); ?></td>
				<td id="fcias-status-dbversion">—</td>
			</tr>
			<tr>
				<td><?php
					p( $l->t( 'Indexed Hashes' ) ); ?></td>
				<td id="fcias-status-rowcount">—</td>
			</tr>
			<tr>
				<td><?php
					p( $l->t( 'Triggers' ) ); ?></td>
				<td id="fcias-status-triggers">—</td>
			</tr>
			<tr>
				<td><?php
					p( $l->t( 'Stored Procedure' ) ); ?></td>
				<td id="fcias-status-sp">—</td>
			</tr>
			</tbody>
		</table>
	</div>

	<div class="fcias-section">
		<h4><?php
			p( $l->t( 'Scheduled Hashing' ) ); ?></h4>

		<h5><?php
			p( $l->t( 'NC Background Job Definitions' ) ); ?></h5>
		<p class="fcias-hint"><?php
			p(
				$l->t(
					'Define one or more background jobs that automatically generate checksums for files that don\'t have one yet. Each definition runs independently with its own scope and schedule.',
				),
			); ?></p>

		<div id="fcias-cron-list">
			<p><?php
				p( $l->t( 'No definitions yet.' ) ); ?></p>
		</div>

		<button class="fcias-btn" id="fcias-btn-add-definition"><?php
			p( $l->t( 'Add Definition' ) ); ?></button>
		<button class="fcias-btn" id="fcias-btn-refresh-definitions"><?php
			p( $l->t( 'Refresh' ) ); ?></button>

		<div id="fcias-cron-form" style="display:none;">
			<div class="fcias-cron-form">
				<div class="fcias-cron-form-row">
					<label for="fcias-cron-userscope"><?php
						p( $l->t( 'User Scope' ) ); ?></label>
					<select id="fcias-cron-userscope">
						<option value="all"><?php
							p( $l->t( 'All Users' ) ); ?></option>
					</select>
				</div>
				<div class="fcias-cron-form-row">
					<label for="fcias-cron-path"><?php
						p( $l->t( 'Path' ) ); ?></label>
					<input type="text" id="fcias-cron-path" value="/" placeholder="/"/>
				</div>
				<div class="fcias-cron-form-row">
					<label for="fcias-cron-algo"><?php
						p( $l->t( 'Algorithm' ) ); ?></label>
					<select id="fcias-cron-algo"></select>
				</div>
				<div class="fcias-cron-form-row">
					<label for="fcias-cron-batchsize"><?php
						p( $l->t( 'Batch Size' ) ); ?></label>
					<input type="number" id="fcias-cron-batchsize" value="100" min="1" max="10000"/>
				</div>
				<div class="fcias-cron-form-row">
					<label for="fcias-cron-interval"><?php
						p( $l->t( 'Interval' ) ); ?></label>
					<select id="fcias-cron-interval">
						<option value="300"><?php
							p( $l->t( '5 minutes' ) ); ?></option>
						<option value="900" selected><?php
							p( $l->t( '15 minutes' ) ); ?></option>
						<option value="1800"><?php
							p( $l->t( '30 minutes' ) ); ?></option>
						<option value="3600"><?php
							p( $l->t( '60 minutes' ) ); ?></option>
					</select>
				</div>
				<div class="fcias-cron-form-actions">
					<button class="fcias-btn" id="fcias-btn-save-definition"><?php
						p( $l->t( 'Save' ) ); ?></button>
					<button class="fcias-btn" id="fcias-btn-cancel-definition"><?php
						p( $l->t( 'Cancel' ) ); ?></button>
				</div>
			</div>
		</div>

		<h5><?php
			p( $l->t( 'System Crontab Snippet' ) ); ?></h5>
		<p class="fcias-hint"><?php
			p(
				$l->t(
					'Generate a crontab entry that you can copy into your system crontab to run hash generation via the CLI.',
				),
			); ?></p>

		<div class="fcias-cron-form" id="fcias-snippet-form" style="display:none;">
			<div class="fcias-cron-form-row">
				<label for="fcias-snippet-userscope"><?php
					p( $l->t( 'User Scope' ) ); ?></label>
				<select id="fcias-snippet-userscope">
					<option value="all"><?php
						p( $l->t( 'All Users' ) ); ?></option>
				</select>
			</div>
			<div class="fcias-cron-form-row">
				<label for="fcias-snippet-path"><?php
					p( $l->t( 'Path' ) ); ?></label>
				<input type="text" id="fcias-snippet-path" value="/" placeholder="/"/>
			</div>
			<div class="fcias-cron-form-row">
				<label for="fcias-snippet-algo"><?php
					p( $l->t( 'Algorithm' ) ); ?></label>
				<select id="fcias-snippet-algo"></select>
			</div>
			<div class="fcias-cron-form-row">
				<label for="fcias-snippet-batchsize"><?php
					p( $l->t( 'Batch Size' ) ); ?></label>
				<input type="number" id="fcias-snippet-batchsize" value="100" min="1" max="10000"/>
			</div>
			<div class="fcias-cron-form-row">
				<label for="fcias-snippet-interval"><?php
					p( $l->t( 'Interval' ) ); ?></label>
				<select id="fcias-snippet-interval">
					<option value="300"><?php
						p( $l->t( '5 minutes' ) ); ?></option>
					<option value="900" selected><?php
						p( $l->t( '15 minutes' ) ); ?></option>
					<option value="1800"><?php
						p( $l->t( '30 minutes' ) ); ?></option>
					<option value="3600"><?php
						p( $l->t( '60 minutes' ) ); ?></option>
				</select>
			</div>
		</div>

		<button class="fcias-btn" id="fcias-btn-generate-snippet"><?php
			p( $l->t( 'Generate Snippet' ) ); ?></button>

		<div id="fcias-cron-snippet-container" style="display:none;">
			<pre id="fcias-cron-snippet"></pre>
			<button class="fcias-btn" id="fcias-btn-copy-snippet"><?php
				p( $l->t( 'Copy to Clipboard' ) ); ?></button>
		</div>

		<div id="fcias-cron-msg"></div>
	</div>

	<div class="fcias-section">
		<h4><?php
			p( $l->t( 'Compatibility' ) ); ?></h4>
		<div id="fcias-compat-results">
			<button class="fcias-btn" id="fcias-btn-compat"><?php
				p( $l->t( 'Run Compatibility Test' ) ); ?></button>
		</div>
	</div>

	<div class="fcias-section">
		<h4><?php
			p( $l->t( 'Maintenance' ) ); ?></h4>

		<h5><?php
			p( $l->t( 'Index Data' ) ); ?></h5>

		<p>
			<button class="fcias-btn fcias-btn-danger" id="fcias-btn-purge">
				<?php
				p( $l->t( 'Purge Index' ) ); ?>
			</button>
			<span class="fcias-hint"><?php
				p( $l->t( 'Truncates the hash table. All index data will be lost.' ) ); ?></span>
		</p>

		<p>
			<button class="fcias-btn" id="fcias-btn-rebuild">
				<?php
				p( $l->t( 'Rebuild Index' ) ); ?>
			</button>
			<span class="fcias-hint"><?php
				p( $l->t( 'Repopulates the hash table from existing filecache checksums.' ) ); ?></span>
		</p>

		<h5><?php
			p( $l->t( 'Triggers & SP' ) ); ?></h5>

		<p>
			<button class="fcias-btn fcias-btn-danger" id="fcias-btn-teardown">
				<?php
				p( $l->t( 'Remove Triggers & SP' ) ); ?>
			</button>
			<span class="fcias-hint"><?php
				p( $l->t( 'Drops triggers and stored procedure. Hash table is preserved.' ) ); ?></span>
		</p>

		<p>
			<button class="fcias-btn" id="fcias-btn-deploy">
				<?php
				p( $l->t( 'Deploy Triggers & SP' ) ); ?>
			</button>
			<span class="fcias-hint"><?php
				p( $l->t( 'Creates triggers and stored procedure if they are missing.' ) ); ?></span>
		</p>

		<h5><?php
			p( $l->t( 'Hash Table' ) ); ?></h5>

		<p>
			<button class="fcias-btn fcias-btn-danger" id="fcias-btn-removetable">
				<?php
				p( $l->t( 'Remove Hash Table' ) ); ?>
			</button>
			<span class="fcias-hint"><?php
				p( $l->t( 'Drops the hash table entirely. Run teardown first.' ) ); ?></span>
		</p>

		<p>
			<button class="fcias-btn" id="fcias-btn-createtable">
				<?php
				p( $l->t( 'Create Hash Table' ) ); ?>
			</button>
			<span class="fcias-hint"><?php
				p( $l->t( 'Creates the hash table if it does not exist.' ) ); ?></span>
		</p>

		<div id="fcias-msg"></div>
	</div>

</div>
