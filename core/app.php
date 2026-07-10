<?php
// Konzertplaner — Engine (Konzert-Liste + Detailseite + JSON-API unter ?action=...)
// Wird vom Einstiegspunkt geladen, der Betriebsart und Pfade als Konstanten definiert:
//   KP_MODE       'standalone' (eigener Passwort-Login aus config/config.php)
//                 | 'platform' (Auth & Limits liefert die umgebende Plattform)
//   KP_DATA_DIR   Datenordner (konzerte/, tracks/, tracks_meta/, notes/)
//   KP_ASSET_URL  Browser-Pfad zu den Assets (CSS/JS)
//   KP_DATA_URL   Browser-Pfad zum Datenordner (Track-/Noten-Auslieferung)
//   KP_LIMITS     optional: ['max_concerts'=>int|null, 'max_storage_bytes'=>int|null]

declare(strict_types=1);

if (!defined('KP_MODE') || !defined('KP_DATA_DIR')) {
    http_response_code(403);
    exit('Direktaufruf nicht erlaubt');
}

// Session-Cookie härten: HttpOnly gegen JS-Zugriff, SameSite=Lax gegen CSRF-POSTs,
// Secure nur unter HTTPS. Im Plattform-Modus läuft die Session bereits.
if (session_status() === PHP_SESSION_NONE) {
    // HTTPS auch hinter TLS-terminierendem Proxy erkennen (X-Forwarded-Proto),
    // sonst fehlt dem Session-Cookie dort das Secure-Flag.
    $kpHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $kpHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$DATA_DIR       = rtrim(KP_DATA_DIR, '/\\');
$TRACKS_DIR     = $DATA_DIR . '/tracks';
$NOTES_DIR      = $DATA_DIR . '/notes';
$CONCERTS_DIR   = $DATA_DIR . '/konzerte';
$TRACKS_META_DIR = $DATA_DIR . '/tracks_meta';
$LEGACY_FILE    = $DATA_DIR . '/konzertplan.json';
$MAX_UPLOAD     = 10 * 1024 * 1024; // 10 MB
$ALLOWED_EXT    = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
// Cache-Buster automatisch aus dem jüngsten Änderungsdatum ALLER Kern-Assets
// (CSS und JS getrennt geändert → trotzdem frische Version), Fallback '12'.
$ASSET_VER      = (string)(max(
    (int)@filemtime(__DIR__ . '/assets/konzertplaner.css'),
    (int)@filemtime(__DIR__ . '/assets/wavesurfer.esm.js'),
    (int)@filemtime(__DIR__ . '/assets/abcjs-basic-min.js')
) ?: '12');

// ---------- Limits (Plattform-Modus; Standalone = unbegrenzt) ----------

function kp_limit(string $key): ?int {
    if (!defined('KP_LIMITS')) return null;
    $limits = KP_LIMITS;
    $v = is_array($limits) ? ($limits[$key] ?? null) : null;
    return (is_int($v) && $v > 0) ? $v : null;
}

// Belegter Speicher in Bytes (Tracks + Notendateien)
function kp_storage_used(string $tracksDir, string $notesDir): int {
    $sum = 0;
    foreach ([$tracksDir, $notesDir] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) $sum += (int)@filesize($f);
        }
    }
    return $sum;
}

// Anzahl Konzert-Gruppen (Backup-Versionen desselben Konzerts zählen nicht extra)
function kp_concert_group_count(string $concertsDir): int {
    $groups = [];
    foreach (list_concerts($concertsDir) as $c) {
        $g = (string)($c['group_id'] ?? ($c['id'] ?? ''));
        if ($g !== '') $groups[$g] = true;
    }
    return count($groups);
}

// Bricht mit 403 ab, wenn das Konzert-Limit erreicht ist
function kp_enforce_concert_limit(string $concertsDir): void {
    $max = kp_limit('max_concerts');
    if ($max !== null && kp_concert_group_count($concertsDir) >= $max) {
        json_response(['ok' => false, 'error' => "Konzert-Limit erreicht (max. $max). Upgrade nötig für mehr Konzerte."], 403);
    }
}

// Summe der Notendatei-Größen der übergebenen Einträge (für die Quota-Prüfung
// beim Duplizieren — Notendateien werden dabei physisch kopiert)
function kp_note_bytes_of_entries(string $baseDir, array $entries): int {
    $sum = 0;
    foreach ($entries as $e) {
        if (!is_array($e)) continue;
        foreach ((array)($e['note_files'] ?? []) as $rel) {
            if (is_string($rel) && $rel !== '') $sum += (int)@filesize($baseDir . '/' . $rel);
        }
    }
    return $sum;
}

// Bricht mit 403 ab, wenn der neue Upload die Speicher-Quota sprengen würde
function kp_enforce_storage_limit(string $tracksDir, string $notesDir, int $addBytes): void {
    $max = kp_limit('max_storage_bytes');
    if ($max !== null && (kp_storage_used($tracksDir, $notesDir) + $addBytes) > $max) {
        $mb = round($max / 1048576);
        json_response(['ok' => false, 'error' => "Speicher-Limit erreicht (max. {$mb} MB). Upgrade nötig für mehr Speicher."], 403);
    }
}

// ---------- Hilfen: Allgemein ----------

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Private Konzertdaten (state, concerts_list, …) nie im Browser-/Proxy-Cache ablegen
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

// CSRF-Zusatzschutz: Schickt der Browser einen Origin-Header mit, muss dessen
// Host zum eigenen Host passen. Fehlt der Header (ältere Browser, direkte
// API-Nutzung per Skript), wird nicht blockiert — das SameSite=Lax-Cookie
// bleibt die Hauptverteidigung, dies ist die zweite Linie für POSTs.
function kp_check_origin(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') return;
    $host = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    $self = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    // Host-Anteil ohne Port vergleichen (HTTP_HOST kann ":8080" enthalten)
    $self = preg_replace('/:\d+$/', '', $self) ?? $self;
    if ($host !== '' && $self !== '' && $host !== $self) {
        json_response(['ok' => false, 'error' => 'Ungültige Anfrage-Herkunft'], 403);
    }
}

// Serialisiert Schreibzugriffe auf EIN Konzert über eine Sperrdatei. Alle
// Endpunkte, die ein Konzert laden→ändern→speichern, holen VOR dem Laden diese
// Sperre — sonst kann ein parallel laufender Request (z. B. das passive
// Längen-Speichern) mit seinem älteren Lade-Stand die Änderung überschreiben.
// Das Handle bleibt offen; PHP gibt die Sperre am Request-Ende automatisch frei.
function kp_concert_lock(string $concertsDir, string $concertId) {
    if (!valid_concert_id($concertId)) return null;
    ensure_dir($concertsDir);
    $h = @fopen($concertsDir . '/.' . $concertId . '.lock', 'c');
    if ($h) @flock($h, LOCK_EX);
    return $h;
}

function ensure_notes_htaccess(string $dir): void {
    ensure_dir($dir);
    $sentinel = $dir . '/.htaccess_ok';
    $ht       = $dir . '/.htaccess';
    // Nur überspringen, wenn die .htaccess wirklich vorhanden UND nicht leer/kaputt ist
    // (falls jemand sie entfernt oder geleert hat, wird sie neu erzeugt).
    if (file_exists($ht) && (int)@filesize($ht) > 40) return;
    $rules = "# Konzertplaner: Upload-Verzeichnis, keine Script-Ausführung\n"
           . "Options -Indexes\n"
           . "<FilesMatch \"\\.(php|phtml|phps|pl|py|cgi|sh|asp|aspx|jsp)$\">\n"
           . "  <IfModule mod_authz_core.c>\n"
           . "    Require all denied\n"
           . "  </IfModule>\n"
           . "  <IfModule !mod_authz_core.c>\n"
           . "    Order allow,deny\n"
           . "    Deny from all\n"
           . "  </IfModule>\n"
           . "</FilesMatch>\n"
           . "<IfModule mod_mime.c>\n"
           . "  RemoveHandler .php .phtml .phps .pl .py .cgi .sh\n"
           . "  RemoveType .php .phtml .phps .pl .py .cgi .sh\n"
           . "  AddType text/plain .php .phtml .phps .pl .py .cgi .sh\n"
           . "</IfModule>\n"
           . "<IfModule mod_headers.c>\n"
           . "  Header set X-Content-Type-Options \"nosniff\"\n"
           . "</IfModule>\n";
    $tmp = $ht . '.tmp';
    $written = false;
    if (@file_put_contents($tmp, $rules, LOCK_EX) !== false) {
        if (@rename($tmp, $ht)) {
            $written = true;
        } else {
            @unlink($tmp);
        }
    }
    if ($written) @file_put_contents($sentinel, "1\n");
}

// Schützt konzerte/ vor direktem HTTP-Zugriff (rohe JSON-Files sollen nur über die API kommen).
function ensure_concerts_htaccess(string $dir): void {
    ensure_dir($dir);
    $sentinel = $dir . '/.htaccess_ok';
    $ht       = $dir . '/.htaccess';
    if (file_exists($ht) && (int)@filesize($ht) > 40) return;
    $rules = "# Konzertplaner: Daten-Verzeichnis, kein direkter Zugriff\n"
           . "Options -Indexes\n"
           . "<IfModule mod_authz_core.c>\n"
           . "  Require all denied\n"
           . "</IfModule>\n"
           . "<IfModule !mod_authz_core.c>\n"
           . "  Order allow,deny\n"
           . "  Deny from all\n"
           . "</IfModule>\n";
    $tmp = $ht . '.tmp';
    $written = false;
    if (@file_put_contents($tmp, $rules, LOCK_EX) !== false) {
        if (@rename($tmp, $ht)) $written = true;
        else @unlink($tmp);
    }
    if ($written) @file_put_contents($sentinel, "1\n");
}

function list_tracks(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.mp3') ?: [];
    $names = array_map(static fn($f) => basename($f), $files);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function tracks_meta(string $dir, array $names): array {
    $meta = [];
    foreach ($names as $n) {
        $full = $dir . '/' . $n;
        if (!is_file($full)) continue;
        $mt = @filemtime($full);
        $meta[$n] = ['mtime' => $mt ? (int)$mt : null];
    }
    return $meta;
}

// ---------- Track-Marker (Annotationen pro Track) ----------

// Hashbasierter Dateiname pro Track, damit Sonderzeichen kollisionsfrei abgebildet werden.
// Das Original wird in der JSON-Datei als "file" mitgespeichert.
function track_meta_path(string $dir, string $trackName): string {
    return $dir . '/' . sha1($trackName) . '.json';
}

function valid_marker_id(string $id): bool {
    return (bool)preg_match('/^m_[a-z0-9]{4,16}$/', $id);
}

function valid_marker_color(string $c): bool {
    return in_array($c, ['yellow', 'red', 'green', 'blue', 'purple'], true);
}

function load_track_markers(string $dir, string $trackName): array {
    $f = track_meta_path($dir, $trackName);
    if (!is_file($f)) return [];
    $raw = @file_get_contents($f);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['markers']) || !is_array($data['markers'])) return [];
    // Sortiert nach Zeit aufsteigend zurückgeben
    $markers = [];
    foreach ($data['markers'] as $m) {
        if (!is_array($m)) continue;
        $id = (string)($m['id'] ?? '');
        if (!valid_marker_id($id)) continue;
        $t = isset($m['t']) && (is_int($m['t']) || is_float($m['t'])) ? (float)$m['t'] : -1.0;
        if ($t < 0) continue;
        $color = (string)($m['color'] ?? 'yellow');
        if (!valid_marker_color($color)) $color = 'yellow';
        $markers[] = [
            'id'    => $id,
            't'     => $t,
            'text'  => mb_substr((string)($m['text'] ?? ''), 0, 60),
            'color' => $color,
        ];
    }
    usort($markers, static fn($a, $b) => $a['t'] <=> $b['t']);
    return $markers;
}

function save_track_markers(string $dir, string $trackName, array $markers): bool {
    ensure_dir($dir);
    $clean = [];
    foreach ($markers as $m) {
        if (!is_array($m)) continue;
        $id = (string)($m['id'] ?? '');
        if (!valid_marker_id($id)) continue;
        $t = isset($m['t']) && (is_int($m['t']) || is_float($m['t'])) ? max(0.0, (float)$m['t']) : null;
        if ($t === null) continue;
        $color = (string)($m['color'] ?? 'yellow');
        if (!valid_marker_color($color)) $color = 'yellow';
        $clean[] = [
            'id'    => $id,
            't'     => $t,
            'text'  => mb_substr((string)($m['text'] ?? ''), 0, 60),
            'color' => $color,
        ];
    }
    usort($clean, static fn($a, $b) => $a['t'] <=> $b['t']);
    $payload = [
        'file'       => $trackName,
        'schema'     => 1,
        'updated_at' => time(),
        'markers'    => $clean,
    ];
    $f   = track_meta_path($dir, $trackName);
    $tmp = $f . '.tmp';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $f);
}

// Sammelt Marker für eine Liste von Tracks (z.B. alle in einem Konzert), nur für existierende Tracks.
function markers_for_tracks(string $metaDir, string $tracksDir, array $trackNames): array {
    $out = [];
    foreach ($trackNames as $t) {
        if (!is_string($t) || $t === '') continue;
        $name = basename($t);
        if ($name === '' || !is_file($tracksDir . '/' . $name)) continue;
        $m = load_track_markers($metaDir, $name);
        if (count($m) > 0) $out[$name] = $m;
    }
    return $out;
}

// ---------- Entry-basierte Marker (Marker gehören zum Eintrag, nicht zum Track) ----------

function entry_meta_path(string $dir, string $entryId): string {
    return $dir . '/entry_' . sha1($entryId) . '.json';
}

function load_entry_markers(string $dir, string $entryId): array {
    if (!valid_entry_id($entryId)) return [];
    $f = entry_meta_path($dir, $entryId);
    if (!is_file($f)) return [];
    $raw = @file_get_contents($f);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['markers']) || !is_array($data['markers'])) return [];
    $markers = [];
    foreach ($data['markers'] as $m) {
        if (!is_array($m)) continue;
        $id = (string)($m['id'] ?? '');
        if (!valid_marker_id($id)) continue;
        $t = isset($m['t']) && (is_int($m['t']) || is_float($m['t'])) ? (float)$m['t'] : -1.0;
        if ($t < 0) continue;
        $color = (string)($m['color'] ?? 'yellow');
        if (!valid_marker_color($color)) $color = 'yellow';
        $markers[] = ['id' => $id, 't' => $t, 'text' => mb_substr((string)($m['text'] ?? ''), 0, 60), 'color' => $color];
    }
    usort($markers, static fn($a, $b) => $a['t'] <=> $b['t']);
    return $markers;
}

function save_entry_markers(string $dir, string $entryId, array $markers): bool {
    if (!valid_entry_id($entryId)) return false;
    ensure_dir($dir);
    $clean = [];
    foreach ($markers as $m) {
        if (!is_array($m)) continue;
        $id = (string)($m['id'] ?? '');
        if (!valid_marker_id($id)) continue;
        $t = isset($m['t']) && (is_int($m['t']) || is_float($m['t'])) ? max(0.0, (float)$m['t']) : null;
        if ($t === null) continue;
        $color = (string)($m['color'] ?? 'yellow');
        if (!valid_marker_color($color)) $color = 'yellow';
        $clean[] = ['id' => $id, 't' => $t, 'text' => mb_substr((string)($m['text'] ?? ''), 0, 60), 'color' => $color];
    }
    usort($clean, static fn($a, $b) => $a['t'] <=> $b['t']);
    $payload = ['entry' => $entryId, 'schema' => 2, 'updated_at' => time(), 'markers' => $clean];
    $f   = entry_meta_path($dir, $entryId);
    $tmp = $f . '.tmp';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $f);
}

function markers_for_entries(string $metaDir, string $tracksDir, array $entries): array {
    $out = [];
    foreach ($entries as $e) {
        if (!is_array($e)) continue;
        $eid = (string)($e['id'] ?? '');
        if (!valid_entry_id($eid)) continue;
        if (($e['type'] ?? '') === 'heading') continue;
        $m = load_entry_markers($metaDir, $eid);
        if (empty($m)) {
            $trackName = '';
            if (is_array($e['tracks'] ?? null)) {
                foreach ($e['tracks'] as $fn) { if (is_string($fn) && $fn !== '') { $trackName = $fn; break; } }
            }
            if ($trackName !== '' && is_file($tracksDir . '/' . $trackName)) {
                $m = load_track_markers($metaDir, $trackName);
                if (!empty($m)) save_entry_markers($metaDir, $eid, $m);
            }
        }
        if (!empty($m)) $out[$eid] = $m;
    }
    return $out;
}

function sanitize_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
    $name = trim($name, '._');
    return $name === '' ? 'datei' : $name;
}

function note_path_is_safe(string $rel, string $baseDir, string $notesDir): bool {
    if ($rel === '' || strpos($rel, 'notes/') !== 0 || strpos($rel, '..') !== false) return false;
    $full = $baseDir . '/' . $rel;
    if (!file_exists($full)) return false;
    $real = realpath($full);
    $notesReal = realpath($notesDir);
    if ($real === false || $notesReal === false) return false;
    return strpos($real, $notesReal . DIRECTORY_SEPARATOR) === 0;
}

// ---------- Hilfen: Konzerte ----------

function valid_concert_id(string $id): bool {
    return (bool)preg_match('/^k_[a-z0-9]{4,16}$/', $id);
}
function valid_entry_id(string $id): bool {
    return (bool)preg_match('/^e_[a-z0-9]{4,16}$/', $id);
}
function valid_rehearsal_id(string $id): bool {
    return (bool)preg_match('/^p_[a-z0-9]{4,16}$/', $id);
}
// BPM (Schläge pro Minute) eines Eintrags normalisieren: Ganzzahl 1–400, sonst 0 (= keine Angabe).
function sanitize_bpm($v): int {
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
        $b = (int)round((float)$v);
        if ($b >= 1 && $b <= 400) return $b;
    }
    return 0;
}

// ---------- Freigabe (Konzert teilen per Link + Passwort) ----------

function valid_share_token(string $t): bool {
    return (bool)preg_match('/^sh_[a-f0-9]{40}$/', $t);
}

function gen_share_token(): string {
    return 'sh_' . bin2hex(random_bytes(20));
}

// Freigabe-Berechtigung der Betrachter:
//   'view'    = nur ansehen + abspielen (Standard, abwärtskompatibel)
//   'markers' = zusätzlich Marker setzen/ändern
//   'edit'    = zusätzlich das ganze Programm bearbeiten
function valid_share_permission(string $p): bool {
    return in_array($p, ['view', 'markers', 'edit'], true);
}
function share_permission_of(?array $c): string {
    $p = (string)(($c['share']['permission'] ?? '') ?: 'view');
    return valid_share_permission($p) ? $p : 'view';
}

// In einer Freigabe-Sitzung dürfen Schreibzugriffe nur Einträge des
// freigegebenen Konzerts betreffen — sonst könnte ein Mitbearbeiter über
// geratene Entry-IDs in andere (private) Konzerte desselben Besitzers schreiben.
// Besitzer (eingeloggt) sind nicht eingeschränkt.
function share_entry_allowed(bool $isLoggedIn, ?array $shareConcert, string $entryId): bool {
    if ($isLoggedIn || $shareConcert === null) return true;
    $ids = array_map(
        static fn($e) => (string)($e['id'] ?? ''),
        is_array($shareConcert['entries'] ?? null) ? $shareConcert['entries'] : []
    );
    return in_array($entryId, $ids, true);
}

// Sucht das Konzert mit diesem Freigabe-Token (nur aktive Freigaben)
function find_concert_by_share_token(string $dir, string $token): ?array {
    if (!valid_share_token($token)) return null;
    foreach (glob($dir . '/k_*.json') ?: [] as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $c = json_decode($raw, true);
        if (!is_array($c)) continue;
        $share = $c['share'] ?? null;
        if (is_array($share) && !empty($share['enabled'])
            && hash_equals((string)($share['token'] ?? ''), $token)) {
            return $c;
        }
    }
    return null;
}

// Freigabe-URL: die Plattform liefert ihren eigenen Pfad per Hook,
// Standalone nutzt den eigenen Einstiegspunkt
function share_url_for_token(string $token): string {
    if (function_exists('kp_platform_share_url')) {
        return kp_platform_share_url($token);
    }
    return '?share=' . rawurlencode($token);
}

// Exponentieller Lock pro Freigabe-Token gegen parallelisiertes
// Passwort-Raten (sleep allein bremst parallele Requests nicht).
// Liefert verbleibende Sperrsekunden (0 = frei).
function share_attempt_update(string $dataDir, string $token, string $modus): int {
    // Sperrdatei in den geschützten config/-Ordner legen (nicht ins per HTTP
    // erreichbare daten/); Fallback auf den übergebenen Datenordner.
    $base = defined('KP_CONFIG_DIR') ? rtrim(KP_CONFIG_DIR, '/\\') : rtrim($dataDir, '/\\');
    $file = $base . '/share_attempts.json';
    $fh = @fopen($file, 'c+b');
    if ($fh === false) return 0;
    if (!@flock($fh, LOCK_EX)) {
        fclose($fh);
        return 0;
    }
    $data = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($data)) $data = [];
    $key = sha1($token);
    $e = is_array($data[$key] ?? null) ? $data[$key] : ['fails' => 0, 'lock_until' => 0];
    $rest = max(0, (int)$e['lock_until'] - time());
    if ($modus === 'fail') {
        $e['fails'] = (int)$e['fails'] + 1;
        $e['lock_until'] = time() + min(5 * (2 ** max(0, (int)$e['fails'] - 1)), 3600);
        $data[$key] = $e;
    } elseif ($modus === 'clear') {
        unset($data[$key]);
    }
    if ($modus !== 'check') {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
        }
    }
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $rest;
}

// Exponentieller Lock für den Admin-Login (analog share_attempt_update):
// schützt gegen parallelisiertes Passwort-Raten, da sleep() nur den einzelnen
// Request bremst. Schlüssel = Client-IP + User-Agent, abgelegt im geschützten
// config/-Ordner. Liefert verbleibende Sperrsekunden (0 = frei).
function kp_login_attempt(string $modus): int {
    $base = defined('KP_CONFIG_DIR') ? rtrim(KP_CONFIG_DIR, '/\\') : sys_get_temp_dir();
    $file = $base . '/login_attempts.json';
    $fh = @fopen($file, 'c+b');
    if ($fh === false) return 0;
    if (!@flock($fh, LOCK_EX)) {
        fclose($fh);
        return 0;
    }
    $data = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($data)) $data = [];
    $key = sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $e = is_array($data[$key] ?? null) ? $data[$key] : ['fails' => 0, 'lock_until' => 0];
    $rest = max(0, (int)$e['lock_until'] - time());
    if ($modus === 'fail') {
        $e['fails'] = (int)$e['fails'] + 1;
        $e['lock_until'] = time() + min(5 * (2 ** max(0, (int)$e['fails'] - 1)), 3600);
        $data[$key] = $e;
        // abgelaufene Einträge aufräumen, damit die Datei nicht unbegrenzt wächst
        foreach ($data as $k => $v) {
            if ((int)($v['lock_until'] ?? 0) < time() - 86400) unset($data[$k]);
        }
    } elseif ($modus === 'clear') {
        unset($data[$key]);
    }
    if ($modus !== 'check') {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
        }
    }
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $rest;
}

// Freigabe-Infos für den BESITZER (niemals pass_hash herausgeben)
function share_info_for_owner(array $c): array {
    $share = is_array($c['share'] ?? null) ? $c['share'] : [];
    $token = (string)($share['token'] ?? '');
    return [
        'enabled'      => !empty($share['enabled']),
        'has_password' => !empty($share['pass_hash']),
        'url'          => $token !== '' ? share_url_for_token($token) : '',
        'permission'   => share_permission_of($c),
    ];
}

function gen_id(string $prefix): string {
    $bytes = random_bytes(8);
    $hex = bin2hex($bytes); // 16 hex chars
    return $prefix . '_' . substr($hex, 0, 10);
}

function concert_file(string $dir, string $id): string {
    return $dir . '/' . $id . '.json';
}

function load_concert(string $dir, string $id): ?array {
    if (!valid_concert_id($id)) return null;
    $file = concert_file($dir, $id);
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    // Defaults / Migration ergänzen
    $data['id']          = (string)($data['id'] ?? $id);
    $data['name']        = (string)($data['name'] ?? 'Konzert');
    $data['date']        = (string)($data['date'] ?? '');
    $data['description'] = (string)($data['description'] ?? '');
    $data['rehearsals']  = is_array($data['rehearsals'] ?? null) ? $data['rehearsals'] : [];
    $data['entries']     = is_array($data['entries'] ?? null) ? $data['entries'] : [];
    $data['durations']   = is_array($data['durations'] ?? null) ? $data['durations'] : [];
    $data['marker_labels'] = is_array($data['marker_labels'] ?? null) ? $data['marker_labels'] : [];
    // Track-Slots: fehlen sie → altes Format (track/track_live) migrieren auf
    // 2 Slots „Original"/„Live" + tracks-Map pro Eintrag. Sonst nur normalisieren
    // und tracks auf existierende Slot-IDs beschränken.
    if (!isset($data['slots']) || !is_array($data['slots']) || count($data['slots']) === 0) {
        // WICHTIG: feste, deterministische Slot-IDs für die Migration.
        // load_concert wird bei noch nicht persistierten Alt-Konzerten mehrfach
        // aufgerufen (state beim Laden, dann erneut beim save). Mit Zufalls-IDs
        // (gen_slot_id) hätte jeder Aufruf andere Slot-IDs → die vom Frontend
        // gesendete tracks-Map zeigte beim Speichern auf nicht mehr existierende
        // Slots und würde von clean_entries verworfen (Track-Datenverlust!).
        // Feste IDs halten state und save konsistent; nach dem ersten Speichern
        // greift ohnehin der else-Zweig (Slots sind dann persistiert).
        $s1 = 's_orig';
        $s2 = 's_live';
        $data['slots'] = [
            ['id' => $s1, 'name' => 'Original', 'color' => 'blue'],
            ['id' => $s2, 'name' => 'Live',     'color' => 'green'],
        ];
        foreach ($data['entries'] as &$e) {
            if (!is_array($e)) continue;
            if (!isset($e['tracks']) || !is_array($e['tracks'])) {
                $tracks = [];
                if (!empty($e['track']))      $tracks[$s1] = (string)$e['track'];
                if (!empty($e['track_live'])) $tracks[$s2] = (string)$e['track_live'];
                $e['tracks'] = $tracks;
            }
            unset($e['track'], $e['track_live']);
        }
        unset($e);
    } else {
        $data['slots'] = clean_slots($data['slots']);
        $slotIds = array_column($data['slots'], 'id');
        foreach ($data['entries'] as &$e) {
            if (!is_array($e)) continue;
            // Kanten-Fall teilmigrierter Daten: Eintrag trägt noch die Legacy-
            // Felder track/track_live, aber keine tracks-Map → Zuweisungen in
            // die Map retten statt sie mit unset() zu verwerfen. Ziel-Slots:
            // die Migrations-IDs s_orig/s_live, sonst die ersten beiden Slots.
            if ((!isset($e['tracks']) || !is_array($e['tracks']))
                && (!empty($e['track']) || !empty($e['track_live']))) {
                $tracks = [];
                $sOrig = in_array('s_orig', $slotIds, true) ? 's_orig' : ($slotIds[0] ?? '');
                $sLive = in_array('s_live', $slotIds, true) ? 's_live' : ($slotIds[1] ?? '');
                if (!empty($e['track'])      && $sOrig !== '') $tracks[$sOrig] = (string)$e['track'];
                if (!empty($e['track_live']) && $sLive !== '') $tracks[$sLive] = (string)$e['track_live'];
                $e['tracks'] = $tracks;
            }
            $e['tracks'] = prune_entry_tracks($e['tracks'] ?? null, $slotIds);
            unset($e['track'], $e['track_live']);
        }
        unset($e);
    }
    $data['group_id']    = (string)($data['group_id'] ?? $data['id']);
    $data['is_starred']  = isset($data['is_starred']) ? (bool)$data['is_starred'] : true;
    $data['created_at']  = (int)($data['created_at'] ?? time());
    $data['updated_at']  = (int)($data['updated_at'] ?? $data['created_at']);
    return $data;
}

// $concert wird per Referenz übergeben, damit Aufrufer nach dem Speichern den
// frisch gesetzten updated_at-Stempel auslesen können (optimistisches Sperren).
// $touch=false behält den vorhandenen updated_at bei — für reine Cache-Schreibvorgänge
// (Track-Längen), die die Nebenläufigkeits-Zeitlinie nicht verschieben dürfen.
// $updateIndex=false überspringt die Neuberechnung von _index.json — sinnvoll, wenn sich
// kein indexrelevantes Feld geändert hat (z. B. nur die Track-Längen): spart bei jedem
// passiven Längen-Save das erneute Sortieren+Schreiben der gesamten Konzertliste.
function save_concert(string $dir, array &$concert, bool $touch = true, bool $updateIndex = true): bool {
    if (!valid_concert_id((string)($concert['id'] ?? ''))) return false;
    ensure_dir($dir);
    // Monoton statt nur time(): zwei Speicherungen in derselben Wall-Clock-
    // Sekunde bekämen sonst denselben Stempel und das optimistische Sperren
    // ('updated_at > base') könnte den Konflikt nicht erkennen.
    if ($touch || !isset($concert['updated_at'])) {
        $concert['updated_at'] = max(time(), (int)($concert['updated_at'] ?? 0) + 1);
    }
    $file = concert_file($dir, $concert['id']);
    $tmp  = $file . '.tmp';
    $json = json_encode($concert, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $file)) return false;
    if ($updateIndex) update_concerts_index($dir, $concert);
    return true;
}

function concert_summary(array $c): array {
    return [
        'id'              => $c['id'],
        'group_id'        => $c['group_id'],
        'is_starred'      => $c['is_starred'],
        'name'            => $c['name'],
        'date'            => $c['date'],
        'description'     => $c['description'],
        'rehearsal_count' => count($c['rehearsals']),
        'entry_count'     => count($c['entries']),
        // Nur echte Track-Einträge (ohne Abschnitts-Überschriften) — das zeigt das Frontend an
        'track_count'     => count(array_filter($c['entries'], static fn($e) => (($e['type'] ?? '') !== 'heading'))),
        'created_at'      => $c['created_at'],
        'updated_at'      => $c['updated_at'],
    ];
}

function concerts_index_file(string $dir): string {
    return $dir . '/_index.json';
}

function sort_concerts(array &$list): void {
    usort($list, static function ($a, $b) {
        return strcoll(
            mb_strtolower((string)$a['name'], 'UTF-8'),
            mb_strtolower((string)$b['name'], 'UTF-8')
        );
    });
}

