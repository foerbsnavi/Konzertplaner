<?php
// Konzertplaner — Selbst-Update (nur Standalone)
// Muster: CMF UpdateController. Ersetzt ausschließlich System-Dateien
// (core/, index.php, version.json, .htaccess); config/ und daten/ werden
// niemals angefasst. Vor jedem Update entsteht ein Backup, Rollback möglich.

declare(strict_types=1);

if (!defined('KP_BASE_DIR')) {
    http_response_code(403);
    exit('Direktaufruf nicht erlaubt');
}

// Nur diese Server dürfen Updates liefern (ausschließlich HTTPS)
const KP_UPDATE_ALLOWED_HOSTS = ['konzertplaner.brosemedien.de'];
const KP_UPDATE_VERSION_PATH  = '/files/konzertplaner_version.json';

// System-Dateien/-Ordner, die ein Update ersetzt (relativ zur Installations-Wurzel).
// Die Konstanten sind nur noch der FALLBACK für Pakete, deren Inhalt nicht lesbar
// ist — normalerweise leitet kp_update_package_manifest() die Liste dynamisch aus
// dem Paket ab. Grund: Der Updater der INSTALLIERTEN Version führt das Update aus;
// eine fixe Liste würde neue Wurzel-Dateien (z. B. LICENSE) nie ankommen lassen.
const KP_UPDATE_SYSTEM_DIRS  = ['core'];
const KP_UPDATE_SYSTEM_FILES = ['index.php', 'version.json', '.htaccess', 'README.txt', 'LIZENZEN.txt', 'LICENSE'];
// Diese Einträge fasst ein Update NIEMALS an (Nutzerdaten + Konfiguration)
const KP_UPDATE_PROTECTED    = ['config', 'daten'];

// Leitet aus dem entpackten Paket ab, welche Ordner/Dateien zu übernehmen sind.
// Alles auf oberster Ebene außer den geschützten Einträgen — so kommen auch
// künftig neue Wurzel-Dateien/Ordner bei Bestandsinstallationen an.
function kp_update_package_manifest(string $tmpDir): array {
    $dirs  = [];
    $files = [];
    foreach (scandir($tmpDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, KP_UPDATE_PROTECTED, true)) continue;
        if (is_dir($tmpDir . '/' . $item)) $dirs[] = $item;
        else $files[] = $item;
    }
    if (!in_array('core', $dirs, true)) {
        // Unplausibles Paket → konservativer Fallback auf die bekannten Listen
        return [KP_UPDATE_SYSTEM_DIRS, KP_UPDATE_SYSTEM_FILES];
    }
    return [$dirs, $files];
}

function kp_update_read_version_file(): array {
    $raw = @file_get_contents(KP_VERSION_FILE);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : ['version' => '0.0.0', 'update_url' => ''];
}

// Validiert die Update-URL: HTTPS + Host aus der Allowlist, sonst null.
function kp_update_validated_base_url(): ?string {
    $info = kp_update_read_version_file();
    $url  = rtrim((string)($info['update_url'] ?? ''), '/');
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    if (($parts['scheme'] ?? '') !== 'https') return null;
    if (!in_array($parts['host'] ?? '', KP_UPDATE_ALLOWED_HOSTS, true)) return null;
    return 'https://' . $parts['host'];
}

function kp_update_http_get(string $url, int $timeout = 30): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Konzertplaner-Updater',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return (is_string($body) && $code === 200) ? $body : null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

// Lädt eine Datei streamend in eine Zieldatei (ZIP nicht komplett im RAM halten)
function kp_update_http_download(string $url, string $dest, int $timeout = 120): bool {
    if (function_exists('curl_init')) {
        $fh = @fopen($dest, 'wb');
        if ($fh === false) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Konzertplaner-Updater',
        ]);
        $ok = curl_exec($ch) === true;
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if (!$ok || $code !== 200) {
            @unlink($dest);
            return false;
        }
        return true;
    }
    // Fallback ohne curl: ebenfalls streamen statt das ZIP komplett in den RAM zu laden
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false) return false;
    $out = @fopen($dest, 'wb');
    if ($out === false) {
        fclose($in);
        return false;
    }
    $copied = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($copied === false || $copied === 0) {
        @unlink($dest);
        return false;
    }
    return true;
}

// Holt die Versions-Info vom Update-Server: ['version','date','changelog','download_url']
function kp_update_remote_info(): ?array {
    $base = kp_update_validated_base_url();
    if ($base === null) return null;
    $raw = kp_update_http_get($base . KP_UPDATE_VERSION_PATH, 15);
    if ($raw === null) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['version'])) return null;
    return $data;
}

