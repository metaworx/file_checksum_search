<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use JsonException;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Search\ISearchComparison;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rule evaluation engine for hash-generation rules.
 *
 * Loads rules from IAppConfig, resolves user scope, searches for
 * matching files via Folder::search(), checks staleness via
 * MetadataService, and marks stale files as pending:{mode}.
 */
class RuleService
{

// constants
	private const CONFIG_KEY_RULES = 'rule_definitions';


	public function __construct(
		private readonly IAppConfig      $appConfig,
		private readonly IRootFolder     $rootFolder,
		private readonly IUserManager    $userManager,
		private readonly MetadataService $metadataService,
		private readonly LoggerInterface $logger,
	) {
	}


	/**
	 * Evaluate all enabled rules and mark stale files as pending.
	 *
	 * Rules are processed in order (first = highest priority).
	 * Later rules exclude files already matched by earlier ones.
	 *
	 * @return array{marked: int, matched: int}
	 */
	/**
	 * @return string[]
	 */
	public function resolveUsers( string $userScope ): array
	{

		if ( $userScope === 'all' )
		{
			$allUsers = [];

			$this->userManager->callForAllUsers(
				function (
					$user,
				) use
				(
					&
					$allUsers,
				): void
				{

					$allUsers[] = $user->getUID();
				},
			);

			return $allUsers;
		}

		$user = $this->userManager->get( $userScope );

		if ( $user === null )
		{
			$this->logger->warning(
				'FCIAS: resolveUsers — user not found.',
				[
					'app'       => Application::APP_ID,
					'userScope' => $userScope,
				],
			);

			return [];
		}

		return [ $user->getUID() ];
	}


	public function evaluateRules(): array
	{

		$rules   = $this->loadRules();
		$marked  = 0;
		$matched = 0;

		$excludedFileIds = [];

		foreach ( $rules as $index => $rule )
		{
			if ( empty( $rule['enabled'] ) )
			{
				continue;
			}

			$result = $this->processRule(
				$rule,
				$excludedFileIds,
			);

			$marked          += $result['marked'];
			$matched         += $result['matched'];
			$excludedFileIds = array_merge( $excludedFileIds, $result['fileIds'] );
		}

		$this->logger->info(
			'FCIAS RuleService: evaluation complete',
			[
				'app'     => Application::APP_ID,
				'rules'   => count( $rules ),
				'matched' => $matched,
				'marked'  => $marked,
			],
		);

		return [
			'marked'  => $marked,
			'matched' => $matched,
		];
	}


	/**
	 * Load rule definitions from IAppConfig.
	 *
	 * @return list<array>
	 */
	public function loadRules(): array
	{

		$json = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			'[]',
		);

		try
		{
			$rules = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		}
		catch ( JsonException )
		{
			return [];
		}

