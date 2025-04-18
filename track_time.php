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
if (!isset($_GET['assignment_id']) || !is_numeric($_GET['assignment_id'])) {
    header("Location: assignments.php");
    exit;
}

$assignment_id = $_GET['assignment_id'];

// Verify the assignment belongs to the current user
$stmt = $conn->prepare("SELECT a.*, s.subject_name, s.subject_code, s.color_code 
                       FROM assignments a 
                       JOIN subjects s ON a.subject_id = s.id
                       WHERE a.id = ? AND a.user_id = ?");
$stmt->bind_param("ii", $assignment_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Assignment doesn't exist or doesn't belong to user
    header("Location: assignments.php");
    exit;
}

$assignment = $result->fetch_assoc();
$stmt->close();

// Handle form submission for adding time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_time'])) {
    $hours_spent = (float) $_POST['hours_spent'];
    $work_date = $_POST['work_date'];
    $work_description = htmlspecialchars($_POST['work_description']);
    $start_time = isset($_POST['start_time']) ? $_POST['start_time'] : null;
    $end_time = isset($_POST['end_time']) ? $_POST['end_time'] : null;
    
    // Insert time entry
    $stmt = $conn->prepare("INSERT INTO time_tracking (assignment_id, user_id, hours_spent, work_date, work_description, start_time, end_time) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iidssss", $assignment_id, $user_id, $hours_spent, $work_date, $work_description, $start_time, $end_time);
    
    if ($stmt->execute()) {
        // Update the actual_hours_spent in the assignments table
        $stmt->close();
        
        // Get total hours spent on this assignment
        $stmt = $conn->prepare("SELECT SUM(hours_spent) AS total_hours FROM time_tracking WHERE assignment_id = ?");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $hours_result = $stmt->get_result();
        $total_hours = $hours_result->fetch_assoc()['total_hours'];
        $stmt->close();
        
        // Update the assignment with the new total
        $stmt = $conn->prepare("UPDATE assignments SET actual_hours_spent = ? WHERE id = ?");
        $stmt->bind_param("di", $total_hours, $assignment_id);
        $stmt->execute();
        $stmt->close();
        
        // Redirect with success message
        header("Location: track_time.php?assignment_id=$assignment_id&added=true");
        exit;
    } else {
        $error_message = "Error tracking time: " . $conn->error;
    }
    $stmt->close();
}

// Handle time entry deletion
if (isset($_GET['delete_entry']) && is_numeric($_GET['delete_entry'])) {
    $entry_id = $_GET['delete_entry'];
    
    // Verify the time entry belongs to this assignment and user
    $stmt = $conn->prepare("SELECT hours_spent FROM time_tracking WHERE id = ? AND assignment_id = ? AND user_id = ?");
    $stmt->bind_param("iii", $entry_id, $assignment_id, $user_id);
    $stmt->execute();
    $entry_result = $stmt->get_result();
    
    if ($entry_result->num_rows > 0) {
        $hours_to_remove = $entry_result->fetch_assoc()['hours_spent'];
        $stmt->close();
        
        // Delete the time entry
        $stmt = $conn->prepare("DELETE FROM time_tracking WHERE id = ?");
        $stmt->bind_param("i", $entry_id);
        
        if ($stmt->execute()) {
            // Recalculate total hours
            $stmt->close();
            $stmt = $conn->prepare("SELECT SUM(hours_spent) AS total_hours FROM time_tracking WHERE assignment_id = ?");
            $stmt->bind_param("i", $assignment_id);
            $stmt->execute();
            $hours_result = $stmt->get_result();
            $total_hours = $hours_result->fetch_assoc()['total_hours'] ?: 0;
            $stmt->close();
            
            // Update the assignment with the new total
            $stmt = $conn->prepare("UPDATE assignments SET actual_hours_spent = ? WHERE id = ?");
            $stmt->bind_param("di", $total_hours, $assignment_id);
            $stmt->execute();
            $stmt->close();
            
            header("Location: track_time.php?assignment_id=$assignment_id&deleted=true");
            exit;
        } else {
            $delete_error = "Error deleting time entry: " . $conn->error;
        }
    } else {
        header("Location: track_time.php?assignment_id=$assignment_id");
        exit;
    }
}

