<?php
// Start session for user authentication and data persistence
session_start();

// Database connection
require_once 'includes/db_connection.php';

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT first_name, last_name, email, institution, major FROM users WHERE id = ?");
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($first_name, $last_name, $email, $institution, $major);
$stmt->fetch();
$stmt->close();

// Get user preferences
$stmt = $conn->prepare("SELECT theme, reminder_days, email_notifications FROM preferences WHERE user_id = ?");
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($theme, $reminder_days, $email_notifications);
$stmt->fetch();
$stmt->close();

// Initialize error and success messages
$error_msg = "";
$success_msg = "";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['update_profile'])) {
        // Update profile information
        $new_first_name = trim($_POST['first_name']);
        $new_last_name = trim($_POST['last_name']);
        $new_email = trim($_POST['email']);
        $new_institution = trim($_POST['institution']);
        $new_major = trim($_POST['major']);
        
        // Basic validation
        if (empty($new_first_name) || empty($new_last_name) || empty($new_email)) {
            $error_msg = "Name and email fields are required.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Please enter a valid email address.";
        } else {
            // Check if email already exists (but not for this user)
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $new_email, $user_id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $error_msg = "Email already in use by another account.";
            } else {
                // Update user information
                $stmt->close();
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, institution = ?, major = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $new_first_name, $new_last_name, $new_email, $new_institution, $new_major, $user_id);
                
                if ($stmt->execute()) {
                    $first_name = $new_first_name;
                    $last_name = $new_last_name;
                    $email = $new_email;
                    $institution = $new_institution;
                    $major = $new_major;
                    $success_msg = "Profile updated successfully!";
                } else {
                    $error_msg = "Error updating profile: " . $conn->error;
                }
            }
            $stmt->close();
        }
    } elseif (isset($_POST['change_password'])) {
        // Change password
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate password inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error_msg = "New password must be at least 8 characters long.";
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->bind_result($password_hash);
            $stmt->fetch();
            $stmt->close();
            
            if (password_verify($current_password, $password_hash)) {
                // Update password
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $new_password_hash, $user_id);
                
                if ($stmt->execute()) {
                    $success_msg = "Password changed successfully!";
                } else {
                    $error_msg = "Error changing password: " . $conn->error;
                }
                $stmt->close();
            } else {
                $error_msg = "Current password is incorrect.";
            }
        }
    } elseif (isset($_POST['update_preferences'])) {
        // Update user preferences
        $new_theme = $_POST['theme'];
        $new_reminder_days = intval($_POST['reminder_days']);
        $new_email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        
        // Input validation
        if ($new_reminder_days < 0 || $new_reminder_days > 30) {
            $error_msg = "Reminder days must be between 0 and 30.";
        } else {
            $stmt = $conn->prepare("UPDATE preferences SET theme = ?, reminder_days = ?, email_notifications = ? WHERE user_id = ?");
            $stmt->bind_param("siii", $new_theme, $new_reminder_days, $new_email_notifications, $user_id);
            
            if ($stmt->execute()) {
                $theme = $new_theme;
                $reminder_days = $new_reminder_days;
                $email_notifications = $new_email_notifications;
                $success_msg = "Preferences updated successfully!";
            } else {
                $error_msg = "Error updating preferences: " . $conn->error;
            }
            $stmt->close();
        }
    } elseif (isset($_POST['delete_account'])) {
        // Verify password before deleting account
        $confirmation_password = $_POST['confirmation_password'];
        
        // Verify password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($password_hash);
        $stmt->fetch();
        $stmt->close();
        
        if (password_verify($confirmation_password, $password_hash)) {
            // Start transaction for account deletion
            $conn->begin_transaction();
            
            try {
                // Delete user data from all related tables
                // The foreign key constraints with ON DELETE CASCADE will handle related tables
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
                
                // Commit transaction
                $conn->commit();
                
                // Destroy session and redirect to login page
                session_destroy();
                header("Location: login.php?deleted=1");
                exit;
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error_msg = "Error deleting account: " . $e->getMessage();
            }
        } else {
            $error_msg = "Incorrect password. Account deletion cancelled.";
        }
    }
}

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-graduation-cap me-2"></i>University Work Analyzer
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="assignments.php">Assignments</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="subjects.php">Subjects</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="grades.php">Grades</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="academic_terms.php">Academic Terms</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="analytics.php">Analytics</a>
                    </li>
                </ul>
                <div class="navbar-nav">
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-id-card me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item active" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-4">
        <div class="row">
            <!-- Settings Navigation -->
            <div class="col-lg-3 mb-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Settings</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush" id="settings-tabs" role="tablist">
                            <a class="list-group-item list-group-item-action active" id="profile-tab" data-bs-toggle="list" href="#profile" role="tab">
                                <i class="fas fa-user me-2"></i>Profile Information
                            </a>
                            <a class="list-group-item list-group-item-action" id="security-tab" data-bs-toggle="list" href="#security" role="tab">
                                <i class="fas fa-lock me-2"></i>Security & Password
                            </a>
                            <a class="list-group-item list-group-item-action" id="preferences-tab" data-bs-toggle="list" href="#preferences" role="tab">
                                <i class="fas fa-sliders-h me-2"></i>Preferences
                            </a>
                            <a class="list-group-item list-group-item-action" id="danger-tab" data-bs-toggle="list" href="#danger" role="tab">
                                <i class="fas fa-exclamation-triangle me-2 text-danger"></i>Danger Zone
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Settings Content -->
            <div class="col-lg-9">
                <!-- Alert Messages -->
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="tab-content">
                    <!-- Profile Information -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Profile Information</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="first_name" class="form-label">First Name</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first_name); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="last_name" class="form-label">Last Name</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last_name); ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="institution" class="form-label">Institution</label>
                                        <input type="text" class="form-control" id="institution" name="institution" value="<?php echo htmlspecialchars($institution); ?>">
                                        <div class="form-text">Your university or college name</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="major" class="form-label">Major/Program</label>
                                        <input type="text" class="form-control" id="major" name="major" value="<?php echo htmlspecialchars($major); ?>">
                                    </div>
                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security & Password -->
                    <div class="tab-pane fade" id="security" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Change Password</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" 
                                            pattern=".{8,}" title="Password must be at least 8 characters" required>
                                        <div class="form-text">Password must be at least 8 characters long.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Preferences -->
                    <div class="tab-pane fade" id="preferences" role="tabpanel">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>User Preferences</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                    <div class="mb-3">
                                        <label for="theme" class="form-label">Theme Preference</label>
                                        <select class="form-select" id="theme" name="theme">
                                            <option value="light" <?php echo $theme == 'light' ? 'selected' : ''; ?>>Light Mode</option>
                                            <option value="dark" <?php echo $theme == 'dark' ? 'selected' : ''; ?>>Dark Mode</option>
                                            <option value="system" <?php echo $theme == 'system' ? 'selected' : ''; ?>>Use System Preference</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="reminder_days" class="form-label">Assignment Reminder Days</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="reminder_days" name="reminder_days" 
                                                min="0" max="30" value="<?php echo $reminder_days; ?>">
                                            <span class="input-group-text">days before due date</span>
                                        </div>
                                        <div class="form-text">How many days before deadline to receive reminders</div>
                                    </div>
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="email_notifications" name="email_notifications" 
                                            <?php echo $email_notifications ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="email_notifications">
                                            Enable email notifications for upcoming deadlines
                                        </label>
                                    </div>
                                    <button type="submit" name="update_preferences" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Preferences
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Danger Zone -->
                    <div class="tab-pane fade" id="danger" role="tabpanel">
                        <div class="card border-danger">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Danger Zone</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-circle me-2"></i>Warning: The following actions are irreversible and will permanently delete your account and all associated data.
                                </div>
                                
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                                    <i class="fas fa-trash-alt me-2"></i>Delete Account
                                </button>
                                
                                <!-- Delete Account Modal -->
                                <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Account
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p class="fw-bold">Are you absolutely sure you want to delete your account?</p>
                                                <p>This action will:</p>
                                                <ul>
                                                    <li>Delete your user profile</li>
                                                    <li>Delete all your assignments and tasks</li>
                                                    <li>Delete all your subjects and academic term data</li>
                                                    <li>Remove all grades and analytics information</li>
                                                </ul>
                                                <p class="text-danger fw-bold">This action is irreversible!</p>
                                                
                                                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                                    <div class="mb-3">
                                                        <label for="confirmation_password" class="form-label">Enter your password to confirm:</label>
                                                        <input type="password" class="form-control" id="confirmation_password" name="confirmation_password" required>
                                                    </div>
                                                    <div class="d-grid">
                                                        <button type="submit" name="delete_account" class="btn btn-danger">
                                                            <i class="fas fa-trash-alt me-2"></i>Yes, Delete My Account
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3 mt-5">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> University Work Analyzer | Developed for Academic Tracking</p>
        </div>
    </footer>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Check password match
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>