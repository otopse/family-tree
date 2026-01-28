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
  document.body.addEventListener('click', function(e) {
    if (e.target.classList.contains('alert-close')) {
      const alert = e.target.closest('.alert');
      if (alert) {
        alert.remove();
      }
    }
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

  // Public trees modal
  const publicTreesLink = document.querySelector('a[href="/public-trees.php"]');
  if (publicTreesLink) {
    publicTreesLink.addEventListener('click', function(e) {
      e.preventDefault();
      openPublicTreesModal();
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
      initFamilyTreesModal(overlay);
      
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

function initFamilyTreesModal(overlay) {
  // Attach close button
  const closeBtn = overlay.querySelector('.modal-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', function(e) {
      e.preventDefault();
      closeFamilyTreesModal();
    });
  }
  
  // Close on overlay click
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) {
      closeFamilyTreesModal();
    }
  });

  // Attach Create Form Handler
  const createForm = overlay.querySelector('#create-tree-form');
  if (createForm) {
    createForm.addEventListener('submit', function(e) {
      e.preventDefault();
      handleTreeFormSubmit(createForm, true);
    });
  }

  // Attach Import Form Handler
  const importForm = overlay.querySelector('#import-gedcom-form');
  if (importForm) {
    importForm.addEventListener('submit', function(e) {
      e.preventDefault();
      handleTreeFormSubmit(importForm, true);
    });
  }

  // Attach Actions (Edit/Rename, Delete) handlers via delegation
  const treesContainer = overlay.querySelector('#trees-container');
  if (treesContainer) {
    treesContainer.addEventListener('click', function(e) {
      const target = e.target;
      
      // Delete
      if (target.closest('.delete-tree')) {
        const btn = target.closest('.delete-tree');
        const id = btn.getAttribute('data-id');
        if (confirm('Naozaj chcete zmazať tento rodokmeň? Táto akcia je nevratná.')) {
          deleteTree(id, true);
        }
      }
      
      // Start Rename
      if (target.closest('.edit-tree')) {
        const btn = target.closest('.edit-tree');
        const row = btn.closest('tr');
        row.querySelector('.tree-name-text').style.display = 'none';
        row.querySelector('.rename-form').style.display = 'flex';
        btn.style.display = 'none';
      }
      
      // Save Rename
      if (target.closest('.save-rename')) {
        const btn = target.closest('.save-rename');
        const form = btn.closest('.rename-form');
        handleRenameSubmit(form, true);
      }
      
      // Cancel Rename
      if (target.closest('.cancel-rename')) {
        const btn = target.closest('.cancel-rename');
        const row = btn.closest('tr');
        row.querySelector('.tree-name-text').style.display = '';
        row.querySelector('.rename-form').style.display = 'none';
        row.querySelector('.edit-tree').style.display = '';
      }
    });

    // Public checkbox toggle (event delegation)
    treesContainer.addEventListener('change', function(e) {
      const target = e.target;
      if (!target || !target.classList || !target.classList.contains('public-toggle')) return;

      const treeId = target.getAttribute('data-id');
      const isPublic = target.checked ? 1 : 0;
      const token = overlay.querySelector('input[name="csrf_token"]')?.value
        || document.querySelector('input[name="csrf_token"]')?.value
        || '';

      const formData = new FormData();
      formData.append('action', 'set_public');
      formData.append('id', treeId);
      formData.append('public', String(isPublic));
      if (token) formData.append('csrf_token', token);

      fetch('/family-trees.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          target.checked = !target.checked;
          showFlash(data.message, 'error', true);
        }
      })
      .catch(() => {
        target.checked = !target.checked;
        showFlash('Chyba komunikácie so serverom.', 'error', true);
      });
    });
  }
}

function openPublicTreesModal() {
  const existing = document.getElementById('public-trees-modal');
  if (existing) {
    existing.remove();
  }

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'public-trees-modal';
  overlay.innerHTML = '<div class="modal-content" style="padding: 40px; text-align: center;"><p>Načítavam...</p></div>';
  document.body.appendChild(overlay);

  fetch('/public-trees.php?modal=1')
    .then(r => r.text())
    .then(html => {
      overlay.innerHTML = html;
      initPublicTreesModal(overlay);
      setTimeout(() => overlay.classList.add('active'), 10);
    })
    .catch(error => {
      console.error('Error loading public trees:', error);
      overlay.innerHTML = '<div class="modal-content" style="padding: 40px;"><p>Chyba pri načítaní.</p></div>';
    });
}

function closePublicTreesModal() {
  const modal = document.getElementById('public-trees-modal');
  if (modal) {
    modal.classList.remove('active');
    setTimeout(() => modal.remove(), 300);
  }
}

function initPublicTreesModal(overlay) {
  const closeBtn = overlay.querySelector('.modal-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', function(e) {
      e.preventDefault();
      closePublicTreesModal();
    });
  }

  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) {
      closePublicTreesModal();
    }
  });
}

function refreshTreeList(isModal) {
  const containerId = isModal ? '#trees-container' : '#trees-container'; // ID is same, context differs
  const container = isModal 
    ? document.querySelector('#family-trees-modal #trees-container')
    : document.querySelector('#trees-container');

  if (!container) return;

  fetch('/family-trees.php?list_only=1')
    .then(r => r.text())
    .then(html => {
      container.innerHTML = html;
    })
    .catch(console.error);
}

function showFlash(message, type = 'success', isModal = false) {
  const alertHtml = `
    <div class="alert alert-${type}">
      <button type="button" class="alert-close">x</button>
      ${message}
    </div>
  `;
  
  const container = isModal 
    ? document.querySelector('#family-trees-modal #modal-alerts') 
    : document.querySelector('#page-alerts');

  if (container) {
    container.innerHTML = alertHtml;
  }
}

function handleTreeFormSubmit(form, isModal) {
  const submitBtn = form.querySelector('button[type="submit"]');
  const originalText = submitBtn.textContent;
  submitBtn.disabled = true;
  submitBtn.textContent = 'Pracujem...';

  const formData = new FormData(form);

  fetch('/family-trees.php', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showFlash(data.message, 'success', isModal);
      form.reset();
      refreshTreeList(isModal);
    } else {
      showFlash(data.message, 'error', isModal);
    }
  })
  .catch(err => {
    console.error(err);
    showFlash('Chyba komunikácie so serverom.', 'error', isModal);
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
  });
}

function deleteTree(id, isModal) {
  const formData = new FormData();
  formData.append('action', 'delete');
  formData.append('id', id);
  // Need CSRF token - try to find one in the page
  const token = document.querySelector('input[name="csrf_token"]')?.value;
  if (token) formData.append('csrf_token', token);

  fetch('/family-trees.php', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showFlash(data.message, 'success', isModal);
      refreshTreeList(isModal);
    } else {
      showFlash(data.message, 'error', isModal);
    }
  })
  .catch(console.error);
}

function handleRenameSubmit(form, isModal) {
  const formData = new FormData(form);
  // CSRF is already in the form hidden input (copied from main form or handled globally if added)
  // Actually, my renderTreeList didn't add CSRF to rename forms.
  // Let's grab it globally.
  const token = document.querySelector('input[name="csrf_token"]')?.value;
  if (token && !formData.has('csrf_token')) {
    formData.append('csrf_token', token);
  }

  fetch('/family-trees.php', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showFlash(data.message, 'success', isModal);
      refreshTreeList(isModal);
    } else {
      showFlash(data.message, 'error', isModal);
    }
  })
  .catch(console.error);
}
