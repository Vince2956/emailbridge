document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById('emailForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(form);
        const parcoursId = form.dataset.parcoursId;

        // Construire URL de soumission (OC.generateUrl si disponible)
        const submitUrl = parcoursId
            ? ((typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl(`/apps/emailbridge/form/${parcoursId}/submit`) : `/apps/emailbridge/form/${parcoursId}/submit`)
            : ((typeof OC !== 'undefined' && OC.generateUrl) ? OC.generateUrl('/apps/emailbridge/submit') : '/apps/emailbridge/submit');

        // convertir FormData en x-www-form-urlencoded
        const params = new URLSearchParams();
        for (const [k, v] of formData.entries()) {
            params.append(k, v);
        }

        try {
            const res = await fetch(submitUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: params.toString()
            });

            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Réponse non-JSON lors du submit form:', text);
                document.getElementById('result').innerText = "Erreur serveur (réponse inattendue).";
                return;
            }

            document.getElementById('result').innerText = data.message || JSON.stringify(data);
        } catch (err) {
            document.getElementById('result').innerText = "❌ Erreur : " + err;
        }
    });
});
