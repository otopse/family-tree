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

    // Function to log to server debug file
    function debugLog(msg, context = '') {
        log(msg);
        try {
            fetch('/api/debug-log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: msg, context: context })
            }).catch(err => {
                console.error('Failed to log to server:', err);
            });
        } catch (e) {
            console.error('Error in debugLog:', e);
        }
    }

    // ... (rest of the script logic)
    // We need to output the PHP data into JS variables
    debugLog("=== JS INIT START ===");
    debugLog("Browser: " + navigator.userAgent);
    debugLog("URL: " + window.location.href);
    
    let individuals, families;
    try {
        const individualsRaw = <?= json_encode(array_values($individuals)) ?>;
        const familiesRaw = <?= json_encode($cleanFamilies) ?>;
        
        debugLog(`Raw PHP data received - individuals type: ${typeof individualsRaw}, families type: ${typeof familiesRaw}`);
        
        individuals = individualsRaw;
        families = familiesRaw;
        
        debugLog(`PHP data loaded: individuals=${Array.isArray(individuals) ? individuals.length : 'INVALID'}, families=${Array.isArray(families) ? families.length : 'INVALID'}`);
        
        if (!Array.isArray(individuals)) {
            debugLog("ERROR: individuals is not an array! Type: " + typeof individuals, JSON.stringify(individuals));
            individuals = [];
        } else if (individuals.length > 0) {
            debugLog(`First individual sample: ${JSON.stringify(individuals[0])}`);
        }
        
        if (!Array.isArray(families)) {
            debugLog("ERROR: families is not an array! Type: " + typeof families, JSON.stringify(families));
            families = [];
        } else if (families.length > 0) {
            debugLog(`First family sample: ${JSON.stringify(families[0])}`);
        }
        
        if (individuals.length === 0) {
            debugLog("WARNING: individuals array is empty!");
        }
        if (families.length === 0) {
            debugLog("WARNING: families array is empty!");
        }
    } catch (e) {
        debugLog("CRITICAL: Failed to parse PHP JSON data: " + e.message, e.stack);
        individuals = [];
        families = [];
    }

    // ... (rest of JS functions: initTree, etc) ...
    // Copying the full JS logic here
    
    window.onerror = function(msg, url, line, col, error) {
        const errorMsg = `ERROR: ${msg} at ${line}:${col}${error ? ' | ' + error.stack : ''}`;
        log(errorMsg);
        debugLog(errorMsg, `URL: ${url}`);
        return false;
    };
    
    window.addEventListener('unhandledrejection', function(event) {
        const errorMsg = `UNHANDLED PROMISE REJECTION: ${event.reason}`;
        log(errorMsg);
        debugLog(errorMsg, event.reason?.stack || '');
    });

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
            debugLog("DOM Loaded. Starting initTree...");
            initTree();
            debugLog("initTree() completed successfully");
        } catch (e) {
            const errorMsg = "CRITICAL ERROR in initTree: " + e.message;
            log(errorMsg);
            debugLog(errorMsg, e.stack);
            console.error(e);
        }
    });

    function initTree() {
        debugLog(`initTree() called. Data: ${individuals.length} individuals, ${families.length} families`);
        
        const wrapper = document.getElementById('tree-wrapper');
        const loading = document.getElementById('loading');
        
        if (!wrapper) {
            debugLog("ERROR: tree-wrapper element not found!");
            return;
        }
        debugLog("tree-wrapper found");
        
        if (!loading) {
            debugLog("WARNING: loading element not found");
        }
        
        if (individuals.length === 0) {
            debugLog("WARNING: No individuals to display!");
            if (loading) loading.textContent = "Žiadne dáta na zobrazenie";
            return;
        }
        
        // 1. Prepare Data & Layout
        debugLog("Step 1: Building indMap...");
        const indMap = {};
        individuals.forEach(i => {
            if (!i.id) {
                debugLog(`WARNING: Individual without id: ${JSON.stringify(i)}`);
            }
            indMap[i.id] = i;
        });
        debugLog(`indMap built with ${Object.keys(indMap).length} entries`);

        // Determine X range
        debugLog("Step 2: Calculating year range...");
        const birthYears = individuals.map(i => i.birthYear).filter(y => y != null && !isNaN(y));
        const deathYears = individuals.map(i => i.deathYear).filter(y => y != null && !isNaN(y));
        
        if (birthYears.length === 0) {
            debugLog("ERROR: No valid birth years found!");
            if (loading) loading.textContent = "Chyba: Žiadne platné dátumy narodenia";
            return;
        }
        
        let minDataYear = Math.min(...birthYears);
        let maxDataYear = deathYears.length > 0 ? Math.max(...deathYears) : minDataYear;
        
        debugLog(`Year range: min=${minDataYear}, max=${maxDataYear}`);
        
        CONFIG.minYear = Math.floor(minDataYear / 10) * 10 - 20;
        CONFIG.maxYear = Math.ceil(maxDataYear / 10) * 10 + 50;
        debugLog(`CONFIG year range: min=${CONFIG.minYear}, max=${CONFIG.maxYear}`);

        // Calculate Widths - use same format as edit-tree.php
        debugLog("Step 3: Calculating widths...");
        const badgeWidth = 22;
        const badgeMargin = 4;
        
        let widthErrors = 0;
        individuals.forEach(ind => {
            try {
                // Use pre-formatted dateStr from PHP
                const displayText = `${ind.name || 'Neznámy'} ${ind.dateStr || ''}`.trim();
                ind.displayText = displayText;
                
                // Use sequential number as displayId
                ind.displayId = ind.seqNum || 0;
                
                // Width = badge + margin + text + padding
                ind.width = badgeWidth + badgeMargin + (displayText.length * CONFIG.charWidth) + CONFIG.basePadding;
            } catch (e) {
                widthErrors++;
                debugLog(`Error calculating width for individual: ${JSON.stringify(ind)}`, e.message);
            }
        });
        if (widthErrors > 0) {
            debugLog(`WARNING: ${widthErrors} errors calculating widths`);
        }

        // 2. Vertical Layout (Ordering) - use seqNum from PHP (same as edit-tree.php tiles)
        debugLog("Step 4: Sorting individuals...");
        const orderedIndividuals = [...individuals].sort((a, b) => {
            const seqA = a.seqNum || 999999;
            const seqB = b.seqNum || 999999;
            return seqA - seqB;
        });
        debugLog(`Sorted ${orderedIndividuals.length} individuals`);

        orderedIndividuals.forEach((ind, idx) => {
            ind.rowIndex = idx;
        });

        debugLog("Step 5: Calculating dimensions...");
        const totalWidth = (CONFIG.maxYear - CONFIG.minYear) * CONFIG.pixelsPerYear + (CONFIG.paddingX * 2);
        const totalHeight = (orderedIndividuals.length * CONFIG.rowHeight) + (CONFIG.paddingY * 2);
        debugLog(`SVG dimensions: ${totalWidth}x${totalHeight}`);

        // 3. Render SVG
        debugLog("Step 6: Creating SVG element...");
        const ns = "http://www.w3.org/2000/svg";
        const svg = document.createElementNS(ns, "svg");
        svg.setAttribute("width", totalWidth);
        svg.setAttribute("height", totalHeight);
        svg.id = "tree-svg";
        debugLog("SVG element created");

        // Grid
        debugLog("Step 7: Creating grid...");
        const gridGroup = document.createElementNS(ns, "g");
        let gridLines = 0;
        try {
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
                gridLines++;

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
            debugLog(`Grid created with ${gridLines} lines`);
        } catch (e) {
            debugLog("ERROR creating grid: " + e.message, e.stack);
        }

        // Connections
        debugLog("Step 8: Creating connections...");
        const connectionsGroup = document.createElementNS(ns, "g");
        
        // Spouses
        debugLog("Creating spouse connections...");
        const processedSpouses = new Set();
        let spouseConnections = 0;
        let spouseErrors = 0;
        families.forEach(fam => {
            try {
                if (fam.husb && fam.wife) {
                    const h = indMap[fam.husb];
                    const w = indMap[fam.wife];
                    if (!h) {
                        debugLog(`WARNING: Husband ${fam.husb} not found in indMap`);
                        spouseErrors++;
                        return;
                    }
                    if (!w) {
                        debugLog(`WARNING: Wife ${fam.wife} not found in indMap`);
                        spouseErrors++;
                        return;
                    }
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
                    spouseConnections++;
                }
            } catch (e) {
                spouseErrors++;
                debugLog(`ERROR creating spouse connection: ${e.message}`, e.stack);
            }
        });
        debugLog(`Created ${spouseConnections} spouse connections (${spouseErrors} errors)`);

        // Children
        debugLog("Creating child connections...");
        let childConnections = 0;
        let childErrors = 0;
        families.forEach(fam => {
            try {
                if (!fam.children || fam.children.length === 0) return;
                const parentId = fam.wife ? fam.wife : fam.husb;
                if (!parentId) {
                    debugLog(`WARNING: Family has children but no parent: ${JSON.stringify(fam)}`);
                    childErrors++;
                    return;
                }
                const parent = indMap[parentId];
                if (!parent) {
                    debugLog(`WARNING: Parent ${parentId} not found in indMap`);
                    childErrors++;
                    return;
                }

                const startX = getX(parent.birthYear) + 20;
                const startY = getY(parent) + CONFIG.boxHeight;

                fam.children.forEach(childId => {
                    const child = indMap[childId];
                    if (!child) {
                        debugLog(`WARNING: Child ${childId} not found in indMap`);
                        childErrors++;
                        return;
                    }

                    const endX = getX(child.birthYear);
                    const endY = getY(child) + (CONFIG.boxHeight / 2);

                    const path = document.createElementNS(ns, "path");
                    const d = `M ${startX} ${startY} C ${startX} ${startY + 20}, ${endX - 20} ${endY}, ${endX} ${endY}`;
                    path.setAttribute("d", d);
                    path.setAttribute("class", "connection-line child-line");
                    connectionsGroup.appendChild(path);
                    childConnections++;
                });
            } catch (e) {
                childErrors++;
                debugLog(`ERROR creating child connection: ${e.message}`, e.stack);
            }
        });
        debugLog(`Created ${childConnections} child connections (${childErrors} errors)`);
        svg.appendChild(connectionsGroup);

        // Boxes
        debugLog("Step 9: Creating person boxes...");
        const boxesGroup = document.createElementNS(ns, "g");
        const badgeWidth = 22;
        const badgeHeight = 16;
        const badgeMargin = 4;
        
        let boxErrors = 0;
        orderedIndividuals.forEach((ind, idx) => {
            try {
                const g = document.createElementNS(ns, "g");
                g.setAttribute("class", "person-box");
                
                const x = getX(ind.birthYear);
                const y = getY(ind);
                g.setAttribute("transform", `translate(${x}, ${y})`);
                
                const rect = document.createElementNS(ns, "rect");
                rect.setAttribute("width", ind.width || 100);
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
                idText.textContent = ind.displayId || idx + 1;
                g.appendChild(idText);

                // Name text after badge
                const text = document.createElementNS(ns, "text");
                text.setAttribute("x", badgeWidth + badgeMargin + 6);
                text.setAttribute("y", CONFIG.boxHeight / 2);
                text.setAttribute("class", "person-text");
                text.setAttribute("dominant-baseline", "central");
                text.textContent = ind.displayText || ind.name || 'Neznámy';
                g.appendChild(text);

                boxesGroup.appendChild(g);
            } catch (e) {
                boxErrors++;
                debugLog(`ERROR creating box for individual ${ind.id || idx}: ${e.message}`, e.stack);
            }
        });
        svg.appendChild(boxesGroup);
        debugLog(`Created ${orderedIndividuals.length} person boxes (${boxErrors} errors)`);

        debugLog("Step 10: Appending SVG to DOM...");
        try {
            if (loading) {
                loading.remove();
                debugLog("Loading element removed");
            }
            wrapper.appendChild(svg);
            debugLog("SVG appended successfully. Graph rendering complete!");
        } catch (e) {
            debugLog("CRITICAL ERROR appending SVG: " + e.message, e.stack);
            if (loading) {
                loading.textContent = "Chyba pri vykresľovaní grafu: " + e.message;
            }
        }
    }

    function getX(year) {
        if (year == null || isNaN(year)) {
            debugLog(`WARNING: getX called with invalid year: ${year}`);
            return CONFIG.paddingX;
        }
        return (year - CONFIG.minYear) * CONFIG.pixelsPerYear + CONFIG.paddingX;
    }
    function getY(ind) {
        if (!ind || ind.rowIndex == null) {
            debugLog(`WARNING: getY called with invalid individual: ${JSON.stringify(ind)}`);
            return CONFIG.paddingY;
        }
        return ind.rowIndex * CONFIG.rowHeight + CONFIG.paddingY;
    }
</script>
