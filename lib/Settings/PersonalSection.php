<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Settings;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Dedicated personal settings section for the File Checksum Index & Search
 * app on the /settings/user page.
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class PersonalSection
	implements
	IIconSection
{

	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N         $l10n,
	) {
	}


	public function getID(): string
	{

		return Application::APP_ID . '_personal';
	}


	public function getName(): string
	{

		return $this->l10n->t( 'File Checksum Index & Search' );
	}


	public function getPriority(): int
	{

		return 50;
	}


	public function getIcon(): string
	{

		return $this->urlGenerator->imagePath( Application::APP_ID, 'app.svg' );
	}

}
