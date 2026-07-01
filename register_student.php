<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo "Database connection failed: " . ($conn->connect_error ?? 'db.php did not provide $conn');
    exit;
}

$required = ['name', 'email', 'password', 'course', 'year', 'gender', 'hostel'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        http_response_code(400);
        echo "Missing required field: $field";
        exit;
    }
}

$name          = trim($_POST['name']);
$email         = trim($_POST['email']);
$password      = password_hash($_POST['password'], PASSWORD_DEFAULT);
$course        = trim($_POST['course']);
$year_of_study = (int) trim($_POST['year']);
$gender        = trim($_POST['gender']);
$hostel_id     = trim($_POST['hostel']);
$phone_no      = trim($_POST['phone'] ?? '');

$stmt = $conn->prepare("SELECT student_id FROM students WHERE email=?");
if (!$stmt) {
    http_response_code(500);
    echo "Prepare failed: " . $conn->error;
    exit;
}
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    http_response_code(409);
    echo "A student with this email already exists.";
    exit;
}
$stmt->close();

$stmt = $conn->prepare("INSERT INTO students (name, email, password, course, year_of_study, gender, phone_no, hostel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo "Prepare failed: " . $conn->error;
    exit;
}
$stmt->bind_param("ssssisss", $name, $email, $password, $course, $year_of_study, $gender, $phone_no, $hostel_id);

if ($stmt->execute()) {
    echo "success";
} else {
    http_response_code(500);
    echo "Insert failed: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>