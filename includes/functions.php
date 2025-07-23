<?php
// includes/functions.php
class ProgressCalculator
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calculates detailed progress for all elements in a given plan file.
     * Returns an array containing both the element list and the overall summary.
     *
     * @param string $planFile The name of the plan file (e.g., 'Zone01.svg').
     * @return array
     */
    public function getPlanProgress(string $planFile): array
    {
        $sql = "
            WITH CompletedStages AS (
                SELECT 
                    i.element_id, 
                    i.stage_id
                FROM inspections i
                WHERE (i.element_id, i.stage_id, i.created_at) IN (
                    SELECT element_id, stage_id, MAX(created_at) 
                    FROM inspections 
                    GROUP BY element_id, stage_id
                ) AND i.overall_status = 'OK'
            ),
            ElementProgress AS (
                SELECT
                    cs.element_id,
                    SUM(s.weight) AS progress_percent
                FROM CompletedStages cs
                JOIN inspection_stages s ON cs.stage_id = s.stage_id
                GROUP BY cs.element_id
            )
            SELECT
                e.element_id, e.element_type, e.zone_name, e.floor_level,
                e.plan_file, e.area_sqm, e.width_cm, e.height_cm, e.contractor, e.block,
                t.cost_per_unit, t.unit_of_measure,
                COALESCE(ep.progress_percent, 0) AS progress_percent
            FROM elements e
            LEFT JOIN ElementProgress ep ON e.element_id = ep.element_id
            JOIN checklist_templates t ON e.element_type = t.template_name -- Join to get cost data
            WHERE e.plan_file = :plan_file AND e.geometry_json IS NOT NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':plan_file' => $planFile]);
        $elements_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- Perform Calculations ---
        $total_possible_work_value = 0.0;
        $total_completed_work_value = 0.0;
        $total_possible_cost = 0.0;
        $earned_value = 0.0;

        foreach ($elements_data as &$element) {
            $area = floatval($element['area_sqm'] ?? 0);
            $progress = floatval($element['progress_percent'] ?? 0);
            $cost_per_unit = floatval($element['cost_per_unit'] ?? 0);

            // Here we can expand for different units like 'meter' if needed
            $measurable_unit = $area; // Assuming m² for now

            $element_total_cost = $measurable_unit * $cost_per_unit;
            $element_earned_value = $element_total_cost * ($progress / 100.0);

            $total_possible_work_value += $area;
            $total_completed_work_value += $area * ($progress / 100.0);
            $total_possible_cost += $element_total_cost;
            $earned_value += $element_earned_value;
        }
        unset($element); // Unset reference

        $overall_progress_percent = ($total_possible_work_value > 0)
            ? ($total_completed_work_value / $total_possible_work_value) * 100
            : 0;

        return [
            'elements' => $elements_data,
            'summary' => [
                'overall_progress' => $overall_progress_percent,
                'total_cost' => $total_possible_cost,
                'earned_value' => $earned_value,
                'total_area' => $total_possible_work_value
            ]
        ];
    }
}
/**
 * Translate status to Persian
 */
function translate_status($status)
{
    $translations = [
        'pending' => 'در انتظار',
        'ordered' => 'سفارش داده شده',
        'in_production' => 'در حال تولید',
        'produced' => 'تولید شده',
        'delivered' => 'تحویل داده شده'
    ];

    return $translations[$status] ?? $status;
}


