<?php

declare(strict_types=1);

namespace OCA\EmailBridge;

class Defaults
{
    // Texte par défaut du footer / désabonnement
    public static function unsubscribeText(): string
    {
        return "Nextcloud - un lieu sûr pour toutes vos données\n"
             . "Ceci est un e-mail envoyé automatiquement, veuillez ne pas y répondre.\n"
             . "Pour vous désabonner, {{unsubscribe_link}}.";
    }


    // Email de confirmation pour storeAndSend
    public static function confirmationSubject(): string
    {
        return "Confirmez votre email";
    }

    public static function confirmationBody(): string
    {
        return "Merci pour votre demande. Cliquez sur le bouton ci-dessous pour confirmer votre adresse et télécharger le document :";
    }

    public static function confirmationButton(): string
    {
        return "Confirmer mon email";
    }

    // Tu pourras ajouter d'autres valeurs par défaut ici au fur et à mesure
    // public static function someOtherDefault(): string { return "valeur"; }
}
