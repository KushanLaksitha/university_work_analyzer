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

// Handle assignment actions
$action = isset($_GET['action']) ? $_GET['action'] : '';
$assignment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$success_message = '';
$error_message = '';

// Handle assignment deletion
if ($action === 'delete' && $assignment_id > 0) {
    // Verify the assignment belongs to current user
    $stmt = $conn->prepare("SELECT id FROM assignments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $assignment_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Delete assignment
        $stmt = $conn->prepare("DELETE FROM assignments WHERE id = ?");
        $stmt->bind_param("i", $assignment_id);
        
        if ($stmt->execute()) {
            $success_message = "Assignment successfully deleted!";
        } else {
            $error_message = "Error deleting assignment. Please try again.";
        }
    } else {
        $error_message = "Assignment not found or you don't have permission to delete it.";
    }
    $stmt->close();
}

// Handle assignment status update
if (isset($_POST['update_status']) && isset($_POST['assignment_id']) && isset($_POST['new_status'])) {
    $update_id = intval($_POST['assignment_id']);
    $new_status = $_POST['new_status'];
    
    // Verify valid status
    $valid_statuses = ['Not Started', 'In Progress', 'Completed', 'Submitted', 'Graded'];
    if (in_array($new_status, $valid_statuses)) {
        $stmt = $conn->prepare("UPDATE assignments SET status = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sii", $new_status, $update_id, $user_id);
        
        if ($stmt->execute()) {
            $success_message = "Assignment status updated successfully!";
        } else {
            $error_message = "Error updating status. Please try again.";
        }
        $stmt->close();
    }
}

// Handle assignment form submission (add/edit)
if (isset($_POST['save_assignment'])) {
    $title = $_POST['title'];
    $subject_id = intval($_POST['subject_id']);
    $description = $_POST['description'] ?? '';
    $type = $_POST['type'];
    $due_date = $_POST['due_date'];
    $time_due = !empty($_POST['time_due']) ? $_POST['time_due'] : null;
    $weight = !empty($_POST['weight']) ? floatval($_POST['weight']) : 0;
    $status = $_POST['status'];
    $priority = $_POST['priority'];
    $estimated_hours = !empty($_POST['estimated_hours']) ? floatval($_POST['estimated_hours']) : null;
    $actual_hours_spent = !empty($_POST['actual_hours_spent']) ? floatval($_POST['actual_hours_spent']) : null;
    $grade = !empty($_POST['grade']) ? floatval($_POST['grade']) : null;
    $edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : 0;
    
    // Validate input
    if (empty($title) || empty($due_date) || $subject_id <= 0) {
        $error_message = "Please fill in all required fields.";
    } else {
        // Check if subject belongs to user
        $stmt = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $subject_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows > 0) {
            if ($edit_id > 0) {
                // Update existing assignment
                $sql = "UPDATE assignments SET 
                    title = ?, subject_id = ?, description = ?, type = ?, due_date = ?,
                    time_due = ?, weight = ?, status = ?, priority = ?, estimated_hours = ?,
                    actual_hours_spent = ?, grade = ?
                    WHERE id = ? AND user_id = ?";
                
                // Prepare statement
                if ($stmt = $conn->prepare($sql)) {
                    // Bind parameters
                    $stmt->bind_param(
                        "sissssdssddii", 
                        $title, $subject_id, $description, $type, $due_date,
                        $time_due, $weight, $status, $priority, $estimated_hours,
                        $actual_hours_spent, $grade, $edit_id, $user_id
                    );
                    
                    // Execute statement
                    if ($stmt->execute()) {
                        $success_message = "Assignment updated successfully!";
                    } else {
                        $error_message = "Error updating assignment: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Error preparing statement: " . $conn->error;
                }
            } else {
                // Insert new assignment
                $sql = "INSERT INTO assignments 
                    (user_id, subject_id, title, description, type, due_date, time_due, 
                    weight, status, priority, estimated_hours, actual_hours_spent, grade)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                // Prepare statement
                if ($stmt = $conn->prepare($sql)) {
                    // Bind parameters
                    $stmt->bind_param(
                        "iisssssdssddd", 
                        $user_id, $subject_id, $title, $description, $type, $due_date,
                        $time_due, $weight, $status, $priority, $estimated_hours, 
                        $actual_hours_spent, $grade
                    );
                    
                    // Execute statement
                    if ($stmt->execute()) {
                        $success_message = "New assignment added successfully!";
                    } else {
                        $error_message = "Error adding assignment: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Error preparing statement: " . $conn->error;
                }
            }
        } else {
            $error_message = "Invalid subject selected.";
        }
    }
}
// Get edit data if in edit mode
$edit_data = null;
if ($action === 'edit' && $assignment_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM assignments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $assignment_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $edit_data = $result->fetch_assoc();
    } else {
        $error_message = "Assignment not found or you don't have permission to edit it.";
    }
    $stmt->close();
}

