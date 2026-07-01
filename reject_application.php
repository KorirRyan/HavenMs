<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo "Database connection failed";
    exit;
}

$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$reason     = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if (!$student_id || !$reason) {
    http_response_code(400);
    echo "Missing required fields";
    exit;
}

// Get student details
$stmt = $conn->prepare("SELECT name, email FROM students WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

if (!$student) {
    http_response_code(404);
    echo "Student not found";
    exit;
}

// Update status in DB
$stmt = $conn->prepare("UPDATE students SET status = 'rejected', rejection_reason = ? WHERE student_id = ?");
$stmt->bind_param("si", $reason, $student_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo "Failed to update student status";
    exit;
}
$stmt->close();
$conn->close();

// Send rejection email
$emailSent = sendRejectionEmail($student['email'], $student['name'], $reason);

echo $emailSent ? "success" : "success_no_email";

// ── GMAIL SMTP MAILER ──
function sendRejectionEmail($toEmail, $toName, $reason) {
    // AFTER
    $smtpHost = 'ssl://smtp.gmail.com';
    $smtpPort = 465;
    $smtpUser  = 'ryanrugut@gmail.com';
    $smtpPass  = 'axaxzseltffsuuwv';  // Your App Password;
    $fromName  = 'HavenMS';
    $fromEmail = 'ryanrugut@gmail.com';

    $subject = 'Update on Your HavenMS Hostel Application';

    $body = "
    <div style='font-family:sans-serif;max-width:560px;margin:0 auto;color:#1a1a1a'>
      <div style='background:#2563eb;padding:24px;border-radius:10px 10px 0 0;text-align:center'>
        <h1 style='color:#fff;margin:0;font-size:22px'>🏠 HavenMS</h1>
        <p style='color:#bfdbfe;margin:4px 0 0;font-size:13px'>Student Housing Management</p>
      </div>
      <div style='background:#fff;padding:28px;border:1px solid #e5e5e0;border-top:none;border-radius:0 0 10px 10px'>
        <h2 style='color:#dc2626;margin:0 0 16px'>Application Update</h2>
        <p>Dear <strong>{$toName}</strong>,</p>
        <p style='margin:12px 0'>Thank you for applying for hostel accommodation through HavenMS. After reviewing your application, we regret to inform you that your application was <strong>not approved</strong> at this time.</p>
        <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px;margin:16px 0;border-left:4px solid #dc2626'>
          <strong style='font-size:13px'>Reason:</strong>
          <p style='margin:6px 0 0;font-size:13px'>{$reason}</p>
        </div>
        <p style='margin:16px 0'>If you have any questions or would like to reapply, please visit the hostel administration office or contact us directly.</p>
        <p style='color:#6b7280;font-size:12px;margin-top:24px;border-top:1px solid #e5e5e0;padding-top:16px'>This is an automated message from HavenMS. Please do not reply to this email.</p>
      </div>
    </div>";

    return smtpSend($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $fromName, $toEmail, $toName, $subject, $body);
}

function smtpSend($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody) {
    $socket = stream_socket_client("{$host}:{$port}", $errno, $errstr, 10);
    if (!$socket) return false;

    fgets($socket, 1024);

    fwrite($socket, "EHLO localhost\r\n");
    while ($line = fgets($socket, 1024)) {
        if (substr($line, 3, 1) === ' ') break;
    }

    fwrite($socket, "AUTH LOGIN\r\n"); fgets($socket, 1024);
    fwrite($socket, base64_encode($user) . "\r\n"); fgets($socket, 1024);
    fwrite($socket, base64_encode($pass) . "\r\n");
    $auth = fgets($socket, 1024);
    if (strpos($auth, '235') === false) { fclose($socket); return false; }

    fwrite($socket, "MAIL FROM:<{$fromEmail}>\r\n"); fgets($socket, 1024);
    fwrite($socket, "RCPT TO:<{$toEmail}>\r\n"); fgets($socket, 1024);
    fwrite($socket, "DATA\r\n"); fgets($socket, 1024);

    $headers  = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "To: {$toName} <{$toEmail}>\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $headers .= chunk_split(base64_encode($htmlBody));

    fwrite($socket, $headers . "\r\n.\r\n");
    $sendResponse = fgets($socket, 1024);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return strpos($sendResponse, '250') !== false;
}
?>