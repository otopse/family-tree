<?php
declare(strict_types=1);

/**
 * Simple GEDCOM Parser
 * Parses a GEDCOM file and imports it into the database.
 */
function parse_and_import_gedcom(string $filePath, int $treeId, int $ownerId): void {
    $content = file_get_contents($filePath);
    if ($content === false) {
        throw new Exception("Cannot read GEDCOM file.");
    }

    // Detect and handle encoding (BOM)
    $bom2 = substr($content, 0, 2);
    $bom3 = substr($content, 0, 3);

    if ($bom3 === "\xEF\xBB\xBF") {
        // UTF-8 BOM
        $content = substr($content, 3);
    } elseif ($bom2 === "\xFF\xFE") {
        // UTF-16LE
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
    } elseif ($bom2 === "\xFE\xFF") {
        // UTF-16BE
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16BE');
    }

    // Normalize line endings
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    
    $lines = explode("\n", $content);

    $debugLog = __DIR__ . '/gedcom_debug.log';
    file_put_contents($debugLog, "\n\n=== GEDCOM IMPORT DEBUG START " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);
    file_put_contents($debugLog, "Starting import for tree $treeId\n", FILE_APPEND);
    file_put_contents($debugLog, "Content length: " . strlen($content) . "\n", FILE_APPEND);
    file_put_contents($debugLog, "Line count: " . count($lines) . "\n", FILE_APPEND);

    $individuals = [];
    $families = [];
    $currentRecord = null;
    $currentType = null;
    $currentId = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Parse line: Level + [Optional ID] + Tag + [Value]
        // Example: 0 @I1@ INDI
        // Example: 1 NAME Jan /Novak/
        if (!preg_match('/^(\d+)\s+(@\w+@)?\s*(\w+)(?:\s+(.*))?$/', $line, $matches)) {
            file_put_contents($debugLog, "Regex failed for line: $line\n", FILE_APPEND);
            continue;
        }

        $level = (int)$matches[1];
        $id = $matches[2] ?? null;
        $tag = $matches[3];
        $value = $matches[4] ?? '';

        if ($level === 0) {
            // Save previous record
            if ($currentRecord && $currentId) {
                if ($currentType === 'INDI') {
                    $individuals[$currentId] = $currentRecord;
                } elseif ($currentType === 'FAM') {
                    $families[$currentId] = $currentRecord;
                }
            }

            // Start new record
            if ($id) {
                $currentId = $id;
                $currentType = $tag;
                $currentRecord = ['id' => $id];
            } else {
                $currentRecord = null;
                $currentType = null;
                $currentId = null;
            }
        } elseif ($currentRecord) {
            // Process details based on tag
            switch ($tag) {
                case 'NAME':
                    $currentRecord['name'] = str_replace('/', '', $value);
                    break;
                case 'SEX':
                    $currentRecord['sex'] = trim($value);
                    break;
                case 'BIRT':
                case 'DEAT':
                    $currentRecord['last_event'] = $tag;
                    break;
                case 'DATE':
                    if (isset($currentRecord['last_event'])) {
                        $event = $currentRecord['last_event'];
                        $currentRecord[$event . '_DATE'] = $value;
                        // Do not unset last_event, as PLAC might follow
                    }
                    break;
                case 'PLAC':
                    if (isset($currentRecord['last_event'])) {
                        $event = $currentRecord['last_event'];
                        $currentRecord[$event . '_PLAC'] = $value;
                        // Do not unset last_event
                    }
                    break;
                case 'HUSB':
                    $currentRecord['husb'] = $value;
                    break;
                case 'WIFE':
                    $currentRecord['wife'] = $value;
                    break;
                case 'CHIL':
                    $currentRecord['children'][] = $value;
                    break;
            }
        }
    }

    // Save last record
    if ($currentRecord && $currentId) {
        if ($currentType === 'INDI') {
            $individuals[$currentId] = $currentRecord;
        } elseif ($currentType === 'FAM') {
            $families[$currentId] = $currentRecord;
        }
    }

    file_put_contents($debugLog, "Individuals found: " . count($individuals) . "\n", FILE_APPEND);
    file_put_contents($debugLog, "Families found: " . count($families) . "\n", FILE_APPEND);
    
    if (empty($families)) {
        file_put_contents($debugLog, "No families found. Dumping individuals keys: " . implode(', ', array_keys($individuals)) . "\n", FILE_APPEND);
    }

    // Import into DB
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    foreach ($families as $famId => $fam) {
        $logMsg = "Processing family: $famId\n";
        $husbId = $fam['husb'] ?? null;
        $wifeId = $fam['wife'] ?? null;
        $childrenIds = $fam['children'] ?? [];

        if ($husbId) $logMsg .= "  Husb: $husbId (" . ($individuals[$husbId]['name'] ?? 'Unknown') . ")\n";
        if ($wifeId) $logMsg .= "  Wife: $wifeId (" . ($individuals[$wifeId]['name'] ?? 'Unknown') . ")\n";
        if (!empty($childrenIds)) $logMsg .= "  Children: " . implode(', ', $childrenIds) . "\n";
        
        file_put_contents($debugLog, $logMsg, FILE_APPEND);

        // Construct pattern (e.g., MZDDD)
        $pattern = '';
        if ($husbId) $pattern .= 'M';
        if ($wifeId) $pattern .= 'Z';
        $pattern .= str_repeat('D', count($childrenIds));

        // Create Record (Family)
        try {
            $stmt = db()->prepare(
                'INSERT INTO ft_records (tree_id, owner, record_name, pattern, created, modified, enabled)
                 VALUES (:tree_id, :owner, :name, :pattern, :created, :modified, 1)'
            );
            
            // Determine record name (Husb Name + Wife Name)
        $recName = 'Rodina';
        $hName = $husbId && isset($individuals[$husbId]) ? $individuals[$husbId]['name'] ?? '' : '';
        $wName = $wifeId && isset($individuals[$wifeId]) ? $individuals[$wifeId]['name'] ?? '' : '';
        
        if ($hName || $wName) {
            $recName = trim("$hName & $wName", " &");
        }

            $stmt->execute([
                'tree_id' => $treeId,
                'owner' => $ownerId,
                'name' => $recName,
                'pattern' => $pattern,
                'created' => $now,
                'modified' => $now
            ]);
            file_put_contents($debugLog, "Inserted record for family $famId\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents($debugLog, "Error inserting family $famId: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
        
        $recordId = (int)db()->lastInsertId();

        // Helper to insert element
        $insertElement = function($indiId, $type, $sortOrder) use ($recordId, $individuals, $debugLog) {
            if (!$indiId || !isset($individuals[$indiId])) return;
            
            $indi = $individuals[$indiId];
            $gender = isset($indi['sex']) ? ($indi['sex'] === 'M' ? 'M' : ($indi['sex'] === 'F' ? 'F' : 'U')) : 'U';
            
            // Parse dates to YYYY.MM.DD, YYYY.MM, or YYYY
            $normalizeDate = function($raw) {
                if (empty($raw)) return null;
                $raw = trim($raw);
                
                // 1. YYYY
                if (preg_match('/^(\d{4})$/', $raw, $m)) {
                    return $m[1];
                }
                
                // 2. GEDCOM format: DD MON YYYY (e.g. 17 JAN 1780)
                // MON map
                $mons = [
                    'JAN' => '01', 'FEB' => '02', 'MAR' => '03', 'APR' => '04', 'MAY' => '05', 'JUN' => '06',
                    'JUL' => '07', 'AUG' => '08', 'SEP' => '09', 'OCT' => '10', 'NOV' => '11', 'DEC' => '12'
                ];
                
                if (preg_match('/^(\d{1,2})\s+([A-Z]{3})\s+(\d{4})$/i', $raw, $m)) {
                    $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                    $monStr = strtoupper($m[2]);
                    $mon = $mons[$monStr] ?? '00';
                    $y = $m[3];
                    if ($mon !== '00') {
                        return "$y.$mon.$d";
                    }
                }
                
                // 3. Just MON YYYY
                if (preg_match('/^([A-Z]{3})\s+(\d{4})$/i', $raw, $m)) {
                    $monStr = strtoupper($m[1]);
                    $mon = $mons[$monStr] ?? '00';
                    $y = $m[2];
                    if ($mon !== '00') {
                        return "$y.$mon";
                    }
                }
                
                // 4. Try strtotime for other formats
                $ts = strtotime($raw);
                if ($ts) {
                    // Check if input has day/month
                    // If input is just "1920", strtotime makes it today's date with time. We already handled "^\d{4}$" above.
                    return date('Y.m.d', $ts);
                }
                
                // Fallback: Return raw string but safe?
                return $raw;
            };

            $bDate = null;
            if (isset($indi['BIRT_DATE'])) {
                $bDate = $normalizeDate($indi['BIRT_DATE']);
            }
            
            $dDate = null;
            if (isset($indi['DEAT_DATE'])) {
                $dDate = $normalizeDate($indi['DEAT_DATE']);
            }

            file_put_contents($debugLog, "  Inserting Element: {$indi['name']} ($type) - Birt: " . ($bDate ?? 'null') . " (Raw: " . ($indi['BIRT_DATE'] ?? 'null') . ")\n", FILE_APPEND);

            $stmt = db()->prepare(
                'INSERT INTO ft_elements (record_id, type, full_name, birth_date, birth_place, death_date, death_place, gender, gedcom_id, sort_order)
                 VALUES (:rid, :type, :name, :bdate, :bplace, :ddate, :dplace, :gender, :gedcom_id, :sort)'
            );
            $stmt->execute([
                'rid' => $recordId,
                'type' => $type,
                'name' => $indi['name'] ?? 'Neznámy',
                'bdate' => $bDate,
                'bplace' => $indi['BIRT_PLAC'] ?? null,
                'ddate' => $dDate,
                'dplace' => $indi['DEAT_PLAC'] ?? null,
                'gender' => $gender,
                'gedcom_id' => $indiId,
                'sort' => $sortOrder
            ]);
        };

        // Insert Husband
        if ($husbId) $insertElement($husbId, 'MUZ', 1);
        
        // Insert Wife
        if ($wifeId) $insertElement($wifeId, 'ZENA', 2);

        // Insert Children
        $sort = 3;
        foreach ($childrenIds as $childId) {
            $insertElement($childId, 'DIETA', $sort++);
        }
    }
}
