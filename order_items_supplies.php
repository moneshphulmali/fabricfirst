<?php
include 'db_connect.php';
session_start(); // ✅ Session start karo

// Check if user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store'])) {
    die("User not logged in");
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

// ✅ QUERY 1: Check ADMIN role
$admin_check = $conn->prepare("
    SELECT 1 
    FROM store_user_roles sur
    JOIN roles r ON sur.role_id = r.role_id
    WHERE sur.user_id = ? 
    AND sur.storeid = ?
    AND r.role_name = 'admin'
    LIMIT 1
");
$admin_check->bind_param("ii", $user_id, $current_store_id);
$admin_check->execute();
$admin_result = $admin_check->get_result();
$is_admin = ($admin_result->num_rows > 0);
$admin_check->close();

// ✅ QUERY 2: Check STORE MANAGER role
$manager_check = $conn->prepare("
    SELECT 1 
    FROM store_user_roles sur
    JOIN roles r ON sur.role_id = r.role_id
    WHERE sur.user_id = ? 
    AND sur.storeid = ?
    AND r.role_name = 'store_manager'
    LIMIT 1
");
$manager_check->bind_param("ii", $user_id, $current_store_id);
$manager_check->execute();
$manager_result = $manager_check->get_result();
$is_manager = ($manager_result->num_rows > 0);
$manager_check->close();

// ✅ Check if user is NEITHER admin NOR store_manager
if (!$is_admin && !$is_manager) {
    die("❌ Access denied. You must be an Admin or Store Manager to access this page.");
}

// ✅ Store role information in session (optional)
$_SESSION['user']['roles'] = [
    'is_admin' => $is_admin,
    'is_manager' => $is_manager
];

$storeid = $current_store_id;

// ✅ SECURE SQL Query (Prepared Statement)
$stmt = $conn->prepare("SELECT id, order_id, item_json, DATE(created_at) as order_date 
        FROM order_items_supplies 
        WHERE storeid = ?
        ORDER BY order_id");
$stmt->bind_param("i", $storeid);
$stmt->execute();
$result = $stmt->get_result();

// Collect orders and merge items with same order_id
$orders = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $order_id = $row['order_id'];
        $items = json_decode($row['item_json'], true);

        if (!isset($orders[$order_id])) {
            $orders[$order_id] = [
                'order_date' => $row['order_date'],
                'items' => $items
            ];
        } else {
            // Merge items if same order_id appears multiple times
            $orders[$order_id]['items'] = array_merge($orders[$order_id]['items'], $items);
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Orders</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f6f6f6; margin:0; padding:20px; }
        table { border-collapse: collapse; width: 80%; margin: auto; background: #fff; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        th { background-color: #00aaff; color: white; }
        button { padding: 5px 10px; cursor: pointer; }
        h3 { text-align: center; }
    </style>
    <script>
        function viewInvoice(items, orderId, orderDate) {
            let html = `
            <html>
            <head>
            <title>Invoice ${orderId}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                h2 { text-align: center; color: #1976d2; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
                th { background-color: #00aaff; color: white; }
                tfoot td { font-weight: bold; }
            </style>
            </head>
            <body>
            <h2>Invoice</h2>
            <p><strong>Order ID:</strong> ${orderId}</p>
            <p><strong>Order Date:</strong> ${orderDate}</p>

            <table>
                <tr>
                    <th>S.No</th>
                    <th>Product</th>
                    <th>Service Type</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>`;
                
            let totalAmount = 0;
            let sno = 1;

            items.forEach(item => {
                html += `<tr>
                    <td>${sno}</td>
                    <td>${item.product_name}</td>
                    <td>${item.service_type}</td>
                    <td>${item.qty}</td>
                    <td>${item.price}</td>
                    <td>${item.total_amount}</td>
                </tr>`;
                sno++;
                totalAmount += parseFloat(item.total_amount);
            });

            html += `
                <tfoot>
                    <tr>
                        <td colspan="5">Grand Total</td>
                        <td>${totalAmount.toFixed(2)}</td>
                    </tr>
                </tfoot>
            </table><br><br>

            <center><button onclick="window.print()">🖨 Print Invoice</button></center>
            </body>
            </html>`;

            const invoiceWindow = window.open('', '_blank', 'width=800,height=600');
            invoiceWindow.document.write(html);
        }
    </script>
</head>
<body>

<?php include 'menu.php'; ?> 
<div class="main-content"> 
<h2 style="text-align:center; color:black;">Orders Supplies</h2>
<table>
    <tr>
        <th>S.No</th>
        <th>Order ID</th>
        <th>Total Amount</th>
        <th>Order Date</th>
        <th>Action</th>
    </tr>
    <?php
    if (!empty($orders)) {
        $sno = 1;

        foreach ($orders as $order_id => $data) {
            $total_amount = array_sum(array_column($data['items'], 'total_amount'));
            $item_json = htmlspecialchars(json_encode($data['items']), ENT_QUOTES);

            echo "<tr>
                <td>{$sno}</td>
                <td>{$order_id}</td>
                <td>{$total_amount}</td>
                <td>{$data['order_date']}</td>
                <td>
                    <button onclick='viewInvoice({$item_json}, `{$order_id}`, `{$data['order_date']}`)'>
                        View Invoice
                    </button>
                </td>
            </tr>";

            $sno++;
        }
    } else {
        echo "<tr><td colspan='5'>No orders found</td></tr>";
    }
    ?>
</table>
</div>
</body>
</html>