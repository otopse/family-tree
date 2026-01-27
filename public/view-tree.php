<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$treeId = (int)($_GET['id'] ?? 0);

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

$individuals = [];
$families = [];
$parentMap = [];

// Helper to extract year
function extractYear(?string $date): ?int {
    if (!$date) return null;
    if (preg_match('/(\d{4})/', $date, $matches)) {
        return (int)$matches[1];
    }
    return null;
}

foreach ($rows as $row) {
    $famId = $row['record_id'];
    
    // Process Individual
    if ($row['gedcom_id']) {
        if (!isset($individuals[$row['gedcom_id']])) {
            $bYear = extractYear($row['birth_date']);
            $dYear = extractYear($row['death_date']);
    
            // Fix: Ignore death date if it matches today (default value error)
            if ($row['death_date'] === date('Y-m-d')) {
                 $dYear = null;
                 $row['death_date'] = null;
            }
            
            // Sanity check/Defaults
            if (!$bYear && $dYear) $bYear = $dYear - 60;
            if (!$bYear) $bYear = 1900; // Fallback
            if (!$dYear) $dYear = $bYear + 70; // Assumed lifespan if unknown
            
            $individuals[$row['gedcom_id']] = [
                'id' => $row['gedcom_id'],
                'name' => $row['full_name'],
                'gender' => $row['gender'],
                'birthYear' => $bYear,
                'deathYear' => $dYear,
                'birthDate' => $row['birth_date'],
                'deathDate' => $row['death_date'],
                'birthPlace' => $row['birth_place'],
                'deathPlace' => $row['death_place'],
                'recordIds' => [] // Initialize array for related records
            ];
        }

        // Add this record ID to the individual's list
        if (!in_array($famId, $individuals[$row['gedcom_id']]['recordIds'])) {
            $individuals[$row['gedcom_id']]['recordIds'][] = $famId;
        }
    }

    // Build Family links
    if (!isset($families[$famId])) {
        $families[$famId] = ['husb' => null, 'wife' => null, 'children' => []];
    }

    if ($row['type'] === 'MUZ') {
        $families[$famId]['husb'] = $row['gedcom_id'];
    } elseif ($row['type'] === 'ZENA') {
        $families[$famId]['wife'] = $row['gedcom_id'];
    } elseif ($row['type'] === 'DIETA') {
        $families[$famId]['children'][] = $row['gedcom_id'];
        if ($row['gedcom_id']) {
            $parentMap[$row['gedcom_id']] = $famId;
        }
    }
}

// Filter families to only those with connections
$cleanFamilies = [];
foreach ($families as $id => $fam) {
    if (($fam['husb'] || $fam['wife']) && !empty($fam['children'])) {
        $cleanFamilies[] = $fam;
    }
}

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
            .id-badge { fill: #007bff; }
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
            .id-badge { fill: #007bff; }
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

        // Calculate Widths
        individuals.forEach(ind => {
            let dateStr = "";
            if (ind.birthYear) {
                dateStr = `${ind.birthYear}`;
                if (ind.deathYear) {
                    dateStr += ` - ${ind.deathYear}`;
                }
            }
            const displayText = `${ind.name} (${dateStr})`;
            ind.displayText = displayText;
            
            // Use displayId (gedcom_id) if available, or just id
            ind.displayId = ind.id.replace(/@/g, ''); 
            
            ind.width = (displayText.length * CONFIG.charWidth) + CONFIG.basePadding + 20;
        });

        // 2. Vertical Layout (Ordering)
        const visited = new Set();
        const orderedIndividuals = [];

        // Infer roots
        const allChildren = new Set();
        families.forEach(f => {
            if (f.children) f.children.forEach(c => allChildren.add(c));
        });

        const roots = individuals.filter(i => !allChildren.has(i.id));
        roots.sort((a, b) => a.birthYear - b.birthYear);
        
        function traverse(indId) {
            if (visited.has(indId)) return;
            visited.add(indId);
            
            const ind = indMap[indId];
            if (!ind) return;
            
            orderedIndividuals.push(ind);

            // Spouses
            const myFamilies = families.filter(f => f.husb === indId || f.wife === indId);
            myFamilies.forEach(fam => {
                const spouseId = (fam.husb === indId) ? fam.wife : fam.husb;
                if (spouseId && !visited.has(spouseId)) {
                    traverse(spouseId);
                }
            });

            // Children
            myFamilies.forEach(fam => {
                if (fam.children) {
                    const kids = fam.children
                        .map(id => indMap[id])
                        .filter(k => k)
                        .sort((a, b) => a.birthYear - b.birthYear);
                        
                    kids.forEach(kid => {
                        traverse(kid.id);
                    });
                }
            });
        }

        roots.forEach(root => traverse(root.id));
        individuals.forEach(ind => {
            if (!visited.has(ind.id)) traverse(ind.id);
        });

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

            const badgeSize = 14;
            const badge = document.createElementNS(ns, "rect");
            badge.setAttribute("x", ind.width - badgeSize);
            badge.setAttribute("y", 0);
            badge.setAttribute("width", badgeSize);
            badge.setAttribute("height", badgeSize);
            badge.setAttribute("class", "id-badge");
            g.appendChild(badge);

            const idText = document.createElementNS(ns, "text");
            idText.setAttribute("x", ind.width - (badgeSize/2));
            idText.setAttribute("y", badgeSize/2);
            idText.setAttribute("class", "id-text");
            idText.textContent = ind.displayId;
            g.appendChild(idText);

            const text = document.createElementNS(ns, "text");
            text.setAttribute("x", 5);
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
