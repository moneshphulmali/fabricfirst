<!-- menu.php -->
<?php  
include 'db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = $_SESSION['user'] ?? null;
$is_admin = ($user && isset($user['is_admin']) && $user['is_admin']);
$store_count = ($is_admin && isset($user['stores'])) ? count($user['stores']) : 0;

// 🟦 Current page identify
$current = basename($_SERVER['PHP_SELF']);
?>


<!-- 🔥 FIXED HEADER WITH MENU AND TITLE 🔥 -->
<div class="main-header">
  <button class="menu-btn" onclick="toggleSidebar()">☰</button>
  
 <!--  <h1 class="header-title"><?php echo htmlspecialchars($user['store_name'] ?? 'FABRIC FIRST'); ?></h1> -->
 <h1 class="header-title"><?php echo htmlspecialchars($is_admin ? '' : ($user['current_store']['store_name'] ?? 'FABRIC FIRST')); ?></h1>

 <!-- ADMIN STORE COUNT -->
  <?php if ($is_admin && $store_count > 0): ?>
    <div class="admin-store-count">Total Stores: <?= $store_count ?></div>
  <?php endif; ?>

  
</div>

<div id="mySidebar" class="sidebar">
  <span class="close-btn" onclick="toggleSidebar()">×</span>

  <!-- ===== USER INFO BOX ===== -->
  <?php if($user): ?>
  
  <div class="user-box">
 
      <div class="user-name">
<svg class="icon-space-store" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#434343"><path d="M234-276q51-39 114-61.5T480-360q69 0 132 22.5T726-276q35-41 54.5-93T800-480q0-133-93.5-226.5T480-800q-133 0-226.5 93.5T160-480q0 59 19.5 111t54.5 93Zm246-164q-59 0-99.5-40.5T340-580q0-59 40.5-99.5T480-720q59 0 99.5 40.5T620-580q0 59-40.5 99.5T480-440Zm0 360q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q53 0 100-15.5t86-44.5q-39-29-86-44.5T480-280q-53 0-100 15.5T294-220q39 29 86 44.5T480-160Zm0-360q26 0 43-17t17-43q0-26-17-43t-43-17q-26 0-43 17t-17 43q0 26 17 43t43 17Zm0-60Zm0 360Z"/></svg>	  

   <?php  //echo htmlspecialchars($user['owner_name']); ?>
   
   <?php echo htmlspecialchars($user['name'] ?? $user['owner_name']); ?>

      </div>
      <div class="user-role">
        (<?php echo htmlspecialchars($user['role'] ?? 'User'); ?>)
      </div>
  </div>
  <?php endif; ?>
  <!-- ====================================== -->

  <!-- ⭐ MENU LINKS WITH ACTIVE HIGHLIGHT ⭐ -->
  <div class="menu-links">
    <hr>
    <a href="dash.php" class="<?= ($current=='dash.php')?'active':'' ?>"><svg class="icon-space"  xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M240-200h120v-240h240v240h120v-360L480-740 240-560v360Zm-80 80v-480l320-240 320 240v480H520v-240h-80v240H160Zm320-350Z"/></svg> Home</a>
    <a href="main.php" class="<?= ($current=='main.php')?'active':'' ?>"><svg class="icon-space" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="m280-40 112-564-72 28v136h-80v-188l202-86q14-6 29.5-7t29.5 4q14 5 26.5 14t20.5 23l40 64q26 42 70.5 69T760-520v80q-70 0-125-29t-94-74l-25 123 84 80v300h-80v-260l-84-64-72 324h-84Zm260-700q-33 0-56.5-23.5T460-820q0-33 23.5-56.5T540-900q33 0 56.5 23.5T620-820q0 33-23.5 56.5T540-740Z"/></svg>	  Walk In</a>
    <a href="bbtocc.php" class="<?= ($current=='bbtocc.php')?'active':'' ?>"><svg class="icon-space" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M280-80q-33 0-56.5-23.5T200-160q0-33 23.5-56.5T280-240q33 0 56.5 23.5T360-160q0 33-23.5 56.5T280-80Zm400 0q-33 0-56.5-23.5T600-160q0-33 23.5-56.5T680-240q33 0 56.5 23.5T760-160q0 33-23.5 56.5T680-80ZM246-720l96 200h280l110-200H246Zm-38-80h590q23 0 35 20.5t1 41.5L692-482q-11 20-29.5 31T622-440H324l-44 80h480v80H280q-45 0-68-39.5t-2-78.5l54-98-144-304H40v-80h130l38 80Zm134 280h280-280Z"/></svg>   	B2C</a>
    <a href="payments.php" class="<?= ($current=='payments.php')?'active':'' ?>"><svg class="icon-space" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M549-120 280-400v-80h140q53 0 91.5-34.5T558-600H240v-80h306q-17-35-50.5-57.5T420-760H240v-80h480v80H590q14 17 25 37t17 43h88v80h-81q-8 85-70 142.5T420-400h-29l269 280H549Z"/></svg> 			 Payment</a>
    <a href="supplies.php" class="<?= ($current=='supplies.php')?'active':'' ?>"> <svg class="icon-space" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M280-160q-50 0-85-35t-35-85H60l18-80h113q17-19 40-29.5t49-10.5q26 0 49 10.5t40 29.5h167l84-360H182l4-17q6-28 27.5-45.5T264-800h456l-37 160h117l120 160-40 200h-80q0 50-35 85t-85 35q-50 0-85-35t-35-85H400q0 50-35 85t-85 35Zm357-280h193l4-21-74-99h-95l-28 120Zm-19-273 2-7-84 360 2-7 34-146 46-200ZM20-427l20-80h220l-20 80H20Zm80-146 20-80h260l-20 80H100Zm180 333q17 0 28.5-11.5T320-280q0-17-11.5-28.5T280-320q-17 0-28.5 11.5T240-280q0 17 11.5 28.5T280-240Zm400 0q17 0 28.5-11.5T720-280q0-17-11.5-28.5T680-320q-17 0-28.5 11.5T640-280q0 17 11.5 28.5T680-240Z"/></svg>  	 Supplies </a>
    <a href="order_items_supplies.php" class="<?= ($current=='order_items_supplies.php')?'active':'' ?>"> <svg class="icon-space" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="m691-150 139-138-42-42-97 95-39-39-42 43 81 81ZM240-600h480v-80H240v80ZM720-40q-83 0-141.5-58.5T520-240q0-83 58.5-141.5T720-440q83 0 141.5 58.5T920-240q0 83-58.5 141.5T720-40ZM120-80v-680q0-33 23.5-56.5T200-840h560q33 0 56.5 23.5T840-760v267q-19-9-39-15t-41-9v-243H200v562h243q5 31 15.5 59T486-86l-6 6-60-60-60 60-60-60-60 60-60-60-60 60Zm120-200h203q3-21 9-41t15-39H240v80Zm0-160h284q38-37 88.5-58.5T720-520H240v80Zm-40 242v-562 562Z"/></svg> Supplies Orders </a>
    <a href="analytics.php" class="<?= ($current=='analytics.php')?'active':'' ?>"> <svg class="icon-space" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M80-120v-80h800v80H80Zm40-120v-280h120v280H120Zm200 0v-480h120v480H320Zm200 0v-360h120v360H520Zm200 0v-600h120v600H720Z"/></svg>	   Analytics </a> 
	<a href="my_store.php" class="<?= ($current=='my_store.php')?'active':'' ?>"> <svg class="icon-space" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M160-120v-480h160v480H160Zm240 0v-480h160v480H400Zm240 0v-480h160v480H640ZM80-80v-600q0-24 18-42t42-18h680q24 0 42 18t18 42v600q0 24-18 42t-42 18H120q-24 0-42-18T80-80Zm80-80h640v-440H160v440Zm0 0v-440 440Z"/></svg> My Store </a>
    <br><br><br><br>
    
    <!-- 🔴 LOGOUT SECTION WITH SEPARATOR LINE 🔴 -->
    <hr class="logout-separator">
  </div>

  <!-- Logout Button - Bottom Fixed Position -->
  <?php if($user): ?>
    <div class="logout-section">
      <a href="index.php?action=logout" class="logout-btn"><svg class="icon-space2" xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#EA3323"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg> <pre>   Logout</pre></a>
    </div>
  <?php endif; ?>
