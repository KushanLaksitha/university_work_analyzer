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

// Check if assignment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: assignments.php");
    exit;
}

$original_assignment_id = $_GET['id'];

// Fetch original assignment details to duplicate
$stmt = $conn->prepare("SELECT * FROM assignments WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $original_assignment_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if assignment exists and belongs to user
if ($result->num_rows === 0) {
    header("Location: assignments.php");
    exit;
}

$original_assignment = $result->fetch_assoc();
$stmt->close();

// Fetch subject list for dropdown
$stmt = $conn->prepare("SELECT id, subject_name FROM subjects WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$subjects = [];
while ($subject = $subjects_result->fetch_assoc()) {
    $subjects[] = $subject;
}
$stmt->close();

// Handle form submission for creating copy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_assignment'])) {
    // Sanitize and validate form data
    $title = htmlspecialchars($_POST['title']);
    $description = htmlspecialchars($_POST['description']);
    $subject_id = (int) $_POST['subject_id'];
    $type = htmlspecialchars($_POST['type']);
    $due_date = $_POST['due_date'];
    $time_due = $_POST['time_due'] ? $_POST['time_due'] : null;
    $weight = (float) $_POST['weight'];
    $status = htmlspecialchars($_POST['status']);
    $priority = htmlspecialchars($_POST['priority']);
    $estimated_hours = $_POST['estimated_hours'] ? (float) $_POST['estimated_hours'] : null;
    
    // Set default values for new assignment
    $grade = null;
    $grade_received_date = null;
    $actual_hours_spent = null;
    $created_at = date('Y-m-d H:i:s');
    
    // Insert new assignment in database
    $stmt = $conn->prepare("INSERT INTO assignments 
                         (user_id, subject_id, title, description, type, 
                         due_date, time_due, weight, status, priority, 
                         estimated_hours, actual_hours_spent, grade,
                         grade_received_date, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssssdssddss", 
                     $user_id, $subject_id, $title, $description, $type, 
                     $due_date, $time_due, $weight, $status, $priority,
                     $estimated_hours, $actual_hours_spent, $grade,
                     $grade_received_date, $created_at);
    
    if ($stmt->execute()) {
        $new_assignment_id = $conn->insert_id;
        
        // If user chose to copy notes
        if (isset($_POST['copy_notes']) && $_POST['copy_notes'] == 'yes') {
            // Fetch notes from original assignment
            $notes_stmt = $conn->prepare("SELECT note_text FROM assignment_notes WHERE assignment_id = ?");
            $notes_stmt->bind_param("i", $original_assignment_id);
            $notes_stmt->execute();
            $notes_result = $notes_stmt->get_result();
            
            // Insert notes for new assignment
            if ($notes_result->num_rows > 0) {
                $insert_note_stmt = $conn->prepare("INSERT INTO assignment_notes (assignment_id, note_text, created_at) VALUES (?, ?, ?)");
                while ($note = $notes_result->fetch_assoc()) {
                    $now = date('Y-m-d H:i:s');
                    $insert_note_stmt->bind_param("iss", $new_assignment_id, $note['note_text'], $now);
                    $insert_note_stmt->execute();
                }
                $insert_note_stmt->close();
            }
            $notes_stmt->close();
        }
        
        // Success, redirect to the new assignment's page
        header("Location: assignment_details.php?id=$new_assignment_id&created=true");
        exit;
    } else {
        $error_message = "Error creating assignment copy: " . $conn->error;
    }
    $stmt->close();
}

// Set default values for form (original values with a "Copy of" prefix for title)
$default_title = "Copy of " . $original_assignment['title'];
$default_status = "Not Started"; // Default new copies to "Not Started" regardless of original status
$default_due_date = date('Y-m-d', strtotime('+1 week')); // Default to one week from now

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Copy Assignment - University Work Analyzer</title>
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
                        <a class="nav-link active" href="assignments.php">Assignments</a>
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
        <!-- Page header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2"><i class="fas fa-copy me-2"></i>Copy Assignment</h1>
            <a href="assignment_details.php?id=<?php echo $original_assignment_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Original
            </a>
        </div>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Assignment form card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Create Assignment Copy</h5>
            </div>
            <div class="card-body">
                <form action="" method="POST">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label for="title" class="form-label">Assignment Title</label>
                            <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($default_title); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="subject_id" class="form-label">Subject</label>
                            <select class="form-select" id="subject_id" name="subject_id" required>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo ($subject['id'] == $original_assignment['subject_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($original_assignment['description']); ?></textarea>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="type" class="form-label">Assignment Type</label>
                            <select class="form-select" id="type" name="type" required>
                                <option value="Essay" <?php echo ($original_assignment['type'] == 'Essay') ? 'selected' : ''; ?>>Essay</option>
                                <option value="Quiz" <?php echo ($original_assignment['type'] == 'Quiz') ? 'selected' : ''; ?>>Quiz</option>
                                <option value="Test" <?php echo ($original_assignment['type'] == 'Test') ? 'selected' : ''; ?>>Test</option>
                                <option value="Project" <?php echo ($original_assignment['type'] == 'Project') ? 'selected' : ''; ?>>Project</option>
                                <option value="Presentation" <?php echo ($original_assignment['type'] == 'Presentation') ? 'selected' : ''; ?>>Presentation</option>
                                <option value="Lab Report" <?php echo ($original_assignment['type'] == 'Lab Report') ? 'selected' : ''; ?>>Lab Report</option>
                                <option value="Discussion" <?php echo ($original_assignment['type'] == 'Discussion') ? 'selected' : ''; ?>>Discussion</option>
                                <option value="Research Paper" <?php echo ($original_assignment['type'] == 'Research Paper') ? 'selected' : ''; ?>>Research Paper</option>
                                <option value="Homework" <?php echo ($original_assignment['type'] == 'Homework') ? 'selected' : ''; ?>>Homework</option>
                                <option value="Other" <?php echo ($original_assignment['type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="due_date" class="form-label">Due Date</label>
                            <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo $default_due_date; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="time_due" class="form-label">Time Due (optional)</label>
                            <input type="time" class="form-control" id="time_due" name="time_due" value="<?php echo $original_assignment['time_due']; ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="weight" class="form-label">Weight (% of grade)</label>
                            <input type="number" class="form-control" id="weight" name="weight" min="0" max="100" step="0.01" value="<?php echo $original_assignment['weight']; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="Not Started" selected>Not Started</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Submitted">Submitted</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-select" id="priority" name="priority" required>
                                <option value="High" <?php echo ($original_assignment['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                                <option value="Medium" <?php echo ($original_assignment['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                <option value="Low" <?php echo ($original_assignment['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="estimated_hours" class="form-label">Estimated Hours (optional)</label>
                            <input type="number" class="form-control" id="estimated_hours" name="estimated_hours" min="0" step="0.5" value="<?php echo $original_assignment['estimated_hours']; ?>">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="copy_notes" name="copy_notes" value="yes">
                                <label class="form-check-label" for="copy_notes">
                                    <i class="fas fa-sticky-note me-1"></i>Copy notes from original assignment
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <div class="d-flex">
                            <div class="me-3">
                                <i class="fas fa-info-circle fa-2x"></i>
                            </div>
                            <div>
                                <h5 class="alert-heading">About Copying Assignments</h5>
                                <p class="mb-0">This will create a new assignment with the details you've specified. Files will not be copied from the original assignment. The status will be set to "Not Started" by default.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end mt-4">
                        <a href="assignment_details.php?id=<?php echo $original_assignment_id; ?>" class="btn btn-secondary me-2">
                            Cancel
                        </a>
                        <button type="submit" name="copy_assignment" class="btn btn-primary">
                            <i class="fas fa-copy me-1"></i>Create Assignment Copy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> University Work Analyzer | Developed for Academic Tracking</p>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Custom JavaScript if needed
        document.addEventListener('DOMContentLoaded', function() {
            // You could add date validation or other form helpers here
        });
    </script>
</body>
</html>