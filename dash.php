<?php
/*
- Session aur Authentication Check
- Session shuru karna
- Agar user logged in nahi hai ya current store set nahi hai, to login page pe redirect karna
- Session ek temporary storage hai jo server pe user-specific
 data store karta hai jab tak user website use kar raha hota hai.
*/
session_start();
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store'])) {
    header("Location: index.php");
    exit;
}

include 'db_connect.php'; // Database connection include karna

/* ✅ Check user ID
Session se user ID nikalna (do possible fields check karna)
Agar user ID nahi mila to error show karna*/

if (isset($_SESSION['user']['id'])) {
    $user_id = intval($_SESSION['user']['id']);
} elseif (isset($_SESSION['user']['user_id'])) {
    $user_id = intval($_SESSION['user']['user_id']);
} else {
    die("❌ User ID not found in session. Please login again.");
}

//Current Store ID - Session se current store ki ID nikalna 
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

/*Summary: Ye code ek secure tarike se database se check karta hai ki current user
 current store ka manager hai ya nahi, aur result ek boolean variable
 mein store karta hai.*/
$manager_check->bind_param("ii", $user_id, $current_store_id); // SQL Injection impossible! and Data automatically sanitized
$manager_check->execute();
$manager_result = $manager_check->get_result();
$is_manager = ($manager_result->num_rows > 0);
$manager_check->close();

// ✅ Check if user is NEITHER admin NOR store_manager
if (!$is_admin && !$is_manager) {
    die("❌ Access denied. You must be an Admin or Store Manager to access this page.");
}

// ✅ Store role information in session (optional)
/*  User ki role information session mein save karna taaki baar baar database query na karna pade*/
$_SESSION['user']['roles'] = [
    'is_admin' => $is_admin,
    'is_manager' => $is_manager
];