</div>

<style>

.icon-space {
    margin-right: 25px;
    flex-shrink: 0; /* ✅ NEW: Icon ka size change nahi hoga */
}
.icon-space-store {
Position: relative;
top: 10px;
left: -20px;
}


.user-name {
  font-size: 16px;
  font-weight: bold;
  color: black;
  margin-bottom: 5px;
    position: relative; 
  
}


/* 🔥 MAIN HEADER STYLES - FIXED POSITION 🔥 */
.main-header {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 60px;
  background: #f6f6f6;
  color: white;
  display: flex;
  align-items: center;
  padding: 0 20px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.2);
  z-index: 1000;
}

.header-title {
  margin: 0;
  font-size: 22px;
  font-weight: bold;
  margin-left: 15px;
  color: #00aaff;
}

/* 🔥 ADMIN STORE COUNT STYLES 🔥 */
.admin-store-count {
  margin-left: auto;
  margin-right: 20px;
  font-size: 16px;
  font-weight: bold;
  color: #00aaff;
  background: #e8f4fd;
  padding: 8px 12px;
  border-radius: 6px;
}
/* Adjust menu button for header */
.menu-btn {
  font-size: 20px;
  cursor: pointer;
  background: rgba(255,255,255,0.2);
  color: black;
  border: none;
  padding: 8px 12px;
  border-radius: 6px;
  transition: 0.3s;
  z-index: 1001;
}

.menu-btn:hover {
  background: rgba(255,255,255,0.3);
}

