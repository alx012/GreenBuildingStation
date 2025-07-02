<?php
/**
 * 測試平面圖上傳和分析功能
 */

// 啟動 session
session_start();

// 模擬登入用戶
$_SESSION['user_id'] = 1;

require_once 'floorplan_upload.php';

echo "<!DOCTYPE html>\n";
echo "<html lang='zh-TW'>\n<head>\n";
echo "<meta charset='UTF-8'>\n";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
echo "<title>🤖 Gemini 平面圖分析完整測試</title>\n";
echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' />\n";
echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' />\n";
echo "<style>\n";
echo ".floor, .unit, .room {\n";
echo "    border: 1px solid #000;\n";
echo "    margin: 10px 0;\n";
echo "    padding: 10px;\n";
echo "    border-radius: 10px;\n";
echo "    display: flex;\n";
echo "    flex-direction: column;\n";
echo "}\n";
echo ".floor:nth-child(odd) {\n";
echo "    background-color: rgba(191, 202, 194, 0.7);\n";
echo "}\n";
echo ".floor:nth-child(even) {\n";
echo "    background-color: rgba(235, 232, 227, 0.7);\n";
echo "}\n";
echo ".header-row {\n";
echo "    display: grid;\n";
echo "    grid-template-columns: repeat(8, 1fr);\n";
echo "    gap: 8px;\n";
echo "    padding: 10px;\n";
echo "    font-weight: bold;\n";
echo "    border-bottom: 2px solid #ddd;\n";
echo "    font-size: 14px;\n";
echo "}\n";
echo ".header-row div {\n";
echo "    flex: 1;\n";
echo "    text-align: center;\n";
echo "    padding: 5px;\n";
echo "    border-bottom: 1px solid #000;\n";
echo "}\n";
echo ".room-row {\n";
echo "    display: grid;\n";
echo "    grid-template-columns: repeat(8, 1fr);\n";
echo "    gap: 8px;\n";
echo "    padding: 8px 10px;\n";
echo "    border-bottom: 1px solid #eee;\n";
echo "    align-items: center;\n";
echo "}\n";
echo ".room-row input {\n";
echo "    padding: 6px 8px;\n";
echo "    border: 1px solid #ddd;\n";
echo "    border-radius: 4px;\n";
echo "    width: 100%;\n";
echo "    box-sizing: border-box;\n";
echo "}\n";
echo ".btn-loading {\n";
echo "    position: relative;\n";
echo "    color: transparent;\n";
echo "}\n";
echo ".btn-loading::after {\n";
echo "    content: \"\";\n";
echo "    position: absolute;\n";
echo "    width: 16px;\n";
echo "    height: 16px;\n";
echo "    top: 50%;\n";
echo "    left: 50%;\n";
echo "    margin-left: -8px;\n";
echo "    margin-top: -8px;\n";
echo "    border-radius: 50%;\n";
echo "    border: 2px solid #ffffff;\n";
echo "    border-top-color: transparent;\n";
echo "    animation: spinner 0.8s linear infinite;\n";
echo "}\n";
echo "@keyframes spinner {\n";
echo "    to {\n";
echo "        transform: rotate(360deg);\n";
echo "    }\n";
echo "}\n";
echo ".analysis-result {\n";
echo "    background: #f8f9fa;\n";
echo "    border: 1px solid #dee2e6;\n";
echo "    border-radius: 8px;\n";
echo "    padding: 20px;\n";
echo "    margin: 20px 0;\n";
echo "}\n";
echo ".image-preview {\n";
echo "    max-width: 100%;\n";
echo "    max-height: 400px;\n";
echo "    border: 1px solid #ddd;\n";
echo "    border-radius: 8px;\n";
echo "    margin: 10px 0;\n";
echo "}\n";
echo "</style>\n";
echo "</head>\n<body>\n";

echo "<div class='container mt-4'>\n";
echo "<h1 class='text-center mb-4'>\n";
echo "<i class='fas fa-robot text-primary'></i> \n";
echo "Gemini 平面圖分析完整測試\n";
echo "</h1>\n";

