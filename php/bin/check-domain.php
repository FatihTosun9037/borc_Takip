<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/domain.php';

$debts = [
    ['id' => 'bonus', 'balanceCents' => decimal_to_cents('15420.30'), 'aprHundredths' => decimal_to_cents('49.80'), 'minimumCents' => decimal_to_cents('2313.05')],
    ['id' => 'loan', 'balanceCents' => decimal_to_cents('86400.00'), 'aprHundredths' => decimal_to_cents('39.50'), 'minimumCents' => decimal_to_cents('4120.00')],
    ['id' => 'aidat', 'balanceCents' => decimal_to_cents('2750.40'), 'aprHundredths' => decimal_to_cents('42.90'), 'minimumCents' => decimal_to_cents('412.56')],
];
$budget = decimal_to_cents('8000.00');
$snowball = project_plan($debts, $budget, 'snowball');
$avalanche = project_plan($debts, $budget, 'avalanche');

$checks = [
    'snowball interest' => [$snowball['totalInterestCents'], 3522946],
    'avalanche interest' => [$avalanche['totalInterestCents'], 3515982],
    'avalanche decimal' => [cents_to_decimal($avalanche['totalInterestCents']), '35159.82'],
    'snowball focus' => [$snowball['focusDebtId'], 'aidat'],
    'avalanche focus' => [$avalanche['focusDebtId'], 'bonus'],
    'months' => [$avalanche['months'], 18],
];

$failed = false;
foreach ($checks as $name => [$actual, $expected]) {
    if ($actual !== $expected) {
        fwrite(STDERR, "$name: expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . PHP_EOL);
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo "domain ok\n";
