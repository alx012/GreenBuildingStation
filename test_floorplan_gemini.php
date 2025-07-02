<?php
/**
 * Gemini AI 平面圖分析測試腳本
 * 專門用於測試 floorplan_3358_1750861587.jpg 的分析結果
 */

header('Content-Type: text/html; charset=utf-8');
require_once 'floorplan_upload.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>🤖 Gemini 平面圖分析測試</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .status { padding: 15px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #cce7ff; color: #004085; border: 1px solid #b8d4fd; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        .stats-table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        .stats-table th, .stats-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .stats-table th { background-color: #f2f2f2; }
        .room-details { background: #f8f9fa; padding: 10px; margin: 5px 0; border-radius: 4px; }
        .image-preview { max-width: 300px; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🤖 Gemini AI 平面圖分析測試</h1>
        <h2>測試檔案: floorplan_3358_1750861587.jpg</h2>";

$imagePath = 'uploads/floorplans/floorplan_3358_1750861587.jpg';

// 顯示圖片預覽
if (file_exists($imagePath)) {
    echo "<div class='status info'>
            <h3>📷 測試圖片預覽</h3>
            <img src='{$imagePath}' alt='測試平面圖' class='image-preview'>
            <p><strong>檔案大小:</strong> " . number_format(filesize($imagePath) / 1024, 2) . " KB</p>
          </div>";
} else {
    echo "<div class='status error'>
            <h3>❌ 錯誤</h3>
            <p>測試圖片檔案不存在: {$imagePath}</p>
          </div>";
    exit;
}

// 檢查 API Key 配置
$uploader = new FloorplanUploader();

// 使用反射來訪問私有方法進行測試
$reflection = new ReflectionClass($uploader);
$getApiKeyMethod = $reflection->getMethod('getGeminiApiKey');
$getApiKeyMethod->setAccessible(true);
$apiKey = $getApiKeyMethod->invoke($uploader);

if (empty($apiKey) || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
    echo "<div class='status error'>
            <h3>❌ API Key 未設置</h3>
            <p>請先設置 Gemini API Key 才能進行測試。</p>
            <p>設置方式請參考 GEMINI_SETUP.md</p>
          </div>";
    exit;
}

echo "<div class='status success'>
        <h3>✅ API Key 已設置</h3>
        <p>開始分析平面圖...</p>
      </div>";

// 進行分析
try {
    // 模擬檔案上傳數據結構
    $fileData = [
        'name' => 'floorplan_3358_1750861587.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $imagePath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($imagePath)
    ];
    
    echo "<div class='status info'>
            <h3>🔄 正在調用 Gemini API 分析...</h3>
            <p>這可能需要 10-30 秒，請耐心等待...</p>
          </div>";
    
    // 刷新輸出以顯示進度
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
    
    $startTime = microtime(true);
    
    // 直接使用 FloorplanUploader 的 analyzeFloorplanWithGemini 方法
    $analyzeMethod = $reflection->getMethod('analyzeFloorplanWithGemini');
    $analyzeMethod->setAccessible(true);
    $result = $analyzeMethod->invoke($uploader, $imagePath);
    
    $endTime = microtime(true);
    $analysisTime = round($endTime - $startTime, 2);
    
    echo "<div class='status info'>
            <h3>⏱️ 分析完成</h3>
            <p>分析時間: {$analysisTime} 秒</p>
          </div>";
    
    if ($result['success']) {
        $analysisResult = $result;
        
        // 統計資訊
        $floors = $analysisResult['floors'] ?? [];
        $units = $analysisResult['units'] ?? [];
        $rooms = $analysisResult['rooms'] ?? [];
        $windows = $analysisResult['windows'] ?? [];
        
        echo "<div class='status success'>
                <h3>🎉 分析成功！</h3>
                <table class='stats-table'>
                    <tr><th>項目</th><th>數量</th><th>詳情</th></tr>
                    <tr><td>樓層</td><td><strong>{count($floors)}</strong></td><td>總面積: " . 
                        round(array_sum(array_column($floors, 'area')), 2) . " m²</td></tr>
                    <tr><td>單元</td><td><strong>{count($units)}</strong></td><td>總面積: " . 
                        round(array_sum(array_column($units, 'area')), 2) . " m²</td></tr>
                    <tr><td>房間</td><td><strong>{count($rooms)}</strong></td><td>平均面積: " . 
                        (count($rooms) > 0 ? round(array_sum(array_column($rooms, 'area')) / count($rooms), 2) : 0) . " m²</td></tr>
                    <tr><td>窗戶</td><td><strong>{count($windows)}</strong></td><td>總面積: " . 
                        round(array_sum(array_column($windows, 'area')), 2) . " m²</td></tr>
                </table>
              </div>";
        
        // 詳細房間資訊
        if (!empty($rooms)) {
            echo "<div class='status info'>
                    <h3>🏠 房間詳細資訊</h3>";
            
            foreach ($rooms as $index => $room) {
                $roomName = $room['name'] ?? "房間 " . ($index + 1);
                $roomType = $room['type'] ?? '未知';
                $roomArea = $room['area'] ?? 0;
                $roomLength = $room['length'] ?? 0;
                $roomWidth = $room['width'] ?? 0;
                $roomHeight = $room['height'] ?? 3.0;
                
                // 計算牆面和窗戶資訊
                $wallInfo = '';
                $windowInfo = '';
                
                if (isset($room['walls']) && !empty($room['walls'])) {
                    $wallOrientations = [];
                    $windowPositions = [];
                    
                    foreach ($room['walls'] as $wall) {
                        if (isset($wall['orientation'])) {
                            $wallOrientations[] = $wall['orientation'];
                        }
                        
                        if (isset($wall['windows']) && !empty($wall['windows'])) {
                            foreach ($wall['windows'] as $window) {
                                if (isset($window['orientation'])) {
                                    $windowPositions[] = $window['orientation'];
                                }
                            }
                        }
                    }
                    
                    $wallInfo = implode(', ', array_unique($wallOrientations));
                    $windowInfo = implode(', ', array_unique($windowPositions));
                }
                
                echo "<div class='room-details'>
                        <h4>{$roomName} ({$roomType})</h4>
                        <p><strong>面積:</strong> {$roomArea} m² | 
                           <strong>尺寸:</strong> {$roomLength} × {$roomWidth} × {$roomHeight} m</p>";
                
                if ($wallInfo) {
                    echo "<p><strong>牆面方位:</strong> {$wallInfo}</p>";
                }
                
                if ($windowInfo) {
                    echo "<p><strong>窗戶位置:</strong> {$windowInfo}</p>";
                }
                
                echo "</div>";
            }
            
            echo "</div>";
        }
        
        // 檢查房間數量是否符合預期
        $expectedRooms = 11; // 根據之前的對話，應該識別出11個房間
        $actualRooms = count($rooms);
        
        if ($actualRooms == $expectedRooms) {
            echo "<div class='status success'>
                    <h3>✅ 房間數量測試通過！</h3>
                    <p>預期房間數: <strong>{$expectedRooms}</strong></p>
                    <p>實際識別數: <strong>{$actualRooms}</strong></p>
                    <p>🎯 Gemini AI 成功識別出正確的房間數量！</p>
                  </div>";
        } else {
            echo "<div class='status warning'>
                    <h3>⚠️ 房間數量與預期不符</h3>
                    <p>預期房間數: <strong>{$expectedRooms}</strong></p>
                    <p>實際識別數: <strong>{$actualRooms}</strong></p>
                    <p>差異: " . ($actualRooms - $expectedRooms > 0 ? '+' : '') . ($actualRooms - $expectedRooms) . "</p>
                    <p>💡 這可能是因為 AI 的識別標準不同，您可以手動調整結果。</p>
                  </div>";
        }
        
        // 顯示完整的原始分析結果
        echo "<div class='status info'>
                <h3>📋 完整分析結果 (JSON)</h3>
                <pre>" . json_encode($analysisResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>
              </div>";
        
    } else {
        echo "<div class='status error'>
                <h3>❌ 分析失敗</h3>
                <p>錯誤訊息: " . ($result['error'] ?? '未知錯誤') . "</p>
              </div>";
    }
    
} catch (Exception $e) {
    echo "<div class='status error'>
            <h3>❌ 測試過程中發生錯誤</h3>
            <p>錯誤訊息: " . $e->getMessage() . "</p>
            <p>檔案: " . $e->getFile() . "</p>
            <p>行號: " . $e->getLine() . "</p>
          </div>";
}

echo "
    <div class='status info'>
        <h3>🔧 測試說明</h3>
        <p>此測試直接調用 Gemini API 分析指定的平面圖檔案。</p>
        <p>如果結果不符合預期，可能的原因包括：</p>
        <ul>
            <li>圖片品質或解析度問題</li>
            <li>房間標示不夠清楚</li>
            <li>AI 識別標準與人工判斷不同</li>
            <li>複雜的建築結構難以自動識別</li>
        </ul>
        <p>💡 <strong>建議：</strong> 將 AI 分析結果作為起點，然後手動調整以獲得最佳效果。</p>
    </div>
</div>
</body>
</html>";
?> 