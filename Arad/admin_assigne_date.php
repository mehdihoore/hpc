<?php
// admin_assign_date.php


require_once __DIR__ . '/../../sercon/bootstrap.php';

// --- Helper function to convert numbers to Persian numerals ---
function toPersianNum($number)
{
    $persian_digits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english_digits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english_digits, $persian_digits, $number);
}

// --- Translation function as requested ---
function translate_panel_data_to_persian1($key, $english_value)
{
    if ($english_value === null || $english_value === '') return '-'; // Handle empty or null

    // Generate translations programmatically
    $zone_translations = [];
    $priority_translations = [];
    for ($i = 1; $i <= 30; $i++) {
        $persian_i = toPersianNum($i);
        $zone_translations['zone ' . $i] = 'زون ' . $persian_i;
        // Assuming priorities are in 'P1', 'P2' format
        $priority_translations['P' . $i] = 'زون ' . $persian_i;
    }

    $translations = [
        'zone' => $zone_translations,
        'priority' => $priority_translations,
        'panel_position' => [
            'terrace edge' => 'لبه تراس',
            'wall panel'   => 'پنل دیواری',
        ],
        'status' => [
            'pending'    => 'در انتظار تخصیص',
            'planned'    => 'برنامه ریزی شده',
            'mesh'       => 'مش بندی',
            'concreting' => 'قالب‌بندی/بتن ریزی',
            'assembly'   => 'فیس کوت',
            'completed'  => 'تکمیل شده',
            'shipped'    => 'ارسال شده'
        ]
    ];

    if (isset($translations[$key][$english_value])) {
        return $translations[$key][$english_value];
    }
    // Fallback to English if no translation, ensuring it's safe for HTML
    return function_exists('escapeHtml') ? escapeHtml($english_value) : htmlspecialchars($english_value, ENT_QUOTES, 'UTF-8');
}


