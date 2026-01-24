<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = require_login();

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Neplatný bezpečnostný token. Skúste to prosím znova.';
  }

  $treeName = trim((string) ($_POST['tree_name'] ?? ''));

  if ($treeName === '') {
    $errors[] = 'Zadajte názov rodokmeňa.';
  } elseif (strlen($treeName) > 255) {
    $errors[] = 'Názov rodokmeňa môže mať maximálne 255 znakov.';
  }

  if (!$errors) {
    try {
      $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
      $stmt = db()->prepare(
        'INSERT INTO family_trees (owner, tree_name, tree_nodes, created, modified, enabled)
         VALUES (:owner, :tree_name, :tree_nodes, :created, :modified, :enabled)'
      );
      $stmt->execute([
        'owner' => $user['id'],
        'tree_name' => $treeName,
        'tree_nodes' => null,
        'created' => $now,
        'modified' => $now,
        'enabled' => 1,
      ]);

      $success = true;
      flash('success', 'Rodokmeň bol úspešne vytvorený.');
      
      // For modal requests, don't redirect - let AJAX handle it
      if (!empty($_GET['modal']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        // This will be handled by AJAX, just continue to render
      } else {
        // Redirect to avoid resubmission for regular page requests
        redirect('/family-trees.php');
      }
    } catch (PDOException $e) {
      error_log('Family trees insert error: ' . $e->getMessage());
      if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), 'Table') !== false) {
        $errors[] = 'Tabuľka rodokmeňov ešte neexistuje. Spustite SQL migráciu z schema.sql.';
      } else {
        $errors[] = 'Chyba pri vytváraní rodokmeňa: ' . $e->getMessage();
      }
    }
  }
}

// Fetch user's family trees
try {
  $stmt = db()->prepare(
    'SELECT id, tree_name, created, modified, enabled
     FROM family_trees
     WHERE owner = :owner
     ORDER BY modified DESC, created DESC'
  );
  $stmt->execute(['owner' => $user['id']]);
  $trees = $stmt->fetchAll();
} catch (PDOException $e) {
  // Table might not exist yet
  error_log('Family trees query error: ' . $e->getMessage());
  $trees = [];
  if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), 'Table') !== false) {
    flash('error', 'Tabuľka rodokmeňov ešte neexistuje. Spustite SQL migráciu z schema.sql.');
  }
}

// Check if this is a modal request
$isModal = !empty($_GET['modal']);

