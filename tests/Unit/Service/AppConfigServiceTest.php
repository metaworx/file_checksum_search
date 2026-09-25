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

//  private properties

	private IAppConfig&MockObject $appConfig;

	private AppConfigService      $service;


//  getters / setters / is* / has*

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


//  other non-static methods

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

		$this->givenSet( $set );

		// An absent key *is* its default, so exporting one would record
		// today's default as though the administrator had chosen it.
		$this->assertSame( $set, $this->service->export() );
	}

	/**
	 * Nextcloud refuses a read that disagrees with the lexicon — asking for
	 * an `INT` key as a string is an error, not a coercion — so every key is
	 * read through the getter its declared type calls for. Reading them all
	 * as strings failed on the first interval key it met.
	 */
	public function testEveryKeyIsReadThroughItsDeclaredType(): void
	{
		$this->givenSet(
			[
				'rule_definitions'         => '[]',
				'rule_processing_interval' => '300',
				'idle_banner_ack'          => '1',
			],
		);

		$exported = $this->service->export();

		$this->assertSame( '300', $exported['rule_processing_interval'] );
		$this->assertSame( '1', $exported['idle_banner_ack'] );
	}

	/**
	 * And back the same way: an integer written as a string would be refused
	 * on the way in for the same reason.
	 */
	public function testEveryKeyIsWrittenThroughItsDeclaredType(): void
	{
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueInt' )
		                ->with( Application::APP_ID, 'rule_processing_interval', 300 )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueBool' )
		                ->with( Application::APP_ID, 'idle_banner_ack', true )
		;
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueString' )
		                ->with( Application::APP_ID, 'rule_definitions', '[]' )
		;

		$report = $this->service->import(
			[
				'rule_definitions'         => '[]',
				'rule_processing_interval' => '300',
				'idle_banner_ack'          => '1',
			],
		);

		$this->assertSame( 3, $report['written'] );
	}

	/**
	 * @dataProvider truthyStrings
	 */
	public function testABooleanIsRecognisedHoweverTheBackupSpeltIt(
		string $written,
		bool   $expected,
	): void
	{
		$this->appConfig->expects( $this->once() )
		                ->method( 'setValueBool' )
		                ->with( Application::APP_ID, 'idle_banner_ack', $expected )
		;

		$this->service->import( [ 'idle_banner_ack' => $written ] );
	}


//  static methods

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function truthyStrings(): array
	{
		return [
			'one'   => [
				'1',
				true,
			],
			'true'  => [
				'true',
				true,
			],
			'TRUE'  => [
				'TRUE',
				true,
			],
			'zero'  => [
				'0',
				false,
			],
			'false' => [
				'false',
				false,
			],
			'empty' => [
				'',
				false,
			],
		];
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
			                static function(
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

		// Only the keys that could have been in the file. History was never
		// exported, so its absence from one says nothing about it.
		$this->assertNotContains( 'stats_rule_sweep_last_run', $deleted );
		$this->assertCount( count( $this->service->portableKeys() ) - 1, $deleted );
	}

	public function testAMergingImportLeavesUnmentionedKeysAlone(): void
	{
		$this->appConfig->expects( $this->never() )
		                ->method( 'deleteKey' )
		;

		$this->service->import( [ 'rule_definitions' => '[]' ] );
	}

	/**
	 * Configuration travels; history does not.
	 *
	 * A key saying how this instance is set up means something elsewhere. A
	 * key recording when a job last ran means nothing anywhere else, and the
	 * repair markers are worse than meaningless: an instance told a one-time
	 * step has already run never runs it.
	 */
	public function testHistoryIsOwnedButNotPortable(): void
	{
		$owned    = $this->service->ownedKeys();
		$portable = $this->service->portableKeys();

		$this->assertContains( 'stats_rule_sweep_last_run', $owned );
		$this->assertNotContains( 'stats_rule_sweep_last_run', $portable );
		$this->assertContains( 'rule_definitions', $portable );

		$this->assertFalse( AppConfigService::isPortable( 'repair_done_selector_model' ) );
		$this->assertTrue( AppConfigService::isPortable( 'rule_definitions' ) );
	}

	public function testAHistoryKeyIsNeverExported(): void
	{
		$this->givenSet(
			[
				'rule_definitions'          => '[]',
				'stats_rule_sweep_last_run' => '1700000000',
			],
		);

		$this->assertSame( [ 'rule_definitions' => '[]' ], $this->service->export() );
	}

	/**
	 * A backup from before the distinction existed still carries them, and
	 * refusing is reported apart from an unknown key: the two need different
	 * things said about them.
	 */
	public function testAHistoryKeyInAFileIsRefusedNotWritten(): void
	{
		$this->appConfig->expects( $this->never() )
		                ->method( 'setValueInt' )
		;

		$report = $this->service->import(
			[
				'rule_definitions'          => '[]',
				'stats_rule_sweep_last_run' => '1700000000',
				'from_a_newer_version'      => 'x',
			],
		);

		$this->assertSame( 1, $report['written'] );
		$this->assertSame( [ 'stats_rule_sweep_last_run' ], $report['not_portable'] );
		$this->assertSame( [ 'from_a_newer_version' ], $report['skipped'] );
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
			                static function(
				                string $app,
				                string $key,
			                ): void
			                {
				                if ( $key === 'rule_definitions' )
				                {
					                throw new RuntimeException( 'locked' );
				                }
			                },
		                )
		;

		$this->assertSame( count( $this->service->ownedKeys() ) - 1, $this->service->clear() );
	}

	/**
	 * Stand in for an instance where exactly these keys are set, answering
	 * each typed getter from the same stored strings.
	 *
	 * @param  array<string, string>  $set
	 */
	private function givenSet( array $set ): void
	{
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
			                ): string => $set[ $key ] ?? '',
		                )
		;
		$this->appConfig->method( 'getValueInt' )
		                ->willReturnCallback(
			                static fn(
				                string $app,
				                string $key,
			                ): int => (int) ( $set[ $key ] ?? 0 ),
		                )
		;
		$this->appConfig->method( 'getValueBool' )
		                ->willReturnCallback(
			                static fn(
				                string $app,
				                string $key,
			                ): bool => ( $set[ $key ] ?? '' ) === '1',
		                )
		;
	}
}