// --- Capacities & Helpers (Unchanged) ---
$formworkDailyCapacity = [
    'M-E-1' => 5,
    'M-W-1' => 5,
    'M-S-1' => 2,
    'M-N-1' => 3,
    'M-W-3' => 1,
    'M-S-2' => 1,
    'M-S-3' => 1,
    'M-E-2' => 1,
    'M-E-3' => 1,
    'M-W-2' => 1,
    'CORNER' => 2
];
function getBaseFormworkType($type)
{
    global $formworkDailyCapacity; // Access global array

    if (!$type) return null;

    $trimmedType = strtoupper(trim((string)$type));

    // Direct match
    if (isset($formworkDailyCapacity[$trimmedType])) {
        return $trimmedType;
    }

    // Optionally check partial match if needed (e.g. ignoring suffixes)
    foreach (array_keys($formworkDailyCapacity) as $baseType) {
        if (stripos($trimmedType, $baseType) === 0) {
            return $baseType;
        }
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

$current_file_path = __DIR__;
$expected_project_key = null;
if (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Fereshteh') !== false) {
    $expected_project_key = 'fereshteh';
} elseif (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Arad') !== false) {
    $expected_project_key = 'arad';
} else {
    // If the file is somehow not in a recognized project folder, handle error
    logError("admin_panel_search.php accessed from unexpected path: " . $current_file_path);
    die("خطای پیکربندی: پروژه قابل تشخیص نیست.");
}

// --- Authorization ---
// 1. Check if logged in
if (!isLoggedIn()) {
    header('Location: /login.php'); // Redirect to common login
    exit();
}
// 2. Check if user has selected ANY project
if (!isset($_SESSION['current_project_config_key'])) {
    logError("Access attempt to {$expected_project_key}/admin_panel_search.php without project selection. User ID: " . $_SESSION['user_id']);
    header('Location: /select_project.php'); // Redirect to project selection
    exit();
}
// 3. Check if the selected project MATCHES the folder this file is in
if ($_SESSION['current_project_config_key'] !== $expected_project_key) {
    logError("Project context mismatch. Session has '{$_SESSION['current_project_config_key']}', expected '{$expected_project_key}'. User ID: " . $_SESSION['user_id']);
    // Maybe redirect to select_project or show an error specific to context mismatch
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}

// --- PanelScheduler Class (Modified scheduleMultiplePanels) ---


class PanelScheduler
{
    private PDO $pdo;
    private ?int $userId; // Nullable if scheduling can be system-initiated without a user
    private array $dailyProcessCapacities; // General capacities like F-H-1 => 1
    private array $progressCols;

    // New constants for daily limits
    private const MAX_PANELS_THURSDAY = 6;
    private const MAX_PANELS_OTHER_WEEKDAY = 12;

    public function __construct(PDO $pdo, ?int $userId, array $dailyProcessCapacities, array $progressCols)
    {
        $this->pdo = $pdo;
        $this->userId = $userId; // Can be null
        $this->dailyProcessCapacities = $dailyProcessCapacities; // These are per formwork type
        $this->progressCols = $progressCols;
    }

    private function getBaseFormworkTypeExternal($type)
    { // Using the global helper
        return getBaseFormworkType($type);
    }

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

    private function isFriday(DateTime $date): bool
    {
        return $date->format('N') == 5; // 5 = Friday
    }

    private function isThursday(DateTime $date): bool
    {
        return $date->format('N') == 4; // 4 = Thursday
    }

    // Fetches UNASSIGNED, PLAN_CHECKED=1 panels, ordered by priority
    private function getCandidatePanelsToSchedule(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, full_address_identifier, formwork_type, Proritization
             FROM hpc_panels
             WHERE plan_checked = 1 AND assigned_date IS NULL AND status != 'completed' /* Or other relevant statuses */
             ORDER BY CAST(SUBSTRING(Proritization, 2) AS UNSIGNED) ASC, id ASC" // Correctly sort priorities like P1, P2, P10
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Gets count of panels already scheduled on a specific date
    private function getScheduledPanelCountForDate(string $dateStr): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM hpc_panels WHERE assigned_date = :date");
        $stmt->execute([':date' => $dateStr]);
        return (int)$stmt->fetchColumn();
    }

    // Gets count of formwork types used on a specific date
    private function getFormworkUsageOnDate(string $dateStr): array
    {
        $usage = [];
        $stmt = $this->pdo->prepare(
            "SELECT formwork_type, COUNT(*) as count
             FROM hpc_panels
             WHERE assigned_date = :date AND formwork_type IS NOT NULL
             GROUP BY formwork_type"
        );
        $stmt->execute([':date' => $dateStr]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as $row) {
            $baseType = $this->getBaseFormworkTypeExternal($row['formwork_type']);
            if ($baseType) {
                $usage[$baseType] = ($usage[$baseType] ?? 0) + (int)$row['count'];
            }
        }
        return $usage;
    }

    // Gets available formwork instances (total, not per day)
    private function getAvailableFormworkInstances(): array
    {
        $stmt = $this->pdo->query("SELECT formwork_type, available_count FROM available_formworks WHERE available_count > 0");
        $available = [];
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $type => $count) {
            $baseType = $this->getBaseFormworkTypeExternal($type);
            if ($baseType) {
                $available[$baseType] = ($available[$baseType] ?? 0) + (int)$count;
            }
        }
        return $available;
    }

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
     * New method to automatically schedule panels for a given target date from the sidebar.
     * This will be called by a NEW AJAX action.
     */
    private function checkPanelsProgress(array $panelIds): array
    {
        $panelProgressStatus = [];
        if (empty($panelIds)) {
            return $panelProgressStatus;
        }
        foreach ($panelIds as $id) {
            $panelProgressStatus[$id] = false;
        }
        $progressColsSql = implode(', ', array_map(fn($col) => "`" . $col . "`", $this->progressCols));
        $placeholders = rtrim(str_repeat('?,', count($panelIds)), ',');
        try {
            $stmtCheck = $this->pdo->prepare("SELECT id, $progressColsSql FROM hpc_panels WHERE id IN ($placeholders)");
            $stmtCheck->execute($panelIds);
            while ($row = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                $panelId = $row['id'];
                foreach ($this->progressCols as $col) {
                    if (isset($row[$col]) && !empty($row[$col])) {
                        $panelProgressStatus[$panelId] = true;
                        break;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Error checking progress for panel shift: " . $e->getMessage());
            throw $e; // Rethrow to be caught by the main try-catch
        }
        return $panelProgressStatus;
    }


    public function shiftScheduledPanels(string $fromDateStr, int $shiftDays, ?int $currentUserId): array
    {
        if ($shiftDays == 0) {
            return ['success' => true, 'message' => 'No shift requested (0 days).', 'shifted' => [], 'failed' => []];
        }

        $shiftedPanels = [];
        $failedPanels = [];
        $todayStr = (new DateTime())->format('Y-m-d'); // Get current date

        try {
            $pdoWhereClauses = ["assigned_date >= :fromDate", "(status IS NULL OR status NOT IN ('completed', 'cancelled'))"];
            $pdoParams = [':fromDate' => $fromDateStr];

            // If shifting backwards, only consider panels currently scheduled in the future
            if ($shiftDays < 0) {
                $pdoWhereClauses[] = "assigned_date > :today_for_negative_shift_filter"; // Strictly greater than today
                $pdoParams[':today_for_negative_shift_filter'] = $todayStr;
            }

            $whereSql = implode(" AND ", $pdoWhereClauses);

            $stmtFetch = $this->pdo->prepare(
                "SELECT id, full_address_identifier, assigned_date
                 FROM hpc_panels
                 WHERE $whereSql
                 ORDER BY assigned_date ASC, id ASC"
            );
            $stmtFetch->execute($pdoParams);
            $panelsToShiftCandidate = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

            if (empty($panelsToShiftCandidate)) {
                $message = 'No panels found to shift on or after the specified date';
                if ($shiftDays < 0) {
                    $message .= ' that are also scheduled for a future date.';
                }
                return ['success' => true, 'message' => $message, 'shifted' => [], 'failed' => []];
            }

            $panelIdsToCheck = array_column($panelsToShiftCandidate, 'id');
            $progressStatuses = $this->checkPanelsProgress($panelIdsToCheck);

            $this->pdo->beginTransaction();

            $stmtUpdate = $this->pdo->prepare(
                "UPDATE hpc_panels
                 SET assigned_date = :new_assigned_date,
                     planned_finish_date = :new_planned_finish_date,
                     planned_finish_user_id = :user_id
                 WHERE id = :panel_id"
            );

            foreach ($panelsToShiftCandidate as $panel) {
                $panelId = $panel['id'];

                if (isset($progressStatuses[$panelId]) && $progressStatuses[$panelId] === true) {
                    $failedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'reason' => 'Panel has progress, not shifted.'];
                    continue;
                }

                try {
                    $originalAssignedDateObj = new DateTime($panel['assigned_date']);
                    $newPotentialAssignedDateObj = (clone $originalAssignedDateObj)->modify(($shiftDays >= 0 ? '+' : '') . $shiftDays . ' days');
                    $maxAttempts = 7; // Try for a week to find a non-Friday
                    $attempt = 0;
                    while ($this->isFriday($newPotentialAssignedDateObj) && $attempt < $maxAttempts) {
                        $adjustment = ($shiftDays >= 0) ? '+1 day' : '-1 day'; // Keep original shift intent for adjustment
                        $newPotentialAssignedDateObj->modify($adjustment);
                        $attempt++;
                    }

                    // Rule: Avoid Fridays
                    if ($this->isFriday($newPotentialAssignedDateObj)) {
                        $adjustmentDirection = ($shiftDays >= 0) ? '+1 day' : '-1 day'; // Keep original shift intent
                        $newPotentialAssignedDateObj->modify($adjustmentDirection);

                        // After adjustment, re-check if it's still Friday OR if it became a past date (for negative shifts)
                        if ($this->isFriday($newPotentialAssignedDateObj)) {
                            $failedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'reason' => 'Friday avoidance resulted in another Friday.'];
                            continue;
                        }
                        if ($shiftDays < 0 && $newPotentialAssignedDateObj->format('Y-m-d') < $todayStr) {
                            $failedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'reason' => 'Friday avoidance resulted in a past date, not allowed.'];
                            continue;
                        }
                    }

                    // Rule: New assigned date cannot be in the past (less than today)
                    if ($shiftDays < 0 && $newPotentialAssignedDateObj->format('Y-m-d') < $todayStr) {
                        $failedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'reason' => 'Shift results in a past date (' . $newPotentialAssignedDateObj->format('Y-m-d') . '), not allowed. Original: ' . $panel['assigned_date']];
                        continue;
                    }



                    $newAssignedDateStr = $newPotentialAssignedDateObj->format('Y-m-d');
                    $newPlannedFinishDateStr = (clone $newPotentialAssignedDateObj)->modify('+1 day')->format('Y-m-d');

                    if ($stmtUpdate->execute([
                        ':new_assigned_date' => $newAssignedDateStr,
                        ':new_planned_finish_date' => $newPlannedFinishDateStr,
                        ':user_id' => $currentUserId,
                        ':panel_id' => $panelId
                    ])) {
                        if ($stmtUpdate->rowCount() > 0) {
                            $shiftedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'old_date' => $panel['assigned_date'], 'new_date' => $newAssignedDateStr];
                        } else {
                            $failedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'reason' => 'Panel not updated (no change/not found).'];
                        }
                    } else {
                        $failedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'reason' => 'DB update failed. Error: ' . implode(' ', $stmtUpdate->errorInfo())];
                    }
                } catch (Exception $e) {
                    error_log("Error processing panel ID {$panelId} during shift: " . $e->getMessage());
                    $failedPanels[] = ['panel_id' => $panelId, 'full_address_identifier' => $panel['full_address_identifier'], 'reason' => 'Processing error: ' . $e->getMessage()];
                }
            }

            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Shift operation completed.',
                'shifted' => $shiftedPanels,
                'failed' => $failedPanels
            ];
        } catch (PDOException | Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error in shiftScheduledPanels: " . $e->getMessage());
            // Consolidate failed panels if an exception occurred mid-processing
            if (isset($panelsToShiftCandidate) && is_array($panelsToShiftCandidate)) {
                foreach ($panelsToShiftCandidate as $p) {
                    $isAccounted = false;
                    foreach ($shiftedPanels as $s) if ($s['panel_id'] == $p['id']) $isAccounted = true;
                    foreach ($failedPanels as $f) if ($f['panel_id'] == $p['id']) $isAccounted = true;
                    if (!$isAccounted) {
                        $failedPanels[] = ['panel_id' => $p['id'], 'full_address_identifier' => $p['full_address_identifier'], 'reason' => 'Not processed due to overall operation error.'];
                    }
                }
            }
            return ['success' => false, 'error' => 'An error occurred: ' . $e->getMessage(), 'shifted' => [], 'failed' => $failedPanels];
        }
    }

    public function autoScheduleForTargetDate(string $targetDateStr): array
    {
        $scheduledOnTargetDate = [];
        $rescheduledFromTargetDate = []; // Tracks panels moved OFF the targetDate
        $failedToSchedule = [];

        try {
            $targetDateObj = new DateTime($targetDateStr);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Invalid target date format.'];
        }

        if ($this->isFriday($targetDateObj)) {
            return ['success' => false, 'error' => 'Target date cannot be a Friday.', 'target_date_invalid' => true];
        }

        $dailyPanelLimit = $this->isThursday($targetDateObj) ? self::MAX_PANELS_THURSDAY : self::MAX_PANELS_OTHER_WEEKDAY;

        $this->pdo->beginTransaction();
        try {
            // --- Stage 1: Attempt to schedule new panels ---
            $candidatePanels = $this->getCandidatePanelsToSchedule();
            $currentPanelsOnTargetDate = $this->getScheduledPanelCountForDate($targetDateStr);
            $formworkUsageOnTargetDate = $this->getFormworkUsageOnDate($targetDateStr);
            $availableFormworkInstances = $this->getAvailableFormworkInstances(); // Total available inventory

            $slotsFilledToday = $currentPanelsOnTargetDate;

            foreach ($candidatePanels as $candidate) {
                if ($slotsFilledToday >= $dailyPanelLimit) {
                    $failedToSchedule[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'reason' => 'Daily panel limit reached for target date.'];
                    continue; // No more slots today
                }

                // Check progress for this candidate
                $progressStmt = $this->pdo->prepare("SELECT " . implode(', ', $this->progressCols) . " FROM hpc_panels WHERE id = ?");
                $progressStmt->execute([$candidate['id']]);
                $progressData = $progressStmt->fetch(PDO::FETCH_ASSOC);
                $hasProgress = false;
                if ($progressData) {
                    foreach ($this->progressCols as $col) {
                        if (!empty($progressData[$col])) {
                            $hasProgress = true;
                            break;
                        }
                    }
                }
                if ($hasProgress) {
                    $failedToSchedule[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'reason' => 'Has existing progress.'];
                    continue;
                }

                // Check formwork type capacity for this specific panel
                $baseCandidateFormworkType = $this->getBaseFormworkTypeExternal($candidate['formwork_type']);
                if (!$baseCandidateFormworkType) {
                    $failedToSchedule[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'reason' => 'Invalid formwork type.'];
                    continue;
                }

                $formworkTypeDailyLimit = $this->dailyProcessCapacities[$baseCandidateFormworkType] ?? 0;
                $formworkTypeUsageToday = $formworkUsageOnTargetDate[$baseCandidateFormworkType] ?? 0;
                $totalOfTypeAvailable = $availableFormworkInstances[$baseCandidateFormworkType] ?? 0;


                // Check if we have an instance of this formwork type available overall
                // AND if adding one more to today doesn't exceed its specific daily process limit
                // AND if adding one more doesn't exceed the total number of available instances of that type (inventory check)
                // The inventory check is tricky: `getFormworkUsageOnDate` needs to be accurate for overall usage.
                // For this specific method, let's simplify: the dailyProcessCapacities is key.
                // We also need to ensure that the total number of *this specific formwork type* used on this day
                // does not exceed the number of physical instances of that formwork available.

                if ($formworkTypeDailyLimit <= 0) {
                    $failedToSchedule[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'reason' => "No daily processing capacity for formwork {$baseCandidateFormworkType}."];
                    continue;
                }
                if ($formworkTypeUsageToday >= $formworkTypeDailyLimit) {
                    $failedToSchedule[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'reason' => "Daily limit for formwork {$baseCandidateFormworkType} reached on target date."];
                    continue;
                }
                // More advanced: check against total available instances if `formworkTypeDailyLimit` could be higher than total stock
                if ($formworkTypeUsageToday >= $totalOfTypeAvailable) {
                    $failedToSchedule[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'reason' => "All available instances of formwork {$baseCandidateFormworkType} are already in use or scheduled."];
                    continue;
                }


                // If all checks pass, schedule this panel
                $updateStmt = $this->pdo->prepare(
                    "UPDATE hpc_panels SET assigned_date = :assigned_date, planned_finish_date = :planned_finish_date, planned_finish_user_id = :user_id
                     WHERE id = :panel_id"
                );
                $plannedFinishDate = (clone $targetDateObj)->modify('+1 day')->format('Y-m-d');
                if ($updateStmt->execute([
                    ':assigned_date' => $targetDateStr,
                    ':planned_finish_date' => $plannedFinishDate,
                    ':user_id' => $this->userId,
                    ':panel_id' => $candidate['id']
                ])) {
                    $scheduledOnTargetDate[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'assigned_to' => $targetDateStr];
                    $slotsFilledToday++;
                    $formworkUsageOnTargetDate[$baseCandidateFormworkType] = ($formworkUsageOnTargetDate[$baseCandidateFormworkType] ?? 0) + 1;
                } else {
                    $failedToSchedule[] = ['panel_id' => $candidate['id'], 'full_address_identifier' => $candidate['full_address_identifier'], 'reason' => 'DB update failed.'];
                }
            } // End foreach candidatePanel

            // --- Stage 2: Reschedule lowest priority panels if new high-priority ones couldn't fit (Complex) ---
            // This part is very tricky and can lead to cascading effects.
            // For now, this example will NOT implement the automatic rescheduling of existing panels.
            // It will simply fill available slots.
            // To implement rescheduling:
            // 1. If $slotsFilledToday was already >= $dailyPanelLimit before starting,
            //    OR if after scheduling some new ones, higher priority candidates still exist but no slots left:
            // 2. Get panels ALREADY scheduled on $targetDateStr, ordered by LOWEST priority (e.g., P12 before P1).
            // 3. For each of these, try to find the NEXT available valid day (not Friday, respects its own day limits).
            // 4. If a new slot is found, UPDATE that panel's assigned_date. Add to $rescheduledFromTargetDate.
            // 5. This frees up a slot on $targetDateStr. Potentially re-run Stage 1 for remaining high-priority candidates.
            // This needs careful loop control and state management.

            $this->pdo->commit();
            return [
                'success' => true,
                'scheduled_new' => $scheduledOnTargetDate,
                'rescheduled_existing' => $rescheduledFromTargetDate, // Will be empty for now
                'failed_to_schedule' => $failedToSchedule,
                'message' => "Auto-scheduling complete for {$targetDateStr}."
            ];
        } catch (PDOException | Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log("Error in autoScheduleForTargetDate ({$targetDateStr}): " . $e->getMessage());
            return ['success' => false, 'error' => 'An error occurred during auto-scheduling: ' . $e->getMessage()];
        }
    }

    // The old scheduleMultiplePanels is still used by the "زمانبندی خودکار" button.
    // Keep it as it was, or if the button should also use the new logic, then this old one can be removed/refactored.
    // For now, I'm assuming the button uses the old logic.
    public function scheduleMultiplePanels(array $panels, string $startDate, string $endDate): array
    {

        $assignmentsMade = [];
        $unscheduledPanels = [];
        $createdOrdersInfo = [];

        try {
            $currentAvailableCounts = $this->getAvailableInstanceCounts();
            $assignmentsUsage = $this->getAssignmentsCountByBaseTypeAndDate($startDate, $endDate);

            $dateRange = [];
            $current = new DateTime($startDate);
            $end = new DateTime($endDate);
            while ($current <= $end) {
                $dateRange[] = $current->format('Y-m-d');
                $current->modify('+1 day');
            }
            if (empty($dateRange)) {
                return ['success' => false, 'error' => 'Invalid date range.', 'scheduled' => [], 'unscheduled' => array_map(fn($p) => ['panel_id' => $p['id'] ?? '?', 'reason' => 'Invalid date range'], $panels), 'createdOrders' => []];
            }

            $panelIds = array_column($panels, 'id');
            $panelDetailsFromDB = [];
            if (!empty($panelIds)) {
                $placeholders = rtrim(str_repeat('?,', count($panelIds)), ',');
                $progressColsSql = implode(', ', array_map(fn($col) => "`" . $col . "`", $this->progressCols));
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
            }

            foreach ($panels as $panelInput) {
                $panelId = $panelInput['id'] ?? null;
                if (!$panelId || !isset($panelDetailsFromDB[$panelId])) {
                    $unscheduledPanels[] = ['panel_id' => $panelId ?? '?', 'reason' => 'Panel ID invalid or details not found'];
                    continue;
                }
                $panelDetail = $panelDetailsFromDB[$panelId];
                $basePanelFormworkType = $this->getBaseFormworkTypeExternal($panelDetail['formwork_type']);

                if (!$basePanelFormworkType) {
                    $unscheduledPanels[] = ['panel_id' => $panelId, 'reason' => 'Invalid/missing Formwork Type in DB'];
                    continue;
                }
                if ($panelDetail['has_progress']) {
                    $unscheduledPanels[] = ['panel_id' => $panelId, 'reason' => 'Skipped: Existing progress data found.'];
                    continue;
                }
                $currentlyAvailableOfType = $currentAvailableCounts[$basePanelFormworkType] ?? 0;
                if ($currentlyAvailableOfType <= 0) {
                    $unscheduledPanels[] = ['panel_id' => $panelId, 'reason' => "No available instances for type '{$basePanelFormworkType}'"];
                    continue;
                }
                $dailyProcessLimit = $this->dailyProcessCapacities[$basePanelFormworkType] ?? 0;
                if ($dailyProcessLimit <= 0) {
                    $unscheduledPanels[] = ['panel_id' => $panelId, 'reason' => "Daily processing capacity not defined for '{$basePanelFormworkType}'"];
                    continue;
                }
                $scheduledThisPanel = false;
                foreach ($dateRange as $date) {
                    $currentUsageOnDate = $assignmentsUsage[$basePanelFormworkType][$date] ?? 0;
                    if ($currentUsageOnDate < $currentlyAvailableOfType && $currentUsageOnDate < $dailyProcessLimit) {
                        $assignmentsMade[] = ['panel_id' => $panelId, 'assigned_date' => $date];
                        $assignmentsUsage[$basePanelFormworkType][$date] = ($assignmentsUsage[$basePanelFormworkType][$date] ?? 0) + 1;
                        $scheduledThisPanel = true;
                        break;
                    }
                }
                if (!$scheduledThisPanel) {
                    $unscheduledPanels[] = ['panel_id' => $panelId, 'reason' => "ظرفیت قالب :  '{$basePanelFormworkType}' برای تاریخ {$startDate}-{$endDate} پر است."];
                }
            }

            if (!empty($assignmentsMade)) {
                $this->pdo->beginTransaction();
                try {
                    $stmtUpdatePanel = $this->pdo->prepare(
                        "UPDATE hpc_panels
                         SET assigned_date = :assigned_date,
                             planned_finish_date = :planned_finish_date_val,
                             planned_finish_user_id = :userId
                         WHERE id = :panel_id"
                    );


                    foreach ($assignmentsMade as $assignment) {
                        $panelId = $assignment['panel_id'];
                        $assignedDate = $assignment['assigned_date'];
                        $plannedFinishDatePhp = (new DateTime($assignedDate))->modify('+1 day')->format('Y-m-d');
                        $requiredDeliveryDate = (new DateTime($assignedDate))->modify('-1 day')->format('Y-m-d');

                        $stmtUpdatePanel->bindValue(':assigned_date', $assignedDate, PDO::PARAM_STR);
                        $stmtUpdatePanel->bindValue(':planned_finish_date_val', $plannedFinishDatePhp, PDO::PARAM_STR);
                        $stmtUpdatePanel->bindValue(':userId', $this->userId, PDO::PARAM_INT);
                        $stmtUpdatePanel->bindValue(':panel_id', $panelId, PDO::PARAM_INT);
                        if (!$stmtUpdatePanel->execute()) {
                            throw new PDOException("DB Update failed for panel ID: $panelId");
                        }
                    }
                    $this->pdo->commit();
                } catch (PDOException | Exception $e) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    error_log("TRANSACTION FAILED in PanelScheduler (old scheduleMultiplePanels): " . $e->getMessage());
                    $dbFailedPanelIds = array_column($assignmentsMade, 'panel_id');
                    $tempUnscheduled = $unscheduledPanels; // Keep pre-check failures
                    foreach ($panels as $inputPanelItem) {
                        $currentPanelId = $inputPanelItem['id'] ?? null;
                        if ($currentPanelId && in_array($currentPanelId, $dbFailedPanelIds)) {
                            $isAlreadyMarked = false;
                            foreach ($tempUnscheduled as $uP) if (($uP['panel_id'] ?? null) == $currentPanelId) $isAlreadyMarked = true;
                            if (!$isAlreadyMarked) $tempUnscheduled[] = ['panel_id' => $currentPanelId, 'reason' => 'DB Transaction Failed'];
                        }
                    }
                    $unscheduledPanels = $tempUnscheduled;
                    return ['success' => false, 'error' => 'Database update/order creation failed. Check logs.', 'scheduled' => [], 'unscheduled' => $unscheduledPanels, 'createdOrders' => []];
                }
            }
            return [
                'success' => true,
                'scheduled' => $assignmentsMade,
                'unscheduled' => $unscheduledPanels,
                'error' => null,
                'createdOrders' => $createdOrdersInfo
            ];
        } catch (PDOException $e) {
            $errorMsg = 'DB error during scheduling prep (old scheduleMultiplePanels): ' . $e->getMessage();
            error_log($errorMsg);
            return ['success' => false, 'error' => $errorMsg, 'scheduled' => [], 'unscheduled' => array_map(fn($p) => ['panel_id' => $p['id'] ?? '?', 'reason' => 'DB Prep Error'], $panels), 'createdOrders' => []];
        } catch (Exception $e) {
            $errorMsg = 'Unexpected error during scheduling prep (old scheduleMultiplePanels): ' . $e->getMessage();
            error_log($errorMsg);
            return ['success' => false, 'error' => $errorMsg, 'scheduled' => [], 'unscheduled' => array_map(fn($p) => ['panel_id' => $p['id'] ?? '?', 'reason' => 'Unexpected Prep Error'], $panels), 'createdOrders' => []];
        }
    }
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
        $pdo = getProjectDBConnection();
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
        // --- ACTION: getMorePanels (NEW) ---
        if ($action === 'getMorePanels') {
            $page = filter_input(INPUT_POST, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
            $panelsPerPage = filter_input(INPUT_POST, 'panelsPerPage', FILTER_VALIDATE_INT, ['options' => ['default' => 50, 'min_range' => 10]]);

            $searchText = $_POST['searchText'] ?? '';
            $selectedType = $_POST['selectedType'] ?? '';
            $selectedPriority = $_POST['selectedPriority'] ?? '';
            $assignmentStatus = $_POST['assignmentStatus'] ?? '';
            $selectedFormwork = $_POST['selectedFormwork'] ?? '';

            $offset = ($page - 1) * $panelsPerPage;

            // UPDATED: Added hp.floor to the SELECT statement
            $baseQuerySelect = "SELECT hp.id, hp.full_address_identifier, hp.status, hp.assigned_date, hp.type, hp.floor, hp.area, hp.width, hp.length, hp.Proritization, hp.formwork_type ";
            $baseQueryFromWhere = "FROM hpc_panels hp WHERE hp.plan_checked = 1";

            $whereClauses = [];
            $queryParams = [];

            if (!empty($searchText)) {
                $whereClauses[] = "hp.full_address_identifier LIKE :searchText";
                $queryParams[':searchText'] = '%' . $searchText . '%';
            }
            if (!empty($selectedType)) {
                // UPDATED: Filter by floor instead of type
                $whereClauses[] = "hp.floor = :selectedFloor";
                $queryParams[':selectedFloor'] = $selectedType; // The variable name is still selectedType from the form
            }
            if ($selectedPriority !== '') {
                if ($selectedPriority === 'IS_NULL_PRIORITY_PLACEHOLDER') { // Special value for NULL/empty priority
                    $whereClauses[] = "(hp.Proritization IS NULL OR hp.Proritization = '')";
                } else {
                    $whereClauses[] = "hp.Proritization = :selectedPriority";
                    $queryParams[':selectedPriority'] = $selectedPriority;
                }
            }
            if (!empty($assignmentStatus)) {
                if ($assignmentStatus === 'assigned') {
                    $whereClauses[] = "hp.assigned_date IS NOT NULL";
                } elseif ($assignmentStatus === 'unassigned') {
                    $whereClauses[] = "hp.assigned_date IS NULL";
                }
            }
            if (!empty($selectedFormwork)) {
                // Assuming formwork_type in DB might contain instance details.
                // This will match if the base type is at the beginning.
                $whereClauses[] = "hp.formwork_type LIKE :selectedFormworkBase";
                $queryParams[':selectedFormworkBase'] = $selectedFormwork . '%';
            }

            $queryFilterString = "";
            if (!empty($whereClauses)) {
                $queryFilterString = " AND " . implode(" AND ", $whereClauses);
            }

            $countQuery = "SELECT COUNT(*) " . $baseQueryFromWhere . $queryFilterString;
            // UPDATED: Corrected priority sorting to be numeric
            $dataQuery = $baseQuerySelect . $baseQueryFromWhere . $queryFilterString . " ORDER BY CAST(SUBSTRING(hp.Proritization, 2) AS UNSIGNED) ASC, hp.id DESC LIMIT :limit OFFSET :offset";

            try {
                $stmtTotal = $pdo->prepare($countQuery);
                $stmtTotal->execute($queryParams);
                $totalFilteredPanels = (int)$stmtTotal->fetchColumn();

                $stmtData = $pdo->prepare($dataQuery);
                foreach ($queryParams as $key => $value) {
                    $stmtData->bindValue($key, $value);
                }
                $stmtData->bindValue(':limit', $panelsPerPage, PDO::PARAM_INT);
                $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmtData->execute();
                $panels = $stmtData->fetchAll(PDO::FETCH_ASSOC);

                foreach ($panels as &$panel) { // Add baseFormworkType for easier JS handling
                    $panel['baseFormworkType'] = getBaseFormworkType($panel['formwork_type']);
                }
                unset($panel);

                $httpStatusCode = 200;
                $response = [
                    'success' => true,
                    'panels' => $panels,
                    'totalFiltered' => $totalFilteredPanels,
                    'currentPage' => $page,
                    'hasMore' => ($offset + count($panels)) < $totalFilteredPanels
                ];
            } catch (PDOException $e) {
                error_log("DB Error getMorePanels: " . $e->getMessage());
                $httpStatusCode = 500;
                $response = ['success' => false, 'error' => 'Database error fetching more panels.'];
            }
        }
        // --- ACTION: updatePanelDate (Handles Single Assignment/Unassignment, Progress, and TWO Orders) ---
        elseif ($action === 'updatePanelDate') {
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
                    'error' => 'پنل دارای پیشرفت است و نمی توان تاریخ آن را تغییر داد.'
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
                    $message = "تخصیص پنل به‌روزرسانی شد.";

                    try {
                        $assignedDateTime = new DateTime($date); // Use $date (validated input)
                        $assignedDateTime->modify('-1 day');
                        $requiredDeliveryDate = $assignedDateTime->format('Y-m-d');
                    } catch (Exception $e) {
                        error_log("Error calculating delivery date for panel {$panelId}, assigned {$date}: " . $e->getMessage());
                        throw new PDOException("Failed to calculate delivery date for panel {$panelId}.");
                    }


                    // Do not append any additional messages regarding order updates or creations.
                } else {
                    // --- Unassigning Date: Delete BOTH 'pending' Orders ---
                    $message = 'Panel unassigned.';
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
        } elseif ($action === 'autoScheduleForDate') { // NEW ACTION
            if (!$canEdit) {
                $httpStatusCode = 403;
                $response = ['success' => false, 'error' => 'Permission denied.'];
                goto send_response;
            }
            $targetDate = $_POST['targetDate'] ?? null; // Expects 'YYYY-MM-DD'

            if (!$targetDate || DateTime::createFromFormat('Y-m-d', $targetDate) === false) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Invalid target date provided.'];
            } else {
                // Ensure PanelScheduler class is available/included
                $scheduler = new PanelScheduler($pdo, (int)$current_user_id, $formworkDailyCapacity, $progressColumns);
                $result = $scheduler->autoScheduleForTargetDate($targetDate); // Call the new method

                // Set HTTP status code based on result
                $httpStatusCode = 200; // Default to 200
                if (isset($result['success']) && !$result['success']) {
                    $httpStatusCode = (isset($result['target_date_invalid']) && $result['target_date_invalid']) ? 400 : 500; // 400 for client error, 500 for server
                }
                $response = $result;
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
        } elseif ($action === 'shiftScheduledPanels') {
            if (!$canEdit) {
                $httpStatusCode = 403;
                $response = ['success' => false, 'error' => 'Permission denied.'];
                goto send_response;
            }

            $fromDate = $_POST['fromDate'] ?? null; // Expects 'YYYY-MM-DD'
            $shiftDays = filter_input(INPUT_POST, 'shiftDays', FILTER_VALIDATE_INT);

            if (!$fromDate || DateTime::createFromFormat('Y-m-d', $fromDate) === false) {
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Invalid "from date" provided.'];
            } elseif ($shiftDays === null || $shiftDays === false) { // filter_input returns false on failure, null if not set
                $httpStatusCode = 400;
                $response = ['success' => false, 'error' => 'Invalid number of days to shift. Must be an integer.'];
            } elseif ($shiftDays == 0) {
                $httpStatusCode = 200; // Or 400 if you consider 0 an invalid input for a shift
                $response = ['success' => true, 'message' => 'No shift requested (0 days).', 'shifted' => [], 'failed' => []];
            } else {
                $scheduler = new PanelScheduler($pdo, (int)$current_user_id, $formworkDailyCapacity, $progressColumns);
                $result = $scheduler->shiftScheduledPanels($fromDate, (int)$shiftDays, (int)$current_user_id);
                $httpStatusCode = $result['success'] ? 200 : 500; // Or 400 for specific validation errors from method
                $response = $result;
            }
        }


        // --- ACTION: getAssignedPanels (Unchanged - Title is already just full_address_identifier) ---
        elseif ($action === 'getAssignedPanels') {
            try {
                // UPDATED: Corrected hp.widt to hp.width and added hp.floor
                $stmt = $pdo->prepare("SELECT hp.id, hp.full_address_identifier, hp.assigned_date, hp.planned_finish_date, hp.formwork_type, hp.status, hp.type, hp.Proritization, hp.width, hp.floor
                                       FROM hpc_panels hp
                                       WHERE hp.assigned_date IS NOT NULL");
                $stmt->execute();
                $dbPanels = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $events = [];

                foreach ($dbPanels as $panel) {
                    $baseFormworkType = getBaseFormworkType($panel['formwork_type']);

                    // UPDATED: Transform priority from 'P1' to 'zone 1' for JS
                    $priorityForJs = $panel['Proritization'] ? 'zone ' . preg_replace('/[^0-9]/', '', $panel['Proritization']) : null;

                    // --- MODIFICATION HERE: Title is ONLY full_address_identifier ---
                    $events[] = [
                        'title' => $panel['full_address_identifier'], // ONLY full_address_identifier
                        'start' => $panel['assigned_date'],
                        // 'end' => $panel['planned_finish_date'], // Uncomment if you want events to span duration
                        'extendedProps' => [
                            'panelId' => $panel['id'],
                            'full_address_identifier' => $panel['full_address_identifier'], // Keep full_address_identifier here too if needed
                            'formworkType' => $baseFormworkType, // Store base type
                            'width' => $panel['width'], // Store width
                            'fullFormworkType' => $panel['formwork_type'], // Store full type
                            'panelType' => $panel['type'], // Store the panel's own type
                            'status' => $panel['status'], // Store panel status
                            'priority' => $priorityForJs, // UPDATED: Use transformed priority
                            'floor' => $panel['floor'] // ADDED: Add floor data
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
    $pdo = getProjectDBConnection();
    $page_load_user_id = $_SESSION['user_id'] ?? null;
    $page_load_user_role = $_SESSION['role'] ?? null;
    $userCanEditPageLoad = userCanEdit($page_load_user_role);

    $panelsPerPage = 50; // Configurable: Number of panels to load initially and per "load more"
    $currentPage = 1; // For initial load

    // Get grand total count of all plan_checked=1 panels
    $stmtGrandTotal = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE plan_checked = 1");
    $grandTotalPanels = (int)$stmtGrandTotal->fetchColumn();

    // Fetch first page of panels (no filters applied on initial load, shows highest priority)
    $offset = ($currentPage - 1) * $panelsPerPage;
    // UPDATED: Added hp.floor to SELECT and corrected priority sorting
    $stmt = $pdo->prepare("SELECT hp.id, hp.full_address_identifier, hp.status, hp.assigned_date, hp.type, hp.floor, hp.area, hp.width, hp.length, hp.Proritization, hp.formwork_type
                           FROM hpc_panels hp
                           WHERE hp.plan_checked = 1
                           ORDER BY CAST(SUBSTRING(hp.Proritization, 2) AS UNSIGNED) ASC, hp.id DESC
                           LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $panelsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $initialPanels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For filter dropdowns, get all unique values from the entire dataset.
    // This might be slow on very large datasets; consider optimizing if needed.
    // UPDATED: Changed `type` to `floor` in the query
    $stmtAllForFilters = $pdo->query("SELECT DISTINCT floor, Proritization, formwork_type FROM hpc_panels WHERE plan_checked = 1");
    $filterData = $stmtAllForFilters->fetchAll(PDO::FETCH_ASSOC);

    // The user's code already correctly used 'floor' here, now the query matches.
    $typesForFilter = array_values(array_unique(array_filter(array_column($filterData, 'floor'))));
    sort($typesForFilter);

    $prioritiesForFilter = array_values(array_unique(array_column($filterData, 'Proritization')));
    // Custom sort to handle nulls/empty strings for "بدون اولویت" appearing correctly if desired
    usort($prioritiesForFilter, function ($a, $b) {
        if ($a === null || $a === '') return -1; // Empty/null comes first or last
        if ($b === null || $b === '') return 1;
        // Correct numeric sort for priorities like P1, P2, P10
        $numA = (int)substr($a, 1);
        $numB = (int)substr($b, 1);
        return $numA <=> $numB;
    });

    $formworkTypesForFilter = array_values(array_unique(array_map('getBaseFormworkType', array_column($filterData, 'formwork_type'))));
    $formworkTypesForFilter = array_filter($formworkTypesForFilter); // Remove nulls from getBaseFormworkType
    sort($formworkTypesForFilter);

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
    const PANELS_PER_PAGE = <?php echo $panelsPerPage; ?>;
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



    .panel-item.panel-selected {
        background-color: #bfdbfe !important;
        /* Tailwind's blue-200 */
        border: 1px solid #60a5fa !important;
        /* Tailwind's blue-400 */
        box-shadow: 0 0 0 2px #3b82f6;
        /* Ring effect, blue-500 */
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
                <!-- Updated Panel Stats Area -->
                <div id="panelStats" class="text-sm text-gray-500 mb-3 border-b pb-2">
                    نمایش: <span id="visibleCountEl" class="font-semibold text-gray-700">0</span>
                    (از <span id="loadedPanelsCountEl" class="font-semibold text-gray-700">0</span>)
                    / <span id="totalFilteredPanelsCountEl" class="font-semibold text-gray-700">0</span>
                    <span class="text-xs block mt-1">کل پنل‌های آماده: <span id="grandTotalPanelsCountEl"><?php echo $grandTotalPanels; ?></span></span>
                </div>

                <div id="external-events"
                    class="external-events space-y-2 max-h-[calc(100vh-480px)] md:max-h-[55vh] overflow-y-auto pr-2 <?php echo !$userCanEditPageLoad ? 'read-only-list' : ''; ?>"
                    data-grand-total-panels="<?php echo $grandTotalPanels; ?>">
                    <?php if (empty($initialPanels)): ?>
                        <p class="text-center text-gray-500 italic">هیچ پنل آماده‌ای یافت نشد.</p>
                        <?php else:
                        foreach ($initialPanels as $panel):
                            $assigned = $panel['assigned_date'] ? 'true' : 'false';
                            $baseFormworkType = getBaseFormworkType($panel['formwork_type']);
                            $panelClasses = 'panel-item p-2 rounded shadow-sm';
                            if ($assigned === 'true') {
                                $panelClasses .= ' bg-gray-200 text-gray-500 cursor-not-allowed';
                            } elseif (!$userCanEditPageLoad) {
                                $panelClasses .= ' bg-blue-100 text-blue-800 read-only-item'; // Differentiate read-only item
                            } else {
                                $panelClasses .= ' bg-blue-100 text-blue-800 hover:bg-blue-200 cursor-grab'; // cursor-grab for draggable
                            }
                        ?>
                            <div class="<?php echo $panelClasses; ?>"
                                data-panel-id="<?php echo $panel['id']; ?>"
                                data-panel-type="<?php echo htmlspecialchars($panel['type'] ?? ''); ?>"
                                data-panel-floor="<?php echo htmlspecialchars($panel['floor'] ?? ''); ?>"
                                data-panel-assigned="<?php echo $assigned; ?>"
                                data-panel-width="<?php echo htmlspecialchars($panel['width'] ?? ''); ?>"
                                data-panel-length="<?php echo htmlspecialchars($panel['length'] ?? ''); ?>"
                                data-panel-priority="<?php echo htmlspecialchars($panel['Proritization'] ?? ''); ?>"
                                data-panel-formwork="<?php echo htmlspecialchars($baseFormworkType ?? ''); ?>">
                                <div class="font-semibold text-sm break-words"> <?php echo htmlspecialchars($panel['full_address_identifier']); ?> </div>
                                <div class="text-xs mt-1 text-gray-600">
                                    قالب: <span class="font-medium"><?php echo htmlspecialchars($baseFormworkType ?? 'N/A'); ?></span> |
                                    اولویت: <span class="font-medium"><?php echo ($panel['Proritization'] === null || $panel['Proritization'] === '') ? 'N/A' : htmlspecialchars(translate_panel_data_to_persian1('zone', $panel['Proritization'])); ?></span>
                                </div>
                                <!-- UPDATED: Changed type to floor -->
                                <div class="text-xs text-gray-500"> طبقه: <?php echo htmlspecialchars($panel['floor'] ?: 'N/A'); ?> | <?php echo $panel['area']; ?>m² | W:<?php echo $panel['width']; ?> L:<?php echo $panel['length']; ?> </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
                <!-- Load More Button -->
                <div id="loadMoreContainer" class="mt-4 text-center <?php echo ($grandTotalPanels <= $panelsPerPage) ? 'hidden' : ''; ?>">
                    <button id="loadMorePanelsBtn" type="button" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded shadow-sm disabled:opacity-50">
                        بارگذاری بیشتر
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white p-4 rounded-lg shadow">
                <h3 class="text-lg font-bold mb-4 text-gray-700">جستجو و فیلتر</h3>
                <div class="space-y-3">
                    <div>
                        <label for="assignmentFilter" class="block text-sm font-medium text-gray-600 mb-1">وضعیت تخصیص</label>
                        <select id="assignmentFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="unassigned">تخصیص داده نشده (پیش‌فرض)</option>
                            <option value="assigned">تخصیص داده شده</option>
                            <option value="">همه</option>
                        </select>
                    </div>
                    <div><label for="searchInput" class="block text-sm font-medium text-gray-600 mb-1">آدرس</label><input type="text" id="searchInput" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm" placeholder="..."></div>
                    <!-- UPDATED: Changed label from "نوع" to "طبقه" -->
                    <div><label for="typeFilter" class="block text-sm font-medium text-gray-600 mb-1">طبقه</label><select id="typeFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="">همه</option>
                            <?php foreach ($typesForFilter as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label for="priorityFilter" class="block text-sm font-medium text-gray-600 mb-1">اولویت</label><select id="priorityFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="">همه</option>
                            <option value="IS_NULL_PRIORITY_PLACEHOLDER">بدون اولویت</option> <!-- For NULL/Empty priorities -->
                            <?php foreach ($prioritiesForFilter as $priority):
                                if ($priority !== null && $priority !== ''): // Avoid duplicating "بدون اولویت"

                                    $translated_priority = translate_panel_data_to_persian1('zone', $priority);
                            ?>
                                    <option value="<?php echo htmlspecialchars($priority); ?>">
                                        <?php echo htmlspecialchars($translated_priority); ?>
                                    </option>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </select></div>


                    <div>
                        <label for="formworkFilter" class="block text-sm font-medium text-gray-600 mb-1">نوع قالب</label>
                        <select id="formworkFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500 shadow-sm">
                            <option value="">همه</option>
                            <?php foreach ($formworkTypesForFilter as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
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
            <div class="bg-white p-4 rounded-lg shadow mt-4">
                <h3 class="text-lg font-bold mb-4 text-gray-700">جابجایی پنل‌های زمان‌بندی شده</h3>
                <div class="space-y-3">
                    <div>
                        <label for="shiftFromDate" class="block text-sm font-medium text-gray-600 mb-1">جابجایی پنل‌ها از تاریخ</label>
                        <input type="text" id="shiftFromDate" class="w-full p-2 border border-gray-300 rounded persian-datepicker shadow-sm" placeholder="YYYY/MM/DD" <?php echo !$userCanEditPageLoad ? 'disabled' : ''; ?>>
                    </div>
                    <div>
                        <label for="shiftDays" class="block text-sm font-medium text-gray-600 mb-1">تعداد روزهای جابجایی (مثبت برای آینده، منفی برای گذشته)</label>
                        <input type="number" id="shiftDays" class="w-full p-2 border border-gray-300 rounded shadow-sm" value="1" <?php echo !$userCanEditPageLoad ? 'disabled' : ''; ?>>
                    </div>
                    <button id="executeShiftBtn" type="button" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-150 ease-in-out flex items-center justify-center gap-2 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed" <?php echo !$userCanEditPageLoad ? 'disabled' : ''; ?>>
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.566.379-1.566 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.566 2.6 1.566 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.566-.379 1.566-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="button-text">اجرای جابجایی</span>
                    </button>
                    <div id="shiftResult" class="mt-2 text-sm p-2 rounded border bg-gray-50 border-gray-200 min-h-[3em]"></div>
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
<script src="assets/fullcalendar-6.1.17/package/index.global.min.js"></script>
<link rel="stylesheet" href="/assets/css/persian-datepicker-dark.min.css">
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

    .panel-item.panel-selected {
        background-color: #bfdbfe !important;
        /* Tailwind's blue-200 */
        border: 1px solid #60a5fa !important;
        /* Tailwind's blue-400 */
        box-shadow: 0 0 0 2px #3b82f6;
        /* Ring effect, blue-500 */
    }

    a {
        color: #111827;
        text-decoration: none;
    }

    .fc-event {
        transition: opacity 0.15s ease-in-out, transform 0.15s ease-in-out, border-color 0.15s ease-in-out;
        border: 2px solid transparent !important;
        /* Default transparent border to prevent layout shifts when selected border is added */
        /* box-sizing: border-box; /* Ensures border doesn't add to element size if not already set */
    }

    /* 2. When multi-selection mode is active, dim unselected events */
    body.calendar-multi-selecting .fc-event:not(.calendar-event-selected) {
        opacity: 0.60 !important;
        /* Make it a bit more dimmed */
    }

    /* 3. Styling for SELECTED events */
    .fc-event.calendar-event-selected {
        background-color: lime !important;
        /* Very obvious color */
        border: 5px dashed red !important;
        transform: scale(1.1) !important;
        opacity: 1 !important;
        z-index: 1000 !important;
        /* Even higher z-index */
    }

    /* And ensure the non-selected are definitely dimmed */
    body.calendar-multi-selecting .fc-event:not(.calendar-event-selected) {
        opacity: 0.4 !important;
    }

    @media print {
        body {
            margin: 0;
            padding: 0;
            font-size: 6pt;
            /* Base font size for print */

        }

        body.print-with-colors {
            -webkit-print-color-adjust: exact !important;
            /* Chrome/Safari */
            print-color-adjust: exact !important;
            /* Standard */
        }

        /* Hide elements not needed for print */
        .md\:col-span-1,
        /* Sidebar */
        .top-header,
        .fc-dayGridMonth-button,
        .fc-dayGridWeek-button,
        .fc-dayGridDay-button,
        .fc-next-button,
        .fc-prev-button,
        .fc-today-button,
        .header-content,
        .nav-container,
        footer,

        #bulkScheduleResult,
        /* Bulk schedule result area */
        .persian-datepicker-container,
        /* Hide datepicker UI */
        .print\:hidden

        /* Hide elements specifically marked */
            {
            display: none !important;
        }

        /* Make the main container and calendar container fill the page */
        .container {
            max-width: 100% !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 1mm !important;
            /* Minimal padding for A4 */
            border: none !important;
            box-shadow: none !important;
        }

        .md\:col-span-3 {
            width: 100% !important;
            grid-column: span 4 / span 4 !important;
            /* Override grid */
            padding: 0 !important;
            margin: 0 !important;
            min-height: auto !important;
            /* Remove min-height */
            box-shadow: none !important;
            border: none !important;
        }

        .bg-white.p-4.rounded-lg.shadow.min-h-\[600px\] {
            padding: 0 !important;
            box-shadow: none !important;
            border: none !important;
            min-height: auto !important;
        }

        /* Style the calendar itself */
        #calendar {
            width: auto !important;
            height: auto !important;
            /* Let content determine height */
            overflow: visible !important;
            /* No scrollbars */
        }

        /* Ensure week view is displayed if active */
        /* (We'll also ensure it's active via JS) */
        .fc-dayGridMonth-view {
            display: none !important;
        }

        .fc-dayGridWeek-view {
            display: block !important;
            width: 100% !important;
        }

        /* Adjustments for tighter layout in week view */
        .fc-daygrid-day-frame {
            min-height: 120px;
            /* Adjust based on expected content height */
            /* Consider adding border for clarity */
            border: 1px solid #eee;
            margin: -1px 0 0 -1px;
            width: 90%;
            /* Overlap borders */
        }

        .fc .fc-daygrid-day-top {
            flex-direction: row !important;
            /* Date number horizontal */
            padding: 2px;

        }

        .fc-daygrid-day-number {
            font-size: 9pt;
            padding: 1px 1px;
        }

        .fc-event {
            font-size: 8pt;
            /* Smaller font for events */
            padding: 1px 2px;
            margin-bottom: 2px;
            page-break-inside: avoid;
            /* Try to keep events on one page */
        }

        .fc-event-main-frame {
            font-size: 8pt;
            min-height: auto !important;
            padding: 1px 2px !important;
            width: 90% !important;
        }

        .fc-event-title,
        .fc-event-detail {
            font-size: 8pt;
            color: #000000 !important;
            line-height: 1.2;
            margin-bottom: 0 !important;
        }

        .fc-daygrid-event-harness {
            margin-bottom: 2px !important;
            background-color: #ffffff !important;
            width: 90%;
            /* Reduce spacing between events */
        }

        /* Hide 'more' links if they clutter */
        .fc-daygrid-more-link {
            /* display: none !important; */
            /* Uncomment if necessary */
            font-size: 8pt;
        }

        /* Ensure event colors print */
        .fc-event {
            background-color: var(--fc-event-bg-color, #3788d8) !important;
            border-color: var(--fc-event-border-color, #3788d8) !important;
            color: var(--fc-event-text-color, #fff) !important;
        }

        .fc-toolbar-title {
            font-size: 8pt;
            line-height: 1.2;
            margin-bottom: 0 !important;
        }

        body.print-with-colors .fc-event {
            background-color: var(--fc-event-bg-color, #3788d8) !important;
            border-color: var(--fc-event-border-color, #3788d8) !important;
            color: var(--fc-event-text-color, #fff) !important;
        }

        body.print-with-colors .fc-event-main-frame {
            color: var(--fc-event-text-color, #fff) !important;
            /* Ensure frame text matches */
        }


        /* Styles when printing WITHOUT colors (Black & White) */
        body:not(.print-with-colors) .fc-event {
            background-color: #ffffff !important;
            /* White background */
            border: 1px solid #000000 !important;
            /* Black border */
            color: #000000 !important;
            /* Black text */
        }

        /* Ensure text inside the event frame is black for B&W */
        body:not(.print-with-colors) .fc-event-main-frame {
            color: #000000 !important;
        }

        body:not(.print-with-colors) .fc-event-title,
        body:not(.print-with-colors) .fc-event-detail {
            color: #000000 !important;
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
        const calendarEl = document.getElementById('calendar');
        const externalEventsContainer = document.getElementById('external-events');
        const searchInput = document.getElementById('searchInput');
        const typeFilter = document.getElementById('typeFilter');
        const priorityFilter = document.getElementById('priorityFilter');
        const assignmentFilter = document.getElementById('assignmentFilter');
        const sidebarMessageArea = document.getElementById('sidebarMessageArea');
        const formworkFilter = document.getElementById('formworkFilter'); // Added

        // Panel Stats Elements
        const visibleCountEl = document.getElementById('visibleCountEl');
        const loadedPanelsCountEl = document.getElementById('loadedPanelsCountEl');
        const totalFilteredPanelsCountEl = document.getElementById('totalFilteredPanelsCountEl');
        const grandTotalPanelsCountEl = document.getElementById('grandTotalPanelsCountEl'); // Already has initial value from PHP

        const bulkScheduleResultEl = document.getElementById('bulkScheduleResult');
        const bulkScheduleBtn = document.getElementById('bulkScheduleBtn');
        const loadMoreBtn = document.getElementById('loadMorePanelsBtn');
        const loadMoreContainer = document.getElementById('loadMoreContainer');
        const md = typeof MobileDetect !== 'undefined' ? new MobileDetect(window.navigator.userAgent) : null;
        const isMobile = md ? md.mobile() !== null : window.innerWidth < 768;
        const togglePriorityColorBtn = document.getElementById('togglePriorityColorBtn');
        const priorityLegend = document.getElementById('priorityLegend'); // Get legend container
        const priorityLegendList = document.getElementById('priorityLegendList'); // Get legend list ul

        // --- State variable for coloring ---
        let priorityColoringEnabled = true;
        const defaultEventColor = '#3b82f6'; // Default blue
        let selectedLegendPriorities = new Set(); // Use a Set to store selected priorities like 'P1', 'P6'
        let selectedCalendarEventIds = new Set();
        const CALENDAR_EVENT_SELECTED_CLASS = 'calendar-event-selected';
        const BODY_MULTI_SELECTING_CLASS = 'calendar-multi-selecting';
        const shiftFromDateEl = document.getElementById('shiftFromDate');
        const shiftDaysEl = document.getElementById('shiftDays');
        const executeShiftBtn = document.getElementById('executeShiftBtn');
        const shiftResultEl = document.getElementById('shiftResult');
        const translationsJS = {
            zone: {
                'zone 1': 'زون ۱',
                'zone 2': 'زون ۲',
                'zone 3': 'زون ۳',
                'zone 4': 'زون ۴',
                'zone 5': 'زون ۵',
                'zone 6': 'زون ۶',
                'zone 7': 'زون ۷',
                'zone 8': 'زون ۸',
                'zone 9': 'زون ۹',
                'zone 10': 'زون ۱۰',
                'zone 11': 'زون ۱۱',
                'zone 12': 'زون ۱۲',
                'zone 13': 'زون ۱۳',
                'zone 14': 'زون ۱۴',
                'zone 15': 'زون ۱۵',
                'zone 16': 'زون ۱۶',
                'zone 17': 'زون ۱۷',
                'zone 18': 'زون ۱۸',
                'zone 19': 'زون ۱۹',
                'zone 20': 'زون ۲۰',
                'zone 21': 'زون ۲۱',
                'zone 22': 'زون ۲۲',
                'zone 23': 'زون ۲۳',
                'zone 24': 'زون ۲۴',
                'zone 25': 'زون ۲۵',
                'zone 26': 'زون ۲۶',
                'zone 27': 'زون ۲۷',
                'zone 28': 'زون ۲۸',
                'zone 29': 'زون ۲۹',
                'zone 30': 'زون ۳۰',
            },
            panel_position: { // Key should match what you use in displayResults for panel.type
                'terrace edge': 'لبه تراس',
                'wall panel': 'پنل دیواری',
            },
            status: { // Your existing status map can serve this purpose
                'pending': 'در انتظار تخصیص',
                'planned': 'برنامه ریزی شده',
                'mesh': 'مش بندی',
                'concreting': 'قالب‌بندی/بتن ریزی',
                'assembly': 'فیس کوت',
                'completed': 'تکمیل شده',
                'shipped': 'ارسال شده'
            },
            // NEW: Add translation for plan_checked status
            plan_checked: {
                '1': 'تایید شده',
                '0': 'تایید نشده',
                'null': '-'
            }
        };
        if (executeShiftBtn && shiftFromDateEl && shiftDaysEl && shiftResultEl) {
            // Initialize persianDatepicker for the new input if not already covered
            if (typeof $.fn.persianDatepicker === 'function' && $(shiftFromDateEl).data('datepicker') === undefined) {
                $(shiftFromDateEl).persianDatepicker({
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
            }


            executeShiftBtn.addEventListener('click', async function() {
                if (!USER_CAN_EDIT) return;

                const fromDateJalali = shiftFromDateEl.value;
                const shiftDays = parseInt(shiftDaysEl.value, 10);

                if (!fromDateJalali) {
                    alert("لطفا 'تاریخ شروع جابجایی' را انتخاب کنید.");
                    return;
                }
                if (isNaN(shiftDays)) {
                    alert("لطفا 'تعداد روزهای جابجایی' را به صورت عددی وارد کنید.");
                    return;
                }
                if (shiftDays === 0) {
                    alert("تعداد روزهای جابجایی نمی‌تواند صفر باشد.");
                    if (shiftResultEl) shiftResultEl.innerHTML = '<p>جابجایی صفر روز درخواست شد. عملیاتی انجام نشد.</p>';
                    return;
                }

                let fromDateGregorian;
                try {
                    let mFrom = moment(toWesternArabicNumerals(fromDateJalali), 'jYYYY/jMM/jDD');
                    if (!mFrom.isValid()) {
                        throw new Error("تاریخ شروع جابجایی معتبر نیست.");
                    }
                    fromDateGregorian = mFrom.format('YYYY-MM-DD');
                } catch (e) {
                    alert("خطای تاریخ: " + e.message);
                    return;
                }

                const direction = shiftDays > 0 ? "به جلو" : "به عقب";
                const absShiftDays = Math.abs(shiftDays);
                let confirmMessage = `آیا مطمئن هستید که می‌خواهید تمام پنل‌های زمان‌بندی شده (بدون پیشرفت) از تاریخ ${fromDateJalali} به بعد را ${absShiftDays} روز ${direction} ببرید؟\n(پنل‌ها به روز جمعه منتقل نخواهند شد.`;
                if (shiftDays < 0) {
                    confirmMessage += `\nفقط پنل‌های آینده جابجا شده و هیچ پنلی به تاریخی قبل از امروز منتقل نخواهد شد.)`;
                } else {
                    confirmMessage += `)`;
                }
                if (!confirm(`آیا مطمئن هستید که می‌خواهید تمام پنل‌های زمان‌بندی شده (بدون پیشرفت ثبت شده) از تاریخ ${fromDateJalali} به بعد را ${absShiftDays} روز ${direction} ببرید؟\n(پنل‌ها به روز جمعه منتقل نخواهند شد)`)) {
                    return;
                }

                showButtonLoading(this, 'در حال جابجایی...');
                shiftResultEl.innerHTML = '';
                shiftResultEl.className = 'mt-2 text-sm p-2 rounded border bg-gray-50 border-gray-200 min-h-[3em]';


                try {
                    const result = await makeAjaxCall({
                        action: 'shiftScheduledPanels',
                        fromDate: fromDateGregorian,
                        shiftDays: shiftDays
                    });

                    let msg = `<h3>نتایج جابجایی پنل‌ها:</h3>`;
                    if (result.success) {
                        msg += `<p class="text-green-600">${result.message || 'عملیات با موفقیت انجام شد.'}</p>`;
                        if (result.shifted && result.shifted.length > 0) {
                            msg += `<strong>با موفقیت جابجا شد (${result.shifted.length}):</strong><ul>`;
                            result.shifted.forEach(p => {
                                msg += `<li>${p.address || 'ID: '+p.panel_id} (از ${moment(p.old_date).format('jYYYY/jMM/jDD')} به ${moment(p.new_date).format('jYYYY/jMM/jDD')})</li>`;
                            });
                            msg += `</ul>`;
                        } else if (!result.failed || result.failed.length === 0) {
                            msg += `<p>هیچ پنلی برای جابجایی در محدوده تاریخ مشخص شده یافت نشد یا نیازی به جابجایی نداشت.</p>`;
                        }

                        if (result.failed && result.failed.length > 0) {
                            msg += `<strong class="text-red-600">ناموفق در جابجایی / جابجا نشده (${result.failed.length}):</strong><ul>`; // Clarify title
                            result.failed.forEach(p => msg += `<li>${p.full_address_identifier || 'ID: '+p.panel_id}: ${p.reason}</li>`);
                            msg += `</ul>`;
                        }
                    } else {
                        msg += `<p class="text-red-600">خطا در عملیات جابجایی: ${result.error || 'خطای ناشناخته از سرور.'}</p>`;
                    }
                    shiftResultEl.innerHTML = msg;
                    shiftResultEl.className = `mt-2 text-sm p-3 rounded border ${ (result.failed && result.failed.length > 0 && result.shifted && result.shifted.length === 0) ? 'border-red-400 bg-red-50' : ((result.failed && result.failed.length > 0) ? 'border-yellow-400 bg-yellow-50' : 'border-green-400 bg-green-50') } min-h-[3em]`;


                    calendar.refetchEvents(); // Refresh calendar to show new dates
                    fetchAndRenderPanels(1, false); // Refresh sidebar panel list as assignments might have changed

                } catch (error) {
                    console.error("Error in shiftScheduledPanels AJAX call:", error);
                    shiftResultEl.innerHTML = `<p class="text-red-600">خطای ارتباط با سرور هنگام جابجایی.</p>`;
                    shiftResultEl.className = 'mt-2 text-sm p-3 rounded border border-red-400 bg-red-50 min-h-[3em]';
                    alert("خطا در اجرای جابجایی.");
                } finally {
                    hideButtonLoading(this);
                }
            });
        }

        function translateJs(key, englishValue) {
            if (englishValue === null || englishValue === undefined || englishValue === '') return '-';
            // Use String() to handle numeric keys like 0 and 1
            const valueStr = String(englishValue);
            if (translationsJS[key] && translationsJS[key][valueStr.toLowerCase()]) { // Convert to lowercase for robust matching
                return translationsJS[key][valueStr.toLowerCase()];
            }
            return valueStr; // Fallback
        }

        function updateGlobalSelectionVisualState() {
            if (selectedCalendarEventIds.size > 0) {
                document.body.classList.add(BODY_MULTI_SELECTING_CLASS);
            } else {
                document.body.classList.remove(BODY_MULTI_SELECTING_CLASS);
            }
            // This function just manages the body class.
            // Individual event styling is now primarily handled by eventClassNames.
        }

        function clearCalendarSelection() {
            const previouslySelectedIds = new Set(selectedCalendarEventIds);
            selectedCalendarEventIds.clear();

            previouslySelectedIds.forEach(id => {
                const event = calendar.getEventById(id.toString()); // id from Set is string
                if (event) {
                    event.setExtendedProp('_selection_trigger_clear', Math.random()); // Nudge event
                }
            });
            updateGlobalSelectionVisualState(); // Update body class
            console.log("Calendar selection cleared.");
        }

        // --- Helper: Clear Calendar Selection ---
        function clearCalendarSelection() {
            // Deselect all visually (if their elements are available)
            selectedCalendarEventIds.forEach(eventId => {
                const eventObj = calendar.getEventById(eventId.toString());
                if (eventObj && eventObj.el) { // Check if .el exists
                    toggleEventElementSelection(eventObj.el, false);
                }
            });
            selectedCalendarEventIds.clear();
            updateGlobalSelectionVisualState(); // Update body class
            console.log("Calendar selection cleared.");

            // To ensure FullCalendar re-evaluates eventClassNames for previously selected items:
            // This is a bit of a "nudge".
            calendar.getEvents().forEach(event => {
                if (event.el && event.el.classList.contains(CALENDAR_EVENT_SELECTED_CLASS)) {
                    // If it still has the class after our direct removal attempt (e.g. element was not found),
                    // triggering a property change might help FullCalendar re-render it.
                    event.setExtendedProp('_clear_trigger', Math.random());
                }
            });
            // Alternatively, a full calendar.render() is the most forceful way, but use sparingly.
        }


        // --- Helper Function to Get Color Based on Priority ---
        // UPDATED: To handle "zone X" and up to 30 zones
        function getPriorityColor(priorityString) {
            if (!priorityString || typeof priorityString !== 'string' || !priorityString.toUpperCase().startsWith('ZONE ')) {
                return '#888888'; // Gray for invalid/missing priority
            }
            // Extract number from "zone X"
            const priorityNum = parseInt(priorityString.replace(/[^0-9]/g, ''), 10);
            if (isNaN(priorityNum) || priorityNum < 1) {
                return '#888888'; // Gray for invalid number
            }

            // Map zone 1-30 to Hue (0=Red, 280=Violet approx)
            const hueRange = 280;
            const maxPriority = 30; // UPDATED
            // Map higher priority (zone 1) to Red (0 hue), lower (zone 30) towards Violet
            const hue = Math.max(0, Math.min(hueRange, (priorityNum - 1) * (hueRange / (maxPriority - 1))));

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
            const priority = li.dataset.priority; // Get the priority string ('zone 1', 'zone 2', etc.)

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

            // UPDATED: Loop up to 30 zones
            const maxPrio = 30;
            for (let i = 1; i <= maxPrio; i++) {
                const priorityStr = `zone ${i}`;
                const priorityStrpr = `زون ${i}`;
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
                label.textContent = priorityStrpr;
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

        // --- Pagination & Filtering State ---
        let currentPage = 1;
        let isLoadingMorePanels = false;
        let currentFilters = {
            searchText: '',
            selectedType: '',
            selectedPriority: '',
            assignmentStatus: 'unassigned',
            selectedFormwork: ''
        };
        let lastSelectedPanelFromList = null; // For shift-click selection

        // --- Helper: Debounce ---
        function debounce(func, delay) {
            let timeout;
            return function(...args) {
                const context = this;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), delay);
            };
        }

        // --- Helper: Create Panel Element (from JS data) ---
        function createPanelElement(panel) {
            const assigned = panel.assigned_date ? 'true' : 'false';
            const baseFormworkType = panel.baseFormworkType; // Provided by PHP AJAX

            let panelClasses = 'panel-item p-2 rounded shadow-sm';
            if (assigned === 'true') {
                panelClasses += ' bg-gray-200 text-gray-500 cursor-not-allowed';
            } else if (!USER_CAN_EDIT) {
                panelClasses += ' bg-blue-100 text-blue-800 read-only-item';
            } else {
                panelClasses += ' bg-blue-100 text-blue-800 hover:bg-blue-200 cursor-grab';
            }

            const div = document.createElement('div');
            div.className = panelClasses;
            div.dataset.panelId = panel.id;
            div.dataset.panelType = panel.type || '';
            div.dataset.panelFloor = panel.floor || '';
            div.dataset.panelAssigned = assigned;
            div.dataset.panelWidth = panel.width || '';
            div.dataset.panelLength = panel.length || '';
            div.dataset.panelPriority = panel.Proritization || '';
            div.dataset.panelFormwork = baseFormworkType || '';

            const priorityDisplay = (panel.Proritization === null || panel.Proritization === '') ? 'N/A' : panel.Proritization;
            const addressDisplay = panel.full_address_identifier || 'N/A';
            const floorDisplay = panel.floor || 'N/A'; // UPDATED: use floor
            const areaDisplay = panel.area || 'N/A';
            const widthDisplay = panel.width || 'N/A';
            const lengthDisplay = panel.length || 'N/A';


            div.innerHTML = `
            <div class="font-semibold text-sm break-words">${addressDisplay}</div>
            <div class="text-xs mt-1 text-gray-600">
                قالب: <span class="font-medium">${baseFormworkType || 'N/A'}</span> |
                اولویت: <span class="font-medium">${translateJs('zone', priorityDisplay)}</span>
            </div>
            <div class="text-xs text-gray-500">طبقه: ${floorDisplay} | ${areaDisplay}m² | W:${widthDisplay} L:${lengthDisplay}</div>
        `;
            return div;
        }

        // --- Helper: Add Touch Listeners to Panel Items ---
        function handlePanelTouchStart() {
            if (USER_CAN_EDIT && this.classList.contains('cursor-grab')) this.classList.add('opacity-75', 'scale-105');
        }

        function handlePanelTouchEnd() {
            if (USER_CAN_EDIT && this.classList.contains('cursor-grab')) this.classList.remove('opacity-75', 'scale-105');
        }

        function addTouchListenersToPanelItems(panelElements) {
            if (!USER_CAN_EDIT) return;
            panelElements.forEach(p => {
                if (p.classList.contains('read-only-item') || p.dataset.panelAssigned === 'true') return;
                p.removeEventListener('touchstart', handlePanelTouchStart); // Prevent duplicates
                p.removeEventListener('touchend', handlePanelTouchEnd);
                p.addEventListener('touchstart', handlePanelTouchStart, {
                    passive: true
                });
                p.addEventListener('touchend', handlePanelTouchEnd, {
                    passive: true
                });
            });
        }


        // --- Core: Fetch and Render Panels ---
        async function fetchAndRenderPanels(page = 1, loadMore = false) {
            if (isLoadingMorePanels) return;
            isLoadingMorePanels = true;
            if (loadMoreBtn) loadMoreBtn.disabled = true;
            if (!loadMore) showGlobalLoading('در حال جستجو...');
            else showGlobalLoading('بارگذاری بیشتر...');


            currentFilters.searchText = searchInput.value.trim(); // No toLowerCase here, backend handles
            currentFilters.selectedType = typeFilter.value;
            currentFilters.selectedPriority = priorityFilter.value;
            currentFilters.assignmentStatus = assignmentFilter.value;
            currentFilters.selectedFormwork = formworkFilter.value;

            try {
                const data = await makeAjaxCall({
                    action: 'getMorePanels',
                    page: page,
                    panelsPerPage: PANELS_PER_PAGE,
                    ...currentFilters
                });

                if (data.success) {
                    if (!loadMore) {
                        externalEventsContainer.innerHTML = ''; // Clear for new search/filter
                        lastSelectedPanelFromList = null; // Reset shift-click selection
                    }
                    const newElements = [];
                    data.panels.forEach(panel => {
                        const el = createPanelElement(panel);
                        externalEventsContainer.appendChild(el);
                        newElements.push(el);
                    });
                    addTouchListenersToPanelItems(newElements);


                    currentPage = data.currentPage;
                    const currentlyLoadedInDOM = externalEventsContainer.children.length;
                    if (loadedPanelsCountEl) loadedPanelsCountEl.textContent = currentlyLoadedInDOM;
                    if (totalFilteredPanelsCountEl) totalFilteredPanelsCountEl.textContent = data.totalFiltered;

                    if (loadMoreContainer) {
                        loadMoreContainer.classList.toggle('hidden', !data.hasMore);
                    }

                    applyClientSideSearchHighlightingAndCount(); // Update visible count and highlight

                } else {
                    alert('خطا در بارگذاری پنل‌ها: ' + (data.error || 'خطای ناشناخته'));
                }
            } catch (error) {
                console.error("Error fetching panels:", error);
                // makeAjaxCall should have alerted
            } finally {
                isLoadingMorePanels = false;
                if (loadMoreBtn) loadMoreBtn.disabled = false;
                hideGlobalLoading();
            }
        }

        // --- Client-side Search Highlighting & Visible Count (after server filters) ---
        function applyClientSideSearchHighlightingAndCount() {
            const searchText = searchInput.value.toLowerCase().trim();
            const allPanelElements = externalEventsContainer.querySelectorAll('.panel-item');
            let currentVisibleCount = 0;

            allPanelElements.forEach(panel => {
                const addressNode = panel.querySelector('.font-semibold');
                const full_address_identifier = (addressNode?.textContent || '').toLowerCase();
                let showBasedOnSearch = true;

                if (searchText && !full_address_identifier.includes(searchText)) {
                    showBasedOnSearch = false;
                }
                panel.classList.toggle('hidden', !showBasedOnSearch);

                if (showBasedOnSearch) {
                    currentVisibleCount++;
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
            if (visibleCountEl) visibleCountEl.textContent = currentVisibleCount;
        }

        // --- Initialize Draggable for External Events (Panel List) ---
        let fcDraggableInstance = null;

        function initializeExternalEventDragging() {
            if (fcDraggableInstance) {
                console.log("Destroying previous Draggable instance"); // For debugging
                fcDraggableInstance.destroy();
                fcDraggableInstance = null;
            }
            if (externalEventsContainer && USER_CAN_EDIT) {
                fcDraggableInstance = new FullCalendar.Draggable(externalEventsContainer, {
                    // Critical: only target items that are NOT assigned and NOT read-only (if user can edit)
                    // And ensure they are not hidden by client-side search!
                    itemSelector: '.panel-item:not([data-panel-assigned="true"]):not(.read-only-item):not(.hidden)',
                    eventData: function(eventEl) {
                        const full_address_identifier = eventEl.querySelector('.font-semibold')?.innerText || 'پنل';
                        return {
                            title: full_address_identifier,
                            extendedProps: {
                                panelId: eventEl.getAttribute('data-panel-id'),
                                formworkType: eventEl.getAttribute('data-panel-formwork'),
                                full_address_identifier: full_address_identifier
                            },
                            create: false // *** IMPORTANT: Prevent FC Draggable from auto-creating event visuals ***
                        };
                    },
                });
                addTouchListenersToPanelItems(externalEventsContainer.querySelectorAll('.panel-item:not([data-panel-assigned="true"]):not(.read-only-item)'));
            } else if (externalEventsContainer) { // Read-only user
                externalEventsContainer.querySelectorAll('.panel-item:not([data-panel-assigned="true"])').forEach(p => {
                    p.style.cursor = 'default';
                });
            }
        }

        function updateSelectedCalendarEventVisuals() {
            let hasSelection = false;
            console.log("--- updateSelectedCalendarEventVisuals ---"); // Log when function is called
            calendar.getEvents().forEach(event => {
                const el = event.el;
                console.log("Event ID:", event.id, "event.el:", el); // What IS event.el?

                if (el) { // Make sure el is not null
                    if (selectedCalendarEventIds.has(event.id)) {
                        console.log("ADDING class to el for event ID:", event.id, el.classList);
                        el.classList.add(CALENDAR_EVENT_SELECTED_CLASS);
                        console.log("   Class list AFTER ADD:", el.classList);
                        hasSelection = true;
                    } else {
                        if (el.classList.contains(CALENDAR_EVENT_SELECTED_CLASS)) { // Only log if removing
                            console.log("REMOVING class from el for event ID:", event.id, el.classList);
                            el.classList.remove(CALENDAR_EVENT_SELECTED_CLASS);
                            console.log("   Class list AFTER REMOVE:", el.classList);
                        }
                    }
                } else {
                    console.warn("event.el is NULL for event ID:", event.id);
                }
            });

            if (hasSelection) {
                if (!document.body.classList.contains('calendar-multi-selecting')) {
                    console.log("Adding 'calendar-multi-selecting' to body");
                    document.body.classList.add('calendar-multi-selecting');
                }
            } else {
                if (document.body.classList.contains('calendar-multi-selecting')) {
                    console.log("Removing 'calendar-multi-selecting' from body");
                    document.body.classList.remove('calendar-multi-selecting');
                }
            }
            console.log("Body class list:", document.body.classList);
            console.log("--- end updateSelectedCalendarEventVisuals ---");
        }
        // --- Helper: Clear Calendar Selection ---
        function clearCalendarSelection() {
            selectedCalendarEventIds.clear();
            updateSelectedCalendarEventVisuals(); // This will now also remove the body class
            console.log("Calendar selection cleared.");
        }

        // --- FullCalendar Init ---
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: localStorage.getItem('fc_initialView') || (isMobile ? 'dayGridWeek' : 'dayGridMonth'),
            initialDate: localStorage.getItem('fc_initialDate') || null,
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
            editable: USER_CAN_EDIT,
            droppable: USER_CAN_EDIT,
            selectable: true,
            selectMirror: true,
            longPressDelay: isMobile ? 150 : 50,
            eventLongPressDelay: isMobile ? 150 : 50,
            height: 'auto', // Or '100%' or a fixed value if preferred
            datesSet: function(dateInfo) {
                // dateInfo.view.type gives the current view name (e.g., 'dayGridMonth')
                // dateInfo.startStr or a date from dateInfo.view.currentStart can be used
                // We'll use the view's active start date for consistency
                localStorage.setItem('fc_initialView', dateInfo.view.type);
                localStorage.setItem('fc_initialDate', moment(dateInfo.view.activeStart).format('YYYY-MM-DD'));
                console.log('Calendar state saved:', dateInfo.view.type, moment(dateInfo.view.activeStart).format('YYYY-MM-DD'));
            },
            // *** Conditional Editability ***
            editable: USER_CAN_EDIT, // Allow moving events only if user can edit
            droppable: USER_CAN_EDIT, // Allow dropping external panels only if user can edit
            selectable: true, // Allow selecting date ranges on background - useful for unselect
            selectMirror: true, // Visual cue for date range selection
            select: function(selectionInfo) {
                clearCalendarSelection();
                calendar.unselect();
            },
            dateClick: function(info) {
                clearCalendarSelection();
            },
            eventClassNames: function(arg) {
                if (selectedCalendarEventIds.has(arg.event.id.toString())) {
                    return [CALENDAR_EVENT_SELECTED_CLASS];
                }
                return [];
            },
            eventDidMount: function(info) {
                // eventClassNames should handle the selected class.
                // Use eventDidMount for things like tooltips or other specific DOM manipulations on info.el
                // if (info.el) { // Always check if info.el is present
                //     info.el.title = `...`; // Example: if you want tooltips
                // }
                // console.log("eventDidMount:", info.event.id, "el:", info.el);
            },
            eventWillUnmount: function(info) {
                // Optional: Clean up if needed, though usually not for simple class toggling
                // console.log("eventWillUnmount - ID:", info.event.id);
            },
            eventDrop: async function(info) {
                if (!USER_CAN_EDIT) {
                    info.revert();
                    return;
                }

                const droppedEvent = info.event;
                const newDate = moment(droppedEvent.start).format('YYYY-MM-DD');
                let eventsToMove = []; // Array of { id: panelId, formworkType: baseFormworkType, originalEvent: eventObj }
                let originalEventPositions = new Map();



                if (selectedCalendarEventIds.size > 0 && selectedCalendarEventIds.has(droppedEvent.id.toString())) {
                    selectedCalendarEventIds.forEach(eventId => {
                        const eventObj = calendar.getEventById(eventId); // eventId is already string from Set
                        if (eventObj) {
                            eventsToMove.push({
                                id: eventObj.extendedProps.panelId,
                                formworkType: eventObj.extendedProps.formworkType,
                                address: eventObj.title,
                                currentEventObject: eventObj
                            });
                            originalEventPositions.set(eventObj.extendedProps.panelId.toString(), {
                                start: eventObj.start
                            });
                        }
                    });
                } else {
                    // If a non-selected event or the only selected event is dragged, clear other selections
                    clearCalendarSelection(); // Clears the Set and nudges previously selected events
                    eventsToMove.push({
                        id: droppedEvent.extendedProps.panelId,
                        formworkType: droppedEvent.extendedProps.formworkType,
                        address: droppedEvent.title,
                        currentEventObject: droppedEvent
                    });
                    originalEventPositions.set(droppedEvent.extendedProps.panelId.toString(), {
                        start: info.oldEvent.start
                    });
                    // Visually select just the dragged event (if not already handled by clear + single add)
                    selectedCalendarEventIds.add(droppedEvent.id.toString());
                    droppedEvent.setExtendedProp('_selection_trigger_drag', Math.random()); // Nudge to apply class
                    updateGlobalSelectionVisualState(); // Update body class
                }



                if (eventsToMove.length === 0) {
                    alert("هیچ پنلی برای جابجایی مشخص نشد.");
                    info.revert();
                    return;
                }

                if (eventsToMove.length > 1) {
                    if (!confirm(`آیا از جابجایی ${eventsToMove.length} پنل انتخاب شده به تاریخ ${moment(newDate).format('jYYYY/jMM/jDD')} اطمینان دارید؟\n(هر پنل جداگانه برای پیشرفت و ظرفیت بررسی خواهد شد)`)) {
                        info.revert();
                        eventsToMove.forEach(eventData => { // Revert other (non-dragged) selected events
                            if (eventData.currentEventObject !== droppedEvent) {
                                const originalPos = originalEventPositions.get(eventData.id.toString());
                                if (eventData.currentEventObject && originalPos) {
                                    eventData.currentEventObject.setStart(originalPos.start, {
                                        maintainDuration: true
                                    });
                                }
                            }
                        });
                        // No need to clear selection here as the operation was cancelled, selection should remain.
                        return;
                    }
                }
                showGlobalLoading("در حال جابجایی پنل(ها)...");
                let allSucceeded = true;
                let results = [];
                let successCount = 0;

                // Store original positions before attempting moves



                for (const eventData of eventsToMove) {
                    const panelId = eventData.id;
                    const baseFormworkType = eventData.formworkType;

                    if (!baseFormworkType) {
                        results.push({
                            panelId: panelId,
                            full_address_identifier: eventData.full_address_identifier,
                            success: false,
                            error: 'نوع قالب نامشخص.'
                        });
                        allSucceeded = false;
                        continue;
                    }

                    try {
                        // 1. Check Availability for this specific panel
                        const availData = await makeAjaxCall({
                            action: 'checkFormworkAvailability',
                            panelId: panelId, // Pass panelId to exclude itself from count if it was already on newDate
                            date: newDate,
                            formworkType: baseFormworkType
                        });
                        if (!availData.available) {
                            results.push({
                                panelId: panelId,
                                full_address_identifier: eventData.full_address_identifier,
                                success: false,
                                error: availData.error || 'ظرفیت تکمیل.'
                            });
                            allSucceeded = false;
                            continue; // Skip this panel
                        }

                        // 2. Attempt Update (this handles progress check, panel DB update, order logic)
                        let updateResponse = await makeAjaxCall({
                            action: 'updatePanelDate', // This action already handles unassign from old + assign to new
                            panelId: panelId,
                            date: newDate
                        });

                        // 3. Handle Progress Warning
                        if (updateResponse.progress_exists) {
                            if (confirm(`پنل "${eventData.full_address_identifier}" دارای اطلاعات پیشرفت است. برای جابجایی، پیشرفت پاک خواهد شد. ادامه می‌دهید؟`)) {
                                updateResponse = await makeAjaxCall({
                                    action: 'updatePanelDate',
                                    panelId: panelId,
                                    date: newDate,
                                    forceUpdate: true
                                });
                            } else {
                                results.push({
                                    panelId: panelId,
                                    full_address_identifier: eventData.full_address_identifier,
                                    success: false,
                                    error: 'جابجایی به دلیل وجود پیشرفت لغو شد.'
                                });
                                allSucceeded = false;
                                continue; // Skip this panel, user cancelled
                            }
                        }

                        if (!updateResponse.success) {
                            results.push({
                                panelId: panelId,
                                full_address_identifier: eventData.full_address_identifier,
                                success: false,
                                error: updateResponse.error || 'خطا در به‌روزرسانی تاریخ.'
                            });
                            allSucceeded = false;
                        } else {
                            results.push({
                                panelId: panelId,
                                full_address_identifier: eventData.full_address_identifier,
                                success: true,
                                message: updateResponse.message
                            });
                            successCount++;
                            // FullCalendar might have already moved it visually if it's the primary `info.event`.
                            // For other selected events, we need to ensure they are moved on the calendar if success.
                            if (eventData.currentEventObject !== droppedEvent) {
                                // Calculate the date difference if the original drag was across multiple days
                                const dateDelta = moment(droppedEvent.start).diff(moment(info.oldEvent.start), 'days');
                                //const originalStartDate = moment(originalEventPositions.get(eventData.id.toString())?.start);
                                const originalStartDateMoment = moment(originalEventPositions.get(eventData.id.toString())?.start);
                                if (originalStartDateMoment.isValid()) {
                                    const newCalculatedStartDate = originalStartDateMoment.clone().add(dateDelta, 'days');
                                    eventData.currentEventObject.setStart(newCalculatedStartDate.toDate(), {
                                        maintainDuration: true
                                    });
                                    // If you have end dates: eventData.currentEventObject.setEnd(...);
                                } else { // Fallback if original position not found, just move to newDate
                                    eventData.currentEventObject.setStart(newDate, {
                                        maintainDuration: true
                                    });
                                }
                            }
                        }
                    } catch (error) {
                        console.error(`Error processing panel ${panelId} during multi-drop:`, error);
                        results.push({
                            panelId: panelId,
                            full_address_identifier: eventData.full_address_identifier,
                            success: false,
                            error: 'خطای سرور هنگام جابجایی.'
                        });
                        allSucceeded = false;
                    }
                } // End loop through eventsToMove

                hideGlobalLoading();

                // --- Display Results ---
                let summaryMessage = "نتایج جابجایی گروهی:\n";

                results.forEach(res => {
                    summaryMessage += `${res.success ? '✅' : '❌'} ${res.address}: ${res.success ? (res.message || 'موفق') : res.error}\n`;
                    if (!res.success) {
                        const eventToRevertData = eventsToMove.find(e => e.id === res.panelId);
                        const originalPos = originalEventPositions.get(res.panelId.toString());
                        if (eventToRevertData && eventToRevertData.currentEventObject && originalPos) {
                            eventToRevertData.currentEventObject.setStart(originalPos.start, {
                                maintainDuration: true
                            });
                            console.log(`Reverted event ${eventToRevertData.currentEventObject.id} due to failure.`);
                        } else if (eventToRevertData && eventToRevertData.currentEventObject === droppedEvent) {
                            info.revert(); // Revert the primary dragged one if it failed
                        }
                    }
                });
                alert(summaryMessage);


                if (successCount > 0 && successCount < eventsToMove.length) { // Partial success
                    console.log("Partial success in eventDrop, refetching events for consistency.");
                    calendar.refetchEvents();
                }
                // If all success, client-side moves are done. If all fail, client-side reverts are done.
                // A refetch might still be good after any server interaction to ensure perfect sync,
                // but can be omitted if client-side updates are reliable.
                // For now, only refetch on partial.

                clearCalendarSelection(); // Clear selection state after operation
            }, // End eventDrop

            // --- Clicking to remove an assignment ---
            eventClick: async function(info) {
                if (!USER_CAN_EDIT) return;
                info.jsEvent.preventDefault();

                const clickedEvent = info.event;
                const clickedEventIdStr = clickedEvent.id.toString();

                if (info.jsEvent.ctrlKey || info.jsEvent.metaKey) { // Toggle selection
                    if (selectedCalendarEventIds.has(clickedEventIdStr)) {
                        selectedCalendarEventIds.delete(clickedEventIdStr);
                    } else {
                        selectedCalendarEventIds.add(clickedEventIdStr);
                    }
                    clickedEvent.setExtendedProp('_selection_trigger_toggle', Math.random()); // Nudge
                } else { // Normal click
                    const isCurrentlyOnlySelected = selectedCalendarEventIds.has(clickedEventIdStr) && selectedCalendarEventIds.size === 1;

                    if (isCurrentlyOnlySelected) { // Attempt to unassign
                        const panelId = clickedEvent.extendedProps.panelId;
                        const dateStr = moment(clickedEvent.start).isValid() ? moment(clickedEvent.start).format('jYYYY/jMM/jDD') : '?';
                        const title = clickedEvent.title || 'پنل';
                        if (confirm(`لغو تخصیص پنل "${title}" در ${dateStr}؟\n(این کار سفارش(های) یونولیت مربوطه را نیز لغو می‌کند)`)) {
                            showGlobalLoading("در حال لغو تخصیص...");
                            try {
                                let response = await makeAjaxCall({
                                    action: 'updatePanelDate',
                                    panelId: panelId,
                                    date: ''
                                });
                                if (response.progress_exists) {
                                    if (confirm("این پنل دارای اطلاعات پیشرفت است. برای لغو تخصیص، اطلاعات پیشرفت پاک خواهد شد. ادامه می‌دهید؟")) {
                                        response = await makeAjaxCall({
                                            action: 'updatePanelDate',
                                            panelId: panelId,
                                            date: '',
                                            forceUpdate: true
                                        });
                                    } else {
                                        hideGlobalLoading(); /* User cancelled, selection state unchanged */
                                        return;
                                    }
                                }
                                if (response.success) {
                                    selectedCalendarEventIds.delete(clickedEventIdStr); // Remove from selection
                                    clickedEvent.remove(); // Remove from calendar
                                    const pEl = document.querySelector(`.panel-item[data-panel-id="${panelId}"]`);
                                    if (pEl) {
                                        /* Update sidebar item */
                                        pEl.dataset.panelAssigned = 'false';
                                        pEl.classList.remove('bg-gray-200', 'text-gray-500', 'cursor-not-allowed', 'panel-selected');
                                        pEl.classList.add('bg-blue-100', 'text-blue-800', 'hover:bg-blue-200', 'cursor-grab');
                                    }
                                    applyClientSideSearchHighlightingAndCount();
                                } else {
                                    alert('خطا در لغو تخصیص: ' + (response.error || 'Unknown error.'));
                                }
                            } catch (error) {
                                /* Handle error */
                                alert('خطای اساسی در لغو تخصیص.');
                            } finally {
                                hideGlobalLoading();
                            }
                        }
                    } else { // Select this event, deselect others
                        const previouslySelectedIds = new Set(selectedCalendarEventIds);
                        selectedCalendarEventIds.clear();
                        selectedCalendarEventIds.add(clickedEventIdStr);

                        previouslySelectedIds.forEach(id => { // Nudge previously selected
                            if (id !== clickedEventIdStr) {
                                const event = calendar.getEventById(id);
                                if (event) event.setExtendedProp('_selection_trigger_deselect_other', Math.random());
                            }
                        });
                        clickedEvent.setExtendedProp('_selection_trigger_select_this', Math.random()); // Nudge current
                    }
                }
                updateGlobalSelectionVisualState(); // Update body class
            },
            // --- Dropping an external panel ---
            drop: async function(info) {
                if (!info.date || !USER_CAN_EDIT) return;

                clearCalendarSelection(); // Clear any calendar multi-selection

                const draggedEl = info.draggedEl; // The single element physically dragged
                const targetDate = moment(info.date).format('YYYY-MM-DD');
                let panelsToProcessClientInfo = []; // Array of {id, formworkType, full_address_identifier, element}

                if (draggedEl.dataset.panelAssigned === 'true' || draggedEl.classList.contains('read-only-item')) {
                    console.warn("Attempted to drop an invalid panel.", draggedEl);
                    return;
                }

                const selectedPanelElements = Array.from(
                    externalEventsContainer.querySelectorAll('.panel-item.panel-selected:not([data-panel-assigned="true"]):not(.read-only-item):not(.hidden)')
                );

                if (selectedPanelElements.length > 0 && selectedPanelElements.includes(draggedEl)) {
                    panelsToProcessClientInfo = selectedPanelElements.map(el => ({
                        id: el.dataset.panelId,
                        formworkType: el.dataset.panelFormwork,
                        full_address_identifier: el.querySelector('.font-semibold')?.innerText || 'پنل',
                        element: el
                    }));
                } else {
                    panelsToProcessClientInfo.push({
                        id: draggedEl.dataset.panelId,
                        formworkType: draggedEl.dataset.panelFormwork,
                        full_address_identifier: draggedEl.querySelector('.font-semibold')?.innerText || 'پنل',
                        element: draggedEl
                    });
                }

                panelsToProcessClientInfo = panelsToProcessClientInfo.filter(p => p.id && p.formworkType);

                if (panelsToProcessClientInfo.length === 0) {
                    alert('هیچ پنل معتبری برای تخصیص انتخاب نشده است.');
                    return;
                }

                if (panelsToProcessClientInfo.length > 1) {
                    if (!confirm(`آیا از تخصیص ${panelsToProcessClientInfo.length} پنل به تاریخ ${moment(targetDate).format('jYYYY/jMM/jDD')} اطمینان دارید؟`)) return;
                }
                showGlobalLoading(`در حال تخصیص ${panelsToProcessClientInfo.length} پنل...`);
                bulkScheduleResultEl.innerHTML = ''; // Clear previous results
                bulkScheduleResultEl.className = 'mt-2 text-sm p-2 rounded border bg-gray-50 border-gray-200 min-h-[3em]';

                let resultsSummary = [];
                let overallSuccessCount = 0;

                for (const panelInfo of panelsToProcessClientInfo) {
                    const panelId = panelInfo.id;
                    const baseFormworkType = panelInfo.formworkType;
                    const panelfull_address_identifier = panelInfo.full_address_identifier;
                    const panelElement = panelInfo.element;

                    try {
                        // 1. Client-side check for formwork type (already done when creating panelsToProcessClientInfo)
                        if (!baseFormworkType) {
                            resultsSummary.push({
                                full_address_identifier: panelfull_address_identifier,
                                success: false,
                                reason: 'نوع قالب نامشخص.'
                            });
                            panelElement.classList.remove('panel-selected'); // Deselect if failed
                            continue;
                        }

                        // 2. AJAX Call to checkFormworkAvailability (as in original single drop)
                        const availData = await makeAjaxCall({
                            action: 'checkFormworkAvailability',
                            panelId: null, // For new assignment, panelId is not relevant for *this* check here
                            date: targetDate,
                            formworkType: baseFormworkType
                        });

                        if (!availData.available) {
                            resultsSummary.push({
                                full_address_identifier: panelfull_address_identifier,
                                success: false,
                                reason: availData.error || 'ظرفیت تکمیل.'
                            });
                            panelElement.classList.remove('panel-selected'); // Deselect if failed
                            continue;
                        }

                        // 3. AJAX Call to updatePanelDate (this handles progress, panel update, orders)
                        let updateData = await makeAjaxCall({
                            action: 'updatePanelDate',
                            panelId: panelId,
                            date: targetDate
                        });

                        // 4. Handle Progress Warning (if `updatePanelDate` returns progress_exists)
                        if (updateData.progress_exists) {
                            if (confirm(`پنل "${panelfull_address_identifier}" دارای اطلاعات پیشرفت است. برای تخصیص، اطلاعات پیشرفت پاک خواهد شد. ادامه می‌دهید؟`)) {
                                updateData = await makeAjaxCall({
                                    action: 'updatePanelDate',
                                    panelId: panelId,
                                    date: targetDate,
                                    forceUpdate: true
                                });
                            } else {
                                resultsSummary.push({
                                    full_address_identifier: panelfull_address_identifier,
                                    success: false,
                                    reason: 'تخصیص به دلیل وجود پیشرفت لغو شد.'
                                });
                                panelElement.classList.remove('panel-selected'); // Deselect if failed
                                continue; // User cancelled
                            }
                        }

                        // 5. Check final update result from updatePanelDate
                        if (!updateData.success) {
                            resultsSummary.push({
                                full_address_identifier: panelfull_address_identifier,
                                success: false,
                                reason: updateData.error || 'خطا در ذخیره تاریخ.'
                            });
                            panelElement.classList.remove('panel-selected'); // Deselect if failed
                        } else {
                            // SUCCESS for this panel
                            overallSuccessCount++;
                            resultsSummary.push({
                                full_address_identifier: panelfull_address_identifier,
                                success: true,
                                message: updateData.message
                            });

                            // Update UI for this specific panelElement
                            panelElement.dataset.panelAssigned = 'true';
                            panelElement.classList.remove('panel-selected', 'bg-blue-100', 'text-blue-800', 'hover:bg-blue-200', 'cursor-grab');
                            panelElement.classList.add('bg-gray-200', 'text-gray-500', 'cursor-not-allowed');
                            // No need to remove 'panel-item' class

                            // Add event to calendar (if not already added by some other mechanism - updatePanelDate doesn't add to calendar)
                            // We need to manually add it here since updatePanelDate is a generic backend.
                            const calendarEvent = calendar.getEventById(panelId.toString()); // Check if event already exists by chance
                            if (!calendarEvent) {
                                calendar.addEvent({
                                    id: panelId.toString(), // Ensure event has an ID
                                    title: panelfull_address_identifier,
                                    start: targetDate,
                                    extendedProps: {
                                        panelId: panelId,
                                        full_address_identifier: panelfull_address_identifier,
                                        formworkType: baseFormworkType,
                                        // You might want to fetch other props if needed, or rely on refetchEvents later
                                    },
                                    // backgroundColor: '#22c55e', // Example: Green for newly added
                                    // borderColor: '#16a34a'
                                });
                            } else { // If event somehow exists, update its date (shouldn't happen for new drop)
                                calendarEvent.setStart(targetDate);
                            }
                        }
                    } catch (error) {
                        resultsSummary.push({
                            address: full_address_identifier,
                            success: false,
                            reason: 'خطای کلاینت: ' + error.message
                        });
                        panelElement.classList.remove('panel-selected');
                    }
                }


                hideGlobalLoading();

                // Display summary message (same as before)
                // ...
                let alertMessage = `نتایج تخصیص ${panelsToProcessClientInfo.length} پنل:\n\n`;
                let detailedSidebarMessageHtml = `<div class="font-semibold mb-2">نتایج تخصیص ${panelsToProcessClientInfo.length} پنل به تاریخ ${moment(targetDate).format('jYYYY/jMM/jDD')}:</div>`;
                let failedCount = 0;
                resultsSummary.forEach(res => {
                    const icon = res.success ? '✅' : '❌';
                    const messagePart = res.success ? (res.message || 'موفق') : res.reason;
                    alertMessage += `${icon} ${res.address}: ${messagePart}\n`;

                    if (res.success) {
                        detailedSidebarMessageHtml += `<div class="text-green-700">${icon} ${res.address}: ${messagePart}</div>`;
                    } else {
                        failedCount++;
                        detailedSidebarMessageHtml += `<div class="text-red-700">${icon} ${res.address}: ${messagePart}</div>`;
                    }
                });
                alert(alertMessage); // *** PRIMARY FEEDBACK VIA ALERT ***

                // --- Optional: Update the persistent sidebar message area ---
                if (sidebarMessageArea) { // Check if the element exists
                    sidebarMessageArea.innerHTML = detailedSidebarMessageHtml;
                    let cls = 'border-gray-300 bg-gray-100';
                    if (overallSuccessCount > 0 && failedCount > 0) cls = 'border-yellow-400 bg-yellow-50';
                    else if (overallSuccessCount > 0 && failedCount === 0) cls = 'border-green-400 bg-green-50';
                    else if (failedCount > 0 && overallSuccessCount === 0) cls = 'border-red-400 bg-red-50';
                    sidebarMessageArea.className = `mb-3 text-sm p-3 rounded border ${cls}`; // Make it visible
                    sidebarMessageArea.classList.remove('hidden');

                    // Optional: Auto-hide sidebar message after a delay
                    setTimeout(() => {
                        sidebarMessageArea.classList.add('hidden');
                        sidebarMessageArea.innerHTML = '';
                    }, 10000); // Hide after 10 seconds
                }


                // NO calendar.refetchEvents(); here. Client-side additions are done.
                // The server state is the source of truth for next page load or explicit refetch.

                applyClientSideSearchHighlightingAndCount();
                initializeExternalEventDragging(); // Re-initialize for sidebar
            }, // End drop
            // --- Fetch Initial Events ---
            events: function(fetchInfo, successCallback, failureCallback) {
                makeAjaxCall({
                        action: 'getAssignedPanels'
                    })
                    .then(response => {
                        if (Array.isArray(response)) {
                            const eventsWithFcIds = response.map(event => ({
                                ...event,
                                id: event.extendedProps.panelId.toString()
                            }));
                            successCallback(eventsWithFcIds);

                        } else {
                            console.error("Invalid event data format:", response);
                            failureCallback(new Error("فرمت نامعتبر رویداد"));
                        }
                    })
                    .catch(error => {
                        console.error("Failed to fetch events:", error);
                        failureCallback(error);
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

                const panelZoneDisplay = translateJs('zone', priority);
                const formworkType = props.formworkType || 'N/A';
                const floor = props.floor || 'N/A'; // ADDED
                const width = props.width ? 'W: ' + parseInt(props.width) + ' mm' : 'N/A';
                const full_address_identifier = arg.event.title;
                let bgColor = defaultEventColor;
                if (priorityColoringEnabled) {
                    bgColor = getPriorityColor(priority);
                }
                const finalBgColor = arg.event.backgroundColor || bgColor;

                let divEl = document.createElement('div');
                // Add a data-event-id attribute for easier debugging if needed
                divEl.setAttribute('data-event-id', arg.event.id);
                divEl.className = 'fc-event-main-frame p-1 text-xs w-full overflow-hidden calendar-event-item ' +
                    (!USER_CAN_EDIT ? 'read-only-event' : ''); // Apply class during render
                divEl.style.cssText = `
                background-color:${finalBgColor};
                color:#fff;
                border-radius:3px;
                height:auto;
                min-height:60px;
                display:flex;
                flex-direction:column;
                ${USER_CAN_EDIT ? 'cursor:pointer;' : 'cursor:default;'}
            `;
                // UPDATED: Added floor to tooltip
                divEl.title = `آدرس: ${full_address_identifier}\nطبقه: ${floor}\nاولویت: ${panelZoneDisplay}\nقالب: ${formworkType}\nعرض پنل: ${width}`;

                const full_address_identifierDiv = document.createElement('div');
                full_address_identifierDiv.className = 'fc-event-title font-semibold mb-1';
                full_address_identifierDiv.textContent = full_address_identifier;

                // ADDED: Floor element
                const floorDiv = document.createElement('div');
                floorDiv.className = 'fc-event-detail mb-1';
                floorDiv.textContent = `طبقه: ${floor}`;

                const formworkDiv = document.createElement('div');
                formworkDiv.className = 'fc-event-detail mb-1';
                formworkDiv.textContent = formworkType;

                const widthDiv = document.createElement('div');
                widthDiv.className = 'fc-event-detail';
                widthDiv.textContent = width;

                divEl.appendChild(full_address_identifierDiv);
                divEl.appendChild(floorDiv); // ADDED
                divEl.appendChild(formworkDiv);
                divEl.appendChild(widthDiv);

                return {
                    domNodes: [divEl]
                };
            },

        }); // End FullCalendar Init

        calendar.render();

        function handleFilterChange() {
            currentPage = 1; // Reset to first page for new filter criteria
            fetchAndRenderPanels(1, false); // false = not "loading more", it's a new query
        }

        if (searchInput) searchInput.addEventListener('input', debounce(applyClientSideSearchHighlightingAndCount, 300)); // Client-side search only highlights
        // Server-side search on filter change:
        if (searchInput) searchInput.addEventListener('change', handleFilterChange); // When user finishes typing (blur/enter)

        if (typeFilter) typeFilter.addEventListener('change', handleFilterChange);
        if (priorityFilter) priorityFilter.addEventListener('change', handleFilterChange);
        if (assignmentFilter) assignmentFilter.addEventListener('change', handleFilterChange);
        if (formworkFilter) formworkFilter.addEventListener('change', handleFilterChange);

        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', () => {
                fetchAndRenderPanels(currentPage + 1, true);
            });
        }

        // --- Multi-select in Panel List ---
        if (externalEventsContainer && USER_CAN_EDIT) {
            externalEventsContainer.addEventListener('click', function(e) {
                console.log("Panel list click detected. Target:", e.target, "Ctrl:", e.ctrlKey, "Shift:", e.shiftKey); // DEBUG

                let panelItem = e.target;
                if (!panelItem.classList.contains('panel-item')) {
                    panelItem = panelItem.closest('.panel-item');
                }
                console.log("Resolved panelItem:", panelItem); // DEBUG

                if (!panelItem || panelItem.dataset.panelAssigned === 'true' || panelItem.classList.contains('read-only-item') || panelItem.classList.contains('hidden')) {
                    console.log("Panel item is invalid for selection or hidden."); // DEBUG
                    return;
                }


                if (!e.ctrlKey && !e.metaKey && !e.shiftKey) { // Normal click
                    const wasSelected = panelItem.classList.contains('panel-selected');
                    externalEventsContainer.querySelectorAll('.panel-item.panel-selected').forEach(p => {
                        p.classList.remove('panel-selected');
                    });
                    if (!wasSelected) panelItem.classList.add('panel-selected'); // Select if it wasn't the one causing deselection
                    lastSelectedPanelFromList = panelItem.classList.contains('panel-selected') ? panelItem : null;
                } else if (e.ctrlKey || e.metaKey) { // Ctrl/Cmd click
                    panelItem.classList.toggle('panel-selected');
                    if (panelItem.classList.contains('panel-selected')) {
                        lastSelectedPanelFromList = panelItem;
                    } else if (lastSelectedPanelFromList === panelItem) {
                        // If deselected the last one, find another selected one to be 'lastSelected' or null
                        const currentSelected = externalEventsContainer.querySelector('.panel-item.panel-selected');
                        lastSelectedPanelFromList = currentSelected;
                    }
                } else if (e.shiftKey && lastSelectedPanelFromList && lastSelectedPanelFromList !== panelItem) { // Shift click
                    const allVisibleItems = Array.from(externalEventsContainer.querySelectorAll('.panel-item:not([data-panel-assigned="true"]):not(.read-only-item):not(.hidden)'));
                    const currentIndex = allVisibleItems.indexOf(panelItem);
                    const lastIndex = allVisibleItems.indexOf(lastSelectedPanelFromList);

                    if (currentIndex !== -1 && lastIndex !== -1) {
                        const start = Math.min(currentIndex, lastIndex);
                        const end = Math.max(currentIndex, lastIndex);

                        // Don't deselect others on shift-click, just add to selection
                        // externalEventsContainer.querySelectorAll('.panel-item.panel-selected').forEach(p => p.classList.remove('panel-selected'));

                        for (let i = start; i <= end; i++) {
                            if (allVisibleItems[i]) {
                                allVisibleItems[i].classList.add('panel-selected');
                            }
                        }
                    }
                    // panelItem becomes the new lastSelected for further shift clicks from this point
                    lastSelectedPanelFromList = panelItem;
                }
                // If simple click on an already selected item, it becomes the last selected for shift
                if (panelItem.classList.contains('panel-selected') && !e.shiftKey) {
                    lastSelectedPanelFromList = panelItem;
                }
                const selectedNow = Array.from(externalEventsContainer.querySelectorAll('.panel-item.panel-selected'));
                console.log("Currently selected panels:", selectedNow.length, selectedNow.map(p => p.dataset.panelId)); // DEBUG
            });
        }

        function toWesternArabicNumerals(str) {
            if (typeof str !== 'string') return str;
            const persianNumbers = [/۰/g, /۱/g, /۲/g, /۳/g, /۴/g, /۵/g, /۶/g, /۷/g, /۸/g, /۹/g];
            const arabicNumbers = [/٠/g, /١/g, /٢/g, /٣/g, /٤/g, /٥/g, /٦/g, /٧/g, /٨/g, /٩/g]; // Also handle standard Arabic if needed
            for (let i = 0; i < 10; i++) {
                str = str.replace(persianNumbers[i], i).replace(arabicNumbers[i], i);
            }
            return str;
        }
        // --- Bulk Scheduling (Updated Confirmation and Result Message Handling) ---
        bulkScheduleBtn.addEventListener('click', async function() {
            if (!USER_CAN_EDIT) return;
            showButtonLoading(this);
            bulkScheduleResultEl.textContent = '';
            bulkScheduleResultEl.className = 'mt-2 text-sm p-2 rounded border bg-gray-50 border-gray-200'; // Reset class

            let startDateInputVal = document.getElementById('bulkStartDate').value;
            let endDateInputVal = document.getElementById('bulkEndDate').value;

            console.log("Raw Bulk Start Date (Original):", startDateInputVal);
            console.log("Raw Bulk End Date (Original):", endDateInputVal);

            // Convert to Western Arabic numerals
            startDateInputVal = toWesternArabicNumerals(startDateInputVal);
            endDateInputVal = toWesternArabicNumerals(endDateInputVal);

            console.log("Raw Bulk Start Date (Converted):", startDateInputVal); // Should be e.g., "1404/02/24"
            console.log("Raw Bulk End Date (Converted):", endDateInputVal); // Should be e.g., "1404/02/26"

            if (!startDateInputVal || !endDateInputVal) {
                alert('لطفاً تاریخ شروع و پایان را انتخاب کنید.');
                hideButtonLoading(this);
                return;
            }

            let mStartDate, mEndDate, startDate, endDate;

            try {
                // Parse using moment() with the explicit Jalali format
                // moment-jalaali extends the global moment object
                mStartDate = moment(startDateInputVal, 'jYYYY/jMM/jDD');
                mEndDate = moment(endDateInputVal, 'jYYYY/jMM/jDD');

                console.log("Moment object for Start Date (after parsing):", mStartDate); // DEBUG
                console.log("Moment object for End Date (after parsing):", mEndDate); // DEBUG

                if (!mStartDate.isValid() || !mEndDate.isValid()) {
                    throw new Error("یکی از تاریخ‌های وارد شده معتبر نیست. لطفاً از انتخابگر تاریخ استفاده کنید یا فرمت صحیح (مثال: ۱۴۰۳/۰۱/۱۵) را وارد نمایید.");
                }
                if (mStartDate.isAfter(mEndDate)) {
                    throw new Error("تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.");
                }

                // Convert to Gregorian YYYY-MM-DD for the backend
                startDate = mStartDate.format('YYYY-MM-DD');
                endDate = mEndDate.format('YYYY-MM-DD');

                console.log("Parsed Gregorian Start for AJAX:", startDate);
                console.log("Parsed Gregorian End for AJAX:", endDate);

            } catch (e) {
                alert('خطای تاریخ: ' + e.message);
                console.error("Date parsing/validation error in bulk schedule:", e, "Input Start:", startDateInputVal, "Input End:", endDateInputVal);
                hideButtonLoading(this);
                return;
            }

            // --- Collect panels to schedule ---
            const panelsToSchedulePayload = Array.from(externalEventsContainer.querySelectorAll('.panel-item:not(.hidden):not([data-panel-assigned="true"]):not(.read-only-item)'))
                .map(p => ({
                    id: p.dataset.panelId
                }))
                .filter(p => p.id);

            if (panelsToSchedulePayload.length === 0) { // Check the correct variable
                alert('هیچ پنل واجد شرایطی (نمایش داده شده و تخصیص نیافته) برای زمانبندی خودکار یافت نشد.');
                hideButtonLoading(this);
                return;
            }

            if (!confirm(`زمان‌بندی ${panelsToSchedulePayload.length} پنل نمایش داده شده؟\n(برای هر پنل موفق، سفارشات 'شرق' و 'غرب' ایجاد/تایید خواهد شد)`)) {
                hideButtonLoading(this);
                return;
            }

            try {
                const result = await makeAjaxCall({
                    action: 'scheduleMultiplePanels',
                    panels: JSON.stringify(panelsToSchedulePayload),
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
                            pEl.className = pEl.className.replace(/bg-blue-\d+00|text-blue-\d+00|hover:bg-blue-\d+00|cursor-grab|read-only-item|panel-selected/g, '').trim() + ' bg-gray-200 text-gray-500 cursor-not-allowed';
                        }
                        // Count orders created for this panel
                        if (result.createdOrders && result.createdOrders[a.panel_id]) {
                            if (result.createdOrders[a.panel_id].east) totalOrdersCreated++;
                            if (result.createdOrders[a.panel_id].west) totalOrdersCreated++;
                        }
                    });

                    // Build result message
                    let msg = `<span class="font-semibold text-green-700">${scheduledCount} پنل زمانبندی شد (${totalOrdersCreated} سفارش شرق/غرب ایجاد/تایید شد).</span><br>`;
                    let cls = 'border-green-300 bg-green-50 text-green-700';
                    let unscheduledCount = result.unscheduled?.length || 0;

                    if (unscheduledCount > 0) {
                        msg += `<span class="font-semibold text-red-700">${unscheduledCount} ناموفق:</span><ul class="list-disc list-inside mt-1 text-xs text-gray-700">`; // Ensure text color for readability
                        result.unscheduled.forEach(i => {
                            const pid = i.panel_id || '?';
                            const r = i.reason || '?';
                            const pEl = document.querySelector(`.panel-item[data-panel-id="${pid}"]`);
                            const adr = pEl ? (pEl.querySelector('.font-semibold')?.textContent || `ID: ${pid}`) : `ID: ${pid}`;
                            msg += `<li>${adr}: ${r}</li>`;
                        });
                        msg += `</ul>`;
                        cls = (scheduledCount > 0) ? 'border-yellow-300 bg-yellow-50 text-yellow-700' : 'border-red-300 bg-red-50 text-red-700';
                    }
                    bulkScheduleResultEl.innerHTML = msg;
                    bulkScheduleResultEl.className = `mt-2 text-sm p-2 rounded border ${cls}`;
                    applyClientSideSearchHighlightingAndCount(); // Update list visibility and counts

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
                    //     document.body.classList.remove('print-with-colors');
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
        function initializePage() {
            const initialPanelElements = externalEventsContainer.querySelectorAll('.panel-item');
            if (visibleCountEl) visibleCountEl.textContent = initialPanelElements.length; // Initial visible = initial loaded
            if (loadedPanelsCountEl) loadedPanelsCountEl.textContent = initialPanelElements.length;
            if (totalFilteredPanelsCountEl) totalFilteredPanelsCountEl.textContent = externalEventsContainer.dataset.grandTotalPanels || '0'; // Before any filter, total filtered = grand total
            if (assignmentFilter) {
                assignmentFilter.value = 'unassigned'; // Set dropdown to default
            }
            fetchAndRenderPanels(1, false); // Initial fetch 

            initializeExternalEventDragging(); // Init draggable for initial items
            addTouchListenersToPanelItems(initialPanelElements); // Add touch for initial
            populatePriorityLegend();
            setInitialButtonAndLegendState();
            // applyClientSideSearchHighlightingAndCount(); // Already covered by visibleCountEl update above for initial load


        }
        initializePage();

    }); // End DOMContentLoaded
</script>