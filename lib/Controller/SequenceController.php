<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;
use OCA\EmailBridge\Service\SequenceManagementService;

class SequenceController extends Controller
{
    private IDBConnection $db;
    private SequenceManagementService $sequenceService;
    private LoggerInterface $logger;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        LoggerInterface $logger,
        SequenceManagementService $sequenceService,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->logger = $logger;
        $this->sequenceService = $sequenceService;
        $this->userSession = $userSession;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getSequence(int $parcoursId): DataResponse
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('emailbridge_sequence')
           ->where($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId)));

        $result = $qb->executeQuery()->fetchAll(\PDO::FETCH_ASSOC);

        $resultWithStats = [];
        foreach ($result as $email) {
            $email['stats'] = $this->sequenceService->getEmailStats($email['id']);
            $resultWithStats[] = $email;
        }

        return new DataResponse(['status' => 'ok', 'emails' => $resultWithStats]);
    }


    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAllParcours(): DataResponse
    {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté.'], 403);
            }
            $userId = $user->getUID();

            $qb = $this->db->getQueryBuilder();
            $rows = $qb->select('id', 'titre')
                       ->from('*PREFIX*emailbridge_parcours')
                       ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                       ->executeQuery()
                       ->fetchAll(\PDO::FETCH_ASSOC);

            return new DataResponse(['status' => 'ok', 'parcours' => $rows]);

        } catch (\Throwable $e) {
            $this->logger->error('Erreur getAllParcours: ' . $e->getMessage() . ' / ' . $e->getTraceAsString());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }



