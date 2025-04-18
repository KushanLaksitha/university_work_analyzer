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
$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($first_name, $last_name);
$stmt->fetch();
$stmt->close();

// Initialize variables
$success_message = '';
$error_message = '';
$term_id = '';
$term_name = '';
$start_date = '';
$end_date = '';
$gpa = '';
$is_current = 0;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new term
        if ($_POST['action'] === 'add') {
            $term_name = trim($_POST['term_name']);
            $start_date = trim($_POST['start_date']);
            $end_date = trim($_POST['end_date']);
            $gpa = !empty($_POST['gpa']) ? trim($_POST['gpa']) : NULL;
            $is_current = isset($_POST['is_current']) ? 1 : 0;
            
            // Validation
            if (empty($term_name) || empty($start_date) || empty($end_date)) {
                $error_message = "Please fill in all required fields.";
            } elseif ($start_date > $end_date) {
                $error_message = "Start date cannot be after end date.";
            } elseif (!empty($gpa) && ($gpa < 0 || $gpa > 4.0)) {
                $error_message = "GPA must be between 0 and 4.0.";
            } else {
                // If is_current is true, set all other terms to not current
                if ($is_current) {
                    $update_stmt = $conn->prepare("UPDATE academic_terms SET is_current = 0 WHERE user_id = ?");
                    $update_stmt->bind_param("i", $user_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                
                // Insert new term
                $stmt = $conn->prepare("INSERT INTO academic_terms (user_id, term_name, start_date, end_date, gpa, is_current) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssdi", $user_id, $term_name, $start_date, $end_date, $gpa, $is_current);
                
                if ($stmt->execute()) {
                    $success_message = "Academic term added successfully!";
                    // Clear form fields
                    $term_name = '';
                    $start_date = '';
                    $end_date = '';
                    $gpa = '';
                    $is_current = 0;
                } else {
                    $error_message = "Error adding academic term: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        
        // Edit existing term
        else if ($_POST['action'] === 'edit') {
            $term_id = $_POST['term_id'];
            $term_name = trim($_POST['term_name']);
            $start_date = trim($_POST['start_date']);
            $end_date = trim($_POST['end_date']);
            $gpa = !empty($_POST['gpa']) ? trim($_POST['gpa']) : NULL;
            $is_current = isset($_POST['is_current']) ? 1 : 0;
            
            // Validation
            if (empty($term_name) || empty($start_date) || empty($end_date)) {
                $error_message = "Please fill in all required fields.";
            } elseif ($start_date > $end_date) {
                $error_message = "Start date cannot be after end date.";
            } elseif (!empty($gpa) && ($gpa < 0 || $gpa > 4.0)) {
                $error_message = "GPA must be between 0 and 4.0.";
            } else {
                // If is_current is true, set all other terms to not current
                if ($is_current) {
                    $update_stmt = $conn->prepare("UPDATE academic_terms SET is_current = 0 WHERE user_id = ?");
                    $update_stmt->bind_param("i", $user_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                
                // Update term
                $stmt = $conn->prepare("UPDATE academic_terms SET term_name = ?, start_date = ?, end_date = ?, gpa = ?, is_current = ? WHERE id = ? AND user_id = ?");
                $stmt->bind_param("sssdiis", $term_name, $start_date, $end_date, $gpa, $is_current, $term_id, $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Academic term updated successfully!";
                    // Reset form data
                    $term_id = '';
                    $term_name = '';
                    $start_date = '';
                    $end_date = '';
                    $gpa = '';
                    $is_current = 0;
                } else {
                    $error_message = "Error updating academic term: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        
        // Delete term
        else if ($_POST['action'] === 'delete') {
            $term_id = $_POST['term_id'];
            
            // First check if there are any grades associated with this term
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM grades WHERE term_id = ? AND user_id = ?");
            $check_stmt->bind_param("ii", $term_id, $user_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $grade_count = $result->fetch_assoc()['count'];
            $check_stmt->close();
            
            if ($grade_count > 0) {
                $error_message = "Cannot delete this term because it has associated grades. Please delete those grades first.";
            } else {
                $stmt = $conn->prepare("DELETE FROM academic_terms WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $term_id, $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Academic term deleted successfully!";
                } else {
                    $error_message = "Error deleting academic term: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Handle edit request (GET)
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $term_id = $_GET['id'];
    $stmt = $conn->prepare("SELECT term_name, start_date, end_date, gpa, is_current FROM academic_terms WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $term_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $term_name = $row['term_name'];
        $start_date = $row['start_date'];
        $end_date = $row['end_date'];
        $gpa = $row['gpa'];
        $is_current = $row['is_current'];
    }
    $stmt->close();
}

// Get all academic terms for this user
$terms = [];
$stmt = $conn->prepare("SELECT id, term_name, start_date, end_date, gpa, is_current FROM academic_terms WHERE user_id = ? ORDER BY start_date DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $terms[] = $row;
}
$stmt->close();

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Terms - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap Datepicker -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
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
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="fas fa-calendar-alt me-2"></i>Academic Terms</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#termModal">
                        <i class="fas fa-plus-circle me-2"></i>Add New Term
                    </button>
                </div>
                
                <!-- Alert Messages -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Academic Terms List -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Your Academic Terms</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($terms) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Term Name</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>GPA</th>
                                            <th>Current Term</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($terms as $term): ?>
                                            <tr <?php echo $term['is_current'] ? 'class="table-primary"' : ''; ?>>
                                                <td><?php echo htmlspecialchars($term['term_name']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($term['start_date'])); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($term['end_date'])); ?></td>
                                                <td>
                                                    <?php if ($term['gpa'] !== NULL): ?>
                                                        <span class="badge bg-<?php 
                                                            $gpa = floatval($term['gpa']);
                                                            if ($gpa >= 3.5) echo 'success';
                                                            elseif ($gpa >= 3.0) echo 'info';
                                                            elseif ($gpa >= 2.0) echo 'warning';
                                                            else echo 'danger';
                                                        ?>">
                                                            <?php echo number_format($term['gpa'], 2); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($term['is_current']): ?>
                                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Current</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="academic_terms.php?action=edit&id=<?php echo $term['id']; ?>" class="btn btn-outline-primary">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-outline-danger" 
                                                                data-bs-toggle="modal" data-bs-target="#deleteConfirmModal" 
                                                                data-id="<?php echo $term['id']; ?>" 
                                                                data-name="<?php echo htmlspecialchars($term['term_name']); ?>">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-calendar-times text-muted fa-4x mb-3"></i>
                                <p class="lead">You haven't added any academic terms yet.</p>
                                <p>Academic terms help you organize your assignments and track your GPA across semesters or quarters.</p>
                                <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#termModal">
                                    <i class="fas fa-plus-circle me-2"></i>Add Your First Term
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Tips Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Tips for Managing Academic Terms</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-lightbulb text-warning fa-2x me-3"></i>
                                    </div>
                                    <div>
                                        <h6>Set Current Term</h6>
                                        <p class="text-muted small">Mark your current academic term to quickly filter assignments and track progress for this period.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-chart-line text-success fa-2x me-3"></i>
                                    </div>
                                    <div>
                                        <h6>Track Your GPA</h6>
                                        <p class="text-muted small">Enter your GPA for each term to visualize your academic progress over time.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-calendar-check text-primary fa-2x me-3"></i>
                                    </div>
                                    <div>
                                        <h6>Plan Ahead</h6>
                                        <p class="text-muted small">Create future terms in advance to better organize upcoming assignments and exams.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Term Modal -->
    <div class="modal fade" id="termModal" tabindex="-1" aria-labelledby="termModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="termModalLabel">
                        <?php echo empty($term_id) ? 'Add New Academic Term' : 'Edit Academic Term'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="academic_terms.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="<?php echo empty($term_id) ? 'add' : 'edit'; ?>">
                        <?php if (!empty($term_id)): ?>
                            <input type="hidden" name="term_id" value="<?php echo $term_id; ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="term_name" class="form-label">Term Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="term_name" name="term_name" 
                                   value="<?php echo htmlspecialchars($term_name); ?>" required>
                            <div class="form-text">Example: Spring 2025, Fall Semester 2024, etc.</div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="start_date" name="start_date" 
                                       value="<?php echo $start_date; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="end_date" name="end_date" 
                                       value="<?php echo $end_date; ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="gpa" class="form-label">GPA</label>
                            <input type="number" class="form-control" id="gpa" name="gpa" 
                                   min="0" max="4" step="0.01" value="<?php echo $gpa; ?>">
                            <div class="form-text">Leave blank if GPA is not available yet.</div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_current" name="is_current" 
                                   <?php echo $is_current ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_current">This is my current academic term</label>
                            <div class="form-text text-muted">Only one term can be marked as current.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <?php echo empty($term_id) ? 'Add Term' : 'Save Changes'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the term "<span id="termToDelete"></span>"?</p>
                    <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <form method="post" action="academic_terms.php">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" id="delete_term_id" name="term_id" value="">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
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
    <!-- jQuery (needed for Bootstrap plugins) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap Datepicker -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
    
    <script>
        // Show the term modal if editing
        <?php if (isset($_GET['action']) && $_GET['action'] === 'edit'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            let termModal = new bootstrap.Modal(document.getElementById('termModal'));
            termModal.show();
        });
        <?php endif; ?>
        
        // Set up the delete confirmation modal
        document.addEventListener('DOMContentLoaded', function() {
            let deleteConfirmModal = document.getElementById('deleteConfirmModal');
            deleteConfirmModal.addEventListener('show.bs.modal', function(event) {
                let button = event.relatedTarget;
                let id = button.getAttribute('data-id');
                let name = button.getAttribute('data-name');
                
                document.getElementById('termToDelete').textContent = name;
                document.getElementById('delete_term_id').value = id;
            });
        });
    </script>
</body>
</html>