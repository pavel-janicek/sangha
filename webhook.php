<?php
// Define a constant to indicate authorized access
define('ALLOW_ACCESS', true);

include 'config.php';

<?php

// ==========================
//  DATABASE INIT
// ==========================
$$db = new PDO( "mysql:host={$db_config['db_host']};dbname={$db_config['db_name']};charset=utf8mb4",
 $db_config['db_user'], $db_config['db_pass'],
  [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ] );
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

function sendMessageWithButtons($chat_id, $text, $buttons) {
    global $BOT_TOKEN;

    $payload = [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => json_encode([
            'inline_keyboard' => $buttons
        ])
    ];

    file_get_contents("https://api.telegram.org/bot$BOT_TOKEN/sendMessage?" . http_build_query($payload));
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

// ==========================
//  CALLBACK HANDLER
// ==========================
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'];
    $user_id = $cb['from']['id'];
    $chat_id = $cb['message']['chat']['id'];

    list($action, $event_id) = explode(":", $data);

    // Přijdu
    if ($action === "come_yes") {
        $db->prepare("UPDATE responses SET will_come = 1 WHERE telegram_id = ? AND event_id = ?")
           ->execute([$user_id, $event_id]);
        sendMessage($chat_id, "👍 Zapsal jsem, že přijdeš.");
        exit;
    }

    // Nepřijdu
    if ($action === "come_no") {
        $db->prepare("UPDATE responses SET will_come = 0 WHERE telegram_id = ? AND event_id = ?")
           ->execute([$user_id, $event_id]);
        sendMessage($chat_id, "👋 Zapsal jsem, že nepřijdeš.");
        exit;
    }

    // Přinesu…
    if ($action === "bring") {
        sendMessage($chat_id, "Co přineseš?");
        $db->prepare("UPDATE responses SET brings = '__WAITING__' WHERE telegram_id = ? AND event_id = ?")
           ->execute([$user_id, $event_id]);
        exit;
    }

    // Udělám…
    if ($action === "do") {
        sendMessage($chat_id, "Co uděláš?");
        $db->prepare("UPDATE responses SET does = '__WAITING__' WHERE telegram_id = ? AND event_id = ?")
           ->execute([$user_id, $event_id]);
        exit;
    }

    exit;
}

// ==========================
//  MESSAGE HANDLER
// ==========================
if (!isset($update["message"])) exit;

$message   = $update["message"];
$chat_id   = $message["chat"]["id"];
$user_id   = $message["from"]["id"];
$name      = $message["from"]["first_name"];
$text      = strtolower(trim($message["text"] ?? ""));

// ==========================
//  COMMAND: /addadmin <id>
// ==========================
if (str_starts_with($text, "/addadmin")) {
    if (!isAdmin($user_id)) {
        sendMessage($chat_id, "Tento příkaz je jen pro adminy.");
        exit;
    }

    $parts = explode(" ", $text);
    $new_admin = intval($parts[1] ?? 0);

    if ($new_admin <= 0) {
        sendMessage($chat_id, "Použití: /addadmin <telegram_id>");
        exit;
    }

    $stmt = $db->prepare("INSERT OR IGNORE INTO admins (telegram_id) VALUES (?)");
    $stmt->execute([$new_admin]);

    sendMessage($chat_id, "Admin přidán: $new_admin");
    exit;
}

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
//  COMMAND: /events
// ==========================
if ($text === "/events") {
    $events = $db->query("SELECT * FROM events ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

    if (!$events) {
        sendMessage($chat_id, "Žádné události zatím nejsou.");
        exit;
    }

    foreach ($events as $e) {
        sendMessageWithButtons($chat_id, "Událost #{$e['id']}: {$e['title']}", [
            [['text' => 'Vybrat', 'callback_data' => "select:{$e['id']}"]]
        ]);
    }

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

    $db->prepare("INSERT OR IGNORE INTO responses (event_id, telegram_id, name) VALUES (?, ?, ?)")
       ->execute([$event_id, $user_id, $name]);

    sendMessageWithButtons($chat_id, "Vybral jsi událost: {$event['title']}", [
        [['text' => 'Přijdu', 'callback_data' => "come_yes:$event_id"]],
        [['text' => 'Nepřijdu', 'callback_data' => "come_no:$event_id"]],
        [['text' => 'Přinesu…', 'callback_data' => "bring:$event_id"]],
        [['text' => 'Udělám…', 'callback_data' => "do:$event_id"]],
    ]);

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
//  TEXT AFTER "Přinesu…" / "Udělám…"
// ==========================
$stmt = $db->prepare("SELECT event_id FROM responses WHERE telegram_id = ? AND brings = '__WAITING__'");
$stmt->execute([$user_id]);
$event_id = $stmt->fetchColumn();

if ($event_id) {
    $db->prepare("UPDATE responses SET brings = ? WHERE telegram_id = ? AND event_id = ?")
       ->execute([$text, $user_id, $event_id]);
    sendMessage($chat_id, "🧺 Zapisuji, že přineseš: $text");
    exit;
}

$stmt = $db->prepare("SELECT event_id FROM responses WHERE telegram_id = ? AND does = '__WAITING__'");
$stmt->execute([$user_id]);
$event_id = $stmt->fetchColumn();

if ($event_id) {
    $db->prepare("UPDATE responses SET does = ? WHERE telegram_id = ? AND event_id = ?")
       ->execute([$text, $user_id, $event_id]);
    sendMessage($chat_id, "👨‍🍳 Zapisuji, že uděláš: $text");
    exit;
}

// ==========================
//  DEFAULT
// ==========================
sendMessage($chat_id, "Použij: /events nebo /event <id>");

?>