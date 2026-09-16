<?php

/**
 * TEMPORARY verification for Asclepius #787 / PRJ2602236.
 *
 * Do not keep this on main. Synthetic fixtures only (no live Business Central).
 * Confirms aanneemsom/omzet counts only Type=GB-rekening and No=800000.
 */

require_once __DIR__ . '/../web/finance_calculations.php';

$expectedSum = 280000.0;

// Modeled on PRJ2602236: contract sum 280.000 on G/L 800000, inflated if
// Item/Resource (and other account) planning lines are included.
$planningLines = [
    [
        'Job_No' => 'PRJ2602236',
        'Type' => 'GB-rekening',
        'No' => '800000',
        'Description' => 'Aanneemsom',
        'Line_Amount_LCY' => 200000.0,
    ],
    [
        'Job_No' => 'PRJ2602236',
        'Type' => 'GB-rekening',
        'No' => '800000',
        'Description' => 'Aanneemsom extra termijn',
        'Line_Amount_LCY' => 80000.0,
    ],
    [
        'Job_No' => 'PRJ2602236',
        'Type' => 'Resource',
        'No' => 'MONTEUR',
        'Description' => 'Distractor resource booking',
        'Line_Amount_LCY' => 45000.0,
    ],
    [
        'Job_No' => 'PRJ2602236',
        'Type' => 'Artikel',
        'No' => 'ITEM-100',
        'Description' => 'Distractor item booking',
        'Line_Amount_LCY' => 32000.0,
    ],
    [
        'Job_No' => 'PRJ2602236',
        'Type' => 'Item',
        'No' => '800000',
        'Description' => 'Distractor: Item with same No as revenue G/L',
        'Line_Amount_LCY' => 9999.0,
    ],
    [
        'Job_No' => 'PRJ2602236',
        'Type' => 'GB-rekening',
        'No' => '611000',
        'Description' => 'Distractor other G/L account',
        'Line_Amount_LCY' => 12500.0,
    ],
];

$included = 0;
$actualSum = 0.0;

foreach ($planningLines as $row) {
    if (!finance_is_revenue_gl_account_line($row)) {
        continue;
    }

    $amount = finance_to_float($row['Line_Amount_LCY'] ?? 0.0);
    if ($amount === 0.0) {
        continue;
    }

    $included++;
    $actualSum = finance_add_amount($actualSum, $amount);
}

$unfilteredSum = 0.0;
foreach ($planningLines as $row) {
    $unfilteredSum = finance_add_amount($unfilteredSum, $row['Line_Amount_LCY'] ?? 0.0);
}

if ($included !== 2) {
    fwrite(STDERR, "FAIL #787: expected 2 included GB-rekening/800000 lines, got {$included}\n");
    exit(1);
}

if (abs($actualSum - $expectedSum) >= 0.005) {
    fwrite(STDERR, 'FAIL #787: expected aanneemsom €' . number_format($expectedSum, 0, ',', '.')
        . ', got €' . number_format($actualSum, 2, ',', '.') . "\n");
    exit(1);
}

if ($unfilteredSum <= $expectedSum) {
    fwrite(STDERR, "FAIL #787: fixture has no distractor inflation (unfiltered={$unfilteredSum})\n");
    exit(1);
}

fwrite(STDOUT, "PASS #787 temporary verification: PRJ2602236-style aanneemsom is €"
    . number_format($actualSum, 0, ',', '.')
    . " (excluded distractors; unfiltered would be €"
    . number_format($unfilteredSum, 0, ',', '.') . ")\n");
exit(0);
