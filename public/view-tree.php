<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

// Debug log helper
$debugLog = __DIR__ . '/gedcom_debug.log';
function debugLog(string $msg): void {
    global $debugLog;
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " [view-tree] " . $msg . "\n", FILE_APPEND);
}

debugLog("=== VIEW-TREE START ===");

$user = require_login();
$treeId = (int)($_GET['id'] ?? 0);
debugLog("Tree ID: $treeId, User ID: " . ($user['id'] ?? 'null'));

if (!$treeId) {
    flash('error', 'Neznámy rodokmeň.');
    redirect('/family-trees.php');
}

// Verify ownership
$stmt = db()->prepare('SELECT * FROM family_trees WHERE id = :id AND owner = :owner');
$stmt->execute(['id' => $treeId, 'owner' => $user['id']]);
$tree = $stmt->fetch();

if (!$tree) {
    flash('error', 'Rodokmeň neexistuje alebo k nemu nemáte prístup.');
    redirect('/family-trees.php');
}

// Fetch all records and elements
$stmt = db()->prepare('
    SELECT r.id as record_id, r.record_name, e.id as element_id, e.type, e.full_name, e.gedcom_id, e.gender, e.birth_date, e.death_date, e.birth_place, e.death_place
    FROM ft_records r
    JOIN ft_elements e ON r.id = e.record_id
    WHERE r.tree_id = :tree_id
    ORDER BY r.id, e.sort_order
');
$stmt->execute(['tree_id' => $treeId]);
$rows = $stmt->fetchAll();
debugLog("Fetched rows count: " . count($rows));

$individuals = [];
$families = [];
$parentMap = [];

// Helper to extract year
function extractYear(?string $date): ?int {
    if (!$date) return null;
    // Remove brackets for fictional dates
    $date = str_replace(['[', ']'], '', $date);
    if (preg_match('/(\d{4})/', $date, $matches)) {
        return (int)$matches[1];
    }
    return null;
}

// Helper to format date for display (same as edit-tree.php)
function formatDateForDisplay(?string $val): string {
    if (empty($val)) return '';
    $val = trim($val);
    
    // If it's a fictional date in brackets, return as is
    if (strpos($val, '[') === 0) return $val;

    if (preg_match('/^\d{4}$/', $val)) {
        return $val;
    }
    $ts = strtotime($val);
    if (!$ts) return $val;
    return date('Y.m.d', $ts);
}

// First pass: collect all elements by record, maintaining order
$recordElements = [];
foreach ($rows as $row) {
    $famId = $row['record_id'];
    if (!isset($recordElements[$famId])) {
        $recordElements[$famId] = ['man' => null, 'woman' => null, 'children' => []];
    }
    
    if ($row['type'] === 'MUZ') {
        $recordElements[$famId]['man'] = $row;
    } elseif ($row['type'] === 'ZENA') {
        $recordElements[$famId]['woman'] = $row;
    } elseif ($row['type'] === 'DIETA') {
        $recordElements[$famId]['children'][] = $row;
    }
}

// Sort records by man's birth year (same as edit-tree.php)
$sortedRecordIds = array_keys($recordElements);
usort($sortedRecordIds, function($a, $b) use ($recordElements) {
    $manA = $recordElements[$a]['man'];
    $manB = $recordElements[$b]['man'];
    $womanA = $recordElements[$a]['woman'];
    $womanB = $recordElements[$b]['woman'];
    
    $yearA = $manA ? extractYear($manA['birth_date']) : ($womanA ? extractYear($womanA['birth_date']) : 9999);
    $yearB = $manB ? extractYear($manB['birth_date']) : ($womanB ? extractYear($womanB['birth_date']) : 9999);
    
    return ($yearA ?? 9999) <=> ($yearB ?? 9999);
});

// Build ordered list of all persons with sequential numbers
$orderedPersons = [];
$seqNum = 1;

debugLog("Sorted record IDs count: " . count($sortedRecordIds));

foreach ($sortedRecordIds as $recordId) {
    $rec = $recordElements[$recordId];
    
    if ($rec['man']) {
        $rec['man']['seqNum'] = $seqNum++;
        $orderedPersons[] = $rec['man'];
    }
    if ($rec['woman']) {
        $rec['woman']['seqNum'] = $seqNum++;
        $orderedPersons[] = $rec['woman'];
    }
    foreach ($rec['children'] as $child) {
        $child['seqNum'] = $seqNum++;
        $orderedPersons[] = $child;
    }
}

debugLog("Ordered persons count: " . count($orderedPersons));

// Now build individuals and families from ordered persons
foreach ($orderedPersons as $row) {
    $famId = $row['record_id'];
    
    // Process Individual - use element_id as unique key if no gedcom_id
    $personKey = $row['gedcom_id'] ?: 'el_' . $row['element_id'];
    
    if (!isset($individuals[$personKey])) {
        $bYear = extractYear($row['birth_date']);
        $dYear = extractYear($row['death_date']);

        // Fix: Ignore death date if it matches today (default value error)
        if ($row['death_date'] === date('Y-m-d')) {
             $dYear = null;
             $row['death_date'] = null;
        }
        
        // Check if birth date is fictional
        $isFictional = strpos($row['birth_date'] ?? '', '[') === 0;
        
        // Sanity check/Defaults for display
        if (!$bYear && $dYear) $bYear = $dYear - 60;
        if (!$bYear) $bYear = 1900; // Fallback
        if (!$dYear) $dYear = $bYear + 70; // Assumed lifespan if unknown
        
        // Format display text to match edit-tree.php
        $birthStr = formatDateForDisplay($row['birth_date']);
        $deathStr = '';
        if (!empty($row['death_date']) && $row['death_date'] !== date('Y-m-d')) {
            $deathStr = formatDateForDisplay($row['death_date']);
        }
        
        $dateStr = '';
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
        
        $individuals[$personKey] = [
            'id' => $personKey,
            'name' => $row['full_name'],
            'gender' => $row['gender'],
            'birthYear' => $bYear,
            'deathYear' => $dYear,
            'birthDate' => $row['birth_date'],
            'deathDate' => $row['death_date'],
            'birthPlace' => $row['birth_place'],
            'deathPlace' => $row['death_place'],
            'seqNum' => $row['seqNum'],
            'dateStr' => $dateStr,
            'recordIds' => []
        ];
    }

    // Add this record ID to the individual's list
    if (!in_array($famId, $individuals[$personKey]['recordIds'])) {
        $individuals[$personKey]['recordIds'][] = $famId;
    }

    // Build Family links
    if (!isset($families[$famId])) {
        $families[$famId] = ['husb' => null, 'wife' => null, 'children' => []];
    }

    if ($row['type'] === 'MUZ') {
        $families[$famId]['husb'] = $personKey;
    } elseif ($row['type'] === 'ZENA') {
        $families[$famId]['wife'] = $personKey;
    } elseif ($row['type'] === 'DIETA') {
        $families[$famId]['children'][] = $personKey;
        $parentMap[$personKey] = $famId;
    }
}

// Filter families to only those with connections
$cleanFamilies = [];
foreach ($families as $id => $fam) {
    if (($fam['husb'] || $fam['wife']) && !empty($fam['children'])) {
        $cleanFamilies[] = $fam;
    }
}

debugLog("Individuals count: " . count($individuals));
debugLog("Families count: " . count($families));
debugLog("Clean families count: " . count($cleanFamilies));

// Log first few individuals for debugging
$i = 0;
foreach ($individuals as $key => $ind) {
    if ($i++ < 5) {
        debugLog("Individual [$key]: seqNum={$ind['seqNum']}, name={$ind['name']}, birthYear={$ind['birthYear']}");
    }
}

// Log JSON encode test
$testIndividuals = json_encode(array_values($individuals));
$testFamilies = json_encode($cleanFamilies);
debugLog("JSON individuals length: " . ($testIndividuals === false ? "ENCODE ERROR: " . json_last_error_msg() : strlen($testIndividuals)));
debugLog("JSON families length: " . ($testFamilies === false ? "ENCODE ERROR: " . json_last_error_msg() : strlen($testFamilies)));

debugLog("=== VIEW-TREE PHP DONE ===");

// Check if embedded mode
$isEmbed = !empty($_GET['embed']);

if ($isEmbed) {
    // Minimal layout for embed
    ?>
    <!DOCTYPE html>
    <html lang="sk">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Tree Embed</title>
        <style>
             body { margin: 0; overflow: hidden; font-family: sans-serif; }
            #tree-wrapper { width: 100vw; height: 100vh; overflow: auto; background: #fff; position: relative; }
            #tree-svg { display: block; }
            /* Copy essential styles */
            .person-box { cursor: pointer; filter: drop-shadow(2px 2px 2px rgba(0,0,0,0.1)); }
            .person-box:hover { filter: drop-shadow(3px 3px 5px rgba(0,0,0,0.3)); }
            .person-rect { stroke: #999; stroke-width: 1; }
            .person-rect.male { fill: #e6f7ff; stroke: #91d5ff; }
            .person-rect.female { fill: #fff0f6; stroke: #ffadd2; }
            .person-text { font-family: 'Segoe UI', sans-serif; font-size: 11px; fill: #333; }
            .spouse-line { stroke: #f5222d; stroke-dasharray: 4; }
            .child-line { stroke: #1890ff; }
            .person-rect.selected { stroke: #1890ff; stroke-width: 3; fill: #ffffb8 !important; }
            .id-badge { fill: #10b981; }
            .id-text { fill: white; font-size: 9px; font-family: monospace; font-weight: bold; text-anchor: middle; dominant-baseline: central; }
            .connection-line { fill: none; stroke: #1890ff; stroke-width: 1.5; stroke-opacity: 0.4; }
            .grid-line { stroke: #eee; stroke-width: 1; }
            .grid-text { fill: #aaa; font-size: 12px; font-family: sans-serif; }
        </style>
    </head>
    <body>
        <div id="tree-wrapper">
            <div id="loading" style="padding: 20px;">Načítavam graf...</div>
        </div>
        <!-- Hidden diagnostics for embed -->
        <div id="diagnostics" style="display:none;"></div>
    </body>
    </html>
    <?php
} else {
    // Normal full page layout
    render_header('Zobrazenie rodokmeňa: ' . e($tree['tree_name']));
    ?>
    <style>
        /* ... existing full page styles ... */
        body { margin: 0; overflow: hidden; }
        #tree-wrapper { width: 100vw; height: calc(100vh - 60px); overflow: auto; background: #fdfdfd; border-top: 1px solid #ddd; }
        /* ... styles ... */
        /* Include the styles from previous version here or reference a css file */
            .person-box { cursor: pointer; filter: drop-shadow(2px 2px 2px rgba(0,0,0,0.1)); }
            .person-box:hover { filter: drop-shadow(3px 3px 5px rgba(0,0,0,0.3)); }
            .person-rect { stroke: #999; stroke-width: 1; }
            .person-rect.male { fill: #e6f7ff; stroke: #91d5ff; }
            .person-rect.female { fill: #fff0f6; stroke: #ffadd2; }
            .person-text { font-family: 'Segoe UI', sans-serif; font-size: 11px; fill: #333; }
            .spouse-line { stroke: #f5222d; stroke-dasharray: 4; }
            .child-line { stroke: #1890ff; }
            .person-rect.selected { stroke: #1890ff; stroke-width: 3; fill: #ffffb8 !important; }
            .id-badge { fill: #10b981; }
            .id-text { fill: white; font-size: 9px; font-family: monospace; font-weight: bold; text-anchor: middle; dominant-baseline: central; }
            .connection-line { fill: none; stroke: #1890ff; stroke-width: 1.5; stroke-opacity: 0.4; }
            .grid-line { stroke: #eee; stroke-width: 1; }
            .grid-text { fill: #aaa; font-size: 12px; font-family: sans-serif; }
    </style>
    
    <div class="container-fluid p-0">
        <div class="d-flex justify-content-between align-items-center p-3 bg-white border-bottom">
            <h4 class="m-0"><?= e($tree['tree_name']) ?></h4>
            <div>
                <a href="/family-trees.php" class="btn btn-sm btn-outline-secondary">Späť</a>
            </div>
        </div>
        
        <div id="tree-wrapper">
            <div id="loading" style="padding: 20px;">Načítavam graf...</div>
        </div>
        <div id="diagnostics" style="padding: 10px; border-top: 1px solid #ccc; background: #eee; height: 150px; overflow: auto; font-family: monospace; font-size: 12px;">
            <strong>Diagnostika:</strong><br>
        </div>
    </div>
    <?php
}
?>

<script>
    function log(msg) {
        const d = document.getElementById('diagnostics');
        if (d) {
            d.innerHTML += msg + '<br>';
            d.scrollTop = d.scrollHeight;
        }
        console.log(msg);
    }

    // ... (rest of the script logic)
    // We need to output the PHP data into JS variables
    const individuals = <?= json_encode(array_values($individuals)) ?>;
    const families = <?= json_encode($cleanFamilies) ?>;

    // ... (rest of JS functions: initTree, etc) ...
    // Copying the full JS logic here
    
    window.onerror = function(msg, url, line, col, error) {
        log(`ERROR: ${msg} at ${line}:${col}`);
        return false;
    };

    const CONFIG = {
        pixelsPerYear: 5,
        rowHeight: 45,
        boxHeight: 30,
        minYear: 1800,
        maxYear: 2050,
        paddingX: 50,
        paddingY: 50,
        charWidth: 7,
        basePadding: 20
    };

    document.addEventListener('DOMContentLoaded', () => {
        try {
            log("DOM Loaded. Starting initTree...");
            initTree();
        } catch (e) {
            log("CRITICAL ERROR in initTree: " + e.message);
            console.error(e);
        }
    });

    function initTree() {
        log(`Data loaded: ${individuals.length} individuals, ${families.length} families`);
        
        const wrapper = document.getElementById('tree-wrapper');
        const loading = document.getElementById('loading');
        
        // 1. Prepare Data & Layout
        const indMap = {};
        individuals.forEach(i => indMap[i.id] = i);

        // Determine X range
        let minDataYear = Math.min(...individuals.map(i => i.birthYear));
        let maxDataYear = Math.max(...individuals.map(i => i.deathYear));
        
        CONFIG.minYear = Math.floor(minDataYear / 10) * 10 - 20;
        CONFIG.maxYear = Math.ceil(maxDataYear / 10) * 10 + 50;

        // Calculate Widths - use same format as edit-tree.php
        const badgeWidth = 22;
        const badgeMargin = 4;
        
        individuals.forEach(ind => {
            // Use pre-formatted dateStr from PHP
            const displayText = `${ind.name} ${ind.dateStr || ''}`.trim();
            ind.displayText = displayText;
            
            // Use sequential number as displayId
            ind.displayId = ind.seqNum;
            
            // Width = badge + margin + text + padding
            ind.width = badgeWidth + badgeMargin + (displayText.length * CONFIG.charWidth) + CONFIG.basePadding;
        });

        // 2. Vertical Layout (Ordering) - use seqNum from PHP (same as edit-tree.php tiles)
        const orderedIndividuals = [...individuals].sort((a, b) => a.seqNum - b.seqNum);

        orderedIndividuals.forEach((ind, idx) => {
            ind.rowIndex = idx;
        });

        const totalWidth = (CONFIG.maxYear - CONFIG.minYear) * CONFIG.pixelsPerYear + (CONFIG.paddingX * 2);
        const totalHeight = (orderedIndividuals.length * CONFIG.rowHeight) + (CONFIG.paddingY * 2);

        // 3. Render SVG
        const ns = "http://www.w3.org/2000/svg";
        const svg = document.createElementNS(ns, "svg");
        svg.setAttribute("width", totalWidth);
        svg.setAttribute("height", totalHeight);
        svg.id = "tree-svg";

        // Grid
        const gridGroup = document.createElementNS(ns, "g");
        for (let y = CONFIG.minYear; y <= CONFIG.maxYear; y += 10) {
            const x = getX(y);
            const line = document.createElementNS(ns, "line");
            line.setAttribute("x1", x);
            line.setAttribute("y1", 0);
            line.setAttribute("x2", x);
            line.setAttribute("y2", totalHeight);
            line.setAttribute("class", "grid-line");
            if (y % 50 === 0) line.style.strokeWidth = "1.5";
            gridGroup.appendChild(line);

            if (y % 50 === 0) {
                const text = document.createElementNS(ns, "text");
                text.setAttribute("x", x + 5);
                text.setAttribute("y", 20);
                text.setAttribute("class", "grid-text");
                text.textContent = y;
                gridGroup.appendChild(text);
            }
        }
        svg.appendChild(gridGroup);

        // Connections
        const connectionsGroup = document.createElementNS(ns, "g");
        
        // Spouses
        const processedSpouses = new Set();
        families.forEach(fam => {
            if (fam.husb && fam.wife) {
                const h = indMap[fam.husb];
                const w = indMap[fam.wife];
                if (h && w) {
                    const pairKey = [h.id, w.id].sort().join('-');
                    if (processedSpouses.has(pairKey)) return;
                    processedSpouses.add(pairKey);

                    const hX = getX(h.birthYear) + h.width;
                    const hY = getY(h) + (CONFIG.boxHeight / 2);
                    const wX = getX(w.birthYear) + w.width;
                    const wY = getY(w) + (CONFIG.boxHeight / 2);

                    const cpOffset = 30;
                    const cp1X = Math.max(hX, wX) + cpOffset;
                    const cp2X = Math.max(hX, wX) + cpOffset;

                    const path = document.createElementNS(ns, "path");
                    const d = `M ${hX} ${hY} C ${cp1X} ${hY}, ${cp2X} ${wY}, ${wX} ${wY}`;
                    path.setAttribute("d", d);
                    path.setAttribute("class", "connection-line spouse-line");
                    connectionsGroup.appendChild(path);
                }
            }
        });

        // Children
        families.forEach(fam => {
            if (!fam.children || fam.children.length === 0) return;
            const parentId = fam.wife ? fam.wife : fam.husb;
            const parent = indMap[parentId];
            if (!parent) return;

            const startX = getX(parent.birthYear) + 20;
            const startY = getY(parent) + CONFIG.boxHeight;

            fam.children.forEach(childId => {
                const child = indMap[childId];
                if (!child) return;

                const endX = getX(child.birthYear);
                const endY = getY(child) + (CONFIG.boxHeight / 2);

                const path = document.createElementNS(ns, "path");
                const d = `M ${startX} ${startY} C ${startX} ${startY + 20}, ${endX - 20} ${endY}, ${endX} ${endY}`;
                path.setAttribute("d", d);
                path.setAttribute("class", "connection-line child-line");
                connectionsGroup.appendChild(path);
            });
        });
        svg.appendChild(connectionsGroup);

        // Boxes
        const boxesGroup = document.createElementNS(ns, "g");
        const badgeWidth = 22;
        const badgeHeight = 16;
        const badgeMargin = 4;
        
        orderedIndividuals.forEach(ind => {
            const g = document.createElementNS(ns, "g");
            g.setAttribute("class", "person-box");
            g.setAttribute("transform", `translate(${getX(ind.birthYear)}, ${getY(ind)})`);
            
            const rect = document.createElementNS(ns, "rect");
            rect.setAttribute("width", ind.width);
            rect.setAttribute("height", CONFIG.boxHeight);
            rect.setAttribute("rx", 4);
            rect.setAttribute("class", `person-rect ${ind.gender === 'M' ? 'male' : 'female'}`);
            g.appendChild(rect);

            // Badge on the left (like in tiles)
            const badge = document.createElementNS(ns, "rect");
            badge.setAttribute("x", 3);
            badge.setAttribute("y", (CONFIG.boxHeight - badgeHeight) / 2);
            badge.setAttribute("width", badgeWidth);
            badge.setAttribute("height", badgeHeight);
            badge.setAttribute("rx", 3);
            badge.setAttribute("class", "id-badge");
            g.appendChild(badge);

            const idText = document.createElementNS(ns, "text");
            idText.setAttribute("x", 3 + badgeWidth / 2);
            idText.setAttribute("y", CONFIG.boxHeight / 2);
            idText.setAttribute("class", "id-text");
            idText.textContent = ind.displayId;
            g.appendChild(idText);

            // Name text after badge
            const text = document.createElementNS(ns, "text");
            text.setAttribute("x", badgeWidth + badgeMargin + 6);
            text.setAttribute("y", CONFIG.boxHeight / 2);
            text.setAttribute("class", "person-text");
            text.setAttribute("dominant-baseline", "central");
            text.textContent = ind.displayText;
            g.appendChild(text);

            boxesGroup.appendChild(g);
        });
        svg.appendChild(boxesGroup);

        if (loading) loading.remove();
        wrapper.appendChild(svg);
    }

    function getX(year) {
        return (year - CONFIG.minYear) * CONFIG.pixelsPerYear + CONFIG.paddingX;
    }
    function getY(ind) {
        return ind.rowIndex * CONFIG.rowHeight + CONFIG.paddingY;
    }
</script>
