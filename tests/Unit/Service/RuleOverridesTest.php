<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\Service;

use OCA\FileChecksumSearch\Service\RuleOverrides;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class RuleOverridesTest
	extends
	TestCase
{

	public function testTheDefaultOverridesNothing(): void
	{

		$overrides = new RuleOverrides();

		$this->assertTrue( $overrides->isEmpty() );
		$this->assertSame( [], $overrides->ignoreRuleIds );
		$this->assertFalse( $overrides->withIgnored );
	}


	/**
	 * With nothing overridden the command must behave exactly as the
	 * background job does, or the two would disagree about the same file.
	 *
	 * @dataProvider rulesWithoutOverrides
	 */
	public function testWithoutOverridesOnlyIncludeIsAllowed(
		?array $rule,
		bool   $expected,
	): void {

		$this->assertSame( $expected, ( new RuleOverrides() )->allows( $rule ) );
	}


	public static function rulesWithoutOverrides(): array
	{

		return [
			'include'          => [
				[ 'type' => 'include' ],
				true,
			],
			'no type defaults' => [
				[ 'id' => 'r1' ],
				true,
			],
			'ignore'           => [
				[ 'type' => 'ignore' ],
				false,
			],
			'exclude'          => [
				[ 'type' => 'exclude' ],
				false,
			],
			'no matching rule' => [
				null,
				false,
			],
		];
	}


	public function testWithIgnoredAllowsIgnoreAndNothingElse(): void
	{

		$overrides = new RuleOverrides( withIgnored: true );

		$this->assertTrue( $overrides->allows( [ 'type' => 'ignore' ] ) );
		$this->assertTrue( $overrides->allows( [ 'type' => 'include' ] ) );

		// "exclude" says the storage must not be read; asking harder does not
		// change what reading it costs.
		$this->assertFalse( $overrides->allows( [ 'type' => 'exclude' ] ) );

		// And a file no rule governs is still not something to hash on a whim.
		$this->assertFalse( $overrides->allows( null ) );
	}


	public function testIgnoreRuleIdsDoNotThemselvesPermitAnything(): void
	{

		// Setting a rule aside changes which rule governs — a question for the
		// lookup. By the time allows() sees the answer, the verdict it carries
		// is the one that counts.
		$overrides = new RuleOverrides( ignoreRuleIds: [ 'metered' ] );

		$this->assertFalse( $overrides->isEmpty() );
		$this->assertFalse(
			$overrides->allows(
				[
					'id'   => 'other',
					'type' => 'exclude',
				],
			),
		);
	}


	public function testReportNamesTheRuleBandAndVerdict(): void
	{

		$output = new BufferedOutput( OutputInterface::VERBOSITY_VERBOSE );

		( new RuleOverrides() )->report(
			$output,
			'/files/metered/a.txt',
			[
				'id'             => 'metered',
				'type'           => 'exclude',
				'userScope'      => 'all',
				'admin_enforced' => true,
			],
			false,
		);

		$this->assertSame(
			"    skip /files/metered/a.txt [metered: band 3, exclude]\n",
			$output->fetch(),
		);
	}


	public function testReportSaysSoWhenNoRuleMatched(): void
	{

		$output = new BufferedOutput( OutputInterface::VERBOSITY_VERBOSE );

		( new RuleOverrides() )->report( $output, '/files/a.txt', null, false );

		$this->assertStringContainsString( '[no matching rule]', $output->fetch() );
	}


	public function testAProceedingFileIsReportedOnlyAtVeryVerbose(): void
	{

		$verbose = new BufferedOutput( OutputInterface::VERBOSITY_VERBOSE );
		( new RuleOverrides() )->report( $verbose, '/files/a.txt', [ 'id' => 'r1' ], true );
		$this->assertSame( '', $verbose->fetch() );

		$veryVerbose = new BufferedOutput( OutputInterface::VERBOSITY_VERY_VERBOSE );
		( new RuleOverrides() )->report( $veryVerbose, '/files/a.txt', [ 'id' => 'r1' ], true );
		$this->assertStringContainsString( 'hash /files/a.txt', $veryVerbose->fetch() );
	}


	public function testNothingIsReportedAtNormalVerbosity(): void
	{

		$output = new BufferedOutput( OutputInterface::VERBOSITY_NORMAL );

		( new RuleOverrides() )->report( $output, '/files/a.txt', [ 'id' => 'r1' ], false );

		$this->assertSame( '', $output->fetch() );
	}


	public function testReportIsANoOpWithoutAnOutput(): void
	{

		$this->expectNotToPerformAssertions();

		( new RuleOverrides() )->report( null, '/files/a.txt', null, false );
	}

}
