<?php
// admin_assign_date.php


require_once __DIR__ . '/../../sercon/config_fereshteh.php';

// --- Capacities & Helpers (Unchanged) ---
$formworkDailyCapacity = ['F-H-1' => 1, 'F-H-2' => 3, 'F-H-3' => 2, 'F-H-4' => 1, 'F-H-5' => 2, 'F-H-6' => 2, 'F-H-7' => 2, 'F-H-8' => 1, 'F-H-9' => 2, 'F-H-10' => 1, 'F-H-11' => 1, 'F-H-12' => 1, 'F-H-13' => 1, 'F-H-14' => 1, 'F-H-15' => 1, 'F-H-16' => 1];
function getBaseFormworkType($type)
{
    if (!$type) return null;
    $trimmedType = trim((string)$type);
    $parts = explode('-', $trimmedType);
    if (count($parts) >= 3 && strtoupper($parts[0]) === 'F' && strtoupper($parts[1]) === 'H' && is_numeric($parts[2])) {
        return $parts[0] . '-' . $parts[1] . '-' . $parts[2];
    }
    return null;
}

// List of progress columns to check/clear
$progressColumns = [
    'formwork_start_time',
    'formwork_end_time',
    'assembly_start_time',
    'assembly_end_time',
    'mesh_start_time',
    'mesh_end_time',
    'concrete_start_time',
    'concrete_end_time'
];


// --- PanelScheduler Class (Modified scheduleMultiplePanels) ---
class PanelScheduler
{
    private PDO $pdo;
    private int $userId;
    private array $dailyProcessCapacities;
    private array $progressCols;

    public function __construct(PDO $pdo, ?int $userId, array $dailyProcessCapacities, array $progressCols)
    {
        $this->pdo = $pdo;
        $this->userId = (int) $userId;
        $this->dailyProcessCapacities = $dailyProcessCapacities;
        $this->progressCols = $progressCols;
    }

    // --- getAvailableInstanceCounts, getAssignmentsCountByBaseTypeAndDate, checkPanelsProgress  ---
    /**
     * Fetches the *currently available* counts for each formwork type.
     * Reads from the `available_formworks` table.
     * Only includes types with an available_count > 0.
     *
     * @return array<string, int> Associative array [formwork_type => available_count].
     */
    private function getAvailableInstanceCounts(): array
    {
        $currentAvailableCounts = [];
        // Initialize with 0 for all types defined in daily capacity limits
        // This ensures the array always contains keys for all relevant types.
        foreach (array_keys($this->dailyProcessCapacities) as $baseType) {
            $currentAvailableCounts[$baseType] = 0;
        }

        try {
            // Query the available_formworks table for types with > 0 available
            // Fetch directly into a key-pair array [formwork_type => available_count]
            $stmt = $this->pdo->query("SELECT formwork_type, available_count FROM available_formworks WHERE available_count > 0");
            $dbCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Merge the results from the database into our initialized array
            // This overwrites the initial 0 with the actual count if found in DB and > 0
            foreach ($dbCounts as $type => $count) {
                // We only care about types that have a defined daily process limit
                // If a type exists in available_formworks but not in daily limits, it won't be scheduled anyway.
                if (array_key_exists($type, $currentAvailableCounts)) {
                    $currentAvailableCounts[$type] = (int)$count;
                }
                // Optionally log types available but without daily limit configured
                // else { error_log("Notice: Formwork type '{$type}' has available instances but no defined daily process limit."); }
            }
        } catch (PDOException $e) {
            error_log("DB Error in getAvailableInstanceCounts: " . $e->getMessage());
            // Return the initialized (mostly zeros) array on error, preventing scheduling
        }
        return $currentAvailableCounts;
    }

    /**
     * Counts the number of panels assigned per base formwork type per date within a range.
     * Reads from the `hpc_panels` table.
     *
     * @param string $startDate Start date in 'Y-m-d' format.
     * @param string $endDate End date in 'Y-m-d' format.
     * @return array<string, array<string, int>> Nested array [base_type => [date => usage_count]].
     */
    private function getAssignmentsCountByBaseTypeAndDate(string $startDate, string $endDate): array
    {
        $usage = [];
        try {
            // Prepare statement to count assignments grouped by base type and date
            // Uses the actual formwork_type column which should store the base type now
            $stmt = $this->pdo->prepare(
                "SELECT formwork_type, assigned_date, COUNT(*) as usage_count
                 FROM hpc_panels
                 WHERE assigned_date BETWEEN :startDate AND :endDate
                   AND formwork_type IS NOT NULL
                   AND assigned_date IS NOT NULL
                 GROUP BY formwork_type, assigned_date"
            );
            $stmt->execute([':startDate' => $startDate, ':endDate' => $endDate]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                // Assuming formwork_type in hpc_panels *is* the base type (e.g., 'F-H-1')
                // If it might contain specific instance names, you'd need getBaseFormworkType here too.
                $baseType = $row['formwork_type'];
                if (!$baseType) continue; // Skip if type is somehow null/empty

                if (!isset($usage[$baseType])) {
                    $usage[$baseType] = [];
                }
                // Store count for the specific date
                $usage[$baseType][$row['assigned_date']] = (int)$row['usage_count'];
            }
        } catch (PDOException $e) {
            error_log("DB Error in getAssignmentsCountByBaseTypeAndDate: " . $e->getMessage());
            // Return empty or partially filled usage array on error
        }
        return $usage;
    }

    /**
     * Checks if panels have existing progress data.
     *
     * @param array<int> $panelIds An array of panel IDs to check.
     * @return array<int, bool> Associative array [panel_id => has_progress (true/false)].
     * @throws PDOException If a database error occurs during the check.
     */
    private function checkPanelsProgress(array $panelIds): array
    {
        $panelProgressStatus = [];
        if (empty($panelIds)) {
            return $panelProgressStatus;
        }

        // Initialize all panels as having no progress
        foreach ($panelIds as $id) {
            $panelProgressStatus[$id] = false;
        }

        // Build the SELECT part dynamically for progress columns
        $progressColsSql = implode(', ', array_map(fn($col) => "`" . $col . "`", $this->progressCols));
        // Create placeholders for the IN clause
        $placeholders = rtrim(str_repeat('?,', count($panelIds)), ',');

        try {
            $stmtCheck = $this->pdo->prepare("SELECT id, $progressColsSql FROM hpc_panels WHERE id IN ($placeholders)");
            // PDO automatically handles binding integers correctly here
            $stmtCheck->execute($panelIds);

            while ($row = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                $panelId = $row['id'];
                foreach ($this->progressCols as $col) {
                    // Check if column exists and has a non-empty value
                    if (isset($row[$col]) && !empty($row[$col])) {
                        $panelProgressStatus[$panelId] = true;
                        break; // Found progress for this panel, move to next row
                    }
                }
            }
        } catch (PDOException $e) {
            // Re-throw the exception to be caught by the calling method (scheduleMultiplePanels)
            error_log("Error checking progress for bulk schedule: " . $e->getMessage());
            throw $e; // Propagate the exception
        }

        return $panelProgressStatus;
    }


