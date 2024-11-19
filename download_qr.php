<?php
if (!isset($_GET['username'])) {
    die("Username not specified.");
}

$username = $_GET['username'];
$file_path = "/etc/openvpn/users/$username";

// Check if the user file exists
if (!file_exists($file_path)) {
    die("User not found.");
}

// Read the secret from the file
$secret = trim(file_get_contents($file_path));

// Define QR code file path
$qr_image_path = "/tmp/{$username}_qr.png";

// Generate the QR code with qrencode if it doesn't already exist
if (!file_exists($qr_image_path)) {
    $qr_code_command = "qrencode -o $qr_image_path 'otpauth://totp/{$username}?secret={$secret}&issuer=VPN'";
    exec($qr_code_command, $output, $result_code);

    if ($result_code !== 0) {
        die("Failed to generate QR code.");
    }
}

// Handle email submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);

    if ($email) {
        $subject = "Your QR Code for {$username}";
        $message = "Please find attached the QR code for user: {$username}";

        // Prepare email headers
        $headers = "From: noreply@xxxxxxxxx.com\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"boundary\"\r\n";

        // Attachment content
        $file_content = file_get_contents($qr_image_path);
        $encoded_content = chunk_split(base64_encode($file_content));

        // Email body with attachment
        $body = "--boundary\r\n";
        $body .= "Content-Type: text/plain; charset=ISO-8859-1\r\n\r\n";
        $body .= $message . "\r\n";
        $body .= "--boundary\r\n";
        $body .= "Content-Type: image/png; name=\"{$username}_qr.png\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$username}_qr.png\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $encoded_content . "\r\n";
        $body .= "--boundary--";

        // Send email
        if (mail($email, $subject, $body, $headers, "-f noreply@xxxxxxxx.com")) {
            echo "<div class='alert alert-success'>Email sent successfully to $email.</div>";
        } else {
            echo "<div class='alert alert-danger'>Failed to send email.</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Invalid email address.</div>";
    }
}

// Check if 'display' or 'download' parameter is set to serve the image
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'display') {
        // Display the QR code in the browser
        header('Content-Type: image/png');
        readfile($qr_image_path);
        exit;
    } elseif ($_GET['action'] === 'download') {
        // Force download the QR code
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $username . '_qr.png"');
        readfile($qr_image_path);
        exit;
    }
}

// Main HTML output for display, download, and email
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QR Code for <?php echo htmlspecialchars($username); ?></title>
    <!-- Include Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">
    <h2 class="mb-4">QR Code for User: <?php echo htmlspecialchars($username); ?></h2>
    <p>Use the buttons below to view, download, or email the QR code:</p>
    
    <div class="mb-3">
        <a href="?username=<?php echo urlencode($username); ?>&action=display" target="_blank" class="btn btn-primary">Display QR Code</a>
        <a href="?username=<?php echo urlencode($username); ?>&action=download" class="btn btn-secondary">Download QR Code</a>
    </div>

    <h3 class="mt-5">Send QR Code via Email</h3>
    <form method="post" class="mb-4">
        <div class="mb-3">
            <label for="email" class="form-label">Email Address:</label>
            <input type="email" name="email" id="email" class="form-control form-control-sm " required>
        </div>
        <button type="submit" class="btn btn-success">Send Email</button>
    </form>

    <!-- Back to Home Button -->
    <a href="index.php" class="btn btn-info">Back to Home</a>

    <!-- Include Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
