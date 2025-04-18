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

// Check if exam ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['message'] = "Invalid exam ID.";
    $_SESSION['message_type'] = "danger";
    header("Location: exams.php");
    exit;
}

$exam_id = $_GET['id'];

// Get exam details
$exam_details = null;
$stmt = $conn->prepare("SELECT e.id, e.title, e.date, e.start_time, e.end_time, e.location, e.notes, 
                        s.subject_name, s.color_code, s.id AS subject_id 
                        FROM exams e 
                        JOIN subjects s ON e.subject_id = s.id 
                        WHERE e.id = ? AND e.user_id = ?");
$stmt->bind_param("ii", $exam_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['message'] = "Exam not found or you don't have permission to view it.";
    $_SESSION['message_type'] = "danger";
    header("Location: exams.php");
    exit;
}

$exam_details = $result->fetch_assoc();
$stmt->close();

// Format date and time for display
$exam_date = date('l, F j, Y', strtotime($exam_details['date']));
$start_time = date('g:i A', strtotime($exam_details['start_time']));
$end_time = date('g:i A', strtotime($exam_details['end_time']));
$duration = (strtotime($exam_details['end_time']) - strtotime($exam_details['start_time'])) / 60; // Duration in minutes

// Check if exam is in the past
$is_past = strtotime($exam_details['date']) < strtotime(date('Y-m-d'));

// Get related assignments for this subject
$related_assignments = [];
$stmt = $conn->prepare("SELECT id, title, due_date FROM assignments 
                        WHERE subject_id = ? AND user_id = ? 
                        ORDER BY due_date ASC 
                        LIMIT 5");
$stmt->bind_param("ii", $exam_details['subject_id'], $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $related_assignments[] = $row;
}
$stmt->close();

// Handle study materials
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // Add study material
    if ($_POST['action'] == 'add_material') {
        $title = trim($_POST['title']);
        $notes = trim($_POST['notes']);
        $file_name = null;
        $file_path = null;
        $file_type = null;
        $file_size = null;
        
        // Handle file upload if present
        if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] == 0) {
            $upload_dir = 'uploads/materials/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $file_name = $_FILES['material_file']['name'];
            $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
            $unique_name = uniqid('material_') . '.' . $file_ext;
            $file_path = $upload_dir . $unique_name;
            
            // Validate file type
            $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif'];
            if (!in_array(strtolower($file_ext), $allowed_types)) {
                $_SESSION['message'] = "Error: Only PDF, Office documents, text files, and images are allowed.";
                $_SESSION['message_type'] = "danger";
                header("Location: exam_details.php?id=$exam_id");
                exit;
            }
            
            // Check file size (limit to 10MB)
            if ($_FILES['material_file']['size'] > 10 * 1024 * 1024) {
                $_SESSION['message'] = "Error: File size exceeds the 10MB limit.";
                $_SESSION['message_type'] = "danger";
                header("Location: exam_details.php?id=$exam_id");
                exit;
            }
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['material_file']['tmp_name'], $file_path)) {
                $file_name = $_FILES['material_file']['name'];
                $file_type = $_FILES['material_file']['type'];
                $file_size = $_FILES['material_file']['size'];
            } else {
                $_SESSION['message'] = "Error uploading file.";
                $_SESSION['message_type'] = "danger";
                header("Location: exam_details.php?id=$exam_id");
                exit;
            }
        }
        
        // Insert material into database
        $stmt = $conn->prepare("INSERT INTO exam_materials (exam_id, user_id, title, notes, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssssi", $exam_id, $user_id, $title, $notes, $file_name, $file_path, $file_type, $file_size);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Study material added successfully!";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error adding study material: " . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
        
        // Redirect to prevent form resubmission
        header("Location: exam_details.php?id=$exam_id");
        exit;
    }

    // Edit study material
