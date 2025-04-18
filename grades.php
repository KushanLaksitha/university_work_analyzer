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

// Handle form submissions
$message = '';
$message_type = '';

// Add new grade
if (isset($_POST['add_grade'])) {
    $subject_id = $_POST['subject_id'];
    $term_id = $_POST['term_id'];
    $grade_type = $_POST['grade_type'];
    $grade_title = $_POST['grade_title'];
    $grade_value = $_POST['grade_value'];
    $max_grade = $_POST['max_grade'];
    $weight = $_POST['weight'];
    $grade_date = $_POST['grade_date'];
    $notes = $_POST['notes'];
    
    $stmt = $conn->prepare("INSERT INTO grades (user_id, subject_id, term_id, grade_type, grade_title, grade_value, max_grade, weight, grade_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiissdddss", $user_id, $subject_id, $term_id, $grade_type, $grade_title, $grade_value, $max_grade, $weight, $grade_date, $notes);
    
    if ($stmt->execute()) {
        $message = "Grade added successfully!";
        $message_type = "success";
        
        // Update term GPA calculation
        calculateAndUpdateTermGPA($conn, $user_id, $term_id);
    } else {
        $message = "Error adding grade: " . $conn->error;
        $message_type = "danger";
    }
    $stmt->close();
}

// Edit existing grade
if (isset($_POST['edit_grade'])) {
    $grade_id = $_POST['grade_id'];
    $subject_id = $_POST['subject_id'];
    $term_id = $_POST['term_id'];
    $grade_type = $_POST['grade_type'];
    $grade_title = $_POST['grade_title'];
    $grade_value = $_POST['grade_value'];
    $max_grade = $_POST['max_grade'];
    $weight = $_POST['weight'];
    $grade_date = $_POST['grade_date'];
    $notes = $_POST['notes'];
    
    $stmt = $conn->prepare("UPDATE grades SET subject_id = ?, term_id = ?, grade_type = ?, grade_title = ?, grade_value = ?, max_grade = ?, weight = ?, grade_date = ?, notes = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("iissdddssis", $subject_id, $term_id, $grade_type, $grade_title, $grade_value, $max_grade, $weight, $grade_date, $notes, $grade_id, $user_id);
    
    if ($stmt->execute()) {
        $message = "Grade updated successfully!";
        $message_type = "success";
        
        // Update term GPA calculation
        calculateAndUpdateTermGPA($conn, $user_id, $term_id);
    } else {
        $message = "Error updating grade: " . $conn->error;
        $message_type = "danger";
    }
    $stmt->close();
}

// Delete grade
if (isset($_POST['delete_grade'])) {
    $grade_id = $_POST['grade_id'];
    $term_id = $_POST['term_id'];
    
    $stmt = $conn->prepare("DELETE FROM grades WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $grade_id, $user_id);
    
    if ($stmt->execute()) {
        $message = "Grade deleted successfully!";
        $message_type = "success";
        
        // Update term GPA calculation
        calculateAndUpdateTermGPA($conn, $user_id, $term_id);
    } else {
        $message = "Error deleting grade: " . $conn->error;
        $message_type = "danger";
    }
    $stmt->close();
}

// Function to calculate and update term GPA
function calculateAndUpdateTermGPA($conn, $user_id, $term_id) {
    // Get all grades for the term with their weights
    $stmt = $conn->prepare("SELECT grade_value, max_grade, weight FROM grades WHERE user_id = ? AND term_id = ?");
    $stmt->bind_param("ii", $user_id, $term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $total_points = 0;
    $total_weight = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Convert to 4.0 scale (assuming max_grade is 100)
        $percentage = ($row['grade_value'] / $row['max_grade']) * 100;
        $gpa_points = percentageToGPA($percentage);
        
        $total_points += $gpa_points * $row['weight'];
        $total_weight += $row['weight'];
    }
    
    $stmt->close();
    
    // Calculate weighted GPA
    $term_gpa = ($total_weight > 0) ? ($total_points / $total_weight) : 0;
    
    // Update the term's GPA
    $stmt = $conn->prepare("UPDATE academic_terms SET gpa = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("dii", $term_gpa, $term_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

// Helper function to convert percentage to 4.0 GPA scale
function percentageToGPA($percentage) {
    if ($percentage >= 93) return 4.0;
    else if ($percentage >= 90) return 3.7;
    else if ($percentage >= 87) return 3.3;
    else if ($percentage >= 83) return 3.0;
    else if ($percentage >= 80) return 2.7;
    else if ($percentage >= 77) return 2.3;
    else if ($percentage >= 73) return 2.0;
    else if ($percentage >= 70) return 1.7;
    else if ($percentage >= 67) return 1.3;
    else if ($percentage >= 63) return 1.0;
    else if ($percentage >= 60) return 0.7;
    else return 0.0;
}

// Get all subjects for this user
$stmt = $conn->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE user_id = ? ORDER BY subject_name");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

// Get all academic terms for this user
$stmt = $conn->prepare("SELECT id, term_name, gpa FROM academic_terms WHERE user_id = ? ORDER BY start_date DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$terms_result = $stmt->get_result();
$terms = [];
while ($row = $terms_result->fetch_assoc()) {
    $terms[] = $row;
}
$stmt->close();

// Get grades based on filter
$term_filter = isset($_GET['term']) ? $_GET['term'] : (count($terms) > 0 ? $terms[0]['id'] : 0);
$subject_filter = isset($_GET['subject']) ? $_GET['subject'] : 'all';

$query = "SELECT g.id, g.grade_type, g.grade_title, g.grade_value, g.max_grade, g.weight, g.grade_date, g.notes, 
           s.subject_name, s.subject_code, s.color_code, t.term_name 
           FROM grades g 
           JOIN subjects s ON g.subject_id = s.id 
           JOIN academic_terms t ON g.term_id = t.id 
           WHERE g.user_id = ? ";

$types = "i";
$params = array($user_id);

if ($term_filter > 0) {
    $query .= "AND g.term_id = ? ";
    $types .= "i";
    $params[] = $term_filter;
}

if ($subject_filter != 'all') {
    $query .= "AND g.subject_id = ? ";
    $types .= "i";
    $params[] = $subject_filter;
}

$query .= "ORDER BY g.grade_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$grades_result = $stmt->get_result();

$grades = [];
while ($row = $grades_result->fetch_assoc()) {
    $grades[] = $row;
}
$stmt->close();

// Get grade for editing if requested
$edit_grade = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM grades WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    if ($edit_result->num_rows == 1) {
        $edit_grade = $edit_result->fetch_assoc();
    }
    $stmt->close();
}

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grades - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Flatpickr for date picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
                        <a class="nav-link active" href="grades.php">Grades</a>
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
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="card-title"><i class="fas fa-chart-line me-2"></i>Grade Management</h2>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGradeModal">
                                <i class="fas fa-plus-circle me-2"></i>Add New Grade
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-5">
                                <label for="term" class="form-label">Academic Term</label>
                                <select class="form-select" id="term" name="term" onchange="this.form.submit()">
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo ($term_filter == $term['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?> 
                                            <?php if ($term['gpa'] !== null): ?>
                                                (GPA: <?php echo number_format($term['gpa'], 2); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="subject" class="form-label">Subject</label>
                                <select class="form-select" id="subject" name="subject" onchange="this.form.submit()">
                                    <option value="all" <?php echo ($subject_filter == 'all') ? 'selected' : ''; ?>>All Subjects</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>" <?php echo ($subject_filter == $subject['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-2"></i>Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grade Cards -->
        <div class="row">
            <?php if (count($grades) > 0): ?>
                <?php foreach ($grades as $grade): ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header" style="background-color: <?php echo htmlspecialchars($grade['color_code']); ?>; color: white;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><?php echo htmlspecialchars($grade['grade_title']); ?></h5>
                                    <span class="badge bg-light text-dark">
                                        <?php echo htmlspecialchars($grade['grade_type']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2 text-muted"><?php echo htmlspecialchars($grade['subject_code'] . ' - ' . $grade['subject_name']); ?></h6>
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <?php
                                                    $percentage = ($grade['grade_value'] / $grade['max_grade']) * 100;
                                                    if ($percentage >= 90) {
                                                        $grade_color = "success";
                                                    } elseif ($percentage >= 80) {
                                                        $grade_color = "primary";
                                                    } elseif ($percentage >= 70) {
                                                        $grade_color = "warning";
                                                    } else {
                                                        $grade_color = "danger";
                                                    }
                                                ?>
                                                <div class="text-center">
                                                    <div class="rounded-circle bg-<?php echo $grade_color; ?> text-white d-flex align-items-center justify-content-center" style="width: 3.5rem; height: 3.5rem;">
                                                        <span class="h5 mb-0"><?php echo number_format($percentage, 0); ?>%</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="h4 mb-0"><?php echo htmlspecialchars($grade['grade_value']); ?> / <?php echo htmlspecialchars($grade['max_grade']); ?></div>
                                                <small class="text-muted">Score</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-end">
                                            <div class="mb-1">
                                                <span class="fw-bold">Weight:</span> <?php echo htmlspecialchars($grade['weight']); ?>%
                                            </div>
                                            <div>
                                                <span class="fw-bold">Date:</span> <?php echo date('M j, Y', strtotime($grade['grade_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($grade['notes'])): ?>
                                    <div class="mb-3">
                                        <small class="text-muted">Notes:</small>
                                        <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($grade['notes'])); ?></p>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between mt-3">
                                    <small class="text-muted"><?php echo htmlspecialchars($grade['term_name']); ?></small>
                                    <div>
                                        <a href="grades.php?edit=<?php echo $grade['id']; ?>&term=<?php echo $term_filter; ?>&subject=<?php echo $subject_filter; ?>" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $grade['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Delete Confirmation Modal -->
                    <div class="modal fade" id="deleteModal<?php echo $grade['id']; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Confirm Deletion</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    Are you sure you want to delete the grade entry for <strong><?php echo htmlspecialchars($grade['grade_title']); ?></strong>?
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <form method="post">
                                        <input type="hidden" name="grade_id" value="<?php echo $grade['id']; ?>">
                                        <input type="hidden" name="term_id" value="<?php echo $term_filter; ?>">
                                        <button type="submit" name="delete_grade" class="btn btn-danger">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-chart-line text-muted fa-4x mb-3"></i>
                            <h3>No grades found</h3>
                            <p class="lead">Start tracking your academic performance by adding grades.</p>
                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addGradeModal">
                                <i class="fas fa-plus-circle me-2"></i>Add Your First Grade
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Grade Modal -->
    <div class="modal fade" id="addGradeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Grade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="subject_id" class="form-label">Subject</label>
                                <select class="form-select" id="subject_id" name="subject_id" required>
                                    <option value="">Select Subject</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>">
                                            <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="term_id" class="form-label">Academic Term</label>
                                <select class="form-select" id="term_id" name="term_id" required>
                                    <option value="">Select Term</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo ($term_filter == $term['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="grade_title" class="form-label">Grade Title</label>
                                <input type="text" class="form-control" id="grade_title" name="grade_title" placeholder="e.g., Midterm Exam, Quiz 1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="grade_type" class="form-label">Grade Type</label>
                                <select class="form-select" id="grade_type" name="grade_type" required>
                                    <option value="Midterm">Midterm</option>
                                    <option value="Final">Final</option>
                                    <option value="Assignment">Assignment</option>
                                    <option value="Quiz">Quiz</option>
                                    <option value="Project">Project</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="grade_value" class="form-label">Grade Value</label>
                                <input type="number" class="form-control" id="grade_value" name="grade_value" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label for="max_grade" class="form-label">Maximum Grade</label>
                                <input type="number" class="form-control" id="max_grade" name="max_grade" step="0.01" min="0" value="100" required>
                            </div>
                            <div class="col-md-4">
                                <label for="weight" class="form-label">Weight (%)</label>
                                <input type="number" class="form-control" id="weight" name="weight" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="grade_date" class="form-label">Grade Date</label>
                            <input type="date" class="form-control" id="grade_date" name="grade_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Optional comments about the grade"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_grade" class="btn btn-primary">Save Grade</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Grade Modal -->
    <?php if ($edit_grade): ?>
    <div class="modal fade" id="editGradeModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Grade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post">
                        <input type="hidden" name="grade_id" value="<?php echo $edit_grade['id']; ?>">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_subject_id" class="form-label">Subject</label>
                                <select class="form-select" id="edit_subject_id" name="subject_id" required>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>" <?php echo ($edit_grade['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_term_id" class="form-label">Academic Term</label>
                                <select class="form-select" id="edit_term_id" name="term_id" required>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo ($edit_grade['term_id'] == $term['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_grade_title" class="form-label">Grade Title</label>
                                <input type="text" class="form-control" id="edit_grade_title" name="grade_title" value="<?php echo htmlspecialchars($edit_grade['grade_title']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_grade_type" class="form-label">Grade Type</label>
                                <select class="form-select" id="edit_grade_type" name="grade_type" required>
                                    <option value="Midterm" <?php echo ($edit_grade['grade_type'] == 'Midterm') ? 'selected' : ''; ?>>Midterm</option>
                                    <option value="Final" <?php echo ($edit_grade['grade_type'] == 'Final') ? 'selected' : ''; ?>>Final</option>
                                    <option value="Assignment" <?php echo ($edit_grade['grade_type'] == 'Assignment') ? 'selected' : ''; ?>>Assignment</option>
                                    <option value="Quiz" <?php echo ($edit_grade['grade_type'] == 'Quiz') ? 'selected' : ''; ?>>Quiz</option>
                                    <option value="Project" <?php echo ($edit_grade['grade_type'] == 'Project') ? 'selected' : ''; ?>>Project</option>
                                    <option value="Other" <?php echo ($edit_grade['grade_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="edit_grade_value" class="form-label">Grade Value</label>
                                <input type="number" class="form-control" id="edit_grade_value" name="grade_value" step="0.01" min="0" value="<?php echo htmlspecialchars($edit_grade['grade_value']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_max_grade" class="form-label">Maximum Grade</label>
                                <input type="number" class="form-control" id="edit_max_grade" name="max_grade" step="0.01" min="0" value="<?php echo htmlspecialchars($edit_grade['max_grade']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_weight" class="form-label">Weight (%)</label>
                                <input type="number" class="form-control" id="edit_weight" name="weight" step="0.01" min="0" value="<?php echo htmlspecialchars($edit_grade['weight']); ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_grade_date" class="form-label">Grade Date</label>
                            <input type="date" class="form-control" id="edit_grade_date" name="grade_date" value="<?php echo htmlspecialchars($edit_grade['grade_date']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="edit_notes" name="notes" rows="3"><?php echo htmlspecialchars($edit_grade['notes']); ?></textarea>
                        </div>
                        <div class="text-end">
                            <a href="grades.php?term=<?php echo $term_filter; ?>&subject=<?php echo $subject_filter; ?>" class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="edit_grade" class="btn btn-primary">Update Grade</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var editModal = new bootstrap.Modal(document.getElementById('editGradeModal'));
            editModal.show();
        });
    </script>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> University Work Analyzer | Developed for Academic Tracking</p>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Initialize date pickers -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Set default date for new grade to today
            document.getElementById('grade_date').valueAsDate = new Date();
            
            // Initialize flatpickr if needed for better date selection
            flatpickr("#grade_date", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
            
            if (document.getElementById('edit_grade_date')) {
                flatpickr("#edit_grade_date", {
                    dateFormat: "Y-m-d",
                    allowInput: true
                });
            }
        });
    </script>
</body>
</html>