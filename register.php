<?php
// Start session
session_start();

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Include database connection
require_once 'includes/db_connection.php';

// Initialize variables
$first_name = "";
$last_name = "";
$email = "";
$institution = "";
$major = "";
$error_message = "";
$success_message = "";

// Process registration form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $institution = trim($_POST['institution']);
    $major = trim($_POST['major']);
    
    // Validate input
    $errors = [];
    
    if (empty($first_name)) {
        $errors[] = "First name is required.";
    }
    
    if (empty($last_name)) {
        $errors[] = "Last name is required.";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $errors[] = "Email already exists. Please use a different email or try logging in.";
    }
    $stmt->close();
    
    // If no errors, create new user
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, institution, major) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $first_name, $last_name, $email, $hashed_password, $institution, $major);
        
        if ($stmt->execute()) {
            // Get the new user's ID
            $new_user_id = $conn->insert_id;
            
            // Create default preferences for the new user
            $pref_stmt = $conn->prepare("INSERT INTO preferences (user_id) VALUES (?)");
            $pref_stmt->bind_param("i", $new_user_id);
            $pref_stmt->execute();
            $pref_stmt->close();
            
            // Create a default academic term for the new user
            $current_month = date('n');
            $current_year = date('Y');
            
            // Determine the current term based on the month
            if ($current_month >= 1 && $current_month <= 5) {
                $term_name = "Spring " . $current_year;
                $start_date = $current_year . "-01-15";
                $end_date = $current_year . "-05-15";
            } elseif ($current_month >= 6 && $current_month <= 7) {
                $term_name = "Summer " . $current_year;
                $start_date = $current_year . "-06-01";
                $end_date = $current_year . "-07-31";
            } else {
                $term_name = "Fall " . $current_year;
                $start_date = $current_year . "-09-01";
                $end_date = $current_year . "-12-15";
            }
            
            $is_current = 1; // Set as current term
            
            $term_stmt = $conn->prepare("INSERT INTO academic_terms (user_id, term_name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?)");
            $term_stmt->bind_param("isssi", $new_user_id, $term_name, $start_date, $end_date, $is_current);
            $term_stmt->execute();
            $term_stmt->close();
            
            // Registration successful
            $success_message = "Registration successful! You can now log in.";
            
            // Clear form data
            $first_name = $last_name = $email = $institution = $major = "";
        } else {
            $error_message = "Registration failed. Please try again later.";
        }
        
        $stmt->close();
    } else {
        // Join all error messages
        $error_message = implode("<br>", $errors);
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
    <title>Register - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="login-card">
                    <div class="card shadow-lg">
                        <div class="card-header bg-primary text-white text-center py-4">
                            <h1 class="h3 mb-0">
                                <i class="fas fa-graduation-cap me-2"></i>
                                University Work Analyzer
                            </h1>
                        </div>
                        <div class="card-body p-5">
                            <?php if (!empty($error_message)): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($success_message)): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <div class="text-center mb-4">
                                    <a href="login.php" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                                    </a>
                                </div>
                            <?php else: ?>
                                <h2 class="text-center mb-4">Create Account</h2>
                                
                                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="registerForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">
                                                        <i class="fas fa-user"></i>
                                                    </span>
                                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                                           value="<?php echo htmlspecialchars($first_name); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">
                                                        <i class="fas fa-user"></i>
                                                    </span>
                                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                                           value="<?php echo htmlspecialchars($last_name); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-envelope"></i>
                                            </span>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars($email); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">
                                                        <i class="fas fa-lock"></i>
                                                    </span>
                                                    <input type="password" class="form-control" id="password" name="password" required>
                                                    <button class="btn btn-outline-secondary toggle-password" type="button">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                                <div class="form-text">Password must be at least 8 characters.</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">
                                                        <i class="fas fa-lock"></i>
                                                    </span>
                                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                                    <button class="btn btn-outline-secondary toggle-password" type="button">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="institution" class="form-label">Institution</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">
                                                        <i class="fas fa-university"></i>
                                                    </span>
                                                    <input type="text" class="form-control" id="institution" name="institution" 
                                                           value="<?php echo htmlspecialchars($institution); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="major" class="form-label">Major/Field of Study</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">
                                                        <i class="fas fa-book"></i>
                                                    </span>
                                                    <input type="text" class="form-control" id="major" name="major" 
                                                           value="<?php echo htmlspecialchars($major); ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4 form-check">
                                        <input type="checkbox" class="form-check-input" id="terms" name="terms" required>
                                        <label class="form-check-label" for="terms">
                                            I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a>
                                        </label>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg" id="registerButton">
                                            <i class="fas fa-user-plus me-2"></i>Register
                                        </button>
                                    </div>
                                </form>
                                
                                <div class="text-center mt-4">
                                    <p class="mb-0">Already have an account? <a href="login.php" class="text-primary">Sign In</a></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="text-center text-muted mt-4">
                        <p>&copy; <?php echo date('Y'); ?> University Work Analyzer | Made for Students</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Terms and Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="termsModalLabel">Terms and Conditions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h5>1. Acceptance of Terms</h5>
                    <p>By registering for an account with University Work Analyzer, you agree to be bound by these Terms and Conditions.</p>
                    
                    <h5>2. User Registration</h5>
                    <p>You agree to provide accurate and complete information when creating your account and to update such information to keep it accurate and current.</p>
                    
                    <h5>3. Privacy Policy</h5>
                    <p>Your use of this service is also governed by our Privacy Policy, which can be found on our website.</p>
                    
                    <h5>4. User Content</h5>
                    <p>You retain all rights to your content that you upload, submit, or display on or through the service.</p>
                    
                    <h5>5. Account Security</h5>
                    <p>You are responsible for safeguarding the password that you use to access the service and for any activities or actions under your password.</p>
                    
                    <h5>6. Termination</h5>
                    <p>We reserve the right to suspend or terminate your account at any time for any reason, without notice or liability.</p>
                    
                    <h5>7. Changes to Terms</h5>
                    <p>We reserve the right to modify these terms at any time. We will provide notice of any significant changes by posting the new Terms on the service.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="acceptTerms">Accept</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript -->
    <script src="js/register.js"></script>
</body>
</html>