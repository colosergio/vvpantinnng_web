<?php
header('Content-Type: application/json');

const INQUIRY_RECIPIENT = 'vasquezandvasquez.painting@gmail.com';

// Habilitar reporte de errores para desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar en pantalla, solo en logs

function loadSmtpConfig() {
    $config = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'vasquezandvasquez.painting@gmail.com',
        'password' => '',
        'from' => 'vasquezandvasquez.painting@gmail.com',
        // Used only by the hosting mail() fallback. A domain sender improves
        // SPF/DMARC alignment compared with pretending to send from Gmail.
        'mail_from' => 'website@vasquezandservices.com',
    ];

    // Keep production credentials outside public_html so Git deployments
    // cannot overwrite them. The in-project path remains as a migration and
    // local-development fallback.
    $configPaths = [
        __DIR__ . '/smtp-config.php',
        dirname(__DIR__, 2) . '/smtp-config.php',
    ];

    foreach ($configPaths as $configPath) {
        if (file_exists($configPath)) {
            $localConfig = require $configPath;
            if (is_array($localConfig)) {
                $config = array_merge($config, $localConfig);
            }
        }
    }

    $envMap = [
        'SMTP_HOST' => 'host',
        'SMTP_PORT' => 'port',
        'SMTP_USERNAME' => 'username',
        'SMTP_PASSWORD' => 'password',
        'SMTP_FROM' => 'from',
        'MAIL_FROM' => 'mail_from',
    ];

    foreach ($envMap as $envName => $configKey) {
        $value = getenv($envName);
        if ($value !== false && $value !== '') {
            $config[$configKey] = $value;
        }
    }

    $config['host'] = trim($config['host']);
    $config['username'] = trim($config['username']);
    $config['password'] = preg_replace('/\s+/', '', $config['password']);
    $config['from'] = trim($config['from']);
    $config['mail_from'] = trim($config['mail_from']);
    $config['port'] = (int) $config['port'];
    return $config;
}

function smtpRead($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function smtpExpect($socket, $expectedCodes) {
    $response = smtpRead($socket);
    $code = (int) substr($response, 0, 3);
    $expectedCodes = (array) $expectedCodes;

    if (!in_array($code, $expectedCodes, true)) {
        throw new Exception('SMTP error: ' . trim($response));
    }

    return $response;
}

function smtpCommand($socket, $command, $expectedCodes) {
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expectedCodes);
}

function normalizeSmtpRecipients($to) {
    $recipients = array_filter(array_map('trim', explode(',', $to)));
    if (empty($recipients)) {
        throw new Exception('No SMTP recipients configured.');
    }

    return $recipients;
}

function buildFallbackHeaders($headers, $from) {
    $safeFrom = str_replace(["\r", "\n"], '', $from);
    return preg_replace('/^From:.*\r\n/m', 'From: ' . $safeFrom . "\r\n", $headers, 1);
}

function sendHostingMail($to, $subject, $message, $headers, $from) {
    $fallbackHeaders = buildFallbackHeaders($headers, $from);
    return mail($to, $subject, $message, $fallbackHeaders);
}

