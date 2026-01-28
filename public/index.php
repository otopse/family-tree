<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = current_user();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Family Tree</title>
  <link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <div class="nav-brand">
        <a href="/">Family Tree</a>
      </div>
      <ul class="nav-menu">
        <li><a href="/?section=home" data-section="home">Home</a></li>
        <li><a href="/?section=features" data-section="features">Features</a></li>
        <li><a href="/?section=pricing" data-section="pricing">Pricing</a></li>
        <li><a href="/?section=contact" data-section="contact">Contact</a></li>
        <li class="nav-public">
          <button type="button" class="nav-public-toggle" aria-haspopup="true" aria-expanded="false">Public Trees</button>
          <div class="nav-public-menu" role="menu" aria-label="Public Trees">
            <div class="nav-public-loading">Loading...</div>
          </div>
        </li>
      </ul>
      <div class="nav-auth">
        <?php if ($user): ?>
          <div class="nav-user">
            <button type="button" class="nav-user-toggle">
              <?= e($user['username'] ?? $user['email']) ?>
              <span style="margin-left: 8px; font-size: 0.85em; opacity: 0.8;">Free</span>
            </button>
            <div class="nav-user-menu">
              <a href="/account.php">Môj účet</a>
              <a href="/family-trees.php">Moje rodokmene</a>
              <a href="/logout.php">Odhlásiť sa</a>
            </div>
          </div>
        <?php else: ?>
          <div class="nav-user">
            <a href="/login.php" class="nav-user-toggle">Prihlásenie</a>
            <div class="nav-user-menu">
              <a href="/login.php">Prihlásiť sa</a>
              <a href="/register.php">Registrácia</a>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <button class="nav-toggle" aria-label="Toggle menu">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
  </nav>

  <main>
    <section class="hero" id="home">
      <div class="container">
        <h1 class="hero-title">Create Your Family Tree</h1>
        <p class="hero-subtitle">Discover your family history and preserve it for future generations</p>
        <div class="hero-cta">
          <a href="/register.php" class="btn-primary btn-large">Get Started Free</a>
          <a href="#features" class="btn-secondary btn-large">Learn More</a>
        </div>
      </div>
    </section>

    <section id="features" class="features">
      <div class="container">
        <h2 class="section-title">Features</h2>
        <div class="features-grid">
          <div class="feature-card">
            <div class="feature-icon">🌳</div>
            <h3>Visual Family Tree</h3>
            <p>Interactive display of your family in a clear tree format</p>
          </div>
          <div class="feature-card">
            <div class="feature-icon">📸</div>
            <h3>Photos and Documents</h3>
            <p>Add photos and important documents for each family member</p>
          </div>
          <div class="feature-card">
            <div class="feature-icon">🔒</div>
            <h3>Privacy and Security</h3>
            <p>Your data is secure and you can set your privacy level</p>
          </div>
          <div class="feature-card">
            <div class="feature-icon">📱</div>
            <h3>Responsive Design</h3>
            <p>Access your family tree from any device - computer, tablet or mobile</p>
          </div>
        </div>
      </div>
    </section>

    <section id="pricing" class="pricing">
      <div class="container">
        <h2 class="section-title">Pricing</h2>
        <div class="pricing-grid">
          <div class="pricing-card">
            <h3>Basic</h3>
            <div class="price">Free</div>
            <ul class="pricing-features">
              <li>Up to 50 family members</li>
              <li>Basic family tree view</li>
              <li>5 GB storage space</li>
            </ul>
            <a href="/register.php" class="btn-secondary">Get Started</a>
          </div>
          <div class="pricing-card featured">
            <div class="badge">Recommended</div>
            <h3>Premium</h3>
            <div class="price">€9.99<span>/month</span></div>
            <ul class="pricing-features">
              <li>Unlimited family members</li>
              <li>Advanced features</li>
              <li>50 GB storage space</li>
              <li>Priority support</li>
            </ul>
            <a href="/register.php" class="btn-primary">Choose</a>
          </div>
        </div>
      </div>
    </section>

    <section id="contact" class="contact">
      <div class="container">
        <h2 class="section-title">Contact</h2>
        <p class="contact-text">Have questions? We're happy to help!</p>
        <a href="mailto:info@family-tree.cz" class="btn-primary">Contact Us</a>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container">
      <p>&copy; 2026 Family Tree. All rights reserved.</p>
    </div>
  </footer>

  <script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
