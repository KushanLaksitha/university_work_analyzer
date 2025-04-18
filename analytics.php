<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Database connection
require_once 'includes/db_connection.php';


// Get user information
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($first_name, $last_name);
$stmt->fetch();
$stmt->close();

// Get user info
$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc();
$user_name = $user ? $user['first_name'] . ' ' . $user['last_name'] : 'User';

// Get active term
$stmt = $conn->prepare("SELECT id, term_name FROM academic_terms WHERE user_id = ? AND is_current = 1 LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$term_result = $stmt->get_result();
$active_term = $term_result->fetch_assoc();
$active_term_id = $active_term ? $active_term['id'] : null;
$active_term_name = $active_term ? $active_term['term_name'] : 'No active term';

// Get GPA data for chart
$terms = [];
$gpas = [];
$stmt = $conn->prepare("SELECT term_name, gpa FROM academic_terms WHERE user_id = ? ORDER BY start_date");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $terms[] = $row['term_name'];
    $gpas[] = $row['gpa'] !== null ? (float)$row['gpa'] : null;
}

// Get grade distribution data
$grade_labels = ['A', 'B', 'C', 'D', 'F'];
$grade_counts = [0, 0, 0, 0, 0];
$grade_colors = [
    'rgba(25, 135, 84, 0.8)',  // Green for A
    'rgba(13, 110, 253, 0.8)',  // Blue for B
    'rgba(255, 193, 7, 0.8)',   // Yellow for C
    'rgba(255, 128, 0, 0.8)',   // Orange for D
    'rgba(220, 53, 69, 0.8)'    // Red for F
];

$stmt = $conn->prepare("
    SELECT 
        CASE 
            WHEN (grade/100)*100 >= 90 THEN 'A'
            WHEN (grade/100)*100 >= 80 THEN 'B'
            WHEN (grade/100)*100 >= 70 THEN 'C'
            WHEN (grade/100)*100 >= 60 THEN 'D'
            ELSE 'F'
        END as letter_grade,
        COUNT(*) as count
    FROM assignments 
    WHERE user_id = ? AND grade IS NOT NULL
    GROUP BY letter_grade
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $index = array_search($row['letter_grade'], $grade_labels);
    if ($index !== false) {
        $grade_counts[$index] = (int)$row['count'];
    }
}

// Get assignment status distribution
$status_labels = ['Not Started', 'In Progress', 'Completed', 'Submitted', 'Graded'];
$status_counts = [0, 0, 0, 0, 0];

$stmt = $conn->prepare("
    SELECT status, COUNT(*) as count 
    FROM assignments 
    WHERE user_id = ? 
    GROUP BY status
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $index = array_search($row['status'], $status_labels);
    if ($index !== false) {
        $status_counts[$index] = (int)$row['count'];
    }
}

// Get assignment type distribution
$type_labels = ['Assignment', 'Quiz', 'Project', 'Exam', 'Presentation', 'Lab', 'Reading', 'Other'];
$type_counts = [0, 0, 0, 0, 0, 0, 0, 0];

$stmt = $conn->prepare("
    SELECT type, COUNT(*) as count 
    FROM assignments 
    WHERE user_id = ? 
    GROUP BY type
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $index = array_search($row['type'], $type_labels);
    if ($index !== false) {
        $type_counts[$index] = (int)$row['count'];
    }
}

// Get priority distribution
$priority_labels = ['Low', 'Medium', 'High'];
$priority_counts = [0, 0, 0];

$stmt = $conn->prepare("
    SELECT priority, COUNT(*) as count 
    FROM assignments 
    WHERE user_id = ? AND status <> 'Graded'
    GROUP BY priority
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $index = array_search($row['priority'], $priority_labels);
    if ($index !== false) {
        $priority_counts[$index] = (int)$row['count'];
    }
}

// Get time management data (estimated vs actual hours by subject)
$time_data = [];
$time_subjects = [];
$time_estimated = [];
$time_actual = [];

