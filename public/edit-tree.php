<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = require_login();
$treeId = (int) ($_GET['id'] ?? 0);

// Fetch tree and verify ownership
$stmt = db()->prepare('SELECT * FROM family_trees WHERE id = :id AND owner = :owner');
$stmt->execute(['id' => $treeId, 'owner' => $user['id']]);
$tree = $stmt->fetch();

if (!$tree) {
  flash('error', 'Rodokmeň neexistuje alebo k nemu nemáte prístup.');
  redirect('/family-trees.php');
}

// Fetch records for this tree
$records = [];
try {
  // We'll join elements later or fetch them separately if needed, 
  // but for the list view, we mainly need record info. 
  // To show "MUZ SRDIECKO ZENA...", we might need to fetch elements too.
  // For MVP list, let's fetch basic record info first.
  
  $stmt = db()->prepare(
    'SELECT * FROM ft_records WHERE tree_id = :tree_id ORDER BY created DESC'
  );
  $stmt->execute(['tree_id' => $treeId]);
  $records = $stmt->fetchAll();
} catch (PDOException $e) {
  // Tables might not exist yet
}

render_header('Editovať rodokmeň: ' . e($tree['tree_name']));
?>

<div class="container-fluid" style="padding: 20px;">
  <div class="editor-header">
    <div style="display: flex; align-items: center; gap: 16px;">
      <a href="/family-trees.php" class="btn-secondary" style="padding: 6px 12px;">← Späť</a>
      <h1 style="margin: 0; font-size: 1.5rem;"><?= e($tree['tree_name']) ?> <span style="font-weight: normal; font-size: 1rem; color: var(--text-secondary);">(Editor)</span></h1>
    </div>
    
    <div class="view-toggles">
      <button class="btn-toggle active" data-view="record-view">Record View</button>
      <button class="btn-toggle" data-view="tree-view">Tree View</button>
    </div>
  </div>

  <?php render_flash(); ?>

  <!-- Record View Section -->
  <div id="record-view" class="view-section active">
    <div class="toolbar">
      <button class="btn-primary">+ Pridať nový záznam</button>
      <div class="search-box">
        <input type="text" placeholder="Hľadať..." class="form-control" style="width: 200px;">
      </div>
    </div>

    <div class="records-grid">
      <?php if (empty($records)): ?>
        <div class="empty-state">
          <p>Tento rodokmeň zatiaľ neobsahuje žiadne záznamy (rodiny).</p>
          <p>Začnite pridaním nového záznamu.</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Názov</th>
              <th>Štruktúra (Pattern)</th>
              <th>Obsah (Ukážka)</th>
              <th>Akcie</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $record): ?>
              <tr>
                <td>#<?= $record['id'] ?></td>
                <td><?= e($record['record_name']) ?></td>
                <td><span class="badge"><?= e($record['pattern']) ?></span></td>
                <td>
                  <!-- Placeholder for elements visualization -->
                  <div class="elements-preview">
                    <!-- TODO: Fetch and display elements icons here -->
                    <span title="Muž">👨</span> ❤️ <span title="Žena">👩</span> 👶 👶
                  </div>
                </td>
                <td>
                  <button class="btn-icon">✏️</button>
                  <button class="btn-icon">🗑️</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tree View Section -->
  <div id="tree-view" class="view-section">
    <div class="tree-canvas-placeholder">
      <p>Tu bude grafické zobrazenie rodokmeňa.</p>
      <div style="font-size: 3rem; margin-top: 20px;">🌳</div>
    </div>
  </div>
</div>

<style>
  .container-fluid {
    max-width: 100%;
    padding: 0 24px;
  }
  
  .editor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
  }

  .view-toggles {
    display: flex;
    background: var(--bg-secondary);
    padding: 4px;
    border-radius: 8px;
    gap: 4px;
  }

  .btn-toggle {
    border: none;
    background: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    color: var(--text-secondary);
    transition: all 0.2s;
  }

  .btn-toggle.active {
    background: white;
    color: var(--primary-color);
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
  }

  .view-section {
    display: none;
  }

  .view-section.active {
    display: block;
    animation: fadeIn 0.3s ease;
  }

  .toolbar {
    display: flex;
    justify-content: space-between;
    margin-bottom: 16px;
  }

  .data-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  }

  .data-table th, .data-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
  }

  .data-table th {
    background: var(--bg-secondary);
    font-weight: 600;
    color: var(--text-secondary);
  }

  .badge {
    background: #e0e7ff;
    color: #4338ca;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 500;
  }

  .tree-canvas-placeholder {
    background: var(--bg-secondary);
    border: 2px dashed var(--border-color);
    border-radius: 12px;
    height: 400px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-secondary);
  }

  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const toggles = document.querySelectorAll('.btn-toggle');
  const sections = document.querySelectorAll('.view-section');

  toggles.forEach(toggle => {
    toggle.addEventListener('click', () => {
      // Update toggles
      toggles.forEach(t => t.classList.remove('active'));
      toggle.classList.add('active');

      // Update sections
      const viewId = toggle.dataset.view;
      sections.forEach(section => {
        section.classList.remove('active');
        if (section.id === viewId) {
          section.classList.add('active');
        }
      });
    });
  });
});
</script>

<?php
render_footer();