// Get all subjects for the user (for dropdown)
$user_id = $_SESSION['user_id']; // Assuming you have the user_id stored in session
$stmt = $conn->prepare("SELECT id, subject_code, subject_name, color_code FROM subjects WHERE user_id = ? AND is_active = TRUE ORDER BY subject_code");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$subjects = [];
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

// Filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$subject_filter = isset($_GET['subject']) ? intval($_GET['subject']) : 0;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'due_date';
$sort_dir = isset($_GET['dir']) ? $_GET['dir'] : 'asc';

// Base query
$query = "SELECT a.*, s.subject_name, s.color_code 
          FROM assignments a
          JOIN subjects s ON a.subject_id = s.id
          WHERE a.user_id = ?";
$params = [$user_id];
$types = "i";

// Apply filters
if ($filter === 'upcoming') {
    $query .= " AND a.due_date >= CURRENT_DATE() AND a.status != 'Completed'";
} elseif ($filter === 'overdue') {
    $query .= " AND a.due_date < CURRENT_DATE() AND a.status NOT IN ('Completed', 'Graded')";
} elseif ($filter === 'completed') {
    $query .= " AND a.status = 'Completed'";
} elseif ($filter === 'active') {
    $query .= " AND a.status IN ('Not Started', 'In Progress')";
}

if ($subject_filter > 0) {
    $query .= " AND a.subject_id = ?";
    $params[] = $subject_filter;
    $types .= "i";
}

if (!empty($search)) {
    $search_term = "%" . $search . "%";
    $query .= " AND (a.title LIKE ? OR a.description LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

// Apply sorting
$valid_sort_fields = ['due_date', 'title', 'subject_name', 'status', 'priority', 'weight'];
$valid_sort_dirs = ['asc', 'desc'];

if (!in_array($sort, $valid_sort_fields)) {
    $sort = 'due_date';
}

if (!in_array($sort_dir, $valid_sort_dirs)) {
    $sort_dir = 'asc';
}

$query .= " ORDER BY ";
if ($sort === 'subject_name') {
    $query .= "s.subject_name " . $sort_dir;
} else {
    $query .= "a." . $sort . " " . $sort_dir;
}

// Prepare and execute the query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$assignments = [];
while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
}
$stmt->close();

// Get stats for filters
$total_assignments = count($assignments);
$active_count = 0;
$completed_count = 0;
$upcoming_count = 0;
$overdue_count = 0;

