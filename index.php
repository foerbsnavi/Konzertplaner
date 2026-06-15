<?php
// Konzertplaner — Einstiegspunkt (Standalone / Selfhosting)
// Definiert die Betriebsart und alle Pfade, lädt dann die Engine aus core/.
// Diese Datei wird bei Updates ersetzt; config/ und daten/ bleiben unangetastet.

declare(strict_types=1);

define('KP_MODE',        'standalone');
define('KP_BASE_DIR',    __DIR__);                      // Installations-Wurzel (für Updates/Backups)
define('KP_CORE_DIR',    __DIR__ . '/core');            // Engine — wird bei Updates ersetzt
define('KP_DATA_DIR',    __DIR__ . '/daten');           // Nutzerdaten — bei Updates geschützt
define('KP_CONFIG_DIR',  __DIR__ . '/config');          // Konfiguration — bei Updates geschützt
define('KP_CONFIG_FILE', KP_CONFIG_DIR . '/config.php');
define('KP_VERSION_FILE', __DIR__ . '/version.json');

// URLs relativ zu dieser Datei (Browser-Sicht)
define('KP_ASSET_URL', 'core/assets');
define('KP_DATA_URL',  'daten');

// Erstaufruf ohne Konfiguration → Einrichtungsdialog (legt config/config.php an)
if (!is_file(KP_CONFIG_FILE)) {
    require KP_CORE_DIR . '/setup.php';
    exit;
}

require KP_CONFIG_FILE;   // definiert KP_PASSWORD_HASH
require KP_CORE_DIR . '/app.php';
