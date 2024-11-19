<?php
$validUsername = 'Vpn';
$validPassword = 'Vpn@2024';

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']) ||
    $_SERVER['PHP_AUTH_USER'] !== $validUsername || $_SERVER['PHP_AUTH_PW'] !== $validPassword) {
    

    header('WWW-Authenticate: Basic realm="Restricted Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized access';
    exit;
}
require 'vendor/autoload.php';
use Sonata\GoogleAuthenticator\GoogleAuthenticator;

function createUser($username, $password, $accountExpiryDays = null, $passwordExpiryDays = null) {
    $accountExpiryDate = $accountExpiryDays ? date('Y-m-d', strtotime("+$accountExpiryDays days")) : "";
    $passwordExpiryDays = $passwordExpiryDays ?? 90;
    $passwordWarnDays = 14;

    $command = "sudo useradd -m -p $(openssl passwd -1 '$password') -s /sbin/nologin ";
    
    if ($accountExpiryDate) {
        $command .= "-e $accountExpiryDate ";
    }
    
    if ($passwordExpiryDays) {
        $command .= "-f $passwordExpiryDays -K PASS_MAX_DAYS=$passwordExpiryDays -K PASS_WARN_AGE=$passwordWarnDays ";
    }

    $command .= escapeshellarg($username);

    exec($command, $output, $return_var);
    
    return $return_var == 0;
}

// Function to save Google Authenticator secret
function saveGoogleAuthenticatorSecret($username) {
    $ga = new GoogleAuthenticator();
    $secret = $ga->generateSecret();

    // Save the secret to a user-specific file without "_ga_secret" in the filename
    $file_path = "/etc/openvpn/users/$username";
    file_put_contents($file_path, $secret);

    return $secret;
}

// Function to delete user and their Google Authenticator secret
function deleteUser($username) {
    exec("sudo userdel -r $username", $output, $return_var);
    if ($return_var == 0) {
        $secret_file = "/etc/openvpn/users/$username";
        if (file_exists($secret_file)) {
            unlink($secret_file);
        }
    }
    return $return_var == 0;
}

// Function to change user password
function changePassword($username, $newPassword) {
    exec("echo '$username:$newPassword' | sudo chpasswd", $output, $return_var);
    return $return_var == 0;
}

// Function to send an email with credentials and VPN-mfa.ovpn file
function sendEmail($username, $password, $email) {
    $subject = "Your OpenVPN Access Details";
    $boundary = md5(time()); // Unique boundary for email content
    $ovpn_file_path = "/etc/openvpn/VPN-mfa.ovpn";
    $ovpn_file_content = file_get_contents($ovpn_file_path);
    $ovpn_encoded_content = chunk_split(base64_encode($ovpn_file_content));

    // Email headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    // Email body with attachment
    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= "Hello,\n\nYour VPN account has been created. Here are your details:\n\n";
    $message .= "Username: $username\nPassword: $password\n\n";
    $message .= "Please find the attached VPN-mfa.ovpn file for configuration.\n\nRegards,\nOpenVPN Support Team\r\n\r\n";

    // Attachment
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: application/octet-stream; name=\"VPN-mfa.ovpn\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"VPN-mfa.ovpn\"\r\n\r\n";
    $message .= "$ovpn_encoded_content\r\n";
    $message .= "--$boundary--";

    // Use sendmail to send the email with -f flag for the From address
    $sendmail_command = "sendmail -f noreply@mail.cartrade.com $email";
    $mail_process = popen($sendmail_command, 'w');
    fputs($mail_process, "To: $email\r\n");
    fputs($mail_process, "Subject: VPN-vpn-mfa $subject\r\n");
    fputs($mail_process, $headers);
    fputs($mail_process, "\r\n$message");
    pclose($mail_process);
}

// Function to retrieve list of users
function getUserList() {
    $userFiles = glob('/etc/openvpn/users/*');
    $users = [];

    foreach ($userFiles as $file) {
        $username = basename($file);
        $secret = trim(file_get_contents($file));
        $passwdExpiry = exec("sudo chage -l $username | grep 'Password expires' | awk -F': ' '{print $2}'");
        $accountExpiry = exec("sudo chage -l $username | grep 'Account expires' | awk -F': ' '{print $2}'");
        $users[$username] = ['secret' => $secret, 'passwdExpiry' => $passwdExpiry, 'accountExpiry' => $accountExpiry];
    }

    return $users;
}

// Handle user creation request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $recipientEmail = $_POST['email'];
    $expiryDays = $_POST['expiry_days'] ?? null;
    $accountExpiryDays = $_POST['account_expiry_days'] ?? null;

    if ($userDetails = createUser($username, $password, $expiryDays, $accountExpiryDays)) {
        saveGoogleAuthenticatorSecret($username);
        sendEmail($username, $password, $recipientEmail);
        echo "User $username created successfully, and details emailed!";
    } else {
        echo "Failed to create user. Please check permissions.";
    }
}

