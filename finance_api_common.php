<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'db.php';

if (!isset($conn) || $conn->connect_error) {
    finance_json_error('Database connection failed', 500);
}

function finance_json_success(array $data = []): void
{
    echo json_encode(array_merge(['status' => 'success'], $data));
    exit;
}

function finance_json_error(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    echo json_encode([
        'status' => 'error',
        'message' => $message,
    ]);
    exit;
}

function finance_normalize_payment_row(array $row): array
{
    return [
        'payment_id' => (int) $row['payment_id'],
        'student_id' => (int) $row['student_id'],
        'student_name' => (string) ($row['student_name'] ?? ''),
        'course' => (string) ($row['course'] ?? ''),
        'year_of_study' => isset($row['year_of_study']) ? (int) $row['year_of_study'] : null,
        'amount' => (float) $row['amount'],
        'receipt_number' => (string) $row['receipt_number'],
        'payment_date' => (string) $row['payment_date'],
        'status' => (string) $row['status'],
    ];
}

function finance_fetch_students(mysqli $conn): array
{
    $sql = "SELECT student_id, name, course, year_of_study, gender, phone_no, email, status
            FROM students
            ORDER BY name ASC";
    $result = $conn->query($sql);
    if (!$result) {
        finance_json_error('Failed to fetch students', 500);
    }

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_id' => (int) $row['student_id'],
            'name' => (string) $row['name'],
            'course' => (string) $row['course'],
            'year_of_study' => isset($row['year_of_study']) ? (int) $row['year_of_study'] : null,
            'gender' => (string) $row['gender'],
            'phone_no' => (string) ($row['phone_no'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'status' => (string) $row['status'],
        ];
    }

    return $students;
}

function finance_fetch_payments(mysqli $conn): array
{
    $sql = "SELECT
                p.payment_id,
                p.student_id,
                s.name AS student_name,
                s.course,
                s.year_of_study,
                p.amount,
                p.receipt_number,
                p.payment_date,
                p.status
            FROM payment p
            INNER JOIN students s ON s.student_id = p.student_id
            ORDER BY p.payment_date DESC, p.payment_id DESC";
    $result = $conn->query($sql);
    if (!$result) {
        finance_json_error('Failed to fetch payments', 500);
    }

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = finance_normalize_payment_row($row);
    }

    return $payments;
}

function finance_fetch_unpaid_students(mysqli $conn): array
{
    $sql = "SELECT
                s.student_id,
                s.name,
                s.course,
                s.year_of_study,
                s.gender,
                s.phone_no,
                s.email,
                CASE
                    WHEN EXISTS (
                        SELECT 1 FROM payment p_paid
                        WHERE p_paid.student_id = s.student_id
                        AND p_paid.status = 'Paid'
                    ) THEN 'Paid'
                    WHEN EXISTS (
                        SELECT 1 FROM payment p_any
                        WHERE p_any.student_id = s.student_id
                    ) THEN 'Unpaid'
                    ELSE 'No Record'
                END AS payment_status
            FROM students s
            WHERE NOT EXISTS (
                SELECT 1 FROM payment p
                WHERE p.student_id = s.student_id
                AND p.status = 'Paid'
            )
            ORDER BY s.name ASC";
    $result = $conn->query($sql);
    if (!$result) {
        finance_json_error('Failed to fetch unpaid students', 500);
    }

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_id' => (int) $row['student_id'],
            'name' => (string) $row['name'],
            'course' => (string) $row['course'],
            'year_of_study' => isset($row['year_of_study']) ? (int) $row['year_of_study'] : null,
            'gender' => (string) $row['gender'],
            'phone_no' => (string) ($row['phone_no'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'payment_status' => (string) $row['payment_status'],
        ];
    }

    return $students;
}
