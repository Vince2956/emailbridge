<?php

declare(strict_types=1);

namespace OCA\EmailBridge\AppInfo;

use OCP\AppFramework\App;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCA\EmailBridge\Controller\PageController;
use OCA\EmailBridge\Controller\FormController;
use OCA\EmailBridge\Controller\SequenceController;
use OCA\EmailBridge\Service\SequenceService;
use OCA\EmailBridge\Service\SequenceManagementService;
use OCA\EmailBridge\Service\EmailService;
use OCA\EmailBridge\BackgroundJob\EmailSenderJob;
use OCA\EmailBridge\Controller\TrackingController;

class Application extends App
{
    public const APP_ID = 'emailbridge';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);

        $container = $this->getContainer();

        // ----------------------------
        // Services et contrôleurs
        // ----------------------------
        $container->registerService(EmailService::class, function ($c) {
            return new EmailService(
                $c->query(IDBConnection::class),
                $c->query(\OCP\Mail\IMailer::class),
                $c->query(\OCP\Share\IManager::class),
                $c->query(\OCP\Files\IRootFolder::class),
                $c->query(IURLGenerator::class),
                $c->query(LoggerInterface::class),
                $c->query(IConfig::class)
            );
        });

        $container->registerService(SequenceService::class, function ($c) {
            $service = new SequenceService(
                $c->query(IDBConnection::class),
                $c->query(LoggerInterface::class),
                $c->query(EmailService::class)
            );

            $emailService = $c->query(EmailService::class);
            $emailService->setSequenceService($service);

            return $service;
        });

        $container->registerService(SequenceManagementService::class, function ($c) {
            return new SequenceManagementService(
                $c->query(IDBConnection::class),
                $c->query(LoggerInterface::class),
                $c->query(SequenceService::class),
                $c->query(EmailService::class)
            );
        });

        $container->registerService(PageController::class, function ($c) {
            return new PageController(
                self::APP_ID,
                $c->query('Request'),
                $c->query(IDBConnection::class),
                $c->query(IURLGenerator::class),
                $c->query(LoggerInterface::class),
                $c->query(\OCP\IUserSession::class)
            );
        });

        $container->registerService(SequenceController::class, function ($c) {
            return new SequenceController(
                self::APP_ID,
                $c->query('Request'),
                $c->query(IDBConnection::class),
                $c->query(LoggerInterface::class),
                $c->query(SequenceManagementService::class),
                $c->query(\OCP\IUserSession::class)
            );
        });

        $container->registerService(FormController::class, function ($c) {
            return new FormController(
                self::APP_ID,
                $c->query('Request'),
                $c->query(EmailService::class),
                $c->query(SequenceService::class),
                $c->query(IURLGenerator::class),
                $c->query(LoggerInterface::class),
                $c->query(IDBConnection::class),
                $c->query(SequenceManagementService::class)
            );
        });

        $container->registerService(EmailSenderJob::class, function ($c) {
            return new EmailSenderJob(
                $c->query(ITimeFactory::class),
                $c->query(IDBConnection::class),
                $c->query(LoggerInterface::class),
                $c->query(EmailService::class),
                $c->query(SequenceService::class),
                $c->query(IConfig::class)
            );
        });


        $container->registerService(TrackingController::class, function ($c) {
            return new TrackingController(
                self::APP_ID,
                $c->query('Request'),
                $c->query(EmailService::class),
                $c->query(IDBConnection::class),
                $c->query(LoggerInterface::class),
                $c->query(SequenceService::class),
                $c->query(SequenceController::class)
            );
        });


        $startupCheck = new StartupCheck(
            $container->query(IDBConnection::class),
            $container->query(LoggerInterface::class)
        );
        $startupCheck->checkBackgroundJob();

    }
}
