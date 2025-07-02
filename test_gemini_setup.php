<?php
/**
 * Gemini AI 平面圖分析系統設置測試
 * 
 * 此文件用於驗證 Gemini API 是否正確配置
 * 運行方式：在瀏覽器中訪問此文件
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>🤖 Gemini AI 設置測試</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #cce7ff; color: #004085; border: 1px solid #b8d4fd; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
        .test-item { margin: 15px 0; padding: 10px; border-left: 4px solid #007bff; background: #f8f9fa; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🤖 Gemini AI 平面圖分析系統測試</h1>
        <p>檢查系統配置和相依性...</p>";

// 測試項目
$tests = [];

// 1. 檢查 PHP 版本
$tests[] = [
    'name' => 'PHP 版本',
    'result' => version_compare(PHP_VERSION, '7.4.0') >= 0,
    'message' => 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '7.4.0') >= 0 ? ' (✓ 支援)' : ' (✗ 需要 7.4+)'),
    'required' => true
];

// 2. 檢查必要的 PHP 擴展
$extensions = ['curl', 'json', 'fileinfo'];
foreach ($extensions as $ext) {
    $tests[] = [
        'name' => "PHP 擴展: {$ext}",
        'result' => extension_loaded($ext),
        'message' => extension_loaded($ext) ? '已安裝' : '未安裝',
        'required' => true
    ];
}

// 3. 檢查目錄權限
$directories = [
    'uploads/floorplans/' => '上傳目錄',
    'config/' => '配置目錄'
];

foreach ($directories as $dir => $desc) {
    $exists = is_dir($dir);
    $writable = $exists && is_writable($dir);
    
    $tests[] = [
        'name' => $desc,
        'result' => $exists && $writable,
        'message' => $exists ? 
            ($writable ? '可寫入' : '無寫入權限') : 
            '目錄不存在',
        'required' => true
    ];
}

// 4. 檢查 Gemini API Key 配置
$apiKeyConfigured = false;
$configMethod = '';

// 方法 A: 環境變數
$envApiKey = getenv('GEMINI_API_KEY');
if (!empty($envApiKey) && $envApiKey !== 'YOUR_GEMINI_API_KEY_HERE') {
    $apiKeyConfigured = true;
    $configMethod = '環境變數';
}

// 方法 B: 配置文件
if (!$apiKeyConfigured && file_exists('config/gemini_config.php')) {
    include 'config/gemini_config.php';
    if (isset($GEMINI_API_KEY) && !empty($GEMINI_API_KEY) && $GEMINI_API_KEY !== 'YOUR_ACTUAL_GEMINI_API_KEY_HERE') {
        $apiKeyConfigured = true;
        $configMethod = '配置文件';
    }
}

$tests[] = [
    'name' => 'Gemini API Key',
    'result' => $apiKeyConfigured,
    'message' => $apiKeyConfigured ? 
        "已設置 (通過{$configMethod})" : 
        '未設置或使用預設值',
    'required' => true
];

// 5. 檢查必要文件
$files = [
    'floorplan_upload.php' => 'Gemini 上傳處理器',
    'greenbuildingcal-new.php' => '主要計算頁面',
    'GEMINI_SETUP.md' => '設置說明文件'
];

foreach ($files as $file => $desc) {
    $tests[] = [
        'name' => $desc,
        'result' => file_exists($file),
        'message' => file_exists($file) ? '存在' : '缺失',
        'required' => true
    ];
}

// 顯示測試結果
$allPassed = true;
foreach ($tests as $test) {
    if ($test['required'] && !$test['result']) {
        $allPassed = false;
    }
    
    $statusClass = $test['result'] ? 'success' : ($test['required'] ? 'error' : 'warning');
    $icon = $test['result'] ? '✅' : ($test['required'] ? '❌' : '⚠️');
    
    echo "<div class='test-item'>
            <div class='status {$statusClass}'>
                {$icon} <strong>{$test['name']}</strong>: {$test['message']}
            </div>
          </div>";
}

// 總結
echo "<div class='test-item'>";
if ($allPassed) {
    echo "<div class='status success'>
            <h3>🎉 所有測試通過！</h3>
            <p>您的 Gemini AI 平面圖分析系統已正確設置。</p>
            <p><strong>下一步：</strong></p>
            <ul>
                <li>前往綠建築計算頁面</li>
                <li>選擇「平面圖上傳」方式</li>
                <li>上傳一張建築平面圖進行測試</li>
            </ul>
          </div>";
} else {
    echo "<div class='status error'>
            <h3>❌ 設置不完整</h3>
            <p>請修復上述錯誤後重新測試。</p>
            <p><strong>常見解決方案：</strong></p>
            <ul>
                <li>設置 GEMINI_API_KEY 環境變數</li>
                <li>或複製 config/gemini_config.php.example 為 config/gemini_config.php 並填入API Key</li>
                <li>確保目錄權限正確: chmod 755 uploads/floorplans config/</li>
            </ul>
          </div>";
}
echo "</div>";

// 顯示配置檔案建立指令
if (!$apiKeyConfigured) {
    echo "<div class='test-item'>
            <div class='status info'>
                <h4>🔧 快速設置指令</h4>
                <p>在終端中執行以下指令來設置 API Key：</p>
                <pre># 方法 1: 複製配置模板
cp config/gemini_config.php.example config/gemini_config.php
# 然後編輯 config/gemini_config.php 文件，填入您的 API Key

# 方法 2: 設置環境變數
export GEMINI_API_KEY=\"your_actual_api_key_here\"</pre>
                <p>📋 <strong>獲取 API Key：</strong> <a href='https://ai.google.dev/api_key' target='_blank'>Google AI Studio</a></p>
            </div>
          </div>";
}

echo "
        <div class='test-item'>
            <div class='status info'>
                <h4>📚 更多資訊</h4>
                <p>詳細設置說明請參考 <strong>GEMINI_SETUP.md</strong> 文件。</p>
                <p>如需技術支援，請檢查瀏覽器開發者工具中的 Console 標籤。</p>
            </div>
        </div>
    </div>
</body>
</html>";
?> 