    /**
     * Attempts to schedule multiple panels within a given date range.
     * Considers availability, limits, progress, and creates TWO orders ('east', 'west') per panel.
     *
     * @param array<array{'id': int, 'formwork_type': ?string}> $panels Array of panels (IDs) to schedule.
     * @param string $startDate Start date in 'Y-m-d' format.
     * @param string $endDate End date in 'Y-m-d' format.
     * @return array{'success': bool, 'scheduled': array, 'unscheduled': array, 'error': ?string, 'createdOrders': array} Result array.
     */
    public function scheduleMultiplePanels(array $panels, string $startDate, string $endDate): array
    {
        $assignmentsMade = [];
        $unscheduledPanels = [];
        $error = null;
        // Store created order numbers: [panel_id => ['east' => ORD-XXXX, 'west' => ORD-YYYY]]
        $createdOrdersInfo = [];

        try {
            // 1. Get Availability & Usage (Unchanged logic)
            $currentAvailableCounts = $this->getAvailableInstanceCounts();
            $assignmentsUsage = $this->getAssignmentsCountByBaseTypeAndDate($startDate, $endDate);

            // 2. Prepare Date Range (Unchanged logic)
            $dateRange = [];
            $current = new DateTime($startDate);
            $end = new DateTime($endDate);
            while ($current <= $end) {
                $dateRange[] = $current->format('Y-m-d');
                $current->modify('+1 day');
            }
            if (empty($dateRange)) {
                return ['success' => false, 'error' => 'Invalid date range.', 'scheduled' => [], 'unscheduled' => $panels, 'createdOrders' => []];
            }

            // 3. Fetch Panel Details (Formwork Type) & Check Progress
            $panelIds = array_column($panels, 'id');
            $panelDetailsFromDB = [];
            if (!empty($panelIds)) {
                $placeholders = rtrim(str_repeat('?,', count($panelIds)), ',');
                $progressColsSql = implode(', ', array_map(fn($col) => "`" . $col . "`", $this->progressCols));
                try {
                    $stmtDetails = $this->pdo->prepare("SELECT id, formwork_type, $progressColsSql FROM hpc_panels WHERE id IN ($placeholders)");
                    $stmtDetails->execute($panelIds);
                    while ($row = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
                        $panelId = $row['id'];
                        $hasProgress = false;
                        foreach ($this->progressCols as $col) {
                            if (!empty($row[$col])) {
                                $hasProgress = true;
                                break;
                            }
                        }
                        $panelDetailsFromDB[$panelId] = [
                            'formwork_type' => $row['formwork_type'],
                            'has_progress' => $hasProgress
                        ];
                    }
                } catch (PDOException $e) {
                    throw $e; /* Rethrow DB errors */
                }
            }

            // 4. Iterate through panels and find slots
            foreach ($panels as $panel) {
                $panelId = $panel['id'] ?? null;
                if (!$panelId || !isset($panelDetailsFromDB[$panelId])) {
                    $unscheduledPanels[] = ['panel' => $panel, 'reason' => 'Panel ID invalid or not found in DB'];
                    continue;
                }

                $panelDetail = $panelDetailsFromDB[$panelId];
                $basePanelFormworkType = getBaseFormworkType($panelDetail['formwork_type']);

                // --- Validation & Progress Skip ---
                if (!$basePanelFormworkType) {
                    $unscheduledPanels[] = ['panel' => ['id' => $panelId], 'reason' => 'Invalid/missing Formwork Type'];
                    continue;
                }
                if ($panelDetail['has_progress']) {
                    $unscheduledPanels[] = ['panel' => ['id' => $panelId], 'reason' => 'Skipped: Existing progress data found.'];
                    continue;
                }

                // --- Capacity Checks (Unchanged logic) ---
                $currentlyAvailableOfType = $currentAvailableCounts[$basePanelFormworkType] ?? 0;
                if ($currentlyAvailableOfType <= 0) {
                    $unscheduledPanels[] = ['panel' => ['id' => $panelId], 'reason' => "No available instances for type '{$basePanelFormworkType}'"];
                    continue;
                }
                $dailyProcessLimit = $this->dailyProcessCapacities[$basePanelFormworkType] ?? 0;
                if ($dailyProcessLimit <= 0) {
                    error_log("Scheduling Warning: Daily process capacity zero/undefined for '{$basePanelFormworkType}' (Panel ID: {$panelId}).");
                    $unscheduledPanels[] = ['panel' => ['id' => $panelId], 'reason' => "Daily processing capacity not defined for '{$basePanelFormworkType}'"];
                    continue;
                }


                // --- Find an Available Date Slot ---
                $scheduledThisPanel = false;
                foreach ($dateRange as $date) {
                    $currentUsageOnDate = $assignmentsUsage[$basePanelFormworkType][$date] ?? 0;
                    if ($currentUsageOnDate < $currentlyAvailableOfType && $currentUsageOnDate < $dailyProcessLimit) {
                        // Found a slot! Mark for assignment.
                        $assignmentsMade[] = [
                            'panel_id' => $panelId,
                            'assigned_date' => $date
                        ];
                        if (!isset($assignmentsUsage[$basePanelFormworkType])) {
                            $assignmentsUsage[$basePanelFormworkType] = [];
                        }
                        $assignmentsUsage[$basePanelFormworkType][$date] = $currentUsageOnDate + 1;
                        $scheduledThisPanel = true;
                        break;
                    }
                } // End date range loop

                if (!$scheduledThisPanel) {
                    $lastDateChecked = end($dateRange);
                    $lastDateUsage = $assignmentsUsage[$basePanelFormworkType][$lastDateChecked] ?? 0;
                    $reason = "No capacity for '{$basePanelFormworkType}' in {$startDate}-{$endDate}. ";
                    if ($lastDateUsage >= $dailyProcessLimit) {
                        $reason .= "Daily limit ({$dailyProcessLimit}) reached.";
                    } elseif ($lastDateUsage >= $currentlyAvailableOfType) {
                        $reason .= "All available instances ({$currentlyAvailableOfType}) used.";
                    } else {
                        $reason .= "(Limit: {$dailyProcessLimit}/day, Avail: {$currentlyAvailableOfType})";
                    }
                    $unscheduledPanels[] = ['panel' => ['id' => $panelId], 'reason' => $reason];
                }
            } // End panel loop

            // 5. Perform DB Update (Panel Assignment + TWO Order Creations with Delivery Date)
            if (!empty($assignmentsMade)) {
                $this->pdo->beginTransaction();
                try {
                    // Prepare statements outside the loop
                    $stmtUpdatePanel = $this->pdo->prepare(
                        "UPDATE hpc_panels
                                     SET assigned_date = :assigned_date,
                                        planned_finish_date = DATE_ADD(:assigned_date, INTERVAL 1 DAY),
                                        planned_finish_user_id = :userId
                                         WHERE id = :panel_id"
                    );
                    $stmtCheckOrder = $this->pdo->prepare("SELECT 1 FROM polystyrene_orders WHERE hpc_panel_id = ? AND order_type = ? AND status = 'ordered' LIMIT 1");
                    $stmtGetMaxOrder = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(order_number, 5) AS UNSIGNED)) FROM polystyrene_orders");
                    // ***** ADD required_delivery_date to INSERT *****
                    $stmtInsertOrder = $this->pdo->prepare(
                        "INSERT INTO polystyrene_orders (hpc_panel_id, order_type, status, order_number, created_at, required_delivery_date)
                                      VALUES (?, ?, 'ordered', ?, NOW(), ?)" // 5 placeholders now
                    );

                    // Get the starting point for order numbers
                    $stmtGetMaxOrder->execute();
                    $maxOrderNumber = $stmtGetMaxOrder->fetchColumn();
                    $nextOrderNum = ($maxOrderNumber === null) ? 1 : $maxOrderNumber + 1;

                    foreach ($assignmentsMade as $assignment) {
                        $panelId = $assignment['panel_id'];
                        $assignedDate = $assignment['assigned_date'];
                        $panelOrders = ['east' => null, 'west' => null];

                        // *** Calculate Required Delivery Date ***
                        try {
                            $assignedDateTime = new DateTime($assignedDate);
                            $assignedDateTime->modify('-1 day');
                            $requiredDeliveryDate = $assignedDateTime->format('Y-m-d');
                        } catch (Exception $e) {
                            // Handle error if date calculation fails (unlikely with valid assignedDate)
                            error_log("Error calculating delivery date for panel {$panelId}, assigned {$assignedDate}: " . $e->getMessage());
                            throw new PDOException("Failed to calculate delivery date for panel {$panelId}."); // Fail transaction
                        }
                        // ***************************************

                        // a) Update the panel
                        $stmtUpdatePanel->execute([':panel_id' => $panelId, ':assigned_date' => $assignedDate, ':userId' => $this->userId]);

                        // b) Create 'east' order if missing
                        $stmtCheckOrder->execute([$panelId, 'east']);
                        if ($stmtCheckOrder->fetchColumn() === false) {
                            $eastOrderNumber = "ORD-" . str_pad((string)$nextOrderNum, 4, '0', STR_PAD_LEFT);
                            // ***** ADD requiredDeliveryDate to execute *****
                            if ($stmtInsertOrder->execute([$panelId, 'east', $eastOrderNumber, $requiredDeliveryDate])) { // 4 params now
                                $panelOrders['east'] = $eastOrderNumber;
                                $nextOrderNum++;
                            } else {
                                throw new PDOException("Failed to insert EAST order for panel ID: $panelId");
                            }
                        }

                        // c) Create 'west' order if missing
                        $stmtCheckOrder->execute([$panelId, 'west']);
                        if ($stmtCheckOrder->fetchColumn() === false) {
                            $westOrderNumber = "ORD-" . str_pad((string)$nextOrderNum, 4, '0', STR_PAD_LEFT);
                            // ***** ADD requiredDeliveryDate to execute *****
                            if ($stmtInsertOrder->execute([$panelId, 'west', $westOrderNumber, $requiredDeliveryDate])) { // 4 params now
                                $panelOrders['west'] = $westOrderNumber;
                                $nextOrderNum++;
                            } else {
                                throw new PDOException("Failed to insert WEST order for panel ID: $panelId");
                            }
                        }

                        $createdOrdersInfo[$panelId] = $panelOrders;
                    } // end foreach assignment

                    $this->pdo->commit();
                } catch (PDOException | Exception $e) { // Catch PDO and DateTime exceptions
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    error_log("DB update/order creation failed during bulk schedule: " . $e->getMessage());
                    // Reset unscheduled list based on failure
                    $finalUnscheduled = $unscheduledPanels;
                    foreach ($assignmentsMade as $failedAssignment) {
                        $originalPanel = ['id' => $failedAssignment['panel_id']];
                        $finalUnscheduled[] = ['panel' => $originalPanel, 'reason' => 'DB Update/Order Failed'];
                    }
                    // Ensure the return array structure matches expectations even on failure
                    $finalUnscheduledFormatted = [];
                    foreach ($finalUnscheduled as $item) {
                        $finalUnscheduledFormatted[] = [
                            'panel_id' => $item['panel']['id'] ?? '?',
                            'reason' => $item['reason'] ?? 'Unknown'
                        ];
                    }
                    return ['success' => false, 'error' => 'Database update or order creation failed.', 'scheduled' => [], 'unscheduled' => $finalUnscheduledFormatted, 'createdOrders' => []];
                }
            } // end if assignmentsMade

            // Format unscheduled panels to just include ID and reason
            $finalUnscheduledFormatted = [];
            foreach ($unscheduledPanels as $item) {
                $finalUnscheduledFormatted[] = [
                    'panel_id' => $item['panel']['id'] ?? '?',
                    'reason' => $item['reason'] ?? 'Unknown'
                ];
            }


            return ['success' => true, 'scheduled' => $assignmentsMade, 'unscheduled' => $finalUnscheduledFormatted, 'error' => null, 'createdOrders' => $createdOrdersInfo];
        } catch (PDOException $e) {
            $error = 'Database error during scheduling preparation: ' . $e->getMessage();
            error_log($error);
            return ['success' => false, 'error' => $error, 'scheduled' => [], 'unscheduled' => array_map(fn($p) => (['panel_id' => $p['id'] ?? '?', 'reason' => 'DB Error Prep']), $panels), 'createdOrders' => []];
        } catch (Exception $e) {
            $error = 'Unexpected error during scheduling: ' . $e->getMessage();
            error_log($error);
            return ['success' => false, 'error' => $error, 'scheduled' => [], 'unscheduled' => array_map(fn($p) => (['panel_id' => $p['id'] ?? '?', 'reason' => 'Unexpected Error']), $panels), 'createdOrders' => []];
        }
    } // end scheduleMultiplePanels

} // End PanelScheduler Class

// Function to check if user can edit (centralized logic)
function userCanEdit(?string $userRole): bool
{
    $readOnlyRoles = ['supervisor', 'planner', 'user', 'cnc_operator'];
    // If role is null (not logged in?) or in the read-only list, they cannot edit.
    // Assumes any other role (like 'admin') CAN edit.
    return ($userRole !== null && !in_array(strtolower($userRole), $readOnlyRoles));
}
global $formworkDailyCapacity, $progressColumns;

