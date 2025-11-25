<?php

// Respecter l’option "Supprimer les données"
if (!empty($_['keepData']) && $_['keepData'] === true) {
    return;
}

$tables = [
    'oc_emailbridge_stats',
    'oc_emailbridge_envoi',
    'oc_emailbridge_inscription',
    'oc_emailbridge_sequence',
    'oc_emailbridge_form',
    'oc_emailbridge_liste',
    'oc_emailbridge_parcours'
];

$connection = \OC::$server->getDatabaseConnection();
$logger     = \OC::$server->getLogger();

$logger->warning("EmailBridge uninstall.php : suppression des données");

foreach ($tables as $table) {
    try {
        $connection->executeStatement("DROP TABLE IF EXISTS `$table`");
        $logger->warning("EmailBridge : table supprimée → $table");
    } catch (\Throwable $e) {
        $logger->error("EmailBridge : erreur DROP $table → " . $e->getMessage());
    }
}
