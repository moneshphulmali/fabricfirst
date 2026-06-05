<?php
// ==========================================
// 1. DATABASE CONNECTION & CONFIGURATION
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 1 temporarily to see errors in the Network tab
header('Content-Type: text/html; charset=utf-8');

include 'db_connect.php';

session_start();

// Public actions (jo employee scan karte waqt use hote hain)
$public_actions = ['process_attendance'];
$action = $_GET['action'] ?? '';

// Agar admin view hai ya token generation hai, toh session check karein
if (!in_array($action, $public_actions) && !isset($_SESSION['user'])) {
    header("Location: indexing.php");
    exit;
}

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

date_default_timezone_set('Asia/Kolkata'); // Setting time zone for India

// ==========================================
// 2. BACKEND API ENDPOINTS (AJAX HANDLERS)
// ==========================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $storeid = $_SESSION['user']['current_store']['storeid'] ?? null;

    // --- Endpoint: Generate Dynamic QR Token ---
    if ($action === 'generate_token') {
        try {
            $empId = $_GET['employee_id'] ?? '';
            if (!$storeid) throw new Exception("Store context missing.");

            $token = bin2hex(random_bytes(16));
            $createdAt = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + 60); // 60 Seconds Validity

            // Cleanup: Delete expired tokens that were never used to keep the table clean
            $pdo->prepare("DELETE FROM qr_tokens WHERE expires_at < NOW() AND status = 'generated' AND storeid = ?")->execute([$storeid]);

            $stmt = $pdo->prepare("INSERT INTO qr_tokens (token, employee_id, storeid, created_at, expires_at, status) VALUES (?, ?, ?, ?, ?, 'generated')");
            $stmt->execute([$token, $empId, $storeid, $createdAt, $expiresAt]);

            echo json_encode(['success' => true, 'token' => $token, 'emp_id' => $empId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- Endpoint: Process Scanning & Attendance Logic ---
    if ($action === 'process_attendance') {
        $empId   = $_POST['employee_id'] ?? '';
        $pin     = $_POST['pin'] ?? '';
        $token   = $_POST['token'] ?? '';
        $deviceId = $_POST['device_id'] ?? '';
        $type    = $_POST['type'] ?? 'check_in'; // 'check_in' or 'check_out'
        
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        // Validation 1: Check inputs
        if (empty($empId) || empty($pin) || empty($token) || empty($deviceId)) {
            echo json_encode(['success' => false, 'message' => 'All fields and scan data are required!']);
            exit;
        }

        // Validation 2: Token Check (Expiration & Anti-Proxy)
        $stmt = $pdo->prepare("SELECT * FROM qr_tokens WHERE token = ? AND employee_id = ? AND expires_at >= ?");
        $stmt->execute([$token, $empId, $now]);
        $tokenData = $stmt->fetch();

        if (!$tokenData) {
            echo json_encode(['success' => false, 'message' => 'Expired or Invalid QR Code! Please scan again.']);
            exit;
        }

        // Validation 3: Employee Verification
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ? AND storeid = ?");
        $stmt->execute([$empId, $tokenData['storeid']]);
        $employee = $stmt->fetch();

        if (!$employee || $employee['pin'] !== $pin) {
            echo json_encode(['success' => false, 'message' => 'Incorrect Employee ID or PIN!']);
            exit;
        }

        // Validation 4: Device Binding Logic
        if (empty($employee['registered_device_id'])) {
            // Bind device automatically on first use
            $stmt = $pdo->prepare("UPDATE employees SET registered_device_id = ? WHERE employee_id = ? AND storeid = ?");
            $stmt->execute([$deviceId, $empId, $tokenData['storeid']]);
        } else if ($employee['registered_device_id'] !== $deviceId) {
            echo json_encode(['success' => false, 'message' => 'Device Mis-match! Proxy attendance detected.']);
            exit;
        }

        // --- Execute Check-In / Check-Out Logic ---
        if ($type === 'check_in') {
            // Verify if already checked in today
            $stmt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND date = ? AND storeid = ?");
            $stmt->execute([$empId, $today, $tokenData['storeid']]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You have already checked in today!']);
                exit;
            }

            // Attendance Rules / Slot Evaluation
            $currentTimeStr = date('H:i:s');
            if ($currentTimeStr <= '10:30:00') {
                $status = 'Present'; // on time ->  10:30 AM ya usse pehle check-in karta hai, toh uska status Present mark hota hai
            } elseif ($currentTimeStr > '10:31:00' && $currentTimeStr <= '11:00:00') {
                $status = 'Late'; // Late -> 10:31 se lekar 11:00 tak ka samay late category mein aata hai.
            } else {
                $status = 'Half-Day'; // Half-Day -> 11:01 AM ke baad check-in karta hai, toh uska status automatic Half-Day ho jata hai.
            }

            $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, storeid, status, check_in_time, date, device_id, token_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$empId, $tokenData['storeid'], $status, $now, $today, $deviceId, $tokenData['id']]);

            // Update QR token status to 'used'
            $stmt = $pdo->prepare("UPDATE qr_tokens SET status = 'used' WHERE id = ?");
            $stmt->execute([$tokenData['id']]);

            echo json_encode(['success' => true, 'message' => "Check-In Successful! Status: $status"]);
            exit;
        } else {
            // Process Check-Out
            $stmt = $pdo->prepare("SELECT id, check_out_time FROM attendance WHERE employee_id = ? AND date = ? AND storeid = ?");
            $stmt->execute([$empId, $today, $tokenData['storeid']]);
            $attendanceRecord = $stmt->fetch();

            if (!$attendanceRecord) {
                echo json_encode(['success' => false, 'message' => 'Please Check-In first before Checking Out!']);
                exit;
            }
            if (!empty($attendanceRecord['check_out_time'])) {
                echo json_encode(['success' => false, 'message' => 'You have already checked out today!']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE attendance SET check_out_time = ? WHERE id = ?");
            $stmt->execute([$now, $attendanceRecord['id']]);

            // Update QR token status to 'used'
            $stmt = $pdo->prepare("UPDATE qr_tokens SET status = 'used' WHERE id = ?");
            $stmt->execute([$tokenData['id']]);

            echo json_encode(['success' => true, 'message' => 'Check-Out Successful! Goodbye.']);
            exit;
        }
    }
}

// --- Automatic Check-Out Correction Logic ---
// Yadi koi employee check-out bhul gaya hai, toh automatic 8:30 PM (20:30:00) set karein
try {
    $autoTime = "20:30:00";
    $pdo->prepare("
        UPDATE attendance 
        SET check_out_time = CONCAT(date, ' ', ?) 
        WHERE check_out_time IS NULL 
        AND (date < CURDATE() OR (date = CURDATE() AND TIME(NOW()) > ?))
    ")->execute([$autoTime, $autoTime]);
} catch (Exception $e) { /* Error handling if required */ }

$current_store_id = $_SESSION['user']['current_store']['storeid'];
$is_admin = $_SESSION['user']['is_admin'] ?? false;

// Admin के लिए सभी स्टोर्स की ID निकालें, मैनेजर के लिए सिर्फ करंट स्टोर
if ($is_admin) {
    $user_stores = $_SESSION['user']['stores'] ?? [];
    $store_ids = array_column($user_stores, 'storeid');
    $store_ids_placeholder = implode(',', array_fill(0, count($store_ids), '?'));
    $store_filter_sql = "e.storeid IN ($store_ids_placeholder)";
    $params_today = array_merge([date('Y-m-d')], $store_ids);
} else {
    $store_filter_sql = "e.storeid = ?";
    $params_today = [date('Y-m-d'), $current_store_id];
}

// Fetch all registered employees from database
$today = date('Y-m-d');
$stmtEmp = $pdo->prepare("SELECT e.*, s.store_name, a.check_in_time, a.check_out_time FROM employees e JOIN stores s ON e.storeid = s.storeid LEFT JOIN attendance a ON e.employee_id = a.employee_id AND a.date = ? WHERE $store_filter_sql ORDER BY s.store_name, e.employee_id ASC");
$stmtEmp->execute($params_today);
$employeesList = $stmtEmp->fetchAll();

// --- New: Employees Summary Logic with Date Range Filter ---
$filterStartDate = $_GET['start_date'] ?? date('Y-m-01');
$filterEndDate = $_GET['end_date'] ?? date('Y-m-d');

// Calculate total working days in the selected range
$startDateTime = new DateTime($filterStartDate);
$endDateTime = new DateTime($filterEndDate);
$endDateTime->modify('+1 day'); // Include the end date itself
$interval = DateInterval::createFromDateString('1 day');
$period = new DatePeriod($startDateTime, $interval, $endDateTime);

$daysElapsed = 0;
foreach ($period as $dt) {
    // Optionally, you can exclude weekends or holidays here if needed
    // For now, counting all days in the range
    $daysElapsed++;
}
if ($daysElapsed > 0) {
    $daysElapsed--; // Adjust for the +1 day modification
}

// Fetch Summary Data
if($is_admin) {
    // Admin logic: Fetch for multiple stores
    $stmtSummary = $pdo->prepare("SELECT e.employee_id, e.name, s.store_name, COUNT(a.id) as present_days, SUM(CASE WHEN a.check_out_time IS NOT NULL THEN TIMESTAMPDIFF(SECOND, a.check_in_time, a.check_out_time) ELSE 0 END) as total_work_seconds, SUM(CASE WHEN TIME(a.check_in_time) > '09:00:00' THEN TIMESTAMPDIFF(SECOND, CONCAT(a.date, ' 09:00:00'), a.check_in_time) ELSE 0 END) as total_late_seconds FROM employees e JOIN stores s ON e.storeid = s.storeid LEFT JOIN attendance a ON e.employee_id = a.employee_id AND a.date BETWEEN ? AND ? WHERE $store_filter_sql GROUP BY e.employee_id, e.name, s.store_name");
    $stmtSummary->execute(array_merge([$filterStartDate, $filterEndDate], $store_ids));
} else {
    // Manager/User logic: Fetch for single store
    $stmtSummary = $pdo->prepare("SELECT e.employee_id, e.name, s.store_name, COUNT(a.id) as present_days, SUM(CASE WHEN a.check_out_time IS NOT NULL THEN TIMESTAMPDIFF(SECOND, a.check_in_time, a.check_out_time) ELSE 0 END) as total_work_seconds, SUM(CASE WHEN TIME(a.check_in_time) > '09:00:00' THEN TIMESTAMPDIFF(SECOND, CONCAT(a.date, ' 09:00:00'), a.check_in_time) ELSE 0 END) as total_late_seconds FROM employees e JOIN stores s ON e.storeid = s.storeid LEFT JOIN attendance a ON e.employee_id = a.employee_id AND a.date BETWEEN ? AND ? WHERE e.storeid = ? GROUP BY e.employee_id, e.name, s.store_name");
    $stmtSummary->execute([$filterStartDate, $filterEndDate, $current_store_id]);
}
$monthlySummary = $stmtSummary->fetchAll();

// Logic to toggle between Admin Kiosk and Employee Form
$isEmployeeView = isset($_GET['token']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Attendance System</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen">
    <?php include 'menu.php'; ?>

    <div class="main-content">
        <div class="max-w-7xl mx-auto p-4 md:p-8">
        
        <!-- ADMIN KIOSK VIEW -->
        <div id="view-admin" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <?php if (!$is_admin): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 flex flex-col items-center justify-center text-center">
                <h2 class="text-xl font-bold text-slate-900 mb-2">Scan this QR to mark attendance</h2>
                
                <div class="bg-slate-100 p-4 rounded-xl border border-dashed border-slate-300 relative">
                    <div id="qrcode" class="p-2 bg-white rounded shadow-sm blur-md transition-all duration-500"></div>
                    <div id="qr-overlay" class="absolute inset-0 bg-slate-900/10 backdrop-blur-xs flex items-center justify-center rounded-xl hidden">
                        <span class="bg-white text-xs px-3 py-1.5 rounded-full font-bold shadow text-indigo-600 animate-pulse">Refreshing...</span>
                    </div>
                </div>
                
                <div class="w-full bg-slate-100 h-2 rounded-full mt-6 overflow-hidden max-w-xs">
                    <div id="countdown-bar" class="bg-indigo-600 h-full transition-all duration-1000 linear" style="width: 100%"></div>
                </div>
                <p class="text-xs text-slate-400 mt-2">Rotates automatically every 60s to isolate proxy submissions.</p>
            </div>
            <?php endif; ?>

            <div class="<?php echo $is_admin ? 'lg:col-span-3' : 'lg:col-span-2'; ?> bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

                <!-- Registered Employees List Section -->
                <div>
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-lg font-bold text-indigo-900">Today's Summary (<?= date('j M Y') ?>)</h2>
                        <span class="text-xs text-slate-400">Total: <?= count($employeesList) ?></span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-600">
                            <thead class="bg-indigo-50/50 text-indigo-500 uppercase text-[10px] font-bold border-b border-indigo-100">
                                <tr>
                                    <th class="py-2 px-4">Emp ID</th>
                                    <?php if($is_admin): ?>
                                    <th class="py-2 px-4">Store</th>
                                    <?php endif; ?>
                                    <th class="py-2 px-4">Name</th>
                                    <th class="py-2 px-4">Check In</th>
                                    <th class="py-2 px-4">Check Out</th>
                                    <?php if (!$is_admin): ?>
                                    <th class="py-2 px-4 text-center">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($employeesList as $emp): ?>
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="py-3 px-4 font-mono font-bold text-indigo-600"><?= htmlspecialchars($emp['employee_id']) ?></td>
                                        <?php if($is_admin): ?>
                                        <td class="py-3 px-4 font-semibold text-slate-700"><?= htmlspecialchars($emp['store_name']) ?></td>
                                        <?php endif; ?>
                                        <td class="py-3 px-4 font-semibold text-slate-900"><?= htmlspecialchars($emp['name']) ?></td>
                                        <td class="py-3 px-4 text-slate-600 font-medium"><?= $emp['check_in_time'] ? date('h:i A', strtotime($emp['check_in_time'])) : '<span class="text-slate-300">-</span>' ?></td>
                                        <td class="py-3 px-4 text-slate-600 font-medium"><?= $emp['check_out_time'] ? date('h:i A', strtotime($emp['check_out_time'])) : '<span class="text-slate-300">-</span>' ?></td>
                                        <?php if (!$is_admin): ?>
                                        <td class="py-3 px-4 text-center">
                                            <button onclick="fetchNextQRToken('<?= $emp['employee_id'] ?>'); window.scrollTo({top: 0, behavior: 'smooth'});" class="bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-3 py-1.5 rounded-lg text-[10px] font-bold transition-all active:scale-95 border border-indigo-100">
                                                Generate QR
                                            </button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- MONTHLY SUMMARY SECTION -->
        <div class="mt-8 bg-white rounded-2xl shadow-sm border border-slate-200 p-6" id="employees-summary-section">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-lg font-bold text-indigo-900">Employees Summary (<?= date('d M Y', strtotime($filterStartDate)) ?> to <?= date('d M Y', strtotime($filterEndDate)) ?>)</h2>
                <div class="flex items-center space-x-2">
                    <label for="summary_start_date" class="text-sm font-medium text-slate-700">From:</label>
                    <input type="date" id="summary_start_date" class="p-2 border border-slate-300 rounded-md text-sm" value="<?= htmlspecialchars($filterStartDate) ?>">
                    
                    <label for="summary_end_date" class="text-sm font-medium text-slate-700">To:</label>
                    <input type="date" id="summary_end_date" class="p-2 border border-slate-300 rounded-md text-sm" value="<?= htmlspecialchars($filterEndDate) ?>">
                    
                    <button onclick="applySummaryDateFilter()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-md text-sm transition-all active:scale-95">
                        Filter
                    </button>
                    <button onclick="resetSummaryDateFilter()" class="bg-slate-400 hover:bg-slate-500 text-white font-bold py-2 px-4 rounded-md text-sm transition-all active:scale-95">
                        Reset
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-600">
                    <thead class="bg-slate-50 text-slate-500 uppercase text-[10px] font-bold border-b border-slate-100">
                        <tr>
                            <th class="py-2 px-4">Name</th>
                            <?php if($is_admin): ?>
                            <th class="py-2 px-4">Store</th>
                            <?php endif; ?>
                            <th class="py-2 px-4">Total Working Days</th>
                            <th class="py-2 px-4">Present Days</th>
                            <th class="py-2 px-4">Absent Days</th>
                            <th class="py-2 px-4">Total Working Hours</th>
                            <th class="py-2 px-4">Total Late Hours</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($monthlySummary as $sum): 
                            $workHours = round($sum['total_work_seconds'] / 3600, 2);
                            $lateHours = round($sum['total_late_seconds'] / 3600, 2);
                            $absentDays = $daysElapsed - $sum['present_days'];
                        ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="py-3 px-4 font-semibold text-slate-900"><?= htmlspecialchars($sum['name']) ?></td>
                                <?php if($is_admin): ?>
                                <td class="py-3 px-4 font-semibold text-slate-700"><?= htmlspecialchars($sum['store_name']) ?></td>
                                <?php endif; ?>
                                <td class="py-3 px-4"><?= $daysElapsed ?></td>
                                <td class="py-3 px-4 text-emerald-600 font-bold"><?= $sum['present_days'] ?></td>
                                <td class="py-3 px-4 text-red-600 font-bold"><?= $absentDays ?></td>
                                <td class="py-3 px-4 font-mono"><?= $workHours ?> hrs</td>
                                <td class="py-3 px-4 font-mono text-orange-600"><?= $lateHours ?> hrs</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>

    <script>
        let qrInterval = null;
        let countdownTimer = null;
        const qrRefreshCycleSeconds = 60;
        let activeEmpId = '';
        <?php if (!$is_admin): ?>

        // --- Admin Kiosk System Logic ---
        const qrContainer = document.getElementById("qrcode");
        const qrcodeInstance = new QRCode(qrContainer, { width: 220, height: 220, correctLevel: QRCode.CorrectLevel.H });

        function fetchNextQRToken(empId = activeEmpId) {
            if (!empId) return;
            activeEmpId = empId;

            // Start rotation only after first manual click
            if (!qrInterval) {
                qrInterval = setInterval(() => fetchNextQRToken(activeEmpId), qrRefreshCycleSeconds * 1000);
            }

            document.getElementById('qr-overlay').classList.remove('hidden');
            fetch('?action=generate_token&employee_id=' + empId)
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        qrContainer.classList.remove('blur-md'); // Remove blur on success
                        qrcodeInstance.clear();
                        // Generate full URL for the employee to open
                        const scanUrl = window.location.origin + "/fabricfirst/mark_attendance.php?token=" + data.token + "&emp_id=" + data.emp_id;
                        qrcodeInstance.makeCode(scanUrl);
                        startCountdownBar();
                    } else {
                        console.error("Server Error:", data.message);
                        alert("Error generating token: " + data.message);
                    }
                })
                .catch(err => {
                    console.error("Network/JSON Error:", err);
                })
                .finally(() => {
                    setTimeout(() => document.getElementById('qr-overlay').classList.add('hidden'), 300);
                });
        }

        function startCountdownBar() {
            clearInterval(countdownTimer);
            const progressBar = document.getElementById('countdown-bar');
            let remaining = qrRefreshCycleSeconds;
            progressBar.style.width = '100%';
            
            countdownTimer = setInterval(() => {
                remaining--;
                progressBar.style.width = `${(remaining / qrRefreshCycleSeconds) * 100}%`;
                if(remaining <= 0) clearInterval(countdownTimer);
            }, 1000);
        }

        function startAdminQRRotation() {
            // Function kept for legacy but auto-start disabled
        }

        // Auto-generation on load disabled as per requirement
        <?php endif; ?>

        // --- Employees Summary Date Filter Logic ---
        function applySummaryDateFilter() {
            const startDate = document.getElementById('summary_start_date').value;
            const endDate = document.getElementById('summary_end_date').value;

            if (!startDate || !endDate) {
                alert('Please select both start and end dates.');
                return;
            }

            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after end date.');
                return;
            }

            window.location.href = `attendance.php?start_date=${startDate}&end_date=${endDate}`;
        }

        function resetSummaryDateFilter() {
            // Reload the page without date parameters to show current month's data
            window.location.href = `attendance.php`;
        }

    </script>
</body>
</html>