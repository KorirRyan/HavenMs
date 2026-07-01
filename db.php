<?php
// db.php
$host = "localhost";
$user = "root";         // your DB username
$pass = "";             // your DB password
$db   = "havendb";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!function_exists('db_column_exists')) {
    function db_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table = str_replace('`', '``', $table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
?>