$stmt = $conn->prepare("
    SELECT 
        s.subject_name,
        SUM(a.estimated_hours) as total_estimated,
        SUM(a.actual_hours_spent) as total_actual
    FROM 
        assignments a
    JOIN 
        subjects s ON a.subject_id = s.id
    WHERE 
        a.user_id = ?
    GROUP BY 
        s.subject_name
    ORDER BY 
        s.subject_name
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $time_data[] = $row;
    $time_subjects[] = $row['subject_name'];
    $time_estimated[] = $row['total_estimated'] ? (float)$row['total_estimated'] : 0;
    $time_actual[] = $row['total_actual'] ? (float)$row['total_actual'] : 0;
}

// Get workload by day of week
$workload_by_day = [];
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$counts = [0, 0, 0, 0, 0, 0, 0];

$stmt = $conn->prepare("
    SELECT 
        DAYNAME(due_date) as day_of_week,
        COUNT(*) as assignment_count
    FROM 
        assignments
    WHERE 
        user_id = ?
    GROUP BY 
        day_of_week
    ORDER BY 
        FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $index = array_search($row['day_of_week'], $days);
    if ($index !== false) {
        $counts[$index] = (int)$row['assignment_count'];
    }
}

// Get total hours by subject
$hours_by_subject = [];
$hour_subjects = [];
$hour_totals = [];

$stmt = $conn->prepare("
    SELECT 
        s.subject_name,
        SUM(a.actual_hours_spent) as total_hours
    FROM 
        assignments a
    JOIN 
        subjects s ON a.subject_id = s.id
    WHERE 
        a.user_id = ? AND 
        a.actual_hours_spent IS NOT NULL
    GROUP BY 
        s.subject_name
    ORDER BY 
        total_hours DESC
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $hours_by_subject[] = $row;
    $hour_subjects[] = $row['subject_name'];
    $hour_totals[] = $row['total_hours'] ? (float)$row['total_hours'] : 0;
}

