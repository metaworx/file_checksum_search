<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PublicApiController;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the rate limits declared on the public API surface.
 *
 * Nextcloud enforces {@see UserRateLimit} in its own middleware against a
 * distributed cache, so the actual 429 can only be observed against a real
 * instance.  What can be pinned down here — and what silently regresses if
 * someone edits a controller — is that the attribute is present at all, on
 * the endpoints that need it, with the intended limit and period.
 */
class RateLimitAttributeTest
	extends
	TestCase
{

	/**
	 * Endpoints that must carry a per-user rate limit.
	 *
	 * The expensive read endpoints get 60/minute; recalculation gets
	 * 20/minute because it triggers real file I/O.
	 *
	 * @return array<string, array{class-string, string, int, int}>
	 */
	public static function rateLimitedEndpointProvider(): array
	{

		return [
			'v1 lookup'         => [ PublicApiController::class, 'lookup', 60, 60 ],
			'v1 duplicates'     => [ PublicApiController::class, 'findAllDuplicates', 60, 60 ],
			'v1 recalc'         => [ PublicApiController::class, 'recalcHash', 20, 60 ],
			// The picker's source: cheap per call, but it searches accounts
			// and groups, so it is metered like the rest.
			'v1 sudo selectable' => [ PublicApiController::class, 'sudoSelectable', 60, 60 ],
		];
	}


	/**
	 * @dataProvider rateLimitedEndpointProvider
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testEndpointDeclaresExpectedRateLimit(
		string $class,
		string $method,
		int    $limit,
		int    $period,
	): void {

		$attributes = ( new ReflectionMethod( $class, $method ) )
			->getAttributes( UserRateLimit::class )
		;

		self::assertCount(
			1,
			$attributes,
			sprintf( '%s::%s() must declare exactly one UserRateLimit attribute', $class, $method ),
		);

		/** @var UserRateLimit $rateLimit */
		$rateLimit = $attributes[0]->newInstance();

		self::assertSame( $limit, $rateLimit->getLimit() );
		self::assertSame( $period, $rateLimit->getPeriod() );
	}


	/**
	 * Recalculation must not be looser than the read endpoints — it
	 * does real file I/O, so a regression that raised its limit to
	 * match `lookup` would defeat the point.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	public function testRecalcIsStricterThanReads(): void
	{

		$limitOf = static function ( string $class, string $method ): int {

			$attributes = ( new ReflectionMethod( $class, $method ) )
				->getAttributes( UserRateLimit::class )
			;

			return $attributes[0]->newInstance()
			                     ->getLimit()
			;
		};

		self::assertLessThan(
			$limitOf( PublicApiController::class, 'lookup' ),
			$limitOf( PublicApiController::class, 'recalcHash' ),
		);
	}
}
