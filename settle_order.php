<?php
include 'db_connect.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// ✅ CHANGE 1: Ensure user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store'])) {
    die(json_encode(["error" => "❌ Login required"]));
}

// ✅ CHANGE 2: Store ID access
$storeid = $_SESSION['user']['current_store']['storeid'];
$is_admin = $_SESSION['user']['is_admin'] ?? false;

// ✅ FIX: If Admin, fetch the correct store ID for the requested order
if ($is_admin && isset($_REQUEST['order_id'])) {
    $req_order_id = intval($_REQUEST['order_id']);
    $chk_store = $conn->prepare("SELECT storeid FROM orders WHERE id = ?");
    if ($chk_store) {
        $chk_store->bind_param("i", $req_order_id);
        $chk_store->execute();
        $res_store = $chk_store->get_result();
        if ($row_store = $res_store->fetch_assoc()) {
            $storeid = $row_store['storeid'];
        }
        $chk_store->close();
    }
}

if ($conn->connect_error) die(json_encode(["error" => "❌ Database connection failed"]));

header("Access-Control-Allow-Origin: *");

// ===================== GET SINGLE ORDER =====================
if (isset($_GET['action']) && $_GET['action'] === 'get_single_order' && isset($_GET['order_id'])) {
    header("Content-Type: application/json");

    $order_id = intval($_GET['order_id']);

    $stmt = $conn->prepare("
        SELECT 
            o.id, o.customer_id, o.total_amount, o.payable_amount, o.payment_status,
            COALESCE(p.Paid_Amount,0) AS paid_amount,
            COALESCE(p.amount,0) AS payment_amount,
            (o.payable_amount - COALESCE(p.Paid_Amount,0)) AS due_amount
        FROM orders o
        LEFT JOIN payments p ON o.id = p.order_id AND p.storeid = ?
        WHERE o.id = ? AND o.storeid = ?
        LIMIT 1
    ");
    $stmt->bind_param("iii", $storeid, $order_id, $storeid);
    $stmt->execute();
    $result = $stmt->get_result();

    echo $result->num_rows ? json_encode($result->fetch_assoc(), JSON_UNESCAPED_UNICODE) 
                           : json_encode(["error" => "❌ Order not found"]);
    exit;
}

// ===================== INSERT / UPDATE PAYMENT =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['paid_amount'], $_POST['due_amount'], $_POST['payment_mode'])) {
    header("Content-Type: text/plain");

    $order_id     = intval($_POST['order_id']);
    $input        = floatval($_POST['paid_amount']);
    $due_amount   = floatval($_POST['due_amount']);
    $payment_mode = $_POST['payment_mode'];

    // Order check
    $stmt_check = $conn->prepare("SELECT id, payable_amount, customer_id FROM orders WHERE id=? AND storeid=?");
    $stmt_check->bind_param("ii", $order_id, $storeid);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    if ($res_check->num_rows === 0) {
        echo "❌ Order not found or unauthorized!";
        exit;
    }
    $order_data = $res_check->fetch_assoc();
    $payable_amount = floatval($order_data['payable_amount']);
    $customer_id = intval($order_data['customer_id']);

    // Payment check
    $stmt = $conn->prepare("SELECT id, Paid_Amount, amount FROM payments WHERE order_id=? AND storeid=?");
    $stmt->bind_param("ii", $order_id, $storeid);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        // UPDATE EXISTING PAYMENT
        $row = $res->fetch_assoc();
        $total_paid = $row['Paid_Amount'] + $input;
        $new_amount = $row['amount'] + $input;

        $stmt_update = $conn->prepare("
            UPDATE payments
            SET 
                Paid_Amount=?, 
                Due_Amount=?, 
                amount=?,
                payment_mode=?, 
                payment_date = NOW()
            WHERE order_id=? AND storeid=?
        ");
        $stmt_update->bind_param("dddssi", $total_paid, $due_amount, $new_amount, $payment_mode, $order_id, $storeid);
        $ok = $stmt_update->execute();
    } else {
        // INSERT NEW PAYMENT
        $stmt_insert = $conn->prepare("
            INSERT INTO payments (storeid, order_id, Paid_Amount, Due_Amount, amount, payment_mode, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt_insert->bind_param("iiddds", $storeid, $order_id, $input, $due_amount, $input, $payment_mode);
        $ok = $stmt_insert->execute();
    }

    // UPDATE PAYMENT STATUS
    if ($ok) {
        $new_total_paid = isset($total_paid) ? $total_paid : $input;
        
        if ($due_amount <= 0) {
            $payment_status = 'Paid';
        } elseif ($new_total_paid > 0 && $new_total_paid < $payable_amount) {
            $payment_status = 'Partial';
        } else {
            $payment_status = 'Due';
        }

        // Update orders table
        $stmt_update_order = $conn->prepare("
            UPDATE orders 
            SET payment_status = ?
            WHERE id = ? AND storeid = ?
        ");
        $stmt_update_order->bind_param("sii", $payment_status, $order_id, $storeid);
        $stmt_update_order->execute();

        // ✅ CHANGE 3: Update order_item table - FIXED
        // Check if order_item table has storeid column
        $stmt_update_order_item = $conn->prepare("
            UPDATE order_item 
            SET payment_status = ?,
                payment_status_updated_at = NOW()
            WHERE order_id = ?
            AND EXISTS (SELECT 1 FROM orders WHERE orders.id = order_item.order_id AND orders.storeid = ?)
        ");
        $stmt_update_order_item->bind_param("sii", $payment_status, $order_id, $storeid);
        $stmt_update_order_item->execute();

        // Insert into transactions
        $stmt_trans = $conn->prepare("
            INSERT INTO transactions (storeid, order_id, payment_mode, amount, payment_date)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt_trans->bind_param("iisd", $storeid, $order_id, $payment_mode, $input);
        $stmt_trans->execute();

        echo "✅ Payment successful! Status: " . $payment_status;
    } else {
        echo "❌ Database error!";
    }

    exit;
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settle Order</title>
<style>
body {font-family: Arial; background: #f4f6f8; display: flex; justify-content: center; align-items: center; height: 100vh;}
.card {background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 350px;}
h2 {text-align: center; background: #00aaff; color: white; padding: 10px; border-radius: 8px;}
label {display:block; margin-top:15px; font-weight:bold;}
input, select {width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;}
button {margin-top: 20px; width: 100%; padding: 10px; background: #00aaff; color: white; font-size: 16px; border: none; border-radius: 6px; cursor: pointer;}
button:hover {background: green;}
.amount-box {background: #f1f1f1; padding: 10px; border-radius: 6px; margin-top: 10px; text-align: center;}
.amount-box span {display:block;}
</style>
</head>

<body>
<div class="card">
  <h2>💰 Settle Order</h2>
  <div id="orderInfo">Loading order details...</div>
</div>

<script>
const order_id = new URLSearchParams(window.location.search).get("order_id");

if (!order_id) {
  document.getElementById("orderInfo").innerHTML = "❌ Invalid order!";
} else {
  loadOrder(order_id);
}

function loadOrder(order_id) {
  fetch(`settle_order.php?action=get_single_order&order_id=${order_id}`)
    .then(res => res.json())
    .then(order => {

      if (!order || order.error) {
        document.getElementById("orderInfo").innerHTML = "❌ Order not found!";
        return;
      }

      const payable = parseFloat(order.payable_amount || 0);
      const paid  = parseFloat(order.paid_amount || 0);
      const due   = (payable - paid).toFixed(2);

      document.getElementById("orderInfo").innerHTML = `
        <div class="amount-box">
          <span><b>Order ID:</b> ${order.id}</span>
          <span><b>Current Status:</b> ${order.payment_status || 'Due'}</span>
          <span><b>Payable Amount:</b> ₹${payable}</span>
          <span style="color:green;"><b>Paid:</b> ₹${paid}</span>
          <span style="color:red;"><b>Due:</b> ₹${due}</span>
        </div>

        <label>Enter amount:</label>
        <input type="number" id="paidInput" placeholder="₹ Enter amount..." step="0.01" min="0" value="${due}" max="${due}">

        <label>Payment Mode</label>
        <select id="paymentMode">
          <option value="">-- Select --</option>
          <option value="Cash">💵 Cash</option>
          <option value="UPI">📱 UPI</option>
          <option value="Card">💳 Card</option>
          <option value="Prepaid"> ✔️ Prepaid</option>
          <option value="other">🧮 Other Payment</option>
        </select>

        <button onclick="savePayment(${order.id}, ${payable}, ${paid})">💾 Save Payment</button>
      `;
    });
}

function savePayment(order_id, payable, oldPaid) {
  const input = parseFloat(document.getElementById("paidInput").value || 0);
  const mode  = document.getElementById("paymentMode").value;

  if (input <= 0) return alert("⚠️ Please enter amount!");
  if (!mode) return alert("⚠️ Please select payment mode!");

  const finalPaid = oldPaid + input;
  const due = (payable - finalPaid).toFixed(2);

  const data = new URLSearchParams();
  data.append("order_id", order_id);
  data.append("paid_amount", input);
  data.append("due_amount", due);
  data.append("payment_mode", mode);

  fetch("settle_order.php", {
    method: "POST",
    headers: {"Content-Type": "application/x-www-form-urlencoded"},
    body: data.toString()
  })
  .then(res => res.text())
  .then(res => {
    if (res.includes("✅") || res.includes("Success")) {
      alert(res);
      window.location.href = "bbtocc.php";
    } else {
      alert("❌ " + res);
    }
  });
}
</script>

</body>
</html>
