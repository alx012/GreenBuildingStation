<?php
/****************************************************************************
 * [0] 開啟 Session，方便累積篩選條件
 * 啟動伺服器：php -S localhost:8000
 * http://localhost:8000/index.php
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
require_once 'db_connection.php';

// 錯誤報告設定 - 只記錄到日誌，不直接顯示
ini_set('display_errors', 0); // 關閉錯誤顯示，避免污染 JSON 響應
ini_set('log_errors', 1); // 啟用錯誤日誌
error_reporting(E_ALL);

/****************************************************************************
 * [2] 處理 AJAX 請求
 ****************************************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (isset($data['action'])) {
            if ($data['action'] === 'saveBuildingData') {
                // handleSaveBuildingData reads from php://input itself.
                handleSaveBuildingData();
                exit;
            }
        }
    }

    // 檢查是否是 AJAX 請求
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    if (isset($_POST['action']) && $_POST['action'] === 'createProject') {
        handleCreateProject();
        exit;
    } elseif (isset($_REQUEST['action']) && $_REQUEST['action'] === 'saveBuildingData') {
        handleSaveBuildingData();
        exit;
    } elseif (isset($_POST['projectName']) && isset($_POST['projectAddress'])) {
        // 相容舊的提交方式，轉發到統一的處理函數
        $_POST['action'] = 'createProject';
        handleCreateProject();
        exit;
    } elseif ($isAjax && isset($_POST['action']) && $_POST['action'] === 'checkProjectHasData') {
        // 新增的檢查專案資料功能
        checkProjectHasData();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'getSpeckleProjects') {
        handleGetSpeckleProjects();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'importSpeckleModel') {
        handleImportSpeckleModel();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'saveSpeckleData') {
        handleSaveSpeckleData();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'analyzeSpeckleModel') {
        handleAnalyzeSpeckleModel();
        exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'analyzeFloorplan') {
        handleFloorplanUpload();
        exit;
    }

    if ($isAjax && isset($_POST['action']) && $_POST['action'] === 'saveDrawingData') {
        handleSaveDrawingData();
        exit;
    } elseif (
        $isAjax && isset($_SERVER['CONTENT_TYPE']) &&
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
    ) {
        handleSaveDrawingData();
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'loadProjectData') {
    handleLoadProjectData();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'getProjectInfo') {
    handleGetProjectInfo();
    exit;
}

/****************************************************************************
 * [3] 處理函數
 ****************************************************************************/
function handleLoadProjectData()
{
    global $serverName, $database, $username, $password;

    header('Content-Type: application/json');

    if (!isset($_SESSION['building_id']) || empty($_SESSION['building_id'])) {
        echo json_encode(['success' => false, 'message' => 'No project selected in session.']);
        return;
    }

    $projectId = $_SESSION['building_id'];

    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 1. Fetch project info
        $projectStmt = $conn->prepare("SELECT * FROM [Test].[dbo].[GBD_Project] WHERE building_id = :building_id");
        $projectStmt->execute([':building_id' => $projectId]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Fetch all floors, units, and rooms (walls) in a more efficient way
        $sql = "
            SELECT 
                f.floor_id, f.floor_number,
                u.unit_id, u.unit_number,
                r.room_id, r.room_number, r.wall_orientation, r.wall_area, r.window_position, r.window_area
            FROM [Test].[dbo].[GBD_Project_floors] f
            LEFT JOIN [Test].[dbo].[GBD_Project_units] u ON f.floor_id = u.floor_id
            LEFT JOIN [Test].[dbo].[GBD_Project_rooms] r ON u.unit_id = r.unit_id
            WHERE f.building_id = :building_id
            ORDER BY f.floor_number, u.unit_number, r.room_number, r.room_id
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':building_id' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $projectData = [
            'project' => $project,
            'floors' => []
        ];
        
        $floorsMap = [];

        foreach ($rows as $row) {
            $floorNumber = $row['floor_number'];
            $unitNumber = $row['unit_number'];
            $roomNumber = $row['room_number'];

            if (!isset($floorsMap[$floorNumber])) {
                $floorsMap[$floorNumber] = [
                    'floor_id' => $row['floor_id'],
                    'floor_number' => $floorNumber,
                    'units' => []
                ];
            }
            
            if ($unitNumber && !isset($floorsMap[$floorNumber]['units'][$unitNumber])) {
                 $floorsMap[$floorNumber]['units'][$unitNumber] = [
                    'unit_id' => $row['unit_id'],
                    'unit_number' => $unitNumber,
                    'rooms' => []
                ];
            }

            if ($roomNumber && !isset($floorsMap[$floorNumber]['units'][$unitNumber]['rooms'][$roomNumber])) {
                $floorsMap[$floorNumber]['units'][$unitNumber]['rooms'][$roomNumber] = [
                    'room_number' => $roomNumber,
                    'walls' => []
                ];
            }

            if ($row['room_id']) {
                 $floorsMap[$floorNumber]['units'][$unitNumber]['rooms'][$roomNumber]['walls'][] = [
                    'wall_orientation' => $row['wall_orientation'],
                    'wall_area' => $row['wall_area'],
                    'window_position' => $row['window_position'],
                    'window_area' => $row['window_area']
                ];
            }
        }

        // Convert maps to arrays for JSON output
        foreach($floorsMap as &$floor) {
            $floor['units'] = array_values($floor['units']);
            foreach($floor['units'] as &$unit) {
                $unit['rooms'] = array_values($unit['rooms']);
            }
        }
        $projectData['floors'] = array_values($floorsMap);


        echo json_encode(['success' => true, 'data' => $projectData]);

    } catch (PDOException $e) {
        error_log("DB Error in handleLoadProjectData: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '資料庫讀取錯誤: ' . $e->getMessage()]);
    }
}

// 根據 building_id 獲取專案資訊的函數
function getProjectInfo($building_id)
{
    global $serverName, $database, $username, $password;
    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "SELECT building_name, building_angle FROM [Test].[dbo].[GBD_Project] WHERE building_id = :building_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':building_id' => $building_id]);

        $projectInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        return $projectInfo;
    } catch (PDOException $e) {
        // 在生產環境中，應記錄錯誤而不是直接輸出
        error_log("獲取專案資訊失敗: " . $e->getMessage());
        return null;
    }
}

// 創建專案的處理函數
function handleCreateProject()
{
    global $serverName, $database, $username, $password;

    // 獲取POST數據
    $projectName = trim($_POST['projectName'] ?? '');
    $projectAddress = trim($_POST['projectAddress'] ?? '');
    $projectType = $_POST['type'] ?? ''; // 獲取專案類型
    $inputMethod = $_POST['inputMethod'] ?? 'table'; // 獲取輸入方法

    // 新增: 獲取建築方位資訊
    $buildingAngle = isset($_POST['buildingAngle']) ? floatval($_POST['buildingAngle']) : null;
    $orientationText = '';

    // 根據角度確定方位文字
    if ($buildingAngle !== null) {
        if ($buildingAngle >= 337.5 || $buildingAngle < 22.5) {
            $orientationText = 'N';
        } elseif ($buildingAngle >= 22.5 && $buildingAngle < 67.5) {
            $orientationText = 'NE';
        } elseif ($buildingAngle >= 67.5 && $buildingAngle < 112.5) {
            $orientationText = 'E';
        } elseif ($buildingAngle >= 112.5 && $buildingAngle < 157.5) {
            $orientationText = 'SE';
        } elseif ($buildingAngle >= 157.5 && $buildingAngle < 202.5) {
            $orientationText = 'S';
        } elseif ($buildingAngle >= 202.5 && $buildingAngle < 247.5) {
            $orientationText = 'SW';
        } elseif ($buildingAngle >= 247.5 && $buildingAngle < 292.5) {
            $orientationText = 'W';
        } elseif ($buildingAngle >= 292.5 && $buildingAngle < 337.5) {
            $orientationText = 'NW';
        }
    }

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
                // 準備 SQL 語句 - 修改加入方位和輸入方法
                $sql = "INSERT INTO [Test].[dbo].[GBD_Project] 
(building_name, address, UserID, created_at, updated_at, 
building_angle, building_orientation, input_method) 
VALUES (:building_name, :address, :UserID, GETDATE(), GETDATE(),
:building_angle, :building_orientation, :input_method)";

                $stmt = $conn->prepare($sql);

                // 執行 SQL
                $stmt->execute([
                    ':building_name' => $projectName,
                    ':address' => $projectAddress,
                    ':UserID' => $_SESSION['user_id'],
                    ':building_angle' => $buildingAngle,
                    ':building_orientation' => $orientationText,
                    ':input_method' => $inputMethod
                ]);

                // 獲取最後插入的 ID
                $building_id = $conn->lastInsertId();

                // 通用ID，保留
                $_SESSION['building_id'] = $building_id;

                // 更新當前專案的通用變數
                $_SESSION['current_gbd_project_id'] = $building_id;
                $_SESSION['current_gbd_project_name'] = $projectName;

                // 只有在綠建築頁面創建專案時，才更新綠建築專案變數
                if ($projectType == 'green' || strpos($_SERVER['PHP_SELF'], 'greenbuildingcal') !== false) {
                    $_SESSION['gbd_project_id'] = $building_id;
                    $_SESSION['gbd_project_name'] = $projectName;
                    error_log("設置綠建築專案: ID={$building_id}, 名稱={$projectName}");
                }

                $response = [
                    'success' => true,
                    'building_id' => $building_id,
                    'message' => '專案創建成功'
                ];
            }
        } catch (PDOException $e) {
            error_log("DB Error in handleCreateProject: " . $e->getMessage());
            $response = [
                'success' => false,
                'message' => '創建專案時發生錯誤: ' . $e->getMessage()
            ];
        }
    }

    if ($response['success']) {
        // 確保session變數已設置，使用特定於綠建築的變數
        $_SESSION['building_id'] = $response['building_id'];
        $_SESSION['gbd_project_id'] = $response['building_id'];
        $_SESSION['gbd_project_name'] = $projectName;

        // 同時更新通用變數
        $_SESSION['current_gbd_project_id'] = $response['building_id'];
        $_SESSION['current_gbd_project_name'] = $projectName;

        // 記入日誌以便調試
        error_log("專案創建成功: ID={$response['building_id']}, 名稱={$projectName}");
        error_log("SESSION設置: gbd_project_id={$response['building_id']}, gbd_name={$projectName}");
    }

    // 如果是 AJAX 請求，返回 JSON
    if (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
    ) {
        header('Content-Type: application/json');

        // 添加輸入方法到回應中，以便前端可以據此進行跳轉
        if ($response['success']) {
            $response['inputMethod'] = $inputMethod;
        }

        echo json_encode($response);
    } else {
        // 非 AJAX 請求，可以重定向
        if ($response['success']) {
            // 根據輸入方法決定跳轉頁面
            if ($inputMethod == 'speckle') {
                header('Location: building-speckle-import.php?building_id=' . $response['building_id']);
                exit;
            } else {
                // 其他輸入方法保持原有流程
                // header('Location: project_page.php');
            }
        }
    }
}

// 儲存建築數據的處理函數
function handleSaveBuildingData()
{
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

        // ---- START OF FIX: Clear existing data before saving ----
        // 1. Delete rooms
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

        // 2. Delete units
        $stmtClearUnits = $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_units]
            WHERE floor_id IN (
                SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors]
                WHERE building_id = :building_id
            )
        ");
        $stmtClearUnits->execute([':building_id' => $building_id]);

        // 3. Delete floors
        $stmtClearFloors = $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_floors]
            WHERE building_id = :building_id
        ");
        $stmtClearFloors->execute([':building_id' => $building_id]);
        // ---- END OF FIX ----

        // 插入樓層的 SQL
        $stmtFloor = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_floors] (building_id, floor_number, created_at) VALUES (:building_id, :floor_number, GETDATE())");

        // 插入單元的 SQL
        $stmtUnit = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_units] (floor_id, unit_number, created_at) VALUES (:floor_id, :unit_number, GETDATE())");

        // 修改插入房間的 SQL - 現在一筆紀錄代表一個牆面
        $stmtWall = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_rooms] 