// =========================================================================
// --- AJAX Request Handling ---
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = ['success' => false, 'error' => 'Invalid request or action.'];
    $pdo = null;
    $httpStatusCode = 400;

    try {
        $pdo = connectDB();
        $current_user_id = $_SESSION['user_id'] ?? null;
        // *** GET USER ROLE ***
        $current_user_role = $_SESSION['role'] ?? null;  // <-- Make sure 'user_role' is set in your session!
        // *** DETERMINE EDIT PERMISSION ***
        $canEdit = userCanEdit($current_user_role);
        $protectedActions = ['updatePanelDate', 'scheduleMultiplePanels', 'checkFormworkAvailability', 'getAssignedPanels'];
        if ($current_user_id === null && in_array($action, $protectedActions)) {
            $httpStatusCode = 401;
            $response = ['success' => false, 'error' => 'User not logged in.'];
            goto send_response;
        }

        // --- ACTION: updatePanelDate (Handles Single Assignment/Unassignment, Progress, and TWO Orders) ---
        if ($action === 'updatePanelDate') {
            // *** ROLE CHECK ***
            if (!$canEdit) {
                $httpStatusCode = 403; // Forbidden
                $response = ['success' => false, 'error' => 'Permission denied. You do not have rights to modify assignments.'];
                goto send_response;
            }
            // *** END ROLE CHECK ***
            $panelId = filter_input(INPUT_POST, 'panelId', FILTER_VALIDATE_INT);
            $dateInput = $_POST['date'] ?? null;
            $date = ($dateInput === '' || $dateInput === null) ? null : $dateInput;
            $forceUpdate = isset($_POST['forceUpdate']) && $_POST['forceUpdate'] === 'true';

            // Basic Validation
            if (!$panelId || $panelId <= 0) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Invalid Panel ID.'];
                goto send_response;
            }
            if ($date !== null && DateTime::createFromFormat('Y-m-d', $date) === false) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Invalid date format.'];
                goto send_response;
            }

            // Check Progress & Panel Existence
            $hasProgress = false;
            try {
                $progressColsSql = implode(', ', array_map(fn($col) => "`" . $col . "`", $progressColumns));
                $stmtCheck = $pdo->prepare("SELECT id, $progressColsSql FROM hpc_panels WHERE id = :id");
                $stmtCheck->execute([':id' => $panelId]);
                $panelData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if (!$panelData) {
                    $httpStatusCode = 404;
                    $response = ['success' => false, 'error' => 'Panel not found.'];
                    goto send_response;
                }

                foreach ($progressColumns as $col) {
                    if (!empty($panelData[$col])) {
                        $hasProgress = true;
                        break;
                    }
                }
            } catch (PDOException $e) {
                error_log("DB Error check panel {$panelId}: " . $e->getMessage());
                $httpStatusCode = 500;
                $response = ['success' => false, 'error' => 'DB error checking panel.'];
                goto send_response;
            }

            // Conflict check
            if ($hasProgress && !$forceUpdate) {
                $httpStatusCode = 409;
                $response = [
                    'success' => false,
                    'progress_exists' => true,
                    'error' => 'Panel has progress. Confirm to clear progress and update.'
                ];
                goto send_response;
            }

            // Proceed with Transaction
            $pdo->beginTransaction();
            try {
                $message = '';
                $createdOrderNumbers = ['east' => null, 'west' => null]; // Track created numbers

                // 1. Update hpc_panels Table
                $plannedFinishDate = null;
                $userIdToSet = null;
                if ($date !== null) {
                    $dt = new DateTime($date);
                    $dt->modify('+1 day');
                    $plannedFinishDate = $dt->format('Y-m-d');
                    $userIdToSet = $current_user_id;
                }
                $setClauses = [
                    "assigned_date = :date",
                    "planned_finish_date = :plannedFinishDate",
                    "planned_finish_user_id = :userId"
                ];
                if ($hasProgress && $forceUpdate) {
                    foreach ($progressColumns as $col) {
                        $setClauses[] = "`" . $col . "` = NULL";
                    }
                }
                $sqlSet = implode(', ', $setClauses);
                $stmtUpdatePanel = $pdo->prepare("UPDATE hpc_panels SET $sqlSet WHERE id = :panelId");
                $stmtUpdatePanel->execute([':panelId' => $panelId, ':date' => $date, ':plannedFinishDate' => $plannedFinishDate, ':userId' => $userIdToSet]);


                // 2. Handle polystyrene_orders Table
                if ($date !== null) {
                    // --- Assigning/Updating Date: Create 'east' and 'west' Orders if missing ---
                    $message = "Panel assignment updated.";
                    $orderTypesToHandle = ['east', 'west'];
                    $stmtCheckOrder = $pdo->prepare("SELECT 1 FROM polystyrene_orders WHERE hpc_panel_id = ? AND order_type = ? AND status = 'ordered' LIMIT 1");
                    $stmtGetMaxOrder = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(order_number, 5) AS UNSIGNED)) FROM polystyrene_orders");
                    $stmtInsertOrder = $pdo->prepare(
                        "INSERT INTO polystyrene_orders (hpc_panel_id, order_type, status, order_number, created_at, required_delivery_date)
                         VALUES (?, ?, 'ordered', ?, NOW(), ?)" // 5 placeholders
                    );
                    // ***** PREPARE UPDATE STATEMENT *****
                    $stmtUpdateOrderDate = $pdo->prepare(
                        "UPDATE polystyrene_orders
                         SET required_delivery_date = :reqDate
                         WHERE hpc_panel_id = :panelId AND order_type = :orderType AND status = 'ordered'"
                    );
                    // *** Calculate Required Delivery Date ***
                    try {
                        $assignedDateTime = new DateTime($date); // Use $date (validated input)
                        $assignedDateTime->modify('-1 day');
                        $requiredDeliveryDate = $assignedDateTime->format('Y-m-d');
                    } catch (Exception $e) {
                        error_log("Error calculating delivery date for panel {$panelId}, assigned {$date}: " . $e->getMessage());
                        throw new PDOException("Failed to calculate delivery date for panel {$panelId}.");
                    }

                    // Get the next available number sequence start point
                    $stmtGetMaxOrder->execute();
                    $maxNum = $stmtGetMaxOrder->fetchColumn();
                    $nextNum = ($maxNum === null) ? 1 : $maxNum + 1;
                    $ordersCreatedCount = 0;
                    $ordersUpdatedCount = 0;

                    foreach ($orderTypesToHandle as $orderType) {
                        $stmtCheckOrder->execute([$panelId, $orderType]);
                        if ($stmtCheckOrder->fetchColumn() === false) {
                            // --- Order Doesn't Exist: INSERT ---
                            $orderNumber = "ORD-" . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
                            if ($stmtInsertOrder->execute([$panelId, $orderType, $orderNumber, $requiredDeliveryDate])) {
                                $createdOrderNumbers[$orderType] = $orderNumber;
                                $nextNum++;
                                $ordersCreatedCount++;
                            } else {
                                throw new PDOException("Failed to create {$orderType} order for panel {$panelId}.");
                            }
                        } else {
                            // --- Order EXISTS: UPDATE Delivery Date ---
                            if ($stmtUpdateOrderDate->execute([':reqDate' => $requiredDeliveryDate, ':panelId' => $panelId, ':orderType' => $orderType])) {
                                if ($stmtUpdateOrderDate->rowCount() > 0) { // Check if any row was actually updated
                                    $ordersUpdatedCount++;
                                }
                            } else {
                                throw new PDOException("Failed to update required delivery date for {$orderType} order for panel {$panelId}.");
                            }
                        }
                    } // End foreach order type

                    // Adjust message based on actions
                    if ($ordersCreatedCount > 0) {
                        $message .= " Created {$ordersCreatedCount} order(s)";
                        if ($createdOrderNumbers['east']) $message .= " East(#{$createdOrderNumbers['east']})";
                        if ($createdOrderNumbers['west']) $message .= " West(#{$createdOrderNumbers['west']})";
                        if ($ordersUpdatedCount > 0) $message .= " and";
                        else $message .= ".";
                    }
                    if ($ordersUpdatedCount > 0) {
                        $message .= ($ordersCreatedCount > 0 ? " " : " "); // Add space if needed
                        $message .= "updated delivery date for {$ordersUpdatedCount} existing order(s).";
                    }
                    if ($ordersCreatedCount == 0 && $ordersUpdatedCount == 0) {
                        $message .= " (East/West 'ordered' orders already existed with the correct delivery date).";
                    }
                } else {
                    // --- Unassigning Date: Delete BOTH 'pending' Orders ---
                    $message = 'Panel unassigned.';
                    $stmtFindOrders = $pdo->prepare("SELECT id, order_number, order_type FROM polystyrene_orders WHERE hpc_panel_id = ? AND status = 'ordered'");
                    $stmtFindOrders->execute([$panelId]);
                    $ordersToDelete = $stmtFindOrders->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($ordersToDelete)) {
                        $stmtDelete = $pdo->prepare("DELETE FROM polystyrene_orders WHERE id = ?");
                        $deletedMsgPart = " Deleted orders:";
                        $deletedCount = 0;
                        foreach ($ordersToDelete as $order) {
                            if ($stmtDelete->execute([$order['id']])) {
                                $deletedMsgPart .= " {$order['order_type']}(#{$order['order_number']})";
                                //log_activity($current_user_id, "order_delete_{$order['order_type']}", "Panel ID: $panelId, Order#: {$order['order_number']}");
                                $deletedCount++;
                            } else {
                                throw new PDOException("Failed to delete {$order['order_type']} order {$order['id']} for panel {$panelId}.");
                            }
                        }
                        if ($deletedCount > 0) {
                            $message .= $deletedMsgPart . ".";
                        } else {
                            $message .= " (Failed to delete orders)."; // Should be caught by exception though
                        }
                    } else {
                        $message .= " (No pending East/West orders found to delete).";
                    }
                }

                // Add progress clearing message if applicable
                if ($hasProgress && $forceUpdate) {
                    $message .= ' Existing progress data cleared.';
                }

                $pdo->commit();
                $httpStatusCode = 200;
                $response = ['success' => true, 'message' => $message, 'created_orders' => $createdOrderNumbers];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("DB Error during panel/order update for panel {$panelId}: " . $e->getMessage());
                $httpStatusCode = 500;
                $response = ['success' => false, 'error' => 'Database error during panel/order update.'];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("General Error during panel/order update for panel {$panelId}: " . $e->getMessage());
                $httpStatusCode = 500;
                $response = ['success' => false, 'error' => 'An internal error occurred.'];
            }
        }

        // --- ACTION: checkFormworkAvailability  ---
        elseif ($action === 'checkFormworkAvailability') {

            $panelId = filter_input(INPUT_POST, 'panelId', FILTER_VALIDATE_INT);
            $date = $_POST['date'] ?? null;
            $formworkTypeInput = $_POST['formworkType'] ?? null;
            $baseFormworkType = getBaseFormworkType($formworkTypeInput);
            if (!$date || !$baseFormworkType) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'available' => false, 'error' => 'Missing required date or valid formwork type.'];
            } elseif (DateTime::createFromFormat('Y-m-d', $date) === false) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'available' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.'];
            } else {
                try {
                    $stmt_avail = $pdo->prepare("SELECT available_count FROM available_formworks WHERE formwork_type = :baseType");
                    $stmt_avail->execute([':baseType' => $baseFormworkType]);
                    $currentlyAvailableOfType = $stmt_avail->fetchColumn();
                    if ($currentlyAvailableOfType === false || !is_numeric($currentlyAvailableOfType)) {
                        $httpStatusCode = 404;
                        $response = ['success' => false, 'available' => false, 'error' => "اطلاعات موجودی برای نوع قالب '{$baseFormworkType}' یافت نشد."];
                    } elseif ($currentlyAvailableOfType <= 0) {
                        $httpStatusCode = 200;
                        $response = ['success' => true, 'available' => false, 'error' => "هیچ نمونه در دسترسی برای نوع قالب '{$baseFormworkType}' وجود ندارد."];
                    } else {
                        $dailyProcessLimit = $formworkDailyCapacity[$baseFormworkType] ?? 0;
                        if ($dailyProcessLimit <= 0) {
                            error_log("Configuration Warning: Daily process capacity is zero or undefined for available formwork type '{$baseFormworkType}'.");
                            $httpStatusCode = 500;
                            $response = ['success' => false, 'available' => false, 'error' => "ظرفیت پردازش روزانه برای قالب '{$baseFormworkType}' به درستی تنظیم نشده است."];
                        } else {
                            $sql_usage = "SELECT COUNT(*) FROM hpc_panels WHERE formwork_type = :baseFormworkType AND assigned_date = :date";
                            $params_usage = [':baseFormworkType' => $baseFormworkType, ':date' => $date];
                            if ($panelId !== null && $panelId > 0) {
                                $sql_usage .= " AND id != :panelId";
                                $params_usage[':panelId'] = $panelId;
                            }
                            $stmt_usage = $pdo->prepare($sql_usage);
                            $stmt_usage->execute($params_usage);
                            $currentUsage = (int)$stmt_usage->fetchColumn();
                            if ($currentUsage < $currentlyAvailableOfType && $currentUsage < $dailyProcessLimit) {
                                $httpStatusCode = 200;
                                $response = [
                                    'success' => true,
                                    'available' => true,
                                    'currentlyAvailableOfType' => (int)$currentlyAvailableOfType,
                                    'dailyProcessLimit' => $dailyProcessLimit,
                                    'currentUsage' => $currentUsage,
                                    'message' => "ظرفیت برای '{$baseFormworkType}' در {$date} موجود است."
                                ];
                            } else {
                                $reason = "";
                                if ($currentUsage >= $dailyProcessLimit) {
                                    $reason = "ظرفیت پردازش روزانه ({$dailyProcessLimit}) تکمیل شده";
                                } elseif ($currentUsage >= $currentlyAvailableOfType) {
                                    $reason = "تمام نمونه‌های در دسترس ({$currentlyAvailableOfType}) در این روز استفاده شده‌اند";
                                } else {
                                    $reason = "ظرفیت تکمیل";
                                }
                                $httpStatusCode = 200;
                                $response = [
                                    'success' => true,
                                    'available' => false,
                                    'error' => "ظرفیت برای '{$baseFormworkType}' در {$date} تکمیل. {$reason}",
                                    'currentlyAvailableOfType' => (int)$currentlyAvailableOfType,
                                    'dailyProcessLimit' => $dailyProcessLimit,
                                    'currentUsage' => $currentUsage
                                ];
                            }
                        }
                    }
                } catch (PDOException $e) {
                    error_log("DB Error checking availability for {$baseFormworkType} on {$date}: " . $e->getMessage());
                    $httpStatusCode = 500;
                    $response = ['success' => false, 'available' => false, 'error' => 'خطای پایگاه داده هنگام بررسی در دسترس بودن.'];
                }
            }
        }

        // --- ACTION: scheduleMultiplePanels (Uses PanelScheduler - modified) ---
        elseif ($action === 'scheduleMultiplePanels') {
            // *** ROLE CHECK ***
            if (!$canEdit) {
                $httpStatusCode = 403; // Forbidden
                $response = ['success' => false, 'error' => 'Permission denied. You do not have rights to perform bulk scheduling.'];
                goto send_response;
            }
            // *** END ROLE CHECK ***
            $panelsJson = $_POST['panels'] ?? '[]';
            $startDate = $_POST['startDate'] ?? null;
            $endDate = $_POST['endDate'] ?? null;
            $panelsInput = json_decode($panelsJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Invalid panel data (JSON decode failed).'];
            } elseif (empty($panelsInput)) {
                $httpStatusCode = 200;
                $response = ['success' => true, 'message' => 'No panels provided.', 'scheduled' => [], 'unscheduled' => []];
            } elseif (!$startDate || !DateTime::createFromFormat('Y-m-d', $startDate) || !$endDate || !DateTime::createFromFormat('Y-m-d', $endDate)) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Invalid date format.'];
            } elseif (new DateTime($startDate) > new DateTime($endDate)) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Start date after end date.'];
            } else {
                // Pass only IDs, PanelScheduler fetches details
                $panelsToSchedule = array_map(fn($p) => ['id' => $p['id'] ?? null], $panelsInput);
                $panelsToSchedule = array_filter($panelsToSchedule, fn($p) => $p['id'] !== null); // Filter out invalid entries


                if (empty($panelsToSchedule)) {
                    $httpStatusCode = 200;
                    $response = ['success' => true, 'message' => 'No valid panel IDs provided.', 'scheduled' => [], 'unscheduled' => $panelsInput];
                } else {
                    $scheduler = new PanelScheduler($pdo, $current_user_id, $formworkDailyCapacity, $progressColumns);
                    $result = $scheduler->scheduleMultiplePanels($panelsToSchedule, $startDate, $endDate); // Pass array of ['id'=>...]

                    // Map unscheduled IDs back to original input if needed for context, or just use the ID/reason from result
                    $formattedUnscheduled = [];
                    if (isset($result['unscheduled']) && is_array($result['unscheduled'])) {
                        foreach ($result['unscheduled'] as $item) {
                            // Find original input panel based on ID for context if desired
                            $originalPanel = null;
                            foreach ($panelsInput as $inp) {
                                if (($inp['id'] ?? null) == $item['panel_id']) {
                                    $originalPanel = $inp;
                                    break;
                                }
                            }
                            $formattedUnscheduled[] = ['panel' => $originalPanel ?? ['id' => $item['panel_id']], 'reason' => $item['reason']];
                        }
                        $result['unscheduled'] = $formattedUnscheduled;
                    }

                    $httpStatusCode = $result['success'] ? 200 : (str_contains($result['error'] ?? '', 'Database') ? 500 : 400);
                    $response = $result;
                }
            }
        }

        // --- ACTION: getAssignedPanels (Unchanged - Title is already just address) ---
        elseif ($action === 'getAssignedPanels') {
            try {
                // Added planned_finish_date, status and type for potential future use in extendedProps
                $stmt = $pdo->prepare("SELECT hp.id, hp.address, hp.assigned_date, hp.planned_finish_date, hp.formwork_type, hp.status, hp.type,hp.Proritization, hp.width
                                       FROM hpc_panels hp
                                       WHERE hp.assigned_date IS NOT NULL");
                $stmt->execute();
                $dbPanels = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $events = [];

                foreach ($dbPanels as $panel) {
                    $baseFormworkType = getBaseFormworkType($panel['formwork_type']);

                    // --- MODIFICATION HERE: Title is ONLY address ---
                    $events[] = [
                        'title' => $panel['address'], // ONLY Address
                        'start' => $panel['assigned_date'],
                        // 'end' => $panel['planned_finish_date'], // Uncomment if you want events to span duration
                        'extendedProps' => [
                            'panelId' => $panel['id'],
                            'address' => $panel['address'], // Keep address here too if needed
                            'formworkType' => $baseFormworkType, // Store base type
                            'width' => $panel['width'], // Store width
                            'fullFormworkType' => $panel['formwork_type'], // Store full type
                            'panelType' => $panel['type'], // Store the panel's own type
                            'status' => $panel['status'], // Store panel status
                            'priority' => $panel['Proritization']
                        ],
                        // Example: Color based on formwork type? Or status?
                        // 'backgroundColor' => '#somecolor',
                        // 'borderColor' => '#someborder',
                    ];
                }
                $httpStatusCode = 200;
                $response = $events; // Return the array of events

            } catch (PDOException $e) {
                error_log("DB Error fetching assigned panels for calendar: " . $e->getMessage());
                $httpStatusCode = 500;
                $response = ['success' => false, 'error' => 'Database error loading calendar events.'];
            }
        }

        // --- Unknown action ---
        else {
            $httpStatusCode = 400;
            $response = ['success' => false, 'error' => 'Unknown action specified.'];
        }
    } catch (PDOException $e) {
        error_log("General DB Error AJAX [Action: $action]: " . $e->getMessage());
        $httpStatusCode = 500;
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        } // Ensure rollback on general PDO errors
        $response = ['success' => false, 'error' => 'Database error processing request.'];
    } catch (Throwable $e) {
        error_log("General Error AJAX [Action: $action]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        $httpStatusCode = 500;
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        } // Ensure rollback on general errors
        $response = ['success' => false, 'error' => 'An unexpected server error occurred.'];
    }

    send_response:
    $pdo = null;
    if (!headers_sent()) {
        http_response_code($httpStatusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
} // End AJAX handling block

// =========================================================================
// --- Page Load Logic (Fetch panels - Unchanged) ---
// =========================================================================
try {
    $pdo = connectDB();
    // --- Determine Edit Permission for Page Load ---
    $page_load_user_id = $_SESSION['user_id'] ?? null;
    $page_load_user_role = $_SESSION['role'] ?? null; // Get role from session

    $userCanEditPageLoad = userCanEdit($page_load_user_role);
    // --- End Determine Edit Permission ---



    // Fetch the 'type' column as it's needed for order creation later
    $stmt = $pdo->query("SELECT hp.id, hp.address, hp.status, hp.assigned_date, hp.type, hp.area, hp.width, hp.length, hp.Proritization, hp.formwork_type
                         FROM hpc_panels hp
                         WHERE hp.plan_checked = 1
                         ORDER BY hp.Proritization ASC, hp.id DESC");
    $allPanels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $priorities = empty($allPanels) ? [] : array_unique(array_column($allPanels, 'Proritization'));
    sort($priorities);
    $pdo = null;
} catch (PDOException $e) {
    error_log("Fatal DB Error fetching panel list: " . $e->getMessage());
    die("Database error loading panel list.");
}

$pageTitle = 'پنل زمانبندی';
require_once 'header.php';
?>
<script>
    const USER_CAN_EDIT = <?php echo $userCanEditPageLoad ? 'true' : 'false'; ?>;
</script>
<!-- Styling (Unchanged) -->
<style>
    body.loading::before {
        content: "در حال پردازش...";
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, .5);
        color: #fff;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 1.5rem;
        font-weight: 700;
        z-index: 9999;
        cursor: wait
    }

    body.loading::after {
        content: '';
        position: fixed;
        top: calc(50% + 30px);
        left: 50%;
        width: 40px;
        height: 40px;
        margin-left: -20px;
        border: 5px solid #f3f3f3;
        border-top: 5px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        z-index: 9999
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg)
        }

        100% {
            transform: rotate(360deg)
        }
    }

    .panel-item {
        margin-bottom: 0.5rem;
    }

    /* Add spacing between panel items */
    #external-events {
        padding-right: 5px;
        /* Add some padding for scrollbar */
    }

    .priority-legend-item.selected-priority {
        background-color: #dbeafe;
        /* Light blue background (Tailwind blue-100) */
        font-weight: 600;
        /* Semibold */
        cursor: pointer;
        /* Indicate clickability */
        border-radius: 3px;
        padding-left: 4px;
        /* Add some padding */
        padding-right: 4px;
    }

    .priority-legend-item {
        cursor: pointer;
        /* Default cursor for non-selected items */
        padding-left: 4px;
        padding-right: 4px;
        border-radius: 3px;
        transition: background-color 0.15s ease-in-out;
        /* Smooth transition */
    }

    .priority-legend-item:not(.invalid):hover {
        background-color: #eef2ff;
        /* Lighter blue on hover (indigo-50) */
    }

    a {
        color: #111827;
        text-decoration: none;
    }