function kp_update_check(): array {
    $local  = kp_update_read_version_file();
    $remote = kp_update_remote_info();
    if ($remote === null) {
        return ['ok' => false, 'error' => 'Update-Server nicht erreichbar'];
    }
    $cur = (string)($local['version'] ?? '0.0.0');
    $new = (string)($remote['version'] ?? '0.0.0');
    return [
        'ok'               => true,
        'current_version'  => $cur,
        'remote_version'   => $new,
        'update_available' => version_compare($new, $cur, '>'),
        'changelog'        => $remote['changelog'] ?? [],
        // Fürs UI: Rollback nur anbieten, wenn ein Backup existiert
        'has_backup'       => count(kp_update_list_backups()) > 0,
    ];
}

function kp_update_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) kp_update_rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

function kp_update_copy_dir(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst) && !@mkdir($dst, 0775, true)) return false;
    $items = scandir($src) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!kp_update_copy_dir($s, $d)) return false;
        } else {
            if (!@copy($s, $d)) return false;
        }
    }
    return true;
}

// Entpackt das ZIP mit Zip-Slip-Schutz (keine "..", keine absoluten Pfade)
function kp_update_extract_zip(string $zipFile, string $target): ?string {
    if (!class_exists('ZipArchive')) return 'PHP-Erweiterung zip fehlt';
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return 'ZIP kann nicht geöffnet werden';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        // Backslashes normalisieren, damit auch Windows-artige ZIP-Pfade geprüft werden
        $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($name === '' || $name[0] === '/' || strpos($name, '..') !== false || preg_match('/^[a-zA-Z]:/', $name)) {
            $zip->close();
            return 'ZIP enthält unzulässige Pfade';
        }
    }
    if (!@mkdir($target, 0775, true) && !is_dir($target)) {
        $zip->close();
        return 'Temp-Ordner kann nicht angelegt werden';
    }
    $ok = $zip->extractTo($target);
    $zip->close();
    if (!$ok) return 'Entpacken fehlgeschlagen';
    // Sicherheitsnetz: alle entpackten Pfade müssen im Zielordner liegen
    $targetReal = realpath($target);
    if ($targetReal === false) return 'Temp-Ordner nicht auflösbar';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $real = realpath((string)$f);
        if ($real === false || strpos($real, $targetReal) !== 0) return 'Pfad-Validierung fehlgeschlagen';
    }
    return null;
}

function kp_update_backup_dir_path(): string {
    return KP_CONFIG_DIR . '/.update_backup_' . date('Ymd_His');
}

function kp_update_list_backups(): array {
    $dirs = glob(KP_CONFIG_DIR . '/.update_backup_*', GLOB_ONLYDIR) ?: [];
    rsort($dirs); // neuestes zuerst
    return $dirs;
}

// Stellt Ordner + Wurzel-Dateien aus einem Backup wieder her (für den
// automatischen Sofort-Restore im Fehlerpfad des Updates).
function kp_update_restore_from(string $backup, array $dirs, array $files): bool {
    $ok = true;
    foreach ($dirs as $d) {
        if (!is_dir($backup . '/' . $d)) continue;
        kp_update_rrmdir(KP_BASE_DIR . '/' . $d);
        $ok = kp_update_copy_dir($backup . '/' . $d, KP_BASE_DIR . '/' . $d) && $ok;
    }
    foreach ($files as $f) {
        if (is_file($backup . '/' . $f)) {
            $ok = @copy($backup . '/' . $f, KP_BASE_DIR . '/' . $f) && $ok;
        }
    }
    return $ok;
}

