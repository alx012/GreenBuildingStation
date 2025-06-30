<?php
require_once 'floorplan_upload.php';
require_once 'db_connection.php';

// 確保session已啟動
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 設置回應頭
header('Content-Type: application/json');

// 檢查是否為 POST 請求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '無效的請求方法']);
    exit;
}

// 檢查用戶是否登入
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false, 
        'error' => '請先登入帳號以使用該功能',
        'redirect' => 'login.php'
    ]);
    exit;
}

// 檢查動作
if (!isset($_POST['action']) || $_POST['action'] !== 'analyzeFloorplan') {
    echo json_encode(['success' => false, 'error' => '無效的動作']);
    exit;
}

// 檢查必要參數
if (!isset($_POST['building_id']) || !isset($_FILES['floorplanFile'])) {
    echo json_encode(['success' => false, 'error' => '缺少必要參數']);
    exit;
}

$building_id = intval($_POST['building_id']);
$scale = isset($_POST['scale']) ? floatval($_POST['scale']) : 0.01;

try {
    // 創建平面圖上傳器實例
    $uploader = new FloorplanUploader();
    
    // 處理檔案上傳和分析
    $result = $uploader->handleUpload($_FILES['floorplanFile'], $building_id);
    
    if ($result['success']) {
        // 記錄分析結果
        error_log("平面圖分析成功: building_id={$building_id}, 檔案={$result['fileName']}");
        error_log("識別結果: " . json_encode($result['analysisResult']));
        
        // 調整比例尺
        if ($scale != 0.01) {
            $result['analysisResult'] = adjustScale($result['analysisResult'], $scale / 0.01);
        }
        
        echo json_encode($result);
    } else {
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("平面圖處理錯誤: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => '處理平面圖時發生錯誤: ' . $e->getMessage()
    ]);
}

/**
 * 調整分析結果的比例尺
 */
function adjustScale($analysisResult, $scaleFactor) {
    // 調整樓層
    if (isset($analysisResult['floors'])) {
        foreach ($analysisResult['floors'] as &$floor) {
            if (isset($floor['area'])) {
                $floor['area'] *= $scaleFactor * $scaleFactor;
            }
        }
    }
    
    // 調整單元
    if (isset($analysisResult['units'])) {
        foreach ($analysisResult['units'] as &$unit) {
            if (isset($unit['area'])) {
                $unit['area'] *= $scaleFactor * $scaleFactor;
            }
            if (isset($unit['width'])) {
                $unit['width'] *= $scaleFactor;
            }
            if (isset($unit['height'])) {
                $unit['height'] *= $scaleFactor;
            }
        }
    }
    
    // 調整房間
    if (isset($analysisResult['rooms'])) {
        foreach ($analysisResult['rooms'] as &$room) {
            if (isset($room['area'])) {
                $room['area'] *= $scaleFactor * $scaleFactor;
            }
            if (isset($room['width'])) {
                $room['width'] *= $scaleFactor;
            }
            if (isset($room['height'])) {
                $room['height'] *= $scaleFactor;
            }
        }
    }
    
    // 調整窗戶
    if (isset($analysisResult['windows'])) {
        foreach ($analysisResult['windows'] as &$window) {
            if (isset($window['area'])) {
                $window['area'] *= $scaleFactor * $scaleFactor;
            }
            if (isset($window['width'])) {
                $window['width'] *= $scaleFactor;
            }
            if (isset($window['height'])) {
                $window['height'] *= $scaleFactor;
            }
        }
    }
    
    return $analysisResult;
}
?> 