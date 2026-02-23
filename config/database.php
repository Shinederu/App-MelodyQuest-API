<?php

define('DB_TYPE', $_ENV['MQ_DB_TYPE'] ?? $_ENV['DB_TYPE'] ?? 'mysql');
define('DB_HOST', $_ENV['MQ_DB_HOST'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1');
define('DB_USER', $_ENV['MQ_DB_USER'] ?? $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['MQ_DB_PASS'] ?? $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['MQ_DB_NAME'] ?? $_ENV['DB_NAME'] ?? 'ShinedeCore');
define('DB_PORT', $_ENV['MQ_DB_PORT'] ?? $_ENV['DB_PORT'] ?? '3306');
