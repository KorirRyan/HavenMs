<?php
require_once 'finance_api_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    finance_json_error('Method not allowed', 405);
}

$paymentId = isset($_POST['payment_id']) ? (int) $_POST['payment_id'] : 0;

if ($paymentId <= 0) {
    finance_json_error('Payment id is required');
}

$stmt = $conn->prepare("DELETE FROM payment WHERE payment_id = ?");
if (!$stmt) {
    finance_json_error('Failed to prepare delete statement', 500);
}

$stmt->bind_param('i', $paymentId);
if (!$stmt->execute()) {
    $stmt->close();
    finance_json_error('Failed to delete payment', 500);
}

$deletedRows = $stmt->affected_rows;
$stmt->close();

if ($deletedRows === 0) {
    finance_json_error('Payment not found', 404);
}

finance_json_success([
    'message' => 'Payment deleted successfully',
]);
