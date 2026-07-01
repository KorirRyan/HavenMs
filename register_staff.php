<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo "Database connection failed";
    exit;
}

$role = isset($_POST['role']) ? strtolower(trim((string) $_POST['role'])) : '';
$firstName = isset($_POST['first_name']) ? trim((string) $_POST['first_name']) : '';
$lastName = isset($_POST['last_name']) ? trim((string) $_POST['last_name']) : '';
$employeeId = isset($_POST['employee_id']) ? trim((string) $_POST['employee_id']) : '';
$email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim((string) $_POST['phone']) : '';
$passwordRaw = isset($_POST['password']) ? (string) $_POST['password'] : '';

if (!in_array($role, ['admin', 'finance', 'cleaner'], true)) {
    http_response_code(400);
    echo "Invalid role selected.";
    exit;
}
if ($firstName === '' || $lastName === '') {
    http_response_code(400);
    echo "First name and last name are required.";
    exit;
}
if ($employeeId === '') {
    http_response_code(400);
    echo "Staff / Employee ID is required.";
    exit;
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo "A valid email address is required.";
    exit;
}
if (strlen($passwordRaw) < 8) {
    http_response_code(400);
    echo "Password must be at least 8 characters.";
    exit;
}

$roleMap = [
    'admin' => 'Admin',
    'finance' => 'Finance',
    'cleaner' => 'Cleaner',
];
$fullName = trim($firstName . ' ' . $lastName);

$checkStmt = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?)");
if (!$checkStmt) {
    http_response_code(500);
    echo "Failed to validate existing account.";
    exit;
}
$checkStmt->bind_param('s', $email);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
if ($checkResult->num_rows > 0) {
    $checkStmt->close();
    http_response_code(409);
    echo "An account with this email already exists.";
    exit;
}
$checkStmt->close();

$conn->begin_transaction();

try {
    $nextIdResult = $conn->query("SELECT COALESCE(MAX(user_id), 0) + 1 AS next_id FROM users FOR UPDATE");
    if (!$nextIdResult) {
        throw new RuntimeException('Failed to generate user id.');
    }

    $nextIdRow = $nextIdResult->fetch_assoc();
    $nextId = (int) $nextIdRow['next_id'];
    $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);
    $dbRole = $roleMap[$role];

    if (db_column_exists($conn, 'users', 'name')) {
        $insertStmt = $conn->prepare("INSERT INTO users (user_id, name, email, password, role) VALUES (?, ?, ?, ?, ?)");
        if (!$insertStmt) {
            throw new RuntimeException('Failed to prepare staff insert.');
        }
        $insertStmt->bind_param('issss', $nextId, $fullName, $email, $passwordHash, $dbRole);
    } else {
        $insertStmt = $conn->prepare("INSERT INTO users (user_id, email, password, role) VALUES (?, ?, ?, ?)");
        if (!$insertStmt) {
            throw new RuntimeException('Failed to prepare staff insert.');
        }
        $insertStmt->bind_param('isss', $nextId, $email, $passwordHash, $dbRole);
    }

    if (!$insertStmt->execute()) {
        $insertStmt->close();
        throw new RuntimeException('Failed to create staff account.');
    }
    $insertStmt->close();

    $conn->commit();
    echo "success";
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo $e->getMessage();
}

$conn->close();
?>
