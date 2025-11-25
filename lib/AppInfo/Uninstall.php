<?php

namespace OCA\EmailBridge\AppInfo;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Classe appelée par registerUninstallClass()
 */
class Uninstall
{
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Exécuté AVANT appinfo/uninstall.php
     */
    public function run(array $options): void
    {
        $keepData = $options['keepData'] ?? false;

        if ($keepData) {
            $this->logger->info("EmailBridge uninstall: keepData=true → aucune suppression");
            return;
        }

        $this->logger->warning("EmailBridge uninstall: déclenché (classe Uninstall)");
    }
}