// 測試說明
echo "<div class='alert alert-info'>\n";
echo "<h5><i class='fas fa-info-circle'></i> 測試說明</h5>\n";
echo "<p>本頁面用於測試完整的 Gemini AI 平面圖分析流程，包括：</p>\n";
echo "<ul class='mb-0'>\n";
echo "<li>檔案上傳和驗證</li>\n";
echo "<li>Gemini API 圖像分析</li>\n";
echo "<li>資料格式轉換（嵌套 → 平級）</li>\n";
echo "<li>表格自動填入</li>\n";
echo "<li>資料庫儲存</li>\n";
echo "</ul>\n";
echo "</div>\n";

// 圖片預覽區
echo "<div class='row'>\n";
echo "<div class='col-md-6'>\n";
echo "<div class='card'>\n";
echo "<div class='card-header'>\n";
echo "<h5><i class='fas fa-image'></i> 測試平面圖</h5>\n";
echo "</div>\n";
echo "<div class='card-body'>\n";
echo "<?php\n";
$imagePath = 'uploads/floorplans/floorplan_3358_1750861587.jpg';
if (file_exists($imagePath)):
?>
<img src='<?php echo $imagePath; ?>' class='image-preview' alt='測試平面圖'>
<p class='text-muted'>檔案: <?php echo basename($imagePath); ?></p>
<p class='text-muted'>大小: <?php echo number_format(filesize($imagePath) / 1024, 1); ?> KB</p>
<?php else: ?>
<div class='alert alert-warning'>
    <i class='fas fa-exclamation-triangle'></i> 
    測試圖片不存在: <?php echo $imagePath; ?>
</div>
<?php endif; ?>
echo "</div>\n";
echo "</div>\n";

echo "<div class='col-md-6'>\n";
echo "<div class='card'>\n";
echo "<div class='card-header'>\n";
echo "<h5><i class='fas fa-cogs'></i> 測試控制台</h5>\n";
echo "</div>\n";
echo "<div class='card-body'>\n";
echo "<button onclick='testGeminiAnalysis()' class='btn btn-primary mb-3' id='testBtn'>\n";
echo "<i class='fas fa-play'></i> 開始測試 Gemini 分析\n";
echo "</button>\n";
echo "<button onclick='clearResults()' class='btn btn-secondary mb-3'>\n";
echo "<i class='fas fa-trash'></i> 清除結果\n";
echo "</button>\n";

echo "<div id='statusLog' class='bg-dark text-light p-3' style='height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px;'>\n";
echo "<div class='text-success'>✅ 系統就緒，點擊開始測試</div>\n";
echo "</div>\n";
echo "</div>\n";
echo "</div>\n";
echo "</div>\n";

// 分析結果區
echo "<div id='analysisResults' class='analysis-result' style='display: none;'>\n";
echo "<h5><i class='fas fa-chart-bar'></i> 分析結果統計</h5>\n";
echo "<div id='resultStats'></div>\n";
echo "</div>\n";

// 表格區域
echo "<div id='tableContainer' style='display: none;'>\n";
echo "<h5><i class='fas fa-table'></i> 自動填入的建築資料表格</h5>\n";
echo "<div id='buildingContainer'></div>\n";
echo "</div>\n";

// JSON 結果區
echo "<div id='jsonResults' style='display: none;'>\n";
echo "<h5><i class='fas fa-code'></i> 完整 JSON 結果</h5>\n";
echo "<pre id='jsonContent' class='bg-light p-3' style='max-height: 400px; overflow-y: auto;'></pre>\n";
echo "</div>\n";
echo "</div>\n";

echo "<script src='https://code.jquery.com/jquery-3.6.0.min.js'></script>\n";
echo "<script>\n";

// 全域變數
let floorCount = 0;
let unitCounts = {};
let roomCounts = {};

function log(message, type = 'info') {
    const statusLog = document.getElementById('statusLog');
    const timestamp = new Date().toLocaleTimeString();
    const icons = {
        'info': 'ℹ️',
        'success': '✅', 
        'error': '❌',
        'warning': '⚠️'
    };
    const colors = {
        'info': 'text-info',
        'success': 'text-success',
        'error': 'text-danger', 
        'warning': 'text-warning'
    };
    
    statusLog.innerHTML += `<div class="${colors[type]}">[${timestamp}] ${icons[type]} ${message}</div>`;
    statusLog.scrollTop = statusLog.scrollHeight;
}