// Handle delete user request
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['username'])) {
    $username = $_GET['username'];
    if (deleteUser($username)) {
        echo "User $username deleted successfully!";
    } else {
        echo "Failed to delete user $username.";
    }
}

// Handle password change request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_username'], $_POST['new_password'])) {
    $username = $_POST['change_username'];
    $newPassword = $_POST['new_password'];
    if (changePassword($username, $newPassword)) {
        echo "Password for $username changed successfully!";
    } else {
        echo "Failed to change password for $username.";
    }
}

function updatePasswordExpiry($username, $expiryDays) {
    // Update password expiry and reset expired accounts if needed
    exec("sudo chage -M $expiryDays $username", $output, $return_var);
    
    // Automatically reset expired account if account is expired
    $status = exec("sudo chage -l $username | grep 'Account expires' | grep -c 'never'");
    if ($status == 0) { // If the account is expired
        exec("sudo chage -E -1 $username"); // Remove expiration
    }
    return $return_var == 0;
}

// Process password expiry update request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_username'], $_POST['expiry_days'])) {
    $username = $_POST['update_username'];
    $expiryDays = (int)$_POST['expiry_days'];
    if (updatePasswordExpiry($username, $expiryDays)) {
        echo "Password expiry for $username updated successfully!";
    } else {
        echo "Failed to update password expiry for $username.";
    }
}


// Display user table with delete and change password options
$users = getUserList();
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OpenVPN User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <h2>OpenVPN User Management</h2>

        <!-- Button to open Add User Form -->
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">Add User</button>

        <!-- "Home" Button with Home Icon -->
        <button class="btn btn-secondary" onclick="goHome()">
            <i class="bi bi-house-door"></i> Home
        </button>

        <!-- Pop-up Form to Create User (Bootstrap Modal) -->
        <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addUserModalLabel">Create New OpenVPN User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username:</label>
                                <input type="text" id="username" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password:</label>
                                <input type="password" id="password" name="password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email:</label>
                                <input type="email" id="email" name="email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label for="expiry_days" class="form-label">Account Expiry (days)(default-never):</label>
                                <input type="number" id="expiry_days" name="expiry_days" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label for="account_expiry_days" class="form-label">Password Expiry (days)(default-never):</label>
                                <input type="number" id="account_expiry_days" name="account_expiry_days" class="form-control">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Create User</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <h2>Existing Users</h2>
        <table class="table table-sm table-bordered">
            <thead>
                <tr class="table-active">
                    <th>Username</th>
                    <th>Secret</th>
                    <th>Password Expiry</th>
                    <th>Account Expiry</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <?php foreach ($users as $username => $details): ?>
                <tr>
                    <td><?php echo htmlspecialchars($username); ?></td>
                    <td><?php echo htmlspecialchars($details['secret']); ?></td>
                    <td><?php echo htmlspecialchars($details['passwdExpiry']); ?></td>
                    <td><?php echo htmlspecialchars($details['accountExpiry']); ?></td>
                    <td>
                        <form action="download_qr.php" method="get" style="display:inline;">
                            <input type="hidden" name="username" value="<?php echo urlencode($username); ?>" />
                            <button type="submit" class="btn btn-info btn-sm">Download QR</button>
                        </form>
                        <form action="" method="get" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                            <input type="hidden" name="action" value="delete" />
                            <input type="hidden" name="username" value="<?php echo urlencode($username); ?>" />
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                        <button class="btn btn-warning btn-sm" onclick="changePasswordPrompt('<?php echo htmlspecialchars($username); ?>')">Change Password</button>
                        <button class="btn btn-primary btn-sm" onclick="updateExpiry('<?php echo htmlspecialchars($username); ?>')">Password Expiry</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <script>
        // JavaScript function to prompt for the new password and submit the form
        function changePasswordPrompt(username) {
            let newPassword = prompt("Enter the new password for " + username + ":");
            if (newPassword !== null && newPassword !== "") {
                document.getElementById("change_username").value = username;
                document.getElementById("new_password").value = newPassword;
                document.getElementById("changePasswordForm").submit();
            }
        }

        // JavaScript function to prompt for expiry days and submit the form
        function updateExpiry(username) {
            let days = prompt("Enter the number of days to extend the password expiry:");
            if (days !== null && days !== "") {
                document.getElementById("expiry_days_input").value = days;
                document.getElementById("update_username_input").value = username;
                document.getElementById("updateExpiryForm").submit();
            }
        }

        // Redirect to Home page
        function goHome() {
            window.location.href = "index.php";  // Update this URL with your homepage
        }
    </script>

    <form id="changePasswordForm" method="POST" action="">
        <input type="hidden" id="change_username" name="change_username" />
        <input type="hidden" id="new_password" name="new_password" />
    </form>

    <form id="updateExpiryForm" method="POST" action="">
        <input type="hidden" id="expiry_days_input" name="expiry_days" />
        <input type="hidden" id="update_username_input" name="update_username" />
    </form>
</body>
</html>
