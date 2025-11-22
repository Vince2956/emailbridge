<?php

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
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
    private function cors(DataResponse $response): DataResponse {
        $origin = $this->request->getHeader('Origin') ?? '*';

        $response->addHeader('Access-Control-Allow-Origin', $origin);
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
        return $this->cors(new DataResponse([], 204));
    }

    /**
     * Sert le fichier embed.js
     *
     * @NoCSRFRequired
     * @PublicPage
     */
    public function js(): DataDisplayResponse {
        $path = \OC::$SERVERROOT . '/apps/emailbridge/js/embed.js';

        if (!file_exists($path)) {
            return new DataDisplayResponse(
                "embed.js introuvable",
                404,
                ['Content-Type' => 'text/plain']
            );
        }

        $content = file_get_contents($path);

        $response = new DataDisplayResponse(
            $content,
            200,
            ['Content-Type' => 'application/javascript']
        );

        // CORS dynamique
        $origin = $this->request->getHeader('Origin') ?? '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->addHeader('Access-Control-Allow-Headers', 'Content-Type');
        $response->addHeader('Access-Control-Allow-Credentials', 'true');

        return $response;
    }


    /**
     * POST → Soumission externe
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

        $response = $this->formController->submitEmbed($parcoursId, $email);

        return $this->cors($response);
    }
}