(unit_id, room_number, wall_orientation, wall_area, window_position, window_area, created_at, updated_at) 
VALUES (:unit_id, :room_number, :wall_orientation, :wall_area, :window_position, :window_area, GETDATE(), GETDATE())");

        // 依照前端傳來的資料格式進行存入
        foreach ($data['floors'] as $floorId => $floor) {
            $floor_number = intval(str_replace('floor', '', $floorId));

            $stmtFloor->execute([
                ':building_id' => $building_id,
                ':floor_number' => $floor_number
            ]);
            $floor_id = $conn->lastInsertId();

            if (isset($floor['units']) && is_array($floor['units'])) {
                foreach ($floor['units'] as $unitId => $unit) {
                    $parts = explode('_', $unitId);
                    $unit_number = isset($parts[1]) ? intval(str_replace('unit', '', $parts[1])) : 1;

                    $stmtUnit->execute([
                        ':floor_id' => $floor_id,
                        ':unit_number' => $unit_number
                    ]);
                    $unit_id = $conn->lastInsertId();

                    if (isset($unit['rooms']) && is_array($unit['rooms'])) {
                        foreach ($unit['rooms'] as $roomId => $room) {
                            $roomNumber = $room['roomNumber'];

                            if (isset($room['walls']) && is_array($room['walls'])) {
                                foreach ($room['walls'] as $wall) {
                                    $wallOrientation = !empty($wall['wallOrientation']) ? $wall['wallOrientation'] : '';
                                    $wallArea = !empty($wall['wallArea']) ? $wall['wallArea'] : null;
                                    $windowPosition = !empty($wall['windowPosition']) ? $wall['windowPosition'] : '';
                                    $windowArea = !empty($wall['windowArea']) ? $wall['windowArea'] : null;

                                    $stmtWall->execute([
                                        ':unit_id' => $unit_id,
                                        ':room_number' => $roomNumber,
                                        ':wall_orientation' => $wallOrientation,
                                        ':wall_area' => $wallArea,
                                        ':window_position' => $windowPosition,
                                        ':window_area' => $windowArea
                                    ]);
                                }
                            }
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

// 處理繪圖輸入資料保存
function handleSaveDrawingData()
{
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

    // 取得 POST 資料（可能是 JSON）
    $inputData = file_get_contents('php://input');
    $data = json_decode($inputData, true);

    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $building_id = $_SESSION['building_id'];

        // 開始事務
        $conn->beginTransaction();

        // 詳細的刪除邏輯，並加入錯誤檢查和日誌
        $deleteQueries = [
            "rooms" => "DELETE FROM [Test].[dbo].[GBD_Project_rooms] 
WHERE unit_id IN (
SELECT unit_id 
FROM [Test].[dbo].[GBD_Project_units] 
WHERE floor_id IN (
SELECT floor_id 
FROM [Test].[dbo].[GBD_Project_floors] 
WHERE building_id = :building_id
)
)",

            "units" => "DELETE FROM [Test].[dbo].[GBD_Project_units] 
WHERE floor_id IN (
SELECT floor_id 
FROM [Test].[dbo].[GBD_Project_floors] 
WHERE building_id = :building_id
)",

            "floors" => "DELETE FROM [Test].[dbo].[GBD_Project_floors] 
WHERE building_id = :building_id"
        ];

        // 執行刪除並記錄刪除數量
        $deletedCounts = [];
        foreach ($deleteQueries as $table => $query) {
            $stmt = $conn->prepare($query);
            $stmt->execute([':building_id' => $building_id]);
            $deletedCounts[$table] = $stmt->rowCount();

            // 記錄刪除日誌
            error_log("Deleted {$deletedCounts[$table]} records from {$table} for building_id {$building_id}");
        }

        // 插入樓層的 SQL
        $stmtFloor = $conn->prepare("
INSERT INTO [Test].[dbo].[GBD_Project_floors] 
(building_id, floor_number, created_at, Area, Height, Coordinates) 
VALUES (:building_id, :floor_number, GETDATE(), :area, :height, :coordinates)
");

        // 插入單位的 SQL
        $stmtUnit = $conn->prepare("
INSERT INTO [Test].[dbo].[GBD_Project_units] 
(floor_id, unit_number, created_at, Area, Height, Coordinates) 
VALUES (:floor_id, :unit_number, GETDATE(), :area, :height, :coordinates)
");

        // 插入房間的 SQL
        $stmtRoom = $conn->prepare("
INSERT INTO [Test].[dbo].[GBD_Project_rooms] 
(unit_id, room_number, created_at, length, depth, window_position, Area, Height, Coordinates) 
VALUES (:unit_id, :room_number, GETDATE(), :length, :depth, :window_position, :area, :height, :coordinates)
");

        // 解析和儲存資料
        foreach ($data['projectData']['floors'] as $floorData) {
            // 插入樓層
            $stmtFloor->execute([
                ':building_id' => $building_id,
                ':floor_number' => $floorData['number'] ?? 1,
                ':area' => $floorData['area'] ?? null,
                ':height' => $floorData['height'] ?? null,
                ':coordinates' => json_encode($floorData['coordinates'] ?? [])
            ]);

            $floor_id = $conn->lastInsertId();

            // 插入單位
            foreach ($floorData['units'] as $unitData) {
                $stmtUnit->execute([
                    ':floor_id' => $floor_id,
                    ':unit_number' => $unitData['number'] ?? 1,
                    ':area' => $unitData['area'] ?? null,
                    ':height' => $unitData['height'] ?? null,
                    ':coordinates' => json_encode($unitData['coordinates'] ?? [])
                ]);

                $unit_id = $conn->lastInsertId();

                // 插入房間
                foreach ($unitData['rooms'] as $roomData) {
                    $stmtRoom->execute([
                        ':unit_id' => $unit_id,
                        ':room_number' => $roomData['number'] ?? 'Room',
                        ':length' => $roomData['length'] ?? null,
                        ':depth' => $roomData['depth'] ?? null,
                        ':window_position' => $roomData['windowPosition'] ?? null,
                        ':area' => $roomData['area'] ?? null,
                        ':height' => $roomData['height'] ?? null,
                        ':coordinates' => json_encode($roomData['coordinates'] ?? [])
                    ]);
                }
            }
        }

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => '繪圖資料儲存成功',
            'deletedCounts' => $deletedCounts
        ]);
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }

        // 詳細的錯誤記錄
        error_log("DB Error in handleSaveDrawingData: " . $e->getMessage());
        error_log("Error Details: " . $e->getTraceAsString());

        echo json_encode([
            'success' => false,
            'message' => '儲存繪圖資料時發生錯誤: ' . $e->getMessage(),
            'errorDetails' => $e->getMessage()
        ]);
    }
}


/**
 * 檢查專案是否有現有資料的函數
 * 需要加入到 paste.txt 的 [3] 處理函數 部分
 */
function checkProjectHasData()
{
    global $serverName, $database, $username, $password;

    header('Content-Type: application/json');

    // 檢查是否有建築 ID
    if (!isset($_SESSION['building_id']) || empty($_SESSION['building_id'])) {
        echo json_encode([
            'success' => false,
            'message' => '無法識別建築 ID，請先建立專案',
            'hasData' => false
        ]);
        return;
    }

    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $building_id = $_SESSION['building_id'];

        // 檢查是否有樓層資料
        $stmt = $conn->prepare("
SELECT COUNT(*) as count 
FROM [Test].[dbo].[GBD_Project_floors] 
WHERE building_id = :building_id
");
        $stmt->execute([':building_id' => $building_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $hasData = $result['count'] > 0;

        echo json_encode([
            'success' => true,
            'hasData' => $hasData,
            'floorCount' => $result['count']
        ]);
    } catch (PDOException $e) {
        error_log("DB Error in checkProjectHasData: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => '檢查專案資料時發生錯誤: ' . $e->getMessage(),
            'hasData' => false
        ]);
    }
}

function handleGetProjectInfo()
{
    global $serverName, $database, $username, $password;

    header('Content-Type: application/json');

    if (!isset($_GET['projectId']) || empty($_GET['projectId'])) {
        echo json_encode([
            'success' => false,
            'message' => '未提供專案ID'
        ]);
        return;
    }

    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $projectId = $_GET['projectId'];

        // 修正: 將 'id' 改為 'building_id'
        $sql = "SELECT building_id, building_name, address, building_angle, building_orientation, speckle_project_id, speckle_model_id 
FROM [Test].[dbo].[GBD_Project] 
WHERE building_id = :projectId";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':projectId' => $projectId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            echo json_encode([
                'success' => true,
                // 修正: 將 'id' 改為 'building_id'
                'projectId' => $result['building_id'],
                'projectName' => $result['building_name'],
                'projectAddress' => $result['address'],
                'buildingAngle' => $result['building_angle'],
                'buildingOrientation' => $result['building_orientation'],
                'speckle_project_id' => $result['speckle_project_id'],
                'speckle_model_id' => $result['speckle_model_id']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => '找不到專案資料'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => '資料庫錯誤: ' . $e->getMessage()
        ]);
    }
}

// 獲取 Speckle 專案列表
function handleGetSpeckleProjects()
{
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
function handleImportSpeckleModel()
{
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

// 儲存從 Speckle 匯入的資料
function handleSaveSpeckleData()
{
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

    // 取得 POST 資料
    $speckleProjectId = $_POST['speckleProjectId'] ?? '';
    $speckleModelId = $_POST['speckleModelId'] ?? '';
    $modelData = $_POST['modelData'] ?? '';

    if (empty($speckleProjectId) || empty($speckleModelId)) {
        echo json_encode([
            'success' => false,
            'message' => '缺少 Speckle 專案或模型 ID'
        ]);
        return;
    }

    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $building_id = $_SESSION['building_id'];

        // 更新專案資料，加入 Speckle 相關資訊
        try {
            // 先檢查是否有 speckle 相關欄位
            $checkColumns = $conn->prepare("
SELECT COUNT(*) as col_count 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'GBD_Project' 
AND COLUMN_NAME IN ('speckle_project_id', 'speckle_model_id')
AND TABLE_SCHEMA = 'dbo'
");
            $checkColumns->execute();
            $columnCheck = $checkColumns->fetch(PDO::FETCH_ASSOC);

            if ($columnCheck['col_count'] < 2) {
                // 如果欄位不存在，先創建
                $conn->exec("ALTER TABLE [Test].[dbo].[GBD_Project] ADD speckle_project_id NVARCHAR(255) NULL");
                $conn->exec("ALTER TABLE [Test].[dbo].[GBD_Project] ADD speckle_model_id NVARCHAR(255) NULL");
            }

            // 更新專案的 Speckle 資訊
            $updateSql = "UPDATE [Test].[dbo].[GBD_Project] 
SET speckle_project_id = :speckle_project_id, 
speckle_model_id = :speckle_model_id,
updated_at = GETDATE()
WHERE building_id = :building_id";

            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([
                ':speckle_project_id' => $speckleProjectId,
                ':speckle_model_id' => $speckleModelId,
                ':building_id' => $building_id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Speckle 資料儲存成功',
                'building_id' => $building_id
            ]);
        } catch (Exception $e) {
            error_log("Save Speckle Data Error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => '儲存 Speckle 資料時發生錯誤: ' . $e->getMessage()
            ]);
        }
    } catch (PDOException $e) {
        error_log("DB Error in handleSaveSpeckleData: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => '資料庫操作發生錯誤: ' . $e->getMessage()
        ]);
    }
}

// 從 Speckle 獲取並分析建築資料
function handleAnalyzeSpeckleModel()
{
    global $serverName, $database, $username, $password;

    // 開始輸出緩衝，避免意外的輸出干擾 JSON 響應
    ob_start();

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
        // 從 Speckle 獲取模型的詳細幾何資料
        $speckleUrl = 'https://speckle.xyz/graphql';

        // 首先獲取最新版本的模型資料
        $versionQuery = '
query GetLatestVersion($projectId: String!, $modelId: String!) {
project(id: $projectId) {
model(id: $modelId) {
id
name
versions(limit: 1) {
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
            'query' => $versionQuery,
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

        $versionData = $data['data']['project']['model']['versions']['items'][0] ?? null;

        if (!$versionData) {
            throw new Exception('找不到模型版本資料');
        }

        $objectId = $versionData['referencedObject'];

        // 獲取物件的詳細資料
        $objectUrl = "https://speckle.xyz/objects/{$projectId}/{$objectId}";

        $objectContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json'
                ]
            ]
        ]);

        $objectResult = file_get_contents($objectUrl, false, $objectContext);

        if ($objectResult === FALSE) {
            throw new Exception('無法獲取物件詳細資料');
        }

        $objectData = json_decode($objectResult, true);

        if (!$objectData) {
            throw new Exception('無法解析 Speckle 物件資料');
        }

        error_log("成功獲取 Speckle 物件資料，開始分析...");
        error_log("物件資料大小: " . strlen($objectResult) . " bytes");

        // 分析建築資料
        $buildingData = analyzeSpeckleModelData($objectData);

        if (!$buildingData || $buildingData['totalFloors'] == 0) {
            error_log("警告: 沒有找到樓層資料");
        }

        if ($buildingData['totalRooms'] == 0) {
            error_log("警告: 沒有找到房間資料");
        }

        // 如果有 building_id，將分析結果儲存到資料庫
        if (isset($_SESSION['building_id'])) {
            $success = saveBuildingDataFromSpeckle($buildingData, $_SESSION['building_id']);

            // 清除可能的錯誤輸出
            ob_clean();

            echo json_encode([
                'success' => $success,
                'buildingData' => $buildingData,
                'message' => $success ? 'Speckle 建築資料分析完成並儲存' : 'Speckle 建築資料分析完成，但儲存失敗'
            ]);
        } else {
            // 清除可能的錯誤輸出
            ob_clean();

            echo json_encode([
                'success' => true,
                'buildingData' => $buildingData,
                'message' => 'Speckle 建築資料分析完成'
            ]);
        }
    } catch (Exception $e) {
        error_log("Analyze Speckle Model Error: " . $e->getMessage());

        // 清除可能的錯誤輸出
        ob_clean();

        echo json_encode([
            'success' => false,
            'message' => '分析 Speckle 模型時發生錯誤: ' . $e->getMessage()
        ]);
    }

    // 結束輸出緩衝
    ob_end_flush();
}

// 分析 Speckle 模型資料
function analyzeSpeckleModelData($objectData)
{
    $floors = [];
    $rooms = [];
    $processedIds = []; // 避免重複處理

    error_log("開始分析 Speckle 資料...");
    error_log("原始資料結構: " . json_encode(array_keys((array)$objectData)));

    // 輸出完整的資料結構以供調試
    error_log("完整 Speckle 資料 (前2000字符): " . substr(json_encode($objectData), 0, 2000));

    // 更寬泛的遞歸分析 Speckle 物件
    $analyzeObjects = function ($objects, $level = 0, $currentFloorIndex = null, $path = '') use (&$floors, &$rooms, &$analyzeObjects, &$processedIds) {
        if (!is_array($objects) && !is_object($objects)) {
            return;
        }

        if ($level > 10) { // 防止過深遞歸
            return;
        }

        foreach ((array)$objects as $key => $object) {
            if (!is_array($object) && !is_object($object)) {
                continue;
            }

            $obj = (array)$object;
            $objId = $obj['id'] ?? null;

            // 避免重複處理同一個物件
            if ($objId && in_array($objId, $processedIds)) {
                continue;
            }
            if ($objId) {
                $processedIds[] = $objId;
            }

            $speckleType = $obj['speckle_type'] ?? '';
            $category = $obj['category'] ?? '';
            $name = $obj['name'] ?? $key;
            $currentPath = $path . '/' . $key;

            // 記錄每個物件的基本信息用於調試
            if ($level < 3) { // 只記錄前幾層的物件避免太多日誌
                error_log("物件路徑: {$currentPath}, 類型: {$speckleType}, 分類: {$category}, 名稱: {$name}");
            }

            // 寬泛的樓層檢測
            $isLevel = false;
            if (
                $speckleType === 'Objects.BuiltElements.Level' ||
                $speckleType === 'Objects.BuiltElements.Level:Objects.Base' ||
                strpos($speckleType, 'Level') !== false ||
                strpos($name, 'Level') !== false ||
                strpos($name, '樓') !== false ||
                ($category && strpos($category, 'Level') !== false)
            ) {
                $isLevel = true;
            }

            if ($isLevel) {
                $elevation = floatval($obj['elevation'] ?? $obj['level']['elevation'] ?? 0);
                $levelName = $obj['name'] ?? $obj['level']['name'] ?? "樓層 " . (count($floors) + 1);

                $floors[] = [
                    'name' => $levelName,
                    'elevation' => $elevation,
                    'id' => $objId ?? uniqid(),
                    'speckle_type' => $speckleType
                ];
                $currentFloorIndex = count($floors) - 1;

                error_log("找到樓層: {$levelName}, 類型: {$speckleType}, 高度: {$elevation}");
            }

            // 寬泛的房間檢測 - 針對各種可能的房間資料格式
            $isRoom = false;

            // 基本房間類型檢測
            if (
                $speckleType === 'Objects.BuiltElements.Room' ||
                $speckleType === 'Objects.BuiltElements.Room:Objects.Base' ||
                strpos($speckleType, 'Room') !== false
            ) {
                $isRoom = true;
            }

            // 名稱包含房間相關詞彙
            if (
                strpos($name, 'Room') !== false ||
                strpos($name, 'Kitchen') !== false ||
                strpos($name, 'Dining') !== false ||
                strpos($name, 'Bath') !== false ||
                strpos($name, 'Living') !== false ||
                strpos($name, 'Bedroom') !== false ||
                strpos($name, 'Hall') !== false ||
                strpos($name, 'Laundry') !== false ||
                strpos($name, 'Media') !== false ||
                preg_match('/^\d{3}$/', $name) || // 像 101, 103 這樣的房間號
                preg_match('/^\d{2,3}[A-Z]?$/', $name) || // 像 101A, 23 這樣的
                (strlen($name) <= 4 && is_numeric($name))
            ) { // 短數字名稱
                $isRoom = true;
                error_log("透過名稱檢測到房間: {$name}");
            }

            // 分類包含房間相關資訊
            if ($category && (strpos($category, 'Room') !== false ||
                strpos($category, '房間') !== false ||
                strpos($category, 'Rooms') !== false)) {
                $isRoom = true;
                error_log("透過分類檢測到房間: {$name}, 分類: {$category}");
            }

            // 檢測 Revit 房間的 builtInCategory
            if (isset($obj['properties']) && is_array($obj['properties'])) {
                $builtInCategory = $obj['properties']['builtInCategory'] ?? '';
                if ($builtInCategory === 'OST_Rooms') {
                    $isRoom = true;
                    error_log("透過 builtInCategory 檢測到房間: {$name}, builtInCategory: {$builtInCategory}");
                }
            }

            // 有面積的物件可能是房間
            if (isset($obj['area']) && floatval($obj['area']) > 0) {
                $isRoom = true;
            }

            // 有房間號碼的物件
            if (isset($obj['roomNumber']) || isset($obj['room_number']) || isset($obj['number'])) {
                $isRoom = true;
            }

            // 檢查是否有常見的房間屬性
            if (
                isset($obj['roomTag']) || isset($obj['tag']) ||
                (isset($obj['parameters']) && is_array($obj['parameters']))
            ) {
                foreach ($obj['parameters'] ?? [] as $param) {
                    if (is_array($param) && isset($param['name'])) {
                        if (
                            strpos($param['name'], 'Room') !== false ||
                            strpos($param['name'], 'Area') !== false
                        ) {
                            $isRoom = true;
                            break;
                        }
                    }
                }
            }

            if ($isRoom) {

                // 從各種可能的位置提取面積
                $area = floatval($obj['area'] ?? 0);
                $volume = floatval($obj['volume'] ?? 0);
                $roomNumber = $obj['number'] ?? '';

                // 嘗試從 Properties 中獲取更多資訊
                $properties = $obj['properties'] ?? [];
                $elementId = $properties['elementId'] ?? '';
                $level = $obj['level'] ?? '';

                // 從 Parameters 中提取尺寸資訊
                $parameters = $obj['parameters'] ?? [];
                $instanceParams = [];
                if (isset($parameters['Instance Parameters'])) {
                    $instanceParams = $parameters['Instance Parameters'];
                } elseif (isset($parameters['instance'])) {
                    $instanceParams = $parameters['instance'];
                } elseif (is_array($parameters)) {
                    $instanceParams = $parameters;
                }

                // 先初始化高度變數
                $height = floatval($obj['height'] ?? $obj['baseHeight'] ?? 0);

                // 從參數中尋找面積、體積、高度等
                foreach ($instanceParams as $paramKey => $paramValue) {
                    if (is_array($paramValue) && isset($paramValue['value'])) {
                        $value = floatval($paramValue['value']);
                        $unit = $paramValue['unit'] ?? '';

                        // 根據參數名稱來判斷是什麼數值
                        if (strpos($paramKey, '面積') !== false || strpos($paramKey, 'Area') !== false) {
                            if ($area == 0) $area = $value;
                        } elseif (strpos($paramKey, '體積') !== false || strpos($paramKey, 'Volume') !== false) {
                            if ($volume == 0) $volume = $value;
                        } elseif (strpos($paramKey, '高度') !== false || strpos($paramKey, 'Height') !== false) {
                            if ($height == 0) $height = $value;
                        }
                    } elseif (is_numeric($paramValue)) {
                        // 直接的數值參數
                        $value = floatval($paramValue);
                        if (strpos($paramKey, '面積') !== false || strpos($paramKey, 'Area') !== false) {
                            if ($area == 0) $area = $value;
                        }
                    }
                }
                if ($height == 0 && $area > 0 && $volume > 0) {
                    $height = $volume / $area; // 透過體積和面積計算高度
                }

                $roomData = [
                    'id' => $objId ?? uniqid(),
                    'name' => $name,
                    'number' => $roomNumber,
                    'area' => $area,
                    'volume' => $volume,
                    'height' => $height,
                    'length' => 0,
                    'width' => 0,
                    'floor' => $currentFloorIndex,
                    'level_name' => $level,
                    'element_id' => $elementId,
                    'built_in_category' => $properties['builtInCategory'] ?? '',
                    'windowPosition' => '',
                    'parameters' => $obj['parameters'] ?? [],
                    'speckle_type' => $speckleType,
                    'category' => $category
                ];

                // 計算房間尺寸
                if (isset($obj['geometry']) || isset($obj['displayValue'])) {
                    $geometry = $obj['geometry'] ?? $obj['displayValue'] ?? null;
                    if ($geometry) {
                        $dimensions = extractRoomDimensions($geometry);
                        $roomData = array_merge($roomData, $dimensions);
                    }
                }

                // 如果還沒有長寬，嘗試從面積估算
                if ($roomData['length'] == 0 && $roomData['width'] == 0 && $area > 0) {
                    $side = sqrt($area);
                    $roomData['length'] = $side;
                    $roomData['width'] = $side;
                }

                $rooms[] = $roomData;

                error_log("找到房間: {$name} ({$roomNumber}), 面積: {$area}, 高度: {$height}, 體積: {$volume}, 樓層: {$level}, 所屬樓層索引: " . ($currentFloorIndex !== null ? $currentFloorIndex : 'null') . ", 分類: {$category}, builtInCategory: " . ($properties['builtInCategory'] ?? '無'));
            }

            // 遞歸處理子物件 - 更謹慎的處理
            $childKeys = ['elements', '@elements', 'children', 'objects'];
            foreach ($childKeys as $childKey) {
                if (isset($obj[$childKey]) && (is_array($obj[$childKey]) || is_object($obj[$childKey]))) {
                    $analyzeObjects($obj[$childKey], $level + 1, $currentFloorIndex, $currentPath);
                }
            }
        }
    };

    // 開始分析
    $analyzeObjects($objectData, 0, null, 'root');

    error_log("分析完成 - 找到樓層數: " . count($floors) . ", 房間數: " . count($rooms));

    // 按樓層組織房間
    $floorData = [];

    if (!empty($floors)) {
        // 按樓層高度排序
        usort($floors, function ($a, $b) {
            return $a['elevation'] <=> $b['elevation'];
        });

        foreach ($floors as $index => $floor) {
            $floorRooms = array_filter($rooms, function ($room) use ($index) {
                return $room['floor'] === $index;
            });

            $floorData[] = [
                'floor_number' => $index + 1,
                'name' => $floor['name'],
                'elevation' => $floor['elevation'],
                'rooms' => array_values($floorRooms)
            ];

            error_log("樓層 {$floor['name']} 包含房間數: " . count($floorRooms));
        }
    }

    // 處理未分配到樓層的房間
    $unassignedRooms = array_filter($rooms, function ($room) {
        return $room['floor'] === null;
    });

    if (!empty($unassignedRooms)) {
        $floorData[] = [
            'floor_number' => count($floorData) + 1,
            'name' => '未分配樓層',
            'elevation' => 0,
            'rooms' => array_values($unassignedRooms)
        ];

        error_log("未分配樓層包含房間數: " . count($unassignedRooms));
    }

    // 如果完全沒有檢測到樓層但有房間，創建預設樓層
    if (empty($floorData) && !empty($rooms)) {
        $floorData[] = [
            'floor_number' => 1,
            'name' => '預設樓層',
            'elevation' => 0,
            'rooms' => $rooms
        ];

        error_log("創建預設樓層，包含房間數: " . count($rooms));
    }

    $result = [
        'floors' => $floorData,
        'totalRooms' => count($rooms),
        'totalFloors' => count($floorData)
    ];

    error_log("最終結果 - 樓層數: " . $result['totalFloors'] . ", 總房間數: " . $result['totalRooms']);

    return $result;
}

// 提取房間尺寸
function extractRoomDimensions($geometry)
{
    $dimensions = [
        'height' => 0,
        'length' => 0,
        'width' => 0
    ];

    if (!is_array($geometry) && !is_object($geometry)) {
        return $dimensions;
    }

    $geom = (array)$geometry;

    // 嘗試從邊界框獲取尺寸
    if (isset($geom['bbox'])) {
        $bbox = (array)$geom['bbox'];
        if (isset($bbox['max']) && isset($bbox['min'])) {
            $max = (array)$bbox['max'];
            $min = (array)$bbox['min'];

            $dimensions['length'] = abs(floatval($max['x'] ?? 0) - floatval($min['x'] ?? 0));
            $dimensions['width'] = abs(floatval($max['y'] ?? 0) - floatval($min['y'] ?? 0));
            $dimensions['height'] = abs(floatval($max['z'] ?? 0) - floatval($min['z'] ?? 0));

            error_log("從 bbox 提取尺寸 - 長:{$dimensions['length']}, 寬:{$dimensions['width']}, 高:{$dimensions['height']}");
        }
    }

    // 嘗試從 displayValue 獲取資訊
    if (isset($geom['displayValue']) && is_array($geom['displayValue'])) {
        foreach ($geom['displayValue'] as $displayItem) {
            if (is_array($displayItem) || is_object($displayItem)) {
                $item = (array)$displayItem;
                if (isset($item['bbox'])) {
                    $bbox = (array)$item['bbox'];
                    if (isset($bbox['max']) && isset($bbox['min'])) {
                        $max = (array)$bbox['max'];
                        $min = (array)$bbox['min'];

                        $length = abs(floatval($max['x'] ?? 0) - floatval($min['x'] ?? 0));
                        $width = abs(floatval($max['y'] ?? 0) - floatval($min['y'] ?? 0));
                        $height = abs(floatval($max['z'] ?? 0) - floatval($min['z'] ?? 0));

                        // 使用較大的值
                        $dimensions['length'] = max($dimensions['length'], $length);
                        $dimensions['width'] = max($dimensions['width'], $width);
                        $dimensions['height'] = max($dimensions['height'], $height);
                    }
                }
            }
        }
    }

    // 嘗試從其他幾何屬性獲取尺寸
    if (isset($geom['area'])) {
        $area = floatval($geom['area']);
        if ($area > 0 && $dimensions['length'] > 0 && $dimensions['width'] == 0) {
            $dimensions['width'] = $area / $dimensions['length'];
        } elseif ($area > 0 && $dimensions['width'] > 0 && $dimensions['length'] == 0) {
            $dimensions['length'] = $area / $dimensions['width'];
        }
    }

    // 如果 length 和 width 都是 0，但有面積，假設是正方形
    if ($dimensions['length'] == 0 && $dimensions['width'] == 0 && isset($geom['area'])) {
        $area = floatval($geom['area']);
        if ($area > 0) {
            $side = sqrt($area);
            $dimensions['length'] = $side;
            $dimensions['width'] = $side;
        }
    }

    return $dimensions;
}

// 檢測窗戶方位
function detectWindowOrientation($roomObject)
{
    $orientations = [];

    // 這裡可以根據 Speckle 模型中的窗戶資料來判斷方位
    // 暫時返回預設值，實際實現會根據具體的 Speckle 資料結構來調整

    return implode(', ', $orientations);
}

// 將分析的建築資料儲存到資料庫
function saveBuildingDataFromSpeckle($buildingData, $building_id)
{
    global $serverName, $database, $username, $password;

    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $conn->beginTransaction();

        // 清除現有的樓層、單位和房間資料（如果需要的話）
        $clearStmt = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_rooms] WHERE unit_id IN (SELECT unit_id FROM [Test].[dbo].[GBD_Project_units] WHERE floor_id IN (SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id))");
        $clearStmt->execute([':building_id' => $building_id]);

        $clearStmt = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_units] WHERE floor_id IN (SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id)");
        $clearStmt->execute([':building_id' => $building_id]);

        $clearStmt = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id");
        $clearStmt->execute([':building_id' => $building_id]);

        // 插入新的資料
        $stmtFloor = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_floors] (building_id, floor_number, created_at) VALUES (:building_id, :floor_number, GETDATE())");
        $stmtUnit = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_units] (floor_id, unit_number, created_at) VALUES (:floor_id, :unit_number, GETDATE())");
        $stmtRoom = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_rooms] (unit_id, room_number, height, length, depth, window_position, created_at, updated_at) VALUES (:unit_id, :room_number, :height, :length, :depth, :window_position, GETDATE(), GETDATE())");

        foreach ($buildingData['floors'] as $floorData) {
            // 插入樓層
            $stmtFloor->execute([
                ':building_id' => $building_id,
                ':floor_number' => $floorData['floor_number']
            ]);

            $floor_id = $conn->lastInsertId();

            // 為每個樓層創建一個預設單位
            $stmtUnit->execute([
                ':floor_id' => $floor_id,
                ':unit_number' => 1
            ]);

            $unit_id = $conn->lastInsertId();

            // 插入房間
            foreach ($floorData['rooms'] as $roomData) {
                $stmtRoom->execute([
                    ':unit_id' => $unit_id,
                    ':room_number' => $roomData['name'] ?? $roomData['number'] ?? 'Room',
                    ':height' => $roomData['height'] ?? null,
                    ':length' => $roomData['length'] ?? null,
                    ':depth' => $roomData['width'] ?? null,
                    ':window_position' => $roomData['windowPosition'] ?? ''
                ]);
            }
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Save Building Data from Speckle Error: " . $e->getMessage());
        return false;
    }
}

