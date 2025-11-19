(function() {
    const script = document.currentScript;
    const id = script.dataset.id;
    const baseUrl = new URL(script.src).origin;

    const target = document.getElementById(`emailbridge-form-${id}`);
    if (!target) {
        console.error("EmailBridge : div cible introuvable (#emailbridge-form-" + id + ")");
        return;
    }

    // Charger le HTML du formulaire
    fetch(`${baseUrl}/apps/emailbridge/formEmbed/${id}`)
        .then(r => r.json())
        .then(data => {
            target.innerHTML = data.html;

            const form = target.querySelector("#emailbridge-form");
            const resultBox = target.querySelector("#emailbridge-result");

            if (!form) {
                resultBox.innerHTML = "Erreur : formulaire absent.";
                return;
            }

            form.addEventListener("submit", async (e) => {
                e.preventDefault();
                const formData = new FormData(form);

                const res = await fetch(`${baseUrl}/apps/emailbridge/formEmbed/${id}/submit`, {
                    method: "POST",
                    body: new URLSearchParams(formData)
                });

                const j = await res.json();
                resultBox.innerHTML = j.message;
            });
        })
        .catch(err => {
            target.innerHTML = "Erreur lors du chargement du formulaire.";
            console.error("EmailBridge embed fetch error:", err);
        });
})();
