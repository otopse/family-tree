<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/_layout.php';

// Debug log helper
$debugLog = __DIR__ . '/gedcom_debug.log';

// Initialize log file - clear it at the start of each edit-tree.php page load
// User can provide this file as feedback: https://family-tree.cz/gedcom_debug.log
file_put_contents($debugLog, date('Y-m-d H:i:s') . " [edit-tree] === EDIT-TREE START (log initialized/cleared) ===\n");
file_put_contents($debugLog, date('Y-m-d H:i:s') . " [edit-tree] REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? '') . " | Tree ID from GET=" . ($_GET['id'] ?? '') . "\n", FILE_APPEND);

function debugLog(string $msg): void {
    global $debugLog;
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " [edit-tree] " . $msg . "\n", FILE_APPEND);
}

debugLog("Tree ID: " . ($_GET['id'] ?? '0') . ", Request time: " . date('Y-m-d H:i:s'));

$user = require_login();
$treeId = (int) ($_GET['id'] ?? 0);
debugLog("Tree ID: $treeId, User ID: " . ($user['id'] ?? 'null'));

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
    
    // Log records with IDs and sequential numbers
    debugLog("Records count: " . count($viewData));
    $cardCounter = 1;
    $personCounter = 1;
    foreach ($viewData as $row) {
      $recordId = $row['record_id'];
      $manName = $row['man']['full_name'] ?? 'N/A';
      $womanName = $row['woman']['full_name'] ?? 'N/A';
      $childrenCount = count($row['children']);
      
      // Calculate person sequence numbers for this record
      $manSeqNum = $row['man'] ? $personCounter++ : null;
      $womanSeqNum = $row['woman'] ? $personCounter++ : null;
      $childrenSeqNums = [];
      foreach ($row['children'] as $child) {
        $childrenSeqNums[] = $personCounter++;
      }
      
      debugLog("Dlaždica [por.č.: $cardCounter, record_id: $recordId]: muž={$manName} (por.č. mien: " . ($manSeqNum ?? 'N/A') . "), žena={$womanName} (por.č. mien: " . ($womanSeqNum ?? 'N/A') . "), detí=$childrenCount (por.č. mien: " . implode(',', $childrenSeqNums) . ")");
      $cardCounter++;
    }
    
    debugLog("Total persons count: " . ($personCounter - 1));
  }

} catch (PDOException $e) {
  debugLog("PDO Exception: " . $e->getMessage());
}

// ---------------------------------------------------------
// HELPER: person key for first-occurrence (same as view-tree: gedcom_id or fallback)
// Fallback: full_name + birth_date + death_date so same person in different records merges.
// Normalize dates to year (4 digits) so "1805", "1805.01.01", "[1805]" match.
// ---------------------------------------------------------
function get_person_key(array $el): string {
  if (!empty($el['gedcom_id'])) {
    return (string) $el['gedcom_id'];
  }
  $name = trim($el['full_name'] ?? '');
  $birthRaw = trim($el['birth_date'] ?? '');
  $deathRaw = trim($el['death_date'] ?? '');
  $birth = preg_match('/\d{4}/', $birthRaw, $m) ? $m[0] : $birthRaw;
  $death = preg_match('/\d{4}/', $deathRaw, $m) ? $m[0] : $deathRaw;
  return 'n:' . $name . '|b:' . $birth . '|d:' . $death;
}

// ---------------------------------------------------------
// HELPER FOR VIEW
// ---------------------------------------------------------
function render_person_html(?array $el, int $currentSeqNum, int $firstSeqNum): string {
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

  // Zelené návestie len pri prvom výskyte; pri opakovaní sivé pole s por. č. prvého výskytu
  $isFirst = ($currentSeqNum === $firstSeqNum);
  if ($isFirst) {
    $badgeHtml = '<span class="seq-badge">' . $currentSeqNum . '</span>';
  } else {
    $badgeHtml = '<span class="seq-badge-ref">' . $firstSeqNum . '</span>';
  }
  return '<span class="person-name" data-seqnum="' . $currentSeqNum . '" data-graph-seqnum="' . $firstSeqNum . '" data-element-id="' . ($el['id'] ?? '') . '">' . $badgeHtml . ' ' . $name . ' ' . $dateStr . '</span>';
}

render_header('Editovať rodokmeň: ' . e($tree['tree_name']));
debugLog("=== RENDERING PHASE START ===");
debugLog("About to render container-fluid. Auth-page should have padding: 0");
debugLog("Current output buffer length: " . ob_get_length());
?><div class="container-fluid">
  <div class="editor-header">
      <div style="display: flex; align-items: center; gap: 16px; width: 100%;">
      <a href="/family-trees.php" class="btn-secondary" style="padding: 6px 12px;">← Späť</a>
      <h1 style="margin: 0; font-size: 1.5rem;"><?= e($tree['tree_name']) ?> <span style="font-weight: normal; font-size: 1rem; color: var(--text-secondary);">(Editor)</span></h1>
      
      <div style="display: flex; align-items: center; gap: 8px; margin-left: auto;">
        <div class="search-box" style="display: inline-block;">
          <input type="text" placeholder="Hľadať..." class="form-control" id="search-input" style="width: 150px; padding: 4px 8px;">
        </div>
        <form method="post" action="/edit-tree.php?id=<?= $treeId ?>" style="margin: 0; display: inline-block;">
          <input type="hidden" name="action" value="add_record">
          <button type="submit" class="btn-primary" style="padding: 6px 12px; font-size: 0.9rem;">+ Záznam</button>
        </form>
        <button type="button" id="edit-record-btn" class="btn-edit-record" style="padding: 6px 12px; font-size: 0.9rem;" disabled title="Vyberte dlaždicu kliknutím">Editovať</button>
        <button id="export-pdf-btn" class="btn-primary" style="padding: 6px 12px;">Export PDF</button>
      </div>
    </div>
  </div><?php
  debugLog("Editor header rendered. Export PDF button should be present.");
  debugLog("Records processed: " . count($viewData));
  debugLog("About to render split-view-container");
  ?><div class="split-view-container">
    <!-- Left: Record View (Masonry) -->
    <div id="record-view" class="split-pane left-pane">
      <div class="masonry-grid-single-col">
        <?php if (empty($viewData)): ?>
          <div class="empty-state">
            <p>Žiadne záznamy.</p>
          </div>
        <?php else: ?>
          <?php
            $cardCounter = 1;
            $personCounter = 1;
            $firstSeqByPersonKey = [];
            debugLog("=== TILES: building first-occurrence map by person key (gedcom_id or name|birth|death) ===");
          ?>
          <?php foreach ($viewData as $row): ?>
            <div class="record-card" data-record-id="<?= $row['record_id'] ?>">
              <div class="record-id">#<?= $cardCounter++ ?></div>
              
              <div class="record-row father-row">
                <?php if ($row['man']): ?>
                  <?php
                    $el = $row['man'];
                    $personKey = get_person_key($el);
                    if (!isset($firstSeqByPersonKey[$personKey])) { $firstSeqByPersonKey[$personKey] = $personCounter; }
                    $currentSeq = $personCounter++;
                    $firstSeq = $firstSeqByPersonKey[$personKey];
                    $isFirst = ($currentSeq === $firstSeq);
                    debugLog(sprintf("TILE person: element_id=%s gedcom_id=%s personKey=%s currentSeq=%d firstSeq=%d badge=%s name=%s", $el['id'] ?? '', $el['gedcom_id'] ?? '', $personKey, $currentSeq, $firstSeq, $isFirst ? 'GREEN' : 'GRAY', $el['full_name'] ?? ''));
                    echo render_person_html($el, $currentSeq, $firstSeq);
                  ?>
                <?php else: ?>
                  <span class="empty-placeholder">&nbsp;</span>
                <?php endif; ?>
              </div>

              <div class="record-row mother-row">
                <?php if ($row['woman']): ?>
                  <?php
                    $el = $row['woman'];
                    $personKey = get_person_key($el);
                    if (!isset($firstSeqByPersonKey[$personKey])) { $firstSeqByPersonKey[$personKey] = $personCounter; }
                    $currentSeq = $personCounter++;
                    $firstSeq = $firstSeqByPersonKey[$personKey];
                    $isFirst = ($currentSeq === $firstSeq);
                    debugLog(sprintf("TILE person: element_id=%s gedcom_id=%s personKey=%s currentSeq=%d firstSeq=%d badge=%s name=%s", $el['id'] ?? '', $el['gedcom_id'] ?? '', $personKey, $currentSeq, $firstSeq, $isFirst ? 'GREEN' : 'GRAY', $el['full_name'] ?? ''));
                    echo render_person_html($el, $currentSeq, $firstSeq);
                  ?>
                <?php else: ?>
                  <span class="empty-placeholder">&nbsp;</span>
                <?php endif; ?>
              </div>

              <div class="children-list">
                <?php foreach ($row['children'] as $child): ?>
                  <div class="child-row">
                    <?php
                      $el = $child;
                      $personKey = get_person_key($el);
                      if (!isset($firstSeqByPersonKey[$personKey])) { $firstSeqByPersonKey[$personKey] = $personCounter; }
                      $currentSeq = $personCounter++;
                      $firstSeq = $firstSeqByPersonKey[$personKey];
                      $isFirst = ($currentSeq === $firstSeq);
                      debugLog(sprintf("TILE person: element_id=%s gedcom_id=%s personKey=%s currentSeq=%d firstSeq=%d badge=%s name=%s", $el['id'] ?? '', $el['gedcom_id'] ?? '', $personKey, $currentSeq, $firstSeq, $isFirst ? 'GREEN' : 'GRAY', $el['full_name'] ?? ''));
                      echo render_person_html($el, $currentSeq, $firstSeq);
                    ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php debugLog("=== TILES: firstSeqByPersonKey summary: " . json_encode($firstSeqByPersonKey)); ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: Tree View -->
    <div id="tree-view" class="split-pane right-pane">
      <iframe src="/view-tree.php?id=<?= $treeId ?>&embed=true" style="width:100%; height:100%; border:none;"></iframe>
    </div>
  </div>
