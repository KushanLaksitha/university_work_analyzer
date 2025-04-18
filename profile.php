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
$message = '';
$success = false;

// Handle form submission for profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $institution = trim($_POST['institution']);
    $major = trim($_POST['major']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $message = "First name, last name, and email are required fields.";
    } else {
        // Start transaction
        $conn->begin_transaction();
        try {
            // Check if email already exists for another user
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            if ($stmt === false) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $message = "Email address is already in use by another account.";
            } else {
                // Update basic profile information
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, 
                                      institution = ?, major = ? WHERE id = ?");
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("sssssi", $first_name, $last_name, $email, $institution, $major, $user_id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                
                // Password change functionality (if requested)
                if (!empty($current_password) && !empty($new_password)) {
                    if ($new_password !== $confirm_password) {
                        throw new Exception("New password and confirm password do not match.");
                    }
                    
                    // Verify current password
                    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                    if ($stmt === false) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user = $result->fetch_assoc();
                    
                    if (!password_verify($current_password, $user['password'])) {
                        throw new Exception("Current password is incorrect.");
                    }
                    
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if ($stmt === false) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                }
                
                // Commit transaction
                $conn->commit();
                $message = "Profile updated successfully!";
                $success = true;
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Get current user data
$stmt = $conn->prepare("SELECT first_name, last_name, email, institution, major, created_at 
                       FROM users WHERE id = ?");
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

// Get statistics for profile
// Total assignments
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM assignments WHERE user_id = ?");
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total_assignments = $result->fetch_assoc()['total'];
$stmt->close();

// Completed assignments
$stmt = $conn->prepare("SELECT COUNT(*) as completed FROM assignments WHERE user_id = ? AND (status = 'Completed' OR status = 'Graded')");
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$completed_assignments = $result->fetch_assoc()['completed'];
$stmt->close();

// Average GPA
$stmt = $conn->prepare("SELECT AVG(gpa) as avg_gpa FROM academic_terms WHERE user_id = ? AND gpa IS NOT NULL");
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$avg_gpa_data = $result->fetch_assoc();
$avg_gpa = $avg_gpa_data['avg_gpa'] ?? 0;
$stmt->close();

// Count number of subjects
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM subjects WHERE user_id = ?");
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total_subjects = $result->fetch_assoc()['total'];
$stmt->close();

// Member since date
$member_since = date('F Y', strtotime($user_data['created_at']));

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - University Work Analyzer</title>
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
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item active" href="profile.php"><i class="fas fa-id-card me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
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
        <h1 class="mb-4"><i class="fas fa-user-circle me-2"></i>My Profile</h1>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column - Profile Stats -->
            <div class="col-lg-4 mb-4">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Profile Summary</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-4">
                            <i class="fas fa-user-graduate fa-5x text-primary mb-3"></i>
                            <h4><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h4>
                            <p class="text-muted">
                                <?php 
                                    if (!empty($user_data['institution']) && !empty($user_data['major'])) {
                                        echo htmlspecialchars($user_data['institution'] . ' - ' . $user_data['major']);
                                    } elseif (!empty($user_data['institution'])) {
                                        echo htmlspecialchars($user_data['institution']);
                                    } elseif (!empty($user_data['major'])) {
                                        echo htmlspecialchars($user_data['major']);
                                    } else {
                                        echo 'No institution or major specified';
                                    }
                                ?>
                            </p>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-4">
                                <h5 class="fw-bold"><?php echo $total_assignments; ?></h5>
                                <small class="text-muted">Assignments</small>
                            </div>
                            <div class="col-4">
                                <h5 class="fw-bold"><?php echo $completed_assignments; ?></h5>
                                <small class="text-muted">Completed</small>
                            </div>
                            <div class="col-4">
                                <h5 class="fw-bold"><?php echo number_format($avg_gpa, 2); ?></h5>
                                <small class="text-muted">Avg. GPA</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-calendar-alt me-2"></i>Member since:</span>
                            <span class="fw-bold"><?php echo $member_since; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Academic Information</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-envelope me-2"></i>Email:</span>
                                <span class="text-truncate ms-2"><?php echo htmlspecialchars($user_data['email']); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-university me-2"></i>Institution:</span>
                                <span><?php echo !empty($user_data['institution']) ? htmlspecialchars($user_data['institution']) : 'Not specified'; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-graduation-cap me-2"></i>Major:</span>
                                <span><?php echo !empty($user_data['major']) ? htmlspecialchars($user_data['major']) : 'Not specified'; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-book me-2"></i>Subjects:</span>
                                <span class="badge bg-primary rounded-pill"><?php echo $total_subjects; ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Edit Profile Form -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-edit me-2"></i>Edit Profile</h5>
                    </div>
                    <div class="card-body">
                        <form action="profile.php" method="POST">
                            <div class="row mb-3">
                                <div class="col-md-6 mb-3 mb-md-0">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6 mb-3 mb-md-0">
                                    <label for="institution" class="form-label">Institution</label>
                                    <input type="text" class="form-control" id="institution" name="institution" value="<?php echo htmlspecialchars($user_data['institution'] ?? ''); ?>" placeholder="e.g., State University">
                                </div>
                                <div class="col-md-6">
                                    <label for="major" class="form-label">Major</label>
                                    <input type="text" class="form-control" id="major" name="major" value="<?php echo htmlspecialchars($user_data['major'] ?? ''); ?>" placeholder="e.g., Computer Science">
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            <h5><i class="fas fa-key me-2"></i>Change Password</h5>
                            <p class="text-muted small">Leave blank if you don't want to change your password.</p>
                            
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password">
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6 mb-3 mb-md-0">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                </div>
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="index.php" class="btn btn-outline-secondary me-md-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> University Work Analyzer | Developed for Academic Tracking</p>
        </div>
    </footer>
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>