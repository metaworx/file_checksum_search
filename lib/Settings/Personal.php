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

/**
 * Registers the personal settings page.
 *
 * The page itself is a Vue app; this only hands Nextcloud the template that
 * mounts it. Its section is a separate one from the administration page's —
 * same app, different navigation — so {@see PersonalSection} has to agree
 * with the id returned here.
 */
class Personal
    implements
    ISettings
{

//  getters / setters / is* / has*

	public function getForm(): TemplateResponse
	{
		return new TemplateResponse( Application::APP_ID, 'settings-personal', [], '' );
	}

	/**
	 * The section this page appears under, matching {@see PersonalSection}.
	 */
	public function getSection(): string
	{
		return Application::APP_ID . '_personal';
	}

	/**
	 * Mid-list, where an app with no claim to be first belongs.
	 */
	public function getPriority(): int
	{
		return 50;
	}
}
