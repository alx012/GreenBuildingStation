<?php
session_start();
include 'db_config.php';

// 檢查是否已登入
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 檢查是否有 building_id
if (!isset($_GET['building_id']) && !isset($_SESSION['building_id'])) {
    header('Location: dashboard.php');
    exit;
}

// 設定 building_id
if (isset($_GET['building_id'])) {
    $_SESSION['building_id'] = $_GET['building_id'];
}

// 獲取專案資訊
$buildingId = $_SESSION['building_id'];
$projectName = $_SESSION['gbd_project_name'] ?? '未命名專案';

// 從資料庫獲取專案結構資料
try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 獲取樓層資料
    $stmtFloors = $conn->prepare("
        SELECT floor_id, floor_number, Area, Height, Coordinates
        FROM [Test].[dbo].[GBD_Project_floors]
        WHERE building_id = :building_id
        ORDER BY floor_number
    ");
    $stmtFloors->execute([':building_id' => $buildingId]);
    
    $floors = $stmtFloors->fetchAll(PDO::FETCH_ASSOC);
    
    // 對每個樓層獲取單位資料
    foreach ($floors as &$floor) {
        $stmtUnits = $conn->prepare("
            SELECT unit_id, unit_number, Area, Height, Coordinates
            FROM [Test].[dbo].[GBD_Project_units]
            WHERE floor_id = :floor_id
            ORDER BY unit_number
        ");
        $stmtUnits->execute([':floor_id' => $floor['floor_id']]);
        
        $floor['units'] = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
        
        // 對每個單位獲取房間資料
        foreach ($floor['units'] as &$unit) {
            $stmtRooms = $conn->prepare("
                SELECT room_id, room_number, length, depth, window_position, Area, Height, Coordinates
                FROM [Test].[dbo].[GBD_Project_rooms]
                WHERE unit_id = :unit_id
                ORDER BY room_number
            ");
            $stmtRooms->execute([':unit_id' => $unit['unit_id']]);
            
            $unit['rooms'] = $stmtRooms->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // 獲取專案詳細資訊
    $stmtProject = $conn->prepare("
        SELECT building_name, address, created_at, updated_at, building_angle, building_orientation
        FROM [Test].[dbo].[GBD_Project]
        WHERE building_id = :building_id
    ");
    $stmtProject->execute([':building_id' => $buildingId]);
    
    $projectInfo = $stmtProject->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "資料庫錯誤: " . $e->getMessage();
    $floors = [];
    $projectInfo = [];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>專案編輯 - <?php echo htmlspecialchars($projectName); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .project-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 20px;
        }
        .project-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ddd;
        }
        .floor-card {
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            overflow: hidden;
        }
        .floor-header {
            background-color: #f5f5f5;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .unit-list {
            padding: 0 15px;
        }
        .unit-card {
            margin: 15px 0;
            border: 1px solid #eee;
            border-radius: 8px;
            overflow: hidden;
        }
        .unit-header {
            background-color: #f9f9f9;
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-list {
            padding: 0 15px;
        }
        .room-card {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #f0f0f0;
            border-radius: 6px;
            background-color: #fafafa;
        }
        .building-info {
            margin-bottom: 30px;
        }
        .info-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="container project-container">
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <div class="project-header">
                <h2><?php echo htmlspecialchars($projectName); ?></h2>
                <p class="text-muted"><?php echo htmlspecialchars($projectInfo['address'] ?? ''); ?></p>
            </div>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="building-info">
                        <div class="info-card">
                            <h4>專案資訊</h4>
                            <table class="table table-sm">
                                <tr>
                                    <th>創建日期：</th>
                                    <td><?php echo date('Y-m-d', strtotime($projectInfo['created_at'] ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th>建築方位：</th>
                                    <td>
                                        <?php echo htmlspecialchars($projectInfo['building_orientation'] ?? '未設定'); ?>
                                        <?php if (isset($projectInfo['building_angle'])): ?>
                                            (<?php echo htmlspecialchars($projectInfo['building_angle']); ?>°)
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>樓層數量：</th>
                                    <td><?php echo count($floors); ?></td>
                                </tr>
                            </table>
                            
                            <div class="mt-3">
                                <button class="btn btn-primary" id="editProjectBtn">編輯專案</button>
                                <button class="btn btn-success" id="calculateBtn">進行計算</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <h4>建築結構</h4>
                    <?php if (empty($floors)): ?>
                        <div class="alert alert-info">
                            尚未有任何樓層資料，請先添加樓層資訊。
                        </div>
                    <?php else: ?>
                        <?php foreach ($floors as $floor): ?>
                            <div class="floor-card">
                                <div class="floor-header">
                                    <h5>樓層 <?php echo htmlspecialchars($floor['floor_number']); ?></h5>
                                    <div>
                                        <span class="badge bg-secondary">面積: <?php echo htmlspecialchars($floor['Area'] ?? '未設定'); ?> m²</span>
                                        <span class="badge bg-secondary">高度: <?php echo htmlspecialchars($floor['Height'] ?? '未設定'); ?> m</span>
                                    </div>
                                </div>
                                
                                <div class="unit-list">
                                    <?php if (empty($floor['units'])): ?>
                                        <div class="alert alert-light my-3">
                                            此樓層尚未有任何單位資料。
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($floor['units'] as $unit): ?>
                                            <div class="unit-card">
                                                <div class="unit-header">
                                                    <h6>單位 <?php echo htmlspecialchars($unit['unit_number']); ?></h6>
                                                    <div>
                                                        <span class="badge bg-light text-dark">面積: <?php echo htmlspecialchars($unit['Area'] ?? '未設定'); ?> m²</span>
                                                    </div>
                                                </div>
                                                
                                                <div class="room-list">
                                                    <?php if (empty($unit['rooms'])): ?>
                                                        <div class="alert alert-light my-2 py-2">
                                                            此單位尚未有任何房間資料。
                                                        </div>
                                                    <?php else: ?>
                                                        <?php foreach ($unit['rooms'] as $room): ?>
                                                            <div class="room-card">
                                                                <div class="d-flex justify-content-between">
                                                                    <div>
                                                                        <strong><?php echo htmlspecialchars($room['room_number']); ?></strong>
                                                                    </div>
                                                                    <div>
                                                                        <span class="text-muted">面積: <?php echo htmlspecialchars($room['Area'] ?? '未設定'); ?> m²</span>
                                                                        <?php if (!empty($room['window_position'])): ?>
                                                                            <span class="text-muted">窗戶朝向: <?php echo htmlspecialchars($room['window_position']); ?></span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // 編輯專案按鈕點擊事件
            $('#editProjectBtn').click(function() {
                alert('編輯功能開發中');
            });
            
            // 進行計算按鈕點擊事件
            $('#calculateBtn').click(function() {
                window.location.href = 'greenbuildingcal-new.php?building_id=<?php echo $buildingId; ?>';
            });
        });
    </script>
</body>
</html> 