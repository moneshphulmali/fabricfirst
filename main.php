<?php
// main.php with Edit Order and Items Load
// Is code ka kaam: Developer ko real-time errors dikhana taaki woh quickly debug kar sake!
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db_connect.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    die(json_encode(["status"=>"error","message"=>"Login required"]));
}

// Get logged-in user's storeid - 
$storeid = $_SESSION['user']['current_store']['storeid'];  
$user_id = $_SESSION['user']['user_id'];
$role = $_SESSION['user']['role'];

// ✅ EDIT ORDER FUNCTIONALITY - SIMPLE VERSION
$edit_customer_data = null;  //Meaning: "Edit karne wale customer ka data abhi nahi hai"
$is_edit_mode = false;   // Meaning: "Default mode - NEW order create kar rahe hain"
$current_order_id = null;  //Meaning: "Currently koi order select nahi hai"


/*
Summary:
isset($_GET['order_id']) → Check karo URL mein order_id hai ya nahi
intval() → String ko safe number mein convert karo
$current_order_id → Order number remember rakho
$is_edit_mode = true → Batado ki hum edit kar rahe hain, naya nahi bana rahe
Ek Line Mein:
"Agar URL mein order_id hai, to usse number banao, store karo, aur edit mode ON karo!"

//Check karta hai ki URL mein order_id parameter hai ya nahi
//intval()-> se string ko number mein convert karta hai
*/
if(isset($_GET['order_id'])) {   
    $order_id = intval($_GET['order_id']);
    $current_order_id = $order_id;
    $is_edit_mode = true;
    
    // Get customer details from order
    $sql = "SELECT c.name, c.mobile, c.address 
            FROM orders o 
            JOIN customers c ON o.customer_id = c.id 
            WHERE o.id = ? AND o.storeid = ? LIMIT 1";
    
	
	//Database connection close karo and Memory/resources free karo ($stmt->close();)

    $stmt = $conn->prepare($sql);   //SQL query ko ready karo (prepare karo)
    if ($stmt) { 
        $stmt->bind_param("ii", $order_id, $storeid);  // ✅ $storeid use ho raha hai
        $stmt->execute();
        $result = $stmt->get_result();
        
        if($row = $result->fetch_assoc()) {
            $edit_customer_data = [
                'name' => $row['name'],
                'phone' => $row['mobile'],
                'address' => $row['address'] ?? ''
            ];
        }
        $stmt->close();
    }
}



header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

//Kya Karta Hai: Database connection mein koi error hai ya nahi check karta hai
if ($conn->connect_error) {
    die(json_encode(["status"=>"error","message"=>"DB connection failed: ".$conn->connect_error]));
}

// ✅ FAST2SMS FUNCTION - UPDATED
function sendFast2SMS($mobile, $message) {
    $api_key = '';
    
    $mobile = preg_replace('/\D/', '', $mobile);
    
    if (strlen($mobile) != 10) {
        error_log("Invalid mobile number: $mobile");
        return ['status' => 'error', 'message' => 'Invalid mobile number'];
    }
    
    $url = "";
    
    $fields = [
        "sender_id" => "TXTIND",
        "message" => $message,
        "language" => "english",
        "route" => "q",
        "numbers" => $mobile,
        "flash" => 0
    ];
    
    $headers = [
        'authorization: ' . $api_key,
        'Content-Type: application/json',
        'cache-control: no-cache',
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    
    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log for debugging
    $log_data = [
        'time' => date('Y-m-d H:i:s'),
        'mobile' => $mobile,
        'message' => $message,
        'http_code' => $http_code,
        'curl_error' => $curl_error,
        'response' => $result
    ];
    
    file_put_contents('sms_api_log.txt', print_r($log_data, true) . "\n---\n", FILE_APPEND);
    
    if ($curl_error) {
        return ['status' => 'error', 'message' => "cURL Error: " . $curl_error];
    }
    
    if (empty($result)) {
        return ['status' => 'error', 'message' => 'Empty response from Fast2SMS server'];
    }
    
    $response = json_decode($result, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => 'error', 
            'message' => 'Invalid JSON response: ' . json_last_error_msg(),
            'raw_response' => $result
        ];
    }
    
    return $response;
}

// Function to escape JSON for MySQL
function escapeJsonForMySQL($conn, $json) {
    // If already empty or invalid, return empty JSON array
    if (empty($json) || $json == '0' || $json == 0) {
        return '{"items":[]}';
    }
    
    // Validate JSON
    $decoded = json_decode($json, true);
    if ($decoded === null) {
        return '{"items":[]}';
    }
    
    // Re-encode
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    
    // Escape for MySQL
    return $conn->real_escape_string($encoded);
}

// Route based on `action`
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // GET CUSTOMER
    if ($action == 'get_customer') {
        $mobile = $_GET['phone'] ?? '';
        if (!$mobile) {
            echo json_encode(["status"=>"error","message"=>"Mobile not provided"]);
            exit;
        }

        $stmt = $conn->prepare("SELECT name, address FROM customers WHERE mobile = ? AND storeid = ?");
        $stmt->bind_param("si", $mobile, $storeid);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode(["status"=>"success","data"=>$row]);
        } else {
            echo json_encode(["status"=>"not_found"]);
        }
        $stmt->close();
        exit;
    }

    // GET ORDER ITEMS FOR EDITING - NEW ACTION  - Edit Mode Ke Liye Order Items Load Karna
	//"Agar user order edit kar raha hai,to us order ka purana data database se lekar form mein bhar do!"
    if ($action == 'get_order_items') {
        $order_id = intval($_GET['order_id']);
        
        // ✅ GET ORDER DETAILS WITH ITEMS
        $sql = "SELECT 
                    o.delivery_date,
                    o.delivery_slot,
                    oi.order_items_with_comments 
                FROM orders o
                LEFT JOIN order_item oi ON o.id = oi.order_id AND oi.is_current = 1
                WHERE o.id = ? AND o.storeid = ? 
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $order_id, $storeid);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $json_data = json_decode($row['order_items_with_comments'], true);
            
            // Extract items from the JSON structure
            $items = [];
            if (isset($json_data['items']) && is_array($json_data['items'])) {
                $items = $json_data['items'];
            }
            
            echo json_encode([
                "status"=>"success", 
                "items"=>$items,
                "delivery_date"=>$row['delivery_date'],
                "delivery_slot"=>$row['delivery_slot']
            ]);
        } else {
            echo json_encode(["status"=>"error", "items"=>[]]);
        }
        exit;
    }	

    // GET PRODUCTS
    if ($action == 'get_products') {
        $sql = "SELECT product_name, product_type, service_type, price 
        FROM price 
        WHERE storeid = $storeid 
        ORDER BY product_name, product_type";

        $result = $conn->query($sql);

        $products = [];
        while ($row = $result->fetch_assoc()) {
            $pname = $row['product_name'];
            $ptype = $row['product_type'];
            if (!isset($products[$pname])) $products[$pname] = [];
            if (!isset($products[$pname][$ptype])) $products[$pname][$ptype] = [];
            $products[$pname][$ptype][] = [
                "service_type" => $row['service_type'],
                "price" => $row['price']
            ];
        }
        ksort($products);
        echo json_encode(["status"=>"success","data"=>$products]);
        exit;
    }

    // GET COUPONS (OFFERS) FROM DB
    if ($action == 'get_coupons') {
        $sql = "SELECT code, discount_percent FROM coupons WHERE storeid = ? AND status = 'active' ORDER BY discount_percent ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $storeid);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $coupons = [];
        while ($row = $result->fetch_assoc()) {
            $coupons[] = [
                'name' => $row['code'],
                'percent' => floatval($row['discount_percent'])
            ];
        }
        
        echo json_encode(["status" => "success", "data" => $coupons]);
        exit;
    }

    // SAVE ORDER
    if ($action == 'save_order') {
        // Get raw input
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode([
                "status" => "error", 
                "message" => "Invalid JSON data: " . json_last_error_msg()
            ]);
            exit;
        }
        
        if (!$data) {
            echo json_encode(["status"=>"error","message"=>"No data received"]);
            exit;
        }
// "Frontend se aaye hue JSON data ko extract karke PHP variables mein convert karna, saath hi missing values ke liye default values set karna"
       
		$name = $data["name"] ?? "";
        $phone = $data["phone"] ?? "";
        $address = $data["address"] ?? "";
        $delivery_date = $data["deliveryDate"] ?? "";
        $delivery_slot = $data["deliverySlot"] ?? "";
        $grossTotal = isset($data["grossTotal"]) ? floatval($data["grossTotal"]) : floatval($data["totalAmount"] ?? 0);
        $discountAmount = isset($data["discountAmount"]) ? floatval($data["discountAmount"]) : 0.0;
        $payableAmount = isset($data["payableAmount"]) ? floatval($data["payableAmount"]) : ($grossTotal - $discountAmount);
        $coupon = isset($data["coupon"]) ? $data["coupon"] : "";
        $expressAmount = isset($data["expressAmount"]) ? floatval($data["expressAmount"]) : 0.0;
        
        $items = $data["items"] ?? [];
        
        // ✅ Check if editing existing order
        $is_edit = isset($data['edit_order_id']) && $data['edit_order_id'] > 0;
        $edit_order_id = $is_edit ? intval($data['edit_order_id']) : 0;

        // ✅ VALIDATION
        if (empty($name) || empty($phone) || empty($items)) {
            echo json_encode(["status"=>"error","message"=>"Missing required fields"]);
            exit;
        }

        if (empty($delivery_date)) {
            echo json_encode(["status"=>"error","message"=>"Delivery date is required!"]);
            exit;
        }

        $delivery_date = trim($delivery_date);
        if ($delivery_date === "0000-00-00" || $delivery_date === "0000-00-00 00:00:00") {
            echo json_encode(["status"=>"error","message"=>"Invalid delivery date selected!"]);
            exit;
        }

        $date_timestamp = strtotime($delivery_date);
        if (!$date_timestamp || $date_timestamp === false) {
            echo json_encode(["status"=>"error","message"=>"Invalid date format!"]);
            exit;
        }
