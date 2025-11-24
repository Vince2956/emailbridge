<?php
namespace OCA\EmailBridge\Listeners;

use OCP\App\Events\AppUninstalledEvent;
use Psr\Log\LoggerInterface;
use OCP\DB\IDBConnection;

class UninstallListener {

    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(IDBConnection $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function __invoke(AppUninstalledEvent $event): void {
        $this->logger->warning('EmailBridge: uninstall event received');

        if ($event->isPreserveData()) {
            $this->logger->info('EmailBridge: preserveData=true, nothing to drop');
            return;
        }

        // Ici tu drop les tables
        $tables = [
            'oc_emailbridge_parcours',
            'oc_emailbridge_liste',
            'oc_emailbridge_inscription',
            'oc_emailbridge_sequence',
            'oc_emailbridge_envoi',
            'oc_emailbridge_stats',
            'oc_emailbridge_form'
        ];

        foreach ($tables as $table) {
            try {
                $this->db->exec("DROP TABLE IF EXISTS $table");
                $this->logger->warning("EmailBridge: table $table dropped");
            } catch (\Exception $e) {
                $this->logger->error("EmailBridge: error dropping $table - ".$e->getMessage());
            }
        }
    }
}
