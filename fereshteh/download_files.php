<?php
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once 'includes/jdf.php';
require_once 'includes/functions.php';

function validateAndGetFilePath($filePath) {
    $baseDir = __DIR__ . '/uploads/';
    $realPath = realpath($baseDir . $filePath);

    if ($realPath && strpos($realPath, $baseDir) === 0 && file_exists($realPath)) {
        return $filePath;
    }
    return false;
}

$pageTitle = 'اپراتور CNC - دانلود فایل‌ها'; // Persian title
require_once 'header.php';

secureSession();

// --- Authentication and Authorization ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pdo = getProjectDBConnection();

// Get user role and permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userRole = $stmt->fetchColumn();
$userPermissions = get_user_permissions($userRole);

// Check if the user has permission to view orders
if (!isset($userPermissions['view_orders']) || !$userPermissions['view_orders']) {
  die("شما مجوز دسترسی به این صفحه را ندارید."); // Persian error message
}

// --- Handle Status Updates ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);
     $transactionStarted = false;

        if (!$userPermissions['update_status']) {
            $error = "You do not have permission to update Status.";
        }
        elseif ($orderId && $newStatus) {
            $allowedStatuses = ['pending', 'ordered', 'in_production', 'produced', 'delivered'];

            if (!in_array($newStatus, $allowedStatuses)) {
                $error = "Invalid status.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $transactionStarted = true;

                    $stmt = $pdo->prepare("UPDATE polystyrene_orders SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$newStatus, $orderId]);

                    $pdo->commit();
                    $message = "Status updated successfully.";
                    log_activity($_SESSION['user_id'], 'status_update', "Order ID: $orderId, New Status: $newStatus");

                } catch (PDOException $e) {
                    if ($transactionStarted) { // Check flag before rollback
                        $pdo->rollBack();
                    }
                    $error = "Database error: " . $e->getMessage();
                    logError("Database error (Status Update): " . $e->getMessage());
                }
            }
        } else {
            $error = "Invalid order ID or status.";
        }
}

// --- Retrieve Ordered Panels and File Information ---
$sql = "SELECT
            po.id AS order_id,
            po.order_type,
            po.status AS order_status,
            po.required_delivery_date,
            hp.address AS panel_address,
            pf.file_path,
            pf.upload_date,
            u.username AS uploaded_by
        FROM polystyrene_orders po
        JOIN hpc_panels hp ON po.hpc_panel_id = hp.id
        LEFT JOIN polystyrene_files pf ON po.id = pf.order_id
        LEFT JOIN users u ON pf.uploaded_by = u.id
        WHERE po.status = 'ordered'
        ORDER BY po.required_delivery_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch into $orders

//CSRF Token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <!-- Add any other CSS you need here -->
     <style>
        /* Base Styles */
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }

        body {
            font-family: 'Vazir', sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <h1><?= $pageTitle ?></h1>

    <?php if (isset($message)): ?>
        <div class="alert alert-success"><?= escapeHtml($message) ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
    <?php endif; ?>

    <?php if (count($orders) > 0): ?>
      <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>شناسه سفارش</th>
                    <th>آدرس پنل</th>
                    <th>نوع سفارش</th>
                    <th>تاریخ تحویل درخواستی</th>
                    <th>فایل</th>
                    <th>آپلود شده توسط</th>
                    <th>تاریخ آپلود</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= escapeHtml($order['order_id']) ?></td>
                        <td><?= escapeHtml($order['panel_address']) ?></td>
                        <td><?= escapeHtml($order['order_type'] == 'east' ? 'شرقی' : 'غربی') ?></td>
                        <td><?= escapeHtml(formatJalaliDateOrEmpty($order['required_delivery_date'])) ?></td>
                        <td>
                            <?php 
                            $validFilePath = validateAndGetFilePath($order['file_path']);
                            if ($validFilePath): 
                            ?>
                                <a href="/<?= escapeHtml($validFilePath) ?>" download class="btn btn-sm btn-primary">
                                    <i class="fas fa-download"></i> دانلود فایل
                                </a>
                            <?php else: ?>
                                <span class="text-danger">فایل پیدا نشد یا قابل دسترسی نیست</span>
                                <?php error_log("Invalid file path for order ID " . $order['order_id']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                           <?= escapeHtml($order['uploaded_by']) ?>
                        </td>
                         <td>
                           <?= escapeHtml(format_jalali_date($order['upload_date'])) ?>
                        </td>
                        <td>
                            <span class="badge bg-info"><?= escapeHtml(translate_status($order['order_status'])) ?></span>
                        </td>
                        <td>
                           <?php if ($userPermissions['update_status']) : ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                                <select name="new_status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                    <option value="">-- انتخاب وضعیت --</option>
                                    <option value="pending" <?= $order['order_status'] == 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                    <option value="ordered" <?= $order['order_status'] == 'ordered' ? 'selected' : '' ?>>سفارش شده</option>
                                    <option value="in_production" <?= $order['order_status'] == 'in_production' ? 'selected' : '' ?>>در حال تولید</option>
                                     <option value="produced" <?= $order['order_status'] == 'produced' ? 'selected' : '' ?>>تولید شده</option>
                                    <option value="delivered" <?= $order['order_status'] == 'delivered' ? 'selected' : '' ?>>تحویل شده</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>
                        <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
      </div>
    <?php else: ?>
        <p>هیچ سفارشِ در حال انتظاری یافت نشد.</p>
    <?php endif; ?>
</div>
<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>