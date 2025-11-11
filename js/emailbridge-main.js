document.addEventListener('DOMContentLoaded', () => {
// Neutralise toutes les alertes pendant les tests
window.alert = function() {};


    // --- Utils ---
    function copyToClipboard(code) {
        navigator.clipboard.writeText(code)
            .then(() => alert("✅ Code copié dans le presse-papier"))
            .catch(err => alert("❌ Impossible de copier : " + err));
    }

        // --- Utils ---
    window.getUrl = function(path) {
        if (typeof OC !== 'undefined' && OC.generateUrl) {
            return OC.generateUrl(path);
        }
        const base = (typeof OC !== 'undefined' && OC.webroot)
            ? OC.webroot
            : '/nextcloud'; // fallback dev
        return base + path;
    };



    const tokenMeta = document.querySelector('meta[name="requesttoken"]');
    const csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

    const wrapper = document.getElementById('parcours-wrapper');
    window.parcoursData = wrapper.dataset.parcours ? JSON.parse(wrapper.dataset.parcours) : [];
    window.createParcoursUrl = wrapper.dataset.createUrl;

    const parcoursDiv = document.getElementById('parcours');
    const modalNew = document.getElementById('modal-new-parcours');
    const modalMessage = document.getElementById('modal-message');
    const titleInput = document.getElementById('new-parcours-title');
    const saveBtn = document.getElementById('save-parcours');
    const cancelBtn = document.getElementById('cancel-parcours');

    if (!parcoursDiv || !modalNew || !titleInput || !saveBtn || !cancelBtn || !modalMessage) {
        console.error('Éléments DOM manquants');
        return;
    }

// --- Parcours ---
function renderParcours() {
    parcoursDiv.innerHTML = '';

    if (window.parcoursData.length === 0) {
        const emptyMsg = document.createElement('div');
        emptyMsg.id = 'parcours-empty';
        emptyMsg.innerHTML = `<p>Aucun parcours existant. Cliquez sur le + pour créer un nouveau parcours.</p>`;
        parcoursDiv.appendChild(emptyMsg);
    }

    window.parcoursData.forEach(p => {
        const col = document.createElement('div');
        col.className = 'parcours-column';
        col.dataset.id = p.id;

        // --- Titre parcours ---
        const title = document.createElement('h4');
        title.textContent = p.nom || p.titre || 'Parcours sans nom';
        col.appendChild(title);

        // --- Bouton supprimer parcours ---
        const delBtn = document.createElement('button');
        delBtn.className = 'delete-parcours-btn parcours-btn';
        delBtn.title = 'Supprimer ce parcours';
        delBtn.addEventListener('click', () => deleteParcours(p.id));
        col.appendChild(delBtn);

        // --- Checkbox Bypass fichier cible ---
        const bypassBlock = document.createElement('div');
        bypassBlock.className = 'parcours-bypass';
        bypassBlock.style.marginBottom = '8px';
        bypassBlock.innerHTML = `
            <label>
                <input type="checkbox" class="bypass-file" ${p.bypass_file ? 'checked' : ''}>
                Bypass fichier + confirmation
            </label>
        `;
        const checkbox = bypassBlock.querySelector('.bypass-file');
        checkbox.style.cursor = 'pointer';
        col.appendChild(bypassBlock);

        // --- Conteneur pour fichier cible + bouton message ---
        const targetBlock = document.createElement('div');
        targetBlock.className = 'parcours-target-block';

        // Fichier cible
        const fileBlock = document.createElement('div');
        fileBlock.className = 'parcours-file';
        fileBlock.innerHTML = `
            <button class="choose-file-btn parcours-btn">📂 Fichier cible</button>
            <span class="selected-file">${p.document_url || 'Aucun fichier sélectionné'}</span>
        `;
        fileBlock.querySelector('.choose-file-btn')
            .addEventListener('click', () => chooseFile(p.id, fileBlock.querySelector('.selected-file')));
        targetBlock.appendChild(fileBlock);

	// Bouton modifier message
	const msgBlock = document.createElement('div');
	msgBlock.className = 'parcours-file'; // même classe que fichier
	const msgBtn = document.createElement('button');
	msgBtn.className = 'edit-message-btn parcours-btn';
	msgBtn.textContent = '✏️ Message';
	msgBtn.addEventListener('click', () => openMessageModal(p.id));
	msgBlock.appendChild(msgBtn);
	targetBlock.appendChild(msgBlock);


        // état initial si bypass activé
        if (p.bypass_file) targetBlock.classList.add('disabled');

        col.appendChild(targetBlock);

        // --- Gestion checkbox ---
        checkbox.addEventListener('change', async () => {
            // toggle côté front
            targetBlock.classList.toggle('disabled', checkbox.checked);

            // sauvegarde backend
            try {
                const res = await fetch(getUrl(`/apps/emailbridge/parcours/${p.id}/update-bypass`), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: new URLSearchParams({ requesttoken: csrfToken, bypass_file: checkbox.checked ? 1 : 0 })
                });
                const result = await res.json();
                if (result.status !== 'ok') {
                    alert('Erreur lors de la sauvegarde du bypass : ' + (result.message || 'inconnue'));
                    checkbox.checked = !checkbox.checked;
                    targetBlock.classList.toggle('disabled', checkbox.checked);
                }
            } catch (err) {
                console.error(err);
                alert('Erreur réseau lors de la sauvegarde du bypass.');
                checkbox.checked = !checkbox.checked;
                targetBlock.classList.toggle('disabled', checkbox.checked);
            }
        });

        // --- Test email ---
        const testBlock = document.createElement('div');
        testBlock.className = 'parcours-test';
        testBlock.innerHTML = `
            <label>Email de test :</label>
            <div class="test-row">
                <input type="email" class="test-input" placeholder="exemple@mail.com" />
                <button class="test-submit parcours-btn">Tester</button>
            </div>
        `;
        const input = testBlock.querySelector('.test-input');
        const btn = testBlock.querySelector('.test-submit');
        btn.addEventListener('click', () => testEmail(p.id, input.value));
        col.appendChild(testBlock);

        // --- Embed / shortcode / iframe ---
        const embedBlock = document.createElement('div');
        embedBlock.className = 'parcours-embed';
        embedBlock.innerHTML = `
            <label>Intégration externe :</label>
            <div class="embed-buttons">
                <button class="copy-iframe">iframe</button>
                <button class="copy-shortcode">shortcode WP</button>
                <button class="copy-html">HTML</button>
            </div>
        `;
        col.appendChild(embedBlock);

        const iframeCode = `<iframe src="/apps/emailbridge/form/${p.id}" width="100%" height="400"></iframe>`;
        const shortcodeCode = `[emailbridge id="${p.id}"]`;
        const htmlCode = `<div id="emailbridge-form-${p.id}"></div><script src="/apps/emailbridge/embed.js"></script>`;
        embedBlock.querySelector('.copy-iframe').addEventListener('click', () => copyToClipboard(iframeCode));
        embedBlock.querySelector('.copy-shortcode').addEventListener('click', () => copyToClipboard(shortcodeCode));
        embedBlock.querySelector('.copy-html').addEventListener('click', () => copyToClipboard(htmlCode));
        embedBlock.querySelectorAll('button').forEach(b => b.classList.add('parcours-btn'));


	// --- Bouton Texte Désabonnement ---
	const unsubscribeBlock = document.createElement('div');
	unsubscribeBlock.className = 'parcours-file'; // même style que fichier/message
	
	const unsubscribeBtn = document.createElement('button');
	unsubscribeBtn.className = 'unsubscribe-text-btn parcours-btn';
	unsubscribeBtn.textContent = '✏️ Texte désabonnement';
	
	unsubscribeBtn.addEventListener('click', () => {
	    if (!p.id) {
	        console.error('Parcours ID manquant pour ce bouton !', p);
	        alert('Erreur : impossible d’ouvrir la fenêtre, ID parcours manquant.');
	        return;
	    }
	    openUnsubscribeModal(p.id);
	});

	unsubscribeBlock.appendChild(unsubscribeBtn);
	col.appendChild(unsubscribeBlock);


	// --- Bouton Gestion Séquence ---
	const gestionBlock = document.createElement('div');
	gestionBlock.className = 'parcours-file';

	const gestionBtn = document.createElement('button');
	gestionBtn.className = 'gestion-sequence-btn parcours-btn';
	gestionBtn.textContent = '⚙️ Gestion';

	gestionBtn.addEventListener('click', async () => {
	    if (!p.id) {
	        alert('Erreur : parcours ID manquant.');
	        return;
	    }

	    // Ouvre la modale et charge les inscriptions
	    openGestionModal(p.id);
	});

	gestionBlock.appendChild(gestionBtn);
	col.appendChild(gestionBlock);


        // --- Séquence emails ---
        renderSequences(p.id, col);

        parcoursDiv.appendChild(col);
    });

    const addColumnBtn = document.createElement('div');
    addColumnBtn.id = 'add-parcours';
    addColumnBtn.className = 'add-parcours-column';
    addColumnBtn.textContent = '+';
    addColumnBtn.addEventListener('click', () => {
        modalNew.classList.remove('hidden');
        titleInput.value = '';
        titleInput.focus();
    });
    parcoursDiv.appendChild(addColumnBtn);
}


// --- Modale Texte Désabonnement ---
const modalUnsubscribe = document.getElementById('modal-unsubscribe');
const unsubscribeTextarea = document.getElementById('unsubscribe-text');
const unsubscribeSaveBtn = document.getElementById('save-unsubscribe');
const unsubscribeCancelBtn = document.getElementById('cancel-unsubscribe');

function openUnsubscribeModal(parcoursId) {
    if (!parcoursId) {
        console.error('openUnsubscribeModal appelé sans ID parcours.');
        alert('Erreur : ID parcours manquant, impossible de charger le texte.');
        return;
    }

    // Sauvegarde l'ID dans la modale pour la sauvegarde ultérieure
    modalUnsubscribe.dataset.parcoursId = parcoursId;

    // Affiche la modale
    modalUnsubscribe.classList.remove('hidden');
    unsubscribeTextarea.value = 'Chargement...';

    // Récupération du texte existant depuis le backend
    fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/unsubscribe-text`))
        .then(res => {
            if (!res.ok) throw new Error('Réponse réseau invalide');
            return res.json();
        })
    
    .then(data => {
        if (data && data.text) {
            unsubscribeTextarea.value = data.text.trim();
        } else {
            unsubscribeTextarea.value = '';
        }
     })
    .catch(err => {
        console.error('Erreur lors de la récupération du texte désabonnement :', err);
        unsubscribeTextarea.value = '';
    });
}


unsubscribeSaveBtn.addEventListener('click', () => {
    const parcoursId = modalUnsubscribe.dataset.parcoursId;
    const text = unsubscribeTextarea.value.trim();

    fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/unsubscribe-text`), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ requesttoken: csrfToken, text })
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'ok') {
            modalUnsubscribe.classList.add('hidden');
        } else alert('Erreur sauvegarde texte désabonnement : ' + (data.message || 'inconnue'));
    })
    .catch(err => {
        console.error(err);
        alert('Erreur réseau lors de la sauvegarde du texte désabonnement.');
    });
});

