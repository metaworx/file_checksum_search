<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Command;

use OCA\FileChecksumSearch\Service\HashIndexService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FindDuplicates
	extends
	Command
{

	public function __construct(
		private readonly HashIndexService $hashIndexService,
		private readonly IDBConnection    $db,
		private readonly IUserManager     $userManager,
	) {

		parent::__construct();
	}


	protected function configure(): void
	{

		$this->setName( 'file-checksum-search:find-duplicates' )
		     ->setDescription( 'Find all files with duplicate hash values across the system' )
		     ->addOption(
			     'algo',
			     'a',
			     InputOption::VALUE_REQUIRED,
			     'Filter by algorithm (e.g. sha1, sha256, md5)',
		     )
		     ->addOption(
			     'user',
			     'u',
			     InputOption::VALUE_REQUIRED,
			     'Show only files accessible to this user (filters by filecache path prefix)',
		     )
		     ->addOption(
			     'min-count',
			     'm',
			     InputOption::VALUE_REQUIRED,
			     'Minimum duplicate count per group (default: 2)',
			     2,
		     )
		     ->addOption(
			     'output',
			     'o',
			     InputOption::VALUE_REQUIRED,
			     'Output format: plain, json, json_pretty (default: plain)',
			     'plain',
		     )
		     ->addOption(
			     'limit',
			     'l',
			     InputOption::VALUE_REQUIRED,
			     'Maximum groups to show (default: 100)',
			     100,
		     )
		     ->addOption(
			     'verify',
			     null,
			     InputOption::VALUE_NONE,
			     'Recalculate hashes for each file and report mismatches',
		     )
		     ->addOption(
			     'verified',
			     null,
			     InputOption::VALUE_NONE,
			     'Only show groups where all files were verified as matching',
		     )
		;
	}


	protected function execute(
		InputInterface  $input,
		OutputInterface $output,
	): int {

		$algo     = $input->getOption( 'algo' );
		$userName = $input->getOption( 'user' );
		$minCount = (int) $input->getOption( 'min-count' );
		$outFmt   = $input->getOption( 'output' );
		$limit    = (int) $input->getOption( 'limit' );
		$verify   = (bool) $input->getOption( 'verify' );
		$verified = (bool) $input->getOption( 'verified' );

		// --verified implies --verify
		if ( $verified )
		{
			$verify = true;
		}

		if ( $algo !== null )
		{
			$algo = strtolower( trim( $algo ) );

			if ( $algo === '' )
			{
				$algo = null;
			}
		}

		if ( $userName !== null )
		{
			$userName = trim( $userName );

			if ( $userName === '' )
			{
				$userName = null;
			}
			else
			{
				$user = $this->userManager->get( $userName );

				if ( $user === null )
				{
					$output->writeln( sprintf( '<error>User "%s" not found.</error>', $userName ) );

					return Command::FAILURE;
				}

				$userName = $user->getUID();
			}
		}

		$minCount = max( 2, $minCount );
		$limit    = max( 1, min( $limit, 1000 ) );

		// When filtering by user, fetch many groups because
		// user filtering happens post-query. The user-specified
		// limit is applied to final output. Do NOT use 0 here —
		// DBAL translates setMaxResults(0) to LIMIT 0.
		$queryLimit = $userName !== null
			? 10000
			: $limit;

		$groups = $this->hashIndexService->findAllDuplicates(
			$algo,
			$minCount,
			$queryLimit,
		);

		if ( empty( $groups ) )
		{
			$this->writeOutput( $output, $outFmt, [] );

			return Command::SUCCESS;
		}

		// Collect all file IDs across all groups
		$allFileIds = [];

		foreach ( $groups as $group )
		{
			foreach ( $group['fileids'] as $fileId )
			{
				$allFileIds[] = $fileId;
			}
		}

		// Batch-lookup filecache paths, optionally filtered by user
		$fcPaths = $this->batchLookupFilecachePaths( $allFileIds, $userName );

		// Build resolved groups with paths
		$resolved = [];

		foreach ( $groups as $group )
		{
			$files = [];

			foreach ( $group['fileids'] as $fileId )
			{
				if ( ! isset( $fcPaths[ $fileId ] ) )
				{
					continue;
				}

				$files[] = [
					'fileid' => $fileId,
					'path'   => $fcPaths[ $fileId ]['path'],
					'name'   => $fcPaths[ $fileId ]['name'],
					'owner'  => $fcPaths[ $fileId ]['user'],
				];
			}

			if ( count( $files ) < $minCount )
			{
				continue;
			}

			$resolved[] = [
				'algo'       => $group['algo'],
				'hash_value' => $group['hash_value'],
				'file_count' => $group['file_count'],
				'files'      => $files,
			];
		}

		// Apply user-specified limit to final filtered output
		if ( $userName !== null && count( $resolved ) > $limit )
		{
			$resolved = array_slice( $resolved, 0, $limit );
		}

		// Recalculate hashes and annotate matches/mismatches
		if ( $verify )
		{
			$output->writeln( 'Verifying hashes …' );

			foreach ( $resolved as &$group )
			{
				$algo          = $group['algo'];
				$matchCount    = 0;
				$mismatchCount = 0;

				foreach ( $group['files'] as &$file )
				{
					$result = $this->hashIndexService->recalcHash(
						$file['fileid'],
						$algo,
						false,
					);

					if ( $result['success'] )
					{
						$file['verified_hash'] = $result['hash'];

						if ( $result['hash'] === $group['hash_value'] )
						{
							$file['verified'] = true;
							$matchCount ++;
						}
						else
						{
							$file['verified'] = false;
							$mismatchCount ++;
						}
					}
					else
					{
						$file['verified']     = false;
						$file['verify_error'] = $result['error'] ?? 'Unknown error';
						$mismatchCount ++;
					}
				}
				unset( $file );

				$group['match_count']    = $matchCount;
				$group['mismatch_count'] = $mismatchCount;
			}
			unset( $group );
		}

		// --verified: filter to only fully-verified groups
		if ( $verified )
		{
			$resolved = array_filter(
				$resolved,
				static fn(
					array $group,
				): bool => ( $group['mismatch_count'] ?? 1 ) === 0,
			);
		}

		$this->writeOutput( $output, $outFmt, $resolved, $verify );

		return Command::SUCCESS;
	}


	/**
	 * @param  array{algo: string, hash_value: string, file_count: int, files: array}[]  $groups
	 */
	private function writeOutput(
		OutputInterface $output,
		string          $format,
		array           $groups,
		bool            $verified = false,
	): void {

		if ( $format === 'json' || $format === 'json_pretty' )
		{
			$output->writeln(
				json_encode(
					[ 'duplicates' => $groups ],
					$format === 'json_pretty'
						? JSON_PRETTY_PRINT
						: 0,
				),
			);

			return;
		}

		if ( empty( $groups ) )
		{
			$output->writeln( 'No duplicate files found.' );

			return;
		}

		$idx = 0;

		foreach ( $groups as $group )
		{
			$idx ++;

			$status = '';

			if ( $verified )
			{
				$match    = $group['match_count'] ?? 0;
				$mismatch = $group['mismatch_count'] ?? 0;

				if ( $mismatch > 0 )
				{
					$status = sprintf( ' [! %d MISMATCH]', $mismatch );
				}
				else
				{
					$status = ' [VERIFIED]';
				}
			}

			$output->writeln(
				sprintf(
					'<info>Group %d: %s / %s (%d files)%s</info>',
					$idx,
					strtoupper( $group['algo'] ),
					$group['hash_value'],
					$group['file_count'],
					$status,
				),
			);

			foreach ( $group['files'] as $file )
			{
				$displayPath = $file['path']
					?: $file['name'];

				$ownerLabel = $file['owner'] !== ''
					? sprintf( '(%s) ', $file['owner'] )
					: '';

				$verifiedTag = '';

				if ( $verified && isset( $file['verified'] ) )
				{
					if ( $file['verified'] )
					{
						$verifiedTag = ' ✓';
					}
					elseif ( isset( $file['verify_error'] ) )
					{
						$verifiedTag = sprintf( ' ✗ (%s)', $file['verify_error'] );
					}
					else
					{
						$verifiedTag = sprintf( ' ✗ (now: %s)', $file['verified_hash'] ?? '?' );
					}
				}

				$output->writeln(
					sprintf(
						'  [%d] %s%s%s',
						$file['fileid'],
						$ownerLabel,
						$displayPath,
						$verifiedTag,
					),
				);
			}

			$output->writeln( '' );
		}
	}


	/**
	 * Batch-lookup filecache paths for a list of file IDs.
	 *
	 * Always joins storages to resolve the storage ID for each file.
	 * When $userName is provided, only files from that user's home
	 * storage are returned (matched via storages.id = 'home::{uid}').
	 *
	 * @param  int[]        $fileIds
	 * @param  string|null  $userName
	 *
	 * @return array<int, array{path: string, name: string, storage_id: string}>
	 */
	private function batchLookupFilecachePaths(
		array   $fileIds,
		?string $userName = null,
	): array {

		if ( empty( $fileIds ) )
		{
			return [];
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select( 'fc.fileid', 'fc.path', 'fc.name', 's.id' )
		   ->from( 'filecache', 'fc' )
		   ->innerJoin(
			   'fc',
			   'storages',
			   's',
			   'fc.storage = s.numeric_id',
		   )
		;

		if ( $userName !== null )
		{
			$qb->where(
				$qb->expr()
				   ->eq(
					   's.id',
					   $qb->createNamedParameter( 'home::' . $userName ),
				   ),
			)
			   ->andWhere(
				   $qb->expr()
				      ->in(
					      'fc.fileid',
					      $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				      ),
			   )
			;
		}
		else
		{
			$qb->where(
				$qb->expr()
				   ->in(
					   'fc.fileid',
					   $qb->createNamedParameter( $fileIds, IQueryBuilder::PARAM_INT_ARRAY ),
				   ),
			);
		}

		$result = $qb->executeQuery();
		$paths  = [];

		while ( ( $row = $result->fetch() ) !== false )
		{
			$sid  = (string) $row['id'];
			$user = '';

			if ( str_starts_with( $sid, 'home::' ) )
			{
				$user = substr( $sid, 6 );
			}
			elseif ( str_starts_with( $sid, 'local::' ) )
			{
				$user = basename( $sid );
			}
			else
			{
				$user = $sid;
			}

			$paths[ (int) $row['fileid'] ] = [
				'path'       => (string) $row['path'],
				'name'       => (string) $row['name'],
				'storage_id' => $sid,
				'user'       => $user,
			];
		}
		$result->closeCursor();

		return $paths;
	}

}