function read_concerts_index(string $dir): ?array {
    $file = concerts_index_file($dir);
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function write_concerts_index(string $dir, array $list): void {
    $file = concerts_index_file($dir);
    $tmp  = $file . '.tmp';
    $json = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return;
    @rename($tmp, $file);
}

function update_concerts_index(string $dir, array $concert): void {
    $list = read_concerts_index($dir) ?? [];
    $found = false;
    foreach ($list as &$entry) {
        if (($entry['id'] ?? '') === $concert['id']) {
            $entry = concert_summary($concert);
            $found = true;
            break;
        }
    }
    unset($entry);
    if (!$found) $list[] = concert_summary($concert);
    sort_concerts($list);
    write_concerts_index($dir, $list);
}

function remove_from_concerts_index(string $dir, string $id): void {
    $list = read_concerts_index($dir) ?? [];
    $list = array_values(array_filter($list, static fn($e) => ($e['id'] ?? '') !== $id));
    write_concerts_index($dir, $list);
}

function rebuild_concerts_index(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/k_*.json') ?: [];
    $list  = [];
    foreach ($files as $f) {
        $base = basename($f, '.json');
        if (!valid_concert_id($base)) continue;
        $c = load_concert($dir, $base);
        if (!$c) continue;
        $list[] = concert_summary($c);
    }
    sort_concerts($list);
    write_concerts_index($dir, $list);
    return $list;
}

function list_concerts(string $dir): array {
    // Schneller Pfad: Index-Datei vorhanden, plausibilisieren über Dateianzahl.
    // Fehlt das (neuere) Feld track_count, stammt der Index aus einer alten
    // Code-Version → neu aufbauen.
    $cached = read_concerts_index($dir);
    if (is_array($cached)) {
        $files = glob($dir . '/k_*.json') ?: [];
        if (count($files) === count($cached)
            && (count($cached) === 0 || isset($cached[0]['track_count']))) {
            return $cached;
        }
    }
    // Sonst neu aufbauen
    return rebuild_concerts_index($dir);
}

function delete_concert_files(string $dir, string $baseDir, string $notesDir, string $id): bool {
    if (!valid_concert_id($id)) return false;
    $c = load_concert($dir, $id);
    if (!$c) return false;
    // Notendateien dieses Konzerts löschen
    foreach ($c['entries'] as $e) {
        $paths = is_array($e['note_files'] ?? null) ? $e['note_files'] : [];
        foreach ($paths as $p) {
            if (!is_string($p)) continue;
            if (!note_path_is_safe($p, $baseDir, $notesDir)) continue;
            $real = realpath($baseDir . '/' . $p);
            if ($real !== false && is_file($real)) @unlink($real);
        }
    }
    // Konzert-JSON löschen
    $ok = @unlink(concert_file($dir, $id));
    if ($ok) remove_from_concerts_index($dir, $id);
    return $ok;
}

// Kopiert eine Notendatei mit neuem entry_id-Prefix; gibt neuen relativen Pfad zurück oder null.
function copy_note_file(string $baseDir, string $notesDir, string $relSrc, string $newEntryId): ?string {
    if (!note_path_is_safe($relSrc, $baseDir, $notesDir)) return null;
    if (!valid_entry_id($newEntryId)) return null;
    $absSrc = realpath($baseDir . '/' . $relSrc);
    if ($absSrc === false || !is_file($absSrc)) return null;
    $base = basename($absSrc);
    // Altes entry_id-Prefix abschneiden, falls vorhanden
    $cleanBase = preg_replace('/^e_[a-z0-9]{4,16}_/', '', $base) ?? $base;
    $target = $notesDir . '/' . $newEntryId . '_' . $cleanBase;
    $i = 1;
    $pi = pathinfo($cleanBase);
    $name = $pi['filename'] ?? 'datei';
    $ext  = isset($pi['extension']) ? '.' . $pi['extension'] : '';
    while (file_exists($target) && $i < 1000) {
        $target = $notesDir . '/' . $newEntryId . '_' . $name . '_' . $i . $ext;
        $i++;
    }
    if ($i >= 1000) return null;
    if (!@copy($absSrc, $target)) return null;
    return 'notes/' . basename($target);
}

// Erstellt eine vollständige Kopie inkl. Notendateien. Eintrags-IDs werden neu vergeben.
function duplicate_concert(string $dir, string $baseDir, string $notesDir, string $srcId): ?array {
    $src = load_concert($dir, $srcId);
    if (!$src) return null;
    $newId = gen_id('k');
    while (is_file(concert_file($dir, $newId))) $newId = gen_id('k');

    $newEntries = [];
    foreach ($src['entries'] as $e) {
        $newEntryId = gen_id('e');
        if (($e['type'] ?? '') === 'heading') {
            $newEntries[] = [
                'id'               => $newEntryId,
                'type'             => 'heading',
                'title'            => (string)($e['title'] ?? ''),
                'notes'            => '',
                'abc'              => '',
                'tracks'           => (object)[],
                'note_files'       => [],
                'manual_duration'  => 0.0,
                'anchored_to_next' => false,
                'status'           => 0,
                'bpm'              => 0,
            ];
            continue;
        }
        $newNoteFiles = [];
        $files = is_array($e['note_files'] ?? null) ? $e['note_files'] : [];
        foreach ($files as $p) {
            if (!is_string($p)) continue;
            $copied = copy_note_file($baseDir, $notesDir, $p, $newEntryId);
            if ($copied !== null) $newNoteFiles[] = $copied;
        }
        $copyStatus = 0;
        if (isset($e['status']) && (is_int($e['status']) || is_string($e['status']))) {
            $cs = (int)$e['status'];
            if (in_array($cs, [0, 10, 20, 30, 40, 50, 80, 100], true)) $copyStatus = $cs;
        }
        $newEntries[] = [
            'id'               => $newEntryId,
            'type'             => '',
            'title'            => (string)($e['title'] ?? ''),
            'notes'            => (string)($e['notes'] ?? ''),
            'abc'              => (string)($e['abc'] ?? ''),
            'tracks'           => (object)(is_array($e['tracks'] ?? null) ? $e['tracks'] : []),
            'note_files'       => $newNoteFiles,
            'manual_duration'  => (float)($e['manual_duration'] ?? 0),
            'anchored_to_next' => !empty($e['anchored_to_next']),
            'status'           => $copyStatus,
            'bpm'              => sanitize_bpm($e['bpm'] ?? 0),
        ];
        // Zeit-Marker sind an die Eintrags-ID gebunden — für die Kopie unter
        // der neuen ID mitkopieren, sonst verliert das Duplikat alle Marker.
        $srcMarkers = load_entry_markers($baseDir . '/tracks_meta', (string)($e['id'] ?? ''));
        if (!empty($srcMarkers)) save_entry_markers($baseDir . '/tracks_meta', $newEntryId, $srcMarkers);
    }
    $nn = count($newEntries);
    if ($nn > 0) $newEntries[$nn - 1]['anchored_to_next'] = false;

    $newRehearsals = [];
    foreach ($src['rehearsals'] as $r) {
        if (!is_array($r)) continue;
        $newRehearsals[] = [
            'id'   => gen_id('p'),
            'date' => (string)($r['date'] ?? ''),
            'note' => (string)($r['note'] ?? ''),
        ];
    }

    $copy = [
        'id'          => $newId,
        'group_id'    => $newId,
        'is_starred'  => true,
        'name'        => mb_substr((string)$src['name'] . ' (Kopie)', 0, 200),
        'date'        => (string)$src['date'],
        'description' => (string)$src['description'],
        'rehearsals'  => $newRehearsals,
        'slots'         => is_array($src['slots'] ?? null) ? $src['slots'] : default_slots(),
        'marker_labels' => is_array($src['marker_labels'] ?? null) ? $src['marker_labels'] : [],
        'entries'     => $newEntries,
        'durations'   => is_array($src['durations']) ? $src['durations'] : [],
        'created_at'  => time(),
        'updated_at'  => time(),
    ];
    if (!save_concert($dir, $copy)) return null;
    return $copy;
}

// Dupliziert einen einzelnen Eintrag direkt nach seinem Original. Kopiert Notendateien mit neuer Eintrag-ID.
// Heading-Einträge werden ohne Track-/Datei-Logik kopiert.
function duplicate_entry(string $dir, string $baseDir, string $notesDir, string $concertId, string $entryId): ?array {
    if (!valid_concert_id($concertId) || !valid_entry_id($entryId)) return null;
    $c = load_concert($dir, $concertId);
    if (!$c) return null;
    $srcIdx = -1;
    foreach ($c['entries'] as $i => $e) {
        if (is_array($e) && ($e['id'] ?? '') === $entryId) { $srcIdx = $i; break; }
    }
    if ($srcIdx < 0) return null;
    $src = $c['entries'][$srcIdx];
    $newEntryId = gen_id('e');

    if (($src['type'] ?? '') === 'heading') {
        $copy = [
            'id'               => $newEntryId,
            'type'             => 'heading',
            'title'            => (string)($src['title'] ?? ''),
            'notes'            => '',
            'abc'              => '',
            'tracks'           => (object)[],
            'note_files'       => [],
            'manual_duration'  => 0.0,
            'anchored_to_next' => false,
            'status'           => 0,
            'bpm'              => 0,
        ];
    } else {
        $newNoteFiles = [];
        $files = is_array($src['note_files'] ?? null) ? $src['note_files'] : [];
        foreach ($files as $p) {
            if (!is_string($p)) continue;
            $copied = copy_note_file($baseDir, $notesDir, $p, $newEntryId);
            if ($copied !== null) $newNoteFiles[] = $copied;
        }
        $copyStatus = 0;
        if (isset($src['status']) && (is_int($src['status']) || is_string($src['status']))) {
            $s = (int)$src['status'];
            if (in_array($s, [0, 10, 20, 30, 40, 50, 80, 100], true)) $copyStatus = $s;
        }
        $copy = [
            'id'               => $newEntryId,
            'type'             => '',
            'title'            => (string)($src['title'] ?? ''),
            'notes'            => (string)($src['notes'] ?? ''),
            'abc'              => (string)($src['abc'] ?? ''),
            'tracks'           => (object)(is_array($src['tracks'] ?? null) ? $src['tracks'] : []),
            'note_files'       => $newNoteFiles,
            'manual_duration'  => (float)($src['manual_duration'] ?? 0),
            // Duplikat selbst hat keinen Anker zum Nachfolger; das Original behält seinen
            // (und „verankert" sich damit zum Duplikat — Gruppe wächst um 1).
            'anchored_to_next' => false,
            'status'           => $copyStatus,
            'bpm'              => sanitize_bpm($src['bpm'] ?? 0),
        ];
        // Zeit-Marker des Originals unter der neuen Eintrags-ID mitkopieren
        $srcMarkers = load_entry_markers($baseDir . '/tracks_meta', $entryId);
        if (!empty($srcMarkers)) save_entry_markers($baseDir . '/tracks_meta', $newEntryId, $srcMarkers);
    }

    array_splice($c['entries'], $srcIdx + 1, 0, [$copy]);
    if (!save_concert($dir, $c)) return null;
    return $c;
}

// Migration: alte konzertplan.json → neues Konzert in konzerte/
function migrate_legacy(string $legacy, string $dir, string $baseDir, string $notesDir): void {
    if (!is_file($legacy)) return;
    if (is_dir($dir)) {
        $existing = glob($dir . '/k_*.json') ?: [];
        if (count($existing) > 0) return; // schon migriert
    }
    $raw = @file_get_contents($legacy);
    if ($raw === false) return;
    $data = json_decode($raw, true);
    if (!is_array($data)) return;
    ensure_dir($dir);
    $id = gen_id('k');
    $tracksDir = $baseDir . '/tracks';
    $validTracks = array_flip(list_tracks($tracksDir));
    $rawEntries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
    $rawDurations = is_array($data['durations'] ?? null) ? $data['durations'] : [];
    $concert = [
        'id'          => $id,
        'name'        => 'Aktuelles Konzert',
        'date'        => '',
        'description' => '',
        'rehearsals'  => [],
        'entries'     => clean_entries($rawEntries, $validTracks, $baseDir, $notesDir),
        'durations'   => clean_durations($rawDurations, $validTracks),
        'created_at'  => time(),
        'updated_at'  => time(),
    ];
    if (save_concert($dir, $concert)) {
        @unlink($legacy);
    }
}

// Validiert/normalisiert Eintragsliste (durch save & save_meta beide nutzbar).
function clean_entries(array $rawEntries, array $validTracksFlip, string $baseDir, string $notesDir, array $validSlotIds = []): array {
    $clean = [];
    foreach ($rawEntries as $e) {
        if (!is_array($e)) continue;
        $id = (string)($e['id'] ?? '');
        if (!valid_entry_id($id)) continue;

        $type = (string)($e['type'] ?? '');
        if ($type === 'heading') {
            // Abschnitts-Überschrift: nur id/type/title bedeutsam, andere Felder leer/false/0
            $clean[] = [
                'id'               => $id,
                'type'             => 'heading',
                'title'            => mb_substr((string)($e['title'] ?? ''), 0, 300),
                'notes'            => '',
                'abc'              => '',
                'tracks'           => (object)[],
                'note_files'       => [],
                'manual_duration'  => 0.0,
                'anchored_to_next' => false,
                'status'           => 0,
                'bpm'              => 0,
            ];
            continue;
        }

        // tracks-Map: nur existierende Slots + tatsächlich vorhandene Track-Dateien
        $tracks = prune_entry_tracks($e['tracks'] ?? null, $validSlotIds);
        foreach ($tracks as $sid => $fn) {
            if (!isset($validTracksFlip[$fn])) unset($tracks[$sid]);
        }

        $noteFiles = [];
        $rawFiles = $e['note_files'] ?? null;
        if (is_array($rawFiles)) {
            foreach ($rawFiles as $p) {
                if (!is_string($p)) continue;
                if (!note_path_is_safe($p, $baseDir, $notesDir)) continue;
                $noteFiles[] = $p;
            }
        } elseif (isset($e['note_file']) && is_string($e['note_file'])) {
            $p = $e['note_file'];
            if (note_path_is_safe($p, $baseDir, $notesDir)) $noteFiles[] = $p;
        }

        $manualDur = 0.0;
        if (isset($e['manual_duration']) && (is_int($e['manual_duration']) || is_float($e['manual_duration']))) {
            $manualDur = max(0.0, min(36000.0, (float)$e['manual_duration']));
        }

        $status = 0;
        if (isset($e['status']) && (is_int($e['status']) || is_string($e['status']))) {
            $s = (int)$e['status'];
            if (in_array($s, [0, 10, 20, 30, 40, 50, 80, 100], true)) $status = $s;
        }

        $clean[] = [
            'id'               => $id,
            'type'             => '',
            'title'            => mb_substr((string)($e['title'] ?? ''), 0, 300),
            'notes'            => mb_substr((string)($e['notes'] ?? ''), 0, 5000),
            'abc'              => mb_substr((string)($e['abc'] ?? ''), 0, 20000),
            'tracks'           => (object)$tracks,
            'note_files'       => $noteFiles,
            'manual_duration'  => $manualDur,
            'anchored_to_next' => !empty($e['anchored_to_next']),
            'status'           => $status,
            'bpm'              => sanitize_bpm($e['bpm'] ?? 0),
        ];
    }
    // Anker am Listenende ist bedeutungslos und wird zwangsweise gelöscht.
    // Anker auf Tracks, deren Nachfolger eine Heading ist, ebenfalls (semantisch sinnlos).
    $n = count($clean);
    if ($n > 0) $clean[$n - 1]['anchored_to_next'] = false;
    for ($i = 0; $i < $n - 1; $i++) {
        if (($clean[$i + 1]['type'] ?? '') === 'heading') {
            $clean[$i]['anchored_to_next'] = false;
        }
    }
    return $clean;
}

function clean_durations(array $rawDur, array $validTracksFlip): array {
    $clean = [];
    foreach ($rawDur as $k => $v) {
        // Nur positive, endliche Längen — schützt den Cache vor 0/negativen/NaN-Werten.
        if (is_string($k) && isset($validTracksFlip[$k]) && (is_float($v) || is_int($v))
            && is_finite((float)$v) && (float)$v > 0) {
            $clean[$k] = (float)$v;
        }
    }
    return $clean;
}

// Validiert YYYY-MM-DDTHH:MM oder leerer String. Ungültiges → ''.
function clean_datetime_local(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $s)) return $s;
    return '';
}

function clean_marker_labels(array $raw): array {
    $allowed = ['yellow', 'red', 'green', 'blue', 'purple'];
    $clean = [];
    foreach ($allowed as $color) {
        if (isset($raw[$color]) && is_string($raw[$color])) {
            $val = trim($raw[$color]);
            if ($val !== '') $clean[$color] = mb_substr($val, 0, 30);
        }
    }
    return $clean;
}

// ---------- Track-Slots (pro Konzert konfigurierbar, 1–5) ----------

// Erlaubte Slot-Farben: Schlüssel wird im JSON gespeichert, Hex liefert das Frontend.
function slot_palette(): array {
    return ['blue' => '#5b8def', 'green' => '#46c98a', 'amber' => '#e9a23d', 'purple' => '#a78bfa', 'red' => '#ef5a52'];
}

function gen_slot_id(): string {
    return 's_' . bin2hex(random_bytes(4));
}

// Standard für neue Konzerte: EIN Slot „Track".
function default_slots(): array {
    return [['id' => gen_slot_id(), 'name' => 'Track', 'color' => 'blue']];
}

// Normalisiert die Slot-Liste: 1–5 Slots, eindeutige IDs, Name ≤40 Zeichen, gültige Farbe.
function clean_slots($raw): array {
    $colors = array_keys(slot_palette());
    $clean = [];
    $seen = [];
    if (is_array($raw)) {
        foreach ($raw as $s) {
            if (!is_array($s)) continue;
            $id = (string)($s['id'] ?? '');
            if (!preg_match('/^s_[a-z0-9]{4,16}$/', $id) || isset($seen[$id])) $id = gen_slot_id();
            $name = mb_substr(trim((string)($s['name'] ?? '')), 0, 40);
            if ($name === '') $name = 'Track';
            $color = (string)($s['color'] ?? 'blue');
            if (!in_array($color, $colors, true)) $color = 'blue';
            $seen[$id] = true;
            $clean[] = ['id' => $id, 'name' => $name, 'color' => $color];
            if (count($clean) >= 5) break;
        }
    }
    if (count($clean) < 1) $clean = default_slots();
    return $clean;
}

// Beschränkt die tracks-Map eines Eintrags auf existierende Slot-IDs + nicht-leere Strings.
function prune_entry_tracks($rawTracks, array $slotIds): array {
    $out = [];
    if (is_array($rawTracks)) {
        foreach ($rawTracks as $sid => $fn) {
            if (in_array((string)$sid, $slotIds, true) && is_string($fn) && $fn !== '') {
                $out[(string)$sid] = $fn;
            }
        }
    }
    return $out;
}

function clean_rehearsals(array $rawList): array {
    $clean = [];
    foreach ($rawList as $r) {
        if (!is_array($r)) continue;
        $id = (string)($r['id'] ?? '');
        if (!valid_rehearsal_id($id)) $id = gen_id('p');
        $clean[] = [
            'id'   => $id,
            'date' => clean_datetime_local((string)($r['date'] ?? '')),
            'note' => mb_substr((string)($r['note'] ?? ''), 0, 300),
        ];
    }
    return $clean;
}

// ---------- Bootstrap ----------

ensure_dir($NOTES_DIR);
ensure_notes_htaccess($NOTES_DIR);
ensure_dir($TRACKS_DIR);
ensure_notes_htaccess($TRACKS_DIR); // MP3s bleiben direkt abrufbar, aber kein Listing/keine Script-Ausführung
ensure_dir($CONCERTS_DIR);
ensure_concerts_htaccess($CONCERTS_DIR);
ensure_dir($TRACKS_META_DIR);
ensure_concerts_htaccess($TRACKS_META_DIR); // reines Daten-Verzeichnis, gleicher Schutz
migrate_legacy($LEGACY_FILE, $CONCERTS_DIR, $DATA_DIR, $NOTES_DIR);

$action      = $_GET['action'] ?? null;
$concertId   = isset($_GET['k']) ? (string)$_GET['k'] : '';
$concertId   = valid_concert_id($concertId) ? $concertId : '';

// Zweite CSRF-Verteidigungslinie für alle zustandsändernden Aktionen
// (Haupt-Schutz bleibt das SameSite=Lax-Session-Cookie)
if ($action !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    kp_check_origin();
}

// ---------- Authentifizierung ----------
// Standalone: eigener Passwort-Login (Hash aus config/config.php).
// Plattform:  die umgebende Plattform stellt kp_platform_is_logged_in()
//             und optional kp_platform_logout_url() bereit.

