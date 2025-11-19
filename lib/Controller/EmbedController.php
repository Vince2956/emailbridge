<?php

namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCA\EmailBridge\Service\FormService; // ton service pour récupérer les infos formulaire

class EmbedController extends Controller {

    private $formService;

    public function __construct($AppName, IRequest $request, FormService $formService) {
        parent::__construct($AppName, $request);
        $this->formService = $formService;
    }

    /**
     * Retourne le HTML minimal du formulaire pour intégration externe.
     * PAS de template Nextcloud, pas de header, pas de scripts NC.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getFormEmbed($id) {
        $parcours = $this->formService->getById($id);

        if (!$parcours) {
            return new DataResponse("Formulaire introuvable", 404);
        }

        // HTML minimal autonome
        $html = '
            <form id="emailbridge-form" data-id="'.intval($id).'">
                <input type="email" name="email" required placeholder="Votre email">
                <button type="submit">Envoyer</button>
            </form>
            <div id="emailbridge-result"></div>
        ';

        return new DataResponse([
            'html' => $html
        ]);
    }

    /**
     * Soumission externe
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function submitExternal($id) {
        $email = $this->request->getParam('email');

        if (!$email) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Email manquant'
            ], 400);
        }

        $ok = $this->formService->handleSubmission($id, $email);

        return new DataResponse([
            'status' => $ok ? 'ok' : 'error',
            'message' => $ok ? 'Email enregistré ✔️' : 'Erreur lors de l’enregistrement'
        ]);
    }
}
