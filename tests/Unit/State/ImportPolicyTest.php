<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit\State;

use InvalidArgumentException;
use OCA\FileChecksumSearch\State\ImportPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The defaults are the interesting assertion here: this is the one place
 * where being permissive writes hashes that no correction path can ever
 * reach again.
 */
class ImportPolicyTest
    extends
    TestCase
{

//  other non-static methods

	public function testTheDefaultsAreTheConservativeOnes(): void
	{
		$policy = new ImportPolicy();

		$this->assertTrue( $policy->merge, 'an import adds; replacing is asked for' );
		$this->assertSame( ImportPolicy::STAMP_SOURCE, $policy->stamp );
		$this->assertFalse( $policy->allowOutdated );
		$this->assertFalse( $policy->strict );
		$this->assertFalse( $policy->dryRun );
		$this->assertFalse( $policy->warrantsWarning() );
	}

	/**
	 * Two ways to end up with a stored hash that says more than the data
	 * supports; both are worth saying out loud.
	 *
	 * @dataProvider warningCases
	 */
	public function testAPolicyThatOverstatesItsEvidenceWarns( ImportPolicy $policy ): void
	{
		$this->assertTrue( $policy->warrantsWarning() );
	}


//  static methods

	/**
	 * @return array<string, array{ImportPolicy}>
	 */
	public static function warningCases(): array
	{
		return [
			'stamping now'      => [ new ImportPolicy( stamp: ImportPolicy::STAMP_NOW ) ],
			'allowing outdated' => [ new ImportPolicy( allowOutdated: true ) ],
		];
	}

	public function testMtimeStampingDoesNotWarn(): void
	{
		// Claiming the hash matches the file as it stands is what a sumfile
		// run a moment ago means; it is the reason the option exists.
		$this->assertFalse(
			( new ImportPolicy( stamp: ImportPolicy::STAMP_MTIME ) )->warrantsWarning(),
		);
	}

	public function testAnUnknownStampIsRefusedWhereItIsWritten(): void
	{
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/source, mtime, now/' );

		new ImportPolicy( stamp: 'yesterday' );
	}
}
