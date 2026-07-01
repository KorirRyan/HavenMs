<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'db.php';

if (!isset($conn) || $conn->connect_error) {
    daraja_json_error('Database connection failed', 500);
}

$darajaConfig = require __DIR__ . '/daraja_config.php';

function daraja_json_success(array $data = []): void
{
    echo json_encode(array_merge(['status' => 'success'], $data));
    exit;
}

function daraja_json_error(string $message, int $statusCode = 400, array $data = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'status' => 'error',
        'message' => $message,
    ], $data));
    exit;
}

function daraja_required_config_missing(array $config): array
{
    $required = ['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url'];
    $missing = [];

    foreach ($required as $key) {
        if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
            $missing[] = $key;
        }
    }

    return $missing;
}

function daraja_is_configured(array $config): bool
{
    return daraja_required_config_missing($config) === [];
}

function daraja_hostel_fee(array $config): float
{
    return isset($config['hostel_fee']) ? (float) $config['hostel_fee'] : 15000.0;
}

function daraja_normalize_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', trim($phone));
    if ($digits === '') {
        return null;
    }

    if (strpos($digits, '254') === 0 && strlen($digits) === 12) {
        return $digits;
    }

    if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
        return '254' . substr($digits, 1);
    }

    if (strpos($digits, '7') === 0 && strlen($digits) === 9) {
        return '254' . $digits;
    }

    if (strpos($digits, '1') === 0 && strlen($digits) === 9) {
        return '254' . $digits;
    }

    return null;
}

function daraja_timestamp(): string
{
    return date('YmdHis');
}

function daraja_password(array $config, string $timestamp): string
{
    return base64_encode(trim((string) $config['shortcode']) . trim((string) $config['passkey']) . $timestamp);
}

function daraja_ensure_transactions_table(mysqli $conn): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS mpesa_transactions (
    transaction_id INT NOT NULL AUTO_INCREMENT,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    merchant_request_id VARCHAR(100) DEFAULT NULL,
    checkout_request_id VARCHAR(100) DEFAULT NULL,
    account_reference VARCHAR(100) DEFAULT NULL,
    transaction_desc VARCHAR(255) DEFAULT NULL,
    status ENUM('Pending','Paid','Failed') NOT NULL DEFAULT 'Pending',
    response_code VARCHAR(20) DEFAULT NULL,
    response_description VARCHAR(255) DEFAULT NULL,
    result_code VARCHAR(20) DEFAULT NULL,
    result_desc TEXT DEFAULT NULL,
    receipt_number VARCHAR(50) DEFAULT NULL,
    callback_payload LONGTEXT DEFAULT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (transaction_id),
    UNIQUE KEY uq_checkout_request_id (checkout_request_id),
    UNIQUE KEY uq_receipt_number (receipt_number),
    KEY idx_student_requested (student_id, requested_at),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    if (!$conn->query($sql)) {
        daraja_json_error('Failed to prepare M-Pesa transactions table', 500);
    }
}

function daraja_fetch_student_by_email(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare(
        "SELECT student_id, name, email, phone_no, hostel_id, status, room_number
         FROM students
         WHERE email = ?
         LIMIT 1"
    );
    if (!$stmt) {
        daraja_json_error('Failed to load student details', 500);
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $student;
}

function daraja_fetch_student_by_id(mysqli $conn, int $studentId): ?array
{
    $stmt = $conn->prepare(
        "SELECT student_id, name, email, phone_no, hostel_id, status, room_number
         FROM students
         WHERE student_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        daraja_json_error('Failed to load student details', 500);
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $student;
}

function daraja_fetch_student_payments(mysqli $conn, int $studentId): array
{
    $stmt = $conn->prepare(
        "SELECT payment_id, amount, receipt_number, payment_date, status
         FROM payment
         WHERE student_id = ?
         ORDER BY payment_date ASC, payment_id ASC"
    );
    if (!$stmt) {
        daraja_json_error('Failed to load payment history', 500);
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = [
            'payment_id' => (int) $row['payment_id'],
            'amount' => (float) $row['amount'],
            'receipt_number' => $row['receipt_number'] !== null ? (string) $row['receipt_number'] : null,
            'payment_date' => (string) $row['payment_date'],
            'status' => (string) $row['status'],
        ];
    }

    $stmt->close();

    return $payments;
}

function daraja_total_paid(array $payments): float
{
    $total = 0.0;

    foreach ($payments as $payment) {
        if (($payment['status'] ?? '') === 'Paid') {
            $total += (float) ($payment['amount'] ?? 0);
        }
    }

    return $total;
}

function daraja_fetch_latest_transaction(mysqli $conn, int $studentId): ?array
{
    daraja_ensure_transactions_table($conn);

    $stmt = $conn->prepare(
        "SELECT transaction_id, amount, phone_number, merchant_request_id, checkout_request_id,
                account_reference, transaction_desc, status, response_code, response_description,
                result_code, result_desc, receipt_number, requested_at, completed_at
         FROM mpesa_transactions
         WHERE student_id = ?
         ORDER BY transaction_id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        daraja_json_error('Failed to load latest M-Pesa request', 500);
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: null;
    $stmt->close();

    if ($row === null) {
        return null;
    }

    return [
        'transaction_id' => (int) $row['transaction_id'],
        'amount' => (float) $row['amount'],
        'phone_number' => (string) $row['phone_number'],
        'merchant_request_id' => $row['merchant_request_id'] !== null ? (string) $row['merchant_request_id'] : null,
        'checkout_request_id' => $row['checkout_request_id'] !== null ? (string) $row['checkout_request_id'] : null,
        'account_reference' => $row['account_reference'] !== null ? (string) $row['account_reference'] : null,
        'transaction_desc' => $row['transaction_desc'] !== null ? (string) $row['transaction_desc'] : null,
        'status' => (string) $row['status'],
        'response_code' => $row['response_code'] !== null ? (string) $row['response_code'] : null,
        'response_description' => $row['response_description'] !== null ? (string) $row['response_description'] : null,
        'result_code' => $row['result_code'] !== null ? (string) $row['result_code'] : null,
        'result_desc' => $row['result_desc'] !== null ? (string) $row['result_desc'] : null,
        'receipt_number' => $row['receipt_number'] !== null ? (string) $row['receipt_number'] : null,
        'requested_at' => (string) $row['requested_at'],
        'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
    ];
}

function daraja_build_access_token(array $config): string
{
    $url = rtrim((string) $config['base_url'], '/') . '/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode(trim((string) $config['consumer_key']) . ':' . trim((string) $config['consumer_secret']));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $credentials,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        daraja_json_error('Failed to connect to Daraja token service: ' . $curlError, 502);
    }

    $payload = json_decode($response, true);
    if (!is_array($payload) || empty($payload['access_token'])) {
        daraja_json_error('Daraja token request failed', 502, ['details' => $payload]);
    }

    if ($statusCode >= 400) {
        daraja_json_error('Daraja token request was rejected', 502, ['details' => $payload]);
    }

    return (string) $payload['access_token'];
}

function daraja_post_json(string $url, array $payload, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        daraja_json_error('Failed to connect to Daraja: ' . $curlError, 502);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        daraja_json_error('Daraja returned an invalid response', 502, ['raw_response' => $response]);
    }

    return [
        'status_code' => $statusCode,
        'body' => $decoded,
    ];
}

function daraja_parse_callback_metadata(?array $items): array
{
    $parsed = [];
    if (!is_array($items)) {
        return $parsed;
    }

    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['Name'])) {
            continue;
        }

        $parsed[(string) $item['Name']] = $item['Value'] ?? null;
    }

    return $parsed;
}

