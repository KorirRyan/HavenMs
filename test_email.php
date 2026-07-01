<?php
$host = 'ssl://smtp.gmail.com';
$port = 465;
$user = 'ryanrugut@gmail.com';
$pass = 'axaxzseltffsuuwv';
$to   = 'ryanrugut@gmail.com'; // sending to yourself to test

$socket = stream_socket_client("{$host}:{$port}", $errno, $errstr, 10);
if (!$socket) die("Connection failed: $errstr");

echo fgets($socket, 1024);

fwrite($socket, "EHLO localhost\r\n");
while ($line = fgets($socket, 1024)) {
    if (substr($line, 3, 1) === ' ') break;
}

fwrite($socket, "AUTH LOGIN\r\n"); fgets($socket, 1024);
fwrite($socket, base64_encode($user) . "\r\n"); fgets($socket, 1024);
fwrite($socket, base64_encode($pass) . "\r\n"); fgets($socket, 1024);

fwrite($socket, "MAIL FROM:<{$user}>\r\n"); echo fgets($socket, 1024);
fwrite($socket, "RCPT TO:<{$to}>\r\n"); echo fgets($socket, 1024);
fwrite($socket, "DATA\r\n"); echo fgets($socket, 1024);

$body = base64_encode("<h2>HavenMS Test Email</h2><p>If you see this, email is working!</p>");
$msg  = "From: HavenMS <{$user}>\r\n";
$msg .= "To: <{$to}>\r\n";
$msg .= "Subject: HavenMS Test\r\n";
$msg .= "MIME-Version: 1.0\r\n";
$msg .= "Content-Type: text/html; charset=UTF-8\r\n";
$msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
$msg .= chunk_split($body);
$msg .= "\r\n.\r\n";

fwrite($socket, $msg);
$response = fgets($socket, 1024);
echo $response;
echo strpos($response, '250') !== false ? "✓ Email sent!" : "✗ Failed to send";

fwrite($socket, "QUIT\r\n");
fclose($socket);
?>