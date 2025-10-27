<?php
/** @var string $status */
/** @var string $message */
/** @var string|null $email */
?>

<div style="text-align:center; padding:50px; font-family:Arial,sans-serif;">
    <p>Nous sommes navrés de vous voir partir 😞.</p>
    <p>Nous éspérons tout de même que vous avez passé un agréable moment.</p>
    <br>
    <p>Votre adresse <strong><?= htmlspecialchars($_['email'] ?? '') ?></strong></p>
    <p>a bien été désinscrite du parcours <strong>ID <?= (int)($_['parcoursId'] ?? 0) ?></strong>.</p>
    <p>Vous ne receverez plus de communication de notre part.</p>
    <br>
    <a href="<?= htmlspecialchars($_['urlAccueil'] ?? '#') ?>" class="button">Retour à l'accueil</a>
</div>
