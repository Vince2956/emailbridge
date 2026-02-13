<?php
script('emailbridge', 'admin');
style('emailbridge', 'admin');
?>

<div class="section">
    <h2>EmailBridge – Import / Export</h2>

    <!-- ============================= -->
    <!-- EXPORT -->
    <!-- ============================= -->
    <div style="margin-top:20px;">
        <button id="exportBtn" class="primary">
            Exporter toutes les données (JSON)
        </button>
    </div>

    <hr style="margin:30px 0;">

    <!-- ============================= -->
    <!-- IMPORT -->
    <!-- ============================= -->
    <div>
        <h3>Importer un fichier JSON</h3>
        <input type="file" id="importFile" accept="application/json">
        <button id="importBtn">
            Importer
        </button>
    </div>

    <hr style="margin:30px 0;">

<!-- ============================= -->
<!-- SETTINGS -->
<!-- ============================= -->
<h2>Paramètres de désinstallation</h2>
<hr style="margin:30px 0;">

<h2>Réinitialiser toutes les données EmailBridge</h2>
<p>Attention : toutes les données (emails, séquences, inscriptions, formulaires) seront supprimées.</p>
<button id="resetBtn" class="warning">Réinitialiser</button>
<div id="resetResult" style="margin-top:20px;"></div>
<form id="settingsForm">
    <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

    <label style="display:flex;align-items:center;gap:10px;margin-top:15px;">
        <input type="checkbox"
               id="delete_on_uninstall"
               name="delete_on_uninstall"
               value="1"
               <?php echo !empty($_['delete_on_uninstall']) ? 'checked' : ''; ?>>
        Supprimer les données lors de la désinstallation
    </label>

    <div style="margin-top:15px;">
        <button type="submit" class="primary">
            Enregistrer les paramètres
        </button>
    </div>
</form>

<div id="result" style="margin-top:20px;"></div>

</div>

