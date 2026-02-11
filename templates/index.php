<?php
declare(strict_types=1);

/** @var array $parcoursData */
/** @var string $createParcoursUrl */
?>

<div id="emailbridge-container">

    <!-- Container principal -->
    <div id="parcours-wrapper"
         data-parcours='<?= json_encode($_['parcoursData']); ?>'
         data-create-url="<?= $_['createParcoursUrl']; ?>">

        <!-- Conteneur flex pour toutes les colonnes (rempli par JS) -->
        <div id="parcours"></div>

    </div>

</div>

<!-- Modale nouveau parcours -->
<div id="modal-new-parcours" class="modal hidden">
    <div class="modal-content">
        <h3>Créer un nouveau parcours</h3>
        <input type="text" id="new-parcours-title" placeholder="Titre du parcours" />
        <div class="modal-actions">
            <button id="save-parcours">Enregistrer</button>
            <button id="cancel-parcours">Annuler</button>
        </div>
    </div>
</div>

<!-- Modale message -->
<div id="modal-message" class="modal hidden">
    <div class="modal-content">
        <h3>Modifier le message</h3>
        <label>Sujet :</label>
        <input type="text" id="message-subject" placeholder="Sujet de l'email">
        <label>Contenu :</label>
        <textarea id="message-body" rows="6" placeholder="Contenu du message"></textarea>
        <label>Texte du bouton :</label>
        <input type="text" id="message-button-text" placeholder="Texte du bouton">
        <div class="modal-actions">
            <button id="save-message">Enregistrer</button>
            <button id="cancel-message">Annuler</button>
        </div>
    </div>
</div>

<!-- Modale création / édition email -->
<div id="emailModal" class="email-modal hidden">
  <div class="modal-content">
    <h2 id="emailModalTitle">Créer un email</h2>

    <form id="emailForm" novalidate>
      <input type="hidden" id="modalParcoursId" value="">
      <input type="hidden" id="modalEmailId" value=""> <!-- Pour savoir si c'est édition -->

      <!-- Sujet + Timing sur la même ligne -->
      <div class="form-row-inline">
        <div class="form-group">
          <label for="emailSujet">Sujet</label>
          <input type="text" id="emailSujet" required>
        </div>

        <div class="form-group">
          <label for="emailSendDay">Jour d'envoi</label>
          <input type="number" id="emailSendDay" min="0" value="0" />
        </div>

        <div class="form-group">
          <label for="emailSendTime">Heure d'envoi</label>
          <input type="time" id="emailSendTime" value="08:00" />
        </div>
      </div>

      <!-- ===== Mini-builder Blocs Configurables ===== -->
      <div id="blocks-container" style="margin-top:15px; border:1px solid #ccc; padding:10px; border-radius:5px;">
        <h4>Contenu par blocs</h4>
        <!-- Les blocs ajoutés apparaîtront ici via JS -->
      </div>
	<div id="add-block-section" class="add-block-section" style="margin:10px 0;">
	  <h4>➕ Ajouter un bloc</h4>
	  <div class="block-buttons" style="display:flex; gap:8px; flex-wrap:wrap;">
	    <button type="button" class="add-block-btn" data-type="texte">📝 Texte</button>
	    <button type="button" class="add-block-btn" data-type="bouton">🔘 Bouton</button>
	    <button type="button" class="add-block-btn" data-type="image">🖼️ Image</button>
	  </div>
	</div>

      <input type="hidden" id="emailBoutons" name="contenu"> <!-- Remplace 'corps' -->

      <!-- Bloc règles d’envoi -->
      <div id="rules-section" class="rules-section">
  <h4>🧠 Règles d’envoi</h4>

  <div class="rule-item">
    <input type="checkbox" id="rule-no-weekend" class="rule-checkbox">
    <label for="rule-no-weekend">Ne pas envoyer le weekend</label>
  </div>

  <div class="rule-item">
    <input type="checkbox" id="rule-no-holidays" class="rule-checkbox">
    <label for="rule-no-holidays">Ne pas envoyer les jours fériés</label>
  </div>

  <div class="rule-item">
    <input type="checkbox" id="rule-redirect" class="rule-checkbox">
    <label for="rule-redirect">Redirection vers une autre séquence :</label>
    <select id="rule-redirect-target" class="rule-select">
      <option value="">-- Choisir --</option>
    </select>
  </div>

  <div class="rule-item">
    <input type="checkbox" id="rule-if-repass" class="rule-checkbox">
    <label for="rule-if-repass">Si repasse → autre séquence :</label>
    <select id="rule-if-repass-target" class="rule-select">
      <option value="">-- Choisir --</option>
    </select>
  </div>