if ($isModal) {
  // Return only modal content
  header('Content-Type: text/html; charset=utf-8');
  ?>
  <div class="modal-overlay active" id="family-trees-modal">
    <div class="modal-content">
      <a href="#" class="modal-close" aria-label="Zavrieť" onclick="closeFamilyTreesModal(); return false;">×</a>
      <div style="padding: 40px;">
        <h1 class="section-title" style="margin-bottom: 24px;">Moje rodokmene</h1>
        <?php
        $messages = consume_flash();
        foreach ($messages as $message) {
          $type = $message['type'] ?? 'info';
          $text = $message['message'] ?? '';
          echo '<div class="alert alert-' . e($type) . '">';
          echo '<button type="button" class="alert-close" aria-label="Zavrieť">x</button>';
          echo e($text);
          echo '</div>';
        }
        ?>
        <?php if ($errors): ?>
          <div class="alert alert-error">
            <button type="button" class="alert-close" aria-label="Zavrieť">x</button>
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (empty($trees)): ?>
          <p style="color: var(--text-secondary); margin-bottom: 24px;">Zatiaľ nemáte žiadne rodokmene.</p>
        <?php else: ?>
          <div class="trees-list" style="margin-bottom: 32px;">
            <table style="width: 100%; border-collapse: collapse;">
              <thead>
                <tr style="border-bottom: 2px solid var(--border-color);">
                  <th style="text-align: left; padding: 12px; font-weight: 600;">Názov</th>
                  <th style="text-align: left; padding: 12px; font-weight: 600;">Vytvorený</th>
                  <th style="text-align: left; padding: 12px; font-weight: 600;">Upravený</th>
                  <th style="text-align: center; padding: 12px; font-weight: 600;">Stav</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($trees as $tree): ?>
                  <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 12px;"><?= e($tree['tree_name']) ?></td>
                    <td style="padding: 12px; color: var(--text-secondary); font-size: 0.9rem;">
                      <?= e(date('d.m.Y H:i', strtotime($tree['created']))) ?>
                    </td>
                    <td style="padding: 12px; color: var(--text-secondary); font-size: 0.9rem;">
                      <?= e(date('d.m.Y H:i', strtotime($tree['modified']))) ?>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                      <?php if ($tree['enabled']): ?>
                        <span style="color: #166534;">Aktívny</span>
                      <?php else: ?>
                        <span style="color: var(--text-secondary);">Neaktívny</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 16px; margin-top: 32px;">Vytvoriť nový rodokmeň</h2>
        <form method="post" action="/family-trees.php?modal=1" id="create-tree-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="form-group">
            <label for="tree_name">Názov rodokmeňa</label>
            <input class="form-control" type="text" id="tree_name" name="tree_name" maxlength="255" required>
          </div>
          <button class="btn-primary btn-large" type="submit">Vytvoriť rodokmeň</button>
        </form>
      </div>
    </div>
  </div>
  <script>
    function closeFamilyTreesModal() {
      const modal = document.getElementById('family-trees-modal');
      if (modal) {
        modal.classList.remove('active');
        setTimeout(() => modal.remove(), 300);
      }
    }
    // Close on overlay click
    document.getElementById('family-trees-modal')?.addEventListener('click', function(e) {
      if (e.target === this) {
        closeFamilyTreesModal();
      }
    });
    
    // Handle form submission via AJAX
    const form = document.getElementById('create-tree-form');
    if (form) {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        const originalText = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = 'Vytváram...';
        
        fetch('/family-trees.php?modal=1', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(response => response.text())
        .then(html => {
          // Reload modal content
          const modal = document.getElementById('family-trees-modal');
          if (modal) {
            modal.innerHTML = html;
            // Re-attach event listeners
            attachModalListeners();
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('Chyba pri vytváraní rodokmeňa. Skúste to znova.');
          submitButton.disabled = false;
          submitButton.textContent = originalText;
        });
      });
    }
    
    function attachModalListeners() {
      // Re-attach close button
      const closeBtn = document.querySelector('#family-trees-modal .modal-close');
      if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
          e.preventDefault();
          closeFamilyTreesModal();
        });
      }
      
      // Re-attach form submit handler
      const form = document.getElementById('create-tree-form');
      if (form) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          
          const formData = new FormData(form);
          const submitButton = form.querySelector('button[type="submit"]');
          const originalText = submitButton.textContent;
          submitButton.disabled = true;
          submitButton.textContent = 'Vytváram...';
          
          fetch('/family-trees.php?modal=1', {
            method: 'POST',
            body: formData,
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          .then(response => response.text())
          .then(html => {
            const modal = document.getElementById('family-trees-modal');
            if (modal) {
              modal.innerHTML = html;
              attachModalListeners();
            }
          })
          .catch(error => {
            console.error('Error:', error);
            alert('Chyba pri vytváraní rodokmeňa. Skúste to znova.');
            submitButton.disabled = false;
            submitButton.textContent = originalText;
          });
        });
      }
      
      // Re-attach alert close buttons
      document.querySelectorAll('#family-trees-modal .alert-close').forEach(button => {
        button.addEventListener('click', function() {
          const alert = this.closest('.alert');
          if (alert) {
            alert.remove();
          }
        });
      });
    }
  </script>
  <?php
  exit;
}

// Regular page view (fallback)
require_once __DIR__ . '/_layout.php';
render_header('Moje rodokmene');
?>
  <div class="container">
    <div class="auth-card">
      <a href="/" class="auth-close" aria-label="Zavrieť">x</a>
      <h1 class="section-title">Moje rodokmene</h1>
      <?php render_flash(); ?>
      <?php if ($errors): ?>
        <div class="alert alert-error">
          <button type="button" class="alert-close" aria-label="Zavrieť">x</button>
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (empty($trees)): ?>
        <p style="color: var(--text-secondary); margin-bottom: 24px;">Zatiaľ nemáte žiadne rodokmene.</p>
      <?php else: ?>
        <div class="trees-list" style="margin-bottom: 32px;">
          <table style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="border-bottom: 2px solid var(--border-color);">
                <th style="text-align: left; padding: 12px; font-weight: 600;">Názov</th>
                <th style="text-align: left; padding: 12px; font-weight: 600;">Vytvorený</th>
                <th style="text-align: left; padding: 12px; font-weight: 600;">Upravený</th>
                <th style="text-align: center; padding: 12px; font-weight: 600;">Stav</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($trees as $tree): ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                  <td style="padding: 12px;"><?= e($tree['tree_name']) ?></td>
                  <td style="padding: 12px; color: var(--text-secondary); font-size: 0.9rem;">
                    <?= e(date('d.m.Y H:i', strtotime($tree['created']))) ?>
                  </td>
                  <td style="padding: 12px; color: var(--text-secondary); font-size: 0.9rem;">
                    <?= e(date('d.m.Y H:i', strtotime($tree['modified']))) ?>
                  </td>
                  <td style="padding: 12px; text-align: center;">
                    <?php if ($tree['enabled']): ?>
                      <span style="color: #166534;">Aktívny</span>
                    <?php else: ?>
                      <span style="color: var(--text-secondary);">Neaktívny</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 16px; margin-top: 32px;">Vytvoriť nový rodokmeň</h2>
      <form method="post" action="/family-trees.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label for="tree_name">Názov rodokmeňa</label>
          <input class="form-control" type="text" id="tree_name" name="tree_name" maxlength="255" required>
        </div>
        <button class="btn-primary btn-large" type="submit">Vytvoriť rodokmeň</button>
      </form>
    </div>
  </div>
<?php
render_footer();
