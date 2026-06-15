<?php
// Konzertplaner — Einrichtungsdialog (nur Standalone)
// Wird vom Einstiegspunkt geladen, solange config/config.php fehlt.
// Legt beim Absenden die Konfiguration mit dem Passwort-Hash an.

declare(strict_types=1);

if (!defined('KP_CONFIG_FILE')) {
    http_response_code(403);
    exit('Direktaufruf nicht erlaubt');
}

$setupError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Schutz gegen Doppel-Einrichtung (z. B. zwei offene Tabs)
    if (is_file(KP_CONFIG_FILE)) {
        header('Location: ./');
        exit;
    }
    $pw1 = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (strlen($pw1) < 8) {
        $setupError = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($pw1 !== $pw2) {
        $setupError = 'Die Passwörter stimmen nicht überein.';
    } else {
        if (!is_dir(KP_CONFIG_DIR)) {
            @mkdir(KP_CONFIG_DIR, 0775, true);
        }
        // Schutz-.htaccess sicherstellen, falls sie im Paket fehlt oder entfernt wurde
        $ht = KP_CONFIG_DIR . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht,
                "# Konzertplaner: Konfigurations-Verzeichnis, kein direkter Zugriff\n"
                . "Options -Indexes\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        $hash = password_hash($pw1, PASSWORD_DEFAULT);
        $config = "<?php\n"
            . "// Konzertplaner — Konfiguration (automatisch erzeugt am " . date('Y-m-d H:i') . ")\n"
            . "// Diese Datei wird bei Updates NIE überschrieben.\n"
            . "define('KP_PASSWORD_HASH', " . var_export($hash, true) . ");\n";
        $tmp = KP_CONFIG_FILE . '.tmp';
        $ok = @file_put_contents($tmp, $config, LOCK_EX) !== false && @rename($tmp, KP_CONFIG_FILE);
        if ($ok) {
            header('Location: ./');
            exit;
        }
        @unlink($tmp);
        $setupError = 'Konfiguration konnte nicht gespeichert werden. Bitte Schreibrechte im Ordner config/ prüfen.';
    }
}

?><!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Konzertplaner — Einrichtung</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(KP_ASSET_URL, ENT_QUOTES, 'UTF-8') ?>/konzertplaner.css">
</head>
<body class="view-login">
  <main class="login-wrap">
    <div class="login-box">
      <h1>Konzertplaner</h1>
      <p class="login-hint">Willkommen! Lege zum Start ein Passwort fest, mit dem du dich künftig anmeldest.</p>
      <form method="post">
        <label>
          <span>Passwort (mind. 8 Zeichen)</span>
          <input type="password" name="password" id="setup-pw" minlength="8" required autofocus
                 autocomplete="new-password" aria-describedby="setup-error"
                 <?= $setupError !== '' ? 'aria-invalid="true"' : '' ?>>
        </label>
        <label>
          <span>Passwort wiederholen</span>
          <input type="password" name="password2" id="setup-pw2" minlength="8" required
                 autocomplete="new-password" aria-describedby="setup-error"
                 <?= $setupError !== '' ? 'aria-invalid="true"' : '' ?>>
        </label>
        <div id="setup-error" class="login-error" role="alert" <?= $setupError === '' ? 'hidden' : '' ?>><?= htmlspecialchars($setupError, ENT_QUOTES, 'UTF-8') ?></div>
        <button type="submit" class="primary login-btn">Einrichtung abschließen</button>
      </form>
    </div>
  </main>
</body>
</html>