		return is_array( $rules )
			? $rules
			: [];
	}


	/**
	 * Process a single rule: resolve users, search files, mark stale.
	 *
	 * @param  array  $rule             Rule definition from IAppConfig
	 * @param  int[]  $excludedFileIds  File IDs matched by higher-priority rules
	 *
	 * @return array{marked: int, matched: int, fileIds: int[]}
	 */
	public function processRule(
		array $rule,
		array $excludedFileIds,
	): array {

		$marked  = 0;
		$matched = 0;
		$fileIds = [];

		$mode      = $rule['mode'] ?? 'auto';
		$pathGlob  = $rule['path'] ?? '/';
		$userScope = $rule['userScope'] ?? 'all';
		$batchSize = 100;

		if ( $pathGlob === '' || $pathGlob === '/' )
		{
			$pathGlob = '**';
		}

		$users = $this->resolveUsers( $userScope );

		foreach ( $users as $userId )
		{
			try
			{
				$userFolder = $this->rootFolder->getUserFolder( $userId );
			}
			catch ( Throwable )
			{
				$this->logger->warning(
					'FCIAS RuleService: unable to get user folder, skipping user.',
					[
						'app'    => Application::APP_ID,
						'userId' => $userId,
					],
				);

				continue;
			}

			try
			{
				$files = $this->searchFiles( $userFolder, $pathGlob, $batchSize );
			}
			catch ( Throwable $e )
			{
				$this->logger->warning(
					'FCIAS RuleService: file search failed for user.',
					[
						'app'       => Application::APP_ID,
						'userId'    => $userId,
						'pathGlob'  => $pathGlob,
						'exception' => $e,
					],
				);

				continue;
			}

			foreach ( $files as $file )
			{
				if ( ! $file instanceof File )
				{
					continue;
				}

				$fileId = $file->getId();

				if ( in_array( $fileId, $excludedFileIds, true ) )
				{
					continue;
				}

				$matched ++;
				$fileIds[] = $fileId;

				$updatedAt = $this->metadataService->getUpdatedAt( $fileId );

				if ( $updatedAt !== null && $updatedAt >= $file->getMTime() )
				{
					continue; // fresh, skip
				}

				$this->metadataService->markPending(
					$fileId,
					MetadataService::PENDING_PREFIX . $mode,
				);

				$marked ++;

				if ( $marked >= $batchSize )
				{
					break 2;
				}
			}
		}

		return [
			'marked'  => $marked,
			'matched' => $matched,
			'fileIds' => $fileIds,
		];
	}


	/**
	 * Find the first enabled rule whose path glob matches the given file path.
	 *
	 * @return array|null Rule definition or null if no match
	 */
	public function findFirstMatchingRule( string $filePath ): ?array
	{

		$rules = $this->loadRules();

		foreach ( $rules as $rule )
		{
			if ( empty( $rule['enabled'] ) )
			{
				continue;
			}

			$pathGlob = $rule['path'] ?? '**';

			if ( $pathGlob === '' || $pathGlob === '/' )
			{
				$pathGlob = '**';
			}

			if ( PathUtil::matchesGlob( $pathGlob, $filePath ) )
			{
				return $rule;
			}
		}

		return null;
	}


	/**
	 * @throws JsonException
	 * @throws \Random\RandomException
	 */
	public function ruleAdd( array $definition ): void
	{

		$rules            = $this->loadRules();
		$definition['id'] = bin2hex( random_bytes( 16 ) );
		$rules[]          = $definition;

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			json_encode( $rules, JSON_THROW_ON_ERROR ),
		);
	}


	/**
	 * @throws JsonException
	 */
	public function ruleDelete( string $id ): void
	{

		$rules = $this->loadRules();
		$rules = array_values(
			array_filter(
				$rules,
				static fn(
					array $rule,
				): bool => ( $rule['id'] ?? '' ) !== $id,
			),
		);

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			json_encode( $rules, JSON_THROW_ON_ERROR ),
		);
	}


	/**
	 * @throws JsonException
	 */
	public function ruleToggle(
		string $id,
		bool   $enabled,
	): void {

		$rules = $this->loadRules();

		foreach ( $rules as &$rule )
		{
			if ( ( $rule['id'] ?? '' ) === $id )
			{
				$rule['enabled'] = $enabled;

				break;
			}
		}
		unset( $rule );

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			json_encode( $rules, JSON_THROW_ON_ERROR ),
		);
	}


	/**
	 * @throws JsonException
	 */
	public function ruleUpdate(
		string $id,
		array  $definition,
	): void {

		$rules = $this->loadRules();

		foreach ( $rules as &$rule )
		{
			if ( ( $rule['id'] ?? '' ) === $id )
			{
				$definition['id'] = $id;
				$rule             = $definition;

				break;
			}
		}
		unset( $rule );

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY_RULES,
			json_encode( $rules, JSON_THROW_ON_ERROR ),
		);
	}


	/**
	 * Search for files matching a path glob within a user folder.
	 *
	 * Delegates to {@see searchFilesByGlob()}.
	 *
	 * @return File[]
	 */
	public function searchFiles(
		Folder $userFolder,
		string $pathGlob,
		int    $limit,
	): array {

		return $this->searchFilesByGlob( $userFolder, $pathGlob, $limit );
	}


	/**
	 * Search for files matching a path glob within a folder.
	 *
	 * Uses offset-based pagination: fetches SQL batches, filters each
	 * with fnmatch (SQL LIKE over-matches because % matches / while
	 * glob * does not), and stops when enough matches are collected.
	 *
	 * A safety cap limits total scanned rows to 10× the requested
	 * limit to avoid unbounded scanning on very broad patterns.
	 *
	 * @return File[]
	 */
	public function searchFilesByGlob(
		Folder $folder,
		string $pathGlob,
		int    $limit,
		int    $pageSize = 500,
	): array {

		$likePattern = self::globToLike( $pathGlob );;
		$maxScan = max( $limit * 5, $pageSize );
		$files   = [];
		$offset  = 0;

		while ( count( $files ) < $limit && $offset < $maxScan )
		{
			$query = new SearchQuery(
				new SearchComparison(
					ISearchComparison::COMPARE_LIKE,
					'path',
					$likePattern,
				),
				$pageSize,
				$offset,
				[],
			);

			$results = $folder->search( $query );

			if ( empty( $results ) )
			{
				break;
			}

			foreach ( $results as $node )
			{
				if ( ! ( $node instanceof File ) )
				{
					continue;
				}

				// SQL LIKE is approximate — PathUtil::matchesGlob ensures exact glob semantics
				if ( ! PathUtil::matchesGlob( $pathGlob, $node->getPath() ) )
				{
					continue;
				}

				$files[] = $node;

				if ( count( $files ) >= $limit )
				{
					break;
				}
			}

			$offset += $pageSize;
		}

		return $files;
	}


	/**
	 * Convert a glob pattern to SQL LIKE pattern.
	 */
	public static function globToLike( string $glob ): string
	{

		$like = str_replace(
			[
				'%',
				'_',
			],
			[
				'\%',
				'\_',
			],
			$glob,
		);
		$like = str_replace(
			[
				'*',
				'?',
			],
			[
				'%',
				'_',
			],
			$like,
		);

		return $like;
	}

}
