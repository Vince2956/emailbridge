<?php

declare(strict_types=1);

namespace OCA\EmailBridge\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\EmailBridge\Service\EmailService;
use OCA\EmailBridge\Service\SequenceService;
use OCP\IConfig;
use OCP\AppFramework\Utility\ITimeFactory;

class EmailSenderJob extends TimedJob
{
    private IDBConnection $db;
    private LoggerInterface $logger;
    private EmailService $emailService;
    private SequenceService $sequenceService;
    private int $batchSize;
    private IConfig $config;

    /**
     * Constructeur compatible Nextcloud cron
     * Arguments optionnels pour injection (tests / container)
     */
    public function __construct(
        ?ITimeFactory $timeFactory = null,
        ?IDBConnection $db = null,
        ?LoggerInterface $logger = null,
        ?EmailService $emailService = null,
        ?SequenceService $sequenceService = null,
        ?IConfig $config = null,
        int $batchSize = 50
    ) {
        parent::__construct($timeFactory ?? \OC::$server->get(ITimeFactory::class));

        // Injection via container si null
        $this->db = $db ?? \OC::$server->get(IDBConnection::class);
        $this->logger = $logger ?? \OC::$server->get(LoggerInterface::class);
        $this->emailService = $emailService ?? \OC::$server->get(EmailService::class);
        $this->sequenceService = $sequenceService ?? \OC::$server->get(SequenceService::class);
        $this->config = $config ?? \OC::$server->get(IConfig::class);
        $this->batchSize = $batchSize;

        $this->setInterval(60); // exécution toutes les 60 secondes
    }

