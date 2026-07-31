<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin
	implements
	ISettings
{

	public function getForm(): TemplateResponse
	{

		return new TemplateResponse( 'file_checksum_search', 'settings-admin', [], '' );
	}


	public function getSection(): string
	{

		return 'additional';
	}


	public function getPriority(): int
	{

		return 50;
	}

}