if (KP_MODE === 'platform') {
    $isLoggedIn = function_exists('kp_platform_is_logged_in') && kp_platform_is_logged_in();
} else {
    $isLoggedIn = !empty($_SESSION['kp_auth']);
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (KP_MODE === 'platform') {
        json_response(['ok' => false, 'error' => 'Login läuft über die Plattform'], 400);
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $pw = is_array($body) ? (string)($body['password'] ?? '') : '';
    $wartezeit = kp_login_attempt('check');
    if ($wartezeit > 0) {
        json_response(['ok' => false, 'error' => "Zu viele Fehlversuche — bitte warte {$wartezeit} Sekunden."], 429);
    }
    if (defined('KP_PASSWORD_HASH') && password_verify($pw, KP_PASSWORD_HASH)) {
        kp_login_attempt('clear');
        session_regenerate_id(true);
        $_SESSION['kp_auth'] = true;
        json_response(['ok' => true]);
    }
    kp_login_attempt('fail');
    sleep(1); // zusätzliche Bremse für den Einzel-Request
    json_response(['ok' => false, 'error' => 'Falsches Passwort'], 401);
}

if ($action === 'logout') {
    if (KP_MODE === 'platform') {
        // Plattform-Session bleibt unangetastet, nur weiterleiten
        header('Location: ' . (function_exists('kp_platform_logout_url') ? kp_platform_logout_url() : '../'));
        exit;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ./');
    exit;
}

// ---------- Freigabe: Token auflösen, Passwort-Login, Lese-Zugriff ----------

$SHARE_TOKEN  = (string)($_GET['share'] ?? ($_POST['share'] ?? ''));
if (!valid_share_token($SHARE_TOKEN)) $SHARE_TOKEN = '';
$shareConcert = $SHARE_TOKEN !== '' ? find_concert_by_share_token($CONCERTS_DIR, $SHARE_TOKEN) : null;
$shareGranted = $shareConcert !== null && !empty($_SESSION['kp_share'][$SHARE_TOKEN]);

if ($action === 'share_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $token = is_array($body) ? (string)($body['token'] ?? '') : '';
    $pw    = is_array($body) ? (string)($body['password'] ?? '') : '';
    if (valid_share_token($token)) {
        $wartezeit = share_attempt_update($DATA_DIR, $token, 'check');
        if ($wartezeit > 0) {
            json_response(['ok' => false, 'error' => "Zu viele Fehlversuche — bitte warte {$wartezeit} Sekunden."], 429);
        }
    }
    $c = valid_share_token($token) ? find_concert_by_share_token($CONCERTS_DIR, $token) : null;
    if ($c !== null && password_verify($pw, (string)($c['share']['pass_hash'] ?? ''))) {
        share_attempt_update($DATA_DIR, $token, 'clear');
        session_regenerate_id(true);
        $_SESSION['kp_share'][$token] = true;
        // Plattform-Hook: erlaubt der Datei-Auslieferung den Zugriff für diese Freigabe
        if (function_exists('kp_platform_share_granted')) {
            kp_platform_share_granted((string)$c['id']);
        }
        json_response(['ok' => true]);
    }
    if (valid_share_token($token)) {
        share_attempt_update($DATA_DIR, $token, 'fail');
    }
    sleep(1); // zusätzliche Bremse für den Einzel-Request
    json_response(['ok' => false, 'error' => 'Falsches Passwort'], 401);
}

if (!$isLoggedIn) {
    if ($action !== null) {
        // Freigabe-Sitzungen: erlaubte Aktionen je nach eingestellter Berechtigung —
        // und immer NUR für das freigegebene Konzert.
        $sharePerm = $shareGranted ? share_permission_of($shareConcert) : 'view';
        $shareAllowed = ['state', 'markers_get'];
        if ($sharePerm === 'markers' || $sharePerm === 'edit') {
            $shareAllowed[] = 'markers_save';
        }
        if ($sharePerm === 'edit') {
            // track_delete bewusst NICHT: das Löschen aus dem gemeinsamen Track-Pool
            // beträfe auch andere Konzerte des Besitzers — bleibt Besitzer-Sache.
            array_push($shareAllowed,
                'save', 'durations_save', 'concert_save_meta', 'entry_duplicate',
                'upload_track', 'upload_note', 'delete_note');
        }
        $shareOk = $shareGranted
            && in_array($action, $shareAllowed, true)
            && $concertId !== ''
            && $concertId === (string)($shareConcert['id'] ?? '');
        if (!$shareOk) {
            json_response(['ok' => false, 'error' => 'Nicht angemeldet'], 401);
        }
    }
}

// ---------- API: Version & Selbst-Update ----------

if ($action === 'version') {
    $info = ['version' => '0.0.0'];
    if (defined('KP_VERSION_FILE') && is_file(KP_VERSION_FILE)) {
        $data = json_decode((string)@file_get_contents(KP_VERSION_FILE), true);
        if (is_array($data)) $info = $data;
    }
    json_response(['ok' => true, 'version' => (string)($info['version'] ?? '0.0.0'), 'date' => (string)($info['date'] ?? '')]);
}

// Selbst-Update nur im Standalone-Betrieb (die Plattform wird zentral aktualisiert)
if (KP_MODE === 'standalone' && in_array($action, ['update_check', 'update_run', 'update_rollback'], true)) {
    require __DIR__ . '/update.php';
    if ($action === 'update_check') {
        json_response(kp_update_check());
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST erforderlich'], 405);
    }
    json_response($action === 'update_run' ? kp_update_run() : kp_update_rollback());
}

// ---------- API ----------

if ($action === 'concerts_list') {
    json_response(['ok' => true, 'concerts' => list_concerts($CONCERTS_DIR)]);
}

if ($action === 'concert_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_response(['ok' => false, 'error' => 'Ungültiges Format'], 400);
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') json_response(['ok' => false, 'error' => 'Name fehlt'], 400);
    kp_enforce_concert_limit($CONCERTS_DIR);
    $id = gen_id('k');
    while (is_file(concert_file($CONCERTS_DIR, $id))) $id = gen_id('k');
    $concert = [
        'id'          => $id,
        'group_id'    => $id,
        'is_starred'  => true,
        'name'        => mb_substr($name, 0, 200),
        'date'        => clean_datetime_local((string)($body['date'] ?? '')),
        'description' => mb_substr((string)($body['description'] ?? ''), 0, 5000),
        'rehearsals'  => clean_rehearsals(is_array($body['rehearsals'] ?? null) ? $body['rehearsals'] : []),
        'slots'       => default_slots(),
        'entries'     => [],
        'durations'   => [],
        'created_at'  => time(),
        'updated_at'  => time(),
    ];
    if (!save_concert($CONCERTS_DIR, $concert)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    json_response(['ok' => true, 'concert' => $concert]);
}

if ($action === 'concert_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = is_array($body) ? (string)($body['id'] ?? '') : '';
    if (!valid_concert_id($id)) json_response(['ok' => false, 'error' => 'Ungültige ID'], 400);
    $c = load_concert($CONCERTS_DIR, $id);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);

    $groupId = $c['group_id'];
    $list = read_concerts_index($CONCERTS_DIR) ?? [];
    $groupCount = count(array_filter($list, static fn($e) => ($e['group_id'] ?? $e['id']) === $groupId));

    if ($c['is_starred'] && $groupCount > 1) {
        json_response(['ok' => false, 'error' => 'Stern-Version kann nicht gelöscht werden, solange andere Versionen existieren'], 400);
    }

    // Aktive Freigabe dieses Konzerts aus dem Plattform-Index austragen
    if (is_array($c['share'] ?? null) && !empty($c['share']['enabled'])
        && function_exists('kp_platform_share_changed')) {
        kp_platform_share_changed($id, null);
    }

    if ($groupCount <= 1) {
        if (!delete_concert_files($CONCERTS_DIR, $DATA_DIR, $NOTES_DIR, $id)) {
            json_response(['ok' => false, 'error' => 'Löschen fehlgeschlagen'], 500);
        }
    } else {
        $ok = @unlink(concert_file($CONCERTS_DIR, $id));
        if ($ok) remove_from_concerts_index($CONCERTS_DIR, $id);
        if (!$ok) json_response(['ok' => false, 'error' => 'Löschen fehlgeschlagen'], 500);
    }
    json_response(['ok' => true]);
}

if ($action === 'concert_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = is_array($body) ? (string)($body['id'] ?? '') : '';
    if (!valid_concert_id($id)) json_response(['ok' => false, 'error' => 'Ungültige ID'], 400);
    $src = load_concert($CONCERTS_DIR, $id);
    if (!$src) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    if (!$src['is_starred']) json_response(['ok' => false, 'error' => 'Nur die Stern-Version kann gesichert werden'], 400);

    $newId = gen_id('k');
    while (is_file(concert_file($CONCERTS_DIR, $newId))) $newId = gen_id('k');

    $backup = [
        'id'            => $newId,
        'group_id'      => $src['group_id'],
        'is_starred'    => false,
        'name'          => $src['name'],
        'date'          => $src['date'],
        'description'   => $src['description'],
        'rehearsals'    => $src['rehearsals'],
        'slots'         => is_array($src['slots'] ?? null) ? $src['slots'] : default_slots(),
        'entries'       => $src['entries'],
        'durations'     => $src['durations'],
        'marker_labels' => $src['marker_labels'],
        'created_at'    => time(),
        'updated_at'    => time(),
    ];
    if (!save_concert($CONCERTS_DIR, $backup)) {
        json_response(['ok' => false, 'error' => 'Backup fehlgeschlagen'], 500);
    }
    $list = read_concerts_index($CONCERTS_DIR) ?? [];
    $groupMembers = array_values(array_filter($list, static fn($e) => ($e['group_id'] ?? $e['id']) === $src['group_id']));
    json_response(['ok' => true, 'backup' => concert_summary($backup), 'group' => $groupMembers]);
}

if ($action === 'concert_set_star' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = is_array($body) ? (string)($body['id'] ?? '') : '';
    if (!valid_concert_id($id)) json_response(['ok' => false, 'error' => 'Ungültige ID'], 400);
    $target = load_concert($CONCERTS_DIR, $id);
    if (!$target) json_response(['ok' => false, 'error' => 'Version nicht gefunden'], 404);
    if ($target['is_starred']) json_response(['ok' => true]);

    $groupId = $target['group_id'];
    $list = read_concerts_index($CONCERTS_DIR) ?? [];
    $members = array_filter($list, static fn($e) => ($e['group_id'] ?? $e['id']) === $groupId);
    // Eine aktive Freigabe wandert zur neu aktivierten Version mit —
    // der geteilte Link zeigt immer auf den aktiven Stand
    $wanderndeFreigabe = null;
    foreach ($members as $m) {
        $mid = (string)($m['id'] ?? '');
        $mc = load_concert($CONCERTS_DIR, $mid);
        if (!$mc) continue;
        if ($mid !== $id && is_array($mc['share'] ?? null) && !empty($mc['share']['enabled'])) {
            $wanderndeFreigabe = $mc['share'];
            unset($mc['share']);
        }
        $mc['is_starred'] = ($mid === $id);
        save_concert($CONCERTS_DIR, $mc);
    }
    if ($wanderndeFreigabe !== null) {
        $neu = load_concert($CONCERTS_DIR, $id);
        if ($neu) {
            $neu['share'] = $wanderndeFreigabe;
            save_concert($CONCERTS_DIR, $neu);
            if (function_exists('kp_platform_share_changed')) {
                kp_platform_share_changed($id, (string)$wanderndeFreigabe['token']);
            }
        }
    }
    $list = read_concerts_index($CONCERTS_DIR) ?? [];
    $groupMembers = array_values(array_filter($list, static fn($e) => ($e['group_id'] ?? $e['id']) === $groupId));
    json_response(['ok' => true, 'group' => $groupMembers]);
}

if ($action === 'concert_duplicate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = is_array($body) ? (string)($body['id'] ?? '') : '';
    if (!valid_concert_id($id)) json_response(['ok' => false, 'error' => 'Ungültige ID'], 400);
    kp_enforce_concert_limit($CONCERTS_DIR);
    // Duplizieren kopiert Notendateien physisch → zählt gegen die Speicher-Quota
    $srcForQuota = load_concert($CONCERTS_DIR, $id);
    if (!$srcForQuota) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    kp_enforce_storage_limit($TRACKS_DIR, $NOTES_DIR, kp_note_bytes_of_entries($DATA_DIR, $srcForQuota['entries']));
    $copy = duplicate_concert($CONCERTS_DIR, $DATA_DIR, $NOTES_DIR, $id);
    if (!$copy) json_response(['ok' => false, 'error' => 'Duplizieren fehlgeschlagen'], 500);
    json_response(['ok' => true, 'concert' => $copy]);
}

// Unified-Update: nimmt beliebige Felder (name/date/description/rehearsals/entries/durations)
// und wendet nur die übergebenen an. Vereinheitlicht concert_save_meta + save.
if ($action === 'concert_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $kpLock = kp_concert_lock($CONCERTS_DIR, $concertId);
    $c = load_concert($CONCERTS_DIR, $concertId);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_response(['ok' => false, 'error' => 'Ungültiges Format'], 400);
    // Optionales optimistisches Sperren (wie bei 'save'): schickt der Aufrufer
    // base_updated_at mit, wird ein zwischenzeitlich geänderter Stand nicht überschrieben.
    $base = isset($body['base_updated_at']) ? (int)$body['base_updated_at'] : 0;
    if ($base > 0 && (int)($c['updated_at'] ?? 0) > $base) {
        json_response([
            'ok' => false,
            'conflict' => true,
            'updated_at' => (int)($c['updated_at'] ?? 0),
            'error' => 'Das Konzert wurde inzwischen an anderer Stelle geändert. Bitte neu laden.',
        ], 409);
    }

    if (isset($body['name'])) {
        $n = trim((string)$body['name']);
        if ($n === '') json_response(['ok' => false, 'error' => 'Name darf nicht leer sein'], 400);
        $c['name'] = mb_substr($n, 0, 200);
    }
    if (isset($body['date']))        $c['date']        = clean_datetime_local((string)$body['date']);
    if (isset($body['description'])) $c['description'] = mb_substr((string)$body['description'], 0, 5000);
    if (isset($body['rehearsals']) && is_array($body['rehearsals'])) {
        $c['rehearsals'] = clean_rehearsals($body['rehearsals']);
    }
    if (isset($body['marker_labels']) && is_array($body['marker_labels'])) {
        $c['marker_labels'] = clean_marker_labels($body['marker_labels']);
    }
    if (isset($body['slots'])) {
        $c['slots'] = clean_slots($body['slots']);
        $slotIds = array_column($c['slots'], 'id');
        foreach ($c['entries'] as &$e) {
            if (!is_array($e) || ($e['type'] ?? '') === 'heading') continue;
            $e['tracks'] = (object)prune_entry_tracks($e['tracks'] ?? null, $slotIds);
        }
        unset($e);
    }
    if (isset($body['entries']) && is_array($body['entries'])) {
        $validTracks = array_flip(list_tracks($TRACKS_DIR));
        $c['entries'] = clean_entries($body['entries'], $validTracks, $DATA_DIR, $NOTES_DIR, array_column($c['slots'], 'id'));
        if (isset($body['durations']) && is_array($body['durations'])) {
            $c['durations'] = clean_durations($body['durations'], $validTracks);
        }
    }
    if (!save_concert($CONCERTS_DIR, $c)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    json_response(['ok' => true, 'concert' => $c]);
}

if ($action === 'concert_save_meta' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $kpLock = kp_concert_lock($CONCERTS_DIR, $concertId);
    $c = load_concert($CONCERTS_DIR, $concertId);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_response(['ok' => false, 'error' => 'Ungültiges Format'], 400);
    if (isset($body['name'])) {
        $n = trim((string)$body['name']);
        if ($n === '') json_response(['ok' => false, 'error' => 'Name darf nicht leer sein'], 400);
        $c['name'] = mb_substr($n, 0, 200);
    }
    if (isset($body['date']))        $c['date']        = clean_datetime_local((string)$body['date']);
    if (isset($body['description'])) $c['description'] = mb_substr((string)$body['description'], 0, 5000);
    if (isset($body['rehearsals']) && is_array($body['rehearsals'])) {
        $c['rehearsals'] = clean_rehearsals($body['rehearsals']);
    }
    if (isset($body['marker_labels']) && is_array($body['marker_labels'])) {
        $c['marker_labels'] = clean_marker_labels($body['marker_labels']);
    }
    if (isset($body['slots'])) {
        $c['slots'] = clean_slots($body['slots']);
        $slotIds = array_column($c['slots'], 'id');
        foreach ($c['entries'] as &$e) {
            if (!is_array($e) || ($e['type'] ?? '') === 'heading') continue;
            $e['tracks'] = (object)prune_entry_tracks($e['tracks'] ?? null, $slotIds);
        }
        unset($e);
    }
    if (!save_concert($CONCERTS_DIR, $c)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    // Freigabe-Geheimnis (Token/Hash) nie ausliefern — diese Antwort ist auch
    // für Mitbearbeiter mit Edit-Freigabe erreichbar. Besitzer bekommt share_info.
    $shareInfo = share_info_for_owner($c);
    unset($c['share']);
    if ($isLoggedIn) $c['share_info'] = $shareInfo;
    json_response(['ok' => true, 'concert' => $c]);
}

// Freigabe verwalten (nur Besitzer): aktivieren/deaktivieren, Passwort setzen,
// Link erzeugen/erneuern. Token + Passwort-Hash verlassen den Server nie.
if ($action === 'share_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $kpLock = kp_concert_lock($CONCERTS_DIR, $concertId);
    $c = load_concert($CONCERTS_DIR, $concertId);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_response(['ok' => false, 'error' => 'Ungültiges Format'], 400);

    $share = is_array($c['share'] ?? null) ? $c['share'] : [];
    $enabled    = !empty($body['enabled']);
    $regenerate = !empty($body['regenerate']);
    $password   = (string)($body['password'] ?? '');

    // Berechtigung der Betrachter: view | markers | edit
    if (isset($body['permission'])) {
        $perm = (string)$body['permission'];
        $share['permission'] = valid_share_permission($perm) ? $perm : 'view';
    }
    if (empty($share['permission']) || !valid_share_permission((string)$share['permission'])) {
        $share['permission'] = 'view';
    }

    if ($password !== '') {
        if (strlen($password) < 4) {
            json_response(['ok' => false, 'error' => 'Freigabe-Passwort: mindestens 4 Zeichen'], 400);
        }
        $share['pass_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    if ($enabled && empty($share['pass_hash'])) {
        json_response(['ok' => false, 'error' => 'Bitte zuerst ein Freigabe-Passwort festlegen'], 400);
    }
    if ($regenerate || empty($share['token']) || !valid_share_token((string)$share['token'])) {
        $share['token'] = gen_share_token();
    }
    $share['enabled'] = $enabled;
    $c['share'] = $share;
    if (!save_concert($CONCERTS_DIR, $c)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    // Plattform-Hook: globalen Freigabe-Index pflegen (Token → Besitzer)
    if (function_exists('kp_platform_share_changed')) {
        kp_platform_share_changed((string)$c['id'], $enabled ? (string)$share['token'] : null);
    }
    json_response(['ok' => true, 'share' => share_info_for_owner($c)]);
}

if ($action === 'state') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $c = load_concert($CONCERTS_DIR, $concertId);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    // Track-Pool nur ausliefern, wo er gebraucht wird: Besitzer und edit-Freigabe.
    // Nur-Lese-/Marker-Betrachter sehen den Pool nicht — das spart pro Aufruf das
    // Scannen (glob + je ein Stat-Call) des gesamten Track-Bestands.
    $wantsPool = $isLoggedIn
        || ($shareConcert !== null && share_permission_of($shareConcert) === 'edit');
    $tracks = $wantsPool ? list_tracks($TRACKS_DIR) : [];
    $c['available_tracks'] = $tracks;
    $c['track_meta']       = $wantsPool ? tracks_meta($TRACKS_DIR, $tracks) : (object)[];
    $c['markers_by_entry'] = markers_for_entries($TRACKS_META_DIR, $TRACKS_DIR, $c['entries']);
    // Freigabe-Geheimnisse (Token, Passwort-Hash) nie ausliefern;
    // der Besitzer bekommt stattdessen aufbereitete share_info
    $shareInfo = share_info_for_owner($c);
    unset($c['share']);
    if ($isLoggedIn) {
        $c['share_info'] = $shareInfo;
    }
    json_response($c);
}
if ($action === 'markers_get') {
    $entryId = (string)($_GET['entry'] ?? '');
    if (!valid_entry_id($entryId)) {
        json_response(['ok' => false, 'error' => 'Ungültige Eintrags-ID'], 400);
    }
    // Freigabe-Modus: Marker nur für Einträge des freigegebenen Konzerts ausliefern.
    // Sonst könnte ein anonymer Betrachter mit geratener Entry-ID Marker aus
    // anderen (privaten) Konzerten desselben Besitzers auslesen.
    if (!$isLoggedIn && $shareConcert !== null) {
        $shareEntryIds = array_map(
            static fn($e) => (string)($e['id'] ?? ''),
            is_array($shareConcert['entries'] ?? null) ? $shareConcert['entries'] : []
        );
        if (!in_array($entryId, $shareEntryIds, true)) {
            json_response(['ok' => false, 'error' => 'Nicht freigegeben'], 403);
        }
    }
    json_response(['ok' => true, 'entry' => $entryId, 'markers' => load_entry_markers($TRACKS_META_DIR, $entryId)]);
}

if ($action === 'markers_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_response(['ok' => false, 'error' => 'Ungültiges Format'], 400);
    $entryId = (string)($body['entry'] ?? '');
    if (!valid_entry_id($entryId)) {
        json_response(['ok' => false, 'error' => 'Ungültige Eintrags-ID'], 400);
    }
    if (!share_entry_allowed($isLoggedIn, $shareConcert, $entryId)) {
        json_response(['ok' => false, 'error' => 'Nicht freigegeben'], 403);
    }
    $markers = is_array($body['markers'] ?? null) ? $body['markers'] : [];
    if (count($markers) > 200) {
        json_response(['ok' => false, 'error' => 'Zu viele Marker (max 200 pro Track)'], 400);
    }
    if (!save_entry_markers($TRACKS_META_DIR, $entryId, $markers)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    json_response(['ok' => true]);
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $kpLock = kp_concert_lock($CONCERTS_DIR, $concertId);
    $c = load_concert($CONCERTS_DIR, $concertId);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['entries']) || !is_array($body['entries'])) {
        json_response(['ok' => false, 'error' => 'Ungültiges Format'], 400);
    }
    // Optimistisches Sperren: hat jemand anderes seit dem Laden dieses Tabs
    // gespeichert, ist der mitgeschickte Stempel älter als der aktuelle Stand.
    // Dann NICHT überschreiben, sondern den Tab zum Neuladen auffordern —
    // verhindert stillen Datenverlust bei gleichzeitigem Bearbeiten.
    $base = isset($body['base_updated_at']) ? (int)$body['base_updated_at'] : 0;
    if ($base > 0 && (int)($c['updated_at'] ?? 0) > $base) {
        json_response([
            'ok' => false,
            'conflict' => true,
            'updated_at' => (int)($c['updated_at'] ?? 0),
            'error' => 'Das Konzert wurde inzwischen an anderer Stelle geändert. Bitte neu laden.',
        ], 409);
    }
    $validTracks = array_flip(list_tracks($TRACKS_DIR));
    $c['entries']   = clean_entries($body['entries'], $validTracks, $DATA_DIR, $NOTES_DIR, array_column($c['slots'], 'id'));
    $c['durations'] = isset($body['durations']) && is_array($body['durations'])
        ? clean_durations($body['durations'], $validTracks)
        : ($c['durations'] ?? []);
    if (!save_concert($CONCERTS_DIR, $c)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    json_response(['ok' => true, 'updated_at' => (int)($c['updated_at'] ?? time())]);
}

// Speichert ausschließlich die Track-Längen (Cache aus den Audio-Metadaten).
// Bewusst getrennt von 'save': diese Aktion läuft passiv (Längen-Vorladen,
// Abspielen) und darf NIEMALS die Eintrags-Liste anderer Tabs überschreiben.
if ($action === 'durations_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $kpLock = kp_concert_lock($CONCERTS_DIR, $concertId);
    $c = load_concert($CONCERTS_DIR, $concertId);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['durations']) || !is_array($body['durations'])) {
        json_response(['ok' => false, 'error' => 'Ungültiges Format'], 400);
    }
    $validTracks = array_flip(list_tracks($TRACKS_DIR));
    // Neue Längen über den vorhandenen Stand legen, dann EINMAL bereinigen —
    // filtert ungültige/veraltete Werte aus beiden Quellen. Einträge bleiben unberührt.
    $merged = is_array($c['durations'] ?? null) ? $c['durations'] : [];
    foreach ($body['durations'] as $name => $dur) {
        $merged[$name] = $dur;
    }
    $c['durations'] = clean_durations($merged, $validTracks);
    // $touch=false: updated_at bleibt; $updateIndex=false: Längen sind nicht im
    // Index-Summary → kein Neu-Sortieren/Schreiben der Konzertliste nötig.
    if (!save_concert($CONCERTS_DIR, $c, false, false)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    json_response(['ok' => true]);
}

if ($action === 'entry_duplicate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $body = json_decode(file_get_contents('php://input'), true);
    $entryId = is_array($body) ? (string)($body['entry_id'] ?? '') : '';
    if (!valid_entry_id($entryId)) json_response(['ok' => false, 'error' => 'Ungültige Eintrags-ID'], 400);
    $kpLock = kp_concert_lock($CONCERTS_DIR, $concertId);
    // Eintrag-Duplizieren kopiert dessen Notendateien → Speicher-Quota prüfen
    $cForQuota = load_concert($CONCERTS_DIR, $concertId);
    if ($cForQuota) {
        foreach ($cForQuota['entries'] as $eQ) {
            if (is_array($eQ) && ($eQ['id'] ?? '') === $entryId) {
                kp_enforce_storage_limit($TRACKS_DIR, $NOTES_DIR, kp_note_bytes_of_entries($DATA_DIR, [$eQ]));
                break;
            }
        }
    }
    $updated = duplicate_entry($CONCERTS_DIR, $DATA_DIR, $NOTES_DIR, $concertId, $entryId);
    if (!$updated) json_response(['ok' => false, 'error' => 'Duplizieren fehlgeschlagen'], 500);
    json_response(['ok' => true, 'entries' => $updated['entries'], 'durations' => $updated['durations'] ?? [], 'updated_at' => (int)($updated['updated_at'] ?? time())]);
}

if ($action === 'upload_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    if (!is_file(concert_file($CONCERTS_DIR, $concertId))) {
        json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Upload fehlgeschlagen'], 400);
    }
    $f = $_FILES['file'];
    kp_enforce_storage_limit($TRACKS_DIR, $NOTES_DIR, (int)$f['size']);
    if ($f['size'] > $MAX_UPLOAD) {
        json_response(['ok' => false, 'error' => 'Datei zu groß (max 10 MB)'], 400);
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT, true)) {
        json_response(['ok' => false, 'error' => 'Dateityp nicht erlaubt'], 400);
    }
    if (!function_exists('finfo_open')) {
        json_response(['ok' => false, 'error' => 'Serverkonfiguration unvollständig (fileinfo fehlt)'], 500);
    }
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $fi ? (string)finfo_file($fi, $f['tmp_name']) : '';
    if ($fi) finfo_close($fi);
    $allowedMime = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];
    if (!isset($allowedMime[$ext]) || !in_array($mime, $allowedMime[$ext], true)) {
        json_response(['ok' => false, 'error' => 'Dateiinhalt passt nicht zur Endung'], 400);
    }

    ensure_dir($NOTES_DIR);
    ensure_notes_htaccess($NOTES_DIR);

    $entryId = (string)($_POST['entry_id'] ?? '');
    if (!valid_entry_id($entryId)) {
        json_response(['ok' => false, 'error' => 'Ungültige entry_id'], 400);
    }
    if (!share_entry_allowed($isLoggedIn, $shareConcert, $entryId)) {
        json_response(['ok' => false, 'error' => 'Nicht freigegeben'], 403);
    }
    $base    = sanitize_filename(pathinfo($f['name'], PATHINFO_FILENAME));
    if (strlen($base) > 80) $base = substr($base, 0, 80);
    $target  = $NOTES_DIR . '/' . $entryId . '_' . $base . '.' . $ext;
    $i = 1;
    while (file_exists($target) && $i < 1000) {
        $target = $NOTES_DIR . '/' . $entryId . '_' . $base . '_' . $i . '.' . $ext;
        $i++;
    }
    if ($i >= 1000) json_response(['ok' => false, 'error' => 'Zu viele Namenskollisionen'], 500);
    if (!move_uploaded_file($f['tmp_name'], $target)) {
        json_response(['ok' => false, 'error' => 'Speichern fehlgeschlagen'], 500);
    }
    json_response(['ok' => true, 'path' => 'notes/' . basename($target)]);
}

if ($action === 'delete_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($concertId === '') json_response(['ok' => false, 'error' => 'Konzert-ID fehlt'], 400);
    $c = load_concert($CONCERTS_DIR, $concertId);
    if (!$c) json_response(['ok' => false, 'error' => 'Konzert nicht gefunden'], 404);
    $body = json_decode(file_get_contents('php://input'), true);
    $path = is_array($body) ? (string)($body['path'] ?? '') : '';
    if (!note_path_is_safe($path, $DATA_DIR, $NOTES_DIR)) {
        json_response(['ok' => false, 'error' => 'Ungültiger Pfad'], 400);
    }
    // Owner-Check: die Datei muss in einem Eintrag dieses Konzerts referenziert sein
    $owned = false;
    foreach ($c['entries'] as $e) {
        $files = is_array($e['note_files'] ?? null) ? $e['note_files'] : [];
        if (in_array($path, $files, true)) { $owned = true; break; }
    }
    if (!$owned) {
        json_response(['ok' => false, 'error' => 'Datei gehört nicht zu diesem Konzert'], 403);
    }
    $real = realpath($DATA_DIR . '/' . $path);
    if ($real !== false && is_file($real) && @unlink($real)) {
        json_response(['ok' => true]);
    }
    json_response(['ok' => false, 'error' => 'Datei nicht gefunden oder Löschen fehlgeschlagen'], 404);
}

if ($action === 'upload_track' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['files'])) {
        json_response(['ok' => false, 'error' => 'Keine Dateien hochgeladen'], 400);
    }
    $files = $_FILES['files'];
    $maxSize = 50 * 1024 * 1024; // 50 MB pro Datei
    $uploaded = [];
    $errors   = [];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    $batchBytes = 0;
    for ($i = 0; $i < $count; $i++) {
        $batchBytes += (int)($files['size'][$i] ?? 0);
    }
    kp_enforce_storage_limit($TRACKS_DIR, $NOTES_DIR, $batchBytes);
    for ($i = 0; $i < $count; $i++) {
        $name  = (string)($files['name'][$i] ?? '');
        $tmp   = (string)($files['tmp_name'][$i] ?? '');
        $err   = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $size  = (int)($files['size'][$i] ?? 0);
        if ($err !== UPLOAD_ERR_OK) { $errors[] = $name . ': Upload-Fehler'; continue; }
        if ($size > $maxSize) { $errors[] = $name . ': Zu groß (max 50 MB)'; continue; }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'mp3') { $errors[] = $name . ': Nur MP3 erlaubt'; continue; }
        // MIME-Prüfung verpflichtend (fail-closed): fehlt fileinfo, wird die
        // Datei abgelehnt statt allein auf die Endung zu vertrauen.
        if (!function_exists('finfo_open')) {
            $errors[] = $name . ': Datei konnte nicht geprüft werden (Server-Konfiguration)';
            continue;
        }
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)finfo_file($fi, $tmp) : '';
        if ($fi) finfo_close($fi);
        if (!in_array($mime, ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'], true)) {
            $errors[] = $name . ': Kein gültiges MP3';
            continue;
        }
        $safeName = preg_replace('/[^\p{L}\p{N}._\- ]+/u', '_', pathinfo($name, PATHINFO_FILENAME));
        $safeName = trim($safeName, '._');
        if ($safeName === '') $safeName = 'track';
        $target = $TRACKS_DIR . '/' . $safeName . '.mp3';
        $j = 1;
        while (file_exists($target) && $j < 1000) {
            $target = $TRACKS_DIR . '/' . $safeName . '_' . $j . '.mp3';
            $j++;
        }
        if ($j >= 1000) { $errors[] = $name . ': Namenskollision'; continue; }
        if (!move_uploaded_file($tmp, $target)) { $errors[] = $name . ': Speichern fehlgeschlagen'; continue; }
        $uploaded[] = basename($target);
    }
    json_response(['ok' => true, 'uploaded' => $uploaded, 'errors' => $errors]);
}

if ($action === 'track_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $file = is_array($body) ? (string)($body['file'] ?? '') : '';
    $basename = basename($file);
    // Nur MP3s löschen — schützt z. B. die .htaccess im Tracks-Ordner
    if ($basename === '' || $basename !== $file
        || strtolower((string)pathinfo($basename, PATHINFO_EXTENSION)) !== 'mp3'
        || !is_file($TRACKS_DIR . '/' . $basename)) {
        json_response(['ok' => false, 'error' => 'Track nicht gefunden'], 404);
    }
    if (!@unlink($TRACKS_DIR . '/' . $basename)) {
        json_response(['ok' => false, 'error' => 'Löschen fehlgeschlagen'], 500);
    }
    $metaFile = track_meta_path($TRACKS_META_DIR, $basename);
    if (is_file($metaFile)) @unlink($metaFile);
    json_response(['ok' => true]);
}

// ---------- View-Routing ----------

$SHARE_MODE = false;
$SHARE_PERMISSION = 'view';
if (!$isLoggedIn) {
    if ($shareConcert !== null) {
        if ($shareGranted) {
            $view = 'detail';
            $concertId = (string)$shareConcert['id'];
            $SHARE_MODE = true;
            $SHARE_PERMISSION = share_permission_of($shareConcert);
        } else {
            $view = 'share_login';
        }
    } else {
        $view = 'login';
    }
} elseif ($concertId !== '' && is_file(concert_file($CONCERTS_DIR, $concertId))) {
    $view = 'detail';
} else {
    $view = 'main';
}

// Installierte Version für die Fußzeile der Hauptseite
$KP_VERSION = '0.0.0';
if (defined('KP_VERSION_FILE') && is_file(KP_VERSION_FILE)) {
    $vj = json_decode((string)@file_get_contents(KP_VERSION_FILE), true);
    if (is_array($vj) && isset($vj['version'])) $KP_VERSION = (string)$vj['version'];
}

?><!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect x='5' y='32' width='6' height='12' rx='3' fill='%235b8def'/><rect x='13' y='27' width='6' height='22' rx='3' fill='%235b8def'/><rect x='21' y='21' width='6' height='34' rx='3' fill='%235b8def'/><rect x='29' y='15' width='6' height='46' rx='3' fill='%235b8def'/><rect x='37' y='23' width='6' height='30' rx='3' fill='%235b8def'/><rect x='45' y='29' width='6' height='18' rx='3' fill='%235b8def'/><rect x='53' y='33' width='6' height='10' rx='3' fill='%235b8def'/><rect x='31' y='7' width='2' height='8' fill='%23ffd166'/><circle cx='32' cy='6' r='4.5' fill='%23ffd166'/><rect x='47' y='21' width='2' height='8' fill='%23ea6058'/><circle cx='48' cy='20' r='4.5' fill='%23ea6058'/></svg>" />
  <title><?= $view === 'detail' ? 'Konzertplaner — Programm' : 'Konzertplaner — Übersicht' ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(KP_ASSET_URL, ENT_QUOTES, 'UTF-8') ?>/konzertplaner.css?v=<?= $ASSET_VER ?>">
  <?php /* Plattform-Hook: zusätzliche Head-Elemente (z. B. Topbar-Stylesheet) */
        if (function_exists('kp_platform_head_html')) echo kp_platform_head_html(); ?>
</head>
<body class="view-<?= htmlspecialchars($view === 'share_login' ? 'login' : $view, ENT_QUOTES, 'UTF-8') ?><?= $SHARE_MODE ? ' share-mode share-perm-' . htmlspecialchars($SHARE_PERMISSION, ENT_QUOTES, 'UTF-8') : '' ?>">
<?php /* Plattform-Hook: gemeinsame Kopfleiste der Online-Plattform */
      if (function_exists('kp_platform_topbar_html')) echo kp_platform_topbar_html(); ?>
  <!-- SVG-Icon-Sprite (versteckt, wird via <use> referenziert) -->
  <svg xmlns="http://www.w3.org/2000/svg" class="svg-sprite-defs" aria-hidden="true" focusable="false">
    <defs>
      <symbol id="i-handle" viewBox="0 0 16 16">
        <circle cx="6" cy="3" r="1.3" fill="currentColor"/>
        <circle cx="10" cy="3" r="1.3" fill="currentColor"/>
        <circle cx="6" cy="8" r="1.3" fill="currentColor"/>
        <circle cx="10" cy="8" r="1.3" fill="currentColor"/>
        <circle cx="6" cy="13" r="1.3" fill="currentColor"/>
        <circle cx="10" cy="13" r="1.3" fill="currentColor"/>
      </symbol>
      <symbol id="i-play" viewBox="0 0 16 16"><path d="M4 3 L13 8 L4 13 Z" fill="currentColor"/></symbol>
      <symbol id="i-pause" viewBox="0 0 16 16">
        <rect x="4" y="3" width="3" height="10" fill="currentColor"/>
        <rect x="9" y="3" width="3" height="10" fill="currentColor"/>
      </symbol>
      <symbol id="i-stop" viewBox="0 0 16 16"><rect x="4" y="4" width="8" height="8" fill="currentColor"/></symbol>
      <symbol id="i-prev" viewBox="0 0 16 16"><rect x="3" y="3" width="2" height="10" fill="currentColor"/><path d="M13 3 L6 8 L13 13 Z" fill="currentColor"/></symbol>
      <symbol id="i-next" viewBox="0 0 16 16"><path d="M3 3 L10 8 L3 13 Z" fill="currentColor"/><rect x="11" y="3" width="2" height="10" fill="currentColor"/></symbol>
      <symbol id="i-close" viewBox="0 0 16 16"><path d="M4 4 L12 12 M12 4 L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></symbol>
      <symbol id="i-trash" viewBox="0 0 16 16"><path d="M3 4 H13 M6 4 V2.5 H10 V4 M5 4 L5.5 13.5 H10.5 L11 4 M7 7 V11 M9 7 V11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
      <symbol id="i-plus" viewBox="0 0 16 16"><path d="M8 3 V13 M3 8 H13" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></symbol>
      <symbol id="i-edit" viewBox="0 0 16 16"><path d="M2 14 L4.5 13.5 L13 5 L11 3 L2.5 11.5 Z M10 4 L12 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
      <symbol id="i-check" viewBox="0 0 16 16"><path d="M3 8 L7 12 L13 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
      <symbol id="i-arrow-up" viewBox="0 0 16 16"><path d="M8 3 V13 M4 7 L8 3 L12 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
      <symbol id="i-arrow-down" viewBox="0 0 16 16"><path d="M8 3 V13 M4 9 L8 13 L12 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
      <symbol id="i-back" viewBox="0 0 16 16"><path d="M11 3 L5 8 L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
      <symbol id="i-home" viewBox="0 0 16 16"><path d="M2 8 L8 2.5 L14 8 M3.5 7 V13 H7 V10 H9 V13 H12.5 V7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
      <symbol id="i-info" viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4" fill="none"/><circle cx="8" cy="4.7" r="0.9" fill="currentColor"/><path d="M8 7.2 V12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></symbol>
      <symbol id="i-cog" viewBox="0 0 16 16"><circle cx="8" cy="8" r="2.2" stroke="currentColor" stroke-width="1.3" fill="none"/><path d="M8 1.6 V3.2 M8 12.8 V14.4 M1.6 8 H3.2 M12.8 8 H14.4 M3.5 3.5 L4.6 4.6 M11.4 11.4 L12.5 12.5 M12.5 3.5 L11.4 4.6 M4.6 11.4 L3.5 12.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></symbol>
      <symbol id="i-copy" viewBox="0 0 16 16">
        <rect x="3" y="3" width="8" height="9" rx="1" stroke="currentColor" stroke-width="1.3" fill="none"/>
        <rect x="5.5" y="5.5" width="8" height="9" rx="1" stroke="currentColor" stroke-width="1.3" fill="var(--panel, #1a1d27)"/>
      </symbol>
      <!-- Geschlossene Kette: zwei vertikal ineinander hängende Glieder -->
      <symbol id="i-anchor" viewBox="0 0 20 28">
        <rect x="6" y="2.5" width="8" height="13" rx="4" ry="4.5"
              stroke="currentColor" stroke-width="1.8" fill="none"/>
        <rect x="6" y="12.5" width="8" height="13" rx="4" ry="4.5"
              stroke="currentColor" stroke-width="1.8" fill="none"/>
      </symbol>
      <!-- Offene Kette: dieselben Glieder, aber etwas auseinander -->
      <symbol id="i-anchor-open" viewBox="0 0 20 28">
        <rect x="6" y="1.5" width="8" height="11" rx="4" ry="4"
              stroke="currentColor" stroke-width="1.8" fill="none"/>
        <rect x="6" y="15.5" width="8" height="11" rx="4" ry="4"
              stroke="currentColor" stroke-width="1.8" fill="none"/>
      </symbol>
      <symbol id="i-backup" viewBox="0 0 16 16">
        <path d="M3 2 H13 V6 L8 9 L3 6 Z M3 8 L8 11 L13 8 V14 H3 Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round" fill="none"/>
      </symbol>
      <symbol id="i-star" viewBox="0 0 16 16">
        <path d="M8 1.5 L9.8 5.8 L14.5 6.2 L11 9.4 L12 14 L8 11.5 L4 14 L5 9.4 L1.5 6.2 L6.2 5.8 Z" fill="currentColor"/>
      </symbol>
      <symbol id="i-star-open" viewBox="0 0 16 16">
        <path d="M8 1.5 L9.8 5.8 L14.5 6.2 L11 9.4 L12 14 L8 11.5 L4 14 L5 9.4 L1.5 6.2 L6.2 5.8 Z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
      </symbol>
    </defs>
  </svg>

<?php if ($view === 'share_login'): ?>

  <main class="login-wrap">
    <div class="login-box">
      <h1><?= htmlspecialchars((string)($shareConcert['name'] ?? 'Konzert'), ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="login-hint">Dieses Konzertprogramm wurde für dich freigegeben. Gib das Passwort ein, das du vom Ersteller bekommen hast.</p>
      <form id="share-login-form">
        <label>
          <span>Freigabe-Passwort</span>
          <input type="password" name="password" id="share-pw" autocomplete="off" aria-describedby="share-error" required autofocus>
        </label>
        <div id="share-error" class="login-error" role="alert" hidden>Falsches Passwort</div>
        <button type="submit" class="primary login-btn">Programm ansehen</button>
      </form>
    </div>
  </main>
  <script>
  (function() {
    'use strict';
    var form = document.getElementById('share-login-form');
    var pw = document.getElementById('share-pw');
    var err = document.getElementById('share-error');
    var token = <?= json_encode($SHARE_TOKEN, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    form.addEventListener('submit', function(ev) {
      ev.preventDefault();
      err.hidden = true;
      fetch('?action=share_login&share=' + encodeURIComponent(token), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({token: token, password: pw.value})
      })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d.ok) { location.reload(); return; }
        err.hidden = false;
        pw.value = '';
        pw.focus();
      })
      .catch(function() {
        err.textContent = 'Verbindungsfehler';
        err.hidden = false;
      });
    });
  })();
  </script>

<?php elseif ($view === 'login'): ?>

  <main class="login-wrap">
    <div class="login-box">
      <h1>Konzertplaner</h1>
      <p class="login-hint">Bitte Passwort eingeben, um fortzufahren.</p>
      <form id="login-form">
        <label>
          <span>Passwort</span>
          <input type="password" name="password" id="login-pw" autocomplete="current-password" aria-describedby="login-error" required autofocus>
        </label>
        <div id="login-error" class="login-error" role="alert" hidden>Falsches Passwort</div>
        <button type="submit" class="primary login-btn">Anmelden</button>
      </form>
    </div>
  </main>
  <script>
  (function() {
    var form = document.getElementById('login-form');
    var pw = document.getElementById('login-pw');
    var err = document.getElementById('login-error');
    form.addEventListener('submit', function(ev) {
      ev.preventDefault();
      err.hidden = true;
      fetch('?action=login', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({password: pw.value})
      })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d.ok) { location.reload(); return; }
        err.hidden = false;
        pw.value = '';
        pw.focus();
      })
      .catch(function() {
        err.textContent = 'Verbindungsfehler';
        err.hidden = false;
      });
    });
  })();
  </script>

