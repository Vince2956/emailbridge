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
public function helloAsso(string $userId): DataResponse
{
    try {
        // 1️⃣ Vérifie le token utilisateur
        $tokenFromRequest = $this->request->getParam('token');
        $tokenFromConfig  = $this->config->getUserValue($userId, 'emailbridge', 'webhook_token', '');
        if (!$tokenFromRequest || $tokenFromRequest !== $tokenFromConfig) {
            return new DataResponse(['status'=>'Unauthorized'], 401);
        }

        // 2️⃣ Payload
        $payload = json_decode(file_get_contents('php://input'), true);
        $this->logger->info('HelloAsso payload', ['payload' => $payload]);
        if (!$payload) {
            return new DataResponse(['status'=>'error','message'=>'Payload vide'], 400);
        }

        // ✅ Récupère l'email du payeur
        $email = $payload['data']['payer']['email'] ?? null;
        if (!$email) {
            return new DataResponse(['status'=>'error','message'=>'Email manquant'], 400);
        }

        // ✅ Boucle sur les items
        $formSlug = $payload['data']['formSlug'] ?? null;

	if (!$formSlug) {
	    return new DataResponse(['status'=>'error','message'=>'formSlug manquant'], 400);
	}
	
	$parcours = $this->emailService->getParcoursByHelloAssoItemForUser($formSlug, $userId);

	if (!$parcours) {
	    return new DataResponse(['status'=>'ignored','message'=>'Produit non lié'], 200);
	}
	
	$parcoursId = (int)$parcours['id'];
	
	// Inscription si pas déjà faite
	if (!$this->emailService->isAlreadyInscribed($email, $parcoursId)) {
	    $documentUrl = $this->emailService->getDocumentUrlForParcours($parcoursId);
	    $this->emailService->storeAndSend($email, $documentUrl, $parcoursId, false);
	}

	return new DataResponse(['status'=>'ok','message'=>'Inscription enregistrée']);

    } catch (\Throwable $e) {
        $this->logger->error('Erreur webhook HelloAsso', ['exception' => $e]);
        return new DataResponse(['status'=>'error','message'=>$e->getMessage()], 500);
    }
}
}
