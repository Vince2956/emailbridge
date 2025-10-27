<?php
/** @var int $parcoursId */
?>

<form id="emailForm" <?php if(!empty($_['parcoursId'])): ?>data-parcours-id="<?= $_['parcoursId'] ?>"<?php endif; ?>>
    <input type="email" name="email" placeholder="Votre email" required>
    <button type="submit">Recevoir le PDF</button>
</form>

<div id="result"></div>

<?php script('emailbridge', 'form'); ?>
