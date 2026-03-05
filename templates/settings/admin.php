<?php
script('emailbridge', 'admin');
style('emailbridge', 'admin');
?>

<div class="section">

    <h2>EmailBridge – Administration</h2>

    <!-- ===================================================== -->
    <!-- 1️⃣ SAUVEGARDE & MIGRATION -->
    <!-- ===================================================== -->
    <div style="margin-top:30px;">
        <h3>📦 Sauvegarde & Migration</h3>

        <div style="margin-top:15px;">
            <button id="exportBtn" class="primary">
                Exporter toutes les données (JSON)
            </button>
        </div>

        <div style="margin-top:20px;">
            <label><strong>Importer un fichier JSON</strong></label><br>
            <input type="file" id="importFile" accept="application/json">
            <button id="importBtn">
                Importer
            </button>
            <div id="result" style="margin-top:10px;"></div>
        </div>
    </div>

    <hr style="margin:40px 0;">

    <!-- ===================================================== -->
    <!-- 2️⃣ HELLOASSO -->
    <!-- ===================================================== -->
    <div>
        <h3>🔗 Configuration HelloAsso</h3>

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
            <div style="margin-bottom:15px;">
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

        <!-- ================= WEBHOOK ================= -->

        <hr style="margin:30px 0;">

        <h4>📡 Webhook HelloAsso</h4>

        <p style="font-size:13px;color:#666;">
            Copiez cette URL dans votre configuration HelloAsso à l'emplacement "Mon URL de callback".
        </p>

        <div style="display:flex;gap:10px;align-items:center;">
            <input 
                type="text" 
                id="webhookUrl"
                value="<?php p($_['webhook_url'] ?? ''); ?>" 
                readonly 
                style="flex:1;"
            >
            <button type="button" id="copyWebhookBtn">
                Copier
            </button>
        </div>

        <div style="margin-top:10px;">
            <button type="button" id="regenTokenBtn">
                🔁 Régénérer le token
            </button>
        </div>

        <div id="webhookResult" style="margin-top:10px;"></div>

        <!-- ================= PRODUITS ================= -->

        <hr style="margin:30px 0;">

        <h4>📦 Produits HelloAsso</h4>

        <button id="loadHelloAssoProducts" class="primary">
            Recharger la liste des produits
        </button>

        <div id="helloAssoProducts" style="margin-top:15px;"></div>

        <div style="margin-top:15px;">
            <button id="saveHelloAssoSelection" class="primary">
                Enregistrer la sélection
            </button>
        </div>

        <div id="helloAssoResult" style="margin-top:10px;"></div>
    </div>

    <!-- ===================================================== -->
    <!-- 3️⃣ ZONE DANGEREUSE -->
    <!-- ===================================================== -->
    <hr style="margin:50px 0;">

    <div>
        <h3 style="color:#c00;">⚠️ Zone dangereuse</h3>

        <p style="font-size:13px;color:#666;">
            Cette action supprimera toutes les données EmailBridge.
        </p>

        <button id="resetBtn" class="critical">
            Réinitialiser toutes les données
        </button>

        <div id="resetResult" style="margin-top:15px;"></div>
    </div>

</div>