/****************************************************************************
 * [3.5] 處理平面圖上傳
 ****************************************************************************/
function handleFloorplanUpload()
{
    require_once 'floorplan_upload.php';

    // 設置回應頭
    header('Content-Type: application/json');

    // 檢查用戶是否登入
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'error' => '請先登入帳號以使用該功能',
            'redirect' => 'login.php'
        ]);
        return;
    }

    // 檢查必要參數
    if (!isset($_POST['building_id']) || !isset($_FILES['floorplanFile'])) {
        echo json_encode(['success' => false, 'error' => '缺少必要參數']);
        return;
    }

    $building_id = intval($_POST['building_id']);
    $scale = isset($_POST['scale']) ? floatval($_POST['scale']) : 0.01;

    try {
        // 創建平面圖上傳器實例
        $uploader = new FloorplanUploader();

        // 處理檔案上傳和分析
        $result = $uploader->handleUpload($_FILES['floorplanFile'], $building_id);

        if ($result['success']) {
            // 記錄分析結果
            error_log("平面圖分析成功: building_id={$building_id}, 檔案={$result['fileName']}");
            error_log("識別結果: " . json_encode($result['analysisResult']));

            // 調整比例尺
            if ($scale != 0.01) {
                $result['analysisResult'] = adjustFloorplanScale($result['analysisResult'], $scale / 0.01);
            }

            // 儲存識別結果到資料庫
            if (isset($result['analysisResult'])) {
                $saved = saveFloorplanDataToDatabase($result['analysisResult'], $building_id);
                if ($saved) {
                    $result['message'] = '平面圖分析完成並已儲存到資料庫';
                } else {
                    $result['message'] = '平面圖分析完成，但儲存到資料庫時發生錯誤';
                }
            }

            echo json_encode($result);
        } else {
            echo json_encode($result);
        }
    } catch (Exception $e) {
        error_log("平面圖處理錯誤: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => '處理平面圖時發生錯誤: ' . $e->getMessage()
        ]);
    }
}