#[NoAdminRequired]
#[NoCSRFRequired]
public function addEmail(int $parcoursId): DataResponse
{
    $data = $this->request->getParams();
    $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s');

    // ===============================
    // Normalisation des champs
    // ===============================
    $sendDay = (int) ($data['send_day'] ?? 0);
    $delayMinutes = isset($data['delay_minutes']) && is_numeric($data['delay_minutes'])
        ? (int) $data['delay_minutes']
        : 0;

    // Règle métier : si J > 0, le délai n’a pas de sens
    if ($sendDay > 0) {
        $delayMinutes = 0;
    }

    try {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('emailbridge_sequence')
           ->values([
               'parcours_id'   => $qb->createNamedParameter($parcoursId),
               'sujet'         => $qb->createNamedParameter($data['sujet'] ?? 'Nouvel email'),
               'contenu'       => $qb->createNamedParameter(json_encode($data['contenu'] ?? [], JSON_THROW_ON_ERROR)),
               'send_day'      => $qb->createNamedParameter($sendDay),
               'send_time'     => $qb->createNamedParameter($data['send_time'] ?? null),
               'delay_minutes' => $qb->createNamedParameter($delayMinutes),
               'created_at'    => $qb->createNamedParameter($nowUtc),
               'updated_at'    => $qb->createNamedParameter($nowUtc),
           ])
           ->executeStatement();

        $emailId = $this->db->lastInsertId('*PREFIX*emailbridge_sequence');

        return new DataResponse([
            'status' => 'ok',
            'id' => $emailId
        ]);

    } catch (\Throwable $e) {
        $this->logger->error('Erreur addEmail: ' . $e->getMessage());
        return new DataResponse([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
}


    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteEmail(int $id): DataResponse
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('emailbridge_sequence')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
            $rows = $qb->executeStatement();

            if ($rows > 0) {
                return new DataResponse(['status' => 'ok']);
            } else {
                return new DataResponse(['status' => 'error', 'message' => 'Email introuvable'], 404);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Erreur deleteEmail: ' . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

#[NoAdminRequired]
#[NoCSRFRequired]
public function editEmail(int $parcoursId, int $emailId): DataResponse
{
    $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $data = [
        'sujet'         => $this->request->getParam('sujet'),
        'contenu'       => json_encode($this->request->getParam('contenu') ?? [], JSON_THROW_ON_ERROR),
        'send_day'      => (int) $this->request->getParam('send_day'),
        'send_time'     => $this->request->getParam('send_time'),
        'delay_minutes' => null !== $this->request->getParam('delay_minutes') 
    	    ? (int) $this->request->getParam('delay_minutes') 
            : 0,
        'updated_at'    => $nowUtc,
    ];

    // Règle métier : si send_day > 0, delay_minutes = 0
    if ($data['send_day'] > 0) {
        $data['delay_minutes'] = 0;
    }

    // ✅ Gestion des règles si présentes
    $rulesParam = $this->request->getParam('rules');
    if ($rulesParam !== null) {
        $data['rules'] = is_string($rulesParam) ? $rulesParam : json_encode($rulesParam, JSON_THROW_ON_ERROR);
    }

    try {
        $qb = $this->db->getQueryBuilder();
        $qb->update('emailbridge_sequence')
           ->set('sujet', $qb->createNamedParameter($data['sujet']))
           ->set('contenu', $qb->createNamedParameter($data['contenu']))
           ->set('send_day', $qb->createNamedParameter($data['send_day']))
           ->set('send_time', $qb->createNamedParameter($data['send_time']))
           ->set('delay_minutes', $qb->createNamedParameter($data['delay_minutes']))
           ->set('updated_at', $qb->createNamedParameter($data['updated_at']));

        if (isset($data['rules'])) {
            $qb->set('rules', $qb->createNamedParameter($data['rules']));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($emailId)))
           ->andWhere($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId)))
           ->executeStatement();

        return new DataResponse([
            'status' => 'ok',
            'id' => $emailId
        ]);

    } catch (\Throwable $e) {
        $this->logger->error('Erreur editEmail: ' . $e->getMessage());
        return new DataResponse([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
}



    /**
     * Stoppe tous les envois “en attente” pour une inscription
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function stopAllSequence(int $inscriptionId): JSONResponse
    {
        if (!$inscriptionId) {
            return new JSONResponse(['status' => 'error', 'message' => 'ID inscription manquant'], 400);
        }

        try {
            $success = $this->sequenceService->stopAllSequence($inscriptionId);

            if ($success) {
                return new JSONResponse([
                    'status' => 'ok',
                    'inscription_id' => $inscriptionId,
                    'message' => 'Toutes les séquences en attente ont été stoppées.'
                ]);
            } else {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Impossible de stopper la séquence'
                ], 500);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Erreur stopAllSequence: ' . $e->getMessage());
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stoppe un envoi unique pour une inscription (une seule séquence)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function stopSingleSequence(int $inscriptionId, int $sequenceId): JSONResponse
    {
        if (!$inscriptionId || !$sequenceId) {
            return new JSONResponse(['status' => 'error', 'message' => 'ID inscription ou sequence manquant'], 400);
        }

        try {
            $success = $this->sequenceService->stopSingleSequence($inscriptionId, $sequenceId);

            if ($success) {
                return new JSONResponse([
                    'status' => 'ok',
                    'inscription_id' => $inscriptionId,
                    'sequence_id' => $sequenceId,
                    'message' => 'La séquence spécifique a été stoppée.'
                ]);
            } else {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Impossible de stopper la séquence'
                ], 500);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Erreur stopSingleSequence: ' . $e->getMessage());
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Redirige une inscription vers une autre séquence
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function redirectInscription(int $inscriptionId): DataResponse
    {
        try {
            $parcoursId = (int)$this->request->getParam('parcoursId');
            if ($parcoursId <= 0) {
                return new DataResponse(['status' => 'error', 'message' => 'Parcours invalide.']);
            }

            // ✅ Vérifier le user_id du parcours
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté.'], 403);
            }
            $userId = $user->getUID();

            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('emailbridge_parcours')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $row = $qb->executeQuery()->fetch();

            if (!$row) {
                $this->logger->warning("Tentative d’accès à un parcours non autorisé: $parcoursId par $userId");
                return new DataResponse(['status' => 'error', 'message' => 'Parcours non autorisé.'], 403);
            }

            $success = $this->sequenceService->redirectInscription($inscriptionId, $parcoursId);

            return new DataResponse([
                'status' => $success ? 'ok' : 'error',
                'message' => $success ? 'Inscription redirigée avec succès.' : 'Échec de la redirection.'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur redirectInscription: ' . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }



    /**
     * Récupère toutes les inscriptions d’un parcours avec leurs envois
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getInscriptions(int $parcoursId): DataResponse
    {
        try {
            $inscriptions = $this->sequenceService->getInscriptionsByParcours($parcoursId);
            return new DataResponse([
                'status' => 'ok',
                'inscriptions' => $inscriptions
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur getInscriptions: ' . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function redirectSequence(int $inscriptionId): DataResponse
    {
        try {
            $newParcoursId = (int) $this->request->getParam('parcoursId');
            if ($newParcoursId <= 0) {
                return new DataResponse(['status' => 'error', 'message' => 'Parcours invalide.']);
            }

            // ✅ Vérifier le user_id du parcours cible
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté.'], 403);
            }
            $userId = $user->getUID();

            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('emailbridge_parcours')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($newParcoursId)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $row = $qb->executeQuery()->fetch();

            if (!$row) {
                $this->logger->warning("Tentative de redirection vers un parcours non autorisé: $newParcoursId par $userId");
                return new DataResponse(['status' => 'error', 'message' => 'Parcours non autorisé.'], 403);
            }

            $this->sequenceService->redirectInscription($inscriptionId, $newParcoursId);

            return new DataResponse(['status' => 'ok', 'message' => 'Redirection effectuée avec succès.']);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur redirectSequence: ' . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }


    /**
     * Récupère les règles d’un email (dans une séquence)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getEmailRules(int $parcoursId, int $emailId): JSONResponse
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('rules')
           ->from('emailbridge_sequence')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($emailId)))
           ->andWhere($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId)));

        $result = $qb->executeQuery()->fetch();

        $rules = [];
        if ($result && !empty($result['rules'])) {
            $decoded = json_decode($result['rules'], true);
            if (is_array($decoded)) {
                $rules = $decoded;
            }
        }

        return new JSONResponse([
            'status' => 'ok',
            'rules' => $rules
        ]);
    }


    /**
     * Sauvegarde les règles d’un email (dans une séquence)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function saveEmailRules(int $parcoursId, int $emailId): JSONResponse
    {
        $rulesJson = $this->request->getParam('rules');

        if (empty($rulesJson)) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Aucune règle reçue'
            ], 400);
        }

        if (is_array($rulesJson)) {
            $rulesJson = json_encode($rulesJson);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('emailbridge_sequence')
           ->set('rules', $qb->createNamedParameter($rulesJson))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($emailId)))
           ->andWhere($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId)));

        try {
            $qb->executeStatement();
        } catch (\Throwable $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Erreur lors de la sauvegarde : ' . $e->getMessage()
            ], 500);
        }

        return new JSONResponse([
            'status' => 'ok',
            'rules' => json_decode($rulesJson, true)
        ]);
    }


}
