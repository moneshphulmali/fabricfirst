<?php
include 'db_connect.php';
session_start();

// ✅ Check if user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store'])) {
    header("Location: index.php");
    exit;
}

// ✅ Get User's Admin Stores
$user_stores = $_SESSION['user']['stores'] ?? [];
$admin_stores = [];

// Filter stores where user is admin
foreach ($user_stores as $s) {
    if (isset($s['role_name']) && strtolower($s['role_name']) === 'admin') {
        $admin_stores[$s['storeid']] = $s;
    }
}

// Fallback: If session stores empty but user is marked as admin in current session
if (empty($admin_stores) && isset($_SESSION['user']['is_admin']) && $_SESSION['user']['is_admin']) {
    $admin_stores[$_SESSION['user']['current_store']['storeid']] = $_SESSION['user']['current_store'];
}

// Determine which store to show
$current_session_store_id = $_SESSION['user']['current_store']['storeid'];
$selected_store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : $current_session_store_id;

// Security check: Is the selected store in the user's admin list?
if (!empty($admin_stores)) {
    if (!array_key_exists($selected_store_id, $admin_stores)) {
        // If selected store is not allowed, default to current store if allowed, else first allowed
        if (array_key_exists($current_session_store_id, $admin_stores)) {
            $selected_store_id = $current_session_store_id;
        } else {
            $selected_store_id = array_key_first($admin_stores);
        }
    }
} else {
    $selected_store_id = $current_session_store_id;
}

$storeid = $selected_store_id;

// ✅ Handle Price Update (AJAX Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_price') {
    $id = intval($_POST['id']);
    $new_price = floatval($_POST['price']);
    $post_store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : $storeid;
    
    // Security check: Ensure the price belongs to the target store and user has access
    if (!empty($admin_stores) && !array_key_exists($post_store_id, $admin_stores)) {
        echo "error: unauthorized";
        exit;
    }
    
    $stmt = $conn->prepare("UPDATE price SET price = ? WHERE id = ? AND storeid = ?");
    $stmt->bind_param("dii", $new_price, $id, $post_store_id);
    
    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
    exit;
}

