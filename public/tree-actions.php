<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = require_login();

// Helper to return JSON response
function jsonResponse(bool $success, string $message, array $data = []): void {
  header('Content-Type: application/json');
  echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jsonResponse(false, 'Neplatná metóda.');
}

$action = $_POST['action'] ?? '';
$treeId = (int)($_POST['tree_id'] ?? 0);

if (!$treeId) {
  jsonResponse(false, 'Chýba ID rodokmeňa.');
}

// Verify ownership
$stmt = db()->prepare('SELECT * FROM family_trees WHERE id = :id AND owner = :owner');
$stmt->execute(['id' => $treeId, 'owner' => $user['id']]);
$tree = $stmt->fetch();

if (!$tree) {
  jsonResponse(false, 'Rodokmeň neexistuje alebo k nemu nemáte prístup.');
}

// ---------------------------------------------------------
// ACTION: INIT (Clear imputed dates)
// ---------------------------------------------------------
if ($action === 'init') {
    try {
        // Find all records belonging to this tree
        // ft_elements -> record_id -> ft_records -> tree_id
        
        // We only want to clear dates that match the imputed format '[YYYY]'
        // or potentially other imputed formats if we change them. 
        // For now, we assume imputed dates are stored as '[...]'
        
        $sql = "UPDATE ft_elements e
                JOIN ft_records r ON e.record_id = r.id
                SET e.birth_date = NULL
                WHERE r.tree_id = :tree_id
                  AND e.birth_date LIKE '[%]'";
        
        $stmt = db()->prepare($sql);
        $stmt->execute(['tree_id' => $treeId]);
        $count = $stmt->rowCount();
        
        // Also clear death dates if we ever impute them (current logic mostly does birth)
        $sqlDeath = "UPDATE ft_elements e
                JOIN ft_records r ON e.record_id = r.id
                SET e.death_date = NULL
                WHERE r.tree_id = :tree_id
                  AND e.death_date LIKE '[%]'";
        $stmtDeath = db()->prepare($sqlDeath);
        $stmtDeath->execute(['tree_id' => $treeId]);
        
        jsonResponse(true, "Inicializácia úspešná. Vymazaných $count vypočítaných dátumov.");
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Chyba databázy: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------
// ACTION: CALCULATE (Impute dates)
// ---------------------------------------------------------
elseif ($action === 'calculate') {
    try {
        // 1. Fetch all data needed
        $stmt = db()->prepare('SELECT * FROM ft_records WHERE tree_id = :tree_id');
        $stmt->execute(['tree_id' => $treeId]);
        $rawRecords = $stmt->fetchAll();

        if (empty($rawRecords)) {
            jsonResponse(true, 'Rodokmeň je prázdny.');
        }

        $recordIds = array_column($rawRecords, 'id');
        $inQuery = implode(',', array_fill(0, count($recordIds), '?'));
        
        $stmt = db()->prepare("SELECT * FROM ft_elements WHERE record_id IN ($inQuery) ORDER BY sort_order ASC");
        $stmt->execute($recordIds);
        $allElements = $stmt->fetchAll();

        // Map ID -> Element for updates
        $elementsById = [];
        foreach ($allElements as $el) {
            $elementsById[$el['id']] = $el;
        }

        // Group by Record
        $elementsByRecord = [];
        foreach ($allElements as $el) {
            $elementsByRecord[$el['record_id']][] = $el;
        }

        // Helper: Get Year
        $getYear = function($dateStr) {
            if (empty($dateStr)) return null;
            $dateStr = trim($dateStr);
            // Ignore existing imputed dates for calculation base? 
            // Or treat them as valid? 
            // If we ran "Init" before, they are gone. 
            // If we run "Calculate" on top of existing, we might want to respect them or overwrite.
            // Let's assume we treat [YYYY] as a valid year YYYY for calculation purposes.
            $cleanStr = str_replace(['[', ']'], '', $dateStr);
            
            if (preg_match('/^(\d{4})/', $cleanStr, $m)) {
                return (int)$m[1];
            }
            $ts = strtotime($cleanStr);
            return $ts ? (int)date('Y', $ts) : null;
        };

        // Global Map
        $globalYears = [];
        foreach ($allElements as $e) {
            $gedId = $e['gedcom_id'] ?? null;
            if (!$gedId) continue;
            $y = $getYear($e['birth_date'] ?? '');
            if ($y !== null) {
                $globalYears[$gedId] = $y;
            }
        }

        // Updates to perform
        $updates = []; // element_id -> new_date_string

        // 3 Iterations
        for ($iter = 1; $iter <= 3; $iter++) {
            foreach ($rawRecords as $record) {
                $els = $elementsByRecord[$record['id']] ?? [];
                
                $man = null; $woman = null; $children = [];
                foreach ($els as $e) {
                    if ($e['type'] === 'MUZ') $man = $e;
                    elseif ($e['type'] === 'ZENA') $woman = $e;
                    elseif ($e['type'] === 'DIETA') $children[] = $e;
                }

                // Get current years (including pending updates from this batch if we applied them? No, let's accumulate)
                // Actually, for iteration to work, we need to update our "view" of the data ($globalYears) immediately.
                
                $getEffectiveYear = function($el) use ($getYear, $updates, $globalYears) {
                    if (!$el) return null;
                    // Check pending updates first
                    if (isset($updates[$el['id']])) {
                        return $getYear($updates[$el['id']]);
                    }
                    // Then check Global Map (strongest source for linked persons)
                    if (isset($el['gedcom_id']) && isset($globalYears[$el['gedcom_id']])) {
                        return $globalYears[$el['gedcom_id']];
                    }
                    // Then check DB
                    return $getYear($el['birth_date'] ?? '');
                };

                $manYear = $getEffectiveYear($man);
                $womanYear = $getEffectiveYear($woman);

                // Update Global if we found something
                if ($manYear !== null && isset($man['gedcom_id'])) $globalYears[$man['gedcom_id']] = $manYear;
                if ($womanYear !== null && isset($woman['gedcom_id'])) $globalYears[$woman['gedcom_id']] = $womanYear;

                // Children Years
                $childrenYears = [];
                $oldestChildYear = null;
                foreach ($children as $child) {
                    $cy = $getEffectiveYear($child);
                    $childrenYears[$child['id']] = $cy;
                    
                    if ($cy !== null) {
                        if (isset($child['gedcom_id'])) $globalYears[$child['gedcom_id']] = $cy;
                        if ($oldestChildYear === null || $cy < $oldestChildYear) {
                            $oldestChildYear = $cy;
                        }
                    }
                }

                // LOGIC
                // 1. Woman from Oldest Child
                if ($womanYear === null && $oldestChildYear !== null && $woman) {
                    $womanYear = $oldestChildYear - 20;
                    $newVal = "[{$womanYear}]";
                    $updates[$woman['id']] = $newVal;
                    if (isset($woman['gedcom_id'])) $globalYears[$woman['gedcom_id']] = $womanYear;
                }

                // 2. Man from Oldest Child (if not from Woman)
                if ($manYear === null && $oldestChildYear !== null && $man) {
                    $manYear = $oldestChildYear - 30;
                    $newVal = "[{$manYear}]";
                    $updates[$man['id']] = $newVal;
                    if (isset($man['gedcom_id'])) $globalYears[$man['gedcom_id']] = $manYear;
                }

                // 3. Spouse from Spouse
                if ($manYear !== null && $womanYear === null && $woman) {
                    $womanYear = $manYear + 10;
                    $newVal = "[{$womanYear}]";
                    $updates[$woman['id']] = $newVal;
                    if (isset($woman['gedcom_id'])) $globalYears[$woman['gedcom_id']] = $womanYear;
                }
                elseif ($womanYear !== null && $manYear === null && $man) {
                    $manYear = $womanYear - 10;
                    $newVal = "[{$manYear}]";
                    $updates[$man['id']] = $newVal;
                    if (isset($man['gedcom_id'])) $globalYears[$man['gedcom_id']] = $manYear;
                }

                // 4. Children from Mother/Siblings
                $prevChildYear = null;
                foreach ($children as $index => $child) {
                    $cy = $childrenYears[$child['id']];
                    
                    if ($cy === null) {
                        if ($index === 0 && $womanYear !== null) {
                            $cy = $womanYear + 20;
                            $newVal = "[{$cy}]";
                            $updates[$child['id']] = $newVal;
                            $childrenYears[$child['id']] = $cy; // update local tracking
                            if (isset($child['gedcom_id'])) $globalYears[$child['gedcom_id']] = $cy;
                        }
                        elseif ($index > 0 && $prevChildYear !== null) {
                            $cy = $prevChildYear + 3;
                            $newVal = "[{$cy}]";
                            $updates[$child['id']] = $newVal;
                            $childrenYears[$child['id']] = $cy;
                            if (isset($child['gedcom_id'])) $globalYears[$child['gedcom_id']] = $cy;
                        }
                    }
                    
                    if ($cy !== null) {
                        $prevChildYear = $cy;
                    }
                }
            }
        }

        // Apply Updates to DB
        $count = 0;
        $updateStmt = db()->prepare('UPDATE ft_elements SET birth_date = :bdate WHERE id = :id');
        
        foreach ($updates as $id => $val) {
            // Only update if current value is empty or matches imputed pattern (to avoid overwriting real dates if logic messed up)
            // But logic above shouldn't have produced updates for existing years unless they were missing.
            // However, we should check if the DB currently has a value.
            $currentVal = $elementsById[$id]['birth_date'] ?? '';
            if (empty($currentVal) || (strpos($currentVal, '[') === 0)) {
                $updateStmt->execute(['bdate' => $val, 'id' => $id]);
                $count++;
            }
        }

        jsonResponse(true, "Výpočet dokončený. Aktualizovaných $count záznamov.");

    } catch (PDOException $e) {
        jsonResponse(false, 'Chyba databázy: ' . $e->getMessage());
    }
}
// ---------------------------------------------------------
// ACTION: SAVE_RECORD (Save edited record from modal)
// ---------------------------------------------------------
elseif ($action === 'save_record') {
    try {
        $recordId = (int)($_POST['record_id'] ?? 0);
        if (!$recordId) {
            jsonResponse(false, 'Chýba ID záznamu.');
        }

        // Verify record belongs to this tree
        $stmt = db()->prepare('SELECT * FROM ft_records WHERE id = :id AND tree_id = :tree_id');
        $stmt->execute(['id' => $recordId, 'tree_id' => $treeId]);
        $record = $stmt->fetch();
        if (!$record) {
            jsonResponse(false, 'Záznam neexistuje alebo nepatrí do tohto rodokmeňa.');
        }

        // Parse input data
        $manText = trim($_POST['man'] ?? '');
        $womanText = trim($_POST['woman'] ?? '');
        $childrenTexts = $_POST['children'] ?? [];

        // Helper to parse name and dates from text like "Name (1805 - 1845)" or "Name (1805.01.01 - 1845.12.31)"
        function parsePersonText($text) {
            $text = trim($text);
            if (empty($text)) return ['name' => '', 'birth' => '', 'death' => ''];
            
            // Extract dates in parentheses: "Name (1805 - 1845)" or "Name (1805)" or "Name (1805.01.01 - 1845.12.31)"
            $name = $text;
            $birth = '';
            $death = '';
            
            if (preg_match('/^(.+?)\s*\(([^)]+)\)/', $text, $matches)) {
                $name = trim($matches[1]);
                $dates = trim($matches[2]);
                
                // Try "YYYY - YYYY" format
                if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $dates, $dMatches)) {
                    $birth = $dMatches[1];
                    $death = $dMatches[2];
                }
                // Try "YYYY.MM.DD - YYYY.MM.DD" format
                elseif (preg_match('/^(\d{4}\.\d{2}\.\d{2})\s*-\s*(\d{4}\.\d{2}\.\d{2})$/', $dates, $dMatches)) {
                    $birth = $dMatches[1];
                    $death = $dMatches[2];
                }
                // Try "YYYY-MM-DD - YYYY-MM-DD" format
                elseif (preg_match('/^(\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})$/', $dates, $dMatches)) {
                    $birth = $dMatches[1];
                    $death = $dMatches[2];
                }
                // Try single year "YYYY"
                elseif (preg_match('/^(\d{4})$/', $dates, $dMatches)) {
                    $birth = $dMatches[1];
                }
                // Try single date "YYYY.MM.DD" or "YYYY-MM-DD"
                elseif (preg_match('/^(\d{4}[\.-]\d{2}[\.-]\d{2})$/', $dates, $dMatches)) {
                    $birth = $dMatches[1];
                }
                // If no match, keep dates as-is (might be fictional date like [1805])
                else {
                    $birth = $dates;
                }
            }
            
            return ['name' => $name, 'birth' => $birth, 'death' => $death];
        }

        // Get existing elements for this record
        $stmt = db()->prepare('SELECT * FROM ft_elements WHERE record_id = :record_id ORDER BY sort_order ASC');
        $stmt->execute(['record_id' => $recordId]);
        $existingElements = $stmt->fetchAll();

        // Update or create elements
        $manParsed = parsePersonText($manText);
        $womanParsed = parsePersonText($womanText);

        // Update man
        $manEl = null;
        foreach ($existingElements as $el) {
            if ($el['type'] === 'MUZ') {
                $manEl = $el;
                break;
            }
        }
        if ($manParsed['name']) {
            if ($manEl) {
                $stmt = db()->prepare('UPDATE ft_elements SET full_name = :name, birth_date = :birth, death_date = :death WHERE id = :id');
                $stmt->execute([
                    'name' => $manParsed['name'],
                    'birth' => $manParsed['birth'] ?: null,
                    'death' => $manParsed['death'] ?: null,
                    'id' => $manEl['id']
                ]);
            } else {
                $stmt = db()->prepare('INSERT INTO ft_elements (record_id, type, full_name, birth_date, death_date, gender, sort_order) VALUES (:rid, :type, :name, :birth, :death, :gender, 0)');
                $stmt->execute([
                    'rid' => $recordId,
                    'type' => 'MUZ',
                    'name' => $manParsed['name'],
                    'birth' => $manParsed['birth'] ?: null,
                    'death' => $manParsed['death'] ?: null,
                    'gender' => 'M'
                ]);
            }
        } elseif ($manEl) {
            // Delete if empty
            $stmt = db()->prepare('DELETE FROM ft_elements WHERE id = :id');
            $stmt->execute(['id' => $manEl['id']]);
        }

        // Update woman
        $womanEl = null;
        foreach ($existingElements as $el) {
            if ($el['type'] === 'ZENA') {
                $womanEl = $el;
                break;
            }
        }
        if ($womanParsed['name']) {
            if ($womanEl) {
                $stmt = db()->prepare('UPDATE ft_elements SET full_name = :name, birth_date = :birth, death_date = :death WHERE id = :id');
                $stmt->execute([
                    'name' => $womanParsed['name'],
                    'birth' => $womanParsed['birth'] ?: null,
                    'death' => $womanParsed['death'] ?: null,
                    'id' => $womanEl['id']
                ]);
            } else {
                $stmt = db()->prepare('INSERT INTO ft_elements (record_id, type, full_name, birth_date, death_date, gender, sort_order) VALUES (:rid, :type, :name, :birth, :death, :gender, 1)');
                $stmt->execute([
                    'rid' => $recordId,
                    'type' => 'ZENA',
                    'name' => $womanParsed['name'],
                    'birth' => $womanParsed['birth'] ?: null,
                    'death' => $womanParsed['death'] ?: null,
                    'gender' => 'F'
                ]);
            }
        } elseif ($womanEl) {
            $stmt = db()->prepare('DELETE FROM ft_elements WHERE id = :id');
            $stmt->execute(['id' => $womanEl['id']]);
        }

        // Update children
        $childElements = [];
        foreach ($existingElements as $el) {
            if ($el['type'] === 'DIETA') {
                $childElements[] = $el;
            }
        }

        $sortOrder = 2;
        foreach ($childrenTexts as $index => $childText) {
            $childParsed = parsePersonText($childText);
            if ($childParsed['name']) {
                if (isset($childElements[$index])) {
                    $stmt = db()->prepare('UPDATE ft_elements SET full_name = :name, birth_date = :birth, death_date = :death, sort_order = :sort WHERE id = :id');
                    $stmt->execute([
                        'name' => $childParsed['name'],
                        'birth' => $childParsed['birth'] ?: null,
                        'death' => $childParsed['death'] ?: null,
                        'sort' => $sortOrder,
                        'id' => $childElements[$index]['id']
                    ]);
                } else {
                    $stmt = db()->prepare('INSERT INTO ft_elements (record_id, type, full_name, birth_date, death_date, gender, sort_order) VALUES (:rid, :type, :name, :birth, :death, :gender, :sort)');
                    $stmt->execute([
                        'rid' => $recordId,
                        'type' => 'DIETA',
                        'name' => $childParsed['name'],
                        'birth' => $childParsed['birth'] ?: null,
                        'death' => $childParsed['death'] ?: null,
                        'gender' => 'U',
                        'sort' => $sortOrder
                    ]);
                }
                $sortOrder++;
            }
        }

        // Delete excess children
        for ($i = count($childrenTexts); $i < count($childElements); $i++) {
            $stmt = db()->prepare('DELETE FROM ft_elements WHERE id = :id');
            $stmt->execute(['id' => $childElements[$i]['id']]);
        }

        jsonResponse(true, 'Záznam bol úspešne uložený.');

    } catch (PDOException $e) {
        jsonResponse(false, 'Chyba databázy: ' . $e->getMessage());
    }
}
else {
    jsonResponse(false, 'Neznáma akcia.');
}
