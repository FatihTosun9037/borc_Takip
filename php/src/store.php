<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $parts = parse_url((string) ($_ENV['DATABASE_URL'] ?? ''));
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('DATABASE_URL eksik.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $parts['host'],
        (int) ($parts['port'] ?? 5432),
        ltrim($parts['path'], '/'),
    );
    $pdo = new PDO($dsn, rawurldecode((string) ($parts['user'] ?? '')), rawurldecode((string) ($parts['pass'] ?? '')), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function session_token(): ?string
{
    $token = $_COOKIE['bt_session'] ?? '';
    return is_string($token) && $token !== '' ? $token : null;
}

function current_user(): ?array
{
    $token = session_token();
    if ($token === null) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT u.id, u.email, u.full_name, s.expires_at
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ?',
    );
    $statement->execute([hash('sha256', $token)]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }
    $expires = new DateTimeImmutable((string) $row['expires_at']);
    if ($expires <= new DateTimeImmutable('now')) {
        return null;
    }

    return [
        'id' => $row['id'],
        'email' => $row['email'],
        'fullName' => $row['full_name'],
    ];
}

function create_session(string $userId): void
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = new DateTimeImmutable('+14 days');
    $pdo = db();
    $pdo->prepare('DELETE FROM sessions WHERE expires_at < now()')->execute();
    $pdo->prepare('INSERT INTO sessions (id, user_id, token_hash, expires_at) VALUES (gen_random_uuid(), ?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), $expires->format(DateTimeInterface::ATOM)]);

    setcookie('bt_session', $token, cookie_base() + [
        'expires' => $expires->getTimestamp(),
        'httponly' => true,
    ]);
}

function destroy_session(): void
{
    $token = session_token();
    if ($token !== null) {
        db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    setcookie('bt_session', '', cookie_base() + [
        'expires' => time() - 3600,
        'httponly' => true,
    ]);
}

function register_user(string $fullName, string $email, string $password): array
{
    if (!defined('PASSWORD_ARGON2ID')) {
        throw new RuntimeException('PHP argon2id desteklemiyor.');
    }
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    try {
        $statement = db()->prepare(
            'INSERT INTO users (id, email, password_hash, full_name, updated_at)
             VALUES (gen_random_uuid(), ?, ?, ?, now())
             RETURNING id',
        );
        $statement->execute([$email, $hash, $fullName]);
        $id = (string) $statement->fetchColumn();
        create_session($id);
        return ['ok' => true];
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') {
            return ['ok' => false, 'status' => 409, 'error' => 'Bu e-posta ile kayıtlı bir hesap var.'];
        }
        throw $error;
    }
}

function login_user(string $email, string $password): array
{
    $statement = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $statement->execute([$email]);
    $user = $statement->fetch();
    $valid = $user && password_verify($password, (string) $user['password_hash']);
    if (!$valid) {
        return ['ok' => false, 'status' => 401, 'error' => 'E-posta veya parola hatalı.'];
    }
    create_session((string) $user['id']);
    return ['ok' => true];
}

function sum_decimal(array $values): string
{
    $cents = 0;
    foreach ($values as $value) {
        $cents += decimal_to_cents((string) $value);
    }
    return cents_to_decimal($cents);
}

