<?php

declare(strict_types=1);

namespace OCA\EmailBridge\AppInfo;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\EmailBridge\BackgroundJob\EmailSenderJob;

class StartupCheck
{
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function checkBackgroundJob(): void
    {
        $config = \OC::$server->getSystemConfig();
        $jobList = \OC::$server->getJobList();

        // Enregistrer EmailSenderJob si nécessaire
        if (!$jobList->has(EmailSenderJob::class, [])) {
            $this->logger->info("EmailBridge: EmailSenderJob not registered. Registering automatically.");
            $jobList->add(EmailSenderJob::class, []);
        }

        $mode = $config->getValue('backgroundjobs_mode', null);

        // Si le mode est cron, on vérifie quand cron a tourné
        if ($mode === 'cron') {
            $lastCron = (int)$config->getValue('cron_last_run', 0);
            $diffMinutes = (time() - $lastCron) / 60;

            if ($diffMinutes > 30) {
                $this->logger->warning(
                    "EmailBridge: Background job mode is 'cron' but cron has not run in the last 30 minutes."
                );
            }

        // On ne logue plus de warning si le mode n'est pas défini (par défaut)
        } elseif ($mode !== null && $mode !== 'cron') {
            $this->logger->warning(
                "EmailBridge: Background job mode is not set to 'cron'. Please configure it in Nextcloud."
            );
        }
    }
}

