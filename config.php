<?php
// Block direct access
if (!defined('ALLOW_ACCESS')) {
    http_response_code(403); // Forbidden
    exit('Access denied.');
}

// Sem pridej realny kody.
$BOT_TOKEN = "SEM_DEJ_TOKEN";

// Database configuration
$db_config = [
    'host' => 'localhost',
    'dbname' => 'dbname',
    'user' => 'dbuser',
    'password' => 'dbpassword'
];