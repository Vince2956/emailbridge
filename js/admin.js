let result = null;

function setResult(html) {
    if (!result) return;

    result.innerHTML = html;

    setTimeout(() => {
        result.innerHTML = '';
    }, 4000);
}

document.addEventListener('DOMContentLoaded', () => {

    const exportBtn = document.getElementById('exportBtn');
    const importBtn = document.getElementById('importBtn');
    const importFile = document.getElementById('importFile');
    
    result = document.getElementById('result');

    const settingsForm = document.getElementById('settingsForm');
    const deleteCheckbox = document.getElementById('delete_on_uninstall');

    const baseUrl = OC.generateUrl('/apps/emailbridge');

    // ============================
    // EXPORT
    // ============================
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            window.location.href = baseUrl + '/admin/export';
        });
    }

    // ============================
    // IMPORT
    // ============================
        
    if (importBtn) {
        importBtn.addEventListener('click', async () => {

            if (!importFile.files.length) {
                alert('Sélectionnez un fichier JSON');
                return;
            }

            const file = importFile.files[0];
            const text = await file.text();

            const response = await fetch(baseUrl + '/admin/import', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    requesttoken: OC.requestToken
                },
                body: text
            });

            const data = await response.json();

            if (data.status === 'ok') {
                setResult('<span style="color:green">Import réussi</span>');
            } else {
                setResult('<span style="color:red">' + (data.message || 'Erreur') + '</span>');
            }
        });
    }

    // ============================
    // SETTINGS
    // ============================
    if (settingsForm) {
        settingsForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const body = { delete_on_uninstall: deleteCheckbox.checked ? 1 : 0 };
    console.log('Envoi saveSettings', body);

    const response = await fetch(baseUrl + '/admin/save-settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
        body: JSON.stringify(body)
    });

    const data = await response.json();
    console.log('Réponse saveSettings', data);

    if (data.status === 'ok') {
        setResult('<span style="color:green">Paramètres enregistrés</span>');
    } else {
        setResult('<span style="color:red">Erreur lors de l’enregistrement</span>');
    }
});

    }
 
 

    // ============================
    // HELLOASSO API
    // ============================
    const helloAssoForm = document.getElementById('helloAssoForm');
    const helloAssoSlugInput = document.getElementById('helloAssoSlug');
    const helloAssoClientIdInput = document.getElementById('helloAssoClientId');
    const helloAssoClientSecretInput = document.getElementById('helloAssoClientSecret');
    const helloAssoApiResult = document.getElementById('helloAssoApiResult');

    if (helloAssoForm) {
        helloAssoForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            console.log('Submit HelloAsso intercepté !');

            const body = {
                slug: helloAssoSlugInput.value.trim(),
                clientId: helloAssoClientIdInput.value.trim(),
                clientSecret: helloAssoClientSecretInput.value.trim()
            };

            helloAssoApiResult.innerHTML = 'Enregistrement en cours…';

            try {
                const response = await fetch(baseUrl + '/admin/save-helloasso-key', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
                    body: JSON.stringify(body)
                });

                const data = await response.json();
                helloAssoApiResult.innerHTML = data.status === 'ok'
                    ? '<span style="color:green">Clés API enregistrées</span>'
                    : '<span style="color:red">Erreur: ' + (data.message || '') + '</span>';

            } catch (err) {
                console.error(err);
                helloAssoApiResult.innerHTML = '<span style="color:red">Erreur réseau</span>';
            }
        });
    }

// ============================
// WEBHOOK
// ============================
const webhookUrlInput = document.getElementById('webhookUrl');
const copyWebhookBtn = document.getElementById('copyWebhookBtn');
const regenTokenBtn = document.getElementById('regenTokenBtn');
const webhookResult = document.getElementById('webhookResult');

// Copier URL
if (copyWebhookBtn && webhookUrlInput) {
    copyWebhookBtn.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(webhookUrlInput.value);
            webhookResult.innerHTML = '<span style="color:green">URL copiée !</span>';
        } catch (err) {
            webhookResult.innerHTML = '<span style="color:red">Impossible de copier</span>';
        }
    });
}