    /**
     * Exécution du job
     */
    public function run($argument): void
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Récupération des envois prêts à être traités, en joignant la table "liste"
            $rows = $qb->select(
                'e.id AS envoi_id',
                'e.inscription_id',
                'e.sequence_id',
                'e.send_at',
                'i.liste_id',
                'l.token',
                'p.bypass_file'
            )
                ->from('emailbridge_envoi', 'e')
                ->innerJoin('e', 'emailbridge_inscription', 'i', 'e.inscription_id = i.id')
                ->innerJoin('i', 'emailbridge_liste', 'l', 'i.liste_id = l.id')
                ->innerJoin('i', 'emailbridge_parcours', 'p', 'i.parcours_id = p.id')
                ->where($qb->expr()->eq('e.status', $qb->createNamedParameter('en_attente')))
                ->andWhere($qb->expr()->lte(
                    'e.send_at',
                    $qb->createNamedParameter((new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'))
                ))
                ->setMaxResults($this->batchSize)
                ->executeQuery()
                ->fetchAll();

            foreach ($rows as $row) {
                // Récupération de la liste (email + confirmed) — on peut utiliser le token déjà chargé si besoin
                $listeQb = $this->db->getQueryBuilder();
                $liste = $listeQb->select('*')
                    ->from('emailbridge_liste', 'l')
                    ->where($listeQb->expr()->eq('l.id', $listeQb->createNamedParameter($row['liste_id'])))
                    ->executeQuery()
                    ->fetch();

                if (!$liste) {
                    $this->logger->warning("EmailSenderJob: liste_id {$row['liste_id']} introuvable");
                    $this->markEnvoi((int)$row['envoi_id'], 'erreur');
                    continue;
                }

                // Si l'utilisateur n'a pas confirmé et qu'on ne bypass pas le fichier
                if (!$liste['confirmed'] && !$row['bypass_file']) {
                    $this->markEnvoi((int)$row['envoi_id'], 'non_recu');
                    continue;
                }

                // Vérification de l’heure d’envoi
                $sendAt = new \DateTimeImmutable($row['send_at'], new \DateTimeZone('UTC'));
                if ($sendAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                    continue;
                }


                // Envoi du mail
                $ok = $this->emailService->sendEmailFromSequence(
                    (int)$row['sequence_id'],
                    $liste['email'],
                    (int)$row['inscription_id'],
                    [], // pas de placeholder ici
                    true // le footer unsubscribe sera généré automatiquement
                );

                $this->markEnvoi((int)$row['envoi_id'], $ok ? 'envoye' : 'erreur');

                // 🚀 Nouvelle logique : déclenchement du redirectTarget si c’est le dernier envoi
                if ($ok) {
                    $this->handlePossibleRedirect((int)$row['sequence_id'], (int)$row['inscription_id']);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("EmailSenderJob exception: " . $e->getMessage());
        }
    }


    private function markEnvoi(int $envoiId, string $status): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('emailbridge_envoi', 'e')
                ->set('status', $qb->createNamedParameter($status))
                ->set(
                    'updated_at',
                    $qb->createNamedParameter((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'))
                )
                ->where($qb->expr()->eq('e.id', $qb->createNamedParameter($envoiId)))
                ->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->error("EmailSenderJob markEnvoi exception: " . $e->getMessage());
        }
    }


    /**
     * Vérifie si la séquence a une redirection (redirectTarget dans rules),
     * et crée une nouvelle inscription vers le parcours cible si nécessaire.
     */
    private function handlePossibleRedirect(int $sequenceId, int $inscriptionId): void
    {
        try {
            // --- 1️⃣ Récupération de la séquence pour lire la règle redirectTarget ---
            $qb = $this->db->getQueryBuilder();
            $rulesJson = $qb->select('rules')
                ->from('emailbridge_sequence')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($sequenceId)))
                ->executeQuery()
                ->fetchOne();

            if (!$rulesJson) {
                return; // aucune règle trouvée
            }

            $rules = json_decode($rulesJson, true);
            $redirect = $rules['redirectTarget'] ?? null;
            if (!$redirect) {
                return; // aucune redirection prévue
            }

            // --- 2️⃣ Récupération de l'email via liste_id ---
            $listeId = $this->getListeIdFromInscription($inscriptionId);
            if (!$listeId) {
                $this->logger->warning("handlePossibleRedirect: impossible de récupérer liste_id pour inscription $inscriptionId");
                return;
            }

            $qbEmail = $this->db->getQueryBuilder();
            $email = $qbEmail->select('email')
                ->from('emailbridge_liste')
                ->where($qbEmail->expr()->eq('id', $qbEmail->createNamedParameter($listeId)))
                ->executeQuery()
                ->fetchOne();

            if (!$email) {
                $this->logger->warning("handlePossibleRedirect: impossible de récupérer l'email pour liste_id $listeId");
                return;
            }

            // --- 3️⃣ Création de la nouvelle inscription pour le parcours cible ---
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $inscriptionQb = $this->db->getQueryBuilder();
            $inscriptionQb->insert('emailbridge_inscription')
                ->values([
                    'liste_id'        => $inscriptionQb->createNamedParameter($listeId, \PDO::PARAM_INT),
                    'parcours_id'     => $inscriptionQb->createNamedParameter((int)$redirect, \PDO::PARAM_INT),
                    'email'           => $inscriptionQb->createNamedParameter($email),
                    'date_inscription' => $inscriptionQb->createNamedParameter($now),
                    'bypass_file'     => $inscriptionQb->createNamedParameter(false, \PDO::PARAM_BOOL),
                    'created_at'      => $inscriptionQb->createNamedParameter($now),
                    'updated_at'      => $inscriptionQb->createNamedParameter($now),
                ])
                ->executeStatement();

            $this->logger->info("Redirection appliquée : inscription #{$inscriptionId} → nouvelle inscription vers parcours #{$redirect}");

            //Reprogrammer les emails de la nouvelle inscription
            $newInscriptionId = (int)$this->db->lastInsertId('emailbridge_inscription_id_seq');
            $this->sequenceService->scheduleEmailsForInscription((int)$newInscriptionId);

        } catch (\Throwable $e) {
            $this->logger->error("handlePossibleRedirect exception: " . $e->getMessage(), [
                'sequenceId' => $sequenceId,
                'inscriptionId' => $inscriptionId
            ]);
        }
    }


    /**
     * Retourne true si tous les emails d'une séquence ont été envoyés pour une inscription donnée.
     */
    private function isSequenceComplete(int $sequenceId, int $inscriptionId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $count = $qb->selectAlias($qb->createFunction('COUNT(*)'), 'pending')
            ->from('emailbridge_envoi', 'e')
            ->where($qb->expr()->eq('e.sequence_id', $qb->createNamedParameter($sequenceId)))
            ->andWhere($qb->expr()->eq('e.inscription_id', $qb->createNamedParameter($inscriptionId)))
            ->andWhere($qb->expr()->neq('e.status', $qb->createNamedParameter('envoye')))
            ->executeQuery()
            ->fetchOne();

        return ((int)$count) === 0;
    }

    /**
     * Récupère la liste_id à partir d'une inscription.
     */
    private function getListeIdFromInscription(int $inscriptionId): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $listeId = $qb->select('liste_id')
            ->from('emailbridge_inscription')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($inscriptionId)))
            ->executeQuery()
            ->fetchOne();

        return $listeId ? (int)$listeId : null;
    }

}
