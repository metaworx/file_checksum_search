<?php

declare( strict_types=1 );

namespace OCA\FileChecksumSearch\Vendor\mwx\Nextcloud\App\Update;

use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An app's own update command: reads the store, clears the server's
 * fetcher cache, and lets the server's installer take a stable or a
 * nightly release.
 *
 * The app's subclass names the app and the command; the server's
 * container builds it, through this constructor, from the app's
 * `appinfo/info.xml`.
 *
 * Which release it takes:
 * - no flag: an installed nightly takes the newest release of any kind
 *   above it, an installed stable the newest stable above it;
 * - `--nightly`: the newest release of any kind above the installed;
 * - `--stable`: the newest stable above the installed.
 *
 * Exit codes: 0 updated, or under `--check` something to take; 1 nothing
 * to take; 2 a failure or a usage error.
 */
abstract class UpdateCommand
    extends
    Command
{

//  constants

    public const OUTPUT_PLAIN       = 'plain';
    public const OUTPUT_JSON        = 'json';
    public const OUTPUT_JSON_PRETTY = 'json_pretty';


//  constructor

    /**
     * @param Installer|null $installer     the server's, through `ServerInstaller`,
     *                                      where not given
     * @param string|null    $serverVersion major.minor.patch;
     *                                      `OCP\Util::getVersion()` where not given
     */
    public function __construct(
        private readonly IClientService  $clientService,
        private readonly IConfig         $config,
        private readonly IAppManager     $appManager,
        private readonly IAppDataFactory $appDataFactory,
        private readonly IAppConfig      $appConfig,
        private readonly ?Installer      $installer = null,
        private readonly ?string         $serverVersion = null,
    )
    {
        parent::__construct( $this->commandName() );
    }


//  config/init/exe/run methods

    #[\Override]
    protected function configure(): void
    {
        $this->setAliases( $this->commandAliases() )
             ->setDescription( sprintf( 'Updates %s from the app store, a stable or a nightly release', $this->appId() ) )
             ->addOption( 'check', null, InputOption::VALUE_NONE, 'Report what would be taken, and change nothing' )
             ->addOption( 'stable', null, InputOption::VALUE_NONE, 'Take the newest stable release above the installed' )
             ->addOption( 'nightly', null, InputOption::VALUE_NONE, 'Take the newest release of any kind above the installed' )
             ->addOption(
                 'output',
                 $this->outputShortcut(),
                 InputOption::VALUE_REQUIRED,
                 'Output format (plain, json or json_pretty)',
                 self::OUTPUT_PLAIN,
             )
        ;
    }

    #[\Override]
    protected function execute(
        InputInterface  $input,
        OutputInterface $output,
    ): int
    {
        $format = $input->getOption( 'output' );

        if ( $input->getOption( 'stable' ) && $input->getOption( 'nightly' ) )
        {
            return $this->usageError( $output, '--stable and --nightly exclude each other' );
        }

        if ( !in_array( $format, [ self::OUTPUT_PLAIN, self::OUTPUT_JSON, self::OUTPUT_JSON_PRETTY ], true ) )
        {
            return $this->usageError( $output, sprintf( '--output takes plain, json or json_pretty, not "%s"', (string) $format ) );
        }

        $check  = (bool) $input->getOption( 'check' );
        $report = $this->takeOrCheck( $this->flaggedChannel( $input ), $check );

        $output->writeln(
            match ( $format )
            {
                self::OUTPUT_JSON        => $report->toJson(),
                self::OUTPUT_JSON_PRETTY => $report->toJson( true ),
                default                  => $report->toLines(),
            },
            OutputInterface::OUTPUT_RAW,
        );

        return $report->outcome->exitCode();
    }


//  other non-static methods

    /**
     * The id of the app this command updates, as in `appinfo/info.xml`.
     */
    abstract protected function appId(): string;

    /**
     * The command's name, `<prefix>:update`.
     */
    abstract protected function commandName(): string;

    /**
     * The command's aliases; none unless the subclass names them.
     *
     * @return list<string>
     */
    protected function commandAliases(): array
    {
        return [];
    }

    /**
     * The one-letter shortcut of `--output`, `-o` unless overridden;
     * null where the app uses the letter for something else.
     */
    protected function outputShortcut(): ?string
    {
        return 'o';
    }

    /**
     * The store as it stands for the app, against this server.
     */
    protected function storeRelease(): StoreRelease
    {
        return new StoreRelease( $this->clientService, $this->config, $this->appManager, $this->appId(), $this->serverVersion );
    }

    protected function appUpdater(): AppUpdater
    {
        return new AppUpdater( $this->installer ?? new ServerInstaller(), $this->appDataFactory, $this->appConfig );
    }

    /**
     * Chooses the channel, decides whether there is anything to take, and
     * lets the installer take it, or under `$check` only asks it.
     *
     * @param string|null $flagged the channel a flag chose, or null for the
     *                             installed version's kind
     */
    private function takeOrCheck(
        ?string $flagged,
        bool    $check,
    ): Report
    {
        $store       = $this->storeRelease();
        $channel     = $flagged ?? $store->getInstalledKind();
        $chosen      = $store->getNewest( $channel );
        $enabled     = $this->config->getSystemValueBool( 'appstoreenabled', true );
        $unavailable = $store->getUnavailableReason();

        // Where the server's fetcher would not ask the store, neither it nor
        // the installer is asked.
        if ( $unavailable !== null )
        {
            $outcome = Outcome::failed( $unavailable );
        }
        elseif ( $store->isReadable() && $chosen === null )
        {
            $outcome = Outcome::nothing(
                sprintf(
                    $channel === Release::STABLE ? 'no compatible stable release above %s' : 'no compatible release above %s',
                    $store->getInstalledVersion(),
                ),
            );
        }
        else
        {
            // The installer offering another version than the chosen one
            // fails there, before anything is taken.
            $outcome = $this->appUpdater()
                            ->update( $this->appId(), $channel === Release::NIGHTLY, $check, $chosen?->version )
            ;

            // The store lists one to take, and the installer offers none:
            // an apps directory it cannot write to, or a git checkout.
            if ( $chosen !== null && $outcome->status === Outcome::NOTHING )
            {
                $outcome = Outcome::failed(
                    sprintf(
                        'the store lists %s, but the server\'s installer offers no update: an apps directory it cannot write to, or a git checkout',
                        $chosen->version,
                    ),
                    true,
                );
            }
        }

        return new Report( $store, $channel, $flagged !== null, $enabled, $check, $outcome );
    }

    private function flaggedChannel( InputInterface $input ): ?string
    {
        return match ( true )
        {
            (bool) $input->getOption( 'stable' )  => Release::STABLE,
            (bool) $input->getOption( 'nightly' ) => Release::NIGHTLY,
            default                               => null,
        };
    }

    private function usageError(
        OutputInterface $output,
        string          $message,
    ): int
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error->writeln( '<error>'.$message.'</error>' );

        return Outcome::EXIT_FAILED;
    }
}