unsubscribeCancelBtn.addEventListener('click', () => {
    modalUnsubscribe.classList.add('hidden');
});



function saveBypassFile(parcoursId, bypass) {
    fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/update-bypass`), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({
            bypass_file: bypass ? 1 : 0,
            requesttoken: csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        if(data.status !== 'ok') {
            console.error('Erreur lors de la sauvegarde du bypass_file:', data.message);
            alert('Impossible de sauvegarder le bypass.');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Erreur réseau lors de la sauvegarde du bypass.');
    });
}



    // --- Séquences ---
    function renderSequences(parcoursId, parentCol) {
        const sequenceContainer = document.createElement('div');
        sequenceContainer.className = 'sequence-container';

        const emailsList = document.createElement('div');
        emailsList.className = 'emails-list';
        emailsList.dataset.parcoursId = parcoursId;
        sequenceContainer.appendChild(emailsList);

        const addEmailBtn = document.createElement('button');
        addEmailBtn.className = 'add-email-btn parcours-btn';
        addEmailBtn.textContent = '+ Ajouter un email';
        addEmailBtn.addEventListener('click', () => openEmailModal(parcoursId));
        sequenceContainer.appendChild(addEmailBtn);

        parentCol.appendChild(sequenceContainer);

        loadEmails(parcoursId, emailsList);
    }

    // --- Gestion Email ---
    function deleteEmail(emailId) {
        if (!emailId || !confirm("Voulez-vous vraiment supprimer cet email ?")) return;

        fetch(getUrl(`/apps/emailbridge/email/${emailId}`), { method: 'DELETE' })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'ok'){
                    const block = document.querySelector(`#email-${emailId}`);
                    if(block) block.remove();
                    emailModal.classList.add('hidden');
                } else alert('Erreur suppression : ' + (data.message || 'inconnue'));
            })
            .catch(err => console.error('Erreur suppression email:', err));
    }

function formatEmailTime(email) {
    if (!email) return '';
    let timingText = '⏱️ ';

    const sendDay = email.send_day ?? email.sendDay ?? 0;
    const sendTime = email.send_time ?? email.sendTime ?? '';
    const delayMinutes = parseInt(email.delay_minutes ?? email.delayMinutes ?? '', 10);

    if (sendDay === 0) {
        if (!isNaN(delayMinutes) && delayMinutes > 0) {
            timingText += delayMinutes % 60 === 0
                ? `J0 +${delayMinutes / 60}h après inscription`
                : `J0 +${delayMinutes} min après inscription`;
        } else {
            timingText += `J0 à ${sendTime || 'HH:MM'}`;
        }
    } else {
        timingText += `J+${sendDay} à ${sendTime || 'HH:MM'}`;
    }

    return timingText;
}

function renderRuleLines(rules) {
    const lines = [];
    if (rules.noWeekend) lines.push('→ Pas d\'envoi le weekend');
    if (rules.noHolidays) lines.push('→ Pas d\'envoi les jours fériés');

    if (rules.redirectTarget) {
        const target = parcoursCache?.find(p => p.id == rules.redirectTarget);
        const name = target ? `${target.titre}` : rules.redirectTarget;
        lines.push(`→ Pont vers ${name}`);
    }

    if (rules.ifRepassTarget) {
        const target = parcoursCache?.find(p => p.id == rules.ifRepassTarget);
        const name = target ? `${target.titre}` : rules.ifRepassTarget;
        lines.push(`→ Si repasse → ${name}`);
    }

    if (rules.redirectOnClick) {
        const target = parcoursCache?.find(p => p.id == rules.redirectOnClick);
        const name = target ? `${target.titre}` : rules.redirectOnClick;
        lines.push(`→ Redirection au clic → ${name}`);
    }

    return lines;
}



