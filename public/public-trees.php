<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = require_login();

// Fetch public family trees
$trees = [];
$dbError = null;

try {
  $stmt = db()->prepare(
    'SELECT id, tree_name, created, modified
     FROM family_trees
     WHERE public = 1 AND enabled = 1
     ORDER BY modified DESC, created DESC'
  );
  $stmt->execute();
  $trees = $stmt->fetchAll();
} catch (PDOException $e) {
  $dbError = $e->getMessage();
}

// JSON list for navbar dropdown
if (!empty($_GET['list_json'])) {
  header('Content-Type: application/json');
  echo json_encode([
    'success' => $dbError === null,
    'message' => $dbError ? ('Chyba databázy: ' . $dbError) : '',
    'trees' => array_map(static function($t) {
      return ['id' => (int)$t['id'], 'name' => (string)$t['tree_name']];
    }, $trees),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

function renderPublicTreeList(array $trees): void {
  if (empty($trees)) {
    echo '<p class="empty-state">Zatiaľ nie sú žiadne verejné rodokmene.</p>';
    return;
  }
  ?>
  <div class="trees-list">
    <table>
      <thead>
        <tr>
          <th>Názov</th>
          <th>Vytvorený</th>
          <th>Upravený</th>
          <th class="actions-col">Akcie</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($trees as $tree): ?>
          <tr>
            <td><span class="tree-name-text"><?= e($tree['tree_name']) ?></span></td>
            <td class="meta-col"><?= e(date('d.m.Y H:i', strtotime($tree['created']))) ?></td>
            <td class="meta-col"><?= e(date('d.m.Y H:i', strtotime($tree['modified']))) ?></td>
            <td class="actions-col">
              <a href="/edit-tree.php?id=<?= (int)$tree['id'] ?>&public_view=1" class="btn-icon" title="Otvoriť (len na čítanie)">👁️</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
}

$isModal = !empty($_GET['modal']);
$listOnly = !empty($_GET['list_only']);

if ($listOnly) {
  renderPublicTreeList($trees);
  exit;
}

if ($isModal) {
  header('Content-Type: text/html; charset=utf-8');
  ?>
  <div class="modal-overlay active" id="public-trees-modal">
    <div class="modal-content">
      <a href="#" class="modal-close" aria-label="Zavrieť" onclick="closePublicTreesModal(); return false;">×</a>
      <div style="padding: 40px;">
        <h1 class="section-title">Verejné rodokmene</h1>

        <?php if ($dbError): ?>
          <div class="alert alert-error">
            <button type="button" class="alert-close">x</button>
            Chyba databázy: <?= e($dbError) ?>
          </div>
        <?php endif; ?>

        <div id="public-trees-container">
          <?php renderPublicTreeList($trees); ?>
        </div>
      </div>
    </div>
  </div>
  <?php
  exit;
}

require_once __DIR__ . '/_layout.php';
render_header('Verejné rodokmene');
?>
<div class="container">
  <div class="auth-card">
    <a href="/" class="auth-close" aria-label="Zavrieť">x</a>
    <h1 class="section-title">Verejné rodokmene</h1>

    <div id="page-alerts">
      <?php render_flash(); ?>
    </div>

    <?php if ($dbError): ?>
      <div class="alert alert-error">
        <button type="button" class="alert-close">x</button>
        Chyba databázy: <?= e($dbError) ?>
      </div>
    <?php endif; ?>

    <div id="public-trees-container">
      <?php renderPublicTreeList($trees); ?>
    </div>
  </div>
</div>
<?php
render_footer();

