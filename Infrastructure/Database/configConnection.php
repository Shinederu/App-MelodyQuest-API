<?php
// MelodyQuest DB configuration from environment variables
// Configure these env vars in PHP-FPM/Nginx/container runtime.
define('DB_TYPE', getenv('MQ_DB_TYPE') ?: 'mysql');
define('DB_HOST', getenv('MQ_DB_HOST') ?: 'mysql-server');
define('DB_NAME', getenv('MQ_DB_NAME') ?: 'MelodyQuest');
define('DB_USER', getenv('MQ_DB_USER') ?: 'MelodyQuestUser');
define('DB_PASS', getenv('MQ_DB_PASS') ?: 'change_me');
define('DB_PORT', getenv('MQ_DB_PORT') ?: '3306');
?>
