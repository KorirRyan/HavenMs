<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
include 'db.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get email from POST
$email = isset($_POST['email']) ? trim($_POST['email']) : (isset($_GET['email']) ? trim($_GET['email']) : '');

if (!$email) {
    echo json_encode(['error' => 'No email provided']);
    exit;
}

$hasFloorNumber = db_column_exists($conn, 'students', 'floor_number');
$fields = "student_id, name, email, course, year_of_study, gender, phone_no, hostel_id, status, room_number, rejection_reason, approved_at";
if ($hasFloorNumber) {
    $fields .= ", floor_number";
}

$stmt = $conn->prepare("SELECT {$fields} FROM students WHERE email = ?");
if (!$stmt) {
    echo json_encode(['error' => 'Failed to prepare student query']);
    exit;
}
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

if (!$student) {
    echo json_encode(['error' => 'Student not found']);
    exit;
}

if (!$hasFloorNumber) {
    $student['floor_number'] = null;
}

echo json_encode($student);
$conn->close();
?>