<div class="rule-item">
    <input type="checkbox" id="rule-redirect-on-click" class="rule-checkbox">
    <label for="rule-redirect-on-click">Redirection au clic du bouton :</label>
    <select id="rule-redirect-on-click-target" class="rule-select">
        <option value="">-- Choisir --</option>
    </select>
</div>
</div>


      <!-- Actions -->
      <div class="modal-actions" style="margin-top:15px;">
        <button type="submit" id="save-email">Enregistrer</button>
        <button type="button" id="delete-email" class="hidden">Supprimer</button>
        <button type="button" id="cancel-email">Annuler</button>
      </div>
    </form>
  </div>
</div>

<!-- Modale Texte Désabonnement -->
<div id="modal-unsubscribe" class="modal hidden">
    <div class="modal-content">
        <h3>Texte de désabonnement</h3>
        <textarea id="unsubscribe-text" rows="3" style="width:100%;"></textarea>
        <div class="modal-actions" style="margin-top:10px; text-align:right;">
            <button id="cancel-unsubscribe">Annuler</button>
            <button id="save-unsubscribe">Enregistrer</button>
        </div>
    </div>
</div>

<!-- Modale Gestion Séquence -->
<div id="modal-gestion" class="modal hidden">
  <div class="modal-content">
    <h3>Gestion de la séquence</h3>
    <p>Liste des inscriptions liées à cette séquence :</p>

    <div id="gestion-table-container">
      <table id="gestion-table" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr style="background:#f5f5f5;">
            <th style="width: 40px; text-align:left; padding:6px;">ID</th>
            <th style="width: 220px; text-align:left; padding:6px;">Email</th>
            <th style="width: 140px; text-align:left; padding:6px;">Dernier mail</th>
            <th style="width: 140px; text-align:left; padding:6px;">Prochain mail</th>
            <th style="width: 140px; text-align:left; padding:6px;">Autres parcours</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="gestion-table-body">
          <!-- Rempli dynamiquement via JS -->
        </tbody>
      </table>
        <!-- Graphique des stats -->
	<div id="stats-container" style="margin-top:15px;">
	    <canvas id="stats-chart" width="400" height="150"></canvas>
	</div>
	<div id="finisher-section" class="hidden">
    <h3>🎉 Finisher de la séquence</h3>
    <table id="finisher-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Dernier mail</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody id="finisher-table-body"></tbody>
    </table>
</div>
    </div>

    <div class="modal-actions">
      <button id="cancel-gestion">Fermer</button>
    </div>
  </div>
</div>
<!-- Modale Redirection Inscription -->
<div id="modal-redirect" class="modal hidden">
  <div class="modal-content">
    <h3>Rediriger l'inscription vers un autre parcours</h3>

    <div id="redirect-parcours-container">
      <!-- Les parcours disponibles seront injectés ici via JS sous forme de radio buttons -->
    </div>

    <div class="modal-actions">
      <button id="confirm-redirect">Confirmer</button>
      <button id="cancel-redirect">Annuler</button>
    </div>
  </div>
</div>


<?php
script('emailbridge', 'vendor/tinymce/tinymce.min');
script('emailbridge', 'chart.min'); 
script('emailbridge', 'emailbridge-main');
style('emailbridge', 'emailbridge-main');
?>
<meta name="requesttoken" content="<?php p($_['requesttoken']); ?>">