$user = $_SESSION['user'];
$storeid = $current_store_id;
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fabrico Laundry Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Roboto', sans-serif; background: #f6f6f6; margin: 0; }

  header { background: white; padding: 12px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
  
  .filters {
    display: flex; align-items: center; gap: 10px;
    background: white; padding: 10px 20px; 
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
  }

  input[type="date"], input[type="text"] {
    padding: 6px; border-radius: 6px; border: 1px solid #ccc;
  }

  .table-container {
    overflow-x: auto; overflow-y: hidden;
    white-space: nowrap; margin: 20px; background: white;
    border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }

  table { width: 100%; border-collapse: collapse; text-align: center; }
  th, td { padding: 6px 8px; border: 1px solid #ccc; font-size: 13px; white-space: nowrap; }
  th { color: white; }
  .header-blue { background: #0d47a1; }
  .header-purple { background: #4a148c; }
  .header-black { background: #ffc100; }
  .header-green { background: #1b5e20; color: white; }

  tr:nth-child(even) td { background: #f9f9f9; }
  .checkmark { color: green; font-weight: bold; }

  .status-btn {
    background: #0288d1; color: white; border: none;
    padding: 3px 8px; border-radius: 4px; cursor: pointer; font-size: 11px;
  }
  .status-btn:hover { background: #01579b; }
</style>
</head>
<body>

<?php include 'menu.php'; ?> 
<div class="main-content"> 

<div class="filters">
  <label><strong>Select Date:</strong></label>
  <input type="date" id="filterDate" onchange="filterByDate()">
  
  <label><strong>Search:</strong></label>
  <input type="text" id="searchInput" onkeyup="searchOrders()" placeholder="Order ID, Name, or Phone">
</div>

<div class="table-container">
  <table>
    <thead>
      <tr id="dateHeader"></tr>
      <tr id="totalHeader"></tr>
      <tr id="processingHeader"></tr>
      <tr id="readyHeader"></tr>
    </thead>
    <tbody id="ordersBody"></tbody>
  </table>
</div>

<script>
let allOrders = []; // सभी orders को स्टोर करेगा

async function loadOrders() {
  // ✅ OPTION 1: Use bbtocc.php API
  const res = await fetch("bbtocc.php?action=get_orders");
  
  // ✅ OPTION 2: Use existing getget_orders.php (if it exists)
  // const res = await fetch("getget_orders.php?storeid=<?= $storeid ?>");
  
  if (!res.ok) {
    alert("Error loading orders. Please check console.");
    console.error("API Error:", res.status);
    return;
  }
  
  allOrders = await res.json();
  renderTable(allOrders);
}

function groupByDate(orders) {
  const grouped = {};
  orders.forEach(o => {
    if (!grouped[o.delivery_date]) grouped[o.delivery_date] = [];
    grouped[o.delivery_date].push(o);
  });
  return grouped;
}

function renderTable(orders) {
  const grouped = groupByDate(orders);
  const dates = Object.keys(grouped).sort().reverse();

  const dateHeader = document.getElementById("dateHeader");
  const totalHeader = document.getElementById("totalHeader");
  const processingHeader = document.getElementById("processingHeader");
  const readyHeader = document.getElementById("readyHeader");
  const ordersBody = document.getElementById("ordersBody");

  dateHeader.innerHTML = `<th class="header-blue">Delivery Date</th>`;
  totalHeader.innerHTML = `<th class="header-purple">Total</th>`;
  processingHeader.innerHTML = `<th class="header-black">Processing</th>`;
  readyHeader.innerHTML = `<th class="header-green">Ready</th>`;

  dates.forEach(date => {
    const all = grouped[date];
    const total = all.length;
    const proc = all.filter(o => o.status === "Processing").length;
    const ready = all.filter(o => o.status === "Ready").length;

    dateHeader.innerHTML += `<th class='header-blue'>${new Date(date).toDateString()}</th>`;
    totalHeader.innerHTML += `<th class='header-purple'>${total} Orders</th>`;
    processingHeader.innerHTML += `<th class='header-black'>${proc}</th>`;
    readyHeader.innerHTML += `<th class='header-green'>${ready}</th>`;
  });

  const maxRows = Math.max(...Object.values(grouped).map(a => a.length));
  ordersBody.innerHTML = "";

  for (let i = 0; i < maxRows; i++) {
    const tr = document.createElement("tr");
    const first = document.createElement("td");
    first.textContent = i === 0 ? "DETAIL" : "";
    tr.appendChild(first);

    dates.forEach(date => {
      const items = grouped[date];
      const item = items[i];
      const td = document.createElement("td");

      if (item) {
        let changeButton = "";
        if (item.status !== "Ready" && item.status !== "Delivered") {
          changeButton = `<button class="status-btn" id="btn-${item.id}" onclick="updateStatus(${item.id})">Change</button>`;
        }

        // ✅ अब customer info एक ही लाइन में
        td.innerHTML = `
          ${i + 1}. ${item.id} - ₹${item.total_amount} - ${item.delivery_slot || ''} | 
          ${item.customer_name} 📞 <span style="color:#555;">${item.customer_phone || ""}</span>
          <span class="checkmark">${item.status === "Ready" ? "✔" : ""}</span>
          ${changeButton}
        `;
      } else {
        td.innerHTML = "&nbsp;";
      }
      tr.appendChild(td);
    });
    ordersBody.appendChild(tr);
  }
}

// ✅ Date filter
function filterByDate() {
  const selectedDate = document.getElementById("filterDate").value;
  if (!selectedDate) {
    renderTable(allOrders);
    return;
  }
  const filtered = allOrders.filter(o => o.delivery_date === selectedDate);
  renderTable(filtered);
}

// ✅ Search filter (Order ID, Name, Phone)
function searchOrders() {
  const term = document.getElementById("searchInput").value.toLowerCase();
  const filtered = allOrders.filter(o =>
    (o.id && o.id.toString().includes(term)) ||
    (o.customer_name && o.customer_name.toLowerCase().includes(term)) ||
    (o.customer_phone && o.customer_phone.toString().includes(term))
  );
  renderTable(filtered);
}

// ✅ Status update
async function updateStatus(id) {
  if (!confirm("Change order status?")) return;

  const btn = document.getElementById(`btn-${id}`);
  if (btn) btn.style.display = "none";

  // ✅ UPDATED API CALL
  const res = await fetch("bbtocc.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `order_id=${id}&status=Ready`
  });
  
  const text = await res.text();
  alert(text);

  setTimeout(loadOrders, 800);
}

loadOrders();
</script>
</div>
</body>
</html>