<?php

namespace OCA\EmailBridge\Migration;

use OCP\Migration\IRepairStep;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\Migration\IOutput;

class Uninstall implements IRepairStep {
    
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function getName(): string {
        return 'EmailBridge uninstall';
    }

    public function run(IOutput $output): void {
        $tables = [
            'oc_emailbridge_stats',
            'oc_emailbridge_envoi',
            'oc_emailbridge_inscription',
            'oc_emailbridge_sequence',
            'oc_emailbridge_form',
            'oc_emailbridge_liste',
            'oc_emailbridge_parcours'
        ];

        foreach ($tables as $table) {
            try {
                $this->db->executeStatement("DROP TABLE IF EXISTS `$table`");
                $output->info("Dropped $table");
                $this->logger->info("EmailBridge: dropped $table");
            } catch (\Throwable $e) {
                $output->warning("Error dropping $table: " . $e->getMessage());
                $this->logger->error("EmailBridge error $table: " . $e->getMessage());
            }
        }
    }
}