function renderEmail(email, container) {
    // Vérifie si l'email existe déjà
    let existing = container.querySelector(`#email-${email.id}`);
    let nextSibling = null;

    if (existing) {
        // Sauvegarde le prochain élément avant suppression
        nextSibling = existing.nextElementSibling;
        existing.remove();
    }

    const block = document.createElement('div');
    block.className = 'email-block';
    block.id = `email-${email.id}`;
    block.dataset.emailId = email.id;

    // Sujet
    const sujet = document.createElement('h4');
    sujet.textContent = email.sujet || '(email sans sujet)';
    block.appendChild(sujet);

    // Timing
    const timing = document.createElement('div');
    timing.className = 'email-timing';
    timing.textContent = formatEmailTime(email);
    block.appendChild(timing);

    // Stats
    const stats = document.createElement('div');
    stats.className = 'email-stats';
    const statItems = [
        {icon: '📬', key: 'sent', title: 'Envoyés'},
        {icon: '👁️', key: 'opened', title: 'Ouverts'},
        {icon: '🔗', key: 'clicked', title: 'Clics'},
        {icon: '🚫', key: 'unsubscribed', title: 'Désinscriptions'},
        {icon: '⛔', key: 'stopped', title: 'Arrêtés'},
        {icon: '↪️', key: 'redirected', title: 'Redirigés'}
    ];
    statItems.forEach((item, index) => {
        const span = document.createElement('span');
        span.textContent = `${item.icon} ${email.stats?.[item.key] ?? 0}`;
        span.title = item.title;
        stats.appendChild(span);
        if (index < statItems.length - 1) stats.appendChild(document.createTextNode(' '));
    });
    block.appendChild(stats);

    // Actions
    const actions = document.createElement('div');
    actions.className = 'sequence-actions';

    const editBtn = document.createElement('button');
    editBtn.className = 'edit-sequence-btn parcours-btn';
    editBtn.textContent = '✏️ Modifier';
    editBtn.addEventListener('click', () => {
        const pid = email.parcoursId ?? email.parcours_id;
        openEmailModal(pid, email);
    });
    actions.appendChild(editBtn);

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'delete-sequence-btn parcours-btn';
    deleteBtn.textContent = '🗑️ Supprimer';
    deleteBtn.addEventListener('click', () => deleteEmail(email.id));
    actions.appendChild(deleteBtn);

    block.appendChild(actions);

    // Règles actives
if (email.rules) {
    const rulesDiv = document.createElement('div');
    rulesDiv.className = 'email-rules';
    try {
        const rules = typeof email.rules === 'string' ? JSON.parse(email.rules || '{}') : email.rules || {};
        const lines = renderRuleLines(rules);
        if (lines.length > 0) {
            rulesDiv.innerHTML = lines.map(l => `<div>${l}</div>`).join('');
            block.appendChild(rulesDiv);
        }
    } catch (err) {
        console.warn("Erreur affichage règles:", err);
    }
}

    // 🧩 Ici, on réinsère le bloc à la bonne position
    if (nextSibling) {
        container.insertBefore(block, nextSibling);
    } else {
        container.appendChild(block);
    }
}

