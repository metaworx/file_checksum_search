<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State\Format;

use InvalidArgumentException;
use OCA\FileChecksumSearch\State\Format\CsvFormat;
use OCA\FileChecksumSearch\State\Format\FormatRegistry;
use OCA\FileChecksumSearch\State\Format\JsonFormat;
use OCA\FileChecksumSearch\State\Format\SumFormat;
use PHPUnit\Framework\TestCase;

/**
 * One list, so `--format` means the same thing on both commands.
 */
class FormatRegistryTest
	extends
	TestCase
{

	private FormatRegistry $registry;


	protected function setUp(): void
	{

		parent::setUp();
		$this->registry = new FormatRegistry();
	}


	public function testEachNameResolvesToItsFormat(): void
	{

		$this->assertInstanceOf( JsonFormat::class, $this->registry->get( 'json' ) );
		$this->assertInstanceOf( CsvFormat::class, $this->registry->get( 'CSV' ) );
		$this->assertInstanceOf( SumFormat::class, $this->registry->get( ' sum ' ) );
	}


	public function testEveryAdvertisedNameResolves(): void
	{

		foreach ( FormatRegistry::names() as $name )
		{
			$this->registry->get( $name );
		}

		$this->assertSame(
			[
				'json',
				'csv',
				'sum',
			],
			FormatRegistry::names(),
		);
	}


	public function testAnUnknownNameSaysWhatIsAvailable(): void
	{

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/json, csv, sum/' );

		$this->registry->get( 'yaml' );
	}


	/**
	 * @dataProvider filenames
	 */
	public function testAFilenameCanSuggestItsFormat(
		string  $path,
		?string $expected,
	): void {

		$this->assertSame( $expected, $this->registry->guessFromPath( $path ) );
	}


	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function filenames(): array
	{

		return [
			'json'           => [
				'/backups/fcias.json',
				'json',
			],
			'csv'            => [
				'export.CSV',
				'csv',
			],
			'sum'            => [
				'hashes.sum',
				'sum',
			],
			'algorithm name' => [
				'SHA256SUMS.sha256',
				'sum',
			],
			'plain text'     => [
				'hashes.txt',
				'sum',
			],
			'no extension'   => [
				'/dev/stdout',
				null,
			],
			'unknown'        => [
				'backup.tar.gz',
				null,
			],
		];
	}

}
