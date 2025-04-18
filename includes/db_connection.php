<?php
/**
 * Database Connection
 * 
 * This file establishes a connection to the MySQL database for the University Work Analyzer application.
 * Place this file in an 'includes' directory and include it in other PHP files when database access is needed.
 */

// Database configuration
$db_host = 'localhost';      // Database host
$db_name = 'university_work_analyzer';  // Database name
$db_user = 'root';           // Database username - changed to 'root' which is the default
$db_pass = '';               // Database password - changed to empty string for default setup

// Error reporting (comment these lines in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Create connection - using try-catch to better handle connection errors
try {
    // Create connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Set character set to utf8mb4 (supports full Unicode)
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    // Log the error
    error_log('Database connection error: ' . $e->getMessage(), 0);
    
    // Display user-friendly error
    echo "
        <div style='width: 50%; margin: 100px auto; text-align: center; font-family: Arial, sans-serif;'>
            <h2>Database Connection Error</h2>
            <p>There was a problem connecting to the database.</p>
            <p>Error details: " . htmlspecialchars($e->getMessage()) . "</p>
            <p>Please check your database credentials in db_connection.php</p>
        </div>
    ";
    exit;
}

// Optional: Set default timezone
date_default_timezone_set('UTC'); // Change to your timezone if necessary

/**
 * Helper function to safely escape values for SQL queries
 * 
 * @param string $value The value to escape
 * @return string The escaped value
 */
function escape_sql($value) {
    global $conn;
    return $conn->real_escape_string($value);
}

/**
 * Helper function to handle database errors
 * 
 * @param string $query The SQL query that caused the error
 * @return void
 */
function handle_db_error($query = '') {
    global $conn;
    
    // Log the error with the query
    $error_message = 'MySQL Error (' . $conn->errno . '): ' . $conn->error;
    if (!empty($query)) {
        $error_message .= ' in query: ' . $query;
    }
    
    error_log($error_message, 0);
    
    // Return a user-friendly message
    return "An error occurred while accessing the database. Please try again later.";
}