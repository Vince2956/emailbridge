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
        $mode = \OC::$server->getSystemConfig()->getValue('backgroundjobs_mode', 'ajax');

        if ($mode !== 'cron') {
            $this->logger->warning("EmailBridge: Background job mode is not set to 'cron'. Please configure it in Nextcloud.");
        }

        $jobList = \OC::$server->getJobList();
        if (!$jobList->has(EmailSenderJob::class, [])) {
            $this->logger->info("EmailBridge: EmailSenderJob not registered. Registering automatically.");
            $jobList->add(EmailSenderJob::class, []);
        }
    }
}
