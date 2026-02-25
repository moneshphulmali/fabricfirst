<?php 
include 'db_connect.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if ($conn->connect_error) {
    if (isset($_GET['action'])) {
        header("Content-Type: application/json");
        die(json_encode(["error" => "❌ Database connection failed"]));
    } else {
        die("❌ Database connection failed");
    }
}

// ✅ Check if user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store']['storeid'])) {
    if (isset($_GET['action'])) {
        header("Content-Type: application/json");
        die(json_encode(["error" => "User not logged in."]));
    } else {
        header("Location: login.php");
        exit;
    }
}

// ✅ Check user ID
if (isset($_SESSION['user']['id'])) {
    $user_id = intval($_SESSION['user']['id']);
} elseif (isset($_SESSION['user']['user_id'])) {
    $user_id = intval($_SESSION['user']['user_id']);
} else {
    die("❌ User ID not found in session. Please login again.");
}

$current_store_id = intval($_SESSION['user']['current_store']['storeid']);

// ✅✅✅✅✅ FIXED: Use session data instead of querying again ✅✅✅✅✅
if (!isset($_SESSION['user']['is_admin']) || !isset($_SESSION['user']['role_id'])) {
    die("❌ Session data corrupted. Please login again.");
}

$is_admin = $_SESSION['user']['is_admin'];
$current_role_id = $_SESSION['user']['role_id'];
$current_role_name = $_SESSION['user']['role'] ?? '';

// Set variables for compatibility
$_SESSION['user']['role_type'] = $current_role_name;
$_SESSION['user']['current_store_id'] = $current_store_id;

$storeid = $current_store_id;

// "Mujhe wo saare stores dikhao jahan yeh user ya to ADMIN hai (role_id=1) ya MANAGER hai (role_id=2)"
//"Aur sirf woh entries jahan role_id ya to 1 hai (Admin) YA 2 hai (Manager)"


