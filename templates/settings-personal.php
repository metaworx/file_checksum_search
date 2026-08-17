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
				<div id="fcias-personal-algos" class="fcias-checkbox-group"></div>
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
