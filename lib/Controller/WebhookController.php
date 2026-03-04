<?php
declare(strict_types=1);

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCA\EmailBridge\Service\EmailService;

class WebhookController extends Controller
{
    private IConfig $config;
    private LoggerInterface $logger;
    private EmailService $emailService;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        LoggerInterface $logger,
        EmailService $emailService
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->logger = $logger;
        $this->emailService = $emailService;
    }

    #[PublicPage]
#[NoCSRFRequired]
public function helloAsso(): DataResponse
{
    try {
        // 1️⃣ Vérifie le token webhook
        $tokenFromRequest = $this->request->getParam('token');
        $tokenFromConfig  = $this->config->getAppValue('emailbridge', 'webhook_token', '');
        if (!$tokenFromRequest || $tokenFromRequest !== $tokenFromConfig) {
            return new DataResponse(['status'=>'Unauthorized'], 401);
        }

        // 2️⃣ Récupère le payload JSON
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!$payload) {
            return new DataResponse(['status'=>'error','message'=>'Payload vide'], 400);
        }

        $email = $payload['data']['email'] ?? null;
        $itemId = $payload['data']['itemId'] ?? null;

        if (!$email || !$itemId) {
            return new DataResponse(['status'=>'error','message'=>'Email ou produit manquant'], 400);
        }

        // 3️⃣ Trouve le parcours associé à ce produit
        $parcours = $this->emailService->getParcoursByHelloAssoItem($itemId);
        if (!$parcours) {
            return new DataResponse(['status'=>'error','message'=>'Aucun parcours associé à ce produit'], 404);
        }

        $parcoursId = (int)$parcours['id'];

        // 4️⃣ Vérifie si l’email est déjà inscrit
        if (!$this->emailService->isAlreadyInscribed($email, $parcoursId)) {
            // crée l’inscription dans la table liste comme submit()
            $documentUrl = $this->emailService->getDocumentUrlForParcours($parcoursId);
            $this->emailService->storeAndSend($email, $documentUrl, $parcoursId, false); 
            // Le dernier paramètre false signifie "ne pas envoyer le mail de confirmation direct",
            // EmailBridge gère ensuite la séquence
        }

        return new DataResponse(['status'=>'ok','message'=>'Inscription enregistrée']);

    } catch (\Throwable $e) {
        $this->logger->error('Erreur webhook HelloAsso', ['exception' => $e]);
        return new DataResponse(['status'=>'error','message'=>$e->getMessage()], 500);
    }
}
}
