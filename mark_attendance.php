<?php
$token = $_GET['token'] ?? '';
$emp_id = $_GET['emp_id'] ?? '';

if (empty($token)) {
    die("Invalid QR Access. Please scan the QR code from the office kiosk.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Attendance </title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://openfpcdn.io/fingerprintjs/v4/iife.js"></script>
    <style>
        body { background-color: #f8fafc; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div class="max-w-md w-full bg-white rounded-3xl shadow-2xl border border-slate-100 p-8">
        <div class="text-center mb-8">
            <div class="bg-indigo-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">👤</span>
            </div>
            <h2 class="text-2xl font-extrabold text-slate-900">Employee Attendance</h2>
            <p class="text-slate-500 text-sm mt-1">Scan Verified. Enter your details.</p>
        </div>

        <form id="attendanceForm" class="space-y-6">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Employee ID</label>
                <input type="text" name="employee_id" value="<?= htmlspecialchars($emp_id) ?>" readonly 
                    class="w-full px-5 py-4 rounded-2xl border border-slate-200 bg-slate-50 text-slate-600 font-bold outline-none cursor-not-allowed">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Security PIN</label>
                <input type="password" name="pin" maxlength="4" placeholder="••••" required 
                    class="w-full px-5 py-4 rounded-2xl border border-slate-200 focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all text-center text-3xl font-mono tracking-[1em]">
            </div>

            <div class="grid grid-cols-2 gap-4 pt-4">
                <button type="button" id="btnIn" onclick="submitAttendance('check_in')" 
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-4 rounded-2xl shadow-lg shadow-indigo-200 transition-all active:scale-95">
                    Check In
                </button>
                <button type="button" id="btnOut" onclick="submitAttendance('check_out')" 
                    class="bg-slate-900 hover:bg-black text-white font-bold py-4 rounded-2xl shadow-lg shadow-slate-200 transition-all active:scale-95">
                    Check Out
                </button>
            </div>
        </form>
        
        <div id="responseMessage" class="mt-8 hidden p-5 rounded-2xl text-center font-bold text-sm animate-bounce"></div>
        
        <p class="text-center text-slate-400 text-[10px] mt-8 uppercase tracking-tighter">
            Secure Attendance System • Fabric First
        </p>
    </div>

    <script>
        const fpPromise = FingerprintJS.load();

        async function submitAttendance(type) {
            const form = document.getElementById('attendanceForm');
            const msgDiv = document.getElementById('responseMessage');
            const btnIn = document.getElementById('btnIn');
            const btnOut = document.getElementById('btnOut');
            
            const fp = await fpPromise;
            const result = await fp.get();
            const deviceId = result.visitorId;

            const formData = new FormData(form);
            formData.append('device_id', deviceId);
            formData.append('type', type);

            btnIn.disabled = true;
            btnOut.disabled = true;
            const originalText = type === 'check_in' ? 'Check In' : 'Check Out';

            fetch('attendance.php?action=process_attendance', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    msgDiv.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'bg-emerald-50', 'text-emerald-700');
                    msgDiv.classList.add(data.success ? 'bg-emerald-100' : 'bg-red-100');
                    msgDiv.classList.add(data.success ? 'text-emerald-800' : 'text-red-800');
                    msgDiv.innerText = (data.success ? "✅ " : "❌ ") + data.message;
                    if(data.success) form.pin.value = "";
                })
                .catch(err => alert("Connection error! Make sure you are connected to internet."))
                .finally(() => {
                    btnIn.disabled = false;
                    btnOut.disabled = false;
                    msgDiv.classList.remove('hidden');
                });
        }
    </script>
</body>
</html>