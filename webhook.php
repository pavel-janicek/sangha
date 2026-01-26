<?php
// Define a constant to indicate authorized access
define('ALLOW_ACCESS', true);

include 'config.php';

// ==========================
//  DATABASE INIT
// ==========================
$db = new PDO('sqlite:data.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Události
$db->exec("
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    date TEXT,
    is_active INTEGER DEFAULT 1
);
");

// Odpovědi
$db->exec("
CREATE TABLE IF NOT EXISTS responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER,
    telegram_id INTEGER,
    name TEXT,
    will_come INTEGER,
    brings TEXT,
    does TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Admini
$db->exec("
CREATE TABLE IF NOT EXISTS admins (
    telegram_id INTEGER PRIMARY KEY
);
");

// ==========================
//  TELEGRAM HELPERS
// ==========================
function sendMessage($chat_id, $text) {
    global $BOT_TOKEN;
    file_get_contents("https://api.telegram.org/bot$BOT_TOKEN/sendMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'text'    => $text
    ]));
}

function isAdmin($id) {
    global $db;
    $stmt = $db->prepare("SELECT 1 FROM admins WHERE telegram_id = ?");
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

// ==========================
//  PROCESS UPDATE
// ==========================
$update = json_decode(file_get_contents("php://input"), true);

if (!isset($update["message"])) exit;

$message   = $update["message"];
$chat_id   = $message["chat"]["id"];
$user_id   = $message["from"]["id"];
$name      = $message["from"]["first_name"];
$text      = strtolower(trim($message["text"] ?? ""));

// ==========================
//  COMMAND: /addevent
// ==========================
if (str_starts_with($text, "/addevent")) {
    if (!isAdmin($user_id)) {
        sendMessage($chat_id, "Tento příkaz je jen pro adminy.");
        exit;
    }

    $title = trim(substr($text, 9));
    if ($title === "") {
        sendMessage($chat_id, "Použití: /addevent Název události");
        exit;
    }

    $stmt = $db->prepare("INSERT INTO events (title) VALUES (?)");
    $stmt->execute([$title]);

    $id = $db->lastInsertId();
    sendMessage($chat_id, "Událost vytvořena: ID $id");
    exit;
}

// ==========================
//  COMMAND: /event <id>
// ==========================
if (str_starts_with($text, "/event")) {
    $parts = explode(" ", $text);
    $event_id = intval($parts[1] ?? 0);

    $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        sendMessage($chat_id, "Událost nenalezena.");
        exit;
    }

    // Uložíme si do session-like tabulky (jednoduše do responses)
    $db->prepare("INSERT OR IGNORE INTO responses (event_id, telegram_id, name) VALUES (?, ?, ?)")
       ->execute([$event_id, $user_id, $name]);

    sendMessage($chat_id, "Vybral jsi událost: {$event['title']}\nNapiš: přijdu / nepřijdu / přinesu X / udělám Y");
    exit;
}

// ==========================
//  COMMAND: /status <id>
// ==========================
if (str_starts_with($text, "/status")) {
    if (!isAdmin($user_id)) {
        sendMessage($chat_id, "Tento příkaz je jen pro adminy.");
        exit;
    }

    $parts = explode(" ", $text);
    $event_id = intval($parts[1] ?? 0);

    $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        sendMessage($chat_id, "Událost nenalezena.");
        exit;
    }

    $coming = $db->prepare("SELECT * FROM responses WHERE event_id = ? AND will_come = 1");
    $coming->execute([$event_id]);
    $coming = $coming->fetchAll(PDO::FETCH_ASSOC);

    $not = $db->prepare("SELECT * FROM responses WHERE event_id = ? AND will_come = 0");
    $not->execute([$event_id]);
    $not = $not->fetchAll(PDO::FETCH_ASSOC);

    $msg = "📋 Přehled pro událost: {$event['title']}\n\n";

    $msg .= "🟢 Přijdou:\n";
    foreach ($coming as $row) {
        $line = " - {$row['name']}";
        if ($row['brings']) $line .= " (přinese: {$row['brings']})";
        if ($row['does'])   $line .= " (udělá: {$row['does']})";
        $msg .= $line . "\n";
    }

    $msg .= "\n🔴 Nepřijdou:\n";
    foreach ($not as $row) {
        $msg .= " - {$row['name']}\n";
    }

    sendMessage($chat_id, $msg);
    exit;
}

// ==========================
//  USER RESPONSES
// ==========================

// Najdeme poslední událost, kterou si uživatel vybral
$stmt = $db->prepare("SELECT event_id FROM responses WHERE telegram_id = ? ORDER BY updated_at DESC LIMIT 1");
$stmt->execute([$user_id]);
$event_id = $stmt->fetchColumn();

if (!$event_id) {
    sendMessage($chat_id, "Nejdřív vyber událost pomocí /event <id>");
    exit;
}

// Přijdu
if (str_contains($text, "přijdu")) {
    $db->prepare("UPDATE responses SET will_come = 1 WHERE telegram_id = ? AND event_id = ?")
       ->execute([$user_id, $event_id]);
    sendMessage($chat_id, "👍 Zapsal jsem, že přijdeš.");
    exit;
}

// Nepřijdu
if (str_contains($text, "nepřijdu")) {
    $db->prepare("UPDATE responses SET will_come = 0 WHERE telegram_id = ? AND event_id = ?")
       ->execute([$user_id, $event_id]);
    sendMessage($chat_id, "👋 Zapsal jsem, že nepřijdeš.");
    exit;
}

// Přinesu X
if (str_contains($text, "přinesu")) {
    $item = trim(str_replace("přinesu", "", $text));
    $db->prepare("UPDATE responses SET brings = ? WHERE telegram_id = ? AND event_id = ?")
       ->execute([$item, $user_id, $event_id]);
    sendMessage($chat_id, "🧺 Zapisuji, že přineseš: $item");
    exit;
}

// Udělám X
if (str_contains($text, "udělám")) {
    $task = trim(str_replace("udělám", "", $text));
    $db->prepare("UPDATE responses SET does = ? WHERE telegram_id = ? AND event_id = ?")
       ->execute([$task, $user_id, $event_id]);
    sendMessage($chat_id, "👨‍🍳 Zapisuji, že uděláš: $task");
    exit;
}

sendMessage($chat_id, "Napiš: přijdu / nepřijdu / přinesu X / udělám Y");

?>