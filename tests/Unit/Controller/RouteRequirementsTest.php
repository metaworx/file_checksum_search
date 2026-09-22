<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Controller;

use OCA\FileChecksumSearch\Controller\PublicApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
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

}
