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

    // Process each record to apply business logic (date imputation)
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

      // Helper to safely get year from YYYY-MM-DD or YYYY
      $getYear = function($dateStr) {
        if (empty($dateStr)) return null;
        // Clean up date string
        $dateStr = trim($dateStr);
        // If it's just a year (4 digits), return it directly
        if (preg_match('/^\d{4}$/', $dateStr)) {
          return (int)$dateStr;
        }
        $ts = strtotime($dateStr);
        return $ts ? (int)date('Y', $ts) : null;
      };

      // 1. Extract Real Years
      $manYear = $man ? $getYear($man['birth_date'] ?? '') : null;
      $womanYear = $woman ? $getYear($woman['birth_date'] ?? '') : null;
      
      $manFictional = false;
      $womanFictional = false;

      // -------------------------------------------------------
      // IMPUTATION LOGIC
      // -------------------------------------------------------

      // A) Bottom-Up: Infer Parents from Children if parents missing
      // (Only if BOTH parents are missing years, or at least we need an anchor)
      // Actually, if we have children but no parents, we can anchor from the oldest child.
      
      $oldestChildYear = null;
      foreach ($children as $child) {
        $cy = $getYear($child['birth_date'] ?? '');
        if ($cy !== null) {
          if ($oldestChildYear === null || $cy < $oldestChildYear) {
            $oldestChildYear = $cy;
          }
        }
      }

      // If woman is missing year but we have a child
      if ($womanYear === null && $oldestChildYear !== null && $woman) {
        $womanYear = $oldestChildYear - 20;
        $womanFictional = true;
      }

      // B) Horizontal: Infer Spouse from Spouse
      // "Ak je známy dátum narodenia manžela ale nie ženy, potom doplň rok narodenia ženy ako manžel +10 rokov"
      if ($manYear !== null && $womanYear === null && $woman) {
        $womanYear = $manYear + 10;
        $womanFictional = true;
      }
      // "Ak je známy dátum narodenia ženy ale nie manžela, potom manžel=žena-10 rokov"
      elseif ($womanYear !== null && $manYear === null && $man) {
        $manYear = $womanYear - 10;
        $manFictional = true;
      }

      // C) Top-Down: Infer Children from Mother (or Siblings)
      $processedChildren = [];
      $prevChildYear = null;

      foreach ($children as $index => $child) {
        $childYear = $getYear($child['birth_date'] ?? '');
        $childFictional = false;

        if ($childYear === null) {
          // "Ak je známy dátum narodenia manželky ale neznámy dátum narodenia prvého dieťaťa, potom dieťa=mama+20"
          if ($index === 0 && $womanYear !== null) {
            $childYear = $womanYear + 20;
            $childFictional = true;
          }
          // "Ak je známy dátum narodenia predchádzajúceho dieťaťa, potom ďalšie dieťa=predchádzajúce+3 roky"
          elseif ($index > 0 && $prevChildYear !== null) {
            $childYear = $prevChildYear + 3;
            $childFictional = true;
          }
        }

        // Save processed child
        $processedChildren[] = [
          'data' => $child,
          'year' => $childYear,
          'is_fictional' => $childFictional
        ];

        // Update tracker for next iteration
        if ($childYear !== null) {
          $prevChildYear = $childYear;
        }
      }

      // Prepare final object for view
      $processedMan = $man ? ['data' => $man, 'year' => $manYear, 'is_fictional' => $manFictional] : null;
      $processedWoman = $woman ? ['data' => $woman, 'year' => $womanYear, 'is_fictional' => $womanFictional] : null;

      // Determine Sort Key (Man's year, fallback to Woman's year, fallback to 9999)
      $sortYear = $manYear ?? ($womanYear ?? 9999);

      $viewData[] = [
        'record_id' => $record['id'],
        'man' => $processedMan,
        'woman' => $processedWoman,
        'children' => $processedChildren,
        'sort_year' => $sortYear
      ];
    }

    // 4. Sort
    // "Pri vyreportovaní dlaždíc ich usporiadaj od najmenšieho dátumu narodenia manžela po najvyšší."
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
function render_person_html(?array $personData): string {
  if (!$personData) {
    return '<span class="empty-placeholder">&nbsp;</span>';
  }

  $el = $personData['data'];
  $year = $personData['year'];
  $isFictional = $personData['is_fictional'];
  
  $name = e($el['full_name']);
  $dateStr = '';

  // Helper to format a single date string safely
  $formatDate = function($val) {
    if (empty($val)) return '';
    $val = trim($val);
    // If it is just a year, return it as is to prevent strtotime parsing "1848" as "18:48 today"
    if (preg_match('/^\d{4}$/', $val)) {
      return $val;
    }
    // Try parse
    $ts = strtotime($val);
    if (!$ts) return $val; // fallback to original string if parse fails
    return date('Y.m.d', $ts);
  };

  // Calculate Display Date
  if ($year) {
    $birthStr = (string)$year; 
    
    // If NOT fictional, try to use full birth date from DB
    if (!$isFictional && !empty($el['birth_date'])) {
      $birthStr = $formatDate($el['birth_date']);
    }

    $deathStr = '';
    if (!empty($el['death_date']) && $el['death_date'] !== date('Y-m-d')) {
      $deathStr = $formatDate($el['death_date']);
    }

    $open = $isFictional ? '[' : '(';
    $close = $isFictional ? ']' : ')'; 

    if ($isFictional) {
      // Fictional: [YYYY]
      $dateStr = "{$open}{$birthStr}{$close}";
    } else {
      // Real dates: (Born - Died) or (Born)
      if ($deathStr) {
         $dateStr = "{$open}{$birthStr} - {$deathStr}{$close}";
      } else {
         $dateStr = "{$open}{$birthStr}{$close}";
      }
    }
  } elseif (!empty($el['death_date']) && $el['death_date'] !== date('Y-m-d')) {
      // Only death date known
      $d = $formatDate($el['death_date']);
      $dateStr = "(? - {$d})";
  }

  return '<span class="person-name">' . $name . ' ' . $dateStr . '</span>';
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
      <?php if (empty($viewData)): ?>
        <div class="empty-state">
          <p>Tento rodokmeň zatiaľ neobsahuje žiadne záznamy (rodiny).</p>
          <p>Začnite pridaním nového záznamu.</p>
        </div>
      <?php else: ?>
        <?php $counter = 1; ?>
        <?php foreach ($viewData as $row): ?>
          <div class="record-card">
            <!-- Counter ID instead of DB ID -->
            <div class="record-id">#<?= $counter++ ?></div>
            
            <!-- Father Row -->
            <div class="record-row father-row">
              <?php if ($row['man']): ?>
                <?= render_person_html($row['man']) ?>
              <?php else: ?>
                <span class="empty-placeholder">&nbsp;</span>
              <?php endif; ?>
            </div>

            <!-- Mother Row -->
            <div class="record-row mother-row">
              <?php if ($row['woman']): ?>
                <?= render_person_html($row['woman']) ?>
              <?php else: ?>
                <span class="empty-placeholder">&nbsp;</span>
              <?php endif; ?>
            </div>

            <!-- Children List -->
            <div class="children-list">
              <?php foreach ($row['children'] as $child): ?>
                <div class="child-row">
                  <?= render_person_html($child) ?>
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