// Get subjects list with statistics
$subjects = [];
$stmt = $conn->prepare("
    SELECT 
        s.id,
        s.subject_code,
        s.subject_name,
        s.instructor,
        s.credits,
        s.is_active,
        COUNT(a.id) as assignment_count,
        AVG(a.grade) as average_grade
    FROM 
        subjects s
    LEFT JOIN 
        assignments a ON s.id = a.subject_id
    WHERE 
        s.user_id = ?
    GROUP BY 
        s.id
    ORDER BY 
        s.subject_name
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Custom CSS -->
    <style>
        .card {
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .stats-card {
            text-align: center;
            padding: 15px;
        }
        .stats-card h3 {
            margin-top: 0;
            font-size: 2rem;
        }
        .stats-card p {
            margin-bottom: 0;
            color: #6c757d;
        }
        .subject-row:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        .nav-tabs .nav-link {
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
        }
        .priority-high {
            color: #dc3545;
        }
        .priority-medium {
            color: #ffc107;
        }
        .priority-low {
            color: #198754;
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
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Dashboard</h1>
            <div class="d-flex align-items-center">
                <span class="me-2">Active Term:</span>
                <h5 class="mb-0"><?php echo htmlspecialchars($active_term_name); ?></h5>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card">
                    <h3><?php echo count($subjects); ?></h3>
                    <p>Subjects</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <h3>
                        <?php 
                        $total_assignments = array_sum($status_counts);
                        echo $total_assignments;
                        ?>
                    </h3>
                    <p>Assignments</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <h3>
                        <?php 
                        $completed = $status_counts[2] + $status_counts[3] + $status_counts[4];
                        echo $total_assignments > 0 ? round(($completed / $total_assignments) * 100) . '%' : '0%';
                        ?>
                    </h3>
                    <p>Completion Rate</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <h3>
                        <?php
                        $current_gpa = end($gpas);
                        echo $current_gpa !== false ? number_format($current_gpa, 2) : 'N/A';
                        ?>
                    </h3>
                    <p>Current GPA</p>
                </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">GPA Trend</h5>
                        <div class="btn-group btn-group-sm">
                            <button id="showLineChart" class="btn btn-outline-primary active">Line</button>
                            <button id="showBarChart" class="btn btn-outline-primary">Bar</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="gpaChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Grade Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="gradeDistributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="row mt-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Assignment Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Assignment Types</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="typeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Priority Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="priorityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 3 -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Time Management</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="timeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Workload by Day</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="workloadByDayChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subjects Table -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Subjects Overview</h5>
                        <div class="btn-group btn-group-sm">
                            <button id="filterAll" class="btn btn-outline-primary active">All</button>
                            <button id="filterActive" class="btn btn-outline-primary">Active</button>
                            <button id="filterHighest" class="btn btn-outline-primary">Highest Grades</button>
                            <button id="filterLowest" class="btn btn-outline-primary">Lowest Grades</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Subject</th>
                                        <th>Average Grade</th>
                                        <th>Assignments</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjects as $subject): ?>
                                    <tr class="subject-row" data-active="<?php echo $subject['is_active'] ? 'true' : 'false'; ?>">
                                        <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                        <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                        <td>
                                            <?php 
                                            if ($subject['average_grade'] !== null) {
                                                echo number_format($subject['average_grade'], 2) . '%';
                                            } else {
                                                echo 'No grades yet';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo (int)$subject['assignment_count']; ?></td>
                                        <td>
                                            <?php if ($subject['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="subjects.php?id=<?php echo $subject['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($subjects)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No subjects found. <a href="subjects.php">Add your first subject</a>.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hours Chart -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Hours Spent by Subject</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="hoursChart"></canvas>
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

                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
    // Helper function to safely parse PHP JSON data
    function safeParseJSON(jsonStr, defaultValue = []) {
        try {
            // Handle the case where PHP outputs empty arrays or null
            if (!jsonStr || jsonStr === 'null' || jsonStr === '[]') {
                return defaultValue;
            }
            return JSON.parse(jsonStr);
        } catch (e) {
            console.error("Error parsing JSON data:", e);
            return defaultValue;
        }
    }
    
    // GPA Chart
    if (document.getElementById('gpaChart')) {
        const gpaCtx = document.getElementById('gpaChart').getContext('2d');
        let gpaChart;
        
        // Safely parse PHP data for GPA chart
        const terms = safeParseJSON('<?php echo json_encode($terms); ?>');
        const gpas = safeParseJSON('<?php echo json_encode($gpas); ?>');
        
        // Create the initial line chart
        function createGPALineChart() {
            if (!terms.length || !gpas.length) {
                // Display a message if no data
                document.getElementById('gpaChart').insertAdjacentHTML('afterend', 
                    '<div class="text-center text-muted my-3">No GPA data available to display.</div>');
                return null;
            }
            
            return new Chart(gpaCtx, {
                type: 'line',
                data: {
                    labels: terms,
                    datasets: [{
                        label: 'GPA',
                        data: gpas,
                        backgroundColor: 'rgba(13, 110, 253, 0.2)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 2,
                        tension: 0.1,
                        fill: true,
                        pointBackgroundColor: 'rgba(13, 110, 253, 1)',
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 0,
                            max: 4,
                            ticks: {
                                stepSize: 0.5
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'GPA Trend by Academic Term'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `GPA: ${context.raw}`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Create bar chart version
        function createGPABarChart() {
            if (!terms.length || !gpas.length) {
                return null;
            }
            
            return new Chart(gpaCtx, {
                type: 'bar',
                data: {
                    labels: terms,
                    datasets: [{
                        label: 'GPA',
                        data: gpas,
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 0,
                            max: 4,
                            ticks: {
                                stepSize: 0.5
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'GPA by Academic Term'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `GPA: ${context.raw}`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Initialize with line chart
        gpaChart = createGPALineChart();
        
        // Only set up event listeners if chart was created
        if (gpaChart) {
            // Switch between chart types
            const lineChartBtn = document.getElementById('showLineChart');
            const barChartBtn = document.getElementById('showBarChart');
            
            if (lineChartBtn) {
                lineChartBtn.addEventListener('click', function() {
                    if (gpaChart) gpaChart.destroy();
                    gpaChart = createGPALineChart();
                });
            }
            
            if (barChartBtn) {
                barChartBtn.addEventListener('click', function() {
                    if (gpaChart) gpaChart.destroy();
                    gpaChart = createGPABarChart();
                });
            }
        }
    }
    
    // Grade Distribution Chart
    if (document.getElementById('gradeDistributionChart')) {
        const gradeDistCtx = document.getElementById('gradeDistributionChart').getContext('2d');
        
        // Safely parse PHP data
        const gradeLabels = safeParseJSON('<?php echo json_encode($grade_labels); ?>');
        const gradeCounts = safeParseJSON('<?php echo json_encode($grade_counts); ?>');
        const gradeColors = safeParseJSON('<?php echo json_encode(array_values($grade_colors)); ?>');
        
        if (gradeLabels.length > 0 && gradeCounts.length > 0) {
            new Chart(gradeDistCtx, {
                type: 'pie',
                data: {
                    labels: gradeLabels,
                    datasets: [{
                        data: gradeCounts,
                        backgroundColor: gradeColors.length === gradeCounts.length ? gradeColors : 
                            gradeCounts.map(() => `rgba(${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, 0.8)`),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            // Display message if no data
            document.getElementById('gradeDistributionChart').insertAdjacentHTML('afterend', 
                '<div class="text-center text-muted my-3">No grade distribution data available.</div>');
        }
    }
    
    // Assignment Status Chart
    if (document.getElementById('statusChart')) {
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        
        // Safely parse PHP data
        const statusLabels = safeParseJSON('<?php echo json_encode($status_labels); ?>');
        const statusCounts = safeParseJSON('<?php echo json_encode($status_counts); ?>');
        const statusColors = [];
        
        // Map status labels to colors
        statusLabels.forEach(status => {
            switch(status) {
                case 'Not Started': statusColors.push('rgba(108, 117, 125, 0.8)'); break;
                case 'In Progress': statusColors.push('rgba(255, 193, 7, 0.8)'); break;
                case 'Completed': statusColors.push('rgba(25, 135, 84, 0.8)'); break;
                case 'Submitted': statusColors.push('rgba(13, 110, 253, 0.8)'); break;
                case 'Graded': statusColors.push('rgba(111, 66, 193, 0.8)'); break;
                default: statusColors.push('rgba(108, 117, 125, 0.8)');
            }
        });
        
        if (statusLabels.length > 0 && statusCounts.length > 0) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: statusColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            document.getElementById('statusChart').insertAdjacentHTML('afterend', 
                '<div class="text-center text-muted my-3">No assignment status data available.</div>');
        }
    }
    
    // Assignment Type Chart
    if (document.getElementById('typeChart')) {
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        
        // Safely parse PHP data
        const typeLabels = safeParseJSON('<?php echo json_encode($type_labels); ?>');
        const typeCounts = safeParseJSON('<?php echo json_encode($type_counts); ?>');
        const typeColors = [];
        
        // Map type labels to colors
        typeLabels.forEach(type => {
            switch(type) {
                case 'Assignment': typeColors.push('rgba(13, 110, 253, 0.8)'); break;
                case 'Quiz': typeColors.push('rgba(255, 193, 7, 0.8)'); break;
                case 'Project': typeColors.push('rgba(25, 135, 84, 0.8)'); break;
                case 'Exam': typeColors.push('rgba(220, 53, 69, 0.8)'); break;
                case 'Presentation': typeColors.push('rgba(111, 66, 193, 0.8)'); break;
                case 'Lab': typeColors.push('rgba(32, 201, 151, 0.8)'); break;
                case 'Reading': typeColors.push('rgba(102, 16, 242, 0.8)'); break;
                default: typeColors.push('rgba(108, 117, 125, 0.8)');
            }
        });
        
        if (typeLabels.length > 0 && typeCounts.length > 0) {
            new Chart(typeCtx, {
                type: 'pie',
                data: {
                    labels: typeLabels,
                    datasets: [{
                        data: typeCounts,
                        backgroundColor: typeColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            document.getElementById('typeChart').insertAdjacentHTML('afterend', 
                '<div class="text-center text-muted my-3">No assignment type data available.</div>');
        }
    }
    
    // Priority Chart
    if (document.getElementById('priorityChart')) {
        const priorityCtx = document.getElementById('priorityChart').getContext('2d');
        
        // Safely parse PHP data
        const priorityLabels = safeParseJSON('<?php echo json_encode($priority_labels); ?>');
        const priorityCounts = safeParseJSON('<?php echo json_encode($priority_counts); ?>');
        const priorityColors = ['rgba(25, 135, 84, 0.8)', 'rgba(255, 193, 7, 0.8)', 'rgba(220, 53, 69, 0.8)'];
        
        // Check if we have valid data
        if (priorityLabels.length > 0 && priorityCounts.length > 0 && priorityCounts.some(count => count > 0)) {
            new Chart(priorityCtx, {
                type: 'doughnut',
                data: {
                    labels: priorityLabels,
                    datasets: [{
                        data: priorityCounts,
                        backgroundColor: priorityColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label} Priority: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            document.getElementById('priorityChart').insertAdjacentHTML('afterend', 
                '<div class="text-center text-muted my-3">No priority data available.</div>');
        }
    }
    
    // Time Management Chart
    if (document.getElementById('timeChart')) {
        const timeCtx = document.getElementById('timeChart').getContext('2d');
        
        // Safely parse PHP data
        // Use special syntax for PHP block content
        const timeSubjects = safeParseJSON('<?php echo json_encode($time_subjects); ?>');
        const timeEstimated = safeParseJSON('<?php echo json_encode($time_estimated); ?>');
        const timeActual = safeParseJSON('<?php echo json_encode($time_actual); ?>');
        
        if (timeSubjects.length > 0 && (timeEstimated.length > 0 || timeActual.length > 0)) {
            new Chart(timeCtx, {
                type: 'bar',
                data: {
                    labels: timeSubjects,
                    datasets: [
                        {
                            label: 'Estimated Hours',
                            data: timeEstimated,
                            backgroundColor: 'rgba(13, 110, 253, 0.7)',
                            borderColor: 'rgba(13, 110, 253, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Actual Hours',
                            data: timeActual,
                            backgroundColor: 'rgba(108, 117, 125, 0.7)',
                            borderColor: 'rgba(108, 117, 125, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            ticks: {
                                autoSkip: false,
                                maxRotation: 45,
                                minRotation: 45
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Hours'
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Estimated vs. Actual Hours by Subject'
                        }
                    }
                }
            });
        } else {
            document.getElementById('timeChart').insertAdjacentHTML('afterend', 
                '<div class="text-center text-muted my-3">No time management data available.</div>');
        }
    }
    
    // Workload by Day Chart
    if (document.getElementById('workloadByDayChart')) {
        const workloadCtx = document.getElementById('workloadByDayChart').getContext('2d');
        
        // Safely parse PHP data
        const days = safeParseJSON('<?php echo json_encode($days); ?>');
        const assignmentCounts = safeParseJSON('<?php echo json_encode($counts); ?>');
        
        if (days.length > 0 && assignmentCounts.length > 0) {
            new Chart(workloadCtx, {
                type: 'bar',
                data: {
                    labels: days,
                    datasets: [{
                        label: 'Number of Assignments',
                        data: assignmentCounts,
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            },
                            title: {
                                display: true,
                                text: 'Assignment Count'
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Assignment Due Dates by Day of Week'
                        }
                    }
                }
            });
        } else {
            document.getElementById('workloadByDayChart').insertAdjacentHTML('afterend', 
                '<div class="text-center text-muted my-3">No workload by day data available.</div>');
        }
    }
    
    // Hours Chart
    if (document.getElementById('hoursChart')) {
        const hoursCtx = document.getElementById('hoursChart').getContext('2d');
        
        // Safely parse PHP data
        const hourSubjects = safeParseJSON('<?php echo json_encode($hour_subjects); ?>');
        const hourTotals = safeParseJSON('<?php echo json_encode($hour_totals); ?>');
        
        if (hourSubjects.length > 0 && hourTotals.length > 0) {
            new Chart(hoursCtx, {
                type: 'bar',
                data: {
                    labels: hourSubjects,
                    datasets: [{
                        label: 'Hours Spent',
                        data: hourTotals,
                        backgroundColor: [
                            'rgba(13, 110, 253, 0.7)',
                            'rgba(25, 135, 84, 0.7)',
                            'rgba(255, 193, 7, 0.7)',
                            'rgba(220, 53, 69, 0.7)',
                            'rgba(111, 66, 193, 0.7)',
                            'rgba(32, 201, 151, 0.7)',
                            'rgba(102, 16, 242, 0.7)',
                            'rgba(253, 126, 20, 0.7)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Hours'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Total Hours Spent by Subject'
                        }
                    }
                }
            });
        } else {
            document.getElementById('hoursChart').insertAdjacentHTML('afterend', 
                '<div class="text-center text-muted my-3">No hours data available.</div>');
        }
    }
    
    // Subject filter handlers
    if (document.getElementById('filterAll')) {
        document.getElementById('filterAll').addEventListener('click', function(e) {
            e.preventDefault();
            // Show all subjects in table
            document.querySelectorAll('tbody tr').forEach(row => {
                row.style.display = '';
            });
        });
    }
    
    if (document.getElementById('filterActive')) {
        document.getElementById('filterActive').addEventListener('click', function(e) {
            e.preventDefault();
            // Logic for filtering active subjects
            // This would need additional data attributes on rows to determine active status
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const isActive = row.getAttribute('data-active') === 'true';
                row.style.display = isActive ? '' : 'none';
            });
        });
    }
    
    if (document.getElementById('filterHighest')) {
        document.getElementById('filterHighest').addEventListener('click', function(e) {
            e.preventDefault();
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            
            // Sort by average grade (highest first)
            rows.sort((a, b) => {
                // Safe parsing of grade values
                const aGradeText = a.cells[2] ? a.cells[2].textContent.trim() : '';
                const bGradeText = b.cells[2] ? b.cells[2].textContent.trim() : '';
                
                const aGrade = parseFloat(aGradeText) || 0;
                const bGrade = parseFloat(bGradeText) || 0;
                
                return bGrade - aGrade;
            });
            
            // Show only top 5
            rows.forEach((row, index) => {
                row.style.display = index < 5 ? '' : 'none';
            });
        });
    }
    
    if (document.getElementById('filterLowest')) {
        document.getElementById('filterLowest').addEventListener('click', function(e) {
            e.preventDefault();
            const rows = Array.from(document.querySelectorAll('tbody tr'));
            
            // Filter out rows with "No grades yet"
            const rowsWithGrades = rows.filter(row => {
                const cellContent = row.cells[2] ? row.cells[2].textContent.trim() : '';
                return cellContent && !cellContent.includes('No grades yet') && !isNaN(parseFloat(cellContent));
            });
            
            // Sort by average grade (lowest first)
            rowsWithGrades.sort((a, b) => {
                const aGrade = parseFloat(a.cells[2].textContent) || 0;
                const bGrade = parseFloat(b.cells[2].textContent) || 0;
                return aGrade - bGrade;
            });
            
            // Hide all rows first
            rows.forEach(row => row.style.display = 'none');
            
            // Then show only bottom 5 with grades
            rowsWithGrades.slice(0, 5).forEach(row => row.style.display = '');
        });
    }
});
                        </script>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Date range picker dependencies -->
<script type="text/javascript" src="https://cdn.jsdelivr.net/jquery/latest/jquery.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
</body>
</html>