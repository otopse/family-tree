<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/_layout.php';

// Debug log helper
$debugLog = __DIR__ . '/gedcom_debug.log';

// Initialize log file - clear it at the start of each edit-tree.php page load
// This ensures we start with a fresh log for each editing session
file_put_contents($debugLog, date('Y-m-d H:i:s') . " [edit-tree] === EDIT-TREE START (log initialized/cleared) ===\n");

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

  return '<span class="person-name" data-seqnum="' . $seqNum . '" data-element-id="' . ($el['id'] ?? '') . '"><span class="seq-badge">' . $seqNum . '</span> ' . $name . ' ' . $dateStr . '</span>';
}

render_header('Editovať rodokmeň: ' . e($tree['tree_name']));
?>

<div class="container-fluid">
  <div class="editor-header">
    <div style="display: flex; align-items: center; gap: 16px;">
      <a href="/family-trees.php" class="btn-secondary" style="padding: 6px 12px;">← Späť</a>
      <h1 style="margin: 0; font-size: 1.5rem;"><?= e($tree['tree_name']) ?> <span style="font-weight: normal; font-size: 1rem; color: var(--text-secondary);">(Editor)</span></h1>
      <button id="export-pdf-btn" class="btn-primary" style="padding: 6px 12px; margin-left: auto;">Export PDF</button>
    </div>
  </div>
  
  <?php
  debugLog("Editor header rendered. Export PDF button should be present.");
  debugLog("Records processed: " . count($viewData));
  debugLog("=== EDIT-TREE PHP DONE ===");
  ?>
    
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
    margin: 0;
    padding: 0;
  }
  
  /* Override auth-page padding for edit-tree */
  .auth-page {
    padding: 0 !important;
    margin: 0 !important;
    margin-top: 72px !important; /* Push down by navbar height (72px) */
    height: calc(100vh - 72px) !important; /* Subtract navbar height */
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
  }
  
  /* Hide footer on edit-tree page */
  .footer {
    display: none !important;
  }

  .container-fluid {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 0 !important;
    margin: 0 !important;
    min-height: 0; /* Important for flex children */
    overflow: hidden;
    height: 100%;
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
    height: 100%;
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
    font-size: 13px;
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
    font-size: 13px;
    margin-right: 4px;
    vertical-align: middle;
    font-weight: bold;
    min-width: 20px;
    display: inline-block;
    text-align: center;
  }
  
  .person-name {
    transition: background-color 0.2s;
    display: inline-block;
    padding: 2px 4px;
    border-radius: 4px;
    margin: 1px 0;
  }
  
  .person-name:hover {
    background-color: #f0f0f0;
  }
  
  .person-name.selected {
    background-color: #ffffb8;
    box-shadow: 0 0 0 2px #1890ff;
  }
  
  .record-card.selected {
    box-shadow: 0 0 0 3px #1890ff;
    transition: box-shadow 0.2s;
    border-color: #1890ff;
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
  
  // Add click handlers to person names in tiles using event delegation
  const recordView = document.getElementById('record-view');
  if (recordView) {
    recordView.addEventListener('click', function(e) {
      const personName = e.target.closest('.person-name');
      if (personName) {
        const seqNum = parseInt(personName.getAttribute('data-seqnum'));
        if (seqNum) {
          console.log('[EDIT-TREE] Person clicked in tile, seqNum:', seqNum);
          highlightPersonInTiles(seqNum);
          highlightPersonInGraph(seqNum);
        }
      }
    });
    
    // Set cursor for all person names
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
        
        // Get SVG dimensions
        const svgWidth = parseFloat(svg.getAttribute('width')) || svg.getBBox().width;
        const svgHeight = parseFloat(svg.getAttribute('height')) || svg.getBBox().height;
        
        // Create canvas for conversion
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const scale = 2; // For better quality
        canvas.width = svgWidth * scale;
        canvas.height = svgHeight * scale;
        
        // Fill white background
        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        // Convert SVG to image with inline styles
        const svgData = new XMLSerializer().serializeToString(svgClone);
        const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(svgBlob);
        
        const img = new Image();
        
        img.onload = function() {
          try {
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            
            // Try to use jsPDF if available
            if (typeof window.jspdf !== 'undefined' && window.jspdf.jsPDF) {
              const { jsPDF } = window.jspdf;
              const pdf = new jsPDF({
                orientation: canvas.width > canvas.height ? 'landscape' : 'portrait',
                unit: 'px',
                format: [canvas.width, canvas.height]
              });
              
              pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, canvas.width, canvas.height);
              pdf.save('rodokmen_<?= $treeId ?>_<?= date('Y-m-d') ?>.pdf');
              console.log('[EDIT-TREE] PDF exported successfully');
            } else {
              console.log('[EDIT-TREE] jsPDF not available, falling back to PNG');
              // Fallback: download as PNG
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
          } finally {
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
