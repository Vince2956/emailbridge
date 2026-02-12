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
        $parameters = [
            'someSetting' => $this->config->getSystemValue('emailbridge_some_setting', true),
        ];
        return new TemplateResponse('emailbridge', 'settings/admin', $parameters, '');
    }

    public function getSection() {
        return 'emailbridge'; // Doit correspondre à l'ID de la section
    }

    public function getPriority() {
        return 10;
    }
}

