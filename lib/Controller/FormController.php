<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http as Http;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\EmailBridge\Service\EmailService;
use OCA\EmailBridge\Service\SequenceService;
use OCA\EmailBridge\Service\SequenceManagementService;
use OCP\IRequest;
use OCP\IURLGenerator;

class FormController extends Controller
{
    private EmailService $emailService;
    private SequenceService $sequenceService;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private $db;
    private SequenceManagementService $sequenceManagement;

    public function __construct(
        string $AppName,
        IRequest $request,
        EmailService $emailService,
        SequenceService $sequenceService,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger,
        IDBConnection $db,
        SequenceManagementService $sequenceManagement
    ) {
        parent::__construct($AppName, $request);
        $this->emailService = $emailService;
        $this->sequenceService = $sequenceService;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->db = $db;
        $this->sequenceManagement = $sequenceManagement;
    }

    /**
     * Page d'accueil du formulaire (template simple).
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function index(): TemplateResponse
    {
        return new TemplateResponse('emailbridge', 'form');
    }

    /**
     * Affiche le formulaire pour un parcours donné (utilisé par le front).
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function show(int $id): TemplateResponse
    {
        return new TemplateResponse('emailbridge', 'form', [
            'parcoursId' => $id,
        ]);
    }

    /**
     * Template pour already_subscribe (renvoie un TemplateResponse).
     * Exposé si on souhaite un affichage full page.
     */
    public function alreadyConfirm(string $email, int $parcoursId): TemplateResponse
    {
        $urlAccueil = $this->urlGenerator->linkToRoute('emailbridge.page.index');

        return new TemplateResponse('emailbridge', 'already_subscribe', [
            'email' => $email,
            'parcoursId' => $parcoursId,
            'urlAccueil' => $urlAccueil
        ]);
    }


