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
$room_number = isset($_POST['room_number']) ? trim($_POST['room_number']) : '';
$note = isset($_POST['note']) ? trim($_POST['note']) : '';
$floor_number = isset($_POST['floor']) ? trim($_POST['floor']) : '';

if (!$student_id || !$room_number) {
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
$hasFloorNumber = db_column_exists($conn, 'students', 'floor_number');
if ($hasFloorNumber) {
    $stmt = $conn->prepare("UPDATE students SET status = 'approved', room_number = ?, floor_number = ?, approved_at = NOW() WHERE student_id = ?");
    $stmt->bind_param("ssi", $room_number, $floor_number, $student_id);
} else {
    $stmt = $conn->prepare("UPDATE students SET status = 'approved', room_number = ?, approved_at = NOW() WHERE student_id = ?");
    $stmt->bind_param("si", $room_number, $student_id);
}
if (!$stmt->execute()) {
    http_response_code(500);
    echo "Failed to update student status";
    exit;
}
$stmt->close();

// Send confirmation email via Gmail SMTP
$emailSent = sendApprovalEmail($student['email'], $student['name'], $room_number, $note);

$conn->close();

if ($emailSent) {
    echo "success";
} else {
    // DB was updated successfully even if email failed
    echo "success_no_email";
}

// ── GMAIL SMTP MAILER ──
function sendApprovalEmail($toEmail, $toName, $roomNumber, $note) {
    // AFTER
    $smtpHost = 'ssl://smtp.gmail.com';
    $smtpPort = 465;
    $smtpUser     = 'ryanrugut@gmail.com'; // Your Gmail
    $smtpPass  = 'axaxzseltffsuuwv';  // Your App Password
    $fromName     = 'HavenMS';
    $fromEmail    = 'ryanrugut@gmail.com';

    $subject = 'Your HavenMS Hostel Application Has Been Approved!';

    $noteSection = $note ? "<p style='background:#f0fdf4;padding:12px 16px;border-radius:8px;border-left:4px solid #16a34a;margin:16px 0'><strong>Note from Admin:</strong> {$note}</p>" : '';

    $body = "
    <div style='font-family:sans-serif;max-width:560px;margin:0 auto;color:#1a1a1a'>
      <div style='background:#2563eb;padding:24px;border-radius:10px 10px 0 0;text-align:center'>
        <h1 style='color:#fff;margin:0;font-size:22px'>🏠 HavenMS</h1>
        <p style='color:#bfdbfe;margin:4px 0 0;font-size:13px'>Student Housing Management</p>
      </div>
      <div style='background:#fff;padding:28px;border:1px solid #e5e5e0;border-top:none;border-radius:0 0 10px 10px'>
        <h2 style='color:#16a34a;margin:0 0 16px'>✅ Application Approved!</h2>
        <p>Dear <strong>{$toName}</strong>,</p>
        <p style='margin:12px 0'>We are pleased to inform you that your hostel application has been <strong>approved</strong>. Your room has been allocated as follows:</p>
        <div style='background:#f8fafc;border:1px solid #e5e5e0;border-radius:8px;padding:16px;margin:16px 0'>
          <table style='width:100%;font-size:14px'>
            <tr><td style='color:#6b7280;padding:4px 0'>Room Number</td><td style='font-weight:600;text-align:right'>{$roomNumber}</td></tr>
            <tr><td style='color:#6b7280;padding:4px 0'>Status</td><td style='color:#16a34a;font-weight:600;text-align:right'>✓ Approved</td></tr>
          </table>
        </div>
        {$noteSection}
        <p style='margin:16px 0'>Please visit the hostel reception to collect your room key. Bring a copy of this email and your student ID.</p>
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
