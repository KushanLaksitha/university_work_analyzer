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
$email = "";
$password = "";
$error_message = "";

// Process login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    // Validate input
    if (empty($email) || empty($password)) {
        $error_message = "Both email and password are required.";
    } else {
        // Prepare SQL statement
        $stmt = $conn->prepare("SELECT id, first_name, last_name, password FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Password is correct, set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                
                // Redirect to dashboard
                header("Location: index.php");
                exit;
            } else {
                $error_message = "Invalid email or password.";
            }
        } else {
            $error_message = "Invalid email or password.";
        }
        
        $stmt->close();
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
    <title>Login - University Work Analyzer</title>
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
            <div class="col-md-8 col-lg-6">
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
                            
                            <h2 class="text-center mb-4">Sign In</h2>
                            
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="loginForm">
                                <div class="mb-4">
                                    <label for="email" class="form-label">Email Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-envelope"></i>
                                        </span>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($email); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <label for="password" class="form-label">Password</label>
                                        <a href="forgot_password.php" class="text-muted small">Forgot Password?</a>
                                    </div>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <button class="btn btn-outline-secondary toggle-password" type="button">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="mb-4 form-check">
                                    <input type="checkbox" class="form-check-input" id="rememberMe" name="remember">
                                    <label class="form-check-label" for="rememberMe">Remember me</label>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg" id="loginButton">
                                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                                    </button>
                                </div>
                            </form>
                            
                            <div class="text-center mt-4">
                                <p class="mb-0">Don't have an account? <a href="register.php" class="text-primary">Sign Up</a></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center text-muted mt-4">
                        <p>&copy; <?php echo date('Y'); ?> University Work Analyzer | Made for Students</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript -->
    <script src="js/login.js"></script>
</body>
</html>