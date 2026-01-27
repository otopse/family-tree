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

// ---------------------------------------------------------
// DATA FETCHING & PROCESSING LOGIC
// ---------------------------------------------------------

$viewData = [];

try {
  // Fetch raw records
  $stmt = db()->prepare(
    'SELECT * FROM ft_records WHERE tree_id = :tree_id'
  );
  $stmt->execute(['tree_id' => $treeId]);
  $rawRecords = $stmt->fetchAll();

  if (!empty($rawRecords)) {
    // Fetch elements
    $recordIds = array_column($rawRecords, 'id');
    $inQuery = implode(',', array_fill(0, count($recordIds), '?'));
    $stmt = db()->prepare(
      "SELECT * FROM ft_elements WHERE record_id IN ($inQuery) ORDER BY sort_order ASC"
    );
    $stmt->execute($recordIds);
    $allElements = $stmt->fetchAll();

    // Group elements
    $elementsByRecord = [];
    foreach ($allElements as $el) {
      $elementsByRecord[$el['record_id']][] = $el;
    }

    // Debug Log Init
    $debugLog = __DIR__ . '/gedcom_debug.log';
    
    // Helper to safely get year from YYYY.MM.DD, YYYY-MM-DD, or YYYY
    $getYear = function($dateStr) {
      if (empty($dateStr)) return null;
      $dateStr = trim($dateStr);
      // Remove brackets for calculation
      $dateStr = str_replace(['[', ']'], '', $dateStr);
      
      if (preg_match('/^(\d{4})/', $dateStr, $m)) {
        return (int)$m[1];
      }
      $ts = strtotime($dateStr);
      return $ts ? (int)date('Y', $ts) : null;
    };

    // ---------------------------------------------------------
    // No on-the-fly calculation anymore.
    // We just display what is in the DB.
    // ---------------------------------------------------------

    // Process each record for View
    foreach ($rawRecords as $record) {
      $els = $elementsByRecord[$record['id']] ?? [];
      
      $man = null;
      $woman = null;
      $children = [];

      foreach ($els as $e) {
        if ($e['type'] === 'MUZ') $man = $e;
        elseif ($e['type'] === 'ZENA') $woman = $e;
        elseif ($e['type'] === 'DIETA') $children[] = $e;
      }

      $manYear = $man ? $getYear($man['birth_date'] ?? '') : null;
      $womanYear = $woman ? $getYear($woman['birth_date'] ?? '') : null;
      
      // Determine Sort Key
      $sortYear = $manYear ?? ($womanYear ?? 9999);

      $viewData[] = [
        'record_id' => $record['id'],
        'man' => $man,
        'woman' => $woman,
        'children' => $children,
        'sort_year' => $sortYear
      ];
    }

    // 4. Sort
    usort($viewData, function($a, $b) {
      return $a['sort_year'] <=> $b['sort_year'];
    });
  }

} catch (PDOException $e) {
  // Silent fail or log
}

// ---------------------------------------------------------
// HELPER FOR VIEW
// ---------------------------------------------------------
function render_person_html(?array $el, int $seqNum): string {
  if (!$el) {
    return '<span class="empty-placeholder">&nbsp;</span>';
  }

  $name = e($el['full_name']);
  $dateStr = '';

  // Check if date is fictional/imputed (stored as [YYYY])
  $birthDate = trim($el['birth_date'] ?? '');
  $isFictional = false;
  
  if (strpos($birthDate, '[') === 0 && substr($birthDate, -1) === ']') {
      $isFictional = true;
      $birthDate = substr($birthDate, 1, -1);
  }

  // Helper to format a single date string safely
  $formatDate = function($val) {
    if (empty($val)) return '';
    $val = trim($val);
    
    if (strpos($val, '[') === 0) return $val;

    if (preg_match('/^\d{4}$/', $val)) {
      return $val;
    }
    $ts = strtotime($val);
    if (!$ts) return $val;
    return date('Y.m.d', $ts);
  };
  
  $birthStr = $formatDate($el['birth_date'] ?? '');
  $deathStr = '';
  
  if (!empty($el['death_date']) && $el['death_date'] !== date('Y-m-d')) {
      $deathStr = $formatDate($el['death_date']);
  }

  if ($birthStr) {
      if ($isFictional) {
          $dateStr = $birthStr; 
      } else {
          if ($deathStr) {
              $dateStr = "({$birthStr} - {$deathStr})";
          } else {
              $dateStr = "({$birthStr})";
          }
      }
  } elseif ($deathStr) {
      $dateStr = "(? - {$deathStr})";
  }

  return '<span class="person-name"><span class="seq-badge">' . $seqNum . '</span> ' . $name . ' ' . $dateStr . '</span>';
}

render_header('Editovať rodokmeň: ' . e($tree['tree_name']));
?>