async function loadEmails(parcoursId, container) {
    // Assure-toi que le cache des parcours est prêt
    if (!parcoursCache) {
        await populateParcoursDropdown(); // remplira parcoursCache
    }

    try {
        const res = await fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/emails`), {
            headers: { 'requesttoken': csrfToken }
        });
        const result = await res.json();

        container.innerHTML = '';
        if (result.status === 'ok' && Array.isArray(result.emails)) {
            result.emails.forEach(email => renderEmail(email, container));
        }
    } catch (err) {
        console.error('Erreur chargement emails:', err);
    }
}



    // --- Supprimer parcours ---
    function deleteParcours(parcoursId) {
        if (!confirm("Voulez-vous vraiment supprimer ce parcours ?")) return;
        fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/delete`), {
            method: 'POST',
            body: new URLSearchParams({ requesttoken: csrfToken })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'ok') {
                window.parcoursData = window.parcoursData.filter(p => p.id !== parcoursId);
                renderParcours();
            } else alert('Erreur: ' + (data.message || 'inconnue'));
        })
        .catch(err => console.error('Erreur suppression parcours:', err));
    }


    // --- Nouveau parcours ---
    cancelBtn.addEventListener('click', () => modalNew.classList.add('hidden'));
    saveBtn.addEventListener('click', async () => {
        const titre = titleInput.value.trim();
        if (!titre) return alert('Veuillez saisir un titre.');
        const formData = new FormData();
        formData.append('titre', titre);
        formData.append('requesttoken', csrfToken);

        try {
            const res = await fetch(window.createParcoursUrl, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'ok') {
                window.parcoursData.push(data.parcours);
                renderParcours();
                modalNew.classList.add('hidden');
            } else alert('Erreur: ' + (data.message || 'inconnue'));
        } catch (err) { console.error('Erreur réseau:', err); alert('Erreur réseau'); }
    });

    // --- Modale Message ---
    function openMessageModal(parcoursId) {
        modalMessage.dataset.parcoursId = parcoursId;
        modalMessage.classList.remove('hidden');

        fetch(getUrl(`/apps/emailbridge/message/${parcoursId}`))
            .then(resp => resp.json())
            .then(data => {
    		document.getElementById('message-subject').value = data.titre;
    		document.getElementById('message-body').value = data.contenu_text;
    		document.getElementById('message-button-text').value = data.label_bouton;
	    })
	    .catch(err => {
    		console.error(err);
	    })
    }


    document.getElementById('cancel-message').addEventListener('click', () => modalMessage.classList.add('hidden'));
    document.getElementById('save-message').addEventListener('click', async () => {
        const parcoursId = modalMessage.dataset.parcoursId;
        const formData = new FormData();
        formData.append('parcours_id', parcoursId);
        formData.append('title', document.getElementById('message-subject').value.trim());
        formData.append('body', document.getElementById('message-body').value.trim());
        formData.append('button', document.getElementById('message-button-text').value.trim());
        formData.append('requesttoken', csrfToken);

        try {
            const res = await fetch(getUrl(`/apps/emailbridge/message/${parcoursId}`), { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'ok') modalMessage.classList.add('hidden');
            else alert('Erreur: ' + (data.message || 'inconnue'));
        } catch (err) {
            console.error(err);
            alert('Erreur réseau');
        }
    });

    // --- Fichier cible ---
    function chooseFile(parcoursId, displayEl) {
        if (!window.OC || !OC.dialogs || !OC.dialogs.filepicker) return alert('File picker indisponible');
        OC.dialogs.filepicker(
            "Choisissez un fichier cible",
            async (filePath) => {
                displayEl.innerText = filePath;
                const formData = new FormData();
                formData.append('document_url', filePath);
                formData.append('requesttoken', csrfToken);
                try {
                    const res = await fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/saveFile`), { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.status !== 'ok') alert('Erreur sauvegarde fichier: ' + (data.message || 'inconnue'));
                } catch (err) { console.error('Erreur sauvegarde fichier:', err); alert('Erreur réseau'); }
            },
            false, "*/*"
        );
    }

// --- Test email ---
function testEmail(parcoursId, email) {
    if (!email) return alert("Veuillez entrer un email de test.");

    fetch(getUrl(`/apps/emailbridge/form/${parcoursId}/submit`), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ 
            email: email,
            parcoursId: parcoursId,
        })
    })
    .then(res => res.json())
    .then(data => {
        console.log("Test email result:", data); // debug

        // --- Gestion déjà inscrit ---
	if ((data.status === 'already' || data.status === 'already_subscribed' || data.status === 'already_registered') && data.redirect) {
    	    window.location.href = data.redirect;
	    return;
	}

        // --- Cas succès ---
        if (data.status === 'ok') {
            alert("Résultat du test : " + (data.message || "Succès"));
        } 
        // --- Cas erreur ---
        else if (data.status === 'error') {
            alert("Erreur : " + (data.message || "Inconnue"));
        } 
        // --- Cas inattendu ---
        else {
            alert("Réponse inattendue : " + JSON.stringify(data));
        }
    })
    .catch(err => { 
        console.error(err); 
        alert("Erreur lors de l'envoi : " + err); 
    });
}


// --- Mini-builder Email ---
const emailModal = document.getElementById('emailModal');
const emailForm = document.getElementById('emailForm');
const deleteBtn = document.getElementById('delete-email');
const cancelEmailBtn = document.getElementById('cancel-email');
const modalTitle = document.getElementById('emailModalTitle');
window.blocksContainer = document.getElementById('blocks-container');
const addBlockBtn = document.getElementById('add-block-btn');

// Champs timing
const sendDayInput = document.getElementById('send_day');
const sendTimeInput = document.getElementById('send_time');
const delayMinutesInput = document.getElementById('delay_minutes');
const delayTypeSelect = document.getElementById('delay_type');

// Ouvre la modale (nouvel email ou édition)
function openEmailModal(parcoursId, email = null) {
    modalTitle.textContent = email ? 'Modifier un email' : 'Nouvel email';
    emailModal.classList.remove('hidden');
    emailModal.dataset.parcoursId = parcoursId;
    emailModal.dataset.emailId = email?.id || '';

    // Reset formulaire
    emailForm.reset();
    blocksContainer.innerHTML = '';

    if (email) {
        emailForm.querySelector('#email_subject').value = email.sujet || '';
        emailForm.querySelector('#send_day').value = email.send_day ?? 0;
        emailForm.querySelector('#send_time').value = email.send_time || '';

// --- Gestion des règles personnalisées ---
const ruleNoWeekend = document.getElementById('rule-no-weekend');
const ruleNoHolidays = document.getElementById('rule-no-holidays');
const ruleRedirect = document.getElementById('rule-redirect');
const ruleRedirectTarget = document.getElementById('rule-redirect-target');
const ruleIfRepass = document.getElementById('rule-if-repass');
const ruleIfRepassTarget = document.getElementById('rule-if-repass-target');

// Reset des cases à cocher
[ruleNoWeekend, ruleNoHolidays, ruleRedirect, ruleIfRepass].forEach(cb => cb.checked = false);
[ruleRedirectTarget, ruleIfRepassTarget].forEach(sel => sel.value = '');

// Si l'email possède des règles, les charger
if (email?.rules) {
  try {
    const rules = typeof email.rules === 'string' ? JSON.parse(email.rules) : email.rules;

    ruleNoWeekend.checked = !!rules.noWeekend;
    ruleNoHolidays.checked = !!rules.noHolidays;
    if (rules.redirectTarget) {
      ruleRedirect.checked = true;
      ruleRedirectTarget.value = rules.redirectTarget;
    }
    if (rules.ifRepassTarget) {
      ruleIfRepass.checked = true;
      ruleIfRepassTarget.value = rules.ifRepassTarget;
    }
  } catch (err) {
    console.warn("Erreur parsing règles:", err);
  }
}



        // --- Gestion J0 spécial ---
        if (parseInt(email.send_day) === 0) {
            if (email.delay_minutes) {
                const delay = parseInt(email.delay_minutes, 10);
                if (delay % 60 === 0) {
                    delayTypeSelect.value = 'hours';
                    delayMinutesInput.value = delay / 60;
                } else {
                    delayTypeSelect.value = 'minutes';
                    delayMinutesInput.value = delay;
                }
            } else {
                delayMinutesInput.value = '';
                delayTypeSelect.value = 'minutes';
            }
        }

        if (email.contenu_json) loadBlocksFromJson(email.contenu_json);
    }
}


cancelEmailBtn.addEventListener('click', () => {
    emailModal.classList.add('hidden');
});

// Gestion du bouton supprimer
deleteBtn.addEventListener('click', () => {
    const emailId = emailModal.dataset.emailId;
    if (!emailId) return alert('Aucun email à supprimer');
    deleteEmail(emailId);
});

// Gestion des blocs
function createBlockElement(type, content = {}) {
  const block = document.createElement('div');
  block.className = 'email-block-item';
  block.style =
    'border:1px solid #ccc; padding:5px; margin-bottom:5px; border-radius:3px; position:relative;';
  block.dataset.type = type;

  // Bouton supprimer
  const removeBtn = document.createElement('button');
  removeBtn.type = 'button';
  removeBtn.textContent = '🗑️';
  removeBtn.style = 'position:absolute; top:5px; right:5px;';
  removeBtn.addEventListener('click', () => {
    if (type === 'texte') {
      const editorId = block.querySelector('textarea')?.id;
      if (editorId && tinymce.get(editorId)) {
        tinymce.get(editorId).remove();
      }
    }
    block.remove();
  });
  block.appendChild(removeBtn);

  // Bloc texte avec TinyMCE
  if (type === 'texte') {
    const textarea = document.createElement('textarea');
    textarea.id = 'tiny-' + Math.random().toString(36).substr(2, 9);
    textarea.value = content.content || '';
    block.appendChild(textarea);

    // Initialisation différée
    const initTiny = () => {
      if (typeof tinymce === 'undefined') {
        console.warn('⚠️ TinyMCE non défini, nouvelle tentative...');
        setTimeout(initTiny, 150);
        return;
      }

      if (!textarea.isConnected || textarea.offsetParent === null) {
        setTimeout(initTiny, 150);
        return;
      }

      const existing = tinymce.get(textarea.id);
      if (existing) existing.remove();

      tinymce.init({
        selector: `#${textarea.id}`,
        menubar: false,
        height: 300,
        plugins: 'link lists image code',
        toolbar:
          'undo redo | p h1 h2 h3 | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image code',
        branding: false,
        license_key: 'gpl',
        setup: (editor) => {
          // Ajouter boutons Paragraphe et titres
          const headings = ['p', 'h1', 'h2', 'h3'];
          headings.forEach((tag) => {
            editor.ui.registry.addButton(tag, {
              text: tag === 'p' ? 'Paragraphe' : tag.toUpperCase(),
              onAction: () => editor.execCommand('FormatBlock', false, tag),
            });
          });

          editor.on('change keyup', () => editor.save());
        },
      });
    };

    setTimeout(initTiny, 200);
  }

  // Bloc image
  else if (type === 'image') {
    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = "URL de l'image...";
    input.value = content.url || '';
    block.appendChild(input);
  }

  // Bloc bouton
  else if (type === 'bouton') {
    const inputLabel = document.createElement('input');
    inputLabel.type = 'text';
    inputLabel.placeholder = 'Texte du bouton...';
    inputLabel.value = content.label || '';

    const inputUrl = document.createElement('input');
    inputUrl.type = 'text';
    inputUrl.placeholder = 'URL du bouton...';
    inputUrl.value = content.url || '';

    block.appendChild(inputLabel);
    block.appendChild(inputUrl);
  }

  return block;
}

