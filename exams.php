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

// Get subjects for dropdown
$subjects = [];
$stmt = $conn->prepare("SELECT id, subject_name, color_code FROM subjects WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

// Handle form submissions for create/edit/delete
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Process the form based on the action
    if (isset($_POST['action'])) {
        // Create new exam
        if ($_POST['action'] == 'create') {
            $title = trim($_POST['title']);
            $subject_id = $_POST['subject_id'];
            $exam_date = $_POST['exam_date'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $location = trim($_POST['location']);
            
            // Check if the description field exists in the table
            // If not, we'll use the notes field instead
            $description = trim($_POST['description']);
            
            // Creating the SQL statement based on your database structure
            $stmt = $conn->prepare("INSERT INTO exams (user_id, subject_id, title, date, start_time, end_time, location, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt === false) {
                $_SESSION['message'] = "Prepare failed: " . $conn->error;
                $_SESSION['message_type'] = "danger";
            } else {
                $stmt->bind_param("iissssss", $user_id, $subject_id, $title, $exam_date, $start_time, $end_time, $location, $description);
                
                if ($stmt->execute()) {
                    $_SESSION['message'] = "Exam added successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error adding exam: " . $stmt->error;
                    $_SESSION['message_type'] = "danger";
                }
                $stmt->close();
            }
            
            // Redirect to prevent form resubmission
            header("Location: exams.php");
            exit;
        }
        
        // Update existing exam
        else if ($_POST['action'] == 'update') {
            $exam_id = $_POST['exam_id'];
            $title = trim($_POST['title']);
            $subject_id = $_POST['subject_id'];
            $exam_date = $_POST['exam_date'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $location = trim($_POST['location']);
            $description = trim($_POST['description']);
            
            $stmt = $conn->prepare("UPDATE exams SET title = ?, subject_id = ?, date = ?, start_time = ?, end_time = ?, location = ?, notes = ? WHERE id = ? AND user_id = ?");
            
            if ($stmt === false) {
                $_SESSION['message'] = "Prepare failed: " . $conn->error;
                $_SESSION['message_type'] = "danger";
            } else {
                $stmt->bind_param("sisssssii", $title, $subject_id, $exam_date, $start_time, $end_time, $location, $description, $exam_id, $user_id);
                
                if ($stmt->execute()) {
                    $_SESSION['message'] = "Exam updated successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error updating exam: " . $stmt->error;
                    $_SESSION['message_type'] = "danger";
                }
                $stmt->close();
            }
            
            // Redirect to prevent form resubmission
            header("Location: exams.php");
            exit;
        }
        
        // Delete exam
        else if ($_POST['action'] == 'delete') {
            $exam_id = $_POST['exam_id'];
            
            $stmt = $conn->prepare("DELETE FROM exams WHERE id = ? AND user_id = ?");
            
            if ($stmt === false) {
                $_SESSION['message'] = "Prepare failed: " . $conn->error;
                $_SESSION['message_type'] = "danger";
            } else {
                $stmt->bind_param("ii", $exam_id, $user_id);
                
                if ($stmt->execute()) {
                    $_SESSION['message'] = "Exam deleted successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error deleting exam: " . $stmt->error;
                    $_SESSION['message_type'] = "danger";
                }
                $stmt->close();
            }
            
            // Redirect to prevent form resubmission
            header("Location: exams.php");
            exit;
        }
    }
}

// Get the exam ID for editing if provided
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$edit_exam = null;

if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT id, title, subject_id, date, start_time, end_time, location, notes FROM exams WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_exam = $result->fetch_assoc();
    }
    $stmt->close();
}

