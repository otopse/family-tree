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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  
  if ($action === 'add_record') {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
      'INSERT INTO ft_records (tree_id, owner, record_name, pattern, created, modified, enabled)
       VALUES (:tree_id, :owner, :name, :pattern, :created, :modified, 1)'
    );
    $stmt->execute([
      'tree_id' => $treeId,
      'owner' => $user['id'],
      'name' => 'Nová rodina',
      'pattern' => '',
      'created' => $now,
      'modified' => $now
    ]);
    flash('success', 'Nový záznam bol vytvorený.');
    redirect('/edit-tree.php?id=' . $treeId);
  }
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
      <form method="post" action="/edit-tree.php?id=<?= $treeId ?>">
        <input type="hidden" name="action" value="add_record">
        <button type="submit" class="btn-primary">+ Pridať nový záznam</button>
      </form>
      <div class="search-box">
        <input type="text" placeholder="Hľadať..." class="form-control" style="width: 200px;">
      </div>
    </div>

    <div class="masonry-grid">
      <?php if (empty($records)): ?>
        <div class="empty-state">
          <p>Tento rodokmeň zatiaľ neobsahuje žiadne záznamy (rodiny).</p>
          <p>Začnite pridaním nového záznamu.</p>
        </div>
      <?php else: ?>
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
          <div class="record-card">
            <div class="record-id">#<?= $record['id'] ?></div>
            
            <!-- Father Row -->
            <div class="record-row father-row">
              <?php if ($man): ?>
                <span class="person-name"><?= format_element($man) ?></span>
              <?php else: ?>
                <span class="empty-placeholder">&nbsp;</span>
              <?php endif; ?>
            </div>

            <!-- Mother Row -->
            <div class="record-row mother-row">
              <?php if ($woman): ?>
                <span class="person-name"><?= format_element($woman) ?></span>
              <?php else: ?>
                <span class="empty-placeholder">&nbsp;</span>
              <?php endif; ?>
            </div>

            <!-- Children List -->
            <div class="children-list">
              <?php foreach ($children as $child): ?>
                <div class="child-row">
                  <span class="person-name"><?= format_element($child) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
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

  /* Masonry Grid Styles */
  .masonry-grid {
    column-count: 1;
    column-gap: 16px;
  }
  
  @media (min-width: 640px) {
    .masonry-grid { column-count: 2; }
  }
  @media (min-width: 1024px) {
    .masonry-grid { column-count: 3; }
  }
  @media (min-width: 1440px) {
    .masonry-grid { column-count: 4; }
  }

  .record-card {
    break-inside: avoid;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 16px;
    position: relative;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .record-id {
    position: absolute;
    top: 0;
    left: 0;
    background: #000;
    color: #fff;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: bold;
    border-bottom-right-radius: 6px;
    z-index: 10;
  }

  .record-row {
    min-height: 36px;
    padding: 6px 12px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    align-items: center;
    font-size: 14px;
  }

  .father-row {
    /* Add padding to avoid ID badge overlap */
    padding-left: 40px; 
    background-color: #f9fafb; /* Slight background for parents? Optional. Let's keep white as per "minimal" vibe */
    background-color: white;
  }
  
  .mother-row {
    border-bottom: 1px solid #f3f4f6;
  }

  .children-list {
    display: flex;
    flex-direction: column;
    background-color: #fff;
    padding: 4px 0;
  }
  
  .child-row {
    padding: 4px 12px;
    font-size: 13px;
    color: #4b5563;
  }

  .empty-placeholder {
    display: inline-block;
    width: 100%;
    height: 100%;
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
