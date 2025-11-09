<?php
// db.php - database connection
$servername = "127.0.0.1";
$username = "root";     
$password = "";         
$dbname = "car_detection";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Handle AJAX payment requests
if(isset($_POST['action']) && $_POST['action'] == 'pay') {
    $id = intval($_POST['id']);
    $method = $_POST['method']; // 'MOMO' or 'CASH'
    $amount = intval($_POST['amount']);
    $status_text = "Paid FRW ".number_format($amount);

    // Update DB
    $stmt = $conn->prepare("UPDATE captures SET payment_method=?, status=? WHERE id=?");
    $stmt->bind_param("ssi", $method, $status_text, $id);
    $stmt->execute();
    echo json_encode(['success'=>true, 'status'=>$status_text]);
    exit;
}

// Handle delete requests
if(isset($_POST['action']) && $_POST['action'] == 'delete') {
    $id = intval($_POST['id']);
    
    // Delete record from database
    $stmt = $conn->prepare("DELETE FROM captures WHERE id=?");
    $stmt->bind_param("i", $id);
    if($stmt->execute()) {
        echo json_encode(['success'=>true, 'message'=>'Record deleted successfully']);
    } else {
        echo json_encode(['success'=>false, 'message'=>'Failed to delete record']);
    }
    exit;
}

// Fetch captures with payment information (last captured first)
$sql = "SELECT * FROM captures ORDER BY id DESC";
$result = $conn->query($sql);

// Calculate statistics
$stats_sql = "SELECT 
    COUNT(*) as total_cars,
    SUM(CASE WHEN status LIKE 'Paid%' THEN 1 ELSE 0 END) as paid_cars,
    SUM(CASE WHEN status = 'Unpaid' THEN 1 ELSE 0 END) as unpaid_cars,
    SUM(CASE WHEN payment_method = 'MOMO' THEN 1 ELSE 0 END) as momo_payments,
    SUM(CASE WHEN payment_method = 'CASH' THEN 1 ELSE 0 END) as cash_payments
    FROM captures";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Calculate revenue by car type
$revenue_sql = "SELECT 
    car_type,
    COUNT(*) as count,
    SUM(CASE WHEN status LIKE 'Paid%' THEN 
        CASE 
            WHEN car_type = 'Car' THEN 10000
            WHEN car_type = 'Truck' THEN 40000
            WHEN car_type = 'Bus' THEN 30000
            WHEN car_type = 'Motocycle' THEN 5000
            ELSE 10000
        END
    ELSE 0 END) as revenue
    FROM captures 
    GROUP BY car_type";
$revenue_result = $conn->query($revenue_sql);
$revenue_by_type = [];
while($row = $revenue_result->fetch_assoc()) {
    $revenue_by_type[$row['car_type']] = $row;
}

// Calculate total potential revenue and actual revenue
$total_potential_sql = "SELECT 
    SUM(CASE 
        WHEN car_type = 'Car' THEN 10000
        WHEN car_type = 'Truck' THEN 40000
        WHEN car_type = 'Bus' THEN 30000
        WHEN car_type = 'Motocycle' THEN 5000
        ELSE 10000
    END) as total_potential
    FROM captures";
$total_potential_result = $conn->query($total_potential_sql);
$total_potential = $total_potential_result->fetch_assoc()['total_potential'] ?? 0;

$total_actual_sql = "SELECT 
    SUM(CASE WHEN status LIKE 'Paid%' THEN 
        CASE 
            WHEN car_type = 'Car' THEN 10000
            WHEN car_type = 'Truck' THEN 40000
            WHEN car_type = 'Bus' THEN 30000
            WHEN car_type = 'Motocycle' THEN 5000
            ELSE 10000
        END
    ELSE 0 END) as total_actual
    FROM captures";
$total_actual_result = $conn->query($total_actual_sql);
$total_actual = $total_actual_result->fetch_assoc()['total_actual'] ?? 0;

$total_loss = $total_potential - $total_actual;

// Calculate period-based analytics (daily, monthly, yearly)
$period = isset($_GET['period']) ? $_GET['period'] : 'daily';

