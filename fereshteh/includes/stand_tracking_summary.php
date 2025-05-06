<?php

/**
 * Stand Tracking Summary Component
 * 
 * This file creates a reusable component to display stand tracking summary information
 * Can be included in any page by requiring this file and calling displayStandSummary()
 */

/**
 * Get current stand tracking status
 * 
 * @param PDO $pdo Database connection
 * @return array Associative array with stand tracking information
 */
function getStandTrackingStatus($pdo)
{
    $result = [
        'total_stands' => 0,
        'total_sent' => 0,
        'total_returned' => 0,
        'currently_out' => 0,
        'currently_available' => 0
    ];

    try {
        // 1. Get Total Stands Setting
        $stmt_total = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'total_stands' LIMIT 1");
        $total_stands_result = $stmt_total->fetch(PDO::FETCH_ASSOC);
        if ($total_stands_result && is_numeric($total_stands_result['setting_value'])) {
            $result['total_stands'] = (int)$total_stands_result['setting_value'];
        } else {
            error_log("Setting 'total_stands' not found or invalid.");
        }

        // 2. Get Total Stands Sent
        $stmt_sent = $pdo->query("SELECT SUM(IFNULL(stands_sent, 0)) as total_sent FROM shipments");
        $total_sent_result = $stmt_sent->fetch(PDO::FETCH_ASSOC);
        $result['total_sent'] = ($total_sent_result && $total_sent_result['total_sent'] !== null) ?
            (int)$total_sent_result['total_sent'] : 0;

        // 3. Get Total Stands Returned
        $stmt_returned = $pdo->query("SELECT SUM(IFNULL(returned_count, 0)) as total_returned FROM stand_returns");
        $total_returned_result = $stmt_returned->fetch(PDO::FETCH_ASSOC);
        $result['total_returned'] = ($total_returned_result && $total_returned_result['total_returned'] !== null) ?
            (int)$total_returned_result['total_returned'] : 0;

        // Calculate current status
        $result['currently_out'] = max(0, $result['total_sent'] - $result['total_returned']); // Ensure non-negative
        $result['currently_available'] = max(0, $result['total_stands'] - $result['currently_out']); // Ensure non-negative

    } catch (PDOException $e) {
        error_log("Error fetching stand tracking status: " . $e->getMessage());
    }

    return $result;
}

/**
 * Display stand tracking summary box
 * 
 * @param PDO $pdo Database connection
 * @param bool $linkToTrackingPage Whether to include a link to the tracking page
 * @param array $customButton Optional custom button configuration [text, url, icon, class]
 * @return void Outputs HTML directly
 */
function displayStandSummary($pdo, $linkToTrackingPage = true, $customButton = null)
{
    $standStatus = getStandTrackingStatus($pdo);

    // CSS for the summary box
    echo '<style>
        .stand-tracking-info {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .stand-info-item {
            margin-right: 15px;
            white-space: nowrap;
        }
        .stand-info-item:first-child {
            margin-right: 0;
        }
        .stand-tracking-link {
            display: block;
            margin-top: 10px;
            font-size: 0.85rem;
            text-align: rigtht;
        }
    .stand-badgeb {
        font-size: 0.85em;
        padding: 12px;
        color: black;
        display: inline-block;
        min-width: 80px;
        text-align: center;
        border-radius: 8px;
        font-weight: 500;
        background-color: #f8f9fa;
        border: 1px solid #ced4da;
        cursor: pointer;
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    .stand-badgeb:hover {
        background-color: #e2e6ea;
        color: #212529;
    }
    </style>';

    // HTML for the summary box
    echo '<div class="alert alert-secondary stand-tracking-info mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="alert-heading" style="font-size: 1.1rem;"><i class="bi bi-pallet"></i> وضعیت خرک‌ها';

    // Add appropriate button based on configuration
    if ($customButton) {
        // Use custom button if provided
        $btnIcon = isset($customButton['icon']) ? $customButton['icon'] : 'bi-plus-circle';
        $btnClass = isset($customButton['class']) ? $customButton['class'] : 'btn-outline-primary';
        $btnUrl = isset($customButton['url']) ? $customButton['url'] : '#';
        $btnText = isset($customButton['text']) ? $customButton['text'] : 'اقدام';
        $btnType = isset($customButton['type']) && $customButton['type'] === 'button' ? 'button' : 'a';
        $btnAttr = isset($customButton['attributes']) ? $customButton['attributes'] : '';

        if ($btnType === 'button') {
            echo '<button type="button" class="stand-badgeb ' . $btnClass . '" ' . $btnAttr . '>
                    <i class="bi ' . $btnIcon . '"></i> ' . $btnText . '
                  </button></h5>';
        } else {
            echo '<a href="' . $btnUrl . '" class="stand-badgeb ' . $btnClass . '" ' . $btnAttr . '>
                    <i class="bi ' . $btnIcon . '"></i> ' . $btnText . '
                  </a></h5>';
        }
    }
    // Otherwise show the default management link if requested
    elseif ($linkToTrackingPage) {
        echo '<a href="stand_return_tracking.php" class="stand-badgeb">
                <i class="bi bi-arrow-left-circle"></i> مدیریت
              </a></h5>';
    }

    echo '</div>
        <hr>
        <p class="mb-0" style="font-size: 1rem;">
            <span class="stand-info-item">کل: <strong>' . escapeHtml($standStatus['total_stands']) . '</strong></span>
            <span class="stand-info-item">ارسالی (بیرون): <strong class="text-danger">' . escapeHtml($standStatus['currently_out']) . '</strong></span>
            <span class="stand-info-item">موجود (باقیمانده): <strong class="text-success">' . escapeHtml($standStatus['currently_available']) . '</strong></span>
        </p>
    </div>';
}