function panel_data(string $userId, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $month = istanbul_month_bounds($now);
    $monthStart = sprintf('%04d-%02d-01', $month['year'], $month['monthIndex'] + 1);
    $next = shift_month($month['year'], $month['monthIndex'], 1);
    $monthEnd = sprintf('%04d-%02d-01', $next['year'], $next['monthIndex'] + 1);
    $pdo = db();

    $accounts = $pdo->prepare(
        'SELECT id, bank_name, account_name, account_type, current_balance::text AS current_balance, iban_last4
         FROM bank_accounts WHERE user_id = ? ORDER BY account_type, bank_name',
    );
    $accounts->execute([$userId]);
    $accountRows = $accounts->fetchAll();

    $debts = $pdo->prepare(
        'SELECT id, name, debt_type, remaining_balance::text AS remaining_balance,
                interest_rate_apr::text AS interest_rate_apr, minimum_payment::text AS minimum_payment,
                due_day, status
         FROM debts WHERE user_id = ? ORDER BY remaining_balance DESC',
    );
    $debts->execute([$userId]);
    $debtRows = $debts->fetchAll();

    $installments = $pdo->prepare(
        'SELECT i.id, i.due_date::text AS due_date, i.amount::text AS amount, d.name AS debt_name
         FROM installments i
         JOIN debts d ON d.id = i.debt_id
         WHERE i.status = \'upcoming\' AND d.user_id = ?
         ORDER BY i.due_date
         LIMIT 6',
    );
    $installments->execute([$userId]);

    $due = $pdo->prepare(
        'SELECT i.amount::text AS amount
         FROM installments i
         JOIN debts d ON d.id = i.debt_id
         WHERE i.status = \'upcoming\' AND d.user_id = ? AND i.due_date >= ? AND i.due_date < ?',
    );
    $due->execute([$userId, $monthStart, $monthEnd]);

    $transactions = $pdo->prepare(
        'SELECT id, amount::text AS amount, direction, category, occurred_at::text AS occurred_at
         FROM transactions
         WHERE user_id = ? AND occurred_at >= ? AND occurred_at < ?
         ORDER BY occurred_at DESC
         LIMIT 8',
    );
    $transactions->execute([
        $userId,
        $month['start']->format(DateTimeInterface::ATOM),
        $month['end']->format(DateTimeInterface::ATOM),
    ]);

    $cash = [];
    foreach ($accountRows as $account) {
        if ($account['account_type'] !== 'credit') {
            $cash[] = $account['current_balance'];
        }
    }
    $openDebt = [];
    foreach ($debtRows as $debt) {
        if ($debt['status'] !== 'paid_off') {
            $openDebt[] = $debt['remaining_balance'];
        }
    }
    $transactionRows = $transactions->fetchAll();
    $inflow = [];
    $outflow = [];
    foreach ($transactionRows as $transaction) {
        if ($transaction['direction'] === 'inflow') {
            $inflow[] = $transaction['amount'];
        } else {
            $outflow[] = $transaction['amount'];
        }
    }
    $inflowTotal = sum_decimal($inflow);
    $outflowTotal = sum_decimal($outflow);

    return [
        'accounts' => $accountRows,
        'debts' => $debtRows,
        'installments' => $installments->fetchAll(),
        'transactions' => $transactionRows,
        'month' => $month,
        'totals' => [
            'cash' => sum_decimal($cash),
            'debt' => sum_decimal($openDebt),
            'due' => sum_decimal(array_column($due->fetchAll(), 'amount')),
            'inflow' => $inflowTotal,
            'outflow' => $outflowTotal,
            'net' => cents_to_decimal(decimal_to_cents($inflowTotal) - decimal_to_cents($outflowTotal)),
        ],
    ];
}

function create_account(string $userId, array $input): array
{
    $iban = parse_iban((string) $input['iban']);
    $balance = parse_amount((string) $input['currentBalance']);
    if ($iban === null || $balance === null) {
        return ['ok' => false, 'error' => 'IBAN veya bakiye geçersiz.'];
    }
    $encrypted = encrypt_iban($iban, load_encryption_key());
    db()->prepare(
        'INSERT INTO bank_accounts (
            id, user_id, bank_name, account_name, account_type,
            iban_ciphertext, iban_iv, iban_tag, iban_last4, current_balance, data_source, updated_at
         ) VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, \'manual\', now())',
    )->execute([
        $userId,
        $input['bankName'],
        $input['accountName'],
        $input['accountType'],
        $encrypted['ciphertext'],
        $encrypted['iv'],
        $encrypted['tag'],
        $encrypted['last4'],
        $balance,
    ]);
    return ['ok' => true];
}

