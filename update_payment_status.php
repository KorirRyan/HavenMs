<?php
require_once 'finance_api_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    finance_json_error('Method not allowed', 405);
}

$paymentId = isset($_POST['payment_id']) ? (int) $_POST['payment_id'] : 0;

if ($paymentId <= 0) {
    finance_json_error('Payment id is required');
}

$stmt = $conn->prepare("UPDATE payment SET status = 'Paid' WHERE payment_id = ? AND status <> 'Paid'");
if (!$stmt) {
    finance_json_error('Failed to prepare payment update', 500);
}

$stmt->bind_param('i', $paymentId);
if (!$stmt->execute()) {
    $stmt->close();
    finance_json_error('Failed to update payment status', 500);
}

$affectedRows = $stmt->affected_rows;
$stmt->close();

if ($affectedRows === 0) {
    $checkStmt = $conn->prepare("SELECT payment_id FROM payment WHERE payment_id = ?");
    if (!$checkStmt) {
        finance_json_error('Failed to verify payment record', 500);
    }
    $checkStmt->bind_param('i', $paymentId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $exists = $checkResult->num_rows > 0;
    $checkStmt->close();

    if (!$exists) {
        finance_json_error('Payment not found', 404);
    }
}

finance_json_success([
    'message' => 'Payment status updated successfully',
]);
