<?php
// analytics.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db_connect.php';
session_start();

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['current_store'])) {
    header("Location: index.php");
    exit;
}

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

$storeid = $current_store_id;  // ✅ Line 15 ki jagah yeh line


// Handle AJAX request for chart data
if (isset($_GET['action']) && $_GET['action'] == 'get_analytics_data') {
    header('Content-Type: application/json');
    
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $period = $_GET['period'] ?? 'daily';

    // Order Status Analysis Data - CORRECTED: 'tagged' changed to 'Ready'
    if ($period === 'daily') {
        $query = "
            SELECT 
                DATE(order_date) as order_day,
                COUNT(*) as total_orders,
                
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'Ready' THEN 1 ELSE 0 END) as Ready,
				SUM(CASE WHEN status = 'delivered' OR delivered_datetime IS NOT NULL THEN 1 ELSE 0 END) as delivered,
                SUM(total_amount) as total_sales_amount
            FROM orders 
            WHERE storeid = ? 
            AND DATE(order_date) BETWEEN ? AND ?
            GROUP BY DATE(order_date)
            ORDER BY order_date
        ";
    } elseif ($period === 'weekly') {
        $query = "
            SELECT 
                YEARWEEK(order_date) as week_number,
                CONCAT('Week ', WEEK(order_date)) as order_week,
                COUNT(*) as total_orders,
                
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'Ready' THEN 1 ELSE 0 END) as Ready,
				SUM(CASE WHEN status = 'delivered' OR delivered_datetime IS NOT NULL THEN 1 ELSE 0 END) as delivered,
                SUM(total_amount) as total_sales_amount
            FROM orders 
            WHERE storeid = ? 
            AND DATE(order_date) BETWEEN ? AND ?
            GROUP BY YEARWEEK(order_date)
            ORDER BY order_date
        ";
    } else { // monthly
        $query = "
            SELECT 
                DATE_FORMAT(order_date, '%Y-%m') as month_key,
                DATE_FORMAT(order_date, '%b %Y') as order_month,
                COUNT(*) as total_orders,
            
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'Ready' THEN 1 ELSE 0 END) as Ready,
				    SUM(CASE WHEN status = 'delivered' OR delivered_datetime IS NOT NULL THEN 1 ELSE 0 END) as delivered,
                SUM(total_amount) as total_sales_amount
            FROM orders 
            WHERE storeid = ? 
            AND DATE(order_date) BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(order_date, '%Y-%m')
            ORDER BY order_date
        ";
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $storeid, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [
        'dates' => [],
        
        'processing' => [],
        'Ready' => [],
		'delivered' => [],
        'revenue' => [],
        'sales_amount' => []
    ];

    while ($row = $result->fetch_assoc()) {
        if ($period === 'daily') {
            $data['dates'][] = $row['order_day'];
        } elseif ($period === 'weekly') {
            $data['dates'][] = $row['order_week'];
        } else {
            $data['dates'][] = $row['order_month'];
        }
        
       
        $data['processing'][] = (int)$row['processing'];
        $data['Ready'][] = (int)$row['Ready'];
		 $data['delivered'][] = (int)$row['delivered'];
        $data['sales_amount'][] = (float)$row['total_sales_amount'];
    }

    // Payments table se revenue data
    if ($period === 'daily') {
        $revenue_query = "
            SELECT 
                DATE(payment_date) as payment_day,
                SUM(Paid_Amount) as total_revenue
            FROM payments 
            WHERE storeid = ? 
            AND DATE(payment_date) BETWEEN ? AND ?
            GROUP BY DATE(payment_date)
            ORDER BY payment_date
        ";
    } elseif ($period === 'weekly') {
        $revenue_query = "
            SELECT 
                YEARWEEK(payment_date) as week_number,
                CONCAT('Week ', WEEK(payment_date)) as payment_week,
                SUM(Paid_Amount) as total_revenue
            FROM payments 
            WHERE storeid = ? 
            AND DATE(payment_date) BETWEEN ? AND ?
            GROUP BY YEARWEEK(payment_date)
            ORDER BY payment_date
        ";
    } else { // monthly
        $revenue_query = "
            SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as month_key,
                DATE_FORMAT(payment_date, '%b %Y') as payment_month,
                SUM(Paid_Amount) as total_revenue
            FROM payments 
            WHERE storeid = ? 
            AND DATE(payment_date) BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
            ORDER BY payment_date
        ";
    }

    $revenue_stmt = $conn->prepare($revenue_query);
    $revenue_stmt->bind_param("iss", $storeid, $start_date, $end_date);
    $revenue_stmt->execute();
    $revenue_result = $revenue_stmt->get_result();

    $revenue_data = [];
    while ($row = $revenue_result->fetch_assoc()) {
        if ($period === 'daily') {
            $date_key = $row['payment_day'];
        } elseif ($period === 'weekly') {
            $date_key = $row['payment_week'];
        } else {
            $date_key = $row['payment_month'];
        }
        $revenue_data[$date_key] = (float)$row['total_revenue'];
    }

    // Orders data ke saath revenue data match karein
    foreach ($data['dates'] as $index => $date) {
        $data['revenue'][] = $revenue_data[$date] ?? 0;
    }

    // Agar koi data nahi mila toh empty arrays return karein
    if (empty($data['dates'])) {
        $data = [
            'dates' => ['No Data'],
			'processing' => [0],
			'Ready' => [0],
            'delivered' => [0],
            
            
            'revenue' => [0],
            'sales_amount' => [0]
        ];
    }

    echo json_encode(["status"=>"success", "data"=>$data]);
    $stmt->close();
    $revenue_stmt->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics | Fabrico</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .time-buttons {
            display: flex;
            gap: 10px;
            background: #f8f9fa;
            padding: 5px;
            border-radius: 10px;
        }
		
		.time-btn:hover{
			background: #00aaff;
		}
		
        .time-btn {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            background: transparent;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }

        .time-btn.active {
            background: #00aaff;
            color: white;
        }

        .date-range {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
            flex-wrap: wrap;
        }

        .date-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .chart-container {
            margin-top: 30px;
            position: relative;
            height: 400px;
            width: 95%;
            margin-left: auto;
            margin-right: auto;
        }

        .chart-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        

        .revenue-btn {
            padding: 6px 16px;
            border: none;
            border-radius: 6px;
            background: transparent;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s;
        }

        .revenue-btn.active {
            background: #00aaff;
            color: white;
        }

       

        .sales-trend-btn {
            padding: 6px 16px;
            border: none;
            border-radius: 6px;
            background: transparent;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s;
        }

        .sales-trend-btn.active {
            background: #00aaff;
            color: white;
        }

        .footer-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 14px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
            display: none;
        }

        @media (max-width: 768px) {
            .content-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-range {
                flex-direction: column;
                width: 100%;
            }
            
            .chart-container {
                width: 100%;
                height: 350px;
            }

            .chart-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }

        @media (max-width: 480px) {
            .footer-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .chart-container {
                height: 300px;
            }
        }
    </style>