// Charger depuis JSON
function loadBlocksFromJson(data) {
    let arr = [];
    try {
        if (typeof data === 'string') arr = JSON.parse(data);
        else if (Array.isArray(data)) arr = data;
    } catch (e) {
        console.warn('JSON invalide pour les blocs', e);
    }

    // Nettoyage avant rechargement
    blocksContainer.innerHTML = '';

    arr.forEach(b => {
        const block = createBlockElement(b.type, b);
        blocksContainer.appendChild(block);
    });
}

// Sérialiser vers JSON
function serializeBlocksToJson() {
    const blocks = [];
    const blockElements = document.querySelectorAll('.email-block-item');

    blockElements.forEach(blockEl => {
        const type = blockEl.dataset.type || 'texte';
        let content = '';

        switch (type) {
            case 'texte':
                // Récupère le contenu TinyMCE ou textarea
                const textarea = blockEl.querySelector('textarea');
                content = textarea ? textarea.value : blockEl.innerHTML;

                // Nettoyage : supprime les <p> vides ou <br> isolés à la fin
                content = content.replace(/(<p>(\s|&nbsp;|<br>)*<\/p>)+$/gi, '');
                content = content.trim();
                break;

            case 'titre':
                const titreInput = blockEl.querySelector('input');
                content = titreInput ? titreInput.value.trim() : '';
                break;

            case 'image':
                const imgInput = blockEl.querySelector('input[type="text"]');
                content = imgInput ? imgInput.value.trim() : '';
                break;

            case 'bouton':
                const labelInput = blockEl.querySelector('.btn-label');
                const urlInput = blockEl.querySelector('.btn-url');
                content = {
                    label: labelInput ? labelInput.value.trim() : 'Voir',
                    url: urlInput ? urlInput.value.trim() : '#'
                };
                break;

            default:
                const defaultInput = blockEl.querySelector('textarea, input');
                content = defaultInput ? defaultInput.value.trim() : '';
                break;
        }

        blocks.push({
            type,
            content
        });
    });

    return blocks;
}

// Gestion des boutons "Ajouter un bloc"
document.querySelectorAll('.add-block-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const type = btn.dataset.type;
        blocksContainer.appendChild(createBlockElement(type));
    });
});





function ensureDelayFields() {
    if (document.getElementById('sendDelayWrapper')) return;

    const sendTimeInput = document.getElementById('emailSendTime');
    if (!sendTimeInput) return;

    const wrapper = document.createElement('div');
    wrapper.id = 'sendDelayWrapper';
    wrapper.className = 'form-group'; // <- alignement avec les autres champs
    wrapper.style.display = 'none';

    wrapper.innerHTML = `
        <label for="emailDelayValue">Délai après inscription</label>
        <div style="display:flex; gap:8px; align-items:center;">
            <input type="number" id="emailDelayValue" min="1" step="1" style="width:90px;" />
            <select id="emailDelayUnit">
                <option value="minutes">minutes</option>
                <option value="hours">heures</option>
            </select>

        </div>
    `;

    const parentGroup = sendTimeInput.closest('.form-group');
    if (parentGroup && parentGroup.parentNode) {
        parentGroup.parentNode.insertBefore(wrapper, parentGroup.nextSibling);
    }
}


function toggleDelayFieldsVisibility() {
    const dayInput = document.getElementById('emailSendDay');
    const wrapper = document.getElementById('sendDelayWrapper');
    const sendTimeInput = document.getElementById('emailSendTime');

    if (!dayInput || !wrapper || !sendTimeInput) return;

    const day = parseInt(dayInput.value, 10) || 0;

    // Le parent du champ heure
    const sendTimeWrapper = sendTimeInput.closest('.form-group');
    if (!sendTimeWrapper) return;

    if (day === 0) {
        wrapper.style.display = 'block';
        sendTimeWrapper.style.display = 'none';
    } else {
        wrapper.style.display = 'none';
        sendTimeWrapper.style.display = '';
    }
}

function formatDate(dateString) {
    if (!dateString) return '';

    const d = new Date(dateString + 'Z'); // le Z force l'interprétation en UTC

    // Calcule la différence en millisecondes
    const now = new Date();
    const diffMs = d - now;
    const diffSec = Math.round(diffMs / 1000);
    const diffMin = Math.round(diffSec / 60);
    const diffH = Math.round(diffMin / 60);
    const diffJ = Math.round(diffH / 24);

    // Format relatif
    let relative;
    if (Math.abs(diffSec) < 60) {
        relative = "à l'instant";
    } else if (Math.abs(diffMin) < 60) {
        relative = diffMin > 0 ? `dans ${diffMin} min` : `il y a ${-diffMin} min`;
    } else if (Math.abs(diffH) < 24) {
        relative = diffH > 0 ? `dans ${diffH} h` : `il y a ${-diffH} h`;
    } else if (Math.abs(diffJ) < 7) {
        relative = diffJ > 0 ? `dans ${diffJ} j` : `il y a ${-diffJ} j`;
    } else {
        // Si plus d'une semaine : format lisible complet
        relative = d.toLocaleString('fr-FR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    return relative;
}

// === Graphique des stats d'une séquence ===
function renderStatsChart(emails) {
    const ctx = document.getElementById('stats-chart');
    if (!ctx) return;

    // Supprimer l'ancien graphe si déjà présent
    if (window.statsChartInstance) {
        window.statsChartInstance.destroy();
    }

    const labels = emails.map(e => e.sujet || '(sans sujet)');
    const datasets = [
        {
            label: 'Envoyés',
            data: emails.map(e => e.stats?.sent ?? 0),
            backgroundColor: '#36A2EB'
        },
        {
            label: 'Ouverts',
            data: emails.map(e => e.stats?.opened ?? 0),
            backgroundColor: '#4BC0C0'
        },
        {
            label: 'Cliqués',
            data: emails.map(e => e.stats?.clicked ?? 0),
            backgroundColor: '#FFCE56'
        },
        {
            label: 'Désabonnés',
            data: emails.map(e => e.stats?.unsubscribed ?? 0),
            backgroundColor: '#FF6384'
        }
    ];

    window.statsChartInstance = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' },
                title: {
                    display: true,
                    text: 'Statistiques des emails de la séquence'
                }
            },
            // ⬇️⬇️ C’est ici qu’on change le comportement d’empilement
            scales: {
                x: { stacked: false },
                y: { stacked: false, beginAtZero: true }
            },
            // Optionnel : espacement et largeur des barres
            datasets: {
                bar: {
                    barPercentage: 0.8, // largeur des barres
                    categoryPercentage: 0.7 // espacement entre groupes
                }
            }
        }
    });
}