$admin_stores = [];
if ($is_admin) {
    $store_query = $conn->prepare("
        SELECT sur.storeid, s.store_name 
        FROM store_user_roles sur
        JOIN stores s ON sur.storeid = s.storeid
        WHERE sur.user_id = ? 
        AND sur.role_id in (1,2)
    ");
    $store_query->bind_param("i", $user_id);
    $store_query->execute();
    $store_result = $store_query->get_result();
    
    while ($store_row = $store_result->fetch_assoc()) {
        $admin_stores[] = $store_row;
    }
    $store_query->close();
} else {
    // For non-admin (manager/salesman), only current store
    $admin_stores[] = [
        'storeid' => $current_store_id,
        'store_name' => $_SESSION['user']['current_store']['store_name'] ?? 'Current Store'
    ];
}

// Check if store column should be shown
$show_store_column = ($is_admin || count($admin_stores) > 1);

// ---------------------------
// API: get_label_data (for Tag Printing)
// ---------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_label_data') {
    header("Content-Type: application/json; charset=utf-8");

    $order_id = intval($_GET['order_id']);
    if (!$order_id) {
        echo json_encode(["error" => "Invalid Order ID"]);
        exit;
    }

    
    if ($is_admin) {
        $q = $conn->prepare("
            SELECT 
                o.id,
                c.name AS customerName,
                o.delivery_date,
                o.id AS orderNo,
                s.owner_name,
                oi.order_items_with_comments,
                o.payable_amount,
                o.total_amount,
                o.discount_amount
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN stores s ON o.storeid = s.storeid
            LEFT JOIN order_item oi ON o.order_item_id = oi.id AND oi.is_current = 1
            WHERE o.id = ? 
            AND o.storeid IN ( 
                SELECT storeid 
                FROM store_user_roles
                WHERE user_id = ? 
                AND role_id IN (1, 2)  
            )
            LIMIT 1
        ");
        $q->bind_param("ii", $order_id, $user_id);
    } else {
        $q = $conn->prepare("
            SELECT 
                o.id,
                c.name AS customerName,
                o.delivery_date,
                o.id AS orderNo,
                s.owner_name,
                oi.order_items_with_comments,
                o.payable_amount,
                o.total_amount,
                o.discount_amount
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN stores s ON o.storeid = s.storeid
            LEFT JOIN order_item oi ON o.order_item_id = oi.id AND oi.is_current = 1
            WHERE o.id = ? 
            AND o.storeid = ?  -- ✅ Only current store for non-admin
            LIMIT 1
        ");
        $q->bind_param("ii", $order_id, $current_store_id);
    }
    
    $q->execute();
    $res = $q->get_result();
    $order = $res->fetch_assoc();
    $q->close();

    if (!$order) {
        echo json_encode(["error" => "Order not found or no access"]);
        exit;
    }

    $items = [];
    
    // Get items from order_item table (JSON format)
    $order_items_json = $order['order_items_with_comments'] ?? "[]";
    $order_items_data = json_decode($order_items_json, true);
    
    // Extract items from JSON structure  
    if (!empty($order_items_data) && isset($order_items_data['items']) && is_array($order_items_data['items'])) {
        foreach($order_items_data['items'] as $item) {
            $items[] = [
                'product' => $item['product_name'] ?? $item['product'] ?? $item['item'] ?? '',
                'service_type' => $item['service_type'] ?? $item['service'] ?? '',
                'qty' => floatval($item['qty'] ?? 1),
                'unit' => $item['unit'] ?? 'Pcs',
                'price' => $item['price'] ?? 0,
                'comments' => $item['comments'] ?? [] 
            ];
        }
    }
    
    // Payment query
    $payQ = $conn->prepare("
        SELECT 
            COALESCE(paid_amount, 0) AS paid,
            COALESCE(due_amount, 0) AS due
        FROM payments 
        WHERE order_id = ?
        LIMIT 1
    ");
    $payQ->bind_param("i", $order_id);
    $payQ->execute();
    $payData = $payQ->get_result()->fetch_assoc();
    $payQ->close();

    $paid  = floatval($payData['paid'] ?? 0);
    $due   = floatval($payData['due'] ?? 0);
    $total = $paid + $due;

    if ($total == 0) {
        $paymentStatus = "Due";
    } elseif ($paid >= $total) {
        $paymentStatus = "Paid";
    } elseif ($paid > 0 && $paid < $total) {
        $paymentStatus = "Partial";
    } else {
        $paymentStatus = "Due";
    }

    echo json_encode([
        "customerName"   => $order['customerName'],
        "orderNo"        => $order['orderNo'],
        "deliveryDate"   => $order['delivery_date'],
        "paymentStatus"  => $paymentStatus,
        "owner_name"     => $order['owner_name'] ?? '',
        "items"          => $items,
        "payable_amount" => $order['payable_amount'] ?? 0,
        "total_amount"   => $order['total_amount'] ?? 0,
        "discount_amount" => $order['discount_amount'] ?? 0
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// ---------------------------
// API: get_orders (MAIN MODIFICATION)
// ---------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_orders') {
    header("Content-Type: application/json; charset=utf-8");

    // 
    if ($is_admin) {
       
        $sql = "
            SELECT 
                o.id, 
                COALESCE(c.name, '') AS customer_name,
                COALESCE(c.mobile, '') AS customer_phone,
                COALESCE(o.total_amount, 0) AS total_amount, 
                COALESCE(o.discount_amount, 0) AS discount_amount,
                COALESCE(o.express_amount, 0) AS express_amount,
                COALESCE(o.delivery_date, '') AS delivery_date, 
                COALESCE(o.delivery_slot, '') AS delivery_slot,
                COALESCE(o.delivered_datetime, '') AS delivered_datetime, 
                COALESCE(oi.status, o.status) AS status,
                COALESCE(o.coupon_code, '') AS coupon_code,
                COALESCE(p.Paid_Amount, 0) AS Paid_Amount, 
                COALESCE(p.Due_Amount, 0) AS Due_Amount,
                COALESCE(o.payable_amount, 0) AS payable_amount,
                COALESCE(o.payment_status, 'Due') AS payment_status,
                o.storeid,
                s.store_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN payments p ON o.id = p.order_id
            LEFT JOIN order_item oi ON o.order_item_id = oi.id
            LEFT JOIN stores s ON o.storeid = s.storeid
            WHERE o.storeid IN ( 
                SELECT storeid 
                FROM store_user_roles
                WHERE user_id = ? 
               AND role_id IN (1, 2)  
            )
            ORDER BY o.id DESC
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(["error" => "Prepare failed: " . $conn->error]);
            exit;
        }
        
        $stmt->bind_param("i", $user_id);
    } else {
       
        $sql = "
            SELECT 
                o.id, 
                COALESCE(c.name, '') AS customer_name,
                COALESCE(c.mobile, '') AS customer_phone,
                COALESCE(o.total_amount, 0) AS total_amount, 
                COALESCE(o.discount_amount, 0) AS discount_amount,
                COALESCE(o.express_amount, 0) AS express_amount,
                COALESCE(o.delivery_date, '') AS delivery_date, 
                COALESCE(o.delivery_slot, '') AS delivery_slot,
                COALESCE(o.delivered_datetime, '') AS delivered_datetime, 
                COALESCE(oi.status, o.status) AS status,
                COALESCE(o.coupon_code, '') AS coupon_code,
                COALESCE(p.Paid_Amount, 0) AS Paid_Amount, 
                COALESCE(p.Due_Amount, 0) AS Due_Amount,
                COALESCE(o.payable_amount, 0) AS payable_amount,
                COALESCE(o.payment_status, 'Due') AS payment_status,
                o.storeid,
                s.store_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN payments p ON o.id = p.order_id
            LEFT JOIN order_item oi ON o.order_item_id = oi.id
            LEFT JOIN stores s ON o.storeid = s.storeid
            WHERE o.storeid = ?  
            ORDER BY o.id DESC
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(["error" => "Prepare failed: " . $conn->error]);
            exit;
        }
        
        $stmt->bind_param("i", $current_store_id);
    }
    
    $stmt->execute();
    $res = $stmt->get_result();

    $orders = [];
    while ($row = $res->fetch_assoc()) {
        $row['total_amount'] = isset($row['total_amount']) ? (float)$row['total_amount'] : 0;
        $row['discount_amount'] = isset($row['discount_amount']) ? (float)$row['discount_amount'] : 0;
        $row['express_amount'] = isset($row['express_amount']) ? (float)$row['express_amount'] : 0;
        $row['payable_amount'] = isset($row['payable_amount']) ? (float)$row['payable_amount'] : 0;
        $row['Paid_Amount']  = isset($row['Paid_Amount']) ? (float)$row['Paid_Amount'] : 0;
        $row['Due_Amount']   = isset($row['Due_Amount']) ? (float)$row['Due_Amount'] : 0;

        // Net total calculate करें
        $netTotal = $row['payable_amount'];
        if ($netTotal < 0) $netTotal = 0;

        if ($row['Paid_Amount'] >= $netTotal && $netTotal > 0) {
            $row['payment_status'] = 'Paid';
        } elseif ($row['Paid_Amount'] > 0 && $row['Paid_Amount'] < $netTotal) {
            $row['payment_status'] = 'Partial';
        } else {
            $row['payment_status'] = 'Due';
        }

        // ✅ EDIT BUTTON LOGIC
        $row['show_edit_button'] = false;

        // Agar status delivered hai ya payment paid hai to edit button hide karein
        if($row['status'] == 'Pending' && $row['payment_status'] == 'Due') {
           $row['show_edit_button'] = true;
        }

        $orders[] = $row;
    }

    echo json_encode($orders, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    $stmt->close();
    $conn->close();
    exit;
}

// ---------------------------
// API: update order status
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && isset($_POST['status'])) {
    $order_id = intval($_POST['order_id']);
    $status   = trim($_POST['status']);

    $allowed = ['Pending','New','Processing','Ready','Delivered','Cancelled'];
    if ($status === '' || !in_array($status, $allowed)) {
        echo "Error: invalid status";
        exit;
    }

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // ✅ FIXED: Check permission based on role
        if ($is_admin) {
            $check_sql = "
                SELECT 1 FROM orders 
                WHERE id = ? 
                AND storeid IN ( 
                    SELECT storeid 
                    FROM store_user_roles
                    WHERE user_id = ? 
                    AND role_id IN (1, 2) 
                )
                LIMIT 1
            ";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $order_id, $user_id);
        } else {
            $check_sql = "
                SELECT 1 FROM orders 
                WHERE id = ? 
                AND storeid = ?
                LIMIT 1
            ";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $order_id, $current_store_id);
        }
        
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            throw new Exception("Error: Order not found or access denied");
        }
        $check_stmt->close();
        
        if ($status === 'Delivered') {
            $delivered_datetime = date('Y-m-d H:i:s');
            
            $upd = $conn->prepare("UPDATE orders SET status = ?, delivered_datetime = ? WHERE id = ?");
            if (!$upd) {
                throw new Exception("Error: prepare failed - " . $conn->error);
            }
            
            $upd->bind_param("ssi", $status, $delivered_datetime, $order_id);
            $upd->execute();
            $upd->close();
            
            $upd_item = $conn->prepare("UPDATE order_item SET status = ?, delivered_datetime = ?, status_updated_at = NOW() WHERE order_id = ? AND is_current = 1");
            if (!$upd_item) {
                throw new Exception("Error: prepare failed - " . $conn->error);
            }
            
            $upd_item->bind_param("ssi", $status, $delivered_datetime, $order_id);
            $upd_item->execute();
            $upd_item->close();
            
        } else {
            $upd = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            if (!$upd) {
                throw new Exception("Error: prepare failed - " . $conn->error);
            }
            $upd->bind_param("si", $status, $order_id);
            $upd->execute();
            $upd->close();
            
            $upd_item = $conn->prepare("UPDATE order_item SET status = ?, status_updated_at = NOW() WHERE order_id = ? AND is_current = 1");
            if (!$upd_item) {
                throw new Exception("Error: prepare failed - " . $conn->error);
            }
            
            $upd_item->bind_param("si", $status, $order_id);
            $upd_item->execute();
            $upd_item->close();
        }
        
        // Commit transaction
        $conn->commit();
        echo "Success: status updated in both tables";
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        echo "Error: " . $e->getMessage();
        exit;
    }
    
    $conn->close();
    exit;
}

// ---------------------------
// INVOICE PAGE
// ---------------------------
if (isset($_GET['invoice']) && isset($_GET['order_id'])) {
    $order_id = intval($_GET['order_id']);

    // ✅ FIXED: Different query for admin vs non-admin
    if ($is_admin) {
        $q = "
            SELECT 
                o.id AS order_id, 
                COALESCE(c.name, '') AS customer_name, 
                COALESCE(c.mobile, '') AS customer_phone,
                COALESCE(o.total_amount, 0) AS total_amount, 
                COALESCE(o.discount_amount, 0) AS discount_amount,
                COALESCE(o.express_amount, 0) AS express_amount,
                COALESCE(o.payable_amount, 0) AS payable_amount,
                COALESCE(o.delivery_date, '') AS delivery_date, 
                COALESCE(o.status, '') AS status,
                COALESCE(o.coupon_code, '') AS coupon_code,
                COALESCE(p.Paid_Amount, 0) AS Paid_Amount, 
                COALESCE(p.Due_Amount, 0) Due_Amount,
                oi.order_items_with_comments,
                o.storeid,
                s.store_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN payments p ON o.id = p.order_id
            LEFT JOIN order_item oi ON o.order_item_id = oi.id AND oi.is_current = 1
            LEFT JOIN stores s ON o.storeid = s.storeid
            WHERE o.id = ? 
            AND o.storeid IN ( 
                SELECT storeid 
                FROM store_user_roles
                WHERE user_id = ? 
                AND role_id IN (1, 2)  
            )
            LIMIT 1
        ";
        
        $st = $conn->prepare($q);
        if (!$st) {
            die('❌ Server error');
        }
        
        $st->bind_param('ii', $order_id, $user_id);
    } else {
        $q = "
            SELECT 
                o.id AS order_id, 
                COALESCE(c.name, '') AS customer_name, 
                COALESCE(c.mobile, '') AS customer_phone,
                COALESCE(o.total_amount, 0) AS total_amount, 
                COALESCE(o.discount_amount, 0) AS discount_amount,
                COALESCE(o.express_amount, 0) AS express_amount,
                COALESCE(o.payable_amount, 0) AS payable_amount,
                COALESCE(o.delivery_date, '') AS delivery_date, 
                COALESCE(o.status, '') AS status,
                COALESCE(o.coupon_code, '') AS coupon_code,
                COALESCE(p.Paid_Amount, 0) AS Paid_Amount, 
                COALESCE(p.Due_Amount, 0) AS Due_Amount,
                oi.order_items_with_comments,
                o.storeid,
                s.store_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN payments p ON o.id = p.order_id
            LEFT JOIN order_item oi ON o.order_item_id = oi.id AND oi.is_current = 1
            LEFT JOIN stores s ON o.storeid = s.storeid
            WHERE o.id = ? 
            AND o.storeid = ?  
            LIMIT 1
        ";
        
        $st = $conn->prepare($q);
        if (!$st) {
            die('❌ Server error');
        }
        
        $st->bind_param('ii', $order_id, $current_store_id);
    }
    
    $st->execute();
    $res = $st->get_result();
    
    if (!$res || $res->num_rows === 0) {
        $st->close();
        $conn->close();
        die('❌ Order not found or access denied');
    }
    
    $order = $res->fetch_assoc();
    $st->close();

    $total = floatval($order['total_amount']);
    $discount = floatval($order['discount_amount'] ?? 0);
    $express = floatval($order['express_amount'] ?? 0);
    $payable = floatval($order['payable_amount'] ?? 0);
    $paid  = floatval($order['Paid_Amount']);
    $due   = floatval($order['Due_Amount']);
    $coupon_code = htmlspecialchars($order['coupon_code'] ?? '');
    
    // Get items from JSON
    $items = [];
    $order_items_json = $order['order_items_with_comments'] ?? "[]";
    $order_items_data = json_decode($order_items_json, true);
    
    // Extract items from JSON structure
    if (!empty($order_items_data) && isset($order_items_data['items']) && is_array($order_items_data['items'])) {
        $items = $order_items_data['items'];
    }
    
    // Calculate discount percentage
    $discount_percent = 0;
    if ($total > 0 && $discount > 0) {
        $discount_percent = round(($discount / $total) * 100, 2);
    }
    
    // Payment status
    if ($paid >= $payable && $payable > 0) {
        $payment_status = "Paid";
    } elseif ($paid > 0 && $paid < $payable) {
        $payment_status = "Partial";
    } else {
        $payment_status = "Due";
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="hi">
    <head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo htmlspecialchars($order['order_id']); ?></title>
    <style>
    body { font-family: Arial; background: #f9f9f9; padding: 20px; }
    .invoice-box { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 5px rgba(0,0,0,0.2); max-width: 800px; margin: auto; }
    h2 { text-align: center; color: #1976d2; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
    th { background: #00aaff; color: white; }
    .total { text-align: right; font-weight: bold; }
    .discount-row { color: #d32f2f; }
    .express-row { color: #388e3c; }
    .net-total { background: #e8f5e8; font-size: 1.1em; font-weight: bold; }
    .comments-cell { font-size: 11px; color: #666; }
    .comments-badge { 
        background: #ff9800; 
        color: white; 
        padding: 2px 5px; 
        border-radius: 3px; 
        margin: 1px;
        font-size: 10px;
        display: inline-block;
    }
    </style>
    </head>
    <body>
    <div class="invoice-box">
      <h2>🧾 Fabric First</h2>
      <p>
         <b>Invoice ID:</b> <?php echo htmlspecialchars($order['order_id']); ?><br>
         <b>Customer:</b> <?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?><br>
         <b>Mobile:</b> <?php echo htmlspecialchars($order['customer_phone'] ?? 'N/A'); ?><br>
         <?php if(!empty($coupon_code)): ?>
         <b>Coupon Code:</b> <?php echo $coupon_code; ?><br>
         <?php endif; ?>
         <b>Delivery Date:</b> <?php echo htmlspecialchars($order['delivery_date']); ?><br>
         <b>Order Status:</b> <?php echo htmlspecialchars($order['status']); ?><br>
         <b>Payment Status:</b> <?php echo htmlspecialchars($payment_status); ?>
      </p>

      <table>
        <thead>
            <tr>
                <th>Sno</th>
                <th>Product</th>
                <th>Service</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Total</th>
                <th>Comments</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $subtotal = 0;
        $item_count = 1;
        $total_garments = 0;
        
        if (!empty($items) && is_array($items)) {
            foreach($items as $item) {
                // Try multiple possible field names for product
                $product_name = $item['product_name'] ?? $item['product'] ?? $item['item'] ?? '';
                $service_type = $item['service_type'] ?? $item['service'] ?? '';
                $qty = floatval($item['qty'] ?? 1);
                $unit = htmlspecialchars($item['unit'] ?? 'Pcs');
                $price = floatval($item['price'] ?? 0);
                $itemTotal = $qty * $price;
                $subtotal += $itemTotal;
                
                // Handle comments - could be array or JSON string
                $comments = $item['comments'] ?? [];
                if (is_string($comments)) {
                    $comments = json_decode($comments, true) ?: [];
                }
                if (!is_array($comments)) {
                    $comments = [];
                }

                // Calculate total garments
                if (stripos($product_name, 'laundry by weight') !== false) {
                    if (!empty($comments) && is_array($comments)) {
                        foreach ($comments as $garment) {
                            $parts = explode(':', $garment);
                            $nameAndQty = $parts[0];
                            $lastHyphenPos = strrpos($nameAndQty, '-');
                            if ($lastHyphenPos !== false) {
                                $garmentQty = intval(substr($nameAndQty, $lastHyphenPos + 1));
                                $total_garments += $garmentQty;
                            }
                        }
                    }
                } else {
                    $total_garments += $qty;
                }
                
                // Prepare display variables
                $product_display_html = htmlspecialchars($product_name);
                $comments_display_html = "-";

                // Check if it's a "Laundry By Weight" item to show garment list
                if (stripos($product_name, 'laundry by weight') !== false) {
                    if (!empty($comments) && is_array($comments)) {
                        $product_display_html .= "<ol style='margin: 5px 0 0 15px; padding-left: 10px; text-align: left; font-size: 12px; color: #333;'>";
                        foreach ($comments as $garment) {
                            $product_display_html .= "<li>" . htmlspecialchars($garment) . "</li>";
                        }
                        $product_display_html .= "</ol>";
                        // Comments are shown in product column, so this column is empty
                        $comments_display_html = "-";
                    }
                } else {
                    // For regular items, show comments as badges
                    if (!empty($comments) && is_array($comments)) {
                        $comments_display_html = "";
                        foreach($comments as $comment) {
                            $comments_display_html .= "<span class='comments-badge'>" . htmlspecialchars($comment) . "</span>";
                        }
                    }
                }

                echo "<tr>
                    <td>{$item_count}</td>
                    <td>{$product_display_html}</td>
                    <td>" . htmlspecialchars($service_type) . "</td>
                    <td>" . htmlspecialchars($qty) . " " . $unit . "</td>
                    <td>₹" . number_format($price, 2) . "</td>
                    <td>₹" . number_format($itemTotal, 2) . "</td>
                    <td class='comments-cell'>{$comments_display_html}</td>
                </tr>";
                $item_count++;
            }
        } else {
            echo "<tr><td colspan='7' style='text-align:center;color:#999;'>No items found</td></tr>";
        }
        ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="total">Total Amount:</td>
                <td colspan="2">₹<?php echo number_format($subtotal, 2); ?></td>
            </tr>
            
            <tr>
                <td colspan="5" class="total">Total Garments:</td>
                <td colspan="2"><?php echo $total_garments; ?> Pcs</td>
            </tr>
            
            <?php if($express > 0): ?>
            <tr class="express-row">
                <td colspan="5" class="total">Express Charge:</td>
                <td colspan="2">+ ₹<?php echo number_format($express, 2); ?></td>
            </tr>
            <?php endif; ?>
            
            <?php if($discount > 0): ?>
            <tr class="discount-row">
                <td colspan="5" class="total">Discount Amount: (<?php echo $discount_percent; ?>%):</td>
                <td colspan="2"> ₹<?php echo number_format($discount, 2); ?></td>
            </tr>
            <?php endif; ?>
            
            <tr class="net-total">
                <td colspan="5" class="total">Payable Amount:</td>
                <td colspan="2">₹<?php echo number_format($payable, 2); ?></td>
            </tr>
            
            <tr>
                <td colspan="5" class="total">Paid Amount:</td>
                <td colspan="2">₹<?php echo number_format($paid, 2); ?></td>
            </tr>
            
            <tr>
                <td colspan="5" class="total">Due Amount:</td>
                <td colspan="2">₹<?php echo number_format($due, 2); ?></td>
            </tr>
        </tfoot>
      </table>

      <div style="margin-top: 30px; padding-top: 15px; border-top: 1px dashed #ccc; text-align: center; font-size: 12px; color: #666;">
          <p>Thank you for your business!</p>
      </div>

      <center>
          <button onclick="window.print()" style="padding: 10px 20px; background: #00aaff; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 20px; font-size: 16px;">🖨 Print Invoice</button>
          <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 20px; margin-left: 10px; font-size: 16px;">✖ Close</button>
      </center>
    </div>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order Manager</title>
<style>
body { font-family: Arial; background: #f6f6f6; margin:0; padding:0; }
h2 { margin-left: 20px; margin-top: 20px; }
table { width: 95%; margin: 20px auto; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: center; }
th { background: #00aaff; color: white; }

/* Action Buttons Container */
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    justify-content: left;
    align-items: center;
    min-height: 40px;
}

/* Button Base Styles */
.action-buttons button {
    flex-shrink: 0;
    margin: 0;
    white-space: nowrap;
    border: none;
    padding: 6px 10px;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    transition: all 0.3s ease;
}

/* Button Colors */
.settle-btn { background: #00aaff; color: white; }
.ready-btn { background: #00aaff; color: white; }
.delivered-btn { background: #00aaff; color: white; }
.invoice-btn { background: #00aaff; color: white; }
.tag-btn { background: #00aaff; color: white; }
.edit-btn { background: #00aaff; color: white; }

/* Button Hover Effects */
.action-buttons button:hover { 
    opacity: 0.9; 
    transform: translateY(-1px);
}

.settle-btn:hover { background: #006699; }
.ready-btn:hover { background: #006699; }
.delivered-btn:hover { background: #006699; }
.invoice-btn:hover { background: #006699; }
.tag-btn:hover { background: #006699; }
.edit-btn:hover { background: #006699; }

.pagination { text-align: center; margin: 20px; }
.pagination button { background: #00aaff; color: white; padding: 8px 14px; margin: 0 4px; border-radius: 5px; border: none; cursor: pointer; }
.pagination button.active { background: #006699; }
.pagination button:hover { background: #0088cc; }
#searchBox { width: 300px; padding: 8px; border-radius: 6px; border: 1px solid #ccc; }
#searchBtn { background: #00aaff; color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; }
#searchBtn:hover { background: #0088cc; }
.discount-text { color: #d32f2f; font-size: 0.9em; }
.express-text { color: #388e3c; font-size: 0.9em; }

/* Store column के लिए conditional styling */
<?php if($show_store_column): ?>
td:nth-child(3) {  /* Store name कॉलम (तीसरा कॉलम) */
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
<?php endif; ?>
</style>
</head>
<body>
<div class="main-content"> 
<?php include 'menu.php'; ?>  

<center>
  <h2>🧾 Orders </h2>
  <input type="text" id="searchBox" placeholder="🔍 Search by Order ID, Name or Phone">
  <button id="searchBtn">Search</button>
</center>

<div id="error" class="error" style="color:red; text-align:center;"></div>

<table>
 <thead>
  <tr>
    <th>Actions</th>
    <th>Order ID</th>
    <?php if($show_store_column): ?>
    <th>Store</th>  <!-- ✅ सिर्फ Admin या multiple stores वालों के लिए -->
    <?php endif; ?>
    <th>Customer Name</th>
    <th>Phone</th>
    <th>Amount Details</th>
    <th>Delivery Date</th>
    <th>Payment Status</th>
    <th>Order Status</th>
  </tr>
</thead>
  <tbody id="ordersTable"></tbody>
</table>

<div class="pagination" id="pagination"></div>

<script>
// ✅ PHP से JavaScript में variables pass करें
const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
const adminStoresCount = <?php echo count($admin_stores); ?>;
const showStoreColumn = <?php echo $show_store_column ? 'true' : 'false'; ?>;

let ordersData = [];
let filteredData = [];
const rowsPerPage = 10;
let currentPage = 1;

fetch("bbtocc.php?action=get_orders")
.then(res => res.json())
.then(data => {
    if (data && data.error) {
        document.getElementById("error").innerText = "❌ " + data.error;
        return;
    }
    ordersData = data;
    filteredData = data;
    createPagination();
    showPage(1);
})
.catch(err => {
    document.getElementById("error").innerText = "❌ Error fetching orders!";
    console.error(err);
});

document.getElementById("searchBtn").addEventListener("click", () => {
  const q = document.getElementById("searchBox").value.trim().toLowerCase();
  if (q === "") filteredData = ordersData;
  else {
    filteredData = ordersData.filter(o =>
      o.id.toString().includes(q) ||
      (o.customer_name && o.customer_name.toLowerCase().includes(q)) ||
      (o.customer_phone && o.customer_phone.includes(q))
    );
  }
  createPagination();
  showPage(1);
});

function showPage(page) {
    currentPage = page;
    const table = document.getElementById("ordersTable");
    table.innerHTML = "";

    const start = (page - 1) * rowsPerPage;
    const end = start + rowsPerPage;
    const pageData = filteredData.slice(start, end);

    pageData.forEach(order => {
        const oid = order.id;
        if (!oid) return;

        const status = ((order.status || "Pending").trim()).toLowerCase();
        const total = parseFloat(order.total_amount || 0);
        const discount = parseFloat(order.discount_amount || 0);
        const express = parseFloat(order.express_amount || 0);
        const payable = parseFloat(order.payable_amount || 0);
        const paid = parseFloat(order.Paid_Amount || 0);
        const due = parseFloat(order.Due_Amount || (payable - paid)).toFixed(2);

        // ✅ Store name HTML - सिर्फ Admin या multiple stores वालों के लिए
        let storeNameHTML = '';
        if (showStoreColumn) {
            let storeName = order.store_name || "Store " + (order.storeid || "");
            storeNameHTML = `<td>${storeName}</td>`;
        }

        let readyBtnHTML = "";
        let deliveredBtnHTML = ""; 
        let settleBtnHTML = "";
        let viewInvoiceBtnHTML = `<button class="invoice-btn" onclick="viewInvoice(${oid})"> Invoice</button>`;
        let tagBtnHTML = `<button class="tag-btn" onclick="showTagInfo(${oid})"> Tag</button>`;
        
        let editBtnHTML = "";
        if (order.show_edit_button !== false) {
            editBtnHTML = `<button class="edit-btn" onclick="editOrder(${oid})">Edit</button>`;
        }
        
        if (order.payment_status !== "Paid") {
            settleBtnHTML = `<button class="settle-btn" onclick="settleOrder(${oid})">Settle</button>`;
        }

        if (status === "pending" || status === "processing" || status === "new" || status === "") {
            readyBtnHTML = `<button class="ready-btn" onclick="updateStatus(${oid}, 'Ready')">Mark Ready</button>`;
            deliveredBtnHTML = `<button class="delivered-btn" onclick="updateStatus(${oid}, 'Delivered')">Mark Delivered</button>`;
        } else if (status === "ready") {
            deliveredBtnHTML = `<button class="delivered-btn" onclick="updateStatus(${oid}, 'Delivered')">Mark Delivered</button>`;
        }

        let bg = "#fff";
        if (status === "ready") bg = "#fff3cd";
        else if (status === "delivered") bg = "#d4edda";

        let discountHTML = "";
        if (discount > 0) {
            let discountPercent = 0;
            if (total > 0) {
                discountPercent = Math.round((discount / total) * 10000) / 100;
            }
            discountHTML = `<div class="discount-text">Discount: ₹${discount.toFixed(2)} (${discountPercent}%)</div>`;
        }
        
        let expressHTML = "";
        if (express > 0) {
            expressHTML = `<div class="express-text">Express: ₹${express.toFixed(2)}</div>`;
        }

        table.innerHTML += `
        <tr id="row-${oid}" style="background:${bg}">
            <td>
                <div class="action-buttons">
                    ${editBtnHTML}
                    ${readyBtnHTML}
                    ${deliveredBtnHTML}
                    ${settleBtnHTML}
                    ${viewInvoiceBtnHTML}
                    ${tagBtnHTML}
                </div>
            </td>
            <td>${oid}</td>
            ${storeNameHTML}  <!-- ✅ यहाँ store name HTML inject होगा या नहीं -->
            <td>${order.customer_name || "-"}</td>
            <td>${order.customer_phone || "-"}</td>
            <td>
                <div>Total: ₹${total.toFixed(2)}</div>
                ${expressHTML}
                ${discountHTML}
                <div style="color:green;">Paid: ₹${paid.toFixed(2)}</div>
                <div style="color:red;">Due: ₹${due}</div>
                <div style="font-weight:bold; color:#1976d2;">Payable: ₹${payable.toFixed(2)}</div>
            </td>
            <td>${order.delivery_date || "-"}</td>
            <td>${order.payment_status || "Due"}</td>
            <td id="orderStatus-${oid}">${status.charAt(0).toUpperCase() + status.slice(1)}</td>
        </tr>`;
    });

    updatePaginationActive();
}

function createPagination() {
    const paginationDiv = document.getElementById("pagination");
    paginationDiv.innerHTML = "";
    const totalPages = Math.ceil(filteredData.length / rowsPerPage);
    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement("button");
        btn.innerText = i;
        btn.onclick = () => showPage(i);
        btn.id = "pageBtn" + i;
        paginationDiv.appendChild(btn);
    }
}

function updatePaginationActive() {
    const totalPages = Math.ceil(filteredData.length / rowsPerPage);
    for (let i = 1; i <= totalPages; i++) {
        const btn = document.getElementById("pageBtn" + i);
        if (btn) btn.classList.toggle("active", i === currentPage);
    }
}

function updateStatus(order_id, newStatus) {
    if (!order_id) return;
    if (!confirm(`क्या आप इस order को "${newStatus}" मार्क करना चाहते हैं?`)) return;

    const formData = new URLSearchParams();
    formData.append("order_id", order_id);
    formData.append("status", newStatus);

    fetch("bbtocc.php", { 
        method: "POST", 
        headers: {"Content-Type":"application/x-www-form-urlencoded"}, 
        body: formData.toString() 
    })
    .then(res => res.text())
    .then(res => {
        if (res.toLowerCase().includes("success")) {
            const row = document.getElementById(`row-${order_id}`);
            const statusCell = document.getElementById(`orderStatus-${order_id}`);
            const actionContainer = row?.querySelector('.action-buttons');
            const btnReady = actionContainer?.querySelector(".ready-btn");
            const btnDelivered = actionContainer?.querySelector(".delivered-btn");
            
            // ✅ यहाँ EDIT BUTTON को FIND करें
            const editBtn = actionContainer?.querySelector(".edit-btn");

            if (statusCell) statusCell.innerText = newStatus;
            
            if (newStatus === "Ready") {
                if (btnReady) btnReady.style.display = "none";
                if (btnDelivered) btnDelivered.style.display = "inline-block";
                row.style.background = "#fff3cd";
                // ✅ READY होने पर EDIT BUTTON HIDE करें
                if (editBtn) editBtn.style.display = "none";
            } else if (newStatus === "Delivered") {
                if (btnReady) btnReady.style.display = "none";
                if (btnDelivered) btnDelivered.style.display = "none";
                row.style.background = "#d4edda";
                // ✅ DELIVERED होने पर EDIT BUTTON HIDE करें
                if (editBtn) editBtn.style.display = "none";
            }
            
            alert(`✅ Order "${newStatus}" के रूप में मार्क कर दिया गया है!`);
        } else alert("❌ Status update failed!\n" + res);
    })
    .catch(err => { 
        alert("❌ Server Error!"); 
        console.error(err); 
    });
}

function settleOrder(order_id) {
  window.location.href = "settle_order.php?order_id=" + order_id;
}

function viewInvoice(order_id) {
  window.open("bbtocc.php?invoice=1&order_id=" + order_id, "_blank");
}

function showTagInfo(order_id) {
    fetch(`bbtocc.php?action=get_label_data&order_id=${order_id}`)
    .then(res => res.json())
    .then(data => {
        if (!data || !data.items || data.items.length === 0) {
            alert("❌ No tag data found!");
            return;
        }

        let labels = [];
        let totalProducts = 0;
        data.items.forEach(item => {
            totalProducts += Number(item.qty) || 1;
        });

        data.items.forEach(item => {
            let qty = Math.ceil(Number(item.qty) || 1); // Tag ke liye round up karein (1.5kg = 2 tags or 1 tag logic)
            let itemComments = item.comments || [];
            
            for (let i = 0; i < qty; i++) {
                labels.push({
                    customer: data.customerName,
                    order: data.orderNo,
                    delivery: data.deliveryDate,
                    product: item.product,
                    service: item.service_type,
                    payment: data.paymentStatus,
                    total: totalProducts,
                    comments: itemComments,
                    owner_name: data.owner_name
                });
            }
        });

        const form = document.createElement("form");
        form.method = "POST";
        form.action = "tag_preview.php";
        form.target = "_blank";

        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "data";
        input.value = JSON.stringify({ labels });

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    })
    .catch(err => alert("❌ Error: " + err));
}

function editOrder(order_id) {
    window.open("main.php?order_id=" + order_id, "_blank");
}
</script>
</body>
</html>