// Predefined date if provided in URL (for quick add from calendar)
$predefined_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get all exams for the user
$exams = [];
$stmt = $conn->prepare("SELECT e.id, e.title, e.date, e.start_time, e.end_time, e.location, e.notes, 
                        s.subject_name, s.color_code FROM exams e 
                        JOIN subjects s ON e.subject_id = s.id 
                        WHERE e.user_id = ? 
                        ORDER BY e.date ASC, e.start_time ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $exams[] = $row;
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
    <title>Manage Exams - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Flatpickr for date/time selection -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .exam-card {
            transition: transform 0.2s;
        }
        .exam-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .subject-indicator {
            width: 10px;
            height: 100%;
            position: absolute;
            left: 0;
            top: 0;
            border-radius: 5px 0 0 5px;
        }
        .color-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        #examForm {
            display: none;
        }
        .active-form {
            display: block !important;
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
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <h2 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Manage Exams</h2>
                        <button id="toggleFormBtn" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-1"></i> <?php echo $edit_id ? 'Update Exam' : 'Add Exam'; ?>
                        </button>
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
        
        <!-- Add/Edit Exam Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="examForm" <?php echo ($edit_id || isset($_GET['action']) && $_GET['action'] == 'add') ? 'class="active-form"' : ''; ?>>
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0"><?php echo $edit_id ? 'Edit Exam' : 'Add New Exam'; ?></h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="exams.php">
                            <input type="hidden" name="action" value="<?php echo $edit_id ? 'update' : 'create'; ?>">
                            <?php if ($edit_id): ?>
                                <input type="hidden" name="exam_id" value="<?php echo $edit_id; ?>">
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="title" class="form-label">Exam Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="title" name="title" required 
                                        value="<?php echo $edit_exam ? htmlspecialchars($edit_exam['title']) : ''; ?>">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                                    <select class="form-select" id="subject_id" name="subject_id" required>
                                        <option value="">Select Subject</option>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>" 
                                                <?php echo ($edit_exam && $edit_exam['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (count($subjects) == 0): ?>
                                        <div class="form-text text-danger">No subjects found. Please <a href="subjects.php">add a subject</a> first.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="exam_date" class="form-label">Exam Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="exam_date" name="exam_date" required
                                        value="<?php echo $edit_exam ? $edit_exam['date'] : $predefined_date; ?>">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="start_time" name="start_time" required
                                        value="<?php echo $edit_exam ? $edit_exam['start_time'] : '09:00'; ?>">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="end_time" name="end_time" required
                                        value="<?php echo $edit_exam ? $edit_exam['end_time'] : '11:00'; ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="location" class="form-label">Location</label>
                                    <input type="text" class="form-control" id="location" name="location"
                                        value="<?php echo $edit_exam ? htmlspecialchars($edit_exam['location']) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Notes</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo $edit_exam ? htmlspecialchars($edit_exam['notes']) : ''; ?></textarea>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> <?php echo $edit_id ? 'Update Exam' : 'Save Exam'; ?>
                                    </button>
                                    <button type="button" id="cancelBtn" class="btn btn-secondary ms-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </button>
                                </div>
                                
                                <?php if ($edit_id): ?>
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteExamModal">
                                        <i class="fas fa-trash-alt me-1"></i> Delete Exam
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Exams List -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0">Upcoming Exams</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($exams)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i> No exams have been added yet. Click the "Add Exam" button to get started.
                            </div>
                        <?php else: ?>
                            <!-- Filter options -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <input type="text" id="searchExam" class="form-control" placeholder="Search exams...">
                                </div>
                                <div class="col-md-4">
                                    <select id="filterSubject" class="form-select">
                                        <option value="">All Subjects</option>
                                        <?php 
                                        $unique_subjects = [];
                                        foreach ($exams as $exam) {
                                            if (!in_array($exam['subject_name'], $unique_subjects)) {
                                                $unique_subjects[] = $exam['subject_name'];
                                                echo '<option value="' . htmlspecialchars($exam['subject_name']) . '">' . 
                                                    htmlspecialchars($exam['subject_name']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select id="sortExams" class="form-select">
                                        <option value="date-asc">Date (Ascending)</option>
                                        <option value="date-desc">Date (Descending)</option>
                                        <option value="subject">Subject</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row" id="examsList">
                                <?php foreach ($exams as $exam): ?>
                                    <?php 
                                    // Format the date and time
                                    $exam_date = date('F j, Y', strtotime($exam['date']));
                                    $start_time = date('g:i A', strtotime($exam['start_time']));
                                    $end_time = date('g:i A', strtotime($exam['end_time']));
                                    
                                    // Check if exam is in the past
                                    $is_past = strtotime($exam['date']) < strtotime(date('Y-m-d'));
                                    ?>
                                    
                                    <div class="col-md-6 col-lg-4 mb-3 exam-item" 
                                         data-subject="<?php echo htmlspecialchars($exam['subject_name']); ?>"
                                         data-date="<?php echo $exam['date']; ?>"
                                         data-title="<?php echo htmlspecialchars($exam['title']); ?>">
                                        <div class="card h-100 exam-card position-relative <?php echo $is_past ? 'border-secondary bg-light' : ''; ?>">
                                            <div class="subject-indicator" style="background-color: <?php echo $exam['color_code']; ?>"></div>
                                            <div class="card-body ps-4">
                                                <h5 class="card-title"><?php echo htmlspecialchars($exam['title']); ?></h5>
                                                <p class="card-text mb-1">
                                                    <span class="color-dot" style="background-color: <?php echo $exam['color_code']; ?>"></span>
                                                    <?php echo htmlspecialchars($exam['subject_name']); ?>
                                                </p>
                                                <p class="card-text mb-1">
                                                    <i class="far fa-calendar-alt me-2"></i><?php echo $exam_date; ?>
                                                </p>
                                                <p class="card-text mb-1">
                                                    <i class="far fa-clock me-2"></i><?php echo $start_time . ' - ' . $end_time; ?>
                                                </p>
                                                <?php if (!empty($exam['location'])): ?>
                                                    <p class="card-text mb-1">
                                                        <i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($exam['location']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if ($is_past): ?>
                                                    <div class="mt-2 badge bg-secondary">Past Exam</div>
                                                <?php else: ?>
                                                    <?php 
                                                    $days_until = floor((strtotime($exam['date']) - time()) / 86400);
                                                    if ($days_until <= 3) {
                                                        echo '<div class="mt-2 badge bg-danger">Coming soon! (' . $days_until . ' days)</div>';
                                                    } elseif ($days_until <= 7) {
                                                        echo '<div class="mt-2 badge bg-warning text-dark">Coming up (' . $days_until . ' days)</div>';
                                                    }
                                                    ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-footer bg-transparent border-top-0">
                                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                                    <a href="exam_details.php?id=<?php echo $exam['id']; ?>" class="btn btn-sm btn-info me-md-1">
                                                        <i class="fas fa-eye me-1"></i> View
                                                    </a>
                                                    <a href="exams.php?edit=<?php echo $exam['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-edit me-1"></i> Edit
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
                    <?php if ($edit_exam): ?>
                        <div class="alert alert-warning">
                            <strong>You are about to delete:</strong> <?php echo htmlspecialchars($edit_exam['title']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="exams.php">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="exam_id" value="<?php echo $edit_id; ?>">
                        <button type="submit" class="btn btn-danger">Delete Exam</button>
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
    <!-- Flatpickr for date/time selection -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle exam form visibility
        const toggleFormBtn = document.getElementById('toggleFormBtn');
        const examForm = document.getElementById('examForm');
        const cancelBtn = document.getElementById('cancelBtn');
        
        if (toggleFormBtn && examForm) {
            toggleFormBtn.addEventListener('click', function() {
                examForm.classList.toggle('active-form');
                
                // Scroll to form when showing
                if (examForm.classList.contains('active-form')) {
                    examForm.scrollIntoView({behavior: 'smooth'});
                }
            });
        }
        
        if (cancelBtn && examForm) {
            cancelBtn.addEventListener('click', function() {
                examForm.classList.remove('active-form');
                
                // If we're in edit mode, redirect to base page
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('edit')) {
                    window.location.href = 'exams.php';
                }
            });
        }
        
        // Handle search and filtering
        const searchExam = document.getElementById('searchExam');
        const filterSubject = document.getElementById('filterSubject');
        const sortExams = document.getElementById('sortExams');
        
        if (searchExam) {
            searchExam.addEventListener('input', filterExams);
        }
        
        if (filterSubject) {
            filterSubject.addEventListener('change', filterExams);
        }
        
        if (sortExams) {
            sortExams.addEventListener('change', filterExams);
        }
        
        function filterExams() {
            const searchValue = searchExam ? searchExam.value.toLowerCase() : '';
            const subjectValue = filterSubject ? filterSubject.value : '';
            const sortValue = sortExams ? sortExams.value : 'date-asc';
            
            const examItems = document.querySelectorAll('.exam-item');
            
            // First, filter items
            examItems.forEach(item => {
                const title = item.dataset.title.toLowerCase();
                const subject = item.dataset.subject;
                const matchesSearch = title.includes(searchValue);
                const matchesSubject = !subjectValue || subject === subjectValue;
                
                if (matchesSearch && matchesSubject) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Then, sort visible items
            const examsList = document.getElementById('examsList');
            if (!examsList) return;
            
            const items = Array.from(examItems).filter(item => item.style.display !== 'none');
            
            if (sortValue === 'date-asc') {
                items.sort((a, b) => new Date(a.dataset.date) - new Date(b.dataset.date));
            } else if (sortValue === 'date-desc') {
                items.sort((a, b) => new Date(b.dataset.date) - new Date(a.dataset.date));
            } else if (sortValue === 'subject') {
                items.sort((a, b) => a.dataset.subject.localeCompare(b.dataset.subject));
            }
            
            // Re-append in new order
            items.forEach(item => {
                examsList.appendChild(item);
            });
        }
        
        // Initialize date/time pickers
        if (typeof flatpickr !== 'undefined') {
            flatpickr("#exam_date", {
                dateFormat: "Y-m-d",
                minDate: "today"
            });
        }
    });
    </script>
</body>
</html>