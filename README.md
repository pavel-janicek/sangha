# Telegram bot na zápis lidí na akci (sanghu)

Pokus o Telegram bota, který umožní lidem registrovat se na akci. Je psán "pro interní potřeby" protože potřebujeme vědět:

- Kdo přijde
- Kdo nepřijde
- Co udělá
- A mít možnost více akcí najednou

## Běžné použití

### Admin
1. Admin přidá bota do telegramové skupiny a dá mu práva na čtení
2. Přes příkaz "/addevent" přidá novou událost, kterou pojmenuje
3. K dispozici má pak ještě příkaz "/events" který mu vypíše dostupné události a u každé bude možnost vybrat (níže) a Status
4. Po kliknutí na Status uvidí přehled dané události s tím, kdo přijde a tak
5. Zde má tlačítko "Uzavřít událost" a tím znepřístupnit akci pro všechny

### Uživatel
1. Uživatel může napsat "/help" kde uvidí, že má k dispozici v zásadě jeden další příkaz
2. Tím je opět příkaz "/events" který mu vypíše dostupné události a u každé bude možnost vybrat.
3. Po kliknutí na tlačítko "Vybrat" má uživatel 4 další tlačítka:
    - Přijdu: Uživatel se objeví na akci
    - Nepřijdu: Uživatel nedorazí
    - Přinesu...: Bot se zeptá, co uživatel přinese a ten pak volným textem odpoví (třeba "chlebíčky"). Bot si to zapíše
    - Udělám...: Taktéž volným textem otázka - odpověď

## Instalace:
(Tohle jsem si nechal napsat od AI)

### 🛠️ 1. Získání bot tokenu

Otevři Telegram a najdi BotFather  
→ napiš `@BotFather` do vyhledávání

Napiš mu:

    /newbot

Zadej:

- název bota (např. „SanghaBot“)

- uživatelské jméno bota (musí končit na bot, např. sanghabot)

BotFather ti pošle:
    

    Done! Congratulations...
    Use this token to access the HTTP API:
    123456789:ABCdefGhIjKlMnOpQrStUvWxYz

👉 Tohle je tvůj bot token — ulož si ho, budeš ho používat v PHP.

### 🌐 2. Nastavení webhooku

Webhook říká Telegramu, kam má posílat zprávy od uživatelů.

Spustíš to jedním HTTP požadavkem:
bash
```
curl -X POST https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook \
     -d url=https://tvujserver.cz/cesta/k/webhooku.php
```     

Nebo v PHP:
```

$token = '123456789:ABCdefGhIjKlMnOpQrStUvWxYz';
$webhook_url = 'https://tvujserver.cz/webhook.php';

file_get_contents("https://api.telegram.org/bot$token/setWebhook?url=$webhook_url");
```

### 💽 3. Nastavení databáze
Na svém serveru si v PHPMyAdmin spusť soubor `sql_setup.sql`, vytvoří ti tabulky v databázi

### 🤫 4. Nastavení secrets
Otevři si ve svém oblíbeném textovém editoru soubor `config.php` a nastav reálné hodnoty na přístup k databázi a token. Jinak ti to nebude fungovat.

Soubor pak nahraj **do stejného adresáře** na server, jako je `webhook.php`

### 📥 5. Co se děje dál

Telegram začne posílat JSON zprávy na webhook.php

Vyhledej svého bota a napiš mu příkaz `/start`. Měl by tě  nastavit jako admina a poslat ti o tom potvrzení.


### 🧠 Bonus: ověření webhooku

Zkontroluj, co je nastaveno:
```

curl https://api.telegram.org/bot<YOUR_TOKEN>/getWebhookInfo

```

Vrátí ti:
```


{
  "ok": true,
  "result": {
    "url": "https://tvujserver.cz/webhook.php",
    "has_custom_certificate": false,
    "pending_update_count": 0,
    ...
  }
}
```


## Závěrem
Kód je psaný za značné pomoci AI botů a v češtině (co se zpráv týče)

Dlouhodobý plán je poskytnout možnost překladů

Asi nemusím říkat, že použití je na vlastní nebezpečí. Žejo.

Že jo?!