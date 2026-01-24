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
});
