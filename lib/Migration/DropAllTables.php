<?php

namespace OCA\EmailBridge\Migration;

use OCP\IRepairStep;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class DropAllTables implements IRepairStep {

    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger) {
        $this->db     = $db;
        $this->logger = $logger;
    }

    public function getName() {
        return 'EmailBridge: Drop all database tables on uninstall';
    }

    public function run(IOutput $output) {
        $tables = [
            'oc_emailbridge_stats',
            'oc_emailbridge_envoi',
            'oc_emailbridge_inscription',
            'oc_emailbridge_sequence',
            'oc_emailbridge_form',
            'oc_emailbridge_liste',
            'oc_emailbridge_parcours',
        ];

        $this->logger->warning("EmailBridge: uninstall triggered");

        foreach ($tables as $table) {
            try {
                $this->db->executeStatement("DROP TABLE IF EXISTS `$table`");
                $output->info("Dropped table $table");
                $this->logger->warning("Dropped table: $table");
            } catch (\Throwable $e) {
                $this->logger->error("Error dropping $table: " . $e->getMessage());
            }
        }
    }
}
