<?php
/****************************************************************************
 * [0] 開啟 Session，方便累積篩選條件
 ****************************************************************************/
session_start();

// 檢查是否已登入
if (!isset($_SESSION['user_id'])) {
    $isLoggedIn = false;
} else {
    $isLoggedIn = true;
}

/****************************************************************************
 * [1] 資料庫連接設定
 ****************************************************************************/
$serverName = "localhost\SQLEXPRESS";
$database   = "Test";
$username   = "weihao0120";
$password   = "weihao0120";

// 啟用錯誤報告以便除錯
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/****************************************************************************
 * [2] 處理 AJAX 請求
 ****************************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'createProject') {
        handleCreateProject();
        exit;
    } elseif (isset($_REQUEST['action']) && $_REQUEST['action'] === 'saveBuildingData') {
        handleSaveBuildingData();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'getProjectData') {
        handleGetProjectData();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'getSpeckleProjects') {
        handleGetSpeckleProjects();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'importSpeckleModel') {
        handleImportSpeckleModel();
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list') {
        handleListProjects();
        exit;
    } elseif (isset($_POST['projectName']) && isset($_POST['projectAddress'])) {
        // 相容舊的提交方式，轉發到統一的處理函數
        $_POST['action'] = 'createProject';
        handleCreateProject();
        exit;
    }
}

/****************************************************************************
 * [3] 處理函數
 ****************************************************************************/

// 創建專案的處理函數
function handleCreateProject() {
    global $serverName, $database, $username, $password;
    
    // 獲取POST數據
    $projectName = trim($_POST['projectName'] ?? '');
    $projectAddress = trim($_POST['projectAddress'] ?? '');
    $speckleProjectId = trim($_POST['speckleProjectId'] ?? '');
    $speckleModelId = trim($_POST['speckleModelId'] ?? '');
    
    // 準備響應數組
    $response = [];
    
    // 驗證輸入
    if (empty($projectName) || empty($projectAddress)) {
        $response = [
            'success' => false,
            'message' => '請填寫所有必填欄位'
        ];
    } else {
        try {
            $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 檢查該用戶是否已有相同名稱的專案
            $checkSql = "SELECT COUNT(*) FROM [Test].[dbo].[GBD_Project] 
                         WHERE UserID = :UserID AND building_name = :building_name";
            
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->execute([
                ':UserID' => $_SESSION['user_id'],
                ':building_name' => $projectName
            ]);
            
            $count = $checkStmt->fetchColumn();
            
            if ($count > 0) {
                $response = [
                    'success' => false,
                    'message' => '您已經有一個相同名稱的專案，請使用不同的專案名稱'
                ];
            } else {
                // 準備 SQL 語句，加入 Speckle 相關欄位
                try {
                    // 先嘗試檢查欄位是否存在
                    $checkColumns = $conn->prepare("
                        SELECT COUNT(*) as col_count 
                        FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_NAME = 'GBD_Project' 
                        AND COLUMN_NAME IN ('speckle_project_id', 'speckle_model_id')
                        AND TABLE_SCHEMA = 'dbo'
                    ");
                    $checkColumns->execute();
                    $columnCheck = $checkColumns->fetch(PDO::FETCH_ASSOC);
                    
                    if ($columnCheck['col_count'] == 2) {
                        // 如果Speckle欄位存在，使用包含這些欄位的SQL
                        $sql = "INSERT INTO [Test].[dbo].[GBD_Project] 
                                (building_name, address, UserID, created_at, updated_at, speckle_project_id, speckle_model_id) 
                                VALUES (:building_name, :address, :UserID, GETDATE(), GETDATE(), :speckle_project_id, :speckle_model_id)";
                        
                        $stmt = $conn->prepare($sql);
                        
                        // 執行 SQL
                        $stmt->execute([
                            ':building_name' => $projectName,
                            ':address' => $projectAddress,
                            ':UserID' => $_SESSION['user_id'],
                            ':speckle_project_id' => $speckleProjectId,
                            ':speckle_model_id' => $speckleModelId
                        ]);
                    } else {
                        // 如果Speckle欄位不存在，先創建欄位
                        if ($columnCheck['col_count'] == 0) {
                            $conn->exec("ALTER TABLE [Test].[dbo].[GBD_Project] ADD speckle_project_id NVARCHAR(255) NULL");
                            $conn->exec("ALTER TABLE [Test].[dbo].[GBD_Project] ADD speckle_model_id NVARCHAR(255) NULL");
                        }
                        
                        // 然後執行插入
                        $sql = "INSERT INTO [Test].[dbo].[GBD_Project] 
                                (building_name, address, UserID, created_at, updated_at, speckle_project_id, speckle_model_id) 
                                VALUES (:building_name, :address, :UserID, GETDATE(), GETDATE(), :speckle_project_id, :speckle_model_id)";
                        
                        $stmt = $conn->prepare($sql);
                        
                        // 執行 SQL
                        $stmt->execute([
                            ':building_name' => $projectName,
                            ':address' => $projectAddress,
                            ':UserID' => $_SESSION['user_id'],
                            ':speckle_project_id' => $speckleProjectId,
                            ':speckle_model_id' => $speckleModelId
                        ]);
                    }
                } catch(PDOException $e) {
                    // 如果還是失敗，回退到不使用Speckle欄位的版本
                    error_log("Speckle columns error, falling back to basic insert: " . $e->getMessage());
                    
                    $sql = "INSERT INTO [Test].[dbo].[GBD_Project] 
                            (building_name, address, UserID, created_at, updated_at) 
                            VALUES (:building_name, :address, :UserID, GETDATE(), GETDATE())";
                    
                    $stmt = $conn->prepare($sql);
                    
                    // 執行 SQL
                    $stmt->execute([
                        ':building_name' => $projectName,
                        ':address' => $projectAddress,
                        ':UserID' => $_SESSION['user_id']
                    ]);
                }
                
                // 獲取最後插入的 ID
                $building_id = $conn->lastInsertId();
                
                // 重要：設置 session 變數，以便導航欄顯示當前專案
                $_SESSION['building_id'] = $building_id;
                $_SESSION['current_gbd_project_id'] = $building_id;
                $_SESSION['current_gbd_project_name'] = $projectName;
                
                // 記錄 session 設置成功
                error_log("SESSION set: project_id={$building_id}, name={$projectName}");
                
                $response = [
                    'success' => true,
                    'building_id' => $building_id,
                    'message' => '專案創建成功',
                    'speckle_project_id' => $speckleProjectId,
                    'speckle_model_id' => $speckleModelId
                ];
            }
            
        } catch(PDOException $e) {
            error_log("DB Error in handleCreateProject: " . $e->getMessage());
            $response = [
                'success' => false,
                'message' => '創建專案時發生錯誤: ' . $e->getMessage()
            ];
        }
    }
    
    // 如果是 AJAX 請求，返回 JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        // 非 AJAX 請求，可以重定向
        if ($response['success']) {
            // 可以在這裡添加重定向代碼
            // header('Location: project_page.php');
        }
    }
}

// 獲取 Speckle 專案列表
function handleGetSpeckleProjects() {
    header('Content-Type: application/json');
    
    $userToken = $_POST['token'] ?? '';
    if (empty($userToken)) {
        echo json_encode([
            'success' => false,
            'message' => '請提供 Speckle 存取權杖'
        ]);
        return;
    }
    
    try {
        // 呼叫 Speckle API 獲取使用者的專案
        $speckleUrl = 'https://speckle.xyz/graphql';
        
        $query = '{
            user {
                projects {
                    items {
                        id
                        name
                        description
                        models {
                            items {
                                id
                                name
                                description
                            }
                        }
                    }
                }
            }
        }';
        
        $postData = json_encode(['query' => $query]);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $userToken
                ],
                'content' => $postData
            ]
        ]);
        
        $result = file_get_contents($speckleUrl, false, $context);
        
        if ($result === FALSE) {
            throw new Exception('無法連接到 Speckle API');
        }
        
        $data = json_decode($result, true);
        
        if (isset($data['errors'])) {
            throw new Exception('Speckle API 錯誤: ' . json_encode($data['errors']));
        }
        
        echo json_encode([
            'success' => true,
            'projects' => $data['data']['user']['projects']['items']
        ]);
        
    } catch (Exception $e) {
        error_log("Speckle API Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => '獲取 Speckle 專案時發生錯誤: ' . $e->getMessage()
        ]);
    }
}

// 匯入 Speckle 模型資料
function handleImportSpeckleModel() {
    header('Content-Type: application/json');
    
    $projectId = $_POST['projectId'] ?? '';
    $modelId = $_POST['modelId'] ?? '';
    $token = $_POST['token'] ?? '';
    
    if (empty($projectId) || empty($modelId) || empty($token)) {
        echo json_encode([
            'success' => false,
            'message' => '缺少必要參數'
        ]);
        return;
    }
    
    try {
        // 從 Speckle 獲取模型資料
        $speckleUrl = 'https://speckle.xyz/graphql';
        
        $query = '
        query GetModel($projectId: String!, $modelId: String!) {
            project(id: $projectId) {
                model(id: $modelId) {
                    id
                    name
                    description
                    versions {
                        items {
                            id
                            referencedObject
                            createdAt
                            message
                        }
                    }
                }
            }
        }';
        
        $variables = [
            'projectId' => $projectId,
            'modelId' => $modelId
        ];
        
        $postData = json_encode([
            'query' => $query,
            'variables' => $variables
        ]);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ],
                'content' => $postData
            ]
        ]);
        
        $result = file_get_contents($speckleUrl, false, $context);
        
        if ($result === FALSE) {
            throw new Exception('無法連接到 Speckle API');
        }
        
        $data = json_decode($result, true);
        
        if (isset($data['errors'])) {
            throw new Exception('Speckle API 錯誤: ' . json_encode($data['errors']));
        }
        
        $modelData = $data['data']['project']['model'];
        
        echo json_encode([
            'success' => true,
            'model' => $modelData,
            'message' => '成功獲取模型資料'
        ]);
        
    } catch (Exception $e) {
        error_log("Import Speckle Model Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => '匯入模型時發生錯誤: ' . $e->getMessage()
        ]);
    }
}

