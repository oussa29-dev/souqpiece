<?php
    require_once('dashboard/database.php');
    $sqlStates = $pdo->prepare('SELECT * FROM setting WHERE id=?');
    $sqlStates->execute([1]);
    $setting = $sqlStates->fetch(PDO::FETCH_ASSOC);
?>

<footer class="modern-footer">
    <div class="footer-container">
        <div class="footer-grid">
            <!-- Colonne Navigation -->
            <div class="footer-column">
                <h3 class="footer-title">Navigation</h3>
                <ul class="footer-links">
                    <li><a href="index.php" class="footer-link"><i class="fas fa-home footer-icon"></i> Accueil</a></li>
                    <li><a href="index.php#section-categories" class="footer-link"><i class="fas fa-list-alt footer-icon"></i> Catégories</a></li>
                    <li><a href="index.php#brand" class="footer-link"><i class="fas fa-car footer-icon"></i> Voitures</a></li>
                    <li><a href="index.php#section-produits" class="footer-link"><i class="fas fa-box-open footer-icon"></i> Produits</a></li>
                </ul>
            </div>

            <!-- Colonne Aide -->
            <div class="footer-column">
                <h3 class="footer-title">Aide & Services</h3>
                <ul class="footer-links">
                    <li><a href="#" class="footer-link"><i class="fas fa-info-circle footer-icon"></i> À propos</a></li>
                    <li><a href="#" class="footer-link"><i class="fas fa-exchange-alt footer-icon"></i> Retour</a></li>
                    <li><a href="#" class="footer-link"><i class="fas fa-truck footer-icon"></i> Livraison</a></li>
                    <li><a href="#" class="footer-link"><i class="fas fa-shield-alt footer-icon"></i> Garantie</a></li>
                </ul>
            </div>

            <!-- Colonne Contact -->
            <div class="footer-column">
                <h3 class="footer-title">Contactez-nous</h3>
                <ul class="footer-contact">
                    <li class="contact-item">
                        <i class="fas fa-phone-alt contact-icon"></i>
                        <a href="tel:0<?=$setting['tel']?>" class="footer-link">0<?=$setting['tel']?></a>
                    </li>
                    <li class="contact-item">
                        <i class="fas fa-envelope contact-icon"></i>
                        <span><?=$setting['adresse']?></span>
                    </li>
                </ul>
            </div>

            <!-- Colonne Réseaux sociaux -->
            <div class="footer-column">
                <h3 class="footer-title">Suivez-nous</h3>
                <div class="social-links">
                    <a href="<?=$setting['facebook']?>" class="social-link" target="_blank" aria-label="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="<?=$setting['insta']?>" class="social-link" target="_blank" aria-label="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <a href="<?=$setting['tiktok']?>" class="social-link" target="_blank" aria-label="TikTok">
                        <i class="fab fa-tiktok"></i>
                    </a>
                    <?php if(!empty($setting['youtube'])): ?>
                    <a href="<?=$setting['youtube']?>" class="social-link" target="_blank" aria-label="YouTube">
                        <i class="fab fa-youtube"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="footer-logo">
                <a href="index.php" class="logo-link"><?=$setting['nom']?></a>
            </div>
            <div class="copyright">
                &copy; <?=date('Y')?> <?=$setting['nom']?>. Tous droits réservés.
                <span class="developer-credit">
                    Développé par <a href="https://www.instagram.com/w3mohamed/" target="_blank">W3mohamed</a>
                </span>
            </div>
        </div>
    </div>
</footer>
<?php include(__DIR__ . '/../ai/widget.php'); ?>
