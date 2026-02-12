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

    // ---------------------------------------------------------
    // 1) Vérifier si l’app est encore installée
    // ---------------------------------------------------------
    if ($this->config->getAppValue('emailbridge', 'installed_version', null) !== null) {
        // L’app est encore installée → on ne touche à rien
        $output->info('EmailBridge still installed - skipping cleanup');
        return;
    }

    // ---------------------------------------------------------
    // 2) Vérifier si l’admin a coché la suppression
    // ---------------------------------------------------------
    $delete = $this->config->getAppValue(
        'emailbridge',
        'delete_on_uninstall',
        '0'
    );

    if ($delete !== '1') {
        $output->info('EmailBridge uninstall without data deletion - skipping');
        return;
    }

    // ---------------------------------------------------------
    // 3) Suppression des tables
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
            $this->db->executeStatement(
                "DROP TABLE IF EXISTS *PREFIX*$table"
            );
            $output->info("Dropped $table");
        } catch (\Throwable $e) {
            $output->warning("Error dropping $table: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------
    // 4) Nettoyage config
    // ---------------------------------------------------------
    foreach ($this->config->getAppKeys('emailbridge') as $key) {
        $this->config->deleteAppValue('emailbridge', $key);
    }

    $output->info('EmailBridge uninstall cleanup complete');
}

}
