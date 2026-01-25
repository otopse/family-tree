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
    .person-name {
        font-family: 'Segoe UI', sans-serif;
        font-size: 12px;
        font-weight: 600;
        fill: #333;
    }
    .person-dates {
        font-family: 'Segoe UI', sans-serif;
        font-size: 10px;
        fill: #666;
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
    const individuals = <?= json_encode(array_values($individuals)) ?>;
    const families = <?= json_encode($cleanFamilies) ?>;
    
    // Config
    const CONFIG = {
        pixelsPerYear: 10,
        rowHeight: 50,
        boxHeight: 36,
        minYear: 1800,
        maxYear: 2050,
        paddingX: 50,
        paddingY: 50
    };

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        initTree();
    });

    function initTree() {
        const wrapper = document.getElementById('tree-wrapper');
        const loading = document.getElementById('loading');
        
        // 1. Calculate Layout
        // Assign sequential IDs (1, 2, 3...) for display
        individuals.forEach((ind, index) => {
            ind.displayId = index + 1;
        });

        // Determine X range
        let minDataYear = Math.min(...individuals.map(i => i.birthYear));
        let maxDataYear = Math.max(...individuals.map(i => i.deathYear));
        CONFIG.minYear = Math.floor(minDataYear / 10) * 10 - 20;
        CONFIG.maxYear = Math.ceil(maxDataYear / 10) * 10 + 20;

        // Assign Rows (Greedy packing algorithm)
        // Sort by birth year to pack from left to right
        const sortedInds = [...individuals].sort((a, b) => a.birthYear - b.birthYear);
        const rows = []; // Array of endYears

        sortedInds.forEach(ind => {
            // Find first row where this person fits
            let rowIndex = -1;
            for (let r = 0; r < rows.length; r++) {
                if (rows[r] < ind.birthYear) {
                    rowIndex = r;
                    break;
                }
            }

            if (rowIndex === -1) {
                // New row
                rowIndex = rows.length;
                rows.push(ind.deathYear + 5); // +5 years buffer
            } else {
                rows[rowIndex] = ind.deathYear + 5;
            }

            ind.rowIndex = rowIndex;
        });

        const totalWidth = (CONFIG.maxYear - CONFIG.minYear) * CONFIG.pixelsPerYear + (CONFIG.paddingX * 2);
        const totalHeight = (rows.length * CONFIG.rowHeight) + (CONFIG.paddingY * 2);

        // 2. Build SVG
        const ns = "http://www.w3.org/2000/svg";
        const svg = document.createElementNS(ns, "svg");
        svg.setAttribute("width", totalWidth);
        svg.setAttribute("height", totalHeight);
        svg.id = "tree-svg";

        // Draw Grid
        const gridGroup = document.createElementNS(ns, "g");
        for (let y = CONFIG.minYear; y <= CONFIG.maxYear; y += 10) {
            const x = getX(y);
            
            const line = document.createElementNS(ns, "line");
            line.setAttribute("x1", x);
            line.setAttribute("y1", 0);
            line.setAttribute("x2", x);
            line.setAttribute("y2", totalHeight);
            line.setAttribute("class", "grid-line");
            if (y % 50 === 0) line.style.strokeWidth = "2";
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
        // Map individual GedcomID to Object for easy lookup
        const indMap = {};
        individuals.forEach(i => indMap[i.id] = i);

        const connectionsGroup = document.createElementNS(ns, "g");
        
        families.forEach(fam => {
            const children = fam.children || [];
            if (children.length === 0) return;

            // Parents
            const husb = fam.husb ? indMap[fam.husb] : null;
            const wife = fam.wife ? indMap[fam.wife] : null;

            // Start point (Average of parents if both exist, otherwise just one)
            let startX, startY;
            if (husb && wife) {
                startX = (getX(husb.deathYear) + getX(wife.birthYear)) / 2; // Midpoint X roughly
                // Better: Use actual box centers
                const hCX = getX(husb.birthYear) + (getWidth(husb) / 2);
                const hCY = getY(husb) + (CONFIG.boxHeight / 2);
                const wCX = getX(wife.birthYear) + (getWidth(wife) / 2);
                const wCY = getY(wife) + (CONFIG.boxHeight / 2);
                
                startX = (hCX + wCX) / 2;
                startY = (hCY + wCY) / 2;
            } else if (husb) {
                startX = getX(husb.birthYear) + (getWidth(husb) / 2);
                startY = getY(husb) + (CONFIG.boxHeight / 2);
            } else if (wife) {
                startX = getX(wife.birthYear) + (getWidth(wife) / 2);
                startY = getY(wife) + (CONFIG.boxHeight / 2);
            } else {
                return;
            }

            children.forEach(childId => {
                const child = indMap[childId];
                if (!child) return;

                const endX = getX(child.birthYear);
                const endY = getY(child) + (CONFIG.boxHeight / 2);

                const path = document.createElementNS(ns, "path");
                // Cubic Bezier: M startX startY C cp1x cp1y, cp2x cp2y, endX endY
                // Control points: adjust curvature
                const cp1X = startX + 50;
                const cp1Y = startY;
                const cp2X = endX - 50;
                const cp2Y = endY;

                const d = `M ${startX} ${startY} C ${cp1X} ${cp1Y}, ${cp2X} ${cp2Y}, ${endX} ${endY}`;
                path.setAttribute("d", d);
                path.setAttribute("class", "connection-line");
                connectionsGroup.appendChild(path);
            });
        });
        svg.appendChild(connectionsGroup);

        // Draw Individuals
        const boxesGroup = document.createElementNS(ns, "g");
        individuals.forEach(ind => {
            const g = document.createElementNS(ns, "g");
            g.setAttribute("class", "person-box");
            g.setAttribute("transform", `translate(${getX(ind.birthYear)}, ${getY(ind)})`);
            
            // Box
            const rect = document.createElementNS(ns, "rect");
            const w = getWidth(ind);
            const h = CONFIG.boxHeight;
            rect.setAttribute("width", w);
            rect.setAttribute("height", h);
            rect.setAttribute("rx", 6); // Rounded corners
            rect.setAttribute("class", `person-rect ${ind.gender === 'M' ? 'male' : 'female'}`);
            g.appendChild(rect);

            // ID Badge
            const badgeSize = 16;
            const badge = document.createElementNS(ns, "rect");
            badge.setAttribute("x", w - badgeSize);
            badge.setAttribute("y", 0);
            badge.setAttribute("width", badgeSize);
            badge.setAttribute("height", badgeSize);
            badge.setAttribute("class", "id-badge");
            // Rounded top-right corner only? or simple square
            badge.setAttribute("rx", 0); 
            g.appendChild(badge);

            const idText = document.createElementNS(ns, "text");
            idText.setAttribute("x", w - (badgeSize/2));
            idText.setAttribute("y", badgeSize/2);
            idText.setAttribute("class", "id-text");
            idText.textContent = ind.displayId;
            g.appendChild(idText);

            // Name
            const name = document.createElementNS(ns, "text");
            name.setAttribute("x", 8);
            name.setAttribute("y", 16);
            name.setAttribute("class", "person-name");
            // Truncate if too long?
            name.textContent = ind.name.replace(/\//g, '');
            g.appendChild(name);

            // Dates
            const dates = document.createElementNS(ns, "text");
            dates.setAttribute("x", 8);
            dates.setAttribute("y", 28);
            dates.setAttribute("class", "person-dates");
            
            // Format places
            let bPlace = ind.birthPlace ? ` ${ind.birthPlace}` : '';
            let dPlace = ind.deathPlace ? ` ${ind.deathPlace}` : '';
            // Shorten places if needed
            
            dates.textContent = `${ind.birthYear}${bPlace} - ${ind.deathYear}${dPlace}`;
            g.appendChild(dates);
            
            // Click Handler (Highlight)
            g.addEventListener('click', () => {
                alert(`ID: ${ind.id}\nName: ${ind.name}`);
            });

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

    function getWidth(ind) {
        let w = (ind.deathYear - ind.birthYear) * CONFIG.pixelsPerYear;
        if (w < 120) w = 120; // Minimum width for readability
        return w;
    }

</script>

<?php // No footer needed really for full screen view, or minimal one ?>