function create_debt(string $userId, array $input): array
{
    $remaining = parse_amount((string) $input['remainingBalance']);
    $minimum = parse_amount((string) $input['minimumPayment']);
    $apr = parse_amount((string) $input['interestRateApr']);
    if ($remaining === null || $minimum === null || $apr === null) {
        return ['ok' => false, 'error' => 'Tutar veya faiz geçersiz.'];
    }
    if (decimal_to_cents($remaining) <= 0 || decimal_to_cents($minimum) <= 0) {
        return ['ok' => false, 'error' => 'Kalan borç ve asgari ödeme sıfırdan büyük olmalı.'];
    }
    if ((float) $apr > 200) {
        return ['ok' => false, 'error' => 'Faiz oranı çok yüksek.'];
    }

    $bankAccountId = null;
    if (($input['bankAccountId'] ?? '') !== '') {
        $account = db()->prepare('SELECT id FROM bank_accounts WHERE id = ? AND user_id = ?');
        $account->execute([$input['bankAccountId'], $userId]);
        $found = $account->fetchColumn();
        if (!$found) {
            return ['ok' => false, 'error' => 'Seçilen hesap bu kullanıcıya ait değil.'];
        }
        $bankAccountId = $found;
    }

    $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $started = utc_date((int) $today->format('Y'), (int) $today->format('n') - 1, (int) $today->format('j'));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO debts (
                id, user_id, bank_account_id, name, debt_type, principal_amount, remaining_balance,
                interest_rate_apr, minimum_payment, due_day, status, started_at, updated_at
             ) VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, now())
             RETURNING id',
        );
        $insert->execute([
            $userId,
            $bankAccountId,
            $input['name'],
            $input['debtType'],
            $remaining,
            $remaining,
            $apr,
            $minimum,
            (int) $input['dueDay'],
            $started,
        ]);
        $debtId = (string) $insert->fetchColumn();
        $installment = $pdo->prepare(
            'INSERT INTO installments (id, debt_id, due_date, amount, status)
             VALUES (gen_random_uuid(), ?, ?, ?, \'upcoming\')',
        );
        foreach (upcoming_due_dates((int) $input['dueDay'], 3, $today) as $dueDate) {
            $installment->execute([$debtId, $dueDate, $minimum]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return ['ok' => true];
}

function open_debts(string $userId): array
{
    $statement = db()->prepare(
        'SELECT id, name, remaining_balance::text AS remaining_balance,
                interest_rate_apr::text AS interest_rate_apr, minimum_payment::text AS minimum_payment
         FROM debts
         WHERE user_id = ? AND status <> \'paid_off\' AND remaining_balance > 0
         ORDER BY name',
    );
    $statement->execute([$userId]);
    return array_map(static fn (array $row): array => [
        'id' => $row['id'],
        'name' => $row['name'],
        'balanceCents' => decimal_to_cents($row['remaining_balance']),
        'aprHundredths' => decimal_to_cents($row['interest_rate_apr']),
        'minimumCents' => decimal_to_cents($row['minimum_payment']),
    ], $statement->fetchAll());
}

function compare_plans(string $userId, string $monthlyBudget, ?DateTimeImmutable $now = null): array
{
    $budget = parse_amount($monthlyBudget);
    if ($budget === null || decimal_to_cents($budget) <= 0) {
        return ['ok' => false, 'error' => 'Aylık bütçe sıfırdan büyük olmalı.'];
    }
    $debts = open_debts($userId);
    if ($debts === []) {
        return ['ok' => false, 'error' => 'Plan için aktif borç yok.'];
    }
    $budgetCents = decimal_to_cents($budget);
    $minimumCents = 0;
    foreach ($debts as $debt) {
        $minimumCents += $debt['minimumCents'];
    }
    if ($budgetCents < $minimumCents) {
        return [
            'ok' => false,
            'error' => 'Aylık bütçe asgari ödemelerin altında. En az ' . format_try(cents_to_decimal($minimumCents)) . ' gerekli.',
        ];
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $start = istanbul_month_bounds($now);
    $names = [];
    foreach ($debts as $debt) {
        $names[$debt['id']] = $debt['name'];
    }
    $inputs = array_map(static fn (array $debt): array => [
        'id' => $debt['id'],
        'balanceCents' => $debt['balanceCents'],
        'aprHundredths' => $debt['aprHundredths'],
        'minimumCents' => $debt['minimumCents'],
    ], $debts);

    $summarize = static function (string $strategy) use ($inputs, $budgetCents, $start, $names): array {
        $plan = project_plan($inputs, $budgetCents, $strategy);
        $payoff = $plan['payoffMonthIndex'] === null
            ? null
            : shift_month($start['year'], $start['monthIndex'], $plan['payoffMonthIndex']);
        return [
            'strategy' => $strategy,
            'closed' => $plan['closed'],
            'months' => $plan['months'],
            'totalInterest' => cents_to_decimal($plan['totalInterestCents']),
            'payoffYear' => $payoff['year'] ?? null,
            'payoffMonthIndex' => $payoff['monthIndex'] ?? null,
            'focusDebtName' => $plan['focusDebtId'] ? ($names[$plan['focusDebtId']] ?? null) : null,
        ];
    };

    return [
        'ok' => true,
        'minimumPayment' => cents_to_decimal($minimumCents),
        'snowball' => $summarize('snowball'),
        'avalanche' => $summarize('avalanche'),
    ];
}

function save_plan(string $userId, string $strategy, string $monthlyBudget, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $compared = compare_plans($userId, $monthlyBudget, $now);
    if (!$compared['ok']) {
        return $compared;
    }
    $budget = parse_amount($monthlyBudget);
    if ($budget === null) {
        return ['ok' => false, 'error' => 'Aylık bütçe sıfırdan büyük olmalı.'];
    }
    $debts = open_debts($userId);
    $projection = project_plan($debts, decimal_to_cents($budget), $strategy);
    $start = istanbul_month_bounds($now);
    $payoff = $projection['payoffMonthIndex'] === null
        ? null
        : utc_date($start['year'], $start['monthIndex'] + $projection['payoffMonthIndex'], 1);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM payment_plans WHERE user_id = ?')->execute([$userId]);
        $insert = $pdo->prepare(
            'INSERT INTO payment_plans (id, user_id, strategy, monthly_budget, projected_payoff_date, total_interest)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?)
             RETURNING id',
        );
        $insert->execute([
            $userId,
            $strategy,
            $budget,
            $payoff,
            cents_to_decimal($projection['totalInterestCents']),
        ]);
        $planId = (string) $insert->fetchColumn();
        $step = $pdo->prepare(
            'INSERT INTO plan_steps (id, plan_id, debt_id, month_index, suggested_amount, remaining_after)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?)',
        );
        foreach ($projection['steps'] as $item) {
            $step->execute([
                $planId,
                $item['debtId'],
                $item['monthIndex'],
                cents_to_decimal($item['suggestedCents']),
                cents_to_decimal($item['remainingCents']),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return ['ok' => true];
}

function saved_plan(string $userId): ?array
{
    $statement = db()->prepare(
        'SELECT id, strategy, monthly_budget::text AS monthly_budget, total_interest::text AS total_interest,
                projected_payoff_date::text AS projected_payoff_date, created_at
         FROM payment_plans WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
    );
    $statement->execute([$userId]);
    $plan = $statement->fetch();
    if (!$plan) {
        return null;
    }

    $steps = db()->prepare(
        'SELECT s.id, s.month_index, s.suggested_amount::text AS amount, s.remaining_after::text AS remaining, d.name AS debt_name
         FROM plan_steps s
         JOIN debts d ON d.id = s.debt_id
         WHERE s.plan_id = ? AND s.month_index < 6
         ORDER BY s.month_index ASC, s.suggested_amount DESC',
    );
    $steps->execute([$plan['id']]);
    $max = db()->prepare('SELECT COALESCE(MAX(month_index), 0) FROM plan_steps WHERE plan_id = ?');
    $max->execute([$plan['id']]);

    $start = istanbul_month_bounds(new DateTimeImmutable((string) $plan['created_at']));
    $groups = [];
    foreach ($steps->fetchAll() as $step) {
        $month = shift_month($start['year'], $start['monthIndex'], (int) $step['month_index']);
        $label = $month['year'] . '-' . $month['monthIndex'];
        $payment = [
            'id' => $step['id'],
            'debtName' => $step['debt_name'],
            'amount' => $step['amount'],
            'remaining' => $step['remaining'],
        ];
        $last = array_key_last($groups);
        if ($last === null || $groups[$last]['label'] !== $label) {
            $groups[] = ['label' => $label, 'payments' => [$payment]];
        } else {
            $groups[$last]['payments'][] = $payment;
        }
    }

    $payoff = $plan['projected_payoff_date'];
    $months = [];
    foreach ($groups as $group) {
        [$year, $monthIndex] = array_map('intval', explode('-', $group['label']));
        $months[] = ['year' => $year, 'monthIndex' => $monthIndex, 'payments' => $group['payments']];
    }

    return [
        'strategy' => $plan['strategy'],
        'monthlyBudget' => $plan['monthly_budget'],
        'totalInterest' => $plan['total_interest'],
        'payoffYear' => $payoff ? (int) substr((string) $payoff, 0, 4) : null,
        'payoffMonthIndex' => $payoff ? (int) substr((string) $payoff, 5, 2) - 1 : null,
        'hasLaterMonths' => (int) $max->fetchColumn() >= 6,
        'months' => $months,
    ];
}

function credit_guide(string $userId): ?array
{
    $snapshot = db()->prepare(
        'SELECT id, utilization_ratio::text AS utilization_ratio, on_time_payment_ratio::text AS on_time_payment_ratio,
                oldest_account_months, late_payment_count, assessed_at::text AS assessed_at
         FROM credit_snapshots WHERE user_id = ? ORDER BY assessed_at DESC LIMIT 1',
    );
    $snapshot->execute([$userId]);
    $row = $snapshot->fetch();
    if (!$row) {
        return null;
    }
    $items = db()->prepare(
        'SELECT id, title, detail, priority, status
         FROM action_items
         WHERE snapshot_id = ? AND status <> \'dismissed\'
         ORDER BY priority',
    );
    $items->execute([$row['id']]);
    return [
        'utilizationRatio' => $row['utilization_ratio'],
        'onTimePaymentRatio' => $row['on_time_payment_ratio'],
        'oldestAccountMonths' => (int) $row['oldest_account_months'],
        'latePaymentCount' => (int) $row['late_payment_count'],
        'assessedAt' => $row['assessed_at'],
        'items' => $items->fetchAll(),
    ];
}

function refresh_credit_guide(string $userId, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pdo = db();
    $accounts = $pdo->prepare('SELECT created_at::text AS created_at FROM bank_accounts WHERE user_id = ? ORDER BY created_at ASC');
    $accounts->execute([$userId]);
    $accountRows = $accounts->fetchAll();

    $debts = $pdo->prepare(
        'SELECT name, debt_type, principal_amount::text AS principal_amount, remaining_balance::text AS remaining_balance,
                interest_rate_apr::text AS interest_rate_apr, due_day, status, started_at::text AS started_at
         FROM debts
         WHERE user_id = ? AND status <> \'paid_off\' AND remaining_balance > 0',
    );
    $debts->execute([$userId]);
    $debtRows = $debts->fetchAll();

    $paid = $pdo->prepare(
        'SELECT COUNT(*) FROM installments i JOIN debts d ON d.id = i.debt_id WHERE i.status = \'paid\' AND d.user_id = ?',
    );
    $paid->execute([$userId]);
    $paidCount = (int) $paid->fetchColumn();
    $overdueInstallments = $pdo->prepare(
        'SELECT COUNT(*) FROM installments i JOIN debts d ON d.id = i.debt_id WHERE i.status = \'overdue\' AND d.user_id = ?',
    );
    $overdueInstallments->execute([$userId]);
    $overdueInstallmentCount = (int) $overdueInstallments->fetchColumn();

    $oldestDebt = $pdo->prepare('SELECT started_at::text AS started_at FROM debts WHERE user_id = ? ORDER BY started_at ASC LIMIT 1');
    $oldestDebt->execute([$userId]);
    $oldestDebtRow = $oldestDebt->fetch();
    if ($accountRows === [] && $debtRows === [] && !$oldestDebtRow) {
        return ['ok' => false, 'error' => 'Değerlendirme için borç veya hesap kaydı gerekli.'];
    }

    $dates = [];
    if (isset($accountRows[0]['created_at'])) {
        $dates[] = new DateTimeImmutable((string) $accountRows[0]['created_at']);
    }
    if ($oldestDebtRow) {
        $dates[] = new DateTimeImmutable($oldestDebtRow['started_at'] . ' 00:00:00', new DateTimeZone('UTC'));
    }
    $oldestMonths = 0;
    foreach ($dates as $date) {
        $oldestMonths = max($oldestMonths, elapsed_months($date, $now));
    }
    $history = $paidCount + $overdueInstallmentCount;
    $onTime = $history === 0 ? 10000 : percent_hundredths($paidCount, $history);
    $overdueDebts = 0;
    foreach ($debtRows as $debt) {
        if ($debt['status'] === 'overdue') {
            $overdueDebts += 1;
        }
    }
    $lateCount = $overdueInstallmentCount > 0 ? $overdueInstallmentCount : $overdueDebts;

    $cards = array_values(array_filter($debtRows, static fn (array $debt): bool => $debt['debt_type'] === 'credit_card'));
    $remainingCents = 0;
    $principalCents = 0;
    foreach ($cards as $card) {
        $remainingCents += decimal_to_cents($card['remaining_balance']);
        $principalCents += decimal_to_cents($card['principal_amount']);
    }

    $actions = build_credit_actions([
        'utilizationHundredths' => min(99999, percent_hundredths($remainingCents, $principalCents)),
        'onTimeHundredths' => $onTime,
        'oldestMonths' => $oldestMonths,
        'lateCount' => $lateCount,
        'cards' => array_map(static fn (array $debt): array => [
            'name' => $debt['name'],
            'remainingCents' => decimal_to_cents($debt['remaining_balance']),
        ], $cards),
        'debts' => array_map(static fn (array $debt): array => [
            'name' => $debt['name'],
            'aprHundredths' => decimal_to_cents($debt['interest_rate_apr']),
            'dueDay' => (int) $debt['due_day'],
            'overdue' => $debt['status'] === 'overdue',
        ], $debtRows),
    ]);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO credit_snapshots (
                id, user_id, utilization_ratio, on_time_payment_ratio, oldest_account_months, late_payment_count, assessed_at
             ) VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?)
             RETURNING id',
        );
        $insert->execute([
            $userId,
            cents_to_decimal(min(99999, percent_hundredths($remainingCents, $principalCents))),
            cents_to_decimal($onTime),
            $oldestMonths,
            $lateCount,
            $now->format(DateTimeInterface::ATOM),
        ]);
        $snapshotId = (string) $insert->fetchColumn();
        $item = $pdo->prepare(
            'INSERT INTO action_items (id, user_id, snapshot_id, title, detail, priority)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?)',
        );
        foreach ($actions as $action) {
            $item->execute([$userId, $snapshotId, $action['title'], $action['detail'], $action['priority']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return ['ok' => true];
}

function set_action_status(string $userId, string $actionId, string $status): array
{
    $statement = db()->prepare('UPDATE action_items SET status = ? WHERE id = ? AND user_id = ?');
    $statement->execute([$status, $actionId, $userId]);
    if ($statement->rowCount() === 0) {
        return ['ok' => false, 'error' => 'Aksiyon bulunamadı.'];
    }
    return ['ok' => true];
}
