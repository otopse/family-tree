// Mobile menu toggle
document.addEventListener('DOMContentLoaded', function() {
  const navToggle = document.querySelector('.nav-toggle');
  const navMenu = document.querySelector('.nav-menu');
  const navAuth = document.querySelector('.nav-auth');

  if (navToggle) {
    navToggle.addEventListener('click', function() {
      navMenu.classList.toggle('active');
      navAuth.classList.toggle('active');
      navToggle.classList.toggle('active');
    });
  }

  // Smooth scrolling for anchor links
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
      const href = this.getAttribute('href');
      if (href !== '#' && href !== '#login' && href !== '#signup') {
        e.preventDefault();
        const target = document.querySelector(href);
        if (target) {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }
      }
    });
  });

  // Close alert messages
  document.querySelectorAll('.alert-close').forEach(button => {
    button.addEventListener('click', function() {
      const alert = this.closest('.alert');
      if (alert) {
        alert.remove();
      }
    });
  });

  // User dropdown menu toggle
  const userToggle = document.querySelector('.nav-user-toggle');
  const userMenu = document.querySelector('.nav-user-menu');
  if (userToggle && userMenu) {
    userToggle.addEventListener('click', function(e) {
      e.stopPropagation();
      userMenu.classList.toggle('active');
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!userToggle.contains(e.target) && !userMenu.contains(e.target)) {
        userMenu.classList.remove('active');
      }
    });
  }

  // Family trees modal
  const familyTreesLink = document.querySelector('a[href="/family-trees.php"]');
  if (familyTreesLink) {
    familyTreesLink.addEventListener('click', function(e) {
      e.preventDefault();
      openFamilyTreesModal();
    });
  }
});

function openFamilyTreesModal() {
  // Remove existing modal if any
  const existing = document.getElementById('family-trees-modal');
  if (existing) {
    existing.remove();
  }

  // Create overlay
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'family-trees-modal';
  overlay.innerHTML = '<div class="modal-content" style="padding: 40px; text-align: center;"><p>Načítavam...</p></div>';
  document.body.appendChild(overlay);
  
  // Load content via AJAX
  fetch('/family-trees.php?modal=1')
    .then(response => response.text())
    .then(html => {
      overlay.innerHTML = html;
      // Re-attach event listeners
      const closeBtn = overlay.querySelector('.modal-close');
      if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
          e.preventDefault();
          closeFamilyTreesModal();
        });
      }
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
          closeFamilyTreesModal();
        }
      });
      // Re-attach alert close buttons
      overlay.querySelectorAll('.alert-close').forEach(button => {
        button.addEventListener('click', function() {
          const alert = this.closest('.alert');
          if (alert) {
            alert.remove();
          }
        });
      });
      // Show modal
      setTimeout(() => overlay.classList.add('active'), 10);
    })
    .catch(error => {
      console.error('Error loading family trees:', error);
      overlay.innerHTML = '<div class="modal-content" style="padding: 40px;"><p>Chyba pri načítaní.</p></div>';
    });
}

function closeFamilyTreesModal() {
  const modal = document.getElementById('family-trees-modal');
  if (modal) {
    modal.classList.remove('active');
    setTimeout(() => modal.remove(), 300);
  }
}
