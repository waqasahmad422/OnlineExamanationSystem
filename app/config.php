<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'gdcekkghund');
define('DB_SOCKET', './tmp/mysql.sock');

define('SITE_URL', 'http://localhost');
define('BASE_PATH', dirname(__DIR__));

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, null, DB_SOCKET);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

define('PRIMARY_COLOR', '#025F11');
define('MAX_LOGIN_ATTEMPTS', 5);
define('AUTO_SAVE_INTERVAL', 35000);

function close_connection() {
    global $conn;
    if ($conn) {
        mysqli_close($conn);
    }
}

register_shutdown_function('close_connection');
?>
