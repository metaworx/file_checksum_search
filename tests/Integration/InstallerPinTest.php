<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration;

use OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update\Installer;
use OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update\ServerInstaller;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Pins behaviour internal to the server, which the app depends on and
 * psalm.xml's inventory lists: `occ fcias:update` drives the server's
 * installer, `OC\Installer`, through the scoped package, which resolves it
 * by name and calls `isUpdateAvailable()` and `updateAppstoreApp()` with an
 * app id and the unstable flag. Re-run for every new Nextcloud release.
 */
class InstallerPinTest
    extends
    TestCase
{

//  other non-static methods

	public function testTheServerResolvesItsInstaller(): void
	{
		self::assertSame( \OC\Installer::class, ServerInstaller::SERVER_CLASS );
		self::assertInstanceOf( \OC\Installer::class, Server::get( ServerInstaller::SERVER_CLASS ) );
	}

	/**
	 * Each method takes the app id and the flag, as the package passes them,
	 * and returns what the package's `Installer` declares. A parameter the
	 * server leaves untyped takes either.
	 */
	#[DataProvider( 'methods' )]
	public function testTheMethodKeepsItsSignature( string $method ): void
	{
		$server     = new ReflectionMethod( \OC\Installer::class, $method );
		$parameters = $server->getParameters();

		self::assertTrue( $server->isPublic(), $method );
		self::assertGreaterThanOrEqual( 2, count( $parameters ), $method );
		self::assertLessThanOrEqual( 2, $server->getNumberOfRequiredParameters(), $method );
		self::assertTrue( self::accepts( $parameters[0]->getType(), 'string' ), $method . ': the app id' );
		self::assertTrue( self::accepts( $parameters[1]->getType(), 'bool' ), $method . ': the unstable flag' );
		self::assertSame(
			self::names( ( new ReflectionMethod( Installer::class, $method ) )->getReturnType() ),
			self::names( $server->getReturnType() ),
			$method . ': the return type',
		);
	}


//  static methods

	/**
	 * @return array<string, array{string}>
	 */
	public static function methods(): array
	{
		return [
			'isUpdateAvailable' => [ 'isUpdateAvailable' ],
			'updateAppstoreApp' => [ 'updateAppstoreApp' ],
		];
	}

	/**
	 * Whether a parameter of this type takes a value of the builtin type.
	 */
	private static function accepts(
		?ReflectionType $type,
		string          $builtin,
	): bool
	{
		$names = self::names( $type );

		return $names === [] || in_array( 'mixed', $names, true ) || in_array( $builtin, $names, true );
	}

	/**
	 * A type's names, sorted; none for no type.
	 *
	 * @return list<string>
	 */
	private static function names( ?ReflectionType $type ): array
	{
		$names = match ( true )
		{
			$type instanceof ReflectionNamedType => [ $type->getName() ],
			$type instanceof ReflectionUnionType => array_map(
				static fn(
					ReflectionType $member,
				): string => $member instanceof ReflectionNamedType ? $member->getName() : (string) $member,
				$type->getTypes(),
			),
			$type === null                       => [],
			default                              => [ (string) $type ],
		};

		if ( $type?->allowsNull() === true && ! in_array( 'null', $names, true ) && ! in_array( 'mixed', $names, true ) )
		{
			$names[] = 'null';
		}

		sort( $names );

		return $names;
	}
}
