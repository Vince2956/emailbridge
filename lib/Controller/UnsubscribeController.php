<?php
declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCA\EmailBridge\Defaults;
use OCA\EmailBridge\Service\EmailService;
use OCP\AppFramework\Http\TemplateResponse;

class UnsubscribeController extends Controller {

    private IDBConnection $db;
    private EmailService $emailService;

    public function __construct(string $appName, IRequest $request, IDBConnection $db, EmailService $emailService) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->emailService = $emailService;
    }

    /**
     * Récupère le texte désabonnement d’un parcours
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getText(int $parcoursId): JSONResponse {
        $qb = $this->db->getQueryBuilder();
        try {
            $row = $qb->select('unsubscribe_text')
                      ->from('emailbridge_parcours')
                      ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId)))
                      ->executeQuery()
                      ->fetch(\PDO::FETCH_ASSOC);

            $text = $row['unsubscribe_text'] ?? Defaults::unsubscribeText();
            return new JSONResponse(['text' => $text]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Erreur serveur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sauvegarde le texte désabonnement
     * @NoAdminRequired
     */
    public function saveText(int $parcoursId): JSONResponse {
        $text = $this->request->getParam('text');

        if (!$parcoursId) {
            return new JSONResponse(['status'=>'error','message'=>'ID parcours manquant'], 400);
        }

        $qb = $this->db->getQueryBuilder();
        try {
            $qb->update('emailbridge_parcours')
               ->set('unsubscribe_text', $qb->createNamedParameter($text))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId)))
               ->executeStatement();

            return new JSONResponse(['status'=>'ok']);
        } catch (\Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Erreur serveur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Traite un lien de désabonnement
     * @NoAdminRequired
     * @PublicPage
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function process(?int $email_id = null, ?int $inscription_id = null): TemplateResponse {
        try {
            // --- 0) Robustesse : accepter injection ou query params ---
            $inscriptionId = (int) (
                $inscription_id
                ?? $this->request->getParam('inscription_id')
                ?? $this->request->getParam('inscriptionId')
                ?? 0
            );
            $emailId = (int) (
                $email_id
                ?? $this->request->getParam('email_id')
                ?? $this->request->getParam('emailId')
                ?? 0
            );

            if ($inscriptionId <= 0) {
                return new TemplateResponse($this->appName, 'unsubscribe', [
                    'status' => 'error',
                    'message' => 'Inscription non spécifiée.',
                    'email' => null
                ]);
            }

            // --- 1) Récupère l'inscription ---
            $inscriptionQb = $this->db->getQueryBuilder();
            $inscription = $inscriptionQb->select('id', 'parcours_id', 'liste_id', 'email')
                ->from('*PREFIX*emailbridge_inscription')
                ->where($inscriptionQb->expr()->eq('id', $inscriptionQb->createNamedParameter($inscriptionId, \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            if (!$inscription) {
                return new TemplateResponse($this->appName, 'unsubscribe', [
                    'status' => 'error',
                    'message' => 'Aucune inscription trouvée pour ce lien.',
                    'email' => null
                ]);
            }

            // --- 2) Marque l'inscription comme désabonnée ---
            $updateQb = $this->db->getQueryBuilder();
            $updateQb->update('*PREFIX*emailbridge_inscription')
                ->set('is_unsubscribed', $updateQb->createNamedParameter(1, \PDO::PARAM_INT))
                ->set('updated_at', $updateQb->createNamedParameter((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')))
                ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter((int)$inscription['id'], \PDO::PARAM_INT)))
                ->executeStatement();

            // --- 3) Annule tous les envois futurs ---
            $cancelQb = $this->db->getQueryBuilder();
            $cancelQb->update('*PREFIX*emailbridge_envoi')
                ->set('status', $cancelQb->createNamedParameter('desinscrit'))
                ->set('updated_at', $cancelQb->createNamedParameter((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')))
                ->where($cancelQb->expr()->eq('inscription_id', $cancelQb->createNamedParameter((int)$inscription['id'], \PDO::PARAM_INT)))
                ->andWhere($cancelQb->expr()->eq('status', $cancelQb->createNamedParameter('en_attente')))
                ->executeStatement();

            // --- 4) Déterminer email_id (séquence) si non fourni ---
            if ($emailId <= 0) {
                $qbSeq = $this->db->getQueryBuilder();
                $lastSeq = $qbSeq->select('sequence_id')
                    ->from('*PREFIX*emailbridge_envoi')
                    ->where($qbSeq->expr()->eq('inscription_id', $qbSeq->createNamedParameter((int)$inscription['id'], \PDO::PARAM_INT)))
                    ->orderBy('id', 'DESC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchOne();

                $emailId = (int) $lastSeq;
            }

            // --- 5) Incrémente les stats "unsubscribed" via le service ---
            if ($emailId > 0) {
                $this->emailService->incrementEmailStat($emailId, (int)$inscription['id'], 'unsubscribed');
            }

            // --- 6) Récupère l'email de la liste pour l'affichage ---
            $qbListe = $this->db->getQueryBuilder();
            $liste = $qbListe->select('email')
                ->from('*PREFIX*emailbridge_liste')
                ->where($qbListe->expr()->eq('id', $qbListe->createNamedParameter((int)$inscription['liste_id'], \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            return new TemplateResponse($this->appName, 'unsubscribe', [
                'status' => 'ok',
                'message' => 'Vous avez été désabonné avec succès 🎉.<br>Tous les mails restants ont été annulés.',
                'email' => $liste['email'] ?? null,
                'parcoursId' => $inscription['parcours_id'] ?? 0,
                'urlAccueil' => \OC::$server->getURLGenerator()->linkToRouteAbsolute('emailbridge.page.index')
            ]);

        } catch (\Throwable $e) {
            \OC::$server->getLogger()->error('Unsubscribe process error: ' . $e->getMessage());
            return new TemplateResponse($this->appName, 'unsubscribe', [
                'status' => 'error',
                'message' => 'Erreur serveur: ' . $e->getMessage(),
                'email' => null
            ]);
        }
    }
}
