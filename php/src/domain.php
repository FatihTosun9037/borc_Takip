<?php

declare(strict_types=1);

const MAX_PLAN_MONTHS = 360;
const MONTH_NAMES = [
    'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
    'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık',
];

function decimal_to_cents(string $value): int
{
    $negative = str_starts_with($value, '-');
    $raw = $negative ? substr($value, 1) : $value;
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $cents = ((int) $whole) * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    return $negative ? -$cents : $cents;
}

function cents_to_decimal(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);
    return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function parse_amount(string $raw): ?string
{
    $normalized = preg_replace('/\s+/', '', trim($raw)) ?? '';
    if (str_contains($normalized, ',')) {
        $normalized = str_replace(',', '.', str_replace('.', '', $normalized));
    }
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalized)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
    return $whole . '.' . str_pad($fraction, 2, '0');
}

function format_try(string|int|float $value): string
{
    $raw = is_string($value) ? $value : number_format((float) $value, 2, '.', '');
    $cents = decimal_to_cents($raw);
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);
    $formatted = number_format(intdiv($absolute, 100), 0, ',', '.') . ',' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    return $sign . '₺' . $formatted;
}

function format_percent(string|int|float $value): string
{
    $raw = is_string($value) ? $value : number_format((float) $value, 2, '.', '');
    $cents = decimal_to_cents($raw);
    $absolute = abs($cents);
    return number_format(intdiv($absolute, 100), 0, ',', '.') . ',' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT) . '%';
}

function format_month(int $year, int $monthIndex): string
{
    return MONTH_NAMES[$monthIndex] . ' ' . $year;
}

function format_utc_date(string $value): string
{
    $date = new DateTimeImmutable(substr($value, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
    return (int) $date->format('j') . ' ' . MONTH_NAMES[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
}

function format_istanbul_date(string $value): string
{
    $date = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return (int) $date->format('j') . ' ' . MONTH_NAMES[(int) $date->format('n') - 1];
}

function istanbul_month_bounds(?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $shifted = $now->setTimezone(new DateTimeZone('UTC'))->modify('+3 hours');
    $year = (int) $shifted->format('Y');
    $month = (int) $shifted->format('n');
    $start = (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('UTC')))->modify('-3 hours');
    $endMonth = $month === 12 ? 1 : $month + 1;
    $endYear = $month === 12 ? $year + 1 : $year;
    $end = (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $endYear, $endMonth), new DateTimeZone('UTC')))->modify('-3 hours');

    return [
        'year' => $year,
        'monthIndex' => $month - 1,
        'start' => $start,
        'end' => $end,
    ];
}

function shift_month(int $year, int $monthIndex, int $add): array
{
    $date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($year, $monthIndex + 1 + $add, 1);
    return ['year' => (int) $date->format('Y'), 'monthIndex' => (int) $date->format('n') - 1];
}

function utc_date(int $year, int $monthIndex, int $day): string
{
    $cursor = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($year, $monthIndex + 1, 1);
    $lastDay = (int) $cursor->format('t');
    return $cursor->setDate((int) $cursor->format('Y'), (int) $cursor->format('n'), min($day, $lastDay))->format('Y-m-d');
}

function upcoming_due_dates(int $dueDay, int $count, ?DateTimeImmutable $from = null): array
{
    $from ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $from = $from->setTimezone(new DateTimeZone('UTC'));
    $year = (int) $from->format('Y');
    $monthIndex = (int) $from->format('n') - 1;
    if ((int) $from->format('j') > $dueDay) {
        $monthIndex += 1;
    }

    $dates = [];
    for ($index = 0; $index < $count; $index += 1) {
        $cursor = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($year, $monthIndex + 1 + $index, 1);
        $dates[] = utc_date((int) $cursor->format('Y'), (int) $cursor->format('n') - 1, $dueDay);
    }
    return $dates;
}

function monthly_interest_cents(int $balanceCents, int $aprHundredths): int
{
    if ($balanceCents <= 0 || $aprHundredths <= 0) {
        return 0;
    }
    $product = $balanceCents * $aprHundredths;
    $divisor = 120000;
    $quotient = intdiv($product, $divisor);
    $remainder = $product % $divisor;
    return $quotient + ($remainder * 2 >= $divisor ? 1 : 0);
}

function pick_target(array $debts, string $strategy): ?array
{
    $open = array_values(array_filter($debts, static fn (array $debt): bool => $debt['balance'] > 0));
    if ($open === []) {
        return null;
    }
    usort($open, static function (array $left, array $right) use ($strategy): int {
        if ($strategy === 'snowball') {
            if ($left['balance'] !== $right['balance']) {
                return $left['balance'] <=> $right['balance'];
            }
            if ($left['aprHundredths'] !== $right['aprHundredths']) {
                return $right['aprHundredths'] <=> $left['aprHundredths'];
            }
        } elseif ($left['aprHundredths'] !== $right['aprHundredths']) {
            return $right['aprHundredths'] <=> $left['aprHundredths'];
        } elseif ($left['balance'] !== $right['balance']) {
            return $left['balance'] <=> $right['balance'];
        }
        return $left['id'] <=> $right['id'];
    });
    return $open[0];
}