<?php elseif ($view === 'main'): ?>

  <header>
    <h1>Konzertplaner</h1>
    <div class="header-sub">Übersicht aller Konzerte</div>
    <div class="spacer"></div>
    <div class="status" id="status" role="status" aria-live="polite" aria-atomic="true"></div>
    <button class="primary" id="add-concert" type="button">
      <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
      Neues Konzert
    </button>
    <?php if (function_exists('kp_platform_nav_html')) echo kp_platform_nav_html(); ?>
    <?php if (KP_MODE === 'standalone'): ?>
    <a href="?action=logout" class="logout-btn" title="Abmelden">Abmelden</a>
    <?php endif; ?>
  </header>

  <main>
    <div id="concerts" role="list" aria-label="Konzerte"></div>
  </main>

  <footer class="app-footer">
    <span class="app-version">Konzertplaner v<?= htmlspecialchars($KP_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
    <?php if (KP_MODE === 'standalone'): ?>
    <button type="button" class="ghost" id="update-check-btn">Auf Updates prüfen</button>
    <span id="update-result" role="status" aria-live="polite"></span>
    <button type="button" class="primary" id="update-run-btn" hidden>Update installieren</button>
    <?php endif; ?>
  </footer>
  <?php if (KP_MODE === 'standalone'): ?>
  <script>
  (function () {
    'use strict';
    var checkBtn = document.getElementById('update-check-btn');
    var runBtn   = document.getElementById('update-run-btn');
    var result   = document.getElementById('update-result');
    if (!checkBtn) return;
    checkBtn.addEventListener('click', function () {
      result.textContent = 'Prüfe…';
      fetch('?action=update_check')
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { result.textContent = d.error || 'Prüfung fehlgeschlagen'; return; }
          if (d.update_available) {
            result.textContent = 'Neue Version ' + d.remote_version + ' verfügbar (installiert: v' + d.current_version + ')';
            runBtn.hidden = false;
          } else {
            result.textContent = 'Auf dem neuesten Stand (v' + d.current_version + ')';
          }
        })
        .catch(function () { result.textContent = 'Update-Server nicht erreichbar'; });
    });
    runBtn.addEventListener('click', async function () {
      if (!await kpConfirm('Update jetzt installieren?\n\nEin Backup der aktuellen Version wird automatisch angelegt. Konzerte, Tracks und Einstellungen bleiben erhalten.')) return;
      runBtn.disabled = true;
      result.textContent = 'Installiere Update…';
      fetch('?action=update_run', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) {
            result.textContent = 'Update auf v' + d.version + ' installiert. Seite wird neu geladen…';
            setTimeout(function () { location.reload(); }, 1500);
          } else {
            result.textContent = 'Fehler: ' + (d.error || 'Update fehlgeschlagen');
            runBtn.disabled = false;
          }
        })
        .catch(function () {
          result.textContent = 'Update fehlgeschlagen';
          runBtn.disabled = false;
        });
    });
  })();
  </script>
  <?php endif; ?>

  <div id="concert-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="concert-modal-title" hidden>
    <div class="modal-inner">
      <h2 id="concert-modal-title">Neues Konzert</h2>
      <form id="concert-form" autocomplete="off">
        <label>
          <span>Name</span>
          <input type="text" name="name" required maxlength="200" placeholder="z. B. Sommerkonzert 2026">
        </label>
        <label>
          <span>Datum / Uhrzeit</span>
          <input type="datetime-local" name="date">
        </label>
        <label>
          <span>Beschreibung</span>
          <textarea name="description" maxlength="5000" rows="3" placeholder="Optionale Notizen, Veranstaltungsort, …"></textarea>
        </label>
        <div class="modal-actions">
          <button type="button" class="ghost" id="concert-cancel">Abbrechen</button>
          <button type="submit" class="primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>

  <script type="module">
  (function () {
    'use strict';

    const els = {
      list:       document.getElementById('concerts'),
      addBtn:     document.getElementById('add-concert'),
      status:     document.getElementById('status'),
      modal:      document.getElementById('concert-modal'),
      modalTitle: document.getElementById('concert-modal-title'),
      form:       document.getElementById('concert-form'),
      cancel:     document.getElementById('concert-cancel'),
    };

    let statusTimer = null;
    function flashStatus(text, ms) {
      clearTimeout(statusTimer);
      els.status.textContent = text;
      if (ms) statusTimer = setTimeout(() => { els.status.textContent = ''; }, ms);
    }

    function icon(id, extraClass) {
      const cls = 'icon' + (extraClass ? ' ' + extraClass : '');
      return '<svg class="' + cls + '" aria-hidden="true"><use href="#' + id + '"/></svg>';
    }

    function fmtDateLocal(s) {
      if (!s) return '';
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
      if (!m) return s;
      return m[3] + '.' + m[2] + '.' + m[1] + ' ' + m[4] + ':' + m[5];
    }
    function fmtTimestamp(ts) {
      if (!ts || !isFinite(ts)) return '';
      const d = new Date(ts * 1000);
      if (isNaN(d.getTime())) return '';
      const pad = (n) => n.toString().padStart(2, '0');
      return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear()
        + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    let concerts = [];
    let modalOpener = null;
    let editingId  = null;

    function load() {
      flashStatus('Lade…', 0);
      fetch('?action=concerts_list')
        .then(r => r.json())
        .then(d => {
          if (!d.ok) { flashStatus('Fehler beim Laden', 0); return; }
          concerts = d.concerts || [];
          render();
          flashStatus('', 0);
        })
        .catch(() => flashStatus('Verbindungsfehler', 0));
    }

    function render() {
      els.list.innerHTML = '';
      if (!concerts.length) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.textContent = 'Noch keine Konzerte. Klick auf „Neues Konzert".';
        els.list.appendChild(empty);
        return;
      }
      const groups = {};
      const groupOrder = [];
      concerts.forEach(c => {
        const g = c.group_id || c.id;
        if (!groups[g]) { groups[g] = []; groupOrder.push(g); }
        groups[g].push(c);
      });
      groupOrder.forEach(g => els.list.appendChild(renderCard(groups[g])));
    }

    function renderCard(group) {
      const starred = group.find(c => c.is_starred) || group[0];
      const versions = group.slice().sort((a, b) => (b.created_at || 0) - (a.created_at || 0));

      const card = document.createElement('div');
      card.className = 'concert-card';
      card.setAttribute('role', 'listitem');

      const link = document.createElement('a');
      link.className = 'concert-link';
      link.href = '?k=' + encodeURIComponent(starred.id);

      const titleRow = document.createElement('div');
      titleRow.className = 'concert-title-row';
      const name = document.createElement('h2');
      name.className = 'concert-name';
      name.textContent = starred.name;
      titleRow.appendChild(name);
      if (starred.date) {
        const date = document.createElement('span');
        date.className = 'concert-date';
        date.textContent = fmtDateLocal(starred.date);
        titleRow.appendChild(date);
      }
      link.appendChild(titleRow);

      const meta = document.createElement('div');
      meta.className = 'concert-meta';
      const trackCount = (starred.track_count ?? starred.entry_count) || 0;
      const rehCount   = (starred.rehearsal_count || 0);
      meta.innerHTML = trackCount + ' Track' + (trackCount === 1 ? '' : 's')
        + ' · ' + rehCount + ' Probetermin' + (rehCount === 1 ? '' : 'e');
      link.appendChild(meta);

      if (starred.description) {
        const desc = document.createElement('p');
        desc.className = 'concert-desc';
        desc.textContent = starred.description;
        link.appendChild(desc);
      }
      card.appendChild(link);

      const bottom = document.createElement('div');
      bottom.className = 'concert-bottom';

      const actions = document.createElement('div');
      actions.className = 'concert-actions';
      const bkp = document.createElement('button');
      bkp.type = 'button';
      bkp.className = 'ghost';
      bkp.innerHTML = icon('i-backup') + ' Backup';
      bkp.addEventListener('click', () => backupConcert(starred));
      actions.appendChild(bkp);
      const dup = document.createElement('button');
      dup.type = 'button';
      dup.className = 'ghost';
      dup.innerHTML = icon('i-copy') + ' Duplizieren';
      dup.addEventListener('click', () => duplicateConcert(starred));
      actions.appendChild(dup);
      bottom.appendChild(actions);

      const verList = document.createElement('div');
      verList.className = 'concert-versions';
      versions.forEach(v => {
        const row = document.createElement('div');
        row.className = 'version-row';

        const dateLink = document.createElement('a');
        dateLink.className = 'version-date';
        dateLink.href = '?k=' + encodeURIComponent(v.id);
        dateLink.textContent = fmtTimestamp(v.created_at);
        row.appendChild(dateLink);

        if (v.description) {
          const verDesc = document.createElement('span');
          verDesc.className = 'version-desc';
          verDesc.textContent = v.description;
          row.appendChild(verDesc);
        }

        const verMeta = document.createElement('span');
        verMeta.className = 'version-meta';
        verMeta.textContent = ((v.track_count ?? v.entry_count) || 0) + ' Tracks';
        row.appendChild(verMeta);

        const starBtn = document.createElement('button');
        starBtn.type = 'button';
        starBtn.className = 'version-star' + (v.is_starred ? ' is-active' : '');
        starBtn.innerHTML = icon(v.is_starred ? 'i-star' : 'i-star-open');
        starBtn.title = v.is_starred ? 'Aktive Version' : 'Als aktive Version setzen';
        starBtn.setAttribute('aria-label', v.is_starred ? 'Aktive Version' : 'Version vom ' + fmtTimestamp(v.created_at) + ' als aktiv setzen');
        if (!v.is_starred) {
          starBtn.addEventListener('click', () => setStarVersion(v.id));
        }
        row.appendChild(starBtn);

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'version-delete';
        delBtn.innerHTML = icon('i-trash');
        delBtn.setAttribute('aria-label', 'Version vom ' + fmtTimestamp(v.created_at) + ' löschen');
        if (v.is_starred && versions.length > 1) {
          delBtn.disabled = true;
          delBtn.title = 'Stern-Version kann nicht gelöscht werden';
        } else {
          delBtn.title = 'Version löschen';
          delBtn.addEventListener('click', () => deleteVersion(v, group));
        }
        row.appendChild(delBtn);

        verList.appendChild(row);
      });
      bottom.appendChild(verList);
      card.appendChild(bottom);
      return card;
    }

    function openModal(opener) {
      modalOpener = opener || null;
      editingId = null;
      els.modalTitle.textContent = 'Neues Konzert';
      els.form.reset();
      els.modal.hidden = false;
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(() => els.form.querySelector('[name="name"]').focus());
    }
    function closeModal() {
      els.modal.hidden = true;
      document.body.style.overflow = '';
      if (modalOpener && document.contains(modalOpener)) modalOpener.focus();
      modalOpener = null;
    }
    els.addBtn.addEventListener('click', () => openModal(els.addBtn));
    els.cancel.addEventListener('click', closeModal);
    els.modal.addEventListener('click', (ev) => { if (ev.target === els.modal) closeModal(); });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !els.modal.hidden) closeModal();
    });
    // Focus-Trap im Modal: Tab/Shift+Tab innerhalb halten
    els.modal.addEventListener('keydown', (ev) => {
      if (els.modal.hidden || ev.key !== 'Tab') return;
      const focusables = els.modal.querySelectorAll(
        'input, textarea, button, [href], [tabindex]:not([tabindex="-1"])'
      );
      const list = Array.from(focusables).filter(el => !el.disabled && el.offsetParent !== null);
      if (!list.length) return;
      const first = list[0];
      const last  = list[list.length - 1];
      if (ev.shiftKey && document.activeElement === first) {
        ev.preventDefault(); last.focus();
      } else if (!ev.shiftKey && document.activeElement === last) {
        ev.preventDefault(); first.focus();
      }
    });

    els.form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const fd = new FormData(els.form);
      const payload = {
        name:        (fd.get('name') || '').toString().trim(),
        date:        (fd.get('date') || '').toString().trim(),
        description: (fd.get('description') || '').toString(),
      };
      if (!payload.name) return;
      flashStatus('Speichere…', 0);
      fetch('?action=concert_create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { kpAlert('Fehler: ' + (d.error || 'Speichern fehlgeschlagen')); flashStatus('', 0); return; }
        closeModal();
        location.href = '?k=' + encodeURIComponent(d.concert.id);
      })
      .catch(() => { kpAlert('Verbindungsfehler'); flashStatus('', 0); });
    });

    function backupConcert(starred) {
      flashStatus('Erstelle Backup…', 0);
      fetch('?action=concert_backup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: starred.id })
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { kpAlert('Fehler: ' + (d.error || 'Backup fehlgeschlagen')); flashStatus('', 0); return; }
        flashStatus('Backup erstellt', 1500);
        load();
      })
      .catch(() => { kpAlert('Verbindungsfehler'); flashStatus('', 0); });
    }

    async function duplicateConcert(starred) {
      if (!await kpConfirm('Konzert „' + starred.name + '" als eigenständige Kopie duplizieren?')) return;
      flashStatus('Dupliziere…', 0);
      fetch('?action=concert_duplicate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: starred.id })
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { kpAlert('Fehler: ' + (d.error || 'Duplizieren fehlgeschlagen')); flashStatus('', 0); return; }
        flashStatus('Kopie angelegt', 1500);
        load();
      })
      .catch(() => { kpAlert('Verbindungsfehler'); flashStatus('', 0); });
    }

    function setStarVersion(versionId) {
      flashStatus('Setze Stern…', 0);
      fetch('?action=concert_set_star', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: versionId })
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { kpAlert('Fehler: ' + (d.error || 'Stern setzen fehlgeschlagen')); flashStatus('', 0); return; }
        flashStatus('Stern gesetzt', 1200);
        load();
      })
      .catch(() => { kpAlert('Verbindungsfehler'); flashStatus('', 0); });
    }

    async function deleteVersion(version, group) {
      let msg;
      if (version.is_starred && group.length <= 1) {
        msg = 'Konzert „' + version.name + '" wirklich löschen?\n\n'
          + 'Dies ist die einzige Version. Programm und alle hochgeladenen Notendateien '
          + 'werden entfernt. Die MP3-Tracks bleiben erhalten.';
      } else {
        msg = 'Version vom ' + fmtTimestamp(version.created_at) + ' löschen?';
      }
      if (!await kpConfirm(msg)) return;
      flashStatus('Lösche…', 0);
      fetch('?action=concert_delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: version.id })
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { kpAlert('Fehler: ' + (d.error || 'Löschen fehlgeschlagen')); flashStatus('', 0); return; }
        flashStatus('Gelöscht', 1200);
        load();
      })
      .catch(() => { kpAlert('Verbindungsfehler'); flashStatus('', 0); });
    }

    load();
  })();
  </script>

