<?php
declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\EmailBridge\Defaults;

class MessageController extends Controller {

    private IDBConnection $db;

    public function __construct(string $appName, IRequest $request, IDBConnection $db) {
        parent::__construct($appName, $request);
        $this->db = $db;
    }

    /**
     * Récupère le message email d’un parcours
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getEmailMessage(int $parcoursId): JSONResponse {
        $qb = $this->db->getQueryBuilder();
            try {
            $row = $qb->select('*')
                      ->from('emailbridge_form')
                      ->where($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId)))
                      ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('email')))
                      ->setMaxResults(1)
                      ->executeQuery()
                      ->fetch(\PDO::FETCH_ASSOC);

            // Si aucune donnée, renvoyer valeurs par défaut
	    if (!$row) {
	        $row = [
        	'titre'        => Defaults::confirmationSubject(),
        	'contenu_text' => Defaults::confirmationBody(),
        	'label_bouton' => Defaults::confirmationButton(),
    	        ];
	    }

            return new JSONResponse($row);
        } catch (\Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Erreur serveur: ' . $e->getMessage()
            ], 500);
        }
    }



    /**
     * Sauvegarde (insert/update) un message email pour un parcours
     *
     * @NoAdminRequired
     */
    public function saveEmailMessage(): JSONResponse {
        $parcoursId = (int)$this->request->getParam('parcours_id');
        $titre      = $this->request->getParam('title');
        $contenu    = $this->request->getParam('body');
        $buttonText = $this->request->getParam('button');
        $buttonUrl  = $this->request->getParam('url'); // optionnel

        if (!$parcoursId || !$titre || !$contenu) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Champs obligatoires manquants'
            ], 400);
        }

        $qb = $this->db->getQueryBuilder();
        $existing = $qb->select('id')
                       ->from('emailbridge_form')
                       ->where($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId)))
                       ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('email')))
                       ->executeQuery()
                       ->fetchOne();

$nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

if ($existing) {
    $qb = $this->db->getQueryBuilder();
    $qb->update('emailbridge_form')
       ->set('titre', $qb->createNamedParameter($titre))
       ->set('contenu_text', $qb->createNamedParameter($contenu))
       ->set('label_bouton', $qb->createNamedParameter($buttonText))
       ->set('document_url', $qb->createNamedParameter($buttonUrl))
       ->set('updated_at', $qb->createNamedParameter($nowUtc))
       ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing)))
       ->executeStatement();
} else {
    $qb = $this->db->getQueryBuilder();
    $qb->insert('emailbridge_form')
       ->values([
           'parcours_id'  => $qb->createNamedParameter($parcoursId),
           'titre'        => $qb->createNamedParameter($titre),
           'contenu_text' => $qb->createNamedParameter($contenu),
           'label_bouton' => $qb->createNamedParameter($buttonText),
           'document_url' => $qb->createNamedParameter($buttonUrl),
           'type'         => $qb->createNamedParameter('email'),
           'created_at'   => $qb->createNamedParameter($nowUtc),
           'updated_at'   => $qb->createNamedParameter($nowUtc),
       ])->executeStatement();
}


        return new JSONResponse(['status' => 'ok']);
    }

}