function fetchAllTruckPanelData(PDO $pdo)
{
    // Get all trucks with shipment status
    $trucksStmt = $pdo->query("SELECT t.*, s.status as shipment_status FROM trucks t LEFT JOIN shipments s ON t.id = s.truck_id ORDER BY t.id DESC");
    $trucks = $trucksStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all available panels
    $availablePanelsStmt = $pdo->query("
        SELECT id, address, type, area, width, length, status
        FROM hpc_panels
        WHERE status = 'completed'
        AND concrete_end_time IS NOT NULL
        AND (packing_status = 'pending' OR packing_status IS NULL)
        ORDER BY id
    ");
    $availablePanels = $availablePanelsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all assigned panels
    $assignedPanelsStmt = $pdo->query("
        SELECT p.id, p.address, p.type, p.width, p.length, p.truck_id, p.packing_status, t.truck_number
        FROM hpc_panels p
        JOIN trucks t ON p.truck_id = t.id
        WHERE p.truck_id IS NOT NULL  
        ORDER BY t.truck_number, p.id
    ");
    $assignedPanels = $assignedPanelsStmt->fetchAll(PDO::FETCH_ASSOC);


    // Group assigned panels by truck
    $panelsByTruck = [];
    foreach ($assignedPanels as $panel) {
        $truckId = $panel['truck_id'];
        if (!isset($panelsByTruck[$truckId])) {
            $panelsByTruck[$truckId] = [];
        }
        $panelsByTruck[$truckId][] = $panel;
    }
    // *** Get the count of completed panels ***
    $stmt = $pdo->query("SELECT COUNT(*) AS completed_count FROM hpc_panels WHERE status = 'completed'
        AND concrete_end_time IS NOT NULL
        AND (packing_status = 'pending' OR packing_status IS NULL)");
    $completedCountResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $completedCount = $completedCountResult['completed_count'];

    return [
        'trucks' => $trucks,
        'availablePanels' => $availablePanels,
        'panelsByTruck' => $panelsByTruck,
        'completedCount' => $completedCount,
    ];
}
/**
 * Secure file upload function
 */
function secure_file_upload($file, $allowed_types, $upload_dir)
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'An upload error occurred. Error code: ' . $file['error']];
    }

    $file_tmp = $file['tmp_name'];
    $file_name = $file['name'];
    $file_size = $file['size'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    // Validate file type
    if (!in_array($file_ext, $allowed_types)) {
        return ['error' => 'نوع فایل مجاز نیست'];
    }

    // Validate file size
    if ($file_size > MAX_FILE_SIZE) {
        return ['error' => 'File size exceeds the limit.'];
    }

    // Ensure unique filename
    $target_path = $upload_dir . $file_name;
    $file_name_without_ext = pathinfo($file_name, PATHINFO_FILENAME);
    $counter = 1;
    while (file_exists($target_path)) {
        $new_file_name = $file_name_without_ext . '_' . $counter . '.' . $file_ext;
        $target_path = $upload_dir . $new_file_name;
        $counter++;
    }

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Check for file existence before moving
    if (!file_exists($file_tmp)) {
        return ['error' => 'Uploaded file not found.'];
    }

    // Move file
    if (move_uploaded_file($file_tmp, $target_path)) {
        return [
            'success' => true,
            'file_path' => $target_path,
            'file_name' => basename($target_path)
        ];
    }

    return ['error' => 'خطا در آپلود فایل'];
}

/**
 * Get user role permissions.  Uses 'array_key_exists' for robustness.
 */
function get_user_permissions($role)
{
    // Define ALL possible permissions with default values (false)
    $default_permissions = [
        'view_all' => false,
        'edit_all' => false,
        'delete_all' => false,
        'upload_files' => false,
        'download_files' => false, // Everyone should be able to download
        'update_status' => false,
        'view_orders' => false,
        'update_dates' => false, // Make sure to include this
        // ... add any other permissions you might have or add later
    ];

    // Define role-specific permissions (overrides defaults)
    $permissions = [
        'admin' => [
            'view_all' => true,
            'edit_all' => true,
            'delete_all' => true,
            'upload_files' => true,
            'download_files' => true, // keep it
            'update_status' => true,
            'view_orders' => true,
            'update_dates' => true, // Add missing permissions

        ],
        'supervisor' => [
            'view_all' => true,
            'edit_all' => true,
            'upload_files' => true,
            'download_files' => true, // keep it
            'update_status' => true,
            'view_orders' => true,
            'update_dates' => true, // Add missing permissions
        ],
        'planner' => [
            'view_assigned' => true,  // Keep this, as it's different from view_all
            'edit_all' => true, // Add/Correct this
            'upload_files' => true,
            'download_files' => true,  // keep it
            'update_status' => true,
            'view_orders' => false,
            'update_dates' => true,   // Add this
        ],
        'cnc_operator' => [
            'view_orders' => true,
            'download_files' => true, // keep it
            'update_status' => true,
            'update_dates' => true,   // keep
            // Don't add edit_all: false here. Let it inherit from defaults.
        ],
        'user' => [
            'view_assigned' => false,
            'download_files' => true, // keep it
            'update_status' => false,
            'view_orders' => false,
            'update_dates' => false, // Add missing permissions
        ]
    ];

    // Get the role's permissions, or default to 'user' if the role is invalid
    $role_permissions = $permissions[$role] ?? $permissions['user'];

    // Merge the role-specific permissions with the defaults
    // Role-specific permissions will OVERWRITE the defaults.
    return array_merge($default_permissions, $role_permissions);
}


/**
 * Log activity
 */

/**
 * Format file size
 */
function format_file_size($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return number_format($bytes) . ' bytes';
    }
}

/**
 * Format Jalali date nicely
 */
function format_jalali_date($date)
{
    if (empty($date)) return '';
    return jdate("Y/m/d", strtotime($date));
}

/**
 * Validate and sanitize input
 */
function sanitize_input($input)
{
    if (is_array($input)) {
        return array_map('sanitize_input', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get Sarotah details
 */
function get_sarotah_details($pdo, $address)
{
    $stmt = $pdo->prepare("SELECT left_panel, right_panel FROM sarotah WHERE left_panel LIKE ? OR right_panel LIKE ?");
    $stmt->execute(["$address%", "$address%"]); // Use % for partial matching
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function formatJalaliDateOrEmpty($date)
{
    if (empty($date)) {
        return ''; // Or return 'N/A' if you prefer
    }
    return jdate("Y/m/d", strtotime($date));
}
function get_status_color($status)
{
    $colors = [
        'pending' => 'warning',
        'ordered' => 'info',
        'in_production' => 'primary',
        'produced' => 'success',
        'delivered' => 'secondary',
    ];
    return $colors[$status] ?? 'light';
}
