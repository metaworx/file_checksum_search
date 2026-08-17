<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Settings;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Personal
	implements
	ISettings
{

	public function getForm(): TemplateResponse
	{

		return new TemplateResponse( Application::APP_ID, 'settings-personal', [], '' );
	}


	public function getSection(): string
	{

		return Application::APP_ID . '_personal';
	}


	public function getPriority(): int
	{

		return 50;
	}

}
