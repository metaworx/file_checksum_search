<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PublicApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `/api/v1/file/many/recalc` puts a literal where `{fileId}` sits on the
 * route beside it. Nothing about a route parameter refuses a word — without
 * a requirement, `{fileId}` swallows `many` and the batch route is never
 * reached, or is reached as a file called "many" cast to 0. So every route
 * carrying `{fileId}` says it takes digits, and this is what keeps that
 * true when the next route is added.
 */
class RouteRequirementsTest
	extends
	TestCase
{

	/**
	 * @return array<string, array{string, ?array}>  method => [url, requirements]
	 */
	private static function apiRoutes(): array
	{

		$routes = [];

		foreach ( ( new ReflectionClass( PublicApiController::class ) )->getMethods() as $method )
		{
			foreach ( $method->getAttributes( ApiRoute::class ) as $attribute )
			{
				/** @var ApiRoute $route */
				$route = $attribute->newInstance();

				$routes[ $method->getName() ] = [ $route->getUrl(), $route->getRequirements() ];
			}
		}

		return $routes;
	}


	public function testEveryFileIdRouteTakesDigitsOnly(): void
	{

		$seen = 0;

		foreach ( self::apiRoutes() as $method => [ $url, $requirements ] )
		{
			if ( ! str_contains( $url, '{fileId}' ) )
			{
				continue;
			}

			$seen++;

			self::assertSame(
				'\d+',
				$requirements['fileId'] ?? null,
				"$method() at $url must require a numeric {fileId}, or the literal 'many' beside it is parsed as one",
			);
		}

		self::assertGreaterThanOrEqual( 6, $seen, 'the six per-file routes, at least' );
	}


	public function testTheBatchRoutesSitBesideTheirSingleFileTwins(): void
	{

		$urls = array_column( self::apiRoutes(), 0 );

		self::assertContains( '/api/v1/file/many/recalc', $urls );
		self::assertContains( '/api/v1/sudo/file/many/recalc', $urls );
		self::assertContains( '/api/v1/file/{fileId}/recalc', $urls );
		self::assertContains( '/api/v1/sudo/file/{fileId}/recalc', $urls );
	}


	/**
	 * @return array<string, array{int, int}|null>  url => [limit, period], or null for none
	 */
	private static function rateLimits(): array
	{

		$limits = [];

		foreach ( ( new ReflectionClass( PublicApiController::class ) )->getMethods() as $method )
		{
			foreach ( $method->getAttributes( ApiRoute::class ) as $attribute )
			{
				/** @var ApiRoute $route */
				$route = $attribute->newInstance();
				$limit = null;

				foreach ( $method->getAttributes( UserRateLimit::class ) as $rate )
				{
					/** @var UserRateLimit $rate */
					$rate  = $rate->newInstance();
					$limit = [ $rate->getLimit(), $rate->getPeriod() ];
				}

				$limits[ $route->getUrl() ] = $limit;
			}
		}

		return $limits;
	}


	/**
	 * A cross-account twin does the work its ordinary route does, and a
	 * password confirmation is not a throttle. So whatever limit the one
	 * carries, the other carries too — in both directions, so that a limit
	 * added to one side is not forgotten on the other.
	 */
	public function testEveryCrossAccountTwinCarriesItsOrdinaryRoutesRateLimit(): void
	{

		$limits = self::rateLimits();
		$pairs  = 0;

		foreach ( $limits as $url => $limit )
		{
			if ( ! str_starts_with( $url, '/api/v1/sudo/' ) )
			{
				continue;
			}

			$twin = '/api/v1/' . substr( $url, strlen( '/api/v1/sudo/' ) );

			if ( ! array_key_exists( $twin, $limits ) )
			{
				// /sudo/selectable has no ordinary twin; nothing to keep in step.
				continue;
			}

			$pairs++;

			self::assertSame(
				$limits[ $twin ],
				$limit,
				"$url must carry the rate limit of $twin, or none as it has none",
			);
		}

		self::assertGreaterThanOrEqual( 6, $pairs, 'the six twins, at least' );
	}


	/**
	 * Every cross-account route is rate limited. The hashes pair was the one
	 * twin pair without a limit, and the pairing test above was content with
	 * that — "none as it has none" — while the sudo twin cost a group leader
	 * one mount resolution per member, twice, per request.
	 */
	public function testEveryCrossAccountRouteIsRateLimited(): void
	{

		$sudo = 0;

		foreach ( self::rateLimits() as $url => $limit )
		{
			if ( ! str_starts_with( $url, '/api/v1/sudo/' ) )
			{
				continue;
			}

			$sudo++;

			self::assertNotNull( $limit, "$url carries no rate limit" );
		}

		self::assertGreaterThanOrEqual( 7, $sudo, 'the six twins and selectable, at least' );
	}

}
