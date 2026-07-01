<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
    ]);
    exit;
}

$hasUserName = db_column_exists($conn, 'users', 'name');
$userNameSelect = $hasUserName ? 'name' : 'NULL AS name';

$metrics = [
    'total_users' => 0,
    'total_students' => 0,
    'total_staff' => 0,
    'admin_count' => 0,
    'finance_count' => 0,
    'cleaner_count' => 0,
    'pending_students' => 0,
    'approved_students' => 0,
    'rejected_students' => 0,
];

$users = [];

$studentSql = "SELECT student_id, name, email, course, year_of_study, gender, hostel_id, status, room_number, approved_at
               FROM students
               ORDER BY name ASC";
$studentResult = $conn->query($studentSql);
if (!$studentResult) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to load students',
    ]);
    exit;
}

while ($row = $studentResult->fetch_assoc()) {
    $status = strtolower((string) $row['status']);

    $users[] = [
        'id' => (int) $row['student_id'],
        'name' => (string) $row['name'],
        'email' => (string) ($row['email'] ?? ''),
        'role' => 'student',
        'source' => 'students',
        'status' => $status,
        'course' => (string) ($row['course'] ?? ''),
        'year_of_study' => isset($row['year_of_study']) ? (int) $row['year_of_study'] : null,
        'gender' => (string) ($row['gender'] ?? ''),
        'hostel_id' => (string) ($row['hostel_id'] ?? ''),
        'room_number' => (string) ($row['room_number'] ?? ''),
        'approved_at' => $row['approved_at'] ?: null,
    ];

    $metrics['total_students']++;
    $metrics['total_users']++;
    if ($status === 'approved') {
        $metrics['approved_students']++;
    } elseif ($status === 'rejected') {
        $metrics['rejected_students']++;
    } else {
        $metrics['pending_students']++;
    }
}

$staffSql = "SELECT user_id, {$userNameSelect}, email, LOWER(role) AS role
             FROM users
             WHERE LOWER(role) IN ('admin', 'finance', 'cleaner')
             ORDER BY COALESCE(name, email) ASC";
$staffResult = $conn->query($staffSql);
if (!$staffResult) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to load staff users',
    ]);
    exit;
}

while ($row = $staffResult->fetch_assoc()) {
    $role = strtolower((string) $row['role']);
    $displayName = trim((string) ($row['name'] ?? ''));
    if ($displayName === '') {
        $displayName = (string) $row['email'];
    }

    $users[] = [
        'id' => (int) $row['user_id'],
        'name' => $displayName,
        'email' => (string) ($row['email'] ?? ''),
        'role' => $role,
        'source' => 'staff',
        'status' => 'active',
        'course' => null,
        'year_of_study' => null,
        'gender' => null,
        'hostel_id' => null,
        'room_number' => null,
        'approved_at' => null,
    ];

    $metrics['total_staff']++;
    $metrics['total_users']++;

    if ($role === 'admin') {
        $metrics['admin_count']++;
    } elseif ($role === 'finance') {
        $metrics['finance_count']++;
    } elseif ($role === 'cleaner') {
        $metrics['cleaner_count']++;
    }
}

usort($users, function (array $a, array $b): int {
    $nameCompare = strcasecmp((string) $a['name'], (string) $b['name']);
    if ($nameCompare !== 0) {
        return $nameCompare;
    }

    return strcmp((string) $a['role'], (string) $b['role']);
});

echo json_encode([
    'status' => 'success',
    'metrics' => $metrics,
    'users' => $users,
]);

$conn->close();
?>
