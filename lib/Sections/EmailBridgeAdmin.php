<?php
namespace OCA\EmailBridge\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class EmailBridgeAdmin implements IIconSection {
    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getIcon(): string {
    	return $this->urlGenerator->imagePath('emailbridge', 'app.svg');
    }

    public function getID(): string {
        return 'emailbridge';
    }

    public function getName(): string {
        return $this->l->t('EmailBridge');
    }

    public function getPriority(): int {
        return 10;
    }
}

