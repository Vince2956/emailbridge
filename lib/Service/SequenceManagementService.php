<?php

namespace OCA\EmailBridge\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class SequenceManagementService
{
    private IDBConnection $db;
    private LoggerInterface $logger;
    private SequenceService $sequenceService;
    private EmailService $emailService; // ✅ propriété manquante ajoutée

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger,
        SequenceService $sequenceService,
        EmailService $emailService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->sequenceService = $sequenceService;
        $this->emailService = $emailService;
    }

    /**
 * Stoppe tous les envois "en attente" pour toutes les inscriptions
 * associées à la même adresse email (tous parcours confondus)
 */
    public function stopAllSequence(int $inscriptionId): bool
    {
        try {
            $this->logger->info("🟡 stopAllSequence() : Appel pour inscription $inscriptionId");

            // 1️⃣ Récupère l'email de l'inscription courante
            $qbEmail = $this->db->getQueryBuilder();
            $qbEmail->select('email')
                ->from('emailbridge_inscription')
                ->where($qbEmail->expr()->eq('id', $qbEmail->createNamedParameter($inscriptionId)));
            $email = $qbEmail->executeQuery()->fetchOne();

            if (!$email) {
                $this->logger->warning("⚠️ stopAllSequence() : Aucun email trouvé pour inscription $inscriptionId");
                return false;
            }

            // 2️⃣ Récupère toutes les inscriptions ayant le même email
            $qbIds = $this->db->getQueryBuilder();
            $qbIds->select('id')
                ->from('emailbridge_inscription')
                ->where($qbIds->expr()->eq('email', $qbIds->createNamedParameter($email)));
            $inscriptionIds = $qbIds->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($inscriptionIds)) {
                $this->logger->warning("⚠️ stopAllSequence() : Aucune autre inscription trouvée pour l'email $email");
                return false;
            }

            // 3️⃣ Prépare les placeholders pour IN()
            $qb = $this->db->getQueryBuilder();
            $placeholders = array_map(fn ($id) => $qb->createNamedParameter($id), $inscriptionIds);

            // 4️⃣ Stoppe tous les envois en attente pour ces inscriptions
            $rows = $qb->update('emailbridge_envoi')
                ->set('status', $qb->createNamedParameter('arrete'))
                ->set('updated_at', $qb->createNamedParameter(
                    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ))
                ->where($qb->expr()->in('inscription_id', $placeholders))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('en_attente')))
                ->executeStatement();

            $this->logger->info("🟢 stopAllSequence() : $rows envois stoppés pour l'email $email (inscriptions: " . implode(',', $inscriptionIds) . ")");

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('❌ Erreur stopAllSequence: ' . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }




    /**
     * Stoppe tous les envois "en attente" pour toutes les séquences du même parcours
     * que celle passée en paramètre.
     */
    public function stopSingleSequence(int $inscriptionId, int $sequenceId): bool
    {
        try {
            $this->logger->info("[stopSingleSequence] Appel pour inscription $inscriptionId / séquence $sequenceId");

            // --- Récupère le parcours lié à cette séquence ---
            $qb = $this->db->getQueryBuilder();
            $qb->select('parcours_id')
                ->from('emailbridge_sequence')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($sequenceId)));
            $parcoursId = $qb->executeQuery()->fetchOne();

            if (!$parcoursId) {
                $this->logger->warning("[stopSingleSequence] Aucun parcours trouvé pour la séquence $sequenceId");
                return false;
            }

            // --- Récupère toutes les séquences du même parcours ---
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('id')
                ->from('emailbridge_sequence')
                ->where($qb2->expr()->eq('parcours_id', $qb2->createNamedParameter($parcoursId)));
            $sequenceIds = $qb2->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($sequenceIds)) {
                $this->logger->warning("[stopSingleSequence] Aucune séquence trouvée pour parcours $parcoursId");
                return false;
            }

            // --- Conversion sécurisée pour le IN() ---
            $qb3 = $this->db->getQueryBuilder();
            $placeholders = array_map(fn ($id) => $qb3->createNamedParameter($id), $sequenceIds);

            // --- Stoppe tous les envois "en attente" de ces séquences ---
            $rows = $qb3->update('emailbridge_envoi')
                ->set('status', $qb3->createNamedParameter('arrete'))
                ->set('updated_at', $qb3->createNamedParameter(
                    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ))
                ->where($qb3->expr()->eq('inscription_id', $qb3->createNamedParameter($inscriptionId)))
                ->andWhere($qb3->expr()->in('sequence_id', $placeholders))
                ->andWhere($qb3->expr()->eq('status', $qb3->createNamedParameter('en_attente')))
                ->executeStatement();

            $this->logger->info("[stopSingleSequence] $rows envois stoppés pour parcours $parcoursId (inscription $inscriptionId)");

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('❌ Erreur stopSingleSequence: ' . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }



    /**
     * Récupère toutes les inscriptions et leurs envois pour un parcours
     */
    public function getInscriptionsByParcours(int $parcoursId): array
    {
        $qb = $this->db->getQueryBuilder();

        // --- Récupère les inscriptions du parcours ---
        $inscriptions = $qb->select('i.id', 'i.email', 'i.parcours_id', 'i.liste_id')
                           ->from('*PREFIX*emailbridge_inscription', 'i')
                           ->where($qb->expr()->eq('i.parcours_id', $qb->createNamedParameter($parcoursId)))
                           ->executeQuery()
                           ->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($inscriptions as &$insc) {
            $inscId = (int)$insc['id'];
            $email = $insc['email'];

            // --- Récupère les envois avec le titre du mail ---
            $qb2 = $this->db->getQueryBuilder();
            $envois = $qb2
                ->select('en.id', 'en.sequence_id', 'en.status', 'en.send_at', 'en.created_at', 'seq.sujet')
                ->from('*PREFIX*emailbridge_envoi', 'en')
                ->innerJoin('en', '*PREFIX*emailbridge_sequence', 'seq', 'en.sequence_id = seq.id')
                ->where($qb2->expr()->eq('en.inscription_id', $qb2->createNamedParameter($inscId)))
                ->executeQuery()
                ->fetchAll(\PDO::FETCH_ASSOC);

            $insc['envois'] = $envois;

            // --- Dernier mail envoyé (le plus récent) ---
            $last = array_filter($envois, fn ($e) => $e['status'] === 'envoye');
            usort($last, function ($a, $b) {
                return strtotime($b['send_at'] ?: $b['created_at']) <=> strtotime($a['send_at'] ?: $a['created_at']);
            });
            if (!empty($last)) {
                $dernier = $last[0];
                $insc['dernier_mail'] = [
                    'sujet' => $dernier['sujet'],
                    'date'  => $dernier['send_at'] ?: $dernier['created_at'],
                ];
            } else {
                $insc['dernier_mail'] = null;
            }

            // --- Prochain mail en attente (le plus proche à venir) ---
            $next = array_filter($envois, fn ($e) => $e['status'] === 'en_attente');
            usort($next, function ($a, $b) {
                return strtotime($a['send_at'] ?: $a['created_at']) <=> strtotime($b['send_at'] ?: $b['created_at']);
            });
            if (!empty($next)) {
                $prochain = $next[0];
                $insc['prochain_mail'] = [
                    'sujet' => $prochain['sujet'],
                    'date'  => $prochain['send_at'] ?: $prochain['created_at'],
                ];
            } else {
                $insc['prochain_mail'] = null;
            }

            // --- Autres parcours du même email ---
            $qb3 = $this->db->getQueryBuilder();
            $autres = $qb3->select('i.parcours_id', 'p.titre')
                          ->from('*PREFIX*emailbridge_inscription', 'i')
                          ->leftJoin('i', '*PREFIX*emailbridge_parcours', 'p', 'i.parcours_id = p.id')
                          ->where($qb3->expr()->eq('i.email', $qb3->createNamedParameter($email)))
                          ->andWhere($qb3->expr()->neq('i.parcours_id', $qb3->createNamedParameter($parcoursId)))
                          ->executeQuery()
                          ->fetchAll(\PDO::FETCH_ASSOC);
            $insc['autres_parcours'] = $autres;

            // --- Statut global ---
            if ($insc['prochain_mail']) {
                $insc['statut'] = 'en_cours';
            } elseif (!$insc['prochain_mail'] && $insc['dernier_mail']) {
                $insc['statut'] = 'termine';
            } else {
                $insc['statut'] = 'arrete';
            }
        }

        return $inscriptions;
    }




    /**
     * Stop le flux actif avant une réinscription
     */
    private function stopActiveSequencesForInscription(int $inscriptionId): void
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // 1️⃣ Récupère les séquences actives
            $sequences = $qb->select('sequence_id')
                ->from('emailbridge_envoi')
                ->where($qb->expr()->eq('inscription_id', $qb->createNamedParameter($inscriptionId)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('en_attente')))
                ->executeQuery()
                ->fetchAll();

            if (empty($sequences)) {
                $this->logger->info("ℹ️ Aucune séquence active trouvée pour inscription $inscriptionId");
                return;
            }

            // 2️⃣ Stoppe les séquences
            $rowsAffected = $qb->update('emailbridge_envoi')
                ->set('status', $qb->createNamedParameter('stopped'))
                ->set(
                    'updated_at',
                    $qb->createNamedParameter(
                        (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                    )
                )
                ->where($qb->expr()->eq('inscription_id', $qb->createNamedParameter($inscriptionId)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('en_attente')))
                ->executeStatement();

            $this->logger->info("🛑 $rowsAffected séquences stoppées pour inscription $inscriptionId");

            // 3️⃣ Incrémente les stats pour chaque séquence stoppée
            foreach ($sequences as $seq) {
                $sequenceId = (int) $seq['sequence_id'];
                $this->logger->info("🔄 Tentative d’incrément de 'stopped' pour seq=$sequenceId / insc=$inscriptionId");
                try {
                    $this->emailService->incrementEmailStat($sequenceId, $inscriptionId, 'stopped');
                    $this->logger->info("✅ Stat 'stopped' incrémentée pour sequence $sequenceId / inscription $inscriptionId");
                } catch (\Throwable $e) {
                    $this->logger->error("❌ Erreur incrément stat 'stopped' : " . $e->getMessage());
                }
            }

        } catch (\Throwable $e) {
            $this->logger->error('❌ Erreur stopActiveSequencesForInscription: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Redirige une inscription vers une autre séquence
     */
    public function redirectInscription(int $inscriptionId, int $nouveauParcoursId): bool
    {
        try {
            // 1️⃣ Stoppe les envois du parcours actuel
            $this->stopActiveSequencesForInscription($inscriptionId);

            // 2️⃣ Récupère les infos de l’inscription
            $qb = $this->db->getQueryBuilder();
            $inscription = $qb->select('*')
                ->from('emailbridge_inscription')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($inscriptionId)))
                ->executeQuery()
                ->fetch();

            if (!$inscription) {
                throw new \Exception('Inscription non trouvée.');
            }

            // 3️⃣ Crée une nouvelle inscription
            $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $qb = $this->db->getQueryBuilder();
            $qb->insert('emailbridge_inscription')
                ->values([
                    'email' => $qb->createNamedParameter($inscription['email']),
                    'parcours_id' => $qb->createNamedParameter($nouveauParcoursId),
                    'liste_id' => $qb->createNamedParameter($inscription['liste_id']),
                    'date_inscription' => $qb->createNamedParameter($nowUtc),
                    'bypass_file' => $qb->createNamedParameter($inscription['bypass_file']),
                    'created_at' => $qb->createNamedParameter($nowUtc),
                    'updated_at' => $qb->createNamedParameter($nowUtc),
                    'is_unsubscribed' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();

            // 4️⃣ Récupère le nouvel ID
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('LAST_INSERT_ID()'));
            $newInscriptionId = (int) $qb->executeQuery()->fetchOne();

            if ($newInscriptionId <= 0) {
                throw new \Exception('Impossible de récupérer le nouvel ID d’inscription.');
            }

            // 5️⃣ Reprogramme les envois
            $created = $this->sequenceService->scheduleEmailsForInscription($newInscriptionId);
            if ($created === 0) {
                $this->logger->warning("⚠️ Aucun envoi planifié pour la redirection $newInscriptionId (parcours $nouveauParcoursId)");
            }

            // 6️⃣ Incrémente la stat 'redirected'
            $qb = $this->db->getQueryBuilder();
            $envoi = $qb->select('sequence_id')
                ->from('emailbridge_envoi')
                ->where($qb->expr()->eq('inscription_id', $qb->createNamedParameter($inscriptionId)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            $sequenceId = (int)($envoi['sequence_id'] ?? 0);
            if ($sequenceId > 0) {
                $this->emailService->incrementEmailStat($sequenceId, $inscriptionId, 'redirected');
                $this->logger->info("🔁 Stat 'redirected' incrémentée pour seq=$sequenceId / insc=$inscriptionId → new=$newInscriptionId");
            } else {
                $this->logger->warning("⚠️ Impossible d’incrémenter 'redirected', sequence non trouvée pour inscription $inscriptionId");
            }

            $this->logger->info("✅ Redirection terminée : ancienne $inscriptionId → nouvelle $newInscriptionId ($created envois)");

            return true;

        } catch (\Throwable $e) {
            $this->logger->error('❌ Erreur redirectInscription: ' . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }

    public function getEmailStats(int $emailId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select([
            $qb->createFunction('SUM(sent) AS sent'),
            $qb->createFunction('SUM(opened) AS opened'),
            $qb->createFunction('SUM(clicked) AS clicked'),
            $qb->createFunction('SUM(unsubscribed) AS unsubscribed'),
            $qb->createFunction('SUM(stopped) AS stopped'),
            $qb->createFunction('SUM(redirected) AS redirected')
        ])
        ->from('emailbridge_stats')
        ->where($qb->expr()->eq('email_id', $qb->createNamedParameter($emailId)));

        $result = $qb->executeQuery()->fetch();

        return [
            'sent'          => (int)($result['sent'] ?? 0),
            'opened'        => (int)($result['opened'] ?? 0),
            'clicked'       => (int)($result['clicked'] ?? 0),
            'unsubscribed'  => (int)($result['unsubscribed'] ?? 0),
            'stopped'       => (int)($result['stopped'] ?? 0),
            'redirected'    => (int)($result['redirected'] ?? 0),
        ];
    }
}