function sendSmtpMail($smtpConfig, $to, $subject, $headers, $body) {
    $socket = stream_socket_client(
        'tcp://' . $smtpConfig['host'] . ':' . $smtpConfig['port'],
        $errno,
        $errstr,
        30
    );

    if (!$socket) {
        throw new Exception('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, 30);
    smtpExpect($socket, 220);

    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    smtpCommand($socket, 'EHLO ' . $serverName, 250);
    smtpCommand($socket, 'STARTTLS', 220);

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        throw new Exception('SMTP TLS negotiation failed.');
    }

    smtpCommand($socket, 'EHLO ' . $serverName, 250);
    smtpCommand($socket, 'AUTH LOGIN', 334);
    smtpCommand($socket, base64_encode($smtpConfig['username']), 334);
    smtpCommand($socket, base64_encode($smtpConfig['password']), 235);

    $from = $smtpConfig['from'] ?: $smtpConfig['username'];
    $recipients = normalizeSmtpRecipients($to);

    smtpCommand($socket, 'MAIL FROM:<' . $from . '>', 250);
    foreach ($recipients as $recipient) {
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
    }

    smtpCommand($socket, 'DATA', 354);

    $email = "Date: " . date(DATE_RFC2822) . "\r\n";
    $email .= "To: " . implode(', ', $recipients) . "\r\n";
    $email .= "Subject: " . str_replace(["\r", "\n"], '', $subject) . "\r\n";
    $email .= $headers . "\r\n" . $body;
    $email = preg_replace('/^\./m', '..', $email);

    fwrite($socket, $email . "\r\n.\r\n");
    smtpExpect($socket, 250);
    smtpCommand($socket, 'QUIT', 221);
    fclose($socket);
}

$smtpConfig = loadSmtpConfig();
$to = INQUIRY_RECIPIENT;
$smtpReady = !empty($smtpConfig['host'])
    && !empty($smtpConfig['port'])
    && !empty($smtpConfig['username'])
    && !empty($smtpConfig['password'])
    && !empty($smtpConfig['from']);
$siteEmail = $smtpReady ? $smtpConfig['from'] : $smtpConfig['mail_from'];

// MODO DESARROLLO: Guardar en archivo en lugar de enviar email
$isDevelopment = (
    isset($_SERVER['SERVER_NAME']) &&
    ($_SERVER['SERVER_NAME'] === 'localhost' ||
     strpos($_SERVER['SERVER_NAME'], '127.0.0.1') !== false ||
     strpos($_SERVER['SERVER_NAME'], '::1') !== false)
);

// Validar que todos los campos requeridos estén presentes
if (!isset($_POST['name']) || empty(trim($_POST['name']))) {
    echo json_encode(['type' => 'error', 'message' => 'Name is required']);
    exit;
}

if (!isset($_POST['lastname']) || empty(trim($_POST['lastname']))) {
    echo json_encode(['type' => 'error', 'message' => 'Last Name is required']);
    exit;
}

if (!isset($_POST['phone']) || empty(trim($_POST['phone']))) {
    echo json_encode(['type' => 'error', 'message' => 'Phone is required']);
    exit;
}

if (!isset($_POST['email']) || empty(trim($_POST['email']))) {
    echo json_encode(['type' => 'error', 'message' => 'Email is required']);
    exit;
}

if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['type' => 'error', 'message' => 'Invalid email format']);
    exit;
}

if (!isset($_POST['address']) || empty(trim($_POST['address']))) {
    echo json_encode(['type' => 'error', 'message' => 'Property Address is required']);
    exit;
}

if (!isset($_POST['comments']) || empty(trim($_POST['comments']))) {
    echo json_encode(['type' => 'error', 'message' => 'Project Description is required']);
    exit;
}

// Recoger datos del formulario
$name = htmlspecialchars(trim($_POST['name']));
$lastname = htmlspecialchars(trim($_POST['lastname']));
$phone = htmlspecialchars(trim($_POST['phone']));
$email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
$address = htmlspecialchars(trim($_POST['address']));
$comments = nl2br(htmlspecialchars(trim($_POST['comments'])));
$replyTo = str_replace(["\r", "\n"], '', $email);

// Configuración del email
$subject = "New Project Inquiry from " . $name . " " . $lastname;

// Crear un separador único para el email multipart
$separator = md5(time());

// Headers
$headers = "From: " . $siteEmail . "\r\n";
$headers .= "Reply-To: " . $replyTo . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"" . $separator . "\"\r\n";

// Cuerpo del mensaje
$message = "--" . $separator . "\r\n";
$message .= "Content-Type: text/html; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";