<?php else: /* view === 'detail' */ ?>

  <header class="detail-header">
    <div class="header-main">
      <?php if (!$SHARE_MODE): ?>
      <a class="back-link" href="<?= htmlspecialchars(defined('KP_HOME_URL') ? KP_HOME_URL : './', ENT_QUOTES, 'UTF-8') ?>" aria-label="Zur Konzert-Übersicht" title="Übersicht">
        <svg class="icon icon-lg" aria-hidden="true"><use href="#i-home"/></svg>
      </a>
      <?php endif; ?>
      <div class="header-title-block">
        <h1 class="concert-h1">
          <span id="concert-name-display" class="concert-name-display">Konzert</span>
        </h1>
        <span id="concert-description-display" class="concert-description-display"></span>
      </div>
      <div class="header-actions">
        <?php if ($SHARE_MODE):
          $shareBadge = $SHARE_PERMISSION === 'edit' ? 'Freigabe · Bearbeiten'
                      : ($SHARE_PERMISSION === 'markers' ? 'Freigabe · Marker' : 'Freigegebenes Programm');
          $shareBadgeTitle = $SHARE_PERMISSION === 'edit' ? 'Diese Freigabe erlaubt dir, das Programm zu bearbeiten'
                      : ($SHARE_PERMISSION === 'markers' ? 'Diese Freigabe erlaubt dir, Marker zu setzen und zu ändern' : 'Du siehst eine schreibgeschützte Freigabe dieses Konzerts');
        ?>
        <span class="share-badge" title="<?= htmlspecialchars($shareBadgeTitle, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($shareBadge, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <?php /* Buttons bleiben im DOM (JS-Referenzen!), CSS blendet sie im Freigabe-Modus aus */ ?>
        <button id="meta-edit" type="button" class="primary meta-edit-btn"
                aria-label="Konzert-Einstellungen" title="Konzert-Einstellungen">
          <svg class="icon" aria-hidden="true"><use href="#i-cog"/></svg>
          <span class="btn-label">Einstellungen</span>
        </button>
        <button class="outline edit-only-btn" id="add-entry" type="button" aria-label="Track-Eintrag hinzufügen" title="Neuen Track-Eintrag hinzufügen">
          <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
          <span class="btn-label">Track</span>
        </button>
        <button class="outline edit-only-btn" id="add-heading" type="button" aria-label="Abschnitts-Überschrift einfügen" title="Abschnitts-Überschrift (Text-Trenner) einfügen">
          <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
          <span class="btn-label">Text</span>
        </button>
        <button id="edit-toggle" type="button" aria-pressed="false">
          <svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg>
          <span class="btn-label toggle-label-off">Bearbeiten</span>
          <span class="btn-label toggle-label-on">Bearbeiten beenden</span>
        </button>
        <?php if (!$SHARE_MODE && function_exists('kp_platform_nav_html')) echo kp_platform_nav_html(); ?>
        <?php if (!$SHARE_MODE && KP_MODE === 'standalone'): ?>
        <a href="?action=logout" class="logout-btn" title="Abmelden">Abmelden</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="header-meta-row">
      <span id="concert-date-display" class="concert-date-display" data-empty="1"></span>
      <span class="total"><strong id="total-duration">0:00</strong> · <span id="entry-count">0</span> Tracks</span>
      <div class="status" id="status-detail" role="status" aria-live="polite" aria-atomic="true"></div>
    </div>
  </header>

  <!-- Konzert-Edit-Modal (Name / Datum / Beschreibung / Probetermine) -->
  <div id="meta-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="meta-modal-title" hidden>
    <div class="modal-inner modal-inner-wide">
      <h2 id="meta-modal-title">Einstellungen</h2>
      <form id="meta-form" autocomplete="off">
        <fieldset class="meta-fs">
          <legend>Eckdaten</legend>
          <label>
            <span>Name</span>
            <input type="text" name="name" id="meta-name" maxlength="200" required>
          </label>
          <label>
            <span>Datum / Uhrzeit</span>
            <input type="datetime-local" name="date" id="meta-date">
          </label>
          <label>
            <span>Beschreibung / Kommentar</span>
            <textarea name="description" id="meta-description" maxlength="5000" rows="2"></textarea>
          </label>
        </fieldset>
        <fieldset class="meta-fs">
          <legend>Probetermine</legend>
          <div id="rehearsals" role="list"></div>
          <button type="button" id="add-rehearsal" class="ghost">
            <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
            Probetermin hinzufügen
          </button>
        </fieldset>
        <fieldset class="meta-fs">
          <legend>Track-Slots</legend>
          <p class="slot-hint">Jeder Programm-Eintrag kann Tracks in mehreren Slots halten (z. B. Original und Live). Die Reihenfolge bestimmt die Priorität für die Laufzeit; die Farbe erscheint als Punkt am Slot.</p>
          <div id="slots-editor" role="list"></div>
          <button type="button" id="add-slot" class="ghost">
            <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
            Slot hinzufügen
          </button>
        </fieldset>
        <fieldset class="meta-fs">
          <legend>Marker-Bezeichnungen</legend>
          <div class="marker-labels-grid">
            <label class="marker-label-row">
              <span class="marker-label-dot" data-color="yellow"></span>
              <input type="text" name="ml_yellow" id="ml-yellow" maxlength="30" placeholder="Gelb" aria-label="Beschriftung für gelbe Marker">
            </label>
            <label class="marker-label-row">
              <span class="marker-label-dot" data-color="green"></span>
              <input type="text" name="ml_green" id="ml-green" maxlength="30" placeholder="Grün" aria-label="Beschriftung für grüne Marker">
            </label>
            <label class="marker-label-row">
              <span class="marker-label-dot" data-color="purple"></span>
              <input type="text" name="ml_purple" id="ml-purple" maxlength="30" placeholder="Lila" aria-label="Beschriftung für lila Marker">
            </label>
            <label class="marker-label-row">
              <span class="marker-label-dot" data-color="blue"></span>
              <input type="text" name="ml_blue" id="ml-blue" maxlength="30" placeholder="Blau" aria-label="Beschriftung für blaue Marker">
            </label>
            <label class="marker-label-row">
              <span class="marker-label-dot" data-color="red"></span>
              <input type="text" name="ml_red" id="ml-red" maxlength="30" placeholder="Rot" aria-label="Beschriftung für rote Marker">
            </label>
          </div>
        </fieldset>
        <fieldset class="meta-fs share-fs">
          <legend>Freigabe</legend>
          <p class="share-fs-hint">Teile dieses Konzert mit deiner Band: per Link und Passwort, ganz ohne Account für die Betrachter. Du bestimmst, wie viel sie dürfen.</p>
          <label class="share-check">
            <input type="checkbox" id="share-enabled">
            <span>Freigabe aktiv</span>
          </label>
          <label>
            <span>Was dürfen Betrachter?</span>
            <select id="share-permission">
              <option value="view">Nur ansehen &amp; abspielen</option>
              <option value="markers">Marker setzen &amp; bearbeiten</option>
              <option value="edit">Das ganze Programm bearbeiten</option>
            </select>
          </label>
          <label>
            <span>Freigabe-Passwort <small>(leer lassen = unverändert)</small></span>
            <input type="text" id="share-password" maxlength="100" autocomplete="off" placeholder="Passwort für Betrachter">
          </label>
          <div class="share-url-row" id="share-url-row" hidden>
            <input type="text" id="share-url" readonly aria-label="Freigabe-Link">
            <button type="button" class="ghost" id="share-copy">Link kopieren</button>
            <button type="button" class="ghost" id="share-rotate" title="Erzeugt einen neuen Link — der alte wird ungültig">Neuen Link erzeugen</button>
          </div>
          <div class="share-status" id="share-status" role="status" aria-live="polite"></div>
          <button type="button" class="primary" id="share-save">Freigabe speichern</button>
        </fieldset>
        <div class="modal-actions">
          <button type="button" class="ghost" id="meta-cancel">Schließen</button>
          <button type="submit" class="primary">Übernehmen</button>
        </div>
      </form>
    </div>
  </div>

  <main>
    <div id="entries" role="list" aria-label="Konzert-Einträge"></div>

    <section id="pool-section" aria-labelledby="pool-heading">
      <h2 id="pool-heading">Musikarchiv</h2>
      <div id="pool" role="list" aria-label="Soundtrack-Pool"></div>
      <button type="button" class="primary upload-tracks-btn" id="upload-tracks-btn">
        <svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>
        MP3 hochladen
      </button>
    </section>

    <!-- MP3-Upload-Modal -->
    <div id="upload-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="upload-modal-title" hidden>
      <div class="modal-inner">
        <h2 id="upload-modal-title">MP3-Tracks hochladen</h2>
        <div id="upload-dropzone" class="upload-dropzone" role="button" tabindex="0"
             aria-label="Dateien hier ablegen oder klicken zum Auswählen">
          <svg class="icon icon-xl" aria-hidden="true"><use href="#i-plus"/></svg>
          <span class="upload-dropzone-text">MP3-Dateien hierher ziehen<br>oder klicken zum Auswählen</span>
          <input type="file" id="upload-file-input" accept=".mp3,audio/mpeg" multiple hidden>
        </div>
        <div id="upload-file-list" class="upload-file-list" role="list" aria-label="Ausgewählte Dateien"></div>
        <div id="upload-status" class="upload-status" role="status" aria-live="polite"></div>
        <div class="modal-actions">
          <button type="button" class="ghost" id="upload-cancel">Schließen</button>
          <button type="button" class="primary" id="upload-submit" disabled>
            <svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>
            Hochladen
          </button>
        </div>
      </div>
    </div>
  </main>

  <!-- Notiz-Editor-Modal -->
  <div id="note-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="note-modal-title" hidden>
    <div class="modal-inner">
      <h2 id="note-modal-title">Notiz</h2>
      <textarea id="note-modal-text" class="note-modal-text" rows="8" maxlength="5000"
                placeholder="Notiz zu diesem Song — z. B. Hornstimme, Übergang, Lichtwechsel, „3 Halbtöne tiefer“ …"></textarea>
      <div class="modal-actions">
        <button type="button" class="ghost" id="note-modal-cancel">Schließen</button>
        <button type="button" class="primary" id="note-modal-save">Speichern</button>
      </div>
    </div>
  </div>

  <!-- BPM-Modal -->
  <div id="bpm-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="bpm-modal-title" hidden>
    <div class="modal-inner">
      <h2 id="bpm-modal-title">BPM</h2>
      <label for="bpm-modal-input" class="bpm-modal-label">Schläge pro Minute (1–400)</label>
      <input type="number" id="bpm-modal-input" class="bpm-modal-input" min="1" max="400" step="1"
             inputmode="numeric" placeholder="z. B. 120">
      <div class="modal-actions">
        <button type="button" class="ghost" id="bpm-modal-cancel">Schließen</button>
        <button type="button" class="primary" id="bpm-modal-save">Speichern</button>
      </div>
    </div>
  </div>

  <!-- Noten-Editor-Modal (abcjs) -->
  <div id="sheet-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="sheet-modal-title" hidden>
    <div class="modal-inner modal-inner-wide">
      <h2 id="sheet-modal-title">Noten</h2>
      <div id="sheet-toolbar" class="sheet-toolbar" role="toolbar" aria-label="Noten-Werkzeuge">
        <button type="button" data-ins="C " title="Note C">C</button>
        <button type="button" data-ins="D " title="Note D">D</button>
        <button type="button" data-ins="E " title="Note E">E</button>
        <button type="button" data-ins="F " title="Note F">F</button>
        <button type="button" data-ins="G " title="Note G">G</button>
        <button type="button" data-ins="A " title="Note A">A</button>
        <button type="button" data-ins="B " title="Note B (deutsches H)">B</button>
        <span class="sheet-tb-sep" aria-hidden="true"></span>
        <button type="button" data-ins="^" title="Kreuz (z. B. ^C = Cis)">♯</button>
        <button type="button" data-ins="_" title="Be (z. B. _B = Bes)">♭</button>
        <button type="button" data-ins="=" title="Auflösungszeichen">♮</button>
        <button type="button" data-ins="z " title="Pause">Pause</button>
        <button type="button" data-ins="| " title="Taktstrich">|</button>
        <button type="button" data-ins="|: " title="Wiederholung Anfang">|:</button>
        <button type="button" data-ins=":| " title="Wiederholung Ende">:|</button>
      </div>
      <div class="sheet-grid">
        <div class="sheet-input-col">
          <label for="sheet-abc" class="sheet-label">Noten (ABC-Notation)</label>
          <textarea id="sheet-abc" class="sheet-abc" spellcheck="false" rows="10"
                    placeholder="C D E F | G A B c |"></textarea>
          <details class="sheet-help">
            <summary>Kurz-Spickzettel</summary>
            <ul>
              <li><strong>Tonhöhe:</strong> C D E F G A B (B = deutsches H). Kleinbuchstaben = Oktave höher (c d e …), Komma tiefer (<code>C,</code>), Apostroph höher (<code>c'</code>).</li>
              <li><strong>Länge:</strong> Zahl verlängert (<code>C2</code> = doppelt), Schrägstrich verkürzt (<code>C/2</code>).</li>
              <li><strong>Vorzeichen:</strong> <code>^C</code> = Cis, <code>_C</code> = Ces, <code>=C</code> = aufgelöst.</li>
              <li><strong>Takt &amp; Wiederholung:</strong> <code>|</code> · <code>|: … :|</code></li>
              <li><strong>Kopf:</strong> <code>M:</code> Taktart, <code>L:</code> Grundlänge, <code>K:</code> Tonart.</li>
            </ul>
          </details>
        </div>
        <div class="sheet-preview-col">
          <!-- bewusst KEIN aria-live: das SVG-Notenbild würde Screenreadern
               sonst bei jedem Tastenanschlag sinnfreie Ansagen erzeugen -->
          <div id="sheet-preview" class="sheet-preview"></div>
          <button type="button" class="ghost sheet-play-btn" id="sheet-play">
            <svg class="icon" aria-hidden="true"><use href="#i-play"/></svg>
            Abspielen
          </button>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="ghost" id="sheet-cancel">Schließen</button>
        <button type="button" class="primary" id="sheet-save">Speichern</button>
      </div>
    </div>
  </div>

  <div id="lightbox" role="dialog" aria-modal="true" aria-label="Noten-Vorschau" hidden>
    <a id="lightbox-open-ext" href="#" target="_blank" rel="noopener">In neuem Tab öffnen</a>
    <button id="lightbox-close" type="button" aria-label="Vorschau schließen">
      <svg class="icon" aria-hidden="true"><use href="#i-close"/></svg>
      Schließen
    </button>
    <div id="lightbox-content"></div>
  </div>

  <div id="player" role="region" aria-label="Audioplayer">
    <div id="player-top">
      <span id="now-playing" aria-label="Aktuell wiedergegebener Titel"></span>
      <span id="player-time" aria-live="off">0:00 / 0:00</span>
      <button class="ghost" id="close-player" type="button" aria-label="Player schließen" title="Schließen">
        <svg class="icon" aria-hidden="true"><use href="#i-close"/></svg>
      </button>
      <button class="ghost" id="marker-toggle" type="button" aria-pressed="false"
              aria-label="Marker bearbeiten" title="Marker bearbeiten" disabled>
        <svg class="icon" aria-hidden="true"><use href="#i-edit"/></svg>
      </button>
    </div>
    <div id="waveform-wrap">
      <div id="waveform" aria-hidden="true"></div>
      <div id="marker-overlay" aria-hidden="true"></div>
      <div id="marker-ahead" class="marker-ahead" role="status" aria-live="assertive" aria-atomic="true"></div>
    </div>
    <div id="player-controls">
      <button id="player-prev" type="button" aria-label="Vorheriger Eintrag" title="Vorheriger Eintrag" disabled>
        <svg class="icon" aria-hidden="true"><use href="#i-prev"/></svg>
      </button>
      <button id="player-play" type="button" aria-label="Abspielen">
        <svg class="icon" aria-hidden="true"><use href="#i-play"/></svg>
        <span class="label">Play</span>
      </button>
      <button id="player-stop" type="button" aria-label="Stop">
        <svg class="icon" aria-hidden="true"><use href="#i-stop"/></svg>
        Stop
      </button>
      <button id="player-next" type="button" aria-label="Nächster Eintrag" title="Nächster Eintrag" disabled>
        <svg class="icon" aria-hidden="true"><use href="#i-next"/></svg>
      </button>
      <label class="autoplay-ctrl" for="autoplay-mode">
        <span>Auto-Play</span>
        <select id="autoplay-mode" title="Nach Songende automatisch den nächsten Eintrag abspielen">
          <option value="off">aus</option>
        </select>
      </label>
      <span class="marker-hint" id="marker-hint" aria-hidden="true">Klick in die Wave setzt einen Marker</span>
      <div class="marker-legend" id="marker-legend" aria-label="Marker-Farblegende">
        <span class="legend-item" data-color="yellow"><span class="legend-dot"></span><span class="legend-text">Gelb</span></span>
        <span class="legend-item" data-color="green"><span class="legend-dot"></span><span class="legend-text">Grün</span></span>
        <span class="legend-item" data-color="purple"><span class="legend-dot"></span><span class="legend-text">Lila</span></span>
        <span class="legend-item" data-color="blue"><span class="legend-dot"></span><span class="legend-text">Blau</span></span>
        <span class="legend-item" data-color="red"><span class="legend-dot"></span><span class="legend-text">Rot</span></span>
      </div>
    </div>
  </div>

  <!-- Marker-Edit-Popup -->
  <div id="marker-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="marker-modal-title" hidden>
    <div class="modal-inner modal-inner-narrow">
      <h2 id="marker-modal-title">Marker</h2>
      <form id="marker-form" autocomplete="off">
        <div class="marker-time-line">
          <span class="marker-time-label">Zeit:</span>
          <strong id="marker-time-display">0:00</strong>
        </div>
        <label>
          <span>Text (max 60 Zeichen)</span>
          <input type="text" name="text" id="marker-text" maxlength="60"
                 placeholder="z. B. Einsatz Hörner" required>
        </label>
        <fieldset class="marker-color-fs">
          <legend>Farbe</legend>
          <div class="marker-color-row" id="marker-color-row" role="group" aria-label="Marker-Farbe">
            <button type="button" class="marker-color-btn" data-color="yellow" aria-label="Gelb" title="Gelb"></button>
            <button type="button" class="marker-color-btn" data-color="red"    aria-label="Rot"  title="Rot"></button>
            <button type="button" class="marker-color-btn" data-color="green"  aria-label="Grün" title="Grün"></button>
            <button type="button" class="marker-color-btn" data-color="blue"   aria-label="Blau" title="Blau"></button>
            <button type="button" class="marker-color-btn" data-color="purple" aria-label="Lila" title="Lila"></button>
          </div>
        </fieldset>
        <div class="modal-actions">
          <button type="button" class="danger" id="marker-delete" hidden>
            <svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg>
            Löschen
          </button>
          <span class="spacer"></span>
          <button type="button" class="ghost" id="marker-cancel">Abbrechen</button>
          <button type="submit" class="primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Noten-Editor: abcjs (UMD, 504 KB) wird NICHT mehr vorab geladen, sondern
       erst beim ersten Öffnen des Noten-Editors nachgeladen (ensureAbcjs) —
       spart auf jeder Detail-/Freigabe-Seite ein halbes MB auf dem kritischen Pfad. -->

  <script type="module">
  import WaveSurfer from '<?php
    // ES-Module brauchen einen aufloesbaren Specifier (/, ./, ../). Ein nackter
    // relativer Pfad wie "core/assets" ist ein "bare specifier" und schlaegt im
    // Browser fehl ("Failed to resolve module specifier"). Darum ./ voranstellen,
    // wenn KP_ASSET_URL relativ ohne fuehrendes Zeichen ist.
    $kpAssetBase = rtrim(KP_ASSET_URL, '/');
    if ($kpAssetBase === '' || !preg_match('#^([a-z][a-z0-9+.-]*:)?/|^\.\.?/#i', $kpAssetBase)) {
        $kpAssetBase = './' . $kpAssetBase;
    }
    echo htmlspecialchars($kpAssetBase, ENT_QUOTES, 'UTF-8');
  ?>/wavesurfer.esm.js';

  (function () {
    'use strict';

    const KONZERT_ID = <?= json_encode($concertId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // In der Freigabe-Ansicht tragen alle API-Aufrufe das Token mit —
    // die Plattform braucht es, um den Datenbereich des Besitzers zu finden
    const SHARE_TOKEN = <?= json_encode($SHARE_MODE ? $SHARE_TOKEN : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const API_K = '&k=' + encodeURIComponent(KONZERT_ID)
      + (SHARE_TOKEN ? '&share=' + encodeURIComponent(SHARE_TOKEN) : '');
    // Browser-Pfad zum Datenordner (Tracks/Noten), vom Einstiegspunkt definiert
    const DATA_URL = <?= json_encode(rtrim(KP_DATA_URL, '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // Noten-Editor-Bibliothek (wird lazy nachgeladen, siehe ensureAbcjs)
    const ABCJS_URL = <?= json_encode(rtrim(KP_ASSET_URL, '/') . '/abcjs-basic-min.js?v=' . $ASSET_VER, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // Freigabe-Ansicht: Betrachter ohne Account
    const SHARE_MODE = <?= json_encode($SHARE_MODE) ?>;
    // Berechtigung der Freigabe: 'view' | 'markers' | 'edit'
    const SHARE_PERMISSION = <?= json_encode($SHARE_PERMISSION) ?>;
    // Rechte dieser Sitzung: Besitzer (kein Share) darf alles.
    const CAN_EDIT_PROGRAM = !SHARE_MODE || SHARE_PERMISSION === 'edit';
    const CAN_EDIT_MARKERS = !SHARE_MODE || SHARE_PERMISSION === 'edit' || SHARE_PERMISSION === 'markers';

    const MARKER_LABEL_DEFAULTS = {yellow:'Gelb', green:'Grün', purple:'Lila', blue:'Blau', red:'Rot'};
    const state = {
      meta: { id: KONZERT_ID, name: '', date: '', description: '', rehearsals: [], markerLabels: {}, slots: [], updatedAt: 0 },
      entries: [], durations: {}, available: [], trackMeta: {}, currentTrack: null,
      currentEntryId: null,
      markersByEntry: {},
      markerEditMode: false,
      currentDuration: 0,
      currentMarkerEditing: null, // id oder null bei Neu-Anlage
    };
    const els = {
      entries:    document.getElementById('entries'),
      pool:       document.getElementById('pool'),
      total:      document.getElementById('total-duration'),
      count:      document.getElementById('entry-count'),
      status:     document.getElementById('status-detail'),
      addBtn:     document.getElementById('add-entry'),
      editToggle: document.getElementById('edit-toggle'),
      addHeadingBtn: document.getElementById('add-heading'),
      player:     document.getElementById('player'),
      waveform:   document.getElementById('waveform'),
      playerTime: document.getElementById('player-time'),
      playerPlay: document.getElementById('player-play'),
      playerStop: document.getElementById('player-stop'),
      playerPrev: document.getElementById('player-prev'),
      playerNext: document.getElementById('player-next'),
      autoplayMode: document.getElementById('autoplay-mode'),
      nowPlaying: document.getElementById('now-playing'),
      closePlayer:document.getElementById('close-player'),
      lightbox:   document.getElementById('lightbox'),
      lightboxContent: document.getElementById('lightbox-content'),
      lightboxClose:   document.getElementById('lightbox-close'),
      lightboxExt:     document.getElementById('lightbox-open-ext'),
      noteModal:       document.getElementById('note-modal'),
      noteModalText:   document.getElementById('note-modal-text'),
      noteModalTitle:  document.getElementById('note-modal-title'),
      noteModalSave:   document.getElementById('note-modal-save'),
      noteModalCancel: document.getElementById('note-modal-cancel'),
      bpmModal:        document.getElementById('bpm-modal'),
      bpmModalInput:   document.getElementById('bpm-modal-input'),
      bpmModalTitle:   document.getElementById('bpm-modal-title'),
      bpmModalSave:    document.getElementById('bpm-modal-save'),
      bpmModalCancel:  document.getElementById('bpm-modal-cancel'),
      sheetModal:      document.getElementById('sheet-modal'),
      sheetTitle:      document.getElementById('sheet-modal-title'),
      sheetAbc:        document.getElementById('sheet-abc'),
      sheetPreview:    document.getElementById('sheet-preview'),
      sheetToolbar:    document.getElementById('sheet-toolbar'),
      sheetPlay:       document.getElementById('sheet-play'),
      sheetSave:       document.getElementById('sheet-save'),
      sheetCancel:     document.getElementById('sheet-cancel'),
      metaName:    document.getElementById('meta-name'),
      metaDate:    document.getElementById('meta-date'),
      metaDesc:    document.getElementById('meta-description'),
      nameDisplay: document.getElementById('concert-name-display'),
      descDisplay: document.getElementById('concert-description-display'),
      dateDisplay: document.getElementById('concert-date-display'),
      metaModal:   document.getElementById('meta-modal'),
      metaModalTitle: document.getElementById('meta-modal-title'),
      metaEditBtn: document.getElementById('meta-edit'),
      metaCancel:  document.getElementById('meta-cancel'),
      metaForm:    document.getElementById('meta-form'),
      rehearsals:  document.getElementById('rehearsals'),
      addRehearsal: document.getElementById('add-rehearsal'),
      slotsEditor: document.getElementById('slots-editor'),
      addSlot: document.getElementById('add-slot'),
      waveformWrap: document.getElementById('waveform-wrap'),
      markerOverlay: document.getElementById('marker-overlay'),
      markerToggle: document.getElementById('marker-toggle'),
      markerAhead: document.getElementById('marker-ahead'),
      markerModal: document.getElementById('marker-modal'),
      markerModalTitle: document.getElementById('marker-modal-title'),
      markerForm: document.getElementById('marker-form'),
      markerText: document.getElementById('marker-text'),
      markerCancel: document.getElementById('marker-cancel'),
      markerDelete: document.getElementById('marker-delete'),
      markerTimeDisplay: document.getElementById('marker-time-display'),
      markerColorRow:    document.getElementById('marker-color-row'),
      markerLegend:      document.getElementById('marker-legend'),
      mlYellow:          document.getElementById('ml-yellow'),
      mlGreen:           document.getElementById('ml-green'),
      mlPurple:          document.getElementById('ml-purple'),
      mlBlue:            document.getElementById('ml-blue'),
      mlRed:             document.getElementById('ml-red'),
      uploadBtn:         document.getElementById('upload-tracks-btn'),
      uploadModal:       document.getElementById('upload-modal'),
      uploadDropzone:    document.getElementById('upload-dropzone'),
      uploadFileInput:   document.getElementById('upload-file-input'),
      uploadFileList:    document.getElementById('upload-file-list'),
      uploadStatus:      document.getElementById('upload-status'),
      uploadCancel:      document.getElementById('upload-cancel'),
      uploadSubmit:      document.getElementById('upload-submit'),
    };

    function icon(id, extraClass) {
      const cls = 'icon' + (extraClass ? ' ' + extraClass : '');
      return '<svg class="' + cls + '" aria-hidden="true"><use href="#' + id + '"/></svg>';
    }

    // ---------- Bearbeitungsmodus ----------
    const EDIT_KEY = 'konzertplaner.edit_mode';
    function isEditMode() { return CAN_EDIT_PROGRAM && document.body.classList.contains('edit-mode'); }
    function setEditMode(on, announce) {
      if (!CAN_EDIT_PROGRAM) on = false; // ohne Bearbeitungsrecht immer schreibgeschützt
      document.body.classList.toggle('edit-mode', on);
      els.editToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
      try { localStorage.setItem(EDIT_KEY, on ? '1' : '0'); } catch (e) {}
      // Programm-Inputs im Read-Only-Modus sperren und aus der Tab-Reihenfolge nehmen.
      // Konzert-Meta-Inputs sind im Modal und nur im Edit-Modus erreichbar.
      document.querySelectorAll('.entry-title, .heading-title, .manual-dur input').forEach(el => {
        if (on) {
          el.removeAttribute('readonly');
          el.tabIndex = 0;
        } else {
          el.setAttribute('readonly', 'readonly');
          el.tabIndex = -1;
        }
      });
      document.querySelectorAll('.handle').forEach(el => { el.tabIndex = on ? 0 : -1; });
      document.querySelectorAll('.pool-item').forEach(el => {
        el.tabIndex = on ? 0 : -1;
        el.draggable = on;
      });
      document.querySelectorAll('.anchor-toggle').forEach(el => {
        el.tabIndex = on ? 0 : -1;
        if (on) el.removeAttribute('aria-hidden');
        else el.setAttribute('aria-hidden', 'true');
      });
      if (announce) flashStatus(on ? 'Bearbeitungsmodus aktiviert' : 'Bearbeitungsmodus beendet', 1500);
      // Probetermine neu rendern, damit Platzhalter-Text dem Modus folgt
      if (typeof renderRehearsals === 'function') renderRehearsals();
      const active = document.activeElement;
      if (active && !on && active.closest('.handle, .entry-actions, .note-file button, .upload-btn, #pool-section, #add-entry, #add-rehearsal, .rehearsal-remove')) {
        els.editToggle.focus();
      }
    }
    function initEditMode() {
      let stored = '0';
      try { stored = localStorage.getItem(EDIT_KEY) || '0'; } catch (e) {}
      setEditMode(stored === '1', false);
    }
    els.editToggle.addEventListener('click', () => setEditMode(!isEditMode(), true));

    // ---------- Status / Live-Region ----------
    let statusTimer = null;
    function flashStatus(text, ms) {
      clearTimeout(statusTimer);
      els.status.textContent = text;
      if (ms) statusTimer = setTimeout(() => { els.status.textContent = ''; }, ms);
    }

    // ---------- Init ----------
    fetch('?action=state' + API_K)
      .then(r => r.json())
      .then(data => {
        // state-Endpoint liefert das Konzert direkt (ohne ok-Wrapper) ODER {ok:false,error:...}
        if (data && data.ok === false) {
          flashStatus('Fehler: ' + (data.error || 'Laden fehlgeschlagen'), 0);
          return;
        }
        state.meta = {
          id:          data.id || KONZERT_ID,
          name:        data.name || '',
          date:        data.date || '',
          description: data.description || '',
          rehearsals:  Array.isArray(data.rehearsals) ? data.rehearsals : [],
          markerLabels: (data.marker_labels && typeof data.marker_labels === 'object') ? data.marker_labels : {},
          slots: Array.isArray(data.slots) ? data.slots : [],
          updatedAt: data.updated_at || 0,
        };
        state.entries   = data.entries || [];
        state.durations = data.durations || {};
        state.available = data.available_tracks || [];
        shareInfo = data.share_info || null;
        fillShareUi();
        state.trackMeta = data.track_meta || {};
        state.markersByEntry = data.markers_by_entry || {};
        applyMetaToView();
        render();
        rebuildAutoplayOptions();
        renderRehearsals();
        renderSlotsEditor();
        initEditMode();
        setTimeout(preloadDurations, 500);
      })
      .catch(() => flashStatus('Verbindungsfehler beim Laden', 0));

    function markerLabel(color) {
      return (state.meta.markerLabels && state.meta.markerLabels[color]) || MARKER_LABEL_DEFAULTS[color] || color;
    }
    function applyMarkerLabels() {
      if (!els.markerLegend) return;
      els.markerLegend.querySelectorAll('.legend-item').forEach(item => {
        const c = item.dataset.color;
        const txt = item.querySelector('.legend-text');
        if (txt && c) txt.textContent = markerLabel(c);
      });
      els.mlYellow.value = state.meta.markerLabels.yellow || '';
      els.mlGreen.value  = state.meta.markerLabels.green  || '';
      els.mlPurple.value = state.meta.markerLabels.purple || '';
      els.mlBlue.value   = state.meta.markerLabels.blue   || '';
      els.mlRed.value    = state.meta.markerLabels.red    || '';
      els.mlYellow.placeholder = MARKER_LABEL_DEFAULTS.yellow;
      els.mlGreen.placeholder  = MARKER_LABEL_DEFAULTS.green;
      els.mlPurple.placeholder = MARKER_LABEL_DEFAULTS.purple;
      els.mlBlue.placeholder   = MARKER_LABEL_DEFAULTS.blue;
      els.mlRed.placeholder    = MARKER_LABEL_DEFAULTS.red;
    }
    function applyMetaToView() {
      const displayName = state.meta.name || 'Konzert';
      document.title = displayName + ' — Konzertplaner';
      els.nameDisplay.textContent = displayName;
      els.descDisplay.textContent = state.meta.description || '';
      els.metaName.value = state.meta.name || '';
      els.metaDate.value = state.meta.date || '';
      els.metaDesc.value = state.meta.description || '';
      updateDateDisplay();
      updatePrintMeta();
      applyMarkerLabels();
    }
    function updatePrintMeta() {
      // Druck-Titel und Druck-Meta (Datum + Beschreibung) als data-attribute am body
      document.body.dataset.printTitle = state.meta.name || 'Konzert';
      const parts = [];
      if (state.meta.date) parts.push(fmtDateLocal(state.meta.date));
      if (state.meta.description) parts.push(state.meta.description);
      document.body.dataset.printMeta = parts.join(' · ');
    }
    function updateDateDisplay() {
      const d = state.meta.date || '';
      els.dateDisplay.textContent = d ? fmtDateLocal(d) : '';
      els.dateDisplay.dataset.empty = d ? '0' : '1';
    }

    // ---------- Persistenz ----------
    let saveTimer = null;
    let saveInFlight = false;   // verhindert überlappende Speicherungen desselben Tabs
    let saveQueued = false;     // Änderung während einer laufenden Speicherung → danach nachspeichern
    function save(immediate = false) {
      if (!CAN_EDIT_PROGRAM) return; // ohne Bearbeitungsrecht nicht speichern
      clearTimeout(saveTimer);
      // saveTimer konsequent nullen: er dient als „Speicherung steht aus"-
      // Indikator (duplicateEntry-Warteschleife, Unload-Beacon) und wäre nach
      // dem ersten Save sonst für immer truthy.
      saveTimer = null;
      const doSave = () => {
        // Nie zwei Speicherungen gleichzeitig: sonst könnte die zweite mit einem
        // veralteten Stempel laufen und einen Konflikt mit dem eigenen Tab auslösen.
        if (saveInFlight) { saveQueued = true; return; }
        saveInFlight = true;
        flashStatus('Speichere…', 0);
        const afterDone = () => {
          saveInFlight = false;
          if (saveQueued) { saveQueued = false; doSave(); } // zwischenzeitliche Änderung nachreichen
        };
        fetch('?action=save' + API_K, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            entries: state.entries,
            durations: state.durations,
            base_updated_at: state.meta.updatedAt || 0,
          })
        })
        .then(r => r.json())
        .then(d => {
          saveInFlight = false;
          if (d && d.conflict) { saveQueued = false; handleSaveConflict(); return; }
          if (d && d.ok && typeof d.updated_at !== 'undefined') state.meta.updatedAt = d.updated_at;
          flashStatus(d.ok ? 'Gespeichert' : 'Fehler', d.ok ? 1200 : 0);
          if (saveQueued) { saveQueued = false; doSave(); }
        })
        .catch(() => { flashStatus('Fehler', 0); afterDone(); });
      };
      if (immediate) doSave();
      else saveTimer = setTimeout(() => { saveTimer = null; doSave(); }, 400);
    }

    // Speichert NUR die Track-Längen (Cache). Läuft passiv (Längen-Vorladen,
    // Abspielen) und fasst die Eintrags-Liste bewusst nicht an — so kann ein
    // nur geöffneter Tab die Einträge eines anderen nicht überschreiben.
    function saveDurations() {
      if (!CAN_EDIT_PROGRAM) return;
      fetch('?action=durations_save' + API_K, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ durations: state.durations })
      }).catch(() => {});
    }

    // Konflikt: jemand anderes hat seit dem Laden dieses Tabs gespeichert.
    // Statt still zu überschreiben, den Nutzer informieren und zum Neuladen anbieten.
    let conflictPrompted = false;
    let conflictDeclined = false;
    async function handleSaveConflict() {
      flashStatus('Nicht gespeichert — anderswo geändert. Bitte Seite neu laden.', 0);
      // Nach einem abgelehnten Neuladen nicht bei jeder weiteren Änderung erneut
      // den Dialog öffnen — der Hinweis bleibt stehen, gespeichert wird bis zum
      // Neuladen ohnehin nichts.
      if (conflictPrompted || conflictDeclined) return;
      conflictPrompted = true;
      const reload = await kpConfirm('Dieses Konzert wurde gerade an anderer Stelle geändert (z. B. von einem Bandkollegen). Deine letzte Änderung wurde nicht gespeichert.\n\nJetzt neu laden, um den aktuellen Stand zu holen?\n\n(Ohne Neuladen wird NICHT mehr gespeichert.)');
      if (reload) { location.reload(); return; }
      conflictPrompted = false;
      conflictDeclined = true;
    }

    let metaSaveTimer = null;
    function saveMeta() {
      if (!CAN_EDIT_PROGRAM) return; // ohne Bearbeitungsrecht nicht speichern
      clearTimeout(metaSaveTimer);
      metaSaveTimer = setTimeout(() => {
        flashStatus('Speichere…', 0);
        fetch('?action=concert_save_meta' + API_K, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: state.meta.name,
            date: state.meta.date,
            description: state.meta.description,
            rehearsals: state.meta.rehearsals,
            marker_labels: state.meta.markerLabels,
            slots: state.meta.slots,
          })
        })
        .then(r => r.json())
        .then(d => {
          if (!d.ok) { flashStatus('Fehler: ' + (d.error || 'Speichern'), 0); return; }
          if (d.concert) {
            state.meta = {
              id:          d.concert.id,
              name:        d.concert.name,
              date:        d.concert.date,
              description: d.concert.description,
              rehearsals:  d.concert.rehearsals || [],
              markerLabels: d.concert.marker_labels || {},
              slots: Array.isArray(d.concert.slots) ? d.concert.slots : (state.meta.slots || []),
              updatedAt: d.concert.updated_at || 0,
            };
            render();
            rebuildAutoplayOptions();
            renderSlotsEditor();
            // Server-bereinigte Werte zurückspielen, falls sie sich geändert haben
            if (els.metaName.value !== state.meta.name) els.metaName.value = state.meta.name;
            if (els.metaDate.value !== state.meta.date) els.metaDate.value = state.meta.date;
            if (els.metaDesc.value !== state.meta.description) els.metaDesc.value = state.meta.description;
            updateDateDisplay();
          }
          const displayName = state.meta.name || 'Konzert';
          document.title = displayName + ' — Konzertplaner';
          els.nameDisplay.textContent = displayName;
          els.descDisplay.textContent = state.meta.description || '';
          flashStatus('Gespeichert', 1200);
        })
        .catch(() => flashStatus('Fehler', 0));
      }, 400);
    }

    // ---------- Hilfen ----------
    // Generiert eine garantiert lange ID (10 Hex-Zeichen) per crypto.getRandomValues
    function randomHex10() {
      const a = new Uint8Array(5);
      (window.crypto || window.msCrypto).getRandomValues(a);
      return Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
    }
    function uid() { return 'e_' + randomHex10(); }
    function pid() { return 'p_' + randomHex10(); }
    function fmt(sec) {
      if (!sec || !isFinite(sec)) return '–:––';
      const m = Math.floor(sec / 60);
      const s = Math.floor(sec % 60).toString().padStart(2, '0');
      return m + ':' + s;
    }
    function fmtTotal(sec) {
      if (!sec) return '0:00';
      const h = Math.floor(sec / 3600);
      const m = Math.floor((sec % 3600) / 60);
      const s = Math.floor(sec % 60).toString().padStart(2, '0');
      return h > 0
        ? h + ':' + m.toString().padStart(2, '0') + ':' + s
        : m + ':' + s;
    }
    function fmtDate(ts) {
      if (!ts || !isFinite(ts)) return '';
      const d = new Date(ts * 1000);
      if (isNaN(d.getTime())) return '';
      const pad = (n) => n.toString().padStart(2, '0');
      return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear()
        + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function fmtDateLocal(s) {
      if (!s) return '';
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
      if (!m) return s;
      return m[3] + '.' + m[2] + '.' + m[1] + ' ' + m[4] + ':' + m[5];
    }
    function trackMtime(name) {
      const m = state.trackMeta[name];
      return (m && typeof m.mtime === 'number') ? m.mtime : 0;
    }
    function parseDuration(str) {
      const s = (str || '').trim();
      if (!s) return 0;
      if (/^\d+$/.test(s)) return parseInt(s, 10);
      const parts = s.split(':').map(p => parseInt(p, 10));
      if (parts.some(n => isNaN(n) || n < 0)) return 0;
      if (parts.length === 2) return parts[0] * 60 + parts[1];
      if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
      return 0;
    }
    function trackUrl(name) { return DATA_URL + '/tracks/' + encodeURIComponent(name); }
    // Gespeicherte Noten-Pfade ('notes/…') in Browser-URLs übersetzen
    function noteUrl(path) { return DATA_URL + '/' + String(path).split('/').map(encodeURIComponent).join('/'); }
    function updateInlineDurationForTrack(name, seconds) {
      const txt = seconds ? fmt(seconds) : '';
      state.entries.forEach(e => {
        if (!Object.values(entryTracks(e)).includes(name)) return;
        const dur = entryDuration(e);
        const entryEl = els.entries.querySelector('[data-id="' + e.id + '"]');
        if (!entryEl) return;
        const durEl = entryEl.querySelector('.entry-dur-inline');
        if (durEl) durEl.textContent = dur ? fmt(dur) : '';
      });
      document.querySelectorAll('.pool-item').forEach(el => {
        if (el.dataset.track !== name) return;
        const durEl = el.querySelector('.track-dur-inline');
        if (durEl) durEl.textContent = txt;
      });
    }
    function assignedTracks() {
      const set = new Set();
      state.entries.forEach(e => {
        Object.values(entryTracks(e)).forEach(fn => { if (fn) set.add(fn); });
      });
      return set;
    }
    // ---------- Track-Slots (pro Konzert konfigurierbar, 1–5) ----------
    function concertSlots() {
      return (state.meta && Array.isArray(state.meta.slots) && state.meta.slots.length)
        ? state.meta.slots : [{ id: 's1', name: 'Track', color: 'blue' }];
    }
    function entryTracks(e) {
      return (e && e.tracks && typeof e.tracks === 'object') ? e.tracks : {};
    }
    function entryHasAnyTrack(e) {
      const t = entryTracks(e);
      return concertSlots().some(s => t[s.id]);
    }
    function allSlotsFilled(e) {
      const t = entryTracks(e);
      return concertSlots().every(s => t[s.id]);
    }
    // Erster befüllter Slot in Slot-Reihenfolge = Priorität (für Dauer & Anzeige).
    function firstFilledTrack(e) {
      const t = entryTracks(e);
      for (const s of concertSlots()) { if (t[s.id]) return t[s.id]; }
      return null;
    }

    function entryDuration(e) {
      if (e.type === 'heading') return 0;
      const first = firstFilledTrack(e);
      if (first) return state.durations[first] || 0;
      return e.manual_duration || 0;
    }
    function totalDuration() {
      return state.entries.reduce((sum, e) => sum + entryDuration(e), 0);
    }

    function preloadDurations() {
      // Nur die im Programm verwendeten Tracks vorladen — nicht den gesamten
      // Pool (der kann über alle Konzerte hinweg groß sein; das erzeugte bei
      // jedem neuen Konzert dutzende stille Metadaten-Requests). Pool-Tracks
      // bekommen ihre Länge weiterhin beim ersten Abspielen.
      const used = new Set();
      state.entries.forEach(e => {
        const t = e.tracks || {};
        Object.values(t).forEach(fn => { if (fn) used.add(fn); });
      });
      const missing = [...used].filter(t => !(t in state.durations));
      if (!missing.length) return;
      let i = 0;
      const next = () => {
        if (i >= missing.length) {
          saveDurations();
          return;
        }
        const t = missing[i++];
        const a = new Audio();
        a.preload = 'metadata';
        a.src = trackUrl(t);
        const done = () => {
          if (a.duration && isFinite(a.duration)) {
            state.durations[t] = a.duration;
            updateTotal();
            updateInlineDurationForTrack(t, a.duration);
          }
          a.removeEventListener('loadedmetadata', done);
          a.removeEventListener('error', done);
          next();
        };
        a.addEventListener('loadedmetadata', done);
        a.addEventListener('error', done);
      };
      next();
    }

    function updatePlayingHighlight() {
      const cur = state.currentTrack;
      document.querySelectorAll('.entry').forEach(el => {
        const slots = el.querySelectorAll(':scope > .entry-body > .track-slot[data-track]');
        let playing = false;
        slots.forEach(s => { if (cur && s.dataset.track === cur) playing = true; });
        el.classList.toggle('is-playing', playing);
        if (playing) el.setAttribute('aria-current', 'true');
        else el.removeAttribute('aria-current');
      });
      document.querySelectorAll('.pool-item').forEach(el => {
        const playing = !!cur && el.dataset.track === cur;
        el.classList.toggle('is-playing', playing);
        if (playing) el.setAttribute('aria-current', 'true');
        else el.removeAttribute('aria-current');
      });
    }

    function render(opts) {
      const o = opts || {};
      // Standard: beide neu rendern. Optional gezielt nur einen Teil neu aufbauen.
      if (o.only !== 'pool')    renderEntries();
      if (o.only !== 'entries') renderPool();
      updateTotal();
      const on = isEditMode();
      document.querySelectorAll('.handle').forEach(el => { el.tabIndex = on ? 0 : -1; });
      document.querySelectorAll('.pool-item').forEach(el => {
        el.tabIndex = on ? 0 : -1;
        el.draggable = on;
      });
      document.querySelectorAll('.anchor-toggle').forEach(el => {
        el.tabIndex = on ? 0 : -1;
        if (on) el.removeAttribute('aria-hidden');
        else el.setAttribute('aria-hidden', 'true');
      });
      updatePlayingHighlight();
    }
    function updateTotal() {
      els.total.textContent = fmtTotal(totalDuration());
      // Nur echte Track-Einträge zählen — Abschnitts-Überschriften sind keine Tracks
      els.count.textContent = state.entries.filter(e => e.type !== 'heading').length;
    }

    // ---------- Song-Status ----------
    // Reihenfolge entspricht der Anzeige im Dropdown.
    const STATUS_OPTIONS = [
      { value: 0,   label: '— kein Status —' },
      { value: 10,  label: '10% Platzhalter' },
      { value: 20,  label: '20% Klangidee' },
      { value: 30,  label: '30% Rohversion' },
      { value: 40,  label: '40% Konzept fertig' },
      { value: 50,  label: '50% ungemastert' },
      { value: 80,  label: '80% gemastert' },
      { value: 100, label: '100% fix' },
    ];
    function statusLabel(v) {
      const opt = STATUS_OPTIONS.find(o => o.value === v);
      return opt ? opt.label : '';
    }

    // ---------- Anker-Gruppen ----------
    // Eine Gruppe ist die maximale Folge aufeinanderfolgender Einträge,
    // bei denen anchored_to_next === true den Übergang markiert.
    function groupRangeAt(idx) {
      let start = idx;
      while (start > 0 && state.entries[start - 1].anchored_to_next) start--;
      let end = idx;
      while (end < state.entries.length - 1 && state.entries[end].anchored_to_next) end++;
      return { start, end };
    }

    // Stellt sicher, dass:
    // - der letzte Eintrag nie anchored_to_next === true hat,
    // - kein Track einen Anker zu einer nachfolgenden Heading hat (semantisch sinnlos),
    // - keine Heading selbst anchored_to_next === true hat.
    function sanitizeAnchors() {
      const n = state.entries.length;
      if (n > 0) state.entries[n - 1].anchored_to_next = false;
      for (let i = 0; i < n; i++) {
        if (state.entries[i].type === 'heading') state.entries[i].anchored_to_next = false;
        if (i < n - 1 && state.entries[i + 1].type === 'heading') {
          state.entries[i].anchored_to_next = false;
        }
      }
    }

    function renderEntries() {
      els.entries.innerHTML = '';
      if (!state.entries.length) {
        const hint = document.createElement('div');
        hint.className = 'pool-empty';
        hint.textContent = isEditMode()
          ? 'Noch keine Einträge. Klick auf „+ Track".'
          : 'Noch keine Einträge. Aktiviere den Bearbeitungsmodus, um Einträge anzulegen.';
        els.entries.appendChild(hint);
        return;
      }
      let trackCounter = 0;
      state.entries.forEach((entry, idx) => {
        if (entry.type === 'heading') {
          els.entries.appendChild(renderHeading(entry, idx));
        } else {
          trackCounter++;
          els.entries.appendChild(renderEntry(entry, idx, trackCounter));
        }
        // Anker-Gap nur zwischen zwei Tracks rendern (nicht neben Überschriften).
        if (idx < state.entries.length - 1) {
          const next = state.entries[idx + 1];
          if (entry.type !== 'heading' && next.type !== 'heading') {
            els.entries.appendChild(renderAnchorGap(entry, idx));
          }
        }
      });
    }

    function renderAnchorGap(entry, idx) {
      const anchored = !!entry.anchored_to_next;
      const editMode = isEditMode();
      const gap = document.createElement('div');
      gap.className = 'anchor-gap';
      gap.dataset.after = entry.id;
      gap.dataset.anchored = anchored ? '1' : '0';

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'anchor-toggle';
      btn.setAttribute('aria-pressed', anchored ? 'true' : 'false');
      const label = anchored
        ? 'Verbindung zwischen Eintrag ' + (idx + 1) + ' und ' + (idx + 2) + ' lösen'
        : 'Eintrag ' + (idx + 1) + ' mit Eintrag ' + (idx + 2) + ' verbinden';
      btn.setAttribute('aria-label', label);
      btn.title = label;
      btn.tabIndex = editMode ? 0 : -1;
      // Im Lese-Modus ist der Button rein dekorativ — für Screenreader ausblenden,
      // sonst wird er weiterhin in der virtuellen Objektnavigation als aktive Schaltfläche angesagt.
      if (editMode) {
        btn.removeAttribute('aria-hidden');
      } else {
        btn.setAttribute('aria-hidden', 'true');
      }
      btn.innerHTML = icon(anchored ? 'i-anchor' : 'i-anchor-open');
      btn.addEventListener('click', () => {
        if (!isEditMode()) return;
        entry.anchored_to_next = !entry.anchored_to_next;
        sanitizeAnchors();
        render();
        flashStatus(entry.anchored_to_next ? 'Anker gesetzt' : 'Anker gelöst', 1200);
        save();
        // Fokus auf gleich gerenderten Button zurücksetzen, falls möglich.
        requestAnimationFrame(() => {
          const fresh = els.entries.querySelector('.anchor-gap[data-after="' + entry.id + '"] .anchor-toggle');
          if (fresh) fresh.focus();
        });
      });
      gap.appendChild(btn);
      return gap;
    }

    function buildTrackSlot(entry, slotCfg, trackIdx) {
      const slotKey = slotCfg.id;
      const slotLabel = slotCfg.name;
      const trackName = (entry.tracks || {})[slotKey] || null;
      const editMode = isEditMode();
      const slot = document.createElement('div');
      slot.className = 'track-slot' + (trackName ? '' : ' empty');
      slot.dataset.entry = entry.id;
      slot.dataset.slot = slotKey;

      const dot = document.createElement('span');
      dot.className = 'slot-dot';
      dot.dataset.color = slotCfg.color || 'blue';
      dot.setAttribute('aria-hidden', 'true');
      slot.appendChild(dot);

      const label = document.createElement('span');
      label.className = 'slot-label';
      label.textContent = slotLabel;
      slot.appendChild(label);

      if (trackName) {
        slot.dataset.track = trackName;
        const trackLabel = trackName.replace(/\.mp3$/i, '');

        const play = document.createElement('button');
        play.type = 'button';
        play.className = 'play-btn';
        play.innerHTML = icon('i-play');
        play.title = slotLabel + ' anspielen';
        play.setAttribute('aria-label', slotLabel + ' anspielen: ' + trackLabel);
        play.addEventListener('click', () => playTrack(trackName, entry.id));
        slot.appendChild(play);

        const name = document.createElement('span');
        name.className = 'track-name';
        name.textContent = trackLabel;
        slot.appendChild(name);

        const dur = document.createElement('span');
        dur.className = 'track-dur';
        dur.textContent = fmtDate(trackMtime(trackName));
        dur.title = 'Datei zuletzt geändert';
        slot.appendChild(dur);

        const trackHandle = document.createElement('span');
        trackHandle.className = 'handle';
        trackHandle.innerHTML = icon('i-handle');
        trackHandle.title = slotLabel + '-Track verschieben';
        trackHandle.setAttribute('aria-label', slotLabel + '-Track ' + trackLabel + ' verschieben');
        trackHandle.setAttribute('role', 'button');
        trackHandle.draggable = true;
        trackHandle.tabIndex = editMode ? 0 : -1;
        trackHandle.addEventListener('dragstart', (ev) => {
          if (!isEditMode()) { ev.preventDefault(); return; }
          ev.stopPropagation();
          ev.dataTransfer.effectAllowed = 'move';
          ev.dataTransfer.setData('application/x-konzert-track', trackName);
          ev.dataTransfer.setData('application/x-konzert-track-from', entry.id);
          ev.dataTransfer.setData('application/x-konzert-track-slot', slotKey);
        });
        slot.appendChild(trackHandle);

        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'ghost track-remove';
        rm.innerHTML = icon('i-close');
        rm.title = slotLabel + '-Track entfernen';
        rm.setAttribute('aria-label', slotLabel + '-Track ' + trackLabel + ' entfernen');
        rm.addEventListener('click', () => {
          if (entry.tracks) delete entry.tracks[slotKey];
          render();
          save();
        });
        slot.appendChild(rm);
      } else {
        const hint = document.createElement('span');
        hint.className = 'track-name';
        // Platzhaltertext liefert CSS (.track-slot.empty .track-name::after) —
        // einzige Quelle, damit es sich nicht mit JS-Text doppelt.
        hint.textContent = '';
        slot.appendChild(hint);
      }

      slot.addEventListener('dragover', (ev) => {
        if (!isEditMode()) return;
        if (ev.dataTransfer.types.includes('application/x-konzert-track')) {
          ev.preventDefault();
          ev.stopPropagation();
          slot.classList.add('track-drop');
        }
      });
      slot.addEventListener('dragleave', (ev) => {
        if (!slot.contains(ev.relatedTarget)) slot.classList.remove('track-drop');
      });
      slot.addEventListener('drop', (ev) => {
        if (!isEditMode()) return;
        if (!ev.dataTransfer.types.includes('application/x-konzert-track')) return;
        ev.preventDefault();
        ev.stopPropagation();
        slot.classList.remove('track-drop');
        const trackDrop = ev.dataTransfer.getData('application/x-konzert-track');
        const fromEntryId = ev.dataTransfer.getData('application/x-konzert-track-from');
        const fromSlot = ev.dataTransfer.getData('application/x-konzert-track-slot');
        if (trackDrop) assignTrack(trackDrop, entry.id, slotKey, fromEntryId || null, fromSlot || null);
      });

      return slot;
    }

    function renderEntry(entry, idx, trackNum) {
      const trackIdx = trackNum != null ? trackNum : (idx + 1);
      const el = document.createElement('div');
      el.className = 'entry';
      el.dataset.id = entry.id;
      el.draggable = false;
      el.setAttribute('role', 'listitem');

      const handle = document.createElement('div');
      handle.className = 'handle';
      handle.innerHTML = icon('i-handle', 'icon-lg');
      handle.title = 'Ziehen oder mit Tastatur verschieben (Pfeile)';
      handle.setAttribute('aria-label', 'Eintrag ' + trackIdx + ' verschieben');
      handle.setAttribute('role', 'button');
      handle.draggable = true;
      handle.addEventListener('dragstart', (ev) => {
        if (!isEditMode()) { ev.preventDefault(); return; }
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('application/x-konzert-entry', entry.id);
        el.classList.add('dragging');
      });
      handle.addEventListener('dragend', () => {
        el.classList.remove('dragging');
        document.querySelectorAll('.drop-above, .drop-below').forEach(e => {
          e.classList.remove('drop-above', 'drop-below');
        });
      });
      handle.addEventListener('keydown', (ev) => {
        if (!isEditMode()) return;
        if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') {
          ev.preventDefault();
          const dir = ev.key === 'ArrowUp' ? -1 : 1;
          const fromIdx = state.entries.findIndex(e => e.id === entry.id);
          if (fromIdx < 0) return;
          const srcRange = groupRangeAt(fromIdx);
          // Nachbar-Gruppe ermitteln (eine Position vor srcRange.start oder eine Position nach srcRange.end).
          if (dir < 0 && srcRange.start === 0) return;
          if (dir > 0 && srcRange.end === state.entries.length - 1) return;
          const neighborIdx = dir < 0 ? srcRange.start - 1 : srcRange.end + 1;
          const dstRange = groupRangeAt(neighborIdx);

          const dstFirstId = state.entries[dstRange.start].id;
          const dstLastId  = state.entries[dstRange.end].id;
          const srcLen = srcRange.end - srcRange.start + 1;
          const slice  = state.entries.splice(srcRange.start, srcLen);

          let insertIdx;
          if (dir < 0) {
            const i = state.entries.findIndex(e => e.id === dstFirstId);
            insertIdx = i >= 0 ? i : 0;
          } else {
            const i = state.entries.findIndex(e => e.id === dstLastId);
            insertIdx = i >= 0 ? i + 1 : state.entries.length;
          }
          state.entries.splice(insertIdx, 0, ...slice);

          // Anker-Konsistenz: Slice-Ränder und Listenende sichern.
          const lastInsertedIdx = insertIdx + slice.length - 1;
          if (lastInsertedIdx < state.entries.length - 1) {
            state.entries[lastInsertedIdx].anchored_to_next = false;
          }
          if (insertIdx > 0) {
            state.entries[insertIdx - 1].anchored_to_next = false;
          }
          sanitizeAnchors();

          render();
          save();
          requestAnimationFrame(() => {
            const moved = els.entries.querySelector('[data-id="' + entry.id + '"] .handle');
            if (moved) moved.focus();
          });
        }
      });
      el.appendChild(handle);

      const body = document.createElement('div');
      body.className = 'entry-body';

      const head = document.createElement('div');
      head.className = 'entry-head';
      const num = document.createElement('span');
      num.className = 'entry-index';
      num.textContent = trackIdx.toString().padStart(2, '0');
      num.setAttribute('aria-hidden', 'true');
      head.appendChild(num);

      const title = document.createElement('input');
      title.className = 'entry-title';
      title.type = 'text';
      title.value = entry.title || '';
      title.placeholder = 'Titel …';
      title.setAttribute('aria-label', 'Titel von Eintrag ' + trackIdx);
      if (!isEditMode()) title.setAttribute('readonly', 'readonly');
      title.addEventListener('input', () => { entry.title = title.value; save(); });
      title.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); title.blur(); }
      });
      head.appendChild(title);

      const headDur = document.createElement('span');
      headDur.className = 'entry-dur-inline';
      const initialDur = entryDuration(entry);
      headDur.textContent = initialDur ? fmt(initialDur) : '';
      headDur.setAttribute('aria-label', 'Spieldauer');
      head.appendChild(headDur);

      // Status-Dropdown (Edit-Modus) und Status-Label (Lese-Modus).
      // Beide werden immer ins DOM gerendert; das CSS regelt, welches sichtbar ist.
      const currentStatus = Number.isInteger(entry.status) ? entry.status : 0;
      const sel = document.createElement('select');
      sel.className = 'entry-status';
      sel.setAttribute('aria-label', 'Bearbeitungsstand des Songs für Eintrag ' + trackIdx);
      STATUS_OPTIONS.forEach(opt => {
        const o = document.createElement('option');
        o.value = String(opt.value);
        o.textContent = opt.label;
        if (opt.value === currentStatus) o.selected = true;
        sel.appendChild(o);
      });
      sel.dataset.value = String(currentStatus);
      sel.addEventListener('change', () => {
        const v = parseInt(sel.value, 10) || 0;
        entry.status = v;
        sel.dataset.value = String(v);
        lbl.dataset.value = String(v);
        lbl.textContent = v > 0 ? statusLabel(v) : '';
        save();
      });
      head.appendChild(sel);

      const lbl = document.createElement('span');
      lbl.className = 'entry-status-label';
      lbl.dataset.value = String(currentStatus);
      lbl.textContent = currentStatus > 0 ? statusLabel(currentStatus) : '';
      head.appendChild(lbl);

      body.appendChild(head);

      concertSlots().forEach(slotCfg => body.appendChild(buildTrackSlot(entry, slotCfg, trackIdx)));

      if (!entryHasAnyTrack(entry)) {
        const durWrap = document.createElement('label');
        durWrap.className = 'manual-dur';
        const durId = 'dur-' + entry.id;
        durWrap.setAttribute('for', durId);
        durWrap.appendChild(document.createTextNode('Dauer (m:ss): '));
        const durInput = document.createElement('input');
        durInput.id = durId;
        durInput.type = 'text';
        durInput.inputMode = 'numeric';
        durInput.placeholder = 'm:ss';
        durInput.value = entry.manual_duration ? fmt(entry.manual_duration) : '';
        durInput.title = 'Manuelle Spielzeit für Eintrag ' + trackIdx;
        durInput.setAttribute('aria-label', 'Manuelle Spielzeit für Eintrag ' + trackIdx);
        if (!isEditMode()) durInput.setAttribute('readonly', 'readonly');
        const commit = () => {
          const parsed = parseDuration(durInput.value);
          entry.manual_duration = parsed;
          durInput.value = parsed ? fmt(parsed) : '';
          headDur.textContent = parsed ? fmt(parsed) : '';
          updateTotal();
          save();
        };
        durInput.addEventListener('blur', commit);
        durInput.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') { ev.preventDefault(); durInput.blur(); }
        });
        durWrap.appendChild(durInput);
        body.appendChild(durWrap);
      }

      body.appendChild(renderNoteFiles(entry));
      el.appendChild(body);

      const actions = document.createElement('div');
      actions.className = 'entry-actions';

      const dup = document.createElement('button');
      dup.type = 'button';
      dup.className = 'ghost';
      dup.innerHTML = icon('i-copy');
      dup.title = 'Eintrag duplizieren';
      dup.setAttribute('aria-label', 'Eintrag ' + trackIdx + (entry.title ? ' („' + entry.title + '")' : '') + ' duplizieren');
      dup.addEventListener('click', () => duplicateEntry(entry.id));
      actions.appendChild(dup);

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'danger';
      del.innerHTML = icon('i-trash');
      del.title = 'Eintrag löschen';
      del.setAttribute('aria-label', 'Eintrag ' + trackIdx + (entry.title ? ' („' + entry.title + '")' : '') + ' löschen');
      del.addEventListener('click', async () => {
        if (!await kpConfirm('Diesen Eintrag wirklich löschen?')) return;
        const noteFiles = entry.note_files || (entry.note_file ? [entry.note_file] : []);
        state.entries = state.entries.filter(e => e.id !== entry.id);
        render();
        // Erst die Notendateien serverseitig löschen (der Owner-Check braucht den Eintrag
        // noch im Konzert-JSON), dann das Programm ohne den Eintrag speichern.
        Promise.allSettled(noteFiles.map(p => deleteNoteFile(p))).then(() => save());
      });
      actions.appendChild(del);
      el.appendChild(actions);

      el.addEventListener('dragover', (ev) => {
        if (!isEditMode()) return;
        const types = ev.dataTransfer.types;
        if (types.includes('application/x-konzert-entry')) {
          ev.preventDefault();
          const rect = el.getBoundingClientRect();
          const above = (ev.clientY - rect.top) < rect.height / 2;
          // Drop-Indikator auf Gruppengrenze umlenken: Hover-Eintrag in Gruppe → Marker am Gruppenrand zeigen.
          document.querySelectorAll('.entry.drop-above, .entry.drop-below').forEach(e => {
            e.classList.remove('drop-above', 'drop-below');
          });
          const myIdx = state.entries.findIndex(e => e.id === entry.id);
          if (myIdx < 0) return;
          const range = groupRangeAt(myIdx);
          const targetEntry = state.entries[above ? range.start : range.end];
          const targetEl = els.entries.querySelector('[data-id="' + targetEntry.id + '"]');
          if (targetEl) targetEl.classList.add(above ? 'drop-above' : 'drop-below');
        } else if (types.includes('application/x-konzert-track')) {
          // Sind beide Slots belegt, keinen Drop auf den Eintrags-Körper anbieten —
          // sonst würde der Original-Track stillschweigend überschrieben.
          // Gezieltes Ziehen auf einen der beiden Slots bleibt möglich.
          if (!allSlotsFilled(entry)) ev.preventDefault();
        }
      });
      el.addEventListener('dragleave', (ev) => {
        if (!el.contains(ev.relatedTarget)) {
          el.classList.remove('drop-above', 'drop-below');
        }
      });
      el.addEventListener('drop', (ev) => {
        if (!isEditMode()) return;
        ev.preventDefault();
        const entryId = ev.dataTransfer.getData('application/x-konzert-entry');
        const trackName = ev.dataTransfer.getData('application/x-konzert-track');
        const trackFrom = ev.dataTransfer.getData('application/x-konzert-track-from');
        const fromSlot = ev.dataTransfer.getData('application/x-konzert-track-slot');
        document.querySelectorAll('.entry.drop-above, .entry.drop-below').forEach(e => {
          e.classList.remove('drop-above', 'drop-below');
        });
        if (entryId && entryId !== entry.id) {
          const rect = el.getBoundingClientRect();
          const above = (ev.clientY - rect.top) < rect.height / 2;
          moveEntry(entryId, entry.id, above);
        } else if (trackName) {
          if (allSlotsFilled(entry)) {
            // Doppelte Absicherung zum dragover-Guard: nichts stillschweigend überschreiben
            flashStatus('Alle Slots belegt — Track direkt auf einen Slot ziehen', 2500);
            return;
          }
          const t = entryTracks(entry);
          const emptySlot = concertSlots().find(s => !t[s.id]);
          assignTrack(trackName, entry.id, emptySlot.id, trackFrom || null, fromSlot || null);
        }
      });

      return el;
    }

    function renderHeading(entry, idx) {
      const el = document.createElement('div');
      el.className = 'entry heading';
      el.dataset.id = entry.id;
      el.draggable = false;
      el.setAttribute('role', 'listitem');

      const handle = document.createElement('div');
      handle.className = 'handle';
      handle.innerHTML = icon('i-handle', 'icon-lg');
      handle.title = 'Überschrift verschieben';
      handle.setAttribute('aria-label', 'Überschrift verschieben');
      handle.setAttribute('role', 'button');
      handle.draggable = true;
      handle.tabIndex = isEditMode() ? 0 : -1;
      handle.addEventListener('dragstart', (ev) => {
        if (!isEditMode()) { ev.preventDefault(); return; }
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('application/x-konzert-entry', entry.id);
        el.classList.add('dragging');
      });
      handle.addEventListener('dragend', () => {
        el.classList.remove('dragging');
        document.querySelectorAll('.drop-above, .drop-below').forEach(e => {
          e.classList.remove('drop-above', 'drop-below');
        });
      });
      handle.addEventListener('keydown', (ev) => {
        if (!isEditMode()) return;
        if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') return;
        ev.preventDefault();
        const dir = ev.key === 'ArrowUp' ? -1 : 1;
        const fromIdx = state.entries.findIndex(e => e.id === entry.id);
        if (fromIdx < 0) return;
        const srcRange = groupRangeAt(fromIdx);
        if (dir < 0 && srcRange.start === 0) return;
        if (dir > 0 && srcRange.end === state.entries.length - 1) return;
        const neighborIdx = dir < 0 ? srcRange.start - 1 : srcRange.end + 1;
        const dstRange = groupRangeAt(neighborIdx);
        const dstFirstId = state.entries[dstRange.start].id;
        const dstLastId  = state.entries[dstRange.end].id;
        const srcLen = srcRange.end - srcRange.start + 1;
        const slice  = state.entries.splice(srcRange.start, srcLen);
        let insertIdx;
        if (dir < 0) {
          const i = state.entries.findIndex(e => e.id === dstFirstId);
          insertIdx = i >= 0 ? i : 0;
        } else {
          const i = state.entries.findIndex(e => e.id === dstLastId);
          insertIdx = i >= 0 ? i + 1 : state.entries.length;
        }
        state.entries.splice(insertIdx, 0, ...slice);
        const lastInsertedIdx = insertIdx + slice.length - 1;
        if (lastInsertedIdx < state.entries.length - 1) {
          state.entries[lastInsertedIdx].anchored_to_next = false;
        }
        if (insertIdx > 0) {
          state.entries[insertIdx - 1].anchored_to_next = false;
        }
        sanitizeAnchors();
        render();
        save();
        requestAnimationFrame(() => {
          const moved = els.entries.querySelector('[data-id="' + entry.id + '"] .handle');
          if (moved) moved.focus();
        });
      });
      el.appendChild(handle);

      const title = document.createElement('input');
      title.className = 'heading-title';
      title.type = 'text';
      title.value = entry.title || '';
      title.placeholder = 'Abschnitt (z. B. „1. Set", „Pause", „Zugaben") …';
      title.setAttribute('aria-label', 'Überschrift bearbeiten');
      if (!isEditMode()) title.setAttribute('readonly', 'readonly');
      title.addEventListener('input', () => { entry.title = title.value; save(); });
      title.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); title.blur(); }
      });
      el.appendChild(title);

      const actions = document.createElement('div');
      actions.className = 'entry-actions';

      const dup = document.createElement('button');
      dup.type = 'button';
      dup.className = 'ghost';
      dup.innerHTML = icon('i-copy');
      dup.title = 'Überschrift duplizieren';
      dup.setAttribute('aria-label', 'Überschrift' + (entry.title ? ' „' + entry.title + '"' : '') + ' duplizieren');
      dup.addEventListener('click', () => duplicateEntry(entry.id));
      actions.appendChild(dup);

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'danger';
      del.innerHTML = icon('i-trash');
      del.title = 'Überschrift löschen';
      del.setAttribute('aria-label', 'Überschrift' + (entry.title ? ' „' + entry.title + '"' : '') + ' löschen');
      del.addEventListener('click', async () => {
        if (!await kpConfirm('Diese Überschrift wirklich löschen?')) return;
        state.entries = state.entries.filter(e => e.id !== entry.id);
        render();
        save();
      });
      actions.appendChild(del);
      el.appendChild(actions);

      // Drop-Ziel: nur Eintrag-Drops (keine Tracks).
      el.addEventListener('dragover', (ev) => {
        if (!isEditMode()) return;
        const types = ev.dataTransfer.types;
        if (!types.includes('application/x-konzert-entry')) return; // Tracks landen hier nicht
        ev.preventDefault();
        const rect = el.getBoundingClientRect();
        const above = (ev.clientY - rect.top) < rect.height / 2;
        document.querySelectorAll('.entry.drop-above, .entry.drop-below').forEach(e => {
          e.classList.remove('drop-above', 'drop-below');
        });
        el.classList.add(above ? 'drop-above' : 'drop-below');
      });
      el.addEventListener('dragleave', (ev) => {
        if (!el.contains(ev.relatedTarget)) {
          el.classList.remove('drop-above', 'drop-below');
        }
      });
      el.addEventListener('drop', (ev) => {
        if (!isEditMode()) return;
        const entryId = ev.dataTransfer.getData('application/x-konzert-entry');
        if (!entryId) return;
        ev.preventDefault();
        document.querySelectorAll('.entry.drop-above, .entry.drop-below').forEach(e => {
          e.classList.remove('drop-above', 'drop-below');
        });
        if (entryId === entry.id) return;
        const rect = el.getBoundingClientRect();
        const above = (ev.clientY - rect.top) < rect.height / 2;
        moveEntry(entryId, entry.id, above);
      });

      return el;
    }

    // Gemeinsame Tab-Fokus-Falle für die Popup-Editoren (Notiz/BPM/Noten) —
    // gleiche Mechanik wie bei den übrigen Dialogen: Tab bleibt im Dialog.
    function trapFocus(modalEl) {
      if (!modalEl) return;
      modalEl.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Tab' || modalEl.hidden) return;
        const f = Array.from(modalEl.querySelectorAll(
          'button:not([hidden]):not(:disabled), [href], input:not([hidden]), textarea, select, [tabindex]:not([tabindex="-1"])'
        )).filter(el => el.offsetParent !== null || el === document.activeElement);
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
      });
    }

    // ---------- Notiz-Editor (Popup, wie ein Notizzettel) ----------
    let noteModalEntry = null;
    let noteModalOpener = null;
    function openNoteModal(entry) {
      noteModalEntry = entry;
      noteModalOpener = document.activeElement;
      const editable = CAN_EDIT_PROGRAM;
      els.noteModalText.value = entry.notes || '';
      els.noteModalText.readOnly = !editable;
      els.noteModalSave.hidden = !editable;
      els.noteModalTitle.textContent = (entry.title && entry.type !== 'heading')
        ? 'Notiz — ' + entry.title : 'Notiz';
      els.noteModal.hidden = false;
      setTimeout(() => { (editable ? els.noteModalText : els.noteModalCancel).focus(); }, 0);
    }
    function closeNoteModal() {
      els.noteModal.hidden = true;
      noteModalEntry = null;
      // Fokus zum auslösenden Element zurückgeben (wie beim BPM-Popup)
      if (noteModalOpener && noteModalOpener.isConnected) noteModalOpener.focus();
      noteModalOpener = null;
    }
    function saveNoteModal() {
      if (!noteModalEntry || !CAN_EDIT_PROGRAM) { closeNoteModal(); return; }
      noteModalEntry.notes = els.noteModalText.value;
      save();
      closeNoteModal();
      render();
    }
    if (els.noteModal) {
      trapFocus(els.noteModal);
      els.noteModalSave.addEventListener('click', saveNoteModal);
      els.noteModalCancel.addEventListener('click', closeNoteModal);
      els.noteModal.addEventListener('click', (ev) => { if (ev.target === els.noteModal) closeNoteModal(); });
      els.noteModalText.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') { ev.preventDefault(); saveNoteModal(); }
      });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && !els.noteModal.hidden) closeNoteModal();
      });
    }

    // ---------- BPM-Editor (Popup, einzelnes Zahlenfeld) ----------
    let bpmModalEntry = null;
    let bpmModalOpener = null;
    function openBpmModal(entry) {
      bpmModalEntry = entry;
      bpmModalOpener = document.activeElement;
      const editable = CAN_EDIT_PROGRAM;
      const v = parseInt(entry.bpm, 10) || 0;
      els.bpmModalInput.value = v ? String(v) : '';
      els.bpmModalInput.readOnly = !editable;
      els.bpmModalSave.hidden = !editable;
      els.bpmModalTitle.textContent = (entry.title && entry.type !== 'heading')
        ? 'BPM — ' + entry.title : 'BPM';
      els.bpmModal.hidden = false;
      setTimeout(() => { (editable ? els.bpmModalInput : els.bpmModalCancel).focus(); }, 0);
    }
    function closeBpmModal() {
      els.bpmModal.hidden = true;
      bpmModalEntry = null;
      // Fokus zum auslösenden Button zurückgeben (nur wenn er noch im DOM ist;
      // nach dem Speichern baut render() die Liste neu auf).
      if (bpmModalOpener && bpmModalOpener.isConnected) bpmModalOpener.focus();
      bpmModalOpener = null;
    }
    function saveBpmModal() {
      if (!bpmModalEntry || !CAN_EDIT_PROGRAM) { closeBpmModal(); return; }
      // Leeres/ungültiges Feld = keine Angabe (0). Sonst auf 1–400 begrenzen.
      let v = parseInt(els.bpmModalInput.value, 10);
      if (!Number.isFinite(v) || v < 1) v = 0;
      else if (v > 400) v = 400;
      bpmModalEntry.bpm = v;
      save();
      closeBpmModal();
      render();
    }
    if (els.bpmModal) {
      trapFocus(els.bpmModal);
      els.bpmModalSave.addEventListener('click', saveBpmModal);
      els.bpmModalCancel.addEventListener('click', closeBpmModal);
      els.bpmModal.addEventListener('click', (ev) => { if (ev.target === els.bpmModal) closeBpmModal(); });
      els.bpmModalInput.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); saveBpmModal(); }
      });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && !els.bpmModal.hidden) closeBpmModal();
      });
    }

    // ---------- Noten-Editor (Popup, abcjs) ----------
    // abcjs (504 KB) lazy nachladen: erst beim ersten Öffnen des Editors.
    let abcjsPromise = null;
    function ensureAbcjs() {
      if (window.ABCJS) return Promise.resolve();
      if (!abcjsPromise) {
        abcjsPromise = new Promise((resolve, reject) => {
          const s = document.createElement('script');
          s.src = ABCJS_URL;
          s.onload = resolve;
          s.onerror = () => { abcjsPromise = null; reject(new Error('abcjs')); };
          document.head.appendChild(s);
        });
      }
      return abcjsPromise;
    }
    const SHEET_TEMPLATE = 'X:1\nM:4/4\nL:1/4\nK:C\nC D E F | G A B c |\n';
    let sheetEntry = null;
    let sheetAudioCtx = null;
    let sheetPlayGain = null;
    function renderSheetPreview() {
      if (!window.ABCJS || !els.sheetPreview) return;
      try {
        const sc = 1.3;
        const w = Math.max(240, (els.sheetPreview.clientWidth - 24) / sc); // *sc bleibt in der Box
        ABCJS.renderAbc(els.sheetPreview, els.sheetAbc.value || '', { staffwidth: w, scale: sc, paddingtop: 8, paddingbottom: 12, paddingleft: 0, paddingright: 0 });
      } catch (e) { /* abcjs ist fehlertolerant */ }
    }
    function openSheetModal(entry) {
      ensureAbcjs().then(() => openSheetModalReady(entry))
        .catch(() => kpAlert('Der Noten-Editor konnte nicht geladen werden.'));
    }
    let sheetOpener = null;
    function openSheetModalReady(entry) {
      sheetEntry = entry;
      sheetOpener = document.activeElement;
      const editable = CAN_EDIT_PROGRAM;
      els.sheetAbc.value = (entry.abc && entry.abc.trim() !== '') ? entry.abc : (editable ? SHEET_TEMPLATE : '');
      els.sheetAbc.readOnly = !editable;
      els.sheetSave.hidden = !editable;
      els.sheetToolbar.style.display = editable ? '' : 'none';
      els.sheetTitle.textContent = (entry.title && entry.type !== 'heading') ? 'Noten — ' + entry.title : 'Noten';
      els.sheetModal.hidden = false;
      requestAnimationFrame(renderSheetPreview); // erst nach Layout rendern (korrekte Breite)
      setTimeout(() => { (editable ? els.sheetAbc : els.sheetCancel).focus(); }, 0);
    }
    function closeSheetModal() {
      stopMelody();
      els.sheetModal.hidden = true;
      sheetEntry = null;
      // Fokus zum auslösenden Element zurückgeben (wie beim BPM-Popup)
      if (sheetOpener && sheetOpener.isConnected) sheetOpener.focus();
      sheetOpener = null;
    }
    function saveSheetModal() {
      if (!sheetEntry || !CAN_EDIT_PROGRAM) { closeSheetModal(); return; }
      let v = els.sheetAbc.value.trim();
      if (v === SHEET_TEMPLATE.trim()) v = ''; // unverändertes Beispiel = keine Noten
      sheetEntry.abc = v;
      save();
      closeSheetModal();
      render();
    }
    function insertSheet(text) {
      const ta = els.sheetAbc;
      const s = ta.selectionStart, e = ta.selectionEnd;
      ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
      ta.selectionStart = ta.selectionEnd = s + text.length;
      ta.focus();
      renderSheetPreview();
    }
    // Einfacher, abhängigkeitsfreier Melodie-Player (Web-Audio-Oszillator).
    // Spielt die einstimmige ABC-Melodie ohne Soundfont/Netzzugriff — CSP-sicher
    // und offline. Versteht Noten A–G/a–g, Oktaven (, '), Vorzeichen (^ _ =),
    // Längen (2, /2) und Pausen (z).
    const ABC_SEMI = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 };
    function parseAbcMelody(abc) {
      const lines = String(abc).split('\n');
      let unit = 0.25; // Grundlänge L:, Standard Viertel
      let body = [];
      let afterKey = false;
      for (const ln of lines) {
        if (/^[A-Za-z]:/.test(ln)) {              // Kopfzeile (X: M: L: K: …)
          const lm = ln.match(/^L:\s*(\d+)\s*\/\s*(\d+)/); if (lm) unit = (+lm[1]) / (+lm[2]);
          if (/^K:/.test(ln)) afterKey = true;    // Notentext beginnt nach K:
          continue;
        }
        if (afterKey) body.push(ln);
      }
      if (!body.length) body = lines.filter(l => !/^[A-Za-z]:/.test(l));
      const text = body.join(' ');
      const re = /(\^\^|\^|__|_|=)?([A-Ga-gz])([,']*)(\d+)?(?:(\/)(\d+)?)?/g;
      const notes = []; let m;
      while ((m = re.exec(text)) !== null) {
        const acc = m[1], letter = m[2], octs = m[3] || '', numStr = m[4], slash = m[5], denStr = m[6];
        const mult = numStr ? +numStr : 1;
        const den = slash ? (denStr ? +denStr : 2) : 1;
        const durSec = (unit * mult / den) * 2.0; // Viertel ≈ 0,5 s (120 bpm)
        if (letter === 'z') { notes.push({ freq: 0, dur: durSec }); continue; }
        const upper = letter === letter.toUpperCase();
        let midi = (upper ? 60 : 72) + ABC_SEMI[letter.toUpperCase()]; // C4 bzw. C5
        for (const ch of octs) midi += (ch === "'") ? 12 : -12;
        if (acc === '^') midi += 1; else if (acc === '^^') midi += 2;
        else if (acc === '_') midi -= 1; else if (acc === '__') midi -= 2;
        notes.push({ freq: 440 * Math.pow(2, (midi - 69) / 12), dur: durSec });
      }
      return notes;
    }
    function stopMelody() {
      if (sheetPlayGain) { try { sheetPlayGain.disconnect(); } catch (e) {} sheetPlayGain = null; }
    }
    function playSheet() {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { kpAlert('Wiedergabe wird von diesem Browser nicht unterstützt.'); return; }
      const notes = parseAbcMelody(els.sheetAbc.value || '');
      if (!notes.length) { kpAlert('Keine abspielbaren Noten gefunden.'); return; }
      try {
        if (!sheetAudioCtx) sheetAudioCtx = new AC();
        if (sheetAudioCtx.state === 'suspended') sheetAudioCtx.resume();
        stopMelody();
        const master = sheetAudioCtx.createGain();
        master.gain.value = 0.3;
        master.connect(sheetAudioCtx.destination);
        sheetPlayGain = master;
        let t = sheetAudioCtx.currentTime + 0.06;
        for (const n of notes) {
          if (n.freq > 0) {
            const osc = sheetAudioCtx.createOscillator();
            const g = sheetAudioCtx.createGain();
            osc.type = 'triangle';
            osc.frequency.value = n.freq;
            const a = 0.012, rel = Math.min(0.09, n.dur * 0.35);
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(1, t + a);
            g.gain.setValueAtTime(1, Math.max(t + a, t + n.dur - rel));
            g.gain.exponentialRampToValueAtTime(0.0001, t + n.dur);
            osc.connect(g); g.connect(master);
            osc.start(t); osc.stop(t + n.dur + 0.03);
          }
          t += n.dur;
        }
      } catch (e) { kpAlert('Wiedergabe nicht möglich.'); }
    }
    if (els.sheetModal) {
      trapFocus(els.sheetModal);
      els.sheetAbc.addEventListener('input', renderSheetPreview);
      els.sheetToolbar.addEventListener('click', (ev) => {
        const b = ev.target.closest('button[data-ins]');
        if (b) insertSheet(b.getAttribute('data-ins'));
      });
      els.sheetPlay.addEventListener('click', playSheet);
      els.sheetSave.addEventListener('click', saveSheetModal);
      els.sheetCancel.addEventListener('click', closeSheetModal);
      els.sheetModal.addEventListener('click', (ev) => { if (ev.target === els.sheetModal) closeSheetModal(); });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && !els.sheetModal.hidden) closeSheetModal();
      });
    }

    function renderNoteFiles(entry) {
      const wrap = document.createElement('div');
      wrap.className = 'note-files';
      if (entry.note_file && !entry.note_files) {
        entry.note_files = [entry.note_file];
        delete entry.note_file;
      }
      // Notiz als anklickbares Chip (wie ein Anhang): immer sichtbar wenn vorhanden,
      // Klick öffnet den Notiz-Editor (bearbeitbar je nach Recht). Das × (entfernen)
      // blendet das CSS im Lesemodus aus — wie bei Dateien.
      if ((entry.notes || '').trim() !== '') {
        const nchip = document.createElement('span');
        nchip.className = 'note-file note-text-chip';
        const nlink = document.createElement('a');
        nlink.href = '#';
        nlink.textContent = 'Notiz';
        nlink.title = 'Notiz ansehen/bearbeiten';
        nlink.addEventListener('click', (ev) => { ev.preventDefault(); openNoteModal(entry); });
        nchip.appendChild(nlink);
        const nrm = document.createElement('button');
        nrm.type = 'button';
        nrm.innerHTML = icon('i-close');
        nrm.title = 'Notiz entfernen';
        nrm.setAttribute('aria-label', 'Notiz entfernen');
        nrm.addEventListener('click', async () => {
          if (!CAN_EDIT_PROGRAM) return;
          if (!await kpConfirm('Diese Notiz wirklich entfernen?')) return;
          entry.notes = '';
          save();
          render();
        });
        nchip.appendChild(nrm);
        wrap.appendChild(nchip);
      }
      // Noten (abcjs) als anklickbares Chip — Klick öffnet den Noten-Editor
      if ((entry.abc || '').trim() !== '') {
        const schip = document.createElement('span');
        schip.className = 'note-file note-sheet-chip';
        const slink = document.createElement('a');
        slink.href = '#';
        slink.textContent = 'Noten';
        slink.title = 'Noten ansehen/bearbeiten';
        slink.addEventListener('click', (ev) => { ev.preventDefault(); openSheetModal(entry); });
        schip.appendChild(slink);
        const srm = document.createElement('button');
        srm.type = 'button';
        srm.innerHTML = icon('i-close');
        srm.title = 'Noten entfernen';
        srm.setAttribute('aria-label', 'Noten entfernen');
        srm.addEventListener('click', async () => {
          if (!CAN_EDIT_PROGRAM) return;
          if (!await kpConfirm('Diese Noten wirklich entfernen?')) return;
          entry.abc = '';
          save();
          render();
        });
        schip.appendChild(srm);
        wrap.appendChild(schip);
      }
      const files = entry.note_files || [];
      files.forEach(path => {
        const chip = document.createElement('span');
        chip.className = 'note-file';
        const link = document.createElement('a');
        link.href = noteUrl(path);
        link.rel = 'noopener';
        const fileName = path.split('/').pop().replace(/^e_[a-z0-9]+_/, '');
        link.textContent = fileName;
        link.addEventListener('click', (ev) => {
          if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0) return;
          ev.preventDefault();
          openLightbox(noteUrl(path), fileName, link);
        });
        chip.appendChild(link);
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.innerHTML = icon('i-close');
        rm.title = 'Entfernen';
        rm.setAttribute('aria-label', fileName + ' entfernen');
        rm.addEventListener('click', async () => {
          if (!await kpConfirm('Diese Notendatei wirklich entfernen?\n\n' + fileName)) return;
          entry.note_files = (entry.note_files || []).filter(p => p !== path);
          deleteNoteFile(path);
          render();
          save();
        });
        chip.appendChild(rm);
        wrap.appendChild(chip);
      });
      // „Notizen“-Button nur, wenn es noch KEINE Notiz gibt (sonst genügt das Chip)
      if ((entry.notes || '').trim() === '') {
        const noteBtn = document.createElement('button');
        noteBtn.type = 'button';
        noteBtn.className = 'upload-btn note-add-btn';
        noteBtn.innerHTML = icon('i-edit') + ' Notizen';
        noteBtn.title = 'Notiz schreiben';
        noteBtn.addEventListener('click', () => openNoteModal(entry));
        wrap.appendChild(noteBtn);
      }

      const label = document.createElement('label');
      label.className = 'upload-btn';
      label.innerHTML = icon('i-plus') + ' PDF/Bild';
      const inp = document.createElement('input');
      inp.type = 'file';
      inp.accept = '.pdf,image/*';
      inp.style.display = 'none';
      inp.addEventListener('change', () => {
        if (!inp.files.length) return;
        uploadNote(entry, inp.files[0]);
        inp.value = '';
      });
      label.appendChild(inp);
      wrap.appendChild(label);

      // „Noteneditor“-Button nur, wenn es noch KEINE Noten gibt (sonst genügt das Chip)
      if ((entry.abc || '').trim() === '') {
        const sheetBtn = document.createElement('button');
        sheetBtn.type = 'button';
        sheetBtn.className = 'upload-btn note-sheet-btn';
        sheetBtn.innerHTML = icon('i-edit') + ' Noteneditor';
        sheetBtn.title = 'Noten schreiben';
        sheetBtn.addEventListener('click', () => openSheetModal(entry));
        wrap.appendChild(sheetBtn);
      }

      // BPM-Button — immer sichtbar. Ohne Wert „BPM“, mit Wert „120 BPM“.
      // Öffnet ein Popup mit einem einzelnen Zahlenfeld (leeres Feld = entfernen).
      const bpmVal = parseInt(entry.bpm, 10) || 0;
      const bpmBtn = document.createElement('button');
      bpmBtn.type = 'button';
      bpmBtn.className = 'upload-btn note-bpm-btn' + (bpmVal ? ' has-value' : '');
      bpmBtn.innerHTML = icon('i-edit') + ' ' + (bpmVal ? (bpmVal + ' BPM') : 'BPM');
      bpmBtn.title = bpmVal ? ('Tempo: ' + bpmVal + ' BPM — ändern') : 'Tempo (BPM) angeben';
      bpmBtn.addEventListener('click', () => openBpmModal(entry));
      wrap.appendChild(bpmBtn);

      return wrap;
    }

    function uploadNote(entry, file) {
      if (file.size > 10 * 1024 * 1024) { kpAlert('Datei zu groß (max 10 MB)'); return; }
      const fd = new FormData();
      fd.append('file', file);
      fd.append('entry_id', entry.id);
      flashStatus('Lade hoch…', 0);
      fetch('?action=upload_note' + API_K, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          if (d.ok) {
            entry.note_files = entry.note_files || [];
            entry.note_files.push(d.path);
            render();
            save();
          } else {
            kpAlert('Fehler: ' + (d.error || 'Upload fehlgeschlagen'));
            flashStatus('', 0);
          }
        })
        .catch(() => { kpAlert('Upload fehlgeschlagen'); flashStatus('', 0); });
    }

    function deleteNoteFile(path) {
      return fetch('?action=delete_note' + API_K, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path })
      });
    }

    function duplicateEntry(entryId, versuch = 0) {
      // Ausstehende (debouncte) Speicherung erst abschließen lassen: der Server
      // dupliziert aus SEINEM Stand und liefert die komplette Liste zurück —
      // eine noch nicht gesendete Änderung (z. B. gerade getippter Titel)
      // würde sonst überschrieben.
      if ((saveTimer || saveInFlight) && versuch < 10) {
        if (saveTimer) save(true);
        setTimeout(() => duplicateEntry(entryId, versuch + 1), 250);
        return;
      }
      flashStatus('Dupliziere…', 0);
      fetch('?action=entry_duplicate' + API_K, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entry_id: entryId })
      })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { kpAlert('Fehler: ' + (d.error || 'Duplizieren fehlgeschlagen')); flashStatus('', 0); return; }
        state.entries = d.entries || [];
        if (d.durations) state.durations = d.durations;
        if (typeof d.updated_at !== 'undefined') state.meta.updatedAt = d.updated_at;
        render();
        flashStatus('Kopie eingefügt', 1200);
      })
      .catch(() => flashStatus('Verbindungsfehler', 0));
    }

    function moveEntry(fromId, toId, above) {
      const fromIdx = state.entries.findIndex(e => e.id === fromId);
      const toIdx   = state.entries.findIndex(e => e.id === toId);
      if (fromIdx < 0 || toIdx < 0) return;

      const srcRange = groupRangeAt(fromIdx);
      const dstRange = groupRangeAt(toIdx);

      // Bewegung innerhalb derselben Gruppe ist ein No-Op (sonst würde die Gruppe zerlegt).
      if (srcRange.start === dstRange.start && srcRange.end === dstRange.end) return;

      // Anker-Punkte als IDs merken — Indizes sind nach dem Splice nicht mehr verlässlich.
      const dstFirstId = state.entries[dstRange.start].id;
      const dstLastId  = state.entries[dstRange.end].id;

      const srcLen = srcRange.end - srcRange.start + 1;
      const slice  = state.entries.splice(srcRange.start, srcLen);

      let insertIdx;
      if (above) {
        const i = state.entries.findIndex(e => e.id === dstFirstId);
        insertIdx = i >= 0 ? i : 0;
      } else {
        const i = state.entries.findIndex(e => e.id === dstLastId);
        insertIdx = i >= 0 ? i + 1 : state.entries.length;
      }
      state.entries.splice(insertIdx, 0, ...slice);

      // Anker-Konsistenz nach Splice:
      // - Letztes Element der eingefügten Gruppe darf nicht mit der dahinter folgenden Gruppe verschmelzen.
      const lastInsertedIdx = insertIdx + slice.length - 1;
      if (lastInsertedIdx < state.entries.length - 1) {
        state.entries[lastInsertedIdx].anchored_to_next = false;
      }
      // - Eintrag direkt VOR der eingefügten Gruppe darf seinen Anker zur eingefügten Gruppe nicht behalten.
      if (insertIdx > 0) {
        state.entries[insertIdx - 1].anchored_to_next = false;
      }
      sanitizeAnchors();

      render();
      save();
    }

    els.addBtn.addEventListener('click', () => {
      // Letzten bisherigen Eintrag entkoppeln, falls ein Anker zum (jetzt zu verschiebenden) Listenende stand.
      sanitizeAnchors();
      state.entries.push({ id: uid(), type: '', title: '', notes: '', abc: '', tracks: {}, note_files: [], manual_duration: 0, anchored_to_next: false, status: 0, bpm: 0 });
      render();
      save();
      // Fokus auf den Titel des neu angelegten Eintrags (immer der letzte .entry, nicht der Anker-Gap).
      const newEntry = els.entries.querySelector('.entry:last-of-type');
      if (newEntry) newEntry.querySelector('.entry-title')?.focus();
    });

    els.addHeadingBtn?.addEventListener('click', () => {
      sanitizeAnchors();
      state.entries.push({ id: uid(), type: 'heading', title: '', notes: '', abc: '', tracks: {}, note_files: [], manual_duration: 0, anchored_to_next: false, status: 0, bpm: 0 });
      render();
      save();
      const newHeading = els.entries.querySelector('.entry.heading:last-of-type');
      if (newHeading) newHeading.querySelector('.heading-title')?.focus();
    });

    // Stellt sicher, dass tracks ein echtes Objekt ist. Ein geleerter Slot kommt
    // vom Server als leeres JSON-Array [] zurück (PHP wandelt leeres {} beim
    // json_decode/encode in []); ein String-Key auf einem JS-Array würde von
    // JSON.stringify lautlos verworfen → der Track ginge beim Speichern verloren.
    function tracksObj(entry) {
      if (!entry.tracks || Array.isArray(entry.tracks)) entry.tracks = {};
      return entry.tracks;
    }
    function assignTrack(trackName, toEntryId, toSlot, fromEntryId, fromSlot) {
      const target = state.entries.find(e => e.id === toEntryId);
      if (!target) return;
      const targetTracks = tracksObj(target);
      if (fromEntryId && fromSlot) {
        const source = state.entries.find(e => e.id === fromEntryId);
        if (source) {
          const sourceTracks = tracksObj(source);
          const oldTargetTrack = targetTracks[toSlot] || null;
          targetTracks[toSlot] = trackName;
          if (oldTargetTrack) sourceTracks[fromSlot] = oldTargetTrack;  // Tausch
          else delete sourceTracks[fromSlot];                          // Verschiebung
          autofillTitle(target);
          autofillTitle(source);
          render();
          save();
          return;
        }
      }
      targetTracks[toSlot] = trackName;
      autofillTitle(target);
      render();
      save();
    }
    function autofillTitle(entry) {
      const ft = firstFilledTrack(entry);
      if (!entry.title && ft) entry.title = ft.replace(/\.mp3$/i, '');
    }

    function renderPool() {
      els.pool.innerHTML = '';
      const items = state.available.slice().sort((a, b) =>
        a.localeCompare(b, 'de', { sensitivity: 'base' })
      );
      if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'pool-empty';
        empty.textContent = 'Keine Tracks vorhanden.';
        els.pool.appendChild(empty);
        return;
      }
      const assigned = assignedTracks();
      items.forEach(name => {
        const labelText = name.replace(/\.mp3$/i, '');
        const inUse = assigned.has(name);
        const item = document.createElement('div');
        item.className = 'pool-item' + (inUse ? ' pool-in-use' : '');
        item.dataset.track = name;
        item.draggable = isEditMode();
        item.setAttribute('role', 'listitem');
        item.setAttribute('aria-label', labelText + (inUse ? ' (zugewiesen)' : ''));
        item.addEventListener('dragstart', (ev) => {
          if (!isEditMode()) { ev.preventDefault(); return; }
          ev.dataTransfer.effectAllowed = 'move';
          ev.dataTransfer.setData('application/x-konzert-track', name);
          item.classList.add('dragging');
        });
        item.addEventListener('dragend', () => {
          item.classList.remove('dragging');
          els.pool.classList.remove('drop-target');
        });

        const play = document.createElement('button');
        play.type = 'button';
        play.className = 'play-btn';
        play.innerHTML = icon('i-play');
        play.title = 'Anspielen';
        play.setAttribute('aria-label', 'Anspielen: ' + labelText);
        play.addEventListener('click', (ev) => { ev.stopPropagation(); playTrack(name, null); });
        item.appendChild(play);

        const titleWrap = document.createElement('span');
        titleWrap.className = 'track-title-wrap';
        const label = document.createElement('span');
        label.className = 'track-name';
        label.textContent = labelText;
        titleWrap.appendChild(label);
        const durInline = document.createElement('span');
        durInline.className = 'track-dur-inline';
        durInline.textContent = state.durations[name] ? fmt(state.durations[name]) : '';
        titleWrap.appendChild(durInline);
        item.appendChild(titleWrap);

        const dur = document.createElement('span');
        dur.className = 'track-dur';
        dur.textContent = fmtDate(trackMtime(name));
        dur.title = 'Datei zuletzt geändert';
        item.appendChild(dur);

        if (inUse) {
          const marker = document.createElement('span');
          marker.className = 'pool-assigned-marker';
          marker.title = 'In Verwendung';
          marker.setAttribute('aria-label', 'Zugewiesen');
          marker.innerHTML = icon('i-check');
          item.appendChild(marker);
        }

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'danger pool-delete';
        delBtn.innerHTML = icon('i-close');
        delBtn.title = 'Track löschen';
        delBtn.setAttribute('aria-label', 'Track „' + labelText + '” vom Server löschen');
        delBtn.addEventListener('click', async (ev) => {
          ev.stopPropagation();
          let msg = 'Track „' + labelText + '” unwiderruflich löschen?\n\nDie MP3-Datei wird vom Server entfernt.';
          if (inUse) msg += '\n\nDieser Track ist aktuell zugewiesen und wird aus den betroffenen Einträgen entfernt.';
          if (!await kpConfirm(msg)) return;
          fetch('?action=track_delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ file: name })
          })
          .then(r => r.json())
          .then(d => {
            if (!d.ok) { kpAlert('Fehler: ' + (d.error || 'Löschen fehlgeschlagen')); return; }
            state.available = state.available.filter(t => t !== name);
            delete state.durations[name];
            state.entries.forEach(e => {
              if (e.tracks) { Object.keys(e.tracks).forEach(k => { if (e.tracks[k] === name) delete e.tracks[k]; }); }
            });
            render();
            flashStatus('Track gelöscht', 1500);
            if (inUse) save();
          })
          .catch(() => kpAlert('Verbindungsfehler'));
        });
        item.appendChild(delBtn);

        els.pool.appendChild(item);
      });
    }

    els.pool.addEventListener('dragover', (ev) => {
      if (!isEditMode()) return;
      if (ev.dataTransfer.types.includes('application/x-konzert-track')) {
        ev.preventDefault();
        els.pool.classList.add('drop-target');
      }
    });
    els.pool.addEventListener('dragleave', (ev) => {
      if (!els.pool.contains(ev.relatedTarget)) {
        els.pool.classList.remove('drop-target');
      }
    });
    els.pool.addEventListener('drop', (ev) => {
      if (!isEditMode()) return;
      ev.preventDefault();
      els.pool.classList.remove('drop-target');
      const fromId = ev.dataTransfer.getData('application/x-konzert-track-from');
      const fromSlot = ev.dataTransfer.getData('application/x-konzert-track-slot');
      if (fromId && fromSlot) {
        const entry = state.entries.find(e => e.id === fromId);
        if (entry) {
          if (entry.tracks) delete entry.tracks[fromSlot];
          render();
          save();
        }
      }
    });

    // ---------- Konzert-Meta: Eingabe-Bindings ----------
    els.metaName.addEventListener('input', () => {
      state.meta.name = els.metaName.value;
      const displayName = state.meta.name || 'Konzert';
      document.title = displayName + ' — Konzertplaner';
      els.nameDisplay.textContent = displayName;
      updatePrintMeta();
      saveMeta();
    });
    els.metaDate.addEventListener('input', () => {
      state.meta.date = els.metaDate.value;
      updateDateDisplay();
      updatePrintMeta();
      saveMeta();
    });
    els.metaDesc.addEventListener('input', () => {
      state.meta.description = els.metaDesc.value;
      els.descDisplay.textContent = state.meta.description;
      updatePrintMeta();
      saveMeta();
    });
    function onMarkerLabelInput() {
      state.meta.markerLabels = {};
      [['yellow', els.mlYellow], ['green', els.mlGreen], ['purple', els.mlPurple],
       ['blue', els.mlBlue], ['red', els.mlRed]].forEach(([c, el]) => {
        const v = el.value.trim();
        if (v) state.meta.markerLabels[c] = v;
      });
      applyMarkerLabels();
      saveMeta();
    }
    els.mlYellow.addEventListener('input', onMarkerLabelInput);
    els.mlGreen.addEventListener('input', onMarkerLabelInput);
    els.mlPurple.addEventListener('input', onMarkerLabelInput);
    els.mlBlue.addEventListener('input', onMarkerLabelInput);
    els.mlRed.addEventListener('input', onMarkerLabelInput);

    // ---------- Konzert-Meta-Modal ----------
    let metaModalOpener = null;
    function openMetaModal(opener) {
      metaModalOpener = opener || null;
      els.metaModal.hidden = false;
      document.body.style.overflow = 'hidden';
      renderRehearsals();
      requestAnimationFrame(() => els.metaName.focus());
    }
    function closeMetaModal() {
      els.metaModal.hidden = true;
      document.body.style.overflow = '';
      if (metaModalOpener && document.contains(metaModalOpener)) metaModalOpener.focus();
      metaModalOpener = null;
    }
    els.metaEditBtn.addEventListener('click', () => openMetaModal(els.metaEditBtn));
    els.metaCancel.addEventListener('click', closeMetaModal);
    els.metaForm.addEventListener('submit', (ev) => { ev.preventDefault(); closeMetaModal(); });
    els.metaModal.addEventListener('click', (ev) => { if (ev.target === els.metaModal) closeMetaModal(); });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !els.metaModal.hidden) closeMetaModal();
    });
    // Focus-Trap im Meta-Modal
    els.metaModal.addEventListener('keydown', (ev) => {
      if (els.metaModal.hidden || ev.key !== 'Tab') return;
      const list = Array.from(els.metaModal.querySelectorAll(
        'input, textarea, button, [href], [tabindex]:not([tabindex="-1"])'
      )).filter(el => !el.disabled && el.offsetParent !== null);
      if (!list.length) return;
      const first = list[0], last = list[list.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    });

    // ---------- Probetermine ----------
    const SLOT_COLOR_KEYS = ['blue', 'green', 'amber', 'purple', 'red'];
    const SLOT_COLOR_NAMES = { blue: 'Blau', green: 'Grün', amber: 'Orange', purple: 'Lila', red: 'Rot' };
    function localSlotId() {
      let h;
      try {
        const a = new Uint8Array(4);
        crypto.getRandomValues(a);
        h = Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
      } catch (e) {
        h = (Math.random().toString(16).slice(2) + '00000000').slice(0, 8);
      }
      return 's_' + h;
    }
    function renderSlotsEditor() {
      if (!els.slotsEditor) return;
      els.slotsEditor.innerHTML = '';
      if (!Array.isArray(state.meta.slots) || !state.meta.slots.length) {
        state.meta.slots = [{ id: localSlotId(), name: 'Track', color: 'blue' }];
      }
      state.meta.slots.forEach((s, idx) => {
        const row = document.createElement('div');
        row.className = 'slot-row';
        row.setAttribute('role', 'listitem');

        const colors = document.createElement('div');
        colors.className = 'slot-colors';
        SLOT_COLOR_KEYS.forEach(ck => {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = 'slot-color-btn' + (s.color === ck ? ' is-active' : '');
          b.dataset.color = ck;
          const cname = SLOT_COLOR_NAMES[ck] || ck;
          b.title = 'Farbe ' + cname;
          b.setAttribute('aria-label', 'Farbe ' + cname + ' für Slot ' + (idx + 1));
          b.setAttribute('aria-pressed', s.color === ck ? 'true' : 'false');
          b.addEventListener('click', () => { s.color = ck; renderSlotsEditor(); render(); saveMeta(); });
          colors.appendChild(b);
        });
        row.appendChild(colors);

        const nameInp = document.createElement('input');
        nameInp.type = 'text';
        nameInp.className = 'slot-name';
        nameInp.value = s.name || '';
        nameInp.maxLength = 40;
        nameInp.placeholder = 'Slot-Name';
        nameInp.setAttribute('aria-label', 'Name von Slot ' + (idx + 1));
        nameInp.addEventListener('input', () => { s.name = nameInp.value; render(); rebuildAutoplayOptions(); saveMeta(); });
        row.appendChild(nameInp);

        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'danger slot-remove';
        rm.innerHTML = icon('i-trash');
        rm.title = 'Slot entfernen';
        rm.setAttribute('aria-label', 'Slot ' + (idx + 1) + ' entfernen');
        // Slot 1 & 2 sind geschützt (Grund-Slots, z. B. Original/Live) — nur ab Slot 3 löschbar
        rm.disabled = idx < 2;
        if (idx < 2) rm.title = 'Slot 1 und 2 können nicht gelöscht werden';
        rm.addEventListener('click', async () => {
          if (idx < 2) return;
          const ok = await kpConfirm('Slot „' + (s.name || 'Slot') + '" entfernen? Die in diesem Slot zugeordneten Tracks werden aus allen Einträgen gelöst (die Dateien bleiben im Musikarchiv).');
          if (!ok) return;
          const sid = s.id;
          state.meta.slots.splice(idx, 1);
          state.entries.forEach(e => { if (e.tracks) delete e.tracks[sid]; });
          renderSlotsEditor(); render(); rebuildAutoplayOptions(); saveMeta();
        });
        row.appendChild(rm);

        els.slotsEditor.appendChild(row);
      });
    }

    function renderRehearsals() {
      els.rehearsals.innerHTML = '';
      if (!state.meta.rehearsals.length) {
        const empty = document.createElement('div');
        empty.className = 'rehearsal-empty';
        empty.textContent = 'Noch keine Probetermine. Klick auf „Probetermin hinzufügen".';
        els.rehearsals.appendChild(empty);
        return;
      }
      state.meta.rehearsals.forEach((r, idx) => {
        const row = document.createElement('div');
        row.className = 'rehearsal-row';
        row.setAttribute('role', 'listitem');
        row.dataset.id = r.id;

        const dateInp = document.createElement('input');
        dateInp.type = 'datetime-local';
        dateInp.className = 'rehearsal-date';
        dateInp.value = r.date || '';
        dateInp.setAttribute('aria-label', 'Datum/Uhrzeit Probetermin ' + (idx + 1));
        if (!isEditMode()) dateInp.setAttribute('readonly', 'readonly');
        dateInp.addEventListener('input', () => {
          r.date = dateInp.value;
          saveMeta();
        });
        row.appendChild(dateInp);

        const noteInp = document.createElement('input');
        noteInp.type = 'text';
        noteInp.className = 'rehearsal-note';
        noteInp.value = r.note || '';
        noteInp.placeholder = 'Notiz (z. B. Komplettprobe, Bühne)';
        noteInp.setAttribute('aria-label', 'Notiz Probetermin ' + (idx + 1));
        noteInp.maxLength = 300;
        if (!isEditMode()) noteInp.setAttribute('readonly', 'readonly');
        noteInp.addEventListener('input', () => {
          r.note = noteInp.value;
          saveMeta();
        });
        row.appendChild(noteInp);

        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'danger rehearsal-remove';
        rm.innerHTML = icon('i-trash');
        rm.title = 'Probetermin entfernen';
        rm.setAttribute('aria-label', 'Probetermin ' + (idx + 1) + ' entfernen');
        rm.addEventListener('click', async () => {
          if (!await kpConfirm('Diesen Probetermin entfernen?')) return;
          state.meta.rehearsals = state.meta.rehearsals.filter(x => x.id !== r.id);
          renderRehearsals();
          saveMeta();
        });
        row.appendChild(rm);

        els.rehearsals.appendChild(row);
      });
    }
    els.addRehearsal.addEventListener('click', () => {
      state.meta.rehearsals.push({ id: pid(), date: '', note: '' });
      renderRehearsals();
      saveMeta();
      const last = els.rehearsals.lastElementChild;
      if (last) last.querySelector('.rehearsal-date')?.focus();
    });
    els.addSlot.addEventListener('click', () => {
      if (!Array.isArray(state.meta.slots)) state.meta.slots = [];
      if (state.meta.slots.length >= 5) { flashStatus('Maximal 5 Slots pro Konzert', 1800); return; }
      const used = state.meta.slots.map(s => s.color);
      const color = SLOT_COLOR_KEYS.find(c => !used.includes(c)) || 'blue';
      state.meta.slots.push({ id: localSlotId(), name: 'Slot ' + (state.meta.slots.length + 1), color });
      renderSlotsEditor(); render(); rebuildAutoplayOptions(); saveMeta();
    });

    // ---------- Auto-Play (automatische Weiterschaltung) ----------
    const AUTOPLAY_KEY = 'konzertplaner.autoplay_mode';
    let autoplayMode = 'off';
    try { autoplayMode = localStorage.getItem(AUTOPLAY_KEY) || 'off'; } catch (e) {}

    // Auto-Play-Dropdown aus den konfigurierten Slots bauen + Modus validieren.
    // Alte Werte 'original'/'live' werden auf den 1./2. Slot abgebildet.
    function rebuildAutoplayOptions() {
      if (!els.autoplayMode) return;
      const slots = concertSlots();
      if (autoplayMode === 'original')      autoplayMode = (slots[0] || {}).id || 'off';
      else if (autoplayMode === 'live')     autoplayMode = (slots[1] || slots[0] || {}).id || 'off';
      else if (autoplayMode !== 'off' && !slots.some(s => s.id === autoplayMode)) autoplayMode = 'off';
      try { localStorage.setItem(AUTOPLAY_KEY, autoplayMode); } catch (e) {}
      els.autoplayMode.innerHTML = '';
      const off = document.createElement('option'); off.value = 'off'; off.textContent = 'aus';
      els.autoplayMode.appendChild(off);
      slots.forEach(s => {
        const o = document.createElement('option'); o.value = s.id; o.textContent = s.name;
        els.autoplayMode.appendChild(o);
      });
      els.autoplayMode.value = autoplayMode;
    }
    rebuildAutoplayOptions();
    els.autoplayMode.addEventListener('change', () => {
      autoplayMode = els.autoplayMode.value;
      try { localStorage.setItem(AUTOPLAY_KEY, autoplayMode); } catch (e) {}
      const slot = concertSlots().find(s => s.id === autoplayMode);
      flashStatus(autoplayMode === 'off' ? 'Auto-Play aus' : 'Auto-Play an: ' + (slot ? slot.name : ''), 1800);
    });

    // Bevorzugten Track eines Eintrags wählen — fehlt der bevorzugte Slot, dient der andere als Fallback
    function preferredTrack(entry, slotId) {
      const t = entryTracks(entry);
      if (slotId && t[slotId]) return t[slotId];
      return firstFilledTrack(entry);   // Fallback: erster befüllter Slot
    }
    // Slot-Präferenz: eingestellte Auto-Play-Slot-ID; bei "aus" (manuelles Springen)
    // am gerade spielenden Track orientieren, sonst erster Slot.
    function slotPreference() {
      if (autoplayMode && autoplayMode !== 'off') return autoplayMode;
      const cur = state.entries.find(e => e.id === state.currentEntryId);
      if (cur) {
        const t = entryTracks(cur);
        for (const s of concertSlots()) { if (t[s.id] === state.currentTrack) return s.id; }
      }
      return (concertSlots()[0] || {}).id || null;
    }
    // Nächsten/vorherigen abspielbaren Eintrag im Programm finden
    // (Überschriften und Einträge ohne Track werden übersprungen)
    function findPlayable(fromEntryId, dir) {
      const idx = state.entries.findIndex(e => e.id === fromEntryId);
      if (idx < 0) return null;
      const pref = slotPreference();
      for (let i = idx + dir; i >= 0 && i < state.entries.length; i += dir) {
        const e = state.entries[i];
        if (e.type === 'heading') continue;
        const t = preferredTrack(e, pref);
        if (t) return { track: t, entryId: e.id };
      }
      return null;
    }
    function skipTo(dir) {
      if (!state.currentEntryId) return;
      const target = findPlayable(state.currentEntryId, dir);
      if (target) playTrack(target.track, target.entryId);
      else flashStatus(dir > 0 ? 'Programm-Ende erreicht' : 'Programm-Anfang erreicht', 2000);
    }
    els.playerPrev.addEventListener('click', () => skipTo(-1));
    els.playerNext.addEventListener('click', () => skipTo(1));

    // ---------- Audio-Player (Wavesurfer) ----------
    let wavesurfer = null;
    function setPlayBtn(playing) {
      const useEl = els.playerPlay.querySelector('use');
      if (useEl) useEl.setAttribute('href', playing ? '#i-pause' : '#i-play');
      const labelEl = els.playerPlay.querySelector('.label');
      if (labelEl) labelEl.textContent = playing ? 'Pause' : 'Play';
      els.playerPlay.setAttribute('aria-label', playing ? 'Pause' : 'Abspielen');
    }
    function updatePlayerTime() {
      if (!wavesurfer) return;
      const cur = wavesurfer.getCurrentTime() || 0;
      const dur = wavesurfer.getDuration() || 0;
      els.playerTime.textContent = fmt(cur) + ' / ' + fmt(dur);
    }
    function playTrack(name, entryId) {
      if (wavesurfer) {
        try { wavesurfer.destroy(); } catch (e) {}
        wavesurfer = null;
        els.waveform.innerHTML = '';
      }
      if (markerSaveTimer) { clearTimeout(markerSaveTimer); markerSaveTimer = null; }
      if (!els.markerModal.hidden) closeMarkerPopup(true);
      if (state.markerEditMode) setMarkerEditMode(false);
      els.markerOverlay.innerHTML = '';
      els.markerAhead.classList.remove('is-visible');
      els.markerToggle.disabled = !entryId;
      state.currentDuration = 0;

      els.nowPlaying.textContent = name.replace(/\.mp3$/i, '');
      els.player.classList.add('active');
      els.playerTime.textContent = '0:00 / 0:00';
      setPlayBtn(true);
      state.currentTrack = name;
      state.currentEntryId = entryId || null;
      // Vor/Zurück nur für Programm-Einträge (Pool-Tracks haben keine Position im Programm)
      els.playerPrev.disabled = !state.currentEntryId;
      els.playerNext.disabled = !state.currentEntryId;
      updatePlayingHighlight();
      if (entryId && !(entryId in state.markersByEntry)) {
        fetch('?action=markers_get&entry=' + encodeURIComponent(entryId) + API_K)
          .then(r => r.json())
          .then(d => {
            if (d.ok && Array.isArray(d.markers)) {
              state.markersByEntry[entryId] = d.markers;
              if (state.currentEntryId === entryId) renderMarkers();
            }
          })
          .catch(() => {});
      }
      // Den jetzt markierten Eintrag/Pool-Item ins Bild bringen
      const playingEl = document.querySelector('.entry.is-playing, .pool-item.is-playing');
      if (playingEl && typeof playingEl.scrollIntoView === 'function') {
        playingEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
      wavesurfer = WaveSurfer.create({
        container: els.waveform,
        waveColor: getComputedStyle(document.documentElement).getPropertyValue('--wave-bg').trim() || '#5b8def',
        progressColor: getComputedStyle(document.documentElement).getPropertyValue('--wave-fg').trim() || '#34406b',
        cursorColor: '#1d4ed8',
        barWidth: 2, barGap: 2, barRadius: 1, height: 44,
        backend: 'MediaElement', url: trackUrl(name),
      });
      wavesurfer.on('ready', () => {
        updatePlayerTime();
        const d = wavesurfer.getDuration();
        if (d && !state.durations[name]) {
          state.durations[name] = d;
          updateTotal();
          updateInlineDurationForTrack(name, d);
          saveDurations();
        }
        state.currentDuration = d || 0;
        // Marker gibt es nur für Programm-Einträge — bei Pool-Tracks bleibt der Toggle gesperrt
        els.markerToggle.disabled = !state.currentEntryId;
        renderMarkers();
        wavesurfer.play().catch(() => {});
      });
      wavesurfer.on('audioprocess', (t) => {
        updatePlayerTime();
        if (typeof t === 'number') checkMarkerAhead(t);
      });
      wavesurfer.on('seeking', (t) => {
        updatePlayerTime();
        // Beim Seek "vorne" das Toast-Tracking zurücksetzen
        if (typeof t === 'number' && t + 0.2 < lastAheadCheckAt) shownAheadIds.clear();
      });
      // Ladefehler (403/404/Netz) nicht mehr verschlucken: vorher blieb der
      // Player still mit Pause-Icon stehen — „Play tut nichts". Jetzt gibt es
      // eine klare Meldung, und bei Auto-Play wird der nächste Track versucht.
      wavesurfer.on('error', () => {
        setPlayBtn(false);
        els.playerTime.textContent = '0:00 / 0:00';
        flashStatus('Track „' + name.replace(/\.mp3$/i, '') + '" konnte nicht geladen werden', 0);
        if (autoplayMode !== 'off' && state.currentEntryId) {
          const next = findPlayable(state.currentEntryId, 1);
          if (next) setTimeout(() => playTrack(next.track, next.entryId), 0);
        }
      });
      wavesurfer.on('play',  () => setPlayBtn(true));
      wavesurfer.on('pause', () => setPlayBtn(false));
      wavesurfer.on('finish', () => {
        setPlayBtn(false);
        // Auto-Weiterschaltung: nur bei aktivem Modus und nur für Programm-Einträge
        if (autoplayMode === 'off' || !state.currentEntryId) return;
        const next = findPlayable(state.currentEntryId, 1);
        if (next) {
          // setTimeout entkoppelt das destroy() der alten Wavesurfer-Instanz vom
          // eigenen finish-Callback; flashStatus sagt den Wechsel für Screenreader an.
          setTimeout(() => {
            playTrack(next.track, next.entryId);
            flashStatus('Auto-Play: ' + next.track.replace(/\.mp3$/i, ''), 2000);
          }, 0);
        } else {
          flashStatus('Programm-Ende erreicht', 2000);
        }
      });
    }
    els.playerPlay.addEventListener('click', () => {
      if (!wavesurfer) return;
      if (wavesurfer.isPlaying()) wavesurfer.pause(); else wavesurfer.play();
    });
    els.playerStop.addEventListener('click', () => {
      if (!wavesurfer) return;
      wavesurfer.stop();
      setPlayBtn(false);
      updatePlayerTime();
    });
    els.closePlayer.addEventListener('click', () => {
      // Offenes Marker-Popup VOR currentTrack=null verwerfen, damit getMarkersForCurrent
      // den richtigen Track noch findet (sonst bleibt der leere Marker im State liegen).
      if (!els.markerModal.hidden) closeMarkerPopup(true);
      if (markerSaveTimer) { clearTimeout(markerSaveTimer); markerSaveTimer = null; }
      if (wavesurfer) { try { wavesurfer.destroy(); } catch (e) {} wavesurfer = null; }
      els.waveform.innerHTML = '';
      els.player.classList.remove('active');
      els.nowPlaying.textContent = '';
      els.playerTime.textContent = '0:00 / 0:00';
      state.currentTrack = null;
      state.currentEntryId = null;
      state.currentDuration = 0;
      updatePlayingHighlight();
      // Marker-Edit-Modus beenden, Overlay leeren
      if (state.markerEditMode) setMarkerEditMode(false);
      els.markerOverlay.innerHTML = '';
      els.markerAhead.classList.remove('is-visible');
      els.markerToggle.disabled = true;
      els.playerPrev.disabled = true;
      els.playerNext.disabled = true;
    });

    // ---------- Track-Marker ----------

    function markerId() { return 'm_' + randomHex10(); }

    // Zuletzt gewählte Farbe — beim Neuen Anlegen Default. Wird beim Picken aktualisiert.
    let lastChosenMarkerColor = 'yellow';
    const MARKER_COLORS = ['yellow', 'red', 'green', 'blue', 'purple'];

    function getMarkersForCurrent() {
      const eid = state.currentEntryId;
      if (!eid) return [];
      if (!state.markersByEntry[eid]) state.markersByEntry[eid] = [];
      return state.markersByEntry[eid];
    }

    function setMarkerEditMode(on) {
      state.markerEditMode = !!on;
      document.body.classList.toggle('marker-edit', state.markerEditMode);
      els.markerToggle.setAttribute('aria-pressed', state.markerEditMode ? 'true' : 'false');
      // Im Edit-Modus Marker für Screenreader/Tastatur sichtbar machen
      els.markerOverlay.setAttribute('aria-hidden', state.markerEditMode ? 'false' : 'true');
      // Wavesurfer-Seek im Edit-Modus deaktivieren, damit Klicks nicht springen
      if (wavesurfer && typeof wavesurfer.setOptions === 'function') {
        try { wavesurfer.setOptions({ interact: !state.markerEditMode }); } catch (e) {}
      }
      flashStatus(state.markerEditMode ? 'Marker-Bearbeitung aktiv' : 'Marker-Bearbeitung beendet', 1500);
    }

    function renderMarkers() {
      els.markerOverlay.innerHTML = '';
      shownAheadIds.clear();
      lastAheadCheckAt = -1;
      if (!state.currentTrack || !state.currentDuration) return;
      const markers = getMarkersForCurrent();
      markers.forEach(m => {
        const el = document.createElement('div');
        el.className = 'wave-marker';
        el.dataset.id = m.id;
        el.dataset.color = (m.color && ['yellow','red','green','blue','purple'].includes(m.color)) ? m.color : 'yellow';
        const pct = Math.max(0, Math.min(100, (m.t / state.currentDuration) * 100));
        el.style.left = pct + '%';

        const tooltip = document.createElement('span');
        tooltip.className = 'wm-tooltip';
        tooltip.textContent = m.text || '';
        el.appendChild(tooltip);

        const handle = document.createElement('button');
        handle.type = 'button';
        handle.className = 'wm-handle';
        handle.title = 'Marker bearbeiten';
        handle.setAttribute('aria-label', 'Marker bei ' + fmt(m.t) + ' bearbeiten: ' + (m.text || ''));
        handle.innerHTML = '<svg aria-hidden="true"><use href="#i-edit"/></svg>';
        handle.addEventListener('click', (ev) => {
          ev.stopPropagation();
          openMarkerPopup(m.id);
        });
        // Tastatur-Bedienung: Pfeil-Links/Rechts verschiebt um 0.5s (Shift: 2s)
        handle.addEventListener('keydown', (ev) => {
          if (!state.markerEditMode) return;
          if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
          ev.preventDefault();
          const step = (ev.shiftKey ? 2 : 0.5) * (ev.key === 'ArrowLeft' ? -1 : 1);
          const newT = Math.max(0, Math.min(state.currentDuration || m.t, m.t + step));
          m.t = newT;
          const pct = state.currentDuration ? (newT / state.currentDuration) * 100 : 0;
          el.style.left = pct + '%';
          const list = getMarkersForCurrent();
          list.sort((a, b) => a.t - b.t);
          flashStatus('Marker: ' + fmt(newT), 800);
          saveMarkersDebounced();
        });
        el.appendChild(handle);

        // Drag im Edit-Modus
        attachMarkerDrag(el, m);

        // Marker-Klicks dürfen nie zum Overlay durchbubblen (sonst → neuer Marker im Edit-Modus)
        el.addEventListener('click', (ev) => {
          ev.stopPropagation();
          if (state.markerEditMode) return;
          el.classList.add('is-touch-show');
          setTimeout(() => el.classList.remove('is-touch-show'), 3000);
        });

        els.markerOverlay.appendChild(el);
      });
    }

    function attachMarkerDrag(el, marker) {
      let dragging = false;
      let downX = 0;
      let startPct = 0;
      let moved = false;

      el.addEventListener('pointerdown', (ev) => {
        if (!state.markerEditMode) return;
        if (ev.target.closest('.wm-handle')) return; // Handle-Click läuft separat
        ev.preventDefault();
        ev.stopPropagation();
        dragging = true;
        moved = false;
        downX = ev.clientX;
        startPct = parseFloat(el.style.left) || 0;
        el.setPointerCapture(ev.pointerId);
      });
      el.addEventListener('pointermove', (ev) => {
        if (!dragging) return;
        const rect = els.markerOverlay.getBoundingClientRect();
        if (rect.width <= 0) return;
        const dx = ev.clientX - downX;
        if (Math.abs(dx) > 3) moved = true;
        let newPct = startPct + (dx / rect.width) * 100;
        newPct = Math.max(0, Math.min(100, newPct));
        el.style.left = newPct + '%';
      });
      el.addEventListener('pointerup', (ev) => {
        if (!dragging) return;
        dragging = false;
        try { el.releasePointerCapture(ev.pointerId); } catch (e) {}
        if (!moved) {
          // Reiner Klick auf der Linie im Edit-Modus → Popup zum Editieren
          openMarkerPopup(marker.id);
          return;
        }
        const pct = parseFloat(el.style.left) || 0;
        const newT = (pct / 100) * (state.currentDuration || 0);
        marker.t = Math.max(0, newT);
        // Liste neu sortieren und (debounced) speichern
        const list = getMarkersForCurrent();
        list.sort((a, b) => a.t - b.t);
        saveMarkersDebounced();
      });
    }

    // Klick auf das Overlay im Edit-Modus → neuen Marker.
    // (Im Normal-Modus ist #marker-overlay pointer-events:none, Klicks gehen an die Wave.)
    els.markerOverlay.addEventListener('click', (ev) => {
      if (!state.markerEditMode) return;
      if (ev.target.closest('.wave-marker')) return;
      if (!state.currentTrack || !state.currentDuration) return;
      const rect = els.markerOverlay.getBoundingClientRect();
      if (rect.width <= 0) return;
      const x = Math.max(0, Math.min(rect.width, ev.clientX - rect.left));
      const t = (x / rect.width) * state.currentDuration;
      const m = { id: markerId(), t: t, text: '', color: lastChosenMarkerColor };
      getMarkersForCurrent().push(m);
      renderMarkers();
      openMarkerPopup(m.id, true);
    });

    // ---------- Marker-Popup ----------

    let markerPopupOpener = null;
    function setActiveColorBtn(color) {
      els.markerColorRow?.querySelectorAll('.marker-color-btn').forEach(btn => {
        const active = btn.dataset.color === color;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
    function currentPickedColor() {
      const active = els.markerColorRow?.querySelector('.marker-color-btn.is-active');
      return (active && MARKER_COLORS.includes(active.dataset.color)) ? active.dataset.color : 'yellow';
    }
    function openMarkerPopup(id, isNew) {
      const list = getMarkersForCurrent();
      const m = list.find(x => x.id === id);
      if (!m) return;
      state.currentMarkerEditing = id;
      els.markerModalTitle.textContent = isNew ? 'Marker anlegen' : 'Marker bearbeiten';
      els.markerTimeDisplay.textContent = fmt(m.t);
      els.markerText.value = m.text || '';
      const initialColor = MARKER_COLORS.includes(m.color) ? m.color : 'yellow';
      setActiveColorBtn(initialColor);
      els.markerDelete.hidden = !!isNew;
      els.markerModal.hidden = false;
      document.body.style.overflow = 'hidden';
      markerPopupOpener = document.activeElement;
      requestAnimationFrame(() => els.markerText.focus());
    }

    // Farb-Buttons: Klick aktiviert die Farbe (visuell + State). Live-Vorschau am Marker.
    els.markerColorRow?.addEventListener('click', (ev) => {
      const btn = ev.target.closest('.marker-color-btn');
      if (!btn) return;
      ev.preventDefault();
      const c = btn.dataset.color;
      if (!MARKER_COLORS.includes(c)) return;
      setActiveColorBtn(c);
      lastChosenMarkerColor = c;
      // Sofortige Farb-Vorschau am offenen Marker (ohne Save-Submit)
      const id = state.currentMarkerEditing;
      if (id) {
        const el = els.markerOverlay.querySelector('.wave-marker[data-id="' + id + '"]');
        if (el) el.dataset.color = c;
      }
    });
    function closeMarkerPopup(discardIfNew) {
      // Wenn ein neuer Marker ohne Text abgebrochen wird, wieder entfernen
      if (discardIfNew && state.currentMarkerEditing) {
        const list = getMarkersForCurrent();
        const idx = list.findIndex(x => x.id === state.currentMarkerEditing);
        if (idx !== -1 && !list[idx].text) {
          list.splice(idx, 1);
          renderMarkers();
        }
      }
      state.currentMarkerEditing = null;
      els.markerModal.hidden = true;
      document.body.style.overflow = '';
      if (markerPopupOpener && document.contains(markerPopupOpener)) markerPopupOpener.focus();
      markerPopupOpener = null;
    }

    els.markerCancel.addEventListener('click', () => closeMarkerPopup(true));
    els.markerModal.addEventListener('click', (ev) => {
      if (ev.target === els.markerModal) closeMarkerPopup(true);
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !els.markerModal.hidden) closeMarkerPopup(true);
    });
    // Focus-Trap Marker-Modal
    els.markerModal.addEventListener('keydown', (ev) => {
      if (els.markerModal.hidden || ev.key !== 'Tab') return;
      const list = Array.from(els.markerModal.querySelectorAll(
        'input, textarea, button, [href]'
      )).filter(el => !el.disabled && !el.hidden && el.offsetParent !== null);
      if (!list.length) return;
      const first = list[0], last = list[list.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    });

    els.markerForm.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const id = state.currentMarkerEditing;
      if (!id) return;
      const list = getMarkersForCurrent();
      const m = list.find(x => x.id === id);
      if (!m) return;
      m.text = els.markerText.value.trim().slice(0, 60);
      if (!m.text) return; // Required
      m.color = currentPickedColor();
      lastChosenMarkerColor = m.color;
      list.sort((a, b) => a.t - b.t);
      renderMarkers();
      saveMarkers(true);
      closeMarkerPopup(false);
    });

    els.markerDelete.addEventListener('click', () => {
      const id = state.currentMarkerEditing;
      if (!id) return;
      const list = getMarkersForCurrent();
      const idx = list.findIndex(x => x.id === id);
      if (idx === -1) return;
      list.splice(idx, 1);
      renderMarkers();
      saveMarkers(true);
      closeMarkerPopup(false);
    });

    // ---------- Marker-Persistenz ----------

    let markerSaveTimer = null;
    function saveMarkersDebounced() {
      clearTimeout(markerSaveTimer);
      markerSaveTimer = setTimeout(() => saveMarkers(true), 400);
    }
    function saveMarkers(immediate) {
      const eid = state.currentEntryId;
      if (!eid) return;
      const markers = getMarkersForCurrent();
      const doSave = () => {
        // API_K trägt Konzert-ID UND Freigabe-Token — ohne das Token lehnt der
        // Server das Speichern in der Freigabe-Ansicht ab (401), obwohl die
        // Freigabe Marker-Rechte hat.
        fetch('?action=markers_save' + API_K, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ entry: eid, markers })
        }).then(r => r.json()).then(d => {
          flashStatus(d.ok ? 'Marker gespeichert' : 'Marker-Fehler', d.ok ? 1200 : 0);
        }).catch(() => flashStatus('Marker-Fehler', 0));
      };
      if (immediate) { clearTimeout(markerSaveTimer); doSave(); }
      else { clearTimeout(markerSaveTimer); markerSaveTimer = setTimeout(doSave, 400); }
    }

    // ---------- Marker-Edit-Toggle Button ----------

    els.markerToggle.addEventListener('click', () => {
      if (els.markerToggle.disabled) return;
      setMarkerEditMode(!state.markerEditMode);
    });

    // ---------- 10-Sekunden-Ahead-Toast ----------

    const shownAheadIds = new Set();
    let lastAheadCheckAt = -1;
    let aheadHideTimer = null;
    function checkMarkerAhead(curT) {
      if (state.markerEditMode) return;
      // Rückwärts gesprungen → set zurücksetzen
      if (curT + 0.2 < lastAheadCheckAt) shownAheadIds.clear();
      lastAheadCheckAt = curT;

      const list = getMarkersForCurrent();
      // Aktiver Marker = das nächste, noch nicht gezeigte, innerhalb 10s, nicht passiert
      let active = null;
      for (const m of list) {
        const delta = m.t - curT;
        if (delta >= -0.5 && delta <= 10 && !shownAheadIds.has(m.id)) {
          active = m;
          break;
        }
      }
      if (!active) {
        if (!els.markerAhead.classList.contains('is-visible-locked')) {
          els.markerAhead.classList.remove('is-visible');
        }
        return;
      }
      const remaining = Math.max(0, active.t - curT);
      const remStr = remaining < 0.5 ? 'jetzt' : Math.ceil(remaining) + 's';
      const colorMap = {yellow:'#f59e0b',red:'#ef4444',green:'#10b981',blue:'#0ea5e9',purple:'#8b5cf6'};
      els.markerAhead.style.background = colorMap[active.color] || 'var(--accent)';
      els.markerAhead.innerHTML =
        '<span class="marker-ahead-count">' + remStr + '</span>' + escapeHtmlInline(active.text);
      els.markerAhead.classList.add('is-visible');
      // Wenn vorbei: ID merken, kurz danach Toast ausblenden
      if (remaining <= 0) {
        shownAheadIds.add(active.id);
        clearTimeout(aheadHideTimer);
        aheadHideTimer = setTimeout(() => {
          els.markerAhead.classList.remove('is-visible');
        }, 1500);
      }
    }
    function escapeHtmlInline(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Reposition Marker bei Resize
    if (typeof ResizeObserver !== 'undefined') {
      const ro = new ResizeObserver(() => { /* % bleiben automatisch */ });
      ro.observe(els.waveformWrap);
    }

    // ---------- Lightbox ----------
    let lightboxOpener = null;
    function openLightbox(path, fileName, opener) {
      const ext = (path.split('.').pop() || '').toLowerCase();
      const c = els.lightboxContent;
      c.innerHTML = '';
      if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
        const img = document.createElement('img');
        img.src = path;
        img.alt = fileName || 'Notenblatt';
        c.appendChild(img);
      } else if (ext === 'pdf') {
        const frame = document.createElement('iframe');
        frame.src = path;
        frame.title = 'Noten-Vorschau: ' + (fileName || '');
        // Kein sandbox="" — das verhindert in Chrome die PDF-Anzeige ("Seite blockiert").
        // Die Datei ist same-origin, wird beim Upload auf echten PDF-Typ geprüft und
        // liegt in einem Ordner ohne Skript-Ausführung (.htaccess). referrerPolicy zur Sicherheit.
        frame.referrerPolicy = 'no-referrer';
        frame.tabIndex = -1;
        c.appendChild(frame);
      } else {
        const fallback = document.createElement('div');
        fallback.style.padding = '40px';
        fallback.textContent = 'Vorschau nicht möglich. Nutze den „In neuem Tab öffnen"-Button.';
        c.appendChild(fallback);
      }
      els.lightboxExt.href = path;
      els.lightbox.hidden = false;
      document.body.style.overflow = 'hidden';
      lightboxOpener = opener || null;
      requestAnimationFrame(() => els.lightboxClose.focus());
    }
    function closeLightbox() {
      els.lightbox.hidden = true;
      els.lightboxContent.innerHTML = '';
      document.body.style.overflow = '';
      if (lightboxOpener && document.contains(lightboxOpener)) lightboxOpener.focus();
      lightboxOpener = null;
    }
    els.lightboxClose.addEventListener('click', closeLightbox);
    els.lightbox.addEventListener('click', (ev) => {
      if (ev.target === els.lightbox) closeLightbox();
    });
    els.lightbox.addEventListener('keydown', (ev) => {
      if (els.lightbox.hidden) return;
      if (ev.key === 'Tab') {
        const focusables = [els.lightboxExt, els.lightboxClose].filter(el => el && !el.disabled);
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (ev.shiftKey && document.activeElement === first) {
          ev.preventDefault(); last.focus();
        } else if (!ev.shiftKey && document.activeElement === last) {
          ev.preventDefault(); first.focus();
        }
      }
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !els.lightbox.hidden) closeLightbox();
    });

    // Globale Tastatur-Shortcuts (nicht in Inputs / Textareas):
    //  - Space: Play / Pause
    //  - K: Play / Pause (Youtube-Konvention)
    document.addEventListener('keydown', (ev) => {
      if (!wavesurfer) return;
      const t = ev.target;
      const tag = t && t.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || (t && t.isContentEditable)) return;
      if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
      if (ev.key === ' ' || ev.key === 'k' || ev.key === 'K') {
        ev.preventDefault();
        if (wavesurfer.isPlaying()) wavesurfer.pause(); else wavesurfer.play();
      }
    });

    // ---------- Auto-Scroll beim Drag ----------
    let autoScrollSpeed = 0;
    let scrollRaf = null;
    const SCROLL_MARGIN = 90;
    const SCROLL_MAX_SPEED = 22;
    function scrollStep() {
      if (autoScrollSpeed) {
        window.scrollBy(0, autoScrollSpeed);
        scrollRaf = requestAnimationFrame(scrollStep);
      } else { scrollRaf = null; }
    }
    function updateAutoScroll(clientY) {
      const h = window.innerHeight;
      if (clientY < SCROLL_MARGIN) {
        const ratio = (SCROLL_MARGIN - clientY) / SCROLL_MARGIN;
        autoScrollSpeed = -Math.min(SCROLL_MAX_SPEED, Math.max(4, ratio * SCROLL_MAX_SPEED));
      } else if (clientY > h - SCROLL_MARGIN) {
        const ratio = (clientY - (h - SCROLL_MARGIN)) / SCROLL_MARGIN;
        autoScrollSpeed = Math.min(SCROLL_MAX_SPEED, Math.max(4, ratio * SCROLL_MAX_SPEED));
      } else { autoScrollSpeed = 0; }
      if (autoScrollSpeed && !scrollRaf) scrollRaf = requestAnimationFrame(scrollStep);
    }
    function stopAutoScroll() {
      autoScrollSpeed = 0;
      if (scrollRaf) { cancelAnimationFrame(scrollRaf); scrollRaf = null; }
    }
    document.addEventListener('dragover', (ev) => {
      const t = ev.dataTransfer && ev.dataTransfer.types;
      if (!t) return;
      const has = (name) => (t.contains ? t.contains(name) : Array.prototype.indexOf.call(t, name) >= 0);
      if (has('application/x-konzert-track') || has('application/x-konzert-entry')) {
        updateAutoScroll(ev.clientY);
      }
    });
    document.addEventListener('dragend',  stopAutoScroll);
    document.addEventListener('drop',     stopAutoScroll);
    window.addEventListener('dragleave', (ev) => {
      if (ev.clientX <= 0 || ev.clientY <= 0 ||
          ev.clientX >= window.innerWidth || ev.clientY >= window.innerHeight) {
        stopAutoScroll();
      }
    });

    // ---------- MP3-Upload ----------

    let uploadFiles = [];
    let uploadModalOpener = null;

    function openUploadModal(opener) {
      uploadModalOpener = opener || null;
      uploadFiles = [];
      renderUploadFileList();
      els.uploadStatus.textContent = '';
      els.uploadSubmit.disabled = true;
      els.uploadModal.hidden = false;
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(() => els.uploadDropzone.focus());
    }
    function closeUploadModal() {
      els.uploadModal.hidden = true;
      document.body.style.overflow = '';
      if (uploadModalOpener && document.contains(uploadModalOpener)) uploadModalOpener.focus();
      uploadModalOpener = null;
    }

    els.uploadBtn.addEventListener('click', () => openUploadModal(els.uploadBtn));
    els.uploadCancel.addEventListener('click', closeUploadModal);
    els.uploadModal.addEventListener('click', (ev) => { if (ev.target === els.uploadModal) closeUploadModal(); });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !els.uploadModal.hidden) closeUploadModal();
    });
    // Focus-Trap
    els.uploadModal.addEventListener('keydown', (ev) => {
      if (els.uploadModal.hidden || ev.key !== 'Tab') return;
      const list = Array.from(els.uploadModal.querySelectorAll(
        'input, button, [tabindex]:not([tabindex="-1"])'
      )).filter(el => !el.disabled && !el.hidden && el.offsetParent !== null);
      if (!list.length) return;
      const first = list[0], last = list[list.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    });

    function addUploadFiles(fileList) {
      for (const f of fileList) {
        if (!f.name.toLowerCase().endsWith('.mp3')) continue;
        if (uploadFiles.some(x => x.name === f.name && x.size === f.size)) continue;
        uploadFiles.push(f);
      }
      renderUploadFileList();
      els.uploadSubmit.disabled = uploadFiles.length === 0;
    }
    function removeUploadFile(idx) {
      uploadFiles.splice(idx, 1);
      renderUploadFileList();
      els.uploadSubmit.disabled = uploadFiles.length === 0;
    }
    function renderUploadFileList() {
      els.uploadFileList.innerHTML = '';
      if (!uploadFiles.length) return;
      uploadFiles.forEach((f, i) => {
        const row = document.createElement('div');
        row.className = 'upload-file-row';
        row.setAttribute('role', 'listitem');
        const name = document.createElement('span');
        name.className = 'upload-file-name';
        name.textContent = f.name;
        row.appendChild(name);
        const size = document.createElement('span');
        size.className = 'upload-file-size';
        size.textContent = (f.size / (1024 * 1024)).toFixed(1) + ' MB';
        row.appendChild(size);
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'ghost';
        rm.innerHTML = icon('i-close');
        rm.title = 'Entfernen';
        rm.setAttribute('aria-label', f.name + ' entfernen');
        rm.addEventListener('click', () => removeUploadFile(i));
        row.appendChild(rm);
        els.uploadFileList.appendChild(row);
      });
    }

    // Dropzone: Klick → Datei-Dialog
    els.uploadDropzone.addEventListener('click', () => els.uploadFileInput.click());
    els.uploadDropzone.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); els.uploadFileInput.click(); }
    });
    els.uploadFileInput.addEventListener('change', () => {
      if (els.uploadFileInput.files.length) addUploadFiles(els.uploadFileInput.files);
      els.uploadFileInput.value = '';
    });

    // Dropzone: Drag & Drop
    els.uploadDropzone.addEventListener('dragover', (ev) => {
      ev.preventDefault();
      els.uploadDropzone.classList.add('is-drag-over');
    });
    els.uploadDropzone.addEventListener('dragleave', () => {
      els.uploadDropzone.classList.remove('is-drag-over');
    });
    els.uploadDropzone.addEventListener('drop', (ev) => {
      ev.preventDefault();
      els.uploadDropzone.classList.remove('is-drag-over');
      if (ev.dataTransfer.files.length) addUploadFiles(ev.dataTransfer.files);
    });

    // Hochladen
    els.uploadSubmit.addEventListener('click', () => {
      if (!uploadFiles.length) return;
      els.uploadSubmit.disabled = true;
      els.uploadStatus.textContent = 'Lade hoch…';
      const fd = new FormData();
      uploadFiles.forEach(f => fd.append('files[]', f));
      fetch('?action=upload_track', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          if (!d.ok) {
            els.uploadStatus.textContent = 'Fehler: ' + (d.error || 'Upload fehlgeschlagen');
            els.uploadSubmit.disabled = false;
            return;
          }
          const up = d.uploaded || [];
          const errs = d.errors || [];
          up.forEach(name => {
            if (!state.available.includes(name)) state.available.push(name);
          });
          render({ only: 'pool' });
          // Dauer der neuen Tracks ermitteln
          up.forEach(name => {
            if (state.durations[name]) return;
            const a = new Audio();
            a.preload = 'metadata';
            a.src = trackUrl(name);
            a.addEventListener('loadedmetadata', () => {
              if (a.duration && isFinite(a.duration)) {
                state.durations[name] = a.duration;
                updateTotal();
                updateInlineDurationForTrack(name, a.duration);
              }
            });
          });
          let msg = up.length + ' Datei' + (up.length !== 1 ? 'en' : '') + ' hochgeladen';
          if (errs.length) msg += '\n' + errs.join('\n');
          els.uploadStatus.textContent = msg;
          uploadFiles = [];
          renderUploadFileList();
          flashStatus(up.length + ' Track' + (up.length !== 1 ? 's' : '') + ' hochgeladen', 2000);
        })
        .catch(() => {
          els.uploadStatus.textContent = 'Verbindungsfehler';
          els.uploadSubmit.disabled = false;
        });
    });

    // ---------- Freigabe (Teilen per Link + Passwort) ----------
    let shareInfo = null;
    const shareEls = {
      enabled:  document.getElementById('share-enabled'),
      permission: document.getElementById('share-permission'),
      password: document.getElementById('share-password'),
      urlRow:   document.getElementById('share-url-row'),
      url:      document.getElementById('share-url'),
      copy:     document.getElementById('share-copy'),
      rotate:   document.getElementById('share-rotate'),
      status:   document.getElementById('share-status'),
      save:     document.getElementById('share-save'),
    };
    function shareAbsUrl(rel) {
      try { return new URL(rel, location.href).href; } catch (e) { return rel; }
    }
    function fillShareUi() {
      if (!shareEls.enabled || SHARE_MODE) return;
      const info = shareInfo || { enabled: false, url: '', has_password: false, permission: 'view' };
      shareEls.enabled.checked = !!info.enabled;
      if (shareEls.permission) shareEls.permission.value = info.permission || 'view';
      shareEls.password.placeholder = info.has_password
        ? 'Passwort gesetzt — leer lassen für unverändert'
        : 'Passwort für Betrachter';
      const zeigeUrl = !!(info.enabled && info.url);
      shareEls.urlRow.hidden = !zeigeUrl;
      if (zeigeUrl) shareEls.url.value = shareAbsUrl(info.url);
    }
    function shareUpdate(body) {
      shareEls.status.textContent = 'Speichere…';
      fetch('?action=share_update' + API_K, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      })
        .then(r => r.json())
        .then(d => {
          if (!d.ok) {
            shareEls.status.textContent = 'Fehler: ' + (d.error || 'Speichern fehlgeschlagen');
            return;
          }
          shareInfo = d.share;
          shareEls.password.value = '';
          fillShareUi();
          shareEls.status.textContent = d.share.enabled
            ? 'Freigabe aktiv — Link unten kopieren und mit dem Passwort weitergeben.'
            : 'Freigabe deaktiviert — der Link funktioniert nicht mehr.';
        })
        .catch(() => { shareEls.status.textContent = 'Speichern fehlgeschlagen'; });
    }
    if (shareEls.save) {
      shareEls.save.addEventListener('click', () => {
        shareUpdate({ enabled: shareEls.enabled.checked, password: shareEls.password.value, permission: shareEls.permission ? shareEls.permission.value : 'view' });
      });
      shareEls.rotate.addEventListener('click', async () => {
        if (!await kpConfirm('Neuen Link erzeugen?\n\nDer bisherige Link funktioniert dann nicht mehr.')) return;
        shareUpdate({ enabled: true, regenerate: true, password: shareEls.password.value, permission: shareEls.permission ? shareEls.permission.value : 'view' });
      });
      shareEls.copy.addEventListener('click', () => {
        const url = shareEls.url.value;
        const fertig = () => { shareEls.status.textContent = 'Link kopiert — jetzt nur noch das Passwort weitergeben.'; };
        const fallback = () => { shareEls.url.select(); document.execCommand('copy'); fertig(); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(fertig).catch(fallback);
        } else {
          fallback();
        }
      });
    }

    // Beim Verlassen: anstehende Saves zuverlässig per sendBeacon flushen.
    // Reguläres fetch wird vom Browser beim Unload abgebrochen.
    window.addEventListener('beforeunload', () => {
      if (!CAN_EDIT_MARKERS) return; // ohne Schreibrecht nichts zu flushen
      // saveInFlight mit abdecken: das reguläre fetch wird beim Unload vom
      // Browser abgebrochen — der Beacon (mit base_updated_at, 409-geschützt)
      // stellt die gerade laufende Speicherung sicher.
      if (saveTimer || saveInFlight) { clearTimeout(saveTimer); beaconSaveEntries(); }
      if (metaSaveTimer)   { clearTimeout(metaSaveTimer);   beaconSaveMeta(); }
      if (markerSaveTimer) { clearTimeout(markerSaveTimer); beaconSaveMarkers(); }
    });
    function beaconSaveMarkers() {
      try {
        if (!state.currentEntryId) return;
        const blob = new Blob(
          [JSON.stringify({ entry: state.currentEntryId, markers: getMarkersForCurrent() })],
          { type: 'application/json' }
        );
        navigator.sendBeacon('?action=markers_save' + API_K, blob);
      } catch (e) {}
    }
    function beaconSaveEntries() {
      try {
        // base_updated_at mitschicken: hat zwischenzeitlich jemand anderes
        // gespeichert, verwirft der Server diesen Beacon (409) statt den neueren
        // Stand zu überschreiben. Die Antwort interessiert beim Unload nicht.
        const blob = new Blob(
          [JSON.stringify({ entries: state.entries, durations: state.durations, base_updated_at: state.meta.updatedAt || 0 })],
          { type: 'application/json' }
        );
        navigator.sendBeacon('?action=save' + API_K, blob);
      } catch (e) {}
    }
    function beaconSaveMeta() {
      try {
        const blob = new Blob(
          [JSON.stringify({
            name: state.meta.name,
            date: state.meta.date,
            description: state.meta.description,
            rehearsals: state.meta.rehearsals,
            marker_labels: state.meta.markerLabels,
            slots: state.meta.slots,
          })],
          { type: 'application/json' }
        );
        navigator.sendBeacon('?action=concert_save_meta' + API_K, blob);
      } catch (e) {}
    }
  })();
  </script>

