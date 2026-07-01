<?php
session_start();
header('Content-Type: application/json');

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'havendb';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

if (!function_exists('login_column_exists')) {
    function login_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table = str_replace('`', '``', $table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}

$email    = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
$role     = isset($_POST['role']) ? strtolower(trim((string) $_POST['role'])) : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email']);
    exit;
}
if ($password === '') {
    echo json_encode(['status' => 'error', 'message' => 'Password is required']);
    exit;
}
if ($role === '') {
    echo json_encode(['status' => 'error', 'message' => 'Role is required']);
    exit;
}
if (!in_array($role, ['student', 'admin', 'finance', 'cleaner'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid role']);
    exit;
}

// Students are in the students table, staff are in the users table
if ($role === 'student') {
    $stmt = $conn->prepare("SELECT student_id AS id, name, email, password FROM students WHERE email = ?");
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('s', $email);
} else {
    // admin, finance, cleaner — query users table with role check
    $staffNameSelect = login_column_exists($conn, 'users', 'name') ? 'name, ' : '';
    $stmt = $conn->prepare("SELECT user_id AS id, {$staffNameSelect}email, password, LOWER(role) AS role FROM users WHERE LOWER(email) = LOWER(?) AND LOWER(role) = ?");
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param('ss', $email, $role);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No account found with that email and role']);
    exit;
}

$row = $result->fetch_assoc();
$dbPassword = isset($row['password']) ? (string) $row['password'] : '';

if (!password_verify($password, $dbPassword)) {
    echo json_encode(['status' => 'error', 'message' => 'Incorrect password']);
    exit;
}

// AFTER
$_SESSION['user'] = [
    'id'    => (int) $row['id'],
    'name'  => (string) ($row['name'] ?? $row['email']),
    'email' => (string) $row['email'],
    'role'  => $role
];

echo json_encode([
    'status' => 'success',
    'user'   => $_SESSION['user']
]);

$stmt->close();
$conn->close();
?>
