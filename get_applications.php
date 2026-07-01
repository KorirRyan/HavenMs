<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode([]);
    exit;
}

$result = $conn->query("SELECT student_id, name, email, course, year_of_study, gender, hostel_id, status, room_number, rejection_reason, approved_at FROM students ORDER BY student_id DESC");

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

echo json_encode($students);
$conn->close();
?>