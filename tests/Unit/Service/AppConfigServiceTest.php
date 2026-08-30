<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCA\FileChecksumSearch\Service\AppConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The app's own slice of appconfig — which keys it owns, and what it does
 * with them.
 */
class AppConfigServiceTest
	extends
	TestCase
{

	private IAppConfig&MockObject $appConfig;

	private AppConfigService      $service;


	protected function setUp(): void
	{

		parent::setUp();

		$this->appConfig = $this->createMock( IAppConfig::class );
		$this->service   = new AppConfigService(
			$this->appConfig,
			new ConfigLexicon(),
			$this->createMock( LoggerInterface::class ),
		);
	}


	/**
	 * The lexicon is the list, not a copy of it: a key declared there is
	 * backed up and reset without anyone remembering a second place.
	 */
	public function testTheOwnedKeysAreTheOnesTheLexiconDeclares(): void
	{

		$this->assertSame(
			array_map(
				static fn(
					$entry,
				): string => $entry->getKey(),
				( new ConfigLexicon() )->getAppConfigs(),
			),
			$this->service->ownedKeys(),
		);
		$this->assertContains( 'rule_definitions', $this->service->ownedKeys() );
	}


	public function testExportSkipsKeysThatWereNeverSet(): void
	{

		$set = [
			'rule_definitions'         => '[{"id":1}]',
			'rule_processing_interval' => '300',
		];

		$this->appConfig->method( 'hasKey' )
		                ->willReturnCallback(
			                static fn(
				                string $app,
				                string $key,
			                ): bool => isset( $set[ $key ] ),
		                )
		;
		$this->appConfig->method( 'getValueString' )
		                ->willReturnCallback(
			                static fn(
				                string $app,
				                string $key,
			                ): string => $set[ $key ],
		                )
		;

		// An absent key *is* its default, so exporting one would record
		// today's default as though the administrator had chosen it.
		$this->assertSame( $set, $this->service->export() );
	}


	public function testImportWritesDeclaredKeys(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with( Application::APP_ID, 'rule_definitions', '[]' )
		;

		$report = $this->service->import( [ 'rule_definitions' => '[]' ] );

		$this->assertSame( 1, $report['written'] );
		$this->assertSame( [], $report['skipped'] );
	}


	/**
	 * A backup from a newer version may name keys this one has never heard
	 * of. Half-applying it silently is the failure mode worth avoiding: the
	 * run reports them rather than writing them.
	 */
	public function testImportRefusesUndeclaredKeys(): void
	{

		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with( Application::APP_ID, 'rule_definitions', '[]' )
		;

		$report = $this->service->import(
			[
				'rule_definitions'   => '[]',
				'from_a_new_version' => 'x',
				'someone_elses_key'  => 'y',
			],
		);

		$this->assertSame( 1, $report['written'] );
		$this->assertSame(
			[
				'from_a_new_version',
				'someone_elses_key',
			],
			$report['skipped'],
		);
	}


	/**
	 * `--replace` means the result is exactly the input, so owned keys the
	 * import does not mention are removed rather than left standing.
	 */
	public function testReplaceDeletesOwnedKeysTheImportDoesNotMention(): void
	{

		$this->appConfig->method( 'hasKey' )
		                ->willReturn( true )
		;

		$deleted = [];
		$this->appConfig->method( 'deleteKey' )
		                ->willReturnCallback(
			                static function (
				                string $app,
				                string $key,
			                ) use
			                (
				                &
				                $deleted,
			                ): void
			                {

				                $deleted[] = $key;
			                },
		                )
		;

		$this->service->import( [ 'rule_definitions' => '[]' ], replace: true );

		$this->assertNotContains( 'rule_definitions', $deleted );
		$this->assertContains( 'rule_processing_interval', $deleted );
		$this->assertCount( count( $this->service->ownedKeys() ) - 1, $deleted );
	}


	public function testAMergingImportLeavesUnmentionedKeysAlone(): void
	{

		$this->appConfig->expects( $this->never() )
		                ->method( 'deleteKey' )
		;

		$this->service->import( [ 'rule_definitions' => '[]' ] );
	}


	/**
	 * Deletion, not writing defaults: the lexicon is where a default lives,
	 * and writing today's values out would freeze them into the instance.
	 */
	public function testClearDeletesEveryKeyThatIsSet(): void
	{

		$this->appConfig->method( 'hasKey' )
		                ->willReturn( true )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueString' )
		;
		$this->appConfig->expects( $this->exactly( count( $this->service->ownedKeys() ) ) )
		                ->method( 'deleteKey' )
		;

		$this->assertSame( count( $this->service->ownedKeys() ), $this->service->clear() );
	}


	public function testClearSkipsKeysThatAreNotSet(): void
	{

		$this->appConfig->method( 'hasKey' )
		                ->willReturn( false )
		;
		$this->appConfig->expects( $this->never() )
		                ->method( 'deleteKey' )
		;

		$this->assertSame( 0, $this->service->clear() );
	}


	/**
	 * One key that refuses to go must not abandon the rest of the reset
	 * half-done — a partial clear the administrator was not told about is
	 * the worst outcome here.
	 */
	public function testOneFailedDeletionDoesNotAbortTheRest(): void
	{

		$this->appConfig->method( 'hasKey' )
		                ->willReturn( true )
		;
		$this->appConfig->method( 'deleteKey' )
		                ->willReturnCallback(
			                static function (
				                string $app,
				                string $key,
			                ): void {

				                if ( $key === 'rule_definitions' )
				                {
					                throw new RuntimeException( 'locked' );
				                }
			                },
		                )
		;

		$this->assertSame( count( $this->service->ownedKeys() ) - 1, $this->service->clear() );
	}

}
