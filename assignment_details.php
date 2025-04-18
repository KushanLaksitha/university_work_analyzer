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

$assignment_id = $_GET['id'];

// Fetch assignment details
$stmt = $conn->prepare("SELECT a.*, s.subject_name, s.subject_code, s.color_code 
                       FROM assignments a 
                       JOIN subjects s ON a.subject_id = s.id
                       WHERE a.id = ? AND a.user_id = ?");
$stmt->bind_param("ii", $assignment_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if assignment exists and belongs to user
if ($result->num_rows === 0) {
    header("Location: assignments.php");
    exit;
}

$assignment = $result->fetch_assoc();
$stmt->close();

// Handle form submission for updating assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assignment'])) {
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
    $actual_hours_spent = $_POST['actual_hours_spent'] ? (float) $_POST['actual_hours_spent'] : null;
    
    // Update assignment in database
    $stmt = $conn->prepare("UPDATE assignments SET 
                           title = ?, description = ?, subject_id = ?, type = ?, 
                           due_date = ?, time_due = ?, weight = ?, status = ?, 
                           priority = ?, estimated_hours = ?, actual_hours_spent = ?
                           WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ssissdsssddi", $title, $description, $subject_id, $type, 
                     $due_date, $time_due, $weight, $status, 
                     $priority, $estimated_hours, $actual_hours_spent, $assignment_id, $user_id);
    
    if ($stmt->execute()) {
        // Success, redirect to refresh the page
        header("Location: assignment_details.php?id=$assignment_id&updated=true");
        exit;
    } else {
        $error_message = "Error updating assignment: " . $conn->error;
    }
    $stmt->close();
}

// Handle note submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $note_text = htmlspecialchars($_POST['note_text']);
    
    $stmt = $conn->prepare("INSERT INTO assignment_notes (assignment_id, note_text) VALUES (?, ?)");
    $stmt->bind_param("is", $assignment_id, $note_text);
    
    if ($stmt->execute()) {
        header("Location: assignment_details.php?id=$assignment_id&note_added=true");
        exit;
    } else {
        $note_error = "Error adding note: " . $conn->error;
    }
    $stmt->close();
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    // Check if file was uploaded without errors
    if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] == 0) {
        $file_name = basename($_FILES['assignment_file']['name']);
        $file_size = $_FILES['assignment_file']['size'];
        $file_type = $_FILES['assignment_file']['type'];
        $upload_dir = "uploads/";
        
        // Create unique filename to prevent overwriting
        $file_path = $upload_dir . $user_id . '_' . time() . '_' . $file_name;
        
        // Check if directory exists, if not create it
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Move the uploaded file to the specified directory
        if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $file_path)) {
            // File moved successfully, now store in database
            $stmt = $conn->prepare("INSERT INTO assignment_files (assignment_id, file_name, file_path, file_type, file_size) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssi", $assignment_id, $file_name, $file_path, $file_type, $file_size);
            
            if ($stmt->execute()) {
                header("Location: assignment_details.php?id=$assignment_id&file_uploaded=true");
                exit;
            } else {
                $file_error = "Error recording file in database: " . $conn->error;
            }
            $stmt->close();
        } else {
            $file_error = "Error uploading file. Please try again.";
        }
    } else {
        $file_error = "Error: " . $_FILES['assignment_file']['error'];
    }
}

// Handle assignment deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['confirm']) && $_GET['confirm'] === 'true') {
    // Delete assignment from database
    $stmt = $conn->prepare("DELETE FROM assignments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $assignment_id, $user_id);
    
    if ($stmt->execute()) {
        // Success, redirect to assignments page
        header("Location: assignments.php?deleted=true");
        exit;
    } else {
        $delete_error = "Error deleting assignment: " . $conn->error;
    }
    $stmt->close();
}

