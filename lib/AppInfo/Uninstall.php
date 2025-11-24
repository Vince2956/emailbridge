<?php

namespace OCA\EmailBridge\AppInfo;

use OCP\Migration\IOutput;
use OCP\Migration\IInstaller;

/**
 * Suppression complète des données EmailBridge lors de la désinstallation.
 * Fonctionne UNIQUEMENT si l'admin coche "Supprimer les données".
 */
class Uninstall {

    public function __invoke(IOutput $output, \Closure $next) {

        /** @var IInstaller $installer */
        $installer = \OC::$server->query(IInstaller::class);

        $output->info("EmailBridge — Suppression des tables avec gestion des FK…");

        // IMPORTANT :
        // Ordre inverse de dépendance :
        // stats → envoi → form → sequence → inscription → liste → parcours

        $tables = [
            'emailbridge_stats',
            'emailbridge_envoi',
            'emailbridge_form',
            'emailbridge_sequence',
            'emailbridge_inscription',
            'emailbridge_liste',
            'emailbridge_parcours',
        ];

        foreach ($tables as $table) {
            try {
                $installer->dropTable($table);
                $output->info("✔ Table {$table} supprimée.");
            } catch (\Throwable $e) {
                // Nextcloud continue même si une table n’existe pas
                $output->warning("⚠ Impossible de supprimer {$table} : " . $e->getMessage());
            }
        }

        $output->info("EmailBridge — Suppression terminée.");
        return $next($output);
    }
}
