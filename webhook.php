<?php
// Define a constant to indicate authorized access
define('ALLOW_ACCESS', true);

$config = require __DIR__ . '/config.php';

$BOT_TOKEN = $config['bot_token'];

// ==========================
//  DATABASE INIT
// ==========================
$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

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

    $db->prepare("INSERT IGNORE INTO responses (event_id, telegram_id, name) VALUES (?, ?, ?)")->execute([$event_id, $user_id, $name]);

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
        // automaticky přijde 
        $db->prepare("UPDATE responses SET will_come = 1 WHERE telegram_id = ? AND event_id = ?") ->execute([$user_id, $event_id]);
        sendMessage($chat_id, "Co přineseš?");
        $db->prepare("UPDATE responses SET brings = '__WAITING__' WHERE telegram_id = ? AND event_id = ?")
           ->execute([$user_id, $event_id]);
        exit;
    }

    // Udělám…
    if ($action === "do") {
        // automaticky přijde
        $db->prepare("UPDATE responses SET will_come = 1 WHERE telegram_id = ? AND event_id = ?") ->execute([$user_id, $event_id]);
        sendMessage($chat_id, "Co uděláš?");
        $db->prepare("UPDATE responses SET does = '__WAITING__' WHERE telegram_id = ? AND event_id = ?")
           ->execute([$user_id, $event_id]);
        exit;
    }

    // Vybrat událost
    if ($action === "select") {

        // Ujisti se, že uživatel má záznam v responses
        $db->prepare("INSERT IGNORE INTO responses (event_id, telegram_id, name)
                      VALUES (?, ?, ?)")
           ->execute([$event_id, $user_id, $name]);

        // Pošli tlačítka
        sendMessageWithButtons($chat_id, "Vybral jsi událost #$event_id. Co chceš udělat?", [
            [['text' => 'Přijdu',    'callback_data' => "come_yes:$event_id"]],
            [['text' => 'Nepřijdu', 'callback_data' => "come_no:$event_id"]],
            [['text' => 'Přinesu…', 'callback_data' => "bring:$event_id"]],
            [['text' => 'Udělám…',  'callback_data' => "do:$event_id"]],
        ]);

        exit;
    }

    // ==========================
    // CALLBACK: Uzavřít událost
    // ==========================
    if ($action === "close") {
        if (!isAdmin($user_id)) {
            sendMessage($chat_id, "Tento příkaz je jen pro adminy.");
            exit;
        }
        $db->prepare("UPDATE events SET is_active = 0 WHERE id = ?")->execute([$event_id]);
        sendMessage($chat_id, "🔒 Událost #$event_id byla uzavřena.");
        exit;
    }

    // ==========================
    // CALLBACK: status
    // ==========================
    if ($action === "status") {

        if (!isAdmin($user_id)) {
            sendMessage($chat_id, "Tento příkaz je jen pro adminy.");
            exit;
        }

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

        sendMessageWithButtons($chat_id, $msg, [
            [['text' => 'Uzavřít událost', 'callback_data' => "close:$event_id"]]
        ]);

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
    $events = $db->query("SELECT * FROM events WHERE is_active = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

    if (!$events) {
        sendMessage($chat_id, "Žádné události zatím nejsou.");
        exit;
    }

    foreach ($events as $e) {
        $buttons = [
            [['text' => 'Vybrat', 'callback_data' => "select:{$e['id']}"]]
        ];

        // Admin dostane navíc tlačítko Status
        if (isAdmin($user_id)) {
            $buttons[] = [['text' => 'Status', 'callback_data' => "status:{$e['id']}"]];
        }

        sendMessageWithButtons($chat_id, "Událost #{$e['id']}: {$e['title']}", $buttons);
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

    sendMessageWithButtons($chat_id, $msg, [
        [['text' => 'Uzavřít událost', 'callback_data' => "close:$event_id"]]
    ]);

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
//  COMMAND: /help
// ==========================
if ($text === "/help") {

    if (isAdmin($user_id)) {

        $msg = "🛠 *Nápověda pro adminy*\n\n";
        $msg .= "Dostupné příkazy:\n";
        $msg .= "• /events – zobrazí seznam událostí\n";
        $msg .= "• /addevent <název> – vytvoří novou událost\n";
        $msg .= "• /status <id> – zobrazí přehled účastníků\n";
        $msg .= "• /addadmin <id> – přidá nového admina\n\n";
        $msg .= "Dostupná tlačítka:\n";
        $msg .= "• Vybrat – otevře menu události\n";
        $msg .= "• Uzavřít událost – zamkne událost proti změnám\n\n";
        $msg .= "Uživatelé mohou používat:\n";
        $msg .= "• Přijdu / Nepřijdu\n";
        $msg .= "• Přinesu…\n";
        $msg .= "• Udělám…\n";

        sendMessage($chat_id, $msg);
        exit;

    } else {

        $msg = "ℹ️ *Nápověda*\n\n";
        $msg .= "Co můžeš dělat:\n";
        $msg .= "• Zjistit události (tlačítko)\n";
        $msg .= "• Vybrat událost a potvrdit účast:\n";
        $msg .= "   – Přijdu\n";
        $msg .= "   – Nepřijdu\n";
        $msg .= "   – Přinesu…\n";
        $msg .= "   – Udělám…\n\n";
        $msg .= "Příkazy:\n";
        $msg .= "• /events – zobrazí seznam událostí\n";
        $msg .= "• /help – zobrazí tuto nápovědu\n";

        sendMessage($chat_id, $msg);
        exit;
    }
}


// ==========================
//  DEFAULT
// ==========================
sendMessage($chat_id, "Použij: /events nebo /event <id>");

?>