// Handle note deletion
if (isset($_GET['delete_note']) && is_numeric($_GET['delete_note'])) {
    $note_id = $_GET['delete_note'];
    
    // Verify the note belongs to this assignment
    $stmt = $conn->prepare("DELETE FROM assignment_notes WHERE id = ? AND assignment_id = ?");
    $stmt->bind_param("ii", $note_id, $assignment_id);
    
    if ($stmt->execute()) {
        header("Location: assignment_details.php?id=$assignment_id&note_deleted=true");
        exit;
    } else {
        $note_delete_error = "Error deleting note: " . $conn->error;
    }
    $stmt->close();
}

// Handle file deletion
if (isset($_GET['delete_file']) && is_numeric($_GET['delete_file'])) {
    $file_id = $_GET['delete_file'];
    
    // Get file path before deleting record
    $stmt = $conn->prepare("SELECT file_path FROM assignment_files WHERE id = ? AND assignment_id = ?");
    $stmt->bind_param("ii", $file_id, $assignment_id);
    $stmt->execute();
    $file_result = $stmt->get_result();
    
    if ($file_result->num_rows > 0) {
        $file_data = $file_result->fetch_assoc();
        $file_path = $file_data['file_path'];
        
        // Delete record from database
        $stmt = $conn->prepare("DELETE FROM assignment_files WHERE id = ? AND assignment_id = ?");
        $stmt->bind_param("ii", $file_id, $assignment_id);
        
        if ($stmt->execute()) {
            // Try to delete the physical file
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            header("Location: assignment_details.php?id=$assignment_id&file_deleted=true");
            exit;
        } else {
            $file_delete_error = "Error deleting file record: " . $conn->error;
        }
    } else {
        $file_delete_error = "File not found.";
    }
    $stmt->close();
}