else if ($_POST['action'] == 'edit_material') {
    $material_id = $_POST['material_id'];
    $title = trim($_POST['title']);
    $notes = trim($_POST['notes']);
    
    // Check if the material exists and belongs to the user
    $stmt = $conn->prepare("SELECT file_path, file_name FROM exam_materials WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $material_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['message'] = "Material not found or you don't have permission to edit it.";
        $_SESSION['message_type'] = "danger";
        header("Location: exam_details.php?id=$exam_id");
        exit;
    }
    
    $material = $result->fetch_assoc();
    $stmt->close();
    
    // Handle file upload if present
    $file_name = $material['file_name'];
    $file_path = $material['file_path'];
    $file_type = null;
    $file_size = null;
    $file_updated = false;
    
    if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] == 0) {
        $upload_dir = 'uploads/materials/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Generate unique filename
        $file_name = $_FILES['material_file']['name'];
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_name = uniqid('material_') . '.' . $file_ext;
        $file_path = $upload_dir . $unique_name;
        
        // Validate file type
        $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif'];
        if (!in_array(strtolower($file_ext), $allowed_types)) {
            $_SESSION['message'] = "Error: Only PDF, Office documents, text files, and images are allowed.";
            $_SESSION['message_type'] = "danger";
            header("Location: exam_details.php?id=$exam_id");
            exit;
        }
        
        // Check file size (limit to 10MB)
        if ($_FILES['material_file']['size'] > 10 * 1024 * 1024) {
            $_SESSION['message'] = "Error: File size exceeds the 10MB limit.";
            $_SESSION['message_type'] = "danger";
            header("Location: exam_details.php?id=$exam_id");
            exit;
        }
        
        // Delete the old file if it exists
        if (!empty($material['file_path']) && file_exists($material['file_path'])) {
            unlink($material['file_path']);
        }
        
        // Move uploaded file
        if (move_uploaded_file($_FILES['material_file']['tmp_name'], $file_path)) {
            $file_type = $_FILES['material_file']['type'];
            $file_size = $_FILES['material_file']['size'];
            $file_updated = true;
        } else {
            $_SESSION['message'] = "Error uploading file.";
            $_SESSION['message_type'] = "danger";
            header("Location: exam_details.php?id=$exam_id");
            exit;
        }
    }
    
    // Update material in database
    if ($file_updated) {
        $stmt = $conn->prepare("UPDATE exam_materials SET title = ?, notes = ?, file_name = ?, file_path = ?, file_type = ?, file_size = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sssssiii", $title, $notes, $file_name, $file_path, $file_type, $file_size, $material_id, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE exam_materials SET title = ?, notes = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ssii", $title, $notes, $material_id, $user_id);
    }
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Study material updated successfully!";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error updating study material: " . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    
    // Redirect to prevent form resubmission
    header("Location: exam_details.php?id=$exam_id");
    exit;
}
    
    // Delete study material
    else if ($_POST['action'] == 'delete_material') {
        $material_id = $_POST['material_id'];
        
        // First, get the file path if exists to delete the file
        $stmt = $conn->prepare("SELECT file_path FROM exam_materials WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $material_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Delete the physical file if it exists
            if (!empty($row['file_path']) && file_exists($row['file_path'])) {
                unlink($row['file_path']);
            }
        }
        $stmt->close();
        
        // Now delete the record
        $stmt = $conn->prepare("DELETE FROM exam_materials WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $material_id, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Study material deleted successfully!";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error deleting study material: " . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
        
        // Redirect to prevent form resubmission
        header("Location: exam_details.php?id=$exam_id");
        exit;
    }
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Get study materials for this exam
$study_materials = [];

// Check if the exam_materials table exists
$table_exists = false;
$result = $conn->query("SHOW TABLES LIKE 'exam_materials'");
if ($result->num_rows > 0) {
    $table_exists = true;
    
    // Modified query to include file information
    $stmt = $conn->prepare("SELECT id, title, notes, file_name, file_path, file_type, file_size, created_at 
                            FROM exam_materials 
                            WHERE exam_id = ? AND user_id = ? 
                            ORDER BY created_at DESC");
                            
    $stmt->bind_param("ii", $exam_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $study_materials[] = $row;
    }
    $stmt->close();
}

// Check if grades table exists and if there's a grade for this exam
$grade_exists = false;
$exam_grade = null;
$result = $conn->query("SHOW TABLES LIKE 'grades'");
if ($result->num_rows > 0) {
    $stmt = $conn->prepare("SELECT e.id, e.title, e.date, e.start_time, e.end_time, e.location, e.notes, 
                        s.subject_name, s.color_code, s.id AS subject_id 
                        FROM exams e 
                        JOIN subjects s ON e.subject_id = s.id 
                        WHERE e.id = ? AND e.user_id = ?");
if ($stmt === false) {
    // Handle the error
    $_SESSION['message'] = "Database error: " . $conn->error;
    $_SESSION['message_type'] = "danger";
    header("Location: exams.php");
    exit;
}
$stmt->bind_param("ii", $exam_id, $user_id);
    
    if ($result->num_rows > 0) {
        $grade_exists = true;
        $exam_grade = $result->fetch_assoc();
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
    <title><?php echo htmlspecialchars($exam_details['title']); ?> - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <style>
        .subject-badge {
            display: inline-block;
            padding: 0.5rem 0.75rem;
            border-radius: 0.25rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .countdown-card {
            transition: all 0.3s ease;
        }
        
        .countdown-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .material-card {
            border-left: 4px solid #6c757d;
            transition: all 0.2s ease;
        }
        
        .material-card:hover {
            border-left-width: 6px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
    </style>
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
                        <a class="nav-link active" href="exams.php">Exams</a>
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
                        <a class="nav-link" href="calendar.php">Calendar</a>
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
        <!-- Page Header & Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb mb-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="exams.php">Exams</a></li>
                                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($exam_details['title']); ?></li>
                                </ol>
                            </nav>
                        </div>
                        <div>
                            <a href="exams.php?edit=<?php echo $exam_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-edit me-1"></i> Edit Exam
                            </a>
                            <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteExamModal">
                                <i class="fas fa-trash-alt me-1"></i> Delete Exam
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Display Messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <!-- Exam Details -->
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0">Exam Details</h3>
                    </div>
                    <div class="card-body">
                        <h2 class="mb-3"><?php echo htmlspecialchars($exam_details['title']); ?></h2>
                        
                        <div class="subject-badge" style="background-color: <?php echo $exam_details['color_code']; ?>">
                            <?php echo htmlspecialchars($exam_details['subject_name']); ?>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5><i class="far fa-calendar-alt me-2"></i>Date & Time</h5>
                                <p class="mb-1"><?php echo $exam_date; ?></p>
                                <p class="mb-1"><?php echo $start_time . ' - ' . $end_time; ?> (<?php echo $duration; ?> minutes)</p>
                            </div>
                            <div class="col-md-6">
                                <h5><i class="fas fa-map-marker-alt me-2"></i>Location</h5>
                                <p><?php echo !empty($exam_details['location']) ? htmlspecialchars($exam_details['location']) : 'No location specified'; ?></p>
                            </div>
                        </div>
                        
                        <h5><i class="fas fa-clipboard-list me-2"></i>Notes</h5>
                        <div class="card mb-4">
                            <div class="card-body">
                                <?php if (!empty($exam_details['notes'])): ?>
                                    <p><?php echo nl2br(htmlspecialchars($exam_details['notes'])); ?></p>
                                <?php else: ?>
                                    <p class="text-muted">No notes added yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($grade_exists && isset($exam_grade['grade_value'])): ?>
                        <h5><i class="fas fa-award me-2"></i>Grade</h5>
                        <div class="card mb-4 <?php echo ($exam_grade['grade_value'] >= 70) ? 'border-success' : (($exam_grade['grade_value'] >= 50) ? 'border-warning' : 'border-danger'); ?>">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h3 class="<?php echo ($exam_grade['grade_value'] >= 70) ? 'text-success' : (($exam_grade['grade_value'] >= 50) ? 'text-warning' : 'text-danger'); ?>">
                                            <?php echo isset($exam_grade['grade_value']) ? $exam_grade['grade_value'] : 'N/A'; ?>%
                                        </h3>
                                    </div>
                                    <div class="col-md-6">
                                        <h5>Feedback:</h5>
                                        <p><?php echo !empty($exam_grade['feedback']) ? nl2br(htmlspecialchars($exam_grade['feedback'])) : 'No feedback provided.'; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($is_past): ?>
                        <div class="d-grid gap-2">
                            <a href="grades.php?add=exam&id=<?php echo $exam_id; ?>" class="btn btn-primary">
                                <i class="fas fa-plus-circle me-1"></i> Add Grade for This Exam
                            </a>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
                
                <!-- Study Materials -->
                <div class="card mt-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">Study Materials</h3>
                        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addMaterialModal">
                            <i class="fas fa-plus-circle me-1"></i> Add Material
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (!$table_exists): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i> The study materials feature is not available. Please contact your administrator.
                            </div>
                        <?php elseif (empty($study_materials)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i> No study materials have been added yet. Click the "Add Material" button to get started.
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($study_materials as $material): ?>
                                    <div class="list-group-item list-group-item-action material-card mb-2">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h5 class="mb-1"><?php echo htmlspecialchars($material['title']); ?></h5>
                                            <small><?php echo date('M j, Y', strtotime($material['created_at'])); ?></small>
                                        </div>
                                        
                                        <?php if (!empty($material['notes'])): ?>
                                            <p class="mb-1"><?php echo nl2br(htmlspecialchars($material['notes'])); ?></p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($material['file_name'])): ?>
                                            <div class="mt-2 mb-2">
                                                <a href="<?php echo htmlspecialchars($material['file_path']); ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="fas fa-file me-1"></i> <?php echo htmlspecialchars($material['file_name']); ?>
                                                    <span class="text-muted ms-1">(<?php echo formatFileSize($material['file_size']); ?>)</span>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-end mt-2">
                                            <button class="btn btn-sm btn-outline-secondary me-2" data-bs-toggle="modal" data-bs-target="#editMaterialModal<?php echo $material['id']; ?>">
                                                <i class="fas fa-edit me-1"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteMaterialModal<?php echo $material['id']; ?>">
                                                <i class="fas fa-trash-alt me-1"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Modal dialogs - moved outside the list-group -->
                <?php if (!empty($study_materials)): ?>
                    <?php foreach ($study_materials as $material): ?>
                        <!-- Delete Material Modal -->
                        <div class="modal fade" id="deleteMaterialModal<?php echo $material['id']; ?>" tabindex="-1" aria-labelledby="deleteMaterialModalLabel<?php echo $material['id']; ?>" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title" id="deleteMaterialModalLabel<?php echo $material['id']; ?>">Confirm Delete</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Are you sure you want to delete this study material?</p>
                                        <strong><?php echo htmlspecialchars($material['title']); ?></strong>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <form method="POST" action="exam_details.php?id=<?php echo $exam_id; ?>">
                                            <input type="hidden" name="action" value="delete_material">
                                            <input type="hidden" name="material_id" value="<?php echo $material['id']; ?>">
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Edit Material Modal -->
                        <div class="modal fade" id="editMaterialModal<?php echo $material['id']; ?>" tabindex="-1" aria-labelledby="editMaterialModalLabel<?php echo $material['id']; ?>" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header bg-primary text-white">
                                        <h5 class="modal-title" id="editMaterialModalLabel<?php echo $material['id']; ?>">Edit Study Material</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form method="POST" action="exam_details.php?id=<?php echo $exam_id; ?>" enctype="multipart/form-data">
                                            <input type="hidden" name="action" value="edit_material">
                                            <input type="hidden" name="material_id" value="<?php echo $material['id']; ?>">
                                            
                                            <div class="mb-3">
                                                <label for="edit_title_<?php echo $material['id']; ?>" class="form-label">Title</label>
                                                <input type="text" class="form-control" id="edit_title_<?php echo $material['id']; ?>" name="title" value="<?php echo htmlspecialchars($material['title']); ?>" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="edit_notes_<?php echo $material['id']; ?>" class="form-label">Notes/Content</label>
                                                <textarea class="form-control" id="edit_notes_<?php echo $material['id']; ?>" name="notes" rows="5"><?php echo htmlspecialchars($material['notes']); ?></textarea>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="edit_file_<?php echo $material['id']; ?>" class="form-label">Replace File (Optional)</label>
                                                <input type="file" class="form-control" id="edit_file_<?php echo $material['id']; ?>" name="material_file">
                                                <div class="form-text">
                                                    <?php if (!empty($material['file_name'])): ?>
                                                        Current file: <?php echo htmlspecialchars($material['file_name']); ?> (<?php echo formatFileSize($material['file_size']); ?>)
                                                    <?php else: ?>
                                                        No file currently attached.
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="d-grid gap-2">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save me-1"></i> Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            <div class="col-lg-4">
                <!-- Countdown / Status -->
                <div class="card mb-4 countdown-card">
                    <?php if ($is_past): ?>
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="fas fa-hourglass-end me-2"></i>Exam Status</h5>
                        </div>
                        <div class="card-body text-center">
                            <div class="display-1 mb-3"><i class="fas fa-check-circle text-secondary"></i></div>
                            <h4>This exam is in the past</h4>
                            <p class="text-muted">Completed on <?php echo $exam_date; ?></p>
                            
                            <?php if (!$grade_exists): ?>
                                <a href="grades.php?add=exam&id=<?php echo $exam_id; ?>" class="btn btn-primary mt-2">
                                    <i class="fas fa-plus-circle me-1"></i> Add Grade
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php 
                        $now = time();
                        $exam_time = strtotime($exam_details['date'] . ' ' . $exam_details['start_time']);
                        $time_diff = $exam_time - $now;
                        $days_left = floor($time_diff / (60 * 60 * 24));
                        $hours_left = floor(($time_diff % (60 * 60 * 24)) / (60 * 60));
                        $minutes_left = floor(($time_diff % (60 * 60)) / 60);
                        
                        if ($days_left <= 1) {
                            $status_class = 'bg-danger';
                            $icon = 'fa-exclamation-circle';
                        } else if ($days_left <= 3) {
                            $status_class = 'bg-warning';
                            $icon = 'fa-exclamation-triangle';
                        } else {
                            $status_class = 'bg-success';
                            $icon = 'fa-calendar-check';
                        }
                        ?>
                        <div class="card-header <?php echo $status_class; ?> text-white">
                            <h5 class="mb-0"><i class="fas <?php echo $icon; ?> me-2"></i>Countdown</h5>
                        </div>
                        <div class="card-body text-center">
                            <h3 class="mb-3">Time Remaining</h3>
                            <div class="row">
                                <div class="col-4">
                                    <div class="card">
                                        <div class="card-body p-2">
                                            <h2 class="mb-0"><?php echo $days_left; ?></h2>
                                            <small>Days</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="card">
                                        <div class="card-body p-2">
                                            <h2 class="mb-0"><?php echo $hours_left; ?></h2>
                                            <small>Hours</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="card">
                                        <div class="card-body p-2">
                                            <h2 class="mb-0"><?php echo $minutes_left; ?></h2>
                                            <small>Minutes</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <a href="calendar.php?date=<?php echo $exam_details['date']; ?>" class="btn btn-outline-primary">
                                    <i class="far fa-calendar-alt me-1"></i> View in Calendar
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Related Assignments -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Related Assignments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($related_assignments)): ?>
                            <p class="text-muted">No related assignments found for this subject.</p>
                        <?php else: ?>
                            <ul class="list-group">
                                <?php foreach ($related_assignments as $assignment): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <a href="assignment_details.php?id=<?php echo $assignment['id']; ?>">
                                                <?php echo htmlspecialchars($assignment['title']); ?>
                                            </a>
                                            <br>
                                            <small class="text-muted">Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></small>
                                        </div>
                                        <span class="badge bg-primary rounded-pill">
                                            <i class="fas fa-link"></i>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <a href="assignments.php?subject=<?php echo $exam_details['subject_id']; ?>" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-list me-1"></i> All Assignments for <?php echo htmlspecialchars($exam_details['subject_name']); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-link me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">
                            <a href="exams.php?edit=<?php echo $exam_id; ?>" class="list-group-item list-group-item-action">
                                <i class="fas fa-edit me-2"></i> Edit Exam Details
                            </a>
                            <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#addMaterialModal">
                                <i class="fas fa-book me-2"></i> Add Study Material
                            </a>
                            <a href="calendar.php?date=<?php echo $exam_details['date']; ?>" class="list-group-item list-group-item-action">
                                <i class="far fa-calendar-alt me-2"></i> View in Calendar
                            </a>
                            <?php if (!$is_past): ?>
                                <a href="#" class="list-group-item list-group-item-action" onclick="setReminder(event)">
                                    <i class="fas fa-bell me-2"></i> Set a Reminder
                                </a>
                            <?php endif; ?>
                            <?php if ($is_past && !$grade_exists): ?>
                                <a href="grades.php?add=exam&id=<?php echo $exam_id; ?>" class="list-group-item list-group-item-action">
                                    <i class="fas fa-plus-circle me-2"></i> Add Grade
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Exam Modal -->
    <div class="modal fade" id="deleteExamModal" tabindex="-1" aria-labelledby="deleteExamModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteExamModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this exam? This action cannot be undone.</p>
                    <div class="alert alert-warning">
                        <strong>You are about to delete:</strong> <?php echo htmlspecialchars($exam_details['title']); ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="exams.php">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
                        <button type="submit" class="btn btn-danger">Delete Exam</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Material Modal -->
    <div class="modal fade" id="addMaterialModal" tabindex="-1" aria-labelledby="addMaterialModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addMaterialModalLabel">Add Study Material</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="exam_details.php?id=<?php echo $exam_id; ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_material">
                    <input type="hidden" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">
                    <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
                    
                    <div class="mb-3">
                        <label for="material_title" class="form-label">Title</label>
                        <input type="text" class="form-control" id="material_title" name="title" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="material_notes" class="form-label">Notes/Content</label>
                        <textarea class="form-control" id="material_notes" name="notes" rows="5"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="material_file" class="form-label">Upload File (Optional)</label>
                        <input type="file" class="form-control" id="material_file" name="material_file">
                        <div class="form-text">Upload PDF, Word documents, images, or other study materials.</div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save Material
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

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Enable tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Set reminder function
        function setReminder(e) {
            e.preventDefault();
            
            // Check if browser supports notifications
            if (!("Notification" in window)) {
                alert("This browser does not support desktop notifications");
                return;
            }
            
            // Request permission for notifications
            Notification.requestPermission().then(function(permission) {
                if (permission === "granted") {
                    // Create event data
                    const examDate = "<?php echo $exam_details['date']; ?>";
                    const examTitle = "<?php echo addslashes($exam_details['title']); ?>";
                    const examTime = "<?php echo $exam_details['start_time']; ?>";
                    const examLocation = "<?php echo addslashes($exam_details['location']); ?>";
                    
                    // Try to add to calendar if possible
                    if ('showSaveFilePicker' in window) {
                        try {
                            // Create ICS file content
                            const icsContent = `BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
SUMMARY:${examTitle} Exam
DESCRIPTION:Exam for ${examTitle}
LOCATION:${examLocation}
DTSTART:${examDate.replace(/-/g, '')}T${examTime.replace(/:/g, '')}
DTEND:${examDate.replace(/-/g, '')}T${examTime.replace(/:/g, '')}
END:VEVENT
END:VCALENDAR`;
                            
                            // Download ICS file
                            const blob = new Blob([icsContent], { type: 'text/calendar' });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `${examTitle}_exam_reminder.ics`;
                            a.click();
                            URL.revokeObjectURL(url);
                            
                            alert("Reminder file downloaded. Please import it into your calendar application.");
                        } catch (error) {
                            console.error("Error creating calendar file:", error);
                            alert("Failed to create calendar file. Please add the exam to your calendar manually.");
                        }
                    } else {
                        // Simple browser notification as fallback
                        alert("Reminder set! You will receive a notification on the day of the exam.");
                        
                        // Calculate notification time (8am on the day of the exam)
                        const reminderDate = new Date(examDate);
                        reminderDate.setHours(8, 0, 0, 0);
                        
                        // Store reminder in localStorage
                        const reminder = {
                            id: <?php echo $exam_id; ?>,
                            title: examTitle,
                            date: examDate,
                            time: examTime,
                            location: examLocation,
                            notificationTime: reminderDate.toISOString()
                        };
                        
                        // Get existing reminders or initialize new array
                        let reminders = JSON.parse(localStorage.getItem('examReminders') || '[]');
                        reminders.push(reminder);
                        localStorage.setItem('examReminders', JSON.stringify(reminders));
                    }
                }
            });
        }
        
        // Function to check for due reminders (run on page load)
        function checkReminders() {
            if (!("Notification" in window)) return;
            
            const now = new Date();
            const reminders = JSON.parse(localStorage.getItem('examReminders') || '[]');
            const updatedReminders = [];
            
            reminders.forEach(reminder => {
                const notificationTime = new Date(reminder.notificationTime);
                
                // If reminder time has passed, show notification
                if (notificationTime <= now) {
                    // Create notification
                    const notification = new Notification(`Exam Today: ${reminder.title}`, {
                        body: `Your exam is today at ${reminder.time} in ${reminder.location}`,
                        icon: '/images/logo.png'
                    });
                    
                    // Handle notification click
                    notification.onclick = function() {
                        window.open(`exam_details.php?id=${reminder.id}`, '_blank');
                    };
                } else {
                    // Keep reminders that haven't triggered yet
                    updatedReminders.push(reminder);
                }
            });
            
            // Update localStorage with remaining reminders
            localStorage.setItem('examReminders', JSON.stringify(updatedReminders));
        }
        
        // Check for reminders when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Check if browser supports notifications and permission is granted
            if ("Notification" in window && Notification.permission === "granted") {
                checkReminders();
            }
        });
        // Enable tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
});

// Additional functions you might want to add for material handling
document.addEventListener('DOMContentLoaded', function() {
    // Preview file before upload for Add Material Modal
    if (document.getElementById('material_file')) {
        document.getElementById('material_file').addEventListener('change', function() {
            const fileInput = this;
            const fileNameDisplay = document.createElement('div');
            fileNameDisplay.className = 'mt-2';
            
            if (fileInput.files.length > 0) {
                const fileName = fileInput.files[0].name;
                const fileSize = formatFileSizeForDisplay(fileInput.files[0].size);
                
                fileNameDisplay.innerHTML = `<strong>Selected file:</strong> ${fileName} (${fileSize})`;
                
                // Remove any existing display
                const existingDisplay = fileInput.parentNode.querySelector('.selected-file-info');
                if (existingDisplay) {
                    existingDisplay.remove();
                }
                
                fileNameDisplay.classList.add('selected-file-info');
                fileInput.parentNode.appendChild(fileNameDisplay);
            }
        });
    }
    
    // Apply the same logic for edit material modals
    document.querySelectorAll('[id^="edit_file_"]').forEach(function(fileInput) {
        fileInput.addEventListener('change', function() {
            const fileNameDisplay = document.createElement('div');
            fileNameDisplay.className = 'mt-2';
            
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                const fileSize = formatFileSizeForDisplay(this.files[0].size);
                
                fileNameDisplay.innerHTML = `<strong>New file:</strong> ${fileName} (${fileSize})`;
                
                // Remove any existing display
                const existingDisplay = this.parentNode.querySelector('.selected-file-info');
                if (existingDisplay) {
                    existingDisplay.remove();
                }
                
                fileNameDisplay.classList.add('selected-file-info');
                this.parentNode.appendChild(fileNameDisplay);
            }
        });
    });
});

// Helper function to format file size in JavaScript
function formatFileSizeForDisplay(bytes) {
    if (bytes >= 1073741824) {
        return (bytes / 1073741824).toFixed(2) + ' GB';
    } else if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    } else {
        return bytes + ' bytes';
    }
}

// Add animations to material cards
document.addEventListener('DOMContentLoaded', function() {
    const materialCards = document.querySelectorAll('.material-card');
    
    materialCards.forEach(function(card) {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
            this.style.transition = 'all 0.3s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    });
});

    </script>
</body>
</html>