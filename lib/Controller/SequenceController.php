<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\EmailBridge\Service\SequenceManagementService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

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

    /**
     * Retourne l'utilisateur courant ou lance une DataResponse d'erreur
     */
    private function getCurrentUser(): ?string
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }
        return $user->getUID();
    }

    /**
     * Vérifie qu'un parcours appartient à l'utilisateur courant
     */
    private function checkParcoursOwner(int $parcoursId, string $userId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('emailbridge_parcours')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return (bool)$qb->executeQuery()->fetch();
    }

    /**
     * Vérifie qu'un email appartient à un parcours de l'utilisateur
     */
    private function checkEmailOwner(int $parcoursId, int $emailId, string $userId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.id')
           ->from('emailbridge_sequence', 's')
           ->innerJoin('s', 'emailbridge_parcours', 'p', 's.parcours_id = p.id')
           ->where($qb->expr()->eq('s.id', $qb->createNamedParameter($emailId)))
           ->andWhere($qb->expr()->eq('s.parcours_id', $qb->createNamedParameter($parcoursId)))
           ->andWhere($qb->expr()->eq('p.user_id', $qb->createNamedParameter($userId)));

        return (bool)$qb->executeQuery()->fetch();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getSequence(int $parcoursId): DataResponse
    {
        $userId = $this->getCurrentUser();
        if (!$userId) {
            return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté'], 403);
        }

        if (!$this->checkParcoursOwner($parcoursId, $userId)) {
            return new DataResponse(['status' => 'error', 'message' => 'Non autorisé'], 403);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('s.*')
           ->from('emailbridge_sequence', 's')
           ->where($qb->expr()->eq('s.parcours_id', $qb->createNamedParameter($parcoursId)));

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
        $userId = $this->getCurrentUser();
        if (!$userId) {
            return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté.'], 403);
        }

        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('id', 'titre')
                   ->from('*PREFIX*emailbridge_parcours')
                   ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                   ->executeQuery()
                   ->fetchAll(\PDO::FETCH_ASSOC);

        return new DataResponse(['status' => 'ok', 'parcours' => $rows]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function addEmail(int $parcoursId): DataResponse
    {
        $userId = $this->getCurrentUser();
        if (!$userId) {
            return new DataResponse(['status' => 'error'], 403);
        }

        if (!$this->checkParcoursOwner($parcoursId, $userId)) {
            return new DataResponse(['status' => 'error', 'message' => 'Accès interdit'], 403);
        }

        $data = $this->request->getParams();
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $sendDay = (int)($data['send_day'] ?? 0);
        $delayMinutes = isset($data['delay_minutes']) && is_numeric($data['delay_minutes']) ? (int)$data['delay_minutes'] : 0;
        if ($sendDay > 0) $delayMinutes = 0;

        $rules = $data['rules'] ?? null;
        if (isset($rules)) {
            $rules = is_string($rules) ? $rules : json_encode($rules, JSON_THROW_ON_ERROR);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('emailbridge_sequence')
               ->values([
                   'parcours_id' => $qb->createNamedParameter($parcoursId),
                   'sujet' => $qb->createNamedParameter($data['sujet'] ?? 'Nouvel email'),
                   'contenu' => $qb->createNamedParameter(json_encode($data['contenu'] ?? [], JSON_THROW_ON_ERROR)),
                   'send_day' => $qb->createNamedParameter($sendDay),
                   'send_time' => $qb->createNamedParameter($data['send_time'] ?? null),
                   'delay_minutes' => $qb->createNamedParameter($delayMinutes),
                   'rules' => $qb->createNamedParameter($rules),
                   'created_at' => $qb->createNamedParameter($nowUtc),
                   'updated_at' => $qb->createNamedParameter($nowUtc),
               ])
               ->executeStatement();

            $emailId = $this->db->lastInsertId('*PREFIX*emailbridge_sequence');

            return new DataResponse(['status' => 'ok', 'id' => $emailId]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur addEmail: ' . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteEmail(int $emailId): DataResponse
    {
        $userId = $this->getCurrentUser();
        if (!$userId) return new DataResponse(['status' => 'error'], 403);

        $qb = $this->db->getQueryBuilder();
        $qb->select('s.parcours_id')->from('emailbridge_sequence', 's')
           ->where($qb->expr()->eq('s.id', $qb->createNamedParameter($emailId)));

        $row = $qb->executeQuery()->fetch();
        if (!$row || !$this->checkParcoursOwner((int)$row['parcours_id'], $userId)) {
            return new DataResponse(['status' => 'error', 'message' => 'Non autorisé'], 403);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('emailbridge_sequence')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($emailId)))
           ->executeStatement();

        return new DataResponse(['status' => 'ok']);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function editEmail(int $parcoursId, int $emailId): DataResponse
    {
        $userId = $this->getCurrentUser();
        if (!$userId) return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté'], 403);

        if (!$this->checkEmailOwner($parcoursId, $emailId, $userId)) {
            return new DataResponse(['status' => 'error', 'message' => 'Non autorisé'], 403);
        }

        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $data = [
            'sujet' => $this->request->getParam('sujet'),
            'contenu' => json_encode($this->request->getParam('contenu') ?? [], JSON_THROW_ON_ERROR),
            'send_day' => (int)$this->request->getParam('send_day'),
            'send_time' => $this->request->getParam('send_time'),
            'delay_minutes' => null !== $this->request->getParam('delay_minutes') ? (int)$this->request->getParam('delay_minutes') : 0,
            'updated_at' => $nowUtc,
        ];

        if ($data['send_day'] > 0) $data['delay_minutes'] = 0;

        $rulesParam = $this->request->getParam('rules');
        if ($rulesParam !== null) {
            $data['rules'] = is_string($rulesParam) ? $rulesParam : json_encode($rulesParam, JSON_THROW_ON_ERROR);
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('emailbridge_sequence', 's')
               ->innerJoin('s', 'emailbridge_parcours', 'p', 's.parcours_id = p.id')
               ->set('s.sujet', $qb->createNamedParameter($data['sujet']))
               ->set('s.contenu', $qb->createNamedParameter($data['contenu']))
               ->set('s.send_day', $qb->createNamedParameter($data['send_day']))
               ->set('s.send_time', $qb->createNamedParameter($data['send_time']))
               ->set('s.delay_minutes', $qb->createNamedParameter($data['delay_minutes']))
               ->set('s.updated_at', $qb->createNamedParameter($data['updated_at']));

            if (isset($data['rules'])) {
                $qb->set('s.rules', $qb->createNamedParameter($data['rules']));
            }

            $qb->where($qb->expr()->eq('s.id', $qb->createNamedParameter($emailId)))
               ->andWhere($qb->expr()->eq('s.parcours_id', $qb->createNamedParameter($parcoursId)))
               ->andWhere($qb->expr()->eq('p.user_id', $qb->createNamedParameter($userId)))
               ->executeStatement();

            return new DataResponse(['status' => 'ok', 'id' => $emailId]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur editEmail: ' . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
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

    return $this->handleSequenceStop(fn() => $this->sequenceService->stopAllSequence($inscriptionId), [
        'inscription_id' => $inscriptionId,
        'message' => 'Toutes les séquences en attente ont été stoppées.'
    ]);
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

    return $this->handleSequenceStop(fn() => $this->sequenceService->stopSingleSequence($inscriptionId, $sequenceId), [
        'inscription_id' => $inscriptionId,
        'sequence_id' => $sequenceId,
        'message' => 'La séquence spécifique a été stoppée.'
    ]);
}

/**
 * Helper interne pour gérer le stop d'une ou plusieurs séquences
 */
private function handleSequenceStop(callable $stopCallback, array $successData): JSONResponse
{
    try {
        $success = $stopCallback();
        if ($success) {
            return new JSONResponse(array_merge(['status' => 'ok'], $successData));
        }
        return new JSONResponse(['status' => 'error', 'message' => 'Impossible de stopper la séquence'], 500);
    } catch (\Throwable $e) {
        $this->logger->error('Erreur handleSequenceStop: ' . $e->getMessage());
        return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

/**
 * Redirige une inscription vers une autre séquence
 */
#[NoAdminRequired]
#[NoCSRFRequired]
public function redirectInscription(int $inscriptionId): DataResponse
{
    return $this->handleRedirect($inscriptionId, 'Inscription redirigée avec succès.');
}

/**
 * Redirection générique (utilisée aussi pour redirectSequence)
 */
#[NoAdminRequired]
#[NoCSRFRequired]
public function redirectSequence(int $inscriptionId): DataResponse
{
    return $this->handleRedirect($inscriptionId, 'Redirection effectuée avec succès.');
}

/**
 * Helper interne pour gérer la redirection d'inscription
 */
private function handleRedirect(int $inscriptionId, string $successMessage): DataResponse
{
    try {
        $parcoursId = (int)$this->request->getParam('parcoursId');
        if ($parcoursId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Parcours invalide.']);
        }

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
        if (!$qb->executeQuery()->fetch()) {
            $this->logger->warning("Tentative de redirection vers un parcours non autorisé: $parcoursId par $userId");
            return new DataResponse(['status' => 'error', 'message' => 'Parcours non autorisé.'], 403);
        }

        $success = $this->sequenceService->redirectInscription($inscriptionId, $parcoursId);

        return new DataResponse([
            'status' => $success ? 'ok' : 'error',
            'message' => $success ? $successMessage : 'Échec de la redirection.'
        ]);

    } catch (\Throwable $e) {
        $this->logger->error('Erreur handleRedirect: ' . $e->getMessage());
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
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['status' => 'error', 'message' => 'Utilisateur non connecté'], 403);
        }
        $userId = $user->getUID();

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('emailbridge_parcours')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        if (!$qb->executeQuery()->fetch()) {
            return new DataResponse(['status' => 'error', 'message' => 'Non autorisé'], 403);
        }

        $inscriptions = $this->sequenceService->getInscriptionsByParcours($parcoursId);
        return new DataResponse(['status' => 'ok', 'inscriptions' => $inscriptions]);

    } catch (\Throwable $e) {
        $this->logger->error('Erreur getInscriptions: ' . $e->getMessage());
        return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

/**
 * Récupère les règles d’un email (dans une séquence)
 */
#[NoAdminRequired]
#[NoCSRFRequired]
public function getEmailRules(int $parcoursId, int $emailId): JSONResponse
{
    $qb = $this->db->getQueryBuilder();
    $qb->select('rules')
       ->from('emailbridge_sequence')
       ->where($qb->expr()->eq('id', $qb->createNamedParameter($emailId)))
       ->andWhere($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId)));

    $result = $qb->executeQuery()->fetch();
    $rules = $result['rules'] ? json_decode($result['rules'], true) : [];

    return new JSONResponse(['status' => 'ok', 'rules' => $rules ?: []]);
}

/**
 * Sauvegarde les règles d’un email (dans une séquence)
 */
#[NoAdminRequired]
#[NoCSRFRequired]
public function saveEmailRules(int $parcoursId, int $emailId): JSONResponse
{
    $user = $this->userSession->getUser();
    if (!$user) {
        return new JSONResponse(['status' => 'error', 'message' => 'Utilisateur non connecté'], 403);
    }

    $rules = $this->request->getParam('rules');
    if (empty($rules)) {
        return new JSONResponse(['status' => 'error', 'message' => 'Aucune règle reçue'], 400);
    }
    $rulesJson = is_array($rules) ? json_encode($rules, JSON_THROW_ON_ERROR) : $rules;

    try {
        $qb = $this->db->getQueryBuilder();
        $qb->update('emailbridge_sequence', 's')
           ->innerJoin('s', 'emailbridge_parcours', 'p', 's.parcours_id = p.id')
           ->set('s.rules', $qb->createNamedParameter($rulesJson))
           ->where($qb->expr()->eq('s.id', $qb->createNamedParameter($emailId)))
           ->andWhere($qb->expr()->eq('s.parcours_id', $qb->createNamedParameter($parcoursId)))
           ->andWhere($qb->expr()->eq('p.user_id', $qb->createNamedParameter($user->getUID())))
           ->executeStatement();

        return new JSONResponse(['status' => 'ok', 'rules' => json_decode($rulesJson, true)]);

    } catch (\Throwable $e) {
        $this->logger->error('Erreur saveEmailRules: ' . $e->getMessage());
        return new JSONResponse(['status' => 'error', 'message' => 'Erreur lors de la sauvegarde : ' . $e->getMessage()], 500);
    }
}


}