$message .= "<html><body>";
$message .= "<h2>New Project Inquiry</h2>";
$message .= "<p><strong>Name:</strong> " . $name . " " . $lastname . "</p>";
$message .= "<p><strong>Email:</strong> " . $email . "</p>";
$message .= "<p><strong>Phone:</strong> " . $phone . "</p>";
$message .= "<p><strong>Property Address:</strong> " . $address . "</p>";
$message .= "<p><strong>Project Description:</strong></p>";
$message .= "<p>" . $comments . "</p>";
$message .= "</body></html>\r\n";

// Procesar archivos adjuntos si existen
$attachedFiles = [];
if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
    $file_count = count($_FILES['attachments']['name']);

    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['attachments']['error'][$i] == 0) {
            $file_name = $_FILES['attachments']['name'][$i];
            $file_tmp = $_FILES['attachments']['tmp_name'][$i];
            $file_size = $_FILES['attachments']['size'][$i];

            // Validar tamaño del archivo (máximo 5MB por archivo)
            if ($file_size > 5242880) {
                echo json_encode(['type' => 'error', 'message' => 'File ' . $file_name . ' is too large. Maximum size is 5MB']);
                exit;
            }

            // Leer el archivo
            $file_content = chunk_split(base64_encode(file_get_contents($file_tmp)));
            $file_type = $_FILES['attachments']['type'][$i];

            // Agregar el archivo al mensaje
            $message .= "--" . $separator . "\r\n";
            $message .= "Content-Type: " . $file_type . "; name=\"" . $file_name . "\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"" . $file_name . "\"\r\n\r\n";
            $message .= $file_content . "\r\n";
            $attachedFiles[] = $file_name;
        }
    }
}

$message .= "--" . $separator . "--";

if ($smtpReady) {
    try {
        sendSmtpMail($smtpConfig, $to, $subject, $headers, $message);
        echo json_encode([
            'type' => 'success',
            'message' => 'Your inquiry has been sent successfully! We will contact you soon.'
        ]);
    } catch (Exception $exception) {
        error_log($exception->getMessage());

        // If Gmail SMTP is temporarily unavailable or its credentials have
        // expired, let Hostinger queue the message instead of losing the lead.
        if (sendHostingMail($to, $subject, $message, $headers, $smtpConfig['mail_from'])) {
            echo json_encode([
                'type' => 'success',
                'message' => 'Your inquiry has been sent successfully! We will contact you soon.'
            ]);
            exit;
        }

        error_log('Inquiry delivery also failed using the hosting mail() fallback.');
        echo json_encode([
            'type' => 'error',
            'message' => 'We could not send your inquiry right now. Please call us at 813-847-7863.'
        ]);
    }
    exit;
}

// Si estamos en desarrollo sin SMTP, guardar en archivo para debugging
if ($isDevelopment) {
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'from' => $siteEmail,
        'reply_to' => $email,
        'to' => $to,
        'name' => $name . ' ' . $lastname,
        'phone' => $phone,
        'address' => $address,
        'comments' => strip_tags($comments),
        'attachments' => $attachedFiles
    ];

    $log_file = __DIR__ . '/inquiry_log.json';
    $existing_logs = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) : [];
    $existing_logs[] = $log_data;
    file_put_contents($log_file, json_encode($existing_logs, JSON_PRETTY_PRINT));

    echo json_encode([
        'type' => 'error',
        'message' => 'SMTP is not configured yet. The inquiry was saved in includes/inquiry_log.json, but no email was sent. Add the Gmail App Password in includes/smtp-config.php.'
    ]);
    exit;
}

// Fallback para producción si el hosting tiene mail() configurado
if (sendHostingMail($to, $subject, $message, $headers, $smtpConfig['mail_from'])) {
    echo json_encode([
        'type' => 'success',
        'message' => 'Your inquiry has been sent successfully! We will contact you soon.'
    ]);
} else {
    error_log('Inquiry delivery failed using the hosting mail() fallback.');
    echo json_encode([
        'type' => 'error',
        'message' => 'Failed to send inquiry. Please try again later.'
    ]);
}
?>
