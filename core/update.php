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

// System-Dateien/-Ordner, die ein Update ersetzt (relativ zur Installations-Wurzel)
const KP_UPDATE_SYSTEM_DIRS  = ['core'];
const KP_UPDATE_SYSTEM_FILES = ['index.php', 'version.json', '.htaccess', 'README.txt', 'LIZENZEN.txt'];

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
    $body = kp_update_http_get($url, $timeout);
    if ($body === null) return false;
    return @file_put_contents($dest, $body, LOCK_EX) !== false;
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

// Führt das Update aus. Liefert ['ok'=>bool, 'error'=>?, 'version'=>?]
function kp_update_run(): array {
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

    // Backup der aktuellen System-Dateien
    $backup = kp_update_backup_dir_path();
    if (!@mkdir($backup, 0775, true)) {
        kp_update_rrmdir($tmpDir);
        return ['ok' => false, 'error' => 'Backup-Ordner kann nicht angelegt werden'];
    }
    $backupOk = kp_update_copy_dir(KP_BASE_DIR . '/core', $backup . '/core');
    foreach (KP_UPDATE_SYSTEM_FILES as $f) {
        if (is_file(KP_BASE_DIR . '/' . $f)) {
            $backupOk = $backupOk && @copy(KP_BASE_DIR . '/' . $f, $backup . '/' . $f);
        }
    }
    if (!$backupOk) {
        kp_update_rrmdir($tmpDir);
        kp_update_rrmdir($backup);
        return ['ok' => false, 'error' => 'Backup fehlgeschlagen, Update abgebrochen'];
    }

    // System-Dateien ersetzen (config/ und daten/ bleiben unberührt)
    foreach (KP_UPDATE_SYSTEM_DIRS as $d) {
        kp_update_rrmdir(KP_BASE_DIR . '/' . $d);
        if (!kp_update_copy_dir($tmpDir . '/' . $d, KP_BASE_DIR . '/' . $d)) {
            kp_update_rrmdir($tmpDir);
            return ['ok' => false, 'error' => 'Kopieren fehlgeschlagen — bitte Rollback ausführen'];
        }
    }
    foreach (KP_UPDATE_SYSTEM_FILES as $f) {
        if (is_file($tmpDir . '/' . $f) && !@copy($tmpDir . '/' . $f, KP_BASE_DIR . '/' . $f)) {
            kp_update_rrmdir($tmpDir);
            return ['ok' => false, 'error' => "Datei {$f} konnte nicht ersetzt werden — bitte Rollback ausführen"];
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

// Stellt das neueste Backup wieder her
function kp_update_rollback(): array {
    $backups = kp_update_list_backups();
    if (!$backups) return ['ok' => false, 'error' => 'Kein Backup vorhanden'];
    $backup = $backups[0];
    if (!is_dir($backup . '/core')) return ['ok' => false, 'error' => 'Backup unvollständig'];

    kp_update_rrmdir(KP_BASE_DIR . '/core');
    if (!kp_update_copy_dir($backup . '/core', KP_BASE_DIR . '/core')) {
        return ['ok' => false, 'error' => 'Wiederherstellen fehlgeschlagen'];
    }
    foreach (KP_UPDATE_SYSTEM_FILES as $f) {
        if (is_file($backup . '/' . $f)) {
            @copy($backup . '/' . $f, KP_BASE_DIR . '/' . $f);
        }
    }
    if (function_exists('opcache_reset')) @opcache_reset();
    return ['ok' => true];
}
