<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Listener;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\RuleProcessingJob;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A deleted user leaves this app's metadata behind; make the purge due now.
 *
 * Nextcloud removes a file's metadata from `CacheEntriesRemovedEvent`, and
 * deleting a user never dispatches it — the home storage's filecache rows go
 * in one statement. The purge that catches this rides RuleProcessingJob once
 * a day; zeroing its clock here means the next tick runs it instead.
 *
 * Nothing is enumerated and nothing is carried: which files were the user's
 * is not knowable after the fact, and the purge does not need to know — it
 * finds them by their absence from the filecache. That is also why it cannot
 * run too early. Whether the filecache rows are already gone when this fires
 * depends on listener order, which is not ours to rely on; if they are not
 * yet, the purge finds nothing and the daily run finds it.
 *
 * `BeforeUserDeletedEvent` would be the wrong hook: it exists to ask whether
 * the deletion may happen, and fires before `backend->deleteUser()`, which can
 * still fail. This one fires only once it has succeeded.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener
    implements
    IEventListener
{

//  constructor

	public function __construct(
		private readonly IAppConfig      $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}


//  static methods

	public static function register( IRegistrationContext $context ): void
	{
		$context->registerEventListener( UserDeletedEvent::class, self::class );
	}


//  other non-static methods

	#[\Override]
	public function handle( Event $event ): void
	{
		if ( ! $event instanceof UserDeletedEvent )
		{
			return;
		}

		// Never let housekeeping throw into a user deletion: the worst case
		// of failing here is that the purge waits for its daily run.
		try
		{
			$this->appConfig->setValueInt(
				Application::APP_ID,
				RuleProcessingJob::ORPHAN_PURGE_LAST_RUN,
				0,
			);

			$this->logger->debug(
				'FCIAS UserDeletedListener: orphan purge made due after deleting {uid}.',
				[
					'app' => Application::APP_ID,
					'uid' => $event->getUid(),
				],
			);
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS UserDeletedListener: could not make the orphan purge due; it runs daily regardless.',
				[
					'app'       => Application::APP_ID,
					'exception' => $e,
				],
			);
		}
	}
}
