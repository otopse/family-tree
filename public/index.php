<?php
declare(strict_types=1);
?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Family Tree</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <div class="nav-brand">
        <a href="/">Family Tree</a>
      </div>
      <ul class="nav-menu">
        <li><a href="#home">Home</a></li>
        <li><a href="#features">Features</a></li>
        <li><a href="#pricing">Pricing</a></li>
        <li><a href="#contact">Contact</a></li>
      </ul>
      <div class="nav-auth">
        <a href="#login" class="btn-link">Login</a>
        <a href="#signup" class="btn-primary">Sign Up</a>
      </div>
      <button class="nav-toggle" aria-label="Toggle menu">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
  </nav>

  <main>
    <section class="hero">
      <div class="container">
        <h1 class="hero-title">Vytvorte si svoj rodokmeň</h1>
        <p class="hero-subtitle">Objavte históriu svojej rodiny a zachovajte ju pre budúce generácie</p>
        <div class="hero-cta">
          <a href="#signup" class="btn-primary btn-large">Začať zdarma</a>
          <a href="#features" class="btn-secondary btn-large">Zistiť viac</a>
        </div>
      </div>
    </section>

    <section id="features" class="features">
      <div class="container">
        <h2 class="section-title">Funkcie</h2>
        <div class="features-grid">
          <div class="feature-card">
            <div class="feature-icon">🌳</div>
            <h3>Vizuálny rodokmeň</h3>
            <p>Interaktívne zobrazenie vašej rodiny v prehľadnom stromovom formáte</p>
          </div>
          <div class="feature-card">
            <div class="feature-icon">📸</div>
            <h3>Fotografie a dokumenty</h3>
            <p>Pridajte fotografie a dôležité dokumenty ku každému členovi rodiny</p>
          </div>
          <div class="feature-card">
            <div class="feature-icon">🔒</div>
            <h3>Súkromie a bezpečnosť</h3>
            <p>Vaše údaje sú v bezpečí a môžete si nastaviť úroveň súkromia</p>
          </div>
          <div class="feature-card">
            <div class="feature-icon">📱</div>
            <h3>Responzívny dizajn</h3>
            <p>Prístup k rodokmeňu z akéhokoľvek zariadenia - počítač, tablet alebo mobil</p>
          </div>
        </div>
      </div>
    </section>

    <section id="pricing" class="pricing">
      <div class="container">
        <h2 class="section-title">Cenník</h2>
        <div class="pricing-grid">
          <div class="pricing-card">
            <h3>Základný</h3>
            <div class="price">Zdarma</div>
            <ul class="pricing-features">
              <li>Až 50 členov rodiny</li>
              <li>Základné zobrazenie rodokmeňa</li>
              <li>5 GB úložného priestoru</li>
            </ul>
            <a href="#signup" class="btn-secondary">Začať</a>
          </div>
          <div class="pricing-card featured">
            <div class="badge">Odporúčané</div>
            <h3>Premium</h3>
            <div class="price">9,99 €<span>/mesiac</span></div>
            <ul class="pricing-features">
              <li>Neobmedzený počet členov</li>
              <li>Pokročilé funkcie</li>
              <li>50 GB úložného priestoru</li>
              <li>Prioritná podpora</li>
            </ul>
            <a href="#signup" class="btn-primary">Vybrať</a>
          </div>
        </div>
      </div>
    </section>

    <section id="contact" class="contact">
      <div class="container">
        <h2 class="section-title">Kontakt</h2>
        <p class="contact-text">Máte otázky? Radi vám pomôžeme!</p>
        <a href="mailto:info@family-tree.cz" class="btn-primary">Kontaktovať nás</a>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container">
      <p>&copy; 2026 Family Tree. Všetky práva vyhradené.</p>
    </div>
  </footer>

  <script src="/assets/app.js"></script>
</body>
</html>
