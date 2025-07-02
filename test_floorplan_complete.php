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
echo "<html>\n<head>\n";
echo "<title>平面圖上傳測試</title>\n";
echo "<meta charset='UTF-8'>\n";
echo "</head>\n<body>\n";

echo "<h1>平面圖上傳和分析測試</h1>\n";

// 測試上傳處理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['floorplanFile'])) {
    echo "<h2>處理上傳檔案</h2>\n";
    
    $building_id = 999; // 測試專案ID
    
    try {
        $uploader = new FloorplanUploader();
        $result = $uploader->handleUpload($_FILES['floorplanFile'], $building_id);
        
        echo "<h3>分析結果：</h3>\n";
        echo "<pre>" . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>\n";
        
        if ($result['success'] && isset($result['analysisResult'])) {
            $analysis = $result['analysisResult'];
            echo "<h3>識別統計：</h3>\n";
            echo "<ul>\n";
            echo "<li>樓層數: " . count($analysis['floors']) . "</li>\n";
            echo "<li>單元數: " . count($analysis['units']) . "</li>\n";
            echo "<li>房間數: " . count($analysis['rooms']) . "</li>\n";
            echo "<li>窗戶數: " . count($analysis['windows']) . "</li>\n";
            echo "</ul>\n";
            
            if (isset($analysis['statistics'])) {
                echo "<h3>分析統計：</h3>\n";
                echo "<ul>\n";
                foreach ($analysis['statistics'] as $key => $value) {
                    echo "<li>{$key}: {$value}</li>\n";
                }
                echo "</ul>\n";
            }
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>錯誤: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
}

// 顯示上傳表單
echo "<h2>上傳平面圖檔案</h2>\n";
echo "<form method='POST' enctype='multipart/form-data'>\n";
echo "<p>\n";
echo "<label for='floorplanFile'>選擇平面圖檔案：</label><br>\n";
echo "<input type='file' id='floorplanFile' name='floorplanFile' accept='image/*' required>\n";
echo "</p>\n";
echo "<p>\n";
echo "<button type='submit'>上傳並分析</button>\n";
echo "</p>\n";
echo "</form>\n";

// 顯示現有測試檔案
echo "<h2>可用的測試檔案</h2>\n";
$testFiles = glob('uploads/floorplans/*.{jpg,jpeg,png,gif}', GLOB_BRACE);
if (!empty($testFiles)) {
    echo "<ul>\n";
    foreach ($testFiles as $file) {
        $fileName = basename($file);
        echo "<li><a href='#{$fileName}' onclick=\"document.getElementById('floorplanFile').files = null; alert('請手動選擇檔案: {$fileName}');\">{$fileName}</a></li>\n";
    }
    echo "</ul>\n";
} else {
    echo "<p>沒有找到測試檔案</p>\n";
}

// 環境檢查
echo "<h2>環境檢查</h2>\n";
echo "<h3>PHP環境</h3>\n";
echo "<ul>\n";
echo "<li>PHP版本: " . phpversion() . "</li>\n";
echo "<li>上傳檔案最大大小: " . ini_get('upload_max_filesize') . "</li>\n";
echo "<li>POST數據最大大小: " . ini_get('post_max_size') . "</li>\n";
echo "<li>記憶體限制: " . ini_get('memory_limit') . "</li>\n";
echo "</ul>\n";

echo "<h3>Python環境</h3>\n";
echo "<ul>\n";

// 檢查Python
$pythonVersion = shell_exec('python3 --version 2>&1');
echo "<li>Python版本: " . htmlspecialchars(trim($pythonVersion)) . "</li>\n";

// 檢查虛擬環境
$venvPath = realpath(dirname(__FILE__) . '/venv/bin/activate');
if (file_exists($venvPath)) {
    echo "<li>虛擬環境: ✓ 存在於 " . htmlspecialchars($venvPath) . "</li>\n";
} else {
    echo "<li>虛擬環境: ✗ 不存在</li>\n";
}

// 檢查OpenCV
$opencvCheck = shell_exec('cd ' . escapeshellarg(dirname(__FILE__)) . ' && source venv/bin/activate && python3 -c "import cv2; print(cv2.__version__)" 2>&1');
if (strpos($opencvCheck, 'ModuleNotFoundError') === false && !empty(trim($opencvCheck))) {
    echo "<li>OpenCV: ✓ " . htmlspecialchars(trim($opencvCheck)) . "</li>\n";
} else {
    echo "<li>OpenCV: ✗ 未安裝或無法載入</li>\n";
}

echo "</ul>\n";

// 檢查Python腳本
echo "<h3>Python腳本</h3>\n";
$scriptPath = dirname(__FILE__) . '/floorplan_analysis.py';
if (file_exists($scriptPath)) {
    echo "<p>✓ 分析腳本存在: " . htmlspecialchars($scriptPath) . "</p>\n";
    
    // 測試腳本幫助
    $helpOutput = shell_exec('cd ' . escapeshellarg(dirname(__FILE__)) . ' && source venv/bin/activate && python3 floorplan_analysis.py --help 2>&1');
    if (!empty($helpOutput)) {
        echo "<h4>腳本使用說明：</h4>\n";
        echo "<pre>" . htmlspecialchars($helpOutput) . "</pre>\n";
    }
} else {
    echo "<p>✗ 分析腳本不存在</p>\n";
}

echo "</body>\n</html>\n";
?> 