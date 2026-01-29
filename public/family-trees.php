<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = require_login();

// Helper to return JSON response
function jsonResponse(bool $success, string $message, array $data = []): void {
  header('Content-Type: application/json');
  echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
  exit;
}

// Handle POST requests (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    jsonResponse(false, 'Neplatný bezpečnostný token. Skúste to prosím znova.');
  }

  $action = $_POST['action'] ?? 'create';

  try {
    if ($action === 'create') {
      $treeName = trim((string) ($_POST['tree_name'] ?? ''));

      if ($treeName === '') {
        jsonResponse(false, 'Zadajte názov rodokmeňa.');
      } elseif (strlen($treeName) > 255) {
        jsonResponse(false, 'Názov rodokmeňa môže mať maximálne 255 znakov.');
      }

      $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
      $stmt = db()->prepare(
        'INSERT INTO family_trees (owner, tree_name, tree_nodes, created, modified, enabled, public)
         VALUES (:owner, :tree_name, :tree_nodes, :created, :modified, :enabled, :public)'
      );
      $stmt->execute([
        'owner' => $user['id'],
        'tree_name' => $treeName,
        'tree_nodes' => null,
        'created' => $now,
        'modified' => $now,
        'enabled' => 1,
        'public' => 0,
      ]);

      jsonResponse(true, 'Rodokmeň bol úspešne vytvorený.');
    } 
    elseif ($action === 'import_gedcom') {
      if (empty($_FILES['gedcom_file']) || $_FILES['gedcom_file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, 'Chyba pri nahrávaní súboru.');
      }

      $file = $_FILES['gedcom_file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

      if ($ext !== 'ged') {
        jsonResponse(false, 'Prosím nahrajte súbor s príponou .ged');
      }

      // Read file content
      $content = file_get_contents($file['tmp_name']);
      if ($content === false) {
        jsonResponse(false, 'Nepodarilo sa prečítať súbor.');
      }

      // Simple GEDCOM parsing to find HEAD...SOUR...NAME or just use filename
      // For MVP, we use filename as tree name and save raw content to tree_nodes (or a new column)
      // Since we don't have a parser yet, we'll just create the tree with the filename.
      
      $treeName = pathinfo($file['name'], PATHINFO_FILENAME);
      
      // Parse GEDCOM and populate ft_records/ft_elements
      require_once __DIR__ . '/_gedcom_parser.php';

      $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
      $stmt = db()->prepare(
        'INSERT INTO family_trees (owner, tree_name, tree_nodes, created, modified, enabled, public)
         VALUES (:owner, :tree_name, :tree_nodes, :created, :modified, :enabled, :public)'
      );
      
      $stmt->execute([
        'owner' => $user['id'],
        'tree_name' => $treeName,
        'tree_nodes' => null, // Placeholder
        'created' => $now,
        'modified' => $now,
        'enabled' => 1,
        'public' => 0,
      ]);
      
      $treeId = (int)db()->lastInsertId();
      
      try {
        parse_and_import_gedcom($file['tmp_name'], $treeId, (int)$user['id']);
        
        // After successful import, run init + calculate automatically
        // Run init (clear imputed dates)
        $initStmt = db()->prepare("UPDATE ft_elements e JOIN ft_records r ON e.record_id = r.id SET e.birth_date = NULL WHERE r.tree_id = :tree_id AND e.birth_date LIKE '[[%]]'");
        $initStmt->execute(['tree_id' => $treeId]);
        $initStmt = db()->prepare("UPDATE ft_elements e JOIN ft_records r ON e.record_id = r.id SET e.death_date = NULL WHERE r.tree_id = :tree_id AND e.death_date LIKE '[[%]]'");
        $initStmt->execute(['tree_id' => $treeId]);
        
        // Run calculate (impute dates) - call tree-actions.php logic
        // We'll trigger it via HTTP request to tree-actions.php
        // But for now, return success and let client trigger it
        jsonResponse(true, 'GEDCOM súbor bol úspešne importovaný.', ['tree_id' => $treeId, 'run_init_calc' => true]);
      } catch (Exception $e) {
        // If parsing fails, we might want to delete the tree or just warn
        error_log('GEDCOM Import Error: ' . $e->getMessage());
        jsonResponse(false, 'Chyba pri spracovaní GEDCOM súboru: ' . $e->getMessage());
      }
    }
    elseif ($action === 'delete') {
      $id = (int) ($_POST['id'] ?? 0);
      $stmt = db()->prepare('DELETE FROM family_trees WHERE id = :id AND owner = :owner');
      $stmt->execute(['id' => $id, 'owner' => $user['id']]);
      
      if ($stmt->rowCount() > 0) {
        jsonResponse(true, 'Rodokmeň bol zmazaný.');
      } else {
        jsonResponse(false, 'Rodokmeň sa nepodarilo zmazať alebo neexistuje.');
      }
    }
    elseif ($action === 'rename') {
      $id = (int) ($_POST['id'] ?? 0);
      $newName = trim((string) ($_POST['tree_name'] ?? ''));

      if ($newName === '') {
        jsonResponse(false, 'Zadajte nový názov.');
      }

      $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
      $stmt = db()->prepare(
        'UPDATE family_trees SET tree_name = :name, modified = :modified WHERE id = :id AND owner = :owner'
      );
      $stmt->execute([
        'name' => $newName, 
        'modified' => $now, 
        'id' => $id, 
        'owner' => $user['id']
      ]);

      if ($stmt->rowCount() > 0) {
        jsonResponse(true, 'Rodokmeň bol premenovaný.');
      } else {
        jsonResponse(false, 'Rodokmeň sa nepodarilo premenovať.');
      }
    }
    elseif ($action === 'set_public') {
      $id = (int) ($_POST['id'] ?? 0);
      $public = (int)($_POST['public'] ?? 0) ? 1 : 0;
      if (!$id) {
        jsonResponse(false, 'Chýba ID rodokmeňa.');
      }
      $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
      $stmt = db()->prepare(
        'UPDATE family_trees SET public = :public, modified = :modified WHERE id = :id AND owner = :owner'
      );
      $stmt->execute([
        'public' => $public,
        'modified' => $now,
        'id' => $id,
        'owner' => $user['id'],
      ]);
      if ($stmt->rowCount() > 0) {
        jsonResponse(true, $public ? 'Rodokmeň je verejný.' : 'Rodokmeň je súkromný.');
      }
      // No row changed (same value) still OK
      jsonResponse(true, 'Nastavenie bolo uložené.');
    }
    else {
      jsonResponse(false, 'Neznáma akcia.');
    }
  } catch (PDOException $e) {
    error_log('Family tree action error: ' . $e->getMessage());
    jsonResponse(false, 'Chyba databázy: ' . $e->getMessage());
  }
}

// Fetch user's family trees for GET request
$trees = [];
$dbError = null;

try {
  $stmt = db()->prepare(
    'SELECT id, tree_name, created, modified, enabled, public
     FROM family_trees
     WHERE owner = :owner
     ORDER BY modified DESC, created DESC'
  );
  $stmt->execute(['owner' => $user['id']]);
  $trees = $stmt->fetchAll();
} catch (PDOException $e) {
  $dbError = $e->getMessage();
}

// Function to render the tree list HTML (reused for initial load and AJAX updates)
function renderTreeList(array $trees): void {
  if (empty($trees)) {
    echo '<p class="empty-state">Zatiaľ nemáte žiadne rodokmene.</p>';
    return;
  }
  ?>
  <div class="trees-list">
    <table>
      <thead>
        <tr>
          <th>Názov</th>
          <th>Public</th>
          <th>Vytvorený</th>
          <th>Upravený</th>
          <th class="actions-col">Akcie</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($trees as $tree): ?>
          <tr>
            <td>
              <span class="tree-name-text"><?= e($tree['tree_name']) ?></span>
              <form class="rename-form" style="display:none;" onsubmit="return false;">
                <input type="hidden" name="id" value="<?= $tree['id'] ?>">
                <input type="hidden" name="action" value="rename">
                <input type="text" name="tree_name" value="<?= e($tree['tree_name']) ?>" class="form-control form-control-sm">
                <button type="button" class="btn-icon save-rename" title="Uložiť">💾</button>
                <button type="button" class="btn-icon cancel-rename" title="Zrušiť">❌</button>
              </form>
            </td>
            <td class="meta-col" style="text-align:center;">
              <input
                type="checkbox"
                class="public-toggle"
                data-id="<?= (int)$tree['id'] ?>"
                <?= !empty($tree['public']) ? 'checked' : '' ?>
                aria-label="Public"
                title="Verejný rodokmeň"
              >
            </td>
            <td class="meta-col"><?= e(date('d.m.Y H:i', strtotime($tree['created']))) ?></td>
            <td class="meta-col"><?= e(date('d.m.Y H:i', strtotime($tree['modified']))) ?></td>
            <td class="actions-col">
              <a href="/edit-tree.php?id=<?= $tree['id'] ?>" class="btn-icon" title="Editovať rodokmeň">👥</a>
              <button type="button" class="btn-icon edit-tree" data-id="<?= $tree['id'] ?>" title="Premenovať">✏️</button>
              <button type="button" class="btn-icon delete-tree" data-id="<?= $tree['id'] ?>" title="Zmazať" onclick="deleteTree(<?= $tree['id'] ?>)">🗑️</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
}

// Check if this is a modal request or just asking for the list HTML
$isModal = !empty($_GET['modal']);
$listOnly = !empty($_GET['list_only']);

if ($listOnly) {
  renderTreeList($trees);
  exit;
}

if ($isModal) {
  header('Content-Type: text/html; charset=utf-8');
  ?>
  <div class="modal-overlay active" id="family-trees-modal">
    <div class="modal-content">
      <a href="#" class="modal-close" aria-label="Zavrieť" onclick="closeFamilyTreesModal(); return false;">×</a>
      <div style="padding: 40px;">
        <h1 class="section-title">Moje rodokmene</h1>
        
        <div id="modal-alerts"></div>

        <?php if ($dbError): ?>
          <div class="alert alert-error">
            <button type="button" class="alert-close">x</button>
            Chyba databázy: <?= e($dbError) ?>
          </div>
        <?php endif; ?>

        <div id="trees-container">
          <?php renderTreeList($trees); ?>
        </div>

        <h2 class="section-subtitle">Vytvoriť nový rodokmeň</h2>
        
        <div class="create-options">
          <!-- Manual Creation -->
          <div class="create-option">
            <h3>Manuálne vytvorenie</h3>
            <form id="create-tree-form" onsubmit="return false;">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="create">
              <div class="form-group">
                <label for="tree_name">Názov rodokmeňa</label>
                <input class="form-control" type="text" id="tree_name" name="tree_name" maxlength="255" required>
              </div>
              <button class="btn-primary btn-large" type="submit">Vytvoriť rodokmeň</button>
            </form>
          </div>

          <div class="divider-vertical">alebo</div>

          <!-- GEDCOM Import -->
          <div class="create-option">
            <h3>Import z GEDCOM</h3>
            <form id="import-gedcom-form" onsubmit="return false;" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="import_gedcom">
              <div class="form-group">
                <label for="gedcom_file">Vyberte .ged súbor</label>
                <input class="form-control" type="file" id="gedcom_file" name="gedcom_file" accept=".ged" required>
              </div>
              <button class="btn-secondary btn-large" type="submit">Importovať GEDCOM</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php
  exit;
}

// Fallback for non-modal access
require_once __DIR__ . '/_layout.php';
render_header('Moje rodokmene');
?>
<div class="container">
  <div class="auth-card">
    <a href="/" class="auth-close" aria-label="Zavrieť">x</a>
    <h1 class="section-title">Moje rodokmene</h1>
    
    <div id="page-alerts">
      <?php render_flash(); ?>
    </div>

    <div id="trees-container">
      <?php renderTreeList($trees); ?>
    </div>

    <h2 class="section-subtitle">Vytvoriť nový rodokmeň</h2>
    
    <div class="create-options">
      <!-- Manual Creation -->
      <div class="create-option">
        <h3>Manuálne vytvorenie</h3>
        <form id="create-tree-form-page" method="post" action="/family-trees.php">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">
          <div class="form-group">
            <label for="tree_name">Názov rodokmeňa</label>
            <input class="form-control" type="text" id="tree_name_page" name="tree_name" maxlength="255" required>
          </div>
          <button class="btn-primary btn-large" type="submit">Vytvoriť rodokmeň</button>
        </form>
      </div>

      <div class="divider-vertical">alebo</div>

      <!-- GEDCOM Import -->
      <div class="create-option">
        <h3>Import z GEDCOM</h3>
        <form id="import-gedcom-form-page" method="post" action="/family-trees.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="import_gedcom">
          <div class="form-group">
            <label for="gedcom_file_page">Vyberte .ged súbor</label>
            <input class="form-control" type="file" id="gedcom_file_page" name="gedcom_file" accept=".ged" required>
          </div>
          <button class="btn-secondary btn-large" type="submit">Importovať GEDCOM</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('change', async (e) => {
  const cb = e.target && e.target.classList && e.target.classList.contains('public-toggle') ? e.target : null;
  if (!cb) return;

  const treeId = cb.getAttribute('data-id');
  const isPublic = cb.checked ? 1 : 0;
  const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';

  try {
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'set_public');
    fd.append('id', treeId);
    fd.append('public', String(isPublic));

    const res = await fetch('/family-trees.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) {
      cb.checked = !cb.checked;
      alert('Chyba: ' + data.message);
    }
  } catch (err) {
    cb.checked = !cb.checked;
    alert('Chyba komunikácie so serverom.');
  }
});
</script>

<?php
render_footer();
