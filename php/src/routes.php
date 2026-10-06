<?php

declare(strict_types=1);

function dispatch(string $method, string $path): void
{
    if ($method === 'POST') {
        require_csrf();
    }
    if ($method === 'GET' && $path === '/api/health') {
        json_out(['ok' => true]);
    }
    if ($method === 'POST' && $path === '/api/auth/giris') {
        login_route();
    }
    if ($method === 'POST' && $path === '/api/auth/kayit') {
        register_route();
    }
    if ($method === 'POST' && $path === '/api/auth/cikis') {
        destroy_session();
        json_out(['ok' => true]);
    }
    if ($method === 'POST' && $path === '/api/accounts') {
        account_route();
    }
    if ($method === 'POST' && $path === '/api/debts') {
        debt_route();
    }
    if ($method === 'POST' && $path === '/api/plans/onizleme') {
        plan_preview_route();
    }
    if ($method === 'POST' && $path === '/api/plans') {
        plan_save_route();
    }
    if ($method === 'POST' && $path === '/api/credit/yenile') {
        $user = require_api_user();
        $result = refresh_credit_guide($user['id']);
        if (!$result['ok']) {
            json_out(['error' => $result['error']], 400);
        }
        json_out(['ok' => true]);
    }
    if ($method === 'POST' && preg_match('#^/api/actions/([^/]+)$#', $path, $matches)) {
        action_route($matches[1]);
    }

    if ($method !== 'GET') {
        render('status', [
            'title' => 'Sayfa bulunamadı',
            'body' => 'Bu adres uygulamada yok.',
            'href' => '/panel',
            'action' => 'Panele dön',
            'status' => 404,
        ]);
    }

    if ($path === '/') {
        redirect(current_user() ? '/panel' : '/giris');
    }
    if ($path === '/giris') {
        if (session_token() && !current_user()) {
            destroy_session();
            render('status', [
                'title' => 'Oturum gerekli',
                'body' => 'Bu sayfa için geçerli bir oturum yok. Yeniden giriş yapınca panele dönebilirsin.',
                'href' => '/giris',
                'action' => 'Giriş yap',
                'status' => 401,
            ]);
        }
        if (current_user()) {
            redirect('/panel');
        }
        render('giris');
    }
    if ($path === '/kayit') {
        if (current_user()) {
            redirect('/panel');
        }
        render('kayit');
    }
    if ($path === '/panel') {
        if (!session_token()) {
            redirect('/giris');
        }
        $user = current_user();
        if (!$user) {
            destroy_session();
            render('status', [
                'title' => 'Oturum gerekli',
                'body' => 'Bu sayfa için geçerli bir oturum yok. Yeniden giriş yapınca panele dönebilirsin.',
                'href' => '/giris',
                'action' => 'Giriş yap',
                'status' => 401,
            ]);
        }
        render('panel', [
            'user' => $user,
            'panel' => panel_data($user['id']),
            'plan' => saved_plan($user['id']),
            'credit' => credit_guide($user['id']),
        ]);
    }

    render('status', [
        'title' => 'Sayfa bulunamadı',
        'body' => 'Bu adres uygulamada yok.',
        'href' => '/panel',
        'action' => 'Panele dön',
        'status' => 404,
    ]);
}

function too_many(array $limit): void
{
    if ($limit['allowed']) {
        return;
    }
    json_out(
        ['error' => 'Çok fazla deneme. Bir süre sonra yeniden deneyin.'],
        429,
        ['Retry-After' => (string) $limit['retryAfterSeconds']],
    );
}

function login_route(): never
{
    too_many(consume_rate_limit('giris:' . client_address(), 8, 15 * 60 * 1000));
    $body = read_json();
    if (!is_array($body)) {
        json_out(['error' => 'Geçersiz istek.'], 400);
    }
    $email = clean_email($body['email'] ?? null);
    $password = is_string($body['password'] ?? null) ? (string) $body['password'] : '';
    if (!$email || $password === '' || text_length($password) > 128) {
        json_out(['error' => 'E-posta ve parola gerekli.'], 400);
    }
    $result = login_user($email, $password);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], $result['status']);
    }
    json_out(['ok' => true]);
}

function register_route(): never
{
    too_many(consume_rate_limit('kayit:' . client_address(), 5, 60 * 60 * 1000));
    $body = read_json();
    if (!is_array($body)) {
        json_out(['error' => 'Geçersiz istek.'], 400);
    }
    $name = clean_text($body['fullName'] ?? null, 2, 80);
    $email = clean_email($body['email'] ?? null);
    $password = is_string($body['password'] ?? null) ? (string) $body['password'] : '';
    if (!$name || !$email || text_length($password) < 8 || text_length($password) > 128) {
        json_out(['error' => 'Ad, e-posta ve en az 8 karakterlik parola gerekli.'], 400);
    }
    $result = register_user($name, $email, $password);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], $result['status']);
    }
    json_out(['ok' => true]);
}