// ✅ Fetch Prices for the current store
$sql = "SELECT id, product_name, product_type, service_type, price FROM price WHERE storeid = ? ORDER BY product_name, service_type";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $storeid);
$stmt->execute();
$result = $stmt->get_result();
$prices = [];
while ($row = $result->fetch_assoc()) {
    $prices[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Store - Manage Prices</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f6f6f6; margin: 0; padding: 0; }
        .container { max-width: 1000px; margin: 30px auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #333; margin-bottom: 20px; }
        
        .search-box { 
            width: 100%; 
            padding: 12px; 
            margin-bottom: 20px; 
            border: 1px solid #ddd; 
            border-radius: 6px; 
            box-sizing: border-box; 
            font-size: 16px;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; border-bottom: 1px solid #eee; text-align: left; }
        th { background-color: #00aaff; color: white; font-weight: 600; }
        tr:hover { background-color: #f9f9f9; }
        
        input[type="number"] { 
            padding: 8px; 
            width: 100px; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
            font-size: 14px;
        }
        
        .update-btn { 
            background-color: #28a745; 
            color: white; 
            border: none; 
            padding: 8px 15px; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 14px;
            transition: background 0.3s;
        }
        .update-btn:hover { background-color: #218838; }
        
        .status-msg { 
            position: fixed; 
            bottom: 20px; 
            right: 20px; 
            padding: 12px 25px; 
            border-radius: 5px; 
            color: white; 
            display: none; 
            font-weight: bold;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        .success { background-color: #28a745; }
        .error { background-color: #dc3545; }
    </style>
</head>
<style>
    .alphabet-filter {
        text-align: center;
        margin-bottom: 20px;
    }
    .alphabet-filter button {
        background-color: #e9ecef;
        color: #495057;
        border: 1px solid #ced4da;
        padding: 5px 10px;
        margin: 2px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
        transition: background-color 0.3s, color 0.3s;
    }
    .alphabet-filter button:hover, .alphabet-filter button.active {
        background-color: #00aaff;
        color: white;
        border-color: #00aaff;
    }
</style>
<body>

<div class="main-content">
    <?php include 'menu.php'; ?>

    <div class="container">
        <h2>💰 Manage Store Prices</h2>
        
        <!-- Store Selector for Admins -->
        <?php if (count($admin_stores) > 1): ?>
        <div style="margin-bottom: 20px; text-align: center;">
            <label for="storeSelector" style="font-weight: bold; margin-right: 10px;">Select Store:</label>
            <select id="storeSelector" onchange="window.location.href='?store_id='+this.value" style="padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                <?php foreach ($admin_stores as $s_id => $s_data): ?>
                    <option value="<?= $s_id ?>" <?= $s_id == $storeid ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s_data['store_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif (!empty($admin_stores)): ?>
            <div style="text-align:center; margin-bottom:15px; color:#666;">
                Store: <strong><?= htmlspecialchars($admin_stores[$storeid]['store_name'] ?? '') ?></strong>
            </div>
        <?php endif; ?>
        
        <input type="text" id="searchInput" class="search-box" placeholder="🔍 Search for product, type or service..." onkeyup="applyFilters()">
        
        <div id="alphabetFilter" class="alphabet-filter"></div>
        
        <div style="overflow-x: auto;">
            <table id="priceTable">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Type</th>
                        <th>Service</th>
                        <th>Price (₹)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($prices) > 0): ?>
                        <?php foreach ($prices as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['product_name']) ?></td>
                            <td><?= htmlspecialchars($p['product_type']) ?></td>
                            <td><?= htmlspecialchars($p['service_type']) ?></td>
                            <td>
                                <input type="number" id="price_<?= $p['id'] ?>" value="<?= intval($p['price']) ?>" step="1">
                            </td>
                            <td>
                                <button class="update-btn" onclick="updatePrice(<?= $p['id'] ?>)">Update</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center;">No prices found for this store.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="statusMsg" class="status-msg"></div>

<script>
function updatePrice(id) {
    const priceInput = document.getElementById('price_' + id);
    const newPrice = priceInput.value;
    
    if(newPrice === '' || newPrice < 0) {
        showStatus("❌ Please enter a valid price", "error");
        return;
    }

    const btn = document.querySelector(`button[onclick="updatePrice(${id})"]`);
    const originalText = btn.innerText;
    btn.innerText = "...";
    btn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'update_price');
    formData.append('id', id);
    formData.append('price', newPrice);
    formData.append('store_id', '<?= $storeid ?>');
    
    fetch('my_store.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(result => {
        if (result.trim() === 'success') {
            showStatus("✅ Price updated successfully!", "success");
        } else {
            showStatus("❌ Error updating price.", "error");
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showStatus("❌ Network error occurred.", "error");
    })
    .finally(() => {
        btn.innerText = originalText;
        btn.disabled = false;
    });
}

function showStatus(message, type) {
    const msgBox = document.getElementById('statusMsg');
    msgBox.textContent = message;
    msgBox.className = "status-msg " + type;
    msgBox.style.display = "block";
    setTimeout(() => { msgBox.style.display = "none"; }, 3000);
}

let currentLetterFilter = 'All';

function renderAlphabetButtons() {
    const filterContainer = document.getElementById('alphabetFilter');
    
    // Add 'All' button
    let allButton = document.createElement('button');
    allButton.textContent = 'All';
    allButton.className = 'active'; // Active by default
    allButton.onclick = () => setAlphabetFilter('All', allButton);
    filterContainer.appendChild(allButton);

    // Add A-Z buttons
    for (let i = 65; i <= 90; i++) {
        const letter = String.fromCharCode(i);
        let button = document.createElement('button');
        button.textContent = letter;
        button.onclick = () => setAlphabetFilter(letter, button);
        filterContainer.appendChild(button);
    }
}

function setAlphabetFilter(letter, clickedButton) {
    currentLetterFilter = letter;
    
    // Update active class
    document.querySelectorAll('#alphabetFilter button').forEach(btn => btn.classList.remove('active'));
    clickedButton.classList.add('active');

    applyFilters();
}

function applyFilters() {
    const searchInput = document.getElementById("searchInput");
    const searchTerm = searchInput.value.toUpperCase();
    const table = document.getElementById("priceTable");
    const tr = table.getElementsByTagName("tr");

    for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header
        const tds = tr[i].getElementsByTagName("td");
        if (tds.length > 0) {
            const productName = (tds[0].textContent || tds[0].innerText).toUpperCase();
            
            // Check alphabet filter
            const letterMatch = (currentLetterFilter === 'All' || productName.startsWith(currentLetterFilter));

            // Check search filter
            let searchMatch = false;
            if (searchTerm === "") {
                searchMatch = true;
            } else {
                let rowText = (tds[0].textContent || tds[0].innerText) + " " + (tds[1].textContent || tds[1].innerText) + " " + (tds[2].textContent || tds[2].innerText);
                if (rowText.toUpperCase().indexOf(searchTerm) > -1) {
                    searchMatch = true;
                }
            }

            // Show or hide row
            tr[i].style.display = (letterMatch && searchMatch) ? "" : "none";
        }
    }
}

document.addEventListener('DOMContentLoaded', renderAlphabetButtons);
</script>

</body>
</html>