<?php endif; ?>

  <!-- Einheitlicher Dialog: ersetzt native alert()/confirm() durch ein gestyltes Popup.
       Klassisches Script (kein Modul), damit window.kpAlert/kpConfirm in allen
       Modul-Scripten (Liste, Detail, Update) global verfügbar sind. -->
  <script>
  (function () {
    'use strict';
    var overlay = null, titleEl = null, msgEl = null, actionsEl = null,
        lastFocus = null, keyHandler = null;

    function build() {
      overlay = document.createElement('div');
      overlay.className = 'modal kp-dialog';
      overlay.hidden = true;
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-labelledby', 'kp-dialog-title');

      var inner = document.createElement('div');
      inner.className = 'modal-inner modal-inner-narrow kp-dialog-inner';

      titleEl = document.createElement('h2');
      titleEl.id = 'kp-dialog-title';
      titleEl.className = 'kp-dialog-title';

      msgEl = document.createElement('p');
      msgEl.className = 'kp-dialog-msg';

      actionsEl = document.createElement('div');
      actionsEl.className = 'modal-actions';

      inner.appendChild(titleEl);
      inner.appendChild(msgEl);
      inner.appendChild(actionsEl);
      overlay.appendChild(inner);
      document.body.appendChild(overlay);
    }

    // Mehrzeilige Meldungen (\n) sicher als Text mit Umbrüchen darstellen (kein innerHTML).
    function setMessage(text) {
      msgEl.textContent = '';
      String(text == null ? '' : text).split('\n').forEach(function (line, i) {
        if (i) msgEl.appendChild(document.createElement('br'));
        msgEl.appendChild(document.createTextNode(line));
      });
    }

    function close() {
      overlay.hidden = true;
      document.body.style.overflow = '';
      if (keyHandler) { document.removeEventListener('keydown', keyHandler); keyHandler = null; }
      overlay.onclick = null;
      if (lastFocus && typeof lastFocus.focus === 'function') { try { lastFocus.focus(); } catch (e) {} }
    }

    function open(opts) {
      if (!overlay) build();
      lastFocus = document.activeElement;

      titleEl.textContent = opts.title || '';
      titleEl.hidden = !opts.title;
      setMessage(opts.message);
      actionsEl.textContent = '';

      return new Promise(function (resolve) {
        function finish(value) { close(); resolve(value); }

        if (opts.confirm) {
          var cancelBtn = document.createElement('button');
          cancelBtn.type = 'button';
          cancelBtn.className = 'ghost';
          cancelBtn.textContent = opts.cancelLabel || 'Abbrechen';
          cancelBtn.addEventListener('click', function () { finish(false); });
          actionsEl.appendChild(cancelBtn);
        }

        var okBtn = document.createElement('button');
        okBtn.type = 'button';
        okBtn.className = 'primary';
        okBtn.textContent = opts.okLabel || 'OK';
        okBtn.addEventListener('click', function () { finish(opts.confirm ? true : undefined); });
        actionsEl.appendChild(okBtn);

        // Esc bricht ab; Tab bleibt im Dialog (Fokus-Trap wie die übrigen Modals).
        // Enter braucht keinen eigenen Handler — der fokussierte Button wird nativ ausgelöst,
        // sodass Enter auf „Abbrechen" auch wirklich abbricht.
        keyHandler = function (ev) {
          if (ev.key === 'Escape') { ev.preventDefault(); finish(opts.confirm ? false : undefined); return; }
          if (ev.key === 'Tab') {
            var list = Array.prototype.slice.call(overlay.querySelectorAll('button'))
              .filter(function (el) { return !el.disabled && el.offsetParent !== null; });
            if (!list.length) return;
            var first = list[0], last = list[list.length - 1];
            if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
            else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
          }
        };
        document.addEventListener('keydown', keyHandler);
        // Klick auf den abgedunkelten Hintergrund = Abbrechen.
        overlay.onclick = function (ev) { if (ev.target === overlay) finish(opts.confirm ? false : undefined); };

        document.body.style.overflow = 'hidden';
        overlay.hidden = false;
        okBtn.focus();
      });
    }

    // Fehlermeldungen erkennen, um den passenden Titel automatisch zu setzen.
    function isError(msg) {
      return /^Fehler|fehlgeschlagen|Verbindungsfehler/.test(String(msg == null ? '' : msg));
    }

    // Info-/Fehler-Popup. Gibt ein Promise zurück (löst auf, wenn weggeklickt).
    window.kpAlert = function (message, opts) {
      opts = opts || {};
      return open({
        message: message,
        title: opts.title || (isError(message) ? 'Fehler' : 'Hinweis'),
        okLabel: opts.okLabel || 'OK',
        confirm: false
      });
    };

    // Ja/Nein-Rückfrage. Gibt ein Promise<boolean> zurück (true = bestätigt).
    window.kpConfirm = function (message, opts) {
      opts = opts || {};
      return open({
        message: message,
        title: opts.title || 'Bestätigen',
        okLabel: opts.okLabel || 'OK',
        cancelLabel: opts.cancelLabel || 'Abbrechen',
        confirm: true
      });
    };
  })();
  </script>

</body>
</html>