    #[NoCSRFRequired]
    #[PublicPage]
    public function submit(): DataResponse
    {
        try {
            $email = (string)$this->request->getParam('email');
            $parcoursId = (int)$this->request->getParam('parcoursId');

            if ($email === '' || $parcoursId <= 0) {
                return new DataResponse(['error' => 'Email ou parcours manquant.'], Http::STATUS_BAD_REQUEST);
            }

            // 🧩 Vérifie si l'email est déjà inscrit à ce parcours
            if ($this->emailService->isAlreadyInscribed($email, $parcoursId)) {

                // 🔄 Teste la règle de repass
                $newInscriptionId = $this->handleRepassRule($email, $parcoursId);

                if ($newInscriptionId !== null) {
                    // Redirection vers le nouveau parcours créé par handleRepassRule
                    $url = $this->urlGenerator->linkToRoute('emailbridge.form.confirmation', [
                        'inscriptionId' => $newInscriptionId
                    ]);
                    return new DataResponse([
                        'status' => 'redirect_repass',
                        'redirect' => $url
                    ], Http::STATUS_OK);
                }

                // Sinon, template déjà inscrit
                $url = $this->urlGenerator->linkToRoute('emailbridge.form.alreadyRegistered', [
                    'email' => $email,
                    'parcoursId' => $parcoursId
                ]);
                return new DataResponse([
                    'status' => 'already_subscribed',
                    'redirect' => $url
                ]);
            }


            // Détermine côté serveur si bypass actif pour ce parcours
            $bypassActive = $this->emailService->isBypassEnabled($parcoursId);

            if ($bypassActive) {
                // Cas BYPASS
                $isKnown = $this->sequenceService->isEmailKnown($email);

                if ($isKnown) {
                    $inscriptionId = $this->sequenceService->createInscriptionDirect($email, $parcoursId);
                    if ($inscriptionId) {
                        $this->sequenceService->scheduleEmailsForInscription($inscriptionId);
                        return new DataResponse([
                            'status' => 'success',
                            'message' => 'Inscription directe effectuée (bypass, email connu).'
                        ], Http::STATUS_OK);
                    }
                    return new DataResponse(['error' => 'Impossible de créer l\'inscription directe.'], Http::STATUS_INTERNAL_SERVER_ERROR);
                } else {
                    $this->emailService->sendBypassConfirmationEmail($email, $parcoursId);
                    return new DataResponse([
                        'status' => 'pending',
                        'message' => 'Confirmation requise (bypass, email inconnu).'
                    ], Http::STATUS_OK);
                }
            }

            // Mode normal (inscription classique)
            $this->emailService->storeAndSend(
                $email,
                $this->emailService->getDocumentUrlForParcours($parcoursId),
                $parcoursId
            );

            return new DataResponse([
                'status' => 'ok',
                'message' => "Merci ! Vérifiez votre email pour confirmer (parcours $parcoursId)."
            ], Http::STATUS_OK);

        } catch (\Throwable $e) {
            $this->logger->error('FormController::submit error: ' . $e->getMessage(), ['exception' => $e]);
            return new DataResponse([
                'error' => 'Erreur lors de la soumission : ' . $e->getMessage()
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }


    #[NoCSRFRequired]
    #[PublicPage]
    public function confirm(): Response
    {
        try {
            $token = (string) $this->request->getParam('token');
            if ($token === '') {
                return new TemplateResponse('emailbridge', 'error', [
                    'message' => 'Token manquant'
                ]);
            }

            // 1️⃣ Récupération de l’inscription ou liste via le token
            $inscription = $this->emailService->getInscriptionByToken($token);
            if (!$inscription) {
                $inscription = $this->emailService->getListeByToken($token);
                if (!$inscription) {
                    return new TemplateResponse('emailbridge', 'error', [
                        'message' => 'Lien invalide ou expiré'
                    ]);
                }
            }

            // 2️⃣ Déjà confirmé ?
            if ((int)($inscription['is_confirmed'] ?? 0) === 1) {
                return $this->alreadyConfirm($inscription['email'] ?? '', $inscription['parcours_id'] ?? 0);
            }

            // 3️⃣ Confirmation effective
            $confirmResult = $this->emailService->confirmToken($token);
            if ($confirmResult === null) {
                return new TemplateResponse('emailbridge', 'error', [
                    'message' => 'Erreur : impossible de confirmer l’inscription.'
                ]);
            }

            // 4️⃣ Rechargement de l’inscription mise à jour
            $inscription = $this->emailService->getInscriptionByToken($token);
            if (!$inscription) {
                return new TemplateResponse('emailbridge', 'error', [
                    'message' => 'Erreur : inscription introuvable après confirmation.'
                ]);
            }

            // 5️⃣ Vérification du mode BYPASS (depuis la table parcours)
            $bypassEnabled = $this->emailService->isBypassEnabled((int)$inscription['parcours_id']);

            if ($bypassEnabled || (int)($inscription['bypass_file'] ?? 0) === 1) {
                // Mode BYPASS → afficher confirm_pending directement
                $urlAccueil = $this->urlGenerator->linkToRoute('emailbridge.page.index');
                $this->logger->info("EmailBridge: confirmation BYPASS affichée pour {$inscription['email']} (token=$token)");

                return new TemplateResponse('emailbridge', 'confirm_pending', [
                    'email' => $inscription['email'] ?? '',
                    'parcoursId' => $inscription['parcours_id'] ?? 0,
                    'urlAccueil' => $urlAccueil
                ]);
            }

            // 6️⃣ Mode normal → redirection vers document_url ou fallback
            $redirectTo = $confirmResult ?: ($inscription['document_url'] ?? '');
            if ($redirectTo === '') {
                $redirectTo = $this->urlGenerator->linkToRoute('emailbridge.page.index');
            }

            $this->logger->info("EmailBridge: confirmation OK pour {$inscription['email']} -> redirect $redirectTo");
            return new RedirectResponse($redirectTo);

        } catch (\Throwable $e) {
            $this->logger->error('FormController::confirm error: ' . $e->getMessage(), ['exception' => $e]);
            return new TemplateResponse('emailbridge', 'error', [
                'message' => 'Erreur interne lors de la confirmation : ' . $e->getMessage()
            ]);
        }
    }



    /**
     * Construit une URL vers la page "already subscribed" (utilisée côté front pour redirection depuis AJAX)
     * Ici on renvoie une route interne vers l'action alreadySubscribed qui affiche le template.
     */
    private function buildAlreadyConfirmUrl(string $email, int $parcoursId): string
    {
        $base = $this->urlGenerator->linkToRoute('emailbridge.form.alreadyConfirm');
        $qs = http_build_query(['email' => $email, 'parcoursId' => $parcoursId]);
        return $base . '?' . $qs;
    }



    #[NoCSRFRequired]
    #[PublicPage]
    public function alreadyRegistered(string $email, int $parcoursId): TemplateResponse
    {
        $urlAccueil = $this->urlGenerator->linkToRoute('emailbridge.page.index');

        return new TemplateResponse('emailbridge', 'already_registered', [
            'email' => $email,
            'parcoursId' => $parcoursId,
            'urlAccueil' => $urlAccueil
        ]);
    }

    /**
     * 👉 Ne pas relancer les envois du parcours d’origine (ou les stopper s’ils ont commencé).
     * 👉 Créer une nouvelle inscription dans le parcours cible (ifRepassTarget).
     * 👉 Programmer les mails de ce nouveau parcours immédiatement
     */
    private function handleRepassRule(string $email, int $parcoursId): ?int
    {
        if ($this->sequenceService === null) {
            return null;
        }

        try {
            // 1️⃣ Récupère l'inscription existante
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'liste_id')
                ->from('emailbridge_inscription')
                ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
                ->andWhere($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
                ->setMaxResults(1);

            $row = $qb->executeQuery()->fetch();
            if (!$row) {
                return null; // pas déjà inscrit
            }

            $inscriptionId = (int)$row['id'];
            $listeId = (int)$row['liste_id'];

            // 2️⃣ Récupère le parcours cible depuis la règle du premier email
            $qb = $this->db->getQueryBuilder();
            $qb->select('rules')
                ->from('emailbridge_sequence')
                ->where($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
                ->andWhere($qb->expr()->eq('send_day', $qb->createNamedParameter(0, \PDO::PARAM_INT))) // premier email
                ->setMaxResults(1);

            $row = $qb->executeQuery()->fetch();
            if (!$row) {
                return null;
            }

            $rules = json_decode($row['rules'], true);
            $redirect = isset($rules['ifRepassTarget']) ? (int)$rules['ifRepassTarget'] : 0;
            if ($redirect <= 0) {
                return null; // pas de redirection valide
            }

            $this->logger->info("[handleRepassRule] Redirection détectée pour $email → parcours cible #$redirect");

            // 3️⃣ Stopper la séquence actuelle
            $this->sequenceManagement->stopSingleSequence($inscriptionId, 1);

            // 4️⃣ Créer la nouvelle inscription pour le parcours cible
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('emailbridge_inscription')
                ->values([
                    'liste_id'        => $qb2->createNamedParameter($listeId, \PDO::PARAM_INT),
                    'parcours_id'     => $qb2->createNamedParameter($redirect, \PDO::PARAM_INT),
                    'email'           => $qb2->createNamedParameter($email),
                    'date_inscription' => $qb2->createNamedParameter($now),
                    'bypass_file'     => $qb2->createNamedParameter(false, \PDO::PARAM_BOOL),
                    'created_at'      => $qb2->createNamedParameter($now),
                    'updated_at'      => $qb2->createNamedParameter($now),
                ])
                ->executeStatement();

            $newInscriptionId = (int)$this->db->lastInsertId('emailbridge_inscription_id_seq');
            $this->logger->info("[handleRepassRule] Nouvelle inscription #$newInscriptionId créée pour parcours #$redirect");

            // 5️⃣ Planifie les emails pour la nouvelle inscription
            $this->sequenceService->scheduleEmailsForInscription($newInscriptionId);

            return $newInscriptionId;

        } catch (\Throwable $e) {
            $this->logger->error("handleRepassRule error: " . $e->getMessage());
            return null;
        }
    }

}