// Fetch subject list for dropdown in edit form
$stmt = $conn->prepare("SELECT id, subject_name FROM subjects WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$subjects = [];
while ($subject = $subjects_result->fetch_assoc()) {
    $subjects[] = $subject;
}
$stmt->close();

// Fetch assignment notes
$stmt = $conn->prepare("SELECT * FROM assignment_notes WHERE assignment_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$notes_result = $stmt->get_result();
$notes = [];
while ($note = $notes_result->fetch_assoc()) {
    $notes[] = $note;
}
$stmt->close();

// Fetch assignment files
$stmt = $conn->prepare("SELECT * FROM assignment_files WHERE assignment_id = ? ORDER BY uploaded_at DESC");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$files_result = $stmt->get_result();
$files = [];
while ($file = $files_result->fetch_assoc()) {
    $files[] = $file;
}
$stmt->close();

// Calculate time left until due date
$due_date = new DateTime($assignment['due_date']);
$today = new DateTime();
$interval = $today->diff($due_date);
$days_left = $interval->days;
$days_left_text = '';

if ($due_date < $today) {
    $days_left_text = '<span class="text-danger">Overdue by ' . $days_left . ' day' . ($days_left > 1 ? 's' : '') . '</span>';
} elseif ($days_left == 0) {
    $days_left_text = '<span class="text-danger">Due today!</span>';
} elseif ($days_left == 1) {
    $days_left_text = '<span class="text-warning">Due tomorrow</span>';
} else {
    $days_left_text = '<span class="text-success">Due in ' . $days_left . ' days</span>';
}

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment Details - University Work Analyzer</title>
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
        <!-- Alerts for form submissions -->
        <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Assignment updated successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['note_added']) && $_GET['note_added'] === 'true'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Note added successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['note_deleted']) && $_GET['note_deleted'] === 'true'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Note deleted successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['file_uploaded']) && $_GET['file_uploaded'] === 'true'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>File uploaded successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['file_deleted']) && $_GET['file_deleted'] === 'true'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>File deleted successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Assignment header with actions -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">
                <span class="badge" style="background-color: <?php echo htmlspecialchars($assignment['color_code']); ?>">
                    <?php echo htmlspecialchars($assignment['subject_code']); ?>
                </span>
                <?php echo htmlspecialchars($assignment['title']); ?>
            </h1>
            <div class="btn-group">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editAssignmentModal">
                    <i class="fas fa-edit me-1"></i>Edit
                </button>
                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAssignmentModal">
                    <i class="fas fa-trash-alt me-1"></i>Delete
                </button>
            </div>
        </div>

        <!-- Assignment details card -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Assignment Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Subject:</strong> <?php echo htmlspecialchars($assignment['subject_name']); ?></p>
                                <p class="mb-1"><strong>Type:</strong> <?php echo htmlspecialchars($assignment['type']); ?></p>
                                <p class="mb-1"><strong>Due Date:</strong> <?php echo date('F j, Y', strtotime($assignment['due_date'])); ?></p>
                                <?php if ($assignment['time_due']): ?>
                                    <p class="mb-1"><strong>Time Due:</strong> <?php echo date('h:i A', strtotime($assignment['time_due'])); ?></p>
                                <?php endif; ?>
                                <p class="mb-1"><strong>Status:</strong> 
                                    <span class="badge bg-<?php 
                                        switch($assignment['status']) {
                                            case 'Completed': echo 'success'; break;
                                            case 'Submitted': echo 'primary'; break;
                                            case 'In Progress': echo 'warning'; break;
                                            case 'Not Started': echo 'secondary'; break;
                                            case 'Graded': echo 'info'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php echo htmlspecialchars($assignment['status']); ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Priority:</strong> 
                                    <span class="badge bg-<?php 
                                        switch($assignment['priority']) {
                                            case 'High': echo 'danger'; break;
                                            case 'Medium': echo 'warning'; break;
                                            case 'Low': echo 'success'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php echo htmlspecialchars($assignment['priority']); ?>
                                    </span>
                                </p>
                                <p class="mb-1"><strong>Weight:</strong> <?php echo $assignment['weight'] ? $assignment['weight'] . '%' : 'Not specified'; ?></p>
                                <p class="mb-1"><strong>Estimated Hours:</strong> <?php echo $assignment['estimated_hours'] ? $assignment['estimated_hours'] . ' hrs' : 'Not specified'; ?></p>
                                <p class="mb-1"><strong>Actual Hours Spent:</strong> <?php echo $assignment['actual_hours_spent'] ? $assignment['actual_hours_spent'] . ' hrs' : 'Not tracked yet'; ?></p>
                                <p class="mb-1"><strong>Time Remaining:</strong> <?php echo $days_left_text; ?></p>
                            </div>
                        </div>
                        
                        <?php if ($assignment['grade']): ?>
                            <div class="alert alert-info mt-3">
                                <h5><i class="fas fa-star me-2"></i>Grade: <?php echo $assignment['grade']; ?>%</h5>
                                <?php if ($assignment['grade_received_date']): ?>
                                    <p class="mb-0 small">Received on <?php echo date('F j, Y', strtotime($assignment['grade_received_date'])); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($assignment['description']): ?>
                            <div class="mt-3">
                                <h5>Description</h5>
                                <div class="card">
                                    <div class="card-body bg-light">
                                        <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notes card -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Notes</h5>
                        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                            <i class="fas fa-plus me-1"></i>Add Note
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (count($notes) > 0): ?>
                            <div class="list-group">
                                <?php foreach ($notes as $note): ?>
                                    <div class="list-group-item list-group-item-action">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></small>
                                            <a href="assignment_details.php?id=<?php echo $assignment_id; ?>&delete_note=<?php echo $note['id']; ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               onclick="return confirm('Are you sure you want to delete this note?');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                        <p class="mt-2 mb-0"><?php echo nl2br(htmlspecialchars($note['note_text'])); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-clipboard text-muted fa-3x mb-3"></i>
                                <p>No notes added yet.</p>
                                <button class="btn btn-info text-white" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                                    <i class="fas fa-plus me-1"></i>Add Your First Note
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Files card -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Files</h5>
                        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#uploadFileModal">
                            <i class="fas fa-upload me-1"></i>Upload
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (count($files) > 0): ?>
                            <div class="list-group">
                                <?php foreach ($files as $file): ?>
                                    <div class="list-group-item list-group-item-action">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <?php 
                                                    $icon_class = 'fa-file';
                                                    $file_ext = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                                                    
                                                    switch(strtolower($file_ext)) {
                                                        case 'pdf': $icon_class = 'fa-file-pdf'; break;
                                                        case 'doc': case 'docx': $icon_class = 'fa-file-word'; break;
                                                        case 'xls': case 'xlsx': $icon_class = 'fa-file-excel'; break;
                                                        case 'ppt': case 'pptx': $icon_class = 'fa-file-powerpoint'; break;
                                                        case 'jpg': case 'jpeg': case 'png': case 'gif': $icon_class = 'fa-file-image'; break;
                                                        case 'txt': $icon_class = 'fa-file-alt'; break;
                                                        case 'zip': case 'rar': $icon_class = 'fa-file-archive'; break;
                                                    }
                                                ?>
                                                <i class="fas <?php echo $icon_class; ?> me-2"></i>
                                                <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($file['file_name']); ?>
                                                </a>
                                            </div>
                                            <div>
                                                <small class="text-muted me-2">
                                                    <?php 
                                                        $size_kb = round($file['file_size'] / 1024, 2);
                                                        echo $size_kb > 1024 ? round($size_kb / 1024, 2) . ' MB' : $size_kb . ' KB'; 
                                                    ?>
                                                </small>
                                                <a href="assignment_details.php?id=<?php echo $assignment_id; ?>&delete_file=<?php echo $file['id']; ?>" 
                                                   class="btn btn-sm btn-outline-danger" 
                                                   onclick="return confirm('Are you sure you want to delete this file?');">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mt-1">
                                            Uploaded: <?php echo date('M j, Y', strtotime($file['uploaded_at'])); ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-cloud-upload-alt text-muted fa-3x mb-3"></i>
                                <p>No files uploaded yet.</p>
                                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadFileModal">
                                    <i class="fas fa-upload me-1"></i>Upload Your First File
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick actions card -->
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($assignment['status'] !== 'Completed'): ?>
                                <a href="mark_completed.php?id=<?php echo $assignment_id; ?>" class="btn btn-success">
                                    <i class="fas fa-check-circle me-2"></i>Mark as Completed
                                </a>
                            <?php endif; ?>
                            <a href="track_time.php?assignment_id=<?php echo $assignment_id; ?>" class="btn btn-warning text-white">
                                <i class="fas fa-clock me-2"></i>Track Time Spent
                            </a>
                            <a href="copy_assignment.php?id=<?php echo $assignment_id; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-copy me-2"></i>Duplicate Assignment
                            </a>
                        </div>
                    </div>
                </div>
                 <!-- Related assignments card -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-link me-2"></i>Related Assignments</h5>
    </div>
    <div class="card-body">
        <a href="assignments.php?subject_id=<?php echo $assignment['subject_id']; ?>" class="btn btn-outline-secondary w-100 mb-2">
            <i class="fas fa-book me-2"></i>View All <?php echo htmlspecialchars($assignment['subject_name']); ?> Assignments
        </a>
        
        <!-- This would typically show other assignments from the same subject -->
        <div class="list-group mt-3">
            <!-- You'd need to retrieve these from the database -->
            <?php 
            // Code to fetch and display related assignments would be here
            // This is a placeholder structure
            ?>
        </div>
    </div>
</div>
               

            </div>  
        </div>   
        </div>        
</div>



<!-- Bootstrap JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="editAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Assignment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="" method="POST">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label for="title" class="form-label">Assignment Title</label>
                            <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($assignment['title']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="subject_id" class="form-label">Subject</label>
                            <select class="form-select" id="subject_id" name="subject_id" required>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo ($subject['id'] == $assignment['subject_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($assignment['description']); ?></textarea>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="type" class="form-label">Assignment Type</label>
                            <select class="form-select" id="type" name="type" required>
                                <option value="Essay" <?php echo ($assignment['type'] == 'Essay') ? 'selected' : ''; ?>>Essay</option>
                                <option value="Quiz" <?php echo ($assignment['type'] == 'Quiz') ? 'selected' : ''; ?>>Quiz</option>
                                <option value="Test" <?php echo ($assignment['type'] == 'Test') ? 'selected' : ''; ?>>Test</option>
                                <option value="Project" <?php echo ($assignment['type'] == 'Project') ? 'selected' : ''; ?>>Project</option>
                                <option value="Presentation" <?php echo ($assignment['type'] == 'Presentation') ? 'selected' : ''; ?>>Presentation</option>
                                <option value="Lab Report" <?php echo ($assignment['type'] == 'Lab Report') ? 'selected' : ''; ?>>Lab Report</option>
                                <option value="Discussion" <?php echo ($assignment['type'] == 'Discussion') ? 'selected' : ''; ?>>Discussion</option>
                                <option value="Research Paper" <?php echo ($assignment['type'] == 'Research Paper') ? 'selected' : ''; ?>>Research Paper</option>
                                <option value="Homework" <?php echo ($assignment['type'] == 'Homework') ? 'selected' : ''; ?>>Homework</option>
                                <option value="Other" <?php echo ($assignment['type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="due_date" class="form-label">Due Date</label>
                            <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo $assignment['due_date']; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="time_due" class="form-label">Time Due (optional)</label>
                            <input type="time" class="form-control" id="time_due" name="time_due" value="<?php echo $assignment['time_due']; ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="weight" class="form-label">Weight (% of grade)</label>
                            <input type="number" class="form-control" id="weight" name="weight" min="0" max="100" step="0.01" value="<?php echo $assignment['weight']; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="Not Started" <?php echo ($assignment['status'] == 'Not Started') ? 'selected' : ''; ?>>Not Started</option>
                                <option value="In Progress" <?php echo ($assignment['status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo ($assignment['status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Submitted" <?php echo ($assignment['status'] == 'Submitted') ? 'selected' : ''; ?>>Submitted</option>
                                <option value="Graded" <?php echo ($assignment['status'] == 'Graded') ? 'selected' : ''; ?>>Graded</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-select" id="priority" name="priority" required>
                                <option value="High" <?php echo ($assignment['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                                <option value="Medium" <?php echo ($assignment['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                <option value="Low" <?php echo ($assignment['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="estimated_hours" class="form-label">Estimated Hours (optional)</label>
                            <input type="number" class="form-control" id="estimated_hours" name="estimated_hours" min="0" step="0.5" value="<?php echo $assignment['estimated_hours']; ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="actual_hours_spent" class="form-label">Actual Hours Spent (optional)</label>
                            <input type="number" class="form-control" id="actual_hours_spent" name="actual_hours_spent" min="0" step="0.5" value="<?php echo $assignment['actual_hours_spent']; ?>">
                        </div>
                    </div>
                    
                    <?php if ($assignment['status'] == 'Graded'): ?>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="grade" class="form-label">Grade (%)</label>
                            <input type="number" class="form-control" id="grade" name="grade" min="0" max="100" step="0.01" value="<?php echo $assignment['grade']; ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="grade_received_date" class="form-label">Grade Received Date</label>
                            <input type="date" class="form-control" id="grade_received_date" name="grade_received_date" value="<?php echo $assignment['grade_received_date']; ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="text-end mt-4">
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_assignment" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Assignment Modal -->
<div class="modal fade" id="deleteAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Delete Assignment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this assignment? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="assignment_details.php?id=<?php echo $assignment_id; ?>&action=delete&confirm=true" class="btn btn-danger">
                    <i class="fas fa-trash-alt me-1"></i>Delete Permanently
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="addNoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-sticky-note me-2"></i>Add Note</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="" method="POST">
                    <div class="mb-3">
                        <label for="note_text" class="form-label">Note</label>
                        <textarea class="form-control" id="note_text" name="note_text" rows="4" required></textarea>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_note" class="btn btn-info text-white">
                            <i class="fas fa-save me-1"></i>Save Note
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Upload File Modal -->
<div class="modal fade" id="uploadFileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Upload File</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="assignment_file" class="form-label">Select File</label>
                        <input class="form-control" type="file" id="assignment_file" name="assignment_file" required>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_file" class="btn btn-success">
                            <i class="fas fa-cloud-upload-alt me-1"></i>Upload
                        </button>
                    </div>
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

<script>
    // Custom JavaScript can be added here
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize tooltips if needed
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Any additional JavaScript functionality would go here
    });
</script>
</body>
</html>