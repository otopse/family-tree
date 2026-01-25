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
    SELECT r.id as record_id, r.record_name, e.id as element_id, e.type, e.full_name, e.gedcom_id, e.gender, e.birth_date, e.death_date
    FROM ft_records r
    JOIN ft_elements e ON r.id = e.record_id
    WHERE r.tree_id = :tree_id
    ORDER BY r.id, e.sort_order
');
$stmt->execute(['tree_id' => $treeId]);
$rows = $stmt->fetchAll();

// Build structure
$families = [];
$individuals = []; // Map gedcom_id -> info
$childrenMap = []; // Map gedcom_id (child) -> family_id (where they are child)
$parentMap = [];   // Map gedcom_id (parent) -> list of family_ids (where they are parent)

foreach ($rows as $row) {
    $famId = $row['record_id'];
    if (!isset($families[$famId])) {
        $families[$famId] = [
            'id' => $famId,
            'name' => $row['record_name'],
            'husb' => null,
            'wife' => null,
            'children' => []
        ];
    }

    $person = [
        'id' => $row['element_id'],
        'name' => $row['full_name'],
        'gedcom_id' => $row['gedcom_id'],
        'gender' => $row['gender'],
        'birth' => $row['birth_date'],
        'death' => $row['death_date']
    ];

    if ($row['gedcom_id']) {
        $individuals[$row['gedcom_id']] = $person;
    }

    if ($row['type'] === 'MUZ') {
        $families[$famId]['husb'] = $person;
        if ($row['gedcom_id']) {
            $parentMap[$row['gedcom_id']][] = $famId;
        }
    } elseif ($row['type'] === 'ZENA') {
        $families[$famId]['wife'] = $person;
        if ($row['gedcom_id']) {
            $parentMap[$row['gedcom_id']][] = $famId;
        }
    } elseif ($row['type'] === 'DIETA') {
        $families[$famId]['children'][] = $person;
        if ($row['gedcom_id']) {
            $childrenMap[$row['gedcom_id']] = $famId;
        }
    }
}

// Find root families (families where parents are not children in any other family)
$rootFamilies = [];
foreach ($families as $famId => $fam) {
    $isRoot = true;
    if ($fam['husb'] && isset($childrenMap[$fam['husb']['gedcom_id']])) $isRoot = false;
    if ($fam['wife'] && isset($childrenMap[$fam['wife']['gedcom_id']])) $isRoot = false;
    
    if ($isRoot) {
        $rootFamilies[] = $famId;
    }
}

// If no root found (circular?), just pick the first one
if (empty($rootFamilies) && !empty($families)) {
    $rootFamilies[] = array_key_first($families);
}

render_header('Zobrazenie rodokmeňa: ' . e($tree['tree_name']));
?>

<style>
    .tree-container {
        overflow: auto;
        padding: 20px;
        background: #f5f5f5;
        min-height: 500px;
    }
    
    .tf-tree {
        display: flex;
        flex-direction: row;
    }

    .tf-tree ul {
        display: flex;
        padding-top: 20px;
        position: relative;
        transition: all 0.5s;
        -webkit-transition: all 0.5s;
        -moz-transition: all 0.5s;
    }

    .tf-tree li {
        float: left;
        text-align: center;
        list-style-type: none;
        position: relative;
        padding: 20px 5px 0 5px;
        transition: all 0.5s;
        -webkit-transition: all 0.5s;
        -moz-transition: all 0.5s;
    }

    /* Connectors */
    .tf-tree li::before, .tf-tree li::after {
        content: '';
        position: absolute; top: 0; right: 50%;
        border-top: 1px solid #ccc;
        width: 50%; height: 20px;
    }
    .tf-tree li::after {
        right: auto; left: 50%;
        border-left: 1px solid #ccc;
    }

    .tf-tree li:only-child::after, .tf-tree li:only-child::before {
        display: none;
    }

    .tf-tree li:only-child { padding-top: 0; }

    .tf-tree li:first-child::before, .tf-tree li:last-child::after {
        border: 0 none;
    }
    
    .tf-tree li:last-child::before {
        border-right: 1px solid #ccc;
        border-radius: 0 5px 0 0;
        -webkit-border-radius: 0 5px 0 0;
        -moz-border-radius: 0 5px 0 0;
    }
    .tf-tree li:first-child::after {
        border-radius: 5px 0 0 0;
        -webkit-border-radius: 5px 0 0 0;
        -moz-border-radius: 5px 0 0 0;
    }

    .tf-tree ul ul::before {
        content: '';
        position: absolute; top: 0; left: 50%;
        border-left: 1px solid #ccc;
        width: 0; height: 20px;
    }

    /* Container for Couple to ensure they stay together */
    .couple-wrapper {
        display: inline-flex;
        align-items: center;
        background: #fff;
        border: 1px solid #ccc;
        padding: 5px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        position: relative;
        z-index: 2;
    }

    .tf-node {
        border: 1px solid #ccc;
        padding: 8px 12px;
        text-decoration: none;
        color: #666;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 13px;
        display: inline-block;
        border-radius: 4px;
        background: white;
        min-width: 140px;
        text-align: left;
        margin: 0 5px;
    }

    .tf-node.male { 
        background-color: #f0f7ff; 
        border-color: #1890ff; 
        border-left-width: 4px;
    }
    .tf-node.female { 
        background-color: #fff0f6; 
        border-color: #eb2f96; 
        border-left-width: 4px;
    }
    
    .tf-node strong { 
        display: block; 
        font-size: 14px; 
        margin-bottom: 4px; 
        color: #2c3e50; 
        font-weight: 600;
    }
    
    .tf-node .dates { 
        font-size: 11px; 
        color: #888; 
        margin-top: 2px;
    }

    .spouse-divider {
        width: 15px;
        height: 1px;
        background: #999;
        margin: 0 2px;
    }