/* 🔥 SIDEBAR STYLES 🔥 */
.sidebar {
  height: 100%;
  width: 250px;
  position: fixed;
  top: 0;
  left: -250px;
  background: #f6f6f6;
  overflow-x: hidden;
  transition: 0.4s ease;
  box-shadow: 2px 0 10px rgba(0,0,0,0.3);
  display: flex;
  flex-direction: column;
  z-index: 999;
}

/* When sidebar is open */
.sidebar.open {
  left: 0;
}

/* 🔥 MAIN CONTENT STYLES - SPACE FIXED 🔥 */
.main-content {
  margin-top: 60px; /* Sirf header ki height */
  padding: 15px; /* Reduced padding */
  transition: margin-left 0.4s ease;
  min-height: calc(100vh - 60px);
  width: 100%;
  box-sizing: border-box; /* Important: padding included in width */
}

/* When sidebar is open, shift ONLY main content */
body.sidebar-open .main-content {
  margin-left: 250px;
  width: calc(100% - 250px);
}

/* USER INFO BOX STYLES */
.user-box {
  position: absolute;
  top: 90px;
  left: 43px;
  padding: 10px;
  background: rgba(255,255,255,0.1);
  border-radius: 8px;
}

.user-role {
  font-size: 14px;
  color: #666;
  margin-left: 30px;
}

/* MENU LINKS POSITION */
.menu-links {
  position: absolute;
  top: 150px;
  left: 5px;
  right: 20px;
  bottom: 80px; /* Logout section ke liye space chhod rahe hain */
  overflow-y: auto;
}

/* ✅✅✅ CHANGES START HERE - MENU ALIGNMENT FIX ✅✅✅ */
.sidebar a {
  display: flex; /* ✅ NEW: Flexbox use karo */
  align-items: center; /* ✅ NEW: Vertical center alignment */
  padding: 14px 25px;
  text-decoration: none;
  color: black;
  font-size: 15px;
  transition: 0.3s;
  text-align: left;
  border-radius: 5px;
  margin-bottom: 5px;
  white-space: nowrap; /* ✅ NEW: Text wrap nahi hoga */
  overflow: hidden; /* ✅ NEW: Overflow handle */
}

.sidebar a:hover {
  background-color: #00aaff;
  color: white;
}

/* ⭐ ACTIVE BUTTON STYLE ⭐ */
.sidebar a.active {
  background: #00aaff;
  color: white;
  border-radius: 5px;
}
/* ✅✅✅ CHANGES END HERE ✅✅✅ */

.close-btn {
  position: absolute;
  top: 25px;
  right: 20px;
  font-size: 30px;
  color: #1976d2;
  cursor: pointer;
  z-index: 1001;
}

/* 🔴 LOGOUT SECTION STYLES - BOTTOM FIXED 🔴 */
.logout-section {
  position: absolute;
  left: 8px;
  right: 20px;
  bottom: 20px;
  text-align: center;
}

.logout-btn {
  display: block;
  padding: 12px 20px;
  background: ;
  color: red;
  font-weight: bold;
  text-decoration: none;
  border-radius: 8px;
  transition: 0.3s;
  text-align: center;
  font-size: 16px;
}

.logout-btn:hover {
  background: #ff3333;
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(255, 77, 77, 0.3);
}

/* Logout separator line */
.logout-separator {
  border: 0;
  height: 1px;
  background: #ccc;
  margin: 20px 0;
}

/* Body styles - IMPORTANT FIX */
body {
  margin: 0;
  padding: 0;
  overflow-x: hidden;
  transition: margin-left 0.4s ease;
}



</style>

<script>
// 🔥 SINGLE TOGGLE FUNCTION - Ek hi button se open/close
function toggleSidebar() {
  const sidebar = document.getElementById("mySidebar");
  const body = document.body;
  
  if (sidebar.classList.contains("open")) {
    // Close sidebar
    sidebar.classList.remove("open");
    body.classList.remove("sidebar-open");
  } else {
    // Open sidebar
    sidebar.classList.add("open");
    body.classList.add("sidebar-open");
  }
}

// Sidebar ke bahar click karein to band ho jaye
document.addEventListener('click', function(event) {
  const sidebar = document.getElementById('mySidebar');
  const menuBtn = document.querySelector('.menu-btn');
  const closeBtn = document.querySelector('.close-btn');
  
  // Agar sidebar open hai aur user sidebar ke bahar click karta hai
  if (sidebar.classList.contains("open") && 
      !sidebar.contains(event.target) && 
      event.target !== menuBtn && 
      !menuBtn.contains(event.target)) {
    toggleSidebar(); // Toggle function use karo
  }
});

// ESC key press par bhi sidebar band ho jaye
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    const sidebar = document.getElementById('mySidebar');
    if (sidebar.classList.contains("open")) {
      toggleSidebar(); // Toggle function use karo
    }
  }
});

// Page load par sidebar close rahe
document.addEventListener('DOMContentLoaded', function() {
  // Sidebar automatically close rahega kyunki CSS mein left: -250px hai
});
</script>