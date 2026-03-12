<?php

include 'db_connect.php';
session_start();

// ================== DATABASE CONFIG ==================
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// ================== LOGOUT ==================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // Clear session completely
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: index.php");
    exit;
}

// ================== STORE CHANGE HANDLER ==================
if (isset($_SESSION['user']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_store'])) {
    $new_storeid = intval($_POST['storeid']);
    
    // Find the store in user's stores
    foreach ($_SESSION['user']['stores'] as $store) {
        if ($store['storeid'] == $new_storeid) {
            $_SESSION['user']['current_store'] = $store;
            
            // Refresh page
            header("Location: index.php");
            exit;
        }
    }
}

// ================== NEW LOGIN SYSTEM (4 TABLES) ==================
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && !isset($_POST['change_store'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'] ?? '';
    $selected_role = $_POST['role'] ?? '';

    // Basic validation
    if ($username === '' || $password === '' || $selected_role === '') {
        $login_error = "Please enter username, password, and role.";
    } else {
        // 1. PEHLE users TABLE SE USER CHECK KARO
        $stmt = $conn->prepare("  
            SELECT user_id, login_id, name, phone, password 
            FROM users 
            WHERE login_id = ? OR phone = ?
            LIMIT 1
        ");
        
        if ($stmt) {
            $stmt->bind_param('ss', $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // PASSWORD VERIFY
                if ($password === $user['password']) {
                    
                    // ✅ FIXED: DIRECT QUERY TO GET USER'S STORES WITH SELECTED ROLE
                    $role_stmt = $conn->prepare("
                        SELECT s.storeid, s.store_name, s.pincode, r.role_name, r.role_id
                        FROM store_user_roles sur
                        JOIN stores s ON sur.storeid = s.storeid
                        JOIN roles r ON sur.role_id = r.role_id
                        WHERE sur.user_id = ? 
                        AND r.role_name = ?  -- ✅ Sirf selected role wale stores
                        ORDER BY s.store_name
                        LIMIT 1  -- ✅ Pehla store hi lelo
                    ");
                    
                    $role_stmt->bind_param('is', $user['user_id'], $selected_role);
                    $role_stmt->execute();
                    $assignment = $role_stmt->get_result();
                    
                    if ($assignment && $assignment->num_rows === 1) {
                        // User ke paas yeh role hai
                        $store_data = $assignment->fetch_assoc();
                        
                        // ✅ GET ALL STORES WHERE USER HAS ANY ROLE (for store switching)
                        $all_stores_stmt = $conn->prepare("
                            SELECT s.storeid, s.store_name, s.pincode, r.role_name
                            FROM store_user_roles sur
                            JOIN stores s ON sur.storeid = s.storeid
                            JOIN roles r ON sur.role_id = r.role_id
                            WHERE sur.user_id = ?
                            ORDER BY s.store_name
                        ");
                        
                        $all_stores_stmt->bind_param('i', $user['user_id']);
                        $all_stores_stmt->execute();
                        $all_assignments = $all_stores_stmt->get_result();
                        
                        $user_stores = [];
                        while ($store_row = $all_assignments->fetch_assoc()) {
                            $user_stores[] = $store_row;
                        }
                        
                        // ✅ CHECK IF USER IS ADMIN (role_id = 1)
                        $is_admin = ($store_data['role_id'] == 1);
                        
                        // 5. SESSION SET KARO
                        session_regenerate_id(true);
                        
                        $_SESSION['user'] = [
                            'user_id' => $user['user_id'],
                            'login_id' => $user['login_id'],
                            'name' => $user['name'],
                            'phone' => $user['phone'],
                            'role' => $selected_role,
                            'role_id' => $store_data['role_id'], // ✅ Store role_id in session
                            'is_admin' => $is_admin, // ✅ Store admin status
                            'stores' => $user_stores,
                            'current_store' => $store_data, // ✅ Selected role wala store
                            'user_type' => 'new_system_user'
                        ];
                        
                        // 6. REDIRECT TO DASHBOARD
                        header("Location: dash.php");
                        exit;
                        
                    } else {
                        // User ke paas yeh role nahi hai, check karo uske paas kon si roles hain
                        $available_roles_stmt = $conn->prepare("
                            SELECT DISTINCT r.role_name
                            FROM store_user_roles sur
                            JOIN roles r ON sur.role_id = r.role_id
                            WHERE sur.user_id = ?
                            ORDER BY r.role_name
                        ");
                        
                        $available_roles_stmt->bind_param('i', $user['user_id']);
                        $available_roles_stmt->execute();
                        $available_roles_result = $available_roles_stmt->get_result();
                        
                        $available_roles = [];
                        while ($role_row = $available_roles_result->fetch_assoc()) {
                            $available_roles[] = $role_row['role_name'];
                        }
                        
                        if (empty($available_roles)) {
                            $login_error = "❌ You are not assigned a role in any store.";
                        } else {
                            $login_error = "❌ You do not have this role in any store. Your available roles are: " .
                                           implode(', ', $available_roles);
                        }
                        
                        $available_roles_stmt->close();
                    }
                    
                    $role_stmt->close();
                } else {
                    $login_error = "❌ Incorrect password";
                }
            } else {
                $login_error = "❌ User not found. Please enter the correct login ID or phone number.";
            }
            $stmt->close();
        } else {
            $login_error = "❌ Database error. Please try again later.";
        }
    }
}

$logged_in = isset($_SESSION['user']);
$user = $logged_in ? $_SESSION['user'] : null;
?>



















<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fabric First - Login</title>
<style>
body{font-family: Arial, Helvetica, sans-serif;background:#f6f6f6;margin:0;padding:0}
.header{max-width:1100px;margin:30px auto 10px auto;text-align:center}
.logo{font-size:48px;color:#1976d2;letter-spacing:3px}
.container{max-width:500px;margin:60px auto;display:flex;flex-direction:column;gap:20px;align-items:center}
.card{width:100%;background:#fff;padding:20px;border-radius:6px;box-shadow:0 10px 20px rgba(0,0,0,0.15);}
.field{margin-bottom:14px}
input[type="text"], input[type="password"], select{width:100%;padding:14px;border:1px solid #ccc;border-radius:3px;box-sizing:border-box}
.role-row{display:flex;gap:12px;align-items:center;margin:12px 0 18px 0}
.role-row label{display:flex;gap:6px;align-items:center}
.btn{display:block;width:100%;padding:12px;background:#00aaff;border:none;color:#fff;border-radius:6px;font-weight:bold;cursor:pointer}
.btn-store{display:inline-block;padding:8px 15px;background:#28a745;color:white;border:none;border-radius:4px;cursor:pointer;margin-top:10px}
.error{color:#b00020;margin:10px 0;text-align:center}
.logout{color:#007bff;text-decoration:none;font-weight:bold}
.logout:hover{text-decoration:underline}
.store-list{background:#f8f9fa;border-radius:5px;padding:10px;margin:15px 0}
.store-item{padding:8px;border-bottom:1px solid #dee2e6}
.store-item:last-child{border-bottom:none}
.store-name{font-weight:bold}
.store-role{color:#6c757d;font-size:14px}
.store-selector{margin:15px 0;padding:15px;background:#e8f4fd;border-radius:5px}
</style>
</head>
<body>

<div class="header">
  <div class="logo">FABRIC FIRST</div>
  <div style="font-size:12px;color:#00aaff">The Laundry Management Software </div>
</div>

<?php if (!$logged_in): ?>
  <div class="container">
    <div class="card">
      <form method="post" autocomplete="off">
        <div class="field">
          <input type="text" name="username" placeholder="Login ID or Phone Number" required>
        </div>
        <div class="field">
          <input type="password" name="password" placeholder="Password" required>
        </div>

        <div style="margin-bottom:10px;font-size:14px;color:#333">Select your role:</div>
        <div class="role-row">
          <!-- ✅ Remove 'required' from all radio buttons, we'll validate server-side -->
          <label><input type="radio" name="role" value="admin"> Admin</label>
          <label><input type="radio" name="role" value="store_manager"> Store Manager</label>
          <label><input type="radio" name="role" value="salesman"> Salesman</label>
        </div>

        <?php if ($login_error): ?>
          <div class="error"><?= htmlspecialchars($login_error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>

        <button class="btn" type="submit" name="login">LOGIN</button>
        
        
      </form>
    </div>
  </div>
  
<?php else: ?>
  <div class="container">
    <div class="card" style="text-align:center;">
      <h2>👋 Welcome, <?= htmlspecialchars($user['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
      
      <p>Login ID: <b><?= htmlspecialchars($user['login_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b></p>
      <p>Phone: <b><?= htmlspecialchars($user['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b></p>
      <p>Current Role: <b><?= htmlspecialchars($user['role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b></p>
      <p>Admin Status: <b><?= isset($user['is_admin']) && $user['is_admin'] ? 'Yes' : 'No' ?></b></p>
      
      <!-- Display Current Store -->
      <?php if(isset($user['current_store'])): ?>
        <div style="background:#e8f4fd;padding:15px;border-radius:5px;margin:15px 0;">
          <h4>Current Store:</h4>
          <p><span class="store-name">🏬 <?= htmlspecialchars($user['current_store']['store_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></p>
          <p>Pincode: <?= htmlspecialchars($user['current_store']['pincode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <p>Role at this store: <b><?= htmlspecialchars($user['current_store']['role_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b></p>
        </div>
      <?php endif; ?>
      
      <!-- Display All Stores Access -->
      <?php if(isset($user['stores']) && count($user['stores']) > 0): ?>
        <div class="store-list">
          <h4>Your Store Access:</h4>
          <?php foreach($user['stores'] as $store): ?>
            <div class="store-item">
              <div class="store-name">🏬 <?= htmlspecialchars($store['store_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="store-role">Role: <?= htmlspecialchars($store['role_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Pincode: <?= htmlspecialchars($store['pincode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        
        <!-- STORE CHANGE FORM (Same page mein) -->
        <?php if(count($user['stores']) > 1): ?>
          <div class="store-selector">
            <h4>Switch Store:</h4>
            <form method="post">
              <select name="storeid" style="padding:8px;width:100%;margin-bottom:10px;">
                <?php foreach($user['stores'] as $store): ?>
                  <?php 
                  // Only show stores where user has the CURRENT role
                  if ($store['role_name'] == $user['role']): 
                  ?>
                    <option value="<?= $store['storeid'] ?>" <?= ($store['storeid'] == $user['current_store']['storeid']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($store['store_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= $store['role_name'] ?>)
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="change_store" value="1" class="btn-store">
                Change Store
              </button>
              <p style="font-size:12px;color:#666;margin-top:5px;">
                <i>Note: You can only switch between stores where you have the current role (<?= htmlspecialchars($user['role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</i>
              </p>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      
      <p style="margin-top:20px;">
        <a href="dash.php" style="display:inline-block;padding:10px 20px;background:#00aaff;color:white;text-decoration:none;border-radius:4px;margin-right:10px;">
          Go to Dashboard →
        </a>
        <a href="bbtocc.php" style="display:inline-block;padding:10px 20px;background:#28a745;color:white;text-decoration:none;border-radius:4px;margin-right:10px;">
          View Orders
        </a>
        <a class="logout" href="?action=logout">Logout</a>
		

      </p>
      
      <!-- DEBUG INFO (Remove in production) -->
      <div style="margin-top:20px;padding:10px;background:#f8f9fa;border-radius:5px;font-size:12px;text-align:left;">
        <strong>DEBUG Info:</strong><br>
        User ID: <?= $user['user_id'] ?><br>
        Current Store ID: <?= $user['current_store']['storeid'] ?><br>
        Role ID: <?= $user['role_id'] ?><br>
        Is Admin: <?= $user['is_admin'] ? 'Yes' : 'No' ?><br>
      </div>
    </div>
  </div>
<?php endif; ?>






</body>
</html>