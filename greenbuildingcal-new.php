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
    }

    if ($isAjax && isset($_POST['action']) && $_POST['action'] === 'saveDrawingData') {
        handleSaveDrawingData();
        exit;
    } elseif ($isAjax && isset($_SERVER['CONTENT_TYPE']) && 
               strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        handleSaveDrawingData();
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
    $projectType = $_POST['type'] ?? ''; // 獲取專案類型
    
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
                // 準備 SQL 語句
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
            
        } catch(PDOException $e) {
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
        
        // 先清除現有數據（可選，視具體需求）
        /*
        $stmtClearRooms = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_rooms] WHERE unit_id IN (SELECT unit_id FROM [Test].[dbo].[GBD_Project_units] WHERE floor_id IN (SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id))");
        $stmtClearRooms->execute([':building_id' => $building_id]);
        
        $stmtClearUnits = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_units] WHERE floor_id IN (SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id)");
        $stmtClearUnits->execute([':building_id' => $building_id]);
        
        $stmtClearFloors = $conn->prepare("DELETE FROM [Test].[dbo].[GBD_Project_floors] WHERE building_id = :building_id");
        $stmtClearFloors->execute([':building_id' => $building_id]);
        */
        
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

// 處理繪圖輸入資料保存
function handleSaveDrawingData() {
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
function checkProjectHasData() {
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
    
    <!-- 引入 Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />

    <style>
        body {
            margin-top: 100px; /* 確保 navbar 不會擋住主內容 */
            padding: 0;
            /* background-image: url('https://i.imgur.com/WJGtbFT.jpeg'); */
            background-color: rgba(255, 255, 255, 0.8);
            background-size: 100% 100%; /* 使背景圖片填滿整個背景區域 */
            background-position: center; /* 背景圖片居中 */
            background-repeat: no-repeat; /* 不重複背景圖片 */
            background-attachment: fixed; /* 背景固定在視口上 */
        }

        .navbar-brand {
            font-weight: bold;
            }

        #container {
            display: flex;
            flex-direction: column;
            align-items: flex-start; /* 讓內容靠左對齊 */
            max-width: 70%;
            margin: 0 auto;
            padding: 20px;
        }

        #buildingContainer {
            /* max-width: 70%; 調整最大寬度，避免內容過寬 */
            margin: 0 auto; /* 讓內容在螢幕中央 */
            padding: 20px; /* 增加內邊距，避免太靠邊 */
        }

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

        button {
            margin-top: 10px;
            padding: 10px;
            border-radius: 12px;
            background-color: #769a76; /* 設定基本顏色 */
            color: white;
            border: none;
            cursor: pointer;
        }

            button:hover {
                background-color: #87ab87; /* 懸停時顏色略微變亮 */
            }

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
        background-color: #769a76; /* 這裡可以換成你要的顏色 */
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
            overflow: hidden; /* 防止內容溢出 */
            border: 1px solid #e5e7eb;
            background-color: white;
        }
        
        #drawingCanvas {
            touch-action: none; /* 防止移動設備上的默認觸摸行為 */
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
            pointer-events: none; /* 允許點擊穿透 */
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

    </style>
