<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "laundrydb";

$conn = new mysqli($servername, $username, $password, $dbname);

// MySQL session ka timezone bhi set karein taaki NOW() sahi chale
$conn->query("SET time_zone = '+05:30'");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


?>
