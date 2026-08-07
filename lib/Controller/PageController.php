<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Page controller for the global duplicate file browser.
 */
class PageController
	extends
	Controller
{

	public function __construct(
		string   $appName,
		IRequest $request,
	) {

		parent::__construct( $appName, $request );
	}


	/**
	 * Render the global duplicate browser page.
	 *
	 * @noinspection PhpUnused
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/duplicates')]
	public function index(): TemplateResponse
	{

		return new TemplateResponse(
			'file_checksum_search',
			'duplicates',
			[],
			TemplateResponse::RENDER_AS_USER,
		);
	}

}