/**
 * 調整分析結果的比例尺
 */
function adjustFloorplanScale($analysisResult, $scaleFactor)
{
    // 調整樓層
    if (isset($analysisResult['floors'])) {
        foreach ($analysisResult['floors'] as &$floor) {
            if (isset($floor['area'])) {
                $floor['area'] *= $scaleFactor * $scaleFactor;
            }
        }
    }

    // 調整單元
    if (isset($analysisResult['units'])) {
        foreach ($analysisResult['units'] as &$unit) {
            if (isset($unit['area'])) {
                $unit['area'] *= $scaleFactor * $scaleFactor;
            }
            if (isset($unit['width'])) {
                $unit['width'] *= $scaleFactor;
            }
            if (isset($unit['height'])) {
                $unit['height'] *= $scaleFactor;
            }
        }
    }

    // 調整房間
    if (isset($analysisResult['rooms'])) {
        foreach ($analysisResult['rooms'] as &$room) {
            if (isset($room['area'])) {
                $room['area'] *= $scaleFactor * $scaleFactor;
            }
            if (isset($room['width'])) {
                $room['width'] *= $scaleFactor;
            }
            if (isset($room['height'])) {
                $room['height'] *= $scaleFactor;
            }
        }
    }

    // 調整窗戶
    if (isset($analysisResult['windows'])) {
        foreach ($analysisResult['windows'] as &$window) {
            if (isset($window['area'])) {
                $window['area'] *= $scaleFactor * $scaleFactor;
            }
            if (isset($window['width'])) {
                $window['width'] *= $scaleFactor;
            }
            if (isset($window['height'])) {
                $window['height'] *= $scaleFactor;
            }
        }
    }

    return $analysisResult;
}

/**
 * 將平面圖分析結果儲存到資料庫
 */
function saveFloorplanDataToDatabase($analysisResult, $building_id)
{
    global $serverName, $database, $username, $password;

    try {
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $conn->beginTransaction();

        // 清除現有的資料
        $clearStmt = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_rooms] WHERE unit_id IN (SELECT unit_id FROM [Test].[dbo].[GBD_Project_units] WHERE floor_id IN (SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id))");
        $clearStmt->execute([':building_id' => $building_id]);

        $clearStmt = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_units] WHERE floor_id IN (SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id)");
        $clearStmt->execute([':building_id' => $building_id]);

        $clearStmt = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id");
        $clearStmt->execute([':building_id' => $building_id]);

        // 準備插入語句
        $stmtFloor = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_floors] (building_id, floor_number, created_at) VALUES (:building_id, :floor_number, GETDATE())");
        $stmtUnit = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_units] (floor_id, unit_number, created_at) VALUES (:floor_id, :unit_number, GETDATE())");
        $stmtRoom = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_rooms] (unit_id, room_number, height, length, depth, window_position, created_at, updated_at) VALUES (:unit_id, :room_number, :height, :length, :depth, :window_position, GETDATE(), GETDATE())");

        // 處理樓層資料
        if (isset($analysisResult['floors'])) {
            foreach ($analysisResult['floors'] as $floorIndex => $floorData) {
                $stmtFloor->execute([
                    ':building_id' => $building_id,
                    ':floor_number' => $floorIndex + 1
                ]);

                $floor_id = $conn->lastInsertId();

                // 為每個樓層創建單元
                if (isset($analysisResult['units'])) {
                    foreach ($analysisResult['units'] as $unitIndex => $unitData) {
                        $stmtUnit->execute([
                            ':floor_id' => $floor_id,
                            ':unit_number' => $unitIndex + 1
                        ]);

                        $unit_id = $conn->lastInsertId();

                        // 插入房間資料
                        if (isset($analysisResult['rooms'])) {
                            foreach ($analysisResult['rooms'] as $roomIndex => $roomData) {
                                $windowPosition = '';
                                if (isset($analysisResult['windows'])) {
                                    foreach ($analysisResult['windows'] as $window) {
                                        if (isset($window['roomId']) && $window['roomId'] == $roomIndex) {
                                            $windowPosition .= $window['orientation'] . ' ';
                                        }
                                    }
                                }

                                $stmtRoom->execute([
                                    ':unit_id' => $unit_id,
                                    ':room_number' => $roomData['name'] ?? 'Room ' . ($roomIndex + 1),
                                    ':height' => $roomData['height'] ?? 3.0,
                                    ':length' => $roomData['length'] ?? $roomData['width'] ?? 0,
                                    ':depth' => $roomData['depth'] ?? $roomData['height'] ?? 0,
                                    ':window_position' => trim($windowPosition)
                                ]);
                            }
                        }
                    }
                } else {
                    // 如果沒有單元資料，創建預設單元
                    $stmtUnit->execute([
                        ':floor_id' => $floor_id,
                        ':unit_number' => 1
                    ]);

                    $unit_id = $conn->lastInsertId();

                    // 插入房間資料
                    if (isset($analysisResult['rooms'])) {
                        foreach ($analysisResult['rooms'] as $roomIndex => $roomData) {
                            $windowPosition = '';
                            if (isset($analysisResult['windows'])) {
                                foreach ($analysisResult['windows'] as $window) {
                                    if (isset($window['roomId']) && $window['roomId'] == $roomIndex) {
                                        $windowPosition .= $window['orientation'] . ' ';
                                    }
                                }
                            }

                            $stmtRoom->execute([
                                ':unit_id' => $unit_id,
                                ':room_number' => $roomData['name'] ?? 'Room ' . ($roomIndex + 1),
                                ':height' => $roomData['height'] ?? 3.0,
                                ':length' => $roomData['length'] ?? $roomData['width'] ?? 0,
                                ':depth' => $roomData['depth'] ?? $roomData['height'] ?? 0,
                                ':window_position' => trim($windowPosition)
                            ]);
                        }
                    }
                }
            }
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("儲存平面圖分析結果到資料庫時發生錯誤: " . $e->getMessage());
        return false;
    }
}

/****************************************************************************
 * [4] 更新導覽列專案名稱顯示
 ****************************************************************************/
// 檢查是否有POST請求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false];

    // 檢查是否有必要的數據
    if (isset($_POST['project_id']) && isset($_POST['project_name'])) {
        // 更新session變數
        $_SESSION['current_gbd_project_id'] = $_POST['project_id'];
        $_SESSION['current_gbd_project_name'] = $_POST['project_name'];

        $response['success'] = true;
        $response['message'] = 'Session updated successfully';
    } else {
        $response['message'] = 'Missing required data';
    }

    // 返回JSON回應
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

