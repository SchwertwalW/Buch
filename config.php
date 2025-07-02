<?php
// api/config.php - Konfigurationsdatei
<?php
define('DATA_DIR', '../data/');
define('BACKUP_DIR', '../backups/');
define('DATA_FILE', DATA_DIR . 'books_data.json');
define('LOG_FILE', DATA_DIR . 'access.log');

// CORS Headers für Frontend-Zugriff
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Preflight-Request behandeln
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Verzeichnisse erstellen falls sie nicht existieren
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
if (!file_exists(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}

// Logging-Funktion
function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

// Basis JSON-Struktur
function getDefaultData() {
    return [
        'books' => [],
        'groups' => [],
        'customGenres' => [],
        'settings' => [
            'theme' => 'default',
            'autoBackup' => true
        ],
        'lastModified' => date('c'),
        'version' => '1.0'
    ];
}

// JSON-Datei laden
function loadJsonData() {
    if (!file_exists(DATA_FILE)) {
        $defaultData = getDefaultData();
        saveJsonData($defaultData);
        return $defaultData;
    }
    
    $jsonContent = file_get_contents(DATA_FILE);
    $data = json_decode($jsonContent, true);
    
    if ($data === null) {
        writeLog("Fehler beim Laden der JSON-Datei: " . json_last_error_msg());
        return getDefaultData();
    }
    
    return $data;
}

// JSON-Datei speichern
function saveJsonData($data) {
    $data['lastModified'] = date('c');
    $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($jsonContent === false) {
        writeLog("Fehler beim Erstellen der JSON: " . json_last_error_msg());
        return false;
    }
    
    // Backup der aktuellen Datei erstellen
    if (file_exists(DATA_FILE)) {
        $backupFile = DATA_FILE . '.backup_' . date('Y-m-d_H-i-s');
        copy(DATA_FILE, $backupFile);
    }
    
    $result = file_put_contents(DATA_FILE, $jsonContent, LOCK_EX);
    
    if ($result === false) {
        writeLog("Fehler beim Speichern der JSON-Datei");
        return false;
    }
    
    writeLog("Daten erfolgreich gespeichert (" . strlen($jsonContent) . " Bytes)");
    return true;
}
?>

---

<?php
// api/test.php - Verbindungstest
require_once 'config.php';

try {
    $response = [
        'status' => 'success',
        'message' => 'Server ist erreichbar',
        'timestamp' => date('c'),
        'php_version' => PHP_VERSION,
        'data_dir_writable' => is_writable(DATA_DIR),
        'backup_dir_writable' => is_writable(BACKUP_DIR)
    ];
    
    writeLog("Verbindungstest erfolgreich");
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Server-Fehler: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    writeLog("Verbindungstest fehlgeschlagen: " . $e->getMessage());
    echo json_encode($response);
}
?>

---

<?php
// api/load_data.php - Daten laden
require_once 'config.php';

try {
    $data = loadJsonData();
    
    $response = [
        'status' => 'success',
        'data' => $data,
        'timestamp' => date('c')
    ];
    
    writeLog("Daten erfolgreich geladen");
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Fehler beim Laden der Daten: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    writeLog("Fehler beim Laden der Daten: " . $e->getMessage());
    echo json_encode($response);
}
?>

---

<?php
// api/save_data.php - Daten speichern
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Nur POST-Requests erlaubt']);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $newData = json_decode($input, true);
    
    if ($newData === null) {
        throw new Exception('Ungültige JSON-Daten: ' . json_last_error_msg());
    }
    
    // Aktuelle Daten laden und mit neuen Daten zusammenführen
    $currentData = loadJsonData();
    
    // Bücher aktualisieren
    if (isset($newData['books'])) {
        $currentData['books'] = $newData['books'];
    }
    
    // Gruppierungen aktualisieren
    if (isset($newData['groups'])) {
        $currentData['groups'] = $newData['groups'];
    }
    
    // Benutzerdefinierte Genres aktualisieren
    if (isset($newData['customGenres'])) {
        $currentData['customGenres'] = $newData['customGenres'];
    }
    
    // Einstellungen aktualisieren
    if (isset($newData['settings'])) {
        $currentData['settings'] = array_merge($currentData['settings'], $newData['settings']);
    }
    
    // Daten speichern
    if (saveJsonData($currentData)) {
        $response = [
            'status' => 'success',
            'message' => 'Daten erfolgreich gespeichert',
            'timestamp' => date('c'),
            'books_count' => count($currentData['books'])
        ];
        
        echo json_encode($response);
    } else {
        throw new Exception('Fehler beim Speichern der Datei');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Fehler beim Speichern: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    writeLog("Fehler beim Speichern: " . $e->getMessage());
    echo json_encode($response);
}
?>

---

<?php
// api/create_backup.php - Backup erstellen
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Nur POST-Requests erlaubt']);
    exit;
}

