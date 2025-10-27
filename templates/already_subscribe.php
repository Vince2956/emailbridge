<?php
/** @var string $email */
/** @var int $parcoursId */
/** @var string $urlAccueil */
?>
<div class="emailbridge-already-subscribed">
    <h2>😕 Hey <?= htmlspecialchars($email) ?> !</h2>
    <p>Vous êtes déjà inscrit à ce parcours (ID: <?= $parcoursId ?>).</p>
    <p>Vous pouvez explorer d'autres parcours ou revenir à l'accueil.</p>
    <br>
    <a href="<?= htmlspecialchars($urlAccueil) ?>" class="button">
        Retour à l'accueil
    </a>
</div>