/****************************************************************************
 * [5] 語言轉換
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>

    <!-- 引入 Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />

    <style>
        body {
            margin-top: 100px;
            /* 確保 navbar 不會擋住主內容 */
            padding: 0;
            /* background-image: url('https://i.imgur.com/WJGtbFT.jpeg'); */
            background-color: rgba(255, 255, 255, 0.8);
            background-size: 100% 100%;
            /* 使背景圖片填滿整個背景區域 */
            background-position: center;
            /* 背景圖片居中 */
            background-repeat: no-repeat;
            /* 不重複背景圖片 */
            background-attachment: fixed;
            /* 背景固定在視口上 */
        }

        .navbar-brand {
            font-weight: bold;
        }

        #container {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            /* 讓內容靠左對齊 */
            max-width: 70%;
            margin: 0 auto;
            padding: 20px;
        }

        #buildingContainer {
            /* max-width: 70%; 調整最大寬度，避免內容過寬 */
            margin: 0 auto;
            /* 讓內容在螢幕中央 */
            padding: 20px;
            /* 增加內邊距，避免太靠邊 */
        }

        .floor,
        .unit,
        .room {
            border: 1px solid #000;
            margin: 10px 0;
            padding: 10px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
        }

        .floor:nth-child(odd) {
            background-color: rgba(191, 202, 194, 0.7);
            /* 第一種顏色，透明度70% */
        }

        .floor:nth-child(even) {
            background-color: rgba(235, 232, 227, 0.7);
            /* 第二種顏色，透明度70% */
        }

        .header-row {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            /* 從 5 改為 8 */
            gap: 8px;
            padding: 10px;
            font-weight: bold;
            border-bottom: 2px solid #ddd;
            font-size: 14px;
            /* 減小字體以適應更多欄位 */
        }

        .header-row div {
            flex: 1;
            text-align: center;
            padding: 5px;
            border-bottom: 1px solid #000;
        }

        .room-row {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            /* 從 5 改為 8 */
            gap: 8px;
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            align-items: center;
        }

        .room-row input {
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            /* 減小輸入框字體 */
            width: 100%;
            box-sizing: border-box;
        }

        .unit {
            width: 100%;
            overflow-x: auto;
            /* 如果還是太寬，允許水平滾動 */
            margin-bottom: 20px;
            border: 1px solid #000;
            border-radius: 8px;
        }

        /* 繪圖轉換後的 7 欄格式 */
        .header-row.drawing-converted {
            grid-template-columns: repeat(7, 1fr);
        }

        .room-row.drawing-converted {
            grid-template-columns: repeat(7, 1fr);
        }

        /* 隱藏欄位樣式 */
        .room-row.drawing-converted input[type="hidden"] {
            display: none;
        }

        button {
            margin-top: 10px;
            padding: 10px;
            border-radius: 12px;
            background-color: #769a76;
            /* 設定基本顏色 */
            color: white;
            border: none;
            cursor: pointer;
        }

        button:hover {
            background-color: #87ab87;
            /* 懸停時顏色略微變亮 */
        }

        #modal,
        #deleteModal,
        #copyModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto;
            /* 允許整個模態框區域滾動 */
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            /* 調整上邊距，讓模態框更靠上 */
            padding: 20px;
            border-radius: 10px;
            width: 60%;
            max-width: 800px;
            max-height: 80vh;
            /* 設置最大高度為視窗高度的80% */
            overflow-y: auto;
            /* 允許內容滾動 */
            position: relative;
            /* 為了固定標題 */
        }

        .sub-modal-content {
            display: none;
            margin-top: 20px;
        }

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
        }

        #fixed-buttons button {
            margin: 8px 0;
            padding: 12px 20px;
            font-size: 16px;
            font-weight: 500;
            border-radius: 6px;
            border: none;
            /* 移除邊框 */
            background-color: #769a76;
            /* 設定基本顏色 */
            color: white;
            /* 文字顏色設為白色 */
            cursor: pointer;
            transition: all 0.2s ease;
        }

        #fixed-buttons button:hover {
            background-color: #87ab87;
            /* 懸停時顏色略微變亮 */
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        #fixed-buttons button:active {
            transform: translateY(0);
            background-color: #658965;
            /* 點擊時顏色略微變暗 */
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

        .sub-modal-content {
            display: none;
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            overflow-y: auto;
        }

        .copy-select,
        .copy-input {
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

        /* 優化按鈕組樣式 */
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

        /* 添加分隔線 */
        .divider {
            height: 1px;
            background-color: #ddd;
            margin: 15px 0;
        }

        /* 新增的樣式 */
        .copy-select {
            margin: 10px 0;
            width: 100%;
            padding: 5px;
        }

        .copy-label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }

        /* 導覽列背景顏色 */
        .custom-navbar {
            background-color: #769a76;
            /* 這裡可以換成你要的顏色 */
        }

        .hidden {
            display: none;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            background-color: #2563eb;
            color: white;
            cursor: pointer;
        }

        .btn:hover {
            background-color: #1d4ed8;
        }

        .input-field {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
            margin-top: 0.25rem;
        }

        /* 縮放控制樣式 */
        .scale-zoom-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #f3f4f6;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
        }

        .canvas-container {
            position: relative;
            overflow: hidden;
            /* 防止內容溢出 */
            border: 1px solid #e5e7eb;
            background-color: white;
        }

        #drawingCanvas {
            touch-action: none;
            /* 防止移動設備上的默認觸摸行為 */
        }

        #gridInfo {
            position: absolute;
            bottom: 0.5rem;
            right: 0.5rem;
            background-color: rgba(255, 255, 255, 0.8);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            color: #4b5563;
            pointer-events: none;
            /* 允許點擊穿透 */
        }

        .zoom-controls button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
        }

        #zoomLevel {
            min-width: 3.5rem;
            text-align: center;
        }

        /* 添加到現有的CSS中 */
        .unit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .unit-orientation {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .unit-angle {
            width: 70px;
            padding: 2px 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .unit-orientation-text {
            min-width: 40px;
            padding: 2px 8px;
            background-color: #f5f5f5;
            border-radius: 4px;
            text-align: center;
        }

        .project-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background-color: #e9ecef;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .project-info-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        /* --- NEW STYLES FOR MULTI-WALL --- */
        .room-block {
            border-top: 2px solid #b2c2b2;
            padding-top: 15px;
            margin-top: 15px;
        }

        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .wall-row {
             display: grid;
            grid-template-columns: repeat(4, 1fr) auto; /* 4 fields + delete button */
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .wall-row input {
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            width: 100%;
            box-sizing: border-box;
        }
        .wall-header-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr) auto;
            gap: 8px;
            padding: 10px 0;
            font-weight: bold;
            border-bottom: 2px solid #ddd;
            font-size: 14px;
            color: #333;
        }
        .delete-wall-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 0 5px;
        }
        /* --- END OF NEW STYLES --- */

        /* Toast Notifications */
        #toast-container {
            position: fixed;
            top: 100px;
            /* Below navbar */
            right: 20px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .toast {
            background-color: #333;
            color: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.4s ease-in-out;
            min-width: 250px;
            max-width: 400px;
            word-break: break-word;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast.toast-success {
            background-color: #28a745;
        }

        .toast.toast-error {
            background-color: #dc3545;
        }

        .toast.toast-info {
            background-color: #17a2b8;
        }
    </style>
</head>

<body>
    <?php include('navbar.php'); ?>
    <div id="toast-container"></div>

    <div class="container my-3">
        <h1 class="text-2xl font-bold"><?php echo __('greenBuildingCalc'); ?></h1>
        <!-- <p class="mt-2"><?php echo __('greenBuildingDesc'); ?></p> -->
    </div>

    <div class="container mx-auto p-4">
        <!-- 專案創建卡片 -->
        <div id="projectCard" class="card max-w-md mx-auto mt-8">
            <div class="mb-4">
                <h2 class="text-xl font-bold"><?php echo __('createProject'); ?></h2>
            </div>

            <?php if (!$isLoggedIn): ?>
                <div class="alert alert-warning">
                    <?php echo __('loginRequired'); ?>
                </div>
            <?php else: ?>
                <form id="projectForm" class="space-y-4">
                    <div>
                        <label for="projectName" class="block font-medium"><?php echo __('projectName'); ?></label>
                        <input
                            type="text"
                            id="projectName"
                            name="projectName"
                            class="input-field"
                            placeholder="<?php echo __('projectName'); ?>"
                            required>
                    </div>

                    <div>
                        <label for="projectAddress" class="block font-medium"><?php echo __('projectAddress'); ?></label>
                        <input
                            type="text"
                            id="projectAddress"
                            name="projectAddress"
                            class="input-field"
                            placeholder="<?php echo __('projectAddress'); ?>"
                            required>
                    </div>

                    <!-- 資料輸入方式選擇 -->
                    <div>
                        <label class="block font-medium">資料輸入方式</label>
                        <div class="mt-2 space-y-2">
                            <div class="flex items-center">
                                <input
                                    type="radio"
                                    id="TableInput"
                                    name="inputMethod"
                                    value="table"
                                    class="h-4 w-4"
                                    checked
                                    onchange="toggleInputMethodGuide()">
                                <label for="TableInput" class="ml-2">表格輸入</label>
                            </div>
                            <div class="flex items-center">
                                <input
                                    type="radio"
                                    id="DrawingInput"
                                    name="inputMethod"
                                    value="drawing"
                                    class="h-4 w-4"
                                    onchange="toggleInputMethodGuide()">
                                <label for="DrawingInput" class="ml-2">繪圖輸入</label>
                            </div>
                            <div class="flex items-center">
                                <input
                                    type="radio"
                                    id="FloorplanUpload"
                                    name="inputMethod"
                                    value="floorplan"
                                    class="h-4 w-4"
                                    onchange="toggleInputMethodGuide()">
                                <label for="FloorplanUpload" class="ml-2">🏠 平面圖自動識別</label>
                            </div>
                            <div class="flex items-center">
                                <input
                                    type="radio"
                                    id="SpeckleInput"
                                    name="inputMethod"
                                    value="speckle"
                                    class="h-4 w-4"
                                    onchange="toggleInputMethodGuide()">
                                <label for="SpeckleInput" class="ml-2">從 Speckle 匯入 3D 資料</label>
                            </div>
                        </div>

                        <!-- 平面圖上傳指引 -->
                        <div id="floorplanGuide" class="mt-4 p-4 bg-green-50 border border-green-200 rounded-lg hidden">
                            <h4 class="text-lg font-semibold text-green-800 mb-3">🏠 平面圖自動識別指引</h4>

                            <div class="space-y-4 text-sm text-green-700">
                                <!-- 檔案要求 -->
                                <div class="bg-white p-3 rounded border border-green-100">
                                    <h5 class="font-semibold text-green-900 mb-2">📄 檔案要求</h5>
                                    <div class="space-y-2">
                                        <div class="flex items-start space-x-2">
                                            <span class="text-green-500">•</span>
                                            <p>支援格式：JPG、PNG、GIF（最大 10MB）</p>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <span class="text-green-500">•</span>
                                            <p>建議使用清晰的黑白線條平面圖</p>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <span class="text-green-500">•</span>
                                            <p>圖檔解析度至少 800x600 像素</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- 功能說明 -->
                                <div class="bg-white p-3 rounded border border-green-100">
                                    <h5 class="font-semibold text-green-900 mb-2">🔍 自動識別功能</h5>
                                    <div class="space-y-3">
                                        <div class="flex items-start space-x-2">
                                            <span class="flex-shrink-0 w-6 h-6 bg-green-100 text-green-800 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                                            <div>
                                                <p class="font-medium">線段提取</p>
                                                <p class="text-green-600">自動檢測平面圖中的牆面線條</p>
                                            </div>
                                        </div>

                                        <div class="flex items-start space-x-2">
                                            <span class="flex-shrink-0 w-6 h-6 bg-green-100 text-green-800 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                                            <div>
                                                <p class="font-medium">閉合區域識別</p>
                                                <p class="text-green-600">找出由線段圍成的封閉空間</p>
                                            </div>
                                        </div>

                                        <div class="flex items-start space-x-2">
                                            <span class="flex-shrink-0 w-6 h-6 bg-green-100 text-green-800 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                                            <div>
                                                <p class="font-medium">建築元素分類</p>
                                                <p class="text-green-600">自動識別樓層、單元、房間和窗戶</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                                <div class="flex items-start space-x-2">
                                    <span class="text-yellow-600 flex-shrink-0 mt-0.5">⚠️</span>
                                    <div class="text-sm text-yellow-800">
                                        <p class="font-medium mb-1">注意事項：</p>
                                        <ul class="space-y-1 text-xs">
                                            <li>• 請確保平面圖線條清晰，對比度高</li>
                                            <li>• 建議移除文字標註和尺寸線</li>
                                            <li>• 識別結果會自動填入表格，可手動調整</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
                                <div class="flex items-start space-x-2">
                                    <span class="text-blue-600 flex-shrink-0 mt-0.5">💡</span>
                                    <div class="text-sm text-blue-800">
                                        <p class="font-medium mb-1">最佳效果建議：</p>
                                        <ul class="space-y-1 text-xs">
                                            <li>• 使用 CAD 軟體匯出的 PNG 檔案</li>
                                            <li>• 確保房間邊界線條完整閉合</li>
                                            <li>• 避免重疊的線條或圖層</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Speckle 使用指引 -->
                        <div id="speckleGuide" class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg hidden">
                            <h4 class="text-lg font-semibold text-blue-800 mb-3">📋 Speckle 3D 資料匯入指引</h4>

                            <div class="space-y-4 text-sm text-blue-700">
                                <!-- 準備工作 -->
                                <div class="bg-white p-3 rounded border border-blue-100">
                                    <h5 class="font-semibold text-blue-900 mb-2">🔧 事前準備</h5>
                                    <div class="space-y-2">
                                        <div class="flex items-start space-x-2">
                                            <span class="text-blue-500">•</span>
                                            <p>在 Revit 中安裝 Speckle Connector 外掛</p>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <span class="text-blue-500">•</span>
                                            <p>將您的 .rvt 檔案透過 Speckle Connector 上傳到 Speckle 平台</p>
                                        </div>
                                        <div class="flex items-start space-x-2">
                                            <span class="text-blue-500">•</span>
                                            <p>確保建築模型包含房間（Room）和空間（Space）資訊</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- 操作步驟 -->
                                <div class="bg-white p-3 rounded border border-blue-100">
                                    <h5 class="font-semibold text-blue-900 mb-2">📝 操作流程</h5>
                                    <div class="space-y-3">
                                        <div class="flex items-start space-x-2">
                                            <span class="flex-shrink-0 w-6 h-6 bg-blue-100 text-blue-800 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                                            <div>
                                                <p class="font-medium">取得 Personal Access Token</p>
                                                <p class="text-blue-600">前往 <a href="https://speckle.xyz/profile" target="_blank" class="underline text-blue-800 hover:text-blue-900">speckle.xyz/profile</a> 建立您的個人存取權杖</p>
                                            </div>
                                        </div>

                                        <div class="flex items-start space-x-2">
                                            <span class="flex-shrink-0 w-6 h-6 bg-blue-100 text-blue-800 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                                            <div>
                                                <p class="font-medium">建立專案並選擇 Speckle</p>
                                                <p class="text-blue-600">點擊「建立專案」後，系統將引導您完成 Token 驗證</p>
                                            </div>
                                        </div>

                                        <div class="flex items-start space-x-2">
                                            <span class="flex-shrink-0 w-6 h-6 bg-blue-100 text-blue-800 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                                            <div>
                                                <p class="font-medium">選擇並匯入模型</p>
                                                <p class="text-blue-600">從您的 Speckle 專案中選擇要匯入的 Revit 模型</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                                <div class="flex items-start space-x-2">
                                    <span class="text-yellow-600 flex-shrink-0 mt-0.5">⚠️</span>
                                    <div class="text-sm text-yellow-800">
                                        <p class="font-medium mb-1">重要注意事項：</p>
                                        <ul class="space-y-1 text-xs">
                                            <li>• 請確認 Revit 模型中已正確設定房間（Room）邊界</li>
                                            <li>• 模型應包含完整的建築樓層和空間資訊</li>
                                            <li>• 首次使用需要約 2-3 分鐘的匯入時間</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded">
                                <div class="flex items-start space-x-2">
                                    <span class="text-green-600 flex-shrink-0 mt-0.5">💡</span>
                                    <div class="text-sm text-green-800">
                                        <p class="font-medium mb-1">使用優勢：</p>
                                        <ul class="space-y-1 text-xs">
                                            <li>• 自動提取房間尺寸和建築資訊，無需手動輸入</li>
                                            <li>• 保持與原始 Revit 模型的同步更新</li>
                                            <li>• 支援複雜建築幾何和多樓層結構</li>
                                            <li>• 直接從 BIM 模型進行綠建築分析</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 建築方位設定 -->
                    <div>
                        <label class="block font-medium mb-2"><?php echo __('buildingOrientation'); ?></label>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="buildingAngle" class="block text-sm mb-1"><?php echo __('angle'); ?></label>
                                <input
                                    type="number"
                                    id="buildingAngle"
                                    name="buildingAngle"
                                    min="0"
                                    max="360"
                                    class="input-field w-full text-center"
                                    placeholder="<?php echo __('angleExample'); ?>">
                            </div>
                            <div>
                                <label class="block text-sm mb-1"><?php echo __('orientation'); ?></label>
                                <div
                                    id="orientationDisplay"
                                    class="input-field w-full text-center">
                                    <?php echo __('orientationDefault'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 平面圖上傳欄位 -->
                    <div id="floorplanUploadField" class="hidden">
                        <label for="floorplanFile" class="block font-medium mb-2">
                            <i class="fas fa-upload mr-2"></i>選擇平面圖檔案
                        </label>
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 hover:border-green-400 transition-colors">
                            <input
                                type="file"
                                id="floorplanFile"
                                name="floorplanFile"
                                accept="image/*"
                                class="hidden"
                                onchange="handleFileSelect(event)">
                            <div class="text-center" onclick="document.getElementById('floorplanFile').click()">
                                <div class="mb-2">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400"></i>
                                </div>
                                <p class="text-gray-600 mb-1">點擊此處選擇檔案</p>
                                <p class="text-sm text-gray-400">或拖拽檔案到此區域</p>
                                <p class="text-xs text-gray-400 mt-2">支援 JPG、PNG、GIF（最大 10MB）</p>
                            </div>
                        </div>
                        <div id="filePreview" class="hidden mt-4">
                            <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded">
                                <img id="previewImage" src="" alt="預覽" class="w-16 h-16 object-cover rounded">
                                <div class="flex-1">
                                    <p id="fileName" class="font-medium text-gray-900"></p>
                                    <p id="fileSize" class="text-sm text-gray-500"></p>
                                </div>
                                <button type="button" onclick="removeFile()" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>

                        <!-- 比例尺設定 -->
                        <div class="mt-4">
                            <label for="imageScale" class="block font-medium mb-2">圖檔比例尺設定</label>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-gray-600 mb-1">實際長度（公尺）</label>
                                    <input
                                        type="number"
                                        id="realLength"
                                        name="realLength"
                                        value="10"
                                        min="1"
                                        step="0.1"
                                        class="input-field"
                                        placeholder="10">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600 mb-1">圖上長度（像素）</label>
                                    <input
                                        type="number"
                                        id="pixelLength"
                                        name="pixelLength"
                                        value="1000"
                                        min="1"
                                        class="input-field"
                                        placeholder="1000">
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">
                                建議：在圖上測量一段已知長度的距離，輸入對應的像素值和實際公尺數
                            </p>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="btn w-full mt-4">
                        <?php echo __('createProjectButton'); ?>
                    </button>
                </form>

                <!-- 下行為檢查是否登入已使用專案功能的程式結束碼 -->
            <?php endif; ?>
        </div>

        <!-- 表格輸入計算器內容 -->
        <div id="tableCalculatorContent" class="hidden">
            <div id="fixed-buttons">
                <button onclick="handleAdd()"><?php echo __('add'); ?></button>
                <button onclick="handleCopy()"><?php echo __('copy'); ?></button>
                <button onclick="handleDelete()"><?php echo __('delete'); ?></button>
                <button onclick="handleSave()"><?php echo __('save'); ?></button>
                <button onclick="handleCalculate()"><?php echo __('calculate'); ?></button>

                <!-- Speckle 資料檢視按鈕 -->
                <button onclick="viewSpeckleData()" id="viewSpeckleButton" style="display:none;">
                    <i class="fas fa-cube"></i> 檢視 Speckle 資料
                </button>

                <!-- 只有從繪圖模式轉換的表格才會顯示 -->
                <button onclick="switchToDrawingMode()" id="switchToDrawingButton" style="display:none;">
                    <?php echo __('switch'); ?>
                </button>
            </div>

            <div class="project-info-header">
                <h2 id="projectNameDisplay"></h2>
                <div class="unit-orientation">
                    <span><?php echo __('buildingOrientation'); ?>:</span>
                    <input type="number" id="buildingAngleDisplay" class="unit-angle" readonly>
                    <div id="orientationTextDisplay" class="unit-orientation-text">--</div>
                </div>
            </div>

            <div id="buildingContainer">
                <div class="floor" id="floor1">
                    <h3><span><?php echo __('floor'); ?></span> 1</h3>
                    <div class="unit" id="floor1_unit1">
                        <div class="unit-header">
                            <h4><span><?php echo __('unit'); ?></span> 1</h4>
                        </div>

                        <!-- START: Modified structure for multi-wall support -->
                        <div class="room-block" id="floor1_unit1_room1">
                             <div class="room-header">
                                <h5><?php echo __('roomNumber'); ?> 1</h5>
                                <button class="btn btn-sm btn-outline-primary" onclick="addWall('floor1_unit1_room1')">
                                    <i class="fas fa-plus"></i> <?php echo __('addWall', '新增牆面'); ?>
                                </button>
                            </div>
                            <div class="walls-container">
                                <div class="wall-header-row">
                                    <div><?php echo __('wallOrientation'); ?></div>
                                    <div><?php echo __('wallArea'); ?></div>
                                    <div><?php echo __('windowPosition'); ?></div>
                                    <div><?php echo __('windowArea'); ?></div>
                                    <div></div> <!-- Placeholder for delete button -->
                                </div>
                                <!-- Wall rows will be added here by JavaScript -->
                            </div>
                        </div>
                         <!-- END: Modified structure -->

                    </div>
                </div>
            </div>
        </div>


        <!-- Speckle 資料檢視模態框 -->
        <div id="speckleDataModal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 1000px; width: 90%;">
                <div class="modal-header">
                    <h2><i class="fas fa-cube me-2"></i>Speckle 建築資料</h2>
                    <button type="button" onclick="closeSpeckleModal()" style="float: right; background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
                </div>

                <!-- 資料摘要 -->
                <div class="row mb-4" id="speckleSummary">
                    <!-- 將由 JavaScript 動態填入 -->
                </div>

                <!-- 詳細資料表格 -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="speckleDataTable">
                        <thead class="table-dark">
                            <tr>
                                <th>樓層</th>
                                <th>房間編號</th>
                                <th>房間名稱</th>
                                <th>長度 (m)</th>
                                <th>寬度 (m)</th>
                                <th>高度 (m)</th>
                                <th>面積 (m²)</th>
                                <th>窗戶方位</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- 將由 JavaScript 動態填入 -->
                        </tbody>
                    </table>
                </div>

                <!-- 操作按鈕 -->
                <div class="d-flex justify-content-between mt-4">
                    <div>
                        <button type="button" class="btn btn-outline-secondary" onclick="refreshSpeckleData()">
                            <i class="fas fa-sync-alt me-2"></i>重新載入
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="exportSpeckleDataCSV()">
                            <i class="fas fa-download me-2"></i>匯出 CSV
                        </button>
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline-primary" onclick="openSpeckleViewer()">
                            <i class="fas fa-external-link-alt me-2"></i>在 Speckle 中檢視
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeSpeckleModal()">
                            關閉
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 表格輸入按鍵功能區 -->
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

        <!-- 繪圖輸入計算器內容 -->
        <div id="drawingCalculatorContent" class="hidden">
            <!-- 工具列區塊 -->
            <div class="section-card mb-6 border rounded-lg shadow-sm p-4 bg-white">
                <h2 class="text-xl font-bold mb-4"><?php echo __('toolbar_title'); ?></h2>
                <div class="controls flex flex-wrap gap-2 mb-4">
                    <button class="button px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="setDrawMode('outer-wall')">
                        🧱 <?php echo __('drawOuterWall'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="setDrawMode('unit')">
                        🏢 <?php echo __('drawUnit'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="setDrawMode('inner-wall')">
                        🏠 <?php echo __('drawRoom'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="setDrawMode('window')">
                        🪟 <?php echo __('drawWindow'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="setDrawMode('height')">
                        🏗️ <?php echo __('inputRoomHeight'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600" onclick="clearCanvasWithConfirm()">
                        🧽 <?php echo __('clear_canvas_btn'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-yellow-500 text-white rounded hover:bg-yellow-600" onclick="resetArea()">
                        🗑️ <?php echo __('reset_project_btn'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600" onclick="saveProject()">
                        💾 <?php echo __('save_project_btn'); ?>
                    </button>
                    <button class="button px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600" onclick="saveAsProject()">
                        📝 <?php echo __('save_as_btn'); ?>
                    </button>
                    <!-- 新增轉換資料按鈕 -->
                    <button class="button px-4 py-2 bg-purple-500 text-white rounded hover:bg-purple-600" onclick="convertToTable()">
                        📊 <?php echo __('convert_to_table', '轉換資料'); ?>
                    </button>
                </div>
                <!-- 比例尺和縮放控制 -->
                <div class="scale-zoom-controls flex items-center justify-between mb-2 p-2 bg-gray-100 rounded">
                    <div class="scale-controls flex items-center">
                        <span class="mr-2 font-medium"><?php echo __('scale', '比例尺'); ?>:</span>
                        <select id="scaleSelector" class="form-select px-2 py-1 border rounded">
                            <option value="50">1:50</option>
                            <option value="100" selected>1:100</option>
                            <option value="200">1:200</option>
                            <option value="500">1:500</option>
                            <option value="1000">1:1000</option>
                        </select>
                        <span class="ml-4 text-sm text-gray-600" id="scaleInfo">1cm = 1m</span>
                    </div>
                    <div class="zoom-controls flex items-center">
                        <button id="zoomOut" class="px-2 py-1 bg-gray-200 rounded-l hover:bg-gray-300">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8" />
                            </svg>
                        </button>
                        <span id="zoomLevel" class="px-2 py-1 bg-white border-t border-b">100%</span>
                        <button id="zoomIn" class="px-2 py-1 bg-gray-200 rounded-r hover:bg-gray-300">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4" />
                            </svg>
                        </button>
                        <button id="resetView" class="ml-2 px-4 py-2 w-auto bg-gray-200 rounded hover:bg-gray-500">
                            <?php echo __('reset_view', '重置視圖'); ?>
                        </button>
                    </div>
                </div>
                <!-- 吸附網格功能 -->
                <div class="draw-mode-controls flex items-center">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" id="snapToGrid" checked class="mr-2">
                        <?php echo __('snap_to_grid'); ?>
                    </label>
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" id="orthographicMode" class="mr-2">
                        <?php echo __('orthographic_mode'); ?>
                    </label>
                </div>
            </div>

            <!-- 繪圖畫布區域 -->
            <div class="canvas-container border border-gray-300 bg-white w-full relative" style="height: 600px;">
                <canvas id="drawingCanvas" width="1270" height="600" class="w-full h-full"></canvas>
                <div id="gridInfo" class="absolute bottom-2 right-2 bg-white px-2 py-1 text-sm text-gray-600 rounded shadow"></div>
            </div>

            <!-- 高度輸入對話框 -->
            <div id="heightInputDialog" class="fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 
bg-white p-5 rounded-lg shadow-lg z-50" style="display: none;">
                <h3 class="text-lg font-bold mb-3"><?php echo __('modify_height_dialog_title'); ?></h3>
                <div class="input-group flex items-center mb-4">
                    <input type="number" id="buildingHeight" min="0" step="any" class="border rounded px-2 py-1 w-full">
                    <span id="heightUnit" class="ml-2"><?php echo __('unit_m'); ?></span>
                </div>
                <div class="flex justify-end">
                    <button class="button px-4 py-1 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="confirmHeight()">
                        <?php echo __('confirm_btn'); ?>
                    </button>
                    <button class="button ml-2 px-4 py-1 bg-gray-500 text-white rounded hover:bg-gray-600" onclick="cancelHeight()">
                        <?php echo __('cancel_btn'); ?>
                    </button>
                </div>
            </div>

            <!-- 專案儲存對話框 -->
            <div id="saveProjectDialog" class="fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 
bg-white p-5 rounded-lg shadow-lg z-50" style="display: none;">
                <h3 class="text-lg font-bold mb-3"><?php echo __('save_project_dialog_title'); ?></h3>
                <div class="input-group mb-4">
                    <label class="block mb-1"><?php echo __('project_name_label'); ?></label>
                    <input type="text" id="projectName" class="border rounded px-2 py-1 w-full">
                </div>
                <div class="flex justify-end">
                    <button class="button px-4 py-1 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="confirmSaveProject()">
                        <?php echo __('confirm_btn'); ?>
                    </button>
                    <button class="button ml-2 px-4 py-1 bg-gray-500 text-white rounded hover:bg-gray-600" onclick="hideSaveDialog()">
                        <?php echo __('cancel_btn'); ?>
                    </button>
                </div>
            </div>

            <!-- 另存專案對話框 -->
            <div id="saveAsProjectDialog" class="fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 
bg-white p-5 rounded-lg shadow-lg z-50" style="display: none;">
                <h3 class="text-lg font-bold mb-3"><?php echo __('save_as_dialog_title'); ?></h3>
                <div class="input-group mb-4">
                    <label class="block mb-1"><?php echo __('new_project_name_label'); ?></label>
                    <input type="text" id="saveAsProjectName" class="border rounded px-2 py-1 w-full">
                </div>
                <div class="flex justify-end">
                    <button class="button px-4 py-1 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="confirmSaveAsProject()">
                        <?php echo __('confirm_btn'); ?>
                    </button>
                    <button class="button ml-2 px-4 py-1 bg-gray-500 text-white rounded hover:bg-gray-600" onclick="hideSaveAsDialog()">
                        <?php echo __('cancel_btn'); ?>
                    </button>
                </div>
            </div>

            <!-- 專案載入對話框 -->
            <div id="loadProjectDialog" class="fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 
bg-white p-5 rounded-lg shadow-lg z-50" style="display: none;">
                <h3 class="text-lg font-bold mb-3"><?php echo __('load_project_dialog_title'); ?></h3>
                <div class="input-group mb-4">
                    <select id="projectSelect" class="border rounded px-2 py-1 w-full"></select>
                </div>
                <div class="flex justify-end">
                    <button class="button px-4 py-1 bg-blue-500 text-white rounded hover:bg-blue-600" onclick="confirmLoadProject()">
                        <?php echo __('confirm_btn'); ?>
                    </button>
                    <button class="button ml-2 px-4 py-1 bg-gray-500 text-white rounded hover:bg-gray-600" onclick="hideLoadDialog()">
                        <?php echo __('cancel_btn'); ?>
                    </button>
                </div>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    </div>

    <!-- =================================================================== -->
    <!-- ==============  START OF NEW JAVASCRIPT BLOCK  ==================== -->
    <!-- =================================================================== -->
    <script>
        // =================================================================
        // Global Helper Functions (全域輔助函式)
        // =================================================================

        /**
         * 在右上角顯示 Toast 通知
         */
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            if (!container) {
                console.error('Toast container not found!');
                alert(message); // Fallback
                return;
            }

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;

            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('show');
            }, 100);

            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove());
            }, 3500);
        }

        /**
         * 更新導航欄中的當前專案名稱
         */
        function updateCurrentProjectDisplay(projectName) {
            const indicator = document.getElementById('current-project-indicator');
            if (indicator) {
                indicator.textContent = projectName || '尚未選取專案';
            }
        }

        /**
         * 更新建築方位顯示 (用於建立專案表單)
         */
        function updateBuildingOrientation(angle) {
            const display = document.getElementById('orientationDisplay');
            if (!display) return;

            let orientationText = '---';
            const numAngle = parseFloat(angle);

            if (!isNaN(numAngle) && angle.trim() !== '') {
                if (numAngle >= 337.5 || numAngle < 22.5) orientationText = 'N';
                else if (numAngle >= 22.5 && numAngle < 67.5) orientationText = 'NE';
                else if (numAngle >= 67.5 && numAngle < 112.5) orientationText = 'E';
                else if (numAngle >= 112.5 && numAngle < 157.5) orientationText = 'SE';
                else if (numAngle >= 157.5 && numAngle < 202.5) orientationText = 'S';
                else if (numAngle >= 202.5 && numAngle < 247.5) orientationText = 'SW';
                else if (numAngle >= 247.5 && numAngle < 292.5) orientationText = 'W';
                else if (numAngle >= 292.5 && numAngle < 337.5) orientationText = 'NW';
            }
            display.textContent = orientationText;
        }

        /**
         * 新增：更新表格編輯器中的建築方位顯示
         */
        function updateEditorOrientationDisplay(angle) {
            const angleInput = document.getElementById('buildingAngleDisplay');
            const orientationDisplay = document.getElementById('orientationTextDisplay');

            if (angleInput) {
                const numAngle = parseFloat(angle);
                angleInput.value = isNaN(numAngle) ? '' : numAngle.toFixed(1);
            }
            if (!orientationDisplay) return;

            let orientationText = '---';
            const numAngle = parseFloat(angle);

            if (!isNaN(numAngle)) {
                if (numAngle >= 337.5 || numAngle < 22.5) orientationText = 'N';
                else if (numAngle >= 22.5 && numAngle < 67.5) orientationText = 'NE';
                else if (numAngle >= 67.5 && numAngle < 112.5) orientationText = 'E';
                else if (numAngle >= 112.5 && numAngle < 157.5) orientationText = 'SE';
                else if (numAngle >= 157.5 && numAngle < 202.5) orientationText = 'S';
                else if (numAngle >= 202.5 && numAngle < 247.5) orientationText = 'SW';
                else if (numAngle >= 247.5 && numAngle < 292.5) orientationText = 'W';
                else if (numAngle >= 292.5 && numAngle < 337.5) orientationText = 'NW';
            }
            orientationDisplay.textContent = orientationText;
        }

        // =================================================================
        // Main Document Ready Logic (主程式邏輯)
        // =================================================================
        document.addEventListener('DOMContentLoaded', function() {

            const projectCard = document.getElementById('projectCard');
            const tableCalculatorContent = document.getElementById('tableCalculatorContent');
            const currentProjectId = <?php echo json_encode($_SESSION["building_id"] ?? null); ?>;
            const currentProjectName = <?php echo json_encode($_SESSION["current_gbd_project_name"] ?? null); ?>;
            const projectNameDisplay = document.getElementById('projectNameDisplay');


            const projectForm = document.getElementById('projectForm');
            const submitButton = projectForm ? projectForm.querySelector('button[type="submit"]') : null;

            if (projectForm && submitButton) {
                // Attach to button's click event to prevent duplicate submissions from other scripts
                submitButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopImmediatePropagation();

                    const formData = new FormData(projectForm);
                    const projectName = formData.get('projectName').trim();
                    const projectAddress = formData.get('projectAddress').trim();

                    if (!projectName || !projectAddress) {
                        showToast('請填寫所有必填欄位', 'error');
                        return;
                    }

                    formData.append('action', 'createProject');

                    fetch('greenbuildingcal-new.php', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(response => {
                            const contentType = response.headers.get("content-type");
                            if (contentType && contentType.indexOf("application/json") !== -1) {
                                return response.json();
                            } else {
                                return response.text().then(text => {
                                    throw new Error('伺服器回應格式錯誤: ' + text);
                                });
                            }
                        })
                        .then(data => {
                            if (data.success) {
                                showToast('專案創建成功！頁面將重新載入。', 'success');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                showToast(data.message || '發生未知錯誤', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('創建專案時發生錯誤:', error);
                            showToast(error.message, 'error');
                        });
                }, true);

                const buildingAngleInput = projectForm.querySelector('#buildingAngle');
                if (buildingAngleInput) {
                    buildingAngleInput.addEventListener('input', function() {
                        updateBuildingOrientation(this.value);
                    });
                }
            }

            // --- Page Initial Setup ---
            if (currentProjectId) {
                if (projectCard) projectCard.style.display = 'none';
                if (tableCalculatorContent) tableCalculatorContent.style.display = 'block';
                updateCurrentProjectDisplay(currentProjectName);

                // Load existing project data
                fetch(`greenbuildingcal-new.php?action=loadProjectData`)
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            if (projectNameDisplay && result.data.project) {
                                projectNameDisplay.textContent = result.data.project.building_name;
                            }
                            renderProjectData(result.data);
                            // Also update the orientation display from the loaded project data
                            if (result.data.project && result.data.project.building_angle !== null) {
                                updateEditorOrientationDisplay(result.data.project.building_angle);
                            }
                        } else {
                             showToast(result.message || '無法載入專案資料', 'error');
                             renderProjectData(null); // Render default empty state on failure
                        }
                    })
                    .catch(error => {
                        console.error('Error loading project data:', error);
                        showToast('載入專案資料時發生網路錯誤', 'error');
                        renderProjectData(null); // Render default empty state on error
                    });
                
            } else {
                if (projectCard) projectCard.style.display = 'block';
                if (tableCalculatorContent) tableCalculatorContent.style.display = 'none';
                updateCurrentProjectDisplay(null);
                 // Render the default empty state for a new project
                if (projectNameDisplay) {
                    projectNameDisplay.textContent = "<?php echo __('newProject', '新專案'); ?>";
                }
                renderProjectData(null);
            }

            // Initial call to set orientation display based on any existing value
            const initialAngle = document.getElementById('buildingAngle');
            if (initialAngle) {
                updateBuildingOrientation(initialAngle.value);
            }

            // FIX: Add a default wall to the initial room on page load
            if (document.getElementById('floor1_unit1_room1')) {
                addWall('floor1_unit1_room1');
            }
        });

        // =================================================================
        // CRUD and Calculation Functions (增刪改查與計算功能)
        // =================================================================

        /**
         * 處理「儲存」按鈕點擊
         */
        function handleSave() {
            const buildingContainer = document.getElementById('buildingContainer');
            const floorsData = {};
            const floors = buildingContainer.querySelectorAll('.floor');

            floors.forEach(floor => {
                const floorId = floor.id;
                floorsData[floorId] = {
                    units: {}
                };

                const units = floor.querySelectorAll('.unit');
                units.forEach(unit => {
                    const unitId = unit.id;
                    floorsData[floorId].units[unitId] = {
                        rooms: {}
                    };

                    const roomBlocks = unit.querySelectorAll('.room-block');
                    roomBlocks.forEach(roomBlock => {
                        const roomId = roomBlock.id;
                        const roomNumberEl = roomBlock.querySelector('h5');
                        const roomNumber = roomNumberEl ? roomNumberEl.textContent.replace(/[^0-9]/g, '') : '';
                        
                        const walls = [];
                        const wallRows = roomBlock.querySelectorAll('.wall-row');
                        wallRows.forEach(wallRow => {
                            const inputs = wallRow.querySelectorAll('input');
                            if (inputs.length === 4) {
                                const wallData = {
                                    wallOrientation: inputs[0].value,
                                    wallArea: inputs[1].value,
                                    windowPosition: inputs[2].value,
                                    windowArea: inputs[3].value
                                };
                                walls.push(wallData);
                            }
                        });

                        floorsData[floorId].units[unitId].rooms[roomId] = {
                            roomNumber: roomNumber,
                            walls: walls
                        };
                    });
                });
            });


            const dataToSave = {
                floors: floorsData
            };

            fetch('greenbuildingcal-new.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'saveBuildingData',
                        ...dataToSave
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('資料儲存成功！', 'success');
                    } else {
                        showToast('儲存失敗: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error saving data:', error);
                    showToast('儲存時發生網路錯誤', 'error');
                });
        }

        /**
         * 處理「計算」按鈕點擊
         */
        function handleCalculate() {
            showToast('計算功能尚未實作。', 'info');
        }

        /**
         * 顯示主操作 modal
         */
        function handleAdd() {
            document.getElementById('modal').style.display = 'block';
        }

        function handleCopy() {
            updateCopyModalOptions();
            document.getElementById('copyModal').style.display = 'block';
        }

        function handleDelete() {
            updateDeleteModalOptions();
            document.getElementById('deleteModal').style.display = 'block';
        }

        // --- Modal Control ---
        function closeModal() {
            document.getElementById('modal').style.display = 'none';
            hideAllSubModals();
        }

        function closeCopyModal() {
            document.getElementById('copyModal').style.display = 'none';
            hideAllSubModals();
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            hideAllSubModals();
        }

        function hideAllSubModals() {
            document.querySelectorAll('.sub-modal-content').forEach(modal => {
                modal.style.display = 'none';
            });
        }

        function closeSubModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }


        // --- Add Functionality ---
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
                option.textContent = floor.querySelector('h3').textContent.trim();
                select.appendChild(option);
            });
            updateUnitNumber();
            document.getElementById('addUnitContent').style.display = 'block';
        }

        function showAddRoom() {
            hideAllSubModals();
            const floorSelect = document.getElementById('roomFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent.trim();
                floorSelect.appendChild(option);
            });
            updateRoomUnitSelect();
            document.getElementById('addRoomContent').style.display = 'block';
        }

        function addFloor() {
            const floorContainer = document.getElementById('buildingContainer');
            const floorCount = floorContainer.querySelectorAll('.floor').length;
            const newFloorNum = floorCount + 1;

            const newFloor = document.createElement('div');
            newFloor.className = 'floor';
            newFloor.id = `floor${newFloorNum}`;
            const newUnitId = `floor${newFloorNum}_unit1`;
            const newRoomId = `${newUnitId}_room1`;
            newFloor.innerHTML = `
                <h3><span><?php echo __('floor'); ?></span> ${newFloorNum}</h3>
                <div class="unit" id="${newUnitId}">
                    <div class="unit-header">
                        <h4><span><?php echo __('unit'); ?></span> 1</h4>
                    </div>
                    <div class="room-block" id="${newRoomId}">
                        <div class="room-header">
                            <h5><?php echo __('roomNumber'); ?> 1</h5>
                            <button class="btn btn-sm btn-outline-primary" onclick="addWall('${newRoomId}')">
                                <i class="fas fa-plus"></i> <?php echo __('addWall', '新增牆面'); ?>
                            </button>
                        </div>
                        <div class="walls-container">
                            <div class="wall-header-row">
                                <div><?php echo __('wallOrientation'); ?></div>
                                <div><?php echo __('wallArea'); ?></div>
                                <div><?php echo __('windowPosition'); ?></div>
                                <div><?php echo __('windowArea'); ?></div>
                                <div></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            floorContainer.appendChild(newFloor);
            addWall(newRoomId); // Add one default wall row
            closeModal();
        }

        function addUnitPrompt() {
            const floorId = document.getElementById('unitFloorSelect').value;
            const unitNumber = document.getElementById('unitNumber').value;
            if (!floorId || !unitNumber) {
                alert("請選擇樓層並輸入單元編號。");
                return;
            }
            addUnit(floorId, parseInt(unitNumber));
            closeModal();
        }

        function addUnit(floorId, unitNumber) {
            const floorElement = document.getElementById(floorId);
            if (!floorElement) return;

            const newUnitId = `${floorId}_unit${unitNumber}`;
            if (document.getElementById(newUnitId)) {
                alert('該單元編號已存在於此樓層。');
                return;
            }

            const newUnit = document.createElement('div');
            newUnit.className = 'unit';
            newUnit.id = newUnitId;
            const newRoomId = `${newUnitId}_room1`;
            newUnit.innerHTML = `
                <div class="unit-header">
                    <h4><span><?php echo __('unit'); ?></span> ${unitNumber}</h4>
                </div>
                <div class="room-block" id="${newRoomId}">
                    <div class="room-header">
                        <h5><?php echo __('roomNumber'); ?> 1</h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="addWall('${newRoomId}')">
                            <i class="fas fa-plus"></i> <?php echo __('addWall', '新增牆面'); ?>
                        </button>
                    </div>
                    <div class="walls-container">
                        <div class="wall-header-row">
                            <div><?php echo __('wallOrientation'); ?></div>
                            <div><?php echo __('wallArea'); ?></div>
                            <div><?php echo __('windowPosition'); ?></div>
                            <div><?php echo __('windowArea'); ?></div>
                            <div></div>
                        </div>
                    </div>
                </div>
            `;
            floorElement.appendChild(newUnit);
            addWall(newRoomId); // Add one default wall row
        }

        function addRoomPrompt() {
            const unitId = document.getElementById('roomUnitSelect').value;
            if (unitId) {
                addRoom(unitId);
                closeModal();
            }
        }

        function addRoom(unitId) {
            const unitElement = document.getElementById(unitId);
            if (!unitElement) return;

            const roomCount = unitElement.querySelectorAll('.room-block').length;
            const newRoomNum = roomCount + 1;
            const newRoomId = `${unitId}_room${newRoomNum}`;

            const newRoomBlock = document.createElement('div');
            newRoomBlock.className = 'room-block';
            newRoomBlock.id = newRoomId;
            newRoomBlock.innerHTML = `
                <div class="room-header">
                    <h5><?php echo __('roomNumber'); ?> ${newRoomNum}</h5>
                    <button class="btn btn-sm btn-outline-primary" onclick="addWall('${newRoomId}')">
                        <i class="fas fa-plus"></i> <?php echo __('addWall', '新增牆面'); ?>
                    </button>
                </div>
                <div class="walls-container">
                    <div class="wall-header-row">
                        <div><?php echo __('wallOrientation'); ?></div>
                        <div><?php echo __('wallArea'); ?></div>
                        <div><?php echo __('windowPosition'); ?></div>
                        <div><?php echo __('windowArea'); ?></div>
                        <div></div>
                    </div>
                </div>
            `;
            unitElement.appendChild(newRoomBlock);
            addWall(newRoomId); // Add one default wall row
        }


        // --- Delete Functionality ---
        function showDeleteFloor() {
            hideAllSubModals();
            document.getElementById('deleteFloorContent').style.display = 'block';
        }

        function showDeleteUnit() {
            hideAllSubModals();
            document.getElementById('deleteUnitContent').style.display = 'block';
        }

        function showDeleteRoom() {
            hideAllSubModals();
            document.getElementById('deleteRoomContent').style.display = 'block';
        }

        function updateDeleteModalOptions() {
            // Populate Floor Select
            const floorSelect = document.getElementById('deleteFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent.trim();
                floorSelect.appendChild(option);
            });

            // Populate Unit Selects
            const unitFloorSelect = document.getElementById('deleteUnitFloorSelect');
            unitFloorSelect.innerHTML = floorSelect.innerHTML;
            updateDeleteUnitSelect(); // Initial population

            // Populate Room Selects
            const roomFloorSelect = document.getElementById('deleteRoomFloorSelect');
            roomFloorSelect.innerHTML = floorSelect.innerHTML;
            updateDeleteRoomUnitSelect(); // Initial population
        }

        function deleteFloor() {
            const floorId = document.getElementById('deleteFloorSelect').value;
            const floorElement = document.getElementById(floorId);
            if (floorElement && confirm(`確定要刪除 ${floorElement.querySelector('h3').textContent.trim()} 嗎？`)) {
                floorElement.remove();
                renumberUI();
                closeDeleteModal();
            }
        }

        function deleteUnit() {
            const unitId = document.getElementById('deleteUnitSelect').value;
            const unitElement = document.getElementById(unitId);
            if (unitElement && confirm(`確定要刪除 ${unitElement.querySelector('h4').textContent.trim()} 嗎？`)) {
                unitElement.remove();
                renumberUI();
                closeDeleteModal();
            }
        }

        function deleteRoom() {
            const roomId = document.getElementById('deleteRoomSelect').value;
            const roomElement = document.getElementById(roomId);
            if (roomElement && confirm(`確定要刪除 ${roomElement.querySelector('h5').textContent.trim()} 嗎？`)) {
                roomElement.remove();
                renumberUI();
                closeDeleteModal();
            }
        }

        // --- Copy Functionality ---
        function showCopyFloor() {
            hideAllSubModals();
            document.getElementById('copyFloorContent').style.display = 'block';
        }

        function showCopyUnit() {
            hideAllSubModals();
            document.getElementById('copyUnitContent').style.display = 'block';
        }

        function showCopyRoom() {
            hideAllSubModals();
            document.getElementById('copyRoomContent').style.display = 'block';
        }
        
        function updateCopyModalOptions() {
            const floorSelectHTML = Array.from(document.querySelectorAll('.floor')).map(floor =>
                `<option value="${floor.id}">${floor.querySelector('h3').textContent.trim()}</option>`
            ).join('');

            document.getElementById('sourceFloorSelect').innerHTML = floorSelectHTML;
            document.getElementById('sourceUnitFloorSelect').innerHTML = floorSelectHTML;
            document.getElementById('targetUnitFloorSelect').innerHTML = floorSelectHTML;
            document.getElementById('sourceRoomFloorSelect').innerHTML = floorSelectHTML;
            document.getElementById('targetRoomFloorSelect').innerHTML = floorSelectHTML;

            updateSourceUnitSelect();
            updateTargetRoomUnitSelect();
        }

        function copyFloor() {
            const sourceFloorId = document.getElementById('sourceFloorSelect').value;
            const targetFloorNumber = document.getElementById('targetFloorNumber').value;
            const sourceFloor = document.getElementById(sourceFloorId);

            if (!sourceFloor || !targetFloorNumber) {
                alert('請選擇來源樓層並指定目標樓層編號。');
                return;
            }

            const newFloorId = `floor${targetFloorNumber}`;
            if (document.getElementById(newFloorId)) {
                alert('目標樓層編號已存在。');
                return;
            }

            const newFloor = sourceFloor.cloneNode(true);
            newFloor.id = newFloorId;
            newFloor.querySelector('h3').innerHTML = `<span><?php echo __('floor'); ?></span> ${targetFloorNumber}`;

            // Update IDs for all children
            newFloor.querySelectorAll('[id]').forEach(el => {
                el.id = el.id.replace(sourceFloorId, newFloorId);
            });

            document.getElementById('buildingContainer').appendChild(newFloor);
            closeCopyModal();
        }

        function copyUnit() {
            // ... copy unit logic ...
             showToast('複製單元功能尚未實作。', 'info');
        }

        function copyRoom() {
            // ... copy room logic ...
            showToast('複製房間功能尚未實作。', 'info');
        }


        // --- Dynamic Select Updaters ---
        function updateUnitNumber() {
            const floorId = document.getElementById('unitFloorSelect').value;
            const unitCount = document.querySelectorAll(`#${floorId} .unit`).length;
            document.getElementById('unitNumber').value = unitCount + 1;
        }

        function updateRoomUnitSelect() {
            const floorId = document.getElementById('roomFloorSelect').value;
            const unitSelect = document.getElementById('roomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent.trim();
                unitSelect.appendChild(option);
            });
        }

        function updateDeleteUnitSelect() {
            const floorId = document.getElementById('deleteUnitFloorSelect').value;
            const unitSelect = document.getElementById('deleteUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent.trim();
                unitSelect.appendChild(option);
            });
        }

        function updateDeleteRoomUnitSelect() {
            const floorId = document.getElementById('deleteRoomFloorSelect').value;
            const unitSelect = document.getElementById('deleteRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent.trim();
                unitSelect.appendChild(option);
            });
            updateDeleteRoomSelect();
        }

        function updateDeleteRoomSelect() {
            const unitId = document.getElementById('deleteRoomUnitSelect').value;
            const roomSelect = document.getElementById('deleteRoomSelect');
            roomSelect.innerHTML = '';
            // FIX: Query for .room-block instead of .room-row
            document.querySelectorAll(`#${unitId} .room-block`).forEach((roomBlock) => {
                const option = document.createElement('option');
                option.value = roomBlock.id;
                // FIX: Get room number from h5 tag
                const roomTitle = roomBlock.querySelector('h5').textContent.trim();
                option.textContent = roomTitle;
                roomSelect.appendChild(option);
            });
        }

        function updateSourceUnitSelect() {
            const floorId = document.getElementById('sourceUnitFloorSelect').value;
            const unitSelect = document.getElementById('sourceUnitSelect');
            unitSelect.innerHTML = '';
             document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent.trim();
                unitSelect.appendChild(option);
            });
        }

        function updateTargetRoomUnitSelect() {
            const floorId = document.getElementById('targetRoomFloorSelect').value;
            const unitSelect = document.getElementById('targetRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent.trim();
                unitSelect.appendChild(option);
            });
        }

        /**
         * 根據從後端獲取的資料渲染整個表格
         */
        function renderProjectData(data) {
            const buildingContainer = document.getElementById('buildingContainer');
            buildingContainer.innerHTML = ''; // Clear existing content

            if (!data || !data.floors || data.floors.length === 0) {
                // If no data, render a default empty state
                const defaultFloorId = 'floor1';
                const defaultUnitId = 'floor1_unit1';
                const defaultRoomId = 'floor1_unit1_room1';
                const defaultFloor = `
                    <div class="floor" id="${defaultFloorId}">
                        <h3><span><?php echo __('floor'); ?></span> 1</h3>
                        <div class="unit" id="${defaultUnitId}">
                            <div class="unit-header">
                                <h4><span><?php echo __('unit'); ?></span> 1</h4>
                            </div>
                             <div class="room-block" id="${defaultRoomId}">
                                 <div class="room-header">
                                    <h5><?php echo __('roomNumber'); ?> 1</h5>
                                    <button class="btn btn-sm btn-outline-primary" onclick="addWall('${defaultRoomId}')">
                                        <i class="fas fa-plus"></i> <?php echo __('addWall', '新增牆面'); ?>
                                    </button>
                                </div>
                                <div class="walls-container">
                                    <div class="wall-header-row">
                                        <div><?php echo __('wallOrientation'); ?></div>
                                        <div><?php echo __('wallArea'); ?></div>
                                        <div><?php echo __('windowPosition'); ?></div>
                                        <div><?php echo __('windowArea'); ?></div>
                                        <div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
                buildingContainer.innerHTML = defaultFloor;
                addWall(defaultRoomId); // Add a default wall for the empty state
                return;
            }

            data.floors.forEach((floor, floorIndex) => {
                const floorDiv = document.createElement('div');
                const floorId = `floor${floor.floor_number}`;
                floorDiv.className = 'floor';
                floorDiv.id = floorId;
                floorDiv.innerHTML = `<h3><span><?php echo __('floor'); ?></span> ${floor.floor_number}</h3>`;

                if (!floor.units || floor.units.length === 0) {
                    // If a floor has no units, at least show the floor
                } else {
                    floor.units.forEach((unit, unitIndex) => {
                        const unitDiv = document.createElement('div');
                        const unitId = `${floorId}_unit${unit.unit_number}`;
                        unitDiv.className = 'unit';
                        unitDiv.id = unitId;

                        let unitHeaderHTML = `
                            <div class="unit-header">
                                <h4><span><?php echo __('unit'); ?></span> ${unit.unit_number}</h4>
                            </div>`;
                        unitDiv.innerHTML = unitHeaderHTML;

                        if (!unit.rooms || unit.rooms.length === 0) {
                             // Add a default room block if there are no rooms in a unit
                            const roomId = `${unitId}_room1`;
                            const roomBlock = document.createElement('div');
                            roomBlock.className = 'room-block';
                            roomBlock.id = roomId;
                            roomBlock.innerHTML = `
                                <div class="room-header">
                                    <h5><?php echo __('roomNumber'); ?> 1</h5>
                                    <button class="btn btn-sm btn-outline-primary" onclick="addWall('${roomId}')">
                                        <i class="fas fa-plus"></i> <?php echo __('addWall', '新增牆面'); ?>
                                    </button>
                                </div>
                                <div class="walls-container">
                                    <div class="wall-header-row">
                                        <div><?php echo __('wallOrientation'); ?></div>
                                        <div><?php echo __('wallArea'); ?></div>
                                        <div><?php echo __('windowPosition'); ?></div>
                                        <div><?php echo __('windowArea'); ?></div>
                                        <div></div>
                                    </div>
                                </div>`;
                            unitDiv.appendChild(roomBlock);
                            addWall(roomId);
                        } else {
                            unit.rooms.forEach(room => {
                                const roomId = `${unitId}_room${room.room_number}`;
                                const roomBlock = document.createElement('div');
                                roomBlock.className = 'room-block';
                                roomBlock.id = roomId;
                                roomBlock.innerHTML = `
                                    <div class="room-header">
                                        <h5><?php echo __('roomNumber'); ?> ${room.room_number}</h5>
                                         <button class="btn btn-sm btn-outline-primary" onclick="addWall('${roomId}')">
                                            <i class="fas fa-plus"></i> <?php echo __('addWall', '新增牆面'); ?>
                                        </button>
                                    </div>
                                    <div class="walls-container">
                                        <div class="wall-header-row">
                                            <div><?php echo __('wallOrientation'); ?></div>
                                            <div><?php echo __('wallArea'); ?></div>
                                            <div><?php echo __('windowPosition'); ?></div>
                                            <div><?php echo __('windowArea'); ?></div>
                                            <div></div>
                                        </div>
                                    </div>
                                `;

                                const wallsContainer = roomBlock.querySelector('.walls-container');
                                if (room.walls && room.walls.length > 0) {
                                    room.walls.forEach(wall => {
                                        const wallRow = document.createElement('div');
                                        wallRow.className = 'wall-row';
                                        wallRow.innerHTML = `
                                            <input type="text" value="${wall.wall_orientation || ''}" placeholder="<?php echo __('wallOrientation'); ?>">
                                            <input type="text" value="${wall.wall_area || ''}" placeholder="<?php echo __('wallArea'); ?>">
                                            <input type="text" value="${wall.window_position || ''}" placeholder="<?php echo __('windowPosition'); ?>">
                                            <input type="text" value="${wall.window_area || ''}" placeholder="<?php echo __('windowArea'); ?>">
                                            <button class="delete-wall-btn" onclick="this.parentElement.remove()">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        `;
                                        wallsContainer.appendChild(wallRow);
                                    });
                                } else {
                                    // If a room exists but has no walls, add one empty wall row
                                    addWall(roomId);
                                }
                                unitDiv.appendChild(roomBlock);
                            });
                        }
                        floorDiv.appendChild(unitDiv);
                    });
                }
                buildingContainer.appendChild(floorDiv);
            });
        }

        /**
         * 新增：動態新增一個牆面輸入列
         */
        function addWall(roomBlockId) {
            const wallsContainer = document.querySelector(`#${roomBlockId} .walls-container`);
            if (!wallsContainer) return;

            const wallRow = document.createElement('div');
            wallRow.className = 'wall-row';
            wallRow.innerHTML = `
                <input type="text" placeholder="<?php echo __('wallOrientation'); ?>">
                <input type="text" placeholder="<?php echo __('wallArea'); ?>">
                <input type="text" placeholder="<?php echo __('windowPosition'); ?>">
                <input type="text" placeholder="<?php echo __('windowArea'); ?>">
                <button class="delete-wall-btn" onclick="this.parentElement.remove()">
                    <i class="fas fa-trash-alt"></i>
                </button>
            `;
            wallsContainer.appendChild(wallRow);
        }

        /**
         * 新增：重新編號整個UI介面
         */
        function renumberUI() {
            const floors = document.querySelectorAll('#buildingContainer .floor');
            floors.forEach((floor, floorIndex) => {
                const newFloorNum = floorIndex + 1;
                const newFloorId = `floor${newFloorNum}`;

                // Update floor header and ID
                floor.querySelector('h3').innerHTML = `<span><?php echo __('floor'); ?></span> ${newFloorNum}`;
                floor.id = newFloorId;

                const units = floor.querySelectorAll('.unit');
                units.forEach((unit, unitIndex) => {
                    const newUnitNum = unitIndex + 1;
                    const newUnitId = `${newFloorId}_unit${newUnitNum}`;

                    // Update unit header and ID
                    unit.querySelector('h4').innerHTML = `<span><?php echo __('unit'); ?></span> ${newUnitNum}`;
                    unit.id = newUnitId;

                    const roomBlocks = unit.querySelectorAll('.room-block');
                    roomBlocks.forEach((roomBlock, roomIndex) => {
                        const newRoomNum = roomIndex + 1;
                        const newRoomId = `${newUnitId}_room${newRoomNum}`;

                        // Update room header and ID
                        roomBlock.querySelector('h5').textContent = `<?php echo __('roomNumber'); ?> ${newRoomNum}`;
                        roomBlock.id = newRoomId;

                        // Update the addWall button's onclick attribute
                        const addWallButton = roomBlock.querySelector('button');
                        if (addWallButton) {
                            addWallButton.setAttribute('onclick', `addWall('${newRoomId}')`);
                        }
                    });
                });
            });
        }

    </script>
    <!-- =================================================================== -->
    <!-- ==============  END OF NEW JAVASCRIPT BLOCK  ====================== -->
    <!-- =================================================================== -->
</body>

</html>