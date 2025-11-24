<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use Psr\Log\LoggerInterface;
use OCP\IUserSession;

class PageController extends Controller
{
    private IDBConnection $db;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private IUserSession $userSession;


    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->userSession = $userSession;
    }

    /**
     * Page principale : liste des parcours
     */

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $this->logger->debug('PageController index appelé');

        \OCP\Util::addScript('emailbridge', 'emailbridge-main');
        \OCP\Util::addStyle('emailbridge', 'emailbridge-main');

        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new TemplateResponse($this->appName, 'index', [
                    'parcoursData' => [],
                    'createParcoursUrl' => '',
                ]);
            }
            $userId = $user->getUID();

            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'titre', 'created_at', 'document_url', 'bypass_file')
               ->from('emailbridge_parcours')
                   ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->orderBy('id', 'ASC');

            $result = $qb->executeQuery();
            $parcours = [];
            while ($row = $result->fetch()) {
                $parcours[] = [
                    'id' => $row['id'],
                'titre' => $row['titre'],
                    'created_at' => $row['created_at'],
            'document_url' => $row['document_url'],
            'bypass_file' => (int)$row['bypass_file'],
                ];
            }


            $this->logger->debug('Parcours récupérés: ' . print_r($parcours, true));

            return new TemplateResponse($this->appName, 'index', [
                'parcoursData' => $parcours,
                'createParcoursUrl' => $this->urlGenerator->linkToRoute('emailbridge.page.createParcours')
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur index PageController: ' . $e->getMessage());
            return new TemplateResponse($this->appName, 'index', [
                'parcoursData' => [],
                'createParcoursUrl' => ''
            ]);
        }
    }

    /**
     * Créer un nouveau parcours
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createParcours(): DataResponse
    {
        $titre = $this->request->getParam('titre');
        if (!$titre) {
            $this->logger->debug('Titre manquant lors de la création de parcours');
            return new DataResponse(['status' => 'error', 'message' => 'Titre manquant'], 400);
        }
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté'], 403);
        }
        $userId = $user->getUID();

        try {
            // Initialisation du QueryBuilder (corrigé)
            $qb = $this->db->getQueryBuilder();

            $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s');

            $qb->insert('emailbridge_parcours')
                ->values([
                    'titre'      => $qb->createNamedParameter($titre),
                    'created_at' => $qb->createNamedParameter($nowUtc),
                    'updated_at' => $qb->createNamedParameter($nowUtc),
            'user_id'    => $qb->createNamedParameter($userId),
                ])
                ->executeStatement();

            $parcoursId = $this->db->lastInsertId('*PREFIX*emailbridge_parcours');
            $this->logger->debug("Nouveau parcours créé: $titre (ID $parcoursId)");

            return new DataResponse([
                'status' => 'ok',
                'message' => 'Parcours créé',
                'parcours' => [
                    'id' => $parcoursId,
                    'titre' => $titre,
                    'created_at' => $nowUtc
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur createParcours: ' . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }


    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function testSubmit(): DataResponse
    {
        $email = $this->request->getParam('email');

        if (!$email) {
            return new DataResponse(['status' => 'error', 'message' => 'Email manquant'], 400);
        }

        // Ici tu pourrais déclencher un envoi ou juste un log
        $this->logger->info("Test formulaire reçu: $email");

        return new DataResponse(['status' => 'ok', 'email' => $email]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveFile(int $id): DataResponse
    {
        // Récupération du paramètre document_url depuis la requête
        $documentUrl = $this->request->getParam('document_url');

        if (!$documentUrl) {
            $this->logger->warning("saveFile: paramètre document_url manquant pour id=$id");
            return new DataResponse(['status' => 'error', 'message' => 'Fichier manquant'], 400);
        }

        $this->logger->debug("saveFile appelé pour id=$id, document_url=$documentUrl");

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('emailbridge_parcours')
               ->set('document_url', $qb->createNamedParameter($documentUrl))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
               ->executeStatement();

            return new DataResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            $this->logger->error("Erreur saveFile pour id=$id : " . $e->getMessage(), ['exception' => $e]);
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteParcours(int $parcoursId)
    {
        if ($parcoursId === null) {
            return ['status' => 'error', 'message' => 'ParcoursId non reçu'];
        }
        $parcoursId = (int)$parcoursId;
        $conn = $this->db;

        try {
            // Démarrer la transaction
            $conn->beginTransaction();

            // Suppression du parcours
            // Les formulaires, inscriptions, séquences et envois liés seront supprimés automatiquement
            // grâce aux clés étrangères avec ON DELETE CASCADE
            $qb = $conn->getQueryBuilder();
            $qb->delete('emailbridge_parcours')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId)));
            $deletedParcours = $qb->executeStatement();

            // Commit de la transaction
            $conn->commit();

            if ($deletedParcours > 0) {
                $this->logger->info("Parcours $parcoursId supprimé avec succès", ['app' => 'emailbridge']);
                return ['status' => 'ok'];
            } else {
                $this->logger->warning("Aucun parcours trouvé pour suppression ID=$parcoursId", ['app' => 'emailbridge']);
                return ['status' => 'error', 'message' => 'Parcours introuvable'];
            }

        } catch (\Throwable $e) {
            // Rollback sécurisé : on ignore si aucune transaction n'est active
            try {
                $conn->rollBack();
            } catch (\Throwable $rollbackEx) {
                // Pas de transaction active, on ignore
            }

            $this->logger->error("Erreur suppression parcours $parcoursId: ".$e->getMessage(), ['app' => 'emailbridge']);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }



    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function updateBypass(int $id): DataResponse
    {
        $bypass = $this->request->getParam('bypass_file');

        if (!isset($bypass)) {
            return new DataResponse(['status' => 'error', 'message' => 'Paramètre bypass_file manquant'], 400);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('emailbridge_parcours')
               ->set('bypass_file', $qb->createNamedParameter((int)$bypass))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
               ->executeStatement();

            $this->logger->debug("Bypass fichier cible mis à jour pour parcours $id: " . $bypass);

            return new DataResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            $this->logger->error("Erreur updateBypass pour parcours $id: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[NoCSRFRequired]
    #[PublicPage]
    public function confirm_pending(): TemplateResponse
    {
        return new TemplateResponse('emailbridge', 'confirm_pending');
    }



#[NoAdminRequired]
#[NoCSRFRequired]
public function resetLine(int $inscriptionId): DataResponse {
    try {
        // 1) Récupérer le liste_id associé
        $qb = $this->db->getQueryBuilder();
        $qb->select('liste_id')
           ->from('emailbridge_inscription')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($inscriptionId)));

        $listeId = $qb->executeQuery()->fetchOne();

        if (!$listeId) {
            throw new \Exception("Aucune inscription trouvée");
        }

        // 2) Supprimer dans la table liste
        $qb2 = $this->db->getQueryBuilder();
        $qb2->delete('emailbridge_liste')
            ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($listeId)))
            ->executeStatement();

        return new DataResponse(['status' => 'ok']);
    } catch (\Throwable $e) {
        return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

}