function daraja_transaction_date_to_mysql($value): ?string
{
    if ($value === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) !== 14) {
        return null;
    }

    $dt = DateTime::createFromFormat('YmdHis', $digits);
    if (!$dt instanceof DateTime) {
        return null;
    }

    return $dt->format('Y-m-d H:i:s');
}

function daraja_next_payment_id(mysqli $conn): int
{
    $result = $conn->query("SELECT COALESCE(MAX(payment_id), 0) + 1 AS next_id FROM payment FOR UPDATE");
    if (!$result) {
        daraja_json_error('Failed to generate payment id', 500);
    }

    $row = $result->fetch_assoc();
    return (int) $row['next_id'];
}

function daraja_sync_successful_payment(mysqli $conn, array $transaction): void
{
    $receiptNumber = trim((string) ($transaction['receipt_number'] ?? ''));
    if ($receiptNumber === '') {
        return;
    }

    $checkStmt = $conn->prepare("SELECT payment_id FROM payment WHERE receipt_number = ? LIMIT 1");
    if (!$checkStmt) {
        daraja_json_error('Failed to check existing payment record', 500);
    }
    $checkStmt->bind_param('s', $receiptNumber);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    if ($checkResult->fetch_assoc()) {
        $checkStmt->close();
        return;
    }
    $checkStmt->close();

    $paymentDateTime = daraja_transaction_date_to_mysql($transaction['transaction_date'] ?? null);
    $paymentDate = $paymentDateTime !== null ? substr($paymentDateTime, 0, 10) : date('Y-m-d');

    $pendingStmt = $conn->prepare(
        "SELECT payment_id
         FROM payment
         WHERE student_id = ? AND status = 'Unpaid' AND (receipt_number IS NULL OR receipt_number = '')
         ORDER BY payment_date DESC, payment_id DESC
         LIMIT 1"
    );
    if (!$pendingStmt) {
        daraja_json_error('Failed to check pending payment record', 500);
    }
    $pendingStmt->bind_param('i', $transaction['student_id']);
    $pendingStmt->execute();
    $pendingResult = $pendingStmt->get_result();
    $pendingRow = $pendingResult->fetch_assoc() ?: null;
    $pendingStmt->close();

    if ($pendingRow !== null) {
        $updateStmt = $conn->prepare(
            "UPDATE payment
             SET amount = ?, receipt_number = ?, payment_date = ?, status = 'Paid'
             WHERE payment_id = ?"
        );
        if (!$updateStmt) {
            daraja_json_error('Failed to update pending payment record', 500);
        }

        $amount = (float) $transaction['amount'];
        $paymentId = (int) $pendingRow['payment_id'];
        $updateStmt->bind_param('dssi', $amount, $receiptNumber, $paymentDate, $paymentId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            daraja_json_error('Failed to update pending payment record', 500);
        }
        $updateStmt->close();
        return;
    }

    $nextId = daraja_next_payment_id($conn);
    $insertStmt = $conn->prepare(
        "INSERT INTO payment (payment_id, student_id, amount, receipt_number, payment_date, status)
         VALUES (?, ?, ?, ?, ?, 'Paid')"
    );
    if (!$insertStmt) {
        daraja_json_error('Failed to create payment record', 500);
    }

    $studentId = (int) $transaction['student_id'];
    $amount = (float) $transaction['amount'];
    $insertStmt->bind_param('iidss', $nextId, $studentId, $amount, $receiptNumber, $paymentDate);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        daraja_json_error('Failed to create payment record', 500);
    }
    $insertStmt->close();
}
