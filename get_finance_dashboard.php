<?php
require_once 'finance_api_common.php';

$students = finance_fetch_students($conn);
$payments = finance_fetch_payments($conn);
$unpaidStudents = finance_fetch_unpaid_students($conn);

$totalPaidAmount = 0.0;
$paidRecords = 0;
$currentMonthRecords = 0;
$currentMonth = date('Y-m');

foreach ($payments as $payment) {
    if ($payment['status'] === 'Paid') {
        $paidRecords++;
        $totalPaidAmount += $payment['amount'];
    }
    if (strpos($payment['payment_date'], $currentMonth) === 0) {
        $currentMonthRecords++;
    }
}

$feePerStudent = 15000;
$totalStudents = count($students);
$unpaidStudentCount = count($unpaidStudents);

finance_json_success([
    'students' => $students,
    'payments' => $payments,
    'metrics' => [
        'total_students' => $totalStudents,
        'total_payment_records' => count($payments),
        'paid_records' => $paidRecords,
        'unpaid_records' => count($payments) - $paidRecords,
        'unpaid_students' => $unpaidStudentCount,
        'total_paid_amount' => $totalPaidAmount,
        'outstanding_amount' => $unpaidStudentCount * $feePerStudent,
        'current_month_records' => $currentMonthRecords,
        'expected_revenue' => $totalStudents * $feePerStudent,
    ],
]);