</style>

<!-- HTML Structure (Added max-height/overflow to panel list) -->
<div class="container mx-auto px-4 py-8">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <!-- Sidebar -->
        <div class="md:col-span-1 space-y-4">
            <!-- Panel List -->
            <div class="bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-bold mb-4 text-gray-700">پنل‌های آماده</h3>
                <div id="panelStats" class="text-sm text-gray-500 mb-3 border-b pb-2">نمایش: <span id="visibleCount" class="font-semibold text-gray-700">0</span> / <span id="totalCount" class="font-semibold text-gray-700"><?php echo count($allPanels); ?></span></div>
                <!-- Added max-height and overflow-y-auto -->
                <!-- Add read-only class conditionally -->
                <div id="external-events" class="external-events space-y-2 max-h-[calc(100vh-450px)] md:max-h-[60vh] overflow-y-auto pr-2 <?php echo !$userCanEditPageLoad ? 'read-only' : ''; ?>">
                    <?php if (empty($allPanels)): ?> <p class="text-center text-gray-500 italic">هیچ پنل آماده‌ای یافت نشد.</p>
                        <?php else: foreach ($allPanels as $panel):
                            $assigned = $panel['assigned_date'] ? 'true' : 'false';
                            $baseFormworkType = getBaseFormworkType($panel['formwork_type']);
                            // Determine classes based on assignment AND edit permissions
                            $panelClasses = 'panel-item p-2 rounded shadow-sm';
                            if ($assigned === 'true') {
                                $panelClasses .= ' bg-gray-200 text-gray-500 cursor-not-allowed'; // Assigned is always non-interactive
                            } elseif (!$userCanEditPageLoad) {
                                $panelClasses .= ' bg-blue-100 text-blue-800 read-only'; // Unassigned but read-only user
                            } else {
                                $panelClasses .= ' bg-blue-100 text-blue-800 hover:bg-blue-200 cursor-move'; // Editable and unassigned
                            }
                        ?>
                            <div class="<?php echo $panelClasses; ?>"
                                data-panel-id="<?php echo $panel['id']; ?>"
                                data-panel-type="<?php echo htmlspecialchars($panel['type'] ?? ''); ?>"
                                data-panel-assigned="<?php echo $assigned; ?>"
                                data-panel-width="<?php echo htmlspecialchars($panel['width'] ?? ''); ?>"
                                data-panel-length="<?php echo htmlspecialchars($panel['length'] ?? ''); ?>"
                                data-panel-priority="<?php echo htmlspecialchars($panel['Proritization'] ?? ''); ?>"
                                data-panel-formwork="<?php echo htmlspecialchars($baseFormworkType ?? ''); ?>">
                                <div class="font-semibold text-sm break-words"> <?php echo htmlspecialchars($panel['address']); ?> </div>
                                <div class="text-xs mt-1 text-gray-600">
                                    قالب: <span class="font-medium"><?php echo htmlspecialchars($baseFormworkType ?? 'N/A'); ?></span> |
                                    اولویت: <span class="font-medium"><?php echo ($panel['Proritization'] === null || $panel['Proritization'] === '') ? 'N/A' : htmlspecialchars($panel['Proritization']); ?></span>
                                </div>
                                <div class="text-xs text-gray-500"> <?php echo htmlspecialchars($panel['type'] ?: 'N/A'); ?> | <?php echo $panel['area']; ?>m² | W:<?php echo $panel['width']; ?> L:<?php echo $panel['length']; ?> </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>
            <!-- Filters (Unchanged) -->
            <!-- Filters (Unchanged) -->
            <div class="bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-bold mb-4 text-gray-700">جستجو و فیلتر</h3>
                <div class="space-y-3">
                    <div><label for="searchInput" class="block text-sm font-medium text-gray-600 mb-1">آدرس</label><input type="text" id="searchInput" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm" placeholder="..."></div>
                    <div><label for="typeFilter" class="block text-sm font-medium text-gray-600 mb-1">نوع</label><select id="typeFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="">همه</option><?php $types = empty($allPanels) ? [] : array_unique(array_column($allPanels, 'type'));
                                                            foreach ($types as $type): if ($type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option><?php endif;
                                                                                                                                                                                                    endforeach; ?>
                        </select></div>
                    <div><label for="priorityFilter" class="block text-sm font-medium text-gray-600 mb-1">اولویت</label><select id="priorityFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="">همه</option><?php foreach ($priorities as $priority): $displayPriority = ($priority === null || $priority === '') ? 'بدون اولویت' : htmlspecialchars($priority);
                                                                $valuePriority = ($priority === null) ? '' : htmlspecialchars($priority); ?><option value="<?php echo $valuePriority; ?>"><?php echo $displayPriority; ?></option><?php endforeach; ?>
                        </select></div>
                    <div><label for="assignmentFilter" class="block text-sm font-medium text-gray-600 mb-1">وضعیت</label><select id="assignmentFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="">همه</option>
                            <option value="assigned">تخصیص داده</option>
                            <option value="unassigned">تخصیص داده نشده</option>
                        </select></div>
                    <div>
                        <label for="formworkFilter" class="block text-sm font-medium text-gray-600 mb-1">نوع قالب</label>
                        <select id="formworkFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="">همه</option>
                            <?php
                            $formworkTypes = empty($allPanels) ? [] : array_unique(array_map('getBaseFormworkType', array_column($allPanels, 'formwork_type')));
                            sort($formworkTypes);
                            foreach ($formworkTypes as $type):
                                if ($type):
                            ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endif;
                            endforeach; ?>
                        </select>
                    </div>
                    <div class="pt-2 border-t border-gray-200">
                        <!-- CHANGE TEXT and CLASSES to reflect default ON state -->
                        <button id="togglePriorityColorBtn" type="button" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-3 py-1.5 rounded text-sm transition duration-150 ease-in-out shadow-sm">
                            غیرفعال کردن رنگ اولویت
                        </button>
                    </div>
                    <!-- Priority Color Legend (REMOVE 'hidden' class) -->
                    <div id="priorityLegend" class="mt-4 p-3 border rounded-md bg-gray-50">
                        <p class="font-semibold text-sm mb-2 text-gray-700">راهنمای رنگ اولویت:</p>
                        <ul id="priorityLegendList" class="list-none p-0 m-0 space-y-1 text-xs">
                            <!-- Legend items will be added by JavaScript -->
                        </ul>
                    </div>
                    <!-- End Legend -->
                </div>
            </div>
            <!-- Bulk Scheduling (Confirmation message updated in JS) -->
            <div class="bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-bold mb-4 text-gray-700">زمانبندی خودکار</h3>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label for="bulkStartDate" class="block text-sm font-medium text-gray-600 mb-1">از تاریخ</label><input type="text" id="bulkStartDate" class="w-full p-2 border border-gray-300 rounded persian-datepicker shadow-sm" placeholder="YYYY/MM/DD" <?php echo !$userCanEditPageLoad ? 'disabled' : ''; ?>></div>
                        <div><label for="bulkEndDate" class="block text-sm font-medium text-gray-600 mb-1">تا تاریخ</label><input type="text" id="bulkEndDate" class="w-full p-2 border border-gray-300 rounded persian-datepicker shadow-sm" placeholder="YYYY/MM/DD" <?php echo !$userCanEditPageLoad ? 'disabled' : ''; ?>></div>
                    </div>
                    <button id="bulkScheduleBtn" type="button" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-150 ease-in-out flex items-center justify-center gap-2 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed" <?php echo !$userCanEditPageLoad ? 'disabled' : ''; ?>>
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.566.379-1.566 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.566 2.6 1.566 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.566-.379 1.566-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="button-text">زمانبندی پنل‌های نمایش داده شده</span>
                    </button>
                    <div id="bulkScheduleResult" class="mt-2 text-sm p-2 rounded border bg-gray-50 border-gray-200 min-h-[3em]"></div>
                </div>
            </div>
        </div>

        <!-- Calendar -->
        <div class="md:col-span-3">
            <div class="bg-white p-4 rounded-lg shadow min-h-[600px]">

                <!-- Print Controls Group -->
                <div class="flex justify-end items-center space-x-4 space-x-reverse mb-2 print:hidden">
                    <!-- Checkbox for Colors -->
                    <div class="flex items-center">
                        <input type="checkbox" id="printColorToggle" name="printColorToggle" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500" checked>
                        <label for="printColorToggle" class="ms-2 text-sm font-medium text-gray-700">چاپ با رنگ پس‌زمینه</label>
                    </div>

                    <!-- Print Button -->
                    <button id="printWeekBtn" type="button" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded text-sm transition duration-150 ease-in-out shadow-sm inline-flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 me-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H7a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm-8-14h18M5 8h14" />
                        </svg>
                        چاپ هفته
                    </button>
                </div>
                <!-- End Print Controls Group -->

                <div id="calendar"></div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

<!-- ========================================================================= -->
<!-- JavaScript Section -->
<!-- ========================================================================= -->
<script src="/assets/js/jquery-3.6.0.min.js"></script>
<script src="/assets/js/moment.min.js"></script>
<script src="/assets/js/moment-jalaali.js"></script>
<script src="/assets/js/persian-date.min.js"></script>
<script src="/assets/js/persian-datepicker.min.js"></script>
<script src="/assets/js/mobile-detect.min.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
<!-- Loading CSS (Unchanged) -->
<style>
    body.loading::before {
        content: "در حال پردازش...";
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, .5);
        color: #fff;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 1.5rem;
        font-weight: 700;
        z-index: 9999;
        cursor: wait
    }

    body.loading::after {
        content: '';
        position: fixed;
        top: calc(50% + 30px);
        left: 50%;
        width: 40px;
        height: 40px;
        margin-left: -20px;
        border: 5px solid #f3f3f3;
        border-top: 5px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        z-index: 9999
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg)
        }

        100% {
            transform: rotate(360deg)
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Datepicker, Element Refs, Mobile Detection (Unchanged)
        if (typeof $.fn.persianDatepicker === 'function') {
            $(".persian-datepicker").persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                initialValue: false,
                calendar: {
                    persian: {
                        locale: 'fa',
                        leapYearMode: 'astronomical'
                    }
                }
            });
        } else {
            console.warn("PersianDatepicker missing.");
        }
        const calendarEl = document.getElementById('calendar'),
            externalEventsContainer = document.getElementById('external-events'),
            searchInput = document.getElementById('searchInput'),
            typeFilter = document.getElementById('typeFilter'),
            priorityFilter = document.getElementById('priorityFilter'),
            assignmentFilter = document.getElementById('assignmentFilter'),
            visibleCountEl = document.getElementById('visibleCount'),
            totalCountEl = document.getElementById('totalCount'),
            bulkScheduleResultEl = document.getElementById('bulkScheduleResult'),
            bulkScheduleBtn = document.getElementById('bulkScheduleBtn');
        const md = typeof MobileDetect !== 'undefined' ? new MobileDetect(window.navigator.userAgent) : null;
        const isMobile = md ? md.mobile() !== null : window.innerWidth < 768;
        const togglePriorityColorBtn = document.getElementById('togglePriorityColorBtn');
        const priorityLegend = document.getElementById('priorityLegend'); // Get legend container
        const priorityLegendList = document.getElementById('priorityLegendList'); // Get legend list ul
        // --- State variable for coloring ---
        let priorityColoringEnabled = true;
        const defaultEventColor = '#3b82f6'; // Default blue
        let selectedLegendPriorities = new Set(); // Use a Set to store selected priorities like 'P1', 'P6'

        // --- Helper Function to Get Color Based on Priority ---
        // (Generates colors from Red to Violet using HSL)
        function getPriorityColor(priorityString) {
            if (!priorityString || typeof priorityString !== 'string' || !priorityString.toUpperCase().startsWith('P')) {
                return '#888888'; // Gray for invalid/missing priority
            }
            // Extract number, default to a high number if parsing fails
            const priorityNum = parseInt(priorityString.substring(1), 10);
            if (isNaN(priorityNum) || priorityNum < 1) {
                return '#888888'; // Gray for invalid number
            }

            // Map P1-P16 to Hue (0=Red, 280=Violet approx)
            // We distribute 16 priorities over roughly 280 degrees of hue
            const hueRange = 280;
            const maxPriority = 16; // Adjust if you have more priorities
            // Map higher priority (P1) to Red (0 hue), lower (P16) towards Violet
            const hue = Math.max(0, Math.min(hueRange, (priorityNum - 1) * (hueRange / (maxPriority - 1))));

            // Keep Saturation and Lightness somewhat constant for visibility
            const saturation = 75; // %
            const lightness = 55; // %

            return `hsl(${hue}, ${saturation}%, ${lightness}%)`;
        }
        // --- Function to filter calendar events based on legend selection ---
        function filterCalendarEventsByLegend() {
            const allEvents = calendar.getEvents(); // Get all events managed by FullCalendar

            if (selectedLegendPriorities.size === 0) {
                // If no legend priorities are selected, show all events
                allEvents.forEach(event => event.setProp('display', 'auto'));
            } else {
                // If some legend priorities are selected, filter
                allEvents.forEach(event => {
                    const eventPriority = event.extendedProps.priority;
                    // Show event only if its priority is in the selected set
                    if (eventPriority && selectedLegendPriorities.has(eventPriority)) {
                        event.setProp('display', 'auto');
                    } else {
                        event.setProp('display', 'none'); // Hide events that don't match
                    }
                });
            }
        }
        // --- Handler for clicking a legend item ---
        function handleLegendItemClick(event) {
            const li = event.currentTarget; // Get the clicked <li> element
            const priority = li.dataset.priority; // Get the priority string ('P1', 'P2', etc.)

            if (!priority) return; // Ignore clicks on non-priority items (like the invalid one)

            // Toggle selection state
            if (selectedLegendPriorities.has(priority)) {
                selectedLegendPriorities.delete(priority);
                li.classList.remove('selected-priority');
            } else {
                selectedLegendPriorities.add(priority);
                li.classList.add('selected-priority');
            }

            // Apply the filter to the calendar
            filterCalendarEventsByLegend();

            // Also ensure coloring is on if a selection is made
            if (selectedLegendPriorities.size > 0 && !priorityColoringEnabled) {
                // Optional: Automatically re-enable coloring if user clicks legend while it's off?
                // togglePriorityColorBtn.click(); // Simulate a click to turn it back on
            }
        }

        // Loading Indicators, makeAjaxCall (Unchanged)
        function showGlobalLoading(text = '...') {
            document.body.classList.add('loading');
        }

        function hideGlobalLoading() {
            document.body.classList.remove('loading');
        }

        function showButtonLoading(btn, text = '...') {
            if (!btn) return;
            btn.disabled = true;
            const txtEl = btn.querySelector('.button-text');
            if (txtEl) {
                if (!btn.dataset.originalText) {
                    btn.dataset.originalText = txtEl.textContent;
                }
                txtEl.textContent = text;
            }
            btn.classList.add('opacity-75');
        }

        function hideButtonLoading(btn) {
            if (!btn) return;
            btn.disabled = false;
            const txtEl = btn.querySelector('.button-text');
            if (txtEl && btn.dataset.originalText) {
                txtEl.textContent = btn.dataset.originalText;
            }
            btn.classList.remove('opacity-75');
        }
        // --- NEW: Function to Populate the Priority Legend ---
        function populatePriorityLegend() {
            if (!priorityLegendList) return;
            priorityLegendList.innerHTML = ''; // Clear existing items
            selectedLegendPriorities.clear(); // Clear selection when repopulating

            // Assuming priorities P1 to P16 are possible
            const maxPrio = 16;
            for (let i = 1; i <= maxPrio; i++) {
                const priorityStr = `P${i}`;
                const color = getPriorityColor(priorityStr);

                const li = document.createElement('li');
                // Add classes and data attribute
                li.className = 'priority-legend-item flex items-center';
                li.dataset.priority = priorityStr; // Store priority for the click handler
                //li.className = 'flex items-center'; // Use flexbox for alignment

                const colorSwatch = document.createElement('span');
                colorSwatch.className = 'inline-block w-3 h-3 rounded-sm me-2 border border-gray-300 flex-shrink-0'; // Added flex-shrink-0
                colorSwatch.style.backgroundColor = color;

                const label = document.createElement('span');
                label.textContent = priorityStr;
                label.className = 'text-gray-600';

                li.appendChild(colorSwatch);
                li.appendChild(label);

                // Add the click listener to this specific item
                li.addEventListener('click', handleLegendItemClick);
                priorityLegendList.appendChild(li);
            }
            // Add a swatch for invalid/missing priority
            const invalidLi = document.createElement('li');
            invalidLi.className = 'flex items-center mt-1 pt-1 border-t border-gray-200 invalid';
            const invalidSwatch = document.createElement('span');
            invalidSwatch.className = 'inline-block w-3 h-3 rounded-sm me-2 border border-gray-300 flex-shrink-0';
            invalidSwatch.style.backgroundColor = getPriorityColor(null); // Get the gray color
            const invalidLabel = document.createElement('span');
            invalidLabel.textContent = 'نامشخص / نامعتبر';
            invalidLabel.className = 'text-gray-500 italic';
            invalidLi.appendChild(invalidSwatch);
            invalidLi.appendChild(invalidLabel);
            priorityLegendList.appendChild(invalidLi);
        }
        // --- End Populate Legend Function ---
        async function makeAjaxCall(data) {
            showGlobalLoading();
            try {
                const response = await $.ajax({
                    url: '',
                    method: 'POST',
                    dataType: 'json',
                    data: data
                });
                // Check for PHP-level errors returned in JSON
                if (response && response.error && !response.success && !response.progress_exists) {
                    console.error("PHP Error Received:", response.error);
                    // Check if it's a permission error specifically
                    if (response.error.toLowerCase().includes('permission denied')) {
                        alert('خطا: شما اجازه انجام این عملیات را ندارید.'); // User-friendly permission error
                    } else {
                        alert('خطای سرور: ' + response.error); // Other server errors
                    }
                    throw new Error(response.error); // Throw the specific error from PHP
                }
                if (externalEventsContainer && USER_CAN_EDIT) { // Only initialize if user can edit
                    console.log("Initializing Draggable for editable user.");
                    // ... initialize draggable ...
                } else if (externalEventsContainer) {
                    console.log("Skipping Draggable initialization for read-only user."); // <-- THIS IS RUNNING
                    // ... style for read-only ...
                }
                // --- END ADDED ---
                return response;
            } catch (error) {
                console.error("Caught Error Object in makeAjaxCall:", error);
                let errorMsg = 'خطای سرور.';
                if (error && typeof error === 'object') {
                    if (error.responseJSON && error.responseJSON.error) {
                        errorMsg = error.responseJSON.error;
                    } else if (error.statusText && error.status === 403) {
                        errorMsg = 'عدم دسترسی.';
                    } // Handle 403 specifically
                    else if (error.statusText && error.status !== undefined) {
                        errorMsg = `خطای سرور. (${error.status}: ${error.statusText})`;
                    } else if (error.message && !error.message.toLowerCase().includes('permission denied')) {
                        // Avoid double-alerting permission errors we already handled
                        errorMsg = `خطای برنامه: ${error.message}`;
                    } else if (!errorMsg.includes('عدم دسترسی')) { // Ensure some message if others fail
                        errorMsg = 'خطای ناشناخته در ارتباط.';
                    }
                }
                // Only alert if it wasn't a permission error already alerted above
                if (!errorMsg.includes('عدم دسترسی') && !(error.message && error.message.toLowerCase().includes('permission denied'))) {
                    alert(errorMsg);
                }
                throw new Error(errorMsg); // Re-throw sanitized/generic error
            } finally {
                hideGlobalLoading();
            }
        }


        // --- FullCalendar Init ---
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: isMobile ? 'dayGridWeek' : 'dayGridMonth',
            locale: 'fa',
            direction: 'rtl',
            firstDay: 6,
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek'
            },
            buttonText: {
                today: 'امروز',
                month: 'ماه',
                week: 'هفته'
            },
            editable: true,
            droppable: true,
            selectable: false,
            longPressDelay: isMobile ? 150 : 50,
            eventLongPressDelay: isMobile ? 150 : 50,
            height: 'auto',
            // *** Conditional Editability ***
            editable: USER_CAN_EDIT, // Allow moving events only if user can edit
            droppable: USER_CAN_EDIT, // Allow dropping external panels only if user can edit

            eventDragStart: function(info) {
                if (!USER_CAN_EDIT) return;
                info.el.classList.add('opacity-50', 'ring-2', 'ring-blue-500');
            },
            eventDragStop: function(info) {
                if (!USER_CAN_EDIT) return;
                info.el.classList.remove('opacity-50', 'ring-2', 'ring-blue-500');
            },

            // --- Moving an existing event ---
            eventDrop: async function(info) {
                if (!USER_CAN_EDIT) {
                    info.revert(); // Immediately revert if user cannot edit
                    return;
                }
                const panelId = info.event.extendedProps.panelId,
                    newDate = moment(info.event.start).format('YYYY-MM-DD'),
                    baseFormworkType = info.event.extendedProps.formworkType;
                if (!baseFormworkType) {
                    alert('خطا: نوع قالب رویداد نامشخص.');
                    info.revert();
                    return;
                }

                try {
                    // 1. Check Availability
                    const availData = await makeAjaxCall({
                        action: 'checkFormworkAvailability',
                        panelId: panelId,
                        date: newDate,
                        formworkType: baseFormworkType
                    });
                    if (!availData.available) {
                        alert(`جابجایی ناموفق: ${availData.error || 'ظرفیت تکمیل است.'}`);
                        info.revert();
                        return;
                    }

                    // 2. Attempt Update (handles progress check, panel update, order logic)
                    let updateData = await makeAjaxCall({
                        action: 'updatePanelDate',
                        panelId: panelId,
                        date: newDate
                    });

                    // 3. Handle Progress Warning
                    if (updateData.progress_exists) {
                        if (confirm("این پنل دارای اطلاعات پیشرفت ثبت شده است. برای جابجایی، اطلاعات پیشرفت پاک خواهد شد. ادامه می‌دهید؟")) {
                            updateData = await makeAjaxCall({
                                action: 'updatePanelDate',
                                panelId: panelId,
                                date: newDate,
                                forceUpdate: true
                            });
                        } else {
                            info.revert();
                            return;
                        }
                    }

                    // 4. Check final update result
                    if (!updateData.success) {
                        alert('خطا در به‌روزرسانی نهایی تاریخ: ' + (updateData.error || 'Unknown error after confirmation.'));
                        info.revert();
                        return;
                    }
                    // Success: Event stays, potentially show success message from updateData.message if needed
                    // calendar.refetchEvents(); // Might be needed if server changes event props

                } catch (error) {
                    console.error('Error during event drop:', error);
                    info.revert();
                }
            },

            // --- Clicking to remove an assignment ---
            eventClick: async function(info) {
                if (!USER_CAN_EDIT) {
                    // Optional: Show details on click for read-only users?
                    // alert(`Panel: ${info.event.title}\nDate: ${moment(info.event.start).format('jYYYY/jMM/jDD')}\nType: ${info.event.extendedProps.formworkType}`);
                    return; // Do nothing if user cannot edit
                }
                const panelId = info.event.extendedProps.panelId;
                const dateStr = moment(info.event.start).isValid() ? moment(info.event.start).format('jYYYY/jMM/jDD') : '?';
                const title = info.event.title || 'پنل'; // Title is now just the address

                if (confirm(`لغو تخصیص پنل "${title}" در ${dateStr}؟\n(این کار سفارش مربوطه را نیز لغو می‌کند)`)) {
                    try {
                        let response = await makeAjaxCall({
                            action: 'updatePanelDate',
                            panelId: panelId,
                            date: ''
                        });
                        if (response.progress_exists) {
                            if (confirm("این پنل دارای اطلاعات پیشرفت ثبت شده است. برای لغو تخصیص، اطلاعات پیشرفت پاک خواهد شد. ادامه می‌دهید؟")) {
                                response = await makeAjaxCall({
                                    action: 'updatePanelDate',
                                    panelId: panelId,
                                    date: '',
                                    forceUpdate: true
                                });
                            } else {
                                return;
                            }
                        }
                        if (response.success) {
                            info.event.remove();
                            const pEl = document.querySelector(`.panel-item[data-panel-id="${panelId}"]`);
                            if (pEl) {
                                pEl.setAttribute('data-panel-assigned', 'false');
                                // Reset classes for editable state
                                pEl.className = pEl.className.replace(/bg-gray-\d+00|text-gray-\d+00|cursor-not-allowed/g, '').trim() + ' bg-blue-100 text-blue-800 hover:bg-blue-200 cursor-move';
                            }
                            filterPanels();
                        } else {
                            alert('خطا در لغو تخصیص: ' + (response.error || 'Unknown error after confirmation.'));
                        }
                    } catch (error) {
                        console.error('Error event click:', error);
                    }
                }
            },

            // --- Dropping an external panel ---
            drop: async function(info) {
                if (!info.date) return;
                const draggedEl = info.draggedEl,
                    panelId = draggedEl.getAttribute('data-panel-id'),
                    date = moment(info.date).format('YYYY-MM-DD'),
                    panelAddress = (draggedEl.querySelector('.font-semibold')?.innerText || 'پنل'), // Get address directly
                    baseFormworkType = draggedEl.getAttribute('data-panel-formwork');

                if (!baseFormworkType) {
                    alert('خطا: نوع قالب پنل مشخص نیست.');
                    return;
                }
                if (draggedEl.getAttribute('data-panel-assigned') === 'true') {
                    alert('این پنل قبلاً تخصیص داده شده.');
                    return;
                }

                try {
                    // 1. Check Availability
                    const availData = await makeAjaxCall({
                        action: 'checkFormworkAvailability',
                        panelId: null,
                        date: date,
                        formworkType: baseFormworkType
                    });
                    if (!availData.available) {
                        alert(`تخصیص ناموفق: ${availData.error || 'ظرفیت تکمیل.'}`);
                        return;
                    }

                    // 2. Attempt Assignment (handles progress check, panel update, order creation)
                    let updateData = await makeAjaxCall({
                        action: 'updatePanelDate',
                        panelId: panelId,
                        date: date
                    });

                    // 3. Handle Progress Warning (Unlikely for new drop, but defensive)
                    if (updateData.progress_exists) {
                        if (confirm("هشدار: این پنل (که در حال تخصیص است) ظاهراً اطلاعات پیشرفت دارد. برای ادامه، اطلاعات پیشرفت پاک خواهد شد. ادامه می‌دهید؟")) {
                            updateData = await makeAjaxCall({
                                action: 'updatePanelDate',
                                panelId: panelId,
                                date: date,
                                forceUpdate: true
                            });
                        } else {
                            return;
                        } // User cancelled drop
                    }

                    // 4. Check final update result
                    if (!updateData.success) {
                        alert('خطا در ذخیره تاریخ: ' + (updateData.error || 'Unknown error after confirmation.'));
                        return;
                    }

                    // 5. Update UI: Mark panel as assigned, add event to calendar
                    draggedEl.setAttribute('data-panel-assigned', 'true');
                    draggedEl.className = draggedEl.className.replace(/bg-blue-\d+00|text-blue-\d+00|hover:bg-blue-\d+00|cursor-move|read-only/g, '').trim() + ' bg-gray-200 text-gray-500 cursor-not-allowed';

                    // --- MODIFICATION HERE: Title is ONLY address ---
                    calendar.addEvent({
                        title: panelAddress, // Use address only
                        start: date,
                        extendedProps: {
                            panelId: panelId,
                            address: panelAddress, // Still store address if needed
                            formworkType: baseFormworkType,

                            // You might want to fetch/add other extendedProps like panelType here if needed later
                        },
                        backgroundColor: '#22c55e', // Example color for newly added
                        borderColor: '#16a34a',
                        textColor: '#ffffff'
                    });
                    filterPanels(); // Update list visibility

                } catch (error) {
                    console.error('Error drop:', error);
                }
            },

            // --- Fetch Initial Events ---
            events: function(fetchInfo, successCallback, failureCallback) {
                // Using makeAjaxCall for consistency and error handling
                makeAjaxCall({
                        action: 'getAssignedPanels'
                    })
                    .then(response => {
                        // makeAjaxCall already checks for basic {error:..., success:false}
                        if (Array.isArray(response)) {
                            successCallback(response); // Pass the array of events
                            // IMPORTANT: Apply legend filter AFTER events are loaded/refetched
                            setTimeout(() => filterCalendarEventsByLegend(), 0);
                        } else {
                            // This case should ideally be caught by makeAjaxCall's checks
                            console.error("Invalid event data format received:", response);
                            failureCallback(new Error("فرمت نامعتبر رویداد از سرور"));
                        }
                    })
                    .catch(error => {
                        // Error already alerted by makeAjaxCall, just pass to FullCalendar
                        console.error("Failed to fetch events:", error);
                        failureCallback(error); // Pass the error object
                    });
            },

            // --- Business Hours & Display (Using eventContent - seems robust) ---
            businessHours: {
                daysOfWeek: [0, 1, 2, 3, 4, 6],
                startTime: '08:00',
                endTime: '18:00'
            },
            weekends: true,
            eventContent: function(arg) {
                const props = arg.event.extendedProps;
                const priority = props.priority;
                const formworkType = props.formworkType || 'N/A';
                const width = props.width ? 'W: ' + parseInt(props.width) + ' mm' : 'N/A';

                // Title is already just the address from the event data
                const address = arg.event.title;
                // Determine color based on panel status or formwork type if needed
                // --- Determine Background Color ---
                let bgColor = defaultEventColor; // Start with default
                if (priorityColoringEnabled) {
                    bgColor = getPriorityColor(priority); // Use priority color if enabled
                }
                // Use event's own background color property if set (FullCalendar uses this)
                // Or fallback to our calculated color
                const finalBgColor = arg.event.backgroundColor || bgColor;

                let divEl = document.createElement('div');
                divEl.className = 'fc-event-main-frame p-1 text-xs w-full overflow-hidden calendar-event-item ' +
                    (!USER_CAN_EDIT ? 'read-only-event' : '');
                divEl.style.cssText = `
                    background-color:${finalBgColor};
                    color:#fff;
                    border-radius:3px;
                    height:auto;
                    min-height:60px;
                    display:flex;
                    flex-direction:column;
                    ${USER_CAN_EDIT ? 'cursor:pointer;' : 'cursor:default;'}
                `; // Tooltip showing more details on hover
                divEl.title = `آدرس: ${address}\nاولویت: ${priority}\nقالب: ${formworkType}\nعرض پنل: ${width}`;

                const addressDiv = document.createElement('div');
                addressDiv.className = 'fc-event-title font-semibold mb-1';
                addressDiv.textContent = address;

                const formworkDiv = document.createElement('div');
                formworkDiv.className = 'fc-event-detail mb-1';
                formworkDiv.textContent = formworkType;

                const widthDiv = document.createElement('div');
                widthDiv.className = 'fc-event-detail';
                widthDiv.textContent = width;

                // Append each line to the container
                divEl.appendChild(addressDiv);
                divEl.appendChild(formworkDiv);
                divEl.appendChild(widthDiv);

                return {
                    domNodes: [divEl]
                };
            },

        }); // End FullCalendar Init


        // --- Draggable External Events Init (Unchanged) ---
        if (externalEventsContainer && USER_CAN_EDIT) {
            console.log("Initializing Draggable for editable user.");
            new FullCalendar.Draggable(externalEventsContainer, {
                itemSelector: '.panel-item:not([data-panel-assigned="true"]):not(.read-only)', // Exclude assigned and read-only styled items
                eventData: function(eventEl) {
                    // --- MODIFICATION HERE: Title is ONLY address ---
                    const address = eventEl.querySelector('.font-semibold')?.innerText || 'پنل';
                    return {
                        title: address, // Just the address for the temporary event being dragged
                        extendedProps: {
                            panelId: eventEl.getAttribute('data-panel-id'),
                            formworkType: eventEl.getAttribute('data-panel-formwork'),
                            address: address // Store address here too
                            // Optionally add panelType: eventEl.getAttribute('data-panel-type')
                        }
                    };
                },
            });
            // Touch feedback (Unchanged)
            document.querySelectorAll('.panel-item:not([data-panel-assigned="true"]):not(.read-only)').forEach(p => {
                p.addEventListener('touchstart', function() {
                    this.classList.add('opacity-75', 'scale-105');
                }, {
                    passive: true
                });
                p.addEventListener('touchend', function() {
                    this.classList.remove('opacity-75', 'scale-105');
                }, {
                    passive: true
                });
            });
        } else if (externalEventsContainer) {
            console.log("Skipping Draggable initialization for read-only user.");
            // Ensure non-editable panels have default cursor if Draggable isn't initialized
            externalEventsContainer.querySelectorAll('.panel-item:not([data-panel-assigned="true"])').forEach(p => {
                p.style.cursor = 'default';
            });
        }

        // --- Filtering Logic (Unchanged) ---
        function filterPanels() {
            const searchText = searchInput.value.toLowerCase().trim(),
                selectedType = typeFilter.value.toLowerCase(),
                selectedPriority = priorityFilter.value,
                assignmentStatus = assignmentFilter.value,
                selectedFormwork = formworkFilter.value.toLowerCase();
            const allPanelElements = document.querySelectorAll('#external-events .panel-item');
            let visiblePanelsCount = 0;
            allPanelElements.forEach(panel => {
                const addressNode = panel.querySelector('.font-semibold'),
                    address = (addressNode?.textContent || '').toLowerCase(),
                    type = (panel.getAttribute('data-panel-type') ?? '').toLowerCase(),
                    priority = panel.getAttribute('data-panel-priority') ?? "",
                    isAssigned = panel.getAttribute('data-panel-assigned') === 'true',
                    formwork = (panel.getAttribute('data-panel-formwork') ?? '').toLowerCase();
                let show = true;
                if (searchText && !address.includes(searchText)) show = false;
                if (show && selectedType && type !== selectedType) show = false;
                if (show && selectedPriority && priority !== selectedPriority) show = false;
                if (show && assignmentStatus) {
                    const shouldBeAssigned = assignmentStatus === 'assigned';
                    if (isAssigned !== shouldBeAssigned) show = false;
                }
                if (show && selectedFormwork && formwork !== selectedFormwork) show = false;
                panel.classList.toggle('hidden', !show);
                if (show) {
                    visiblePanelsCount++;
                    if (addressNode) {
                        const originalText = addressNode.dataset.originalText || addressNode.textContent || '';
                        if (searchText && !addressNode.dataset.originalText) addressNode.dataset.originalText = originalText;
                        if (searchText) {
                            const regex = new RegExp(searchText.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'), 'gi');
                            addressNode.innerHTML = (addressNode.dataset.originalText || originalText).replace(regex, '<span class="search-highlight bg-yellow-200 px-0 py-0">$&</span>');
                        } else if (addressNode.dataset.originalText) {
                            addressNode.innerHTML = addressNode.dataset.originalText;
                            delete addressNode.dataset.originalText;
                        }
                    }
                } else {
                    if (addressNode && addressNode.dataset.originalText) {
                        addressNode.innerHTML = addressNode.dataset.originalText;
                        delete addressNode.dataset.originalText;
                    } else if (addressNode && addressNode.querySelector('.search-highlight')) {
                        addressNode.innerHTML = addressNode.textContent || '';
                    }
                }
            });
            visibleCountEl.textContent = visiblePanelsCount;
            totalCountEl.textContent = allPanelElements.length;
        }
        if (searchInput) searchInput.addEventListener('input', filterPanels);
        if (typeFilter) typeFilter.addEventListener('change', filterPanels);
        if (priorityFilter) priorityFilter.addEventListener('change', filterPanels);
        if (assignmentFilter) assignmentFilter.addEventListener('change', filterPanels);
        if (formworkFilter) formworkFilter.addEventListener('change', filterPanels);

        // --- Bulk Scheduling (Updated Confirmation and Result Message Handling) ---
        bulkScheduleBtn.addEventListener('click', async function() {
            if (!USER_CAN_EDIT) return; // Extra safeguard in JS
            showButtonLoading(this);
            bulkScheduleResultEl.textContent = '';
            bulkScheduleResultEl.className = 'mt-2 text-sm p-2 rounded border bg-gray-50 border-gray-200';
            const startDateInput = document.getElementById('bulkStartDate').value;
            const endDateInput = document.getElementById('bulkEndDate').value;
            if (!startDateInput || !endDateInput) {
                alert('لطفاً تاریخ شروع و پایان را انتخاب کنید.');
                hideButtonLoading(this);
                return;
            }
            let startDate, endDate;
            try {
                startDate = moment(startDateInput, 'jYYYY/jMM/jDD').format('YYYY-MM-DD');
                endDate = moment(endDateInput, 'jYYYY/jMM/jDD').format('YYYY-MM-DD');
                if (!moment(startDate).isValid() || !moment(endDate).isValid() || moment(startDate).isAfter(endDate)) throw new Error("تاریخ نامعتبر یا شروع بعد از پایان.");
            } catch (e) {
                alert('خطا تاریخ: ' + e.message);
                hideButtonLoading(this);
                return;
            }

            const panelsToSchedule = Array.from(document.querySelectorAll('#external-events .panel-item:not(.hidden):not([data-panel-assigned="true"]):not(.read-only)')) // Ensure we don't select read-only styled ones
                .map(p => ({
                    id: p.getAttribute('data-panel-id')
                })) // Only need IDs
                .filter(p => p.id);

            if (panelsToSchedule.length === 0) {
                alert('هیچ پنل واجد شرایطی (نمایش داده شده و تخصیص نیافته) برای زمانبندی خودکار یافت نشد.');
                hideButtonLoading(this);
                return;
            }

            // --- MODIFIED Confirmation Message ---
            if (!confirm(`زمان‌بندی ${panelsToSchedule.length} پنل نمایش داده شده؟\n(برای هر پنل موفق، سفارشات 'شرق' و 'غرب' ایجاد خواهد شد)`)) {
                hideButtonLoading(this);
                return;
            }

            try {
                const result = await makeAjaxCall({
                    action: 'scheduleMultiplePanels',
                    panels: JSON.stringify(panelsToSchedule),
                    startDate: startDate,
                    endDate: endDate
                });
                // Check result structure (makeAjaxCall handles basic errors)
                if (result.success !== undefined) {
                    calendar.refetchEvents();
                    let scheduledCount = 0;
                    let totalOrdersCreated = 0;

                    // Update panel list UI
                    result.scheduled?.forEach(a => {
                        scheduledCount++;
                        const pEl = document.querySelector(`.panel-item[data-panel-id="${a.panel_id}"]`);
                        if (pEl) {
                            pEl.setAttribute('data-panel-assigned', 'true');
                            pEl.className = pEl.className.replace(/bg-blue-\d+00|text-blue-\d+00|hover:bg-blue-\d+00|cursor-move|read-only/g, '').trim() + ' bg-gray-200 text-gray-500 cursor-not-allowed';
                        }
                        // Count orders created for this panel
                        if (result.createdOrders && result.createdOrders[a.panel_id]) {
                            if (result.createdOrders[a.panel_id].east) totalOrdersCreated++;
                            if (result.createdOrders[a.panel_id].west) totalOrdersCreated++;
                        }
                    });

                    // Build result message
                    let msg = `<span class="font-semibold text-green-700">${scheduledCount} پنل زمانبندی شد (${totalOrdersCreated} سفارش شرق/غرب ایجاد/تایید شد).</span><br>`;
                    let cls = 'border-green-300 bg-green-50';
                    let unscheduledCount = result.unscheduled?.length || 0;

                    if (unscheduledCount > 0) {
                        msg += `<span class="font-semibold text-red-700">${unscheduledCount} ناموفق:</span><ul class="list-disc list-inside mt-1 text-xs">`;
                        result.unscheduled.forEach(i => {
                            // Use panel_id and reason directly from the simplified response
                            const pid = i.panel_id || '?';
                            const r = i.reason || '?';
                            const pEl = document.querySelector(`.panel-item[data-panel-id="${pid}"]`);
                            const adr = pEl ? (pEl.querySelector('.font-semibold')?.textContent || `ID: ${pid}`) : `ID: ${pid}`;
                            msg += `<li class="text-gray-700">${adr}: ${r}</li>`;
                        });
                        msg += `</ul>`;
                        cls = (scheduledCount > 0) ? 'border-yellow-300 bg-yellow-50' : 'border-red-300 bg-red-50';
                    }
                    bulkScheduleResultEl.innerHTML = msg;
                    bulkScheduleResultEl.className = `mt-2 text-sm p-2 rounded border ${cls}`;
                    filterPanels(); // Update list visibility

                } else {
                    throw new Error("پاسخ نامعتبر از سرور.");
                }
            } catch (error) {
                console.error('Error bulk schedule:', error);
                bulkScheduleResultEl.textContent = 'خطا: ' + error.message;
                bulkScheduleResultEl.className = 'mt-2 text-sm p-2 rounded border border-red-300 bg-red-50 text-red-700';
            } finally {
                hideButtonLoading(this);
            }
        });
        // --- Priority Color Toggle Button Listener (MODIFY to show/hide legend) ---
        if (togglePriorityColorBtn) {
            togglePriorityColorBtn.addEventListener('click', function() {
                priorityColoringEnabled = !priorityColoringEnabled; // Toggle coloring state

                if (priorityColoringEnabled) {
                    this.textContent = 'غیرفعال کردن رنگ اولویت';
                    this.classList.remove('bg-indigo-500', 'hover:bg-indigo-600');
                    this.classList.add('bg-gray-500', 'hover:bg-gray-600');
                    priorityLegend.classList.remove('hidden');
                    // Re-apply legend filter (in case it was active before disabling colors)
                    filterCalendarEventsByLegend();
                } else {
                    this.textContent = 'فعال کردن رنگ اولویت';
                    this.classList.remove('bg-gray-500', 'hover:bg-gray-600');
                    this.classList.add('bg-indigo-500', 'hover:bg-indigo-600');
                    priorityLegend.classList.add('hidden');

                    // **** RESET Legend Selection when disabling colors ****
                    selectedLegendPriorities.clear();
                    document.querySelectorAll('.priority-legend-item.selected-priority').forEach(item => {
                        item.classList.remove('selected-priority');
                    });
                    // Show all events when coloring is disabled
                    calendar.getEvents().forEach(event => event.setProp('display', 'auto'));
                    // *******************************************************
                }

                // Re-fetch events to apply/remove coloring
                calendar.refetchEvents();
            });
        }
        // --- NEW: Function to set initial button/legend state based on priorityColoringEnabled ---
        function setInitialButtonAndLegendState() {
            if (!togglePriorityColorBtn || !priorityLegend) return;

            if (priorityColoringEnabled) { // Initial state is ON
                togglePriorityColorBtn.textContent = 'غیرفعال کردن رنگ اولویت';
                togglePriorityColorBtn.classList.remove('bg-indigo-500', 'hover:bg-indigo-600');
                togglePriorityColorBtn.classList.add('bg-gray-500', 'hover:bg-gray-600');
                priorityLegend.classList.remove('hidden'); // Ensure legend is visible
            } else { // Fallback if default was false (shouldn't happen now but good practice)
                togglePriorityColorBtn.textContent = 'فعال کردن رنگ اولویت';
                togglePriorityColorBtn.classList.remove('bg-gray-500', 'hover:bg-gray-600');
                togglePriorityColorBtn.classList.add('bg-indigo-500', 'hover:bg-indigo-600');
                priorityLegend.classList.add('hidden'); // Ensure legend is hidden
            }
        }

        // --- Populate Legend on Load ---
        populatePriorityLegend(); // Build the legend content when the page loads
        // --- Set Initial Visual State ---
        setInitialButtonAndLegendState(); // <-- CALL THIS NEW FUNCTION
        // --- End NEW Listener ---
        // --- ADD PRINT BUTTON LISTENER ---
        const printWeekBtn = document.getElementById('printWeekBtn');
        const printColorToggle = document.getElementById('printColorToggle'); // Get the checkbox
        if (printWeekBtn && printColorToggle) { // Check both exist
            printWeekBtn.addEventListener('click', function() {
                const currentView = calendar.view.type;
                const isWeekView = currentView === 'dayGridWeek';
                const shouldPrintColors = printColorToggle.checked; // Check the checkbox state

                // Function to trigger print
                const triggerPrint = () => {
                    // Add or remove class BEFORE printing
                    if (shouldPrintColors) {
                        document.body.classList.add('print-with-colors');
                    } else {
                        document.body.classList.remove('print-with-colors');
                    }

                    window.print(); // Opens the browser's print dialog

                    // Optional: Clean up the class afterwards, though not strictly necessary
                    // as it only affects @media print. But good practice if you might
                    // reuse the class name elsewhere or want a clean state.
                    // setTimeout(() => {
                    //    document.body.classList.remove('print-with-colors');
                    // }, 500); // Needs a delay
                };

                if (!isWeekView) {
                    console.log('Switching to week view for printing...');
                    calendar.changeView('dayGridWeek');
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            triggerPrint();
                            // Optional: Switch back view
                            // setTimeout(() => { calendar.changeView(currentView); }, 1000);
                        });
                    });
                } else {
                    triggerPrint();
                }
            });
        }
        // --- END PRINT BUTTON LISTENER ---
        // --- Initial Filter Application ---
        filterPanels();
        calendar.render();

    }); // End DOMContentLoaded
</script>