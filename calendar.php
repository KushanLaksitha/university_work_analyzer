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

// Get current month and year (from URL parameters if set, otherwise current date)
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Validate month and year to prevent invalid dates
if ($month < 1 || $month > 12) {
    $month = date('m');
}
if ($year < 2000 || $year > 2100) {
    $year = date('Y');
}

// Calculate previous and next month/year for navigation
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

// Get the first day of the month
$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$number_of_days = date('t', $first_day_of_month);
$first_day_of_week = date('N', $first_day_of_month); // 1 (Monday) to 7 (Sunday)

// Get month name
$month_name = date('F', $first_day_of_month);

// Fetch assignments for the current month
$month_start = date('Y-m-01', $first_day_of_month);
$month_end = date('Y-m-t', $first_day_of_month);

$stmt = $conn->prepare("SELECT a.id, a.title, a.description, a.due_date, a.status, s.subject_name, s.color_code 
                       FROM assignments a 
                       JOIN subjects s ON a.subject_id = s.id 
                       WHERE a.user_id = ? AND a.due_date BETWEEN ? AND ? 
                       ORDER BY a.due_date ASC");
$stmt->bind_param("iss", $user_id, $month_start, $month_end);
$stmt->execute();
$result = $stmt->get_result();

$events = [];
while ($row = $result->fetch_assoc()) {
    $date = substr($row['due_date'], 8, 2); // Extract day from date (DD)
    if (!isset($events[$date])) {
        $events[$date] = [];
    }
    $events[$date][] = $row;
}
$stmt->close();

// Fetch exams for the current month
$stmt = $conn->prepare("SELECT e.id, e.title, e.date, e.start_time, e.end_time, s.subject_name, s.color_code 
                       FROM exams e 
                       JOIN subjects s ON e.subject_id = s.id 
                       WHERE e.user_id = ? AND e.date BETWEEN ? AND ? 
                       ORDER BY e.date ASC, e.start_time ASC");
$stmt->bind_param("iss", $user_id, $month_start, $month_end);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $date = substr($row['date'], 8, 2); // Extract day from date (DD)
    if (!isset($events[$date])) {
        $events[$date] = [];
    }
    // Add an event type to distinguish from assignments
    $row['event_type'] = 'exam';
    $events[$date][] = $row;
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
    <title>Academic Calendar - University Work Analyzer</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Calendar-specific styles -->
    <style>
        .calendar-day {
            height: 120px;
            border: 1px solid #dee2e6;
            padding: 5px;
            position: relative;
        }
        .calendar-day:hover {
            background-color: #f8f9fa;
        }
        .day-number {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        .event {
            font-size: 0.8rem;
            margin-bottom: 3px;
            padding: 2px 4px;
            border-radius: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }
        .calendar-header {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .today {
            background-color: #e8f4fe;
        }
        .outside-month {
            background-color: #f5f5f5;
            color: #aaa;
        }
        .event-tooltip {
            position: absolute;
            background-color: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            padding: 10px;
            z-index: 1000;
            display: none;
            max-width: 250px;
        }
        .filter-option {
            cursor: pointer;
        }
        .badge-count {
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 0.75rem;
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
            <div class="col-12">
                <div class="card">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <h2 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Academic Calendar</h2>
                        <div>
                            <a href="calendar.php?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="btn btn-sm btn-outline-secondary me-1">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                            <a href="calendar.php" class="btn btn-sm btn-outline-primary me-1">Today</a>
                            <a href="calendar.php?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="btn btn-sm btn-outline-secondary">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Controls Row -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-filter me-2"></i>Filters & Options
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Event Types</label>
                            <div class="form-check">
                                <input class="form-check-input filter-option" type="checkbox" value="assignments" id="filterAssignments" checked>
                                <label class="form-check-label" for="filterAssignments">
                                    <span class="badge bg-primary">Assignments</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input filter-option" type="checkbox" value="exams" id="filterExams" checked>
                                <label class="form-check-label" for="filterExams">
                                    <span class="badge bg-danger">Exams</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assignment Status</label>
                            <div class="form-check">
                                <input class="form-check-input filter-option" type="checkbox" value="completed" id="filterCompleted" checked>
                                <label class="form-check-label" for="filterCompleted">
                                    <span class="badge bg-success">Completed</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input filter-option" type="checkbox" value="inprogress" id="filterInProgress" checked>
                                <label class="form-check-label" for="filterInProgress">
                                    <span class="badge bg-warning">In Progress</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input filter-option" type="checkbox" value="notstarted" id="filterNotStarted" checked>
                                <label class="form-check-label" for="filterNotStarted">
                                    <span class="badge bg-secondary">Not Started</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>Quick Add
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <a href="assignments.php?action=add" class="btn btn-primary w-100 mb-2">
                                    <i class="fas fa-file-alt me-2"></i>New Assignment
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="exams.php?action=add" class="btn btn-danger w-100 mb-2">
                                    <i class="fas fa-clipboard-list me-2"></i>New Exam
                                </a>
                            </div>
                        </div>
                        <div class="alert alert-info mt-2 mb-0">
                            <i class="fas fa-info-circle me-2"></i>Click on any day in the calendar to quickly add an event for that date.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar Row -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0 text-center"><?php echo $month_name . ' ' . $year; ?></h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0">
                                <thead>
                                    <tr class="calendar-header text-center">
                                        <th>Monday</th>
                                        <th>Tuesday</th>
                                        <th>Wednesday</th>
                                        <th>Thursday</th>
                                        <th>Friday</th>
                                        <th>Saturday</th>
                                        <th>Sunday</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Adjust the offset since we use Monday as the first day
                                    $day_offset = $first_day_of_week - 1;
                                    if ($day_offset < 0) $day_offset = 6; // Adjust for Sunday
                                    
                                    // Calculate total cells needed (days in month + offset)
                                    $total_cells = $number_of_days + $day_offset;
                                    $total_rows = ceil($total_cells / 7);
                                    
                                    $day_counter = 1;
                                    $current_day = 1 - $day_offset;
                                    
                                    // Get current day for highlighting today
                                    $today_day = date('j');
                                    $today_month = date('n');
                                    $today_year = date('Y');
                                    
                                    for ($row = 0; $row < $total_rows; $row++) {
                                        echo "<tr>";
                                        
                                        for ($col = 0; $col < 7; $col++) {
                                            // Check if the current cell is within the current month
                                            if ($current_day <= 0 || $current_day > $number_of_days) {
                                                // Cell is outside the current month
                                                echo '<td class="calendar-day outside-month"></td>';
                                            } else {
                                                // Check if it's today
                                                $is_today = ($current_day == $today_day && $month == $today_month && $year == $today_year);
                                                $today_class = $is_today ? 'today' : '';
                                                
                                                // Format the day as a string with leading zeros for array access
                                                $day_str = str_pad($current_day, 2, '0', STR_PAD_LEFT);
                                                
                                                // Count events for badge
                                                $event_count = isset($events[$day_str]) ? count($events[$day_str]) : 0;
                                                
                                                echo '<td class="calendar-day ' . $today_class . '" data-date="' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . $day_str . '">';
                                                echo '<div class="day-number">' . $current_day . '</div>';
                                                
                                                // Display badge count if there are events
                                                if ($event_count > 0) {
                                                    echo '<span class="badge bg-primary badge-count">' . $event_count . '</span>';
                                                }
                                                
                                                // Display events for this day
                                                if (isset($events[$day_str])) {
                                                    foreach ($events[$day_str] as $event) {
                                                        // Determine if it's an assignment or exam
                                                        if (isset($event['event_type']) && $event['event_type'] == 'exam') {
                                                            $event_class = 'bg-danger';
                                                            $event_icon = '<i class="fas fa-clipboard-list me-1"></i>';
                                                            $time = date('g:i A', strtotime($event['start_time']));
                                                            $event_title = $event_icon . $time . ' - ' . htmlspecialchars($event['title']);
                                                        } else {
                                                            // It's an assignment - color based on status
                                                            if ($event['status'] == 'Completed') {
                                                                $event_class = 'bg-success';
                                                            } elseif ($event['status'] == 'In Progress') {
                                                                $event_class = 'bg-warning';
                                                            } else {
                                                                $event_class = 'bg-secondary';
                                                            }
                                                            $event_icon = '<i class="fas fa-file-alt me-1"></i>';
                                                            $event_title = $event_icon . htmlspecialchars($event['title']);
                                                        }
                                                        
                                                        // Create the event element with data attributes for tooltip
                                                        echo '<div class="event ' . $event_class . ' text-white" data-event-id="' . $event['id'] . '"';
                                                        
                                                        // Add tooltip data
                                                        echo ' data-event-title="' . htmlspecialchars($event['title']) . '"';
                                                        echo ' data-event-subject="' . htmlspecialchars($event['subject_name']) . '"';
                                                        
                                                        if (isset($event['event_type']) && $event['event_type'] == 'exam') {
                                                            $start = date('g:i A', strtotime($event['start_time']));
                                                            $end = date('g:i A', strtotime($event['end_time']));
                                                            echo ' data-event-time="' . $start . ' - ' . $end . '"';
                                                            echo ' data-event-type="exam"';
                                                        } else {
                                                            echo ' data-event-status="' . htmlspecialchars($event['status']) . '"';
                                                            echo ' data-event-description="' . htmlspecialchars($event['description']) . '"';
                                                            echo ' data-event-type="assignment"';
                                                        }
                                                        
                                                        echo '>' . $event_title . '</div>';
                                                    }
                                                }
                                                echo '</td>';
                                            }
                                            $current_day++;
                                        }
                                        echo "</tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Event tooltip container (hidden by default) -->
        <div id="eventTooltip" class="event-tooltip"></div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> University Work Analyzer | Developed for Academic Tracking</p>
        </div>
    </footer>

    <!-- Add Event Modal -->
    <div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addEventModalLabel">Add Event</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Select the type of event you want to add for <strong id="modalDate"></strong>:</p>
                    <div class="d-grid gap-2">
                        <a href="#" id="addAssignmentBtn" class="btn btn-primary">
                            <i class="fas fa-file-alt me-2"></i>Add Assignment
                        </a>
                        <a href="#" id="addExamBtn" class="btn btn-danger">
                            <i class="fas fa-clipboard-list me-2"></i>Add Exam
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Calendar JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tooltip = document.getElementById('eventTooltip');
            const addEventModal = new bootstrap.Modal(document.getElementById('addEventModal'));
            let selectedDate = '';
            
            // Show event details on hover
            document.querySelectorAll('.event').forEach(eventElement => {
                eventElement.addEventListener('mouseover', function(e) {
                    const eventType = this.getAttribute('data-event-type');
                    const title = this.getAttribute('data-event-title');
                    const subject = this.getAttribute('data-event-subject');
                    
                    let tooltipContent = `<div class="fw-bold">${title}</div>`;
                    tooltipContent += `<div>Subject: ${subject}</div>`;
                    
                    if (eventType === 'exam') {
                        const time = this.getAttribute('data-event-time');
                        tooltipContent += `<div>Time: ${time}</div>`;
                        tooltipContent += `<div class="text-danger fw-bold mt-2">Exam</div>`;
                    } else {
                        const status = this.getAttribute('data-event-status');
                        const description = this.getAttribute('data-event-description');
                        tooltipContent += `<div>Status: ${status}</div>`;
                        if (description) {
                            tooltipContent += `<div class="mt-2 text-muted">${description}</div>`;
                        }
                        tooltipContent += `<div class="fw-bold mt-2">Assignment</div>`;
                    }
                    
                    tooltip.innerHTML = tooltipContent;
                    tooltip.style.display = 'block';
                    
                    // Position the tooltip next to the mouse
                    const rect = this.getBoundingClientRect();
                    tooltip.style.left = (rect.right + 10) + 'px';
                    tooltip.style.top = rect.top + 'px';
                    
                    // Check if tooltip goes out of viewport and adjust
                    const tooltipRect = tooltip.getBoundingClientRect();
                    if (tooltipRect.right > window.innerWidth) {
                        tooltip.style.left = (rect.left - tooltipRect.width - 10) + 'px';
                    }
                });

                eventElement.addEventListener('mouseout', function() {
                    tooltip.style.display = 'none';
                });
                
                // Navigate to detail page on click
                eventElement.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const eventId = this.getAttribute('data-event-id');
                    const eventType = this.getAttribute('data-event-type');
                    
                    if (eventType === 'exam') {
                        window.location.href = `exam_details.php?id=${eventId}`;
                    } else {
                        window.location.href = `assignment_details.php?id=${eventId}`;
                    }
                });
            });
            
            // Hide tooltip when clicking elsewhere
            document.addEventListener('click', function() {
                tooltip.style.display = 'none';
            });
            
            // Quick add event by clicking on a calendar day
            document.querySelectorAll('.calendar-day').forEach(day => {
                if (!day.classList.contains('outside-month')) {
                    day.addEventListener('click', function() {
                        selectedDate = this.getAttribute('data-date');
                        document.getElementById('modalDate').textContent = new Date(selectedDate).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                        addEventModal.show();
                    });
                }
            });
            
            // Set up quick add buttons
            document.getElementById('addAssignmentBtn').addEventListener('click', function() {
                window.location.href = `assignments.php?action=add&date=${selectedDate}`;
            });
            
            document.getElementById('addExamBtn').addEventListener('click', function() {
                window.location.href = `exams.php?action=add&date=${selectedDate}`;
            });
            
            // Filter functionality
            const filterCheckboxes = document.querySelectorAll('.filter-option');
            filterCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', applyFilters);
            });
            
            function applyFilters() {
                // Get filter values
                const showAssignments = document.getElementById('filterAssignments').checked;
                const showExams = document.getElementById('filterExams').checked;
                const showCompleted = document.getElementById('filterCompleted').checked;
                const showInProgress = document.getElementById('filterInProgress').checked;
                const showNotStarted = document.getElementById('filterNotStarted').checked;
                
                document.querySelectorAll('.event').forEach(event => {
                    const eventType = event.getAttribute('data-event-type');
                    let shouldShow = false;
                    
                    if (eventType === 'exam') {
                        shouldShow = showExams;
                    } else {
                        // It's an assignment, check status too
                        const status = event.getAttribute('data-event-status');
                        shouldShow = showAssignments && 
                            ((status === 'Completed' && showCompleted) ||
                             (status === 'In Progress' && showInProgress) ||
                             (status === 'Not Started' && showNotStarted));
                    }
                    
                    event.style.display = shouldShow ? 'block' : 'none';
                });
                
                // Update badge counts
                updateBadgeCounts();
            }
            
            function updateBadgeCounts() {
                document.querySelectorAll('.calendar-day').forEach(day => {
                    if (!day.classList.contains('outside-month')) {
                        const events = day.querySelectorAll('.event');
                        const visibleEvents = Array.from(events).filter(event => 
                            window.getComputedStyle(event).display !== 'none'
                        ).length;
                        
                        // Update or remove badge
                        let badge = day.querySelector('.badge-count');
                        if (visibleEvents > 0) {
                            if (badge) {
                                badge.textContent = visibleEvents;
                            } else {
                                badge = document.createElement('span');
                                badge.className = 'badge bg-primary badge-count';
                                badge.textContent = visibleEvents;
                                day.appendChild(badge);
                            }
                        } else if (badge) {
                            badge.remove();
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>