</div>

<!-- Modal Window for Editing Record -->
<div id="edit-record-modal" class="edit-modal" style="display: none;">
  <div class="edit-modal-content">
    <div class="edit-modal-header">
      <h3>Editovať záznam</h3>
      <button class="edit-modal-close" aria-label="Zavrieť">&times;</button>
    </div>
    <div class="edit-modal-body">
      <input type="hidden" id="edit-record-id" value="">
      <input type="hidden" id="edit-tree-id" value="<?= $treeId ?>">
      
      <div class="edit-input-group">
        <input type="text" id="edit-man-input" class="edit-input" placeholder="Muž">
      </div>
      
      <div class="edit-input-group">
        <input type="text" id="edit-woman-input" class="edit-input" placeholder="Žena">
      </div>
      
      <div id="edit-children-container">
        <!-- Children inputs will be added here dynamically -->
      </div>
      
      <button type="button" id="add-child-btn" class="btn-add-child">+ Dieťa</button>
    </div>
    <div class="edit-modal-footer">
      <button type="button" id="save-record-btn" class="btn-action btn-primary">Ulož</button>
    </div>
  </div>
</div>
<?php
debugLog("Container-fluid closing tag rendered");
debugLog("About to render inline styles");
debugLog("=== EDIT-TREE PHP RENDERING DONE ===");
?>
<style>
  html, body {
    height: 100%;
    overflow: hidden; /* Prevent body scroll */
    margin: 0;
    padding: 0;
    position: relative; /* For absolute positioning of auth-page */
  }
  
  /* Override auth-page padding for edit-tree - must override global .auth-page padding: 80px 0 */
  /* Use highest specificity to override global styles */
  html body main.auth-page,
  body main.auth-page,
  main.auth-page {
    padding: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin: 0 !important;
    margin-top: 0 !important; /* Changed: No margin-top, navbar is sticky so auth-page starts at top */
    margin-bottom: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    height: 100vh !important; /* Full viewport height */
    min-height: 100vh !important;
    max-height: 100vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    position: absolute !important; /* Changed: Use absolute positioning */
    top: 72px !important; /* Start below navbar */
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    background: white !important; /* Match container background */
    box-sizing: border-box !important;
  }
  
  /* Hide footer on edit-tree page */
  .footer {
    display: none !important;
  }
  
  /* Ensure navbar has no margin-bottom that could create gap */
  .navbar {
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
  }

  .container-fluid {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    margin: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    min-height: 0; /* Important for flex children */
    overflow: hidden;
    height: 100%;
    box-sizing: border-box;
    align-self: stretch; /* Fill parent */
  }
  
  .editor-header {
    flex: 0 0 auto;
    padding: 10px 20px;
    margin: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    border-bottom: 1px solid var(--border-color);
    background: white;
    box-sizing: border-box;
  }
  
  .editor-header > div {
    display: flex;
    align-items: center;
    gap: 16px;
    width: 100%;
  }
  
  .editor-header h1 {
    flex: 0 0 auto;
  }
  
  .editor-header .search-box {
    display: inline-flex;
    align-items: center;
  }
  
  .editor-header form {
    margin: 0;
    display: inline-block;
  }
  
  .split-view-container {
    flex: 1;
    margin-top: 0 !important;
    padding-top: 0 !important;
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
    min-height: 0;
    box-sizing: border-box;
  }

  .view-toggles { display: none; } /* Hide toggles as we show both */

  .split-view-container {
    display: flex;
    flex: 1;
    overflow: hidden;
    min-height: 0; /* Important for flex children to scroll */
    height: 100%;
    margin-top: 0 !important;
    padding-top: 0 !important;
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
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
    padding-bottom: 32px; /* Extra bottom padding to show last card fully */
    overflow-y: auto;
    overflow-x: hidden;
    height: 100%;
    box-sizing: border-box;
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
    height: 100%;
    overflow: hidden;
  }
  
  .right-pane iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
  }

  /* Single Column Grid for Left Pane */
  .masonry-grid-single-col {
    display: flex;
    flex-direction: column;
    gap: 8px; /* Reduced from 16px to 8px */
    padding-bottom: 0; /* No extra padding, gap handles spacing */
    min-height: fit-content; /* Ensure all cards are visible */
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
    margin-bottom: 0; /* No margin, gap handles spacing */
    position: relative;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    padding-bottom: 2px; /* Reduced from 4px to 2px */
  }

  .record-id {
    position: absolute;
    top: 0;
    right: 0;
    bottom: auto;
    left: auto;
    background: #000;
    color: #fff;
    padding: 1px 6px;
    font-size: 11px; /* Slightly smaller */
    font-weight: bold;
    border-bottom-left-radius: 6px; /* Changed from border-top-left-radius */
    z-index: 10;
    line-height: 1.2;
  }

  .record-row {
    padding: 0px 8px; /* Reduced from 1px to 0px */
    display: flex;
    align-items: center;
    font-size: 12px; /* Reduced from 13px */
    line-height: 1.2; /* Reduced from 1.3 */
    min-height: 20px; /* Reduced height */
  }

  .father-row {
    padding-top: 4px; /* Reduced from 6px */
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
    margin-top: 1px; /* Reduced from 2px */
  }
  
  .child-row {
    padding: 0px 8px; /* Reduced from 1px to 0px */
    font-size: 12px; /* Reduced from 13px */
    color: #4b5563;
    line-height: 1.2; /* Reduced from 1.3 */
    min-height: 20px; /* Reduced height */
  }

  .empty-placeholder {
    display: inline-block;
    width: 100%;
    min-height: 20px; /* Reduced from 18px to match row height */
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
    padding: 0px 3px; /* Reduced from 1px 4px */
    border-radius: 3px;
    font-size: 9px; /* Reduced from 10px */
    margin-right: 3px; /* Reduced from 4px */
    vertical-align: middle;
    font-weight: bold;
    min-width: 16px; /* Reduced from 18px */
    display: inline-block;
    text-align: center;
    line-height: 1.1; /* Reduced from 1.2 */
  }

  /* Sivé pole – por. č. prvého výskytu pri opakovaných menách */
  .seq-badge-ref {
    background-color: #9ca3af;
    color: white;
    padding: 0px 3px;
    border-radius: 3px;
    font-size: 9px;
    margin-right: 3px;
    vertical-align: middle;
    font-weight: bold;
    min-width: 16px;
    display: inline-block;
    text-align: center;
    line-height: 1.1;
  }
  
  .person-name {
    transition: background-color 0.2s;
    display: inline-block;
    padding: 1px 4px; /* Reduced from 2px to 1px */
    border-radius: 4px;
    margin: 0px 0; /* Reduced from 1px to 0px */
  }
  
  .person-name:hover {
    background-color: #f0f0f0;
  }
  
  .person-name.selected {
    background-color: #ffffb8;
    box-shadow: 0 0 0 2px #1890ff;
  }
  
  .person-name.search-highlight {
    background-color: #fff3cd;
    font-weight: bold;
  }
  
  .record-card.selected {
    box-shadow: 0 0 0 3px #1890ff;
    transition: box-shadow 0.2s;
    border-color: #1890ff;
    background-color: #f0f7ff;
  }

  .record-card {
    cursor: pointer;
  }

  .record-card:hover {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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

  /* Edit Modal Styles */
  .edit-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .edit-modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
  }

  .edit-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
  }

  .edit-modal-header h3 {
    margin: 0;
    font-size: 1.25rem;
  }

  .edit-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    color: #666;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .edit-modal-close:hover {
    color: #000;
  }

  .edit-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
  }

  .edit-input-group {
    margin-bottom: 16px;
  }

  .edit-input-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #333;
    font-size: 14px;
  }

  .edit-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
  }

  .edit-input:focus {
    outline: none;
    border-color: var(--primary-color, #1890ff);
    box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.1);
  }

  #edit-children-container {
    margin-bottom: 12px;
  }

  .child-input-group {
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .child-input-group .edit-input {
    flex: 1;
  }

  .remove-child-btn {
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 6px 12px;
    cursor: pointer;
    font-size: 12px;
  }

  .remove-child-btn:hover {
    background: #dc2626;
  }

  .btn-add-child {
    background: #f3f4f6;
    border: 1px dashed #d1d5db;
    border-radius: 4px;
    padding: 8px 16px;
    cursor: pointer;
    color: #666;
    font-size: 14px;
    width: 100%;
  }

  .btn-add-child:hover {
    background: #e5e7eb;
    border-color: #9ca3af;
  }

  .edit-modal-footer {
    padding: 16px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
  }

  .btn-action {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: white;
    cursor: pointer;
    font-size: 14px;
  }

  .btn-action:hover {
    background: #f9fafb;
  }

  .btn-action.btn-primary {
    background: var(--primary-color, #1890ff);
    color: white;
    border-color: var(--primary-color, #1890ff);
  }

  .btn-action.btn-primary:hover {
    background: #1677ff;
  }

  /* Editovať button: primary when enabled, grey when disabled */
  #edit-record-btn.btn-edit-record {
    background: #9ca3af;
    color: #fff;
    border: none;
    cursor: not-allowed;
  }
  #edit-record-btn.btn-edit-record:not(:disabled) {
    background: var(--primary-color, #1890ff);
    cursor: pointer;
  }
  #edit-record-btn.btn-edit-record:not(:disabled):hover {
    background: #1677ff;
  }
</style>

<script>
// Log immediately when script loads (before DOM ready)
console.log('[EDIT-TREE] Script loaded at:', new Date().toISOString());
console.log('[EDIT-TREE] Document ready state:', document.readyState);

document.addEventListener('DOMContentLoaded', function() {
  console.log('[EDIT-TREE] DOMContentLoaded event fired at:', new Date().toISOString());
  // Enhanced diagnostic logging for layout issues
  console.log('[EDIT-TREE] ========================================');
  console.log('[EDIT-TREE] DOM Loaded - Starting comprehensive diagnostics');
  console.log('[EDIT-TREE] ========================================');
  
  // Get all relevant elements
  const body = document.body;
  const html = document.documentElement;
  const navbar = document.querySelector('.navbar');
  const authPage = document.querySelector('.auth-page');
  const containerFluid = document.querySelector('.container-fluid');
  const editorHeader = document.querySelector('.editor-header');
  const splitView = document.querySelector('.split-view-container');
  
  // Log viewport and body dimensions
  console.log('[EDIT-TREE] Viewport dimensions:', {
    width: window.innerWidth,
    height: window.innerHeight,
    scrollHeight: document.documentElement.scrollHeight
  });
  
  if (body) {
    const bodyStyles = window.getComputedStyle(body);
    const bodyRect = body.getBoundingClientRect();
    console.log('[EDIT-TREE] body computed styles:', {
      margin: bodyStyles.margin,
      padding: bodyStyles.padding,
      height: bodyStyles.height,
      overflow: bodyStyles.overflow,
      boxSizing: bodyStyles.boxSizing
    });
    console.log('[EDIT-TREE] body getBoundingClientRect:', bodyRect);
  }
  
  if (html) {
    const htmlStyles = window.getComputedStyle(html);
    console.log('[EDIT-TREE] html computed styles:', {
      margin: htmlStyles.margin,
      padding: htmlStyles.padding,
      height: htmlStyles.height,
      overflow: htmlStyles.overflow
    });
  }
  
  // Log navbar
  if (navbar) {
    const navStyles = window.getComputedStyle(navbar);
    const navRect = navbar.getBoundingClientRect();
    console.log('[EDIT-TREE] navbar computed styles:', {
      height: navStyles.height,
      marginBottom: navStyles.marginBottom,
      paddingBottom: navStyles.paddingBottom,
      position: navStyles.position
    });
    console.log('[EDIT-TREE] navbar getBoundingClientRect:', navRect);
    console.log('[EDIT-TREE] navbar height (actual):', navRect.height + 'px');
  }
  
  // Log auth-page with full details
  if (authPage) {
    const authStyles = window.getComputedStyle(authPage);
    const authRect = authPage.getBoundingClientRect();
    console.log('[EDIT-TREE] ===== auth-page DETAILED ANALYSIS =====');
    console.log('[EDIT-TREE] auth-page computed styles:', {
      padding: authStyles.padding,
      paddingTop: authStyles.paddingTop,
      paddingBottom: authStyles.paddingBottom,
      paddingLeft: authStyles.paddingLeft,
      paddingRight: authStyles.paddingRight,
      margin: authStyles.margin,
      marginTop: authStyles.marginTop,
      marginBottom: authStyles.marginBottom,
      marginLeft: authStyles.marginLeft,
      marginRight: authStyles.marginRight,
      height: authStyles.height,
      minHeight: authStyles.minHeight,
      maxHeight: authStyles.maxHeight,
      display: authStyles.display,
      flexDirection: authStyles.flexDirection,
      overflow: authStyles.overflow,
      boxSizing: authStyles.boxSizing,
      position: authStyles.position,
      background: authStyles.background
    });
    console.log('[EDIT-TREE] auth-page getBoundingClientRect:', {
      top: authRect.top,
      left: authRect.left,
      bottom: authRect.bottom,
      right: authRect.right,
      width: authRect.width,
      height: authRect.height
    });
    console.log('[EDIT-TREE] auth-page offsetTop:', authPage.offsetTop);
    console.log('[EDIT-TREE] auth-page offsetHeight:', authPage.offsetHeight);
    
    // Check for whitespace in HTML
    const authPageHTML = authPage.outerHTML.substring(0, 200);
    console.log('[EDIT-TREE] auth-page HTML start (first 200 chars):', authPageHTML);
    
    // Check first child
    const firstChild = authPage.firstElementChild;
    if (firstChild) {
      console.log('[EDIT-TREE] auth-page first child:', firstChild.tagName, firstChild.className);
      const firstChildRect = firstChild.getBoundingClientRect();
      console.log('[EDIT-TREE] auth-page first child getBoundingClientRect:', firstChildRect);
      
      // Check for text nodes (whitespace)
      const firstTextNode = authPage.firstChild;
      if (firstTextNode && firstTextNode.nodeType === 3) {
        const textContent = firstTextNode.textContent;
        console.warn('[EDIT-TREE] WARNING: Text node before first element:', JSON.stringify(textContent));
        console.warn('[EDIT-TREE] Text node length:', textContent.length);
      }
    }
  }
  
  // Log container-fluid with full details
  if (containerFluid) {
    const containerStyles = window.getComputedStyle(containerFluid);
    const containerRect = containerFluid.getBoundingClientRect();
    console.log('[EDIT-TREE] ===== container-fluid DETAILED ANALYSIS =====');
    console.log('[EDIT-TREE] container-fluid computed styles:', {
      padding: containerStyles.padding,
      paddingTop: containerStyles.paddingTop,
      paddingBottom: containerStyles.paddingBottom,
      paddingLeft: containerStyles.paddingLeft,
      paddingRight: containerStyles.paddingRight,
      margin: containerStyles.margin,
      marginTop: containerStyles.marginTop,
      marginBottom: containerStyles.marginBottom,
      marginLeft: containerStyles.marginLeft,
      marginRight: containerStyles.marginRight,
      height: containerStyles.height,
      minHeight: containerStyles.minHeight,
      maxHeight: containerStyles.maxHeight,
      display: containerStyles.display,
      flexDirection: containerStyles.flexDirection,
      overflow: containerStyles.overflow,
      boxSizing: containerStyles.boxSizing,
      alignSelf: containerStyles.alignSelf,
      flex: containerStyles.flex
    });
    console.log('[EDIT-TREE] container-fluid getBoundingClientRect:', {
      top: containerRect.top,
      left: containerRect.left,
      bottom: containerRect.bottom,
      right: containerRect.right,
      width: containerRect.width,
      height: containerRect.height
    });
    console.log('[EDIT-TREE] container-fluid offsetTop:', containerFluid.offsetTop);
    console.log('[EDIT-TREE] container-fluid offsetHeight:', containerFluid.offsetHeight);
    
    // Check for whitespace before container-fluid
    const containerHTML = containerFluid.outerHTML.substring(0, 200);
    console.log('[EDIT-TREE] container-fluid HTML start (first 200 chars):', containerHTML);
  }
  
  // Log editor-header
  if (editorHeader) {
    const headerStyles = window.getComputedStyle(editorHeader);
    const headerRect = editorHeader.getBoundingClientRect();
    console.log('[EDIT-TREE] editor-header computed styles:', {
      padding: headerStyles.padding,
      paddingTop: headerStyles.paddingTop,
      paddingBottom: headerStyles.paddingBottom,
      margin: headerStyles.margin,
      marginTop: headerStyles.marginTop,
      marginBottom: headerStyles.marginBottom,
      height: headerStyles.height,
      flex: headerStyles.flex
    });
    console.log('[EDIT-TREE] editor-header getBoundingClientRect:', headerRect);
  }
  
  // Log split-view-container
  if (splitView) {
    const splitStyles = window.getComputedStyle(splitView);
    const splitRect = splitView.getBoundingClientRect();
    console.log('[EDIT-TREE] split-view-container computed styles:', {
      padding: splitStyles.padding,
      paddingTop: splitStyles.paddingTop,
      paddingBottom: splitStyles.paddingBottom,
      margin: splitStyles.margin,
      marginTop: splitStyles.marginTop,
      marginBottom: splitStyles.marginBottom,
      height: splitStyles.height,
      minHeight: splitStyles.minHeight,
      flex: splitStyles.flex
    });
    console.log('[EDIT-TREE] split-view-container getBoundingClientRect:', splitRect);
  }
  
  // ===== GAP ANALYSIS =====
  console.log('[EDIT-TREE] ===== GAP ANALYSIS =====');
  
  // Gap between navbar and auth-page (THIS MIGHT BE THE ISSUE)
  if (navbar && authPage) {
    const navRect = navbar.getBoundingClientRect();
    const authRect = authPage.getBoundingClientRect();
    const gap = authRect.top - navRect.bottom;
    const navStyles = window.getComputedStyle(navbar);
    const authStyles = window.getComputedStyle(authPage);
    
    console.log('[EDIT-TREE] ===== NAVBAR TO AUTH-PAGE GAP ANALYSIS =====');
    console.log('[EDIT-TREE] navbar.bottom:', navRect.bottom + 'px');
    console.log('[EDIT-TREE] auth-page.top:', authRect.top + 'px');
    console.log('[EDIT-TREE] Gap (auth-page.top - navbar.bottom):', gap + 'px');
    console.log('[EDIT-TREE] navbar margin-bottom:', navStyles.marginBottom);
    console.log('[EDIT-TREE] navbar padding-bottom:', navStyles.paddingBottom);
    console.log('[EDIT-TREE] auth-page margin-top:', authStyles.marginTop);
    console.log('[EDIT-TREE] auth-page padding-top:', authStyles.paddingTop);
    console.log('[EDIT-TREE] auth-page background:', authStyles.background);
    console.log('[EDIT-TREE] auth-page background-color:', authStyles.backgroundColor);
    
    if (gap > 0) {
      console.error('[EDIT-TREE] ERROR: Gap detected between navbar and auth-page!');
      console.error('[EDIT-TREE] This gap of', gap + 'px is likely the white space you see!');
      console.error('[EDIT-TREE] Expected: auth-page should start immediately after navbar');
      
      // Check if there are any elements between navbar and auth-page
      const body = document.body;
      let node = navbar.nextSibling;
      const elementsBetween = [];
      while (node && node !== authPage) {
        if (node.nodeType === 1) { // Element node
          const rect = node.getBoundingClientRect();
          const styles = window.getComputedStyle(node);
          elementsBetween.push({
            tag: node.tagName,
            className: node.className,
            height: rect.height,
            top: rect.top,
            bottom: rect.bottom,
            background: styles.background,
            backgroundColor: styles.backgroundColor,
            display: styles.display,
            visibility: styles.visibility
          });
        } else if (node.nodeType === 3) { // Text node
          const text = node.textContent.trim();
          if (text.length > 0) {
            elementsBetween.push({
              type: 'text',
              content: JSON.stringify(text)
            });
          }
        }
        node = node.nextSibling;
      }
      
      if (elementsBetween.length > 0) {
        console.error('[EDIT-TREE] Elements found between navbar and auth-page:', elementsBetween);
      } else {
        console.log('[EDIT-TREE] No elements found between navbar and auth-page');
      }
    } else if (gap < 0) {
      console.warn('[EDIT-TREE] WARNING: Negative gap (overlap):', gap + 'px');
    } else {
      console.log('[EDIT-TREE] ✓ No gap between navbar and auth-page');
    }
  }
  
  // Gap between auth-page and container-fluid (THE MAIN ISSUE)
  if (authPage && containerFluid) {
    const authRect = authPage.getBoundingClientRect();
    const containerRect = containerFluid.getBoundingClientRect();
    const gap = containerRect.top - authRect.top;
    const gapFromAuthContent = containerRect.top - (authRect.top + parseFloat(window.getComputedStyle(authPage).paddingTop));
    console.log('[EDIT-TREE] ===== MAIN GAP ANALYSIS =====');
    console.log('[EDIT-TREE] auth-page.top:', authRect.top + 'px');
    console.log('[EDIT-TREE] container-fluid.top:', containerRect.top + 'px');
    console.log('[EDIT-TREE] Gap (container-fluid.top - auth-page.top):', gap + 'px');
    console.log('[EDIT-TREE] Gap from auth-page content area:', gapFromAuthContent + 'px');
    
    // Check if container-fluid is the first child
    const isFirstChild = authPage.firstElementChild === containerFluid;
    console.log('[EDIT-TREE] container-fluid is first element child:', isFirstChild);
    
    // Check for text nodes before container-fluid
    let textNodeBefore = null;
    let node = authPage.firstChild;
    while (node && node !== containerFluid) {
      if (node.nodeType === 3) { // Text node
        const text = node.textContent.trim();
        if (text.length > 0) {
          textNodeBefore = text;
          break;
        }
      }
      node = node.nextSibling;
    }
    if (textNodeBefore) {
      console.warn('[EDIT-TREE] WARNING: Non-empty text node found before container-fluid:', JSON.stringify(textNodeBefore));
    } else {
      console.log('[EDIT-TREE] No non-empty text nodes before container-fluid');
    }
    
    if (gap > 0) {
      console.error('[EDIT-TREE] ERROR: Gap detected between auth-page and container-fluid!');
      console.error('[EDIT-TREE] Expected gap: 0px');
      console.error('[EDIT-TREE] Actual gap:', gap + 'px');
      console.error('[EDIT-TREE] This gap is likely causing the white space issue!');
    } else if (gap < 0) {
      console.warn('[EDIT-TREE] WARNING: Negative gap (overlap):', gap + 'px');
    } else {
      console.log('[EDIT-TREE] ✓ No gap detected - elements are aligned correctly');
    }
  }
  
  // Gap between editor-header and split-view-container
  if (editorHeader && splitView) {
    const headerRect = editorHeader.getBoundingClientRect();
    const splitRect = splitView.getBoundingClientRect();
    const gap = splitRect.top - headerRect.bottom;
    console.log('[EDIT-TREE] Gap between editor-header.bottom and split-view-container.top:', gap + 'px');
    if (gap > 0) {
      console.warn('[EDIT-TREE] WARNING: Gap detected between editor-header and split-view-container!');
    }
  }
  
  // Check for any elements between auth-page and container-fluid
  if (authPage && containerFluid) {
    const children = Array.from(authPage.children);
    const containerIndex = children.indexOf(containerFluid);
    console.log('[EDIT-TREE] container-fluid child index:', containerIndex);
    if (containerIndex > 0) {
      console.warn('[EDIT-TREE] WARNING: Elements found before container-fluid:');
      for (let i = 0; i < containerIndex; i++) {
        const el = children[i];
        const rect = el.getBoundingClientRect();
        console.warn('[EDIT-TREE]   - Element', i + ':', el.tagName, el.className, 'height:', rect.height + 'px');
      }
    }
  }
  
  console.log('[EDIT-TREE] ========================================');
  console.log('[EDIT-TREE] Diagnostics complete');
  console.log('[EDIT-TREE] ========================================');
  
  // Set up MutationObserver to detect any dynamic style changes
  if (authPage && containerFluid) {
    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
          const authRect = authPage.getBoundingClientRect();
          const containerRect = containerFluid.getBoundingClientRect();
          const gap = containerRect.top - authRect.top;
          console.log('[EDIT-TREE] MutationObserver: Style changed, gap is now:', gap + 'px');
        }
      });
    });
    
    observer.observe(authPage, {
      attributes: true,
      attributeFilter: ['style', 'class']
    });
    
    observer.observe(containerFluid, {
      attributes: true,
      attributeFilter: ['style', 'class']
    });
    
    console.log('[EDIT-TREE] MutationObserver set up to monitor auth-page and container-fluid');
  }
  
  // Re-check layout after a short delay (in case styles load asynchronously)
  setTimeout(function() {
    console.log('[EDIT-TREE] ===== DELAYED CHECK (after 100ms) =====');
    if (authPage && containerFluid) {
      const authRect = authPage.getBoundingClientRect();
      const containerRect = containerFluid.getBoundingClientRect();
      const gap = containerRect.top - authRect.top;
      console.log('[EDIT-TREE] Delayed check - Gap between auth-page and container-fluid:', gap + 'px');
      
      if (gap > 0) {
        console.error('[EDIT-TREE] ERROR: Gap still present after delay:', gap + 'px');
        console.error('[EDIT-TREE] This suggests the gap is not caused by async style loading');
      }
    }
  }, 100);
  
  // Re-check layout after stylesheets load
  window.addEventListener('load', function() {
    console.log('[EDIT-TREE] ===== WINDOW LOAD EVENT (all resources loaded) =====');
    if (authPage && containerFluid) {
      const authRect = authPage.getBoundingClientRect();
      const containerRect = containerFluid.getBoundingClientRect();
      const gap = containerRect.top - authRect.top;
      console.log('[EDIT-TREE] After window load - Gap between auth-page and container-fluid:', gap + 'px');
      
      if (gap > 0) {
        console.error('[EDIT-TREE] ERROR: Gap still present after all resources loaded:', gap + 'px');
      }
    }
  });
  
  // Expose diagnostic function to window for manual debugging
  window.debugEditTreeLayout = function() {
    console.log('[EDIT-TREE] ===== MANUAL DIAGNOSTIC RUN =====');
    const navbar = document.querySelector('.navbar');
    const authPage = document.querySelector('.auth-page');
    const containerFluid = document.querySelector('.container-fluid');
    const editorHeader = document.querySelector('.editor-header');
    
    if (!authPage || !containerFluid) {
      console.error('[EDIT-TREE] ERROR: Required elements not found');
      return;
    }
    
    const navRect = navbar ? navbar.getBoundingClientRect() : null;
    const authRect = authPage.getBoundingClientRect();
    const containerRect = containerFluid.getBoundingClientRect();
    const headerRect = editorHeader ? editorHeader.getBoundingClientRect() : null;
    
    const navStyles = navbar ? window.getComputedStyle(navbar) : null;
    const authStyles = window.getComputedStyle(authPage);
    const containerStyles = window.getComputedStyle(containerFluid);
    const headerStyles = editorHeader ? window.getComputedStyle(editorHeader) : null;
    
    console.log('[EDIT-TREE] ===== ALL GAPS =====');
    if (navbar && navRect) {
      const navToAuthGap = authRect.top - navRect.bottom;
      console.log('[EDIT-TREE] Navbar to auth-page gap:', navToAuthGap + 'px');
    }
    const authToContainerGap = containerRect.top - authRect.top;
    console.log('[EDIT-TREE] Auth-page to container-fluid gap:', authToContainerGap + 'px');
    if (editorHeader && headerRect) {
      const headerToSplitGap = containerRect.top - headerRect.bottom;
      console.log('[EDIT-TREE] Editor-header to container-fluid gap:', headerToSplitGap + 'px');
    }
    
    console.log('[EDIT-TREE] ===== COMPUTED STYLES =====');
    if (navbar && navStyles) {
      console.log('[EDIT-TREE] navbar:', {
        height: navStyles.height,
        marginBottom: navStyles.marginBottom,
        paddingBottom: navStyles.paddingBottom,
        background: navStyles.background
      });
    }
    console.log('[EDIT-TREE] auth-page:', {
      paddingTop: authStyles.paddingTop,
      marginTop: authStyles.marginTop,
      paddingBottom: authStyles.paddingBottom,
      marginBottom: authStyles.marginBottom,
      background: authStyles.background,
      backgroundColor: authStyles.backgroundColor,
      height: authStyles.height
    });
    console.log('[EDIT-TREE] container-fluid:', {
      marginTop: containerStyles.marginTop,
      paddingTop: containerStyles.paddingTop,
      marginBottom: containerStyles.marginBottom,
      paddingBottom: containerStyles.paddingBottom,
      background: containerStyles.background,
      backgroundColor: containerStyles.backgroundColor
    });
    if (editorHeader && headerStyles) {
      console.log('[EDIT-TREE] editor-header:', {
        marginTop: headerStyles.marginTop,
        marginBottom: headerStyles.marginBottom,
        paddingTop: headerStyles.paddingTop,
        paddingBottom: headerStyles.paddingBottom,
        height: headerStyles.height
      });
    }
    
    console.log('[EDIT-TREE] ===== POSITIONS =====');
    if (navbar && navRect) {
      console.log('[EDIT-TREE] navbar:', { top: navRect.top, bottom: navRect.bottom, height: navRect.height });
    }
    console.log('[EDIT-TREE] auth-page:', { top: authRect.top, bottom: authRect.bottom, height: authRect.height });
    console.log('[EDIT-TREE] container-fluid:', { top: containerRect.top, bottom: containerRect.bottom, height: containerRect.height });
    if (editorHeader && headerRect) {
      console.log('[EDIT-TREE] editor-header:', { top: headerRect.top, bottom: headerRect.bottom, height: headerRect.height });
    }
    
    // Check for text nodes
    let node = authPage.firstChild;
    let textNodes = [];
    while (node) {
      if (node.nodeType === 3) {
        const text = node.textContent;
        if (text.trim().length > 0) {
          textNodes.push({
            text: JSON.stringify(text),
            length: text.length
          });
        }
      }
      if (node === containerFluid) break;
      node = node.nextSibling;
    }
    
    if (textNodes.length > 0) {
      console.warn('[EDIT-TREE] Text nodes found:', textNodes);
    } else {
      console.log('[EDIT-TREE] No text nodes found between auth-page and container-fluid');
    }
    
    return {
      navToAuthGap: navbar && navRect ? authRect.top - navRect.bottom : null,
      authToContainerGap: authToContainerGap,
      authPageTop: authRect.top,
      containerTop: containerRect.top,
      authPagePaddingTop: authStyles.paddingTop,
      authPageMarginTop: authStyles.marginTop,
      containerMarginTop: containerStyles.marginTop,
      containerPaddingTop: containerStyles.paddingTop,
      textNodes: textNodes
    };
  };
  
  console.log('[EDIT-TREE] Diagnostic function available: window.debugEditTreeLayout()');
  
  // Search functionality
  const searchInput = document.getElementById('search-input');
  if (searchInput) {
    searchInput.addEventListener('input', function(e) {
      const searchTerm = e.target.value.toLowerCase().trim();
      const personNames = document.querySelectorAll('.person-name');
      const recordCards = document.querySelectorAll('.record-card');
      
      if (searchTerm === '') {
        // Show all when search is empty
        recordCards.forEach(card => {
          card.style.display = '';
        });
        personNames.forEach(person => {
          person.classList.remove('search-highlight');
        });
      } else {
        // Filter and highlight
        let hasMatch = false;
        recordCards.forEach(card => {
          const personsInCard = card.querySelectorAll('.person-name');
          let cardHasMatch = false;
          
          personsInCard.forEach(person => {
            const personText = person.textContent.toLowerCase();
            if (personText.includes(searchTerm)) {
              person.classList.add('search-highlight');
              cardHasMatch = true;
              hasMatch = true;
            } else {
              person.classList.remove('search-highlight');
            }
          });
          
          // Show/hide card based on match
          card.style.display = cardHasMatch ? '' : 'none';
        });
      }
    });
    
    console.log('[EDIT-TREE] Search functionality initialized');
  }
  
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
  
  // Edit Record Modal functionality
  const editRecordBtn = document.getElementById('edit-record-btn');
  const editModal = document.getElementById('edit-record-modal');
  const editModalClose = document.querySelector('.edit-modal-close');
  let selectedRecordCard = null;

  // Enable/disable Edit button based on selection
  function updateEditButton() {
    const selectedCard = document.querySelector('.record-card.selected');
    if (selectedCard) {
      editRecordBtn.disabled = false;
      selectedRecordCard = selectedCard;
    } else {
      editRecordBtn.disabled = true;
      selectedRecordCard = null;
    }
  }

  // Single delegated click on #record-view: person-name -> sync graph; card -> select for Edit modal
  const recordView = document.getElementById('record-view');
  function logToServer(message, context = '') {
    try {
      fetch('/api/debug-log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message, context })
      }).catch(() => {});
    } catch (e) {
      // ignore
    }
  }
  if (recordView) {
    recordView.addEventListener('click', function(e) {
      const personName = e.target.closest('.person-name');
      const card = e.target.closest('.record-card');
      if (personName) {
        const graphSeqNum = parseInt(personName.getAttribute('data-graph-seqnum'));
        if (graphSeqNum) {
          highlightPersonInTiles(graphSeqNum);
          highlightPersonInGraph(graphSeqNum);
          logToServer('EDIT-TREE: tile person click', `record_id=${card?.getAttribute('data-record-id') || ''} graphSeqNum=${graphSeqNum}`);
        }
        if (card) {
          document.querySelectorAll('.record-card').forEach(c => c.classList.remove('selected'));
          card.classList.add('selected');
          selectedRecordCard = card;
          updateEditButton();
        }
      } else if (card) {
        document.querySelectorAll('.record-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        selectedRecordCard = card;
        updateEditButton();
        logToServer('EDIT-TREE: tile card selected', `record_id=${card.getAttribute('data-record-id') || ''}`);
      }
    });
  }

  // Open modal when Edit button is clicked
  if (editRecordBtn && editModal) {
    editRecordBtn.addEventListener('click', function() {
      if (!selectedRecordCard) return;
      loadRecordDataIntoModal(selectedRecordCard);
      editModal.style.display = 'flex';
      try {
        const recordId = document.getElementById('edit-record-id')?.value || '';
        const man = document.getElementById('edit-man-input')?.value || '';
        const woman = document.getElementById('edit-woman-input')?.value || '';
        const children = Array.from(document.querySelectorAll('.child-input')).map(i => i.value || '');
        logToServer('EDIT-TREE: modal opened', JSON.stringify({ recordId, man, woman, children }));
      } catch (e) {}
    });
  }

  // Close modal
  if (editModalClose) {
    editModalClose.addEventListener('click', function() {
      editModal.style.display = 'none';
    });
  }

  // Close modal when clicking outside
  if (editModal) {
    editModal.addEventListener('click', function(e) {
      if (e.target === editModal) {
        editModal.style.display = 'none';
      }
    });
  }

  // Load record data from card into modal inputs
  function loadRecordDataIntoModal(card) {
    const recordId = card.getAttribute('data-record-id');
    document.getElementById('edit-record-id').value = recordId;

    // Get man, woman, children from card
    const manRow = card.querySelector('.father-row .person-name');
    const womanRow = card.querySelector('.mother-row .person-name');
    const childrenRows = card.querySelectorAll('.children-list .child-row .person-name');

    // Extract text (name + dates) from person-name elements
    // Remove badge numbers (green or gray) at the start
    function extractPersonText(personElement) {
      if (!personElement) return '';
      let text = personElement.textContent.trim();
      // Remove badge number at start (e.g. "7 Name..." or " 7 Name...")
      // Badge can be green (seqNum) or gray (firstSeqNum), both are numbers
      text = text.replace(/^\s*\d+\s+/, '');
      return text;
    }

    const manText = extractPersonText(manRow);
    const womanText = extractPersonText(womanRow);

    document.getElementById('edit-man-input').value = manText;
    document.getElementById('edit-woman-input').value = womanText;

    // Clear children container
    const childrenContainer = document.getElementById('edit-children-container');
    childrenContainer.innerHTML = '';

    // Add existing children + one extra empty input
    childrenRows.forEach((childRow, index) => {
      const childText = extractPersonText(childRow);
      addChildInput(childText);
    });
    // Add one extra empty child input
    addChildInput('');
  }

  // Add child input field
  function addChildInput(value = '') {
    const container = document.getElementById('edit-children-container');
    const div = document.createElement('div');
    div.className = 'child-input-group';
    div.innerHTML = `
      <input type="text" class="edit-input child-input" placeholder="+Dieťa" value="${value}">
      <button type="button" class="remove-child-btn">Odstrániť</button>
    `;
    div.querySelector('.remove-child-btn').addEventListener('click', function() {
      div.remove();
    });
    container.appendChild(div);
  }

  // Add child button
  document.getElementById('add-child-btn')?.addEventListener('click', function() {
    addChildInput('');
  });

  // Modal action buttons
  const treeId = <?= $treeId ?>;

  // Ulož button
  document.getElementById('save-record-btn')?.addEventListener('click', function() {
    const recordId = document.getElementById('edit-record-id').value;
    const manText = document.getElementById('edit-man-input').value.trim();
    const womanText = document.getElementById('edit-woman-input').value.trim();
    const childInputs = document.querySelectorAll('.child-input');
    const childrenTexts = Array.from(childInputs).map(input => input.value.trim()).filter(v => v);
    logToServer('EDIT-TREE: modal save click', JSON.stringify({ recordId, manText, womanText, childrenTexts }));

    const formData = new URLSearchParams({
      action: 'save_record',
      tree_id: treeId,
      record_id: recordId,
      man: manText,
      woman: womanText
    });

    childrenTexts.forEach((text, index) => {
      formData.append('children[]', text);
    });

    fetch('/tree-actions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        alert(data.message);
        window.location.reload();
      } else {
        alert('Chyba: ' + data.message);
      }
    })
    .catch(err => {
      console.error('Error:', err);
      alert('Chyba komunikácie so serverom.');
    });
  });

  // Initialize edit button state
  updateEditButton();

  // Synchronization between tiles and graph
  const iframe = document.querySelector('#tree-view iframe');
  
  // Function to highlight person in tiles
  function highlightPersonInTiles(seqNum) {
    // Remove previous selection
    document.querySelectorAll('.person-name.selected').forEach(el => {
      el.classList.remove('selected');
    });
    document.querySelectorAll('.record-card.selected').forEach(el => {
      el.classList.remove('selected');
    });
    
    // Find and highlight the person
    const personElement = document.querySelector(`.person-name[data-seqnum="${seqNum}"]`);
    if (personElement) {
      personElement.classList.add('selected');
      
      // Scroll to the element in the left pane
      const leftPane = document.querySelector('.left-pane');
      if (leftPane) {
        const elementTop = personElement.getBoundingClientRect().top;
        const paneTop = leftPane.getBoundingClientRect().top;
        const scrollTop = leftPane.scrollTop;
        const targetScroll = scrollTop + (elementTop - paneTop) - (leftPane.clientHeight / 2);
        
        leftPane.scrollTo({
          top: Math.max(0, targetScroll),
          behavior: 'smooth'
        });
      }
      
      // Also highlight the parent record card
      const recordCard = personElement.closest('.record-card');
      if (recordCard) {
        recordCard.classList.add('selected');
        setTimeout(() => {
          recordCard.classList.remove('selected');
        }, 2000);
      }
    }
  }
  
  // Function to highlight person in graph (iframe)
  function highlightPersonInGraph(seqNum) {
    if (!iframe) return;
    
    // Wait for iframe to be ready
    const tryHighlight = () => {
      try {
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        if (!iframeDoc || !iframeDoc.getElementById('tree-svg')) {
          // Iframe not ready yet, try again
          setTimeout(tryHighlight, 100);
          return;
        }
        
        const personBox = iframeDoc.querySelector(`.person-box[data-seqnum="${seqNum}"]`);
        
        if (personBox) {
          // Remove previous selection
          iframeDoc.querySelectorAll('.person-box.selected').forEach(el => {
            el.classList.remove('selected');
          });
          
          // Add selection
          personBox.classList.add('selected');
          
          // Scroll to the element in iframe
          const svg = iframeDoc.getElementById('tree-svg');
          const wrapper = iframeDoc.getElementById('tree-wrapper');
          
          if (svg && wrapper) {
            const transform = personBox.getAttribute('transform');
            const match = transform.match(/translate\(([^,]+),\s*([^)]+)\)/);
            if (match) {
              const x = parseFloat(match[1]);
              const y = parseFloat(match[2]);
              
              // Get box height for centering
              const boxHeight = 30; // CONFIG.boxHeight
              
              // Calculate scroll position - center the element
              const scrollX = x - (wrapper.clientWidth / 2);
              const scrollY = y - (wrapper.clientHeight / 2) + (boxHeight / 2);
              
              wrapper.scrollTo({
                left: Math.max(0, scrollX),
                top: Math.max(0, scrollY),
                behavior: 'smooth'
              });
            }
          }
        }
      } catch (e) {
        console.error('[EDIT-TREE] Error highlighting in graph:', e);
      }
    };
    
    tryHighlight();
  }
  
  // Listen for messages from iframe (graph clicks)
  window.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'selectPerson') {
      const seqNum = event.data.seqNum;
      console.log('[EDIT-TREE] Received selectPerson message, seqNum:', seqNum);
      highlightPersonInTiles(seqNum);
    }
  });
  
  // Cursor for person names (click handler is above, single delegation on recordView)
  if (recordView) {
    document.querySelectorAll('.person-name').forEach(personName => {
      personName.style.cursor = 'pointer';
    });
  }
  
  // Wait for iframe to load and initialize synchronization
  if (iframe) {
    iframe.addEventListener('load', function() {
      console.log('[EDIT-TREE] Iframe loaded, synchronization ready');
      
      // Update cursor for person names (event delegation already handles clicks)
      setTimeout(() => {
        document.querySelectorAll('.person-name').forEach(personName => {
          personName.style.cursor = 'pointer';
        });
      }, 500);
    });
  }
  
  // Export PDF functionality for edit-tree.php
  const exportPdfBtn = document.getElementById('export-pdf-btn');
  console.log('[EDIT-TREE] Export PDF button found:', exportPdfBtn !== null);
  
  if (exportPdfBtn) {
    console.log('[EDIT-TREE] Export PDF button exists, adding event listener');
    exportPdfBtn.addEventListener('click', function() {
      console.log('[EDIT-TREE] Export PDF button clicked');
      
      // Get the iframe with the tree view
      const iframe = document.querySelector('#tree-view iframe');
      if (!iframe) {
        console.error('[EDIT-TREE] Tree view iframe not found');
        alert('Graf nie je ešte načítaný.');
        return;
      }
      
      const btn = this;
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Exportujem...';
      
      try {
        // Access iframe content
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        const svg = iframeDoc.getElementById('tree-svg');
        
        if (!svg) {
          console.error('[EDIT-TREE] SVG not found in iframe');
          alert('Graf nie je ešte načítaný v iframe.');
          btn.disabled = false;
          btn.textContent = originalText;
          return;
        }
        
        console.log('[EDIT-TREE] SVG found, starting export');
        
        // Clone SVG to avoid modifying original
        const svgClone = svg.cloneNode(true);
        
        // Function to inline styles into SVG elements
        function inlineStyles(element) {
          // Apply class-based styles inline
          if (element.classList) {
            if (element.classList.contains('person-rect')) {
              if (element.classList.contains('male')) {
                element.setAttribute('fill', '#e6f7ff');
                element.setAttribute('stroke', '#91d5ff');
                element.setAttribute('stroke-width', '1');
              } else if (element.classList.contains('female')) {
                element.setAttribute('fill', '#fff0f6');
                element.setAttribute('stroke', '#ffadd2');
                element.setAttribute('stroke-width', '1');
              }
            } else if (element.classList.contains('id-badge')) {
              element.setAttribute('fill', '#10b981');
            } else if (element.classList.contains('id-text')) {
              element.setAttribute('fill', 'white');
              element.setAttribute('font-size', '11px');
              element.setAttribute('font-family', 'monospace');
              element.setAttribute('font-weight', 'bold');
            } else if (element.classList.contains('person-text')) {
              element.setAttribute('fill', '#333');
              element.setAttribute('font-size', '14px');
              element.setAttribute('font-family', 'Segoe UI, sans-serif');
            } else if (element.classList.contains('grid-text')) {
              element.setAttribute('fill', '#aaa');
              element.setAttribute('font-size', '14px');
            } else if (element.classList.contains('spouse-line')) {
              element.setAttribute('stroke', '#f5222d');
              element.setAttribute('stroke-dasharray', '4');
              element.setAttribute('fill', 'none');
              element.setAttribute('stroke-width', '1.5');
            } else if (element.classList.contains('child-line')) {
              element.setAttribute('stroke', '#1890ff');
              element.setAttribute('fill', 'none');
              element.setAttribute('stroke-width', '1.5');
            } else if (element.classList.contains('connection-line')) {
              element.setAttribute('stroke', '#1890ff');
              element.setAttribute('fill', 'none');
              element.setAttribute('stroke-width', '1.5');
              element.setAttribute('stroke-opacity', '0.4');
            } else if (element.classList.contains('grid-line')) {
              element.setAttribute('stroke', '#eee');
              element.setAttribute('stroke-width', '1');
            }
          }
          
          // Recursively process children
          Array.from(element.children).forEach(child => {
            if (child.nodeType === 1) { // Element node
              inlineStyles(child);
            }
          });
        }
        
        // Inline all styles
        inlineStyles(svgClone);
        
        // Add style tag with all CSS rules to SVG
        const styleTag = iframeDoc.createElementNS('http://www.w3.org/2000/svg', 'style');
        styleTag.textContent = `
          .person-rect.male { fill: #e6f7ff; stroke: #91d5ff; stroke-width: 1; }
          .person-rect.female { fill: #fff0f6; stroke: #ffadd2; stroke-width: 1; }
          .id-badge { fill: #10b981; }
          .id-text { fill: white; font-size: 11px; font-family: monospace; font-weight: bold; }
          .person-text { fill: #333; font-size: 14px; font-family: 'Segoe UI', sans-serif; }
          .grid-text { fill: #aaa; font-size: 14px; font-family: sans-serif; }
          .spouse-line { stroke: #f5222d; stroke-dasharray: 4; fill: none; stroke-width: 1.5; }
          .child-line { stroke: #1890ff; fill: none; stroke-width: 1.5; }
          .connection-line { stroke: #1890ff; fill: none; stroke-width: 1.5; stroke-opacity: 0.4; }
          .grid-line { stroke: #eee; stroke-width: 1; }
        `;
        svgClone.insertBefore(styleTag, svgClone.firstChild);
        
        // Get SVG dimensions and panel width for PDF (include QR panel)
        const svgWidth = parseFloat(svg.getAttribute('width')) || svg.getBBox().width;
        const svgHeight = parseFloat(svg.getAttribute('height')) || svg.getBBox().height;
        const panelWidth = 220;
        const scale = 2;
        const totalWidth = (svgWidth + panelWidth) * scale;
        const totalHeight = svgHeight * scale;
        
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = totalWidth;
        canvas.height = totalHeight;
        
        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        const svgData = new XMLSerializer().serializeToString(svgClone);
        const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(svgBlob);
        const img = new Image();
        
        const infoPanel = iframeDoc.getElementById('info-panel');
        const treeName = (infoPanel && infoPanel.dataset && infoPanel.dataset.treeName) ? infoPanel.dataset.treeName : '';
        const treeCreated = (infoPanel && infoPanel.dataset && infoPanel.dataset.treeCreated) ? infoPanel.dataset.treeCreated : '';
        
        function drawPanelBackgroundAndLabels() {
          const panelLeft = svgWidth * scale;
          const pw = panelWidth * scale;
          ctx.fillStyle = '#fff';
          ctx.fillRect(panelLeft, 0, pw, totalHeight);
          ctx.strokeStyle = '#ddd';
          ctx.lineWidth = 1;
          ctx.strokeRect(panelLeft, 0, pw, totalHeight);
          ctx.fillStyle = '#666';
          ctx.font = 'bold ' + (12 * scale) + 'px "Segoe UI", sans-serif';
          ctx.fillText('Rodokmeň: ' + treeName, panelLeft + 16 * scale, 28 * scale);
          ctx.fillText('Vytvorený: ' + treeCreated, panelLeft + 16 * scale, 52 * scale);
        }
        function drawCopyrightAndFinish() {
          const panelLeft = svgWidth * scale;
          ctx.fillStyle = '#999';
          ctx.font = (14 * scale) + 'px "Segoe UI", sans-serif';
          ctx.fillText('© Family-tree.cz (<?= date('Y') ?>)', panelLeft + 16 * scale, totalHeight - 20 * scale);
          try {
            if (typeof window.jspdf !== 'undefined' && window.jspdf.jsPDF) {
              const { jsPDF } = window.jspdf;
              const pdf = new jsPDF({
                orientation: totalWidth > totalHeight ? 'landscape' : 'portrait',
                unit: 'px',
                format: [totalWidth, totalHeight]
              });
              pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, totalWidth, totalHeight);
              pdf.save('rodokmen_<?= $treeId ?>_<?= date('Y-m-d') ?>.pdf');
              console.log('[EDIT-TREE] PDF exported successfully');
            } else {
              canvas.toBlob(function(blob) {
                if (blob) {
                  const downloadUrl = URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = downloadUrl;
                  a.download = 'rodokmen_<?= $treeId ?>_<?= date('Y-m-d') ?>.png';
                  document.body.appendChild(a);
                  a.click();
                  document.body.removeChild(a);
                  URL.revokeObjectURL(downloadUrl);
                }
              }, 'image/png');
            }
          } catch (e) {
            console.error('[EDIT-TREE] Export error:', e);
            alert('Chyba pri exporte: ' + e.message);
          }
          URL.revokeObjectURL(url);
          btn.disabled = false;
          btn.textContent = originalText;
        }
        function tryDrawQrThenFinish() {
          drawPanelBackgroundAndLabels();
          const viewUrl = iframe.contentWindow ? iframe.contentWindow.location.href : '';
          const qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent(viewUrl);
          const qrImg = new Image();
          qrImg.crossOrigin = 'anonymous';
          qrImg.onload = function() {
            try {
              const qrSize = 120 * scale;
              const panelLeft = svgWidth * scale;
              const qrX = panelLeft + (panelWidth * scale - qrSize) / 2;
              ctx.drawImage(qrImg, qrX, 70 * scale, qrSize, qrSize);
            } catch (e) {}
            drawCopyrightAndFinish();
          };
          qrImg.onerror = function() { drawCopyrightAndFinish(); };
          qrImg.src = qrApiUrl;
        }
        
        img.onload = function() {
          try {
            ctx.drawImage(img, 0, 0, svgWidth * scale, svgHeight * scale);
            tryDrawQrThenFinish();
          } catch (e) {
            console.error('[EDIT-TREE] Export error:', e);
            alert('Chyba pri exporte: ' + e.message);
            URL.revokeObjectURL(url);
            btn.disabled = false;
            btn.textContent = originalText;
          }
        };
        
        img.onerror = function() {
          console.error('[EDIT-TREE] Image load error');
          alert('Chyba pri načítaní obrázka. Skúste použiť iný prehliadač.');
          URL.revokeObjectURL(url);
          btn.disabled = false;
          btn.textContent = originalText;
        };
        
        img.src = url;
        
      } catch (e) {
        console.error('[EDIT-TREE] Export error:', e);
        alert('Chyba pri exporte: ' + e.message);
        btn.disabled = false;
        btn.textContent = originalText;
      }
    });
  } else {
    console.error('[EDIT-TREE] Export PDF button NOT FOUND in DOM');
  }
});
</script>

<!-- Include jsPDF library for PDF export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<?php
render_footer();
