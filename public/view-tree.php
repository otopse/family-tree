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
    if ($row['gedcom_id'] && !isset($individuals[$row['gedcom_id']])) {
        $bYear = extractYear($row['birth_date']);
        $dYear = extractYear($row['death_date']);
        
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
            'deathPlace' => $row['death_place']
        ];
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

render_header('Zobrazenie rodokmeňa: ' . e($tree['tree_name']));
?>

<style>
    body {
        margin: 0;
        overflow: hidden; /* Main scroll handled by container */
    }
    #tree-wrapper {
        width: 100vw;
        height: calc(100vh - 100px); /* Adjust for header */
        overflow: auto;
        position: relative;
        background-color: #fdfdfd;
        border-top: 1px solid #ddd;
    }
    #tree-svg {
        display: block;
    }
    .person-box {
        cursor: pointer;
        filter: drop-shadow(2px 2px 2px rgba(0,0,0,0.1));
        transition: filter 0.2s;
    }
    .person-box:hover {
        filter: drop-shadow(3px 3px 5px rgba(0,0,0,0.3));
    }
    .person-rect {
        stroke: #999;
        stroke-width: 1;
    }
    .person-rect.male {
        fill: #e6f7ff;
        stroke: #91d5ff;
    }
    .person-rect.female {
        fill: #fff0f6;
        stroke: #ffadd2;
    }
    .person-text {
        font-family: 'Segoe UI', sans-serif;
        font-size: 11px;
        fill: #333;
    }
    .spouse-line {
        stroke: #f5222d; /* Reddish for marriage */
        stroke-dasharray: 4;
    }
    .child-line {
        stroke: #1890ff;
    }
    .id-badge {
        fill: black;
    }
    .id-text {
        fill: white;
        font-size: 9px;
        font-family: monospace;
        font-weight: bold;
        text-anchor: middle;
        dominant-baseline: central;
    }
    .connection-line {
        fill: none;
        stroke: #1890ff;
        stroke-width: 1.5;
        stroke-opacity: 0.4;
    }
    .grid-line {
        stroke: #eee;
        stroke-width: 1;
    }
    .grid-text {
        fill: #aaa;
        font-size: 12px;
        font-family: sans-serif;
    }
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
</div>