function account_route(): never
{
    $user = require_api_user();
    $body = read_json();
    if (!is_array($body)) {
        json_out(['error' => 'Geçersiz istek.'], 400);
    }
    $bankName = clean_text($body['bankName'] ?? null, 2, 80);
    $accountName = clean_text($body['accountName'] ?? null, 2, 80);
    $accountType = $body['accountType'] ?? '';
    $iban = clean_text($body['iban'] ?? null, 15, 42);
    $balance = clean_text($body['currentBalance'] ?? null, 1, 20);
    if (!$bankName || !$accountName || !in_array($accountType, ['checking', 'savings', 'credit'], true) || !$iban || !$balance) {
        json_out(['error' => 'Hesap bilgilerini kontrol edin.'], 400);
    }
    $result = create_account($user['id'], [
        'bankName' => $bankName,
        'accountName' => $accountName,
        'accountType' => $accountType,
        'iban' => $iban,
        'currentBalance' => $balance,
    ]);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], 400);
    }
    json_out(['ok' => true]);
}

function debt_route(): never
{
    $user = require_api_user();
    $body = read_json();
    if (!is_array($body)) {
        json_out(['error' => 'Geçersiz istek.'], 400);
    }
    $name = clean_text($body['name'] ?? null, 2, 80);
    $debtType = $body['debtType'] ?? '';
    $remaining = clean_text($body['remainingBalance'] ?? null, 1, 20);
    $apr = clean_text($body['interestRateApr'] ?? null, 1, 10);
    $minimum = clean_text($body['minimumPayment'] ?? null, 1, 20);
    $dueDay = filter_var($body['dueDay'] ?? null, FILTER_VALIDATE_INT);
    $bankAccountId = $body['bankAccountId'] ?? '';
    $accountOk = $bankAccountId === '' || $bankAccountId === null || (is_string($bankAccountId) && is_uuid($bankAccountId));
    if (
        !$name
        || !in_array($debtType, ['credit_card', 'personal_loan', 'mortgage', 'other'], true)
        || !$remaining
        || !$apr
        || !$minimum
        || $dueDay === false
        || $dueDay < 1
        || $dueDay > 31
        || !$accountOk
    ) {
        json_out(['error' => 'Borç bilgilerini kontrol edin.'], 400);
    }
    $result = create_debt($user['id'], [
        'name' => $name,
        'debtType' => $debtType,
        'remainingBalance' => $remaining,
        'interestRateApr' => $apr,
        'minimumPayment' => $minimum,
        'dueDay' => $dueDay,
        'bankAccountId' => is_string($bankAccountId) ? $bankAccountId : '',
    ]);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], 400);
    }
    json_out(['ok' => true]);
}

function plan_preview_route(): never
{
    $user = require_api_user();
    $body = read_json();
    if (!is_array($body)) {
        json_out(['error' => 'Geçersiz istek.'], 400);
    }
    $budget = clean_text($body['monthlyBudget'] ?? null, 1, 20);
    if (!$budget) {
        json_out(['error' => 'Aylık bütçeyi kontrol edin.'], 400);
    }
    $result = compare_plans($user['id'], $budget);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], 400);
    }
    json_out($result);
}

function plan_save_route(): never
{
    $user = require_api_user();
    $body = read_json();
    if (!is_array($body)) {
        json_out(['error' => 'Geçersiz istek.'], 400);
    }
    $budget = clean_text($body['monthlyBudget'] ?? null, 1, 20);
    $strategy = $body['strategy'] ?? '';
    if (!$budget || !in_array($strategy, ['snowball', 'avalanche'], true)) {
        json_out(['error' => 'Plan bilgilerini kontrol edin.'], 400);
    }
    $result = save_plan($user['id'], $strategy, $budget);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], 400);
    }
    json_out(['ok' => true]);
}

function action_route(string $id): never
{
    $user = require_api_user();
    if (!is_uuid($id)) {
        json_out(['error' => 'Geçersiz kayıt.'], 400);
    }
    $body = read_json();
    if (!is_array($body)) {
        json_out(['error' => 'Geçersiz istek.'], 400);
    }
    $status = $body['status'] ?? '';
    if (!in_array($status, ['done', 'dismissed'], true)) {
        json_out(['error' => 'Aksiyon durumunu kontrol edin.'], 400);
    }
    $result = set_action_status($user['id'], $id, $status);
    if (!$result['ok']) {
        json_out(['error' => $result['error']], 404);
    }
    json_out(['ok' => true]);
}
