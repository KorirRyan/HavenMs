<?php
require_once 'finance_api_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    finance_json_error('Method not allowed', 405);
}

$studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$receiptNumber = isset($_POST['receipt_number']) ? trim((string) $_POST['receipt_number']) : '';
$paymentDate = isset($_POST['payment_date']) ? trim((string) $_POST['payment_date']) : '';
$status = isset($_POST['status']) ? trim((string) $_POST['status']) : '';

if ($studentId <= 0) {
    finance_json_error('Student is required');
}
if ($amount <= 0) {
    finance_json_error('Amount must be greater than zero');
}
if ($receiptNumber === '') {
    finance_json_error('Receipt number is required');
}
if ($paymentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
    finance_json_error('A valid payment date is required');
}
if (!in_array($status, ['Paid', 'Unpaid'], true)) {
    finance_json_error('Invalid payment status');
}

$studentStmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
if (!$studentStmt) {
    finance_json_error('Failed to validate student', 500);
}
$studentStmt->bind_param('i', $studentId);
$studentStmt->execute();
$studentResult = $studentStmt->get_result();
if ($studentResult->num_rows === 0) {
    $studentStmt->close();
    finance_json_error('Student not found', 404);
}
$studentStmt->close();

$receiptStmt = $conn->prepare("SELECT payment_id FROM payment WHERE receipt_number = ?");
if (!$receiptStmt) {
    finance_json_error('Failed to validate receipt number', 500);
}
$receiptStmt->bind_param('s', $receiptNumber);
$receiptStmt->execute();
$receiptResult = $receiptStmt->get_result();
if ($receiptResult->num_rows > 0) {
    $receiptStmt->close();
    finance_json_error('Receipt number already exists', 409);
}
$receiptStmt->close();

$conn->begin_transaction();

try {
    $nextIdResult = $conn->query("SELECT COALESCE(MAX(payment_id), 0) + 1 AS next_id FROM payment FOR UPDATE");
    if (!$nextIdResult) {
        throw new RuntimeException('Failed to generate payment id');
    }

    $nextIdRow = $nextIdResult->fetch_assoc();
    $nextId = (int) $nextIdRow['next_id'];

    $insertStmt = $conn->prepare(
        "INSERT INTO payment (payment_id, student_id, amount, receipt_number, payment_date, status)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$insertStmt) {
        throw new RuntimeException('Failed to prepare payment insert');
    }

    $insertStmt->bind_param('iidsss', $nextId, $studentId, $amount, $receiptNumber, $paymentDate, $status);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        throw new RuntimeException('Failed to create payment');
    }
    $insertStmt->close();

    $conn->commit();

    finance_json_success([
        'message' => 'Payment created successfully',
        'payment_id' => $nextId,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    finance_json_error($e->getMessage(), 500);
}
