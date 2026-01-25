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

    .tf-node {
        border: 1px solid #ccc;
        padding: 10px;
        text-decoration: none;
        color: #666;
        font-family: arial, verdana, tahoma;
        font-size: 12px;
        display: inline-block;
        border-radius: 5px;
        -webkit-border-radius: 5px;
        -moz-border-radius: 5px;
        background: white;
        min-width: 120px;
    }

    .tf-node.male { background-color: #e6f7ff; border-color: #91d5ff; }
    .tf-node.female { background-color: #fff0f6; border-color: #ffadd2; }
    
    .tf-node strong { display: block; font-size: 14px; margin-bottom: 5px; color: #333; }
    .tf-node .dates { font-size: 11px; color: #888; }
    
    .spouse-connector {
        position: relative;
        display: inline-block;
        width: 20px;
        border-bottom: 1px solid #ccc;
        vertical-align: middle;
        margin: 0 5px;
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
                        
                        $genderClass = ($person['gender'] === 'M') ? 'male' : (($person['gender'] === 'F') ? 'female' : '');
                        
                        echo '<li>';
                        echo '<div class="tf-node ' . $genderClass . '">';
                        echo '<strong>' . e($person['name']) . '</strong>';
                        if ($person['birth'] || $person['death']) {
                            echo '<div class="dates">';
                            echo e($person['birth'] ? date('Y', strtotime($person['birth'])) : '?');
                            echo ' - ';
                            echo e($person['death'] ? date('Y', strtotime($person['death'])) : '');
                            echo '</div>';
                        }
                        echo '</div>';
                        
                        // Check if this person is a parent in any family
                        if ($person['gedcom_id'] && isset($parentMap[$person['gedcom_id']])) {
                            foreach ($parentMap[$person['gedcom_id']] as $famId) {
                                $fam = $families[$famId];
                                
                                // Find spouse
                                $spouse = null;
                                if ($fam['husb'] && $fam['husb']['gedcom_id'] !== $person['gedcom_id']) $spouse = $fam['husb'];
                                if ($fam['wife'] && $fam['wife']['gedcom_id'] !== $person['gedcom_id']) $spouse = $fam['wife'];
                                
                                if ($spouse) {
                                    // Render spouse next to person (simplified for now, ideally they should be connected)
                                    // In this CSS tree structure, spouses are tricky. 
                                    // Usually we treat the "Couple" as the node.
                                }
                                
                                if (!empty($fam['children'])) {
                                    echo '<ul>';
                                    foreach ($fam['children'] as $child) {
                                        // Find full child info if available
                                        $childFull = $child;
                                        if ($child['gedcom_id'] && isset($individuals[$child['gedcom_id']])) {
                                            $childFull = $individuals[$child['gedcom_id']];
                                        }
                                        renderNode($childFull, $families, $parentMap, $individuals);
                                    }
                                    echo '</ul>';
                                }
                            }
                        }
                        
                        echo '</li>';
                    }

                    // Render Root Families
                    // This is tricky because a family has TWO parents. The CSS tree usually starts with ONE root.
                    // We will try to render the Husband of the root family as the "Root Node" and show Wife next to him.
                    
                    foreach ($rootFamilies as $famId) {
                        $fam = $families[$famId];
                        $husb = $fam['husb'];
                        $wife = $fam['wife'];
                        
                        echo '<li>';
                        echo '<div class="family-node" style="display:inline-block; border:1px solid #ddd; padding:10px; background:#fff;">';
                        
                        if ($husb) {
                            echo '<div class="tf-node male" style="display:inline-block; margin-right:10px;">';
                            echo '<strong>' . e($husb['name']) . '</strong>';
                            echo '</div>';
                        }
                        
                        if ($wife) {
                            echo '<div class="tf-node female" style="display:inline-block;">';
                            echo '<strong>' . e($wife['name']) . '</strong>';
                            echo '</div>';
                        }
                        
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
