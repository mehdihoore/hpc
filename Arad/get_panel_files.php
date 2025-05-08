<?php
require_once __DIR__ . '/../../sercon/config_fereshteh.php';

// Basic security
if (!isset($_GET['panel_id']) || !is_numeric($_GET['panel_id'])) { /* ... */ }
$panelId = intval($_GET['panel_id']);

// --- ** NEW: Filter by step_name if provided ** ---
$filterStepName = $_GET['step_name'] ?? null;
// --- ****************************************** ---

$files = [];
$response = ['success' => false, 'files' => [], 'message' => 'No files found.'];

try {
    $pdo = connectDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Base SQL Query
    $sql = "
        SELECT pa.id as attachment_id, pa.file_path, pa.file_type,
               pa.original_filename, pa.created_at as upload_time, pd.step_name
        FROM hpc_panel_attachments pa
        JOIN hpc_panel_details pd ON pa.panel_detail_id = pd.id
        WHERE pd.panel_id = ?
    ";
    $params = [$panelId];

    // --- ** NEW: Add step_name filter if provided ** ---
    if ($filterStepName !== null) {
        $sql .= " AND pd.step_name = ?";
        $params[] = $filterStepName;
    }
    // --- ******************************************** ---

    $sql .= " ORDER BY pd.step_name, pa.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params); // Execute with potentially added parameter
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($results) {
        // ... (Keep the path processing and array building logic) ...
         $webRootPath = '/';
         foreach ($results as $row) {
             $relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], $webRootPath, $row['file_path']);
             $files[] = [ /* ... file details ... */ 'path' => $relativePath, /* ... */ ];
         }
         $response = ['success' => true, 'files' => $files];

    } else {
         $response['message'] = 'No attachments found matching the criteria.';
    }


} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    error_log("Error fetching panel files: " . $e->getMessage());
    $response['message'] = 'Database error while fetching files.';
} catch (Exception $e) {
     http_response_code(500);
     error_log("General error fetching panel files: " . $e->getMessage());
     $response['message'] = 'An error occurred.';
}

header('Content-Type: application/json');
echo json_encode($response);
exit;
?>