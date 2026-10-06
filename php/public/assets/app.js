const strategies = { snowball: "Kar topu", avalanche: "Çığ" };

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
  }[char]));
}

function formatTry(value) {
  return new Intl.NumberFormat("tr-TR", { style: "currency", currency: "TRY" }).format(Number(value));
}

function formatMonth(year, monthIndex) {
  return new Intl.DateTimeFormat("tr-TR", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, monthIndex, 1)));
}

async function postJson(url, body) {
  const token = document.querySelector('meta[name="csrf-token"]')?.content || "";
  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": token,
    },
    body: JSON.stringify(body),
  });
  const payload = await response.json().catch(() => null);
  return { response, payload };
}

function showError(form, message) {
  const slot = form.querySelector("[data-error]");
  if (!slot) return;
  slot.hidden = !message;
  slot.textContent = message || "";
}

function bindForm(id, url, fields, pendingLabel, done) {
  const form = document.getElementById(id);
  if (!form) return;
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button[type='submit']");
    const idle = button.textContent;
    showError(form, "");
    button.disabled = true;
    button.textContent = pendingLabel;
    const data = new FormData(form);
    const body = {};
    for (const field of fields) body[field] = data.get(field);
    const { response, payload } = await postJson(url, body);
    button.disabled = false;
    button.textContent = idle;
    if (!response.ok) {
      showError(form, payload?.error || "İşlem tamamlanamadı.");
      return;
    }
    done(payload, form);
  });
}

bindForm("login-form", "/api/auth/giris", ["email", "password"], "Giriş yapılıyor…", () => {
  window.location.href = "/panel";
});

bindForm(
  "register-form",
  "/api/auth/kayit",
  ["fullName", "email", "password"],
  "Kaydediliyor…",
  () => {
    window.location.href = "/panel";
  },
);

bindForm(
  "account-form",
  "/api/accounts",
  ["bankName", "accountName", "accountType", "currentBalance", "iban"],
  "Ekleniyor…",
  () => window.location.reload(),
);

bindForm(
  "debt-form",
  "/api/debts",
  ["name", "debtType", "remainingBalance", "interestRateApr", "minimumPayment", "dueDay", "bankAccountId"],
  "Ekleniyor…",
  () => window.location.reload(),
);

const logout = document.getElementById("logout");
if (logout) {
  logout.addEventListener("click", async () => {
    logout.disabled = true;
    logout.textContent = "Çıkılıyor…";
    const token = document.querySelector('meta[name="csrf-token"]')?.content || "";
    await fetch("/api/auth/cikis", {
      method: "POST",
      headers: { "X-CSRF-Token": token },
    });
    window.location.href = "/giris";
  });
}

const refresh = document.getElementById("refresh-credit");
if (refresh) {
  refresh.addEventListener("click", async () => {
    const card = refresh.closest("section");
    refresh.disabled = true;
    refresh.textContent = "Hesaplanıyor…";
    const { response, payload } = await postJson("/api/credit/yenile", {});
    if (!response.ok) {
      refresh.disabled = false;
      refresh.textContent = "Değerlendirmeyi yenile";
      showError(card, payload?.error || "Değerlendirme yenilenemedi.");
      return;
    }
    window.location.reload();
  });
}

document.querySelectorAll("[data-action]").forEach((button) => {
  button.addEventListener("click", async () => {
    const id = button.getAttribute("data-action");
    const status = button.getAttribute("data-status");
    button.disabled = true;
    const { response, payload } = await postJson(`/api/actions/${id}`, { status });
    if (!response.ok) {
      button.disabled = false;
      showError(button.closest("section"), payload?.error || "Aksiyon güncellenemedi.");
      return;
    }
    window.location.reload();
  });
});

const planForm = document.getElementById("plan-form");
if (planForm) {
  planForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = planForm.querySelector("button");
    showError(planForm, "");
    button.disabled = true;
    button.textContent = "Hesaplanıyor…";
    const budget = new FormData(planForm).get("monthlyBudget");
    const { response, payload } = await postJson("/api/plans/onizleme", { monthlyBudget: budget });
    button.disabled = false;
    button.textContent = "Karşılaştır";
    const slot = document.getElementById("compare-result");
    slot.innerHTML = "";
    if (!response.ok || !payload?.snowball || !payload?.avalanche) {
      showError(planForm, payload?.error || "Plan hesaplanamadı.");
      return;
    }
    for (const summary of [payload.snowball, payload.avalanche]) {
      slot.append(strategyCard(summary, budget));
    }
  });
}

function strategyCard(summary, budget) {
  const article = document.createElement("article");
  article.className = `strategy lift ${summary.strategy}`;
  const payoff = summary.closed && summary.payoffYear !== null
    ? formatMonth(summary.payoffYear, summary.payoffMonthIndex)
    : `${summary.months} ay içinde yok`;
  const focus = summary.focusDebtName
    ? `İlk ayın fazla ödemesi ${summary.focusDebtName} borcuna gider.`
    : "Fazla ödeme yok; bütçe asgari ödemelere gider.";
  article.innerHTML = `
    <div class="stripe"></div>
    <div class="body">
      <h3>${escapeHtml(strategies[summary.strategy])}</h3>
      <p class="muted">${escapeHtml(focus)}</p>
      <dl class="stats">
        <div class="stat due"><dt class="muted">Kapanış</dt><dd class="strong">${payoff}</dd>${summary.closed ? `<p class="muted tiny">${summary.months} ay</p>` : ""}</div>
        <div class="stat debt"><dt class="muted">Toplam faiz</dt><dd class="strong ember">${formatTry(summary.totalInterest)}</dd></div>
      </dl>
      <button type="button" class="btn ghost slim">Bu planı kaydet</button>
    </div>`;
  article.querySelector("button").addEventListener("click", async (event) => {
    const save = event.currentTarget;
    save.disabled = true;
    save.textContent = "Kaydediliyor…";
    const { response, payload } = await postJson("/api/plans", { monthlyBudget: budget, strategy: summary.strategy });
    if (!response.ok) {
      save.disabled = false;
      save.textContent = "Bu planı kaydet";
      showError(planForm, payload?.error || "Plan kaydedilemedi.");
      return;
    }
    window.location.reload();
  });
  return article;
}
