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
$subject_id = null;
$subject_code = '';
$subject_name = '';
$instructor = '';
$credits = 3.0;
$color_code = '#4285F4';
$is_active = true;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$alert_message = '';
$alert_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new subject
    if (isset($_POST['add_subject'])) {
        $subject_code = trim($_POST['subject_code']);
        $subject_name = trim($_POST['subject_name']);
        $instructor = trim($_POST['instructor']);
        $credits = floatval($_POST['credits']);
        $color_code = trim($_POST['color_code']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate inputs
        if (empty($subject_code) || empty($subject_name)) {
            $alert_message = "Subject code and name are required!";
            $alert_type = "danger";
        } else {
            // Check if subject already exists for this user
            $stmt = $conn->prepare("SELECT id FROM subjects WHERE user_id = ? AND subject_code = ?");
            $stmt->bind_param("is", $user_id, $subject_code);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $alert_message = "A subject with this code already exists!";
                $alert_type = "danger";
            } else {
                // Insert new subject
                $stmt = $conn->prepare("INSERT INTO subjects (user_id, subject_code, subject_name, instructor, credits, color_code, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssdsi", $user_id, $subject_code, $subject_name, $instructor, $credits, $color_code, $is_active);
                if ($stmt->execute()) {
                    $alert_message = "Subject added successfully!";
                    $alert_type = "success";
                    // Reset form values
                    $subject_code = '';
                    $subject_name = '';
                    $instructor = '';
                    $credits = 3.0;
                    $color_code = '#4285F4';
                    $is_active = true;
                } else {
                    $alert_message = "Error adding subject: " . $conn->error;
                    $alert_type = "danger";
                }
            }
            $stmt->close();
        }
    }

    // Update subject
    if (isset($_POST['update_subject'])) {
        $subject_id = $_POST['subject_id'];
        $subject_code = trim($_POST['subject_code']);
        $subject_name = trim($_POST['subject_name']);
        $instructor = trim($_POST['instructor']);
        $credits = floatval($_POST['credits']);
        $color_code = trim($_POST['color_code']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate inputs
        if (empty($subject_code) || empty($subject_name)) {
            $alert_message = "Subject code and name are required!";
            $alert_type = "danger";
        } else {
            // Check if another subject with the same code exists for this user
            $stmt = $conn->prepare("SELECT id FROM subjects WHERE user_id = ? AND subject_code = ? AND id != ?");
            $stmt->bind_param("isi", $user_id, $subject_code, $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $alert_message = "Another subject with this code already exists!";
                $alert_type = "danger";
            } else {
                // Update subject
                $stmt = $conn->prepare("UPDATE subjects SET subject_code = ?, subject_name = ?, instructor = ?, credits = ?, color_code = ?, is_active = ? WHERE id = ? AND user_id = ?");
                $stmt->bind_param("sssdsiii", $subject_code, $subject_name, $instructor, $credits, $color_code, $is_active, $subject_id, $user_id);
                if ($stmt->execute()) {
                    $alert_message = "Subject updated successfully!";
                    $alert_type = "success";
                    $action = ''; // Reset action to show subject list
                } else {
                    $alert_message = "Error updating subject: " . $conn->error;
                    $alert_type = "danger";
                }
            }
            $stmt->close();
        }
    }

    // Delete subject
    if (isset($_POST['delete_subject'])) {
        $subject_id = $_POST['subject_id'];
        
        // Check if subject has assignments
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments WHERE subject_id = ?");
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $assignment_count = $row['count'];
        $stmt->close();
        
        if ($assignment_count > 0) {
            $alert_message = "Cannot delete subject. There are $assignment_count assignments associated with this subject.";
            $alert_type = "danger";
        } else {
            // Delete subject
            $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $subject_id, $user_id);
            if ($stmt->execute()) {
                $alert_message = "Subject deleted successfully!";
                $alert_type = "success";
            } else {
                $alert_message = "Error deleting subject: " . $conn->error;
                $alert_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Handle edit request
if ($action === 'edit' && isset($_GET['id'])) {
    $subject_id = $_GET['id'];
    
    // Get subject details
    $stmt = $conn->prepare("SELECT subject_code, subject_name, instructor, credits, color_code, is_active FROM subjects WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $subject_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($subject_code, $subject_name, $instructor, $credits, $color_code, $is_active);
    $stmt->fetch();
    $stmt->close();
}

// Get all subjects
$subjects = [];
$stmt = $conn->prepare("SELECT s.id, s.subject_code, s.subject_name, s.instructor, s.credits, s.color_code, s.is_active, 
                       (SELECT COUNT(*) FROM assignments a WHERE a.subject_id = s.id) as assignment_count,
                       (SELECT COUNT(*) FROM assignments a WHERE a.subject_id = s.id AND a.status = 'Completed') as completed_count
                       FROM subjects s 
                       WHERE s.user_id = ? 
                       ORDER BY s.is_active DESC, s.subject_name ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
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
    <title>Subjects - University Work Analyzer</title>
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
                        <a class="nav-link active" href="subjects.php">Subjects</a>
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
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <?php if ($action === 'add'): ?>
                                <i class="fas fa-plus-circle me-2"></i>Add New Subject
                            <?php elseif ($action === 'edit'): ?>
                                <i class="fas fa-edit me-2"></i>Edit Subject
                            <?php else: ?>
                                <i class="fas fa-book me-2"></i>My Subjects
                            <?php endif; ?>
                        </h5>
                        <?php if ($action !== 'add' && $action !== 'edit'): ?>
                            <a href="subjects.php?action=add" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus-circle me-1"></i> Add New Subject
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($alert_message)): ?>
                            <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                                <?php echo $alert_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($action === 'add' || $action === 'edit'): ?>
                            <!-- Subject Form -->
                            <form method="post" action="subjects.php">
                                <?php if ($action === 'edit'): ?>
                                    <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                                <?php endif; ?>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="subject_code" class="form-label">Subject Code*</label>
                                        <input type="text" class="form-control" id="subject_code" name="subject_code" value="<?php echo htmlspecialchars($subject_code); ?>" required>
                                        <div class="form-text">Example: CS301, MATH201</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="subject_name" class="form-label">Subject Name*</label>
                                        <input type="text" class="form-control" id="subject_name" name="subject_name" value="<?php echo htmlspecialchars($subject_name); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="instructor" class="form-label">Instructor</label>
                                        <input type="text" class="form-control" id="instructor" name="instructor" value="<?php echo htmlspecialchars($instructor); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="credits" class="form-label">Credits</label>
                                        <input type="number" class="form-control" id="credits" name="credits" min="0" step="0.5" value="<?php echo $credits; ?>">
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="color_code" class="form-label">Color Code</label>
                                        <div class="input-group">
                                            <input type="color" class="form-control form-control-color" id="color_code" name="color_code" value="<?php echo $color_code; ?>">
                                            <input type="text" class="form-control" id="color_code_text" value="<?php echo $color_code; ?>" readonly>
                                        </div>
                                        <div class="form-text">Choose a color to represent this subject</div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?php echo $is_active ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">
                                                This is an active subject
                                            </label>
                                            <div class="form-text">Inactive subjects won't appear in most selection lists</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <?php if ($action === 'add'): ?>
                                        <button type="submit" name="add_subject" class="btn btn-primary">
                                            <i class="fas fa-plus-circle me-1"></i> Add Subject
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="update_subject" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Update Subject
                                        </button>
                                    <?php endif; ?>
                                    <a href="subjects.php" class="btn btn-secondary ms-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- Subjects List -->
                            <?php if (empty($subjects)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-book text-muted fa-4x mb-3"></i>
                                    <h5>No subjects found</h5>
                                    <p class="text-muted">You haven't added any subjects yet.</p>
                                    <a href="subjects.php?action=add" class="btn btn-primary">
                                        <i class="fas fa-plus-circle me-1"></i> Add Your First Subject
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Color</th>
                                                <th>Code</th>
                                                <th>Name</th>
                                                <th>Instructor</th>
                                                <th>Credits</th>
                                                <th>Progress</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($subjects as $subject): ?>
                                                <tr class="<?php echo $subject['is_active'] ? '' : 'text-muted'; ?>">
                                                    <td>
                                                        <div class="color-circle" style="background-color: <?php echo htmlspecialchars($subject['color_code']); ?>"></div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($subject['instructor']); ?></td>
                                                    <td><?php echo $subject['credits']; ?></td>
                                                    <td>
                                                        <?php if ($subject['assignment_count'] > 0): ?>
                                                            <div class="progress" style="height: 10px;">
                                                                <?php $progress = ($subject['completed_count'] / $subject['assignment_count']) * 100; ?>
                                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $progress; ?>%;" 
                                                                    aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100">
                                                                </div>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?php echo $subject['completed_count']; ?>/<?php echo $subject['assignment_count']; ?> completed
                                                            </small>
                                                        <?php else: ?>
                                                            <small class="text-muted">No assignments</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($subject['is_active']): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <a href="subjects.php?action=edit&id=<?php echo $subject['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <a href="assignments.php?subject_id=<?php echo $subject['id']; ?>" class="btn btn-sm btn-outline-info">
                                                                <i class="fas fa-tasks"></i>
                                                            </a>
                                                            <?php if ($subject['assignment_count'] == 0): ?>
                                                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $subject['id']; ?>">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Delete Modal -->
                                                <div class="modal fade" id="deleteModal<?php echo $subject['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $subject['id']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="deleteModalLabel<?php echo $subject['id']; ?>">Confirm Delete</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                Are you sure you want to delete the subject <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>? This action cannot be undone.
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <form method="post" action="subjects.php">
                                                                    <input type="hidden" name="subject_id" value="<?php echo $subject['id']; ?>">
                                                                    <button type="submit" name="delete_subject" class="btn btn-danger">Delete</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
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
    
    <!-- Custom Script -->
    <script>
        // Update text input when color picker changes
        document.getElementById('color_code').addEventListener('input', function() {
            document.getElementById('color_code_text').value = this.value;
        });
    </script>
    
    <!-- Style for color circles -->
    <style>
        .color-circle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-block;
        }
    </style>
</body>
</html>