</style>

<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h1><?= e($tree['tree_name']) ?></h1>
            <div class="actions">
                <a href="/family-trees.php" class="btn btn-secondary">Späť na zoznam</a>
            </div>
        </div>
        <div class="card-body tree-container">
            <div class="tf-tree">
                <ul>
                    <?php
                    // Recursive function to render tree
                    function renderNode($person, $families, $parentMap, $individuals) {
                        if (!$person) return;
                        
                        echo '<li>';
                        
                        // Begin Couple Wrapper
                        echo '<div class="couple-wrapper">';
                        
                        // Render Primary Person
                        renderPersonCard($person);
                        
                        // Check if this person is a parent in any family (find spouse)
                        $spouse = null;
                        $children = [];
                        
                        if ($person['gedcom_id'] && isset($parentMap[$person['gedcom_id']])) {
                            // We might have multiple families (marriages), but for this simple tree we usually pick the first one
                            // or loop through them. Standard vertical trees handle multiple spouses poorly without complex logic.
                            // We will take the first family for now.
                            foreach ($parentMap[$person['gedcom_id']] as $famId) {
                                $fam = $families[$famId];
                                
                                if ($fam['husb'] && $fam['husb']['gedcom_id'] !== $person['gedcom_id']) $spouse = $fam['husb'];
                                if ($fam['wife'] && $fam['wife']['gedcom_id'] !== $person['gedcom_id']) $spouse = $fam['wife'];
                                
                                $children = $fam['children'];
                                break; // Only first family
                            }
                        }
                        
                        if ($spouse) {
                            echo '<div class="spouse-divider"></div>';
                            // Get full spouse info if available
                            if ($spouse['gedcom_id'] && isset($individuals[$spouse['gedcom_id']])) {
                                $spouse = $individuals[$spouse['gedcom_id']];
                            }
                            renderPersonCard($spouse);
                        }
                        
                        echo '</div>'; // End Couple Wrapper
                        
                        // Render Children
                        if (!empty($children)) {
                            echo '<ul>';
                            foreach ($children as $child) {
                                $childFull = $child;
                                if ($child['gedcom_id'] && isset($individuals[$child['gedcom_id']])) {
                                    $childFull = $individuals[$child['gedcom_id']];
                                }
                                renderNode($childFull, $families, $parentMap, $individuals);
                            }
                            echo '</ul>';
                        }
                        
                        echo '</li>';
                    }

                    function renderPersonCard($person) {
                        $genderClass = ($person['gender'] === 'M') ? 'male' : (($person['gender'] === 'F') ? 'female' : '');
                        echo '<div class="tf-node ' . $genderClass . '">';
                        echo '<strong>' . e($person['name']) . '</strong>';
                        if ($person['birth'] || $person['death']) {
                            echo '<div class="dates">';
                            echo e($person['birth'] ? date('Y', strtotime($person['birth'])) : '');
                            echo ' - ';
                            echo e($person['death'] ? date('Y', strtotime($person['death'])) : '');
                            echo '</div>';
                        }
                        echo '</div>';
                    }

                    // Render Root Families
                    foreach ($rootFamilies as $famId) {
                        $fam = $families[$famId];
                        $husb = $fam['husb'];
                        $wife = $fam['wife'];
                        
                        echo '<li>';
                        echo '<div class="couple-wrapper">';
                        
                        if ($husb) renderPersonCard($husb);
                        if ($husb && $wife) echo '<div class="spouse-divider"></div>';
                        if ($wife) renderPersonCard($wife);
                        
                        echo '</div>';
                        
                        if (!empty($fam['children'])) {
                            echo '<ul>';
                            foreach ($fam['children'] as $child) {
                                $childFull = $child;
                                if ($child['gedcom_id'] && isset($individuals[$child['gedcom_id']])) {
                                    $childFull = $individuals[$child['gedcom_id']];
                                }
                                renderNode($childFull, $families, $parentMap, $individuals);
                            }
                            echo '</ul>';
                        }
                        echo '</li>';
                    }
                    ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>