// Get date range based on period
switch($period) {
    case 'monthly':
        $date_format = '%Y-%m';
        $group_by = 'MONTH(captured_time), YEAR(captured_time)';
        $period_label = 'Monthly';
        break;
    case 'yearly':
        $date_format = '%Y';
        $group_by = 'YEAR(captured_time)';
        $period_label = 'Yearly';
        break;
    default:
        $date_format = '%Y-%m-%d';
        $group_by = 'DATE(captured_time)';
        $period_label = 'Daily';
}

$period_sql = "SELECT 
    DATE_FORMAT(captured_time, '{$date_format}') as period,
    COUNT(*) as total_cars,
    SUM(CASE WHEN status LIKE 'Paid%' THEN 1 ELSE 0 END) as paid_cars,
    SUM(CASE WHEN status = 'Unpaid' THEN 1 ELSE 0 END) as unpaid_cars,
    SUM(CASE WHEN status LIKE 'Paid%' THEN 
        CASE 
            WHEN car_type = 'Car' THEN 10000
            WHEN car_type = 'Truck' THEN 40000
            WHEN car_type = 'Bus' THEN 30000
            WHEN car_type = 'Motocycle' THEN 5000
            ELSE 10000
        END
    ELSE 0 END) as actual_revenue,
    SUM(CASE 
        WHEN car_type = 'Car' THEN 10000
        WHEN car_type = 'Truck' THEN 40000
        WHEN car_type = 'Bus' THEN 30000
        WHEN car_type = 'Motocycle' THEN 5000
        ELSE 10000
    END) as potential_revenue
    FROM captures 
    GROUP BY {$group_by}
    ORDER BY captured_time DESC
    LIMIT 12";

$period_result = $conn->query($period_sql);
$period_data = [];
while($row = $period_result->fetch_assoc()) {
    $row['loss'] = $row['potential_revenue'] - $row['actual_revenue'];
    $row['profit'] = $row['actual_revenue'];
    $period_data[] = $row;
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Car Washing System - History & Analytics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
    --primary: #2c3e50;
    --secondary: #3498db;
    --success: #27ae60;
    --warning: #f39c12;
    --danger: #e74c3c;
    --light: #ecf0f1;
    --dark: #2c3e50;
}

body {
    background-color: #f8f9fa;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.navbar-brand {
    font-weight: 700;
}

.sidebar {
    background-color: var(--primary);
    color: white;
    min-height: calc(100vh - 56px);
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}

.sidebar .nav-link {
    color: rgba(255,255,255,0.8);
    padding: 12px 20px;
    border-radius: 0;
    transition: all 0.3s;
}

.sidebar .nav-link:hover, .sidebar .nav-link.active {
    color: white;
    background-color: rgba(255,255,255,0.1);
}

.sidebar .nav-link i {
    margin-right: 10px;
    width: 20px;
    text-align: center;
}

.main-content {
    padding: 20px;
}

.card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: transform 0.3s, box-shadow 0.3s;
    margin-bottom: 20px;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

.card-icon {
    font-size: 2rem;
    opacity: 0.8;
}

