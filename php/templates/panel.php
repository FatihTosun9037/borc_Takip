<?php
$accountTypeLabel = ['checking' => 'Vadesiz', 'savings' => 'Birikim', 'credit' => 'Kredi kartı'];
$debtTypeLabel = ['credit_card' => 'Kredi kartı', 'personal_loan' => 'İhtiyaç kredisi', 'mortgage' => 'Konut kredisi', 'other' => 'Diğer'];
$debtStatusLabel = ['active' => 'Aktif', 'paid_off' => 'Kapandı', 'overdue' => 'Gecikmiş'];
$directionLabel = ['inflow' => 'Giriş', 'outflow' => 'Çıkış'];
$strategyLabel = ['snowball' => 'Kar topu', 'avalanche' => 'Çığ'];

$accounts = $panel['accounts'];
$debts = $panel['debts'];
$totals = $panel['totals'];
$monthLabel = format_month($panel['month']['year'], $panel['month']['monthIndex']);
$empty = $accounts === [] && $debts === [];
$openDebts = array_values(array_filter($debts, static fn (array $debt): bool => $debt['status'] !== 'paid_off' && decimal_to_cents($debt['remaining_balance']) > 0));
$minimum = sum_decimal(array_column($openDebts, 'minimum_payment'));
$flowTotal = decimal_to_cents($totals['inflow']) + decimal_to_cents($totals['outflow']);
$inflowShare = $flowTotal === 0 ? 0 : (decimal_to_cents($totals['inflow']) / $flowTotal) * 100;
?>
<main class="wrap stack">
  <?php if ($empty): ?>
    <section>
      <h1>Panel</h1>
      <p class="muted wide">Henüz kayıt yok. Hesap veya borç ekleyince nakit, taksit ve ödeme planı burada görünür.</p>
    </section>
  <?php else: ?>
    <section>
      <h1>Panel</h1>
      <p class="muted wide"><?= e($monthLabel) ?> özeti. Nakit, vadesiz ve birikim hesaplarının toplamıdır. Kredi kartı bakiyesi borç tarafında durur.</p>
    </section>
    <section class="grid-3">
      <article class="summary cash lift"><p class="amount"><?= e(format_try($totals['cash'])) ?></p><p class="muted">Nakit</p></article>
      <article class="summary debt lift"><p class="amount"><?= e(format_try($totals['debt'])) ?></p><p class="muted">Toplam borç</p></article>
      <article class="summary due lift"><p class="amount"><?= e(format_try($totals['due'])) ?></p><p class="muted">Bu ay ödenecek</p></article>
    </section>

    <section class="card">
      <h2>Kredi görünümü</h2>
      <div class="split-head">
        <p class="muted wide">Bu bir Findeks veya KKB notu değildir. Öneriler kendi kayıtlarından üretilir. Kart kullanım oranı, kalan borcun başlangıç tutarına oranıdır.</p>
        <button type="button" class="btn slim" id="refresh-credit">Değerlendirmeyi yenile</button>
      </div>
      <p class="error" data-error hidden></p>
      <?php if ($credit): ?>
        <div class="grid-4">
          <article class="metric lift"><p class="metric-value"><?= e(format_percent($credit['utilizationRatio'])) ?></p><p class="muted">Kart kullanım oranı</p></article>
          <article class="metric lift"><p class="metric-value"><?= e(format_percent($credit['onTimePaymentRatio'])) ?></p><p class="muted">Zamanında ödeme</p></article>
          <article class="metric lift"><p class="metric-value"><?= e((string) $credit['oldestAccountMonths']) ?> ay</p><p class="muted">En eski kayıt</p></article>
          <article class="metric lift"><p class="metric-value"><?= e((string) $credit['latePaymentCount']) ?></p><p class="muted">Gecikme</p></article>
        </div>
        <p class="muted">Son değerlendirme <?= e(format_istanbul_date($credit['assessedAt'])) ?>.</p>
        <?php if ($credit['items'] === []): ?>
          <p class="muted">Açık öneri yok.</p>
        <?php else: ?>
          <ol class="list">
            <?php foreach ($credit['items'] as $item): ?>
              <li class="metric">
                <p class="strong"><?= e($item['priority']) ?>. <?= e($item['title']) ?></p>
                <p class="muted"><?= e($item['detail']) ?></p>
                <?php if ($item['status'] === 'pending'): ?>
                  <div class="actions">
                    <button type="button" class="btn ghost slim" data-action="<?= e($item['id']) ?>" data-status="done">Yapıldı</button>
                    <button type="button" class="btn line slim" data-action="<?= e($item['id']) ?>" data-status="dismissed">Gizle</button>
                  </div>
                <?php else: ?>
                  <p class="muted"><?= e($item['status'] === 'done' ? 'Yapıldı' : 'Gizlendi') ?></p>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Henüz değerlendirme yok.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Ödeme planı</h2>
      <p class="muted wide">Asgari ödemeler her borca gider. Kalan bütçe kar topunda en küçük bakiyeye, çığda en yüksek faizli borca yazılır. Sonuç bir projeksiyondur.</p>
      <?php if ($plan): ?>
        <div class="plan">
          <div class="plan-head">
            <div>
              <span class="badge"><?= e($strategyLabel[$plan['strategy']] ?? $plan['strategy']) ?></span>
              <p class="amount"><?= e(format_try($plan['monthlyBudget'])) ?></p>
              <p class="muted">aylık bütçe</p>
            </div>
            <dl class="stats">
              <div class="stat due">
                <dt class="muted">Kapanış</dt>
                <dd class="strong"><?= $plan['payoffYear'] !== null ? e(format_month($plan['payoffYear'], $plan['payoffMonthIndex'])) : '360 ay içinde yok' ?></dd>
              </div>
              <div class="stat debt">
                <dt class="muted">Toplam faiz</dt>
                <dd class="strong ember"><?= e(format_try($plan['totalInterest'])) ?></dd>
              </div>
            </dl>
          </div>
          <ol class="months">
            <?php foreach ($plan['months'] as $item): ?>
              <li>
                <p class="month"><?= e(format_month($item['year'], $item['monthIndex'])) ?></p>
                <ul class="list">
                  <?php foreach ($item['payments'] as $payment): ?>
                    <li class="pay-row">
                      <span>
                        <span class="strong"><?= e($payment['debtName']) ?></span>
                        <span class="muted tiny">kalan <?= e(format_try($payment['remaining'])) ?></span>
                      </span>
                      <span class="chip"><?= e(format_try($payment['amount'])) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php if ($plan['hasLaterMonths']): ?>
            <p class="note">İlk 6 ay listeleniyor.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <p class="muted">Henüz kayıtlı plan yok.</p>
      <?php endif; ?>
      <?php if ($openDebts !== []): ?>
        <form id="plan-form" class="plan-form">
          <label class="field grow">Aylık bütçe
            <input name="monthlyBudget" inputmode="decimal" placeholder="8000,00" required>
          </label>
          <button type="submit" class="btn">Karşılaştır</button>
        </form>
        <p class="muted">Asgari ödemeler toplamı <?= e(format_try($minimum)) ?>.</p>
        <p class="error" data-error hidden></p>
        <div id="compare-result" class="compare"></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="row-between">
        <h2>Nakit akışı</h2>
        <p class="muted"><?= e($monthLabel) ?></p>
      </div>
      <div class="grid-3">
        <article class="flow cash"><p class="muted">Giriş</p><p class="metric-value"><?= e(format_try($totals['inflow'])) ?></p></article>
        <article class="flow debt"><p class="muted">Çıkış</p><p class="metric-value"><?= e(format_try($totals['outflow'])) ?></p></article>
        <article class="flow net"><p>Net</p><p class="metric-value"><?= e(format_try($totals['net'])) ?></p></article>
      </div>
      <?php if ($flowTotal > 0): ?>
        <div class="bar">
          <span class="in bar-fill" style="width: <?= e((string) $inflowShare) ?>%"></span>
          <span class="out bar-fill" style="width: <?= e((string) (100 - $inflowShare)) ?>%"></span>
        </div>
      <?php else: ?>
        <p class="muted">Bu ay hareket yok.</p>
      <?php endif; ?>
      <?php if ($panel['transactions'] !== []): ?>
        <ul class="list">
          <?php foreach ($panel['transactions'] as $transaction): ?>
            <li class="pill-row <?= $transaction['direction'] === 'inflow' ? 'cash' : 'debt' ?>">
              <span><?= e($transaction['category']) ?> <span class="muted"><?= e($directionLabel[$transaction['direction']]) ?> · <?= e(format_istanbul_date($transaction['occurred_at'])) ?></span></span>
              <span><?= e(format_try($transaction['amount'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <div class="split">
      <section>
        <h2>Hesaplar</h2>
        <?php if ($accounts === []): ?>
          <p class="muted">Henüz hesap yok.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Banka</th><th>IBAN</th><th class="right">Tutar</th></tr></thead>
              <tbody>
                <?php foreach ($accounts as $account): ?>
                  <tr class="<?= $account['account_type'] === 'credit' ? 'debt' : 'cash' ?>">
                    <td><?= e($account['bank_name']) ?><span class="muted block"><?= e($account['account_name']) ?> · <?= e($accountTypeLabel[$account['account_type']] ?? $account['account_type']) ?></span></td>
                    <td class="mono">···· <?= e(trim($account['iban_last4'])) ?></td>
                    <td class="right"><?= e(format_try($account['current_balance'])) ?><?php if ($account['account_type'] === 'credit'): ?><span class="muted block tiny">Kart borcu</span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
      <section>
        <h2>Borçlar</h2>
        <?php if ($debts === []): ?>
          <p class="muted">Henüz borç yok.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Ad</th><th>Faiz</th><th class="right">Kalan</th></tr></thead>
              <tbody>
                <?php foreach ($debts as $debt): ?>
                  <tr class="debt">
                    <td><?= e($debt['name']) ?><span class="muted block"><?= e($debtTypeLabel[$debt['debt_type']] ?? $debt['debt_type']) ?> · <?= e($debtStatusLabel[$debt['status']] ?? $debt['status']) ?> · ayın <?= e((string) $debt['due_day']) ?>. günü</span></td>
                    <td><?= e(format_percent($debt['interest_rate_apr'])) ?></td>
                    <td class="right"><?= e(format_try($debt['remaining_balance'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <section>
      <h2>Yaklaşan taksitler</h2>
      <?php if ($panel['installments'] === []): ?>
        <p class="muted">Yaklaşan taksit yok.</p>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($panel['installments'] as $installment): ?>
            <li class="pill-row due">
              <span><?= e($installment['debt_name']) ?> <span class="muted"><?= e(format_utc_date($installment['due_date'])) ?></span></span>
              <span><?= e(format_try($installment['amount'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="split">
    <details class="card" <?= $empty ? 'open' : '' ?>>
      <summary>Hesap ekle</summary>
      <form id="account-form" class="form-grid">
        <label class="field">Banka<input name="bankName" required minlength="2"></label>
        <label class="field">Hesap adı<input name="accountName" required minlength="2"></label>
        <label class="field">Tür
          <select name="accountType">
            <option value="checking">Vadesiz</option>
            <option value="savings">Birikim</option>
            <option value="credit">Kredi kartı</option>
          </select>
        </label>
        <label class="field">Bakiye<input name="currentBalance" inputmode="decimal" placeholder="0,00" required></label>
        <label class="field span-2">IBAN<input name="iban" autocomplete="off" required></label>
        <p class="error span-2" data-error hidden></p>
        <button type="submit" class="btn span-2">Hesabı ekle</button>
      </form>
    </details>
    <details class="card" <?= $empty ? 'open' : '' ?>>
      <summary>Borç ekle</summary>
      <form id="debt-form" class="form-grid">
        <label class="field">Ad<input name="name" required minlength="2"></label>
        <label class="field">Tür
          <select name="debtType">
            <option value="credit_card">Kredi kartı</option>
            <option value="personal_loan">İhtiyaç kredisi</option>
            <option value="mortgage">Konut kredisi</option>
            <option value="other">Diğer</option>
          </select>
        </label>
        <label class="field">Kalan borç<input name="remainingBalance" inputmode="decimal" placeholder="0,00" required></label>
        <label class="field">Yıllık faiz<input name="interestRateApr" inputmode="decimal" placeholder="39,50" required></label>
        <label class="field">Asgari ödeme<input name="minimumPayment" inputmode="decimal" placeholder="0,00" required></label>
        <label class="field">Vade günü<input name="dueDay" type="number" min="1" max="31" required></label>
        <label class="field span-2">Bağlı hesap
          <select name="bankAccountId">
            <option value="">Yok</option>
            <?php foreach ($accounts as $account): ?>
              <option value="<?= e($account['id']) ?>"><?= e($account['bank_name'] . ' · ' . $account['account_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="error span-2" data-error hidden></p>
        <button type="submit" class="btn span-2">Borcu ekle</button>
      </form>
    </details>
  </div>
</main>
