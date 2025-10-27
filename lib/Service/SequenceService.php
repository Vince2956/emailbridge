<?php
declare(strict_types=1);

namespace OCA\EmailBridge\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class SequenceService {
    private IDBConnection $db;
    private LoggerInterface $logger;
    private EmailService $emailService;

    public function __construct(IDBConnection $db, LoggerInterface $logger, EmailService $emailService) {
        $this->db = $db;
        $this->logger = $logger;
        $this->emailService = $emailService;
    }


    /**
     * Vérifie si un email est déjà présent dans emailbridge_inscription (toutes parcours confondus).
     */
    public function isEmailKnown(string $email): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('emailbridge_inscription')
               ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
               ->setMaxResults(1);

            $row = $qb->executeQuery()->fetch();
            return !empty($row);
        } catch (\Throwable $e) {
            $this->logger->error('SequenceService::isEmailKnown error: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }


    /**
     * Crée une inscription "directe" pour bypass (email connu) :
     *  - crée ou récupère une ligne dans emailbridge_liste (confirmed = 1)
     *  - crée ensuite la ligne dans emailbridge_inscription en pointant sur liste_id
     *
     * Retourne l'ID de l'inscription créée (int) ou 0 en cas d'erreur.
     */

public function createInscriptionDirect(string $email, int $parcoursId, ?string $token = null): int {
    try {
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // 1️⃣ Récupération du document_url du parcours (si existant)
        $qbDoc = $this->db->getQueryBuilder();
        $doc = $qbDoc->select('document_url')
                     ->from('emailbridge_parcours')
                     ->where($qbDoc->expr()->eq('id', $qbDoc->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
                     ->setMaxResults(1)
                     ->executeQuery()
                     ->fetchOne();
        $documentUrlValue = ($doc === false) ? '' : (string)$doc;

        // 2️⃣ Génère un token s’il n’a pas été fourni
        if ($token === null) {
            $token = bin2hex(random_bytes(16));
        }

        // 3️⃣ Crée (ou remplace) une entrée dans emailbridge_liste
        $qbInsert = $this->db->getQueryBuilder();
        $qbInsert->insert('emailbridge_liste')
                 ->values([
                     'email'        => $qbInsert->createNamedParameter($email),
                     'token'        => $qbInsert->createNamedParameter($token),
                     'confirmed'    => $qbInsert->createNamedParameter(1, \PDO::PARAM_INT),
                     'confirmed_at' => $qbInsert->createNamedParameter($nowUtc),
                     'parcours_id'  => $qbInsert->createNamedParameter($parcoursId, \PDO::PARAM_INT),
                     'document_url' => $qbInsert->createNamedParameter($documentUrlValue),
                     'created_at'   => $qbInsert->createNamedParameter($nowUtc),
                 ])
                 ->executeStatement();

        // 4️⃣ Récupère l’ID de la liste insérée via le token
        $qbSel = $this->db->getQueryBuilder();
        $row = $qbSel->select('id')
                     ->from('emailbridge_liste')
                     ->where($qbSel->expr()->eq('token', $qbSel->createNamedParameter($token)))
                     ->setMaxResults(1)
                     ->executeQuery()
                     ->fetch();

        $listeId = (int)($row['id'] ?? 0);
        if ($listeId <= 0) {
            $this->logger->error('createInscriptionDirect: impossible de récupérer liste_id après insert', [
                'email' => $email,
                'parcoursId' => $parcoursId
            ]);
            return 0;
        }

        // 5️⃣ Création de la ligne dans emailbridge_inscription
        $qbIns = $this->db->getQueryBuilder();
        $qbIns->insert('emailbridge_inscription')
              ->values([
                  'parcours_id'      => $qbIns->createNamedParameter($parcoursId, \PDO::PARAM_INT),
                  'liste_id'         => $qbIns->createNamedParameter($listeId, \PDO::PARAM_INT),
                  'email'            => $qbIns->createNamedParameter($email),
                  'date_inscription' => $qbIns->createNamedParameter($nowUtc),
                  'bypass_file'      => $qbIns->createNamedParameter(true, \PDO::PARAM_BOOL),
                  'created_at'       => $qbIns->createNamedParameter($nowUtc),
                  'updated_at'       => $qbIns->createNamedParameter($nowUtc),
              ])
              ->executeStatement();

        // 6️⃣ Récupère l’id de l’inscription
        $qbSel2 = $this->db->getQueryBuilder();
        $insRow = $qbSel2->select('id')
                         ->from('emailbridge_inscription')
                         ->where($qbSel2->expr()->eq('liste_id', $qbSel2->createNamedParameter($listeId, \PDO::PARAM_INT)))
                         ->orderBy('id', 'DESC')
                         ->setMaxResults(1)
                         ->executeQuery()
                         ->fetch();

        $inscriptionId = (int)($insRow['id'] ?? 0);

        if ($inscriptionId <= 0) {
            $this->logger->error('createInscriptionDirect: impossible de récupérer inscription_id', [
                'listeId' => $listeId,
                'email' => $email
            ]);
            return 0;
        }

        return $inscriptionId;

    } catch (\Throwable $e) {
        $this->logger->error('SequenceService::createInscriptionDirect error: ' . $e->getMessage(), [
            'email' => $email,
            'parcoursId' => $parcoursId
        ]);
        return 0;
    }
}


private function computeSendDate(\DateTimeImmutable $dateInscriptionUtc, array $sequence): \DateTimeImmutable {
    $daysToAdd = (int)($sequence['send_day'] ?? 0);
    $delayMinutes = is_numeric($sequence['delay_minutes']) ? (int)$sequence['delay_minutes'] : null;
    $sendTime = $sequence['send_time'] ?? null;

    $sendMoment = $dateInscriptionUtc;

    if ($daysToAdd === 0) {
        // --- Cas J0 ---
        if ($delayMinutes !== null && $delayMinutes > 0) {
            $sendMoment = $sendMoment->modify("+{$delayMinutes} minutes");
        } elseif (!empty($sendTime) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $sendTime)) {
            [$h, $m, $s2] = array_pad(explode(':', $sendTime), 3, 0);
            $sendMoment = $sendMoment->setTime((int)$h, (int)$m, (int)$s2);
            if ($sendMoment < $dateInscriptionUtc) {
                $sendMoment = $sendMoment->modify('+1 day');
            }
        } else {
            // fallback : +15 minutes
            $sendMoment = $sendMoment->modify('+15 minutes');
        }
    } else {
        // --- Cas J>0 ---
        $sendMoment = $sendMoment->modify("+{$daysToAdd} days");
        if (!empty($sendTime) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $sendTime)) {
            [$h, $m, $s2] = array_pad(explode(':', $sendTime), 3, 0);
            $sendMoment = $sendMoment->setTime((int)$h, (int)$m, (int)$s2);
        } else {
            // si pas d'heure définie, conserver l'heure d'inscription
            $sendMoment = $sendMoment->setTime(
                (int)$dateInscriptionUtc->format('H'),
                (int)$dateInscriptionUtc->format('i'),
                (int)$dateInscriptionUtc->format('s')
            );
        }
    }

    return $sendMoment;
}

/**
 * Applique l’ensemble des règles de décalage (week-end, jours fériés, heures tardives)
 */
private function applySendRules(\DateTimeImmutable $date): \DateTimeImmutable {
    $adjusted = $this->adjustForWeekend($date);
    $adjusted = $this->adjustForHoliday($adjusted);
    $adjusted = $this->adjustForLateHour($adjusted);
    return $adjusted;
}



/**
 * Schedule les envois pour une inscription donnée (après confirmation)
 */
public function scheduleEmailsForInscription(int $inscriptionId): int {
    $created = 0;

    // --- 1) Récupérer l'inscription ---
    $qbIns = $this->db->getQueryBuilder();
    $ins = $qbIns->select('i.id', 'i.parcours_id', 'i.date_inscription', 'i.email', 'i.liste_id', 'i.bypass_file')
        ->from('emailbridge_inscription', 'i')
        ->where($qbIns->expr()->eq('i.id', $qbIns->createNamedParameter($inscriptionId, \PDO::PARAM_INT)))
        ->executeQuery()
        ->fetch();

    if (!$ins) {
        $this->logger->error("scheduleEmailsForInscription: inscription $inscriptionId introuvable");
        return 0;
    }

    try {
        $dateInscriptionUtc = new \DateTimeImmutable($ins['date_inscription'], new \DateTimeZone('UTC'));
    } catch (\Throwable $e) {
        $this->logger->error("scheduleEmailsForInscription: date_inscription invalide pour $inscriptionId : " . $e->getMessage());
        return 0;
    }

    // --- 2) Récupérer la séquence ---
    $qbSeq = $this->db->getQueryBuilder();
    $seqs = $qbSeq->select('s.id', 's.send_day', 's.send_time', 's.delay_minutes', 's.rules')
        ->from('emailbridge_sequence', 's')
        ->where($qbSeq->expr()->eq('s.parcours_id', $qbSeq->createNamedParameter($ins['parcours_id'], \PDO::PARAM_INT)))
        ->orderBy('s.send_day', 'ASC')
        ->addOrderBy('s.send_time', 'ASC')
        ->executeQuery()
        ->fetchAll();

    if (empty($seqs)) {
        $this->logger->warning("scheduleEmailsForInscription: aucune sequence pour parcours {$ins['parcours_id']}");
        return 0;
    }

    // --- 3) Transaction + insertion ---
    $this->db->beginTransaction();
    try {
        foreach ($seqs as $s) {
            // --- Calcul du sendMoment avec computeSendDate ---
            $sequenceData = [
                'send_day' => $s['send_day'],
                'send_time' => $s['send_time'],
                'delay_minutes' => $s['delay_minutes']
            ];
            $sendMoment = $this->computeSendDate($dateInscriptionUtc, $sequenceData);

            // --- Appliquer les règles dynamiques ---
            $rules = json_decode($s['rules'] ?? '{}', true);
            if (!empty($rules['noWeekend'])) {
                $sendMoment = $this->adjustForWeekend($sendMoment);
            }
            if (!empty($rules['noHolidays'])) {
                $sendMoment = $this->adjustForHoliday($sendMoment);
            }
            // Toujours appliquer la règle d'heure tardive
            $sendMoment = $this->adjustForLateHour($sendMoment);

            $sendAtUtc = $sendMoment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            // --- Insert dans emailbridge_envoi ---
            $qbInsert = $this->db->getQueryBuilder();
            $qbInsert->insert('emailbridge_envoi')
                ->values([
                    'inscription_id' => $qbInsert->createNamedParameter($inscriptionId, \PDO::PARAM_INT),
                    'sequence_id'    => $qbInsert->createNamedParameter($s['id'], \PDO::PARAM_INT),
                    'send_at'        => $qbInsert->createNamedParameter($sendAtUtc),
                    'status'         => $qbInsert->createNamedParameter('en_attente'),
                    'attempts'       => $qbInsert->createNamedParameter(0),
                    'created_at'     => $qbInsert->createNamedParameter($nowUtc),
                    'updated_at'     => $qbInsert->createNamedParameter($nowUtc),
                ])
                ->executeStatement();

            $this->logger->info("Planifié sequence_id {$s['id']} pour inscription_id {$inscriptionId} à $sendAtUtc (UTC)");
            $created++;
        }

        $this->db->commit();
        $this->logger->info("scheduleEmailsForInscription: $created envois créés pour inscription $inscriptionId");
        
	// Annule les doublons si l'adresse a déjà reçu les mêmes séquences
	$this->cancelDuplicatePendingEmails($ins['email']);
 	
	return $created;
    } catch (\Throwable $e) {
        $this->db->rollBack();
        $this->logger->error("Erreur lors du scheduling pour inscription $inscriptionId : " . $e->getMessage());
        return 0;
    }
}




    public function isAlreadyInscribed(string $email, int $parcoursId): bool {
        $qb = $this->db->getQueryBuilder();
        $id = $qb->select('i.id')
                 ->from('emailbridge_inscription', 'i')
                 ->where($qb->expr()->eq('i.email', $qb->createNamedParameter($email)))
                 ->andWhere($qb->expr()->eq('i.parcours_id', $qb->createNamedParameter($parcoursId)))
                 ->executeQuery()
                 ->fetchOne();

        return !empty($id);
    }

/**
 * Si la date tombe un samedi/dimanche → reporte au lundi à la même heure.
 */
private function adjustForWeekend(\DateTimeImmutable $dt): \DateTimeImmutable {
    $dayOfWeek = (int)$dt->format('N'); // 6=samedi, 7=dimanche

    if ($dayOfWeek === 6) { // samedi
        $dt = $dt->modify('+2 days');
    } elseif ($dayOfWeek === 7) { // dimanche
        $dt = $dt->modify('+1 day');
    }

    // ⏰ On garde l’heure existante
    return $dt;
}

/**
 * Si la date est un jour férié → reporte au jour ouvré suivant, même heure.
 */
private function adjustForHoliday(\DateTimeImmutable $dt): \DateTimeImmutable {
    $year = (int)$dt->format('Y');
    $feries = $this->getFrenchHolidays($year);

    while (true) {
        // Si la date dépasse l’année courante, on recharge les jours fériés
        $newYear = (int)$dt->format('Y');
        if ($newYear !== $year) {
            $year = $newYear;
            $feries = $this->getFrenchHolidays($year);
        }

        // Si c’est un jour férié → décale d’un jour
        if (in_array($dt->format('Y-m-d'), $feries, true)) {
            $dt = $dt->modify('+1 day');
            continue;
        }

        // Si c’est un week-end → passe au lundi
        $dayOfWeek = (int)$dt->format('N');
        if ($dayOfWeek >= 6) {
            $dt = $dt->modify('next monday');
            continue;
        }

        // Sinon, c’est bon
        break;
    }

    return $dt;
}

/**
 * Si l’heure est trop tardive (ex. après 20h) → décale au lendemain à la même heure ou heure ajustée.
 */
private function adjustForLateHour(\DateTimeImmutable $dt): \DateTimeImmutable {
    $hour = (int)$dt->format('H');
    if ($hour >= 20) {
        // Décale au lendemain à la même heure
        $dt = $dt->modify('+1 day')->setTime($hour, (int)$dt->format('i'));
    }
    return $dt;
}

/**
 * Retourne la liste des jours fériés français (calculs mobiles inclus)
 */
private array $holidayCache = [];

private function getFrenchHolidays(int $year): array {
    if (isset($this->holidayCache[$year])) {
        return $this->holidayCache[$year];
    }

    $years = [$year, $year + 1];
    $feries = [];

    foreach ($years as $y) {
        $easter = (new \DateTimeImmutable('@' . easter_date($y)))->setTime(0, 0);
        $feries = array_merge($feries, [
            "$y-01-01",
            $easter->modify('+1 day')->format('Y-m-d'),
            "$y-05-01",
            "$y-05-08",
            $easter->modify('+39 days')->format('Y-m-d'),
            $easter->modify('+50 days')->format('Y-m-d'),
            "$y-07-14",
            "$y-08-15",
            "$y-11-01",
            "$y-11-11",
            "$y-12-25",
        ]);
    }

    return $this->holidayCache[$year] = $feries;
}



/**
 * Si le parcours contient une redirection (colonne redirect_to_id),
 * renvoie l'ID du parcours cible à suivre, sinon renvoie le parcours d’origine.
 */
private function getRedirectedParcoursId(int $parcoursId): int {
    try {
        $qb = $this->db->getQueryBuilder();
        $target = $qb->select('redirect_to_id')
                     ->from('emailbridge_parcours')
                     ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
                     ->setMaxResults(1)
                     ->executeQuery()
                     ->fetchOne();
        return $target ? (int)$target : $parcoursId;
    } catch (\Throwable $e) {
        $this->logger->error("Erreur getRedirectedParcoursId: ".$e->getMessage());
        return $parcoursId;
    }
}

/**
 * Annule les envois en attente pour un email déjà envoyé
 * (évite les doublons si l’adresse est inscrite sur plusieurs parcours).
 */
private function cancelDuplicatePendingEmails(string $email): void {
    try {
        // 1️⃣ Récupère toutes les inscriptions associées à cet email
        $qb1 = $this->db->getQueryBuilder();
        $inscriptions = $qb1->select('id')
            ->from('emailbridge_inscription')
            ->where($qb1->expr()->eq('email', $qb1->createNamedParameter($email)))
            ->executeQuery()
            ->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($inscriptions)) {
            return;
        }

        // 2️⃣ Récupère toutes les séquences déjà "envoyées"
        $qb2 = $this->db->getQueryBuilder();
        $sentSeqs = $qb2->selectDistinct('sequence_id')
            ->from('emailbridge_envoi')
            ->where($qb2->expr()->in('inscription_id', $qb2->createParameter('ids')))
            ->andWhere($qb2->expr()->eq('status', $qb2->createNamedParameter('envoye')))
            ->setParameter('ids', $inscriptions, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
            ->executeQuery()
            ->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($sentSeqs)) {
            return;
        }

        // 3️⃣ Annule tous les envois "en attente" pour ces mêmes séquences
        $qb3 = $this->db->getQueryBuilder();
        $qb3->update('emailbridge_envoi')
            ->set('status', $qb3->createNamedParameter('annule'))
            ->where($qb3->expr()->in('inscription_id', $qb3->createParameter('ids')))
            ->andWhere($qb3->expr()->in('sequence_id', $qb3->createParameter('seqs')))
            ->andWhere($qb3->expr()->eq('status', $qb3->createNamedParameter('en_attente')))
            ->setParameter('ids', $inscriptions, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
            ->setParameter('seqs', $sentSeqs, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
            ->executeStatement();

        $this->logger->info("cancelDuplicatePendingEmails: email $email → doublons annulés pour séquences [" . implode(',', $sentSeqs) . "]");
    } catch (\Throwable $e) {
        $this->logger->error("cancelDuplicatePendingEmails error: " . $e->getMessage(), ['email' => $email]);
    }
}


}
