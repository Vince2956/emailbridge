<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Service;

use OCP\IDBConnection;
use OCP\Mail\IMailer;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;           // <-- Pour TYPE_LINK
use OCP\Files\IRootFolder;
use OCP\Constants;              // <-- Pour PERMISSION_READ
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCA\EmailBridge\Defaults;

class EmailService
{
    private IDBConnection $db;
    private IMailer $mailer;
    private ShareManager $shareManager;
    private IRootFolder $rootFolder;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private IConfig $config;

    /** @var SequenceService|null Inject via setter to avoid cycles */
    private ?SequenceService $sequenceService = null;

    public function __construct(
        IDBConnection $db,
        IMailer $mailer,
        ShareManager $shareManager,
        IRootFolder $rootFolder,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger,
        IConfig $config
    ) {
        $this->db = $db;
        $this->mailer = $mailer;
        $this->shareManager = $shareManager;
        $this->rootFolder = $rootFolder;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Setter pour SequenceService afin d'éviter l'instanciation directe et les cycles DI.
     */
    public function setSequenceService(SequenceService $service): void
    {
        $this->sequenceService = $service;
    }

    /**
     * Récupère un row de template (form) pour un parcours + type ; fallback sur is_default.
     * Retourne tableau associatif ou [].
     */
    private function fetchFormRow(int $parcoursId, string $type): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('emailbridge_form')
            ->where($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->setMaxResults(1);

        $row = $qb->executeQuery()->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        // fallback default
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('emailbridge_form')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('is_default', $qb->createNamedParameter(1)))
            ->setMaxResults(1);

        $row = $qb->executeQuery()->fetch(\PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    /**
     * Récupère document_url pour un parcours (ou null si absent).
     */
    public function getDocumentUrlForParcours(int $parcoursId): ?string
    {
        $qb = $this->db->getQueryBuilder();
        $val = $qb->select('document_url')
            ->from('emailbridge_parcours')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return $val !== false ? $val : null;
    }

    /**
     * Crée ou récupère un lien public Nextcloud pour un fichier (path relatif dans user).
     * Si $path empty -> retourne empty string.
     */
    private function getPublicLink(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        try {
            $userId = \OC_User::getUser();
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node = $userFolder->get($path);

            $shares = $this->shareManager->getSharesByPath(
                $node,
                IShare::TYPE_LINK,
                $userId,
                true
            );

            if (!empty($shares)) {
                $token = $shares[0]->getToken();
            } else {
                $share = $this->shareManager->newShare();
                $share->setNode($node)
                      ->setShareType(IShare::TYPE_LINK)
                      ->setPermissions(Constants::PERMISSION_READ)
                      ->setSharedBy($userId);

                $share = $this->shareManager->createShare($share);
                $token = $share->getToken();
            }

            return $this->urlGenerator->linkToRouteAbsolute(
                'files_sharing.sharecontroller.showShare',
                ['token' => $token]
            );

        } catch (\Throwable $e) {
            $this->logger->error('getPublicLink error: ' . $e->getMessage(), ['path' => $path]);
            return '';
        }
    }



    /**
     * Génère un token sécurisé.
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Construire l'URL de confirmation absolue à partir du token.
     */
    private function getConfirmUrl(string $token): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('emailbridge.form.confirm', ['token' => $token]);
    }

    /**
     * Retourne l'url de base du serveur (overwrite.cli.url).
     */
    private function getBaseUrl(): string
    {
        return rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
    }

    /**
     * Normalise document_url pour écriture en base : jamais null, toujours string ('' si vide).
     */
    private function ensureDocumentUrl(?string $url): string
    {
        return $url === null ? '' : $url;
    }

    /**
     * Crée la ligne dans emailbridge_liste et renvoie l'ID inséré.
     * Note: created_at est en UTC.
     */
    private function createListeRow(string $email, int $parcoursId, string $token, string $documentUrl): int
    {
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $qb = $this->db->getQueryBuilder();
        $qb->insert('emailbridge_liste')
            ->values([
                'email' => $qb->createNamedParameter($email),
                'token' => $qb->createNamedParameter($token),
                'confirmed' => $qb->createNamedParameter(0),
                'parcours_id' => $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT),
                'document_url' => $qb->createNamedParameter($this->ensureDocumentUrl($documentUrl)),
                'created_at' => $qb->createNamedParameter($nowUtc)
            ])->executeStatement();

        // Récupérer l'id de la ligne insérée : on sélectionne la row par token (unique)
        $qbSelect = $this->db->getQueryBuilder();
        $row = $qbSelect->select('id')
            ->from('emailbridge_liste')
            ->where($qbSelect->expr()->eq('token', $qbSelect->createNamedParameter($token)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetch();

        return (int)($row['id'] ?? 0);
    }


    /**
     * Retourne true si le parcours a le flag bypass_file activé.
     */
    public function isBypassEnabled(int $parcoursId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $val = $qb->select('bypass_file')
            ->from('emailbridge_parcours')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($val === false) {
            return false;
        }

        // Normalise la valeur vers bool
        return ((int)$val) === 1;
    }

    public function sendBypassConfirmationEmail(string $email, int $parcoursId): bool
    {
        try {
            // 1) récupérer éventuelle ligne existante dans emailbridge_liste pour ce email + parcours
            $qb = $this->db->getQueryBuilder();
            $existingRow = $qb->select('id', 'token')
                ->from('emailbridge_liste')
                ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
                ->andWhere($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            // 2) obtenir ou générer token
            $token = null;
            if ($existingRow && !empty($existingRow['token'])) {
                $token = (string)$existingRow['token'];
            } else {
                $token = bin2hex(random_bytes(16));
            }

            // 3) si pas d'existant => insert ; sinon, s'il n'avait pas de token, update la ligne avec le token
            if (!$existingRow) {
                $qbIns = $this->db->getQueryBuilder();
                $qbIns->insert('emailbridge_liste')
                    ->values([
                        'email'       => $qbIns->createNamedParameter($email),
                        'parcours_id' => $qbIns->createNamedParameter($parcoursId, \PDO::PARAM_INT),
                        'token'       => $qbIns->createNamedParameter($token),
                        'confirmed'   => $qbIns->createNamedParameter(0, \PDO::PARAM_INT),
                        'created_at'  => $qbIns->createNamedParameter($nowUtc),
                    ])->executeStatement();
            } elseif (empty($existingRow['token'])) {
                // mise à jour du token si ligne existante mais sans token
                $qbUpd = $this->db->getQueryBuilder();
                $qbUpd->update('emailbridge_liste')
                    ->set('token', $qbUpd->createNamedParameter($token))
                    ->where($qbUpd->expr()->eq('id', $qbUpd->createNamedParameter((int)$existingRow['id'], \PDO::PARAM_INT)))
                    ->executeStatement();
            }

            // 4) construire l'URL de confirmation (route publique)
            $confirmUrl = $this->urlGenerator->linkToRouteAbsolute('emailbridge.form.confirm', ['token' => $token]);

            // 5) récupérer template email (reuse pattern storeAndSend)
            $row = $this->fetchFormRow($parcoursId, 'email');
            if (!empty($row)) {
                $subject = $row['titre'] ?: Defaults::confirmationSubject();
                $bodyText = $row['contenu_text'] ?: Defaults::confirmationBody();
                $buttonText = $row['label_bouton'] ?: Defaults::confirmationButton();
            } else {
                $subject = Defaults::confirmationSubject();
                $bodyText = Defaults::confirmationBody();
                $buttonText = Defaults::confirmationButton();
            }

            // Construire template Nextcloud (bouton mène à confirm route — pour bypass on affichera confirm_pending)
            $template = $this->mailer->createEMailTemplate('emailbridge.confirmation', []);
            $template->setSubject($subject);
            $template->addHeader();
            $template->addHeading('Confirmation requise', false);
            $template->addBodyText($bodyText);
            $template->addBodyButton($buttonText, $confirmUrl);
            $template->addBodyText("Si vous n’êtes pas à l’origine de cette demande, ignorez simplement ce message.");
            $template->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo([$email]);
            $message->useTemplate($template);
            $this->mailer->send($message);

            $this->logger->info("sendBypassConfirmationEmail: mail de confirmation (bypass) envoyé à {$email} (parcours {$parcoursId}) token={$token}");

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('sendBypassConfirmationEmail error: ' . $e->getMessage(), [
                'email' => $email,
                'parcoursId' => $parcoursId
            ]);
            return false;
        }
    }





    /**
     * storeAndSend — enregistre la liste et envoie l'email de confirmation Nextcloud.
     * $documentPath peut être un chemin (pour getPublicLink) ou une URL déjà publique.
     */
    public function storeAndSend(string $email, ?string $documentPath, int $parcoursId): void
    {
        try {
            $token = $this->generateToken();

            // Si documentPath ressemble à un path interne => try getPublicLink, sinon on peut considérer qu'on reçoit déjà une URL publique.
            $publicUrl = '';
            if (!empty($documentPath)) {
                // Tentative prudente : si $documentPath starts with http => we use it, else try getPublicLink
                if (preg_match('#^https?://#i', $documentPath)) {
                    $publicUrl = $documentPath;
                } else {
                    $publicUrl = $this->getPublicLink($documentPath);
                }
            }

            $this->createListeRow($email, $parcoursId, $token, $publicUrl);

            $confirmUrl = $this->getConfirmUrl($token);

            // Récupérer template/form personnalisé (type = 'email')
            $row = $this->fetchFormRow($parcoursId, 'email');

            if (!empty($row)) {
                $subject = $row['titre'] ?: Defaults::confirmationSubject();
                $bodyText = $row['contenu_text'] ?: Defaults::confirmationBody();
                $buttonText = $row['label_bouton'] ?: Defaults::confirmationButton();
            } else {
                $subject = Defaults::confirmationSubject();
                $bodyText = Defaults::confirmationBody();
                $buttonText = Defaults::confirmationButton();
            }

            // Construire template Nextcloud
            $template = $this->mailer->createEMailTemplate('emailbridge.confirmation', []);
            $template->setSubject($subject);
            $template->addHeader();
            $template->addHeading('Confirmation requise', false);
            $template->addBodyText($bodyText);
            $template->addBodyButton($buttonText, $confirmUrl);
            $template->addBodyText("Si vous n’êtes pas à l’origine de cette demande, ignorez simplement ce message.");
            $template->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo([$email]);
            $message->useTemplate($template);
            $this->mailer->send($message);

            $this->logger->info("storeAndSend: mail de confirmation envoyé à {$email} (parcours {$parcoursId})");
        } catch (\Throwable $e) {
            $this->logger->error('storeAndSend error: ' . $e->getMessage(), [
                'email' => $email,
                'parcoursId' => $parcoursId
            ]);
            // On ne lance pas d'exception pour ne pas casser le flow; l'appelant peut checker les logs.
        }
    }

    /**
     * Récupère la "liste" liée à un token pour la vue confirm.
     */
    public function getInscriptionByToken(string $token): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('l.id AS liste_id', 'l.email', 'l.parcours_id', 'l.confirmed AS is_confirmed', 'l.document_url')
            ->from('emailbridge_liste', 'l')
            ->where($qb->expr()->eq('l.token', $qb->createNamedParameter($token)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetch();

        return $row ?: null;
    }

    /**
 * confirmToken — validation du token (24h), marquage liste.confirmed, insertion inscription,
 * et (si SequenceService injecté) scheduling des emails.
 *
 * Retourne document_url (string) — qui peut être '' — ou NULL si token invalide/expiré.
 */
    public function confirmToken(string $token): ?string
    {
        try {
            $this->logger->info("confirmToken: début pour token={$token}");

            $qbSelect = $this->db->getQueryBuilder();
            $row = $qbSelect->select('*')
                ->from('emailbridge_liste')
                ->where($qbSelect->expr()->eq('token', $qbSelect->createNamedParameter($token)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            if (!$row) {
                $this->logger->warning("confirmToken: token introuvable");
                return null;
            }
            $this->logger->info("confirmToken: liste trouvée id={$row['id']} email={$row['email']} document_url={$row['document_url']}");

            // Validation 24h
            $createdAt = new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (($now->getTimestamp() - $createdAt->getTimestamp()) > 86400) {
                $this->logger->info("confirmToken: token expiré pour liste_id {$row['id']}");
                return null;
            }

            // 1) marquer la liste comme confirmée
            $qbUpdate = $this->db->getQueryBuilder();
            $qbUpdate->update('emailbridge_liste')
                ->set('confirmed', $qbUpdate->createNamedParameter(true, \PDO::PARAM_BOOL))
                ->set('confirmed_at', $qbUpdate->createNamedParameter($now->format('Y-m-d H:i:s')))
                ->where($qbUpdate->expr()->eq('token', $qbUpdate->createNamedParameter($token)))
                ->executeStatement();
            $this->logger->info("confirmToken: liste marquée comme confirmée id={$row['id']}");

            // 2) récupérer la valeur bypass_file depuis le parcours correspondant
            $qbParcours = $this->db->getQueryBuilder();
            $parcours = $qbParcours->select('bypass_file')
                ->from('emailbridge_parcours')
                ->where($qbParcours->expr()->eq('id', $qbParcours->createNamedParameter($row['parcours_id'], \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            $bypassFile = isset($parcours['bypass_file']) ? (int)$parcours['bypass_file'] : 0;
            $this->logger->info("confirmToken: bypass_file du parcours id={$row['parcours_id']} => {$bypassFile}");

            // 3) Insérer l'inscription (on copie le bypass_file du parcours)
            $nowUtc = $now->format('Y-m-d H:i:s');
            $qbInsert = $this->db->getQueryBuilder();
            $qbInsert->insert('emailbridge_inscription')
                ->values([
                    'parcours_id'   => $qbInsert->createNamedParameter($row['parcours_id'], \PDO::PARAM_INT),
                    'liste_id'      => $qbInsert->createNamedParameter($row['id'], \PDO::PARAM_INT),
                    'email'         => $qbInsert->createNamedParameter($row['email']),
                    'date_inscription' => $qbInsert->createNamedParameter($nowUtc),
                    'bypass_file'   => $qbInsert->createNamedParameter($bypassFile, \PDO::PARAM_INT),
                    'created_at'    => $qbInsert->createNamedParameter($nowUtc),
                    'updated_at'    => $qbInsert->createNamedParameter($nowUtc)
                ])->executeStatement();
            $this->logger->info("confirmToken: inscription insérée pour liste_id={$row['id']} parcours_id={$row['parcours_id']}");

            // 4) Récupérer l'inscription créée
            $qbSelIns = $this->db->getQueryBuilder();
            $inscription = $qbSelIns->select('id')
                ->from('emailbridge_inscription')
                ->where($qbSelIns->expr()->eq('liste_id', $qbSelIns->createNamedParameter($row['id'], \PDO::PARAM_INT)))
                ->andWhere($qbSelIns->expr()->eq('parcours_id', $qbSelIns->createNamedParameter($row['parcours_id'], \PDO::PARAM_INT)))
                ->andWhere($qbSelIns->expr()->eq('date_inscription', $qbSelIns->createNamedParameter($nowUtc)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            if ($inscription && !empty($inscription['id'])) {
                $inscriptionId = (int)$inscription['id'];
                $this->logger->info("confirmToken: inscription récupérée id={$inscriptionId}");

                // 5) Planifier les emails si SequenceService injecté
                if ($this->sequenceService !== null) {
                    try {
                        $this->sequenceService->scheduleEmailsForInscription($inscriptionId);
                        $this->logger->info("confirmToken: emails planifiés pour inscription id={$inscriptionId}");
                    } catch (\Throwable $e) {
                        $this->logger->error('confirmToken: erreur scheduleEmailsForInscription: ' . $e->getMessage(), [
                            'inscriptionId' => $inscriptionId
                        ]);
                    }
                } else {
                    $this->logger->info('confirmToken: SequenceService non-injecté — emails non planifiés.');
                }
            } else {
                $this->logger->warning("confirmToken: impossible de récupérer l'inscription créée pour liste_id={$row['id']}");
            }

            // 6) Retour du document_url de la table liste
            $this->logger->info("confirmToken: document_url retourné => {$row['document_url']}");
            return $row['document_url'] ?? '';
        } catch (\Throwable $e) {
            $this->logger->error('confirmToken error: ' . $e->getMessage(), ['token' => $token]);
            return null;
        }
    }




    /**
     * Méthode utilitaire d'envoi simple (plain text) si nécessaire.
     */
    private function send(string $to, string $subject, string $body, array $attachments = []): void
    {
        $message = $this->mailer->createMessage();
        $message->setTo([$to]);
        $message->setSubject($subject);
        $message->setPlainBody($body);
        // TODO: gérer attachments si nécessaire
        $this->mailer->send($message);
    }

    /**
     * Envoie un email construit depuis une séquence.
     */
    public function sendEmailFromSequence(
        int $sequenceId,
        string $toEmail,
        int $inscriptionId,
        array $extraPlaceholders = [],
        bool $addUnsubscribe = false
    ): bool {
        try {
            // --- 1) Récupérer la séquence ---
            $qb = $this->db->getQueryBuilder();
            $sequence = $qb->select('*')
                ->from('emailbridge_sequence')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($sequenceId, \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            if (!$sequence) {
                $this->logger->error("sendEmailFromSequence: Séquence #$sequenceId introuvable");
                return false;
            }

            $subject = $sequence['sujet'] ?? '(Sans sujet)';
            $sequenceContent = $sequence['contenu'] ?? '';

            // --- 2) Récupérer inscription + token + document_url ---
            $qbInscription = $this->db->getQueryBuilder();
            $inscription = $qbInscription->select('i.id', 'i.email', 'i.parcours_id', 'i.liste_id', 'l.token', 'l.document_url')
                ->from('emailbridge_inscription', 'i')
                ->innerJoin('i', 'emailbridge_liste', 'l', 'i.liste_id = l.id')
                ->where($qbInscription->expr()->eq('i.id', $qbInscription->createNamedParameter($inscriptionId, \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            if (!$inscription) {
                $this->logger->warning("sendEmailFromSequence: inscription #$inscriptionId introuvable");
                return false;
            }

            // --- 3) placeholders ---
            $placeholders = [
                '{{inscriptionId}}' => $inscription['id'] ?? '',
                '{{email}}'         => $inscription['email'] ?? '',
                '{{parcoursId}}'    => $inscription['parcours_id'] ?? '',
                '{{documentUrl}}'   => $inscription['document_url'] ?? '',
            ];
            $placeholders = array_merge($placeholders, $extraPlaceholders);

            $contentWithVars = strtr($sequenceContent, $placeholders);
            $contentWithVars = trim($contentWithVars, '"');
            $contentWithVars = stripslashes($contentWithVars);
            $blocks = json_decode($contentWithVars, true);
            if (!is_array($blocks)) {
                $blocks = [['type' => 'texte', 'content' => $contentWithVars]];
            }

            // --- 4) template ---
            $template = $this->mailer->createEMailTemplate('emailbridge.sequence', []);
            $template->setSubject($subject);
            $template->addHeader();
            //$template->addHeading($subject, false);

            $lastTextIndex = null;

            // --- sécuriser $blocks avant la boucle ---
            if (!is_array($blocks)) {
                // si JSON invalide ou null -> on transforme en bloc texte unique
                $blocks = [
                    ['type' => 'texte', 'content' => (string)$sequenceContent]
                ];
            }

            foreach ($blocks as $block) {
                $type = $block['type'] ?? 'texte';
                $content = $block['content'] ?? '';

                // Nettoyage du HTML
                $content = trim($content);
                $content = preg_replace('/\s+/', ' ', $content);

                switch ($type) {
                    case 'titre':
                        // Titre seul (rare, selon structure de tes blocs)
                        $content = preg_replace_callback(
                            '/<(h[1-3])([^>]*)>(.*?)<\/\1>/i',
                            function ($matches) {
                                $tag = $matches[1];
                                $attrs = $matches[2];
                                $text = $matches[3];
                                // Force style
                                $attrs = preg_replace('/style="[^"]*"/i', '', $attrs);
                                return sprintf(
                                    '<%s style="color:#333;font-weight:bold;margin:0 0 10px 0;">%s</%s>',
                                    $tag,
                                    $text,
                                    $tag
                                );
                            },
                            $content
                        );
                        $template->addBodyText($content, true);
                        break;

                    case 'texte':
                        // D’abord : forcer les titres à être plus sombres
                        $content = preg_replace_callback(
                            '/<(h[1-3])([^>]*)>(.*?)<\/\1>/i',
                            function ($matches) {
                                $tag = $matches[1];
                                $attrs = $matches[2];
                                $text = $matches[3];
                                $attrs = preg_replace('/style="[^"]*"/i', '', $attrs);
                                return sprintf(
                                    '<%s style="color:#333;font-weight:bold;margin:0 0 10px 0;">%s</%s>',
                                    $tag,
                                    $text,
                                    $tag
                                );
                            },
                            $content
                        );

                        // Ensuite : appliquer couleur par défaut au texte
                        $content = '<div style="color:#777; line-height:1.6;">' . $content . '</div>';
                        $template->addBodyText($content, true);
                        break;

                    case 'image':
                        if (!empty($block['url'])) {
                            $imgHtml = '<div style="text-align:center;margin:15px 0;">
                    <img src="' . htmlspecialchars($block['url'], ENT_QUOTES) . '" alt=""
                         style="max-width:100%;border-radius:6px;">
                </div>';
                            $template->addBodyText($imgHtml, true);
                        }
                        break;

                    case 'bouton':
                        $label = $block['label'] ?? 'Voir';
                        $url   = $block['url'] ?? '#';
                        $trackingBase = $this->urlGenerator->getAbsoluteURL('/index.php/apps/emailbridge/tracking/click');

                        $trackingUrl = sprintf(
                            '%s?email_id=%d&inscription_id=%d&link=%s',
                            $trackingBase,
                            (int)$sequenceId,
                            (int)($inscription['id'] ?? 0),
                            urlencode($url)
                        );

                        $btnHtml = '<div style="text-align:center;margin:10px 0 0 0;">
                <a href="' . htmlspecialchars($trackingUrl, ENT_QUOTES) . '"
                   style="background:#00679e;color:#fff;text-decoration:none;
                          padding:10px 20px;border-radius:6px;display:inline-block;">
                    ' . htmlspecialchars($label) . '
                </a>
            </div>';

                        $template->addBodyText($btnHtml, true);
                        break;

                    default:
                        $template->addBodyText($content, true);
                        break;
                }
            }




            // --- 5) Tracking pixel ---
            $trackingBase = $this->urlGenerator->linkToRouteAbsolute('emailbridge.tracking.trackOpen');
            if (strpos($trackingBase, '192.168.') === false && strpos($trackingBase, 'localhost') === false) {
                $trackingBase = str_replace('http://', 'https://', $trackingBase);
            }

            $cacheBuster = bin2hex(random_bytes(6));
            $trackingUrl = sprintf(
                '%s?email_id=%d&inscription_id=%d&r=%s',
                $trackingBase,
                (int)$sequenceId,
                (int)$inscriptionId,
                $cacheBuster
            );

            $trackingPixelHtml = sprintf(
                '<p style="margin:0;padding:0;text-align:center;">
                <img src="%s" width="1" height="1" alt="" style="display:none;" />
            </p>',
                $trackingUrl
            );

            $template->addBodyText($trackingPixelHtml, true);

            // --- 6) Footer unsubscribe ---
            if ($addUnsubscribe) {
                $qbParcours = $this->db->getQueryBuilder();
                $unsubscribeRow = $qbParcours->select('unsubscribe_text')
                    ->from('emailbridge_parcours')
                    ->where($qbParcours->expr()->eq('id', $qbParcours->createNamedParameter($inscription['parcours_id'], \PDO::PARAM_INT)))
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetch();

                $unsubscribeText = $unsubscribeRow['unsubscribe_text'] ?? Defaults::unsubscribeText();
                $unsubscribeText = nl2br($unsubscribeText);

                $base = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
                $query = http_build_query([
                    'email_id' => (int)$sequenceId,
                    'inscription_id' => (int)$inscription['id']
                ]);
                $unsubscribeUrl = $base . '/index.php/apps/emailbridge/unsubscribe?' . $query;

                $unsubscribeHtml = '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8') . '">cliquez ici</a>';
                $template->addFooter(str_replace('{{unsubscribe_link}}', $unsubscribeHtml, $unsubscribeText));
            }

            // --- 7) Création du message et envoi ---
            $message = $this->mailer->createMessage();
            $message->setTo([$toEmail]);
            $message->useTemplate($template); // ⚠️ PAS de $htmlBody
            $this->mailer->send($message);

            // --- 8) Statistiques ---
            $this->incrementEmailStat($sequence['id'], $inscription['id'], 'sent');
            $this->logger->info("sendEmailFromSequence: email envoyé à {$toEmail} (sequence {$sequenceId})");

            return true;

        } catch (\Throwable $e) {
            $this->logger->error("sendEmailFromSequence error: " . $e->getMessage(), [
                'sequenceId' => $sequenceId,
                'toEmail' => $toEmail,
                'inscriptionId' => $inscriptionId,
            ]);
            return false;
        }
    }



    /**
     * Crée ou récupère une inscription à partir d'une ligne de liste (BYPASS).
     * Retourne le tableau de l'inscription créée ou existante.
     */
    public function getInscriptionFromListe(array $liste): ?array
    {
        try {
            if (empty($liste['id'])) {
                $this->logger->warning('getInscriptionFromListe: liste invalide', ['liste' => $liste]);
                return null;
            }

            $qb = $this->db->getQueryBuilder();

            // Vérifie si une inscription existe déjà pour ce liste_id
            $inscription = $qb->select('*')
                ->from('emailbridge_inscription')
                ->where($qb->expr()->eq('liste_id', $qb->createNamedParameter($liste['id'], \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            if ($inscription && !empty($inscription['id'])) {
                $this->logger->info("getInscriptionFromListe: inscription existante trouvée id={$inscription['id']} liste_id={$liste['id']}");
                return $inscription;
            }

            // Sinon, créer une nouvelle inscription
            $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $bypassFile = isset($liste['bypass_file']) ? (int)$liste['bypass_file'] : 0;

            $qbInsert = $this->db->getQueryBuilder();
            $qbInsert->insert('emailbridge_inscription')
                ->values([
                    'parcours_id'     => $qbInsert->createNamedParameter($liste['parcours_id'], \PDO::PARAM_INT),
                    'liste_id'        => $qbInsert->createNamedParameter($liste['id'], \PDO::PARAM_INT),
                    'email'           => $qbInsert->createNamedParameter($liste['email']),
                    'date_inscription' => $qbInsert->createNamedParameter($nowUtc),
                    'bypass_file'     => $qbInsert->createNamedParameter($bypassFile, \PDO::PARAM_INT),
                    'created_at'      => $qbInsert->createNamedParameter($nowUtc),
                    'updated_at'      => $qbInsert->createNamedParameter($nowUtc)
                ])->executeStatement();

            // Récupère l'inscription créée
            $qbSel = $this->db->getQueryBuilder();
            $inscription = $qbSel->select('*')
                ->from('emailbridge_inscription')
                ->where($qbSel->expr()->eq('liste_id', $qbSel->createNamedParameter($liste['id'], \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            if ($inscription && !empty($inscription['id'])) {
                $this->logger->info("getInscriptionFromListe: nouvelle inscription créée id={$inscription['id']} liste_id={$liste['id']}");
                return $inscription;
            }

            $this->logger->warning("getInscriptionFromListe: impossible de créer l'inscription pour liste_id={$liste['id']}");
            return null;

        } catch (\Throwable $e) {
            $this->logger->error('getInscriptionFromListe error: ' . $e->getMessage(), ['liste' => $liste]);
            return null;
        }
    }

    public function isAlreadyInscribed(string $email, int $parcoursId): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $row = $qb->select('id')
                ->from('emailbridge_inscription')
                ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
                ->andWhere($qb->expr()->eq('parcours_id', $qb->createNamedParameter($parcoursId, \PDO::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetch();

            return !empty($row);
        } catch (\Throwable $e) {
            $this->logger->error('EmailService::isAlreadyInscribed error: ' . $e->getMessage(), [
                'email' => $email,
                'parcoursId' => $parcoursId,
            ]);
            return false;
        }
    }
    public function incrementEmailStat(int $emailId, int $inscriptionId, string $statColumn): void
    {
        $qb = $this->db->getQueryBuilder();

        // Vérifie si la ligne existe déjà pour cet email et cette inscription
        $row = $qb->select('*')
            ->from('emailbridge_stats')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('email_id', $qb->createNamedParameter($emailId, \PDO::PARAM_INT)),
                    $qb->expr()->eq('inscription_id', $qb->createNamedParameter($inscriptionId, \PDO::PARAM_INT))
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetch();

        if ($row) {
            // --- Mise à jour : incrémenter la colonne + updated_at ---
            $qbUpdate = $this->db->getQueryBuilder();
            $qbUpdate->update('emailbridge_stats')
                ->set($statColumn, $qbUpdate->createFunction($statColumn . ' + 1'))
                ->set('updated_at', $qbUpdate->createNamedParameter(date('Y-m-d H:i:s')))
                ->where(
                    $qbUpdate->expr()->andX(
                        $qbUpdate->expr()->eq('email_id', $qbUpdate->createNamedParameter($emailId, \PDO::PARAM_INT)),
                        $qbUpdate->expr()->eq('inscription_id', $qbUpdate->createNamedParameter($inscriptionId, \PDO::PARAM_INT))
                    )
                )
                ->executeStatement();
        } else {
            // --- Insertion ---
            $columns = ['sent','opened','clicked','unsubscribed','stopped','redirected'];
            $values = [];
            foreach ($columns as $col) {
                $values[$col] = $col === $statColumn ? 1 : 0;
            }
            $values['email_id'] = $emailId;
            $values['inscription_id'] = $inscriptionId;
            $values['updated_at'] = date('Y-m-d H:i:s');

            $qbInsert = $this->db->getQueryBuilder();
            $qbInsert->insert('emailbridge_stats')
                ->values(array_map(fn ($v) => $qbInsert->createNamedParameter($v), $values))
                ->executeStatement();
        }
    }






}