function clearResults() {
    document.getElementById('statusLog').innerHTML = '<div class="text-success">✅ 結果已清除，可重新開始測試</div>';
    document.getElementById('analysisResults').style.display = 'none';
    document.getElementById('tableContainer').style.display = 'none';
    document.getElementById('jsonResults').style.display = 'none';
    document.getElementById('buildingContainer').innerHTML = '';
}

async function testGeminiAnalysis() {
    const testBtn = document.getElementById('testBtn');
    const originalText = testBtn.innerHTML;
    
    testBtn.classList.add('btn-loading');
    testBtn.disabled = true;
    
    log('🚀 開始 Gemini 平面圖分析測試', 'info');
    log('📤 準備上傳測試圖片...', 'info');

    try {
        // 模擬檔案上傳
        const formData = new FormData();
        formData.append('action', 'analyzeFloorplan');
        formData.append('building_id', '999'); // 測試用 building_id
        
        // 由於我們無法直接上傳檔案，我們調用後端直接分析現有圖片
        const response = await fetch('test_floorplan_complete.php?direct_test=1', {
            method: 'POST',
            body: formData
        });

        log('📡 收到 API 回應', 'info');
        const result = await response.json();
        
        if (result.success) {
            log('🎉 Gemini 分析成功！', 'success');
            
            // 顯示統計
            displayAnalysisStats(result);
            
            // 顯示表格
            displayTable(result.analysisResult);
            
            // 顯示 JSON
            displayJSON(result);
            
            log(`📊 識別統計: ${result.stats}`, 'success');
            
        } else {
            log(`❌ 分析失敗: ${result.error}`, 'error');
        }

    } catch (error) {
        log(`💥 測試過程發生錯誤: ${error.message}`, 'error');
    } finally {
        testBtn.classList.remove('btn-loading');
        testBtn.disabled = false;
        testBtn.innerHTML = originalText;
    }
}

