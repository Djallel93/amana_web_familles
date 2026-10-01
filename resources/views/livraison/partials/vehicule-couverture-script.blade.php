{{--
    JS des blocs « Véhicule » / « Couverture »
    (livraison/partials/vehicule-couverture.blade.php) — à inclure UNE
    seule fois par page, après le(s) bloc(s). Vanilla, comme le reste de
    ces deux écrans (ni l'un ni l'autre n'est une île Vue).

    API exposée : window.VehiculeCouverture.{appliquer, lire,
    afficherErreurs}. Chaque racine `[data-vc-root]` est initialisée avec
    son attribut data-etat.
--}}
<script>
    (function () {
        if (window.VehiculeCouverture) return;

        const un = (racine, cle) => racine.querySelector('[data-vc="' + cle + '"]');
        const tous = (racine, cle) => [...racine.querySelectorAll('[data-vc="' + cle + '"]')];
        const secteursDeVille = (racine, idVille) => tous(racine, 'secteur').filter((c) => c.dataset.ville === String(idVille));

        function griser(fieldset, grise) {
            fieldset.disabled = grise;
            fieldset.classList.toggle('opacity-50', grise);
        }

        function rafraichir(racine) {
            const memeVehicule = un(racine, 'vehicule-confirme').checked;
            const permis = un(racine, 'permis');
            const type = un(racine, 'vehicule-type');

            griser(un(racine, 'vehicule-champs'), memeVehicule);
            if (!memeVehicule && !permis.checked) type.value = '';
            // La liste de véhicules n'est accessible qu'avec le permis.
            type.disabled = memeVehicule || !permis.checked;
            un(racine, 'note-sans-permis').hidden = memeVehicule || permis.checked;

            const memeZone = un(racine, 'coverage-confirmee').checked;
            griser(un(racine, 'couverture-champs'), memeZone);
            un(racine, 'tout-rien').disabled = memeZone;

            // État de chaque ville : cochée si tous ses secteurs le sont,
            // « indéterminée » si une partie seulement.
            tous(racine, 'ville').forEach((caseVille) => {
                const secteurs = secteursDeVille(racine, caseVille.dataset.ville);
                const n = secteurs.filter((c) => c.checked).length;
                caseVille.checked = n > 0 && n === secteurs.length;
                caseVille.indeterminate = n > 0 && n < secteurs.length;
                const compteur = racine.querySelector('[data-vc="compteur-ville"][data-ville="' + caseVille.dataset.ville + '"]');
                if (compteur) compteur.textContent = n > 0 ? n + '/' + secteurs.length : '';
            });
        }

        function cacherErreurs(racine) {
            ['erreur-vehicule', 'erreur-couverture'].forEach((cle) => {
                const p = un(racine, cle);
                p.hidden = true;
                p.textContent = '';
            });
        }

        function appliquer(racine, etat) {
            etat = etat || {};
            un(racine, 'vehicule-confirme').checked = !!etat.vehicule_confirme;
            un(racine, 'permis').checked = !!etat.permis;
            un(racine, 'vehicule-type').value = etat.id_vehicule_type ? String(etat.id_vehicule_type) : '';
            un(racine, 'coverage-confirmee').checked = !!etat.coverage_confirmee;
            const choisis = (etat.secteurs || []).map(String);
            tous(racine, 'secteur').forEach((c) => { c.checked = choisis.includes(c.value); });
            cacherErreurs(racine);
            rafraichir(racine);
        }

        function lire(racine) {
            const memeVehicule = un(racine, 'vehicule-confirme').checked;
            const permis = un(racine, 'permis').checked;
            const memeZone = un(racine, 'coverage-confirmee').checked;
            const type = un(racine, 'vehicule-type').value;

            return {
                vehicule_confirme: memeVehicule,
                permis: memeVehicule ? null : permis,
                id_vehicule_type: !memeVehicule && permis && type ? Number(type) : null,
                coverage_confirmee: memeZone,
                secteurs: memeZone ? [] : tous(racine, 'secteur').filter((c) => c.checked).map((c) => Number(c.value)),
            };
        }

        // Erreurs de validation Laravel (422) → message sous le bloc concerné.
        function afficherErreurs(racine, erreurs) {
            cacherErreurs(racine);
            const premier = (cles) => {
                for (const cle of cles) {
                    if (erreurs && erreurs[cle] && erreurs[cle][0]) return erreurs[cle][0];
                }
                return null;
            };
            [
                ['erreur-vehicule', ['vehicule_confirme', 'permis', 'id_vehicule_type']],
                ['erreur-couverture', ['coverage_confirmee', 'secteurs']],
            ].forEach(([cle, champs]) => {
                const message = premier(champs);
                if (!message) return;
                const p = un(racine, cle);
                p.textContent = message;
                p.hidden = false;
            });
        }

        function initialiser(racine) {
            racine.addEventListener('change', (e) => {
                const cible = e.target;
                if (cible.matches('[data-vc="ville"]')) {
                    secteursDeVille(racine, cible.dataset.ville).forEach((c) => { c.checked = cible.checked; });
                }
                rafraichir(racine);
            });

            racine.addEventListener('click', (e) => {
                if (!e.target.closest('[data-vc="tout-rien"]')) return;
                const secteurs = tous(racine, 'secteur');
                const toutCoche = secteurs.length > 0 && secteurs.every((c) => c.checked);
                secteurs.forEach((c) => { c.checked = !toutCoche; });
                rafraichir(racine);
            });

            let etat = {};
            try { etat = JSON.parse(racine.dataset.etat || '{}'); } catch (e) { etat = {}; }
            appliquer(racine, etat);
        }

        window.VehiculeCouverture = { appliquer, lire, afficherErreurs };
        document.querySelectorAll('[data-vc-root]').forEach(initialiser);
    })();
</script>