// 儲存建築數據的處理函數
function handleSaveBuildingData() {
    global $serverName, $database, $username, $password;
    
    header('Content-Type: application/json');
    
    // 檢查是否有建築 ID
    if (!isset($_SESSION['building_id']) || empty($_SESSION['building_id'])) {
        echo json_encode([
            'success' => false,
            'message' => '無法識別建築 ID，請先建立專案'
        ]);
        return;
    }
    
    // 取得 AJAX 傳送的 JSON 資料
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!isset($data['floors']) || !is_array($data['floors'])) {
        echo json_encode([
            'success' => false,
            'message' => '無效的資料格式'
        ]);
        return;
    }
    
    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 開始交易
        $conn->beginTransaction();
        
        $building_id = $_SESSION['building_id'];
        
        // 先刪除所有與該 building_id 相關的資料
        // 1. 先刪除房間資料
        $stmtClearRooms = $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_rooms] 
            WHERE unit_id IN (
                SELECT unit_id FROM [Test].[dbo].[GBD_Project_units] 
                WHERE floor_id IN (
                    SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] 
                    WHERE building_id = :building_id
                )
            )
        ");
        $stmtClearRooms->execute([':building_id' => $building_id]);
        error_log("清除房間資料，受影響行數: " . $stmtClearRooms->rowCount());

        // 2. 刪除單位資料
        $stmtClearUnits = $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_units] 
            WHERE floor_id IN (
                SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] 
                WHERE building_id = :building_id
            )
        ");
        $stmtClearUnits->execute([':building_id' => $building_id]);
        error_log("清除單位資料，受影響行數: " . $stmtClearUnits->rowCount());

        // 3. 刪除樓層資料
        $stmtClearFloors = $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_floors] 
            WHERE building_id = :building_id
        ");
        $stmtClearFloors->execute([':building_id' => $building_id]);
        error_log("清除樓層資料，受影響行數: " . $stmtClearFloors->rowCount());
        
        // 插入樓層的 SQL
        $stmtFloor = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_floors] (building_id, floor_number, created_at) VALUES (:building_id, :floor_number, GETDATE())");
        
        // 插入單位的 SQL
        $stmtUnit = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_units] (floor_id, unit_number, created_at) VALUES (:floor_id, :unit_number, GETDATE())");
        
        // 插入房間的 SQL
        $stmtRoom = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_rooms] (unit_id, room_number, height, length, depth, window_position, created_at, updated_at) VALUES (:unit_id, :room_number, :height, :length, :depth, :window_position, GETDATE(), GETDATE())");
        
        // 依照前端傳來的資料格式進行存入
        foreach ($data['floors'] as $floorId => $floor) {
            // 從前端資料中取得樓層編號，例如 "floor1" 取出 1
            $floor_number = intval(str_replace('floor', '', $floorId));
            
            $stmtFloor->execute([
                ':building_id' => $building_id,
                ':floor_number' => $floor_number
            ]);
            
            $floor_id = $conn->lastInsertId();
            error_log("Inserted floor_id: $floor_id for floor_number: $floor_number");
            
            if (isset($floor['units']) && is_array($floor['units'])) {
                foreach ($floor['units'] as $unitId => $unit) {
                    // 從單位 id 如 "floor1_unit1" 取出單位編號
                    $parts = explode('_', $unitId);
                    $unit_number = isset($parts[1]) ? intval(str_replace('unit', '', $parts[1])) : 1;
                    
                    $stmtUnit->execute([
                        ':floor_id' => $floor_id,
                        ':unit_number' => $unit_number
                    ]);
                    
                    $unit_id = $conn->lastInsertId();
                    error_log("Inserted unit_id: $unit_id for unit_number: $unit_number");
                    
                    if (isset($unit['rooms']) && is_array($unit['rooms'])) {
                        foreach ($unit['rooms'] as $roomId => $room) {
                            // 確保所有值都是有效的
                            $roomNumber = !empty($room['roomNumber']) ? $room['roomNumber'] : '';
                            $height = !empty($room['height']) ? $room['height'] : null;
                            $length = !empty($room['length']) ? $room['length'] : null;
                            $depth = !empty($room['depth']) ? $room['depth'] : null;
                            $windowPosition = !empty($room['windowPosition']) ? $room['windowPosition'] : '';
                            
                            $stmtRoom->execute([
                                ':unit_id' => $unit_id,
                                ':room_number' => $roomNumber,
                                ':height' => $height,
                                ':length' => $length,
                                ':depth' => $depth,
                                ':window_position' => $windowPosition
                            ]);
                            
                            $room_id = $conn->lastInsertId();
                            error_log("Inserted room_id: $room_id for room_number: $roomNumber");
                        }
                    }
                }
            }
        }
        
        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => '資料庫儲存成功'
        ]);
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("DB Error in handleSaveBuildingData: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        echo json_encode([
            'success' => false,
            'message' => '儲存資料時發生錯誤: ' . $e->getMessage()
        ]);
    }
}

//取得專案內容
function handleGetProjectData() {
    global $serverName, $database, $username, $password;
    
    header('Content-Type: application/json');
    
    if (!isset($_POST['projectId']) || empty($_POST['projectId'])) {
        echo json_encode([
            'success' => false,
            'message' => '缺少專案ID'
        ]);
        return;
    }
    
    $projectId = intval($_POST['projectId']);
    
    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 獲取專案基本資訊
        try {
            // 先檢查Speckle欄位是否存在
            $checkColumns = $conn->prepare("
                SELECT COUNT(*) as col_count 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_NAME = 'GBD_Project' 
                AND COLUMN_NAME IN ('speckle_project_id', 'speckle_model_id')
                AND TABLE_SCHEMA = 'dbo'
            ");
            $checkColumns->execute();
            $columnCheck = $checkColumns->fetch(PDO::FETCH_ASSOC);
            
            if ($columnCheck['col_count'] == 2) {
                // 如果Speckle欄位存在，包含在查詢中
                $projectStmt = $conn->prepare("SELECT *, speckle_project_id, speckle_model_id FROM [Test].[dbo].[GBD_Project] WHERE building_id = :building_id");
            } else {
                // 如果Speckle欄位不存在，只查詢基本欄位
                $projectStmt = $conn->prepare("SELECT * FROM [Test].[dbo].[GBD_Project] WHERE building_id = :building_id");
            }
            
            $projectStmt->execute([':building_id' => $projectId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
            
            // 如果沒有Speckle欄位，設為null
            if ($columnCheck['col_count'] != 2) {
                $project['speckle_project_id'] = null;
                $project['speckle_model_id'] = null;
            }
            
        } catch(PDOException $e) {
            // 如果查詢失敗，嘗試基本查詢
            $projectStmt = $conn->prepare("SELECT * FROM [Test].[dbo].[GBD_Project] WHERE building_id = :building_id");
            $projectStmt->execute([':building_id' => $projectId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
            
            // 設置預設值
            if ($project) {
                $project['speckle_project_id'] = null;
                $project['speckle_model_id'] = null;
            }
        }
        
        if (!$project) {
            echo json_encode([
                'success' => false,
                'message' => '找不到該專案'
            ]);
            return;
        }
        
        // 獲取樓層
        $floorsStmt = $conn->prepare("
            SELECT * FROM [Test].[dbo].[GBD_Project_floors] 
            WHERE building_id = :building_id
            ORDER BY floor_number ASC
        ");
        $floorsStmt->execute([':building_id' => $projectId]);
        $floors = $floorsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $projectData = [
            'project' => $project,
            'floors' => []
        ];
        
        foreach ($floors as $floor) {
            $floorData = [
                'floor_id' => $floor['floor_id'],
                'floor_number' => $floor['floor_number'],
                'units' => []
            ];
            
            // 獲取該樓層的單位
            $unitsStmt = $conn->prepare("
                SELECT * FROM [Test].[dbo].[GBD_Project_units] 
                WHERE floor_id = :floor_id
                ORDER BY unit_number ASC
            ");
            $unitsStmt->execute([':floor_id' => $floor['floor_id']]);
            $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($units as $unit) {
                $unitData = [
                    'unit_id' => $unit['unit_id'],
                    'unit_number' => $unit['unit_number'],
                    'rooms' => []
                ];
                
                // 獲取該單位的房間
                $roomsStmt = $conn->prepare("
                    SELECT * FROM [Test].[dbo].[GBD_Project_rooms] 
                    WHERE unit_id = :unit_id
                    ORDER BY room_number ASC
                ");
                $roomsStmt->execute([':unit_id' => $unit['unit_id']]);
                $rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($rooms as $room) {
                    $unitData['rooms'][] = [
                        'room_id' => $room['room_id'],
                        'room_number' => $room['room_number'] ?? '',
                        'height' => $room['Height'] ?? null,
                        'length' => $room['length'] ?? null,
                        'depth' => $room['depth'] ?? null,
                        'window_position' => $room['window_position'] ?? ''
                    ];
                }
                
                $floorData['units'][] = $unitData;
            }
            
            $projectData['floors'][] = $floorData;
        }
        
        echo json_encode([
            'success' => true,
            'projectData' => $projectData
        ]);
        
    } catch (PDOException $e) {
        error_log("DB Error in handleGetProjectData: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => '獲取專案資料時發生錯誤: ' . $e->getMessage()
        ]);
    }
}

// Add this function to your PHP file
function handleListProjects() {
    // 清除之前的輸出緩衝
    ob_clean();
    
    global $serverName, $database, $username, $password;
    
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => '請先登入',
            'redirect' => 'login.php'
        ]);
        exit; // 重要：確保沒有其他輸出
    }
    
    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $conn->prepare("SELECT building_id, building_name, address, created_at FROM [Test].[dbo].[GBD_Project] WHERE UserID = :UserID ORDER BY created_at DESC");
        $stmt->execute([':UserID' => $_SESSION['user_id']]);
        
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'projects' => $projects
        ]);
        
    } catch (PDOException $e) {
        error_log("DB Error in handleListProjects: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => '載入專案列表時發生錯誤: ' . $e->getMessage()
        ]);
    }
    exit; // 重要：確保沒有其他輸出
}


// 如果這是一個 list 請求，直接處理並終止
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    handleListProjects();
    exit;
}


/****************************************************************************
 * [4] 語言轉換
 ****************************************************************************/
