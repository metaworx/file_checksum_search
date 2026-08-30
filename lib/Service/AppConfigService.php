<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * This app's own slice of Nextcloud's app configuration.
 *
 * Which keys the app owns is a question with one honest answer: the config
 * lexicon, which already declares every one of them and is what Nextcloud
 * validates writes against. Reading the list from there rather than repeating
 * it means a key added to the lexicon is backed up and reset without anyone
 * remembering to add it in a second place — and a key *missing* from the
 * lexicon was already a defect, since Nextcloud logs a warning for every read
 * of an undeclared key.
 *
 * Not `readonly`: the command tests double this class, and PHPUnit 10.5
 * cannot mock readonly classes (TESTING.md §6.1).
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class AppConfigService
{

	public function __construct(
		private readonly IAppConfig      $appConfig,
		private readonly ConfigLexicon   $lexicon,
		private readonly LoggerInterface $logger,
	) {
	}


	/**
	 * The keys this app owns, in the order the lexicon declares them.
	 *
	 * @return list<string>
	 */
	public function ownedKeys(): array
	{

		return array_map(
			static fn(
				$entry,
			): string => $entry->getKey(),
			$this->lexicon->getAppConfigs(),
		);
	}


	/**
	 * Every owned key that is actually set, as raw strings.
	 *
	 * Values are exported as stored rather than as typed values: a backup is
	 * a record of what the instance had, and re-typing it on the way out
	 * would mean guessing on the way back in.
	 *
	 * @return array<string, string>
	 */
	public function export(): array
	{

		$config = [];

		foreach ( $this->ownedKeys() as $key )
		{
			if ( ! $this->appConfig->hasKey( Application::APP_ID, $key ) )
			{
				continue;
			}

			$config[ $key ] = $this->appConfig->getValueString( Application::APP_ID, $key );
		}

		return $config;
	}


	/**
	 * Write configuration back.
	 *
	 * Keys the lexicon does not declare are refused rather than written: an
	 * import is not a way to smuggle configuration past the declaration that
	 * makes it legible, and a backup from a newer version naming keys this
	 * one does not have should say so rather than half-apply.
	 *
	 * A backup file's config object is whatever `json_decode()` made of it,
	 * so the keys may have been coerced to integers and the values may be any
	 * scalar — hence the casts, which the declared types alone do not make
	 * redundant.
	 *
	 * @param  array<array-key, scalar>  $config
	 * @param  bool                      $replace  Delete owned keys the import
	 *                                             does not mention, so the
	 *                                             result is exactly the input.
	 *
	 * @return array{written: int, skipped: list<string>}
	 */
	public function import(
		array $config,
		bool  $replace = false,
	): array {

		$owned   = $this->ownedKeys();
		$written = 0;
		$skipped = [];

		foreach ( $config as $key => $value )
		{
			if ( ! in_array( $key, $owned, true ) )
			{
				$skipped[] = (string) $key;

				continue;
			}

			$this->appConfig->setValueString( Application::APP_ID, (string) $key, (string) $value );
			$written ++;
		}

		if ( $replace )
		{
			foreach ( array_diff( $owned, array_keys( $config ) ) as $key )
			{
				$this->deleteKey( $key );
			}
		}

		return [
			'written' => $written,
			'skipped' => $skipped,
		];
	}


	/**
	 * Forget every owned key, returning the app to its declared defaults.
	 *
	 * Deletion rather than writing defaults: an absent key *is* the default,
	 * and the lexicon is where the default lives. Writing them out would
	 * freeze today's values into the instance and quietly diverge the day a
	 * default changed.
	 *
	 * @return int  Keys deleted.
	 */
	public function clear(): int
	{

		$deleted = 0;

		foreach ( $this->ownedKeys() as $key )
		{
			if ( ! $this->appConfig->hasKey( Application::APP_ID, $key ) )
			{
				continue;
			}

			if ( $this->deleteKey( $key ) )
			{
				$deleted ++;
			}
		}

		return $deleted;
	}


	/**
	 * One deletion, contained: a key that refuses to go must not abandon the
	 * rest of the reset half-done.
	 */
	private function deleteKey( string $key ): bool
	{

		try
		{
			$this->appConfig->deleteKey( Application::APP_ID, $key );

			return true;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: could not delete app config key {key}; continuing.',
				[
					'app'       => Application::APP_ID,
					'key'       => $key,
					'exception' => $e,
				],
			);

			return false;
		}
	}

}