</head>
<body>
<div class="main-content"> 
    <?php include 'menu.php'; ?>
   <br>
    <div class="container">
        <div class="content">
            <div class="content-header">
                <div class="time-buttons">
                    <button class="time-btn active" data-period="daily">Daily</button>
                    <button class="time-btn" data-period="weekly">Weekly</button>
                    <button class="time-btn" data-period="monthly">Monthly</button>
                </div>
                <div class="date-range">
                    <span>Select Date Range</span>
                    <input type="date" class="date-input" id="startDate" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                    <span>-</span>
                    <input type="date" class="date-input" id="endDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <!-- Order Status Analysis Chart -->
            <div class="chart-card">
                <div class="chart-title">ORDER STATUS ANALYSIS</div>
                <div class="chart-container">
                    <canvas id="orderStatusChart"></canvas>
                </div>
                <div id="chartLoading" class="loading">Loading data from database...</div>
            </div>

            <!-- Sales Trend Line Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <div class="chart-title" style="margin-bottom: 0;">SALES TREND</div>
                  

				  
					
					
                </div>
                <div class="chart-container">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>

            <!-- Payments Trend Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <div class="chart-title" style="margin-bottom: 0;">PAYMENTS TREND</div>
                  

				
					
					
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div class="footer-info">               
                <div>
                    <span><?php echo date('d-m-Y'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        let orderStatusChart, salesTrendChart, revenueChart;
        let currentPeriod = 'daily';
        let currentRevenuePeriod = 'daily';
        let currentSalesTrendPeriod = 'daily';

        // Initialize charts
        function initializeCharts() {
            // Order Status Chart
            const orderStatusCtx = document.getElementById('orderStatusChart').getContext('2d');
            orderStatusChart = new Chart(orderStatusCtx, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [
                        {
                            
							
							label: 'Processing',
                            data: [],
                            backgroundColor: 'rgba(255, 193, 7, 0.8)',
                            borderColor: 'rgba(255, 193, 7, 1)',
                            borderWidth: 1
							
                        },
                        {
                            label: 'Ready',
                            data: [],
                            backgroundColor: 'rgba(23, 162, 184, 0.8)',
                            borderColor: 'rgba(23, 162, 184, 1)',
                            borderWidth: 1
                        },
                        {
                            						
							label: 'Delivered',
                            data: [],
                            backgroundColor: 'rgba(40, 167, 69, 0.8)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 1
							
							
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Orders'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    return `Date: ${context[0].label}`;
                                },
                                label: function(context) {
                                    const datasetLabel = context.dataset.label || '';
                                    const value = context.parsed.y;
                                    return `${datasetLabel}: ${value} orders`;
                                },
                                afterLabel: function(context) {
                                    const datasets = context.chart.data.datasets;
                                    const currentIndex = context.dataIndex;
                                    
                                  
                                    const processing = datasets[0].data[currentIndex];
                                    const ready = datasets[1].data[currentIndex];
									  const delivered = datasets[2].data[currentIndex];
                                    const total = delivered + processing + ready;
									
                                    
                                    return [
                                        `---`,
                                        `Total Orders: ${total}`,
                                        
                                        `Processing: ${processing}`,
                                        `Ready: ${ready}`,
										`Delivered: ${delivered}`
                                    ];
                                }
                            }
                        },
                        legend: {
                            position: 'top',
                        }
                    }
                }
            });

            // Sales Trend Chart
            const salesTrendCtx = document.getElementById('salesTrendChart').getContext('2d');
            salesTrendChart = new Chart(salesTrendCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Sales Amount (₹)',
                        data: [],
                        backgroundColor: 'rgba(40, 167, 69, 0.2)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Sales Amount (₹)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Sales: ₹${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            });

            // Revenue Chart
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            revenueChart = new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Revenue (₹)',
                        data: [],
                        backgroundColor: 'rgba(118, 75, 162, 0.2)',
                        borderColor: 'rgba(118, 75, 162, 1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Revenue (₹)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Revenue: ₹${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Fetch data function
        async function fetchAnalyticsData(startDate, endDate, period = 'daily') {
            try {
                document.getElementById('chartLoading').style.display = 'block';
                
                const response = await fetch(`?action=get_analytics_data&start_date=${startDate}&end_date=${endDate}&period=${period}`);
                const result = await response.json();
                
                document.getElementById('chartLoading').style.display = 'none';
                
                if (result.status === "success") {
                    updateChartsWithRealData(result.data);
                } else {
                    console.error('Error fetching data:', result.message);
                    alert('Data load nahi ho paya: ' + result.message);
                }
            } catch (error) {
                document.getElementById('chartLoading').style.display = 'none';
                console.error('Data fetch error:', error);
                alert('Network error: Data fetch nahi ho paya');
            }
        }

        // Update charts with real data
        function updateChartsWithRealData(orderData) {
            console.log("Received Data:", orderData); // Debugging ke liye
            
            // Update Order Status Chart
            orderStatusChart.data.labels = orderData.dates;
            
            orderStatusChart.data.datasets[0].data = orderData.processing;
            orderStatusChart.data.datasets[1].data = orderData.Ready;
			orderStatusChart.data.datasets[2].data = orderData.delivered;
            orderStatusChart.update();
            
            // Update Sales Trend Chart
            salesTrendChart.data.labels = orderData.dates;
            salesTrendChart.data.datasets[0].data = orderData.sales_amount;
            salesTrendChart.update();
            
            // Update Revenue Chart
            revenueChart.data.labels = orderData.dates;
            revenueChart.data.datasets[0].data = orderData.revenue;
            revenueChart.update();
        }

        // Event listeners
        document.querySelectorAll('.time-btn').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('.time-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                currentPeriod = this.dataset.period;
                const startDate = document.getElementById('startDate').value;
                const endDate = document.getElementById('endDate').value;
                
                fetchAnalyticsData(startDate, endDate, currentPeriod);
            });
        });

        // Sales trend buttons
        document.querySelectorAll('.sales-trend-btn').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('.sales-trend-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                currentSalesTrendPeriod = this.dataset.salesPeriod;
                const startDate = document.getElementById('startDate').value;
                const endDate = document.getElementById('endDate').value;
                fetchAnalyticsData(startDate, endDate, currentPeriod);
            });
        });

        // Revenue buttons
        document.querySelectorAll('.revenue-btn').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('.revenue-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                currentRevenuePeriod = this.dataset.revenuePeriod;
                const startDate = document.getElementById('startDate').value;
                const endDate = document.getElementById('endDate').value;
                fetchAnalyticsData(startDate, endDate, currentPeriod);
            });
        });

        // Date range change handler
        document.getElementById('startDate').addEventListener('change', updateData);
        document.getElementById('endDate').addEventListener('change', updateData);

        function updateData() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            fetchAnalyticsData(startDate, endDate, currentPeriod);
        }

        // Page load par data fetch karein
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            fetchAnalyticsData(startDate, endDate, currentPeriod);
        });
    </script>
</div>
</body>
</html>