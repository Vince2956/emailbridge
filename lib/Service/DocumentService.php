<?php
declare(strict_types=1);

namespace OCA\EmailBridge\Service;

use OCP\IDbConnection;

class DocumentService {
    private IDbConnection $db;

    public function __construct(IDbConnection $db) {
        $this->db = $db;
    }

    /**
     * Récupère tous les parcours avec leurs étapes
     */
    public function getAllParcours(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('parcours_id', 'type', 'contenu_text', 'document_url', 'ordre')
            ->from('etapesbridge')
            ->orderBy('parcours_id', 'ASC')
            ->addOrderBy('ordre', 'ASC');

        $result = $qb->executeQuery();

        $parcours = [];
        while ($row = $result->fetch()) {
            $contenu = null;
            if ($row['type'] === 'document') {
                $contenu = $row['document_url'];
            } else {
                $contenu = $row['contenu_text'];
            }

            $parcours[$row['parcours_id']][] = [
                'type' => $row['type'],
                'contenu' => $contenu,
                'ordre' => $row['ordre'],
            ];
        }

        return $parcours;
    }
}
