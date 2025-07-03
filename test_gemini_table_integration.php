<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🧪 Gemini 表格填入測試</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background: #f5f5f5; 
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .test-section { 
            margin: 20px 0; 
            padding: 20px; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
        }
        .test-button { 
            background: #007bff; 
            color: white; 
            border: none; 
            padding: 12px 24px; 
            border-radius: 4px; 
            cursor: pointer; 
            margin: 10px 5px; 
        }
        .test-button:hover { 
            background: #0056b3; 
        }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .info { color: #17a2b8; }
        
        /* 表格樣式 - 複製自 greenbuildingcal-new.php */
        .floor, .unit, .room {
            border: 1px solid #000;
            margin: 10px 0;
            padding: 10px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
        }
        .floor:nth-child(odd) {
            background-color: rgba(191, 202, 194, 0.7);
        }
        .floor:nth-child(even) {
            background-color: rgba(235, 232, 227, 0.7);
        }
        .header-row {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 8px;
            padding: 10px;
            font-weight: bold;
            border-bottom: 2px solid #ddd;
            font-size: 14px;
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
            gap: 8px;
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            align-items: center;
        }
        .room-row input {
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        .log-area {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            max-height: 300px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 12px;
        }
        .json-display {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Gemini 分析結果表格填入測試</h1>
        
        <div class="test-section">
            <h3>🎯 測試目標</h3>
            <p>驗證 Gemini API 分析結果是否能正確處理和填入綠建築計算表格，包括：</p>
            <ul>
                <li>✅ 資料格式轉換（嵌套結構 → 平級結構）</li>
                <li>✅ populateTableWithGeminiResult() 函數正確性</li>
                <li>✅ 表格自動填入和顯示</li>
                <li>✅ 房間、牆面、窗戶資訊處理</li>
            </ul>
        </div>

        <div class="test-section">
            <h3>🚀 測試控制台</h3>
            <button class="test-button" onclick="testRealGeminiAPI()">🤖 測試真實 Gemini API</button>
            <button class="test-button" onclick="testMockData()">📝 測試模擬資料</button>
            <button class="test-button" onclick="clearAll()">🗑️ 清除結果</button>
            
            <div id="testLog" class="log-area">
                <div class="info">📋 測試日誌區域，點擊上方按鈕開始測試</div>
            </div>
        </div>

        <div id="resultSection" class="test-section" style="display: none;">
            <h3>📊 分析結果統計</h3>
            <div id="resultStats"></div>
        </div>

        <div id="tableSection" class="test-section" style="display: none;">
            <h3>🏗️ 自動填入的建築資料表格</h3>
            <div id="buildingContainer"></div>
        </div>

        <div id="jsonSection" class="test-section" style="display: none;">
            <h3>📄 完整 JSON 資料</h3>
            <div id="jsonDisplay" class="json-display"></div>
        </div>
    </div>

    <script>
        // 全域變數（複製自 greenbuildingcal-new.php）
        let floorCount = 0;
        let unitCounts = {};
        let roomCounts = {};

        // 日誌函數
        function log(message, type = 'info') {
            const logArea = document.getElementById('testLog');
            const timestamp = new Date().toLocaleTimeString();
            const colors = {
                'info': '#17a2b8',
                'success': '#28a745',
                'error': '#dc3545',
                'warning': '#ffc107'
            };
            const color = colors[type] || '#333';
            
            logArea.innerHTML += `<div style="color: ${color}; margin: 5px 0;">[${timestamp}] ${message}</div>`;
            logArea.scrollTop = logArea.scrollHeight;
        }

        // 清除所有結果
        function clearAll() {
            document.getElementById('testLog').innerHTML = '<div class="info">📋 結果已清除，可重新開始測試</div>';
            document.getElementById('resultSection').style.display = 'none';
            document.getElementById('tableSection').style.display = 'none';
            document.getElementById('jsonSection').style.display = 'none';
            document.getElementById('buildingContainer').innerHTML = '';
        }

        // 測試真實 Gemini API
        async function testRealGeminiAPI() {
            log('🚀 開始測試真實 Gemini API...', 'info');
            
            try {
                const response = await fetch('test_gemini_table_integration.php?api_test=1', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ test_type: 'real_api' })
                });

                const result = await response.json();
                
                if (result.success) {
                    log('✅ Gemini API 調用成功!', 'success');
                    processTestResult(result);
                } else {
                    log('❌ Gemini API 測試失敗: ' + result.error, 'error');
                }
            } catch (error) {
                log('💥 API 測試過程發生錯誤: ' + error.message, 'error');
            }
        }

        // 測試模擬資料
        function testMockData() {
            log('📝 開始測試模擬資料...', 'info');
            
            // 創建模擬的 Gemini 分析結果（平級結構）
            const mockAnalysisResult = {
                success: true,
                floors: [
                    { floor_number: 1, area: 100.0 }
                ],
                units: [
                    { unit_number: 1, area: 100.0 }
                ],
                rooms: [
                    {
                        room_number: 1,
                        name: "客廳",
                        type: "living_room",
                        area: 20.5,
                        length: 5.0,
                        width: 4.1,
                        height: 3.0,
                        walls: [
                            {
                                wall_id: 1,
                                orientation: "南",
                                length: 5.0,
                                area: 15.0,
                                windows: [
                                    {
                                        window_id: 1,
                                        orientation: "南",
                                        width: 1.5,
                                        height: 1.2,
                                        area: 1.8
                                    }
                                ]
                            },
                            {
                                wall_id: 2,
                                orientation: "北",
                                length: 5.0,
                                area: 15.0,
                                windows: []
                            }
                        ]
                    },
                    {
                        room_number: 2,
                        name: "主臥",
                        type: "master_bedroom", 
                        area: 16.0,
                        length: 4.0,
                        width: 4.0,
                        height: 3.0,
                        walls: [
                            {
                                wall_id: 3,
                                orientation: "東",
                                length: 4.0,
                                area: 12.0,
                                windows: [
                                    {
                                        window_id: 2,
                                        orientation: "東",
                                        width: 1.2,
                                        height: 1.0,
                                        area: 1.2
                                    }
                                ]
                            }
                        ]
                    },
                    {
                        room_number: 3,
                        name: "廚房",
                        type: "kitchen",
                        area: 8.0,
                        length: 4.0,
                        width: 2.0,
                        height: 3.0,
                        walls: []
                    }
                ],
                windows: [
                    {
                        window_id: 1,
                        room_id: 1,
                        orientation: "南",
                        width: 1.5,
                        height: 1.2,
                        area: 1.8
                    },
                    {
                        window_id: 2,
                        room_id: 2,
                        orientation: "東",
                        width: 1.2,
                        height: 1.0,
                        area: 1.2
                    }
                ]
            };

            const result = {
                success: true,
                analysisResult: mockAnalysisResult,
                message: '模擬資料測試'
            };

            log('✅ 模擬資料生成成功!', 'success');
            processTestResult(result);
        }

        // 處理測試結果
        function processTestResult(result) {
            const analysisResult = result.analysisResult;
            
            // 顯示統計
            displayStats(analysisResult);
            
            // 測試表格填入
            log('🏗️ 開始測試表格填入功能...', 'info');
            try {
                populateTableWithGeminiResult(analysisResult);
                log('✅ 表格填入測試成功!', 'success');
                document.getElementById('tableSection').style.display = 'block';
            } catch (error) {
                log('❌ 表格填入測試失敗: ' + error.message, 'error');
            }
            
            // 顯示 JSON
            displayJSON(result);
            
            log('🎉 測試完成!', 'success');
        }

        // 顯示統計資訊
        function displayStats(analysisResult) {
            const floors = analysisResult.floors || [];
            const units = analysisResult.units || [];
            const rooms = analysisResult.rooms || [];
            const windows = analysisResult.windows || [];

            const statsHTML = `
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; text-align: center;">
                    <div style="background: #e3f2fd; padding: 15px; border-radius: 8px;">
                        <h2 style="color: #1976d2; margin: 0;">${floors.length}</h2>
                        <p style="margin: 5px 0;">樓層</p>
                    </div>
                    <div style="background: #e8f5e8; padding: 15px; border-radius: 8px;">
                        <h2 style="color: #388e3c; margin: 0;">${units.length}</h2>
                        <p style="margin: 5px 0;">單元</p>
                    </div>
                    <div style="background: #fff3e0; padding: 15px; border-radius: 8px;">
                        <h2 style="color: #f57c00; margin: 0;">${rooms.length}</h2>
                        <p style="margin: 5px 0;">房間</p>
                    </div>
                    <div style="background: #fce4ec; padding: 15px; border-radius: 8px;">
                        <h2 style="color: #c2185b; margin: 0;">${windows.length}</h2>
                        <p style="margin: 5px 0;">窗戶</p>
                    </div>
                </div>
                <div style="margin-top: 15px;">
                    <h5>房間詳細清單:</h5>
                    <ul style="margin: 10px 0;">
                        ${rooms.map((room, index) => 
                            `<li>${index + 1}. ${room.name || 'Room ' + (index + 1)} (${room.type || '未知'}) - ${room.area || 0} m²</li>`
                        ).join('')}
                    </ul>
                </div>
            `;

            document.getElementById('resultStats').innerHTML = statsHTML;
            document.getElementById('resultSection').style.display = 'block';
            
            log(`📊 統計完成: ${floors.length} 樓層, ${units.length} 單元, ${rooms.length} 房間, ${windows.length} 窗戶`, 'info');
        }

        // 顯示 JSON
        function displayJSON(result) {
            document.getElementById('jsonDisplay').textContent = JSON.stringify(result, null, 2);
            document.getElementById('jsonSection').style.display = 'block';
        }

        // 複製並修正後的 populateTableWithGeminiResult 函數
        function populateTableWithGeminiResult(analysisResult) {
            const buildingContainer = document.getElementById('buildingContainer');
            buildingContainer.innerHTML = ''; // 清空現有內容
            
            // 重設計數器
            floorCount = 0;
            unitCounts = {};
            roomCounts = {};
            
            log('📋 開始處理 Gemini 分析結果...', 'info');
            
            // 獲取轉換後的平級資料
            const floors = analysisResult.floors || [];
            const units = analysisResult.units || [];
            const rooms = analysisResult.rooms || [];
            const windows = analysisResult.windows || [];
            
            log(`📋 資料統計: ${floors.length} 個樓層, ${units.length} 個單元, ${rooms.length} 個房間, ${windows.length} 個窗戶`, 'info');
            
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
                        log(`➕ 添加房間: ${roomName} (${room.type})`, 'info');
                    });
                    
                    floorDiv.appendChild(unitDiv);
                });
                
                buildingContainer.appendChild(floorDiv);
            });
            
            log('✅ 表格建構完成', 'success');
        }

        // 頁面載入時顯示歡迎訊息
        window.onload = function() {
            log('🎉 Gemini 表格填入測試系統已就緒', 'success');
            log('👆 點擊上方按鈕開始測試...', 'info');
        };
    </script>
</body>
</html>

<?php
// 後端 API 測試處理
if (isset($_GET['api_test']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'floorplan_upload.php';
    
    header('Content-Type: application/json');
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($input['test_type'] === 'real_api') {
            // 測試真實 Gemini API
            $imagePath = 'uploads/floorplans/floorplan_3358_1750861587.jpg';
            
            if (!file_exists($imagePath)) {
                echo json_encode([
                    'success' => false,
                    'error' => '測試圖片不存在: ' . $imagePath
                ]);
                exit;
            }
            
            // 創建 FloorplanUploader 實例
            $uploader = new FloorplanUploader();
            
            // 使用反射調用私有方法進行測試
            $reflection = new ReflectionClass($uploader);
            $method = $reflection->getMethod('analyzeFloorplanWithGemini');
            $method->setAccessible(true);
            
            $analysisResult = $method->invoke($uploader, $imagePath);
            
            if ($analysisResult['success']) {
                echo json_encode([
                    'success' => true,
                    'analysisResult' => $analysisResult,
                    'message' => '真實 Gemini API 測試成功'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $analysisResult['error'] ?? '分析失敗'
                ]);
            }
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