<?php
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

// --- DATABASE CONNECTION ---
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "timesheet_app";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Public routes
if (in_array($action, ['login', 'register'])) {
    if ($action === 'login') login($conn);
    if ($action === 'register') register($conn);
    $conn->close();
    exit();
}

// Protected routes
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

$userId = $_SESSION['user_id'];

switch ($action) {
    case 'get_session':
        echo json_encode(['success' => true, 'userId' => $_SESSION['user_id'], 'username' => $_SESSION['username']]);
        break;
    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;
    case 'get_entries':
        get_entries($conn, $userId);
        break;
    case 'save_entry':
        save_entry($conn, $userId);
        break;
    case 'delete_entry':
        delete_entry($conn, $userId);
        break;
    case 'get_settings':
        get_settings($conn, $userId);
        break;
    case 'save_settings':
        save_settings($conn, $userId);
        break;
    case 'get_rules':
        get_rules($conn, $userId);
        break;
    case 'save_rule':
        save_rule($conn, $userId);
        break;
    case 'delete_rule':
        delete_rule($conn, $userId);
        break;
    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Action not found.']);
}

$conn->close();
exit();

// --- AUTH FUNCTIONS ---
function register($conn)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? '';
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    if (empty($username) || empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please fill all fields.']);
        return;
    }
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $sql = "INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $username, $email, $password_hash);
    if ($stmt->execute()) {
        $newUserId = $conn->insert_id;
        $settingsSql = "INSERT INTO user_settings (user_id, base_salary) VALUES (?, ?)";
        $settingsStmt = $conn->prepare($settingsSql);
        $defaultSalary = 71661840; // Default base salary based on 1403
        $settingsStmt->bind_param("id", $newUserId, $defaultSalary);
        $settingsStmt->execute();
        $settingsStmt->close();
        echo json_encode(['success' => true, 'message' => 'Registration successful.']);
    } else {
        if ($conn->errno == 1062) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Registration failed.']);
        }
    }
    $stmt->close();
}

function login($conn)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
        return;
    }
    $sql = "SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            echo json_encode(['success' => true, 'message' => 'Login successful.']);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        }
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }
    $stmt->close();
}

// --- SETTINGS & RULES ---
function get_settings($conn, $userId)
{
    $sql = "SELECT base_salary, housing_allowance, food_allowance, default_work_hours, thursday_work_hours, lunch_break_start, lunch_break_end FROM user_settings WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    if (!$settings) {
        $settings = ['base_salary' => 0, 'housing_allowance' => 0, 'food_allowance' => 0, 'default_work_hours' => 7.33, 'thursday_work_hours' => 4, 'lunch_break_start' => '12:00:00', 'lunch_break_end' => '13:00:00'];
    }
    echo json_encode(['success' => true, 'data' => $settings]);
    $stmt->close();
}

function save_settings($conn, $userId)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $sql = "INSERT INTO user_settings (user_id, base_salary, housing_allowance, food_allowance, default_work_hours, thursday_work_hours, lunch_break_start, lunch_break_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE base_salary = VALUES(base_salary), housing_allowance = VALUES(housing_allowance), food_allowance = VALUES(food_allowance), default_work_hours = VALUES(default_work_hours), thursday_work_hours = VALUES(thursday_work_hours), lunch_break_start = VALUES(lunch_break_start), lunch_break_end = VALUES(lunch_break_end)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("idddddss", $userId, $data['base_salary'], $data['housing_allowance'], $data['food_allowance'], $data['default_work_hours'], $data['thursday_work_hours'], $data['lunch_break_start'], $data['lunch_break_end']);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Settings saved.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    $stmt->close();
}

// get_rules, save_rule, delete_rule are unchanged...

// --- TIMESHEET ENTRIES ---


