<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Command;

use OCA\FileChecksumSearch\Command\SearchHash;
use OCA\FileChecksumSearch\Service\HashIndexService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SearchHashTest
    extends
    TestCase
{

//  constants

	private const HASH = 'aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d';


//  private properties

	private MockObject|HashIndexService $hashIndexService;

	private CommandTester               $tester;


//  getters / setters / is* / has*

	protected function setUp(): void
	{
		parent::setUp();

		$this->hashIndexService = $this->createMock( HashIndexService::class );
		$this->tester           = new CommandTester(
			new SearchHash( $this->hashIndexService, $this->createMock( LoggerInterface::class ) ),
		);
	}


//  other non-static methods

	public function testAFileIsPrintedByItsPath(): void
	{
		$this->found(
			[
				'fileid' => 7,
				'algo'   => 'sha1',
				'path'   => '/Docs/a.txt',
			],
		);

		$this->assertSame( Command::SUCCESS, $this->tester->execute( [ 'query' => self::HASH ] ) );
		$this->assertSame( "[sha1] (ID: 7) -> Docs/a.txt\n", $this->tester->getDisplay() );
	}

	/**
	 * A version's path is its file's: the date says which version it is.
	 */
	public function testAVersionIsPrintedWithItsDate(): void
	{
		$this->found(
			[
				'fileid'  => 8,
				'algo'    => 'sha1',
				'path'    => 'versions:///Docs/a.txt',
				'version' => [
					'fileid'    => 7,
					'timestamp' => '2023-11-14T22:13:20+00:00',
				],
			],
		);

		$this->tester->execute( [ 'query' => self::HASH ] );

		$this->assertSame(
			"[sha1] (ID: 8) -> versions:///Docs/a.txt (version of 2023-11-14T22:13:20+00:00)\n",
			$this->tester->getDisplay(),
		);
	}

	public function testAVersionsDateComesBeforeItsLocalPath(): void
	{
		$this->found(
			[
				'fileid'     => 8,
				'algo'       => 'sha1',
				'path'       => 'versions:///Docs/a.txt',
				'version'    => [
					'fileid'    => 7,
					'timestamp' => '2023-11-14T22:13:20+00:00',
				],
				'local_path' => '/data/alice/files_versions/Docs/a.txt.v1700000000',
			],
		);

		$this->tester->execute(
			[
				'query'        => self::HASH,
				'--local-path' => true,
			],
		);

		$this->assertStringContainsString(
			'(version of 2023-11-14T22:13:20+00:00) => /data/alice/files_versions/Docs/a.txt.v1700000000',
			$this->tester->getDisplay(),
		);
	}

	/**
	 * @param  array<string, mixed>  $row
	 */
	private function found( array $row ): void
	{
		$this->hashIndexService->method( 'findByHash' )
		                       ->willReturn( [ $row ] )
		;
	}
}
