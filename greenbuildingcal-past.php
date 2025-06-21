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
                
                // 重要：設置 session 變數，以便導航欄顯示當前專案
                $_SESSION['building_id'] = $building_id;
                $_SESSION['current_gbd_project_id'] = $building_id;
                $_SESSION['current_gbd_project_name'] = $projectName;
                
                // 記錄 session 設置成功
                error_log("SESSION set: project_id={$building_id}, name={$projectName}");
                
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
        $projectStmt = $conn->prepare("SELECT * FROM [Test].[dbo].[GBD_Project] WHERE building_id = :building_id");
        $projectStmt->execute([':building_id' => $projectId]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
        
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
                    $roomData = [
                        'room_id' => $room['room_id'],
                        'room_number' => $room['room_number'] ?? '',
                        'height' => $room['Height'] ?? null,
                        'length' => $room['length'] ?? null,
                        'depth' => $room['depth'] ?? null,
                        'window_position' => $room['window_position'] ?? ''
                    ];
                    
                    $unitData['rooms'][] = $roomData;
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
            display: grid;
            grid-template-columns: repeat(8, 1fr); /* 從 5 改為 8 */
            gap: 8px;
            padding: 10px;
            font-weight: bold;
            border-bottom: 2px solid #ddd;
            font-size: 14px; /* 減小字體以適應更多欄位 */
        }


        .header-row div {
            flex: 1;
            text-align: center;
            padding: 5px;
            border-bottom: 1px solid #000;
        }

        .room-row {
                display: grid;
                grid-template-columns: repeat(8, 1fr); /* 從 5 改為 8 */
                gap: 8px;
                padding: 8px 10px;
                border-bottom: 1px solid #eee;
                align-items: center;
            }

        .room-row input {
                padding: 6px 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 13px; /* 減小輸入框字體 */
                width: 100%;
                box-sizing: border-box;
            }

            .unit {
                width: 100%;
                overflow-x: auto; /* 如果還是太寬，允許水平滾動 */
                margin-bottom: 20px;
                border: 1px solid #000; 
                border-radius: 8px;
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



        .viewer-header h5 {
            margin: 0;
            color: #333;
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

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="modelSource" id="manualInput" value="manual" checked>
                            <label class="form-check-label" for="manualInput">
                                手動輸入建築資訊
                            </label>
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

    // 頁面加載時初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 初始顯示狀態設定
        document.getElementById('section-card-list').style.display = 'block';
        document.getElementById('calculatorContent').style.display = 'none';
        
        loadProjectHistory();
        
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
                
                // 重置表單
                form.reset();
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
```
</div>
</rewritten_file>