// Régénérer token
if (regenTokenBtn) {
    regenTokenBtn.addEventListener('click', async () => {

        if (!confirm("Régénérer le token invalidera l'ancien webhook. Continuer ?")) {
            return;
        }

        webhookResult.innerHTML = 'Régénération…';

        try {
            const response = await fetch(baseUrl + '/admin/regenerate-webhook-token', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    requesttoken: OC.requestToken
                }
            });

            const data = await response.json();

            if (data.status === 'ok') {
                webhookUrlInput.value = data.webhook_url;
                webhookResult.innerHTML = '<span style="color:green">Token régénéré</span>';
            } else {
                webhookResult.innerHTML = '<span style="color:red">' + (data.message || 'Erreur') + '</span>';
            }

        } catch (err) {
            console.error(err);
            webhookResult.innerHTML = '<span style="color:red">Erreur réseau</span>';
        }
    });
}

// ============================
// Recharger les produits HelloAsso
// ============================
const loadHelloAssoProducts = document.getElementById('loadHelloAssoProducts');
const helloAssoProducts = document.getElementById('helloAssoProducts');
const helloAssoResult = document.getElementById('helloAssoResult');
const saveHelloAssoSelection = document.getElementById('saveHelloAssoSelection');

if (loadHelloAssoProducts) {
    loadHelloAssoProducts.addEventListener('click', async () => {
        helloAssoProducts.innerHTML = 'Chargement…';
        helloAssoResult.innerHTML = '';

        try {
            const response = await fetch(baseUrl + '/admin/fetch-helloasso-products', {
                headers: { requesttoken: OC.requestToken }
            });

            const data = await response.json();

            if (data.status === 'ok') {
                helloAssoProducts.innerHTML = '';

                // 🔹 Les produits sont dans data.products.data
                const productsArray = data.products?.data ?? [];

                if (productsArray.length === 0) {
                    helloAssoProducts.innerHTML = '<span style="color:#666">Aucun produit trouvé</span>';
                    return;
                }

                productsArray.forEach(p => {
                    const div = document.createElement('div');
                    const checked = p.active ? 'checked' : '';

                    // Utiliser p.title pour le nom du produit (ou p.privateTitle si tu veux)
                    const name = p.title || p.item_name || 'Produit sans titre';
                    
                    div.innerHTML = `<label>
                        <input type="checkbox" value="${p.helloasso_item_id}" ${checked}> ${name}
                    </label>`;
                    helloAssoProducts.appendChild(div);
                });

            } else {
                helloAssoProducts.innerHTML = '<span style="color:red">Erreur lors du chargement: ' + (data.message || '') + '</span>';
            }

        } catch (err) {
            console.error('Erreur fetch HelloAsso products:', err);
            helloAssoProducts.innerHTML = '<span style="color:red">Erreur réseau</span>';
        }
    });
}

// Chargement automatique au refresh
if (loadHelloAssoProducts) {
    loadHelloAssoProducts.click();
}

// Enregistrer la sélection
if (saveHelloAssoSelection) {
    saveHelloAssoSelection.addEventListener('click', async () => {
        const selected = Array.from(helloAssoProducts.querySelectorAll('input[type=checkbox]:checked'))
                              .map(cb => cb.value);

        helloAssoResult.innerHTML = 'Enregistrement…';
        try {
            const response = await fetch(baseUrl + '/admin/save-helloasso-selection', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
                body: JSON.stringify({ selected })
            });
            const data = await response.json();

            if (data.status === 'ok') {
                helloAssoResult.innerHTML = '<span style="color:green">Sélection enregistrée</span>';
            } else {
                helloAssoResult.innerHTML = '<span style="color:red">Erreur: ' + (data.message || '') + '</span>';
            }
        } catch (err) {
            console.error(err);
            helloAssoResult.innerHTML = '<span style="color:red">Erreur réseau</span>';
        }
    });
}
 
    // ============================
// RESET
// ============================
const resetBtn = document.getElementById('resetBtn');

if (resetBtn) {
    resetBtn.addEventListener('click', async () => {

        if (!confirm("⚠️ Voulez-vous vraiment supprimer TOUTES les données EmailBridge ?")) {
            return;
        }

        setResult('Réinitialisation en cours…');

        const response = await fetch(baseUrl + '/admin/reset', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                requesttoken: OC.requestToken
            }
        });

        const data = await response.json();

        if (data.status === 'ok') {
            setResult('<span style="color:green">' + data.message + '</span>');
        } else {
            setResult('<span style="color:red">' + data.message + '</span>');
        }
    });
}


});