<script>
    // Config
    const CONFIG = {
        pixelsPerYear: 5, // Compressed X axis
        rowHeight: 45,
        boxHeight: 30,
        minYear: 1800,
        maxYear: 2050,
        paddingX: 50,
        paddingY: 50,
        charWidth: 7, // Estimate for width calculation
        basePadding: 20 // Padding inside box
    };

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        initTree();
    });

    function initTree() {
        const wrapper = document.getElementById('tree-wrapper');
        const loading = document.getElementById('loading');
        
        // 1. Prepare Data & Layout
        
        // Map for easy lookup
        const indMap = {};
        individuals.forEach(i => indMap[i.id] = i);

        // Determine X range
        let minDataYear = Math.min(...individuals.map(i => i.birthYear));
        let maxDataYear = Math.max(...individuals.map(i => i.deathYear));
        CONFIG.minYear = Math.floor(minDataYear / 10) * 10 - 20;
        CONFIG.maxYear = Math.ceil(maxDataYear / 10) * 10 + 50; // More space on right for spouse curves

        // Calculate Widths based on text length
        individuals.forEach(ind => {
            // Estimate width: name length + date string length
            // Format: Meno Priezvisko (Nar - Umr)
            // Or just [Rok] if dates unknown?
            // The PHP part passes formatted dates, but we construct the string in JS for display.
            // Let's reconstruct the display string to measure it.
            
            let dateStr = "";
            if (ind.birthYear) {
                dateStr = `${ind.birthYear}`;
                if (ind.deathYear) {
                    dateStr += ` - ${ind.deathYear}`;
                }
            }
            
            const displayText = `${ind.name} (${dateStr})`;
            ind.displayText = displayText;
            
            // Estimate width
            ind.width = (displayText.length * CONFIG.charWidth) + CONFIG.basePadding + 20; // +20 for ID badge
        });

        // 2. Vertical Layout (Ordering)
        // We want to place related people close to each other.
        // Strategy: DFS traversal starting from "roots" (people with no parents in the tree, or oldest ancestors).
        
        const visited = new Set();
        const orderedIndividuals = [];

        // Find roots: People whose parents are not in the `individuals` list or are unknown
        // We can use the parentMap from PHP, but we didn't pass it fully.
        // Let's infer roots: People who are not children in any family in `families` list.
        const allChildren = new Set();
        families.forEach(f => {
            if (f.children) f.children.forEach(c => allChildren.add(c));
        });

        const roots = individuals.filter(i => !allChildren.has(i.id));
        // Sort roots by birth year
        roots.sort((a, b) => a.birthYear - b.birthYear);

        function traverse(indId) {
            if (visited.has(indId)) return;
            visited.add(indId);
            
            const ind = indMap[indId];
            if (!ind) return;
            
            orderedIndividuals.push(ind);

            // Find spouses and process them immediately
            // We need to find families where this person is a parent
            const myFamilies = families.filter(f => f.husb === indId || f.wife === indId);
            
            myFamilies.forEach(fam => {
                const spouseId = (fam.husb === indId) ? fam.wife : fam.husb;
                if (spouseId && !visited.has(spouseId)) {
                    traverse(spouseId);
                }
            });

            // Process children for each family
            myFamilies.forEach(fam => {
                if (fam.children) {
                    // Sort children by birth year
                    const kids = fam.children
                        .map(id => indMap[id])
                        .filter(k => k) // filter nulls
                        .sort((a, b) => a.birthYear - b.birthYear);
                        
                    kids.forEach(kid => {
                        traverse(kid.id);
                    });
                }
            });
        }

        // Run traversal
        roots.forEach(root => traverse(root.id));
        
        // Process any remaining disconnected individuals
        individuals.forEach(ind => {
            if (!visited.has(ind.id)) {
                traverse(ind.id);
            }
        });

        // Assign Row Indices
        orderedIndividuals.forEach((ind, idx) => {
            ind.rowIndex = idx;
        });

        // Calculate total dimensions
        const totalWidth = (CONFIG.maxYear - CONFIG.minYear) * CONFIG.pixelsPerYear + (CONFIG.paddingX * 2);
        const totalHeight = (orderedIndividuals.length * CONFIG.rowHeight) + (CONFIG.paddingY * 2);

        // 3. Render SVG
        const ns = "http://www.w3.org/2000/svg";
        const svg = document.createElementNS(ns, "svg");
        svg.setAttribute("width", totalWidth);
        svg.setAttribute("height", totalHeight);
        svg.id = "tree-svg";

        // Draw Grid (Vertical lines for years)
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

        // Draw Connections
        const connectionsGroup = document.createElementNS(ns, "g");
        
        // 1. Spouse Connections (Curved line on the right)
        // "Manželské páry spájaj čiarami, ktoré vychádzajú a vchádzajú z pravej strany ich tehál."
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

                    // Control points for curve on the right
                    const cpOffset = 30;
                    const cp1X = Math.max(hX, wX) + cpOffset;
                    const cp1Y = hY;
                    const cp2X = Math.max(hX, wX) + cpOffset;
                    const cp2Y = wY;

                    const path = document.createElementNS(ns, "path");
                    const d = `M ${hX} ${hY} C ${cp1X} ${cp1Y}, ${cp2X} ${cp2Y}, ${wX} ${wY}`;
                    path.setAttribute("d", d);
                    path.setAttribute("class", "connection-line spouse-line");
                    connectionsGroup.appendChild(path);
                }
            }
        });

        // 2. Parent-Child Connections (Mother -> Child)
        // "Čiara smerujúca k dieťaťu musí začínať na tehle matky... presne 20 pixelov od ľavého okraja"
        families.forEach(fam => {
            if (!fam.children || fam.children.length === 0) return;

            // Prefer Mother, then Father
            const parentId = fam.wife ? fam.wife : fam.husb;
            const parent = indMap[parentId];
            
            if (!parent) return;

            // Anchor point: 20px from left edge of parent
            const startX = getX(parent.birthYear) + 20;
            const startY = getY(parent) + CONFIG.boxHeight;

            fam.children.forEach(childId => {
                const child = indMap[childId];
                if (!child) return;

                // Child anchor: Left edge, middle of height
                const endX = getX(child.birthYear);
                const endY = getY(child) + (CONFIG.boxHeight / 2);

                const path = document.createElementNS(ns, "path");
                
                // Curve logic: Down then Right
                // M startX startY
                // C startX (startY + endY)/2, startX (startY + endY)/2, startX endY ?? No
                // Let's try a simple Bezier
                // Control point 1: Below start
                // Control point 2: Left of end
                
                const cp1X = startX;
                const cp1Y = endY; // Go down to child's level
                const cp2X = (startX + endX) / 2; 
                const cp2Y = endY;

                // If child is to the right of the anchor
                const d = `M ${startX} ${startY} C ${startX} ${startY + 20}, ${endX - 20} ${endY}, ${endX} ${endY}`;
                
                path.setAttribute("d", d);
                path.setAttribute("class", "connection-line child-line");
                connectionsGroup.appendChild(path);
            });
        });

        svg.appendChild(connectionsGroup);

        // Draw Individuals (Bricks)
        const boxesGroup = document.createElementNS(ns, "g");
        orderedIndividuals.forEach(ind => {
            const g = document.createElementNS(ns, "g");
            g.setAttribute("class", "person-box");
            g.setAttribute("transform", `translate(${getX(ind.birthYear)}, ${getY(ind)})`);
            
            // Box
            const rect = document.createElementNS(ns, "rect");
            rect.setAttribute("width", ind.width);
            rect.setAttribute("height", CONFIG.boxHeight);
            rect.setAttribute("rx", 4);
            rect.setAttribute("class", `person-rect ${ind.gender === 'M' ? 'male' : 'female'}`);
            g.appendChild(rect);

            // ID Badge (Right edge)
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

            // Text content
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

        // Finish
        loading.remove();
        wrapper.appendChild(svg);
    }

    // Helpers
    function getX(year) {
        return (year - CONFIG.minYear) * CONFIG.pixelsPerYear + CONFIG.paddingX;
    }

    function getY(ind) {
        return ind.rowIndex * CONFIG.rowHeight + CONFIG.paddingY;
    }


</script>

<?php // No footer needed really for full screen view, or minimal one ?>