// Fetch time tracking entries for this assignment
$stmt = $conn->prepare("SELECT * FROM time_tracking WHERE assignment_id = ? ORDER BY work_date DESC, id DESC");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$time_entries_result = $stmt->get_result();
$time_entries = [];
while ($entry = $time_entries_result->fetch_assoc()) {
    $time_entries[] = $entry;
}
$stmt->close();

// Calculate total time spent on this assignment
$total_time_spent = 0;
foreach ($time_entries as $entry) {
    $total_time_spent += $entry['hours_spent'];
}

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Time - University Work Analyzer</title>
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
        <!-- Alert messages -->
        <?php if (isset($_GET['added']) && $_GET['added'] === 'true'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Time added successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] === 'true'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Time entry deleted successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Page header with back button -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">
                <a href="assignment_details.php?id=<?php echo $assignment_id; ?>" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left"></i>
                </a>
                Track Time for: <span class="badge" style="background-color: <?php echo htmlspecialchars($assignment['color_code']); ?>">
                    <?php echo htmlspecialchars($assignment['subject_code']); ?>
                </span>
                <?php echo htmlspecialchars($assignment['title']); ?>
            </h1>
        </div>

        <div class="row">
            <!-- Time tracking form -->
            <div class="col-md-5">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Add Time Spent</h5>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <div class="mb-3">
                                <label for="hours_spent" class="form-label">Hours Spent</label>
                                <input type="number" class="form-control" id="hours_spent" name="hours_spent" min="0.25" step="0.25" required>
                                <div class="form-text">Input time in hours (e.g., 1.5 for 1 hour and 30 minutes)</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="work_date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="work_date" name="work_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="start_time" class="form-label">Start Time (optional)</label>
                                    <input type="time" class="form-control" id="start_time" name="start_time">
                                </div>
                                <div class="col-md-6">
                                    <label for="end_time" class="form-label">End Time (optional)</label>
                                    <input type="time" class="form-control" id="end_time" name="end_time">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="work_description" class="form-label">Description of Work Done</label>
                                <textarea class="form-control" id="work_description" name="work_description" rows="3" required></textarea>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="add_time" class="btn btn-primary">
                                    <i class="fas fa-plus-circle me-1"></i>Add Time
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Timer tool (client-side only) -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-stopwatch me-2"></i>Timer Tool</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div id="timer" class="display-4">00:00:00</div>
                        </div>
                        <div class="d-flex justify-content-center mb-3">
                            <button id="startTimer" class="btn btn-success me-2">
                                <i class="fas fa-play me-1"></i>Start
                            </button>
                            <button id="pauseTimer" class="btn btn-warning me-2" disabled>
                                <i class="fas fa-pause me-1"></i>Pause
                            </button>
                            <button id="resetTimer" class="btn btn-danger">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                        </div>
                        <div class="d-grid">
                            <button id="recordTime" class="btn btn-primary" disabled>
                                <i class="fas fa-save me-1"></i>Record This Time
                            </button>
                        </div>
                        <div class="form-text text-center mt-2">
                            Timer will not save automatically. Click "Record This Time" to add to the form above.
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Time entries list -->
            <div class="col-md-7">
                <div class="card mb-4">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Time Log</h5>
                        <span class="badge bg-light text-dark">Total: <?php echo number_format($total_time_spent, 2); ?> hours</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($time_entries) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Hours</th>
                                            <th>Description</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($time_entries as $entry): ?>
                                            <tr>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($entry['work_date'])); ?>
                                                    <?php if ($entry['start_time'] && $entry['end_time']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo date('g:i A', strtotime($entry['start_time'])); ?> - 
                                                            <?php echo date('g:i A', strtotime($entry['end_time'])); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $hours = floor($entry['hours_spent']);
                                                        $minutes = round(($entry['hours_spent'] - $hours) * 60);
                                                        
                                                        if ($hours > 0) {
                                                            echo $hours . ' hr' . ($hours > 1 ? 's' : '');
                                                            if ($minutes > 0) {
                                                                echo ' ' . $minutes . ' min';
                                                            }
                                                        } else {
                                                            echo $minutes . ' min';
                                                        }
                                                    ?>
                                                </td>
                                                <td><?php echo nl2br(htmlspecialchars($entry['work_description'])); ?></td>
                                                <td>
                                                    <a href="track_time.php?assignment_id=<?php echo $assignment_id; ?>&delete_entry=<?php echo $entry['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger"
                                                       onclick="return confirm('Are you sure you want to delete this time entry?');">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times text-muted fa-3x mb-3"></i>
                                <p>No time entries recorded yet.</p>
                                <p class="text-muted">Use the form on the left to start tracking time for this assignment.</p>
                            </div>
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

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Timer Functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let timerInterval;
            let seconds = 0;
            let isRunning = false;
            
            const timerDisplay = document.getElementById('timer');
            const startBtn = document.getElementById('startTimer');
            const pauseBtn = document.getElementById('pauseTimer');
            const resetBtn = document.getElementById('resetTimer');
            const recordBtn = document.getElementById('recordTime');
            
            // Format time as HH:MM:SS
            function formatTime(totalSeconds) {
                const hours = Math.floor(totalSeconds / 3600);
                const minutes = Math.floor((totalSeconds % 3600) / 60);
                const seconds = totalSeconds % 60;
                
                return [
                    hours.toString().padStart(2, '0'),
                    minutes.toString().padStart(2, '0'),
                    seconds.toString().padStart(2, '0')
                ].join(':');
            }
            
            // Start the timer
            startBtn.addEventListener('click', function() {
                if (!isRunning) {
                    isRunning = true;
                    timerInterval = setInterval(function() {
                        seconds++;
                        timerDisplay.textContent = formatTime(seconds);
                        recordBtn.disabled = false;
                    }, 1000);
                    
                    startBtn.disabled = true;
                    pauseBtn.disabled = false;
                }
            });
            
            // Pause the timer
            pauseBtn.addEventListener('click', function() {
                clearInterval(timerInterval);
                isRunning = false;
                startBtn.disabled = false;
                pauseBtn.disabled = true;
            });
            
            // Reset the timer
            resetBtn.addEventListener('click', function() {
                clearInterval(timerInterval);
                seconds = 0;
                timerDisplay.textContent = '00:00:00';
                isRunning = false;
                startBtn.disabled = false;
                pauseBtn.disabled = true;
                recordBtn.disabled = true;
            });
            
            // Record time to form
            recordBtn.addEventListener('click', function() {
                // Convert seconds to hours for the form
                const hours = seconds / 3600;
                
                // Fill the hours spent field
                document.getElementById('hours_spent').value = hours.toFixed(2);
                
                // Set start and end times if they're empty
                const now = new Date();
                const currentTime = now.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit' });
                
                if (!document.getElementById('end_time').value) {
                    document.getElementById('end_time').value = currentTime;
                }
                
                // Calculate and set start time based on elapsed seconds
                if (!document.getElementById('start_time').value) {
                    const startDate = new Date(now.getTime() - (seconds * 1000));
                    const startTime = startDate.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit' });
                    document.getElementById('start_time').value = startTime;
                }
                
                // Focus on the description field to prompt user to add details
                document.getElementById('work_description').focus();
                
                // Reset timer
                clearInterval(timerInterval);
                seconds = 0;
                timerDisplay.textContent = '00:00:00';
                isRunning = false;
                startBtn.disabled = false;
                pauseBtn.disabled = true;
                recordBtn.disabled = true;
            });
        });
    </script>
</body>
</html>