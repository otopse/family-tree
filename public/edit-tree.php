<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/_layout.php';

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
$elements = [];
try {
  // Fetch records
  $stmt = db()->prepare(
    'SELECT * FROM ft_records WHERE tree_id = :tree_id ORDER BY created DESC'
  );
  $stmt->execute(['tree_id' => $treeId]);
  $records = $stmt->fetchAll();

  if (!empty($records)) {
    // Fetch elements for these records
    $recordIds = array_column($records, 'id');
    $inQuery = implode(',', array_fill(0, count($recordIds), '?'));
    $stmt = db()->prepare(
      "SELECT * FROM ft_elements WHERE record_id IN ($inQuery) ORDER BY sort_order ASC"
    );
    $stmt->execute($recordIds);
    $allElements = $stmt->fetchAll();

    // Group elements by record_id
    foreach ($allElements as $el) {
      $elements[$el['record_id']][] = $el;
    }
  }
} catch (PDOException $e) {
  // Tables might not exist yet
}

// Helper to format element display
function format_element(array $el): string {
  $text = e($el['full_name']);
  
  $dates = [];
  if (!empty($el['birth_date'])) {
    $b = date('Y.m.d', strtotime($el['birth_date']));
    if (!empty($el['birth_place'])) $b .= ' ' . e($el['birth_place']);
    $dates[] = $b;
  }
  if (!empty($el['death_date'])) {
    $d = date('Y.m.d', strtotime($el['death_date']));
    if (!empty($el['death_place'])) $d .= ' ' . e($el['death_place']);
    $dates[] = $d;
  }

  if (!empty($dates)) {
    $text .= ' (' . implode('-', $dates) . ')';
  }

  return $text;
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
        <table class="data-table record-table">
          <thead>
            <tr>
              <th style="width: 50px;">ID</th>
              <th>Muž</th>
              <th>Žena</th>
              <th>Deti</th>
              <th style="width: 120px;">Akcie</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $record): ?>
              <?php
                $recordElements = $elements[$record['id']] ?? [];
                $man = null;
                $woman = null;
                $children = [];
                
                foreach ($recordElements as $el) {
                  if ($el['type'] === 'MUZ') $man = $el;
                  elseif ($el['type'] === 'ZENA') $woman = $el;
                  elseif ($el['type'] === 'DIETA') $children[] = $el;
                }
              ?>
              <tr>
                <td>#<?= $record['id'] ?></td>
                <td>
                  <?php if ($man): ?>
                    <div class="person-cell">
                      <span class="person-icon">👨</span>
                      <span class="person-info"><?= format_element($man) ?></span>
                      <div class="person-actions">
                        <button class="btn-tiny" title="Editovať">✏️</button>
                        <button class="btn-tiny" title="Zmazať">🗑️</button>
                      </div>
                    </div>
                  <?php else: ?>
                    <button class="btn-dashed">+ Pridať muža</button>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($woman): ?>
                    <div class="person-cell">
                      <span class="person-icon">👩</span>
                      <span class="person-info"><?= format_element($woman) ?></span>
                      <div class="person-actions">
                        <button class="btn-tiny" title="Editovať">✏️</button>
                        <button class="btn-tiny" title="Zmazať">🗑️</button>
                      </div>
                    </div>
                  <?php else: ?>
                    <button class="btn-dashed">+ Pridať ženu</button>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="children-list">
                    <?php foreach ($children as $child): ?>
                      <div class="person-cell child-cell">
                        <span class="person-icon">👶</span>
                        <span class="person-info"><?= format_element($child) ?></span>
                        <div class="person-actions">
                          <button class="btn-tiny" title="Editovať">✏️</button>
                          <button class="btn-tiny" title="Zmazať">🗑️</button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                    <button class="btn-dashed btn-small">+ Dieťa</button>
                  </div>
                </td>
                <td>
                  <button class="btn-icon" title="Editovať záznam">✏️</button>
                  <button class="btn-icon" title="Zmazať záznam">🗑️</button>
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

  /* Record View Styles */
  .record-table td {
    vertical-align: top;
  }

  .person-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
    position: relative;
  }

  .person-cell:hover .person-actions {
    display: flex;
  }

  .person-icon {
    font-size: 1.2rem;
  }

  .person-info {
    font-size: 0.9rem;
  }

  .person-actions {
    display: none;
    gap: 4px;
    margin-left: auto;
  }

  .btn-tiny {
    padding: 2px 4px;
    font-size: 0.7rem;
    border: 1px solid var(--border-color);
    background: white;
    border-radius: 4px;
    cursor: pointer;
  }

  .btn-tiny:hover {
    background: var(--bg-secondary);
  }

  .btn-dashed {
    border: 1px dashed var(--border-color);
    background: none;
    color: var(--text-secondary);
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85rem;
    cursor: pointer;
    width: 100%;
    text-align: left;
  }

  .btn-dashed:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border-style: solid;
  }

  .btn-small {
    font-size: 0.8rem;
    padding: 2px 6px;
    margin-top: 4px;
    width: auto;
  }

  .children-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .child-cell {
    padding-left: 8px;
    border-left: 2px solid var(--border-color);
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
