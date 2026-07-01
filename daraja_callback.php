<?php
require_once 'daraja_payment_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    daraja_json_error('Method not allowed', 405);
}

daraja_ensure_transactions_table($conn);

$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload, true);

if (!is_array($payload)) {
    daraja_json_error('Invalid callback payload', 400);
}

$callback = $payload['Body']['stkCallback'] ?? null;
if (!is_array($callback)) {
    daraja_json_error('Invalid callback structure', 400);
}

$checkoutRequestId = isset($callback['CheckoutRequestID']) ? (string) $callback['CheckoutRequestID'] : '';
if ($checkoutRequestId === '') {
    daraja_json_error('Missing CheckoutRequestID', 400);
}

$selectStmt = $conn->prepare(
    "SELECT transaction_id, student_id, amount
     FROM mpesa_transactions
     WHERE checkout_request_id = ?
     LIMIT 1"
);
if (!$selectStmt) {
    daraja_json_error('Failed to load M-Pesa transaction', 500);
}

$selectStmt->bind_param('s', $checkoutRequestId);
$selectStmt->execute();
$transactionResult = $selectStmt->get_result();
$transactionRow = $transactionResult->fetch_assoc() ?: null;
$selectStmt->close();

if ($transactionRow === null) {
    daraja_json_error('Transaction not found', 404);
}

$metadata = daraja_parse_callback_metadata($callback['CallbackMetadata']['Item'] ?? null);
$resultCode = isset($callback['ResultCode']) ? (string) $callback['ResultCode'] : null;
$resultDesc = isset($callback['ResultDesc']) ? (string) $callback['ResultDesc'] : null;
$receiptNumber = isset($metadata['MpesaReceiptNumber']) ? (string) $metadata['MpesaReceiptNumber'] : null;
$phoneNumber = isset($metadata['PhoneNumber']) ? (string) $metadata['PhoneNumber'] : null;
$amount = isset($metadata['Amount']) ? (float) $metadata['Amount'] : (float) $transactionRow['amount'];
$transactionDate = $metadata['TransactionDate'] ?? null;
$status = $resultCode === '0' ? 'Paid' : 'Failed';

$conn->begin_transaction();

$updateStmt = $conn->prepare(
    "UPDATE mpesa_transactions
     SET phone_number = ?, amount = ?, status = ?, result_code = ?, result_desc = ?,
         receipt_number = ?, callback_payload = ?, completed_at = NOW()
     WHERE transaction_id = ?"
);
if (!$updateStmt) {
    $conn->rollback();
    daraja_json_error('Failed to update M-Pesa transaction', 500);
}

$phoneForSave = $phoneNumber !== null ? (string) $phoneNumber : (string) ($transactionRow['phone_number'] ?? '');
$receiptForSave = $receiptNumber !== null ? (string) $receiptNumber : null;
$callbackPayload = json_encode($payload);
$transactionId = (int) $transactionRow['transaction_id'];
$updateStmt->bind_param(
    'sdsssssi',
    $phoneForSave,
    $amount,
    $status,
    $resultCode,
    $resultDesc,
    $receiptForSave,
    $callbackPayload,
    $transactionId
);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->rollback();
    daraja_json_error('Failed to update M-Pesa transaction', 500);
}
$updateStmt->close();

if ($status === 'Paid') {
    daraja_sync_successful_payment($conn, [
        'student_id' => (int) $transactionRow['student_id'],
        'amount' => $amount,
        'receipt_number' => $receiptNumber,
        'transaction_date' => $transactionDate,
    ]);
}

$conn->commit();

daraja_json_success([
    'message' => 'Callback received successfully',
]);
