<?php
/** @var string $email */
/** @var int $parcoursId */
/** @var string $urlAccueil */
?>
<div class="emailbridge-confirm-pending">
    <h2>Bienvenu ! ✅</h2>
    <p>Votre adresse email <strong><?= htmlspecialchars($email) ?></strong> a bien été confirmée (P<?= $parcoursId ?>).</p>
    <p>Vous n'avez plus rien à faire. Vérifiez votre boîte de réception pour suivre les prochaines étapes.</p>
    <div style="margin-top: 20px;">
        <a class="button" href="<?= $urlAccueil ?>">Retour à l’accueil</a>
    </div>
</div>
