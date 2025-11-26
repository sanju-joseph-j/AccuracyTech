<?php
// inc/sendEmail.php (with SMTP debug)
// Overwrite your existing file with this exact content for temporary debugging.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php'; // correct relative path to vendor

// Load .env if present
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();
}

function envVal(string $k, $d = null) {
    return $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k) ?: $d;
}

function jsonOut(array $data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function logErr(string $msg) {
    $file = __DIR__ . '/../mail_debug.log';
    file_put_contents($file, date('c') . ' ' . $msg . PHP_EOL . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['status' => 'error', 'message' => 'Method not allowed. Use POST.'], 405);
}

// Get inputs
$name = trim((string)($_POST['contactName'] ?? ''));
$email = trim((string)($_POST['contactEmail'] ?? ''));
$subject = trim((string)($_POST['contactSubject'] ?? ''));
$message_raw = trim((string)($_POST['contactMessage'] ?? ''));

// Validation
$errors = [];
if (mb_strlen($name) < 2) $errors['name'] = 'Enter your name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
if (mb_strlen($message_raw) < 15) $errors['message'] = 'Message must be at least 15 characters.';

foreach ([$name, $email, $subject] as $f) {
    if (preg_match("/[\r\n]/", $f)) { $errors['injection'] = 'Invalid input.'; break; }
}

if (!empty($errors)) jsonOut(['status'=>'validation_error','errors'=>$errors], 400);

// Prepare HTML body
$esc_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$esc_email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$esc_msg_html = nl2br(htmlspecialchars($message_raw, ENT_QUOTES, 'UTF-8'));

$body = <<<HTML
<html><body>
<p><strong>From:</strong> {$esc_name}</p>
<p><strong>Email:</strong> {$esc_email}</p>
<p><strong>Message:</strong><br />{$esc_msg_html}</p>
<hr/>
<p>Sent from site contact form.</p>
</body></html>
HTML;

// SMTP config from .env
$smtpHost = envVal('SMTP_HOST', '');
$smtpUser = envVal('SMTP_USER', '');
$smtpPass = envVal('SMTP_PASS', '');
$smtpPort = (int)envVal('SMTP_PORT', 587);
$smtpSecure = envVal('SMTP_SECURE', 'tls');
$fromEmail = envVal('FROM_EMAIL', 'no-reply@yourdomain.com');
$fromName = envVal('FROM_NAME', 'Website Contact');
$recipient = envVal('RECIPIENT', 'iamsanjujoseph@gmail.com');

try {
    $mail = new PHPMailer(true);

    // SMTP config
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = $smtpSecure;
    $mail->Port = $smtpPort;
    $mail->CharSet = 'UTF-8';



    // Message headers/body
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipient);
    $mail->addReplyTo($email, $name);
    $mail->isHTML(true);
    $mail->Subject = $subject ?: 'Contact Form Submission';
    $mail->Body = $body;
    $mail->AltBody = strip_tags($message_raw);

    // Send
    $mail->send();
    jsonOut(['status'=>'OK','message'=>'Message sent.']);

} catch (Exception $e) {
    // Log both PHPMailer's ErrorInfo and exception message (safe)
    $err = "PHPMailer Exception: " . $e->getMessage() . " | ErrorInfo: " . ($mail->ErrorInfo ?? 'N/A');
    logErr($err);
    jsonOut(['status'=>'error','message'=>'Something went wrong. Check mail_debug.log'], 500);
}
