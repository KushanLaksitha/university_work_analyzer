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

// Get upcoming assignments (due within 7 days)
$upcoming_assignments = [];
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));

$stmt = $conn->prepare("SELECT a.id, a.title, a.due_date, a.status, s.subject_name, s.color_code 
                       FROM assignments a 
                       JOIN subjects s ON a.subject_id = s.id 
                       WHERE a.user_id = ? AND a.due_date BETWEEN ? AND ? 
                       ORDER BY a.due_date ASC");
$stmt->bind_param("iss", $user_id, $today, $next_week);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $upcoming_assignments[] = $row;
}
$stmt->close();

// Get statistics
// 1. Total assignments
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM assignments WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total_assignments = $result->fetch_assoc()['total'];
$stmt->close();

// 2. Completed assignments
$stmt = $conn->prepare("SELECT COUNT(*) as completed FROM assignments WHERE user_id = ? AND status = 'Completed'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$completed_assignments = $result->fetch_assoc()['completed'];
$stmt->close();

// 3. Pending assignments
$stmt = $conn->prepare("SELECT COUNT(*) as pending FROM assignments WHERE user_id = ? AND status = 'In Progress'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$pending_assignments = $result->fetch_assoc()['pending'];
$stmt->close();

// 4. Overdue assignments
$stmt = $conn->prepare("SELECT COUNT(*) as overdue FROM assignments WHERE user_id = ? AND status != 'Completed' AND due_date < ?");
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$result = $stmt->get_result();
$overdue_assignments = $result->fetch_assoc()['overdue'];
$stmt->close();

// 5. Total subjects
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM subjects WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total_subjects = $result->fetch_assoc()['total'];
$stmt->close();

// Get GPA data for Chart.js
$stmt = $conn->prepare("SELECT term_name, gpa FROM academic_terms WHERE user_id = ? ORDER BY start_date ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$terms = [];
$gpas = [];

while ($row = $result->fetch_assoc()) {
    $terms[] = $row['term_name'];
    $gpas[] = $row['gpa'];
}
$stmt->close();

// Get assignment completion data for Chart.js
$stmt = $conn->prepare("SELECT s.subject_name, 
                       SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) as completed,
                       COUNT(a.id) as total
                       FROM subjects s
                       LEFT JOIN assignments a ON s.id = a.subject_id
                       WHERE s.user_id = ?
                       GROUP BY s.id");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$subject_names = [];
$completion_percentages = [];

while ($row = $result->fetch_assoc()) {
    $subject_names[] = $row['subject_name'];
    $completion_percentages[] = $row['total'] > 0 ? round(($row['completed'] / $row['total']) * 100) : 0;
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
    <title>Dashboard - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <a class="nav-link active" href="index.php">Dashboard</a>
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
    <div class="container-fluid py-4">
        <!-- Welcome Banner -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-light">
                    <div class="card-body">
                        <h1 class="card-title">Welcome back, <?php echo htmlspecialchars($first_name); ?>!</h1>
                        <p class="card-text">Here's an overview of your academic progress and upcoming deadlines.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card text-white bg-primary h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Assignments</h6>
                                <h2 class="card-text"><?php echo $total_assignments; ?></h2>
                            </div>
                            <i class="fas fa-tasks fa-3x"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card text-white bg-success h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Completed</h6>
                                <h2 class="card-text"><?php echo $completed_assignments; ?></h2>
                            </div>
                            <i class="fas fa-check-circle fa-3x"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card text-white bg-warning h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">In Progress</h6>
                                <h2 class="card-text"><?php echo $pending_assignments; ?></h2>
                            </div>
                            <i class="fas fa-spinner fa-3x"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card text-white bg-danger h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Overdue</h6>
                                <h2 class="card-text"><?php echo $overdue_assignments; ?></h2>
                            </div>
                            <i class="fas fa-exclamation-circle fa-3x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Middle Row - Upcoming Assignments and GPA Chart -->
        <div class="row mb-4">
            <!-- Upcoming Assignments -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Upcoming Deadlines</h5>
                        <a href="assignments.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($upcoming_assignments) > 0): ?>
                            <div class="list-group">
                                <?php foreach ($upcoming_assignments as $assignment): ?>
                                    <?php 
                                        $due_date = new DateTime($assignment['due_date']);
                                        $today = new DateTime();
                                        $interval = $today->diff($due_date);
                                        $days_left = $interval->days;
                                        $urgent_class = '';
                                        
                                        if ($days_left <= 1) {
                                            $urgent_class = 'border-danger';
                                        } elseif ($days_left <= 3) {
                                            $urgent_class = 'border-warning';
                                        }
                                    ?>
                                    <a href="assignment_details.php?id=<?php echo $assignment['id']; ?>" class="list-group-item list-group-item-action <?php echo $urgent_class; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1">
                                                <span class="badge" style="background-color: <?php echo htmlspecialchars($assignment['color_code']); ?>">
                                                    <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                                </span>
                                                <?php echo htmlspecialchars($assignment['title']); ?>
                                            </h6>
                                            <small>
                                                <?php 
                                                    if ($days_left == 0) {
                                                        echo '<span class="text-danger fw-bold">Due today!</span>';
                                                    } elseif ($days_left == 1) {
                                                        echo '<span class="text-danger">Due tomorrow</span>';
                                                    } else {
                                                        echo 'Due in ' . $days_left . ' days';
                                                    }
                                                ?>
                                            </small>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-2">
                                            <small><?php echo date('D, M j', strtotime($assignment['due_date'])); ?></small>
                                            <span class="badge bg-<?php echo $assignment['status'] == 'Completed' ? 'success' : 'warning'; ?>">
                                                <?php echo $assignment['status']; ?>
                                            </span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
                                <p class="lead">No upcoming deadlines for the next 7 days!</p>
                                <a href="assignments.php?action=add" class="btn btn-sm btn-primary">Add New Assignment</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- GPA Trend -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>GPA Trend</h5>
                        <a href="analytics.php" class="btn btn-sm btn-outline-primary">Full Analytics</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($terms) > 0): ?>
                            <canvas id="gpaChart" width="400" height="250"></canvas>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-chart-line text-muted fa-4x mb-3"></i>
                                <p class="lead">No GPA data available yet.</p>
                                <a href="grades.php?action=add" class="btn btn-sm btn-primary">Add Term Grades</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Row - Subject Progress and Quick Actions -->
        <div class="row mb-4">
            <!-- Subject Progress -->
            <div class="col-lg-8 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Subject Progress</h5>
                        <a href="subjects.php" class="btn btn-sm btn-outline-primary">View All Subjects</a>
                    </div>
                    <div class="card-body">
                        <?php if (count($subject_names) > 0): ?>
                            <canvas id="subjectProgressChart" width="400" height="250"></canvas>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-book text-muted fa-4x mb-3"></i>
                                <p class="lead">No subject data available yet.</p>
                                <a href="subjects.php?action=add" class="btn btn-sm btn-primary">Add New Subject</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-3">
                            <a href="assignments.php?action=add" class="btn btn-primary">
                                <i class="fas fa-plus-circle me-2"></i>Add New Assignment
                            </a>
                            <a href="subjects.php?action=add" class="btn btn-info text-white">
                                <i class="fas fa-folder-plus me-2"></i>Add New Subject
                            </a>
                            <a href="grades.php?action=add" class="btn btn-success">
                                <i class="fas fa-graduation-cap me-2"></i>Record New Grade
                            </a>
                            <a href="calendar.php" class="btn btn-warning text-white">
                                <i class="fas fa-calendar-alt me-2"></i>View Academic Calendar
                            </a>
                            <?php if ($overdue_assignments > 0): ?>
                                <a href="assignments.php?filter=overdue" class="btn btn-danger">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Review Overdue Tasks
                                </a>
                            <?php endif; ?>
                        </div>
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
    
    <!-- Charts Initialization -->
    <script>
        // Initialize GPA chart if data exists
        <?php if (count($terms) > 0): ?>
        const gpaCtx = document.getElementById('gpaChart').getContext('2d');
        const gpaChart = new Chart(gpaCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($terms); ?>,
                datasets: [{
                    label: 'GPA',
                    data: <?php echo json_encode($gpas); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 2,
                    tension: 0.1,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: false,
                        min: Math.max(0, Math.min(...<?php echo json_encode($gpas); ?>) - 0.5),
                        max: Math.min(4.0, Math.max(...<?php echo json_encode($gpas); ?>) + 0.5),
                        ticks: {
                            stepSize: 0.5
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'GPA: ' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                },
                responsive: true,
                maintainAspectRatio: false
            }
        });
        <?php endif; ?>

        // Initialize Subject Progress chart if data exists
        <?php if (count($subject_names) > 0): ?>
        const subjectCtx = document.getElementById('subjectProgressChart').getContext('2d');
        const subjectChart = new Chart(subjectCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($subject_names); ?>,
                datasets: [{
                    label: 'Completion Rate (%)',
                    data: <?php echo json_encode($completion_percentages); ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(54, 162, 235, 0.7)',
                        'rgba(255, 206, 86, 0.7)',
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(153, 102, 255, 0.7)',
                        'rgba(255, 159, 64, 0.7)',
                        'rgba(199, 199, 199, 0.7)',
                        'rgba(83, 102, 255, 0.7)',
                        'rgba(40, 159, 64, 0.7)',
                        'rgba(210, 30, 30, 0.7)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)',
                        'rgba(199, 199, 199, 1)',
                        'rgba(83, 102, 255, 1)',
                        'rgba(40, 159, 64, 1)',
                        'rgba(210, 30, 30, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + '% completed';
                            }
                        }
                    }
                },
                responsive: true,
                maintainAspectRatio: false
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>