// Ouvre la modale Gestion et charge les inscriptions
async function openGestionModal(parcoursId) {
    try {
        // --- Récupération des inscriptions du parcours ---
	const res = await fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/inscriptions`));
        const data = await res.json();

        if (data.status !== 'ok') {
            alert('Erreur lors du chargement des inscriptions.');
            return;
        }

        // Vide le tableau existant
        const tbody = document.getElementById('gestion-table-body');
        const finisherBody = document.getElementById('finisher-table-body');
        tbody.innerHTML = '';
        finisherBody.innerHTML = '';

        // --- Remplit le tableau ---
        data.inscriptions.forEach(insc => {

// 👉 Détermine si c’est un finisher
    const isFinisher = insc.prochain_mail === null && insc.dernier_mail !== null;

if (isFinisher) {
        // --- Ajoute au tableau Finisher ---
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${insc.id}</td>
            <td>${insc.email}</td>
            <td>${insc.dernier_mail.sujet}</td>
            <td>${formatDate(insc.dernier_mail.date)}</td>
        `;
        finisherBody.appendChild(tr);
        return; // on ne l'affiche pas dans la table principale
    }

            const tr = document.createElement('tr');
            tr.classList.add(insc.statut); // ajoute class en_cours / termine / arrete

            tr.innerHTML = `
            <td>${insc.id}</td>
    	   <td>${insc.email}</td>
            <td>
        		${insc.dernier_mail ? `
         	<div><strong>${insc.dernier_mail.sujet}</strong></div>
         	<div class="msg-date">${formatDate(insc.dernier_mail.date)}</div>
        		` : '-'}
    	    </td>
    	    <td>
        		${insc.prochain_mail ? `
         	   <div><strong>${insc.prochain_mail.sujet}</strong></div>
         	   <div class="msg-date">${formatDate(insc.prochain_mail.date)}</div>
        		` : '-'}
    	</td>
    	<td>${insc.autres_parcours.map(p => p.titre).join(', ') || '-'}</td>
    	<td class="action-col">
    	    <button class="stop-seq-btn">⏹️ Stop</button>
    	    <button class="stop-all-btn">🛑 StopAll</button>
    	    <button class="redirect-btn">➡️ Rediriger</button>
    	</td>
	`;

            tbody.appendChild(tr);

            // --- Bouton Stop Séquence (une seule séquence) ---
            tr.querySelector('.stop-seq-btn').addEventListener('click', async () => {
                const firstEnvoi = insc.envois.find(e => e.status === 'en_attente');
                if (!firstEnvoi) {
                    alert('Aucune séquence en attente pour cet inscrit.');
                    return;
                }
                await stopSingleSequence(insc.id, firstEnvoi.sequence_id);
                tr.remove();
            });

            // --- Bouton Stop All ---
            tr.querySelector('.stop-all-btn').addEventListener('click', async () => {
                await stopAllSequence(insc.id);
                tr.remove();
            });

            // --- Bouton Rediriger ---
            tr.querySelector('.redirect-btn').addEventListener('click', async () => {
                await openRedirectModal(insc.id);
            });
        });

// --- Charger les emails + stats pour le graphique ---
const emailsResponse = await fetch(OC.generateUrl(`/apps/emailbridge/parcours/${parcoursId}/emails`));
const emailsData = await emailsResponse.json();

if (emailsData.status === 'ok' && Array.isArray(emailsData.emails)) {
    renderStatsChart(emailsData.emails);
} else {
    console.warn('Aucune donnée email pour ce parcours');
}

// Affiche la section finisher seulement si elle contient des données
// --- Si des finisher ont été ajoutés, affiche la section ---
const finisherSection = document.getElementById('finisher-section');
if (finisherBody.children.length > 0) {
    finisherSection.classList.remove('hidden');
} else {
    finisherSection.classList.add('hidden');
}


        // --- Ouvre la modale ---
        const modal = document.getElementById('modal-gestion');
        modal.classList.remove('hidden');

    } catch (err) {
        console.error(err);
        alert('Erreur réseau lors du chargement de la modale.');
    }
}

// Ferme la modale
function closeGestionModal() {
    const modal = document.getElementById('modal-gestion');
    modal.classList.add('hidden');
}

document.getElementById('cancel-gestion').addEventListener('click', closeGestionModal);

// --- Fonctions Stop ---
async function stopSingleSequence(inscriptionId, sequenceId) {
    const res = await fetch(getUrl(`/apps/emailbridge/inscription/${inscriptionId}/stop-single-sequence/${sequenceId}`), {
        method: 'POST'
    });

    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        console.error('Réponse non JSON stopSingleSequence:', text);
        alert('Erreur serveur : réponse inattendue.');
        return;
    }

    if (data.status !== 'ok') alert('Erreur : ' + data.message);
}

async function stopAllSequence(inscriptionId) {
    const res = await fetch(getUrl(`/apps/emailbridge/inscription/${inscriptionId}/stop-all-sequence`), {
        method: 'POST'
    });

    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        console.error('Réponse non JSON stopAllSequence:', text);
        alert('Erreur serveur : réponse inattendue.');
        return;
    }

    if (data.status !== 'ok') alert('Erreur : ' + data.message);
}

// --- Modale de redirection ---
async function openRedirectModal(inscriptionId) {
    try {
        // --- Récupère tous les parcours ---
        const res = await fetch(getUrl(`/apps/emailbridge/parcours/all`));
        const text = await res.text();

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Réponse non JSON (liste parcours):', text);
            alert('Erreur : la réponse du serveur n’est pas au format JSON.');
            return;
        }

        if (data.status !== 'ok') {
            alert('Impossible de récupérer les parcours.');
            return;
        }

        // --- Crée le contenu HTML avec radio boutons ---
        const container = document.getElementById('redirect-parcours-container');
        container.innerHTML = data.parcours.map(p => `
            <label>
                <input type="radio" name="redirect_parcours" value="${p.id}">
                ${p.titre}
            </label><br>
        `).join('');

        // --- Affiche la modale ---
        const modal = document.getElementById('modal-redirect');
        modal.classList.remove('hidden');

        // --- Configure le bouton confirmer ---
        const confirmBtn = document.getElementById('confirm-redirect');
        confirmBtn.onclick = async () => {
            const selected = Array.from(document.getElementsByName('redirect_parcours')).find(r => r.checked);
            if (!selected) {
                alert('Sélectionnez un parcours.');
                return;
            }

            const newParcoursId = selected.value;
            const redirectRes = await fetch(getUrl(`/apps/emailbridge/inscription/${inscriptionId}/redirect-sequence`), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({
                    parcoursId: newParcoursId,
                    requesttoken: csrfToken
                })
            });

            const redirectText = await redirectRes.text();
            let redirectData;
            try {
                redirectData = JSON.parse(redirectText);
            } catch (e) {
                console.error('Réponse non JSON redirect:', redirectText);
                alert('Erreur serveur : réponse inattendue.');
                return;
            }

            if (redirectData.status === 'ok') {
                alert('Inscription redirigée avec succès.');
            } else {
                alert('Erreur : ' + (redirectData.message || 'inconnue'));
            }

            modal.classList.add('hidden');
        };

    } catch (err) {
        console.error(err);
        alert('Erreur réseau lors de la récupération des parcours.');
    }
}


// Ferme la modale de redirection
document.getElementById('cancel-redirect').addEventListener('click', () => {
    document.getElementById('modal-redirect').classList.add('hidden');
});

