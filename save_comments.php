<?php
include 'db_connect.php';
session_start();

if (!isset($_SESSION['user']['storeid'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = intval($_POST['order_id']);
    $service_type = $_POST['service_type'];
    $comments = $_POST['comments'];
    $storeid = $_SESSION['user']['storeid'];
    
    // Save comments to database
    $stmt = $conn->prepare("INSERT INTO order_comments (order_id, service_type, comments, storeid, created_at) VALUES (?, ?, ?, ?, NOW())");
    
    if ($stmt) {
        $stmt->bind_param("issi", $order_id, $service_type, $comments, $storeid);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}

$conn->close();
?>