$today = new DateTime();
foreach ($assignments as $assignment) {
    $due_date = new DateTime($assignment['due_date']);
    
    if ($assignment['status'] === 'Completed' || $assignment['status'] === 'Graded') {
        $completed_count++;
    } else {
        $active_count++;
        
        if ($due_date >= $today) {
            $upcoming_count++;
        } else {
            $overdue_count++;
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
    <title>Assignments - University Work Analyzer</title>
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
    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-md-8">
                <h2><i class="fas fa-tasks me-2"></i>Assignment Management</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Assignments</li>
                    </ol>
                </nav>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignmentFormModal" <?php echo ($action === 'add' ? 'id="autoOpenModal"' : ''); ?>>
                    <i class="fas fa-plus-circle me-2"></i>Add New Assignment
                </button>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="?filter=all" class="text-decoration-none">
                    <div class="card bg-light h-100 <?php echo $filter === 'all' ? 'border-primary' : ''; ?>">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="fas fa-list fa-2x text-primary"></i>
                            </div>
                            <h5>All Assignments</h5>
                            <span class="badge bg-primary rounded-pill"><?php echo $total_assignments; ?></span>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="?filter=active" class="text-decoration-none">
                    <div class="card bg-light h-100 <?php echo $filter === 'active' ? 'border-primary' : ''; ?>">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="fas fa-spinner fa-2x text-warning"></i>
                            </div>
                            <h5>Active</h5>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $active_count; ?></span>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="?filter=upcoming" class="text-decoration-none">
                    <div class="card bg-light h-100 <?php echo $filter === 'upcoming' ? 'border-primary' : ''; ?>">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="fas fa-calendar-alt fa-2x text-info"></i>
                            </div>
                            <h5>Upcoming</h5>
                            <span class="badge bg-info text-white rounded-pill"><?php echo $upcoming_count; ?></span>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="?filter=overdue" class="text-decoration-none">
                    <div class="card bg-light h-100 <?php echo $filter === 'overdue' ? 'border-primary' : ''; ?>">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="fas fa-exclamation-circle fa-2x text-danger"></i>
                            </div>
                            <h5>Overdue</h5>
                            <span class="badge bg-danger rounded-pill"><?php echo $overdue_count; ?></span>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Search & Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="assignments.php" class="row g-3">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    
                    <div class="col-md-4">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="Search assignments" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <select class="form-select" name="subject">
                            <option value="0">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="input-group">
                            <select class="form-select" name="sort">
                                <option value="due_date" <?php echo $sort === 'due_date' ? 'selected' : ''; ?>>Due Date</option>
                                <option value="title" <?php echo $sort === 'title' ? 'selected' : ''; ?>>Title</option>
                                <option value="subject_name" <?php echo $sort === 'subject_name' ? 'selected' : ''; ?>>Subject</option>
                                <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Status</option>
                                <option value="priority" <?php echo $sort === 'priority' ? 'selected' : ''; ?>>Priority</option>
                                <option value="weight" <?php echo $sort === 'weight' ? 'selected' : ''; ?>>Weight</option>
                            </select>
                            <select class="form-select" name="dir">
                                <option value="asc" <?php echo $sort_dir === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                                <option value="desc" <?php echo $sort_dir === 'desc' ? 'selected' : ''; ?>>Descending</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assignments List -->
        <div class="card">
            <div class="card-header bg-light">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <?php 
                        $icon = '';
                        switch($filter) {
                            case 'active':
                                $icon = '<i class="fas fa-spinner me-2 text-warning"></i>Active Assignments';
                                break;
                            case 'upcoming':
                                $icon = '<i class="fas fa-calendar-alt me-2 text-info"></i>Upcoming Assignments';
                                break;
                            case 'overdue':
                                $icon = '<i class="fas fa-exclamation-circle me-2 text-danger"></i>Overdue Assignments';
                                break;
                            case 'completed':
                                $icon = '<i class="fas fa-check-circle me-2 text-success"></i>Completed Assignments';
                                break;
                            default:
                                $icon = '<i class="fas fa-list me-2 text-primary"></i>All Assignments';
                                break;
                        }
                        echo $icon;
                        ?>
                    </h5>
                    <span class="badge bg-primary"><?php echo count($assignments); ?> assignments</span>
                </div>
            </div>
            
            <?php if (count($assignments) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Weight</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                                <?php 
                                    $due_date = new DateTime($assignment['due_date']);
                                    $today = new DateTime();
                                    $interval = $today->diff($due_date);
                                    $days_diff = $interval->days;
                                    $is_past = $due_date < $today;
                                    
                                    // Row styling
                                    $row_class = '';
                                    if ($assignment['status'] === 'Completed' || $assignment['status'] === 'Graded') {
                                        $row_class = 'table-success opacity-75';
                                    } elseif ($is_past) {
                                        $row_class = 'table-danger';
                                    } elseif ($days_diff <= 2) {
                                        $row_class = 'table-warning';
                                    }
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <a href="assignment_details.php?id=<?php echo $assignment['id']; ?>" class="fw-bold text-decoration-none">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </a>
                                        <?php if (!empty($assignment['description'])): ?>
                                            <p class="small text-muted mb-0 text-truncate" style="max-width: 250px;">
                                                <?php echo htmlspecialchars($assignment['description']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($assignment['color_code']); ?>">
                                            <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                        </span>
                                        <div class="small mt-1"><?php echo htmlspecialchars($assignment['type']); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                            echo date('M j, Y', strtotime($assignment['due_date']));
                                            if (!empty($assignment['time_due'])) {
                                                echo '<br><small class="text-muted">' . date('g:i A', strtotime($assignment['time_due'])) . '</small>';
                                            }
                                            
                                            // Show relative date
                                            if ($is_past && $assignment['status'] !== 'Completed' && $assignment['status'] !== 'Graded') {
                                                echo '<div class="small text-danger fw-bold">' . $days_diff . ' day' . ($days_diff != 1 ? 's' : '') . ' overdue</div>';
                                            } elseif (!$is_past) {
                                                if ($days_diff == 0) {
                                                    echo '<div class="small text-danger fw-bold">Due today!</div>';
                                                } elseif ($days_diff == 1) {
                                                    echo '<div class="small text-warning fw-bold">Due tomorrow</div>';
                                                } else {
                                                    echo '<div class="small text-muted">In ' . $days_diff . ' days</div>';
                                                }
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm dropdown-toggle <?php 
                                                $statusClass = '';

                                                switch ($assignment['status']) {
                                                    case 'Completed':
                                                        $statusClass = 'btn-success';
                                                        break;
                                                    case 'Graded':
                                                        $statusClass = 'btn-info';
                                                        break;
                                                    case 'Submitted':
                                                        $statusClass = 'btn-primary';
                                                        break;
                                                    case 'In Progress':
                                                        $statusClass = 'btn-warning';
                                                        break;
                                                    default:
                                                        $statusClass = 'btn-secondary';
                                                        break;
                                                }
                                                
                                                echo $statusClass;
                                                
                                            ?>" type="button" data-bs-toggle="dropdown">
                                                <?php echo htmlspecialchars($assignment['status']); ?>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <form method="POST" action="assignments.php">
                                                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                    <input type="hidden" name="update_status" value="1">
                                                    
                                                    <li><button type="submit" name="new_status" value="Not Started" class="dropdown-item">Not Started</button></li>
                                                    <li><button type="submit" name="new_status" value="In Progress" class="dropdown-item">In Progress</button></li>
                                                    <li><button type="submit" name="new_status" value="Completed" class="dropdown-item">Completed</button></li>
                                                    <li><button type="submit" name="new_status" value="Submitted" class="dropdown-item">Submitted</button></li>
                                                    <li><button type="submit" name="new_status" value="Graded" class="dropdown-item">Graded</button></li>
                                                </form>
                                            </ul>
                                        </div>
                                        <?php if ($assignment['actual_hours_spent']): ?>
                                            <div class="small text-muted mt-1">
                                                <?php echo $assignment['actual_hours_spent']; ?> hours spent
                                            </div>
                                        <?php elseif ($assignment['estimated_hours']): ?>
                                            <div class="small text-muted mt-1">
                                                Est. <?php echo $assignment['estimated_hours']; ?> hours
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            $priorityClass = '';

                                            switch ($assignment['priority']) {
                                                case 'High':
                                                    $priorityClass = 'danger';
                                                    break;
                                                case 'Medium':
                                                    $priorityClass = 'warning text-dark';
                                                    break;
                                                default:
                                                    $priorityClass = 'success';
                                                    break;
                                            }
                                            
                                            echo $priorityClass;
                                            
                                        ?>">
                                            <?php echo htmlspecialchars($assignment['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($assignment['weight'] > 0): ?>
                                            <?php echo $assignment['weight']; ?>%
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                        <?php if (!is_null($assignment['grade'])): ?>
                                            <div class="small fw-bold">
                                                Grade: <?php echo $assignment['grade']; ?>%
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <a href="assignment_details.php?id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                        </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteConfirmModal" 
                                                    data-id="<?php echo $assignment['id']; ?>"
                                                    title="Delete">
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
                <div class="card-body text-center py-5">
                    <div class="text-muted mb-3">
                        <i class="fas fa-clipboard-list fa-4x"></i>
                    </div>
                    <h5>No assignments found</h5>
                    <p>
                        <?php 
                       $message = '';

                       switch ($filter) {
                           case 'active':
                               $message = 'You have no active assignments at the moment.';
                               break;
                           case 'upcoming':
                               $message = 'You have no upcoming assignments at the moment.';
                               break;
                           case 'overdue':
                               $message = 'You have no overdue assignments. Good job!';
                               break;
                           case 'completed':
                               $message = 'You have not completed any assignments yet.';
                               break;
                           default:
                               $message = 'You have not added any assignments yet.';
                               break;
                       }
                       
                       echo $message;
                       
                        ?>
                    </p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignmentFormModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Your First Assignment
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

   <!-- Assignment Form Modal -->
<div class="modal fade" id="assignmentFormModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <?php echo $edit_data ? '<i class="fas fa-edit me-2"></i>Edit Assignment' : '<i class="fas fa-plus-circle me-2"></i>Add New Assignment'; ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="assignments.php">
                <div class="modal-body">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']; ?>">
                    <?php endif; ?>
                    
                    <!-- Basic Info -->
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label for="title" class="form-label">Assignment Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required 
                                   value="<?php echo $edit_data ? htmlspecialchars($edit_data['title']) : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                            <select class="form-select" id="subject_id" name="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" 
                                            <?php echo ($edit_data && $edit_data['subject_id'] == $subject['id']) ? 'selected' : ''; ?>
                                            style="background-color: <?php echo htmlspecialchars($subject['color_code']); ?>80; color: #000;">
                                        <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Type & Dates -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="type" class="form-label">Assignment Type</label>
                            <select class="form-select" id="type" name="type">
                                <?php 
                                    $types = ['Assignment', 'Quiz', 'Project', 'Exam', 'Presentation', 'Lab', 'Reading', 'Other'];
                                    foreach ($types as $type):
                                ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($edit_data && $edit_data['type'] == $type) ? 'selected' : ''; ?>>
                                        <?php echo $type; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="due_date" class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="due_date" name="due_date" required
                                   value="<?php echo $edit_data ? $edit_data['due_date'] : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="time_due" class="form-label">Time Due</label>
                            <input type="time" class="form-control" id="time_due" name="time_due"
                                   value="<?php echo $edit_data && $edit_data['time_due'] ? $edit_data['time_due'] : ''; ?>">
                        </div>
                    </div>
                    
                    <!-- Status & Priority -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php 
                                    $statuses = ['Not Started', 'In Progress', 'Completed', 'Submitted', 'Graded'];
                                    foreach ($statuses as $status):
                                ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($edit_data && $edit_data['status'] == $status) ? 'selected' : ''; ?>>
                                        <?php echo $status; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-select" id="priority" name="priority">
                                <?php 
                                    $priorities = ['Low', 'Medium', 'High'];
                                    foreach ($priorities as $priority):
                                ?>
                                    <option value="<?php echo $priority; ?>" <?php echo ($edit_data && $edit_data['priority'] == $priority) ? 'selected' : ''; ?>>
                                        <?php echo $priority; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="weight" class="form-label">Weight (%)</label>
                            <input type="number" class="form-control" id="weight" name="weight" min="0" max="100" step="0.01"
                                   value="<?php echo $edit_data ? $edit_data['weight'] : '0'; ?>">
                        </div>
                    </div>
                    
                    <!-- Hours & Grade -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="estimated_hours" class="form-label">Estimated Hours</label>
                            <input type="number" class="form-control" id="estimated_hours" name="estimated_hours" min="0" step="0.5"
                                   value="<?php echo $edit_data && !is_null($edit_data['estimated_hours']) ? $edit_data['estimated_hours'] : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="actual_hours_spent" class="form-label">Actual Hours Spent</label>
                            <input type="number" class="form-control" id="actual_hours_spent" name="actual_hours_spent" min="0" step="0.5"
                                   value="<?php echo $edit_data && !is_null($edit_data['actual_hours_spent']) ? $edit_data['actual_hours_spent'] : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="grade" class="form-label">Grade (%)</label>
                            <input type="number" class="form-control" id="grade" name="grade" min="0" max="100" step="0.01"
                                   value="<?php echo $edit_data && !is_null($edit_data['grade']) ? $edit_data['grade'] : ''; ?>">
                        </div>
                    </div>
                    
                    <!-- Description -->
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo $edit_data ? htmlspecialchars($edit_data['description']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_assignment" class="btn btn-primary">
                        <?php echo $edit_data ? 'Update Assignment' : 'Add Assignment'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this assignment? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <a href="#" id="deleteAssignmentBtn" class="btn btn-danger">
        <i class="fas fa-trash-alt me-2"></i>Delete Assignment
    </a>
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
<!-- Bootstrap Datepicker -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>

<script>
// Auto open modal on edit
$(document).ready(function() {
    if (document.getElementById('autoOpenModal')) {
        var assignmentModal = new bootstrap.Modal(document.getElementById('assignmentFormModal'));
        assignmentModal.show();
    }
    
    // Datepicker initialization
    $('.datepicker').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
    
    // Delete confirmation modal
    $('#deleteConfirmModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var id = button.data('id');
        var deleteBtn = document.getElementById('deleteAssignmentBtn');
        deleteBtn.href = 'assignments.php?action=delete&id=' + id;
    });
});


</script>
</body>
</html>


                    