</head>
<body>
<?php include('navbar.php'); ?>

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
                        required
                    >
                </div>
                
                <div>
                    <label for="projectAddress" class="block font-medium"><?php echo __('projectAddress'); ?></label>
                    <input
                        type="text"
                        id="projectAddress"
                        name="projectAddress"
                        class="input-field"
                        placeholder="<?php echo __('projectAddress'); ?>"
                        required
                    >
                </div>
                
                <!-- 新增資料輸入方式選擇 -->
                <div class="mt-4">
                    <label class="block font-medium mb-2"><?php echo __('inputMethod'); ?></label>
                    <div class="flex space-x-4">
                        <div class="flex items-center">
                            <input
                                type="radio"
                                id="tableInput"
                                name="inputMethod"
                                value="table"
                                class="mr-2"
                                checked
                            >
                            <label for="tableInput"><?php echo __('tableInput'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input
                                type="radio"
                                id="drawingInput"
                                name="inputMethod"
                                value="drawing"
                                class="mr-2"
                            >
                            <label for="drawingInput"><?php echo __('drawingInput'); ?></label>
                        </div>
                    </div>
                </div>

                <button 
                    type="submit"
                    class="btn w-full"
                >
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
        
            <!-- 只有從繪圖模式轉換的表格才會顯示 -->
            <button onclick="switchToDrawingMode()" id="switchToDrawingButton" style="display:none;">
                <?php echo __('switch'); ?>
            </button>
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
                            <path d="M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8"/>
                        </svg>
                    </button>
                    <span id="zoomLevel" class="px-2 py-1 bg-white border-t border-b">100%</span>
                    <button id="zoomIn" class="px-2 py-1 bg-gray-200 rounded-r hover:bg-gray-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4"/>
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

<!-- 表格輸入區域以及網頁基本script -->
    <script defer>
        function handleCreateProject(event) {
            event.preventDefault();
            
            // 獲取表單數據
            const projectName = document.getElementById('projectName').value;
            const projectAddress = document.getElementById('projectAddress').value;
            const inputMethod = document.querySelector('input[name="inputMethod"]:checked').value;

            // 切換顯示
            document.getElementById('projectCard').classList.add('hidden');
            
            // 根據選擇的輸入方式顯示對應的計算器內容
            if (inputMethod === 'table') {
                document.getElementById('tableCalculatorContent').classList.remove('hidden');
                document.getElementById('drawingCalculatorContent').classList.add('hidden');
            } else if (inputMethod === 'drawing') {
                document.getElementById('tableCalculatorContent').classList.add('hidden');
                document.getElementById('drawingCalculatorContent').classList.remove('hidden');
            }
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
                
                // 根據選擇的輸入方式顯示對應的計算器內容
                const inputMethod = formData.get('inputMethod');
                if (inputMethod === 'table') {
                    document.getElementById('tableCalculatorContent').classList.remove('hidden');
                    document.getElementById('drawingCalculatorContent').classList.add('hidden');
                } else if (inputMethod === 'drawing') {
                    document.getElementById('tableCalculatorContent').classList.add('hidden');
                    document.getElementById('drawingCalculatorContent').classList.remove('hidden');
                }
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
        fetch('greenbuildingcal-new.php?action=saveBuildingData', {
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

<script>
        // 表單提交時
        $("#projectForm").submit(function(e) {
            e.preventDefault();
            
            $.ajax({
                url: "greenbuildingcal-new.php",
                type: "POST",
                data: {
                    action: "createProject",
                    projectName: $("#projectName").val(),
                    projectAddress: $("#projectAddress").val()
                },
                success: function(response) {
                if (response.success) {
                    // 儲存專案ID和名稱
                    var projectId = response.building_id;
                    var projectName = $("#projectName").val();
                    
                    // 更新前端UI
                    updateCurrentProject(projectId, projectName);
                    
                    // 額外AJAX呼叫來更新PHP session
                    $.ajax({
                        url: 'greenbuildingcal-new.php',
                        type: 'POST',
                        data: {
                            project_id: projectId,
                            project_name: projectName
                        },
                        dataType: 'json',
                        success: function(sessionResponse) {
                            console.log('Session updated:', sessionResponse);
                        },
                        error: function(xhr, status, error) {
                            console.error('Session update error:', error);
                        }
                    });
                    
                    // 顯示成功訊息
                    alert(response.message);
                } else {
                    // 顯示錯誤訊息
                    alert(response.message);
                }
            },
            });
        });
    </script>
    
    <!-- 繪圖區域script -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 獲取畫布和上下文
        const canvas = document.getElementById('drawingCanvas');
        const ctx = canvas.getContext('2d');
        
        // 當前選擇的工具
        let currentTool = null;
        
        // 繪製狀態
        let isDrawing = false;
        let firstClick = true; // 是否為第一次點擊
        let lastX, lastY; // 最後一個點的位置
        let firstPointX, firstPointY; // 存儲第一個點的位置，用於閉合圖形
        let isNewShape = true; // 標記是否為新圖形
        
        // 新增全域變數來追蹤外牆資訊
        let wallGroups = [];
        let floorCounter = 1; // 自動遞增的樓層編號
        let currentFloor = 1; // 當前正在繪製的樓層
        let floors = []; // 存儲樓層資訊
        let floorUnitCounters = {}; // 記錄每個樓層的單元計數器

        // 添加 UNIT 相關變數
        let unitCounter = 1; // 全局單元計數器
        let units = []; // 存儲單元數據
        let unitRoomCounters = {}; // 存儲每個單元的房間計數器
        
        // 添加 ROOM 相關變數
        let roomCounter = 1;
        let rooms = []; // 存儲房間數據

        // 存儲當前正在繪製的形狀的點
        let currentShapePoints = [];
        
        // 儲存所有已經繪製的元素
        let drawnElements = [];
        let showAreas = true; // 控制是否顯示面積
        
        // 網格設置
        const gridSize = 20; // 網格大小（像素）
        
        // 縮放和比例尺設置
        let currentScale = 100; // 當前比例尺 1:100
        let zoomLevel = 1.0;    // 當前縮放級別
        const MIN_ZOOM = 0.5;   // 最小縮放級別
        const MAX_ZOOM = 5.0;   // 最大縮放級別
        let panOffset = { x: 0, y: 0 }; // 平移偏移量
        let isPanning = false;  // 是否正在平移
        let lastPanPosition = { x: 0, y: 0 }; // 上次平移位置
            
        // 顏色設置
        const COLORS = {
            OUTER_WALL: '#708090', // 更改為淺灰色 (Slate Gray)
            INNER_WALL: '#D2B48C', // 更改為淺棕色 (Tan)
            INNER_WALL_FILL: 'rgba(210, 180, 140, 0.7)', // 實體填充顏色
            UNIT: '#ac7aac',      // 單元顏色 (深棕色)
            UNIT_FILL: 'rgba(172, 122, 172, 0.3)', // 半透明填充顏色
            WINDOW: '#3498db'      // 保持藍色不變
        };
        
        // 閉合距離閾值（像素）
        const CLOSE_THRESHOLD = gridSize / 2;
        
        // 初始化繪圖區域
        function initDrawing() {
            // 初始化縮放和比例尺
            initScaleAndZoom();
            
            // 清除畫布
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // 繪製網格
            drawGrid();
            
            // 初始化事件監聽
            initEventListeners();
        }
        
        // 繪製網格 
        function drawGrid() { 
            ctx.beginPath(); 
            ctx.strokeStyle = '#e0e0e0'; 
            ctx.lineWidth = 0.5;

            // 確定網格尺寸
            const gridWidth = 10;  // 水平網格間距為10像素
            const gridHeight = 10;  // 垂直網格間距為10像素

            // 計算整個畫布的範圍（與縮放和平移無關）
            const canvasLeft = -panOffset.x / zoomLevel;
            const canvasTop = -panOffset.y / zoomLevel;
            const canvasRight = (canvas.width - panOffset.x) / zoomLevel;
            const canvasBottom = (canvas.height - panOffset.y) / zoomLevel;
            
            // 擴展繪製範圍以確保覆蓋所有可能的視口區域
            // 添加額外的緩衝區，確保平移時有足夠的網格
            const buffer = Math.max(canvas.width, canvas.height) / zoomLevel;
            const startX = Math.floor((canvasLeft - buffer) / gridWidth) * gridWidth;
            const startY = Math.floor((canvasTop - buffer) / gridHeight) * gridHeight;
            const endX = Math.ceil((canvasRight + buffer) / gridWidth) * gridWidth;
            const endY = Math.ceil((canvasBottom + buffer) / gridHeight) * gridHeight;

            // 繪製水平線 (擴展範圍)
            for (let y = startY; y <= endY; y += gridHeight) { 
                const transformedY1 = reverseTransformCoordinate({x: 0, y: y}).y;
                ctx.moveTo(0, transformedY1); 
                ctx.lineTo(canvas.width, transformedY1); 
            }

            // 繪製垂直線 (擴展範圍)
            for (let x = startX; x <= endX; x += gridWidth) { 
                const transformedX1 = reverseTransformCoordinate({x: x, y: 0}).x;
                ctx.moveTo(transformedX1, 0); 
                ctx.lineTo(transformedX1, canvas.height); 
            }

            ctx.stroke(); 
        }

        // 初始化縮放和比例尺功能
        function initScaleAndZoom() {
            // 設置比例尺選擇器事件
            const scaleSelector = document.getElementById('scaleSelector');
            if (scaleSelector) {
                scaleSelector.addEventListener('change', function() {
                    currentScale = parseInt(this.value);
                    
                    // 更新比例尺信息顯示
                    updateScaleInfo();
                    
                    // 重繪畫布
                    redrawCanvas();
                });
            }
            
            // 縮放按鈕事件
            const zoomInBtn = document.getElementById('zoomIn');
            if (zoomInBtn) {
                zoomInBtn.addEventListener('click', function() {
                    setZoom(zoomLevel * 1.25);
                });
            }
            
            const zoomOutBtn = document.getElementById('zoomOut');
            if (zoomOutBtn) {
                zoomOutBtn.addEventListener('click', function() {
                    setZoom(zoomLevel / 1.25);
                });
            }
            
            // 重置視圖按鈕
            const resetViewBtn = document.getElementById('resetView');
            if (resetViewBtn) {
                resetViewBtn.addEventListener('click', function() {
                    resetView();
                });
            }
            
            // 滾輪縮放
            canvas.addEventListener('wheel', function(e) {
                e.preventDefault();
                
                // 計算縮放前的鼠標位置
                const rect = canvas.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;
                
                // 確定縮放方向並設置新的縮放級別
                const zoomDirection = e.deltaY < 0 ? 1.1 : 0.9;
                const newZoom = zoomLevel * zoomDirection;
                
                // 應用縮放並調整平移偏移以保持鼠標位置不變
                if (newZoom >= MIN_ZOOM && newZoom <= MAX_ZOOM) {
                    // 計算新舊縮放比例
                    const scaleFactor = newZoom / zoomLevel;
                    
                    // 調整偏移量，使鼠標位置保持不變
                    panOffset.x = mouseX - (mouseX - panOffset.x) * scaleFactor;
                    panOffset.y = mouseY - (mouseY - panOffset.y) * scaleFactor;
                    
                    // 設置新縮放級別
                    setZoom(newZoom);
                }
            });
            
            // 平移功能（按住空格鍵 + 拖動）
            document.addEventListener('keydown', function(e) {
                if (e.code === 'Space' && !isPanning) {
                    enablePanMode();
                }
            });
            
            document.addEventListener('keyup', function(e) {
                if (e.code === 'Space') {
                    disablePanMode();
                }
            });
            
            // 添加中鍵拖動平移功能
            canvas.addEventListener('mousedown', function(e) {
                // 中鍵 (鼠標滾輪按下)
                if (e.button === 1 || (e.button === 0 && isPanning)) {
                    e.preventDefault();
                    startPan(e.clientX, e.clientY);
                }
            });
            
            // 更新比例尺信息
            updateScaleInfo();
        }
        
        // 初始化事件監聽
        function initEventListeners() {
            // 對齊網格切換
            const snapToGridCheckbox = document.getElementById('snapToGrid');
            if (snapToGridCheckbox) {
                snapToGridCheckbox.addEventListener('change', function() {
                    // 重新繪製
                    redrawCanvas();
                });
            }

            // 正交模式切換
            const orthoModeCheckbox = document.getElementById('orthographicMode');
            if (orthoModeCheckbox) {
                orthoModeCheckbox.addEventListener('change', function() {
                    // 重新繪製
                    redrawCanvas();
                });
            }
            
            // 畫布事件
            canvas.addEventListener('click', handleCanvasClick);
            canvas.addEventListener('mousemove', function(e) {
                drawPreview(e);
                showGridInfo(e);
            });
            canvas.addEventListener('mouseout', function() {
                // 鼠標移出時只重繪，不停止繪製
                redrawCanvas();
            });
            canvas.addEventListener('dblclick', endDrawing); // 雙擊結束繪製
        }
        
        // 設置繪圖模式
        window.setDrawMode = function(mode) {
            currentTool = mode;
            
            // 重置繪製狀態
            firstClick = true;
            isNewShape = true;
            lastX = null;
            lastY = null;
            currentShapePoints = []; // 清空當前形狀點
            
            // 移除所有按鈕的活動狀態
            document.querySelectorAll('.controls .button').forEach(btn => {
                btn.classList.remove('bg-blue-700');
            });
            
            // 設置當前按鈕為活動狀態
            const btnSelector = `.controls .button[onclick*="setDrawMode('${mode}')"]`;
            const activeBtn = document.querySelector(btnSelector);
            if (activeBtn) {
                activeBtn.classList.add('bg-blue-700');
            }
            
            console.log(`當前工具: ${mode}`);
        };
        
        // 對齊網格
        function snapCoordinateToGrid(coord) {
            const snapToGridCheckbox = document.getElementById('snapToGrid');
            if (snapToGridCheckbox && !snapToGridCheckbox.checked) return coord;
            return Math.round(coord / gridSize) * gridSize;
        }

        // 設置縮放級別
        function setZoom(newZoom) {
            if (newZoom < MIN_ZOOM) newZoom = MIN_ZOOM;
            if (newZoom > MAX_ZOOM) newZoom = MAX_ZOOM;
            
            zoomLevel = newZoom;
            
            // 更新縮放顯示
            const zoomLevelElem = document.getElementById('zoomLevel');
            if (zoomLevelElem) {
                zoomLevelElem.textContent = `${Math.round(zoomLevel * 100)}%`;
            }
            
            // 重繪畫布
            redrawCanvas();
        }

        // 啟用平移模式
        function enablePanMode() {
            isPanning = true;
            canvas.style.cursor = 'grab';
        }

        // 禁用平移模式
        function disablePanMode() {
            isPanning = false;
            canvas.style.cursor = 'default';
        }

        // 開始平移
        function startPan(clientX, clientY) {
            isPanning = true;
            canvas.style.cursor = 'grabbing';
            
            lastPanPosition = {
                x: clientX,
                y: clientY
            };
            
            // 添加鼠標移動和鬆開事件
            document.addEventListener('mousemove', handlePan);
            document.addEventListener('mouseup', endPan);
        }

        // 處理平移
        function handlePan(e) {
            if (!isPanning) return;
            
            const deltaX = e.clientX - lastPanPosition.x;
            const deltaY = e.clientY - lastPanPosition.y;
            
            panOffset.x += deltaX;
            panOffset.y += deltaY;
            
            lastPanPosition = {
                x: e.clientX,
                y: e.clientY
            };
            
            // 重繪畫布
            redrawCanvas();
        }

        // 結束平移
        function endPan() {
            isPanning = false;
            canvas.style.cursor = 'default';
            
            // 移除事件監聽器
            document.removeEventListener('mousemove', handlePan);
            document.removeEventListener('mouseup', endPan);
        }

        // 重置視圖
        function resetView() {
            zoomLevel = 1.0;
            panOffset = { x: 0, y: 0 };
            
            const zoomLevelElem = document.getElementById('zoomLevel');
            if (zoomLevelElem) {
                zoomLevelElem.textContent = '100%';
            }
            
            // 重繪畫布
            redrawCanvas();
        }

        // 重繪畫布函數 (確保更新所有元素)
        function redrawCanvas() {
            // 清除整個畫布
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // 繪製網格
            drawGrid();
            
            // 重繪所有元素
            redrawAllElements();
        }

        // 更新比例尺信息
        function updateScaleInfo() {
            const scaleInfoElem = document.getElementById('scaleInfo');
            if (scaleInfoElem) {
                scaleInfoElem.textContent = `1cm = ${currentScale / 100}m`;
            }
        }

        // 轉換座標：考慮縮放和平移
        function transformCoordinate(point) {
            return {
                x: (point.x - panOffset.x) / zoomLevel,
                y: (point.y - panOffset.y) / zoomLevel
            };
        }

        // 反轉座標變換：從畫布座標到實際座標
        function reverseTransformCoordinate(point) {
            return {
                x: point.x * zoomLevel + panOffset.x,
                y: point.y * zoomLevel + panOffset.y
            };
        }

        // 更新網格信息顯示，添加實際尺寸
        function showGridInfo(e) {
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            // 轉換為原始座標
            const originalPoint = transformCoordinate({ x, y });
            
            // 取得對齊網格的座標
            const gridX = snapCoordinateToGrid(originalPoint.x);
            const gridY = snapCoordinateToGrid(originalPoint.y);
            
            // 計算實際尺寸（以米為單位）
            const realX = (gridX * currentScale / gridSize / 100).toFixed(2);
            const realY = (gridY * currentScale / gridSize / 100).toFixed(2);
            
            // 更新網格信息顯示
            const gridInfo = document.getElementById('gridInfo');
            if (gridInfo) {
                gridInfo.textContent = `X: ${gridX}px (${realX}m), Y: ${gridY}px (${realY}m)`;
            }
        }
        
        // 檢查是否回到第一個點（用於閉合形狀）
        function isCloseToFirstPoint(x, y) {
            if (!firstPointX || !firstPointY) return false;
            
            const distance = Math.sqrt(
                Math.pow(firstPointX - x, 2) + 
                Math.pow(firstPointY - y, 2)
            );
            
            return distance <= CLOSE_THRESHOLD;
        }
        
        // 處理畫布點擊事件
        function handleCanvasClick(e) {
            if (!currentTool) return;
            
            const rect = canvas.getBoundingClientRect();
            const rawX = e.clientX - rect.left;
            const rawY = e.clientY - rect.top;
            
            // 轉換為相對於實際畫布的座標（考慮縮放和平移）
            const transformedPoint = transformCoordinate({x: rawX, y: rawY});
            
            // 對齊網格
            let x = snapCoordinateToGrid(transformedPoint.x);
            let y = snapCoordinateToGrid(transformedPoint.y);
            
            // 正交模式邏輯
            const orthographicMode = document.getElementById('orthographicMode').checked;
            
            if (orthographicMode && !firstClick && lastX !== undefined && lastY !== undefined) {
                // 計算從上一個點到當前點的水平和垂直距離
                const deltaX = Math.abs(x - lastX);
                const deltaY = Math.abs(y - lastY);
                
                // 根據距離較大的方向來決定固定座標
                if (deltaX > deltaY) {
                    // 水平移動更明顯，保持 y 座標不變
                    y = lastY;
                } else {
                    // 垂直移動更明顯，保持 x 座標不變
                    x = lastX;
                }
            }
            if (currentTool === 'height') {
                // 顯示高度輸入對話框
                showHeightInputDialog(x, y);
                return;
            }
            
            // 如果是牆類型工具
            if (currentTool === 'outer-wall' || currentTool === 'inner-wall' || currentTool === 'unit') {
                // 如果接近第一個點，閉合圖形
                if (!isNewShape && !firstClick && isCloseToFirstPoint(x, y)) {
                    // 添加最後一條線連回到第一個點
                    drawnElements.push({
                        type: currentTool,
                        x1: lastX,
                        y1: lastY,
                        x2: firstPointX,
                        y2: firstPointY
                    });
                    
                    // 添加當前形狀的最後一個點
                    currentShapePoints.push({x: lastX, y: lastY});
                    
                    // 如果是外牆，標記為新樓層
                    if (currentTool === 'outer-wall') {
                        const newFloorNumber = markAsNewFloor([...currentShapePoints]);
                        console.log(`創建了新樓層: ${newFloorNumber}`);
                    }
                    // 如果是 UNIT，添加填充區域和記錄 unit 資訊
                    else if (currentTool === 'unit') {
                        // 檢查這個單元在哪個樓層內
                        let containingFloor = null;
                        
                        // 遍歷所有樓層，檢查單元的中心點是否在樓層內
                        const center = calculatePolygonCentroid([...currentShapePoints]);
                        for (const floor of floors) {
                            if (isPointInPolygon(center, floor.points)) {
                                containingFloor = floor.number;
                                break;
                            }
                        }
                        
                        // 如果沒找到包含的樓層，則使用當前樓層
                        if (!containingFloor) {
                            containingFloor = currentFloor;
                        }
                        
                        // 根據所屬樓層分配單元編號
                        floorUnitCounters[containingFloor] = (floorUnitCounters[containingFloor] || 0) + 1;
                        const unitNumber = `${containingFloor}-${floorUnitCounters[containingFloor]}`;
                        
                        // 在單元添加處（unit-fill 部分）
                        const unitArea = calculatePolygonArea([...currentShapePoints]);
                        const unitRealArea = convertToRealArea(unitArea);

                        // 添加單元資訊
                        units.push({
                            number: unitNumber, // 使用 "樓層-編號" 格式
                            floorNumber: containingFloor, // 記錄所屬樓層
                            points: [...currentShapePoints],
                            center: center,
                            area: unitRealArea  // 添加面積信息
                        });
                        
                        // 初始化該單元的房間計數器
                        unitRoomCounters[unitNumber] = 0;
                        
                        // 添加填充元素
                        drawnElements.push({
                            type: 'unit-fill',
                            points: [...currentShapePoints],
                            number: unitNumber,
                            center: center
                        });
                        
                        console.log(`創建了新單元: ${unitNumber}, 所屬樓層: ${containingFloor}`);
                    } else if (currentTool === 'inner-wall') {
                        // 為 ROOM 添加房間號
                        const center = calculatePolygonCentroid([...currentShapePoints]);
                        
                        // 檢查這個房間在哪個 unit 內
                        let containingUnit = null;
                        for (const unit of units) {
                            if (isPointInPolygon(center, unit.points)) {
                                containingUnit = unit.number;
                                break;
                            }
                        }
                        
                        // 如果沒找到包含的單元，檢查這個房間在哪個樓層內
                        let containingFloor = null;
                        if (!containingUnit) {
                            for (const floor of floors) {
                                if (isPointInPolygon(center, floor.points)) {
                                    containingFloor = floor.number;
                                    break;
                                }
                            }
                            
                            // 如果還是沒找到，使用當前樓層
                            if (!containingFloor) {
                                containingFloor = currentFloor;
                            }
                        }
                        
                        let roomNumber;
                        if (containingUnit) {
                            // 如果在某個 unit 內，使用格式 "unit-room"
                            unitRoomCounters[containingUnit]++;
                            roomNumber = `${containingUnit}-${unitRoomCounters[containingUnit]}`;
                        } else if (containingFloor) {
                            // 如果不在任何 unit 內但在某個樓層內，使用格式 "floor-room"
                            roomNumber = `F${containingFloor}-R${roomCounter++}`;
                        } else {
                            // 如果都沒有，使用普通房間號
                            roomNumber = `R${roomCounter++}`;
                        }
                        
                        // 在房間添加處（room-fill 部分）
                        const roomArea = calculatePolygonArea([...currentShapePoints]);
                        const roomRealArea = convertToRealArea(roomArea);

                        // 添加房間資訊
                        rooms.push({
                            number: roomNumber,
                            points: [...currentShapePoints],
                            center: center,
                            containingUnit: containingUnit,
                            containingFloor: containingFloor,
                            area: roomRealArea  // 添加面積信息
                        });
                        
                        // 添加填充元素
                        drawnElements.push({
                            type: 'room-fill',
                            points: [...currentShapePoints],
                            number: roomNumber,
                            center: center,
                            containingUnit: containingUnit,
                            containingFloor: containingFloor
                        });
                        
                        console.log(`創建了新房間: ${roomNumber}, 所屬單元: ${containingUnit}, 所屬樓層: ${containingFloor}`);
                    }

                    // 重置繪製狀態為新圖形
                    firstClick = true;
                    isNewShape = true;
                    lastX = null;
                    lastY = null;
                    currentShapePoints = []; // 清空當前形狀的點
                    
                    // 重繪
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    drawGrid();
                    redrawAllElements();
                    return;
                }
                
                // 如果不是第一次點擊，則完成一個線段
                if (!firstClick) {
                    drawnElements.push({
                        type: currentTool,
                        x1: lastX,
                        y1: lastY,
                        x2: x,
                        y2: y
                    });
                    
                    // 添加當前點到形狀點列表
                    currentShapePoints.push({x: lastX, y: lastY});
                    
                    // 更新最後一個點
                    lastX = x;
                    lastY = y;
                } else {
                    // 第一次點擊，記錄起點
                    firstClick = false;
                    isNewShape = false;
                    firstPointX = x;
                    firstPointY = y;
                    lastX = x;
                    lastY = y;
                    
                    // 清空並重新開始收集形狀點
                    currentShapePoints = [{x: x, y: y}];
                }
                
                // 重繪所有元素
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawGrid();
                redrawAllElements();
            }
            // 對於窗戶工具，維持原有的行為（點擊-拖拽-釋放）
            else if (currentTool === 'window') {
                if (firstClick) {
                    lastX = x;
                    lastY = y;
                    firstClick = false;
                } else {
                    // 添加窗戶元素
                    drawnElements.push({
                        type: 'window',
                        x1: Math.min(lastX, x),
                        y1: Math.min(lastY, y),
                        x2: Math.max(lastX, x),
                        y2: Math.max(lastY, y)
                    });
                    
                    firstClick = true;
                    
                    // 重繪所有元素
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    drawGrid();
                    redrawAllElements();
                }
            }
        }

        // 添加函數：標記閉合的外牆為一個樓層
        function markAsNewFloor(points) {
            // 當閉合外牆時被調用
            const floorNumber = floorCounter++;
            
            // 初始化該樓層的單元計數器
            floorUnitCounters[floorNumber] = 0;
            
            // 計算樓層面積
            const floorArea = calculatePolygonArea(points);
            const floorRealArea = convertToRealArea(floorArea);
            
            // 計算樓層中心點位置
            const center = calculatePolygonCentroid(points);
            
            // 添加樓層資訊
            floors.push({
                number: floorNumber,
                points: [...points],
                center: center,
                area: floorRealArea
            });
            
            // 設定當前樓層
            currentFloor = floorNumber;
            
            // 在閉合的外牆左上方添加樓層標記
            const topLeft = {
                x: Math.min(...points.map(p => p.x)),
                y: Math.min(...points.map(p => p.y))
            };
            
            // 添加樓層標記元素
            drawnElements.push({
                type: 'floor-label',
                x: topLeft.x - 30, // 向左偏移
                y: topLeft.y - 30, // 向上偏移
                floorNumber: floorNumber
            });
            
            return floorNumber;
        }

        // 添加一個函數來檢查點是否在多邊形內
        function isPointInPolygon(point, polygon) {
            let inside = false;
            for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
                const xi = polygon[i].x;
                const yi = polygon[i].y;
                const xj = polygon[j].x;
                const yj = polygon[j].y;
                
                const intersect = ((yi > point.y) !== (yj > point.y)) &&
                    (point.x < (xj - xi) * (point.y - yi) / (yj - yi) + xi);
                if (intersect) inside = !inside;
            }
            return inside;
        }

        // 改進計算多邊形中心點的函數
        function calculatePolygonCentroid(points) {
            let area = 0;
            let cx = 0;
            let cy = 0;
            const len = points.length;
            
            // 特殊情況處理: 如果點數少於3，使用簡單平均
            if (len < 3) {
                return {
                    x: points.reduce((sum, pt) => sum + pt.x, 0) / len,
                    y: points.reduce((sum, pt) => sum + pt.y, 0) / len
                };
            }
            
            // 使用多邊形質心算法計算中心
            for (let i = 0; i < len; i++) {
                const j = (i + 1) % len;
                const cross = points[i].x * points[j].y - points[j].x * points[i].y;
                area += cross;
                cx += (points[i].x + points[j].x) * cross;
                cy += (points[i].y + points[j].y) * cross;
            }
            
            // 面積可能為負數，取絕對值
            area = Math.abs(area / 2);
            
            // 處理面積為0的情況 (線段或點)
            if (area === 0) {
                return {
                    x: points.reduce((sum, pt) => sum + pt.x, 0) / len,
                    y: points.reduce((sum, pt) => sum + pt.y, 0) / len
                };
            }
            
            cx = cx / (6 * area);
            cy = cy / (6 * area);
            
            // 確保不返回NaN
            if (isNaN(cx) || isNaN(cy)) {
                return {
                    x: points.reduce((sum, pt) => sum + pt.x, 0) / len,
                    y: points.reduce((sum, pt) => sum + pt.y, 0) / len
                };
            }
            
            return {x: Math.abs(cx), y: Math.abs(cy)};
        }

        // 添加面積計算函數
        function calculatePolygonArea(points) {
            // 如果點數少於3，無法形成多邊形，面積為0
            if (!points || points.length < 3) return 0;
            
            let area = 0;
            const len = points.length;
            
            // 使用鞋帶公式(Shoelace formula)計算多邊形面積
            for (let i = 0; i < len; i++) {
                const j = (i + 1) % len;
                area += points[i].x * points[j].y;
                area -= points[j].x * points[i].y;
            }
            
            area = Math.abs(area / 2);
            return area;
        }

        // 將計算得到的像素面積轉換為實際面積（平方米）
        function convertToRealArea(pixelArea) {
            // 根據網格大小和比例尺轉換
            // 1 格網格 = gridSize 像素 = currentScale/100 米
            // 因此 1 像素 = (currentScale/100)/gridSize 米
            const conversionFactor = (currentScale/100)/gridSize;
            return pixelArea * Math.pow(conversionFactor, 2);
        }

        // 為面積顯示添加切換功能
        window.toggleAreaDisplay = function() {
            showAreas = !showAreas;
            redrawCanvas();
            
            // 更新按鈕狀態（如果有的話）
            const toggleBtn = document.getElementById('toggleArea');
            if (toggleBtn) {
                if (showAreas) {
                    toggleBtn.classList.add('bg-blue-700');
                    toggleBtn.textContent = '隱藏面積';
                } else {
                    toggleBtn.classList.remove('bg-blue-700');
                    toggleBtn.textContent = '顯示面積';
                }
            }
        };

        // 繪製預覽
        function drawPreview(e) {
            if (!currentTool) return;
            
            const rect = canvas.getBoundingClientRect();
            const rawX = e.clientX - rect.left;
            const rawY = e.clientY - rect.top;
            
            // 轉換為相對於實際畫布的座標（考慮縮放和平移）
            const transformedPoint = transformCoordinate({x: rawX, y: rawY});
            
            // 對齊網格
            let currentX = snapCoordinateToGrid(transformedPoint.x);
            let currentY = snapCoordinateToGrid(transformedPoint.y);
            
            // 正交模式邏輯
            const orthographicMode = document.getElementById('orthographicMode').checked;
            
            if (orthographicMode && !firstClick && (currentTool === 'outer-wall' || currentTool === 'inner-wall' || currentTool === 'unit')) {
                // 計算從上一個點到當前點的水平和垂直距離
                const deltaX = Math.abs(currentX - lastX);
                const deltaY = Math.abs(currentY - lastY);
                
                // 根據距離較大的方向來決定固定座標
                if (deltaX > deltaY) {
                    // 水平移動更明顯，保持 y 座標不變
                    currentY = lastY;
                } else {
                    // 垂直移動更明顯，保持 x 座標不變
                    currentX = lastX;
                }
            }
            // 清除畫布並重繪
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            drawGrid();
            redrawAllElements();
            
            // 如果已經有一個點並且不是第一次點擊，則繪製預覽線和顯示距離
            if (!firstClick && (currentTool === 'outer-wall' || currentTool === 'inner-wall' || currentTool === 'unit')) {
                // 繪製從上一個點到當前鼠標位置的預覽線
                if (currentTool === 'outer-wall') {
                    drawWall(lastX, lastY, currentX, currentY, COLORS.OUTER_WALL, 5);
                    
                    // 顯示距離
                    displayDistance(lastX, lastY, currentX, currentY);
                    
                    // 如果接近第一個點，顯示閉合提示
                    if (!isNewShape && isCloseToFirstPoint(currentX, currentY)) {
                        // 繪製閉合提示標記
                        ctx.beginPath();
                        ctx.arc(firstPointX, firstPointY, 7, 0, Math.PI * 2);
                        ctx.fillStyle = 'rgba(255, 0, 0, 0.5)';
                        ctx.fill();
                    }
                } else if (currentTool === 'inner-wall') {
                    drawWall(lastX, lastY, currentX, currentY, COLORS.INNER_WALL, 3);
                    
                    // 顯示距離
                    displayDistance(lastX, lastY, currentX, currentY);
                    
                    // 如果接近第一個點，顯示閉合提示
                    if (!isNewShape && isCloseToFirstPoint(currentX, currentY)) {
                        // 繪製閉合提示標記
                        ctx.beginPath();
                        ctx.arc(firstPointX, firstPointY, 7, 0, Math.PI * 2);
                        ctx.fillStyle = 'rgba(255, 0, 0, 0.5)';
                        ctx.fill();
                    }
                } else if (currentTool === 'unit') {
                    drawWall(lastX, lastY, currentX, currentY, COLORS.UNIT, 3);
                    
                    // 顯示距離
                    displayDistance(lastX, lastY, currentX, currentY);
                    
                    // 如果接近第一個點，顯示閉合提示
                    if (!isNewShape && isCloseToFirstPoint(currentX, currentY)) {
                        // 繪製閉合提示標記
                        ctx.beginPath();
                        ctx.arc(firstPointX, firstPointY, 7, 0, Math.PI * 2);
                        ctx.fillStyle = 'rgba(255, 0, 0, 0.5)';
                        ctx.fill();
                    }
                }
            }
            // 對於窗戶，如果已經點擊了第一個點，繪製預覽窗戶
            else if (!firstClick && currentTool === 'window') {
                drawWindow(
                    Math.min(lastX, currentX),
                    Math.min(lastY, currentY),
                    Math.max(lastX, currentX),
                    Math.max(lastY, currentY)
                );
                
                // 顯示距離（窗戶的寬度和高度）
                const width = Math.abs(currentX - lastX);
                const height = Math.abs(currentY - lastY);
                
                // 轉換為實際距離（米）
                const realWidth = (width * currentScale / gridSize / 100).toFixed(2);
                const realHeight = (height * currentScale / gridSize / 100).toFixed(2);
                
                // 顯示窗戶尺寸
                const midX = (lastX + currentX) / 2;
                const midY = (lastY + currentY) / 2;
                const transformedMid = reverseTransformCoordinate({x: midX, y: midY});
                
                ctx.font = `${12 * zoomLevel}px Arial`;
                ctx.fillStyle = '#000000';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(`${realWidth}m × ${realHeight}m`, transformedMid.x, transformedMid.y);
            }
        }
        
        // 結束繪製（雙擊）
        function endDrawing() {
            // 重置繪製狀態
            firstClick = true;
            isNewShape = true;
            lastX = null;
            lastY = null;
            currentShapePoints = []; // 清空當前形狀的點
            
            // 重繪
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            drawGrid();
            redrawAllElements();
        }
        
        // 重繪所有元素
        function redrawAllElements() {
            // 先繪製填充區域
            drawnElements.forEach(element => {
                if (element.type === 'unit-fill') {
                    // 轉換所有點的座標
                    const transformedPoints = element.points.map(point => reverseTransformCoordinate(point));
                    drawPolygon(transformedPoints, COLORS.UNIT_FILL);
                } else if (element.type === 'room-fill') {
                    // 轉換所有點的座標
                    const transformedPoints = element.points.map(point => reverseTransformCoordinate(point));
                    drawPolygon(transformedPoints, COLORS.INNER_WALL_FILL);
                }
            });
            
            // 再繪製線條和其他元素
            drawnElements.forEach(element => {
                if (element.type === 'floor-label') {
                    // 繪製樓層標籤，需要轉換座標
                    const transformedPosition = reverseTransformCoordinate({
                        x: element.x, 
                        y: element.y
                    });
                    
                    ctx.font = `bold ${18 * zoomLevel}px Arial`; // 加粗並放大字體
                    ctx.fillStyle = '#333333'; // 深灰色
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'top';
                    ctx.fillText(`Floor ${element.floorNumber}`, transformedPosition.x, transformedPosition.y);
                }
                else if (element.type === 'unit-fill') {
                    // 修改單元標籤顯示格式
                    const transformedCenter = reverseTransformCoordinate(element.center);
                    
                    // 繪製單元號，從 "樓層-編號" 提取單元號碼部分
                    const unitParts = element.number.split('-');
                    const unitDisplayNumber = unitParts[1]; // 只取編號部分
                    
                    ctx.font = `${16 * zoomLevel}px Arial`;
                    ctx.fillStyle = '#000000';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(`Unit ${unitDisplayNumber}`, transformedCenter.x, transformedCenter.y);
                } else if (element.type === 'room-fill') {
                    // 繪製房間號
                    const transformedCenter = reverseTransformCoordinate(element.center);
                    ctx.font = `${14 * zoomLevel}px Arial`; // 縮放字體大小
                    ctx.fillStyle = '#000000';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(element.number, transformedCenter.x, transformedCenter.y);
                } else {
                    // 繪製其他元素，需要轉換所有座標
                    switch (element.type) {
                        case 'outer-wall':
                            const transformedWallStart = reverseTransformCoordinate({x: element.x1, y: element.y1});
                            const transformedWallEnd = reverseTransformCoordinate({x: element.x2, y: element.y2});
                            drawWall(
                                transformedWallStart.x, transformedWallStart.y, 
                                transformedWallEnd.x, transformedWallEnd.y, 
                                COLORS.OUTER_WALL, 5 * zoomLevel // 縮放線寬
                            );
                            break;
                        case 'inner-wall':
                            const transformedInnerWallStart = reverseTransformCoordinate({x: element.x1, y: element.y1});
                            const transformedInnerWallEnd = reverseTransformCoordinate({x: element.x2, y: element.y2});
                            drawWall(
                                transformedInnerWallStart.x, transformedInnerWallStart.y, 
                                transformedInnerWallEnd.x, transformedInnerWallEnd.y, 
                                COLORS.INNER_WALL, 3 * zoomLevel // 縮放線寬
                            );
                            break;
                        case 'unit':
                            const transformedUnitStart = reverseTransformCoordinate({x: element.x1, y: element.y1});
                            const transformedUnitEnd = reverseTransformCoordinate({x: element.x2, y: element.y2});
                            drawWall(
                                transformedUnitStart.x, transformedUnitStart.y, 
                                transformedUnitEnd.x, transformedUnitEnd.y, 
                                COLORS.UNIT, 3 * zoomLevel // 縮放線寬
                            );
                            break;
                        case 'window':
                            const transformedWindowStart = reverseTransformCoordinate({x: element.x1, y: element.y1});
                            const transformedWindowEnd = reverseTransformCoordinate({x: element.x2, y: element.y2});
                            drawWindow(
                                transformedWindowStart.x, transformedWindowStart.y,
                                transformedWindowEnd.x, transformedWindowEnd.y
                            );
                            break;
                        case 'height':
                            // 轉換高度標記的座標
                            const transformedHeight = reverseTransformCoordinate({x: element.x, y: element.y});
                            
                            // 在指定位置顯示高度
                            ctx.font = `${14 * zoomLevel}px Arial`; // 縮放字體大小
                            ctx.fillStyle = '#ff5500';
                            ctx.fillText(`H: ${element.height}m`, transformedHeight.x, transformedHeight.y);
                            
                            // 繪製高度標記
                            ctx.beginPath();
                            ctx.arc(transformedHeight.x, transformedHeight.y, 5 * zoomLevel, 0, Math.PI * 2); // 縮放圓形大小
                            ctx.fillStyle = '#ff5500';
                            ctx.fill();
                            break;
                    }
                }
            });
            
            // 如果正在繪製中，繪製當前點的標記
            if (!firstClick && (currentTool === 'outer-wall' || currentTool === 'inner-wall' || currentTool === 'unit')) {
                // 轉換當前點和第一個點的座標
                const transformedLastPoint = reverseTransformCoordinate({x: lastX, y: lastY});
                
                ctx.beginPath();
                ctx.arc(transformedLastPoint.x, transformedLastPoint.y, 5 * zoomLevel, 0, Math.PI * 2); // 縮放圓形大小
                let fillColor;
                if (currentTool === 'outer-wall') {
                    fillColor = COLORS.OUTER_WALL;
                } else if (currentTool === 'inner-wall') {
                    fillColor = COLORS.INNER_WALL;
                } else if (currentTool === 'unit') {
                    fillColor = COLORS.UNIT;
                }
                ctx.fillStyle = fillColor;
                ctx.fill();
                
                // 如果不是新形狀，繪製第一個點的標記（用於閉合識別）
                if (!isNewShape) {
                    const transformedFirstPoint = reverseTransformCoordinate({x: firstPointX, y: firstPointY});
                    
                    ctx.beginPath();
                    ctx.arc(transformedFirstPoint.x, transformedFirstPoint.y, 5 * zoomLevel, 0, Math.PI * 2); // 縮放圓形大小
                    ctx.strokeStyle = 'red';
                    ctx.lineWidth = 2 * zoomLevel; // 縮放線寬
                    ctx.stroke();
                }
            }
        }

        // 添加繪製多邊形的函數
        function drawPolygon(points, fillColor) {
            if (!points || points.length < 3) return;
            
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            
            for (let i = 1; i < points.length; i++) {
                ctx.lineTo(points[i].x, points[i].y);
            }
            
            ctx.closePath();
            ctx.fillStyle = fillColor;
            ctx.fill();
        }
        
        // 繪製牆
        function drawWall(x1, y1, x2, y2, color, width) {
            ctx.beginPath();
            ctx.moveTo(x1, y1);
            ctx.lineTo(x2, y2);
            ctx.strokeStyle = color;
            ctx.lineWidth = width;
            ctx.stroke();
        }
        
        // 繪製窗戶
        function drawWindow(x1, y1, x2, y2) {
            // 計算窗戶的寬度和高度
            const width = Math.abs(x2 - x1);
            const height = Math.abs(y2 - y1);
            const startX = Math.min(x1, x2);
            const startY = Math.min(y1, y2);
            
            // 繪製窗戶框
            ctx.beginPath();
            ctx.rect(startX, startY, width, height);
            ctx.strokeStyle = COLORS.WINDOW;
            ctx.lineWidth = 2 * zoomLevel; // 縮放線寬
            ctx.stroke();
            
            // 填充半透明藍色
            ctx.fillStyle = 'rgba(52, 152, 219, 0.3)';
            ctx.fillRect(startX, startY, width, height);
        }
        
        // 添加顯示距離的函數
        function displayDistance(x1, y1, x2, y2) {
            // 計算像素距離
            const pixelDistance = Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
            
            // 轉換為實際距離（米）
            const realDistance = (pixelDistance * currentScale / gridSize / 100).toFixed(2);
            
            // 計算線段中點位置
            const midX = (x1 + x2) / 2;
            const midY = (y1 + y2) / 2;
            
            // 轉換為畫布座標（應用縮放和平移）
            const transformedMid = reverseTransformCoordinate({x: midX, y: midY});
            
            // 創建一個背景矩形，使文字更容易閱讀
            const text = `${realDistance}m`;
            const textWidth = ctx.measureText(text).width + 10;
            
            ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
            ctx.fillRect(transformedMid.x - textWidth/2, transformedMid.y - 10, textWidth, 20);
            
            // 顯示距離文字
            ctx.font = `bold ${12 * zoomLevel}px Arial`;
            ctx.fillStyle = '#000000';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, transformedMid.x, transformedMid.y);
            
            // 如果是正交模式，也顯示水平和垂直距離
            const orthographicMode = document.getElementById('orthographicMode').checked;
            if (orthographicMode) {
                const deltaX = Math.abs(x2 - x1);
                const deltaY = Math.abs(y2 - y1);
                
                // 只有當有明顯的水平或垂直分量時才顯示
                if (deltaX > 5 && deltaY > 5) {
                    const realDeltaX = (deltaX * currentScale / gridSize / 100).toFixed(2);
                    const realDeltaY = (deltaY * currentScale / gridSize / 100).toFixed(2);
                    
                    // 顯示水平距離
                    const horizontalMidX = (x1 + x2) / 2;
                    const horizontalMidY = y1;
                    const transformedHorizontalMid = reverseTransformCoordinate({
                        x: horizontalMidX, 
                        y: horizontalMidY - 15 / zoomLevel // 向上偏移以避免與線重疊
                    });
                    
                    ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
                    ctx.fillRect(transformedHorizontalMid.x - textWidth/2, transformedHorizontalMid.y - 10, textWidth, 20);
                    
                    ctx.fillStyle = '#0066cc';
                    ctx.fillText(`x: ${realDeltaX}m`, transformedHorizontalMid.x, transformedHorizontalMid.y);
                    
                    // 顯示垂直距離
                    const verticalMidX = x1;
                    const verticalMidY = (y1 + y2) / 2;
                    const transformedVerticalMid = reverseTransformCoordinate({
                        x: verticalMidX - 15 / zoomLevel, // 向左偏移以避免與線重疊
                        y: verticalMidY
                    });
                    
                    ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
                    ctx.fillRect(transformedVerticalMid.x - textWidth/2, transformedVerticalMid.y - 10, textWidth, 20);
                    
                    ctx.fillStyle = '#cc6600';
                    ctx.fillText(`y: ${realDeltaY}m`, transformedVerticalMid.x, transformedVerticalMid.y);
                }
            }
        }

        // 顯示高度輸入對話框
        function showHeightInputDialog(x, y) {
            // 儲存當前點擊位置
            window.currentHeightPoint = {x, y};
            
            // 顯示對話框
            document.getElementById('heightInputDialog').style.display = 'block';
            document.getElementById('buildingHeight').value = '';
            document.getElementById('buildingHeight').focus();
        }
        
        // 確認高度輸入
        window.confirmHeight = function() {
            const heightInput = document.getElementById('buildingHeight');
            const height = heightInput.value.trim();
            
            if (height !== '') {
                // 獲取之前保存的點位置
                const point = window.currentHeightPoint;
                
                // 保存高度標記
                drawnElements.push({
                    type: 'height',
                    x: point.x,
                    y: point.y,
                    height: height
                });
                
                // 重繪所有元素
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawGrid();
                redrawAllElements();
            }
            
            // 關閉對話框
            document.getElementById('heightInputDialog').style.display = 'none';
        };
        
        // 取消高度輸入
        window.cancelHeight = function() {
            document.getElementById('heightInputDialog').style.display = 'none';
        };
        
        // 清除畫布（帶確認）
        window.clearCanvasWithConfirm = function() {
            if (confirm("<?php echo __('confirm_clear_canvas'); ?>")) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawGrid();
                drawnElements = []; // 清除所有繪製元素
                rooms = []; // 清除房間數據
                units = []; // 清除單元數據
                roomCounter = 1; // 重置房間計數器
                unitCounter = 1; // 重置單元計數器
                unitRoomCounters = {}; // 重置單元房間計數器
                firstClick = true;
                isNewShape = true;
                lastX = null;
                lastY = null;
                currentShapePoints = []; // 清空當前形狀的點
            }
        };
        
        // 重置整個工作區
        window.resetArea = function() {
            if (confirm("<?php echo __('confirm_reset_project'); ?>")) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawGrid();
                drawnElements = []; // 清除所有繪製元素
                rooms = []; // 清除房間數據
                units = []; // 清除單元數據
                roomCounter = 1; // 重置房間計數器
                unitCounter = 1; // 重置單元計數器
                unitRoomCounters = {}; // 重置單元房間計數器
                firstClick = true;
                isNewShape = true;
                lastX = null;
                lastY = null;
                currentShapePoints = []; // 清空當前形狀的點
            }
        };
        
        //模型轉資料表
        window.convertToTable = function() {
            console.log('Convert to Table triggered');
            
            if (floors.length === 0) {
                alert('請先繪製建築模型，至少需要一個樓層');
                return;
            }

            localStorage.setItem('convertedFromDrawing', 'true');

            const buildingContainer = document.getElementById('buildingContainer');
            buildingContainer.innerHTML = '';

            // 按樓層順序創建表格
            floors.sort((a, b) => a.number - b.number).forEach(floor => {
                const floorNumber = floor.number;
                
                const floorDiv = document.createElement('div');
                floorDiv.className = 'floor';
                floorDiv.id = `floor${floorNumber}`;
                floorDiv.innerHTML = `<h3 style="text-align: center;">樓層 ${floorNumber}</h3>`;
                
                // 找出該樓層的所有單元
                const unitsInFloor = units.filter(unit => {
                    return parseInt(unit.floorNumber) === floorNumber;
                });
                
                if (unitsInFloor.length === 0) {
                    floorDiv.innerHTML += `<p style="text-align: center;">此樓層尚未繪製單元</p>`;
                } else {
                    // 按照單元編號排序
                    unitsInFloor.sort((a, b) => {
                        const aUnitNumber = parseInt(a.number.split('-')[1]);
                        const bUnitNumber = parseInt(b.number.split('-')[1]);
                        return aUnitNumber - bUnitNumber;
                    }).forEach(unit => {
                        const unitParts = unit.number.split('-');
                        const unitNumber = unitParts[1]; // 獲取單元編號
                        
                        // 找出該單元的所有房間
                        const roomsInUnit = rooms.filter(room => room.containingUnit === unit.number);

                        const unitDiv = document.createElement('div');
                        unitDiv.className = 'unit';
                        unitDiv.id = `floor${floorNumber}_unit${unitNumber}`;
                        unitDiv.innerHTML = `<h4>單元 ${unitNumber}</h4>
                            <div class="header-row">
                                <div>房間編號</div>
                                <div>高度</div>
                                <div>面積 (m²)</div>
                                <div>窗戶位置</div>
                            </div>`;

                        if (roomsInUnit.length === 0) {
                            unitDiv.innerHTML += `<p>此單元尚未繪製房間</p>`;
                        } else {
                            roomsInUnit.forEach((room, roomIndex) => {
                                const roomDiv = document.createElement('div');
                                roomDiv.className = 'room-row';
                                roomDiv.id = `floor${floorNumber}_unit${unitNumber}_room${roomIndex + 1}`;
                                
                                roomDiv.innerHTML = `
                                    <input type="text" value="${room.number}" placeholder="房間編號" />
                                    <input type="text" placeholder="高度" value="" />
                                    <input type="text" value="${room.area.toFixed(2)}" placeholder="面積" readonly />
                                    <input type="text" placeholder="窗戶位置" value="" />
                                `;

                                unitDiv.appendChild(roomDiv);
                            });
                        }

                        floorDiv.appendChild(unitDiv);
                    });
                }
                
                // 找出直接屬於該樓層但不屬於任何單元的房間
                const roomsDirectlyInFloor = rooms.filter(room => {
                    return !room.containingUnit && room.containingFloor === floorNumber;
                });
                
                if (roomsDirectlyInFloor.length > 0) {
                    const nonUnitDiv = document.createElement('div');
                    nonUnitDiv.className = 'unit';
                    nonUnitDiv.id = `floor${floorNumber}_nonUnit`;
                    nonUnitDiv.innerHTML = `<h4>非單元區域</h4>
                        <div class="header-row">
                            <div>房間編號</div>
                            <div>高度</div>
                            <div>面積 (m²)</div>
                            <div>窗戶位置</div>
                        </div>`;
                        
                    roomsDirectlyInFloor.forEach((room, roomIndex) => {
                        const roomDiv = document.createElement('div');
                        roomDiv.className = 'room-row';
                        roomDiv.id = `floor${floorNumber}_nonUnit_room${roomIndex + 1}`;
                        
                        roomDiv.innerHTML = `
                            <input type="text" value="${room.number}" placeholder="房間編號" />
                            <input type="text" placeholder="高度" value="" />
                            <input type="text" value="${room.area.toFixed(2)}" placeholder="面積" readonly />
                            <input type="text" placeholder="窗戶位置" value="" />
                        `;

                        nonUnitDiv.appendChild(roomDiv);
                    });
                    
                    floorDiv.appendChild(nonUnitDiv);
                }
                
                buildingContainer.appendChild(floorDiv);
            });

            // 切換到表格模式
            document.getElementById('tableCalculatorContent').classList.remove('hidden');
            document.getElementById('drawingCalculatorContent').classList.add('hidden');

            const switchButton = document.getElementById('switchToDrawingButton');
            if (switchButton) {
                switchButton.style.display = 'block';
            }
        };

        // 新增函數：根據單元的繪製順序分組樓層
        function groupUnitsByFloor(units) {
            const floorGroups = [];
            let currentFloorUnits = [];

            units.forEach((unit, index) => {
                currentFloorUnits.push(unit);

                // 如果下一個單元的點不在同一個範圍內，視為新的樓層
                if (index < units.length - 1) {
                    const currentCenter = calculatePolygonCentroid(unit.points || []);
                    const nextCenter = calculatePolygonCentroid(units[index + 1].points || []);

                    // 可以根據中心點的距離或其他邏輯判斷是否為同一樓層
                    const distance = Math.sqrt(
                        Math.pow(nextCenter.x - currentCenter.x, 2) + 
                        Math.pow(nextCenter.y - currentCenter.y, 2)
                    );

                    // 如果距離超過一定閾值，視為新樓層
                    if (distance > 500) {  // 這個閾值可以根據實際繪圖情況調整
                        floorGroups.push(currentFloorUnits);
                        currentFloorUnits = [];
                    }
                }
            });

            // 加入最後一組
            if (currentFloorUnits.length > 0) {
                floorGroups.push(currentFloorUnits);
            }

            return floorGroups;
        }

        //轉換資料表
        window.switchToDrawingMode = function() {
            console.log('Switch to Drawing Mode triggered');
            
            // 獲取元素
            const tableContent = document.getElementById('tableCalculatorContent');
            const drawingContent = document.getElementById('drawingCalculatorContent');
            const switchButton = document.getElementById('switchToDrawingButton');

            // 調試日誌
            console.log('Table Content:', tableContent);
            console.log('Drawing Content:', drawingContent);
            console.log('Switch Button:', switchButton);

            if (tableContent && drawingContent) {
                // 切換內容
                tableContent.classList.add('hidden');
                drawingContent.classList.remove('hidden');
                
                // 隱藏切換按鈕
                if (switchButton) {
                    switchButton.style.display = 'none';
                }
                
                // 移除轉換標記
                localStorage.removeItem('convertedFromDrawing');
            } else {
                console.error('Unable to find content elements');
                alert('無法切換到繪圖模式');
            }
        };

        //儲存專案
        window.saveProject = function() {
            const currentProjectName = '<?php echo $_SESSION["current_gbd_project_name"] ?? ""; ?>';
            
            if (!currentProjectName) {
                alert("請先選擇或建立專案");
                return;
            }

            if (floors.length === 0) {
                alert("請先繪製建築模型，至少需要一個樓層");
                return;
            }

            const saveData = {
                projectName: currentProjectName,
                floors: []
            };

            // 從樓層和單元收集資料
            floors.forEach(floor => {
                const floorNumber = floor.number;
                
                // 找出該樓層的所有單元
                const floorUnits = units.filter(unit => {
                    return parseInt(unit.floorNumber) === floorNumber;
                });
                
                const unitsData = floorUnits.map(unit => {
                    const unitNumber = unit.number.split('-')[1]; // 單元編號
                    
                    // 找出該單元的所有房間
                    const roomsInUnit = rooms.filter(r => r.containingUnit === unit.number);
                    
                    const roomsData = roomsInUnit.map(room => {
                        return {
                            number: room.number,
                            area: room.area,
                            coordinates: room.points || [],
                            height: null,
                            windowPosition: null
                        };
                    });
                    
                    return {
                        number: unitNumber,
                        area: unit.area,
                        coordinates: unit.points || [],
                        rooms: roomsData
                    };
                });
                
                // 找出直接屬於該樓層但不屬於任何單元的房間
                const roomsDirectlyInFloor = rooms.filter(room => {
                    return !room.containingUnit && room.containingFloor === floorNumber;
                });
                
                const directRoomsData = roomsDirectlyInFloor.map(room => {
                    return {
                        number: room.number,
                        area: room.area,
                        coordinates: room.points || [],
                        height: null,
                        windowPosition: null
                    };
                });
                
                saveData.floors.push({
                    number: floorNumber,
                    area: floor.area,
                    height: null,
                    coordinates: floor.points || [],
                    units: unitsData,
                    directRooms: directRoomsData // 添加直接屬於樓層的房間
                });
            });

            // 發送儲存請求
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    action: 'saveDrawingData',
                    projectData: saveData
                })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    console.log('Deleted records:', result.deletedCounts);
                    alert("繪圖資料已成功儲存");
                } else {
                    console.error('Save error:', result);
                    alert(result.message || "儲存繪圖資料時發生錯誤");
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert("儲存繪圖資料時發生錯誤");
            });
        };

        // 添加一個函數來清除所有數據
        window.clearAllData = function() {
            if (confirm("確定要清除所有數據嗎？此操作無法撤銷。")) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawGrid();
                
                // 清除所有數據
                drawnElements = [];
                floors = [];
                units = [];
                rooms = [];
                floorCounter = 1;
                unitCounter = 1;
                roomCounter = 1;
                currentFloor = 1;
                floorUnitCounters = {};
                unitRoomCounters = {};
                
                // 重置繪製狀態
                firstClick = true;
                isNewShape = true;
                lastX = null;
                lastY = null;
                currentShapePoints = [];
                
                alert("已清除所有數據");
            }
        };

        // 確認保存專案
        window.confirmSaveProject = function() {
            const projectName = document.getElementById('projectName').value.trim();
            
            if (projectName === '') {
                alert("<?php echo __('project_name_required'); ?>");
                return;
            }
            
            // 獲取畫布數據
            const canvasData = canvas.toDataURL();
            
            // 建立要保存的專案數據
            const projectData = {
                name: projectName,
                canvasData: canvasData,
                elements: drawnElements, // 儲存所有繪製的元素數據
                rooms: rooms // 儲存房間數據
            };
            
            // 這裡可以添加AJAX請求保存到服務器的代碼
            console.log('保存專案:', projectData);
            
            // 關閉對話框
            document.getElementById('saveProjectDialog').style.display = 'none';
            
            alert("<?php echo __('project_saved_successfully'); ?>");
        };
        
        // 隱藏保存對話框
        window.hideSaveDialog = function() {
            document.getElementById('saveProjectDialog').style.display = 'none';
        };
        
        // 另存為專案
        window.saveAsProject = function() {
            document.getElementById('saveAsProjectDialog').style.display = 'block';
            document.getElementById('saveAsProjectName').focus();
        };
        
        // 確認另存為
        window.confirmSaveAsProject = function() {
            const projectName = document.getElementById('saveAsProjectName').value.trim();
            
            if (projectName === '') {
                alert("<?php echo __('project_name_required'); ?>");
                return;
            }
            
            // 獲取畫布數據
            const canvasData = canvas.toDataURL();
            
            // 建立要保存的專案數據
            const projectData = {
                name: projectName,
                canvasData: canvasData,
                elements: drawnElements, // 儲存所有繪製的元素數據
                rooms: rooms // 儲存房間數據
            };
            
            // 這裡可以添加AJAX請求保存到服務器的代碼
            console.log('另存專案:', projectData);
            
            // 關閉對話框
            document.getElementById('saveAsProjectDialog').style.display = 'none';
            
            alert("<?php echo __('project_saved_successfully'); ?>");
        };
        
        // 隱藏另存為對話框
        window.hideSaveAsDialog = function() {
            document.getElementById('saveAsProjectDialog').style.display = 'none';
        };
        
        // 載入專案
        window.confirmLoadProject = function() {
            document.getElementById('loadProjectDialog').style.display = 'none';
        };
        
        // 隱藏載入對話框
        window.hideLoadDialog = function() {
            document.getElementById('loadProjectDialog').style.display = 'none';
        };
        
        // 初始化繪圖區域
        initDrawing();
    });
    </script>

</body>
</html>