include('language.php');
// 確保session已啟動
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="greenBuildingTitle">綠建築計算</title>
    
    <!-- 引入 Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="your-existing-styles.css" />
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <!-- Speckle Viewer -->
    <script type="module" src="https://unpkg.com/@speckle/viewer@2/dist/viewer.js"></script>

    <style>
        /* 全局樣式 */
        body {
            margin-top: 100px; /* 確保 navbar 不會擋住主內容 */
            padding: 0;
            background: linear-gradient(to bottom, rgba(255, 255, 255, 0.8), rgba(255, 255, 255, 0) 80%), 
                        url('https://i.imgur.com/WJGtbFT.jpeg');
            background-size: 100% 100%; /* 使背景圖片填滿整個背景區域 */
            background-position: center; /* 背景圖片居中 */
            background-repeat: no-repeat; /* 不重複背景圖片 */
            background-attachment: fixed; /* 背景固定在視口上 */
        }


        .navbar-brand {
            font-weight: bold;
        }

        /* 共用容器樣式 */
        #container, #buildingContainer {
            margin: 0 auto;
            padding: 20px;
        }

        #container {
            display: flex;
            flex-direction: column;
            align-items: flex-start; /* 讓內容靠左對齊 */
            max-width: 70%;
        }

        #buildingContainer {
            /* max-width: 70%; 調整最大寬度，避免內容過寬 */
            margin: 0 auto; /* 讓內容在螢幕中央 */
            padding: 20px; /* 增加內邊距，避免太靠邊 */
        }

        /* 卡片相關樣式 */
        .section-card, .section-card-list, .project-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .project-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 4px solid #769a76;
        }

        .project-card:hover {
            background-color: #f0f0f0;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* 樓層和房間樣式 */
        .floor, .unit, .room {
            border: 1px solid #000;
            margin: 10px 0;
            padding: 10px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
        }

        .floor:nth-child(odd) {
            background-color: rgba(191, 202, 194, 0.7); /* 第一種顏色，透明度70% */
        }

        .floor:nth-child(even) {
            background-color: rgba(235, 232, 227, 0.7); /* 第二種顏色，透明度70% */
        }

        /* 表頭和行樣式 */
        .header-row {
            font-weight: bold;
            display: flex;
            justify-content: space-between;
        }

        .header-row div {
            flex: 1;
            text-align: center;
            padding: 5px;
            border-bottom: 1px solid #000;
        }

        .room-row {
            display: flex;
            justify-content: space-between;
        }

        .room-row input {
            flex: 1;
            margin: 5px;
            padding: 5px;
        }

        /* 按鈕共用樣式 */
        button, .btn {
            margin-top: 10px;
            padding: 10px;
            border-radius: 12px;
            background-color: #769a76; /* 設定基本顏色 */
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        button:hover, .btn:hover {
            background-color: #87ab87; /* 懸停時顏色略微變亮 */
        }

        /* Modal 相關樣式 */
        #modal, #deleteModal, #copyModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto; /* 允許整個模態框區域滾動 */
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto; /* 調整上邊距，讓模態框更靠上 */
            padding: 20px;
            border-radius: 10px;
            width: 60%;
            max-width: 800px;
            max-height: 80vh; /* 設置最大高度為視窗高度的80% */
            overflow-y: auto; /* 允許內容滾動 */
            position: relative; /* 為了固定標題 */
        }

        /* 固定按鈕區域樣式 */
        #fixed-buttons {
            position: fixed;
            top: 220px;
            left: 150px;
            background-color: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            min-width: 120px;
            margin-bottom: 20px;
        }

        #fixed-buttons button {
            margin: 8px 0;
            padding: 12px 20px;
            font-size: 16px;
            font-weight: 500;
            border-radius: 6px;
            border: none; /* 移除邊框 */
            background-color: #769a76; /* 設定基本顏色 */
            color: white; /* 文字顏色設為白色 */
            cursor: pointer;
            transition: all 0.2s ease;
        }

        #fixed-buttons button:hover {
            background-color: #87ab87; /* 懸停時顏色略微變亮 */
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        #fixed-buttons button:active {
            transform: translateY(0);
            background-color: #658965; /* 點擊時顏色略微變暗 */
        }

        /* Modal 子內容樣式 */
        .sub-modal-content {
            display: none;
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            overflow-y: auto;
        }

        .modal-header {
            position: sticky;
            top: 0;
            background-color: #fff;
            padding: 10px 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            z-index: 1;
        }

        /* 複製功能相關樣式 */
        .copy-select, .copy-input {
            margin: 8px 0;
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .copy-label {
            display: block;
            margin-top: 12px;
            margin-bottom: 4px;
            font-weight: bold;
            color: #333;
        }

        /* 按鈕組樣式 */
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            position: sticky;
            bottom: 0;
            background-color: #fff;
        }

        .button-group button {
            flex: 1;
            margin: 0;
        }

        /* 分隔線 */
        .divider {
            height: 1px;
            background-color: #ddd;
            margin: 15px 0;
        }

        /* 輸入欄位樣式 */
        .input-field {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
            margin-top: 0.25rem;
        }

        /* 其他輔助樣式 */
        .hidden {
            display: none;
        }

        .custom-navbar {
            background-color: #769a76;
        }

        /* 以下是第二段獨有的樣式 */

        /* 分頁控制樣式 */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .pagination button {
            margin: 0 5px;
            padding: 5px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            background-color: #fff;
        }

        .pagination button.active {
            background-color: #007bff;
            color: #fff;
        }

        .pagination button:disabled {
            background-color: #f0f0f0;
            color: #999;
            cursor: not-allowed;
        }

        /* 專案相關樣式 */
        .project-list {
            max-height: 700px;
            overflow-y: auto;
        }

        .project-name-display {
            margin-bottom: 15px;
            padding: 5px 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }

        .project-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            margin-bottom: 10px;
            background: #f5f5f5;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        /* Loading 狀態 */
        .loading {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid rgba(0,0,0,.125);
            font-size: 32px;
            font-family: "Arial", sans-serif;
        }

        /* 專案名稱相關樣式 */
        .project-name {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .project-details {
            font-size: 0.9em;
            color: #666;
        }

        .project-info {
            flex: 1;
        }

        .project-name-display h3 {
            margin: 0;
            font-size: 20px;
            color: #333;
        }

        #currentProjectName {
            font-weight: bold;
            color: #2196F3;
        }

        /* Speckle Viewer 樣式 */
        .speckle-viewer-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80vw;
            height: 80vh;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            z-index: 2000;
            display: flex;
            flex-direction: column;
        }

        .viewer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 10px 10px 0 0;
        }

        .viewer-header h5 {
            margin: 0;
            color: #333;
        }

        .speckle-viewer {
            flex: 1;
            width: 100%;
            border-radius: 0 0 10px 10px;
        }

        /* 背景遮罩 */
        .speckle-viewer-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1999;
        }
    </style>
</head>
<body>
<?php include('navbar.php'); ?>

    <!-- <div class="container my-3">
                <h1 class="text-2xl font-bold" data-i18n="greenBuildingCalc">綠建築專案</h1>
                <p class="mt-2" data-i18n="greenBuildingDesc">在這裡進行綠建築計算的內容。</p>
    </div> -->

    <div class="container my-3">
        <div class="card mb-4" id="history-section">
        <h2 class="card-header"><?php echo __('green_building_project_history'); ?></h2>
            <div id="section-card-list">
                <div class="filter-project-list-section" id="projectListSection">
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3">
                        <h5>專案列表</h5>
                        <button class="btn btn-primary" onclick="showCreateProjectModal()">
                            <i class="fas fa-plus"></i> 新增專案
                        </button>
                    </div>
                    <div id="projectList" class="project-list p-3">
                        <!-- 專案列表將由 JavaScript 動態填充 -->
                        <div class="loading">載入中...</div>
                    </div>
            
                    <!-- 分頁控制將由 JavaScript 動態填充 -->
                    <div id="pagination" class="pagination"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 新增專案 Modal -->
    <div class="modal fade" id="createProjectModal" tabindex="-1" aria-labelledby="createProjectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createProjectModalLabel">新增綠建築專案</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createProjectForm">
                        <!-- 基本資訊 -->
                        <div class="mb-3">
                            <label for="projectName" class="form-label">專案名稱 *</label>
                            <input type="text" class="form-control" id="projectName" name="projectName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="projectAddress" class="form-label">專案地址 *</label>
                            <input type="text" class="form-control" id="projectAddress" name="projectAddress" required>
                        </div>

                        <!-- Speckle 整合選項 -->
                        <div class="mb-4">
                            <h6 class="text-secondary">模型來源選擇</h6>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="modelSource" id="manualInput" value="manual" checked>
                                <label class="form-check-label" for="manualInput">
                                    手動輸入建築資料
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="modelSource" id="speckleImport" value="speckle">
                                <label class="form-check-label" for="speckleImport">
                                    從 Speckle 匯入 Revit 模型
                                </label>
                            </div>
                        </div>

                        <!-- Speckle 選項區域 -->
                        <div id="speckleSection" class="d-none">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle"></i> 使用 Speckle 匯入模型</h6>
                                <p class="mb-1">請確保您已在 Revit 中將模型發送到 Speckle。</p>
                                <p class="mb-0">然後提供您的 Speckle 存取權杖以選擇要匯入的模型。</p>
                            </div>
                            
                            <div class="mb-3">
                                <label for="speckleToken" class="form-label">Speckle 存取權杖</label>
                                <input type="password" class="form-control" id="speckleToken" 
                                       placeholder="請貼上您的 Speckle 存取權杖">
                                <div class="form-text">
                                    <a href="https://speckle.xyz/profile" target="_blank">
                                        在此取得您的 Speckle 存取權杖
                                    </a>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <button type="button" class="btn btn-outline-primary" onclick="loadSpeckleProjects()">
                                    <i class="fas fa-download"></i> 載入我的 Speckle 專案
                                </button>
                            </div>
                            
                            <div id="speckleProjectsSection" class="d-none">
                                <div class="mb-3">
                                    <label for="speckleProjectSelect" class="form-label">選擇 Speckle 專案</label>
                                    <select class="form-select" id="speckleProjectSelect" onchange="loadSpeckleModels()">
                                        <option value="">-- 請選擇專案 --</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="speckleModelSelect" class="form-label">選擇模型</label>
                                    <select class="form-select" id="speckleModelSelect">
                                        <option value="">-- 請先選擇專案 --</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="createProject()" id="createProjectBtn">
                        創建專案
                    </button>
                </div>
            </div>
        </div>
    </div>