<div class="container-fluid" style="padding: 20px;">
  <div class="editor-header">
    <div style="display: flex; align-items: center; gap: 16px;">
      <a href="/family-trees.php" class="btn-secondary" style="padding: 6px 12px;">← Späť</a>
      <h1 style="margin: 0; font-size: 1.5rem;"><?= e($tree['tree_name']) ?> <span style="font-weight: normal; font-size: 1rem; color: var(--text-secondary);">(Editor)</span></h1>
    </div>
  </div>
    
  <div class="split-view-container">
    <!-- Left: Record View (Masonry) -->
    <div id="record-view" class="split-pane left-pane">
      <div class="toolbar">
        <form method="post" action="/edit-tree.php?id=<?= $treeId ?>">
          <input type="hidden" name="action" value="add_record">
          <button type="submit" class="btn-primary" style="padding: 6px 12px; font-size: 0.9rem;">+ Záznam</button>
        </form>
        <div class="search-box">
          <input type="text" placeholder="Hľadať..." class="form-control" style="width: 150px; padding: 4px 8px;">
        </div>
      </div>

      <div class="masonry-grid-single-col">
        <?php if (empty($viewData)): ?>
          <div class="empty-state">
            <p>Žiadne záznamy.</p>
          </div>
        <?php else: ?>
          <?php $cardCounter = 1; $personCounter = 1; ?>
          <?php foreach ($viewData as $row): ?>
            <div class="record-card">
              <div class="record-id">#<?= $cardCounter++ ?></div>
              
              <div class="record-row father-row">
                <?php if ($row['man']): ?>
                  <?= render_person_html($row['man'], $personCounter++) ?>
                <?php else: ?>
                  <span class="empty-placeholder">&nbsp;</span>
                <?php endif; ?>
              </div>

              <div class="record-row mother-row">
                <?php if ($row['woman']): ?>
                  <?= render_person_html($row['woman'], $personCounter++) ?>
                <?php else: ?>
                  <span class="empty-placeholder">&nbsp;</span>
                <?php endif; ?>
              </div>

              <div class="children-list">
                <?php foreach ($row['children'] as $child): ?>
                  <div class="child-row">
                    <?= render_person_html($child, $personCounter++) ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: Tree View -->
    <div id="tree-view" class="split-pane right-pane">
      <iframe src="/view-tree.php?id=<?= $treeId ?>&embed=true" style="width:100%; height:100%; border:none;"></iframe>
    </div>
  </div>
</div>

<style>
  html, body {
    height: 100%;
    overflow: hidden; /* Prevent body scroll */
  }

  .container-fluid {
    height: 100vh;
    display: flex;
    flex-direction: column;
    padding: 0 !important;
  }
  
  .editor-header {
    flex: 0 0 auto;
    padding: 10px 20px;
    margin: 0;
    border-bottom: 1px solid var(--border-color);
    background: white;
  }

  .view-toggles { display: none; } /* Hide toggles as we show both */

  .split-view-container {
    display: flex;
    flex: 1;
    overflow: hidden;
    min-height: 0; /* Important for flex children to scroll */
  }

  .split-pane {
    height: 100%;
  }

  .left-pane {
    width: 350px; /* Fixed width for tiles */
    flex: 0 0 350px;
    border-right: 1px solid var(--border-color);
    background: #f8fafc;
    padding: 16px;
    overflow-y: auto;
    overflow-x: hidden;
    height: 100%;
  }

  /* Custom scrollbar styling */
  .left-pane::-webkit-scrollbar {
    width: 8px;
  }

  .left-pane::-webkit-scrollbar-track {
    background: #e2e8f0;
    border-radius: 4px;
  }

  .left-pane::-webkit-scrollbar-thumb {
    background: #94a3b8;
    border-radius: 4px;
  }

  .left-pane::-webkit-scrollbar-thumb:hover {
    background: #64748b;
  }

  .right-pane {
    flex: 1;
    background: white;
  }

  /* Single Column Grid for Left Pane */
  .masonry-grid-single-col {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  /* Adjust Record Card for smaller space */
  .record-card {
    margin-bottom: 0;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
  }

  /* Hide original grid styles to avoid conflict */
  .masonry-grid { display: none; }
  .view-section { display: block !important; }


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
    padding-bottom: 4px; /* Slight padding at bottom */
  }

  .record-id {
    position: absolute;
    bottom: 0;
    right: 0;
    top: auto;
    left: auto;
    background: #000;
    color: #fff;
    padding: 1px 6px;
    font-size: 10px;
    font-weight: bold;
    border-top-left-radius: 6px;
    z-index: 10;
    line-height: 1.2;
  }

  .record-row {
    padding: 1px 8px; /* Very compact padding */
    display: flex;
    align-items: center;
    font-size: 13px;
    line-height: 1.3;
  }

  .father-row {
    padding-top: 6px; /* A bit more top padding for the first element */
    background-color: white;
  }
  
  .mother-row {
    /* No border, just tight stacking */
  }

  .children-list {
    display: flex;
    flex-direction: column;
    background-color: #fff;
    padding: 0;
    margin-top: 2px;
  }
  
  .child-row {
    padding: 1px 8px;
    font-size: 13px;
    color: #4b5563;
    line-height: 1.3;
  }

  .empty-placeholder {
    display: inline-block;
    width: 100%;
    min-height: 18px; /* Height of one text line */
  }

  .person-name {
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .seq-badge {
    background-color: #10b981;
    color: white;
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 11px;
    margin-right: 4px;
    vertical-align: middle;
    font-weight: bold;
    min-width: 20px;
    display: inline-block;
    text-align: center;
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
