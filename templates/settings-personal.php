<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Util;

Util::addScript( Application::APP_ID, Application::APP_ID . '-settings-personal' );
Util::addStyle( Application::APP_ID, Application::APP_ID . '-settings-personal' );

/** @var \OCP\IL10N $l is auto-injected by NC's TemplateResponse renderer via \OCP\Util::getL10N('file_checksum_search'). */
?>
<div id="fcias-personal-settings">
	<h3><?php
		p( $l->t( 'File Checksum Index & Search' ) ); ?></h3>

	<div class="fcias-tabs" role="tablist">
		<button type="button" class="fcias-tab is-active" id="fcias-tab-btn-rules" data-tab="rules" role="tab"
		        aria-selected="true" aria-controls="fcias-tab-panel-rules"><?php
			p( $l->t( 'Rules' ) ); ?></button>
		<button type="button" class="fcias-tab" id="fcias-tab-btn-faq" data-tab="faq" role="tab" aria-selected="false"
		        aria-controls="fcias-tab-panel-faq"><?php
			p( $l->t( 'FAQ' ) ); ?></button>
	</div>

	<div id="fcias-tab-panel-rules" class="fcias-tab-panel" role="tabpanel" aria-labelledby="fcias-tab-btn-rules">
	<h4><?php
		p( $l->t( 'Rules applying to your files' ) ); ?></h4>

	<p class="fcias-hint"><?php
		p(
			$l->t(
				'These rules apply to your files. Admin-enforced rules are read-only. You can edit rules only if you are in an enabled group and the rule path is in a folder you can write to.',
			),
		); ?></p>

	<div id="fcias-personal-msg"></div>

	<div id="fcias-personal-rules">
		<p><?php
			p( $l->t( 'Loading …' ) ); ?></p>
	</div>

	<button class="fcias-btn" id="fcias-personal-add" style="display:none;"><?php
		p( $l->t( 'Add Rule' ) ); ?></button>

	<!-- Add/Edit rule form (hidden by default) -->
	<div id="fcias-personal-form" style="display:none;">
		<div class="fcias-cron-form">
			<div class="fcias-cron-form-row">
				<label for="fcias-personal-path"><?php
					p( $l->t( 'Path (glob)' ) ); ?></label>
				<input type="text" id="fcias-personal-path" value="/" placeholder="/"/>
			</div>
			<div class="fcias-cron-form-row">
				<label><?php
					p( $l->t( 'Algorithms' ) ); ?></label>
				<div id="fcias-personal-algos" class="fcias-algo-select"></div>
			</div>
			<div class="fcias-cron-form-row">
				<label for="fcias-personal-mode"><?php
					p( $l->t( 'Mode' ) ); ?></label>
				<select id="fcias-personal-mode">
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
				<button class="fcias-btn" id="fcias-personal-save"><?php
					p( $l->t( 'Save' ) ); ?></button>
				<button class="fcias-btn" id="fcias-personal-cancel"><?php
					p( $l->t( 'Cancel' ) ); ?></button>
			</div>
		</div>
	</div>
	</div>

	<div id="fcias-tab-panel-faq" class="fcias-tab-panel" role="tabpanel" aria-labelledby="fcias-tab-btn-faq" hidden>
		<div id="fcias-personal-faq-viewer"></div>
	</div>
</div>
