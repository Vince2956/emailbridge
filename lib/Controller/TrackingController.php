<?php
declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\EmailBridge\Service\EmailService;
use OCA\EmailBridge\Service\SequenceService;

/**
 * Contrôleur de tracking pour EmailBridge :
 * - Pixel d’ouverture invisible (trackOpen)
 * - Redirection avec suivi de clics (click)
 */
class TrackingController extends Controller {

    private EmailService $emailService;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private SequenceService $sequenceService; 
    private SequenceController $sequenceController;

    public function __construct(
        string $AppName,
        IRequest $request,
        EmailService $emailService,
        IDBConnection $db,
        LoggerInterface $logger,
        SequenceService $sequenceService,
        SequenceController $sequenceController
    ) {
        parent::__construct($AppName, $request);
        $this->emailService = $emailService;
        $this->db = $db;
        $this->logger = $logger;
        $this->sequenceService = $sequenceService;
        $this->sequenceController = $sequenceController;
    }

    /**
     * 📨 Tracking d’ouverture du mail via un pixel 1x1 transparent
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function trackOpen(?int $email_id = null, ?int $inscription_id = null): DataDownloadResponse {
        try {
            if ($email_id && $inscription_id) {
                $this->emailService->incrementEmailStat($email_id, $inscription_id, 'opened');
                $this->logger->info("📬 TrackingController: ouverture mail (email_id=$email_id, inscription_id=$inscription_id)");
            } else {
                $this->logger->warning("⚠️ TrackingController: paramètres manquants pour trackOpen");
            }
        } catch (\Throwable $e) {
            $this->logger->error('❌ Erreur TrackingController::trackOpen - ' . $e->getMessage(), ['exception' => $e]);
        }

        $pixel = base64_decode('R0lGODlhAQABAIABAP///wAAACwAAAAAAQABAAACAkQBADs=');
        $response = new DataDownloadResponse($pixel, 'pixel.gif', 'image/gif');
        $response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Expires', '0');

        return $response;
    }

    /**
     * 🔗 Tracking des clics sur les liens des mails et redirection si la règle existe
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function click(?int $email_id = null, ?int $inscription_id = null, ?string $link = null): RedirectResponse {
    $decodedLink = $link ? urldecode($link) : '';
    $decodedLink = trim($decodedLink);

    if ($email_id && $inscription_id) {
        try {
            // 1️⃣ Incrément du compteur de clics
            $this->emailService->incrementEmailStat($email_id, $inscription_id, 'clicked');
            $this->logger->info("🔗 TrackingController: clic enregistré (email_id=$email_id, inscription_id=$inscription_id)");

            // 2️⃣ Redirection conditionnelle vers un autre parcours si la règle existe
            $this->handleRedirectOnClick($email_id, $inscription_id);

        } catch (\Throwable $e) {
            $this->logger->error("❌ Erreur TrackingController::click - " . $e->getMessage(), ['exception' => $e]);
        }
    } else {
        $this->logger->warning("⚠️ TrackingController: paramètres manquants pour click");
    }

    // 3️⃣ Vérifie que le lien est valide
    if (empty($decodedLink) || !filter_var($decodedLink, FILTER_VALIDATE_URL)) {
        $this->logger->warning("⚠️ TrackingController: lien invalide ou manquant, redirection vers /");
        $decodedLink = '/';
    }

    // 4️⃣ Redirection finale
    return new RedirectResponse($decodedLink);
}


private function handleRedirectOnClick(int $emailId, int $inscriptionId): void {
    try {
        $qb = $this->db->getQueryBuilder();
        $rulesJson = $qb->select('rules')
                        ->from('emailbridge_sequence')
                        ->where($qb->expr()->eq('id', $qb->createNamedParameter($emailId)))
                        ->executeQuery()
                        ->fetchOne();

        if (!$rulesJson) return;

        $rules = json_decode($rulesJson, true);
        $redirect = $rules['redirectOnClick'] ?? null;
        if (!$redirect) return;

        // --- Récupération de l'inscription actuelle ---
        $qbIns = $this->db->getQueryBuilder();
        $ins = $qbIns
            ->select('email', 'parcours_id', 'liste_id')
            ->from('emailbridge_inscription')
            ->where($qbIns->expr()->eq('id', $qbIns->createNamedParameter($inscriptionId)))
            ->executeQuery()
            ->fetch();

        if (!$ins) {
            $this->logger->warning("[handleRedirectOnClick] Inscription #$inscriptionId introuvable");
            return;
        }

        // --- Récupération de la séquence active pour cette inscription ---
        $qbSeq = $this->db->getQueryBuilder();
        $sequenceId = $qbSeq->select('sequence_id')
                            ->from('emailbridge_envoi')
                            ->where($qbSeq->expr()->eq('inscription_id', $qbSeq->createNamedParameter($inscriptionId)))
                            ->andWhere($qbSeq->expr()->eq('status', $qbSeq->createNamedParameter('en_attente')))
                            ->orderBy('send_at', 'ASC')
                            ->setMaxResults(1)
                            ->executeQuery()
                            ->fetchOne();

        if ($sequenceId) {
            $this->sequenceController->stopSingleSequence($inscriptionId, (int)$sequenceId);
            $this->logger->info("[handleRedirectOnClick] Séquence #$sequenceId stoppée pour inscription #$inscriptionId");
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // --- Création de la nouvelle inscription pour le parcours cible ---
        $qbInsert = $this->db->getQueryBuilder();
        $qbInsert->insert('emailbridge_inscription')
                 ->values([
                     'liste_id' => $qbInsert->createNamedParameter($ins['liste_id'], \PDO::PARAM_INT),
                     'email' => $qbInsert->createNamedParameter($ins['email']),
                     'parcours_id' => $qbInsert->createNamedParameter($redirect, \PDO::PARAM_INT),
                     'date_inscription' => $qbInsert->createNamedParameter($now),
                     'bypass_file' => $qbInsert->createNamedParameter(false, \PDO::PARAM_BOOL),
                     'created_at' => $qbInsert->createNamedParameter($now),
                     'updated_at' => $qbInsert->createNamedParameter($now),
                 ])
                 ->executeStatement();

        $newInscriptionId = (int)$this->db->lastInsertId('emailbridge_inscription_id_seq');
        $this->logger->info("Redirection appliquée : inscription #$inscriptionId → nouvelle inscription #$newInscriptionId vers parcours #$redirect");

        // --- Programmer les emails pour la nouvelle inscription ---
        $this->sequenceService->scheduleEmailsForInscription($newInscriptionId);

    } catch (\Throwable $e) {
        $this->logger->error("[handleRedirectOnClick] Erreur : " . $e->getMessage(), [
            'emailId' => $emailId, 
            'inscriptionId' => $inscriptionId
        ]);
    }
}

}
