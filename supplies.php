<?php
include 'db_connect.php';
session_start();

// ✅ CHANGE 1: Session check
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store'])) {
    header("Location: index.php");
    exit;
}

// ✅ NEW SECURITY CODE - Add HERE (Lines 10-60)
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

$storeid = $current_store_id;  // ✅ Yeh line replace karegi line 13

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($conn->connect_error) {
    die(json_encode(["status"=>"error","message"=>"DB connection failed: ".$conn->connect_error]));
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // 1️⃣ GET SUPPLIES LIST
    if ($action == 'get_supplies') {

        // Category filter
        if (isset($_GET['category'])) {
            $cat = trim($_GET['category']);
            
            // ✅ NO CHANGE HERE - supplies table doesn't have storeid
            if ($cat == 'tagandprinter' || $cat == 'Tag & printer' || $cat == 'Tagandprinter') {
                $sql = "SELECT Name, Type, ServiceType, Price FROM supplies 
                        WHERE category IN ('tagandprinter', 'printandtag') 
                        ORDER BY Name, Type";
            } elseif ($cat == 'chemicals' || $cat == 'Chemical') {
                $sql = "SELECT Name, Type, ServiceType, Price FROM supplies 
                        WHERE category = 'chemicals' 
                        ORDER BY Name, Type";
            } else {
                $sql = "SELECT Name, Type, ServiceType, Price FROM supplies 
                        WHERE category = '$cat' 
                        ORDER BY Name, Type";
            }
        } else {
            $sql = "SELECT Name, Type, ServiceType, Price FROM supplies ORDER BY Name, Type";
        }

        $result = $conn->query($sql);

        $products = [];
        while ($row = $result->fetch_assoc()) {
            $name = $row['Name'];
            $type = $row['Type'];

            if (!isset($products[$name])) $products[$name] = [];
            if (!isset($products[$name][$type])) $products[$name][$type] = [];

            $products[$name][$type][] = [
                "service_type" => $row["ServiceType"],
                "price" => $row["Price"]
            ];
        }

        echo json_encode(["status" => "success", "data" => $products]);
        exit;
    }

    // ---------------------------------------------------
    // 2️⃣ SAVE ORDER - ✅ IMPORTANT CHANGE HERE
    // ---------------------------------------------------
    if ($action == 'save_order') {

        $data = json_decode(file_get_contents("php://input"), true);
        if (!$data) {
            echo json_encode(["status"=>"error","message"=>"No data received"]);
            exit;
        }

        $total_amount = $data["totalAmount"] ?? 0;
        $items = $data["items"] ?? [];

        if (empty($items)) {
            echo json_encode(["status"=>"error","message"=>"No items found"]);
            exit;
        }

        $order_id = time();

        // ✅ CHANGE 3: CORRECT QUERY - storeid column EXISTS
        $stmt_item = $conn->prepare("
            INSERT INTO order_items_supplies (order_id, storeid, item_json)
            VALUES (?, ?, ?)
        ");

        foreach ($items as $item) {
            $json_data = json_encode([[
                "product_name"  => $item["item"],
                "service_type"  => $item["service"],
                "qty"           => $item["qty"],
                "price"         => $item["price"],
                "total_amount"  => ($item["qty"] * $item["price"])
            ]], JSON_UNESCAPED_UNICODE);

            // ✅ CORRECT BINDING - 3 parameters
            $stmt_item->bind_param("iis", $order_id, $storeid, $json_data);
            $stmt_item->execute();
        }

        $stmt_item->close();

        echo json_encode([
            "status" => "success",
            "message" => "Order saved successfully!",
            "order_id" => $order_id
        ]);
        exit;
    }
}
?>


<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supplies</title>

<style>
.active-btn { background:#00aaff !important; color:white !important; }
.type-btn { background:#f1f5ff; border:none; margin:3px; padding:4px 8px; border-radius:4px; font-size:12px; cursor:pointer;}
.type-btn:hover { background:#87ceeb; color:white;}
.services-container { margin-top:5px; padding-left:10px; border-left:2px solid #cce7ff;}
.hidden { display:none;}
body { font-family:Arial,sans-serif; background:#f6f6f6; margin:0; padding:20px;}
.container { display:flex; gap:20px;}
.left,.right { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.1);}
.left { flex:1;}
.right { flex:1; max-height:85vh; overflow-y:auto;}
table { width:100%; border-collapse:collapse; margin-top:10px;}
th, td { border-bottom:1px solid #eee; padding:8px; text-align:center;}
.total-section { margin-top:10px; font-weight:bold;}
.create-btn { background:#00aaff; color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; width:100%; }
.alphabet-bar button {
    background: #d0e7ff;
    border: none;
    margin: 2px;
    padding: 5px 10px;
    border-radius: 4px;
    cursor: pointer;
}

.add-btn {
    background: #87ceeb;
    color: white;
    border: none;
    padding: 6px 10px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 13px;
}
</style>
</head>
<body>
<div class="main-content"> 
<?php include 'menu.php'; ?> 

<h2 style="text-align:center; color: black"> 📦 Supplies </h2>

<div class="container">

<!-- LEFT SIDE -->
<div class="left">

<table id="orderTable">
<thead>
<tr>
<th>Item</th>
<th>Service</th>
<th>Qty</th>
<th>Price</th>
<th>Remove</th>
</tr>
</thead>
<tbody></tbody>
</table>

<div class="total-section">
Total Amount: ₹<span id="totalAmount">0</span>
</div>

<button class="create-btn" onclick="saveOrder()">Create Order</button>
</div>


<!-- RIGHT SIDE -->
<div class="right">
  <input type="text" id="searchInput" placeholder="🔍 Search item..." style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ccc; border-radius:8px; font-size:14px;">
  <div class="alphabet-bar" id="alphabetBar"></div>
  <div id="productList"></div>
</div>

</div>

<script>
let allProducts = {};
const alphabetBar = document.getElementById("alphabetBar");
const productList = document.getElementById("productList");
const orderTable = document.querySelector("#orderTable tbody");
const totalAmountEl = document.getElementById("totalAmount");

let total = 0;

// Load SUPPLIES DATA
fetch("?action=get_supplies")
  .then(r => r.json())
  .then(data => {
    if (data.status === "success") allProducts = data.data;
  });
  
// Category buttons
const categories = ["Chemical", "Boiler", "Dryer", "Iron Table", "Packing", "Tag & printer", "Washer", "Utilities"];

categories.forEach(cat => {
    const btn = document.createElement("button");
    btn.textContent = cat;
    btn.className = "cat-btn";
    
    // ✅ सभी buttons के लिए category mapping
    const categoryMapping = {
        "Chemical": "chemicals",
        "Boiler": "Boiler", 
        "Dryer": "Dryer",
        "Iron Table": "Iron Table",
        "Packing": "Packing",
        "Tag & printer": "tagandprinter",
              
        "Washer": "Washer",
        "Utilities": "Utilities"
    };
    
    // Button click handler
    btn.onclick = function() {
        const categoryParam = categoryMapping[cat];
        loadCategory(categoryParam, this);
    };

    alphabetBar.appendChild(btn);
});

// ✅ Category load function
function loadCategory(cat, buttonElement) {
  fetch("?action=get_supplies&category=" + encodeURIComponent(cat))
    .then(r => r.json())
    .then(data => {
      if (data.status === "success") {
        allProducts = data.data;
        productList.innerHTML = "";
        showAllProducts();
        
        // ✅ Active button highlight
        document.querySelectorAll('.cat-btn').forEach(btn => {
          btn.classList.remove('active-btn');
        });
        if (buttonElement) {
          buttonElement.classList.add('active-btn');
        }
      }
    })
    .catch(error => {
      console.error("Error loading category:", error);
      productList.innerHTML = "<p style='text-align:center;color:red;'>Error loading products</p>";
    });
}

function showAllProducts() {
    productList.innerHTML = "";

    if (Object.keys(allProducts).length === 0) {
        productList.innerHTML = "<p style='text-align:center;color:gray;'>No products found in this category</p>";
        return;
    }

    Object.keys(allProducts).forEach(name => {
        const types = allProducts[name];

        let typeBtns = "";
        for (let type in types) {
            typeBtns += `<button class='type-btn' onclick="showServices('${name.replace(/'/g, "\\'")}','${type.replace(/'/g, "\\'")}')">${type}</button>`;
        }

        const div = document.createElement("div");
        div.className = "product";
        div.innerHTML = `
            <b>${name}</b>
            <div>${typeBtns}</div>
            <div id="${name.replace(/\s+/g,'_').replace(/'/g, '_')}_services" class="services-container hidden"></div>
        `;

        productList.appendChild(div);
    });
}

function showServices(name, type) {
  const containerId = `${name.replace(/\s+/g, '_').replace(/'/g, '_')}_services`;
  const container = document.getElementById(containerId);
  
  // Toggle display
  if (container.classList.contains('hidden')) {
    // Clear and load services
    container.innerHTML = "";

    const services = allProducts[name][type];
    if (services && services.length > 0) {
      services.forEach(s => {
        const div = document.createElement("div");
        div.innerHTML = `
          <div style="margin:4px 0; display:flex; justify-content:space-between; align-items:center;">
            <span>${s.service_type}</span>
            <span>₹${s.price}</span>
            <button class="add-btn" onclick="addItem('${name.replace(/'/g, "\\'")}','${s.service_type.replace(/'/g, "\\'")}',${s.price})">Add</button>
          </div>`;
        container.appendChild(div);
      });
    } else {
      container.innerHTML = "<p style='color:gray;'>No services available</p>";
    }
    
    container.classList.remove('hidden');
  } else {
    container.classList.add('hidden');
  }
}

function addItem(name, service, price) {
  const row = document.createElement("tr");
  row.innerHTML = `
    <td>${name}</td>
    <td>${service}</td>
    <td><input type='number' value='1' min='1' style='width:60px;text-align:center;' onchange='updateQty(this, ${price})'></td>
    <td class='price-cell'>₹${price}</td>
    <td><button onclick="removeItem(this)">❌</button></td>`;
  row.dataset.price = price;
  row.dataset.qty = 1;
  orderTable.appendChild(row);
  total += price;
  totalAmountEl.textContent = total;
}

function updateQty(input, price) {
  const qty = parseInt(input.value) || 1;
  const row = input.closest("tr");
  const prevQty = parseInt(row.dataset.qty);
  const diff = qty - prevQty;
  total += diff * price;
  row.dataset.qty = qty;
  row.querySelector(".price-cell").textContent = `₹${price * qty}`;
  totalAmountEl.textContent = total;
}

function removeItem(btn) {
  const row = btn.closest("tr");
  total -= row.dataset.price * row.dataset.qty;
  row.remove();
  totalAmountEl.textContent = total;
}


function saveOrder() {

  const items = [];
  document.querySelectorAll("#orderTable tbody tr").forEach(row => {
    const cols = row.querySelectorAll("td");
    items.push({
      item: cols[0].innerText,
      service: cols[1].innerText,
      qty: parseInt(row.dataset.qty),
      price: parseFloat(row.dataset.price)
    });
  });

  if (items.length === 0) {
    alert("⚠️ Please add at least one item.");
    return;
  }

  fetch("?action=save_order", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ totalAmount: total, items })
  })
  .then(r => r.json())
  .then(res => {
    if (res.status === "success") {
        // 👉 Popup मे Order ID show करो
        document.getElementById("popupOrderId").innerText = res.order_id;

        // 👉 Popup box दिखाओ
        document.getElementById("orderPopup").style.display = "flex";

    } else {
      alert("❌ Error: " + res.message);
    }
  })
  .catch(error => {
    alert("❌ Network error: " + error);
  });
}

</script>




<!-- POPUP BOX -->
<div id="orderPopup" style="
    position: fixed; top:0; left:0; width:100%; height:100%;
    background: rgba(0,0,0,0.5); display:none; 
    align-items:center; justify-content:center; z-index:9999;
">
  <div style="
      background:white; padding:30px; border-radius:12px; width:300px;
      text-align:center; box-shadow:0 4px 20px rgba(0,0,0,0.3);
  ">
      <h3>🎉 Order Created!</h3>
      <p>Order ID: <b id="popupOrderId"></b></p>

      <button onclick="viewInvoice()" style="
          background:#1976d2; color:white; border:none; padding:10px 20px;
          border-radius:8px; cursor:pointer;
      ">View Invoice</button>

      <br><br>
      <button onclick="closePopup()" style="
          background:gray; color:white; border:none; padding:6px 15px;
          border-radius:6px; cursor:pointer;
      ">Close</button>
  </div>
</div>

<script>
function closePopup() {
    document.getElementById("orderPopup").style.display = "none";
}

function viewInvoice() {
    const orderId = document.getElementById("popupOrderId").innerText;

    fetch("view_invoice_api.php?order_id=" + orderId)
    .then(r => r.json())
    .then(res => {

        if (res.status !== "success") {
            alert("❌ Invoice loading failed");
            return;
        }

        let items = res.items;
        let orderDate = res.order_date;

        let html = `
        <html>
        <head>
        <title>Invoice ${orderId}</title>
        <style>
            body { font-family: Arial; padding:20px; }
            h2 { text-align:center; color:#1976d2; }
            table { width:100%; border-collapse:collapse; margin-top:20px; }
            th, td { border:1px solid #ddd; padding:8px; text-align:center; }
            th { background:#00aaff; color:white; }
        </style>
        </head>
        <body>

        <h2>Invoice</h2>
        <p><b>Order ID:</b> ${orderId}</p>
        <p><b>Date:</b> ${orderDate}</p>

        <table>
            <tr>
                <th>S.No</th>
        <th>Product</th>
        <th>Service</th>
        <th>Qty</th>
        <th>Price</th>
        <th>Total</th>
            </tr>`;
        
        let totalAmount = 0;

       
let sno = 1;  // <-- ADD THIS

items.forEach(item => {
    html += `
    <tr>
        <td>${sno}</td>
        <td>${item.product_name}</td>
        <td>${item.service_type}</td>
        <td>${item.qty}</td>
        <td>${item.price}</td>
        <td>${item.total_amount}</td>
    </tr>`;
    sno++;   // increase counter
});


        html += `
            <tr>
                <td colspan="4"><b>Grand Total</b></td>
                <td><b>${totalAmount.toFixed(2)}</b></td>
            </tr>
        </table>

        <br><center>
        <button onclick="window.print()">🖨 Print Invoice</button>
        </center>

        </body>
        </html>
        `;

        let win = window.open("", "_blank", "width=800,height=600");
        win.document.write(html);
    });
}

</script>



<!-- INVOICE POPUP -->
<div id="invoicePopup" style="
    position: fixed; top:0; left:0; width:100%; height:100%;
    background: rgba(0,0,0,0.6); display:none;
    align-items:center; justify-content:center; z-index:99999;">
    
    <div style="
        width: 420px; background:white; border-radius:12px;
        padding:20px; box-shadow:0 4px 20px rgba(0,0,0,0.4);
        max-height:90vh; overflow-y:auto;
    ">

        <h2 style="text-align:center; margin-top:0;">🧾 Invoice</h2>

        <p><b>Order ID:</b> <span id="invOrderId"></span></p>
        <p><b>Date:</b> <span id="invDate"></span></p>

        <table style="width:100%; border-collapse:collapse; margin-top:10px;">
            <thead>
                <tr>
                    <th style="border-bottom:1px solid #ccc; padding:6px;">Item</th>
                    <th style="border-bottom:1px solid #ccc; padding:6px;">Service</th>
                    <th style="border-bottom:1px solid #ccc; padding:6px;">Qty</th>
                    <th style="border-bottom:1px solid #ccc; padding:6px;">Total</th>
                </tr>
            </thead>
            <tbody id="invTable"></tbody>
        </table>

        <h3 style="text-align:right; padding-right:10px; margin-top:10px;">
            Grand Total: ₹<span id="invTotal"></span>
        </h3>

        <br>
        <button onclick="window.print()" style="
            width:48%; background:#1976d2; padding:10px;
            border:none; color:white; border-radius:6px; cursor:pointer;">
            Print
        </button>

        <button onclick="closeInvoice()" style="
            width:48%; background:#444; padding:10px;
            border:none; color:white; border-radius:6px; cursor:pointer;">
            Close
        </button>
    </div>
</div>
<script>
function closeInvoice() {
    document.getElementById("invoicePopup").style.display = "none";
}
</script>

</div>
</body>
</html>