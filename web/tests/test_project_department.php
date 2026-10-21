<?php

/**
 * Asclepius #1275: een regel toont de afdeling waarop hij geboekt is.
 * De projectkaart (LVS_Global_Dimension_1_Code) blijft apart beschikbaar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

define('ARGUS_MAANDEN_LIBRARY', true);

$authPath = __DIR__ . '/../auth.php';
$createdAuth = false;
if (!is_file($authPath)) {
    $stub = <<<'PHP'
<?php
$baseUrl = 'https://bc.invalid:7148/';
$environment = 'Production';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'test', 'pass' => 'test'],
];
$auth = $auth_list['Production'];
PHP;
    $createdAuth = file_put_contents($authPath, $stub) !== false;
}

try {
    require_once __DIR__ . '/../maanden.php';

    $built = build_month_rows(
        'Koninklijke van Twist',
        '2026-09',
        ['prj2608435' => ['costs' => 10.0, 'revenue' => 0.0, 'resultaat' => -10.0]],
        [
            'prj2608435' => [
                'No' => 'PRJ2608435',
                'Description' => 'Kaart',
                'LVS_Global_Dimension_1_Code' => '50',
            ],
        ],
        [],
        []
    );
    assert_same('50', $built['project_summaries'][0]['Cost_Center'] ?? null, 'zonder grootboekpost blijft de projectkaart de getoonde afdeling');
    assert_same('50', $built['project_summaries'][0]['Project_Department'] ?? null, 'de projectkaart staat apart op de samenvatting');
    assert_same(5, $built['cache_version'] ?? null, 'een nieuwe maand hoort bij cacheversie 5');

    $GLOBALS['demeter_company_environment_map'] = [
        'Koninklijke van Twist' => 'GeenAuth',
    ];
    $wip = [
        'column_data_target' => [
            'grootboekposten_ohw' => [
                'by_project' => [
                    'prj2608435' => [
                        'costs' => 12.0,
                        'revenue' => 4.0,
                        'rows' => [
                            [
                                'Job_No' => 'PRJ2608435',
                                'G_L_Account_No' => '899900',
                                'G_L_Bal_Account_No' => '199900',
                                'WIP_Entry_Amount' => 12,
                                'Global_Dimension_1_Code' => '10',
                                'Posting_Date' => '2026-09-15',
                            ],
                            [
                                'Job_No' => 'PRJ2608435',
                                'G_L_Account_No' => '899901',
                                'G_L_Bal_Account_No' => '199901',
                                'WIP_Entry_Amount' => 4,
                                'Global_Dimension_1_Code' => '20',
                                'Posting_Date' => '2026-09-16',
                            ],
                        ],
                    ],
                    'prjleeg' => [
                        'costs' => 3.0,
                        'revenue' => 0.0,
                        'rows' => [
                            [
                                'Job_No' => 'PRJLEEG',
                                'G_L_Account_No' => '899900',
                                'G_L_Bal_Account_No' => '199900',
                                'WIP_Entry_Amount' => 3,
                                'Global_Dimension_1_Code' => '30',
                            ],
                        ],
                    ],
                ],
            ],
            'project_details' => [
                'by_project' => [
                    'prj2608435' => [
                        'row' => [
                            'No' => 'PRJ2608435',
                            'Description' => 'Kaart',
                            'LVS_Global_Dimension_1_Code' => '50',
                        ],
                    ],
                    'prjleeg' => [
                        'row' => [
                            'No' => 'PRJLEEG',
                            'Description' => 'Lege kaart',
                            'LVS_Global_Dimension_1_Code' => '',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $snapshot = build_snapshot_from_column_wip('Koninklijke van Twist', '2026-09', $wip);
    $byJob = [];
    foreach ($snapshot['project_summaries'] as $summary) {
        if (!is_array($summary)) {
            continue;
        }
        $byJob[strtolower((string) ($summary['Job_No'] ?? ''))] = $summary;
    }

    assert_same('10', $byJob['prj2608435']['Cost_Center'] ?? null, 'verversen toont de geboekte afdeling, niet de projectkaart');
    assert_same('50', $byJob['prj2608435']['Project_Department'] ?? null, 'de projectkaart blijft naast de geboekte afdeling staan');
    assert_same('30', $byJob['prjleeg']['Cost_Center'] ?? null, 'een lege projectkaart toont de geboekte afdeling');
    assert_same('', $byJob['prjleeg']['Project_Department'] ?? null, 'een lege projectkaart blijft leeg in Project_Department');
    assert_same('10', $snapshot['project_breakdowns']['prj2608435']['total_costs_lines'][0]['Global_Dimension_1_Code'] ?? null, 'de specificatie van een grootboekpost houdt zijn eigen dimensie');
    assert_same(5, $snapshot['cache_version'] ?? null, 'een verversing schrijft cacheversie 5');
    assert_same('10', bc_fetch_primary_department_from_breakdown([
        'total_costs_lines' => [
            ['Global_Dimension_1_Code' => ''],
            ['Global_Dimension_1_Code' => '10'],
        ],
        'total_revenue_lines' => [
            ['Global_Dimension_1_Code' => '20'],
        ],
    ]), 'kostenregels gaan voor opbrengstregels');

    $fromBuild = maanden_apply_booked_departments($built, [
        [
            'Job_No' => 'PRJ2608435',
            'G_L_Account_No' => '899900',
            'G_L_Bal_Account_No' => '199900',
            'Global_Dimension_1_Code' => '10',
        ],
    ]);
    assert_same('10', $fromBuild['project_summaries'][0]['Cost_Center'] ?? null, 'opbouwen zet de geboekte afdeling op de regel');
    assert_same('50', $fromBuild['project_summaries'][0]['Project_Department'] ?? null, 'opbouwen laat de projectkaart staan');
} finally {
    if ($createdAuth && is_file($authPath)) {
        unlink($authPath);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK project department\n";
