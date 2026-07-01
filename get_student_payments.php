<?php
require_once 'daraja_payment_common.php';

$email = isset($_GET['email']) ? trim((string) $_GET['email']) : '';
if ($email === '') {
    daraja_json_error('Student email is required');
}

$student = daraja_fetch_student_by_email($conn, $email);
if ($student === null) {
    daraja_json_error('Student not found', 404);
}

$payments = daraja_fetch_student_payments($conn, (int) $student['student_id']);
$totalPaid = daraja_total_paid($payments);
$totalDue = daraja_hostel_fee($darajaConfig);
$balance = max($totalDue - $totalPaid, 0);
$latestTransaction = daraja_fetch_latest_transaction($conn, (int) $student['student_id']);
$studentStatus = strtolower((string) ($student['status'] ?? 'pending'));
$normalizedPhone = daraja_normalize_phone((string) ($student['phone_no'] ?? ''));

$mappedPayments = array_map(static function (array $payment): array {
    return [
        'payment_id' => $payment['payment_id'],
        'amount' => $payment['amount'],
        'receipt_number' => $payment['receipt_number'],
        'payment_date' => $payment['payment_date'],
        'status' => $payment['status'],
    ];
}, $payments);

daraja_json_success([
    'student_id' => (int) $student['student_id'],
    'student_status' => $studentStatus,
    'total_due' => $totalDue,
    'total_paid' => $totalPaid,
    'balance' => $balance,
    'daraja_enabled' => daraja_is_configured($darajaConfig),
    'can_initiate_payment' => $studentStatus === 'approved' && $balance > 0,
    'phone_number' => $normalizedPhone,
    'latest_transaction' => $latestTransaction,
    'payments' => $mappedPayments,
]);