function project_plan(array $debts, int $budgetCents, string $strategy): array
{
    $working = array_map(static fn (array $debt): array => [
        'id' => $debt['id'],
        'balance' => $debt['balanceCents'],
        'aprHundredths' => $debt['aprHundredths'],
        'minimum' => $debt['minimumCents'],
    ], $debts);
    $minimumCents = 0;
    foreach ($working as $debt) {
        $minimumCents += max($debt['minimum'], 0);
    }
    $steps = [];
    $totalInterestCents = 0;
    $payoffMonthIndex = null;
    $focusDebtId = null;
    $allClosed = static function (array $rows): bool {
        foreach ($rows as $debt) {
            if ($debt['balance'] > 0) {
                return false;
            }
        }
        return true;
    };

    if ($allClosed($working)) {
        return [
            'strategy' => $strategy,
            'closed' => true,
            'months' => 0,
            'payoffMonthIndex' => null,
            'totalInterestCents' => 0,
            'minimumCents' => $minimumCents,
            'focusDebtId' => null,
            'steps' => [],
        ];
    }

    for ($monthIndex = 0; $monthIndex < MAX_PLAN_MONTHS; $monthIndex += 1) {
        if ($allClosed($working)) {
            break;
        }
        foreach ($working as &$debt) {
            if ($debt['balance'] <= 0) {
                continue;
            }
            $interest = monthly_interest_cents($debt['balance'], $debt['aprHundredths']);
            $debt['balance'] += $interest;
            $totalInterestCents += $interest;
        }
        unset($debt);

        $paid = [];
        $budgetLeft = $budgetCents;
        foreach ($working as &$debt) {
            if ($debt['balance'] <= 0 || $budgetLeft <= 0) {
                continue;
            }
            $minimumDue = min($debt['minimum'], $debt['balance']);
            $payment = min($minimumDue, $budgetLeft);
            $debt['balance'] -= $payment;
            $budgetLeft -= $payment;
            $paid[$debt['id']] = $payment;
        }
        unset($debt);

        while ($budgetLeft > 0) {
            $target = pick_target($working, $strategy);
            if ($target === null) {
                break;
            }
            foreach ($working as &$debt) {
                if ($debt['id'] !== $target['id']) {
                    continue;
                }
                $payment = min($debt['balance'], $budgetLeft);
                $debt['balance'] -= $payment;
                $budgetLeft -= $payment;
                $paid[$debt['id']] = ($paid[$debt['id']] ?? 0) + $payment;
                if ($monthIndex === 0 && $focusDebtId === null) {
                    $focusDebtId = $debt['id'];
                }
                break;
            }
            unset($debt);
        }

        foreach ($working as $debt) {
            $suggested = $paid[$debt['id']] ?? 0;
            if ($suggested <= 0) {
                continue;
            }
            $steps[] = [
                'debtId' => $debt['id'],
                'monthIndex' => $monthIndex,
                'suggestedCents' => $suggested,
                'remainingCents' => $debt['balance'],
            ];
        }

        if ($allClosed($working)) {
            $payoffMonthIndex = $monthIndex;
            break;
        }
    }

    if ($payoffMonthIndex === null) {
        return [
            'strategy' => $strategy,
            'closed' => false,
            'months' => MAX_PLAN_MONTHS,
            'payoffMonthIndex' => null,
            'totalInterestCents' => $totalInterestCents,
            'minimumCents' => $minimumCents,
            'focusDebtId' => $focusDebtId,
            'steps' => $steps,
        ];
    }

    return [
        'strategy' => $strategy,
        'closed' => true,
        'months' => $payoffMonthIndex + 1,
        'payoffMonthIndex' => $payoffMonthIndex,
        'totalInterestCents' => $totalInterestCents,
        'minimumCents' => $minimumCents,
        'focusDebtId' => $focusDebtId,
        'steps' => $steps,
    ];
}

function percent_hundredths(int $part, int $total): int
{
    if ($total <= 0 || $part <= 0) {
        return 0;
    }
    $product = $part * 10000;
    $quotient = intdiv($product, $total);
    $remainder = $product % $total;
    return $quotient + ($remainder * 2 >= $total ? 1 : 0);
}

function elapsed_months(DateTimeInterface $from, DateTimeInterface $to): int
{
    $fromUtc = DateTimeImmutable::createFromInterface($from)->setTimezone(new DateTimeZone('UTC'));
    $toUtc = DateTimeImmutable::createFromInterface($to)->setTimezone(new DateTimeZone('UTC'));
    $months = ((int) $toUtc->format('Y') - (int) $fromUtc->format('Y')) * 12
        + ((int) $toUtc->format('n') - (int) $fromUtc->format('n'));
    if ((int) $toUtc->format('j') < (int) $fromUtc->format('j')) {
        $months -= 1;
    }
    return max(0, $months);
}

