<?php
// Grille des 10 emplacements photo des formulaires ajouter/modifier produit.
// Les noms de champs (img_produit, img_produit2..10) sont ceux que le
// traitement de ajouter-produit.php lit deja : seul l'affichage change.
//
// $produit : ligne produit (mode modification, montre les photos deja
// enregistrees) ou null (mode ajout).
function photos_produit_afficher(?array $produit): void
{
    ?>
    <style>
        .ph-bloc{margin:14px 0 6px}
        .ph-titre{font-weight:600;margin:0 0 4px}
        .ph-aide{color:#555;font-size:14px;margin:0 0 12px;max-width:80ch}
        .ph-grille{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin:0 0 16px}
        .ph-slot{display:flex;flex-direction:column;gap:6px;position:relative}
        .ph-cadre{position:relative;display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;border:2px dashed #b9c1cc;border-radius:10px;background:#f6f8fa;overflow:hidden;cursor:pointer;transition:border-color .15s,background-color .15s}
        .ph-cadre:hover{border-color:#126CFB;background:#eef4ff}
        .ph-cadre img{width:100%;height:100%;object-fit:contain;background:#fff;display:none}
        .ph-vide{display:flex;flex-direction:column;align-items:center;gap:6px;color:#6b7684;font-size:13px;text-align:center;padding:8px}
        .ph-vide i{font-size:26px}
        .ph-slot.ph-a-image .ph-cadre{border-style:solid;border-color:#c9d1db;background:#fff}
        .ph-slot.ph-a-image .ph-cadre img{display:block}
        .ph-slot.ph-a-image .ph-vide{display:none}
        .ph-slot.ph-nouveau .ph-cadre{border-color:rgb(24,185,24);box-shadow:0 0 0 2px rgba(24,185,24,.25)}
        .ph-ligne{min-height:22px;display:flex;align-items:center;justify-content:space-between;gap:6px;font-size:14px}
        .ph-nom{font-weight:600}
        .ph-badge{font-size:12px;border-radius:999px;padding:2px 8px;color:#fff;background:#126CFB}
        .ph-slot.ph-nouveau .ph-badge{background:rgb(24,185,24)}
        .ph-etat{font-size:12px;color:#6b7684;min-height:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ph-slot.ph-nouveau .ph-etat{color:rgb(18,140,18)}
        .ph-annuler{display:none;position:absolute;top:6px;right:6px;width:26px;height:26px;border:none;border-radius:50%;background:rgba(18,18,18,.75);color:#fff;font-size:15px;line-height:26px;padding:0;cursor:pointer}
        .ph-slot.ph-nouveau .ph-annuler{display:block}
        .page-voiture form .ph-slot input[type="file"]{position:absolute;width:1px;height:1px;margin:0;padding:0;border:0;opacity:0;pointer-events:none}
    </style>
    <div class="ph-bloc">
        <p class="ph-titre">Photos du produit</p>
        <p class="ph-aide">
            La photo 1 est l'image principale affichée dans les listes.
            Cliquer sur un emplacement pour choisir un fichier.
            <?= $produit !== null
                ? "Sur une photo existante, le nouveau fichier la remplacera à l'enregistrement ; un emplacement non touché reste inchangé."
                : "Un emplacement laissé vide reste sans photo." ?>
        </p>
        <div class="ph-grille">
            <?php for ($n = 1; $n <= 10; $n++):
                $champ = $n === 1 ? 'img_produit' : 'img_produit' . $n;
                $fichier = $produit !== null ? trim((string)($produit['img' . $n] ?? '')) : '';
                $existe = $fichier !== '';
            ?>
                <div class="ph-slot<?= $existe ? ' ph-a-image' : '' ?>" data-existe="<?= $existe ? '1' : '0' ?>">
                    <label class="ph-cadre" for="ph-input-<?= $n ?>">
                        <img src="<?= $existe ? '../img/produit/' . htmlspecialchars($fichier) : '' ?>" alt="Photo <?= $n ?>">
                        <span class="ph-vide"><i class="fa-solid fa-camera"></i>Ajouter</span>
                    </label>
                    <button type="button" class="ph-annuler" title="Annuler ce fichier" aria-label="Annuler le fichier choisi pour la photo <?= $n ?>">&times;</button>
                    <input type="file" id="ph-input-<?= $n ?>" name="<?= $champ ?>" accept="image/*">
                    <div class="ph-ligne">
                        <span class="ph-nom">Photo <?= $n ?></span>
                        <?php if ($n === 1): ?><span class="ph-badge" style="background:#121212">Principale</span><?php endif; ?>
                    </div>
                    <div class="ph-etat"><?= $existe ? 'Enregistrée' : 'Vide' ?></div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <script>
        (function () {
            document.querySelectorAll('.ph-slot').forEach(function (slot) {
                var input = slot.querySelector('input[type="file"]');
                var img = slot.querySelector('.ph-cadre img');
                var etat = slot.querySelector('.ph-etat');
                var annuler = slot.querySelector('.ph-annuler');
                var existe = slot.getAttribute('data-existe') === '1';
                var srcInitial = img.getAttribute('src');
                var urlLocale = null;

                img.addEventListener('error', function () {
                    if (slot.classList.contains('ph-nouveau') || !img.getAttribute('src')) { return; }
                    slot.classList.remove('ph-a-image');
                    etat.textContent = 'Fichier introuvable';
                });

                function restaurer() {
                    if (urlLocale) { URL.revokeObjectURL(urlLocale); urlLocale = null; }
                    input.value = '';
                    slot.classList.remove('ph-nouveau');
                    if (existe) {
                        img.src = srcInitial;
                        slot.classList.add('ph-a-image');
                        etat.textContent = 'Enregistrée';
                    } else {
                        img.removeAttribute('src');
                        slot.classList.remove('ph-a-image');
                        etat.textContent = 'Vide';
                    }
                }

                input.addEventListener('change', function () {
                    if (!input.files || !input.files[0]) { restaurer(); return; }
                    var fichier = input.files[0];
                    if (urlLocale) { URL.revokeObjectURL(urlLocale); }
                    urlLocale = URL.createObjectURL(fichier);
                    img.src = urlLocale;
                    slot.classList.add('ph-a-image', 'ph-nouveau');
                    etat.textContent = (existe ? 'Remplace : ' : 'Nouvelle : ') + fichier.name;
                    etat.title = fichier.name;
                });
                annuler.addEventListener('click', restaurer);
            });
        })();
    </script>
    <?php
}
