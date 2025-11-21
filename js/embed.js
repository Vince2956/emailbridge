document.addEventListener("DOMContentLoaded", function() {
    console.log("EmailBridge embed.js loaded");

    const containers = document.querySelectorAll('[id^="emailbridge-form-"]');

    containers.forEach(container => {
        const parcoursId = container.dataset.id || container.id.replace('emailbridge-form-', '');
        if (!parcoursId) return;

        container.innerHTML = `
            <form id="emailbridge-external-form-${parcoursId}">
                <input type="email" name="email" placeholder="Votre email" required />
                <button type="submit">Envoyer</button>
            </form>
            <div id="emailbridge-message-${parcoursId}"></div>
        `;

        const form = document.getElementById(`emailbridge-external-form-${parcoursId}`);
        const msg  = document.getElementById(`emailbridge-message-${parcoursId}`);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const params = new URLSearchParams();
            params.append('email', form.email.value);

            try {
                const res = await fetch(
                    window.EMAILBRIDGE_API_ENDPOINT.replace('{id}', parcoursId),
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: params.toString(),
                        credentials: 'include'
                    }
                );

                if (!res.ok) {
                    msg.textContent = "Erreur lors de l’envoi : " + res.status;
                    return;
                }

                let data;
                try {
                    data = await res.json();
                } catch (err) {
                    console.error('Réponse non JSON :', await res.text());
                    msg.textContent = "Erreur serveur (réponse inattendue).";
                    return;
                }

                msg.textContent = data.message || "Merci ! Votre demande a bien été enregistrée.";

            } catch (err) {
                console.error('Erreur réseau :', err);
                msg.textContent = "Erreur réseau";
            }
        });
    });
});
