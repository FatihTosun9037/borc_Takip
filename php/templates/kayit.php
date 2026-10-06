<main class="auth">
  <div class="auth-card">
    <p class="brand">Borç Takip</p>
    <h1>Kayıt</h1>
    <p class="muted">Yeni hesap boş başlar. Örnek banka verisi demo hesabındadır.</p>
    <form id="register-form" class="form">
      <label class="field">Ad soyad
        <input name="fullName" required minlength="2" maxlength="80">
      </label>
      <label class="field">E-posta
        <input name="email" type="email" autocomplete="email" required>
      </label>
      <label class="field">Parola
        <input name="password" type="password" autocomplete="new-password" required minlength="8">
      </label>
      <p class="error" data-error hidden></p>
      <button type="submit" class="btn">Hesap oluştur</button>
      <p class="muted">Hesabın var mı? <a href="/giris">Giriş yap</a></p>
    </form>
  </div>
</main>