try {
    if (!file_exists(DATA_FILE)) {
        throw new Exception('Keine Datei zum Sichern vorhanden');
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFilename = "backup_$timestamp.json";
    $backupPath = BACKUP_DIR . $backupFilename;
    
    // Backup erstellen
    if (!copy(DATA_FILE, $backupPath)) {
        throw new Exception('Backup konnte nicht erstellt werden');
    }
    
    // Alte Backups aufräumen (nur die letzten 10 behalten)
    $backupFiles = glob(BACKUP_DIR . 'backup_*.json');
    if (count($backupFiles) > 10) {
        // Nach Änderungsdatum sortieren (älteste zuerst)
        usort($backupFiles, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Älteste Backups löschen
        $filesToDelete = array_slice($backupFiles, 0, count($backupFiles) - 10);
        foreach ($filesToDelete as $file) {
            unlink($file);
        }
    }
    
    $fileSize = filesize($backupPath);
    
    $response = [
        'status' => 'success',
        'message' => 'Backup erfolgreich erstellt',
        'filename' => $backupFilename,
        'filepath' => $backupPath,
        'filesize' => $fileSize,
        'timestamp' => date('c')
    ];
    
    writeLog("Backup erstellt: $backupFilename ($fileSize Bytes)");
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Fehler beim Erstellen des Backups: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    writeLog("Backup-Fehler: " . $e->getMessage());
    echo json_encode($response);
}
?>

---

<?php
// api/get_backups.php - Verfügbare Backups anzeigen
require_once 'config.php';

try {
    $backupFiles = glob(BACKUP_DIR . 'backup_*.json');
    $backups = [];
    
    foreach ($backupFiles as $file) {
        $filename = basename($file);
        $backups[] = [
            'filename' => $filename,
            'size' => filesize($file),
            'created' => date('c', filemtime($file)),
            'created_formatted' => date('d.m.Y H:i:s', filemtime($file))
        ];
    }
    
    // Nach Erstellungsdatum sortieren (neueste zuerst)
    usort($backups, function($a, $b) {
        return strtotime($b['created']) - strtotime($a['created']);
    });
    
    $response = [
        'status' => 'success',
        'backups' => $backups,
        'count' => count($backups),
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Fehler beim Abrufen der Backups: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
}
?>

---

<?php
// api/restore_backup.php - Backup wiederherstellen
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Nur POST-Requests erlaubt']);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $request = json_decode($input, true);
    
    if (!isset($request['filename'])) {
        throw new Exception('Backup-Dateiname nicht angegeben');
    }
    
    $backupFile = BACKUP_DIR . $request['filename'];
    
    if (!file_exists($backupFile)) {
        throw new Exception('Backup-Datei nicht gefunden');
    }
    
    // Aktuelles Backup erstellen bevor Wiederherstellung
    if (file_exists(DATA_FILE)) {
        $currentBackup = DATA_FILE . '.before_restore_' . date('Y-m-d_H-i-s');
        copy(DATA_FILE, $currentBackup);
    }
    
    // Backup wiederherstellen
    if (!copy($backupFile, DATA_FILE)) {
        throw new Exception('Wiederherstellung fehlgeschlagen');
    }
    
    // Validierung der wiederhergestellten Daten
    $restoredData = loadJsonData();
    if (!$restoredData) {
        throw new Exception('Wiederhergestellte Daten sind ungültig');
    }
    
    $response = [
        'status' => 'success',
        'message' => 'Backup erfolgreich wiederhergestellt',
        'filename' => $request['filename'],
        'books_count' => count($restoredData['books'] ?? []),
        'timestamp' => date('c')
    ];
    
    writeLog("Backup wiederhergestellt: " . $request['filename']);
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Fehler bei der Wiederherstellung: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    writeLog("Wiederherstellungs-Fehler: " . $e->getMessage());
    echo json_encode($response);
}
?>

---

<?php
// api/export_data.php - Daten in verschiedenen Formaten exportieren
require_once 'config.php';

try {
    $format = $_GET['format'] ?? 'json';
    $data = loadJsonData();
    
    switch ($format) {
        case 'json':
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="buchverwaltung_' . date('Y-m-d') . '.json"');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="buchverwaltung_' . date('Y-m-d') . '.csv"');
            
            echo "\xEF\xBB\xBF"; // BOM für UTF-8
            echo "Titel,Autor,Genre,Fortschritt,Bewertung,Fertig gelesen,Kommentare\n";
            
            foreach ($data['books'] as $book) {
                echo sprintf('"%s","%s","%s","%s%%","%s","%s","%s"' . "\n",
                    str_replace('"', '""', $book['title'] ?? ''),
                    str_replace('"', '""', $book['author'] ?? ''),
                    str_replace('"', '""', $book['genre'] ?? ''),
                    $book['progress'] ?? 0,
                    $book['rating'] ?? '',
                    $book['dateFinished'] ?? '',
                    str_replace('"', '""', $book['comments'] ?? '')
                );
            }
            break;
            
        default:
            throw new Exception('Unbekanntes Export-Format');
    }
    
    writeLog("Daten exportiert als $format");
    
} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'status' => 'error',
        'message' => 'Export-Fehler: ' . $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
}
?>

---

# .htaccess - Konfiguration für Apache-Server
RewriteEngine On

# API-Routen
RewriteRule ^api/test/?$ api/test.php [L]
RewriteRule ^api/load/?$ api/load_data.php [L]
RewriteRule ^api/save/?$ api/save_data.php [L]
RewriteRule ^api/backup/?$ api/create_backup.php [L]
RewriteRule ^api/backups/?$ api/get_backups.php [L]
RewriteRule ^api/restore/?$ api/restore_backup.php [L]
RewriteRule ^api/export/?$ api/export_data.php [L]

# Sicherheit - Direkten Zugriff auf Datenverzeichnisse verhindern
RewriteRule ^data/ - [F,L]
RewriteRule ^backups/ - [F,L]

# Cache-Control für statische Dateien
<FilesMatch "\.(html|css|js)$">
    Header set Cache-Control "max-age=3600"
</FilesMatch>

# JSON-Dateien vor direktem Zugriff schützen
<FilesMatch "\.json$">
    Order Allow,Deny
    Deny from all
</FilesMatch>