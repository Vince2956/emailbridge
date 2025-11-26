<?php

namespace OCA\EmailBridge\Migration;

use OCP\Migration\IRepairStep;
use OCP\IDBConnection;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\Migration\IOutput;

class Uninstall implements IRepairStep {
    
    private IDBConnection $db;
    private LoggerInterface $logger;
    private IConfig $config;

    public function __construct(IDBConnection $db, IConfig $config, LoggerInterface $logger) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function getName(): string {
        return 'EmailBridge uninstall';
    }

    public function run(IOutput $output): void {

        /** ------------------------------
         *  1) SUPPRESSION DES TABLES
         * ------------------------------- */
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
                $this->logger->error("EmailBridge error dropping $table: " . $e->getMessage());
            }
        }

        /** ------------------------------
         *  2) SUPPRESSION DES APPCONFIG
         * ------------------------------- */
        try {
            $this->config->deleteApp('emailbridge');
            $output->info("AppConfig cleaned");
            $this->logger->info("EmailBridge: removed appconfig entries");
        } catch (\Throwable $e) {
            $output->warning("Error cleaning appconfig: " . $e->getMessage());
            $this->logger->error("EmailBridge error appconfig: " . $e->getMessage());
        }

        /** ------------------------------
         *  3) SUPPRESSION DES MIGRATIONS
         * ------------------------------- */
        try {
            $this->db->executeStatement(
                "DELETE FROM `*PREFIX*migrations` WHERE `app` = ?",
                ['emailbridge']
            );
            $output->info("Migration entries removed");
            $this->logger->info("EmailBridge: removed migrations entries");
        } catch (\Throwable $e) {
            $output->warning("Error cleaning migrations: " . $e->getMessage());
            $this->logger->error("EmailBridge error migrations: " . $e->getMessage());
        }

        /** ------------------------------
         *  4) SUPPRESSION DES JOBS (oc_jobs)
         * ------------------------------- */
        try {
            $this->db->executeStatement(
                "DELETE FROM `*PREFIX*jobs` 
                 WHERE `class` LIKE '%emailbridge%' 
                    OR `argument` LIKE '%emailbridge%'"
            );
            $output->info("Jobs cleaned");
            $this->logger->info("EmailBridge: removed job queue entries");
        } catch (\Throwable $e) {
            $output->warning("Error cleaning jobs: " . $e->getMessage());
            $this->logger->error("EmailBridge error jobs: " . $e->getMessage());
        }

        $output->info("EmailBridge uninstall complete");
    }
}
