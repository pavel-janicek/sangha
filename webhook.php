<?php
// Define a constant to indicate authorized access
define('ALLOW_ACCESS', true);

include 'config.php';

// ==========================
//  DATABASE INIT
// ==========================
$db = new PDO('sqlite:data.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Tabulka pro odpovědi
$db->exec("
CREATE TABLE IF NOT EXISTS responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER,
    name TEXT,
    will_come INTEGER,
    brings TEXT,
    does TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// ==========================
//  TELEGRAM HELPERS
// ==========================
function sendMessage($chat_id, $text) {
    global $bot_token;
    file_get_contents("https://api.telegram.org/bot$bot_token/sendMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'text'    => $text
    ]));
}

// ==========================
//  PROCESS UPDATE
// ==========================
$update = json_decode(file_get_contents("php://input"), true);

if (!isset($update["message"])) {
    exit;
}

$message   = $update["message"];
$chat_id   = $message["chat"]["id"];
$user_id   = $message["from"]["id"];
$name      = $message["from"]["first_name"];
$text      = strtolower(trim($message["text"] ?? ""));

// ==========================
//  ADMIN COMMAND: /status
// ==========================
if ($text === "/status") {
    if ($user_id != $admin_id) {
        sendMessage($chat_id, "Tento příkaz je jen pro admina.");
        exit;
    }

    $coming = $db->query("SELECT name, brings, does FROM responses WHERE will_come = 1")->fetchAll(PDO::FETCH_ASSOC);
    $not_coming = $db->query("SELECT name FROM responses WHERE will_come = 0")->fetchAll(PDO::FETCH_ASSOC);

    $msg = "📋 *Přehled účasti*\n\n";

    $msg .= "🟢 Přijdou:\n";
    if (!$coming) {
        $msg .= "  - nikdo zatím\n";
    } else {
        foreach ($coming as $row) {
            $line = "  - {$row['name']}";
            if ($row['brings']) $line .= " (přinese: {$row['brings']})";
            if ($row['does'])   $line .= " (udělá: {$row['does']})";
            $msg .= $line . "\n";
        }
    }

    $msg .= "\n🔴 Nepřijdou:\n";
    if (!$not_coming) {
        $msg .= "  - nikdo\n";
    } else {
        foreach ($not_coming as $row) {
            $msg .= "  - {$row['name']}\n";
        }
    }

    sendMessage($chat_id, $msg);
    exit;
}

// ==========================
//  USER RESPONSES
// ==========================

// Základní záznam uživatele
$stmt = $db->prepare("SELECT * FROM responses WHERE telegram_id = ?");
$stmt->execute([$user_id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    $db->prepare("INSERT INTO responses (telegram_id, name) VALUES (?, ?)")
       ->execute([$user_id, $name]);
}

// Přijdu
if (str_contains($text, "přijdu")) {
    $db->prepare("UPDATE responses SET will_come = 1 WHERE telegram_id = ?")
       ->execute([$user_id]);
    sendMessage($chat_id, "👍 Díky, $name! Zapsal jsem, že přijdeš.");
    exit;
}

// Nepřijdu
if (str_contains($text, "nepřijdu")) {
    $db->prepare("UPDATE responses SET will_come = 0 WHERE telegram_id = ?")
       ->execute([$user_id]);
    sendMessage($chat_id, "👋 Mrzí mě to, $name. Zapsal jsem, že nepřijdeš.");
    exit;
}

// Přinesu X
if (str_contains($text, "přinesu")) {
    $item = trim(str_replace("přinesu", "", $text));
    $db->prepare("UPDATE responses SET brings = ? WHERE telegram_id = ?")
       ->execute([$item, $user_id]);
    sendMessage($chat_id, "🧺 Super, $name! Zapisuji, že přineseš: $item");
    exit;
}

// Udělám X
if (str_contains($text, "udělám")) {
    $task = trim(str_replace("udělám", "", $text));
    $db->prepare("UPDATE responses SET does = ? WHERE telegram_id = ?")
       ->execute([$task, $user_id]);
    sendMessage($chat_id, "👨‍🍳 Paráda, $name! Zapisuji, že uděláš: $task");
    exit;
}

// ==========================
//  DEFAULT
// ==========================
sendMessage($chat_id, "Ahoj $name! Můžeš napsat:\n- Přijdu\n- Nepřijdu\n- Přinesu ...\n- Udělám ...\nAdmin může použít /status.");

?>