<div class="container mx-auto p-4">

        <!-- 計算器內容 -->

    <div id="calculatorContent" class="hidden">
    
        <div id="back_list_btn">
            <button 
                onclick="backToList()" 
                class="btn bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded mb-4"
            >
                <?php echo __('back_to_list'); ?>
            </button>
        </div>

        <div id="fixed-buttons">
            <button onclick="handleAdd()"><?php echo __('add'); ?></button>
            <button onclick="handleCopy()"><?php echo __('copy'); ?></button>
            <button onclick="handleDelete()"><?php echo __('delete'); ?></button>
            <button onclick="handleSave()"><?php echo __('save'); ?></button>
            <button onclick="handleCalculate()"><?php echo __('calculate'); ?></button>
            <button onclick="toggleSpeckleViewer()" id="speckleViewerBtn" style="display: none;">
                <i class="fas fa-cube"></i> 3D 模型
            </button>
        </div>

        <!-- Speckle 3D Viewer -->
        <div id="speckleViewerContainer" class="speckle-viewer-container" style="display: none;">
            <div class="viewer-header">
                <h5>3D 模型檢視器</h5>
                <button type="button" class="btn-close" onclick="closeSpeckleViewer()"></button>
            </div>
            <div id="speckleViewer" class="speckle-viewer"></div>
        </div>

        <div id="buildingContainer">
            <div class="floor" id="floor1">
                <h3><span><?php echo __('floor'); ?></span> 1</h3>
                <div class="unit" id="floor1_unit1">
                    <h4><span><?php echo __('unit'); ?></span> 1</h4>
                    <div class="header-row">
                        <div><?php echo __('roomNumber'); ?></div>
                        <div><?php echo __('height'); ?></div>
                        <div><?php echo __('length'); ?></div>
                        <div><?php echo __('depth'); ?></div>
                        <div><?php echo __('windowPosition'); ?></div>
                    </div>
                    <div class="room-row" id="floor1_unit1_room1">
                        <input type="text" value="1">
                        <input type="text">
                        <input type="text">
                        <input type="text">
                        <input type="text">
                    </div>
                </div>
            </div>
        </div>
    </div>

        <!-- Add Modal -->
        <div id="modal">
            <div class="modal-content">
                <h2><?php echo __('selectOptionAdd'); ?></h2>
                <button onclick="showAddFloor()"><?php echo __('addFloor'); ?></button>
                <button onclick="showAddUnit()"><?php echo __('addUnit'); ?></button>
                <button onclick="showAddRoom()"><?php echo __('addRoom'); ?></button>
                <button onclick="closeModal()"><?php echo __('cancel'); ?></button>

                <div class="sub-modal-content" id="addFloorContent">
                    <h3><?php echo __('addFloorTitle'); ?></h3>
                    <p><?php echo __('floorAddedSuccess'); ?></p>
                    <button onclick="addFloor()"><?php echo __('confirm'); ?></button>
                    <button onclick="closeSubModal('addFloorContent')"><?php echo __('cancel'); ?></button>
                </div>

                <div class="sub-modal-content" id="addUnitContent">
                    <h3><?php echo __('addUnitTitle'); ?></h3>
                    <label for="unitFloorSelect"><?php echo __('selectFloor'); ?></label>
                    <select id="unitFloorSelect" onchange="updateUnitNumber()"></select>
                    <label for="unitNumber"><?php echo __('unitNumber'); ?></label>
                    <input type="number" id="unitNumber" min="1" value="1">
                    <button onclick="addUnitPrompt()"><?php echo __('confirm'); ?></button>
                    <button onclick="closeSubModal('addUnitContent')"><?php echo __('cancel'); ?></button>
                </div>

                <div class="sub-modal-content" id="addRoomContent">
                    <h3><?php echo __('addRoomTitle'); ?></h3>
                    <label for="roomFloorSelect"><?php echo __('selectFloor'); ?></label>
                    <select id="roomFloorSelect" onchange="updateRoomUnitSelect()"></select>
                    <label for="roomUnitSelect"><?php echo __('selectUnit'); ?></label>
                    <select id="roomUnitSelect"></select>
                    <button onclick="addRoomPrompt()"><?php echo __('confirm'); ?></button>
                    <button onclick="closeSubModal('addRoomContent')"><?php echo __('cancel'); ?></button>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div id="deleteModal">
            <div class="modal-content">
                <h2><?php echo __('selectOptionDelete'); ?></h2>
                <button onclick="showDeleteFloor()"><?php echo __('deleteFloor'); ?></button>
                <button onclick="showDeleteUnit()"><?php echo __('deleteUnit'); ?></button>
                <button onclick="showDeleteRoom()"><?php echo __('deleteRoom'); ?></button>
                <button onclick="closeDeleteModal()"><?php echo __('cancel'); ?></button>

                <div class="sub-modal-content" id="deleteFloorContent">
                    <h3><?php echo __('deleteFloorTitle'); ?></h3>
                    <label for="deleteFloorSelect"><?php echo __('selectFloor'); ?></label>
                    <select id="deleteFloorSelect"></select>
                    <button onclick="deleteFloor()"><?php echo __('confirm'); ?></button>
                    <button onclick="closeSubModal('deleteFloorContent')"><?php echo __('cancel'); ?></button>
                </div>

                <div class="sub-modal-content" id="deleteUnitContent">
                    <h3><?php echo __('deleteUnitTitle'); ?></h3>
                    <label for="deleteUnitFloorSelect"><?php echo __('selectFloor'); ?></label>
                    <select id="deleteUnitFloorSelect" onchange="updateDeleteUnitSelect()"></select>
                    <label for="deleteUnitSelect"><?php echo __('selectUnit'); ?></label>
                    <select id="deleteUnitSelect"></select>
                    <button onclick="deleteUnit()"><?php echo __('confirm'); ?></button>
                    <button onclick="closeSubModal('deleteUnitContent')"><?php echo __('cancel'); ?></button>
                </div>

                <div class="sub-modal-content" id="deleteRoomContent">
                    <h3><?php echo __('deleteRoomTitle'); ?></h3>
                    <label for="deleteRoomFloorSelect"><?php echo __('selectFloor'); ?></label>
                    <select id="deleteRoomFloorSelect" onchange="updateDeleteRoomUnitSelect()"></select>
                    <label for="deleteRoomUnitSelect"><?php echo __('selectUnit'); ?></label>
                    <select id="deleteRoomUnitSelect" onchange="updateDeleteRoomSelect()"></select>
                    <label for="deleteRoomSelect"><?php echo __('selectRoom'); ?></label>
                    <select id="deleteRoomSelect"></select>
                    <button onclick="deleteRoom()"><?php echo __('confirm'); ?></button>
                    <button onclick="closeSubModal('deleteRoomContent')"><?php echo __('cancel'); ?></button>
                </div>
            </div>
        </div>

        <!-- Copy Modal -->
        <div id="copyModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><?php echo __('selectOptionCopy'); ?></h2>
                </div>

                <button onclick="showCopyFloor()"><?php echo __('copyFloor'); ?></button>
                <button onclick="showCopyUnit()"><?php echo __('copyUnit'); ?></button>
                <button onclick="showCopyRoom()"><?php echo __('copyRoom'); ?></button>

                <div class="divider"></div>

                <div class="sub-modal-content" id="copyFloorContent">
                    <h3><?php echo __('copyFloorTitle'); ?></h3>
                    <label class="copy-label"><?php echo __('sourceFloor'); ?></label>
                    <select id="sourceFloorSelect" class="copy-select"></select>

                    <label class="copy-label"><?php echo __('targetFloorNumber'); ?></label>
                    <input type="number" id="targetFloorNumber" class="copy-select" min="1">

                    <div class="button-group">
                        <button onclick="copyFloor()"><?php echo __('copy'); ?></button>
                        <button onclick="closeSubModal('copyFloorContent')"><?php echo __('cancel'); ?></button>
                    </div>
                </div>

                <div class="sub-modal-content" id="copyUnitContent">
                    <h3><?php echo __('copyUnitTitle'); ?></h3>
                    <label class="copy-label"><?php echo __('sourceFloor'); ?></label>
                    <select id="sourceUnitFloorSelect" class="copy-select" onchange="updateSourceUnitSelect()"></select>

                    <label class="copy-label"><?php echo __('sourceUnit'); ?></label>
                    <select id="sourceUnitSelect" class="copy-select"></select>

                    <label class="copy-label"><?php echo __('targetFloor'); ?></label>
                    <select id="targetUnitFloorSelect" class="copy-select"></select>

                    <label class="copy-label"><?php echo __('targetUnitNumber'); ?></label>
                    <input type="number" id="targetUnitNumber" class="copy-select" min="1">

                    <div class="button-group">
                        <button onclick="copyUnit()"><?php echo __('copy'); ?></button>
                        <button onclick="closeSubModal('copyUnitContent')"><?php echo __('cancel'); ?></button>
                    </div>
                </div>

                <div class="sub-modal-content" id="copyRoomContent">
                    <h3><?php echo __('copyRoomTitle'); ?></h3>
                    <label class="copy-label"><?php echo __('sourceFloor'); ?></label>
                    <select id="sourceRoomFloorSelect" class="copy-select" onchange="updateSourceRoomUnitSelect()"></select>

                    <label class="copy-label"><?php echo __('sourceUnit'); ?></label>
                    <select id="sourceRoomUnitSelect" class="copy-select" onchange="updateSourceRoomSelect()"></select>

                    <label class="copy-label"><?php echo __('sourceRoom'); ?></label>
                    <select id="sourceRoomSelect" class="copy-select"></select>

                    <label class="copy-label"><?php echo __('targetFloor'); ?></label>
                    <select id="targetRoomFloorSelect" class="copy-select" onchange="updateTargetRoomUnitSelect()"></select>

                    <label class="copy-label"><?php echo __('targetUnit'); ?></label>
                    <select id="targetRoomUnitSelect" class="copy-select"></select>

                    <div class="button-group">
                        <button onclick="copyRoom()"><?php echo __('copy'); ?></button>
                        <button onclick="closeSubModal('copyRoomContent')"><?php echo __('cancel'); ?></button>
                    </div>
                </div>

                <button onclick="closeCopyModal()" style="margin-top: 15px; width: 100%;"><?php echo __('close'); ?></button>
            </div>
        </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
    let projectsData = [];
    let currentPage = 1;
    const itemsPerPage = 5;
    let speckleProjects = [];
    let currentSpeckleViewer = null;
    let currentProjectSpeckleData = null;

    // 頁面加載時初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 初始顯示狀態設定
        document.getElementById('section-card-list').style.display = 'block';
        document.getElementById('calculatorContent').style.display = 'none';
        
        loadProjectHistory();
        
        // 初始化模型來源選擇事件
        document.querySelectorAll('input[name="modelSource"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const speckleSection = document.getElementById('speckleSection');
                if (this.value === 'speckle') {
                    speckleSection.classList.remove('d-none');
                } else {
                    speckleSection.classList.add('d-none');
                }
            });
        });
        
        // 檢查URL參數是否需要自動載入專案
        const urlParams = new URLSearchParams(window.location.search);
        const autoloadId = urlParams.get('autoload');
        
        if (autoloadId) {
            // 如果有autoload參數，自動載入此專案
            setTimeout(() => loadProject(autoloadId), 500);
        } else {
            // 從sessionStorage恢復專案資訊（如果有）
            const savedProjectId = sessionStorage.getItem('currentProjectId');
            const savedProjectName = sessionStorage.getItem('currentProjectName');
            
            console.log("頁面載入，檢查存儲的專案: ", savedProjectId, savedProjectName);
            
            if (savedProjectId && savedProjectName) {
                // 更新GBD專案（舊方法）
                updateGBDProject(savedProjectId, savedProjectName);
                
                // 直接更新導航欄（新方法）
                forceUpdateNavbar(savedProjectId, savedProjectName);
                
                // 延遲執行以確保DOM完全載入
                setTimeout(() => {
                    forceUpdateNavbar(savedProjectId, savedProjectName);
                }, 100);
            }
        }
    });

    // 顯示創建專案Modal
    function showCreateProjectModal() {
        const modal = new bootstrap.Modal(document.getElementById('createProjectModal'));
        modal.show();
    }

    // 載入Speckle專案
    function loadSpeckleProjects() {
        const token = document.getElementById('speckleToken').value.trim();
        if (!token) {
            alert('請先輸入Speckle存取權杖');
            return;
        }

        // 顯示載入狀態
        const loadButton = event.target;
        const originalText = loadButton.innerHTML;
        loadButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 載入中...';
        loadButton.disabled = true;

        fetch('greenbuildingcal-past.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=getSpeckleProjects&token=' + encodeURIComponent(token)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                speckleProjects = data.projects;
                populateSpeckleProjects();
                document.getElementById('speckleProjectsSection').classList.remove('d-none');
            } else {
                alert('載入Speckle專案失敗: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('載入Speckle專案時發生錯誤');
        })
        .finally(() => {
            loadButton.innerHTML = originalText;
            loadButton.disabled = false;
        });
    }

    // 填充Speckle專案選項
    function populateSpeckleProjects() {
        const select = document.getElementById('speckleProjectSelect');
        select.innerHTML = '<option value="">-- 請選擇專案 --</option>';
        
        speckleProjects.forEach(project => {
            const option = document.createElement('option');
            option.value = project.id;
            option.textContent = project.name + (project.description ? ` (${project.description})` : '');
            option.dataset.project = JSON.stringify(project);
            select.appendChild(option);
        });
    }

    // 載入Speckle模型
    function loadSpeckleModels() {
        const projectSelect = document.getElementById('speckleProjectSelect');
        const modelSelect = document.getElementById('speckleModelSelect');
        
        modelSelect.innerHTML = '<option value="">-- 請選擇模型 --</option>';
        
        if (!projectSelect.value) return;
        
        const selectedProject = JSON.parse(projectSelect.selectedOptions[0].dataset.project);
        
        if (selectedProject.models && selectedProject.models.items) {
            selectedProject.models.items.forEach(model => {
                const option = document.createElement('option');
                option.value = model.id;
                option.textContent = model.name + (model.description ? ` (${model.description})` : '');
                modelSelect.appendChild(option);
            });
        }
    }

    // 創建專案
    function createProject() {
        const form = document.getElementById('createProjectForm');
        const formData = new FormData(form);
        
        const projectName = formData.get('projectName');
        const projectAddress = formData.get('projectAddress');
        const modelSource = formData.get('modelSource');
        
        if (!projectName || !projectAddress) {
            alert('請填寫所有必填欄位');
            return;
        }
        
        // 準備提交數據
        const submitData = {
            action: 'createProject',
            projectName: projectName,
            projectAddress: projectAddress
        };
        
        // 如果選擇了Speckle匯入
        if (modelSource === 'speckle') {
            const speckleProjectId = document.getElementById('speckleProjectSelect').value;
            const speckleModelId = document.getElementById('speckleModelSelect').value;
            
            if (!speckleProjectId || !speckleModelId) {
                alert('請選擇Speckle專案和模型');
                return;
            }
            
            submitData.speckleProjectId = speckleProjectId;
            submitData.speckleModelId = speckleModelId;
        }
        
        // 顯示載入狀態
        const createBtn = document.getElementById('createProjectBtn');
        const originalText = createBtn.textContent;
        createBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 創建中...';
        createBtn.disabled = true;
        
        // 提交表單
        const params = new URLSearchParams(submitData);
        
        fetch('greenbuildingcal-past.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: params
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('專案創建成功！');
                
                // 關閉Modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('createProjectModal'));
                modal.hide();
                
                // 重新載入專案列表
                loadProjectHistory();
                
                // 如果有Speckle數據，自動載入專案
                if (data.speckle_project_id && data.speckle_model_id) {
                    setTimeout(() => {
                        loadProject(data.building_id);
                    }, 1000);
                }
                
                // 重置表單
                form.reset();
                document.getElementById('speckleSection').classList.add('d-none');
                document.getElementById('speckleProjectsSection').classList.add('d-none');
            } else {
                alert('創建專案失敗: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('創建專案時發生錯誤');
        })
        .finally(() => {
            createBtn.textContent = originalText;
            createBtn.disabled = false;
        });
    }

    // Speckle Viewer 相關功能
    function toggleSpeckleViewer() {
        const container = document.getElementById('speckleViewerContainer');
        if (container.style.display === 'none') {
            showSpeckleViewer();
        } else {
            closeSpeckleViewer();
        }
    }

    function showSpeckleViewer() {
        if (!currentProjectSpeckleData) {
            alert('此專案沒有關聯的3D模型');
            return;
        }
        
        const container = document.getElementById('speckleViewerContainer');
        container.style.display = 'flex';
        
        // 初始化Speckle Viewer
        if (!currentSpeckleViewer) {
            const viewerContainer = document.getElementById('speckleViewer');
            
            // 創建Speckle Viewer
            import('https://unpkg.com/@speckle/viewer@2/dist/viewer.js').then((SpeckleViewer) => {
                currentSpeckleViewer = new SpeckleViewer.Viewer({
                    container: viewerContainer,
                    showStats: true
                });
                
                // 載入模型
                const objectUrl = `https://speckle.xyz/projects/${currentProjectSpeckleData.projectId}/models/${currentProjectSpeckleData.modelId}@latest`;
                currentSpeckleViewer.loadObject(objectUrl);
            }).catch(error => {
                console.error('Error loading Speckle Viewer:', error);
                alert('載入3D檢視器時發生錯誤');
            });
        }
    }

    function closeSpeckleViewer() {
        const container = document.getElementById('speckleViewerContainer');
        container.style.display = 'none';
    }

    function loadProjectHistory() {
        // 顯示載入指示器
        document.getElementById('projectList').innerHTML = '<div class="loading">載入中...</div>';
        
        fetch('greenbuildingcal-past.php?action=list')
            .then(response => {
                // 先檢查一下實際的響應內容
                return response.text().then(text => {
                    console.log("Raw response:", text);
                    try {
                        return JSON.parse(text);
                    } catch (error) {
                        throw new Error(`JSON parsing error: ${error.message}. Raw response: ${text.substring(0, 100)}...`);
                    }
                });
            })
            .then(data => {
                const projectList = document.getElementById('projectList');

                if (!data.success) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    projectList.innerHTML = `<div class="text-red-500 p-4">${data.message}</div>`;
                    return;
                }

                if (!data.projects || data.projects.length === 0) {
                    projectList.innerHTML = `
                        <div class='alert alert-info'>
                            <?php echo __('no_projects_message'); ?>
                        </div>`;
                    document.getElementById('pagination').innerHTML = '';
                    return;
                }

                projectsData = data.projects;
                currentPage = 1;

                renderProjects();
                renderPagination();
            })
            .catch(error => {
                console.error('Error loading projects:', error);
                document.getElementById('projectList').innerHTML = `
                    <div class="text-red-500 p-4"><?php echo __('project_load_error'); ?>: ${error.message}</div>`;
            });
    }

            function renderProjects() {
            const projectList = document.getElementById('projectList');
            projectList.innerHTML = '';

            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const projectsToShow = projectsData.slice(start, end);

            if (projectsToShow.length === 0) {
                projectList.innerHTML = '<div class="text-center py-4 text-gray-600"><?php echo __("no_saved_projects"); ?></div>';
                return;
            }

            projectsToShow.forEach(project => {
                const createdDate = new Date(project.created_at).toLocaleDateString();
                const projectCard = document.createElement('div');
                projectCard.className = "project-card";
                projectCard.innerHTML = `
                    <h5>${project.building_name}</h5>
                    <div class="text-muted mt-2"><?php echo __('created_at'); ?>：${createdDate}</div>
                `;
                
                // 明確添加點擊事件
                projectCard.addEventListener('click', function() {
                    console.log('Project clicked:', project.building_id);
                    loadProject(project.building_id);
                });
                
                projectList.appendChild(projectCard);
            });
        }

        function renderPagination() {
            const paginationDiv = document.getElementById('pagination');
            paginationDiv.innerHTML = '';
            const totalPages = Math.ceil(projectsData.length / itemsPerPage);

            function createPageButton(page, text = page) {
                const button = document.createElement('button');
                button.textContent = text;
                
                // 為所有按鈕設置基本樣式
                button.style.backgroundColor = page === currentPage ? '#769a76' : '#fff';
                button.style.color = page === currentPage ? '#fff' : '#333';
                
                if (typeof page === 'number') {
                    button.addEventListener('click', () => {
                        currentPage = page;
                        renderProjects();
                        renderPagination();
                    });
                }
                return button;
            }

            // 上一頁按鈕
            const prevButton = createPageButton(currentPage - 1, '<?php echo __("previous_page"); ?>');
            prevButton.disabled = currentPage === 1;
            prevButton.style.backgroundColor = currentPage === 1 ? '#f0f0f0' : '#fff';
            prevButton.style.color = currentPage === 1 ? '#999' : '#333';
            paginationDiv.appendChild(prevButton);

            // 分頁按鈕
            if (totalPages <= 5) {
                for (let i = 1; i <= totalPages; i++) {
                    paginationDiv.appendChild(createPageButton(i));
                }
            } else {
                paginationDiv.appendChild(createPageButton(1));
                
                if (currentPage > 3) {
                    const ellipsis = document.createElement('span');
                    ellipsis.textContent = '...';
                    ellipsis.className = 'px-2';
                    paginationDiv.appendChild(ellipsis);
                }

                // 當前頁碼附近的按鈕
                for (let i = Math.max(2, currentPage - 1); i <= Math.min(currentPage + 1, totalPages - 1); i++) {
                    paginationDiv.appendChild(createPageButton(i));
                }

                if (currentPage < totalPages - 2) {
                    const ellipsis = document.createElement('span');
                    ellipsis.textContent = '...';
                    ellipsis.className = 'px-2';
                    paginationDiv.appendChild(ellipsis);
                }

                if (currentPage < totalPages) {
                    paginationDiv.appendChild(createPageButton(totalPages));
                }
            }

            // 下一頁按鈕
            const nextButton = createPageButton(currentPage + 1, '<?php echo __("next_page"); ?>');
            nextButton.disabled = currentPage === totalPages;
            nextButton.style.backgroundColor = currentPage === totalPages ? '#f0f0f0' : '#fff';
            nextButton.style.color = currentPage === totalPages ? '#999' : '#333';
            paginationDiv.appendChild(nextButton);
        }

        // 處理專案點擊事件的函數
        function loadProject(projectId) {
            // 顯示計算器內容，隱藏歷史部分
            document.getElementById('calculatorContent').style.display = 'block';
            document.getElementById('history-section').style.display = 'none';
            
            // 清除現有的計算器內容
            resetCalculator();
            
            // 顯示載入中的訊息
            const buildingContainer = document.getElementById('buildingContainer');
            buildingContainer.innerHTML = '<div class="loading">載入中...</div>';
            
            // 發送請求獲取專案數據
            fetch('greenbuildingcal-past.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=getProjectData&projectId=' + projectId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 獲取專案名稱
                    const projectName = data.projectData.project.building_name;
                    const project = data.projectData.project;
                    
                    console.log("專案載入成功: ID=" + projectId + ", 名稱=" + projectName);
                    
                    // 直接更新全局變數
                    gbdProjectInfo.id = projectId;
                    gbdProjectInfo.name = projectName;
                    currentProjectInfo.id = projectId;
                    currentProjectInfo.name = projectName;
                    
                    // 檢查是否有Speckle數據
                    if (project.speckle_project_id && project.speckle_model_id) {
                        currentProjectSpeckleData = {
                            projectId: project.speckle_project_id,
                            modelId: project.speckle_model_id
                        };
                        // 顯示3D模型按鈕
                        document.getElementById('speckleViewerBtn').style.display = 'block';
                    } else {
                        currentProjectSpeckleData = null;
                        // 隱藏3D模型按鈕
                        document.getElementById('speckleViewerBtn').style.display = 'none';
                    }
                    
                    // 設置瀏覽器會話存儲
                    sessionStorage.setItem('currentProjectId', projectId);
                    sessionStorage.setItem('currentProjectName', projectName);
                    
                    // 直接刷新導航欄顯示
                    forceUpdateNavbar(projectId, projectName);
                    
                    // 渲染專案數據
                    renderProjectData(data.projectData);
                    
                    // 更新PHP session
                    fetch('set_session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'gbd_project_id=' + projectId + '&gbd_project_name=' + encodeURIComponent(projectName)
                    })
                    .then(response => response.json())
                    .then(sessionData => {
                        if (!sessionData.success) {
                            console.warn('Session更新警告:', sessionData.message);
                        } else {
                            console.log('PHP Session成功更新');
                            // 再次強制更新導航欄
                            setTimeout(() => forceUpdateNavbar(projectId, projectName), 300);
                        }
                    })
                    .catch(err => {
                        console.error('Session更新錯誤:', err);
                    });
                } else {
                    alert(data.message || '無法載入專案資料');
                    buildingContainer.innerHTML = '<div class="error">載入失敗</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('載入資料時發生錯誤');
                buildingContainer.innerHTML = '<div class="error">載入錯誤</div>';
            });
        }

        // 重置計算器內容
        function resetCalculator() {
            const buildingContainer = document.getElementById('buildingContainer');
            // 保留第一個樓層作為模板
            const firstFloor = document.getElementById('floor1');
            buildingContainer.innerHTML = '';
            buildingContainer.appendChild(firstFloor.cloneNode(true));
        }

        // 渲染專案資料
        function renderProjectData(projectData) {
            const buildingContainer = document.getElementById('buildingContainer');
            buildingContainer.innerHTML = '';
            
            // 渲染樓層、單位和房間
            projectData.floors.forEach(floor => {
                const floorDiv = document.createElement('div');
                floorDiv.className = 'floor';
                floorDiv.id = 'floor' + floor.floor_number;
                
                const floorTitle = document.createElement('h3');
                floorTitle.innerHTML = `<span>樓層</span> ${floor.floor_number}`;
                floorDiv.appendChild(floorTitle);
                
                floor.units.forEach(unit => {
                    const unitDiv = document.createElement('div');
                    unitDiv.className = 'unit';
                    unitDiv.id = `floor${floor.floor_number}_unit${unit.unit_number}`;
                    
                    const unitTitle = document.createElement('h4');
                    unitTitle.innerHTML = `<span>單元</span> ${unit.unit_number}`;
                    unitDiv.appendChild(unitTitle);
                    
                    // 添加表頭
                    const headerRow = document.createElement('div');
                    headerRow.className = 'header-row';
                    headerRow.innerHTML = `
                        <div>房間編號</div>
                        <div>高度</div>
                        <div>長度</div>
                        <div>深度</div>
                        <div>窗戶位置</div>
                    `;
                    unitDiv.appendChild(headerRow);
                    
                    unit.rooms.forEach(room => {
                        const roomRow = document.createElement('div');
                        roomRow.className = 'room-row';
                        roomRow.id = `floor${floor.floor_number}_unit${unit.unit_number}_room${room.room_number}`;
                        
                        roomRow.innerHTML = `
                            <input type="text" value="${room.room_number || ''}" />
                            <input type="text" value="${room.height || ''}" />
                            <input type="text" value="${room.length || ''}" />
                            <input type="text" value="${room.depth || ''}" />
                            <input type="text" value="${room.window_position || ''}" />
                        `;
                        
                        unitDiv.appendChild(roomRow);
                    });
                    
                    floorDiv.appendChild(unitDiv);
                });
                
                buildingContainer.appendChild(floorDiv);
            });
        }

        // 修改你的專案列表渲染函數，添加點擊事件
        function renderProjectList(projects) {
            const projectList = document.getElementById('projectList');
            projectList.innerHTML = '';
            
            if (projects.length === 0) {
                projectList.innerHTML = '<div class="no-projects"><?php echo __('no_projects'); ?></div>';
                return;
            }
            
            projects.forEach(project => {
                const projectItem = document.createElement('div');
                projectItem.className = 'project-item';
                projectItem.innerHTML = `
                    <div class="project-name">${project.building_name}</div>
                    <div class="project-address">${project.address}</div>
                    <div class="project-date">${project.created_at}</div>
                `;
                
                // 添加點擊事件
                projectItem.addEventListener('click', () => {
                    loadProject(project.building_id);
                });
                
                projectList.appendChild(projectItem);
            });
        }

        // 加入返回列表的功能（如果需要）
        function backToList() {
            document.getElementById('history-section').style.display = 'block';
            document.getElementById('calculatorContent').style.display = 'none';
        }
        </script>

    <script defer>
        function handleCreateProject(event) {
            event.preventDefault();
            
            // 獲取表單數據
            const projectName = document.getElementById('projectName').value;
            const projectAddress = document.getElementById('projectAddress').value;

            // 切換顯示
            document.getElementById('projectCard').classList.add('hidden');
            document.getElementById('calculatorContent').classList.remove('hidden');
        }

        function handleAdd() {
            console.log('Add clicked');
            document.getElementById('modal').style.display = 'block';
        }

        function handleCopy() {
            console.log('Copy clicked');
            document.getElementById('copyModal').style.display = 'block';
        }

        function handleDelete() {
            console.log('Delete clicked');
            document.getElementById('deleteModal').style.display = 'block';
        }

        function handleSave() {
            console.log('Save clicked');
        }

        function handleCalculate() {
            console.log('Calculate clicked');
        }
    </script>

    <script>
    document.getElementById('projectForm')?.addEventListener('submit', function(event) {
        event.preventDefault();
        
        const formData = new FormData(this);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                // 隱藏專案創建卡片
                document.getElementById('projectCard').classList.add('hidden');
                // 顯示計算器內容
                document.getElementById('calculatorContent').classList.remove('hidden');
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('創建專案時發生錯誤，請稍後再試');
        });
    });
    </script>

    <script>
        let floorCount = 1;
        let maxFloorNumber = 1;  // 新增這行
        let unitCounts = { 'floor1': 1 };
        let roomCounts = { 'floor1_unit1': 1 };
        let deletedFloors = [];
        let deletedUnits = {};
        let deletedRooms = {};

        function showModal() {
            document.getElementById('modal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('modal').style.display = 'none';
            hideAllSubModals();
        }

        function showDeleteModal() {
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            hideAllSubModals();
        }

        function showCopyModal() {
            document.getElementById('copyModal').style.display = 'block';
        }

        function closeCopyModal() {
            document.getElementById('copyModal').style.display = 'none';
            hideAllSubModals();
        }

        function showAddFloor() {
            hideAllSubModals();
            document.getElementById('addFloorContent').style.display = 'block';
        }

        function showAddUnit() {
            hideAllSubModals();
            const select = document.getElementById('unitFloorSelect');
            select.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                select.appendChild(option);
            });
            updateUnitNumber();
            document.getElementById('addUnitContent').style.display = 'block';
        }

        function updateUnitNumber() {
            const floorId = document.getElementById('unitFloorSelect').value;
            const unitNumber = document.getElementById('unitNumber');
            unitNumber.value = (unitCounts[floorId] || 0) + 1;
        }

        function showAddRoom() {
            hideAllSubModals();
            const floorSelect = document.getElementById('roomFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option);
            });
            updateRoomUnitSelect();
            document.getElementById('addRoomContent').style.display = 'block';
        }

        function updateRoomUnitSelect() {
            const floorId = document.getElementById('roomFloorSelect').value;
            const unitSelect = document.getElementById('roomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function showDeleteFloor() {
            hideAllSubModals();
            const select = document.getElementById('deleteFloorSelect');
            select.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                select.appendChild(option);
            });
            document.getElementById('deleteFloorContent').style.display = 'block';
        }

        function showDeleteUnit() {
            hideAllSubModals();
            const floorSelect = document.getElementById('deleteUnitFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option);
            });
            updateDeleteUnitSelect();
            document.getElementById('deleteUnitContent').style.display = 'block';
        }

        function updateDeleteUnitSelect() {
            const floorId = document.getElementById('deleteUnitFloorSelect').value;
            const unitSelect = document.getElementById('deleteUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function showDeleteRoom() {
            hideAllSubModals();
            const floorSelect = document.getElementById('deleteRoomFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option);
            });
            updateDeleteRoomUnitSelect();
            document.getElementById('deleteRoomContent').style.display = 'block';
        }

        function deleteRoom() {
            const roomId = document.getElementById('deleteRoomSelect').value;
            const room = document.getElementById(roomId);
            if (room) {
                const [floorId, unitId, roomNum] = roomId.split('_');
                const unitFullId = `${floorId}_${unitId}`;
                if (!deletedRooms[unitFullId]) {
                    deletedRooms[unitFullId] = [];
                }
                deletedRooms[unitFullId].push(parseInt(roomNum.replace('room', '')));
                deletedRooms[unitFullId].sort((a, b) => a - b);
                room.remove();
                roomCounts[unitFullId]--;
                closeDeleteModal();
            } else {
                alert("Room not found.");
            }
        }

        function updateDeleteRoomUnitSelect() {
            const floorId = document.getElementById('deleteRoomFloorSelect').value;
            const unitSelect = document.getElementById('deleteRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
            updateDeleteRoomSelect();
        }

        function updateDeleteRoomSelect() {
            const unitId = document.getElementById('deleteRoomUnitSelect').value;
            const roomSelect = document.getElementById('deleteRoomSelect');
            roomSelect.innerHTML = '';
            document.querySelectorAll(`#${unitId} .room-row`).forEach(room => {
                const option = document.createElement('option');
                option.value = room.id;
                option.textContent = `Room ${room.querySelector('input').value}`;
                roomSelect.appendChild(option);
            });
        }

        function hideAllSubModals() {
            const subModals = document.querySelectorAll('.sub-modal-content');
            subModals.forEach(modal => modal.style.display = 'none');
        }

        function closeSubModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function addFloor() {
            let newFloorNum;
            if (deletedFloors.length > 0) {
                newFloorNum = deletedFloors.shift();
            } else {
                newFloorNum = maxFloorNumber + 1;
            }
            maxFloorNumber = Math.max(maxFloorNumber, newFloorNum);
            floorCount = maxFloorNumber;

            let floorDiv = `<div class="floor" id="floor${newFloorNum}">
                <h3>Floor ${newFloorNum}</h3>
                <div class="unit" id="floor${newFloorNum}_unit1">
                    <h4>Unit 1</h4>
                    <div class="header-row">
                        <div>Room Number</div>
                        <div>Height</div>
                        <div>Length</div>
                        <div>Depth</div>
                        <div>Window Position</div>
                    </div>
                    <div class="room-row" id="floor${newFloorNum}_unit1_room1">
                        <input type="text" placeholder="Room Number" value="1" />
                        <input type="text" placeholder="Height" />
                        <input type="text" placeholder="Length" />
                        <input type="text" placeholder="Depth" />
                        <input type="text" placeholder="Window Position" />
                    </div>
                </div>
            </div>`;
            document.getElementById('buildingContainer').insertAdjacentHTML('beforeend', floorDiv);
            unitCounts[`floor${newFloorNum}`] = 1;
            roomCounts[`floor${newFloorNum}_unit1`] = 1;
            closeModal();
        }

        function addUnitPrompt() {
            const floorId = document.getElementById('unitFloorSelect').value;
            const unitNumber = document.getElementById('unitNumber').value;
            if (floorId && unitNumber) {
                addUnit(floorId, parseInt(unitNumber));
                closeSubModal('addUnitContent');
            } else {
                alert("Please select a floor and enter a unit number.");
            }
        }

        function addRoomPrompt() {
            const unitId = document.getElementById('roomUnitSelect').value;
            if (unitId) {
                addRoom(unitId);
                closeSubModal('addRoomContent');
            } else {
                alert("Please select a unit.");
            }
        }

        function addUnit(floorId, unitNumber) {
            if (unitNumber <= unitCounts[floorId]) {
                alert("Unit number already exists. Please choose a higher number.");
                return;
            }
            unitCounts[floorId] = Math.max(unitCounts[floorId] || 0, unitNumber);

            let unitDiv = `<div class="unit" id="${floorId}_unit${unitNumber}">
                        <h4>Unit ${unitNumber}</h4>
                        <div class="header-row">
                            <div>Room Number</div>
                            <div>Height</div>
                            <div>Length</div>
                            <div>Depth</div>
                            <div>Window Position</div>
                        </div>
                        <div class="room-row" id="${floorId}_unit${unitNumber}_room1">
                            <input type="text" placeholder="Room Number" value="1" />
                            <input type="text" placeholder="Height" />
                            <input type="text" placeholder="Length" />
                            <input type="text" placeholder="Depth" />
                            <input type="text" placeholder="Window Position" />
                        </div>
                    </div>`;
            document.getElementById(floorId).insertAdjacentHTML('beforeend', unitDiv);
            roomCounts[`${floorId}_unit${unitNumber}`] = 1;
        }

        function addRoom(unitId) {
            let newRoomNum;
            if (deletedRooms[unitId] && deletedRooms[unitId].length > 0) {
                newRoomNum = deletedRooms[unitId].shift();
            } else {
                newRoomNum = roomCounts[unitId] + 1;
            }
            roomCounts[unitId] = Math.max(roomCounts[unitId], newRoomNum);

            let roomDiv = `<div class="room-row" id="${unitId}_room${newRoomNum}">
                        <input type="text" placeholder="Room Number" value="${newRoomNum}" />
                        <input type="text" placeholder="Height" />
                        <input type="text" placeholder="Length" />
                        <input type="text" placeholder="Depth" />
                        <input type="text" placeholder="Window Position" />
                    </div>`;
            document.getElementById(unitId).insertAdjacentHTML('beforeend', roomDiv);
        }

        function deleteFloor() {
            const floorId = document.getElementById('deleteFloorSelect').value;
            const floor = document.getElementById(floorId);
            if (floor) {
                const floorNum = parseInt(floorId.replace('floor', ''));
                deletedFloors.push(floorNum);
                deletedFloors.sort((a, b) => a - b);
                floor.remove();
                delete unitCounts[floorId];
                closeDeleteModal();
            } else {
                alert("Floor not found.");
            }
        }

        function deleteUnit() {
            const unitId = document.getElementById('deleteUnitSelect').value;
            const unit = document.getElementById(unitId);
            if (unit) {
                const [floorId, unitNum] = unitId.split('_');
                if (!deletedUnits[floorId]) {
                    deletedUnits[floorId] = [];
                }
                deletedUnits[floorId].push(parseInt(unitNum.replace('unit', '')));
                deletedUnits[floorId].sort((a, b) => a - b);
                unit.remove();
                delete roomCounts[unitId];
                closeDeleteModal();
            } else {
                alert("Unit not found.");
            }
        }

        function deleteRoom() {
            const roomId = document.getElementById('deleteRoomSelect').value;
            const room = document.getElementById(roomId);
            if (room) {
                const [floorId, unitId, roomNum] = roomId.split('_');
                const fullUnitId = `${floorId}_${unitId}`;
                if (!deletedRooms[fullUnitId]) {
                    deletedRooms[fullUnitId] = [];
                }
                deletedRooms[fullUnitId].push(parseInt(roomNum.replace('room', '')));
                deletedRooms[fullUnitId].sort((a, b) => a - b);
                room.remove();
                closeDeleteModal();
            } else {
                alert("Room not found.");
            }
        }

        function addRoom(unitId) {
            let newRoomNum;
            if (deletedRooms[unitId] && deletedRooms[unitId].length > 0) {
                newRoomNum = deletedRooms[unitId].shift();
            } else {
                newRoomNum = roomCounts[unitId] + 1;
            }
            roomCounts[unitId] = Math.max(roomCounts[unitId], newRoomNum);

            let roomDiv = `<div class="room-row" id="${unitId}_room${newRoomNum}">
                                        <input type="text" placeholder="Room Number" value="${newRoomNum}" />
                                        <input type="text" placeholder="Height" />
                                        <input type="text" placeholder="Length" />
                                        <input type="text" placeholder="Depth" />
                                        <input type="text" placeholder="Window Position" />
                                    </div>`;
            document.getElementById(unitId).insertAdjacentHTML('beforeend', roomDiv);
        }

        // 新增複製相關功能
        function showCopyModal() {
            document.getElementById('copyModal').style.display = 'block';
        }

        function closeCopyModal() {
            document.getElementById('copyModal').style.display = 'none';
            hideAllSubModals();
        }

        function showCopyFloor() {
            hideAllSubModals();
            const select = document.getElementById('sourceFloorSelect');
            select.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                select.appendChild(option);
            });
            document.getElementById('copyFloorContent').style.display = 'block';
        }

        function showCopyUnit() {
            hideAllSubModals();
            const floorSelect = document.getElementById('sourceUnitFloorSelect');
            const targetFloorSelect = document.getElementById('targetUnitFloorSelect');
            floorSelect.innerHTML = '';
            targetFloorSelect.innerHTML = '';

            document.querySelectorAll('.floor').forEach(floor => {
                const option1 = document.createElement('option');
                const option2 = document.createElement('option');
                option1.value = option2.value = floor.id;
                option1.textContent = option2.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option1);
                targetFloorSelect.appendChild(option2);
            });

            updateSourceUnitSelect();
            document.getElementById('copyUnitContent').style.display = 'block';
        }

        function showCopyRoom() {
            hideAllSubModals();
            const sourceFloorSelect = document.getElementById('sourceRoomFloorSelect');
            const targetFloorSelect = document.getElementById('targetRoomFloorSelect');
            sourceFloorSelect.innerHTML = '';
            targetFloorSelect.innerHTML = '';

            document.querySelectorAll('.floor').forEach(floor => {
                const option1 = document.createElement('option');
                const option2 = document.createElement('option');
                option1.value = option2.value = floor.id;
                option1.textContent = option2.textContent = floor.querySelector('h3').textContent;
                sourceFloorSelect.appendChild(option1);
                targetFloorSelect.appendChild(option2);
            });

            updateSourceRoomUnitSelect();
            updateTargetRoomUnitSelect();
            document.getElementById('copyRoomContent').style.display = 'block';
        }

        function updateSourceUnitSelect() {
            const floorId = document.getElementById('sourceUnitFloorSelect').value;
            const unitSelect = document.getElementById('sourceUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function updateSourceRoomUnitSelect() {
            const floorId = document.getElementById('sourceRoomFloorSelect').value;
            const unitSelect = document.getElementById('sourceRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
            updateSourceRoomSelect();
        }

        function updateTargetRoomUnitSelect() {
            const floorId = document.getElementById('targetRoomFloorSelect').value;
            const unitSelect = document.getElementById('targetRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function updateSourceRoomSelect() {
            const unitId = document.getElementById('sourceRoomUnitSelect').value;
            const roomSelect = document.getElementById('sourceRoomSelect');
            roomSelect.innerHTML = '';
            document.querySelectorAll(`#${unitId} .room-row`).forEach(room => {
                const option = document.createElement('option');
                option.value = room.id;
                option.textContent = `Room ${room.querySelector('input').value}`;
                roomSelect.appendChild(option);
            });
        }

        function copyFloor() {
            const sourceFloorId = document.getElementById('sourceFloorSelect').value;
            const targetFloorNum = parseInt(document.getElementById('targetFloorNumber').value);

            if (!sourceFloorId || !targetFloorNum) {
                alert("Please select source floor and target floor number.");
                return;
            }

            const sourceFloor = document.getElementById(sourceFloorId);
            const newFloorId = `floor${targetFloorNum}`;

            // 檢查目標樓層是否已存在
            if (document.getElementById(newFloorId)) {
                alert("Target floor already exists. Please choose a different number.");
                return;
            }

            // 創建新樓層並複製內容
            const newFloor = sourceFloor.cloneNode(true);
            newFloor.id = newFloorId;
            newFloor.querySelector('h3').textContent = `Floor ${targetFloorNum}`;

            // 更新單元和房間的 ID
            newFloor.querySelectorAll('.unit').forEach((unit, unitIndex) => {
                const originalUnitNum = unit.id.split('_unit')[1];
                const newUnitId = `${newFloorId}_unit${originalUnitNum}`;
                unit.id = newUnitId;
                unitCounts[newFloorId] = Math.max(unitCounts[newFloorId] || 0, parseInt(originalUnitNum));

                unit.querySelectorAll('.room-row').forEach((room, roomIndex) => {
                    const originalRoomNum = room.id.split('_room')[1];
                    room.id = `${newUnitId}_room${originalRoomNum}`;
                    if (!roomCounts[newUnitId]) {
                        roomCounts[newUnitId] = 0;
                    }
                    roomCounts[newUnitId] = Math.max(roomCounts[newUnitId], parseInt(originalRoomNum));
                });
            });

            document.getElementById('buildingContainer').appendChild(newFloor);
            maxFloorNumber = Math.max(maxFloorNumber, targetFloorNum);
            floorCount = maxFloorNumber;
            closeCopyModal();
        }

        function copyUnit() {
            const sourceUnitId = document.getElementById('sourceUnitSelect').value;
            const targetFloorId = document.getElementById('targetUnitFloorSelect').value;
            const targetUnitNum = parseInt(document.getElementById('targetUnitNumber').value);

            if (!sourceUnitId || !targetFloorId || !targetUnitNum) {
                alert("Please fill in all required fields.");
                return;
            }

            const targetUnitId = `${targetFloorId}_unit${targetUnitNum}`;

            // 檢查目標單元是否已存在
            if (document.getElementById(targetUnitId)) {
                alert("Target unit already exists. Please choose a different number.");
                return;
            }

            const sourceUnit = document.getElementById(sourceUnitId);
            const newUnit = sourceUnit.cloneNode(true);
            newUnit.id = targetUnitId;
            newUnit.querySelector('h4').textContent = `Unit ${targetUnitNum}`;

            // 更新房間的 ID
            newUnit.querySelectorAll('.room-row').forEach((room) => {
                const originalRoomNum = room.id.split('_room')[1];
                room.id = `${targetUnitId}_room${originalRoomNum}`;
            });

            // 更新計數器
            unitCounts[targetFloorId] = Math.max(unitCounts[targetFloorId] || 0, targetUnitNum);
            roomCounts[targetUnitId] = sourceUnit.querySelectorAll('.room-row').length;

            document.getElementById(targetFloorId).appendChild(newUnit);
            closeCopyModal();
        }

        function copyRoom() {
            const sourceRoomId = document.getElementById('sourceRoomSelect').value;
            const targetUnitId = document.getElementById('targetRoomUnitSelect').value;

            if (!sourceRoomId || !targetUnitId) {
                alert("Please fill in all required fields.");
                return;
            }

            // 获取源房間
            const sourceRoom = document.getElementById(sourceRoomId);
            if (!sourceRoom) {
                alert("Source room not found.");
                return;
            }

            // 獲取目標單元中的房間數量，用於生成新房間號碼
            let newRoomNum;
            if (deletedRooms[targetUnitId] && deletedRooms[targetUnitId].length > 0) {
                newRoomNum = deletedRooms[targetUnitId].shift();
            } else {
                newRoomNum = (roomCounts[targetUnitId] || 0) + 1;
            }

            // 創建新房間
            const newRoom = sourceRoom.cloneNode(true);
            newRoom.id = `${targetUnitId}_room${newRoomNum}`;

            // 複製所有輸入值
            const sourceInputs = sourceRoom.querySelectorAll('input');
            const newInputs = newRoom.querySelectorAll('input');

            // 更新房間號碼，保持其他值不變
            newInputs[0].value = newRoomNum;
            for (let i = 1; i < sourceInputs.length; i++) {
                newInputs[i].value = sourceInputs[i].value;
            }

            // 將新房間添加到目標單元
            document.getElementById(targetUnitId).appendChild(newRoom);

            // 更新房間計數
            roomCounts[targetUnitId] = Math.max(roomCounts[targetUnitId] || 0, newRoomNum);

            closeCopyModal();
        }

        function handleSave() {
            // 檢查是否有空欄位
            let hasEmptyFields = false;
            const inputs = document.querySelectorAll('#buildingContainer input[type="text"]');
            inputs.forEach(input => {
                if (input.value.trim() === '') {
                    hasEmptyFields = true;
                }
            });
            
            // 如果有空欄位，先確認
            if (hasEmptyFields) {
                if (!confirm('部分內容尚未填寫完成，是否要繼續儲存？')) {
                    return; // 用戶選擇不儲存，直接返回
                }
            }
            
        // Create the data structure
        const buildingData = {
            floors: {}
        };
        
        // Get all floors
        const floors = document.querySelectorAll('#buildingContainer .floor');
        
        floors.forEach(floor => {
            const floorId = floor.id;
            buildingData.floors[floorId] = {
            units: {}
            };
            
            // Get all units in this floor
            const units = floor.querySelectorAll('.unit');
            units.forEach(unit => {
            const unitId = unit.id;
            buildingData.floors[floorId].units[unitId] = {
                rooms: {}
            };
            
            // Get all rooms in this unit
            const rooms = unit.querySelectorAll('.room-row');
            rooms.forEach(room => {
                const roomId = room.id;
                const inputs = room.querySelectorAll('input');
                
                buildingData.floors[floorId].units[unitId].rooms[roomId] = {
                    roomNumber: inputs[0].value,
                    height: inputs[1].value.trim() !== '' ? inputs[1].value : null,
                    length: inputs[2].value.trim() !== '' ? inputs[2].value : null,
                    depth: inputs[3].value.trim() !== '' ? inputs[3].value : null,
                    windowPosition: inputs[4].value
                };
            });
            });
        });
        
        // Send to server via AJAX
        fetch('greenbuildingcal-past.php?action=saveBuildingData', {
            method: 'POST',
            headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(buildingData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
            alert('資料儲存成功！');
            } else {
            alert('儲存失敗：' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('發生錯誤，請檢查控制台');
        });
        }

        function save() {
            const buildingData = {
                floors: {},
                unitCounts: unitCounts,
                roomCounts: roomCounts,
                deletedFloors: deletedFloors,
                deletedUnits: deletedUnits,
                deletedRooms: deletedRooms
            };

            document.querySelectorAll('.floor').forEach(floor => {
                const floorId = floor.id;
                buildingData.floors[floorId] = {
                    units: {}
                };

                floor.querySelectorAll('.unit').forEach(unit => {
                    const unitId = unit.id;
                    buildingData.floors[floorId].units[unitId] = {
                        rooms: {}
                    };

                    unit.querySelectorAll('.room-row').forEach(room => {
                        const roomId = room.id;
                        const inputs = room.querySelectorAll('input');
                        buildingData.floors[floorId].units[unitId].rooms[roomId] = {
                            roomNumber: inputs[0].value,
                            height: inputs[1].value,
                            length: inputs[2].value,
                            depth: inputs[3].value,
                            windowPosition: inputs[4].value
                        };
                    });
                });
            });

            // 將 buildingData 轉換為 Excel 格式
            const wb = XLSX.utils.book_new();  // 創建一個新的工作簿

            Object.keys(buildingData.floors).forEach(floorId => {
                const ws_data = [['Unit', 'Room Number', 'Height', 'Length', 'Depth', 'Window Position']];
                const floor = buildingData.floors[floorId];

                Object.keys(floor.units).forEach(unitId => {
                    const unit = floor.units[unitId];
                    Object.keys(unit.rooms).forEach(roomId => {
                        const room = unit.rooms[roomId];
                        ws_data.push([unitId, room.roomNumber, room.height, room.length, room.depth, room.windowPosition]);
                    });
                });

                // 創建新的工作表
                const ws = XLSX.utils.aoa_to_sheet(ws_data);
                XLSX.utils.book_append_sheet(wb, ws, floorId);  // 將工作表添加到工作簿中
            });

            // 觸發 Excel 檔案下載
            XLSX.writeFile(wb, 'buildingData.xlsx');

            alert('Data saved as Excel file!');
        }

        // 用於初始化時加載保存的數據
        function loadSavedData() {
            // 每次載入時清除本地儲存的資料，保證重新開始
            localStorage.removeItem('buildingData');

            // 建立預設的樓層、單元和房間
            const container = document.getElementById('buildingContainer');
            container.innerHTML = ''; // 清除容器內容

            // 創建預設的 floor1, unit1 和 room1
            const floorDiv = createFloorElement('floor1');
            const unitDiv = createUnitElement('floor1_unit1');
            const roomDiv = createRoomElement('floor1_unit1_room1', {
                roomNumber: '1',
                height: '',
                length: '',
                depth: '',
                windowPosition: ''
            });

            // 將它們添加到 DOM
            unitDiv.appendChild(roomDiv);
            floorDiv.appendChild(unitDiv);
            container.appendChild(floorDiv);
        }


        function createFloorElement(floorId) {
            const floorNum = floorId.replace('floor', '');
            const floorDiv = document.createElement('div');
            floorDiv.className = 'floor';
            floorDiv.id = floorId;
            floorDiv.innerHTML = `<h3>Floor ${floorNum}</h3>`;
            return floorDiv;
        }

        function createUnitElement(unitId) {
            const unitNum = unitId.split('_unit')[1];
            const unitDiv = document.createElement('div');
            unitDiv.className = 'unit';
            unitDiv.id = unitId;
            unitDiv.innerHTML = `
                        <h4>Unit ${unitNum}</h4>
                        <div class="header-row">
                            <div>Room Number</div>
                            <div>Height</div>
                            <div>Length</div>
                            <div>Depth</div>
                            <div>Window Position</div>
                        </div>
                    `;
            return unitDiv;
        }

        function createRoomElement(roomId, roomData) {
            const roomDiv = document.createElement('div');
            roomDiv.className = 'room-row';
            roomDiv.id = roomId;
            roomDiv.innerHTML = `
                        <input type="text" placeholder="Room Number" value="${roomData.roomNumber}" />
                        <input type="text" placeholder="Height" value="${roomData.height}" />
                        <input type="text" placeholder="Length" value="${roomData.length}" />
                        <input type="text" placeholder="Depth" value="${roomData.depth}" />
                        <input type="text" placeholder="Window Position" value="${roomData.windowPosition}" />
                    `;
            return roomDiv;
        }

        // 頁面加載時初始化數據
        document.addEventListener('DOMContentLoaded', function () {
            loadSavedData();
        });

        function calculate() {
            let totalHeight = 0;
            let totalLength = 0;
            let totalDepth = 0;

            // 遍歷每個樓層
            document.querySelectorAll('.floor').forEach(floor => {
                // 遍歷每個單元
                floor.querySelectorAll('.unit').forEach(unit => {
                    // 遍歷每個房間
                    unit.querySelectorAll('.room-row').forEach(room => {
                        const height = parseFloat(room.querySelector('input[placeholder="Height"]').value);
                        const length = parseFloat(room.querySelector('input[placeholder="Length"]').value);
                        const depth = parseFloat(room.querySelector('input[placeholder="Depth"]').value);

                        // 累加總和
                        totalHeight += isNaN(height) ? 0 : height;
                        totalLength += isNaN(length) ? 0 : length;
                        totalDepth += isNaN(depth) ? 0 : depth;
                    });
                });
            });

            // 顯示結果
            const result = `總高度: ${totalHeight}\n總長度: ${totalLength}\n總深度: ${totalDepth}`;
            showResultModal(result);
        }

        function showResultModal(result) {
            const modal = document.createElement('div');
            modal.style.position = 'fixed';
            modal.style.top = '50%';
            modal.style.left = '50%';
            modal.style.transform = 'translate(-50%, -50%)';
            modal.style.backgroundColor = 'white';
            modal.style.padding = '20px';
            modal.style.boxShadow = '0 0 10px rgba(0, 0, 0, 0.5)';
            modal.style.zIndex = '1000';

            // 設置視窗的寬度和高度
            modal.style.width = '400px'; // 您可以根據需要調整這裡的值
            modal.style.height = 'auto';  // 高度自動根據內容調整
            modal.style.overflowY = 'auto'; // 若內容過多可滾動

            // 增加圓弧
            modal.style.borderRadius = '10px'; // 調整這裡的值來改變圓弧的大小

            const modalContent = document.createElement('div'); // 使用 div 來包裹內容
            modalContent.style.textAlign = 'center'; // 文字置中
            modalContent.style.marginBottom = '10px'; // 加入一些底部邊距
            modalContent.style.fontSize = '22px'; // 設置字體大小，您可以根據需求調整這裡的值
            modalContent.textContent = result; // 將計算結果設置為內容
            modal.appendChild(modalContent);

            const closeButtonContainer = document.createElement('div');
            closeButtonContainer.style.display = 'flex'; // 使用 flex 排版
            closeButtonContainer.style.justifyContent = 'center'; // 使按鈕置中對齊

            const closeButton = document.createElement('button');
            closeButton.textContent = '關閉';
            closeButton.onclick = () => {
                document.body.removeChild(modal);
            };

            closeButtonContainer.appendChild(closeButton);
            modal.appendChild(closeButtonContainer);

            document.body.appendChild(modal);
        } 

    </script>
    
    <!-- 先加載翻譯文件 -->
    <script src="GBS_js/translations.js"></script>
    <!-- 後加載 i18n 類 -->
    <script src="GBS_js/i18n.js"></script>
    
    <script>
        // 為了同步 navbar 和頁面的語言切換
        window.addEventListener('storage', function(e) {
            if (e.key === 'language') {
                window.location.reload();
            }
        });

        // 當頁面加載完成時，更新所有翻譯
        document.addEventListener('DOMContentLoaded', function() {
            updatePageTranslations();
        });

        function updatePageTranslations() {
            const elements = document.querySelectorAll('[data-i18n]');
            const currentLang = localStorage.getItem('language') || 'zh-TW';
            
            elements.forEach(element => {
                const key = element.getAttribute('data-i18n');
                if (translations[currentLang][key]) {
                    element.textContent = translations[currentLang][key];
                }
            });

            // 更新 placeholder 翻譯
            const placeholders = document.querySelectorAll('[data-i18n-placeholder]');
            placeholders.forEach(element => {
                const key = element.getAttribute('data-i18n-placeholder');
                if (translations[currentLang][key]) {
                    element.placeholder = translations[currentLang][key];
                }
            });
        }
    </script>
    </div>
</body>
</html>