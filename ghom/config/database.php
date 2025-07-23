<?php
// config/database.php


'connections' => [

// ... (sqlite, pgsql, sqlsrv connections might be here) ...

'mysql' => [ // This is the default connection, using DB_* from .env
'driver' => 'mysql',
'url' => env('DATABASE_URL'),
'host' => env('DB_HOST', '127.0.0.1'),
'port' => env('DB_PORT', '3306'),
'database' => env('DB_DATABASE', 'forge'),
'username' => env('DB_USERNAME', 'forge'),
'password' => env('DB_PASSWORD', ''),
'unix_socket' => env('DB_SOCKET', ''),
'charset' => 'utf8mb4',
'collation' => 'utf8mb4_unicode_ci',
'prefix' => '',
'prefix_indexes' => true,
'strict' => true,
'engine' => null,
'options' => extension_loaded('pdo_mysql') ? array_filter([
PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
]) : [],
],

'projects_db' => [ // Our new custom connection
'driver' => env('DB_CONNECTION_PROJECTS', 'mysql'), // Read driver from .env
'url' => env('DATABASE_URL_PROJECTS'), // Optional URL if you prefer
'host' => env('DB_HOST_PROJECTS', '127.0.0.1'),
'port' => env('DB_PORT_PROJECTS', '3306'),
'database' => env('DB_DATABASE_PROJECTS', 'projects_db_default'), // Default if not in .env
'username' => env('DB_USERNAME_PROJECTS', 'project_user_default'),
'password' => env('DB_PASSWORD_PROJECTS', ''),
'unix_socket' => env('DB_SOCKET_PROJECTS', ''),
'charset' => 'utf8mb4',
'collation' => 'utf8mb4_unicode_ci',
'prefix' => '',
'prefix_indexes' => true,
'strict' => true,
'engine' => null,
'options' => extension_loaded('pdo_mysql') ? array_filter([
PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA_PROJECTS'),
]) : [],
],

],

php?>