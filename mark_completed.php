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

// Check if assignment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: assignments.php");
    exit;
}

$assignment_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

// Verify the assignment belongs to the current user
$stmt = $conn->prepare("SELECT id FROM assignments WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $assignment_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Assignment doesn't exist or doesn't belong to user
    header("Location: assignments.php");
    exit;
}

$stmt->close();

// Update assignment status to "Completed"
$status = "Completed";
$stmt = $conn->prepare("UPDATE assignments SET status = ? WHERE id = ? AND user_id = ?");
$stmt->bind_param("sii", $status, $assignment_id, $user_id);

if ($stmt->execute()) {
    // Success, redirect back to assignment details page
    header("Location: assignment_details.php?id=$assignment_id&status_updated=true");
    exit;
} else {
    // Error occurred
    header("Location: assignment_details.php?id=$assignment_id&error=update_failed");
    exit;
}

$stmt->close();
$conn->close();
?>