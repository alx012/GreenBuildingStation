<?php
/****************************************************************************
 * [0] 開啟 Session，方便累積篩選條件, 利用「HTTP_REFERER」判斷是否從外部網站回來並清空
 ****************************************************************************/
session_start();

// 檢查是否已登入
if (!isset($_SESSION['user_id'])) {
    // 可以選擇是否要立即重新導向
    // header('Location: login.php');
    // exit;
    
    // 或者允許瀏覽但限制功能
    $isLoggedIn = false;
} else {
    $isLoggedIn = true;
}

/****************************************************************************
 * [1] 資料庫連線 (請根據你的實際環境調整)
 ****************************************************************************/
$serverName = "localhost\SQLEXPRESS";
$database   = "Test";
$username   = "weihao0120";   // 依照你實際的帳號
$password   = "weihao0120";   // 依照你實際的密碼

try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

//儲存開啟專案設定
function saveProject($conn) {
    if (!isset($_SESSION['user_id'])) {
        return [
            'success' => false, 
            'message' => '請先登入帳號以使用該功能',
            'redirect' => 'login.php'
        ];
    }

    try {
        $rawData = file_get_contents('php://input');
        $data = json_decode($rawData, true);
        
        if ($data === null) {
            throw new Exception('JSON 解析錯誤: ' . json_last_error_msg());
        }

        if (empty($data['projectName'])) {
            throw new Exception('專案名稱不能為空');
        }

        // 檢查專案是否已存在
        $stmt = $conn->prepare("
            SELECT ProjectID FROM Ubclm_project WHERE ProjectName = ? AND UserID = ?
        ");
        $stmt->execute([$data['projectName'], $_SESSION['user_id']]);
        $existingProject = $stmt->fetch(PDO::FETCH_ASSOC);

        $conn->beginTransaction();

        if ($existingProject) {
            // 如果專案已存在，則執行覆蓋邏輯
            $projectId = $existingProject['ProjectID'];

            // 刪除舊資料（shapes & distances）
            $conn->prepare("DELETE FROM Ubclm_shapes WHERE ProjectID = ?")->execute([$projectId]);
            $conn->prepare("DELETE FROM Ubclm_distances WHERE ProjectID = ?")->execute([$projectId]);

            // 更新專案基本資訊
            $stmt = $conn->prepare("
                UPDATE Ubclm_project SET 
                    Length = ?, Width = ?, LengthUnit = ?, WidthUnit = ?, CreatedDate = GETDATE()
                WHERE ProjectID = ?
            ");
            $stmt->execute([
                $data['length'],
                $data['width'],
                $data['lengthUnit'],
                $data['widthUnit'],
                $projectId
            ]);
        } else {
            // 如果專案不存在，則新增
            $stmt = $conn->prepare("
                INSERT INTO Ubclm_project (
                    ProjectName, UserID, CreatedDate, Length, Width, LengthUnit, WidthUnit
                ) VALUES (?, ?, GETDATE(), ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['projectName'],
                $_SESSION['user_id'],
                $data['length'],
                $data['width'],
                $data['lengthUnit'],
                $data['widthUnit']
            ]);
            $projectId = $conn->lastInsertId();
        }

        // 儲存形狀資料
        $shapeStmt = $conn->prepare("
        INSERT INTO Ubclm_shapes (ProjectID, ShapeNumber, ShapeType, Area, Height, Coordinates, IsTarget)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($data['shapes'] as $shape) {
        $shapeStmt->execute([
            $projectId,
            $shape['shapeNumber'],
            $shape['shapeType'],
            $shape['area'],
            $shape['height'],
            $shape['coordinates'],
            isset($shape['isTarget']) && $shape['isTarget'] ? 1 : 0  // 轉換為 1/0 值儲存到資料庫
        ]);
        }

        // 儲存距離資料
        if (!empty($data['distances'])) {
            $distanceStmt = $conn->prepare("
                INSERT INTO Ubclm_distances (ProjectID, Shape1Number, Shape2Number, Distance)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($data['distances'] as $distance) {
                $distanceStmt->execute([
                    $projectId,
                    $distance['shape1number'],
                    $distance['shape2number'],
                    $distance['distance']
                ]);
            }
        }

        $conn->commit();

        return [
            'success' => true,
            'message' => $existingProject ? '專案更新成功' : '專案儲存成功',
            'projectId' => $projectId
        ];
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        return [
            'success' => false,
            'message' => '儲存失敗：' . $e->getMessage()
        ];
    }
}


function loadProject($conn) {
    // 檢查是否登入（保持不變）
    if (!isset($_SESSION['user_id'])) {
        return [
            'success' => false, 
            'message' => '請先登入帳號以使用該功能',
            'redirect' => 'login.php'
        ];
    }

    try {
        $projectId = $_GET['projectId'] ?? null;
        if (!$projectId) {
            throw new Exception('未指定專案ID');
        }

        // 專案查詢維持不變
        $projectStmt = $conn->prepare("
            SELECT 
                ProjectID,
                ProjectName,
                CreatedDate,
                Length,
                Width,
                LengthUnit,
                WidthUnit,
                building_id
            FROM Ubclm_project 
            WHERE ProjectID = ? AND UserID = ?
        ");
        $projectStmt->execute([$projectId, $_SESSION['user_id']]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$project) {
            throw new Exception('找不到指定的專案或無權限存取');
        }

        // 取得形狀資料 - 確保包含IsTarget欄位
        // 首先檢查IsTarget欄位是否存在
        $checkColumnStmt = $conn->prepare("
            SELECT COUNT(*) AS column_exists 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'Ubclm_shapes' AND COLUMN_NAME = 'IsTarget'
        ");
        $checkColumnStmt->execute();
        $columnExists = $checkColumnStmt->fetch(PDO::FETCH_ASSOC)['column_exists'] > 0;
        
        // 根據IsTarget欄位是否存在調整查詢
        if ($columnExists) {
            $shapeStmt = $conn->prepare("
                SELECT ShapeID, ProjectID, ShapeNumber, ShapeType, Area, Height, Coordinates, IsTarget 
                FROM Ubclm_shapes 
                WHERE ProjectID = ? 
                ORDER BY ShapeNumber
            ");
        } else {
            // 若欄位不存在，使用原本的查詢
            $shapeStmt = $conn->prepare("
                SELECT ShapeID, ProjectID, ShapeNumber, ShapeType, Area, Height, Coordinates
                FROM Ubclm_shapes 
                WHERE ProjectID = ? 
                ORDER BY ShapeNumber
            ");
        }
        
        $shapeStmt->execute([$projectId]);
        $shapes = $shapeStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 轉換形狀資料以適應前端需求
        foreach ($shapes as &$shape) {
            // 確保IsTarget欄位存在，若不存在則設為false
            if (!isset($shape['IsTarget'])) {
                $shape['IsTarget'] = 0;
            }
            
            // 添加前端用的isTarget屬性（使用駝峰式命名）
            $shape['isTarget'] = (bool)$shape['IsTarget'];
            
            // 如果需要其他數據類型轉換，可以在這裡處理
            // 例如將座標從JSON字串轉為對象
            if (isset($shape['Coordinates']) && is_string($shape['Coordinates'])) {
                $shape['coordinates'] = json_decode($shape['Coordinates'], true);
            }
        }

        // 取得距離資料
        $distanceStmt = $conn->prepare("
            SELECT * FROM Ubclm_distances 
            WHERE ProjectID = ?
        ");
        $distanceStmt->execute([$projectId]);
        $distances = $distanceStmt->fetchAll(PDO::FETCH_ASSOC);

        // 更新 session 中的當前專案信息
        $_SESSION['current_project_id'] = $projectId;
        $_SESSION['current_project_name'] = $project['ProjectName'];

        return [
            'success' => true,
            'project' => $project,
            'shapes' => $shapes,
            'distances' => $distances
        ];
        
    } catch (Exception $e) {
        error_log('Load project error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => '載入失敗：' . $e->getMessage()
        ];
    }
}

function getProjectList($conn) {
    // 檢查是否登入
    if (!isset($_SESSION['user_id'])) {
        return [
            'success' => false, 
            'message' => '請先登入帳號以使用該功能',
            'redirect' => 'login.php'
        ];
    }

    try {
        // 檢查是否有當前綠建築專案ID
        $gbdProjectId = isset($_SESSION['gbd_project_id']) ? $_SESSION['gbd_project_id'] : null;
        
        // 準備查詢語句
        $sql = "
            SELECT 
                p.ProjectID, 
                p.ProjectName, 
                p.CreatedDate,
                p.building_id,
                (SELECT COUNT(*) FROM Ubclm_shapes WHERE ProjectID = p.ProjectID) as ShapeCount
            FROM Ubclm_project p
            WHERE p.UserID = ?
        ";
        
        $params = [$_SESSION['user_id']];
        
        // 如果有綠建築專案ID，加入過濾條件
        if ($gbdProjectId) {
            $sql .= " AND p.building_id = ?";
            $params[] = $gbdProjectId;
        }
        
        // 添加排序條件
        $sql .= " ORDER BY p.CreatedDate DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'projects' => $projects,
            'currentGbdProjectId' => $gbdProjectId // 同時將當前綠建築專案ID返回給前端
        ];
        
    } catch (Exception $e) {
        error_log('Get project list error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => '取得專案列表失敗：' . $e->getMessage()
        ];
    }
}

//新增確認專案名稱以防另存專案時重複儲存
function checkProjectName($conn, $projectName, $userId) {
    try {
        // 準備 SQL 語句，檢查相同用戶是否有相同名稱的專案
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM Ubclm_project 
            WHERE ProjectName = ? AND UserID = ?
        ");
        
        $stmt->execute([$projectName, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'exists' => $result['count'] > 0,
            'message' => $result['count'] > 0 ? '專案名稱已存在' : null
        ];
    } catch (Exception $e) {
        error_log('檢查專案名稱失敗：' . $e->getMessage());
        return [
            'success' => false,
            'message' => '檢查專案名稱失敗：' . $e->getMessage()
        ];
    }
}

//創建新專案
function createProject($conn) {
    error_log('開始處理 createProject 請求');
    
    if (!isset($_SESSION['user_id'])) {
        return [
            'success' => false, 
            'message' => '請先登入帳號以使用該功能',
            'redirect' => 'login.php'
        ];
    }

    try {
        $rawData = file_get_contents('php://input');
        error_log('收到的原始資料: ' . $rawData);
        
        $data = json_decode($rawData, true);
        
        if ($data === null) {
            throw new Exception('JSON 解析錯誤: ' . json_last_error_msg());
        }

        error_log('解析後的資料: ' . print_r($data, true));

        if (empty($data['projectName'])) {
            throw new Exception('專案名稱不能為空');
        }

        // 檢查專案名稱是否已存在
        $stmt = $conn->prepare("
            SELECT ProjectID FROM Ubclm_project WHERE ProjectName = ? AND UserID = ?
        ");
        $stmt->execute([$data['projectName'], $_SESSION['user_id']]);
        
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => '專案名稱已存在'];
        }

        // 開始事務
        $conn->beginTransaction();

        // 修正：使用 gbd_project_id 而不是 current_gbd_project_id
        $currentBuildingId = isset($_SESSION['gbd_project_id']) ? $_SESSION['gbd_project_id'] : null;
        
        // 將獲取的ID記錄到日誌
        error_log('使用綠建築專案ID: ' . $currentBuildingId);

        // 插入新專案
        $stmt = $conn->prepare("
            INSERT INTO Ubclm_project (
                ProjectName, UserID, CreatedDate, 
                Length, Width, LengthUnit, WidthUnit, 
                building_id
            ) VALUES (?, ?, GETDATE(), ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['projectName'],
            $_SESSION['user_id'],
            $data['length'],
            $data['width'],
            $data['lengthUnit'],
            $data['widthUnit'],
            $currentBuildingId
        ]);

        $projectId = $conn->lastInsertId();
        
        $conn->commit();

        // 更新 session 中的當前專案資訊
        $_SESSION['current_gbd_project_id'] = $projectId;
        $_SESSION['current_gbd_project_name'] = $data['projectName'];

        return [
            'success' => true,
            'message' => '專案創建成功',
            'projectId' => $projectId,
            'building_id' => $currentBuildingId
        ];
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        return [
            'success' => false,
            'message' => '創建失敗：' . $e->getMessage()
        ];
    }
}

//刪除專案
function deleteProject($conn) {
    // 檢查是否已登入
    if (!isset($_SESSION['user_id'])) {
        return [
            'success' => false,
            'message' => '請先登入帳號以使用該功能',
            'redirect' => 'login.php'
        ];
    }
    
    // 取得專案ID，這裡假設是透過 GET 參數傳入
    $projectId = $_GET['projectId'] ?? null;
    if (!$projectId) {
        return [
            'success' => false,
            'message' => '未指定專案ID'
        ];
    }
    
    // 檢查該專案是否存在，且確定該專案屬於目前登入的使用者
    $stmt = $conn->prepare("SELECT ProjectID FROM Ubclm_project WHERE ProjectID = ? AND UserID = ?");
    $stmt->execute([$projectId, $_SESSION['user_id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) {
        return [
            'success' => false,
            'message' => '找不到該專案或無權限存取'
        ];
    }
    
    // 開始交易，先刪除相關資料，再刪除專案本身
    try {
        $conn->beginTransaction();
        
        // 刪除該專案下的形狀資料
        $conn->prepare("DELETE FROM Ubclm_shapes WHERE ProjectID = ?")->execute([$projectId]);
        // 刪除該專案下的距離資料
        $conn->prepare("DELETE FROM Ubclm_distances WHERE ProjectID = ?")->execute([$projectId]);
        // 刪除專案本身
        $conn->prepare("DELETE FROM Ubclm_project WHERE ProjectID = ?")->execute([$projectId]);
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => '專案刪除成功'
        ];
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return [
            'success' => false,
            'message' => '刪除失敗：' . $e->getMessage()
        ];
    }
}

if (isset($_GET['action'])) {
    $response = null;
    
    switch($_GET['action']) {
        case 'save':
            $response = saveProject($conn);
            break;
        case 'load':
            $response = loadProject($conn);
            break;
        case 'list':
            $response = getProjectList($conn);
            break;
        case 'createProject':
            $response = createProject($conn);
            break;
        case 'checkName':
            if (!isset($_SESSION['user_id'])) {
                $response = [
                    'success' => false,
                    'message' => '請先登入帳號以使用該功能',
                    'redirect' => 'login.php'
                ];
            } else {
                $data = json_decode(file_get_contents('php://input'), true);
                $response = checkProjectName($conn, $data['projectName'], $_SESSION['user_id']);
            }
            break;
        case 'delete':  // 新增刪除專案的動作
            $response = deleteProject($conn);
            break;
        default:
            $response = ['success' => false, 'message' => '無效的操作'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}


?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>建築街廓微氣候計算器</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
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

        .content-wrapper {
            width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .input-section {
            margin-bottom: 20px;
        }

        .input-group {
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .controls {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }

        .draw-mode-controls {
            margin: 10px 0;
        }

        #drawingCanvas {
            border: 1px solid #000;
            margin: 10px 0;
            max-width: 100%;
            height: auto;
        }

        .button {
            padding: 8px 16px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .button:hover {
            background-color: #45a049;
        }

        #gridInfo {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.8);
            padding: 5px;
            border-radius: 4px;
            font-size: 14px;
            color: #666;
        }

        .canvas-container {
            position: relative;
            width: fit-content;
        }

        .section-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .section-card-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        h1 {
            margin-bottom: 30px;
        }

        h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.5rem;
        }

        /* 專案名稱顯示區域的樣式 */
        .project-name-display {
            margin-bottom: 15px;
            padding: 5px 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
            border-left: 4px solid #4CAF50;
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

        /* 初始隱藏繪圖相關區域 */
        #drawingSection {
            display: none;
        }

        /* 導覽列背景顏色 */
        .custom-navbar {
        background-color: #769a76; /* 這裡可以換成你要的顏色 */
        }

        .project-list {
            padding: 10px;
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

        .project-item:hover {
            background: #e0e0e0;
        }

        .project-info {
            flex: 1;
        }

        .project-name {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .project-details {
            font-size: 0.9em;
            color: #666;
        }

        .no-projects {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        /* 新增：分頁控制的樣式 */
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
            background-color: #769a76;
            color: #fff;
        }

        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid rgba(0,0,0,.125);
            font-size: 32px; /* 調整字體大小 */
            font-family: "Arial", sans-serif; /* 設定字體 */
            /* font-weight: bold; 設定粗體 */
        }


        .project-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .badge {
            font-weight: normal;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        /* 添加卡片的懸停效果 */
        .project-card {
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            border-left: 4px solid #769a76;
        }

        .project-card:hover {
            background-color: #f0f0f0;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        #history-section {
            margin-top: 30px; /* 根據需求調整數值 */
        }

        #osmMapContainer {
            width: 100%;
            height: 80vh; /* 或其他明確高度 */
            display: none;
            flex-direction: column;
        }

        #map {
            flex: 1;
            min-height: 400px;
        }

    </style>
</head>
<body>
<?php include('navbar.php'); ?>

    <div class="container my-3">
        
        <!-- 輸入區域卡片 -->
        <div class="section-card" id="projectCreationSection" style="display: none;">
            <div class="input-section" id="inputSection">
                <h2>輸入專案資訊</h2>
                <div class="input-group">
                    <label>專案名稱：</label>
                    <input type="text" id="newprojectName" required>
                </div>
                <div class="input-group">
                    <label>長度：</label>
                    <input type="number" id="length" min="1" step="any" required>
                    <select id="lengthUnit">
                        <option value="km">公里</option>
                        <option value="m" selected>公尺</option>
                        <option value="cm">公分</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>寬度：</label>
                    <input type="number" id="width" min="1" step="any" required>
                    <select id="widthUnit">
                        <option value="km">公里</option>
                        <option value="m" selected>公尺</option>
                        <option value="cm">公分</option>
                    </select>
                </div>
                <div>
                    <button class="button" onclick="createNewProject()">創建專案</button>

                    <label for="inputMode">輸入方式：</label>
                    <select id="inputMode" onchange="setInputMode(this.value)">
                        <option value="draw" selected>繪圖輸入</option>
                        <option value="bbox">匡選輸入</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 歷史專案區域 -->
        <div class="card mb-4" id="history-section">
            <h2 class="card-header"><?php echo __('urban_climate_project_history'); ?></h2>
            <div id="section-card-list">
                <div class="filter-project-list-section" id="projectListSection">
                    <div id="projectList" class="project-list p-3">
                        <!-- 專案將在這裡由JavaScript動態載入 -->
                        <div class="loading"><?php echo __('loading'); ?></div>
                    </div>
                    <!-- 保留原有的分頁控制區域 -->
                    <div id="pagination" class="pagination"></div>
                </div>
            </div>
        </div>

        <!-- 繪圖相關區域（初始隱藏） -->
        <div id="drawingSection">
            <div class="section-card">
                <div class="project-name-display">
                    <h3><?php echo __('current_project'); ?>: <span id="currentProjectName"><?php echo __('empty_project'); ?></span></h3>
                </div>
                <h2><?php echo __('toolbar_title'); ?></h2>
                <div class="controls">
                    <button class="button" onclick="setDrawMode('polygon')">🖊️ <?php echo __('draw_polygon_btn'); ?></button>
                    <button class="button" onclick="setDrawMode('height')">🏗️ <?php echo __('modify_height_btn'); ?></button>
                    <button class="button" onclick="setDrawMode('target')" style="background-color:#b83939;">🎯 <?php echo __('target_building_btn'); ?></button>
                    <button class="button" onclick="setDrawMode('delete')" style="background-color:#e74c3c;">🧹 <?php echo __('delete_building_btn'); ?></button>
                    <button class="button" onclick="clearCanvasWithConfirm()">🧽 <?php echo __('clear_canvas_btn'); ?></button>
                    <button class="button" onclick="resetArea()" style="background-color:rgb(212, 157, 38);">🗑️ <?php echo __('reset_project_btn'); ?></button>
                    <button class="button" onclick="saveProject()">💾 <?php echo __('save_project_btn'); ?></button>
                    <button class="button" onclick="saveAsProject()">📝 <?php echo __('save_as_btn'); ?></button>
                    <button class="button" onclick="confirmNavigation()">📂 <?php echo __('view_other_projects_btn'); ?></button>
                </div>
                <div class="draw-mode-controls">
                    <label>
                        <input type="checkbox" id="snapToGrid" checked>
                        <?php echo __('snap_to_grid'); ?>
                    </label>
                </div>
                
                <!-- 高度輸入對話框 -->
                <div id="heightInputDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                    background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                    <h3><?php echo __('modify_height_dialog_title'); ?></h3>
                    <div class="input-group">
                        <input type="number" id="buildingHeight" min="0" step="any">
                        <span id="heightUnit">公尺</span>
                    </div>
                    <div style="margin-top: 10px;">
                        <button class="button" onclick="confirmHeight()"><?php echo __('confirm_btn'); ?></button>
                        <button class="button" onclick="cancelHeight()" style="margin-left: 10px; background-color: #999;"><?php echo __('cancel_btn'); ?></button>
                    </div>
                </div>

                <!-- 專案儲存對話框 -->
                <div id="saveProjectDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                    background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                    <h3><?php echo __('save_project_dialog_title'); ?></h3>
                    <div class="input-group">
                        <label><?php echo __('project_name_label'); ?>：</label>
                        <input type="text" id="projectName">
                    </div>
                    <div style="margin-top: 10px;">
                        <button class="button" onclick="confirmSaveProject()"><?php echo __('confirm_btn'); ?></button>
                        <button class="button" onclick="hideSaveDialog()" style="margin-left: 10px; background-color: #999;"><?php echo __('cancel_btn'); ?></button>
                    </div>
                </div>

                <!-- 另存專案對話框 -->
                <div id="saveAsProjectDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                    background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                    <h3><?php echo __('save_as_dialog_title'); ?></h3>
                    <div class="input-group">
                        <label><?php echo __('new_project_name_label'); ?></label>
                        <input type="text" id="saveAsProjectName">
                    </div>
                    <div style="margin-top: 10px;">
                        <button class="button" onclick="confirmSaveAsProject()"><?php echo __('confirm_btn'); ?></button>
                        <button class="button" onclick="hideSaveAsDialog()" style="margin-left: 10px; background-color: #999;"><?php echo __('cancel_btn'); ?></button>
                    </div>
                </div>
                        
                <!-- 專案載入對話框 -->
                <div id="loadProjectDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                    background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                    <h3><?php echo __('load_project_dialog_title'); ?></h3>
                    <div class="input-group">
                        <select id="projectSelect"></select>
                    </div>
                    <div style="margin-top: 10px;">
                        <button class="button" onclick="confirmLoadProject()"><?php echo __('confirm_btn'); ?></button>
                        <button class="button" onclick="hideLoadDialog()" style="margin-left: 10px; background-color: #999;"><?php echo __('cancel_btn'); ?></button>
                    </div>
                </div>

                <div class="canvas-container">
                    <canvas id="drawingCanvas" width="1500" height="800"></canvas>
                    <div id="gridInfo"></div>
                </div>

                <!-- 添加地圖容器用於 bbox 模式 -->
                <div id="osmMapContainer">
                    <h3>建築物高度分析地圖</h3>
                    <iframe id="bboxIframe" src="overpass.html" width="100%" height="800" frameborder="0"></iframe>
                </div>
            </div>
        </div>

    <script>
        //全域變數設置區域
        let canvas = document.getElementById('drawingCanvas');
        let ctx = canvas.getContext('2d');
        let drawMode = 'polygon'; // 改為預設使用多邊形模式
        let isDrawing = false;
        let startX, startY;
        let shapes = [];
        let currentShape = [];
        let gridSize = 10;
        let scaleX, scaleY;
        // 在現有變數後添加
        let selectedShape = null;
        let heightInputMode = false;
        let mouseX = 0;
        let mouseY = 0;
        // 全局變量 - 追踪當前專案名稱
        let currentProjectName = "預設專案";
        let currentProjectId = null;
        let blockDimensions = {
            length: null,
            width: null,
            lengthUnit: null,
            widthUnit: null
        };
        let projectsData = [];
        let currentPage = 1;
        const itemsPerPage = 5;
        let targetMode = false;
        let deleteMode = false; // 新增刪除模式變數
        let hoveredShapeIndex = -1; // 添加一個變數來追踪當前懸停的形狀

        // 在現有變數後添加縮放相關變數
        let zoomLevel = 1; // 起始縮放級別為 1
        let panOffsetX = 0; // 平移偏移量 X
        let panOffsetY = 0; // 平移偏移量 Y
        let isDragging = false; // 是否正在拖動
        let lastPanX = 0; // 上次平移位置 X
        let lastPanY = 0; // 上次平移位置 Y
        let minZoom = 1; // 最小縮放級別 (修改為1, 不允許縮小)
        let maxZoom = 3; // 最大縮放級別

        // 添加綠建築專案資訊變數
        let gbdProjectInfo = {
            id: null,
            name: "尚未選取專案"
        };

        // 檢查專案狀態函數
        function checkProjectStatus() {
            // 檢查是否有當前專案
            if (currentProjectName && currentProjectName !== "預設專案") {
                // 如果有專案，顯示繪圖區域
                document.getElementById('drawingSection').style.display = 'block';
                updateProjectNameDisplay();
            } else {
                // 如果沒有專案，顯示輸入區域
                document.getElementById('drawingSection').style.display = 'none';
            }
        }

        // 重設範圍函數
        function resetArea() {
            // 添加確認提示
            const confirmation = confirm("確定要重設當前專案嗎？所有未保存的資料將會丟失。");
            
            // 如果用戶取消了操作，則直接返回
            if (!confirmation) {
                return;
            }
            
            // 清除所有已繪製的形狀
            shapes = [];
            currentShape = [];

            // 重置專案名稱輸入框
            document.getElementById('newprojectName').value = '';

            // 重置輸入框的值
            document.getElementById('length').value = '';
            document.getElementById('width').value = '';
            
            // 顯示輸入區域的卡片
            document.querySelector('.section-card').style.display = 'block';

            // 重置專案名稱
            currentProjectName = "預設專案";
            currentProjectId = null;
            updateProjectNameDisplay();

            // 隱藏繪圖區域
            document.getElementById('drawingSection').style.display = 'none';
            
            // 重置選中狀態
            selectedShape = null;
            
            // 隱藏高度輸入對話框（如果開著的話）
            hideHeightDialog();
        }

        function setInputMode(mode) {
            if (!mode) {
                console.warn("Input mode is empty.");
                return;
            }
            const mapContainer = document.getElementById('osmMapContainer');
            const canvasContainer = document.querySelector('.canvas-container');

            if (mode === 'bbox') {
                console.log("Setting input mode to bbox.");
                mapContainer.style.display = 'block';
                canvasContainer.style.display = 'none';

                // 延遲呼叫 invalidateSize 讓 Leaflet 正常載入
                setTimeout(() => {
                if (window.map) {
                    window.map.invalidateSize();
                }
                }, 200); // 給它一點時間進行 DOM 排版
            } else {
                console.log("Setting input mode to draw.");
                mapContainer.style.display = 'none';
                canvasContainer.style.display = 'block';
            }
        }

        let osmMapInitialized = false;

        document.addEventListener('DOMContentLoaded', function() {
        // 檢查當前專案狀態
        checkProjectStatus();
    });

            // 當繪圖區域可見時，添加縮放控制
            if (document.getElementById('drawingSection').style.display !== 'none') {
                addZoomControls();
                setupWheelZoom();
                setupPanning();
            } else {
                // 如果繪圖區域未顯示，設置監聽器在區域顯示時添加控制
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (document.getElementById('drawingSection').style.display !== 'none') {
                            addZoomControls();
                            setupWheelZoom();
                            setupPanning();
                            observer.disconnect();
                        }
                    });
                });
                
                observer.observe(document.getElementById('drawingSection'), {
                    attributes: true,
                    attributeFilter: ['style']
                });
            }
        });

        // 刪除專案
        function deleteProject() {
            if (confirm("確定要刪除目前的專案嗎？此動作無法復原。")) {
                // 假設 currentProjectId 是目前開啟專案的 ID
                fetch('?action=delete&projectId=' + currentProjectId)
                    .then(response => response.json())
                    .then(data => {
                        alert(data.message);
                        if (data.success) {
                            // 根據需求，可能要導向其他頁面或更新畫面
                            window.location.href = 'urbanclimate-past.php';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
            }
        }

        //查看其他專案
        function confirmNavigation() {
            let userConfirmed = confirm("確定要返回專案清單嗎？尚未儲存的動作將無法復原。");
            if (userConfirmed) {
                window.location.href = 'urbanclimate-past.php';
            }
        }
        
        // 新增一個專門處理按鈕點擊清除的函數
        function clearCanvasWithConfirm() {
            const isConfirmed = confirm('確定要清除畫布上所有的圖形嗎？');
            const inputMode = document.getElementById('inputMode').value;
            if (inputMode === 'draw') {
                // 如果是繪圖模式，重置畫布並重新繪製網格
                console.log("清除畫布並重新繪製網格");
                clearCanvas();
                drawGrid();
            } else if (inputMode === 'bbox') {
                console.log("清除匡選模式的標記");
                // 如果是匡選模式，清除地圖上的標記
                const iframe = document.getElementById('bboxIframe');
                if (iframe && iframe.contentWindow && typeof iframe.contentWindow.resetBboxPolygons === 'function') {
                    iframe.contentWindow.resetBboxPolygons();
                } else {
                    console.warn('iframe 中找不到 resetBboxPolygons 函數');
                }
            }
            console.log("畫布已清除");
        }

        // 保持原本的 clearCanvas 函數不變，供其他功能直接調用
        function clearCanvas() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            let shapes = [];
            currentShape = [];
            drawGrid();
        }

        function drawGrid() {
            // 確保網格繪製考慮縮放和平移
            ctx.beginPath();
            ctx.strokeStyle = '#ddd';
            ctx.lineWidth = 0.5 / zoomLevel; // 調整線寬以保持網格清晰
            
            // 計算可見區域的範圍
            const visibleLeft = -panOffsetX / zoomLevel;
            const visibleTop = -panOffsetY / zoomLevel;
            const visibleRight = (canvas.width - panOffsetX) / zoomLevel;
            const visibleBottom = (canvas.height - panOffsetY) / zoomLevel;
            
            // 繪製垂直線
            for (let x = Math.floor(visibleLeft / gridSize) * gridSize; x <= visibleRight; x += gridSize) {
                ctx.moveTo(x, visibleTop);
                ctx.lineTo(x, visibleBottom);
            }
            
            // 繪製水平線
            for (let y = Math.floor(visibleTop / gridSize) * gridSize; y <= visibleBottom; y += gridSize) {
                ctx.moveTo(visibleLeft, y);
                ctx.lineTo(visibleRight, y);
            }
            
            ctx.stroke();
            ctx.lineWidth = 1;  // 重置線寬
        }

        // 初始化網格
        function initializeGrid() {
            const length = parseFloat(document.getElementById('length').value);
            const width = parseFloat(document.getElementById('width').value);
            const lengthUnit = document.getElementById('lengthUnit').value;
            const widthUnit = document.getElementById('widthUnit').value;

            // 計算比例
            scaleX = canvas.width / length;
            scaleY = canvas.height / width;

            // 清除畫布並繪製網格
            clearCanvas();

            // 計算並顯示網格資訊
            const gridLengthInUnit = length / (canvas.width / gridSize);
            const gridWidthInUnit = width / (canvas.height / gridSize);
            document.getElementById('gridInfo').innerHTML = 
                `每格代表: ${gridLengthInUnit.toFixed(2)}${lengthUnit} × ${gridWidthInUnit.toFixed(2)}${widthUnit}`;
        }

        // 設置繪圖模式
        function setDrawMode(mode) {
            if (mode === 'polygon' || mode === 'height' || mode === 'target' || mode === 'delete') {
                drawMode = mode;
                currentShape = [];
                heightInputMode = mode === 'height';
                targetMode = mode === 'target';
                deleteMode = mode === 'delete'; // 設置刪除模式狀態
                
                // 顯示相應的模式提示
                if (mode === 'target') {
                    alert('請點選要設為標的建築物的形狀。每個專案只能有一個標的建築物。');
                } else if (mode === 'delete') {
                    alert('請點選要刪除的建築物。此操作無法復原。');
                }
                
                redrawAll();
            }
        }

        // 開始繪製
        function startDrawing(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleFactorX = canvas.width / rect.width;
            const scaleFactorY = canvas.height / rect.height;
            
            startX = (e.clientX - rect.left) * scaleFactorX;
            startY = (e.clientY - rect.top) * scaleFactorY;

            // 應用平移和縮放轉換
            startX = (startX - panOffsetX) / zoomLevel;
            startY = (startY - panOffsetY) / zoomLevel;

            if (document.getElementById('snapToGrid').checked) {
                startX = Math.round(startX / gridSize) * gridSize;
                startY = Math.round(startY / gridSize) * gridSize;
            }

            if (drawMode === 'polygon') {
                if (currentShape.length === 0) {
                    currentShape.push({ x: startX, y: startY });
                }
            }
        }

        function draw(e) {
            // 由於移除矩形功能，這個函數可以簡化或移除
            if (drawMode === 'polygon') {
                redrawAll();
            }
        }

        function stopDrawing(e) {
            isDrawing = false;
            redrawAll();
        }

        // 重繪畫布
        function redrawAll() {
            // 清除整個畫布
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // 保存當前狀態
            ctx.save();
            
            // 應用縮放和平移變換（使用正確的順序）
            ctx.translate(panOffsetX, panOffsetY);
            ctx.scale(zoomLevel, zoomLevel);
            
            // 繪製網格
            drawGrid();
            
            // 繪製所有已完成形狀的填充 (最底層)
            shapes.forEach((shape, index) => {
                if (shape.type === 'polygon') {
                    ctx.beginPath();
                    ctx.moveTo(shape.points[0].x, shape.points[0].y);
                    
                    for (let i = 1; i < shape.points.length; i++) {
                        ctx.lineTo(shape.points[i].x, shape.points[i].y);
                    }
                    
                    ctx.closePath();
                    
                    // 選擇填充顏色 - 添加懸停效果
                    if (drawMode === 'delete' && index === hoveredShapeIndex) {
                        ctx.fillStyle = 'rgba(231, 76, 60, 0.5)'; // 刪除模式下懸停時顯示紅色
                    } else if (shape.isTarget) {
                        ctx.fillStyle = 'rgba(255, 0, 0, 0.3)'; // 紅色，標的建築物
                    } else {
                        ctx.fillStyle = 'rgba(0, 150, 255, 0.3)'; // 藍色，一般建築物
                    }
                    
                    ctx.fill();
                }
            });
            
            // 繪製所有形狀的邊框 (中間層)
            shapes.forEach((shape) => {
                if (shape.type === 'polygon') {
                    ctx.beginPath();
                    ctx.moveTo(shape.points[0].x, shape.points[0].y);
                    
                    for (let i = 1; i < shape.points.length; i++) {
                        ctx.lineTo(shape.points[i].x, shape.points[i].y);
                    }
                    
                    ctx.closePath();
                    
                    // 標的建築物使用不同色邊框
                    if (shape.isTarget) {
                        ctx.strokeStyle = 'red';
                        ctx.lineWidth = 2;
                    } else {
                        ctx.strokeStyle = 'blue';
                        ctx.lineWidth = 1;
                    }
                    
                    ctx.stroke();
                    ctx.lineWidth = 1; // 恢復預設線寬
                }
            });

            // 繪製正在繪製中的多邊形
            if (currentShape.length > 0) {
                drawCurrentPolygon();
            }
            
            // 最後繪製編號和高度 (最上層)
            shapes.forEach((shape, index) => {
                if (shape.type === 'polygon') {
                    // 計算多邊形中心點
                    const centerX = shape.points.reduce((sum, p) => sum + p.x, 0) / shape.points.length;
                    const centerY = shape.points.reduce((sum, p) => sum + p.y, 0) / shape.points.length;
                    
                    // 使用原有的函數來繪製編號和高度
                    drawShapeInfo(centerX, centerY, index + 1, shape.zHeight, shape.isTarget);
                }
            });
            
            // 恢復狀態
            ctx.restore();
        }

        // 繪製當前的多邊形
        function drawCurrentPolygon() {
            if (currentShape.length === 0) return;

            ctx.beginPath();
            ctx.strokeStyle = '#000';
            ctx.moveTo(currentShape[0].x, currentShape[0].y);
            
            // 繪製已存在的點之間的線段
            for (let i = 1; i < currentShape.length; i++) {
                ctx.lineTo(currentShape[i].x, currentShape[i].y);
            }
            
            // 如果正在繪製新的線段（連接最後一點到當前滑鼠位置）
            if (currentShape.length >= 1) {
                const lastPoint = currentShape[currentShape.length - 1];
                
                // 繪製從最後一點到當前滑鼠位置的線段
                if (!(mouseX === lastPoint.x && mouseY === lastPoint.y)) {
                    ctx.lineTo(mouseX, mouseY);
                }
                
                // 檢查是否接近起點（可以完成多邊形）
                if (currentShape.length > 1) {
                    const distanceToStart = Math.sqrt(
                        Math.pow(mouseX - currentShape[0].x, 2) + 
                        Math.pow(mouseY - currentShape[0].y, 2)
                    );
                    
                    // 如果靠近起點，繪製連接線表示可以完成多邊形
                    if (distanceToStart < gridSize * 2) {
                        ctx.lineTo(currentShape[0].x, currentShape[0].y);
                    }
                }
            }
            
            ctx.stroke();
        }

        // 修改形狀資訊顯示
        function drawShapeInfo(x, y, number, zHeight, isTarget) {
            // 根據是否為標的建築物選擇顏色
            ctx.fillStyle = isTarget ? '#ff0000' : '#000';
            ctx.font = isTarget ? 'bold 16px Arial' : '16px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            
            // 如果是標的建築物，添加標記
            if (isTarget) {
                ctx.fillText("🎯" + number.toString(), x, y - 10);
            } else {
                ctx.fillText(number.toString(), x, y);
            }
            
            if (zHeight !== undefined && zHeight !== null) {
                ctx.fillText(`H: ${zHeight}`, x, y + 20);
            }
        }

        // 處理多邊形點擊
        function handlePolygonClick(e) {
            // 只處理左鍵點擊，右鍵點擊用於取消
            if (e.button !== 0) return;
            
            if (drawMode !== 'polygon') return;

            // 獲取滑鼠點擊的物理位置（相對於瀏覽器視窗）
            const rect = canvas.getBoundingClientRect();
            
            // 計算滑鼠在畫布元素上的實際位置
            const mouseXOnCanvas = e.clientX - rect.left;
            const mouseYOnCanvas = e.clientY - rect.top;
            
            // 計算滑鼠在畫布內部座標系統的位置
            // 1. 從實際畫布像素轉換到畫布座標系
            const scaleFactorX = canvas.width / rect.width;
            const scaleFactorY = canvas.height / rect.height;
            
            // 2. 應用這個比例因子
            const rawCanvasX = mouseXOnCanvas * scaleFactorX;
            const rawCanvasY = mouseYOnCanvas * scaleFactorY;
            
            // 3. 應用平移和縮放轉換
            const clickX = (rawCanvasX - panOffsetX) / zoomLevel;
            const clickY = (rawCanvasY - panOffsetY) / zoomLevel;
            
            // 網格對齊
            let finalX = clickX;
            let finalY = clickY;
            if (document.getElementById('snapToGrid').checked) {
                finalX = Math.round(clickX / gridSize) * gridSize;
                finalY = Math.round(clickY / gridSize) * gridSize;
            }
            
            console.log(`點擊座標: 原始(${mouseXOnCanvas}, ${mouseYOnCanvas}), 畫布(${rawCanvasX}, ${rawCanvasY}), 最終(${finalX}, ${finalY})`);

            // 檢查是否完成多邊形（點擊接近第一個點）
            if (currentShape.length > 2) {
                const firstPoint = currentShape[0];
                const distance = Math.sqrt(
                    Math.pow(finalX - firstPoint.x, 2) + 
                    Math.pow(finalY - firstPoint.y, 2)
                );

                // 只有當點數超過2且點擊接近第一個點時才視為閉合
                if (distance < gridSize) {
                    console.log("多邊形閉合");
                    // 創建新的多邊形形狀
                    const newShape = {
                        type: 'polygon',
                        points: [...currentShape],
                        zHeight: null
                    };
                    shapes.push(newShape);
                    
                    // 設置當前選中的形狀並顯示高度輸入對話框
                    selectedShape = newShape;
                    showHeightDialog();
                    
                    currentShape = [];
                    redrawAll();
                    return;
                }
            }

            // 第一個點特殊處理 - 確保不會在首次點擊時誤判為閉合
            if (currentShape.length === 0) {
                // 檢查是否點擊在現有形狀上 (避免重疊)
                for (let shape of shapes) {
                    if (isPointInShape(finalX, finalY, shape)) {
                        console.log("點擊在現有形狀上，忽略");
                        return;
                    }
                }
                console.log("開始新的多邊形");
            } else if (currentShape.length === 1) {
                // 第二個點檢查 - 確保與第一個點不同
                const firstPoint = currentShape[0];
                const distance = Math.sqrt(
                    Math.pow(finalX - firstPoint.x, 2) + 
                    Math.pow(finalY - firstPoint.y, 2)
                );
                
                if (distance < gridSize/2) {
                    console.log("第二個點太接近第一個點，忽略");
                    return;
                }
                console.log("添加多邊形的第二個點");
            } else {
                console.log(`添加多邊形的點 #${currentShape.length+1}`);
            }

            // 將新點添加到當前形狀
            currentShape.push({ x: finalX, y: finalY });
            redrawAll();
        }

        // 添加點擊檢測函數
        function isPointInShape(x, y, shape) {
            if (shape.type === 'rectangle') {
                return x >= shape.x && x <= shape.x + shape.width &&
                    y >= shape.y && y <= shape.y + shape.height;
            } else if (shape.type === 'polygon') {
                // 多邊形點擊檢測
                let inside = false;
                for (let i = 0, j = shape.points.length - 1; i < shape.points.length; j = i++) {
                    const xi = shape.points[i].x, yi = shape.points[i].y;
                    const xj = shape.points[j].x, yj = shape.points[j].y;
                    const intersect = ((yi > y) !== (yj > y)) &&
                        (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
                    if (intersect) inside = !inside;
                }
                return inside;
            }
            return false;
        }

        // 顯示高度輸入對話框
        function showHeightDialog() {
            const dialog = document.getElementById('heightInputDialog');
            dialog.style.display = 'block';
            
            // 如果形狀已有高度，顯示當前值
            if (selectedShape && selectedShape.zHeight !== null) {
                document.getElementById('buildingHeight').value = selectedShape.zHeight;
            } else {
                document.getElementById('buildingHeight').value = '';
            }
        }

        function confirmHeight() {
            const zHeight = parseFloat(document.getElementById('buildingHeight').value);
            if (!isNaN(zHeight) && zHeight >= 0) {
                if (selectedShape) {
                    selectedShape.zHeight = zHeight;
                    hideHeightDialog();
                    // 清除選中狀態
                    selectedShape = null;
                    // 重置為多邊形繪製模式
                    setDrawMode('polygon');
                    // 清空當前形狀陣列，準備接收新的點
                    currentShape = [];
                    redrawAll();
                }
            } else {
                alert('請輸入有效的高度值');
            }
        }

        function cancelHeight() {
            hideHeightDialog();
            // 如果是新建的形狀被取消設置高度，則移除該形狀
            if (selectedShape && selectedShape.zHeight === null) {
                const index = shapes.indexOf(selectedShape);
                if (index > -1) {
                    shapes.splice(index, 1);
                }
            }
            // 清除選中狀態
            selectedShape = null;
            // 重置為多邊形繪製模式
            setDrawMode('polygon');
            // 清空當前形狀陣列，準備接收新的點
            currentShape = [];
            redrawAll();
        }

        function hideHeightDialog() {
            document.getElementById('heightInputDialog').style.display = 'none';
        }

        // 儲存專案相關函數
        function saveProject() {
            // 檢查是否有現有專案名稱
            const projectNameInput = document.getElementById('projectName');
            if (currentProjectName) {
                projectNameInput.value = currentProjectName; // 直接帶入現有專案名稱
            } else {
                projectNameInput.value = ''; // 若無專案名稱，則清空讓使用者輸入
            }

            document.getElementById('saveProjectDialog').style.display = 'block';
        }

        function hideSaveDialog() {
            document.getElementById('saveProjectDialog').style.display = 'none';
        }

        async function confirmSaveProject() {
            let projectName = document.getElementById('projectName').value.trim();
            if (!projectName) {
                alert('請輸入專案名稱');
                return;
            }

            // 匡選模式要從 iframe 取得資料
            const inputMode = document.getElementById('inputMode').value;
            if (inputMode === 'bbox') {
                console.log('匡選模式，從 iframe 取得資料');
                
                const iframe = document.getElementById('bboxIframe');
                console.log('iframe:', iframe);
                shapes = iframe.contentWindow.bboxProjectData; 
                console.log('從 iframe 取得的匡選資料:', shapes);
            }

            try {
                // 檢查專案是否已經存在
                const checkResponse = await fetch('?action=checkName', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ projectName })
                });

                const checkResult = await checkResponse.json();

                if (!checkResult.success) {
                    if (checkResult.redirect) {
                        alert(checkResult.message);
                        window.location.href = checkResult.redirect;
                        return;
                    }
                    throw new Error(checkResult.message);
                }

                // 如果專案已存在，詢問是否覆蓋
                if (checkResult.exists) {
                    const overwrite = confirm(`專案 "${projectName}" 已存在，是否覆蓋？`);
                    if (!overwrite) return;
                }

                // 取得街廓尺寸資料
                const length = document.getElementById('length').value;
                const width = document.getElementById('width').value;
                const lengthUnit = document.getElementById('lengthUnit').value;
                const widthUnit = document.getElementById('widthUnit').value;

                console.log('shapes before projectData:', shapes);
                // 準備專案資料
                const projectData = {
                    projectName: projectName,
                    length: Number(length),
                    width: Number(width),
                    lengthUnit: lengthUnit,
                    widthUnit: widthUnit,
                    shapes: shapes.map((shape, index) => {
                        const coordinates = shape.type === 'polygon'
                            ? shape.points.map(point => ({ x: Number(point.x), y: Number(point.y) }))
                            : [{ x: Number(shape.x), y: Number(shape.y) }];

                        return {
                            shapeNumber: index + 1,
                            shapeType: shape.type,
                            area: Number(calculateArea(shape).toFixed(2)),
                            height: shape.zHeight ? Number(shape.zHeight) : null,
                            coordinates: JSON.stringify(coordinates),
                            isTarget: shape.isTarget ? true : false // 添加是否為標的建築物
                        };
                    }),
                    distances: []
                };

                // 計算形狀間的距離
                // 繪製模式算建築之間的距離，匡選模式算建築群之間的距離
                for (let i = 0; i < shapes.length; i++) {
                    for (let j = i + 1; j < shapes.length; j++) {
                        const distance = calculateEdgeDistance(shapes[i], shapes[j]);
                        projectData.distances.push({
                            shape1number: i + 1,
                            shape2number: j + 1,
                            distance: Number(distance.toFixed(2))
                        });
                    }
                }

                console.log('專案資料:', projectData);

                // 發送儲存請求
                const saveResponse = await fetch('?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(projectData)
                });

                const saveResult = await saveResponse.json();

                if (saveResult.success) {
                    currentProjectName = projectName; // 更新目前專案名稱
                    if (saveResult.projectId) {
                        currentProjectId = saveResult.projectId;
                    }
                    updateProjectNameDisplay();
                    alert('專案儲存成功！');
                    hideSaveDialog();
                } else {
                    if (saveResult.redirect) {
                        alert(saveResult.message);
                        window.location.href = saveResult.redirect;
                    } else {
                        throw new Error(saveResult.message);
                    }
                }
            } catch (error) {
                console.error('儲存失敗：', error);
                alert('儲存失敗：' + error.message);
            }
        }

        // 另存專案相關函數
        function saveAsProject() {
            document.getElementById('saveAsProjectName').value = '';
            document.getElementById('saveAsProjectDialog').style.display = 'block';
        }

        function hideSaveAsDialog() {
            document.getElementById('saveAsProjectDialog').style.display = 'none';
            document.getElementById('saveAsProjectName').value = '';
        }

        async function confirmSaveAsProject() {
            const projectName = document.getElementById('saveAsProjectName').value;
            if (!projectName) {
                alert('請輸入專案名稱');
                return;
            }

            // 匡選模式要從 iframe 取得資料
            const inputMode = document.getElementById('inputMode').value;
            if (inputMode === 'bbox') {
                console.log('匡選模式，從 iframe 取得資料');
                
                const iframe = document.getElementById('bboxIframe');
                console.log('iframe:', iframe);
                shapes = iframe.contentWindow.bboxProjectData; 
                console.log('從 iframe 取得的匡選資料:', shapes);
            }

            try {
                // 檢查名稱部分保持不變...
                const checkResponse = await fetch('?action=checkName', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ projectName: projectName })
                });
                
                const checkResult = await checkResponse.json();
                
                if (!checkResult.success) {
                    if (checkResult.redirect) {
                        alert(checkResult.message);
                        window.location.href = checkResult.redirect;
                        return;
                    }
                    throw new Error(checkResult.message);
                }

                if (checkResult.exists) {
                    alert('已存在相同名稱的專案，請使用其他名稱');
                    return;
                }

                // 取得街廓尺寸資料
                const length = document.getElementById('length').value;
                const width = document.getElementById('width').value;
                const lengthUnit = document.getElementById('lengthUnit').value;
                const widthUnit = document.getElementById('widthUnit').value;

                // 準備專案資料
                const projectData = {
                    projectName: projectName,
                    // 加入街廓尺寸資料
                    length: Number(length),
                    width: Number(width),
                    lengthUnit: lengthUnit,
                    widthUnit: widthUnit,
                    shapes: shapes.map((shape, index) => ({
                        shapeNumber: index + 1,
                        shapeType: shape.type,
                        area: Number(calculateArea(shape).toFixed(2)),
                        height: shape.zHeight ? Number(shape.zHeight) : null,
                        coordinates: JSON.stringify(shape.type === 'polygon' ? shape.points : [{
                            x: Number(shape.x),
                            y: Number(shape.y)
                        }]),
                        isTarget: shape.isTarget ? true : false // 新增這一行
                    })),
                    distances: []
                };

                // 計算距離資料
                for (let i = 0; i < shapes.length; i++) {
                    for (let j = i + 1; j < shapes.length; j++) {
                        const distance = calculateEdgeDistance(shapes[i], shapes[j]);
                        projectData.distances.push({
                            shape1number: i + 1,
                            shape2number: j + 1,
                            distance: Number(distance.toFixed(2))
                        });
                    }
                }

                const saveResponse = await fetch('?action=save', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(projectData)
                });
                
                const saveResult = await saveResponse.json();
                
                if (saveResult.success) {
                    // 更新當前專案名稱和ID
                    currentProjectName = projectName;
                    if (saveResult.projectId) {
                        currentProjectId = saveResult.projectId;
                    }
                    
                    // 更新顯示
                    updateProjectNameDisplay();
                    
                    alert('專案另存成功！');
                    hideSaveAsDialog();
                } else {
                    if (saveResult.redirect) {
                        alert(saveResult.message);
                        window.location.href = saveResult.redirect;
                    } else {
                        throw new Error(saveResult.message);
                    }
                }
            } catch (error) {
                console.error('另存失敗：', error);
                alert('另存失敗：' + error.message);
            }
        }

        // 計算面積
        function calculateArea(shape) {
            if (shape.type === 'rectangle') {
                const realWidth = Math.abs(shape.width / scaleX);
                const realHeight = Math.abs(shape.height / scaleY);
                return realWidth * realHeight;
            } else if (shape.type === 'polygon') {
                let area = 0;
                for (let i = 0; i < shape.points.length; i++) {
                    const j = (i + 1) % shape.points.length;
                    area += shape.points[i].x * shape.points[j].y;
                    area -= shape.points[j].x * shape.points[i].y;
                }
                return Math.abs(area / 2) / (scaleX * scaleY);
            }
            return 0;
        }

        // 計算兩個形狀之間的最短邊到邊距離
        function calculateEdgeDistance(shape1, shape2) {
            let minDistance = Infinity;
            
            if (shape1.type === 'rectangle' && shape2.type === 'rectangle') {
                // 矩形到矩形的距離
                const rect1 = {
                    left: shape1.x,
                    right: shape1.x + shape1.width,
                    top: shape1.y,
                    bottom: shape1.y + shape1.height
                };
                const rect2 = {
                    left: shape2.x,
                    right: shape2.x + shape2.width,
                    top: shape2.y,
                    bottom: shape2.y + shape2.height
                };
                
                // 檢查是否重疊
                if (rect1.left <= rect2.right && rect1.right >= rect2.left &&
                    rect1.top <= rect2.bottom && rect1.bottom >= rect2.top) {
                    return 0;
                }
                
                // 計算水平和垂直距離
                let dx = 0;
                let dy = 0;
                
                // 水平距離
                if (rect1.right < rect2.left) {
                    dx = rect2.left - rect1.right;
                } else if (rect2.right < rect1.left) {
                    dx = rect1.left - rect2.right;
                }
                
                // 垂直距離
                if (rect1.bottom < rect2.top) {
                    dy = rect2.top - rect1.bottom;
                } else if (rect2.bottom < rect1.top) {
                    dy = rect1.top - rect2.bottom;
                }
                
                // 轉換為實際單位並使用畢氏定理計算實際距離
                dx = dx / scaleX;  // 轉換 X 方向的距離
                dy = dy / scaleY;  // 轉換 Y 方向的距離
                
                return Math.sqrt(dx * dx + dy * dy);
            } else {
                // 處理多邊形或矩形與多邊形的情況
                const points1 = shape1.type === 'rectangle' ? 
                    [
                        {x: shape1.x, y: shape1.y},
                        {x: shape1.x + shape1.width, y: shape1.y},
                        {x: shape1.x + shape1.width, y: shape1.y + shape1.height},
                        {x: shape1.x, y: shape1.y + shape1.height}
                    ] : shape1.points;
                    
                const points2 = shape2.type === 'rectangle' ? 
                    [
                        {x: shape2.x, y: shape2.y},
                        {x: shape2.x + shape2.width, y: shape2.y},
                        {x: shape2.x + shape2.width, y: shape2.y + shape2.height},
                        {x: shape2.x, y: shape2.y + shape2.height}
                    ] : shape2.points;
                
                // 計算所有點之間的距離
                for (let i = 0; i < points1.length; i++) {
                    for (let j = 0; j < points2.length; j++) {
                        const dx = (points2[j].x - points1[i].x) / scaleX;  // 轉換 X 方向的距離
                        const dy = (points2[j].y - points1[i].y) / scaleY;  // 轉換 Y 方向的距離
                        const distance = Math.sqrt(dx * dx + dy * dy);
                        minDistance = Math.min(minDistance, distance);
                    }
                }
            }
            
            return minDistance;
        }

        // 更新專案名稱顯示
        function updateProjectNameDisplay() {
            const nameElement = document.getElementById('currentProjectName');
            if (nameElement) {
                nameElement.textContent = currentProjectName;
            }
        }

        async function createNewProject() {
            // 獲取輸入值
            const projectName = document.getElementById('newprojectName').value.trim();
            const length = document.getElementById('length').value;
            const width = document.getElementById('width').value;
            const lengthUnit = document.getElementById('lengthUnit').value;
            const widthUnit = document.getElementById('widthUnit').value;

            // 驗證輸入
            if (!projectName || !length || !width) {
                alert('請填寫所有必要資訊');
                return;
            }

            // 建立要傳送的資料物件
            const projectData = {
                projectName: projectName,
                length: Number(length),
                width: Number(width),
                lengthUnit: lengthUnit,
                widthUnit: widthUnit
            };

            try {
                // 先檢查專案名稱是否已存在
                const checkResponse = await fetch('?action=checkName', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ projectName })
                });

                const checkResult = await checkResponse.json();

                if (!checkResult.success) {
                    if (checkResult.redirect) {
                        alert(checkResult.message);
                        window.location.href = checkResult.redirect;
                        return;
                    }
                    throw new Error(checkResult.message);
                }

                // 如果專案已存在，提示使用者
                if (checkResult.exists) {
                    alert('專案名稱已存在，請使用其他名稱');
                    return;
                }

                // 顯示傳送的資料（用於偵錯）
                console.log('傳送的資料:', JSON.stringify(projectData, null, 2));

                // 創建新專案
                const response = await fetch('?action=createProject', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(projectData)
                });

                // 顯示原始回應（用於偵錯）
                const responseText = await response.text();
                console.log('伺服器回應:', responseText);

                // 嘗試解析回應
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    throw new Error(`JSON 解析錯誤: ${e.message}\n原始回應: ${responseText}`);
                }
                
                if (result.success) {
                    // 更新目前專案資訊
                    currentProjectName = projectName;
                    if (result.projectId) {
                        currentProjectId = result.projectId;
                    }
                    
                    // 隱藏輸入區域
                    document.querySelector('.section-card').style.display = 'none';
                    
                    // 顯示畫布區域
                    document.getElementById('drawingSection').style.display = 'block';
                    console.log('早安，專案創建成功:', result);
                    
                    // 初始化畫布
                    validateAndInitialize();
                    
                    // 更新專案名稱顯示
                    updateProjectNameDisplay();
                } else {
                    if (result.redirect) {
                        alert(result.message);
                        window.location.href = result.redirect;
                    } else {
                        throw new Error(result.message);
                    }
                }
            } catch (error) {
                console.error('創建專案失敗：', error);
                alert('創建專案失敗：' + error.message);
            }
        }

        function validateAndInitialize() {
            const length = document.getElementById('length').value;
            const width = document.getElementById('width').value;
            const lengthUnit = document.getElementById('lengthUnit').value;
            const widthUnit = document.getElementById('widthUnit').value;

            if (!length || !width) {
                alert('請輸入完整的街廓尺寸');
                return;
            }

            // 儲存尺寸資料
            blockDimensions = {
                length: parseFloat(length),
                width: parseFloat(width),
                lengthUnit: lengthUnit,
                widthUnit: widthUnit
            };

            document.getElementById('drawingSection').style.display = 'block';
            setInputMode(document.getElementById('inputMode').value);

            // 初始化網格
            initializeGrid();
        }

        // 載入專案相關函數
        function hideLoadDialog() {
            document.getElementById('loadProjectDialog').style.display = 'none';
        }

        function confirmLoadProject() {
            const projectSelect = document.getElementById('projectSelect');
            const selectedProjectId = projectSelect.value;
            
            if (!selectedProjectId) {
                alert('請選擇要載入的專案');
                return;
            }
            
            loadProject(selectedProjectId);
            hideLoadDialog();
        }

        // 添加縮放控制按鈕到 HTML
        function addZoomControls() {
            // 檢查是否已經存在縮放控制，避免重複添加
            if (document.querySelector('.zoom-controls')) {
                return;
            }
            
            const controlsDiv = document.createElement('div');
            controlsDiv.className = 'zoom-controls';
            controlsDiv.style.position = 'absolute';
            controlsDiv.style.top = '10px';
            controlsDiv.style.left = '10px';
            controlsDiv.style.zIndex = '100';
            
            controlsDiv.innerHTML = `
                <button id="zoomInBtn" class="button" style="margin-right: 5px;">🔍+</button>
                <button id="zoomOutBtn" class="button" style="margin-right: 5px;">🔍-</button>
                <button id="resetZoomBtn" class="button">🔄</button>
            `;
            
            const canvasContainer = document.querySelector('.canvas-container');
            canvasContainer.appendChild(controlsDiv);
            
            // 使用事件監聽器方式綁定，而不是直接賦值
            document.getElementById('zoomInBtn').addEventListener('click', function() {
                adjustZoom(0.1);
            });
            
            document.getElementById('zoomOutBtn').addEventListener('click', function() {
                adjustZoom(-0.1);
            });
            
            document.getElementById('resetZoomBtn').addEventListener('click', function() {
                resetZoomAndPan();
            });
            
            console.log("縮放控制按鈕已添加並綁定事件");
        }

        // 調整縮放級別
        function adjustZoom(delta) {
            console.log(`嘗試調整縮放: 當前=${zoomLevel}, 增量=${delta}`);
            
            const oldZoom = zoomLevel;
            // 限制最小和最大縮放級別
            const newZoom = Math.min(Math.max(zoomLevel + delta, minZoom), maxZoom);
            
            if (newZoom !== oldZoom) {
                // 如果從縮放狀態返回到100%，重置平移
                if (oldZoom > 1 && newZoom === 1) {
                    panOffsetX = 0;
                    panOffsetY = 0;
                }
                
                zoomLevel = newZoom;
                console.log(`縮放級別已調整為: ${zoomLevel.toFixed(2)}`);
                
                // 重繪所有內容
                redrawAll();
            } else {
                console.log(`縮放未變更: 已達到極限 ${oldZoom === minZoom ? '最小' : '最大'} 縮放值`);
            }
        }

        // 重置縮放和平移
        function resetZoomAndPan() {
            console.log("重置縮放和平移: 從", zoomLevel, "到 1.0");
            
            // 重置縮放級別和平移偏移量
            zoomLevel = 1;
            panOffsetX = 0;
            panOffsetY = 0;
            
            // 重繪畫布以應用變更
            redrawAll();
            
            // 更新滑鼠游標
            canvas.style.cursor = 'default';
            
            console.log("已重置縮放和平移完成");
        }

        // 添加滑鼠滾輪事件用於縮放
        function setupWheelZoom() {
            canvas.addEventListener('wheel', function(e) {
                e.preventDefault(); // 防止頁面滾動
                
                // 獲取滑鼠在畫布上的位置
                const rect = canvas.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;
                
                // 計算縮放增量
                const delta = -e.deltaY / 1000; // 調整滾動靈敏度
                const oldZoom = zoomLevel;
                const newZoom = Math.min(Math.max(zoomLevel + delta, minZoom), maxZoom);
                
                // 應用縮放
                if (newZoom !== oldZoom) {
                    // 如果縮放返回到100%，重置平移
                    if (oldZoom > 1 && newZoom <= 1) {
                        panOffsetX = 0;
                        panOffsetY = 0;
                    }
                    
                    zoomLevel = newZoom;
                    redrawAll();
                }
            });
        }

        // 設置拖曳平移功能
        function setupPanning() {
            // 按下中鍵開始拖曳
            canvas.addEventListener('mousedown', function(e) {
                // 使用中鍵(滾輪)拖曳或按住Ctrl鍵拖曳
                if (e.button === 1 || (e.button === 0 && e.ctrlKey)) {
                    // 檢查是否允許平移（只在縮放級別不是1時允許）
                    if (zoomLevel > 1.001) {  // 使用略大於1的值處理浮點誤差
                        e.preventDefault();
                        isDragging = true;
                        lastPanX = e.clientX;
                        lastPanY = e.clientY;
                        canvas.style.cursor = 'grabbing';
                    } else {
                        // 可以選擇在這裡顯示提示
                        console.log("在100%縮放比例下無法平移");
                    }
                }
            });
            
            // 鼠標移動處理平移
            canvas.addEventListener('mousemove', function(e) {
                if (isDragging) {
                    e.preventDefault();
                    
                    // 計算鼠標移動的距離
                    const dx = e.clientX - lastPanX;
                    const dy = e.clientY - lastPanY;
                    
                    // 更新平移偏移量
                    panOffsetX += dx;
                    panOffsetY += dy;
                    
                    // 更新上次位置
                    lastPanX = e.clientX;
                    lastPanY = e.clientY;
                    
                    // 立即重繪畫布
                    redrawAll();
                }
            });
            
            // 鼠標松開停止拖曳
            window.addEventListener('mouseup', function(e) {
                if (isDragging) {
                    isDragging = false;
                    canvas.style.cursor = 'default';
                }
            });
            
            // 支援Alt鍵暫時啟用平移模式
            window.addEventListener('keydown', function(e) {
                if (e.key === 'Alt' && zoomLevel > 1.001) {  // 只在縮放級別大於1時改變游標
                    canvas.style.cursor = 'grab';
                }
            });
            
            window.addEventListener('keyup', function(e) {
                if (e.key === 'Alt') {
                    canvas.style.cursor = 'default';
                }
            });
        }

        // 添加事件監聽器
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('click', handlePolygonClick);

        // 修改 canvas 點擊事件處理
        canvas.addEventListener('mousemove', function(e) {
            // 獲取滑鼠的物理位置
            const rect = canvas.getBoundingClientRect();
            
            // 計算滑鼠在畫布元素上的實際位置
            const mouseXOnCanvas = e.clientX - rect.left;
            const mouseYOnCanvas = e.clientY - rect.top;
            
            // 計算滑鼠在畫布內部座標系統的位置
            const scaleFactorX = canvas.width / rect.width;
            const scaleFactorY = canvas.height / rect.height;
            
            const rawCanvasX = mouseXOnCanvas * scaleFactorX;
            const rawCanvasY = mouseYOnCanvas * scaleFactorY;
            
            const posX = (rawCanvasX - panOffsetX) / zoomLevel;
            const posY = (rawCanvasY - panOffsetY) / zoomLevel;
            
            // 網格對齊
            if (document.getElementById('snapToGrid').checked) {
                mouseX = Math.round(posX / gridSize) * gridSize;
                mouseY = Math.round(posY / gridSize) * gridSize;
            } else {
                mouseX = posX;
                mouseY = posY;
            }
            
            // 只有在非拖曳模式下才處理繪圖
            if (!isDragging) {
                // 在多邊形模式且有活動的形狀時重繪
                if (drawMode === 'polygon' && currentShape.length > 0) {
                    redrawAll();
                }
                
                // 在刪除模式下檢測懸停
                if (drawMode === 'delete') {
                    let foundHover = false;
                    for (let i = 0; i < shapes.length; i++) {
                        if (isPointInShape(mouseX, mouseY, shapes[i])) {
                            hoveredShapeIndex = i;
                            foundHover = true;
                            redrawAll(); // 重繪以顯示懸停效果
                            break;
                        }
                    }
                    
                    // 如果滑鼠沒有懸停在任何形狀上，但先前有懸停效果
                    if (!foundHover && hoveredShapeIndex !== -1) {
                        hoveredShapeIndex = -1;
                        redrawAll();
                    }
                }
            }
        });
    </script>
</body>
</html>