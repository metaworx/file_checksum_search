<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deliberate, named deviations from what the rules say, for one CLI run.
 *
 * The rules are a statement of intent about the storage — an `exclude` on a
 * metered mount means reading costs money — so there is no blanket override.
 * What an administrator gets instead is the ability to say exactly which
 * rule they are setting aside, or that "not automatically" should not stop a
 * command they typed themselves. Both appear in shell history and in cron
 * lines, where a `--force` would have said nothing about what it permitted.
 *
 * The default instance overrides nothing, so every caller that does not opt
 * in behaves exactly as the background job does.
 */
readonly class RuleOverrides
{

	/**
	 * @param  bool          $withIgnored    Process files whose governing rule
	 *                                       is `ignore`. That verdict means
	 *                                       "not automatically, but when
	 *                                       asked", and an administrator
	 *                                       typing a command is asking. Never
	 *                                       affects `exclude`.
	 * @param  list<string>  $ignoreRuleIds  Rules to evaluate as though they
	 *                                       did not exist, so the next
	 *                                       matching rule decides. Not a
	 *                                       general permit: a file no other
	 *                                       rule matches still falls through
	 *                                       to the default.
	 */
	public function __construct(
		public bool  $withIgnored = false,
		public array $ignoreRuleIds = [],
	) {
	}


	/**
	 * Whether this run should process a file governed by $rule.
	 *
	 * $rule must already have been resolved with {@see $ignoreRuleIds} applied
	 * — setting a rule aside changes *which* rule governs, which is a question
	 * for the lookup, not for this one.
	 */
	public function allows( ?array $rule ): bool
	{

		if ( RuleService::maintainsHashes( $rule ) )
		{
			return true;
		}

		return $this->withIgnored
			&& $rule !== null
			&& RuleService::verdictOf( $rule ) === RuleService::TYPE_IGNORE;
	}


	/**
	 * Report which rule decided a file, when the caller asked to be told.
	 *
	 * A skipped file used to be only a number in the summary, so the one
	 * question an operator actually has — why is *this* file not being hashed
	 * — had no answer short of working the bands out by hand. `-v` names the
	 * rule for every file that was skipped; `-vv` also names it for the files
	 * that proceeded, which is what makes "nothing matched, so nothing
	 * decided" visible rather than merely absent.
	 *
	 * It lives here because both the direct and the --mark path need it and
	 * both already hold the overrides that produced the decision.
	 */
	public function report(
		?OutputInterface $output,
		string           $path,
		?array           $rule,
		bool             $proceeding,
	): void {

		if ( $output === null )
		{
			return;
		}

		$required = $proceeding
			? OutputInterface::VERBOSITY_VERY_VERBOSE
			: OutputInterface::VERBOSITY_VERBOSE;

		if ( $output->getVerbosity() < $required )
		{
			return;
		}

		$output->writeln(
			sprintf(
				'    %s %s [%s]',
				$proceeding
					? 'hash'
					: 'skip',
				$path,
				$rule === null
					? 'no matching rule'
					: sprintf(
					'%s: band %d, %s',
					$rule['id'] ?? '?',
					RuleService::bandOf( $rule ),
					RuleService::verdictOf( $rule ),
				),
			),
		);
	}


	/**
	 * True when nothing is overridden, i.e. the rules decide on their own.
	 */
	public function isEmpty(): bool
	{

		return ! $this->withIgnored && $this->ignoreRuleIds === [];
	}

}
