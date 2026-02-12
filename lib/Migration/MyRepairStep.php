<?php

namespace OCA\EmailBridge\Migration;

use OCP\Migration\IRepairStep;
use OCP\Migration\IOutput;
use OCP\IDBConnection;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class MyRepairStep implements IRepairStep {

    private IDBConnection $db;
    private IConfig $config;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, IConfig $config, LoggerInterface $logger) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function getName(): string {
        return 'EmailBridge uninstall cleanup';
    }

    public function run(IOutput $output): void {

        // ---------------------------------------------------------
        // 1) Vérifier si l’admin a coché la suppression
        // ---------------------------------------------------------
        $delete = $this->config->getAppValue('emailbridge', 'delete_on_uninstall', '0');
        if ($delete !== '1') {
            $output->info('EmailBridge uninstall without data deletion - skipping');
            return;
        }

        $output->info('EmailBridge uninstall with data deletion - starting cleanup');

        // ---------------------------------------------------------
        // 2) Suppression des tables
        // ---------------------------------------------------------
        $tables = [
            'emailbridge_stats',
            'emailbridge_envoi',
            'emailbridge_inscription',
            'emailbridge_sequence',
            'emailbridge_form',
            'emailbridge_liste',
            'emailbridge_parcours'
        ];

        foreach ($tables as $table) {
            try {
                $this->db->executeStatement("DROP TABLE IF EXISTS *PREFIX*$table");
                $output->info("Dropped $table");
                $this->logger->info("EmailBridge: dropped $table");
            } catch (\Throwable $e) {
                $output->warning("Error dropping $table: " . $e->getMessage());
                $this->logger->error("EmailBridge error dropping $table: " . $e->getMessage());
            }
        }

        // ---------------------------------------------------------
        // 3) Suppression des jobs (oc_jobs)
        // ---------------------------------------------------------
        try {
            $this->db->executeStatement(
                "DELETE FROM *PREFIX*jobs WHERE class LIKE '%emailbridge%' OR argument LIKE '%emailbridge%'"
            );
            $output->info("Jobs removed");
            $this->logger->info("EmailBridge: job entries removed");
        } catch (\Throwable $e) {
            $output->warning("Error removing jobs: " . $e->getMessage());
            $this->logger->error("EmailBridge error removing jobs: " . $e->getMessage());
        }

        // ---------------------------------------------------------
        // 4) Nettoyage config
        // ---------------------------------------------------------
        try {
            foreach ($this->config->getAppKeys('emailbridge') as $key) {
                $this->config->deleteAppValue('emailbridge', $key);
            }
            $output->info('AppConfig cleaned');
            $this->logger->info('EmailBridge: AppConfig cleaned');
        } catch (\Throwable $e) {
            $output->warning("AppConfig cleanup error: " . $e->getMessage());
            $this->logger->error("EmailBridge AppConfig cleanup error: " . $e->getMessage());
        }

        $output->info('EmailBridge uninstall cleanup complete');
        $this->logger->info('EmailBridge uninstall cleanup complete');
    }
}

