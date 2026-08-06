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
		private readonly IAppConfig       $appConfig,
		private readonly IRootFolder      $rootFolder,
		private readonly HashIndexService $hashIndexService,
		private readonly MetadataService  $metadataService,
		private readonly LoggerInterface  $logger,
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

		$users = $this->hashIndexService->resolveUsers( $userScope );

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
	 * @return File[]
	 */
	public function searchFiles(
		Folder $userFolder,
		string $pathGlob,
		int    $limit,
	): array {

		$likePattern = self::globToLike( $pathGlob );

		$query = new SearchQuery(
			new SearchComparison(
				ISearchComparison::COMPARE_LIKE,
				'name',
				$likePattern,
			),
			$limit,
			0,
			[],
		);

		$results = $userFolder->search( $query );

		$files = [];
		foreach ( $results as $node )
		{
			if ( $node instanceof File )
			{
				$files[] = $node;
			}
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
