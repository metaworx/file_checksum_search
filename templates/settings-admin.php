<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Util;

Util::addScript( Application::APP_ID, Application::APP_ID . '-settings-admin' );
Util::addScript( Application::APP_ID, Application::APP_ID . '-settings-admin-docs' );
Util::addStyle( Application::APP_ID, Application::APP_ID . '-settings-admin' );

/** @var \OCP\IL10N $l is auto-injected by NC's TemplateResponse renderer via \OCP\Util::getL10N('file_checksum_search'). */
?>

<div id="fcias-admin-settings">
	<h3>
		<?php
		echo str_replace( 'fill="#fff"', 'fill="currentColor"', file_get_contents( __DIR__ . '/../img/app.svg' ) ); ?>
		<?php
		p( $l->t( 'File Checksum Index & Search' ) ); ?>
	</h3>

	<div class="fcias-tabs" role="tablist">
		<button type="button" class="fcias-tab is-active" id="fcias-tab-btn-settings" data-tab="settings" role="tab"
		        aria-selected="true" aria-controls="fcias-tab-panel-settings"><?php
			p( $l->t( 'Settings' ) ); ?></button>
		<button type="button" class="fcias-tab" id="fcias-tab-btn-docs" data-tab="docs" role="tab" aria-selected="false"
		        aria-controls="fcias-tab-panel-docs"><?php
			p( $l->t( 'Documentation' ) ); ?></button>
	</div>

	<div id="fcias-tab-panel-settings" class="fcias-tab-panel" role="tabpanel" aria-labelledby="fcias-tab-btn-settings">
		<div class="fcias-section">
			<h4><?php
				p( $l->t( 'Status' ) ); ?>
				<button class="fcias-btn" id="fcias-btn-refresh-status" style="margin-left:12px"><?php
					p( $l->t( 'Refresh' ) ); ?></button>
			</h4>
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
						p( $l->t( 'Pending Updates' ) ); ?></td>
					<td id="fcias-status-pending">—</td>
				</tr>
				<tr>
					<td><?php
						p( $l->t( 'Last Updated' ) ); ?></td>
					<td id="fcias-status-lastupdated">—</td>
				</tr>
				</tbody>
			</table>
		</div>

		<div class="fcias-section">
			<h4><?php
				p( $l->t( 'Rule Definitions' ) ); ?></h4>

			<p class="fcias-hint"><?php
				p(
					$l->t(
						'The global default for real-time file events.',
					),
				); ?></p>

			<!-- Global rule (always visible) -->
			<div id="fcias-global-rule"></div>

			<!-- Additional rules -->
			<h5><?php
				p( $l->t( 'Additional Rules' ) ); ?></h5>
			<p class="fcias-hint"><?php
				p(
					$l->t(
						'Rules are processed in order — each file is handled by the first matching rule.',
					),
				); ?></p>

			<div id="fcias-cron-list">
				<p><?php
					p( $l->t( 'No additional rules.' ) ); ?></p>
			</div>

			<button class="fcias-btn" id="fcias-btn-add-definition"><?php
				p( $l->t( 'Add Rule' ) ); ?></button>

			<!-- Add/Edit rule form (hidden by default) -->
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
							p( $l->t( 'Path (glob)' ) ); ?></label>
						<input type="text" id="fcias-cron-path" value="/" placeholder="/"/>
					</div>
					<div class="fcias-cron-form-row">
						<label><?php
							p( $l->t( 'Algorithms' ) ); ?></label>
						<div id="fcias-cron-algos" class="fcias-checkbox-group"></div>
					</div>
					<div class="fcias-cron-form-row">
						<label for="fcias-cron-mode"><?php
							p( $l->t( 'Mode' ) ); ?></label>
						<select id="fcias-cron-mode">
							<option value="auto"><?php
								p( $l->t( 'Auto (recalc existing only if stale)' ) ); ?></option>
							<option value="missing"><?php
								p( $l->t( 'Missing (recalc existing + missing)' ) ); ?></option>
							<option value="force"><?php
								p( $l->t( 'Force (delete all, recalc all)' ) ); ?></option>
							<option value="lazy"><?php
								p( $l->t( 'Lazy (delete hashes, recalc later)' ) ); ?></option>
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

			<div id="fcias-cron-msg"></div>
		</div>
	</div>

	<div id="fcias-tab-panel-docs" class="fcias-tab-panel" role="tabpanel" aria-labelledby="fcias-tab-btn-docs" hidden>
		<div id="fcias-docs-viewer"></div>
	</div>

</div>
