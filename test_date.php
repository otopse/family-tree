<?php
echo "Current: " . date('Y-m-d') . "\n";
$inputs = [
    '',
    ' ',
    'now',
    null,
    'unknown',
    '?',
    '0000-00-00',
    '1780.01.17'
];

foreach ($inputs as $val) {
    echo "Input: '$val' -> ";
    if (empty($val)) {
        echo "Empty\n";
        continue;
    }
    $ts = strtotime($val);
    if ($ts) {
        echo date('Y.m.d', $ts) . "\n";
    } else {
        echo "False\n";
    }
}
