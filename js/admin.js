document.addEventListener('DOMContentLoaded', () => {

    const exportBtn = document.getElementById('exportBtn');
    const importBtn = document.getElementById('importBtn');
    const importFile = document.getElementById('importFile');
    const result = document.getElementById('result');
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
                result.innerHTML = '<span style="color:green">Import réussi</span>';
            } else {
                result.innerHTML = '<span style="color:red">' + data.message + '</span>';
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
        result.innerHTML = '<span style="color:green">Paramètres enregistrés</span>';
    } else {
        result.innerHTML = '<span style="color:red">Erreur lors de l’enregistrement</span>';
    }
});

    }

});

