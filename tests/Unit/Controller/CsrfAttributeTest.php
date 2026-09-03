<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PublicApiController;
use OCA\FileChecksumSearch\Controller\RulesController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards which routes waive the CSRF check.
 *
 * `#[NoCSRFRequired]` drops both the token check and the strict-cookie
 * check in core's SecurityMiddleware, so a mutating route must not carry
 * it: a browser sends the request token, an API caller the
 * `OCS-APIRequest` header, and either satisfies the check. The read routes
 * keep it — a GET is not the concern, and an app-password caller has no
 * token. Middleware can only be observed against a real instance; the
 * attribute's presence or absence is what regresses silently, and is what
 * this pins.
 */
class CsrfAttributeTest
	extends
	TestCase
{

	/**
	 * The mutating POSTs, which must NOT waive CSRF.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function mutatingProvider(): array
	{

		return [
			'recalc' => [ PublicApiController::class, 'recalcHash' ],
			'apply'  => [ RulesController::class, 'apply' ],
		];
	}


	/**
	 * @dataProvider mutatingProvider
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAMutatingRouteDoesNotWaiveCsrf(
		string $class,
		string $method,
	): void {

		$attributes = ( new ReflectionMethod( $class, $method ) )
			->getAttributes( NoCSRFRequired::class )
		;

		self::assertCount(
			0,
			$attributes,
			sprintf( '%s::%s() is a mutating POST and must not carry #[NoCSRFRequired].', $class, $method ),
		);
	}


	/**
	 * A read route keeps the waiver: it is called with an app password that
	 * carries no CSRF token, and a GET is not the CSRF concern.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testAReadRouteKeepsTheWaiver(): void
	{

		$attributes = ( new ReflectionMethod( PublicApiController::class, 'lookup' ) )
			->getAttributes( NoCSRFRequired::class )
		;

		self::assertCount( 1, $attributes, 'lookup is a GET called with an app password and keeps #[NoCSRFRequired].' );
	}

}
