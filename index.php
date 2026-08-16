<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/script.js"></script>
    <title>Souq piece</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300&family=Oswald&family=Pacifico&family=Roboto&family=Roboto+Slab:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/fontawesome/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css" rel="stylesheet">
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
</head>
<body>

    <!--============================================================
                    header
    =============================================================-->
    <div class="hero-section">
        <?php 
            require_once('dashboard/database.php');
            include('partie/navbar.php');
            $sqlStates = $pdo->prepare('SELECT * FROM setting WHERE id=?');
            $sqlStates->execute([1]);
            $setting = $sqlStates->fetch(PDO::FETCH_ASSOC);
        ?>
        <div class="hero-content-wrapper">
        <div class="hero-content">
            <h1>Souqpièces Algérie | Votre spécialiste en pièces détachées automobiles</h1>
            <p class="hero-subtitle">Trouvez la pièce qu'il vous faut parmi notre large catalogue de <strong>pièces détachées voiture</strong> pour toutes marques en Algérie. Livraison rapide et prix compétitifs.</p>
            
            <div class="hero-search">
                <form action="product.php" method="GET" id="searchForm2">
                    <div class="search-container search-autocomplete">
                        <input 
                            type="text" 
                            name="query" 
                            id="searchInput2"
                            placeholder="Rechercher une référence, une pièce auto..." 
                            required 
                            class="search-input"
                            aria-label="Recherche de pièces détachées"
                            autocomplete="off"
                        />
                        <div class="suggestions-box"></div>
                        <button type="submit" class="search-button">
                            <span>Rechercher</span>
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="hero-keywords">
                <span>Pièces détachées Alger</span>
                <span>Pièces auto pas cher</span>
                <span>Réparation voiture</span>
                <span>Vente pièces automobiles</span>
            </div>
        </div>
        </div>
    </div>
    <style>
        .hero-section{
            /*background-image: linear-gradient(rgba(40, 40, 49, 0.5),rgba(58, 72, 73, 0.4),rgba(0, 0, 0, 0.6)), url(../img/<?=$setting['image']?>) ;*/
             background: 
                    /* Couche rouge en bas */
                    linear-gradient(to top, rgba(225, 41, 41, 0.4) 0%, transparent 30%),
                    /* Noir semi-transparent centré */
                    linear-gradient(rgba(20, 20, 20, 0.7), rgba(20, 20, 20, 0.7)),
                    /* Image originale */ 
                    url(../img/<?=$setting['image']?>) center/cover no-repeat;
        }
    </style>
    <!--============================================================
                    brand
    =============================================================-->
    <section class="brand-section" id="brand">
        <div class="section-header">
            <h2 class="section-title">Quelle est la <span>marque</span> de votre voiture ?</h2>
            <p class="section-subtitle">Trouvez les pièces adaptées à votre véhicule</p>
        </div>

        <div class="brand-carousel">
            <?php
            $sql = 'SELECT * FROM marque';
            $query = $pdo->query($sql);
            $brands = $query->fetchAll();
            foreach($brands as $brand):
            ?>
            <a href="voiture.php?id=<?=$brand['id_marque']?>" class="brand-card">
                <div class="brand-logo-container">
                    <img src="img/logo/<?=$brand['logo']?>" alt="<?=$brand['libelle']?>" loading="lazy" class="brand-logo">
                </div>
                <span class="brand-name"><?=$brand['libelle']?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Section Catégories -->
    <section class="category-section" id="section-categories">
        <div class="section-header">
            <h2 class="section-title">Découvrez nos <span>catégories</span></h2>
            <p class="section-subtitle">Toutes les pièces pour votre véhicule</p>
        </div>

        <div class="category-grid">
            <?php
            $sqlStates = $pdo->prepare('SELECT * FROM categorie');
            $sqlStates->execute();
            $categories = $sqlStates->fetchAll(PDO::FETCH_ASSOC);
            foreach($categories as $categorie):
            ?>
            <a href="product.php?id_categorie=<?=$categorie['id_categorie']?>" class="category-card">
                <div class="category-image-container">
                    <img src="img/categories/<?=$categorie['img']?>" alt="<?=$categorie['libelle']?>" loading="lazy" class="category-image">
                    <div class="category-overlay"></div>
                </div>
                <div class="category-info">
                    <h3 class="category-title"><?=$categorie['libelle']?></h3>
                    <p class="category-title-ar"><?=$categorie['arabe']?></p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Section Produits -->
    <section class="products-section" id="section-produits">
        <div class="section-header">
            <h2 class="section-title">Découvrez nos <span>produits</span></h2>
            <p class="section-subtitle">Les pièces les plus demandées</p>
        </div>

        <?php
        foreach($categories as $categorie){
            $sqlProduit = $pdo->prepare('SELECT * FROM produit WHERE id_categorie=? AND trie > 0 ORDER BY trie LIMIT 8');
            $sqlProduit->execute([$categorie['id_categorie']]);
            $produits = $sqlProduit->fetchAll(PDO::FETCH_ASSOC);
            
            if(!empty($produits)):
        ?>
        <div class="product-category">
            <div class="category-header">
                <h3 class="category-name"><?=$categorie['libelle']?></h3>
                <h3 class="category-name-ar"><?=$categorie['arabe']?></h3>
            </div>
            
            <div class="product-grid">
                <?php    
                foreach($produits as $produit){  
                    $sqlVoit = $pdo->prepare('SELECT id_voiture FROM pvd WHERE id_produit=?');
                    $sqlVoit->execute([$produit['id_produit']]);
                    $id_voiture = $sqlVoit->fetchColumn();

                    $sqlModele = $pdo->prepare('SELECT libelle FROM marque WHERE id_marque= (SELECT id_marque FROM voiture WHERE id_voiture=?)');
                    $sqlModele->execute([$id_voiture]);
                    $marque = $sqlModele->fetchColumn();  
                    
                    $sqlMarque = $pdo->prepare('SELECT modele FROM voiture WHERE id_voiture=?');
                    $sqlMarque->execute([$id_voiture]);
                    $modele = $sqlMarque->fetchColumn(); 
                ?>
                <div class="product-card <?= $produit['stock'] == 0 ? 'out-of-stock' : '' ?>">
                    <a href="produit.php?id=<?=$produit['id_produit']?>&id_voiture=<?=$id_voiture?>" class="product-link">
                        <?php if($produit['stock'] == 0): ?>
                        <div class="stock-badge">Épuisé</div>
                        <?php endif; ?>
                        
                        <div class="product-image-container">
                            <img src="img/produit/<?=$produit['img1']?>" alt="<?=$produit['libelle']?>" loading="lazy" class="product-image">
                        </div>
                        
                        <div class="product-info">
                            <h3 class="product-title"><?=$produit['libelle']?></h3>
                            <div class="product-meta">
                                <span class="product-brand"><?=$marque?></span>
                                <span class="product-model"><?=$modele?></span>
                            </div>
                            <div class="product-footer">
                                <span class="product-price"><?=number_format($produit['prix'], 0, ',', ' ')?> DA</span>
                                <button class="add-to-cart">
                                    <i class="fas fa-shopping-cart"></i>
                                </button>
                            </div>
                        </div>
                    </a>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php 
            endif;
        } 
        ?>
    </section>
    
    <script>
        // Script pour le carousel personnalisé (optionnel)
        document.addEventListener('DOMContentLoaded', function() {
            // Animation au scroll
            const sections = document.querySelectorAll('section');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, { threshold: 0.1 });
            
            sections.forEach(section => {
                section.style.opacity = 0;
                section.style.transform = 'translateY(20px)';
                section.style.transition = 'all 0.6s ease-out';
                observer.observe(section);
            });
            
            // Bouton ajouter au panier
            document.querySelectorAll('.add-to-cart').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const productCard = this.closest('.product-card');
                    if(productCard.classList.contains('out-of-stock')) return;
                    
                    // Animation d'ajout au panier
                    this.innerHTML = '<i class="fas fa-check"></i>';
                    this.style.background = '#28a745';
                    
                    setTimeout(() => {
                        this.innerHTML = '<i class="fas fa-shopping-cart"></i>';
                        this.style.background = '#e12929';
                    }, 1000);
                    
                    // Ici vous pourriez ajouter une requête AJAX pour ajouter au panier
                });
            });
        });
    </script>
    <!--============================================================
                  Boutique
    =============================================================-->
    <div class="section-boutique">
        <div class="titre"><h1>A propos de notre <span>boutique</span></h1></div>
        <div class="container">
            <div class="item">
                <i class="fa-solid fa-truck"></i>
                <h3>Livraison 58 Wilayas</h3>
            </div>
            <div class="item">
                <i class="fa-solid fa-sack-dollar"></i>
                <h3>Paiement a la livraison</h3>
            </div>
            <div class="item">
                <i class="fa-solid fa-award"></i>
                <h3>Garantie sur nos produit</h3>
            </div>
        </div>
    </div>
    <!--====================================================
                        contect
    =======================================================-->
    <div class="contact" id="contact">
        <div class="info">
            <h3>Contactez nous</h3>
            <form method="POST">
                <input type="text" name="nom" placeholder="Nom" required>
                <input type="tel" name="tel" placeholder="N° Téléphone" required>
                <input type="email" name="email" placeholder="Email">
                <textarea name="message" id="message" placeholder="Message"></textarea>
                <input type="submit" name="envoyer" value="Envoyer">
            </form>
            <?php
                if(isset($_POST['envoyer'])){
                    $nom = $_POST['nom'];
                    $tel = $_POST['tel'];
                    $email = $_POST['email'];
                    $message = $_POST['message'];

                    $sqlContact = $pdo->prepare('INSERT INTO contact(nom,tel,email,message) VALUES (?,?,?,?)');
                    $sqlContact->execute([$nom,$tel,$email,$message]);
                    ?>
                    <script> swal("Merci pour votre message", "Merci pour votre intérêt. Nous vous répondrons rapidement.", "success"); </script> <?php
                }
            ?>
        </div>
        <div class="info">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d819257.418681153!2d2.3120521960949625!3d36.66910790419517!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x128e5320fbaba479%3A0xb98fd2a68cc6734b!2sEts%20Ben%20Amar%20Pieces%20Japonaise!5e0!3m2!1sfr!2sdz!4v1733172161383!5m2!1sfr!2sdz"
                width="100%" 
                height="100%" 
                style="border:0;"
                allowfullscreen="" 
                loading="lazy" 
                referrerpolicy="no-referrer-when-downgrade">
            </iframe>
        </div>
    </div>
    <!--=========================================================
                        footer
    ========================================================-->
    <?php include('partie/footer.php') ?>
    <script>
        document.addEventListener( 'DOMContentLoaded', function() {
            var splide = new Splide( '.splide1', {
                perPage: 3,
                focus  : 0,
                omitEnd: true,
                breakpoints: {
                    1000: {
                        perPage: 2, // 2 slides visibles sur les petits écrans
                    },
                    600: {
                        perPage: 1, // 1 slide visible sur les très petits écrans
                    },
                },
            } );
            splide.mount();
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var splide = new Splide('.splide2', {
                perPage: 6, // Par défaut, 6 éléments par page
                focus  : 0,
                omitEnd: true,
                breakpoints: { // Gestion responsive
                    900: { // Pour écrans <= 1200px
                        perPage: 4,
                    },
                    782: { // Pour écrans <= 992px
                        perPage: 3,
                    },
                    558: { // Pour écrans <= 768px
                        perPage: 2,
                    },
                },
            });
            splide.mount(); // Monter le Splide après configuration
        });
    </script>

</body>
</html>