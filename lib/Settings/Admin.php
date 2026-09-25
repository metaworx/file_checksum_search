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
 * Registers the administration settings page.
 *
 * The page itself is a Vue app; this only hands Nextcloud the template that
 * mounts it, and says where in the settings navigation it belongs.
 */
class Admin
    implements
    ISettings
{

//  getters / setters / is* / has*

	public function getForm(): TemplateResponse
	{
		// Rendered bare: the settings framework supplies the page chrome, and
		// asking for a render-as would nest one inside another.
		return new TemplateResponse( Application::APP_ID, 'settings-admin', [], '' );
	}

	/**
	 * The section this page appears under, matching {@see AdminSection}.
	 */
	public function getSection(): string
	{
		return Application::APP_ID;
	}

	/**
	 * Mid-list, where an app with no claim to be first belongs.
	 */
	public function getPriority(): int
	{
		return 50;
	}
}
