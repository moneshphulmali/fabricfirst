<?php
include 'db_connect.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');

session_start();

// ✅ Session check
if(!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store'])) {
    header("Location: index.php");
    exit;
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

if($conn->connect_error) die("DB connection failed: ".$conn->connect_error);


// ================= GET PAYMENTS =================
if(isset($_GET['action']) && $_GET['action']=='get_payments'){
    header('Content-Type: application/json; charset=utf-8');

    $filter = $_GET['filter'] ?? "month";

    if($filter == "today"){
        $dateCondition = "DATE(t.payment_date) = CURDATE()";
    }
    elseif($filter == "7days"){
        $dateCondition = "DATE(t.payment_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    }
    elseif($filter == "all"){
        $dateCondition = "1";
    }
    else{
        $dateCondition = "
            MONTH(t.payment_date) = MONTH(CURDATE()) 
            AND YEAR(t.payment_date) = YEAR(CURDATE())
        ";
    }

    // TOTAL PAID PER ORDER (NO FILTER)
    $paidQuery = $conn->query("
        SELECT order_id, SUM(amount) AS total_paid
        FROM transactions
        GROUP BY order_id
    ");

    $orderPaid = [];
    while($p = $paidQuery->fetch_assoc()){
        $orderPaid[$p['order_id']] = floatval($p['total_paid']);
    }

    // FILTERED DATA
    $result = $conn->query("
        SELECT 
            t.id AS transaction_id,
            o.id AS order_id,
            o.customer_id,
            o.total_amount,
            o.status,
            t.amount AS Paid_Amount,
            t.payment_mode,
            t.payment_date
        FROM transactions t
        INNER JOIN orders o ON o.id = t.order_id
        WHERE o.storeid = $storeid
        AND $dateCondition
        ORDER BY t.payment_date DESC, t.id DESC
    ");

    $payments = [];

    while($row = $result->fetch_assoc()){
        $order_id = $row['order_id'];
        $total_amount = floatval($row['total_amount']);
        $paid_total = $orderPaid[$order_id] ?? 0;

        $row['Paid_Amount'] = floatval($row['Paid_Amount']);
        $row['total_amount'] = $total_amount;
        $row['Due_Amount'] = $total_amount - $paid_total;

        $payments[] = $row;
    }
    
    

    echo json_encode(["payments"=>$payments], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}



// ================= ADD PAYMENT =================
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['order_id'], $_POST['paid_amount'], $_POST['payment_mode'])){

    $order_id = intval($_POST['order_id']);
    $paid_amount = floatval($_POST['paid_amount']);
    $payment_mode = $_POST['payment_mode'];

$res_order = $conn->query("SELECT total_amount FROM orders WHERE id=$order_id AND storeid=$storeid");
    if(!$res_order->num_rows){ echo "❌ Order not found!"; exit; }

    $stmt_trans = $conn->prepare("
        INSERT INTO transactions(storeid, order_id, payment_mode, amount, payment_date)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt_trans->bind_param("iisd", $storeid, $order_id, $payment_mode, $paid_amount);

    if($stmt_trans->execute()){
        echo "✅ Success: Transaction added";
    }else{
        echo "❌ Error: ".$conn->error;
    }

    $conn->close();
    exit;
}
?>


<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<title>Payments Dashboard</title>
<style>
body { font-family: Arial; background: #f6f6f6; padding: 20px; margin:0;}
h2 { color:black;padding:10px;border-radius:8px; display:; justify-content:space-between; align-items:center; }


.summary {background:#00aaff; padding:12px;border-radius:8px;margin-bottom:15px;font-weight:bold; color: white}

.filter-btn{
    margin-right:10px;
    padding:8px 14px;
    border:none;
    border-radius:6px;
    background: #00aaff;
    color:white;
    cursor:pointer;
}
.filter-btn:hover{opacity:0.8; background:#006699;}

.date-row { background:#00aaff;color:white;padding:10px;margin-top:10px;border-radius:6px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
.date-text { color:white;font-weight:bold; }
.arrow { transition: transform 0.3s; }
.arrow.down { transform:rotate(90deg);}
.orders-table { width:100%; border-collapse: collapse; margin-top:5px; background:white;}
.orders-table th, .orders-table td { border:1px solid #ddd; padding:8px; text-align:center;}
.orders-table th { background:#00aaff;color:white; }
.hidden{ display:none;}
</style>
</head>

<body>
<div class="main-content"> 
<?php include 'menu.php'; ?> 
<center>
<h2>
  Payments Summary
</center>
</h2>




<div>
    <button class="filter-btn" onclick="loadPayments('month')">📅 This Month</button>
    <button class="filter-btn" onclick="loadPayments('today')">📌 Today</button>
    <button class="filter-btn" onclick="loadPayments('all')">📂 All Records</button>
	 
</div>

<br>

<div class="summary" id="summary">Loading summary...</div>
<div id="paymentContainer"></div>


<script>
async function loadPayments(filter="month"){
    try{
        const res = await fetch('?action=get_payments&filter='+filter);
        const json = await res.json();
        const payments = json.payments || [];

        if(payments.length===0){
            document.querySelector("#summary").innerText="No data found";
            document.getElementById("paymentContainer").innerHTML="";
            return;
        }

        let orderSummary = {};

        // ORDER WISE TOTALS
        payments.forEach(p=>{
            if(!orderSummary[p.order_id]){
                orderSummary[p.order_id] = {
                    total: p.total_amount,
                    paid: 0
                };
            }
            orderSummary[p.order_id].paid += p.Paid_Amount;
        });

        let grand_total=0, total_paid=0, total_due=0, total_cash=0, total_upi=0, total_card=0;

        Object.values(orderSummary).forEach(o=>{
            grand_total += o.total;
            total_paid += o.paid;
            total_due += (o.total - o.paid);
        });

        // CASH / UPI
       // CASH / UPI / CARD
payments.forEach(p=>{
    const mode = (p.payment_mode || "").toLowerCase();
    if(mode==="cash") total_cash += p.Paid_Amount;
    else if(mode==="upi") total_upi += p.Paid_Amount;
    else if(mode==="card") total_card += p.Paid_Amount;
});


        document.querySelector("#summary").innerHTML=`
   Bill Amount: ₹${grand_total.toFixed(2)} | 
    💰 Cash: ₹${total_cash.toFixed(2)} |
    💳 Card: ₹${total_card.toFixed(2)} |
    📱 UPI: ₹${total_upi.toFixed(2)} |
    ✅ Paid: ₹${total_paid.toFixed(2)} |
`;


        const grouped = {};
        payments.forEach(p=>{
            const dateOnly = (p.payment_date || "").split(" ")[0];
            if(!grouped[dateOnly]) grouped[dateOnly]=[];
            grouped[dateOnly].push(p);
        });

        const container = document.getElementById("paymentContainer");
        container.innerHTML="";

        Object.keys(grouped).forEach(date=>{
            let total=0, paid=0, due=0;
            let ordersSeen = new Set();

            grouped[date].forEach(p=>{
                total += p.total_amount;
                paid += p.Paid_Amount;

                if(!ordersSeen.has(p.order_id)){
                    due += p.Due_Amount;
                    ordersSeen.add(p.order_id);
                }
            });

            const dateDiv = document.createElement("div");
            dateDiv.innerHTML=`
                <div class="date-row" onclick="toggleOrders(this)">
                    <span class="date-text">${date}</span>
                    <span> Paid: ₹${paid.toFixed(2)} | Due: ₹${due.toFixed(2)} <span class="arrow">▶</span></span>
                </div>

                <div class="orders hidden">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer ID</th>
                                <th>Payment Mode</th>
                                <th>Bill Amount (₹)</th>
                                <th>Paid (₹)</th>
                                <th>Due (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${grouped[date].map(p=>`
                                <tr>
                                    <td>${p.order_id}</td>
                                    <td>${p.customer_id}</td>
                                    <td>${p.payment_mode}</td>
                                    <td>${p.total_amount}</td>
                                    <td>${p.Paid_Amount}</td>
                                    <td>${p.Due_Amount}</td>
                                </tr>`).join("")}
                        </tbody>
                    </table>
                </div>
            `;
            container.appendChild(dateDiv);
        });

    }catch(err){
        console.error(err);
        document.querySelector("#summary").innerText="⚠️ Error loading data.";
    }
}

function toggleOrders(el){
    const ordersDiv = el.nextElementSibling;
    const arrow = el.querySelector(".arrow");
    ordersDiv.classList.toggle("hidden");
    arrow.classList.toggle("down");
}

loadPayments(); 
</script>

</div>
</body>
</html>