// Führt das Update aus. Liefert ['ok'=>bool, 'error'=>?, 'version'=>?]
function kp_update_run(): array {
    // Sperre gegen parallele Update-Läufe (Doppelklick, zweiter Tab). Das Handle
    // bleibt offen; PHP gibt die Sperre am Request-Ende automatisch frei.
    $kpUpdateLock = @fopen(KP_CONFIG_DIR . '/.update.lock', 'c');
    if ($kpUpdateLock !== false && !@flock($kpUpdateLock, LOCK_EX | LOCK_NB)) {
        fclose($kpUpdateLock);
        return ['ok' => false, 'error' => 'Ein Update läuft bereits — bitte einen Moment warten.'];
    }

    // Reste abgebrochener früherer Läufe wegräumen (Staging-/Alt-Ordner)
    foreach (glob(KP_BASE_DIR . '/core.{neu,alt}_*', GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $rest) {
        kp_update_rrmdir($rest);
    }

    $remote = kp_update_remote_info();
    if ($remote === null) return ['ok' => false, 'error' => 'Update-Server nicht erreichbar'];

    $local = kp_update_read_version_file();
    if (!version_compare((string)$remote['version'], (string)($local['version'] ?? '0.0.0'), '>')) {
        return ['ok' => false, 'error' => 'Keine neuere Version verfügbar'];
    }

    // Download-URL validieren: gleicher Host wie der Update-Server, nur HTTPS
    $base = kp_update_validated_base_url();
    $dl   = (string)($remote['download_url'] ?? '');
    if ($dl !== '' && $dl[0] === '/') $dl = $base . $dl;
    $parts = parse_url($dl);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || !in_array($parts['host'] ?? '', KP_UPDATE_ALLOWED_HOSTS, true)) {
        return ['ok' => false, 'error' => 'Ungültige Download-URL'];
    }

    $stamp   = date('Ymd_His');
    $tmpZip  = KP_CONFIG_DIR . '/.update_' . $stamp . '.zip';
    $tmpDir  = KP_CONFIG_DIR . '/.update_tmp_' . $stamp;
    if (!kp_update_http_download($dl, $tmpZip, 120) || (int)@filesize($tmpZip) < 1000) {
        @unlink($tmpZip);
        return ['ok' => false, 'error' => 'Download fehlgeschlagen'];
    }

    $err = kp_update_extract_zip($tmpZip, $tmpDir);
    @unlink($tmpZip);
    if ($err !== null) {
        kp_update_rrmdir($tmpDir);
        return ['ok' => false, 'error' => $err];
    }

    // Integrität: Kern-Dateien müssen im Paket vorhanden sein
    if (!is_file($tmpDir . '/core/app.php') || !is_file($tmpDir . '/index.php') || !is_file($tmpDir . '/version.json')) {
        kp_update_rrmdir($tmpDir);
        return ['ok' => false, 'error' => 'Update-Paket unvollständig'];
    }

    // Zu übernehmende Ordner/Dateien aus dem Paket ableiten (config/ und daten/
    // sind grundsätzlich ausgenommen) — so kommen auch neue Wurzel-Dateien an.
    [$pkgDirs, $pkgFiles] = kp_update_package_manifest($tmpDir);

    // Backup des aktuellen Ist-Zustands (alles, was gleich ersetzt wird)
    $backup = kp_update_backup_dir_path();
    if (!@mkdir($backup, 0775, true)) {
        kp_update_rrmdir($tmpDir);
        return ['ok' => false, 'error' => 'Backup-Ordner kann nicht angelegt werden'];
    }
    $backupOk = true;
    foreach ($pkgDirs as $d) {
        if (is_dir(KP_BASE_DIR . '/' . $d)) {
            $backupOk = $backupOk && kp_update_copy_dir(KP_BASE_DIR . '/' . $d, $backup . '/' . $d);
        }
    }
    foreach ($pkgFiles as $f) {
        if (is_file(KP_BASE_DIR . '/' . $f)) {
            $backupOk = $backupOk && @copy(KP_BASE_DIR . '/' . $f, $backup . '/' . $f);
        }
    }
    if (!$backupOk) {
        kp_update_rrmdir($tmpDir);
        kp_update_rrmdir($backup);
        return ['ok' => false, 'error' => 'Backup fehlgeschlagen, Update abgebrochen'];
    }

    // core/ atomar tauschen: erst VOLLSTÄNDIG nach core.neu_* kopieren, dann
    // Rename-Swap. So gibt es keinen Moment, in dem core/ halb kopiert ist —
    // vorher konnte ein Fehler mitten im Kopieren die Installation lahmlegen,
    // und ausgerechnet der Rollback-Endpunkt läuft selbst über core/app.php.
    $stage  = KP_BASE_DIR . '/core.neu_' . $stamp;
    $oldDir = KP_BASE_DIR . '/core.alt_' . $stamp;
    if (!kp_update_copy_dir($tmpDir . '/core', $stage)) {
        kp_update_rrmdir($stage);
        kp_update_rrmdir($tmpDir);
        return ['ok' => false, 'error' => 'Kopieren fehlgeschlagen — Installation unverändert'];
    }
    if (!@rename(KP_BASE_DIR . '/core', $oldDir)) {
        kp_update_rrmdir($stage);
        kp_update_rrmdir($tmpDir);
        return ['ok' => false, 'error' => 'core/ konnte nicht getauscht werden — Installation unverändert'];
    }
    if (!@rename($stage, KP_BASE_DIR . '/core')) {
        @rename($oldDir, KP_BASE_DIR . '/core'); // sofort zurücktauschen
        kp_update_rrmdir($stage);
        kp_update_rrmdir($tmpDir);
        return ['ok' => false, 'error' => 'core/ konnte nicht getauscht werden — vorherige Version wiederhergestellt'];
    }
    kp_update_rrmdir($oldDir);

    // Übrige Ordner + Wurzel-Dateien ersetzen; bei Fehlern automatisch
    // KOMPLETT aus dem eben erstellten Backup wiederherstellen.
    foreach ($pkgDirs as $d) {
        if ($d === 'core') continue;
        kp_update_rrmdir(KP_BASE_DIR . '/' . $d);
        if (!kp_update_copy_dir($tmpDir . '/' . $d, KP_BASE_DIR . '/' . $d)) {
            kp_update_restore_from($backup, $pkgDirs, $pkgFiles);
            kp_update_rrmdir($tmpDir);
            return ['ok' => false, 'error' => "Ordner {$d} konnte nicht ersetzt werden — vorherige Version automatisch wiederhergestellt"];
        }
    }
    foreach ($pkgFiles as $f) {
        if (is_file($tmpDir . '/' . $f) && !@copy($tmpDir . '/' . $f, KP_BASE_DIR . '/' . $f)) {
            kp_update_restore_from($backup, $pkgDirs, $pkgFiles);
            kp_update_rrmdir($tmpDir);
            return ['ok' => false, 'error' => "Datei {$f} konnte nicht ersetzt werden — vorherige Version automatisch wiederhergestellt"];
        }
    }

    kp_update_rrmdir($tmpDir);

    // Alte Backups aufräumen (nur das neueste behalten)
    $backups = kp_update_list_backups();
    foreach (array_slice($backups, 1) as $old) {
        kp_update_rrmdir($old);
    }

    if (function_exists('opcache_reset')) @opcache_reset();

    return ['ok' => true, 'version' => (string)$remote['version']];
}