//"Agar delivery date invalid hai (empty ya wrong format), to user ko batado ki valid date select kare!"
      
	  $mysql_date = date('Y-m-d', $date_timestamp);
        if ($mysql_date === "1970-01-01" || $mysql_date === "0000-00-00") {
            echo json_encode(["status"=>"error","message"=>"Please select a valid delivery date!"]);
            exit;
        }

        $delivery_date = $mysql_date;

        // ✅ START TRANSACTION
        $conn->begin_transaction();

        try {
            // Calculate total quantity
            $total_quantity = 0;
            foreach ($items as $item) {
                // Use floatval to handle decimal quantities like 1.5 kg
                $total_quantity += isset($item["qty"]) ? floatval($item["qty"]) : 1;
            }

            // ✅ STEP 1: PREPARE ORDER ITEMS ARRAY PROPERLY
            $order_items_array = [
                'items' => []
            ];

            if (is_array($items) && count($items) > 0) {
                foreach ($items as $item) {
                    // Ensure comments is always an array
                    $comments = $item["comments"] ?? [];
                    if (!is_array($comments)) {
                        $comments = [$comments];
                    }
                    
                    // Clean and validate each field
                    $product_name = isset($item["item"]) ? trim($item["item"]) : '';
                    $service_type = isset($item["service"]) ? trim($item["service"]) : '';
                    $qty = isset($item["qty"]) ? floatval($item["qty"]) : 1; // Allow float for JSON
                    $unit = isset($item["unit"]) ? trim($item["unit"]) : 'Pcs';
                    $price = isset($item["price"]) ? floatval($item["price"]) : 0.0;
                    
                    // Only add if product_name is not empty
                    if (!empty($product_name)) {
                        $order_items_array['items'][] = [
                            'product_name' => $product_name,
                            'product_type' => $item["product_type"] ?? '',
                            'service_type' => $service_type,
                            'qty' => $qty,
                            'unit' => $unit,
                            'price' => $price,
                            'comments' => $comments,
                            'item_total' => $price * $qty
                        ];
                    }
                }
            }

            // ✅ STEP 2: VALIDATE ARRAY BEFORE JSON ENCODE
            if (empty($order_items_array['items'])) {
                // If no items, create empty structure
                $order_items_array = ['items' => []];
            }

            // ✅ STEP 3: JSON ENCODE WITH ERROR CHECKING
            $order_items_json_string = json_encode($order_items_array, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

            // ✅ STEP 4: HANDLE JSON ENCODE ERRORS
            if ($order_items_json_string === false) {
                error_log("JSON Encode Error: " . json_last_error_msg());
                $order_items_json_string = '{"items":[]}';
            }

            // ✅ STEP 5: ENSURE VALID JSON
            if (empty($order_items_json_string) || $order_items_json_string == 'null' || $order_items_json_string == '0') {
                $order_items_json_string = '{"items":[]}';
            }

            // ✅ STEP 6: DECODE AND RE-ENCODE TO VALIDATE
            $decoded = json_decode($order_items_json_string, true);
            if ($decoded === null) {
                $order_items_json_string = '{"items":[]}';
                $decoded = ['items' => []];
            }

            // Final validation
            $order_items_json_string = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            // ✅ STEP 7: ESCAPE JSON FOR MYSQL
            $order_items_json_escaped = escapeJsonForMySQL($conn, $order_items_json_string);

            // ✅  LOGS
            error_log("📦 ORDER ITEMS ARRAY: " . print_r($order_items_array, true));
            error_log("✅ FINAL JSON STRING: " . $order_items_json_string);
            error_log("✅ ESCAPED JSON: " . $order_items_json_escaped);
            error_log("✅ JSON LENGTH: " . strlen($order_items_json_escaped));
            error_log("✅ JSON VALID: " . (json_decode($order_items_json_escaped) !== null ? 'Yes' : 'No'));
            
            if ($is_edit && $edit_order_id > 0) {
                // ✅ UPDATE EXISTING ORDER WITH VERSIONING
                
                // 1. Get existing customer_id
                $get_customer_sql = "SELECT customer_id FROM orders WHERE id = ? AND storeid = ? LIMIT 1";
                $stmt_get = $conn->prepare($get_customer_sql);
                $stmt_get->bind_param("ii", $edit_order_id, $storeid);
                $stmt_get->execute();
                $result = $stmt_get->get_result();
                $row = $result->fetch_assoc();
                $customer_id = $row['customer_id'] ?? 0;
                $stmt_get->close();
                
                if ($customer_id == 0) {
                    throw new Exception("Customer not found for this order");
                }
                
                // 2. Update customer details
                $update_customer_sql = "UPDATE customers SET name = ?, mobile = ?, address = ? WHERE id = ?";
                $stmt_customer = $conn->prepare($update_customer_sql);
                $stmt_customer->bind_param("sssi", $name, $phone, $address, $customer_id);
                $stmt_customer->execute();
                $stmt_customer->close();
                
                // 3. Update orders table
                $update_order_sql = "
                    UPDATE orders SET 
                        discount_amount = ?, 
                        express_amount = ?, 
                        total_amount = ?, 
                        delivery_date = ?, 
                        delivery_slot = ?, 
                        coupon_code = ?, 
                        payable_amount = ?, 
                        quantity = ?
                    WHERE id = ? AND storeid = ?
                ";
                
                $stmt_order = $conn->prepare($update_order_sql);
                $stmt_order->bind_param(
                    "dddsssdiii", 
                    $discountAmount, $expressAmount, 
                    $grossTotal, $delivery_date, $delivery_slot, 
                    $coupon, $payableAmount, $total_quantity,
                    $edit_order_id, $storeid
                );
                $stmt_order->execute();
                $stmt_order->close();
				
				$payment_update = $conn->prepare("UPDATE payments SET Due_Amount = ? WHERE order_id = ?");
$payment_update->bind_param("di", $payableAmount, $edit_order_id);
$payment_update->execute();
$payment_update->close();
                
                $order_id = $edit_order_id;
				
                
                // 4. MARK OLD ORDER_ITEM AS NOT CURRENT
                $mark_old_sql = "UPDATE order_item SET is_current = 0 WHERE order_id = ?";
                $stmt_mark = $conn->prepare($mark_old_sql);
                $stmt_mark->bind_param("i", $order_id);
                $stmt_mark->execute();
                $stmt_mark->close();
                
                // 5. INSERT NEW ORDER_ITEM ENTRY WITH VERSION - USING DIRECT SQL
				//  INSERT के साथ SELECT (Combined Query)
                $insert_item_sql = "
                    INSERT INTO order_item (
                        order_id, customer_id, discount_amount, express_amount, 
                        total_amount, delivery_date, delivery_slot, 
                        coupon_code, payable_amount, order_items_with_comments, 
                        Quantity, version, is_current, created_at
                    ) 
                    SELECT 
                        $order_id, $customer_id, $discountAmount, $expressAmount, 
                        $grossTotal, '$delivery_date', '$delivery_slot', 
                        '$coupon', $payableAmount, '$order_items_json_escaped', 
                        $total_quantity,
                        COALESCE(MAX(version), 0) + 1, 1, NOW()
                    FROM order_item WHERE order_id = $order_id
                ";
				
	// Example: अगर order_id = 100 के 3 versions पहले से हैं:
// version: 1, 2, 3 (MAX(version) = 3)
// तो नया version होगा: 3 + 1 = 4

// अगर कोई version नहीं है (पहली बार):
// MAX(version) = NULL
// COALESCE(NULL, 0) = 0
// 0 + 1 = 1 (पहला version)
                
                error_log("🔍 EDIT MODE SQL: " . $insert_item_sql);
                
                // Direct execute
                if ($conn->query($insert_item_sql)) {
                    $order_item_id = $conn->insert_id;
                    error_log("✅ ORDER_ITEM INSERTED WITH ID: $order_item_id");
                } else {
                    throw new Exception("Failed to insert order item: " . $conn->error);
                }
                
                // 6. Update orders table with latest order_item_id
                $update_order_item_id = "UPDATE orders SET order_item_id = ? WHERE id = ?";
                $stmt_update = $conn->prepare($update_order_item_id);
                $stmt_update->bind_param("ii", $order_item_id, $order_id);
                $stmt_update->execute();
                $stmt_update->close();
                
            } else {
                // ✅ INSERT NEW ORDER
                
                // 1. Check if customer already exists
                $check_sql = "SELECT id FROM customers WHERE mobile = ? AND storeid = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("si", $phone, $storeid);
                $check_stmt->execute();
                $result = $check_stmt->get_result();

                if ($result->num_rows > 0) {
                    // ✅ EXISTING CUSTOMER - UPDATE DETAILS
                    $row = $result->fetch_assoc();
                    $customer_id = $row['id'];
                    
                    // Update name and address
                    $update_sql = "UPDATE customers SET name = ?, address = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ssi", $name, $address, $customer_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                } else {
                    // ✅ NEW CUSTOMER - INSERT
                    $insert_sql = "INSERT INTO customers (mobile, name, address, storeid) VALUES (?, ?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("sssi", $phone, $name, $address, $storeid);
                    $insert_stmt->execute();
                    $customer_id = $insert_stmt->insert_id;
                    $insert_stmt->close();
                }
                $check_stmt->close();
                
                // 2. Insert into orders table using prepared statement
                $insert_order_sql = "
                    INSERT INTO orders (
                        storeid, 
                        customer_id, 
                        discount_amount, 
                        express_amount, 
                        total_amount, 
                        delivery_date, 
                        delivery_slot, 
                        order_date, 
                        payment_status, 
                        status, 
                        delivered_datetime, 
                        coupon_code, 
                        payable_amount, 
                        quantity
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";

                $order_date = date("Y-m-d H:i:s");
                $payment_status = "Due";
                $status = "pending";
                $delivered_datetime = null;

                $stmt_order = $conn->prepare($insert_order_sql);
                $stmt_order->bind_param(
                    "iidddsssssssdi",
                    $storeid,
                    $customer_id,
                    $discountAmount,
                    $expressAmount,
                    $grossTotal,
                    $delivery_date,
                    $delivery_slot,
                    $order_date,
                    $payment_status,
                    $status,
                    $delivered_datetime,
                    $coupon,
                    $payableAmount,
                    $total_quantity
                );

                $stmt_order->execute();
                $order_id = $stmt_order->insert_id;
                $stmt_order->close();
				
				$payment_insert = $conn->prepare("INSERT INTO payments (order_id, Paid_Amount, Due_Amount, storeid) VALUES (?, 0, ?, ?)");
				$payment_insert->bind_param("idi", $order_id, $payableAmount, $storeid);
				$payment_insert->execute();
				$payment_insert->close();

                // 3. Insert into order_item table - USING DIRECT SQL FOR JSON
                $item_log_date = date("Y-m-d H:i:s");
                $version = 1;
                $is_current = 1;
                $status_updated_at = date("Y-m-d H:i:s");
                $payment_status_updated_at = date("Y-m-d H:i:s");
                
                // Handle NULL for delivered_datetime
                $delivered_datetime_sql = $delivered_datetime === null ? 'NULL' : "'$delivered_datetime'";
                
                $insert_item_sql = "
                    INSERT INTO order_item (
                        order_id,
                        customer_id,
                        discount_amount,
                        express_amount,
                        total_amount,
                        delivery_date,
                        delivery_slot,
                        item_log_date,
                        payment_status,
                        status,
                        delivered_datetime,
                        coupon_code,
                        payable_amount,
                        order_items_with_comments,
                        Quantity,
                        version,
                        is_current,
                        status_updated_at,
                        payment_status_updated_at
                    ) VALUES (
                        $order_id,
                        $customer_id,
                        $discountAmount,
                        $expressAmount,
                        $grossTotal,
                        '$delivery_date',
                        '$delivery_slot',
                        '$item_log_date',
                        'Due',
                        'Pending',
                        $delivered_datetime_sql,
                        '$coupon',
                        $payableAmount,
                        '$order_items_json_escaped',
                        $total_quantity,
                        $version,
                        $is_current,
                        '$status_updated_at',
                        '$payment_status_updated_at'
                    )
                ";
                
                error_log("🔍 NEW ORDER SQL: " . $insert_item_sql);
                
                // Direct execute
                if ($conn->query($insert_item_sql)) {
                    $order_item_id = $conn->insert_id;
                    error_log("✅ ORDER_ITEM INSERTED WITH ID: $order_item_id");
                } else {
                    throw new Exception("Failed to insert order item: " . $conn->error);
                }

                // 4. Update orders table with order_item_id
                $update_order_item_id = "UPDATE orders SET order_item_id = ? WHERE id = ?";
                $stmt_update = $conn->prepare($update_order_item_id);
                $stmt_update->bind_param("ii", $order_item_id, $order_id);
                $stmt_update->execute();
                $stmt_update->close();
            }
            
            // ✅ INSERT INTO order_item_byweight (Laundry By Weight Data)
            // First delete existing for this order (for edits)
            $conn->query("DELETE FROM order_item_byweight WHERE order_id = $order_id");
            
            $stmt_weight = $conn->prepare("INSERT INTO order_item_byweight (order_id, storeid, product_name, product_type, service_type, comments, qty) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($items as $item) {
                $p_name = isset($item["item"]) ? trim($item["item"]) : '';
                // Check if Laundry By Weight
                if (stripos($p_name, 'laundry by weight') !== false) {
                    $p_type = $item["product_type"] ?? '';
                    $s_type = isset($item["service"]) ? trim($item["service"]) : '';
                    $quantity = isset($item["qty"]) ? floatval($item["qty"]) : 0;
                    $comments_arr = $item["comments"] ?? [];
                    $comments_str = is_array($comments_arr) ? json_encode($comments_arr, JSON_UNESCAPED_UNICODE) : (string)$comments_arr;
                    
                    $stmt_weight->bind_param("iissssd", $order_id, $storeid, $p_name, $p_type, $s_type, $comments_str, $quantity);
                    $stmt_weight->execute();
                }
            }
            $stmt_weight->close();

            // ✅ COMMIT TRANSACTION
            $conn->commit();

            // ✅ PREPARE RESPONSE
            $response = [
                "status" => "success",
                "message" => $is_edit ? "Order updated successfully!" : "Order saved successfully!",
                "order_id" => $order_id,
                "is_edit" => $is_edit,
                "json_debug" => [
                    "length" => strlen($order_items_json_escaped),
                    "valid" => json_decode($order_items_json_escaped) !== null
                ]
            ];

            echo json_encode($response);
            
            // ✅ Flush output
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            
            // ✅ Send SMS only for new orders
            if (!$is_edit && $order_id && !empty($phone)) {
                $delivery_date_formatted = date('d/m/Y', strtotime($delivery_date));
                $sms_message = "Hello $name, Your order #$order_id has been created. Amount: Rs.$payableAmount. Delivery: $delivery_date_formatted ($delivery_slot). Thank you!";
                $clean_mobile = preg_replace('/[^0-9]/', '', $phone);
                $sms_result = sendFast2SMS($clean_mobile, $sms_message);
                
                // Log SMS result
                $log_entry = "========================================\n";
                $log_entry .= "Time: " . date('Y-m-d H:i:s') . "\n";
                $log_entry .= "Order ID: $order_id\n";
                $log_entry .= "Customer: $name\n";
                $log_entry .= "Mobile: $clean_mobile\n";
                $log_entry .= "Message: $sms_message\n";
                $log_entry .= "API Response: " . json_encode($sms_result) . "\n";
                $log_entry .= "========================================\n\n";
                file_put_contents('sms_delivery_log.txt', $log_entry, FILE_APPEND);
            }
            
        } catch (Exception $e) {
            // ✅ ROLLBACK TRANSACTION ON ERROR
            $conn->rollback();
            error_log("❌ TRANSACTION ERROR: " . $e->getMessage());
            echo json_encode([
                "status" => "error",
                "message" => "Transaction failed: " . $e->getMessage(),
                "debug" => [
                    "json_string" => $order_items_json_string ?? '',
                    "json_escaped" => $order_items_json_escaped ?? '',
                    "json_length" => isset($order_items_json_string) ? strlen($order_items_json_string) : 0
                ]
            ]);
        }
        
        exit;
    }
}
?>







