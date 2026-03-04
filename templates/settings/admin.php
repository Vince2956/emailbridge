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

   <!-- <hr style="margin:30px 0;">-->

<!-- ============================= -->
<!-- SETTINGS -->
<!-- ============================= -->
<!--
<h2>Paramètres de désinstallation</h2>

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
-->


<!-- ============================= -->
<!-- HELLOASSO -->
<!-- ============================= -->
<div style="margin-top:30px;">
<h2>Paramètres HelloAsso</h2>

<form id="helloAssoForm" method="post" action="javascript:void(0);">

    <!-- SLUG -->
    <div style="margin-bottom:15px;">
        <label><strong>Slug organisation</strong></label><br>
        <input 
            type="text" 
            id="helloAssoSlug" 
            value="<?php p($_['helloasso_slug'] ?? ''); ?>"
            placeholder="ex: association-la-cascade"
            style="width:400px;"
        />
        <p style="font-size:12px;color:#666;">
            Correspond au nom visible dans l’URL HelloAsso.
        </p>
    </div>

    <!-- CLIENT ID -->
    <div style="margin-bottom:10px;">
        <label>Client ID</label><br>
        <input 
            type="text" 
            id="helloAssoClientId"
            value="<?php p($_['helloasso_client_id'] ?? ''); ?>"
            style="width:400px;" 
        />
    </div>

    <!-- CLIENT SECRET -->
    <div style="margin-bottom:10px;">
        <label>Client Secret</label><br>
        <input 
            type="password" 
            id="helloAssoClientSecret"
            value="<?php p($_['helloasso_client_secret'] ?? ''); ?>"
            style="width:400px;" 
        />
    </div>

    <button type="submit" class="primary">
        Enregistrer la configuration
    </button>

</form>

<div id="helloAssoApiResult" style="margin-top:10px;"></div>

<hr style="margin:25px 0;">

<!-- PRODUITS -->
<div>
    <button id="loadHelloAssoProducts" class="primary">
        Recharger la liste des produits
    </button>
</div>

<div id="helloAssoProducts" style="margin-top:15px;">
    <!-- Produits injectés ici -->
</div>

<div style="margin-top:15px;">
    <button id="saveHelloAssoSelection" class="primary">
        Enregistrer la sélection
    </button>
</div>

<div id="helloAssoResult" style="margin-top:10px;"></div>

</div>


<!-- ============================= -->
<!-- RESET -->
<!-- ============================= -->
<hr style="margin:30px 0;">

<h2>Réinitialisation complète</h2>
<div>
    <button id="resetBtn" class="critical" style="margin-top:10px;">
        Réinitialiser toutes les données EmailBridge
    </button>
    <div id="resetResult" style="margin-top:15px;"></div>
</div>

<div id="result" style="margin-top:20px;"></div>

</div>