// Stellt das neueste Backup wieder her — mit demselben Rename-Swap wie das
// Update selbst, damit auch ein fehlgeschlagener Rollback die Installation
// nie in einem halben Zustand zurücklässt.
function kp_update_rollback(): array {
    // Gleiche Sperre wie kp_update_run: beide manipulieren core/ per Rename-Swap
    // und dürfen nie gleichzeitig laufen.
    $kpUpdateLock = @fopen(KP_CONFIG_DIR . '/.update.lock', 'c');
    if ($kpUpdateLock !== false && !@flock($kpUpdateLock, LOCK_EX | LOCK_NB)) {
        fclose($kpUpdateLock);
        return ['ok' => false, 'error' => 'Ein Update läuft gerade — bitte einen Moment warten.'];
    }

    $backups = kp_update_list_backups();
    if (!$backups) return ['ok' => false, 'error' => 'Kein Backup vorhanden'];
    $backup = $backups[0];
    if (!is_dir($backup . '/core')) return ['ok' => false, 'error' => 'Backup unvollständig'];

    $stamp  = date('Ymd_His');
    $stage  = KP_BASE_DIR . '/core.neu_' . $stamp;
    $oldDir = KP_BASE_DIR . '/core.alt_' . $stamp;
    kp_update_rrmdir($stage);
    if (!kp_update_copy_dir($backup . '/core', $stage)) {
        kp_update_rrmdir($stage);
        return ['ok' => false, 'error' => 'Wiederherstellen fehlgeschlagen — Installation unverändert'];
    }
    if (!@rename(KP_BASE_DIR . '/core', $oldDir)) {
        kp_update_rrmdir($stage);
        return ['ok' => false, 'error' => 'core/ konnte nicht getauscht werden — Installation unverändert'];
    }
    if (!@rename($stage, KP_BASE_DIR . '/core')) {
        @rename($oldDir, KP_BASE_DIR . '/core');
        kp_update_rrmdir($stage);
        return ['ok' => false, 'error' => 'core/ konnte nicht getauscht werden — Installation unverändert'];
    }
    kp_update_rrmdir($oldDir);

    // Alles Übrige (weitere Ordner + Wurzel-Dateien) aus dem Backup zurückspielen —
    // der Inhalt des Backups spiegelt genau das, was das Update ersetzt hatte.
    foreach (scandir($backup) ?: [] as $item) {
        if ($item === '.' || $item === '..' || $item === 'core') continue;
        if (in_array($item, KP_UPDATE_PROTECTED, true)) continue;
        $src = $backup . '/' . $item;
        if (is_dir($src)) {
            kp_update_rrmdir(KP_BASE_DIR . '/' . $item);
            kp_update_copy_dir($src, KP_BASE_DIR . '/' . $item);
        } else {
            @copy($src, KP_BASE_DIR . '/' . $item);
        }
    }
    if (function_exists('opcache_reset')) @opcache_reset();
    return ['ok' => true];
}
