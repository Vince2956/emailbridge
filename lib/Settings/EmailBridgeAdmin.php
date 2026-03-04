<?php
namespace OCA\EmailBridge\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

class EmailBridgeAdmin implements ISettings {
    private IL10N $l;
    private IConfig $config;

    public function __construct(IConfig $config, IL10N $l) {
        $this->config = $config;
        $this->l = $l;
    }

public function getForm() {

    $delete = $this->config->getAppValue('emailbridge', 'delete_on_uninstall', '0');

    return new TemplateResponse('emailbridge', 'settings/admin', [

        'delete_on_uninstall' =>
            $delete === '1',

        'helloasso_slug' =>
            $this->config->getAppValue('emailbridge', 'helloasso_slug', ''),

        'helloasso_client_id' =>
            $this->config->getAppValue('emailbridge', 'helloasso_client_id', ''),

        'helloasso_client_secret' =>
            $this->config->getAppValue('emailbridge', 'helloasso_client_secret', ''),

    ], '');
}

    public function getSection() {
        return 'emailbridge'; // Doit correspondre à l'ID de la section
    }

    public function getPriority() {
        return 10;
    }
}

