<?php
// submit.php - Form submission handler

$config = require __DIR__ . '/email-config.php';

function smtp_send($socket, $command, $expectedCode) {
    fwrite($socket, $command);
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return strpos($response, (string) $expectedCode) === 0;
}

function parse_recipients($addresses) {
    if (is_array($addresses)) {
        return array_filter(array_map('trim', $addresses));
    }

    $parts = preg_split('/[;,]+/', $addresses);
    return array_filter(array_map('trim', $parts));
}

function smtp_mail($to, $subject, $body, $headers, $config) {
    $host = $config['smtp_host'];
    $port = $config['smtp_port'];
    $encryption = strtolower($config['smtp_encryption']);
    $username = $config['smtp_username'];
    $password = $config['smtp_password'];

    $remote = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
    $socket = stream_socket_client($remote . ':' . $port, $errno, $errstr, 30);
    if (!$socket) {
        return false;
    }

    if (!smtp_send($socket, '', 220)) {
        fclose($socket);
        return false;
    }

    $localhost = 'localhost';
    if (!smtp_send($socket, "EHLO $localhost\r\n", 250)) {
        if (!smtp_send($socket, "HELO $localhost\r\n", 250)) {
            fclose($socket);
            return false;
        }
    }

    if ($encryption === 'tls') {
        if (!smtp_send($socket, "STARTTLS\r\n", 220)) {
            fclose($socket);
            return false;
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!smtp_send($socket, "EHLO $localhost\r\n", 250)) {
            fclose($socket);
            return false;
        }
    }

    if (!empty($username) && !empty($password)) {
        if (!smtp_send($socket, "AUTH LOGIN\r\n", 334)) {
            fclose($socket);
            return false;
        }
        if (!smtp_send($socket, base64_encode($username) . "\r\n", 334)) {
            fclose($socket);
            return false;
        }
        if (!smtp_send($socket, base64_encode($password) . "\r\n", 235)) {
            fclose($socket);
            return false;
        }
    }

    $from = $config['email_from'];
    if (!smtp_send($socket, "MAIL FROM:<$from>\r\n", 250)) {
        fclose($socket);
        return false;
    }

    $recipients = parse_recipients($to);
    if (empty($recipients)) {
        fclose($socket);
        return false;
    }

    foreach ($recipients as $recipient) {
        if (!smtp_send($socket, "RCPT TO:<$recipient>\r\n", 250)) {
            fclose($socket);
            return false;
        }
    }

    if (!smtp_send($socket, "DATA\r\n", 354)) {
        fclose($socket);
        return false;
    }

    $toHeader = "To: " . implode(', ', $recipients) . "\r\n";
    $subjectHeader = "Subject: $subject\r\n";
    $data = $toHeader . $subjectHeader . $headers . "\r\n" . $body . "\r\n.\r\n";
    if (!smtp_send($socket, $data, 250)) {
        fclose($socket);
        return false;
    }

    smtp_send($socket, "QUIT\r\n", 221);
    fclose($socket);
    return true;
}

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = htmlspecialchars(trim($_POST['name']));
    $phone = htmlspecialchars(trim($_POST['phone']));
    $email = htmlspecialchars(trim($_POST['email']));
    $course = htmlspecialchars(trim($_POST['course']));
    $city = htmlspecialchars(trim($_POST['city']));
    $message = htmlspecialchars(trim($_POST['message']));

    // Validate required fields
    if (empty($name) || empty($phone) || empty($city)) {
        header("Location: index.html?error=missing_fields");
        exit();
    }

    $to = $config['email_to'];
    $subject = "New Admission Enquiry - $name";

    // Email content
    $email_content = "New Admission Enquiry Received\r\n\r\n";
    $email_content .= "Student Details:\r\n";
    $email_content .= "Name: $name\r\n";
    $email_content .= "Phone: $phone\r\n";
    $email_content .= "Email: $email\r\n";
    $email_content .= "Course: $course\r\n";
    $email_content .= "City: $city\r\n\r\n";
    $email_content .= "Message:\r\n$message\r\n\r\n";
    $email_content .= "--\r\n";
    $email_content .= "Please contact the student at: $phone\r\n";

    $from = $config['email_from'];
    $fromName = $config['email_from_name'];

    // Email headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: $fromName <$from>\r\n";

    $sent = false;
    if (!empty($config['use_smtp']) && !empty($config['smtp_host'])) {
        $sent = smtp_mail($to, $subject, $email_content, $headers, $config);
    } else {
        $sent = mail($to, $subject, $email_content, $headers);
    }

    if ($sent) {
        header("Location: index.html?success=1");
        exit();
    } else {
        header("Location: index.html?error=email_failed");
        exit();
    }
} else {
    header("Location: index.html");
    exit();
}
?>