function format_hundredths(int $value): string
{
    $absolute = abs($value);
    return intdiv($absolute, 100) . ',' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function tr_compare(string $left, string $right): int
{
    if (class_exists(Collator::class)) {
        static $collator = null;
        $collator ??= new Collator('tr_TR');
        return $collator->compare($left, $right) <=> 0;
    }
    return strcmp($left, $right);
}

function build_credit_actions(array $input): array
{
    $drafts = [];
    $overdue = array_values(array_filter($input['debts'], static fn (array $debt): bool => $debt['overdue']));

    $earliest = $input['debts'];
    usort($earliest, static function (array $left, array $right): int {
        if ($left['dueDay'] !== $right['dueDay']) {
            return $left['dueDay'] <=> $right['dueDay'];
        }
        return tr_compare($left['name'], $right['name']);
    });
    $highest = $input['debts'];
    usort($highest, static function (array $left, array $right): int {
        if ($left['aprHundredths'] !== $right['aprHundredths']) {
            return $right['aprHundredths'] <=> $left['aprHundredths'];
        }
        return tr_compare($left['name'], $right['name']);
    });
    $cards = $input['cards'];
    usort($cards, static function (array $left, array $right): int {
        if ($left['remainingCents'] !== $right['remainingCents']) {
            return $right['remainingCents'] <=> $left['remainingCents'];
        }
        return tr_compare($left['name'], $right['name']);
    });

    if ($input['lateCount'] > 0 || $overdue !== []) {
        $named = $overdue[0] ?? ($earliest[0] ?? null);
        $lateText = $input['lateCount'] > 0 ? $input['lateCount'] . ' gecikme kayıtlı. ' : 'Gecikmiş borç var. ';
        $drafts[] = [
            'title' => 'Gecikmeyi kapat, sıradaki taksiti öne al',
            'detail' => $named
                ? $lateText . $named['name'] . ' borcunu ayın ' . $named['dueDay'] . '. gününden önce ödemek yeni gecikmeyi önler.'
                : $lateText . 'Sıradaki taksiti vade gününden önce öde.',
        ];
    }

    if ($input['utilizationHundredths'] > 3000) {
        $card = $cards[0] ?? null;
        $rate = format_hundredths($input['utilizationHundredths']);
        $drafts[] = [
            'title' => 'Kart kullanımını %30 altına indir',
            'detail' => $card
                ? 'Kullanım oranı %' . $rate . '. ' . $card['name'] . ' bakiyesi bu oranı yukarı çekiyor. Kalan borcu başlangıç tutarının yüzde 30\'unun altına indirmek oranı düşürür.'
                : 'Kullanım oranı %' . $rate . '. Kart bakiyesini başlangıç tutarının yüzde 30\'unun altına indirmek dosyayı rahatlatır.',
        ];
    }

    $costly = $highest[0] ?? null;
    if ($costly && $costly['aprHundredths'] >= 4000) {
        $drafts[] = [
            'title' => 'En yüksek faizli borca fazla ödeme ayır',
            'detail' => $costly['name'] . ' yıllık faizi %' . format_hundredths($costly['aprHundredths']) . '. Çığ planında bütçeden artan tutar bu borca gider.',
        ];
    }

    if ($input['onTimeHundredths'] < 10000 && $input['lateCount'] === 0 && $overdue === []) {
        $drafts[] = [
            'title' => 'Ödemeleri vadeden önce yap',
            'detail' => 'Zamanında ödeme oranı %' . format_hundredths($input['onTimeHundredths']) . '. Taksiti vade gününden birkaç gün önce ödemek bu oranı yükseltir.',
        ];
    }

    if ($input['oldestMonths'] < 12) {
        $drafts[] = [
            'title' => 'En eski hesabı açık tut',
            'detail' => 'En eski kayıt ' . $input['oldestMonths'] . ' aylık. Kısa geçmişi olan hesabı kapatmak dosyayı zayıflatır.',
        ];
    }

    if ($drafts === []) {
        $drafts[] = [
            'title' => 'Mevcut düzeni koru',
            'detail' => 'Kullanım oranı yüzde 30\'un altında ve gecikme yok. Asgari ödemeleri zamanında sürdürmek bu görünümü korur.',
        ];
    }

    $actions = [];
    foreach (array_slice($drafts, 0, 4) as $index => $draft) {
        $actions[] = [
            'priority' => $index + 1,
            'title' => $draft['title'],
            'detail' => $draft['detail'],
        ];
    }
    return $actions;
}

function normalize_iban(string $iban): string
{
    return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
}

function parse_iban(string $raw): ?string
{
    $normalized = normalize_iban($raw);
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $normalized)) {
        return null;
    }
    return $normalized;
}

function load_encryption_key(): string
{
    $raw = trim((string) ($_ENV['DATA_ENCRYPTION_KEY'] ?? ''));
    if ($raw === '') {
        throw new RuntimeException('DATA_ENCRYPTION_KEY eksik');
    }
    $key = base64_decode($raw, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('DATA_ENCRYPTION_KEY 32 bayt base64 olmalı');
    }
    return $key;
}

function encrypt_iban(string $iban, string $key): array
{
    $normalized = normalize_iban($iban);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($normalized, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('IBAN şifrelenemedi.');
    }
    return [
        'ciphertext' => base64_encode($cipher),
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'last4' => substr($normalized, -4),
    ];
}