async function loadEmailRules(parcoursId, emailId) {
    try {
        const response = await fetch(
            getUrl(`/apps/emailbridge/parcours/${parcoursId}/emails/${emailId}/rules`)
        );

        if (!response.ok) {
            console.error('Erreur HTTP lors du chargement des règles:', response.status);
            return { noWeekend: false, noHolidays: false, redirectTarget: null, ifRepassTarget: null };
        }

        const data = await response.json();
        if (data && data.rules) {
            return data.rules;
        } else {
            console.warn('Aucune règle trouvée pour cet email.');
            return { noWeekend: false, noHolidays: false, redirectTarget: null, ifRepassTarget: null };
        }

    } catch (error) {
        console.error('Erreur lors du chargement des règles', error);
        return { noWeekend: false, noHolidays: false, redirectTarget: null, ifRepassTarget: null };
    }
}


function saveEmailRules(parcoursId, emailId) {
  const modal = document.querySelector('#modalRegles');
  const formData = new FormData(modal.querySelector('form'));
  const rules = [];

  // On récupère les règles saisies dans le formulaire
  modal.querySelectorAll('.rule-item').forEach(item => {
    const condition = item.querySelector('.rule-condition')?.value || '';
    const value = item.querySelector('.rule-value')?.value || '';
    const action = item.querySelector('.rule-action')?.value || '';

    if (condition && action) {
      rules.push({ condition, value, action });
    }
  });

  // On ferme la modale visuellement
  $(modal).modal('hide');

  // On envoie la requête de sauvegarde
  fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/emails/${emailId}/rules/save`), {
    method: 'POST',
    headers: {
        'OCS-APIREQUEST': 'true',
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({ rules })
})
  .then(async response => {
    if (!response.ok) {
      const text = await response.text();
      throw new Error(`Erreur serveur: ${response.status} - ${text}`);
    }
    return response.json();
  })
  .then(data => {
    if (data && data.status === 'ok') {
      OC.dialogs.alert('Les règles ont été enregistrées avec succès.', 'Succès');
      // Rafraîchir la vue si besoin
      refreshSequenceList(parcoursId);
    } else {
      throw new Error('Réponse inattendue du serveur.');
    }
  })
  .catch(error => {
    console.error('Erreur lors de la sauvegarde des règles:', error);
    OC.dialogs.alert(`Erreur lors de la sauvegarde : ${error.message}`, 'Erreur');
  });
}

let parcoursCache = null;

async function populateParcoursDropdown() {
    const selects = [
        document.getElementById('rule-redirect-target'),
        document.getElementById('rule-if-repass-target'),
	document.getElementById('rule-redirect-on-click-target')
    ].filter(Boolean); // enlève les null

    if (!selects.length) return;

    // Si déjà chargé, on remplit tous les selects
    if (parcoursCache) {
        selects.forEach(sel => fillDropdown(sel, parcoursCache));
        return;
    }

    try {
        const response = await fetch(getUrl('/apps/emailbridge/parcours/all'));
        const data = await response.json();

        if (data.status === 'ok' && Array.isArray(data.parcours)) {
            parcoursCache = data.parcours;
            selects.forEach(sel => fillDropdown(sel, parcoursCache));
        } else {
            selects.forEach(sel => fillDropdown(sel, []));
        }
    } catch (e) {
        console.error('Erreur de chargement des parcours :', e);
    }
}


function fillDropdown(select, parcours) {
    select.innerHTML = '<option value="">-- Sélectionner un parcours --</option>';
    if (parcours.length) {
        parcours.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.titre;
            select.appendChild(opt);
        });
    } else {
        const opt = document.createElement('option');
        opt.textContent = 'Aucun parcours disponible';
        select.appendChild(opt);
    }
}

function resetRulesFields() {
    ['rule-no-weekend','rule-no-holidays','rule-redirect','rule-if-repass'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = false;
    });

    const redirectTarget = document.getElementById('rule-redirect-target');
    if (redirectTarget) redirectTarget.value = '';

    const repassTarget = document.getElementById('rule-if-repass-target');
    if (repassTarget) repassTarget.value = '';
}



async function openEmailModal(parcoursId, email = null) {
    const modalParcoursId = document.getElementById('modalParcoursId');
    if (modalParcoursId) modalParcoursId.value = parcoursId;

    if (!blocksContainer) return console.error('blocksContainer introuvable');
    blocksContainer.innerHTML = '';

    ensureDelayFields();
    await populateParcoursDropdown();

    // --- Éléments du DOM ---
    const modalEmailId   = document.getElementById('modalEmailId');
    const emailSujet     = document.getElementById('emailSujet');
    const emailSendDay   = document.getElementById('emailSendDay');
    const emailSendTime  = document.getElementById('emailSendTime');
    const delayValInput  = document.getElementById('emailDelayValue');
    const delayUnitSelect= document.getElementById('emailDelayUnit');
    const deleteBtn      = document.getElementById('delete-email');
    const modalTitle     = document.getElementById('emailModalTitle');

    // --- Règles ---
    const ruleNoWeekend = document.getElementById('rule-no-weekend');
    const ruleNoHolidays = document.getElementById('rule-no-holidays');
    const ruleRedirect = document.getElementById('rule-redirect');
    const ruleRedirectTarget = document.getElementById('rule-redirect-target');
    const ruleIfRepass = document.getElementById('rule-if-repass');
    const ruleIfRepassTarget = document.getElementById('rule-if-repass-target');
    const ruleRedirectOnClick = document.getElementById('rule-redirect-on-click');
    const ruleRedirectOnClickTarget = document.getElementById('rule-redirect-on-click-target');

    // --- Fonction de reset des règles ---
    function resetRulesFields() {
        [ruleNoWeekend, ruleNoHolidays, ruleRedirect, ruleIfRepass, ruleRedirectOnClick].forEach(cb => cb.checked = false);
        [ruleRedirectTarget, ruleIfRepassTarget, ruleRedirectOnClickTarget].forEach(sel => sel.value = '');
    }

    resetRulesFields();

    if (email) {
        // --- Mode édition ---
        if (modalEmailId) modalEmailId.value = email.id;
        if (emailSujet) emailSujet.value = email.sujet || '';
        if (emailSendDay) emailSendDay.value = email.send_day ?? 0;
        if (emailSendTime) emailSendTime.value = email.send_time || '08:00';

        // --- delay_minutes rétrocompatibilité ---
        const dm = email.delay_minutes ?? email.delayMinutes ?? null;
        if (dm !== null && dm !== undefined) {
            if (delayUnitSelect && delayValInput) {
                if (parseInt(dm, 10) % 60 === 0) {
                    delayUnitSelect.value = 'hours';
                    delayValInput.value = parseInt(dm, 10) / 60;
                } else {
                    delayUnitSelect.value = 'minutes';
                    delayValInput.value = parseInt(dm, 10);
                }
            }
        } else if (delayUnitSelect && delayValInput) {
            delayUnitSelect.value = 'minutes';
            delayValInput.value = 15;
        }

        // --- Chargement des blocs ---
        let blocs = [];
        try {
            if (typeof email.contenu === 'string') {
                blocs = JSON.parse(email.contenu);
            } else if (Array.isArray(email.contenu)) {
                blocs = email.contenu;
            }
        } catch (e) {
            console.warn('JSON invalide pour contenu:', e);
        }
        loadBlocksFromJson(blocs);
        if (blocksContainer.children.length === 0) {
            blocksContainer.appendChild(createBlockElement('texte'));
        }

        // --- Chargement des règles depuis DB ---
        try {
            const rules = await loadEmailRules(parcoursId, email.id);
            if (rules) {
                ruleNoWeekend.checked = rules.noWeekend || false;
                ruleNoHolidays.checked = rules.noHolidays || false;

                if (rules.redirectTarget) {
                    ruleRedirect.checked = true;
                    ruleRedirectTarget.value = rules.redirectTarget;
                }
                if (rules.ifRepassTarget) {
                    ruleIfRepass.checked = true;
                    ruleIfRepassTarget.value = rules.ifRepassTarget;
                }
                if (rules.redirectOnClick) {
                    ruleRedirectOnClick.checked = true;
                    ruleRedirectOnClickTarget.value = rules.redirectOnClick;
                }
            }
        } catch (e) {
            console.warn('Impossible de charger les règles:', e);
        }

        if (deleteBtn) deleteBtn.classList.remove('hidden');
        if (modalTitle) modalTitle.textContent = "Modifier l'email";
    } else {
        // --- Mode création ---
        if (modalEmailId) modalEmailId.value = '';
        if (emailForm) emailForm.reset();
        if (emailSendDay) emailSendDay.value = 0;
        if (emailSendTime) emailSendTime.value = '08:00';
        if (delayUnitSelect) delayUnitSelect.value = 'minutes';
        if (delayValInput) delayValInput.value = 15;

        resetRulesFields();

        blocksContainer.appendChild(createBlockElement('texte'));
        if (deleteBtn) deleteBtn.classList.add('hidden');
        if (modalTitle) modalTitle.textContent = "Créer un email";
    }

    // --- Affichage et gestion des champs retard/délai ---
    toggleDelayFieldsVisibility();
    const dayInput = document.getElementById('emailSendDay');
    if (dayInput && !dayInput._hasToggleListener) {
        dayInput.addEventListener('change', toggleDelayFieldsVisibility);
        dayInput._hasToggleListener = true;
    }

    // --- Affichage de la modale ---
    const emailModal = document.getElementById('emailModal');
    if (emailModal) emailModal.classList.remove('hidden');
}



// Fermer la modale si clic à l'extérieur
emailModal.addEventListener("click", function(event) {
  if (event.target === emailModal) {
    closeEmailModal(); // ou: emailModal.classList.add('hidden');
  }
});


// Soumettre avec la touche Entrée
document.getElementById("emailForm").addEventListener("keydown", function(event) {
  if (event.key === "Enter") {
    event.preventDefault(); // empêche reload
    this.requestSubmit();   // déclenche submit
  }
});


// Sauvegarde de l’email
emailForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    // 🔸 Avant toute lecture des blocs, on force TinyMCE à sauvegarder le contenu HTML dans les <textarea>
    if (typeof tinymce !== 'undefined') tinymce.triggerSave();

    const emailModal = document.getElementById('emailModal');
    const hasBlocks = blocksContainer.children.length > 0;
    if (!hasBlocks) {
        alert("Veuillez ajouter au moins un bloc avant d’enregistrer.");
        return;
    }

    const parcoursId = document.getElementById('modalParcoursId').value;
    const emailId = document.getElementById('modalEmailId').value;

    const sendDayVal = parseInt(document.getElementById('emailSendDay').value, 10) || 0;
    const sendTimeVal = document.getElementById('emailSendTime').value || '';

    let delayMinutes;
    let sendAt = null;

    if (sendDayVal === 0) {
        const delayVal = parseInt(document.getElementById('emailDelayValue')?.value || '0', 10);
        const delayUnit = document.getElementById('emailDelayUnit')?.value;
        delayMinutes = (delayVal > 0) ? (delayUnit === 'hours' ? delayVal * 60 : delayVal) : 15;

        const now = new Date();
        now.setMinutes(now.getMinutes() + delayMinutes);
        sendAt = now.toISOString().slice(0, 19).replace('T', ' ');
    } else if (sendDayVal > 0 && sendTimeVal) {
        const [hour, minute] = sendTimeVal.split(':').map(Number);
        const now = new Date();
        now.setDate(now.getDate() + sendDayVal);
        now.setHours(hour, minute, 0, 0);
        sendAt = now.toISOString().slice(0, 19).replace('T', ' ');
    }

    // 🔸 serializeBlocksToJson() lit maintenant les textarea mis à jour par TinyMCE
    const data = {
        sujet: document.getElementById('emailSujet').value,
        contenu: JSON.stringify(serializeBlocksToJson()), // ✅ tinyMCE pris en compte ici
        send_day: sendDayVal,
        send_time: sendTimeVal
    };
    if (sendDayVal === 0) data.delay_minutes = delayMinutes;
    if (sendAt) data.send_at = sendAt;

    // --- Règles ---
    const rules = {
        noWeekend: document.getElementById('rule-no-weekend')?.checked || false,
        noHolidays: document.getElementById('rule-no-holidays')?.checked || false,
        redirectTarget: document.getElementById('rule-redirect')?.checked
            ? document.getElementById('rule-redirect-target').value
            : null,
        ifRepassTarget: document.getElementById('rule-if-repass')?.checked
            ? document.getElementById('rule-if-repass-target').value
            : null,
        redirectOnClick: document.getElementById('rule-redirect-on-click')?.checked
            ? document.getElementById('rule-redirect-on-click-target').value
            : null
    };
    data.rules = JSON.stringify(rules);

    const url = emailId
        ? `/apps/emailbridge/parcours/${parcoursId}/emails/${emailId}/edit`
        : `/apps/emailbridge/parcours/${parcoursId}/emails/add`;

    try {
        const res = await fetch(getUrl(url), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ ...data, requesttoken: csrfToken })
        });

        const result = await res.json();

        if (result.status === 'ok' || result.success === true) {
            const container = document.querySelector(`.emails-list[data-parcours-id="${parcoursId}"]`);

            // Supprime l'ancien email si édition
            let emailItem = document.querySelector(`.email-item[data-email-id="${emailId}"]`);
            if (emailItem) emailItem.remove();

            const newEmailId = emailId || result.emailId;

            const emailData = {
                id: newEmailId,
                parcoursId,
                sujet: data.sujet,
                send_day: data.send_day,
                send_time: data.send_time,
                delay_minutes: data.delay_minutes ?? null,
                rules,
                stats: { sent:0, opened:0, clicked:0, unsubscribed:0, stopped:0, redirected:0 }
            };

            await loadEmails(parcoursId, container);
            if (emailModal) emailModal.classList.add('hidden');

        } else {
            alert('Erreur: ' + (result.message || 'inconnue'));
        }
    } catch (err) {
        alert('Erreur réseau');
        console.error(err);
    }
});



    cancelEmailBtn.addEventListener('click', () => emailModal.classList.add('hidden'));
    deleteBtn.addEventListener('click', () => {
        const emailId = document.getElementById('modalEmailId').value;
        const parcoursId = document.getElementById('modalParcoursId').value;
        if (emailId && confirm("Voulez-vous vraiment supprimer cet email ?")) {
            fetch(getUrl(`/apps/emailbridge/parcours/${parcoursId}/emails/${emailId}/delete`), { 
                method: 'POST', 
                body: new URLSearchParams({ requesttoken: csrfToken }) 
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'ok'){
                    emailModal.classList.add('hidden');
                    const container = document.querySelector(`.emails-list[data-parcours-id="${parcoursId}"]`);
                    loadEmails(parcoursId, container);
                } else alert('Erreur: ' + (data.message || 'inconnue'));
            });
        }
    });
    
    // --- Initial render ---
    renderParcours();

});
