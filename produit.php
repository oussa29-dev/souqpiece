<!DOCTYPE html>
<html lang="en">
    <?php
        require_once('dashboard/database.php');

        $id = $_GET['id'];
        // Charger depuis la base de données comme avant
        $sqlStates = $pdo->prepare('SELECT * FROM produit WHERE id_produit=?');
        $sqlStates->execute([$id]);
        $produit = $sqlStates->fetch(PDO::FETCH_ASSOC);
        
        if (!$produit) {
            die("Produit non trouvé");
        }
    ?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=$produit['libelle']?></title>
    <link rel="stylesheet" href="css/style.css">
    <script src="js/script.js"></script>
    <script src="js/produit.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/fontawesome/css/all.min.css">
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css" rel="stylesheet">
</head>

<body>
    <!--=============== navbar=================-->
    <?php 
        include('partie/navbar.php');
        $id_voiture = $_GET['id_voiture'] ?? null;

        // Pour les produits de la base de données, on garde la logique existante
        $sqlAff = $pdo->prepare('SELECT s.libelle as sous , v.modele as modele
            FROM categorie as c , sous_categorie as s , marque as m , voiture as v 
            WHERE s.id_sous_categorie=? AND c.id_categorie=s.id_categorie AND v.id_voiture=? AND m.id_marque=v.id_marque');
        $sqlAff->execute([$produit['id_sous_categorie'],$id_voiture]);
        $aff = $sqlAff->fetch();
        
        $sqlDesc = $pdo->prepare('SELECT description FROM pvd WHERE id_produit=? AND id_voiture=?');
        $sqlDesc->execute([$id,$id_voiture]);
        $desc = $sqlDesc->fetch();
        
    ?>  

    <!--=====================================
            detail
    ==========================================--> 
    <div class="detail">
        <div class="container">
            <div class="item">
                <div class="img-principale">
                    <?php
                        if($produit['img1'] == NULL){
                            $produit['img1'] = 'aucune.png';
                        }
                    ?>
                    <img src="img/produit/<?=$produit['img1']?>" alt="<?=$produit['libelle']?>" loading="lazy" id="mainImage">
                </div>
                <div class="gellery">
                    <i class="fa-solid fa-chevron-left" id="leftBtn"></i>
                    <i class="fa-solid fa-chevron-right" id="rightBtn"></i>
                    <div class="second-img">
                        <img src="img/produit/<?=$produit['img1']?>" alt="<?=$produit['libelle']?>" class="active">
                        <?php for($i = 2; $i <= 10 ; $i++): ?>
                            <?php if(!empty($produit['img' .$i])): ?>
                                <img src="img/produit/<?=$produit['img'. $i]?>" alt="<?=$produit['libelle']?>" loading="lazy">
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>                
                </div>
            </div>
            
            <div class="item">
                <div class="product-info">
                    <h1><?=$produit['libelle']?> - <?=$produit['marquepiece']?></h1>
                    
                    <?php if($produit['stock'] == 0): ?>
                        <div class="product-badge unavailable">
                            <i class="fa-solid fa-times-circle"></i>
                            Non disponible
                        </div>
                    <?php else: ?>
                        <div class="product-badge">
                            <i class="fa-solid fa-check-circle"></i>
                            En stock
                        </div>
                    <?php endif; ?>
                    
                    <div class="product-meta">
                        <h4><i class="fa-solid fa-car"></i> <?=$aff['modele']?></h4>
                        <h5><i class="fa-solid fa-cogs"></i> <?=$aff['sous']?></h5>
                    </div>

                    <div class="rating">
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <i class="fa-solid fa-star"></i>
                        <span class="rating-text">(4.8/5)</span>
                    </div>
                    
                    <h3 class="prix"><?=$produit['prix']?> DA</h3>
                    
                    <div class="product-description">
                        <h3><i class="fa-solid fa-info-circle"></i> Information sur le produit :</h3>
                        <p><?= !empty($desc['description']) ? $desc['description'] : 'Description détaillée du produit avec toutes les caractéristiques techniques et les informations importantes pour l\'acheteur.' ?></p>
                    </div>

                    <form method="POST" action="panier.php" class="product-form">
                        <input type="hidden" name="produit" value="<?=htmlspecialchars($id)?>">
                        <input type="hidden" name="id_voiture" value="<?=htmlspecialchars($id_voiture)?>">
                        
                        <div class="quantity-selector">
                            <label class="quantity-label">Quantité :</label>
                            <input type="number" name="quantite" value="1" min="1" class="quantity-input" <?= $produit['stock'] == 0 ? 'disabled' : '' ?>>
                        </div>
                        
                        <div class="button-group">
                            <input type="submit" name="add_cart" value="Ajouter au panier" class="btn btn-cart" <?= $produit['stock'] == 0 ? 'disabled' : '' ?>>
                            <input type="submit" name="acheter" value="Acheter maintenant" class="btn btn-buy" <?= $produit['stock'] == 0 ? 'disabled' : '' ?>>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Section produits similaires -->
    <?php
        $id_sous_categorie = $produit['id_sous_categorie'];
        $sqlSimilaires = $pdo->prepare('
            SELECT p.*
            FROM produit AS p
            JOIN pvd AS pv ON p.id_produit = pv.id_produit
            WHERE pv.id_voiture = :id_voiture
                AND p.id_sous_categorie = :id_sous_categorie
                AND p.id_produit != :id_produit
            LIMIT 10
        ');
        $sqlSimilaires->execute([
            ':id_voiture' => $id_voiture,
            ':id_sous_categorie' => $id_sous_categorie,
            ':id_produit' => $id,
        ]);
        
        $produitsSimilaires = $sqlSimilaires->fetchAll(PDO::FETCH_ASSOC);
    ?>
    
      <!--=====================================
            similaires
    ==========================================--> 
    <?php
        $id_sous_categorie = $produit['id_sous_categorie'];
        $sqlSimilaires = $pdo->prepare('
            SELECT p.*
            FROM produit AS p
            JOIN pvd AS pv ON p.id_produit = pv.id_produit
            WHERE pv.id_voiture = :id_voiture
                AND p.id_sous_categorie = :id_sous_categorie
                AND p.id_produit != :id_produit
            LIMIT 10
        ');
        $sqlSimilaires->execute([
            ':id_voiture' => $id_voiture,
            ':id_sous_categorie' => $id_sous_categorie,
            ':id_produit' => $id,
        ]);
        
        $produitsSimilaires = $sqlSimilaires->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="similaire">
        <h3>Des produits similaires</h3>
        <section class="splide splideSem" aria-label="Splide Basic HTML Example">
            <div class="splide__track">
                <ul class="splide__list">
                    <?php foreach($produitsSimilaires as $produitsSimilaire): ?>
                    <li class="splide__slide">
                        <!-- <div class="produit" style="width:100%">
                            <?php if($produitsSimilaire['stock'] == 0): ?>
                            <h3 class="stock">non disponible</h3><?php endif; ?>
                            <a href="produit.php?id=<?=$produitsSimilaire['id_produit']?>&id_voiture=<?=$id_voiture?>">
                                <img src="img/produit/<?= $produitsSimilaire['img1']?>" loading="lazy" alt="$produitsSimilaire['libelle']?>">
                                <h2><?= $produitsSimilaire['libelle']?></h2>
                                <h4><?= $aff['modele']?></h4>
                                <h4><?=$aff['sous']?></h4>
                                <h2 id="prix"><?=$produitsSimilaire['prix']?> DA</h2>
                                <i class="fa-solid fa-cart-shopping" id="ajouter-panier"></i>
                            </a>
                        </div> -->

                        <div class="product-card <?= $produitsSimilaire['stock'] == 0 ? 'out-of-stock' : '' ?>">
                            <a href="produit.php?id=<?=$produitsSimilaire['id_produit']?>&id_voiture=<?=$id_voiture?>" class="product-link">
                                <?php if($produitsSimilaire['stock'] == 0): ?>
                                <div class="stock-badge">Épuisé</div>
                                <?php endif; ?>
                                
                                <div class="product-image-container">
                                    <img src="img/produit/<?=$produitsSimilaire['img1']?>" alt="<?=$produitsSimilaire['libelle']?>" loading="lazy" class="product-image">
                                </div>
                                
                                <div class="product-info">
                                    <h3 class="product-title" style="font-size:16px"><?=$produitsSimilaire['libelle']?></h3>
                                    <div class="product-meta">
                                        <span class="product-brand"><?=$aff['sous']?></span>
                                        <span class="product-model"><?=$aff['modele']?></span>
                                    </div>
                                    <div class="product-footer">
                                        <span class="product-price"><?=number_format($produitsSimilaire['prix'], 0, ',', ' ')?> DA</span>
                                        <button class="add-to-cart">
                                            <i class="fas fa-shopping-cart"></i>
                                        </button>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var splide = new Splide('.splideSem', {
                perPage: 6,
                focus  : 0,
                omitEnd: true,
                gap : '20px',
                breakpoints: {
                    1200: {
                        perPage: 4,
                    },
                    992: {
                        perPage: 3,
                    },
                    768: {
                        perPage: 2,
                    },
                },
            });
            splide.mount();
        });
    </script>    

    <?php if(isset($_GET['added'])): ?>
        <script>
            swal({
                title: "Insertion avec succès!",
                text: "Le produit <?=$produit['libelle']?> a été bien ajouté au panier!",
                icon: "success",
            });
        </script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Gestion de la galerie d'images
            const mainImage = document.getElementById('mainImage');
            const thumbnails = document.querySelectorAll('.second-img img');
            const leftBtn = document.getElementById('leftBtn');
            const rightBtn = document.getElementById('rightBtn');
            const thumbnailContainer = document.querySelector('.second-img');
            
            let currentIndex = 0;
            let images = [];
            
            // Collecter toutes les images disponibles
            thumbnails.forEach((thumbnail, index) => {
                images.push(thumbnail.src);
                
                thumbnail.addEventListener('click', function() {
                    currentIndex = index;
                    updateMainImage();
                    updateActiveThumbnail();
                });
            });
            
            // Navigation avec les flèches seulement si il y a plus d'une image
            if(images.length > 1) {
                leftBtn.addEventListener('click', function() {
                    currentIndex = currentIndex > 0 ? currentIndex - 1 : images.length - 1;
                    updateMainImage();
                    updateActiveThumbnail();
                    scrollToActiveThumbnail();
                });
                
                rightBtn.addEventListener('click', function() {
                    currentIndex = currentIndex < images.length - 1 ? currentIndex + 1 : 0;
                    updateMainImage();
                    updateActiveThumbnail();
                    scrollToActiveThumbnail();
                });
            } else {
                // Cacher les boutons si une seule image
                leftBtn.style.display = 'none';
                rightBtn.style.display = 'none';
            }
            
            function updateMainImage() {
                if(images[currentIndex]) {
                    mainImage.src = images[currentIndex];
                }
            }
            
            function updateActiveThumbnail() {
                thumbnails.forEach((thumb, index) => {
                    thumb.classList.toggle('active', index === currentIndex);
                });
            }
            
            function scrollToActiveThumbnail() {
                if(thumbnails[currentIndex]) {
                    thumbnails[currentIndex].scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                        inline: 'center'
                    });
                }
            }
            
            // Animation des cartes produits au scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -100px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.animation = 'fadeInUp 0.6s ease-out forwards';
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.produit').forEach(produit => {
                observer.observe(produit);
            });
        });
    </script>

    <?php include('partie/footer.php') ?>  
</body>
</html>