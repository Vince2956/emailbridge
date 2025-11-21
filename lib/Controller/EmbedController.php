<?php

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RawResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCA\EmailBridge\Service\EmailService;
use OCA\EmailBridge\Controller\FormController;

class EmbedController extends Controller {

    private EmailService $emailService;
    private FormController $formController;

    public function __construct(
        string $AppName,
        IRequest $request,
        EmailService $emailService,
        FormController $formController
    ) {
        parent::__construct($AppName, $request);
        $this->emailService = $emailService;
        $this->formController = $formController;
    }

    /**
     * Ajoute les headers CORS nécessaires
     */
    private function cors(RawResponse|DataResponse $response): RawResponse|DataResponse {
        $origin = $this->request->getHeader('Origin');
        if ($origin) {
            $response->addHeader('Access-Control-Allow-Origin', $origin); // ou '*' si tu veux
        }
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
        $response->addHeader('Access-Control-Allow-Credentials', 'true');
        return $response;
    }

    /**
     * OPTIONS / Préflight
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function options(): DataResponse {
        return $this->cors(new DataResponse([], Http::STATUS_NO_CONTENT));
    }

    /**
     * Sert le fichier embed.js avec CORS
     *
     * @NoCSRFRequired
     * @PublicPage
     */
    public function js(): RawResponse {
        $path = \OC::$SERVERROOT . '/apps/emailbridge/js/embed.js';
        if (!file_exists($path)) {
            return new RawResponse('Fichier introuvable', 404);
        }

        $content = file_get_contents($path);

        $response = new RawResponse($content, 200, ['Content-Type' => 'application/javascript']);
        return $this->cors($response);
    }

    /**
     * POST → Soumission externe depuis l'embed
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function submitExternal(int $parcoursId): DataResponse {
        $email = $this->request->getParam('email');

        if (!$email) {
            return $this->cors(new DataResponse([
                'status' => 'error',
                'message' => 'Email manquant'
            ], 400));
        }

        // Appelle directement la méthode submitEmbed du FormController
        $response = $this->formController->submitEmbed($parcoursId, $email);

        return $this->cors($response);
    }
}
