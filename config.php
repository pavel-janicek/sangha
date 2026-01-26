<?php
// Block direct access
if (!defined('ALLOW_ACCESS')) {
    http_response_code(403); // Forbidden
    exit('Access denied.');
}

return [ 'db_host' => 'localhost', 
'db_name' => 'chlebickybot', 
'db_user' => 'UZIVATEL', 
'db_pass' => 'HESLO', 
'bot_token' => 'TVŮJ_TELEGRAM_TOKEN' ];