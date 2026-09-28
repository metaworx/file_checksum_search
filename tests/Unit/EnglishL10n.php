<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Unit;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An `IL10N` that answers in English, for a unit under test that translates.
 *
 * It fills a text's values the way Nextcloud's does — `%s` and `%1$s` by
 * `vsprintf`, `%n` with the count — so a test asserts the English message a
 * user without a translation reads, placeholders filled. For a class
 * extending PHPUnit's `TestCase`, whose `createMock()` it uses.
 */
trait EnglishL10n
{

//  other non-static methods

	protected function englishL10n(): IL10N&MockObject
	{
		$l10n = $this->createMock( IL10N::class );

		$l10n->method( 't' )
		     ->willReturnCallback(
			     static fn(
				     string       $text,
				     array|string $parameters = [],
			     ): string => vsprintf( $text, (array) $parameters ),
		     )
		;

		$l10n->method( 'n' )
		     ->willReturnCallback(
			     static fn(
				     string $singular,
				     string $plural,
				     int    $count,
				     array  $parameters = [],
			     ): string => vsprintf(
				     str_replace( '%n', (string) $count, $count === 1 ? $singular : $plural ),
				     $parameters,
			     ),
		     )
		;

		return $l10n;
	}
}
