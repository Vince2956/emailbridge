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
        $this->db    = $db;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function getName(): string {
        return 'EmailBridge uninstall cleanup';
    }

    public function run(IOutput $output): void {

        /** ----------------------------------------------------------------
         * 1) Suppression manuelle d’éléments non gérés par database.xml
         * ---------------------------------------------------------------- */
        $tables = [
            '*PREFIX*emailbridge_stats',
            '*PREFIX*emailbridge_envoi',
            '*PREFIX*emailbridge_inscription',
            '*PREFIX*emailbridge_sequence',
            '*PREFIX*emailbridge_form',
            '*PREFIX*emailbridge_liste',
            '*PREFIX*emailbridge_parcours'
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

        /** ----------------------------------------------------------------
         * 2) Suppression AppConfig
         * ---------------------------------------------------------------- */
        try {
            foreach ($this->config->getAppKeys('emailbridge') as $key) {
                $this->config->deleteAppValue('emailbridge', $key);
            }
            $output->info("AppConfig cleaned");
            $this->logger->info("EmailBridge: appconfig cleaned");
        } catch (\Throwable $e) {
            $output->warning("AppConfig error: " . $e->getMessage());
            $this->logger->error("EmailBridge appconfig error: " . $e->getMessage());
        }

        /** ----------------------------------------------------------------
         * 3) Suppression des entrées de migration
         * ---------------------------------------------------------------- */
        try {
            $this->db->executeStatement(
                "DELETE FROM `*PREFIX*migrations` WHERE `app` = ?",
                ['emailbridge']
            );
            $output->info("Migrations removed");
            $this->logger->info("EmailBridge: migration entries removed");
        } catch (\Throwable $e) {
            $output->warning("Migration cleanup error: " . $e->getMessage());
            $this->logger->error("EmailBridge migration cleanup error: " . $e->getMessage());
        }

        /** ----------------------------------------------------------------
         * 4) Suppression des jobs (oc_jobs)
         * ---------------------------------------------------------------- */
        try {
            $this->db->executeStatement(
                "DELETE FROM `*PREFIX*jobs`
                 WHERE `class` LIKE '%emailbridge%'
                 OR `argument` LIKE '%emailbridge%'"
            );
            $output->info("Jobs removed");
            $this->logger->info("EmailBridge: job entries removed");
        } catch (\Throwable $e) {
            $output->warning("Jobs cleanup error: " . $e->getMessage());
            $this->logger->error("EmailBridge jobs cleanup error: " . $e->getMessage());
        }

        $output->info("EmailBridge uninstall cleanup complete");
    }
}
