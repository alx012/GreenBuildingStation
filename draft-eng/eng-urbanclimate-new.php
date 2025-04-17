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
            INSERT INTO Ubclm_shapes (ProjectID, ShapeNumber, ShapeType, Area, Height, Coordinates)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($data['shapes'] as $shape) {
            $shapeStmt->execute([
                $projectId,
                $shape['shapeNumber'],
                $shape['shapeType'],
                $shape['area'],
                $shape['height'],
                $shape['coordinates']
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

        // 插入新專案
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
        
        $conn->commit();

        return [
            'success' => true,
            'message' => '專案創建成功',
            'projectId' => $projectId
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
            background: #f8f9fa;
        }

        .navbar-brand {
            font-weight: bold;
        }

        .content my-3 {
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


    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="container my-3">
        <h1>MICROCLIMATE Model Setup for Street Blocks</h1>
            
        <!-- Input area card -->
        <div class="section-card">
            <div class="input-section" id="inputSection">
                <h2>Enter Project Information</h2>
                <div class="input-group">
                    <label>Project Name:</label>
                    <input type="text" id="newprojectName" required>
                </div>
                <div class="input-group">
                    <label>Length:</label>
                    <input type="number" id="length" min="1" step="any" required>
                    <select id="lengthUnit">
                        <option value="km">Kilometers</option>
                        <option value="m" selected>Meters</option>
                        <option value="cm">Centimeters</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Width:</label>
                    <input type="number" id="width" min="1" step="any" required>
                    <select id="widthUnit">
                        <option value="km">Kilometers</option>
                        <option value="m" selected>Meters</option>
                        <option value="cm">Centimeters</option>
                    </select>
                </div>
                <div>
                    <button class="button" onclick="createNewProject()">Create Project</button>
                </div>
            </div>
        </div>

        <!-- Drawing related area (initially hidden) -->
        <div id="drawingSection">
            <div class="section-card">
                <div class="project-name-display">
                    <h3>Current Project: <span id="currentProjectName">Empty Project</span></h3>
                </div>
                <h2>Toolbar</h2>
                <div class="controls">
                    <button class="button" onclick="setDrawMode('polygon')">🖊️ Draw Polygon</button>
                    <button class="button" onclick="setDrawMode('height')">🏗️ Modify Building Height</button>
                    <button class="button" onclick="clearCanvasWithConfirm()">🧽 Clear Canvas</button>
                    <button class="button" onclick="resetArea()" style="background-color:rgb(212, 157, 38);">🔄 Reset Project</button>
                    <button class="button" onclick="saveProject()">💾 Save Project</button>
                    <button class="button" onclick="saveAsProject()">📝 Save As</button>
                </div>
                <div class="draw-mode-controls">
                    <label>
                        <input type="checkbox" id="snapToGrid" checked>
                        Snap to Grid
                    </label>
                </div>
                <!-- 添加高度輸入對話框 -->
                <div id="heightInputDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                    background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                    <h3>修改建物高度</h3>
                    <div class="input-group">
                        <input type="number" id="buildingHeight" min="0" step="any">
                        <span id="heightUnit">公尺</span>
                    </div>
                    <div style="margin-top: 10px;">
                        <button class="button" onclick="confirmHeight()">確認</button>
                        <button class="button" onclick="cancelHeight()" style="margin-left: 10px; background-color: #999;">取消</button>
                    </div>
                </div>
            </div>

            <!-- 添加專案儲存對話框 -->
            <div id="saveProjectDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                <h3>儲存專案</h3>
                <div class="input-group">
                    <label>專案名稱：</label>
                    <input type="text" id="projectName">
                </div>
                <div style="margin-top: 10px;">
                    <button class="button" onclick="confirmSaveProject()">確認</button>
                    <button class="button" onclick="hideSaveDialog()" style="margin-left: 10px; background-color: #999;">取消</button>
                </div>
            </div>

            <!-- 添加另存專案對話框 -->
            <div id="saveAsProjectDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                <h3>另存新檔</h3>
                <div class="input-group">
                    <label>新專案名稱：</label>
                    <input type="text" id="saveAsProjectName">
                </div>
                <div style="margin-top: 10px;">
                    <button class="button" onclick="confirmSaveAsProject()">確認</button>
                    <button class="button" onclick="hideSaveAsDialog()" style="margin-left: 10px; background-color: #999;">取消</button>
                </div>
            </div>
                
            <!-- 添加專案載入對話框 -->
            <div id="loadProjectDialog" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 1000;">
                <h3>載入專案</h3>
                <div class="input-group">
                    <select id="projectSelect"></select>
                </div>
                <div style="margin-top: 10px;">
                    <button class="button" onclick="confirmLoadProject()">確認</button>
                    <button class="button" onclick="hideLoadDialog()" style="margin-left: 10px; background-color: #999;">取消</button>
                </div>
            </div>

            <div class="canvas-container">
                <canvas id="drawingCanvas" width="1500" height="800"></canvas>
                <div id="gridInfo"></div>
            </div>
        </div>
    </div>

    <script>
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

            // 顯示繪圖相關區域
            document.getElementById('drawingSection').style.display = 'block';

            // 初始化網格
            initializeGrid();

        }

        // 初始化專案名稱顯示區域
        function initializeProjectNameDisplay() {
            // 檢查是否已有專案名稱元素，如果沒有則創建
            if (!document.getElementById('currentProjectName')) {
                // 創建專案名稱顯示區域
                const projectNameDisplay = document.createElement('div');
                projectNameDisplay.className = 'project-name-display';
                projectNameDisplay.innerHTML = `<h3>目前專案: <span id="currentProjectName">${currentProjectName}</span></h3>`;
                
                // 找到工具列所在的section-card元素
                const toolbarSection = document.querySelector('#drawingSection .section-card');
                
                // 將專案名稱區域插入工具列之前
                if (toolbarSection) {
                    toolbarSection.insertBefore(projectNameDisplay, toolbarSection.firstChild);
                }
            }
            
            // 更新專案名稱顯示
            updateProjectNameDisplay();
        }

        // 更新專案名稱顯示
        function updateProjectNameDisplay() {
            const nameElement = document.getElementById('currentProjectName');
            if (nameElement) {
                nameElement.textContent = currentProjectName;
            }
        }

        function drawGrid() {
        ctx.beginPath();
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth = 1;
        
        // 繪製垂直線
        for (let x = 0; x <= canvas.width; x += gridSize) {
            ctx.moveTo(x + 0.5, 0);
            ctx.lineTo(x + 0.5, canvas.height);
        }
        
        // 繪製水平線
        for (let y = 0; y <= canvas.height; y += gridSize) {
            ctx.moveTo(0, y + 0.5);
            ctx.lineTo(canvas.width, y + 0.5);
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

            // 新增一個專門處理按鈕點擊清除的函數
            function clearCanvasWithConfirm() {
                const isConfirmed = confirm('確定要清除畫布上所有的圖形嗎？');
                if (isConfirmed) {
                    clearCanvas();
                }
            }

            // 保持原本的 clearCanvas 函數不變，供其他功能直接調用
            function clearCanvas() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                shapes = [];
                currentShape = [];
                drawGrid();
            }

            function drawShape(shape, index) {
            ctx.strokeStyle = shape === selectedShape ? '#ff0000' : '#000';
            ctx.lineWidth = shape === selectedShape ? 3 : 1;
            
            if (shape.type === 'rectangle') {
                ctx.strokeRect(shape.x, shape.y, shape.width, shape.height);
                const centerX = shape.x + shape.width / 2;
                const centerY = shape.y + shape.height / 2;
                drawShapeInfo(centerX, centerY, index + 1, shape.zHeight); // 改用 zHeight
            } else if (shape.type === 'polygon') {
                ctx.beginPath();
                ctx.moveTo(shape.points[0].x, shape.points[0].y);
                shape.points.forEach(point => {
                    ctx.lineTo(point.x, point.y);
                });
                ctx.closePath();
                ctx.stroke();
                
                let centerX = 0, centerY = 0;
                shape.points.forEach(point => {
                    centerX += point.x;
                    centerY += point.y;
                });
                centerX /= shape.points.length;
                centerY /= shape.points.length;
                drawShapeInfo(centerX, centerY, index + 1, shape.zHeight); // 改用 zHeight
            }
            ctx.lineWidth = 1;
        }

        
        // 修改形狀資訊顯示
        function drawShapeInfo(x, y, number, zHeight) {
            ctx.fillStyle = '#000';
            ctx.font = '16px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(number.toString(), x, y);
            if (zHeight !== undefined && zHeight !== null) {
                ctx.fillText(`H: ${zHeight}`, x, y + 20);
            }
        }

        // 繪製形狀編號
        function drawShapeNumber(x, y, number) {
            ctx.fillStyle = '#000';
            ctx.font = '16px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(number.toString(), x, y);
        }

        //重繪畫布
        function redrawAll() {
            // 清除畫布
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // 繪製網格
            drawGrid();
            
            // 繪製所有已完成形狀的填充 (最底層)
            shapes.forEach((shape) => {
                if (shape.type === 'polygon') {
                    ctx.beginPath();
                    ctx.moveTo(shape.points[0].x, shape.points[0].y);
                    
                    for (let i = 1; i < shape.points.length; i++) {
                        ctx.lineTo(shape.points[i].x, shape.points[i].y);
                    }
                    
                    ctx.closePath();
                    ctx.fillStyle = 'rgba(0, 150, 255, 0.3)';
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
                    ctx.strokeStyle = 'blue';
                    ctx.stroke();
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
                    drawShapeInfo(centerX, centerY, index + 1, shape.zHeight);
                }
            });
        }

        // 設置繪圖模式
        function setDrawMode(mode) {
            if (mode === 'polygon' || mode === 'height') {
                drawMode = mode;
                currentShape = [];
                heightInputMode = mode === 'height';
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

        // 首先新增一個計算兩點之間實際距離的函數
        function calculateDistance(x1, y1, x2, y2) {
            // 將畫布上的距離轉換為實際距離
            const dx = Math.abs(x2 - x1) / scaleX;
            const dy = Math.abs(y2 - y1) / scaleY;
            return Math.sqrt(dx * dx + dy * dy);
        }

        // 新增一個繪製線段長度的函數
        function drawLineLength(x1, y1, x2, y2) {
            const distance = calculateDistance(x1, y1, x2, y2);
            // 如果距離為 0，則不顯示
            if (distance === 0) return;
            
            const centerX = (x1 + x2) / 2;
            const centerY = (y1 + y2) / 2;
            
            ctx.save();
            ctx.font = '12px Arial';
            ctx.fillStyle = '#000';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            
            const unit = document.getElementById('lengthUnit').value;
            
            if (unit === 'km') {
                ctx.fillText(`${distance.toFixed(3)}${unit}`, centerX, centerY - 10);
            } else {
                ctx.fillText(`${distance.toFixed(1)}${unit}`, centerX, centerY - 10);
            }
            
            ctx.restore();
        }

        // 處理多邊形點擊
        function handlePolygonClick(e) {
            if (drawMode !== 'polygon') return;

            const rect = canvas.getBoundingClientRect();
            const scaleFactorX = canvas.width / rect.width;
            const scaleFactorY = canvas.height / rect.height;
            
            let clickX = (e.clientX - rect.left) * scaleFactorX;
            let clickY = (e.clientY - rect.top) * scaleFactorY;

            if (document.getElementById('snapToGrid').checked) {
                clickX = Math.round(clickX / gridSize) * gridSize;
                clickY = Math.round(clickY / gridSize) * gridSize;
            }

            // 檢查是否完成多邊形
            if (currentShape.length > 2) {
                const firstPoint = currentShape[0];
                const distance = Math.sqrt(
                    Math.pow(clickX - firstPoint.x, 2) + 
                    Math.pow(clickY - firstPoint.y, 2)
                );

                if (distance < gridSize) {
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

            // 開始新的多邊形或添加新點
            if (currentShape.length === 0) {
                // 檢查是否點擊在現有形狀上
                for (let shape of shapes) {
                    if (isPointInShape(clickX, clickY, shape)) {
                        return;
                    }
                }
            }

            currentShape.push({ x: clickX, y: clickY });
            redrawAll();
        }

        // 繪製當前的多邊形
        function drawCurrentPolygon() {
            if (currentShape.length === 0) return;

            ctx.beginPath();
            ctx.strokeStyle = '#000';
            ctx.moveTo(currentShape[0].x, currentShape[0].y);
            
            // 只繪製線段，不顯示點的標記
            for (let i = 1; i < currentShape.length; i++) {
                ctx.lineTo(currentShape[i].x, currentShape[i].y);
                // 只顯示線段長度
                drawLineLength(
                    currentShape[i-1].x, 
                    currentShape[i-1].y, 
                    currentShape[i].x, 
                    currentShape[i].y
                );
            }
            
            // 如果正在繪製新的線段
            if (currentShape.length >= 1) {
                const lastPoint = currentShape[currentShape.length - 1];
                
                // 繪製當前動態線段
                if (!(mouseX === lastPoint.x && mouseY === lastPoint.y)) {
                    ctx.moveTo(lastPoint.x, lastPoint.y);
                    ctx.lineTo(mouseX, mouseY);
                    // 只在滑鼠移動時顯示距離
                    drawLineLength(lastPoint.x, lastPoint.y, mouseX, mouseY);
                }
                
                // 檢查是否接近起點
                if (currentShape.length > 1) {
                    const distanceToStart = Math.sqrt(
                        Math.pow(mouseX - currentShape[0].x, 2) + 
                        Math.pow(mouseY - currentShape[0].y, 2)
                    );
                    
                    if (distanceToStart < gridSize * 2) {
                        ctx.moveTo(mouseX, mouseY);
                        ctx.lineTo(currentShape[0].x, currentShape[0].y);
                        drawLineLength(mouseX, mouseY, currentShape[0].x, currentShape[0].y);
                    }
                }
            }
            
            ctx.stroke();
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

        // 修改 Shape 物件結構，添加高度屬性
        function createShape(type, props) {
            return {
                type: type,
                zHeight: null, // 改用 zHeight 替代 height
                ...props
            };
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
            
            // 設置單位
            document.getElementById('heightUnit').textContent = document.getElementById('lengthUnit').value;
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

        // 修改 canvas 點擊事件處理
        canvas.addEventListener('mousemove', function(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleFactorX = canvas.width / rect.width;
            const scaleFactorY = canvas.height / rect.height;
            
            mouseX = (e.clientX - rect.left) * scaleFactorX;
            mouseY = (e.clientY - rect.top) * scaleFactorY;

            if (document.getElementById('snapToGrid').checked) {
                mouseX = Math.round(mouseX / gridSize) * gridSize;
                mouseY = Math.round(mouseY / gridSize) * gridSize;
            }

            // 只在多邊形模式且有活動的形狀時重繪
            if (drawMode === 'polygon' && currentShape.length > 0) {
                redrawAll();
            }
        });

        canvas.addEventListener('click', function(e) {
            if (drawMode === 'polygon') {
                handlePolygonClick(e);
            } else if (drawMode === 'height') {
                const rect = canvas.getBoundingClientRect();
                const scaleFactorX = canvas.width / rect.width;
                const scaleFactorY = canvas.height / rect.height;
                
                let clickX = (e.clientX - rect.left) * scaleFactorX;
                let clickY = (e.clientY - rect.top) * scaleFactorY;

                // 檢查點擊是否在任何形狀內
                for (let shape of shapes) {
                    if (isPointInShape(clickX, clickY, shape)) {
                        selectedShape = shape;
                        showHeightDialog();
                        break;
                    }
                }
            }
        });

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

        // 1.1 儲存專案按鈕視窗
        function saveProject() {
            // 檢查是否有形狀要儲存
            if (shapes.length === 0) {
                alert('請先繪製至少一個形狀');
                return;
            }
            // 檢查是否有現有專案名稱
            const projectNameInput = document.getElementById('projectName');
            if (currentProjectName) {
                projectNameInput.value = currentProjectName; // 直接帶入現有專案名稱
            } else {
                projectNameInput.value = ''; // 若無專案名稱，則清空讓使用者輸入
            }

            document.getElementById('saveProjectDialog').style.display = 'block';
        }

        // 1.2 隱藏儲存專案視窗
        function hideSaveDialog() {
            document.getElementById('saveProjectDialog').style.display = 'none';
        }

        // 1.3 確認儲存專案
        async function confirmSaveProject() {
            let projectName = document.getElementById('projectName').value.trim();
            if (!projectName) {
                alert('請輸入專案名稱');
                return;
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
                            coordinates: JSON.stringify(coordinates)
                        };
                    }),
                    distances: []
                };

                // 計算形狀間的距離
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

        // 2. 另存專案按鈕事件
        async function confirmSaveAsProject() {
            const projectName = document.getElementById('saveAsProjectName').value;
            if (!projectName) {
                alert('請輸入專案名稱');
                return;
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
                        }])
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

        // 2.1 另存專案按鈕視窗
        function saveAsProject() {
            if (shapes.length === 0) {
                alert('請先繪製至少一個形狀');
                return;
            }
            
            // 使用新的對話框
            document.getElementById('saveAsProjectName').value = '';
            document.getElementById('saveAsProjectDialog').style.display = 'block';
        }

        // 2.2 隱藏另存專案按鈕視窗
        function hideSaveAsDialog() {
            document.getElementById('saveAsProjectDialog').style.display = 'none';
            document.getElementById('saveAsProjectName').value = '';
        }

        // 確保在頁面載入完成後初始化專案名稱顯示
        document.addEventListener('DOMContentLoaded', function() {
            // 如果繪圖區域已經可見，則初始化專案名稱顯示
            if (document.getElementById('drawingSection').style.display !== 'none') {
                initializeProjectNameDisplay();
            }
        });

        // 添加事件監聽器
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('click', handlePolygonClick);
    </script>
</body>
</html>