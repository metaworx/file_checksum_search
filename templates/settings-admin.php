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

	<div class="fcias-section fcias-section-muted">
		<h4><?php
			p( $l->t( 'Coming Soon' ) ); ?></h4>
		<p class="fcias-muted"><?php
			p( $l->t( 'Permissions management' ) ); ?></p>
		<p class="fcias-muted"><?php
			p( $l->t( 'Cron-based hash generation' ) ); ?></p>
	</div>

	<div id="fcias-msg"></div>
</div>