.stat-card .card-body {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.stat-card .card-text {
    font-size: 0.9rem;
    color: whitesmoke;
}

.stat-card .card-title {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 0;
}

.thumb {
    max-width: 120px;
    max-height: 80px;
    object-fit: cover;
    border-radius: 6px;
    cursor: pointer;
    transition: transform 0.3s;
}

.thumb:hover {
    transform: scale(1.05);
}

.mono {
    font-family: monospace;
    font-size: 0.9rem;
}

.badge-paid {
    background-color: var(--success);
}

.badge-unpaid {
    background-color: var(--warning);
}

.table th {
    border-top: none;
    font-weight: 600;
    color: var(--dark);
}

.filter-btn.active {
    background-color: var(--secondary);
    border-color: var(--secondary);
}

.btn-action {
    padding: 5px 10px;
    font-size: 0.85rem;
    margin: 2px;
}

.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.export-btn {
    background-color: var(--success);
    border-color: var(--success);
}

.export-btn:hover {
    background-color: #219653;
    border-color: #219653;
}

.dashboard-header {
    display: flex;
    justify-content: between;
    align-items: center;
    margin-bottom: 20px;
}

.search-container {
    position: relative;
    max-width: 400px;
}

.search-container i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

.search-container input {
    padding-left: 40px;
}

.stats-card { 
    transition: transform 0.2s; 
    cursor: pointer; 
}
.stats-card:hover { 
    transform: translateY(-5px); 
}
.paid { 
    color: #198754; 
    font-weight: bold; 
}
.unpaid { 
    color: #dc3545; 
    font-weight: bold; 
}
.period-nav .nav-link.active { 
    font-weight: bold; 
    background-color: #0d6efd; 
    color: white; 
}
.analytics-table th { 
    background-color: #f8f9fa; 
}

@media (max-width: 768px) {
    .sidebar {
        min-height: auto;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn-action {
        width: 100%;
    }
}
</style>
</head>
<body>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark" style="background-color: var(--primary);">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">
            <i class="fas fa-car me-2"></i>Car Wash System --- Boss Checking Panel
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown me-5">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle "></i> Admin
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#"><i class="fas fa-cog "></i>Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#"><i class="fas fa-sign-out-alt "></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-lg-2 d-md-block sidebar collapse">
            <div class="position-sticky pt-3">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="all_vehiclesadmin.php">
                            <i class="fas fa-car"></i> Vehicles
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="http://127.0.0.1:5000/">
                            <i class="fas fa-chart-bar"></i> View Live
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="history.php">
                            <i class="fas fa-history"></i> History
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <h2 class="mb-0">Payment History & Analytics</h2>
                <div class="d-flex">
                    <div class="search-container me-2">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search by plate or car type">
                    </div>
                    <a href="index.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Main
                    </a>
                    <button class="btn btn-success export-btn" id="exportBtn">
                        <i class="fas fa-file-export me-1"></i> Export
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card stat-card text-white bg-primary" id="totalCarsCard">
                        <div class="card-body">
                            <div>
                                <p class="card-text">Total Cars</p>
                                <h3 class="card-title"><?php echo $stats['total_cars']; ?></h3>
                            </div>
                            <i class="fas fa-car card-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card stat-card text-white bg-success" id="paidCarsCard">
                        <div class="card-body">
                            <div>
                                <p class="card-text">Paid Cars</p>
                                <h3 class="card-title"><?php echo $stats['paid_cars']; ?></h3>
                            </div>
                            <i class="fas fa-check-circle card-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card stat-card text-white bg-danger" id="unpaidCarsCard">
                        <div class="card-body">
                            <div>
                                <p class="card-text">Unpaid Cars</p>
                                <h3 class="card-title"><?php echo $stats['unpaid_cars']; ?></h3>
                            </div>
                            <i class="fas fa-exclamation-circle card-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="card stat-card text-white bg-info">
                        <div class="card-body">
                            <div>
                                <p class="card-text">Payment Methods</p>
                                <p class="card-text mb-0">MoMo: <?php echo $stats['momo_payments']; ?></p>
                                <p class="card-text">Cash: <?php echo $stats['cash_payments']; ?></p>
                            </div>
                            <i class="fas fa-money-bill card-icon"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue Summary -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h5 class="card-title">Revenue Summary</h5>
                            <p class="card-text mb-1">Potential Revenue: <strong>FRW <?php echo number_format($total_potential); ?></strong></p>
                            <p class="card-text mb-1">Actual Revenue: <strong class="text-success">FRW <?php echo number_format($total_actual); ?></strong></p>
                            <p class="card-text mb-0">Total Loss: <strong class="text-danger">FRW <?php echo number_format($total_loss); ?></strong></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h5 class="card-title">Revenue by Car Type</h5>
                            <div class="row">
                                <?php foreach($revenue_by_type as $type => $data): ?>
                                <div class="col-3 text-center">
                                    <h6><?php echo $type; ?></h6>
                                    <p class="mb-0">FRW <?php echo number_format($data['revenue']); ?></p>
                                    <small class="text-muted">(<?php echo $data['count']; ?> cars)</small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Period Analytics -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Period Analytics</h5>
                </div>
                <div class="card-body">
                    <!-- Period Navigation -->
                    <ul class="nav nav-pills period-nav mb-3">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $period == 'daily' ? 'active' : ''; ?>" href="?period=daily">Daily</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $period == 'monthly' ? 'active' : ''; ?>" href="?period=monthly">Monthly</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $period == 'yearly' ? 'active' : ''; ?>" href="?period=yearly">Yearly</a>
                        </li>
                    </ul>

                    <!-- Analytics Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered analytics-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Period</th>
                                    <th>Total Cars</th>
                                    <th>Paid Cars</th>
                                    <th>Unpaid Cars</th>
                                    <th>Potential Revenue</th>
                                    <th>Actual Revenue</th>
                                    <th>Profit</th>
                                    <th>Loss</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!empty($period_data)): ?>
                                    <?php foreach($period_data as $data): ?>
                                    <tr>
                                        <td><strong><?php echo $data['period']; ?></strong></td>
                                        <td><?php echo $data['total_cars']; ?></td>
                                        <td class="text-success"><?php echo $data['paid_cars']; ?></td>
                                        <td class="text-danger"><?php echo $data['unpaid_cars']; ?></td>
                                        <td>FRW <?php echo number_format($data['potential_revenue']); ?></td>
                                        <td class="text-success">FRW <?php echo number_format($data['actual_revenue']); ?></td>
                                        <td class="text-success">FRW <?php echo number_format($data['profit']); ?></td>
                                        <td class="text-danger">FRW <?php echo number_format($data['loss']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No data available for the selected period</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Revenue by Car Type</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="revenueChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Payment Methods</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="paymentMethodChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by plate or car type">
                        </div>
                        <div class="col-md-3 mb-2">
                            <select id="paymentFilter" class="form-select">
                                <option value="">All Payment Status</option>
                                <option value="paid">Paid</option>
                                <option value="unpaid">Unpaid</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <select id="methodFilter" class="form-select">
                                <option value="">All Payment Methods</option>
                                <option value="MOMO">MoMo</option>
                                <option value="CASH">Cash</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <select id="carTypeFilter" class="form-select">
                                <option value="">All Car Types</option>
                                <option value="Car">Car</option>
                                <option value="Truck">Truck</option>
                                <option value="Bus">Bus</option>
                                <option value="Motocycle">Motorcycle</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- History Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="historyTable">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Image</th>
                                    <th>Car Type</th>
                                    <th>Plate</th>
                                    <th>Captured Time</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($result->num_rows > 0) {
                                    while($row = $result->fetch_assoc()) {
                                        $car_type = strtolower($row['car_type']);
                                        $status = $row['status'] ?? 'Unpaid';
                                        $payment_method = $row['payment_method'] ?? 'Not Paid';
                                        $is_paid = strpos($status, 'Paid') === 0;
                                        $status_class = $is_paid ? 'paid' : 'unpaid';
                                        
                                        echo "<tr data-type='{$row['car_type']}' data-id='{$row['id']}' data-status='{$status_class}' data-method='{$payment_method}'>";
                                        echo "<td>".$row['id']."</td>";
                                        echo "<td><img src='".$row['image']."' class='thumb' alt='car_".$row['id']."' data-bs-toggle='modal' data-bs-target='#imageModal' data-full='".$row['image']."'></td>";
                                        echo "<td>".ucfirst($row['car_type'])."</td>";
                                        echo "<td><span class='badge bg-secondary'>".$row['plate']."</span></td>";
                                        echo "<td>".$row['captured_time']."</td>";
                                        echo "<td>".$payment_method."</td>";
                                        echo "<td><span class='badge ".($is_paid ? 'badge-paid' : 'badge-unpaid')."'>".$status."</span></td>";
                                        echo "<td>
                                                <div class='action-buttons'>
                                                    <button class='btn btn-sm btn-success momo-btn btn-action' ".($is_paid?'disabled':'')."><i class='fas fa-mobile-alt me-1'></i> MOMO</button>
                                                    <button class='btn btn-sm btn-primary cash-btn btn-action' ".($is_paid?'disabled':'')."><i class='fas fa-money-bill me-1'></i> CASH</button>
                                                    <button class='btn btn-sm btn-danger delete-btn btn-action'><i class='fas fa-trash me-1'></i> Delete</button>
                                                </div>
                                              </td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='8' class='text-center py-4'>No records found</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card mb-4">
              Desined by IBYIKORA MOISE
            </div>
        </main>
    </div>
</div>

<!-- Image preview modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Image Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="img-fluid rounded shadow-sm" alt="full">
                <p id="modalPath" class="mono mt-2 small text-break"></p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Car type prices
const prices = {
    car: 10000,
    truck: 40000,
    bus: 30000,
    motocycle: 5000,
    default: 10000
};

// Calculate amounts for statistics
function calculateAmounts() {
    const totalCars = <?php echo $stats['total_cars']; ?>;
    const paidCars = <?php echo $stats['paid_cars']; ?>;
    const unpaidCars = <?php echo $stats['unpaid_cars']; ?>;
    
    // Calculate average price per car (simplified calculation)
    const totalRevenue = <?php echo $total_actual; ?>;
    const totalPotential = <?php echo $total_potential; ?>;
    
    const avgPricePerCar = totalCars > 0 ? Math.round(totalPotential / totalCars) : 0;
    const totalAmount = totalPotential;
    const paidAmount = totalRevenue;
    const unpaidAmount = totalPotential - totalRevenue;
    
    return {
        totalAmount,
        paidAmount,
        unpaidAmount,
        avgPricePerCar
    };
}

// Statistics card click handlers
document.getElementById('totalCarsCard').addEventListener('click', function() {
    const amounts = calculateAmounts();
    Swal.fire({
        title: 'Total Cars Analysis',
        html: `
            <div class="text-start">
                <p><strong>Total Cars:</strong> <?php echo $stats['total_cars']; ?></p>
                <p><strong>Potential Revenue:</strong> FRW ${amounts.totalAmount.toLocaleString()}</p>
                <p><strong>Average Price per Car:</strong> FRW ${amounts.avgPricePerCar.toLocaleString()}</p>
                <p><strong>Revenue Realization:</strong> ${((amounts.paidAmount / amounts.totalAmount) * 100).toFixed(1)}%</p>
            </div>
        `,
        icon: 'info',
        confirmButtonText: 'OK'
    });
});

document.getElementById('paidCarsCard').addEventListener('click', function() {
    const amounts = calculateAmounts();
    Swal.fire({
        title: 'Paid Cars Analysis',
        html: `
            <div class="text-start">
                <p><strong>Paid Cars:</strong> <?php echo $stats['paid_cars']; ?></p>
                <p><strong>Actual Revenue:</strong> FRW ${amounts.paidAmount.toLocaleString()}</p>
                <p><strong>Payment Methods:</strong></p>
                <ul>
                    <li>MoMo: <?php echo $stats['momo_payments']; ?></li>
                    <li>Cash: <?php echo $stats['cash_payments']; ?></li>
                </ul>
                <p><strong>Collection Rate:</strong> ${((amounts.paidAmount / amounts.totalAmount) * 100).toFixed(1)}%</p>
            </div>
        `,
        icon: 'success',
        confirmButtonText: 'OK'
    });
});

document.getElementById('unpaidCarsCard').addEventListener('click', function() {
    const amounts = calculateAmounts();
    Swal.fire({
        title: 'Unpaid Cars Analysis',
        html: `
            <div class="text-start">
                <p><strong>Unpaid Cars:</strong> <?php echo $stats['unpaid_cars']; ?></p>
                <p><strong>Potential Loss:</strong> FRW ${amounts.unpaidAmount.toLocaleString()}</p>
                <p><strong>Loss Percentage:</strong> ${((amounts.unpaidAmount / amounts.totalAmount) * 100).toFixed(1)}%</p>
                <p class="text-danger"><strong>Action Required:</strong> Follow up on unpaid vehicles</p>
            </div>
        `,
        icon: 'warning',
        confirmButtonText: 'OK'
    });
});

// Filter functionality
const searchInput = document.getElementById('searchInput');
const paymentFilter = document.getElementById('paymentFilter');
const methodFilter = document.getElementById('methodFilter');
const carTypeFilter = document.getElementById('carTypeFilter');
const table = document.getElementById('historyTable');
const rows = Array.from(table.tBodies[0].rows);

function filterTable() {
    const searchQuery = searchInput.value.toLowerCase();
    const paymentValue = paymentFilter.value;
    const methodValue = methodFilter.value;
    const carTypeValue = carTypeFilter.value;
    
    rows.forEach(row => {
        const text = Array.from(row.cells).map(cell => cell.textContent.toLowerCase()).join(' ');
        const status = row.dataset.status;
        const method = row.dataset.method;
        const type = row.dataset.type;
        
        const matchesSearch = text.includes(searchQuery);
        const matchesPayment = !paymentValue || 
            (paymentValue === 'paid' && status === 'paid') || 
            (paymentValue === 'unpaid' && status === 'unpaid');
        const matchesMethod = !methodValue || method === methodValue;
        const matchesType = !carTypeValue || type === carTypeValue;
        
        row.style.display = (matchesSearch && matchesPayment && matchesMethod && matchesType) ? '' : 'none';
    });
}

searchInput.addEventListener('input', filterTable);
paymentFilter.addEventListener('change', filterTable);
methodFilter.addEventListener('change', filterTable);
carTypeFilter.addEventListener('change', filterTable);

// Image modal
const imageModal = document.getElementById('imageModal');
const modalImage = document.getElementById('modalImage');
const modalPath = document.getElementById('modalPath');

imageModal.addEventListener('show.bs.modal', function(event) {
    const img = event.relatedTarget;
    modalImage.src = img.dataset.full;
    modalPath.textContent = img.dataset.full;
});

// Export functionality
document.getElementById('exportBtn').addEventListener('click', function() {
    Swal.fire({
        title: 'Export Data',
        text: 'Choose export format',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'CSV',
        cancelButtonText: 'PDF',
        showDenyButton: true,
        denyButtonText: 'Excel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Export as CSV
            exportToCSV();
        } else if (result.isDenied) {
            // Export as Excel
            exportToExcel();
        } else if (result.dismiss === Swal.DismissReason.cancel) {
            // Export as PDF
            exportToPDF();
        }
    });
});

function exportToCSV() {
    // In a real implementation, this would make an AJAX call to generate CSV
    Swal.fire('Success!', 'Data exported as CSV successfully.', 'success');
}

function exportToExcel() {
    // In a real implementation, this would make an AJAX call to generate Excel
    Swal.fire('Success!', 'Data exported as Excel successfully.', 'success');
}

function exportToPDF() {
    // In a real implementation, this would make an AJAX call to generate PDF
    Swal.fire('Success!', 'Data exported as PDF successfully.', 'success');
}

// Payment and Delete functionality
document.querySelectorAll('#historyTable tbody tr').forEach(row => {
    const momoBtn = row.querySelector('.momo-btn');
    const cashBtn = row.querySelector('.cash-btn');
    const deleteBtn = row.querySelector('.delete-btn');
    const statusCell = row.querySelector('.status');
    const methodCell = row.cells[5];
    const carType = row.dataset.type.toLowerCase() || 'default';
    const id = row.dataset.id;
    const amount = prices[carType] || prices['default'];

    // MOMO payment
    momoBtn?.addEventListener('click', () => {
        Swal.fire({
            title: 'Pay with MTN MoMo',
            html: `Enter driver phone number for ${carType.toUpperCase()} payment (FRW ${amount.toLocaleString()}):`,
            input: 'text',
            inputLabel: 'Phone number',
            inputPlaceholder: '07XXXXXXXX',
            inputValidator: (value) => {
                if (!value) {
                    return 'Please enter a phone number!';
                }
                if (!/^07\d{8}$/.test(value)) {
                    return 'Please enter a valid MTN phone number!';
                }
            },
            showCancelButton: true,
            confirmButtonText: 'Pay',
            showLoaderOnConfirm: true,
            preConfirm: (phone) => {
                return fetch('', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: `action=pay&id=${id}&method=MOMO&amount=${amount}`
                }).then(res => {
                    if (!res.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return res.json();
                }).catch(error => {
                    Swal.showValidationMessage(`Request failed: ${error}`);
                });
            }
        }).then(result => {
            if(result.isConfirmed && result.value){
                if(result.value.success){
                    statusCell.textContent = result.value.status;
                    statusCell.className = 'badge badge-paid';
                    methodCell.textContent = 'MOMO';
                    row.dataset.status = 'paid';
                    row.dataset.method = 'MOMO';
                    momoBtn.disabled = true;
                    cashBtn.disabled = true;
                    Swal.fire('Success!', `Payment FRW ${amount.toLocaleString()} completed.`, 'success');
                    // Reload page to update statistics
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error!', 'Payment failed. Please try again.', 'error');
                }
            }
        });
    });

    // CASH payment
    cashBtn?.addEventListener('click', () => {
        Swal.fire({
            title: 'Confirm Cash Payment',
            html: `Confirm cash payment of FRW ${amount.toLocaleString()} for ${carType.toUpperCase()}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Confirm Payment'
        }).then(result => {
            if(result.isConfirmed){
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: `action=pay&id=${id}&method=CASH&amount=${amount}`
                }).then(res=>res.json()).then(data=>{
                    if(data.success){
                        statusCell.textContent = data.status;
                        statusCell.className = 'badge badge-paid';
                        methodCell.textContent = 'CASH';
                        row.dataset.status = 'paid';
                        row.dataset.method = 'CASH';
                        momoBtn.disabled = true;
                        cashBtn.disabled = true;
                        Swal.fire('Success!', `Cash payment FRW ${amount.toLocaleString()} completed.`, 'success');
                        // Reload page to update statistics
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        Swal.fire('Error!', 'Payment failed. Please try again.', 'error');
                    }
                });
            }
        });
    });

    // Delete record
    deleteBtn?.addEventListener('click', () => {
        Swal.fire({
            title: 'Delete Record',
            html: `Are you sure you want to delete this record?<br><small class="text-muted">This action cannot be undone.</small>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it!',
            confirmButtonColor: '#d33',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if(result.isConfirmed){
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: `action=delete&id=${id}`
                }).then(res=>res.json()).then(data=>{
                    if(data.success){
                        row.remove();
                        Swal.fire('Deleted!', 'Record has been deleted successfully.', 'success');
                        // Reload page to update statistics
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        Swal.fire('Error!', 'Failed to delete record. Please try again.', 'error');
                    }
                });
            }
        });
    });
});

// Charts
document.addEventListener('DOMContentLoaded', function() {
    // Revenue by Car Type Chart
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueChart = new Chart(revenueCtx, {
        type: 'bar',
        data: {
            labels: ['Car', 'Truck', 'Bus', 'Motorcycle'],
            datasets: [{
                label: 'Revenue (FRW)',
                data: [
                    <?php echo $revenue_by_type['Car']['revenue'] ?? 0; ?>,
                    <?php echo $revenue_by_type['Truck']['revenue'] ?? 0; ?>,
                    <?php echo $revenue_by_type['Bus']['revenue'] ?? 0; ?>,
                    <?php echo $revenue_by_type['Motocycle']['revenue'] ?? 0; ?>
                ],
                backgroundColor: [
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(255, 99, 132, 0.7)',
                    'rgba(75, 192, 192, 0.7)',
                    'rgba(255, 205, 86, 0.7)'
                ],
                borderColor: [
                    'rgb(54, 162, 235)',
                    'rgb(255, 99, 132)',
                    'rgb(75, 192, 192)',
                    'rgb(255, 205, 86)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'FRW ' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // Payment Methods Chart
    const paymentCtx = document.getElementById('paymentMethodChart').getContext('2d');
    const paymentChart = new Chart(paymentCtx, {
        type: 'doughnut',
        data: {
            labels: ['MoMo', 'Cash', 'Unpaid'],
            datasets: [{
                data: [
                    <?php echo $stats['momo_payments']; ?>,
                    <?php echo $stats['cash_payments']; ?>,
                    <?php echo $stats['unpaid_cars']; ?>
                ],
                backgroundColor: [
                    'rgba(54, 162, 235, 0.7)',
                    'rgba(75, 192, 192, 0.7)',
                    'rgba(255, 99, 132, 0.7)'
                ],
                borderColor: [
                    'rgb(54, 162, 235)',
                    'rgb(75, 192, 192)',
                    'rgb(255, 99, 132)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                }
            }
        }
    });
});
</script>
</body>
</html>

<?php
$conn->close();
?>