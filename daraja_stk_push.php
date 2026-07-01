<?php
require_once 'daraja_payment_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    daraja_json_error('Method not allowed', 405);
}

$missingConfig = daraja_required_config_missing($darajaConfig);
if ($missingConfig !== []) {
    daraja_json_error(
        'Daraja is not configured yet. Fill daraja_config.php first.',
        400,
        ['missing_config' => $missingConfig]
    );
}

$studentId = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
$phoneInput = isset($_POST['phone_number']) ? trim((string) $_POST['phone_number']) : '';

if ($studentId <= 0) {
    daraja_json_error('Student id is required');
}

$student = daraja_fetch_student_by_id($conn, $studentId);
if ($student === null) {
    daraja_json_error('Student not found', 404);
}

$studentStatus = strtolower((string) ($student['status'] ?? 'pending'));
if ($studentStatus !== 'approved') {
    daraja_json_error('Hostel payment is only available after the room booking is approved.');
}

$payments = daraja_fetch_student_payments($conn, $studentId);
$balance = max(daraja_hostel_fee($darajaConfig) - daraja_total_paid($payments), 0);
if ($balance <= 0) {
    daraja_json_error('This hostel fee has already been fully paid.');
}

$normalizedPhone = daraja_normalize_phone($phoneInput !== '' ? $phoneInput : (string) ($student['phone_no'] ?? ''));
if ($normalizedPhone === null) {
    daraja_json_error('Enter a valid Safaricom M-Pesa phone number.');
}

$latestTransaction = daraja_fetch_latest_transaction($conn, $studentId);
if (
    $latestTransaction !== null &&
    $latestTransaction['status'] === 'Pending' &&
    strtotime($latestTransaction['requested_at']) !== false &&
    strtotime($latestTransaction['requested_at']) > strtotime('-5 minutes')
) {
    daraja_json_error('A payment prompt is already pending on your phone. Please complete it or wait a few minutes.');
}

$timestamp = daraja_timestamp();
$token = daraja_build_access_token($darajaConfig);
$accountReference = 'HOSTEL-' . $studentId;
$transactionDesc = 'Hostel booking fee for student #' . $studentId;

$payload = [
    'BusinessShortCode' => trim((string) $darajaConfig['shortcode']),
    'Password' => daraja_password($darajaConfig, $timestamp),
    'Timestamp' => $timestamp,
    'TransactionType' => trim((string) $darajaConfig['transaction_type']),
    'Amount' => (int) round($balance),
    'PartyA' => $normalizedPhone,
    'PartyB' => trim((string) $darajaConfig['shortcode']),
    'PhoneNumber' => $normalizedPhone,
    'CallBackURL' => trim((string) $darajaConfig['callback_url']),
    'AccountReference' => $accountReference,
    'TransactionDesc' => $transactionDesc,
];

$response = daraja_post_json(
    rtrim((string) $darajaConfig['base_url'], '/') . '/mpesa/stkpush/v1/processrequest',
    $payload,
    $token
);

$body = $response['body'];
if (($response['status_code'] ?? 500) >= 400 || !isset($body['ResponseCode'])) {
    daraja_json_error('Daraja rejected the payment request.', 502, ['details' => $body]);
}

if ((string) $body['ResponseCode'] !== '0') {
    daraja_json_error(
        (string) ($body['errorMessage'] ?? $body['ResponseDescription'] ?? 'Unable to start M-Pesa payment.'),
        400,
        ['details' => $body]
    );
}

daraja_ensure_transactions_table($conn);

$insertStmt = $conn->prepare(
    "INSERT INTO mpesa_transactions (
        student_id, amount, phone_number, merchant_request_id, checkout_request_id,
        account_reference, transaction_desc, status, response_code, response_description
     ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)"
);
if (!$insertStmt) {
    daraja_json_error('Failed to save M-Pesa request', 500);
}

$amount = (float) round($balance, 2);
$merchantRequestId = (string) ($body['MerchantRequestID'] ?? '');
$checkoutRequestId = (string) ($body['CheckoutRequestID'] ?? '');
$responseCode = (string) ($body['ResponseCode'] ?? '');
$responseDescription = (string) ($body['ResponseDescription'] ?? '');
$insertStmt->bind_param(
    'idsssssss',
    $studentId,
    $amount,
    $normalizedPhone,
    $merchantRequestId,
    $checkoutRequestId,
    $accountReference,
    $transactionDesc,
    $responseCode,
    $responseDescription
);

if (!$insertStmt->execute()) {
    $insertStmt->close();
    daraja_json_error('Failed to save M-Pesa request', 500);
}

$transactionId = (int) $insertStmt->insert_id;
$insertStmt->close();

daraja_json_success([
    'message' => (string) ($body['CustomerMessage'] ?? 'M-Pesa prompt sent successfully.'),
    'transaction_id' => $transactionId,
    'checkout_request_id' => $checkoutRequestId,
    'phone_number' => $normalizedPhone,
    'amount' => $amount,
]);