function displayAnalysisStats(result) {
    const analysisResult = result.analysisResult;
    const floors = analysisResult.floors || [];
    const units = analysisResult.units || [];
    const rooms = analysisResult.rooms || [];
    const windows = analysisResult.windows || [];

    const stats = `
        <div class='row'>
            <div class='col-md-3'>
                <div class='card text-center'>
                    <div class='card-body'>
                        <h2 class='text-primary'>${floors.length}</h2>
                        <p>樓層</p>
                    </div>
                </div>
            </div>
            <div class='col-md-3'>
                <div class='card text-center'>
                    <div class='card-body'>
                        <h2 class='text-success'>${units.length}</h2>
                        <p>單元</p>
                    </div>
                </div>
            </div>
            <div class='col-md-3'>
                <div class='card text-center'>
                    <div class='card-body'>
                        <h2 class='text-warning'>${rooms.length}</h2>
                        <p>房間</p>
                    </div>
                </div>
            </div>
            <div class='col-md-3'>
                <div class='card text-center'>
                    <div class='card-body'>
                        <h2 class='text-info'>${windows.length}</h2>
                        <p>窗戶</p>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.getElementById('resultStats').innerHTML = stats;
    document.getElementById('analysisResults').style.display = 'block';
}

function displayTable(analysisResult) {
    log('🏗️ 開始填入建築資料表格...', 'info');
    populateTableWithGeminiResult(analysisResult);
    document.getElementById('tableContainer').style.display = 'block';
    log('✅ 表格填入完成', 'success');
}

function displayJSON(result) {
    document.getElementById('jsonContent').textContent = JSON.stringify(result, null, 2);
    document.getElementById('jsonResults').style.display = 'block';
}

// 複製 greenbuildingcal-new.php 中修正後的函數
function populateTableWithGeminiResult(analysisResult) {
    const buildingContainer = document.getElementById('buildingContainer');
    buildingContainer.innerHTML = ''; // 清空現有內容
    
    // 重設計數器
    floorCount = 0;
    unitCounts = {};
    roomCounts = {};
    
    console.log('收到的 Gemini 分析結果:', analysisResult);
    
    // 獲取轉換後的平級資料
    const floors = analysisResult.floors || [];
    const units = analysisResult.units || [];
    const rooms = analysisResult.rooms || [];
    const windows = analysisResult.windows || [];
    
    console.log(`轉換後資料統計: ${floors.length} 個樓層, ${units.length} 個單元, ${rooms.length} 個房間, ${windows.length} 個窗戶`);
    log(`📋 資料統計: ${floors.length} 樓層, ${units.length} 單元, ${rooms.length} 房間, ${windows.length} 窗戶`, 'info');
    
    // 如果沒有樓層，創建一個預設樓層
    const processedFloors = floors.length > 0 ? floors : [{ floor_number: 1, area: 0 }];
    
    processedFloors.forEach(floor => {
        const floorId = `floor${floor.floor_number}`;
        floorCount = Math.max(floorCount, floor.floor_number);
        
        // 創建樓層
        const floorDiv = document.createElement('div');
        floorDiv.className = 'floor';
        floorDiv.id = floorId;
        floorDiv.innerHTML = `<h3><span>樓層</span> ${floor.floor_number} (面積: ${(floor.area || 0).toFixed(2)} m²)</h3>`;
        
        // 獲取該樓層的單元（如果沒有單元，創建一個預設單元）
        const floorUnits = units.length > 0 ? units : [{ unit_number: 1, area: 0 }];
        unitCounts[floorId] = 0;
        
        floorUnits.forEach(unit => {
            const unitId = `${floorId}_unit${unit.unit_number}`;
            unitCounts[floorId] = Math.max(unitCounts[floorId], unit.unit_number);
            
            // 創建單元
            const unitDiv = document.createElement('div');
            unitDiv.className = 'unit';
            unitDiv.id = unitId;
            
            // 創建表頭
            const headerRow = document.createElement('div');
            headerRow.className = 'header-row';
            headerRow.innerHTML = `
                <div>房間編號</div>
                <div>高度</div>
                <div>長度</div>
                <div>深度</div>
                <div>牆面方位</div>
                <div>牆面積</div>
                <div>窗戶位置</div>
                <div>窗戶面積</div>
            `;
            
            const unitArea = unit.area ? ` (面積: ${unit.area.toFixed(2)} m²)` : '';
            unitDiv.innerHTML = `<h4><span>單元</span> ${unit.unit_number}${unitArea}</h4>`;
            unitDiv.appendChild(headerRow);
            
            // 將房間平均分配到各個單元
            const roomsPerUnit = Math.ceil(rooms.length / floorUnits.length);
            const startIndex = (unit.unit_number - 1) * roomsPerUnit;
            const endIndex = Math.min(startIndex + roomsPerUnit, rooms.length);
            const unitRooms = rooms.slice(startIndex, endIndex);
            
            roomCounts[unitId] = 0;
            
            // 如果該單元沒有房間，創建一個預設房間
            const processedRooms = unitRooms.length > 0 ? unitRooms : [{
                room_number: 1,
                name: 'Room 1',
                type: 'unknown',
                area: 0,
                length: 0,
                width: 0,
                height: 3.0,
                walls: []
            }];
            
            processedRooms.forEach((room, roomIndex) => {
                const roomDiv = document.createElement('div');
                roomDiv.className = 'room-row';
                const roomNumber = room.room_number || (roomIndex + 1);
                roomDiv.id = `${unitId}_room${roomNumber}`;
                roomCounts[unitId] = Math.max(roomCounts[unitId], roomNumber);
                
                // 處理 Gemini 的詳細房間資訊
                const roomName = room.name || `${room.type || 'Room'} ${roomNumber}`;
                const roomArea = room.area || 0;
                const roomLength = room.length || 0;
                const roomWidth = room.width || 0;
                const roomHeight = room.height || 3.0;
                
                // 處理牆面資訊
                let wallOrientations = [];
                let totalWallArea = 0;
                let windowPositions = [];
                let totalWindowArea = 0;
                
                if (room.walls && room.walls.length > 0) {
                    room.walls.forEach(wall => {
                        if (wall.orientation) {
                            wallOrientations.push(wall.orientation);
                        }
                        if (wall.area) {
                            totalWallArea += wall.area;
                        }
                        
                        // 處理該牆面的窗戶
                        if (wall.windows && wall.windows.length > 0) {
                            wall.windows.forEach(window => {
                                if (window.orientation) {
                                    windowPositions.push(window.orientation);
                                }
                                if (window.area) {
                                    totalWindowArea += window.area;
                                }
                            });
                        }
                    });
                }
                
                // 如果沒有牆面資訊，使用轉換後的外部窗戶資料
                if (totalWindowArea === 0 && windows.length > 0) {
                    const roomWindows = windows.filter(w => 
                        w.room_id === roomNumber || w.room_id === room.room_number
                    );
                    roomWindows.forEach(window => {
                        if (window.orientation) {
                            windowPositions.push(window.orientation);
                        }
                        totalWindowArea += window.area || 0;
                    });
                }
                
                // 如果沒有牆面積，估算一個
                if (totalWallArea === 0 && roomLength > 0 && roomWidth > 0 && roomHeight > 0) {
                    totalWallArea = 2 * (roomLength + roomWidth) * roomHeight;
                }
                
                const wallOrientationStr = [...new Set(wallOrientations)].join(',');
                const windowPositionStr = [...new Set(windowPositions)].join(',');
                
                roomDiv.innerHTML = `
                    <input type="text" value="${roomName}" placeholder="房間編號" title="房間類型: ${room.type || '未知'}">
                    <input type="text" value="${roomHeight.toFixed(2)}" placeholder="高度">
                    <input type="text" value="${roomLength.toFixed(2)}" placeholder="長度">
                    <input type="text" value="${roomWidth.toFixed(2)}" placeholder="深度">
                    <input type="text" value="${wallOrientationStr}" placeholder="牆面方位">
                    <input type="text" value="${totalWallArea.toFixed(2)}" placeholder="牆面積">
                    <input type="text" value="${windowPositionStr}" placeholder="窗戶位置">
                    <input type="text" value="${totalWindowArea.toFixed(2)}" placeholder="窗戶面積">
                `;
                
                unitDiv.appendChild(roomDiv);
                log(`➕ 添加房間: ${roomName}`, 'info');
            });
            
            floorDiv.appendChild(unitDiv);
        });
        
        buildingContainer.appendChild(floorDiv);
    });
    
    // 顯示房間詳細清單到控制台
    if (rooms.length > 0) {
        log('📋 房間清單:', 'info');
        rooms.forEach((room, index) => {
            log(`  ${index + 1}. ${room.name || 'Room ' + (index + 1)} (${room.type || '未知'}) - ${room.area || 0} m²`, 'info');
        });
    }
}
echo "</script>\n";
echo "</body>\n</html>\n";
?>

<?php
// 後端直接測試邏輯
if (isset($_GET['direct_test']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'floorplan_upload.php';
    
    header('Content-Type: application/json');
    
    try {
        $imagePath = 'uploads/floorplans/floorplan_3358_1750861587.jpg';
        
        if (!file_exists($imagePath)) {
            echo json_encode([
                'success' => false,
                'error' => '測試圖片不存在: ' . $imagePath
            ]);
            exit;
        }
        
        // 創建 FloorplanUploader 實例並直接分析圖片
        $uploader = new FloorplanUploader();
        
        // 使用反射調用私有方法進行測試
        $reflection = new ReflectionClass($uploader);
        $method = $reflection->getMethod('analyzeFloorplanWithGemini');
        $method->setAccessible(true);
        
        $analysisResult = $method->invoke($uploader, $imagePath);
        
        if ($analysisResult['success']) {
            $floors = $analysisResult['floors'] ?? [];
            $units = $analysisResult['units'] ?? [];
            $rooms = $analysisResult['rooms'] ?? [];
            $windows = $analysisResult['windows'] ?? [];
            
            $stats = "{$floors} 樓層, {$units} 單元, {$rooms} 房間, {$windows} 窗戶";
            
            echo json_encode([
                'success' => true,
                'analysisResult' => $analysisResult,
                'stats' => $stats,
                'message' => 'Gemini 分析完成'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $analysisResult['error'] ?? '分析失敗'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => '測試過程中發生錯誤: ' . $e->getMessage()
        ]);
    }
    
    exit;
}
?> 