<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $is_edit_mode ? "Edit Order #$current_order_id" : "Walk-In"; ?> | Fabrico Laundry</title>
<style>
/* Your existing CSS styles remain the same */
.active-btn { background:#28a745 !important; color:white !important; }
.type-btn { background:#f1f5ff; border:none; margin:3px; padding:4px 8px; border-radius:4px; font-size:12px; cursor:pointer;}
.type-btn:hover { background:#87ceeb; color:white;}
.services-container { margin-top:5px; padding-left:10px; border-left:2px solid #cce7ff;}
.hidden { display:none;}
body { font-family:Arial,sans-serif; background:#f6f6f6; margin:0; padding:20px;}
.container { display:flex; gap:20px;}
.left,.right { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.1);} 
.left { flex:1;}
.right { flex:1; max-height:85vh; overflow-y:auto;}
input, select, textarea { padding:8px; margin:5px 0; width:48%; border:1px solid #ccc; border-radius:6px;}
textarea { width:100%; resize:vertical;}
.alphabet-bar { text-align:center; margin-bottom:10px;}
.alphabet-bar button { background:#d0e7ff; border:none; margin:2px; padding:5px 10px; border-radius:4px; cursor:pointer;}
.alphabet-bar button:hover { background:#87ceeb; color:white;}
.product { display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding:10px 0;}
.service-btns { display:flex; gap:5px;}
.add-btn { background:#00aaff; color:white; border:none; padding:6px 10px; border-radius:5px; cursor:pointer; font-size:13px;}
.add-btn:hover { background:#007399;}
table { width:100%; border-collapse:collapse; margin-top:10px;}
th, td { border-bottom:1px solid #eee; padding:8px; text-align:center;}
.summary-box { margin-top:12px; padding:5px; border-radius:8px; background:#fafafa; border:1px solid #eee; width:100%;}
.summary-row { display:flex; justify-content:space-between; padding:6px 0; font-size:14px;}
.total-section { margin-top:10px; font-weight:bold;}
.bottom-section { display:flex; flex-direction:column; align-items:flex-start; gap:10px; margin-top:10px;}
.create-btn { background:#00aaff; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; width:100%;}
.create-btn:hover { background:#007399;}
.coupon-open-btn { background:#00aaff;color:white;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;}
.coupon-open-btn:hover { background:#007399;}
.remove-coupon { background:#ff4444;color:white;border:none;padding:8px 12px;border-radius:5px;cursor:pointer; position: absolute; margin-left: 130px; margin-top: -33px; }

/* Coupon Panel */
#couponPanel { 
    position:fixed; 
    top:0; 
    left:-360px; 
    width:320px; 
    height:100%; 
    background:white; 
    box-shadow:2px 0 8px rgba(0,0,0,0.3); 
    padding:20px; 
    transition:0.35s; 
    z-index:10000; 
    overflow-y:auto;
}
.offer-item { padding:10px;border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;}
.offer-item small { color:gray; }

/* Comments Panel */
#commentsPanel { 
    position:fixed; 
    top:0; 
    right:-400px; 
    width:380px; 
    height:100%; 
    background:white; 
    box-shadow:-2px 0 8px rgba(0,0,0,0.3); 
    padding:20px; 
    transition:0.35s; 
    z-index:10000; 
    overflow-y:auto;
}

.active-comment {
    background: #3498db !important;
    color: white !important;
    border-color: #2980b9 !important;
}

/* Item Comments */
.item-comment {
    display: inline-block;
    background: #e3f2fd;
    padding: 2px 6px;
    margin: 1px;
    border-radius: 3px;
    font-size: 10px;
    border: 1px solid #bbdefb;
}

.comment-badge {
    background: #ff9800;
    color: white;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 10px;
    margin-left: 5px;
}

.service-item {
    margin: 8px 0;
    padding: 8px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    background: #fafafa;
}

.comments-btnnnn {
    background: #ff9800;
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 11px;
    margin-left: 10px;
}

.comments-btn:hover {
    background: #f57c00;
}

/* Add Item Button in Comments Panel */
.add-item-comments-btn {
    width: 100%;
    background: #00c853;
    color: white;
    border: none;
    padding: 12px;
    border-radius: 6px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    top: -50px;
    margin-top: 50px;
    margin-bottom: 10px;
    position: relative;
    z-index: 1000;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

.add-item-comments-btn:hover {
    background: #00b34a;
}

/* Comments Grid in Panel */
.comments-grid-panel {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin-bottom: 15px;
    max-height: 300px;
    overflow-y: auto;
}

.comment-btn-panel {
    padding: 10px 8px;
    border: 2px solid #ddd;
    border-radius: 6px;
    background: white;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
    text-align: center;
}

/* Clickable Comments Styles */
.comments-cell {
    cursor: pointer;
    position: relative;
    max-width: 150px;
    min-height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.comments-cell:hover {
    background: #f0f8ff;
}

.item-comments-display {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    align-items: center;
    justify-content: center;
    min-height: 25px;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.3s ease;
    width: 100%;
}

.item-comments-display:hover {
    background: #e3f2fd;
    border: 1px dashed #1976d2;
}

.comment-badge {
    background: #ff9800;
    color: white;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 10px;
    margin: 1px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
}

.comment-badge:hover {
    background: #f57c00;
    transform: scale(1.05);
}

.empty-comments {
    color: #999;
    font-style: italic;
    font-size: 11px;
    padding: 4px 8px;
}

/* Comments Edit Modal */
.comments-edit-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10001;
    justify-content: center;
    align-items: center;
}

.comments-edit-content {
    background: white;
    padding: 20px;
    border-radius: 10px;
    width: 400px;
    max-width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.comments-edit-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin: 15px 0;
    max-height: 300px;
    overflow-y: auto;
}

.comment-edit-btn {
    padding: 8px;
    border: 2px solid #ddd;
    border-radius: 6px;
    background: white;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s ease;
}

.comment-edit-btn.active {
    background: #3498db;
    color: white;
    border-color: #2980b9;
}

.selected-comments-display {
    min-height: 60px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 5px;
    margin: 10px 0;
    border: 1px dashed #ccc;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
}

/* Error Styling */
.error-input {
    border: 2px solid red !important;
    background-color: #ffe6e6 !important;
}

.error-message {
    color: red;
    font-size: 12px;
    margin-top: 2px;
    display: none;
}

.required-label {
    font-weight: bold;
}

.required-label::after {
    content: " *";
    color: red;
}

/* Ensure panels are above everything */
#couponPanel, #commentsPanel {
    z-index: 10000 !important;
}

/* SMS Notification Indicator */
.sms-status {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 10px 15px;
    border-radius: 8px;
    font-size: 14px;
    z-index: 9999;
    display: none;
}

.sms-success {
    background: #4CAF50;
    color: white;
}

.sms-error {
    background: #f44336;
    color: white;
}
</style>
</head>
<body>
<div class="main-content"> 
<!-- SMS Notification -->
<div id="smsNotification" class="sms-status"></div>

<!-- Order Success Popup -->
<div id="orderPopup" 
     style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
     background:rgba(0,0,0,0.5); z-index:99999; justify-content:center; align-items:center;">
  
  <div style="background:white; padding:25px; border-radius:10px; width:340px; text-align:center;">
      <h3><?php echo $is_edit_mode ? "🎉 Order Updated!" : "🎉 Order Created!"; ?></h3>
      <p id="popupMsg" style="font-size:18px; font-weight:bold;"></p>
      
      <!-- ✅ SMS STATUS DISPLAY -->
      <div id="smsStatusDisplay" style="margin: 10px 0; padding: 8px; border-radius: 5px; background: #f0f8ff; display: none;">
          <span id="smsStatusText"></span>
      </div>

      <!-- VIEW INVOICE BUTTON -->
      <button id="viewInvoiceBtn" onclick="viewInvoice()" 
              style="margin-top:15px; background:#28a745; color:white; padding:8px 18px; 
              border:none; border-radius:6px; cursor:pointer; margin-right:10px; display:none;">
          View Invoice
      </button>

      <!-- ⭐ NEW TAG BUTTON -->
      <button id="tagBtn" onclick="printTag()" 
              style="margin-top:15px; background:#ff9800; color:white; padding:8px 18px; 
              border:none; border-radius:6px; cursor:pointer; margin-right:10px; display:none;">
          Tag
      </button>

      <!-- CLOSE BUTTON -->
      <button onclick="closePopup()" 
              style="margin-top:15px; background:#1976d2; color:white; padding:8px 18px; 
              border:none; border-radius:6px; cursor:pointer;">
          Close
      </button>
  </div>
</div>

<!-- Comments Edit Modal -->
<div id="commentsEditModal" class="comments-edit-modal">
    <div class="comments-edit-content">
        <h3 style="margin-top: 0; color: #1976d2;">✏️ Edit Comments</h3>
        
        <div style="margin-bottom: 15px;">
            <strong>Item:</strong>
            <span id="editItemName" style="color: #1976d2; font-weight: bold;"></span>
        </div>
        
        <div style="margin-bottom: 15px;">
            <h4 style="margin-bottom: 8px;">Select Comments:</h4>
            <div id="commentsEditGrid" class="comments-edit-grid">
                <!-- Comments buttons will be added here dynamically -->
            </div>
        </div>
        
        <div style="margin-bottom: 15px;">
            <h4 style="margin-bottom: 8px;">Selected Comments:</h4>
            <div id="editSelectedComments" class="selected-comments-display">
                No comments selected
            </div>
        </div>
        
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button onclick="closeEditModal()" 
                    style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                Cancel
            </button>
            <button onclick="saveEditedComments()" 
                    style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                Save Comments
            </button>
        </div>
    </div>
</div>

<?php include 'menu.php'; ?> 

<h2 style="text-align:center; color: black">
    <?php echo $is_edit_mode ? "✏️ Edit Order #$current_order_id" : "🧺 Walk In"; ?>
</h2>
<div class="container">

<!-- Left Section -->
<div class="left">
<label class="required-label">Phone:</label><br>
<!-- Line 463 ke baad -->
<input type="text" id="mobile" placeholder="Mobile" maxlength="10" required 
       onblur="checkCustomer()"
       value="<?php echo htmlspecialchars($edit_customer_data['phone'] ?? ''); ?>"><br>
<span id="mobileError" class="error-message"></span>

<label class="required-label">Customer Name:</label><br>
<input type="text" id="name" placeholder="Name" required 
       value="<?php echo htmlspecialchars($edit_customer_data['name'] ?? ''); ?>"><br>
<span id="nameError" class="error-message"></span>

<label>Address:</label><br>
<textarea id="address" rows="2" placeholder="Customer address (optional)">
<?php echo htmlspecialchars($edit_customer_data['address'] ?? ''); ?>
</textarea>

<table id="orderTable">
<thead>
<tr>
<th>Item</th>
<th>Service</th>
<th>Qty</th>
<th>Price</th>
<th>Comments</th>
<th>Remove</th>
</tr>
</thead>
<tbody id="orderTableBody"></tbody>
</table>

<!-- Summary box showing Gross, Discount, etc -->
<div class="summary-box">
  <div class="summary-row"><div>Total Amount:</div><div>₹<span id="grossTotal">0.00</span></div></div>
  <div class="summary-row"><div>Discount Amount:</div><div>₹<span id="discountAmount">0.00</span></div></div>
  <div class="summary-row"><div>Express Amount:</div><div>₹<span id="expressAmount">0.00</span></div></div>
  <div class="summary-row"><div>Total Count:</div><div><span id="totalCount">0</span> pc</div></div>
  <hr>
  <div class="summary-row" style="font-weight:bold;"><div>Payable Amount:</div><div>₹<span id="payableAmount">0.00</span></div></div>

  <div style="margin-top:10px;">
      <button class="coupon-open-btn" onclick="openCouponPanel()">🏷️ Add Coupon</button>
  </div>
</div>

<div class="bottom-section">
  <div style="width:100%;">
    <label class="required-label">Delivery Date:</label><br>
    <input type="date" id="deliveryDate" required style="width:100%;">
    <span id="dateError" class="error-message"></span>
  </div>
  <div style="width:100%;">
    <label class="required-label">Delivery Time Slot:</label><br>
    <select id="timeSlot" required style="width:100%;">
      <option value="">Select Delivery Slot</option>
      <option>Morning (9-12)</option>
      <option>Afternoon (12-4)</option>
      <option>Evening (4-8)</option>
    </select>
    <span id="slotError" class="error-message"></span>
  </div>
</div>

<button class="create-btn" onclick="saveOrder()">
    <?php echo $is_edit_mode ? "Update Order" : "Create Order"; ?>
</button>

<?php if($is_edit_mode): ?>
<input type="hidden" id="editOrderId" value="<?php echo $current_order_id; ?>">
<?php endif; ?>
</div>

<!-- Right Section -->
<div class="right">
  <input type="text" id="searchInput" placeholder="🔍 Search item..." style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ccc; border-radius:8px; font-size:14px;">
  <div class="alphabet-bar" id="alphabetBar"></div>
  <div id="productList"></div>
</div>
</div>

<!-- LEFT SIDE COUPON PANEL -->
<div id="couponPanel">
    <h2 style="margin-top:0;">🎟️ Offers</h2>
    <button onclick="closeCouponPanel()" style="background:#ff4444;color:white;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;">Close ✖</button>
    <hr>
    <div id="offerList"></div>
</div>

<!-- RIGHT SIDE COMMENTS PANEL -->
<div id="commentsPanel">
    <h2 style="margin-top:0; color: #1976d2;">💬 Add Comments</h2>
    <button onclick="closeCommentsPanel()" style="background:#ff4444;color:white;border:none;padding:6px 10px;border-radius:4px;cursor:pointer; margin-bottom: 15px;">Close ✖</button>
    
    <div style="background: #fff9e6; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; border-left: 4px solid #ffc107;">
        <strong>Note:</strong> Customer will be notified for these comments and it is advised to wait for atleast 30 min to start processing this order after tagging.
    </div>
    
    <div style="margin-bottom: 15px;">
        <strong>Selected Item:</strong>
        <div id="selectedServiceInfo" style="color: #1976d2; font-weight: bold; margin-top: 5px; padding: 8px; background: #f8f9fa; border-radius: 4px;">
            No item selected
        </div>
    </div>
    
    <div style="margin-bottom: 15px;">
        <h4 style="margin-bottom: 10px;">Select Comments:</h4>
        <div id="commentsGrid" class="comments-grid-panel">
            <!-- Comments buttons will be added here -->
        </div>
    </div>
    
    <div style="margin-bottom: 15px;">
        <h4 style="margin-bottom: 8px;">Selected Comments:</h4>
        <div id="selectedCommentsDisplay" style="min-height: 60px; padding: 12px; background: white; border-radius: 5px; border: 1px dashed #ccc; font-size: 14px;">
            No comments selected
        </div>
    </div>
    
    <button class="add-item-comments-btn" onclick="addItemWithSelectedComments()">
        ✅ ADD ITEM WITH COMMENTS
    </button>
</div>

<!-- JS -->
<script>



// Line 580 ke baad (realtime validation ke baad) add karein:
function checkCustomer() {
    const phone = document.getElementById('mobile').value.trim();
    const nameInput = document.getElementById('name');
    const addressInput = document.getElementById('address');
    
    if (!phone || phone.length < 10 || isEditMode) return;
    
    // Disable fields temporarily while checking
    nameInput.disabled = true;
    addressInput.disabled = true;
    
    fetch(`?action=get_customer&phone=${encodeURIComponent(phone)}`)
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            // Customer exists - auto fill and disable
            nameInput.value = data.data.name;
            addressInput.value = data.data.address;
            
            // Disable name and address fields
            nameInput.disabled = true;
            addressInput.disabled = true;
            
            // Show message
            showNotification('Existing customer found. Name and address are locked.', 'info');
        } else {
            // Customer not found - enable fields
            nameInput.disabled = false;
            addressInput.disabled = false;
            nameInput.value = '';
            addressInput.value = '';
            nameInput.focus();
        }
    })
    .catch(err => {
        console.error('Error checking customer:', err);
        nameInput.disabled = false;
        addressInput.disabled = false;
    });
}

// Helper function for notification
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 5px;
        color: white;
        z-index: 10000;
        font-weight: bold;
    `;
    
    if (type === 'info') {
        notification.style.background = '#17a2b8';
    } else if (type === 'success') {
        notification.style.background = '#28a745';
    }
    
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

	


let allProducts = {};
const alphabetBar = document.getElementById("alphabetBar");
const productList = document.getElementById("productList");
const orderTable = document.querySelector("#orderTable tbody");

const grossTotalEl = document.getElementById("grossTotal");
const discountAmountEl = document.getElementById("discountAmount");
const expressAmountEl = document.getElementById("expressAmount");
const totalCountEl = document.getElementById("totalCount");
const payableAmountEl = document.getElementById("payableAmount");

let grossTotal = 0.0;
let discountAmount = 0.0;
let payableAmount = 0.0;
let expressAmount = 0.0;
let totalCount = 0;

// Check if editing order
const urlParams = new URLSearchParams(window.location.search);
const isEditMode = urlParams.has('order_id');
const orderId = urlParams.get('order_id');

// Coupon offers
let offers = [];

// Load coupons from DB
fetch("?action=get_coupons")
  .then(r => r.json())
  .then(data => {
    if (data.status === "success") offers = data.data;
  });

let appliedCoupon = null;

// Comments
const comments = [
    "Bleach Mark", "Button Missing", "Colour Stain", "Fragile Garment",
    "Fungus Stain", "Hole", "No Guarantee For Stain", "Pressing Mark",
    "Print Damaged", "Risk Of Damage", "Stich Open", "Thread Loose", "Torn"
];

// Store currently selected service info
let currentSelectedService = null;
let selectedComments = [];

// Comments edit functionality
let currentEditingRow = null;

// ✅ LOAD EXISTING ORDER ITEMS
// ✅ LOAD EXISTING ORDER ITEMS WITH DELIVERY INFO
function loadExistingOrderItems() {
    if (!isEditMode || !orderId) return;
    
    fetch(`?action=get_order_items&order_id=${orderId}`)
    .then(r => r.json())
    .then(data => {
        if (data.status === "success" && data.items) {
            // ✅ 1. SET DELIVERY DATE
            if (data.delivery_date) {
                document.getElementById("deliveryDate").value = data.delivery_date;
            }
            
            // ✅ 2. SET DELIVERY SLOT  
            if (data.delivery_slot) {
                document.getElementById("timeSlot").value = data.delivery_slot;
            }
            
            // ✅ 3. LOAD ITEMS
            // Clear table
            document.querySelector("#orderTable tbody").innerHTML = "";
            
            // Reset totals
            grossTotal = 0;
            totalCount = 0;
            
            // Add each item
            data.items.forEach(item => {
                addItem(
                    item.product_name || item.item || '',
                    item.product_type || '', // product type
                    item.service_type || item.service || '',
                    parseFloat(item.price || 0),
                    parseFloat(item.qty || 1), // Use parseFloat
                    item.comments || [],
                    item.unit || '' // Pass unit
                );
            });
            
            recalcAfterChange();
        }
    })
    .catch(err => console.error("Error loading order items:", err));
}

// ✅ ENHANCED DATE VALIDATION
function validateAndFormatDate(dateString) {
    if (!dateString) return null;
    
    // Check for invalid patterns
    if (dateString.includes('0000') || dateString.includes('undefined') || dateString === "0000-00-00") {
        return null;
    }
    
    const date = new Date(dateString);
    
    // Check if date is valid
    if (isNaN(date.getTime())) {
        return null;
    }
    
    // Check for reasonable year (not 1970 or 0000)
    const year = date.getFullYear();
    if (year < 2000 || year > 2100) {
        return null;
    }
    
    // Format as YYYY-MM-DD
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    return `${year}-${month}-${day}`;
}

// ✅ REAL-TIME VALIDATION
function setupRealTimeValidation() {
    const nameInput = document.getElementById("name");
    const mobileInput = document.getElementById("mobile");
    const dateInput = document.getElementById("deliveryDate");
    const slotInput = document.getElementById("timeSlot");
    
    // Name validation
    nameInput.addEventListener("blur", function() {
        const value = this.value.trim();
        const errorEl = document.getElementById("nameError");
        
        if (!value) {
            this.classList.add("error-input");
            errorEl.textContent = "Name is required";
            errorEl.style.display = "block";
        } else {
            this.classList.remove("error-input");
            errorEl.style.display = "none";
        }
    });
    
    // Mobile validation
    mobileInput.addEventListener("blur", function() {
        const value = this.value.trim();
        const errorEl = document.getElementById("mobileError");
        
        if (!value) {
            this.classList.add("error-input");
            errorEl.textContent = "Mobile number is required";
            errorEl.style.display = "block";
        } else if (!validateMobile(value)) {
            this.classList.add("error-input");
            errorEl.textContent = "Enter valid 10-digit mobile number";
            errorEl.style.display = "block";
        } else {
            this.classList.remove("error-input");
            errorEl.style.display = "none";
        }
    });
    
    // Date validation
    dateInput.addEventListener("blur", function() {
        const value = this.value;
        const errorEl = document.getElementById("dateError");
        
        if (!value) {
            this.classList.add("error-input");
            errorEl.textContent = "Delivery date is required";
            errorEl.style.display = "block";
        } else if (!validateAndFormatDate(value)) {
            this.classList.add("error-input");
            errorEl.textContent = "Invalid date selected";
            errorEl.style.display = "block";
        } else {
            this.classList.remove("error-input");
            errorEl.style.display = "none";
        }
    });
    
    // Slot validation
    slotInput.addEventListener("change", function() {
        const value = this.value;
        const errorEl = document.getElementById("slotError");
        
        if (!value) {
            this.classList.add("error-input");
            errorEl.textContent = "Please select delivery slot";
            errorEl.style.display = "block";
        } else {
            this.classList.remove("error-input");
            errorEl.style.display = "none";
        }
    });
    
    // Add date change listener to prevent invalid dates
    dateInput.addEventListener("change", function() {
        const value = this.value;
        
        if (value && (value.includes('0000') || value === "0000-00-00")) {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, "0");
            const dd = String(today.getDate()).padStart(2, "0");
            this.value = `${yyyy}-${mm}-${dd}`;
            alert("Invalid date was corrected to today's date.");
        }
    });
}

// ✅ VALIDATION FUNCTIONS
function validateRequiredFields(name, phone, deliveryDate, deliverySlot) {
    let isValid = true;
    let errorMessage = "";
    let focusElement = null;

    if (!name) {
        errorMessage = "⚠️ Please enter customer name!";
        focusElement = document.getElementById("name");
        isValid = false;
    } 
    else if (!phone) {
        errorMessage = "⚠️ Please enter mobile number!";
        focusElement = document.getElementById("mobile");
        isValid = false;
    }
    else if (!deliveryDate) {
        errorMessage = "⚠️ Please select delivery date!";
        focusElement = document.getElementById("deliveryDate");
        isValid = false;
    }
    else if (!deliverySlot) {
        errorMessage = "⚠️ Please select delivery time slot!";
        focusElement = document.getElementById("timeSlot");
        isValid = false;
    }

    if (!isValid) {
        alert(errorMessage);
        if (focusElement) {
            focusElement.focus();
            focusElement.classList.add("error-input");
            setTimeout(() => {
                focusElement.classList.remove("error-input");
            }, 3000);
        }
    }

    return isValid;
}

function validateMobile(phone) {
    const phoneRegex = /^[6-9]\d{9}$/;
    return phoneRegex.test(phone);
}

// Auto-fill Name & Address
document.getElementById("mobile").addEventListener("blur", function() {
    const phone = this.value.trim();
    if (!phone || !validateMobile(phone) || isEditMode) return;
    
    fetch(`?action=get_customer&phone=${encodeURIComponent(phone)}`)
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            document.getElementById("name").value = data.data.name;
            document.getElementById("address").value = data.data.address;
        } else {
            document.getElementById("name").value = "";
            document.getElementById("address").value = "";
        }
    });
});

// Load products
fetch("?action=get_products")
  .then(r => r.json())
  .then(data => {
    if (data.status === "success") allProducts = data.data;
  });

// A-Z buttons
"ABCDEFGHIJKLMNOPQRSTUVWXYZ".split("").forEach(l => {
  const btn = document.createElement("button");
  btn.textContent = l;
  btn.onclick = () => showProducts(l);
  alphabetBar.appendChild(btn);
});

function showProducts(letter) {
  productList.innerHTML = "";
  const filtered = Object.keys(allProducts).filter(p => p.toUpperCase().startsWith(letter));
  if (filtered.length === 0) {
    productList.innerHTML = "<p style='text-align:center;color:gray;'>No items found</p>";
    return;
  }
  filtered.forEach(name => {
    const types = allProducts[name];
    let typeBtns = "";
    for (let type in types) {
      typeBtns += `<button class='type-btn' onclick="showServices('${escapeHtml(name)}','${escapeHtml(type)}')">${type}</button>`;
    }
    const div = document.createElement("div");
    div.className = "product";
    const id = name.replace(/\s+/g, '_') + "_services";
    div.innerHTML = `<div><b>${name}</b><div>${typeBtns}</div></div>\n    <div id="${id}" class="services-container hidden"></div>`;
    productList.appendChild(div);
  });
}

function showServices(name, type) {
  name = unescape(name);
  type = unescape(type);
  const containerId = `${name.replace(/\s+/g, '_')}_services`;
  const container = document.getElementById(containerId);
  container.innerHTML = "";
  const services = allProducts[name] && allProducts[name][type] ? allProducts[name][type] : [];
  
  services.forEach(s => {
    let addGarmentsBtn = "";
    let addBtnHTML = `<button class="add-btn" onclick="addItem('${escapeHtml(name)}','${escapeHtml(type)}','${escapeHtml(s.service_type)}',${s.price})">Add</button>`;

    if (name.toLowerCase().includes('laundry by weight')) {
        addGarmentsBtn = `<button class="add-btn" style="background:#ff9800;" onclick="openGarmentModal('${escapeHtml(name)}','${escapeHtml(type)}','${escapeHtml(s.service_type)}',${s.price})">Add Garments</button>`;
        addBtnHTML = ''; // Hide the simple "Add" button for weight items
    }

    const serviceDiv = document.createElement("div");
    serviceDiv.className = "service-item";
    
    const serviceDetails = document.createElement("div");
    serviceDetails.style.display = "flex";
    serviceDetails.style.justifyContent = "space-between";
    serviceDetails.style.alignItems = "center";
    
    serviceDetails.innerHTML = `
      <div>
        <span style="font-weight:bold;">${s.service_type}</span>
        <span style="color:#1976d2; margin-left:10px;">₹${s.price}</span>
      </div>
      <div>
        ${addBtnHTML} <!-- Simple "Add" button -->
        ${addGarmentsBtn}    <!-- Ye sirf weight wale items ke liye dikhega -->

      </div>
    `;
    
    serviceDiv.appendChild(serviceDetails);
    container.appendChild(serviceDiv);
  });
  
  container.classList.toggle("hidden");
}

// Open comments panel
function openCommentsPanel(name, type, service, price) {
  currentSelectedService = {
    name: unescape(name),
    type: unescape(type),
    service: unescape(service),
    price: price
  };
  
  document.getElementById('selectedServiceInfo').innerHTML = `
    <strong>Product:</strong> ${currentSelectedService.name}<br>
    <strong>Type:</strong> ${currentSelectedService.type}<br>
    <strong>Service:</strong> ${currentSelectedService.service}<br>
    <strong>Price:</strong> ₹${currentSelectedService.price}
  `;
  
  selectedComments = [];
  updateSelectedCommentsDisplay();
  initComments();
  document.getElementById('commentsPanel').style.right = '0';
}

// Close comments panel
function closeCommentsPanel() {
  document.getElementById('commentsPanel').style.right = '-400px';
  currentSelectedService = null;
  selectedComments = [];
}

// Initialize comments buttons
function initComments() {
  const commentsGrid = document.getElementById('commentsGrid');
  commentsGrid.innerHTML = '';
  
  comments.forEach(comment => {
    const button = document.createElement('button');
    button.textContent = comment;
    button.className = 'comment-btn-panel';
    
    button.addEventListener('click', function() {
      this.classList.toggle('active-comment');
      
      if (this.classList.contains('active-comment')) {
        if (!selectedComments.includes(comment)) {
          selectedComments.push(comment);
        }
      } else {
        selectedComments = selectedComments.filter(c => c !== comment);
      }
      
      updateSelectedCommentsDisplay();
    });
    
    commentsGrid.appendChild(button);
  });
}

// Update selected comments display
function updateSelectedCommentsDisplay() {
  const selectedDiv = document.getElementById('selectedCommentsDisplay');
  
  if (selectedComments.length === 0) {
    selectedDiv.innerHTML = 'No comments selected';
    selectedDiv.style.color = '#999';
  } else {
    selectedDiv.innerHTML = selectedComments.map(comment => 
      `<span class="item-comment">${comment}</span>`
    ).join('');
    selectedDiv.style.color = 'black';
  }
}

// Add item with selected comments
function addItemWithSelectedComments() {
  if (!currentSelectedService) {
    alert('Please select a service first');
    return;
  }
  
  const { name, type, service, price } = currentSelectedService;
  const itemId = Date.now() + Math.random();
  
  const row = document.createElement("tr");
  row.dataset.itemId = itemId;
  row.innerHTML = `
    <td>${name} (${type})</td>
    <td>${service}</td>
    <td><input type='number' value='1' min='1' style='width:60px;text-align:center;' onchange='updateQty(this, ${price})'></td>
    <td class='price-cell'>₹${price.toFixed(2)}</td>
    <td class='comments-cell' onclick="editComments(this)">
        <div id="comments-${itemId}" class="item-comments-display">
            ${selectedComments.length > 0 
                ? selectedComments.map(comment => `<span class="comment-badge" title="${comment}">💬</span>`).join('') + `(${selectedComments.length})`
                : '<span class="empty-comments">Click to add comments</span>'
            }
        </div>
    </td>
    <td><button onclick="removeItem(this)">❌</button></td>
  `;
  
  row.dataset.price = price;
  row.dataset.qty = 1;
  row.dataset.itemName = name;
  row.dataset.serviceName = service;
  row.dataset.comments = JSON.stringify(selectedComments);
  
  orderTable.appendChild(row);

  grossTotal += price;
  totalCount += 1;
  recalcAfterChange();
  closeCommentsPanel();
}

// Simple add item without comments - MODIFIED
function addItem(name, type, service, price, qty = 1, item_comments = [], unit = '') {
  name = unescape(name);
  type = unescape(type);
  service = unescape(service);
  
  // If unit is not provided, determine it from product name
  if (!unit) {
    unit = (name.toLowerCase().includes('laundry by weight')) ? 'Kg' : 'Pcs';
  }
  
  const isKg = (unit === 'Kg');
  const qtyInputHTML = isKg
    ? `<input type='number' value='${qty}' min='0.1' step='0.01' style='width:60px;text-align:center;' onchange='updateQty(this, ${price})'>`
    : `<input type='number' value='${qty}' min='1' style='width:60px;text-align:center;' onchange='updateQty(this, ${price})'>`;

  const itemId = Date.now() + Math.random();
  
  const row = document.createElement("tr");
  row.dataset.itemId = itemId;

  // ✅ Build the first cell
  let nameCellHTML = '<td>';
  if (isKg) {
      nameCellHTML += getKgItemCellHTML(name, type, item_comments, itemId);
  } else {
      nameCellHTML += `${name} (${type})`;
  }
  nameCellHTML += `</td>`;

  // For piece-based items, the comments cell is clickable. For weight-based, it's not.
  const commentsCellHTML = isKg 
    ? `<td class='comments-cell'>-</td>` 
    : `<td class='comments-cell' onclick="editComments(this)">
        <div id="comments-${itemId}" class="item-comments-display">
           ${item_comments.length > 0 
               ? item_comments.map(comment => `<span class="comment-badge" title="${comment}">💬</span>`).join('') + `(${item_comments.length})`
               : '<span class="empty-comments">Click to add comments</span>'
           }
        </div>
    </td>`;

    // The action cell with remove button ONLY
    const actionCellHTML = `<td><button onclick="removeItem(this)">❌</button></td>`;

  row.innerHTML = `
    ${nameCellHTML}
    <td>${service}</td>
    <td>${qtyInputHTML} ${unit}</td>
    <td class='price-cell'>₹${(price * qty).toFixed(2)}</td>
    ${commentsCellHTML}
    ${actionCellHTML}
  `;
  
  row.dataset.price = price;
  row.dataset.qty = qty;
  row.dataset.itemName = name;
  row.dataset.itemType = type;
  row.dataset.serviceName = service;
  row.dataset.unit = unit; // Store unit
  row.dataset.comments = JSON.stringify(item_comments);
  
  orderTable.appendChild(row);

  grossTotal += (price * qty);
  totalCount += parseFloat(qty);
  recalcAfterChange();
}

// Function to open comments edit modal
function editComments(commentsCell) {
    const row = commentsCell.closest('tr');
    const itemId = row.dataset.itemId;
    const currentComments = JSON.parse(row.dataset.comments || "[]");
    const itemName = row.dataset.itemName;
    
    currentEditingRow = row;
    
    document.getElementById('editItemName').textContent = itemName;
    document.getElementById('editSelectedComments').innerHTML = currentComments.length > 0 
        ? currentComments.map(comment => `<span class="comment-badge">${comment}</span>`).join('')
        : 'No comments selected';
    
    const commentsGrid = document.getElementById('commentsEditGrid');
    commentsGrid.innerHTML = '';
    
    comments.forEach(comment => {
        const button = document.createElement('button');
        button.textContent = comment;
        button.className = `comment-edit-btn ${currentComments.includes(comment) ? 'active' : ''}`;
        button.onclick = function() {
            this.classList.toggle('active');
            updateEditSelectedComments();
        };
        commentsGrid.appendChild(button);
    });
    
    document.getElementById('commentsEditModal').style.display = 'flex';
}

// Update selected comments in edit modal
function updateEditSelectedComments() {
    const selected = [];
    document.querySelectorAll('#commentsEditGrid .comment-edit-btn.active').forEach(btn => {
        selected.push(btn.textContent);
    });
    
    const display = document.getElementById('editSelectedComments');
    display.innerHTML = selected.length > 0 
        ? selected.map(comment => `<span class="comment-badge">${comment}</span>`).join('')
        : 'No comments selected';
}

// Save edited comments
function saveEditedComments() {
    if (!currentEditingRow) return;
    
    const selected = [];
    document.querySelectorAll('#commentsEditGrid .comment-edit-btn.active').forEach(btn => {
        selected.push(btn.textContent);
    });
    
    currentEditingRow.dataset.comments = JSON.stringify(selected);
    
    const commentsDisplay = currentEditingRow.querySelector('.item-comments-display');
    commentsDisplay.innerHTML = selected.length > 0 
        ? selected.map(comment => `<span class="comment-badge" title="${comment}">💬</span>`).join('') + `(${selected.length})`
        : '<span class="empty-comments">Click to add comments</span>';
    
    closeEditModal();
}

// Close edit modal
function closeEditModal() {
    document.getElementById('commentsEditModal').style.display = 'none';
    currentEditingRow = null;
}

function updateQty(input, price) {
  const qty = parseFloat(input.value) || 1;
  const row = input.closest("tr");
  const prevQty = parseFloat(row.dataset.qty);
  const diff = qty - prevQty;
  row.dataset.qty = qty;
  row.querySelector(".price-cell").textContent = `₹${(price * qty).toFixed(2)}`;

  grossTotal += diff * price;
  totalCount += diff;
  if (totalCount < 0) totalCount = 0;
  recalcAfterChange();
}

function removeItem(btn) {
  const row = btn.closest("tr");
  const price = parseFloat(row.dataset.price);
  const qty = parseFloat(row.dataset.qty);
  
  grossTotal -= price * qty;
  totalCount -= qty;
  if (grossTotal < 0) grossTotal = 0;
  if (totalCount < 0) totalCount = 0;
  row.remove();
  recalcAfterChange();
}

function recalcAfterChange() {
  if (appliedCoupon) {
    discountAmount = (grossTotal * appliedCoupon.percent) / 100;
  } else {
    discountAmount = 0;
  }
  payableAmount = Math.max(0, grossTotal - discountAmount + expressAmount);
  updateSummaryUI();
}

function updateSummaryUI() {
  grossTotalEl.textContent = grossTotal.toFixed(2);
  discountAmountEl.textContent = discountAmount.toFixed(2);
  expressAmountEl.textContent = expressAmount.toFixed(2);
  totalCountEl.textContent = totalCount;
  payableAmountEl.textContent = payableAmount.toFixed(2);
}

// ✅ UPDATED SAVE ORDER FUNCTION














// ✅ UPDATED SAVE ORDER FUNCTION WITH EDIT SUPPORT
function saveOrder() {
	
	
	
	
    // Collect all values
    const name = document.getElementById("name").value.trim();
    const phone = document.getElementById("mobile").value.trim();
    const address = document.getElementById("address").value.trim();
    const deliveryDateInput = document.getElementById("deliveryDate").value;
    const deliverySlot = document.getElementById("timeSlot").value;

    // ✅ STEP 1: Basic required fields validation
    if (!validateRequiredFields(name, phone, deliveryDateInput, deliverySlot)) {
        return;
    }

    // ✅ STEP 2: Mobile number validation
    if (!validateMobile(phone)) {
        alert("⚠️ Please enter valid 10-digit mobile number!");
        document.getElementById("mobile").focus();
        document.getElementById("mobile").classList.add("error-input");
        return;
    }

    // ✅ STEP 3: Check if items are added
    if (totalCount === 0) {
        alert("⚠️ Please add at least one item!");
        return;
    }

    // ✅ STEP 4: Date format validation - USING NEW VALIDATION
    const formattedDate = validateAndFormatDate(deliveryDateInput);
    if (!formattedDate) {
        alert("⚠️ Invalid date! Please select a valid delivery date");
        document.getElementById("deliveryDate").focus();
        document.getElementById("deliveryDate").classList.add("error-input");
        
        // Reset date to today
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, "0");
        const dd = String(today.getDate()).padStart(2, "0");
        document.getElementById("deliveryDate").value = `${yyyy}-${mm}-${dd}`;
        
        return;
    }

    // Collect items
    const items = [];
    document.querySelectorAll("#orderTable tbody tr").forEach(row => {
        const itemName = row.dataset.itemName;
        const serviceName = row.dataset.serviceName;
        const comments = JSON.parse(row.dataset.comments || "[]");
        
        items.push({
            item: itemName,
            product_type: row.dataset.itemType || '',
            service: serviceName,
            qty: parseFloat(row.dataset.qty),
            price: parseFloat(row.dataset.price),
            comments: comments,
            unit: row.dataset.unit || 'Pcs' // Send unit to backend
        });
    });

    const payload = {
        name: name,
        phone: phone,
        address: address,
        deliveryDate: formattedDate,
        deliverySlot: deliverySlot,
        grossTotal: parseFloat(grossTotal.toFixed(2)),
        discountAmount: parseFloat(discountAmount.toFixed(2)),
        payableAmount: parseFloat(payableAmount.toFixed(2)),
        coupon: appliedCoupon ? appliedCoupon.code : "",
        items: items
    };

    // ✅ ADD EDIT ORDER ID IF EDITING
    if (isEditMode && orderId) {
        payload.edit_order_id = orderId;
    }

    // Show loading
    const createBtn = document.querySelector('.create-btn');
    const originalText = createBtn.textContent;
    createBtn.textContent = isEditMode ? 'Updating Order...' : 'Creating Order...';
    createBtn.disabled = true;

    // Send to server
    fetch("?action=save_order", {
        method: "POST",
        headers: { 
            "Content-Type": "application/json",
            "Accept": "application/json"
        },
        body: JSON.stringify(payload)
    })
    .then(async r => {
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error("Failed to parse JSON:", text);
            throw new Error("Invalid response from server");
        }
    })
    .then(res => {
        if (res.status === "success") {
            window.lastOrderId = res.order_id;
            document.getElementById("tagBtn").style.display = "inline-block";
            document.getElementById("viewInvoiceBtn").style.display = "inline-block";
            
            const smsStatusDiv = document.getElementById("smsStatusDisplay");
            const smsStatusText = document.getElementById("smsStatusText");
            
            smsStatusDiv.style.display = "block";
            
            if (res.is_edit) {
                smsStatusText.innerHTML = "✅ Order Updated Successfully";
                smsStatusDiv.style.background = "#d4edda";
                smsStatusDiv.style.border = "1px solid #c3e6cb";
                smsStatusDiv.style.color = "#155724";
                
                openPopup("Order #" + res.order_id + " updated successfully!");
                
                // After 2 seconds, redirect to orders page
                setTimeout(() => {
                    window.location.href = "bbtocc.php";
                }, 2000);
                
            } else {
                smsStatusText.innerHTML = "📱 Sending SMS...";
                smsStatusDiv.style.background = "#fff3cd";
                smsStatusDiv.style.border = "1px solid #ffeaa7";
                smsStatusDiv.style.color = "#856404";
                
                openPopup("Order ID: " + res.order_id + " created successfully!");
                
                setTimeout(() => {
                    smsStatusText.innerHTML = "✅ SMS Sent Successfully";
                    smsStatusDiv.style.background = "#d4edda";
                    smsStatusDiv.style.border = "1px solid #c3e6cb";
                    smsStatusDiv.style.color = "#155724";
                    
                    showSMSNotification("SMS sent to customer", "success");
                }, 2000);
            }
            
        } else {
            alert("❌ Error: " + res.message);
        }
    })
    .catch(err => {
        console.error("Fetch error:", err);
        alert("❌ Error: " + err.message);
    })
    .finally(() => {
        createBtn.textContent = originalText;
        createBtn.disabled = false;
    });
}



















// SMS Notification function
function showSMSNotification(message, type) {
    const notification = document.getElementById('smsNotification');
    notification.textContent = message;
    notification.className = 'sms-status ' + (type === 'success' ? 'sms-success' : 'sms-error');
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}

// VIEW INVOICE function
function viewInvoice() {
    if (window.lastOrderId) {
        window.open("bbtocc.php?invoice=1&order_id=" + window.lastOrderId, "_blank");
    } else {
        alert('Order ID not found.');
    }
}

// Coupon panel
function escapeHtml(s){
  return String(s).replace(/'/g, "\\'").replace(/\"/g, '&quot;');
}
function openCouponPanel() {
  loadOffers();
  document.getElementById("couponPanel").style.left = "0px";
}
function closeCouponPanel() {
  document.getElementById("couponPanel").style.left = "-360px";
}
function loadOffers() {
  const box = document.getElementById("offerList");
  box.innerHTML = "";
  offers.forEach(of => {
    const div = document.createElement("div");
    div.className = "offer-item";
    div.innerHTML = `
      <div>
        <b>${of.name}</b><br><small>Flat ${of.percent}% off</small>
      </div>
      <div>
        <button onclick="applyCoupon('${of.name}', ${of.percent})" style="border:none;background:#00aaff;color:white;padding:6px 10px;border-radius:4px;cursor:pointer;">APPLY</button>
      </div>
    `;
    box.appendChild(div);
  });
}

function applyCoupon(code, percent) {
  appliedCoupon = { code, percent, timestamp: Date.now() };
  discountAmount = (grossTotal * percent) / 100;
  payableAmount = Math.max(0, grossTotal - discountAmount + expressAmount);
  updateSummaryUI();
  closeCouponPanel();
  showRemoveCouponUI();
  alert(`🎉 Coupon ${code} applied (${percent}% off)`);
}

function showRemoveCouponUI() {
  if (!document.getElementById("removeCouponBtn")) {
    const btn = document.createElement("button");
    btn.id = "removeCouponBtn";
    btn.className = "remove-coupon";
    btn.textContent = "❌ Remove Coupon";
    btn.onclick = removeCoupon;
    document.querySelector(".summary-box").appendChild(btn);
  }
}

function removeCoupon() {
  appliedCoupon = null;
  discountAmount = 0.0;
  payableAmount = Math.max(0, grossTotal - discountAmount + expressAmount);
  updateSummaryUI();
  const btn = document.getElementById("removeCouponBtn");
  if (btn) btn.remove();
  alert("Coupon removed");
}

// Popup functions
function openPopup(text){
    document.getElementById("popupMsg").innerText = text;
    document.getElementById("orderPopup").style.display = "flex";
}

function closePopup(){
    document.getElementById("orderPopup").style.display = "none";
    if (isEditMode) {
        window.location.href = "bbtocc.php";
    } else {
        location.reload();
    }
}

function printTag() {
    const orderId = window.lastOrderId;
    if (!orderId) {
        alert("Order ID missing!");
        return;
    }
    showTagInfo(orderId);
}

// Tag printing function
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
            let qty = Number(item.qty) || 1;
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
                    store_name: data.store_name,
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

// ✅ INITIALIZE ON PAGE LOAD
document.addEventListener("DOMContentLoaded", () => {
    // Set default date to today
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, "0");
    const dd = String(today.getDate()).padStart(2, "0");
    const todayStr = `${yyyy}-${mm}-${dd}`;
    
    // Set the date input
    const dateInput = document.getElementById("deliveryDate");
    dateInput.value = todayStr;
    dateInput.setAttribute("min", todayStr);
    
    // Clear any invalid date that might be present
    if (dateInput.value === "0000-00-00" || dateInput.value.includes('0000')) {
        dateInput.value = todayStr;
    }
    
    setupRealTimeValidation();
    updateSummaryUI();
    
    // ✅ LOAD EXISTING ORDER ITEMS IF EDITING
    if (isEditMode) {
        loadExistingOrderItems();
    }
});

// ✅ GARMENT SELECTION MODAL LOGIC
let currentWeightService = null;

function openGarmentModal(name, type, service, price) {
    name = unescape(name);
    type = unescape(type);
    service = unescape(service);
    
    currentWeightService = { name, type, service, price };

    // Reset weight input to default
    document.getElementById('garmentWeight').value = '1.0';
    
    document.getElementById('garmentModal').style.display = 'flex';
    renderGarmentList();
    renderGarmentAlphabet();
}

function closeGarmentModal() {
    document.getElementById('garmentModal').style.display = 'none';
    currentWeightService = null;
}

function renderGarmentList() {
    const list = document.getElementById('garmentList');
    list.innerHTML = '';
    
    const sortedProducts = Object.keys(allProducts).sort();
    
    sortedProducts.forEach(pName => {
        // Filter out the main weight product itself to avoid confusion
        if (pName.toLowerCase().includes('laundry by weight')) return;

        // Iterate over all types for this product
        const types = Object.keys(allProducts[pName]);

        types.forEach(pType => {
            const div = document.createElement('div');
            div.className = 'garment-item';
            div.style.display = 'flex';
            div.style.justifyContent = 'space-between';
            div.style.alignItems = 'center';
            div.style.padding = '8px';
            div.style.borderBottom = '1px solid #f0f0f0';
            
            div.dataset.comments = '[]';
            // Create unique name for comment (e.g., "Shirt (Men)")
            const uniqueName = `${pName} (${pType})`;

            div.innerHTML = `
                <div style="flex: 2; display:flex; align-items:center;">
                    <span style="font-weight:500;">${pName}</span>
                    <button style="margin-left:8px; padding:2px 8px; border:1px solid #00aaff; background:#e1f5fe; color:#007bb5; border-radius:12px; font-size:11px; cursor:default;">${pType}</button>
                </div>
                <div style="flex: 3; cursor:pointer;" onclick="openItemCommentsModal(this)">
                    <div class="item-comments-display" style="min-height: 25px; padding: 4px; border-radius: 4px; border: 1px dashed #ccc; display: flex; flex-wrap: wrap; gap: 3px; align-items: center;">
                        <span style="color:#999; font-style:italic; font-size:12px;">Add Comments</span>
                    </div>
                </div>
                <div style="flex: 1.5; display:flex; align-items:center; justify-content: flex-end; gap:8px;">
                    <button onclick="updateGarmentCount(this, -1)" style="width:28px; height:28px; border-radius:50%; border:1px solid #ccc; background:#f8f9fa; cursor:pointer; font-weight:bold;">-</button>
                    <input type="number" class="garment-count" data-name="${uniqueName}" value="0" min="0" style="width:40px; text-align:center; padding:4px; border:1px solid #ddd; border-radius:4px;" readonly>
                    <button onclick="updateGarmentCount(this, 1)" style="width:28px; height:28px; border-radius:50%; border:1px solid #ccc; background:#f8f9fa; cursor:pointer; font-weight:bold;">+</button>
                </div>
            `;
            list.appendChild(div);
        });
    });
}

function updateGarmentCount(btn, change) {
    const input = btn.parentElement.querySelector('input');
    let val = parseInt(input.value) || 0;
    val += change;
    if (val < 0) val = 0;
    input.value = val;
    
    const row = btn.closest('.garment-item');
    if (val > 0) row.style.backgroundColor = '#e3f2fd';
    else row.style.backgroundColor = 'transparent';
}

function filterGarments() {
    const term = document.getElementById('garmentSearch').value.toLowerCase();
    const items = document.querySelectorAll('.garment-item');
    items.forEach(item => {
        const text = item.innerText.toLowerCase();
        item.style.display = text.includes(term) ? 'flex' : 'none';
    });
}

function confirmGarments() {
    if (!currentWeightService) return;
    
    const weight = parseFloat(document.getElementById('garmentWeight').value);
    if (!weight || weight <= 0) {
        alert("Please enter a valid weight.");
        document.getElementById('garmentWeight').focus();
        return;
    }
    
    const selectedGarments = [];
    document.querySelectorAll('.garment-item').forEach(itemRow => {
        const countInput = itemRow.querySelector('.garment-count');        
        const count = parseInt(countInput.value);
        if (count > 0) {
            const name = countInput.dataset.name;
            const commentsArray = JSON.parse(itemRow.dataset.comments || '[]');
            const commentString = commentsArray.join(', ');
            
            let garmentString = `${name}-${count}`;
            if (commentString) {
                garmentString += `: ${commentString}`;
            }
            selectedGarments.push(garmentString);
        }
    });
    
    addItem(
        currentWeightService.name,
        currentWeightService.type,
        currentWeightService.service,
        currentWeightService.price,
        weight, // Use the new weight value
        selectedGarments,
        'Kg'
    );
    
    closeGarmentModal();
}

function renderGarmentAlphabet() {
    const bar = document.getElementById('garmentAlphabetBar');
    if (!bar) return;
    bar.innerHTML = '';
    
    const btnStyle = "background:#d0e7ff; border:none; margin:2px; padding:5px 10px; border-radius:4px; cursor:pointer; font-weight:bold; color:#1976d2;";
    
    // All button
    const allBtn = document.createElement("button");
    allBtn.textContent = "All";
    allBtn.style.cssText = btnStyle + "background:#1976d2; color:white;";
    allBtn.onclick = function() { 
        filterGarmentsByLetter('All');
        highlightAlphaBtn(this);
    };
    bar.appendChild(allBtn);

    "ABCDEFGHIJKLMNOPQRSTUVWXYZ".split("").forEach(l => {
        const btn = document.createElement("button");
        btn.textContent = l;
        btn.style.cssText = btnStyle;
        btn.onclick = function() { 
            filterGarmentsByLetter(l);
            highlightAlphaBtn(this);
        };
        bar.appendChild(btn);
    });
}

function highlightAlphaBtn(activeBtn) {
    const bar = document.getElementById('garmentAlphabetBar');
    const btns = bar.getElementsByTagName('button');
    for(let btn of btns) {
        btn.style.background = '#d0e7ff';
        btn.style.color = '#1976d2';
    }
    activeBtn.style.background = '#1976d2';
    activeBtn.style.color = 'white';
}

function filterGarmentsByLetter(letter) {
    const items = document.querySelectorAll('.garment-item');
    items.forEach(item => {
        // The product name is in the first span inside the first div
        const nameDiv = item.children[0];
        const nameSpan = nameDiv.querySelector('span');
        
        if (nameSpan) {
            const text = nameSpan.innerText.toUpperCase();
            if (letter === 'All' || text.startsWith(letter)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        }
    });
}

// --- Item Comments Modal Logic ---
let currentItemCommentsButton = null;
const predefinedComments = ["Hole", "Missing Button", "No Guarantee For Stain", "Bleach Mark", "Colour Stain", "Fragile Garment", "Fungus Stain", "Pressing Mark", "Print Damaged", "Risk Of Damage", "Stich Open", "Thread Loose", "Torn"];

function openItemCommentsModal(element) {
    currentItemCommentsButton = element;
    const modal = document.getElementById('itemCommentsModal');
    const grid = document.getElementById('itemCommentsGrid');
    grid.innerHTML = ''; // Clear previous buttons

    const garmentItemRow = currentItemCommentsButton.closest('.garment-item');
    const currentComments = JSON.parse(garmentItemRow.dataset.comments || '[]');

    // Populate with buttons
    predefinedComments.forEach(comment => {
        const btn = document.createElement('button');
        btn.textContent = comment;
        btn.style.cssText = "padding: 10px; border: 1px solid #ccc; border-radius: 5px; background: #f9f9f9; cursor: pointer; text-align: center;";
        
        if (currentComments.includes(comment)) {
            btn.classList.add('selected');
            btn.style.background = '#d0e7ff';
            btn.style.borderColor = '#1976d2';
        }

        btn.onclick = function() {
            this.classList.toggle('selected');
            if (this.classList.contains('selected')) {
                this.style.background = '#d0e7ff';
                this.style.borderColor = '#1976d2';
            } else {
                this.style.background = '#f9f9f9';
                this.style.borderColor = '#ccc';
            }
        };
        grid.appendChild(btn);
    });

    modal.style.display = 'flex';
}

function closeItemCommentsModal() {
    document.getElementById('itemCommentsModal').style.display = 'none';
    currentItemCommentsButton = null;
}

function saveItemComments() {
    if (!currentItemCommentsButton) return;

    const selectedComments = [];
    document.querySelectorAll('#itemCommentsGrid button.selected').forEach(btn => {
        selectedComments.push(btn.textContent);
    });

    const garmentItemRow = currentItemCommentsButton.closest('.garment-item');
    garmentItemRow.dataset.comments = JSON.stringify(selectedComments);

    const displayDiv = currentItemCommentsButton.querySelector('.item-comments-display');
    if (selectedComments.length > 0) {
        displayDiv.innerHTML = selectedComments.map(c => `<span style="background: #ffebcd; color: #856404; padding: 2px 5px; border-radius: 3px; font-size: 11px; margin:1px;">${c}</span>`).join('');
    } else {
        displayDiv.innerHTML = '<span style="color:#999; font-style:italic; font-size:12px;">Add Comments</span>';
    }

    closeItemCommentsModal();
}

function toggleGarmentDetails(event, listId) {
    event.stopPropagation(); // Prevent click from bubbling up to document
    const list = document.getElementById(listId);
    if (list) {
        // Close all other detail lists first
        document.querySelectorAll('[id^="garment-list-"]').forEach(otherList => {
            if (otherList.id !== listId) {
                otherList.style.display = 'none';
            }
        });
        // Toggle the current one
        list.style.display = list.style.display === 'none' ? 'block' : 'none';
    }
}

// Add a global click listener to close the dropdowns when clicking outside
document.addEventListener('click', function(event) {
    document.querySelectorAll('[id^="garment-list-"]').forEach(list => {
        if (list.style.display === 'block' && !list.contains(event.target) && !list.parentElement.contains(event.target)) {
            list.style.display = 'none';
        }
    });
});

function getKgItemCellHTML(name, type, item_comments, itemId) {
    let html = `${name} (${type})`;
    
    let totalGarments = 0;
    item_comments.forEach(g => {
        let qtyPart = g;
        if (g.includes(':')) {
            qtyPart = g.split(':')[0];
        }
        const lastDash = qtyPart.lastIndexOf('-');
        if (lastDash !== -1) {
             const q = parseInt(qtyPart.substring(lastDash + 1));
             if (!isNaN(q)) totalGarments += q;
        }
    });

    const garmentListId = `garment-list-${itemId}`;
    
    let rowsHTML = '';
    item_comments.forEach((g, index) => {
        let nameTypeQty = g;
        let comments = '-';
        
        const colonIndex = g.indexOf(':');
        if (colonIndex !== -1) {
            nameTypeQty = g.substring(0, colonIndex);
            comments = g.substring(colonIndex + 1).trim();
            if(!comments) comments = '-';
        }
        
        const lastDash = nameTypeQty.lastIndexOf('-');
        let nameType = nameTypeQty;
        let qty = '';
        
        if (lastDash !== -1) {
            nameType = nameTypeQty.substring(0, lastDash);
            qty = nameTypeQty.substring(lastDash + 1);
        }
        
        rowsHTML += `
          <tr>
              <td style="border:1px solid #eee; padding:4px; text-align:center;">${index + 1}</td>
              <td style="border:1px solid #eee; padding:4px;">${nameType}</td>
              <td style="border:1px solid #eee; padding:4px;">${comments}</td>
              <td style="border:1px solid #eee; padding:4px; text-align:center;">${qty}</td>
              <td style="border:1px solid #eee; padding:4px; text-align:center;">
                  <button onclick="removeGarmentFromList(event, '${itemId}', ${index})" style="background:#ff4444; color:white; border:none; border-radius:3px; cursor:pointer; padding:2px 6px; font-size:10px;">❌</button>
              </td>
          </tr>
        `;
    });

    const detailsDropdownHTML = `
        <div style="margin-top: 5px;">
            <span style="font-size: 12px; color: #555; font-weight: bold;">Total Garments: ${totalGarments}</span>
            <div style="display: inline-block; position: relative; margin-left: 10px;">
                <button onclick="toggleGarmentDetails(event, '${garmentListId}')" style="background: #3498db; color: white; border: none; padding: 2px 8px; border-radius: 4px; cursor: pointer; font-size: 12px;">Details</button>
                <div id="${garmentListId}" style="display: none; position: absolute; background: white; border: 1px solid #ccc; padding: 10px; z-index: 10; left: 0; min-width: 450px; text-align: left; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.2);">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                      <thead>
                          <tr style="background:#f0f0f0;">
                              <th style="border:1px solid #eee; padding:4px;">#</th>
                              <th style="border:1px solid #eee; padding:4px;">Product</th>
                              <th style="border:1px solid #eee; padding:4px;">Comments</th>
                              <th style="border:1px solid #eee; padding:4px;">Qty</th>
                              <th style="border:1px solid #eee; padding:4px;">Action</th>
                          </tr>
                      </thead>
                      <tbody>
                          ${rowsHTML || '<tr><td colspan="5" style="text-align:center;">No garments specified.</td></tr>'}
                      </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
    return html + detailsDropdownHTML;
}

function removeGarmentFromList(event, itemId, index) {
    event.stopPropagation();
    const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
    if (!row) return;
    
    let comments = JSON.parse(row.dataset.comments || '[]');
    comments.splice(index, 1);
    row.dataset.comments = JSON.stringify(comments);
    
    const name = row.dataset.itemName;
    const type = row.dataset.itemType;
    
    row.cells[0].innerHTML = getKgItemCellHTML(name, type, comments, itemId);
    
    const list = document.getElementById(`garment-list-${itemId}`);
    if(list) list.style.display = 'block';
}
</script>
</div>

<!-- ये code सिर्फ एडमिन के लिए बटन बंद कर देगा, स्टाफ के लिए नहीं  -->
<?php if($role === 'admin'): ?>
<script>
document.querySelector('.create-btn').disabled = true;
</script>
<?php endif; ?>

<!-- GARMENT SELECTION MODAL -->
<div id="garmentModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10002; justify-content:center; align-items:center;">
    <div style="background:white; width:700px; max-height:85vh; padding:20px; border-radius:10px; display:flex; flex-direction:column; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#1976d2;">Select Garments</h3>
        <input type="text" id="garmentSearch" placeholder="Search garments..." onkeyup="filterGarments()" style="padding:10px; border:1px solid #ccc; border-radius:5px; margin-bottom:10px; width:100%; box-sizing:border-box;">
        <div id="garmentAlphabetBar" style="text-align:center; margin-bottom:10px; overflow-x:auto; white-space:nowrap; padding-bottom:5px; min-height:40px;"></div>
        <div id="garmentList" style="flex:1; overflow-y:auto; border:1px solid #eee; padding:5px; margin-bottom:15px;">
            <!-- Items will be populated here -->
        </div>
        <div style="margin-bottom: 15px; border-top: 1px solid #eee; padding-top: 15px;">
            <label for="garmentWeight" style="font-weight: bold; font-size: 16px;">Total Weight (Kg):</label>
            <input type="number" id="garmentWeight" value="1.0" step="0.1" min="0.1" style="width: 100px; padding: 8px; font-size: 16px; text-align: center; border: 1px solid #1976d2; border-radius: 5px; margin-left: 10px;">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button onclick="closeGarmentModal()" style="background:#666; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Cancel</button>
            <button onclick="confirmGarments()" style="background:#28a745; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Add to Order</button>
        </div>
    </div>
</div>

<!-- ITEM COMMENTS MODAL -->
<div id="itemCommentsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10003; justify-content:center; align-items:center;">
    <div style="background:white; width:450px; max-height:90vh; padding:20px; border-radius:10px; display:flex; flex-direction:column; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#1976d2;">Select Comments</h3>
        <div id="itemCommentsGrid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px; overflow-y: auto;">
            <!-- Predefined comment buttons will be populated here -->
        </div>
        <div style="margin-top: auto; display:flex; justify-content:flex-end; gap:10px; border-top: 1px solid #eee; padding-top: 15px;">
            <button onclick="closeItemCommentsModal()" style="background:#666; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Cancel</button>
            <button onclick="saveItemComments()" style="background:#28a745; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">Save Comments</button>
        </div>
    </div>
</div>
</body>
</html>