// Placeholders for unchanged functions from previous version
function get_rules($conn, $userId)
{
    $sql = "SELECT id, rule_date, required_hours, notes FROM work_day_rules WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rules = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $rules]);
    $stmt->close();
}
function save_rule($conn, $userId)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $sql = "INSERT INTO work_day_rules (user_id, rule_date, required_hours, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE required_hours = VALUES(required_hours), notes = VALUES(notes)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isds", $userId, $data['rule_date'], $data['required_hours'], $data['notes']);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Rule saved.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    $stmt->close();
}
function delete_rule($conn, $userId)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $ruleId = (int)$data['id'];
    $sql = "DELETE FROM work_day_rules WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $ruleId, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Rule deleted.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    $stmt->close();
}
function get_entries($conn, $userId)
{
    $sql = "SELECT e.id, e.gregorian_date, e.jalali_date, e.start_time as overall_start_time, e.end_time as overall_end_time, t.id as task_id, t.start_time, t.end_time, t.description FROM timesheet_entries e LEFT JOIN timesheet_tasks t ON e.id = t.entry_id WHERE e.user_id = ? ORDER BY e.gregorian_date, t.start_time";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = [];
    while ($row = $result->fetch_assoc()) {
        $entryId = $row['id'];
        if (!isset($entries[$entryId])) {
            $entries[$entryId] = ['id' => $entryId, 'gregorian_date' => $row['gregorian_date'], 'jalali_date' => $row['jalali_date'], 'overall_start_time' => $row['overall_start_time'], 'overall_end_time' => $row['overall_end_time'], 'tasks' => []];
        }
        if ($row['task_id']) {
            $entries[$entryId]['tasks'][] = ['id' => $row['task_id'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time'], 'description' => $row['description']];
        }
    }
    echo json_encode(array_values($entries));
    $stmt->close();
}
function save_entry($conn, $userId)
{
    $conn->begin_transaction();
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $gregorianDate = $data['gregorianDate'];
        $jalaliDate = $data['date'];
        $overallStartTime = $data['overall_start_time'] ?? null;
        $overallEndTime = $data['overall_end_time'] ?? null;
        $tasks = $data['tasks'] ?? [];
        $entryId = null;
        $stmt = $conn->prepare("SELECT id FROM timesheet_entries WHERE user_id = ? AND gregorian_date = ?");
        $stmt->bind_param("is", $userId, $gregorianDate);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $entryId = $row['id'];
            $stmt->close();
            $stmt_update = $conn->prepare("UPDATE timesheet_entries SET start_time = ?, end_time = ? WHERE id = ?");
            $stmt_update->bind_param("ssi", $overallStartTime, $overallEndTime, $entryId);
            $stmt_update->execute();
            $stmt_update->close();
        } else {
            $stmt->close();
            $stmt_insert = $conn->prepare("INSERT INTO timesheet_entries (user_id, gregorian_date, jalali_date, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
            $stmt_insert->bind_param("issss", $userId, $gregorianDate, $jalaliDate, $overallStartTime, $overallEndTime);
            $stmt_insert->execute();
            $entryId = $conn->insert_id;
            $stmt_insert->close();
        }
        $stmt_del = $conn->prepare("DELETE FROM timesheet_tasks WHERE entry_id = ?");
        $stmt_del->bind_param("i", $entryId);
        $stmt_del->execute();
        $stmt_del->close();
        if (!empty($tasks)) {
            $stmt_ins_task = $conn->prepare("INSERT INTO timesheet_tasks (entry_id, start_time, end_time, description) VALUES (?, ?, ?, ?)");
            foreach ($tasks as $task) {
                $stmt_ins_task->bind_param("isss", $entryId, $task['start_time'], $task['end_time'], $task['description']);
                $stmt_ins_task->execute();
            }
            $stmt_ins_task->close();
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Entry saved successfully.']);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
    }
}
function delete_entry($conn, $userId)
{
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid entry ID.']);
        return;
    }
    $stmt = $conn->prepare("DELETE FROM timesheet_entries WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $userId);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Entry deleted.']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Entry not found or permission denied.']);
    }
    $stmt->close();
}
