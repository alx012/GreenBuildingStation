<?php
// 確保在任何輸出之前啟動 session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 包含數據庫連接
include('db_connection.php'); // 您的數據庫連接文件

// 設置默認語言
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'zh_TW';
}

// 處理語言切換
if (isset($_GET['lang'])) {
    // 確保語言設置與數據庫欄位名匹配
    // 這裡添加轉換，將前端的zh-TW轉為數據庫的zh_TW
    $lang = str_replace('-', '_', $_GET['lang']);
    $_SESSION['language'] = $lang;
    
    // 如果有 HTTP_REFERER，則重定向回原頁面
    if (isset($_SERVER['HTTP_REFERER'])) {
        // 從referrer URL中移除可能存在的lang參數，以避免循環重定向
        $url = preg_replace('/([?&])lang=[^&]+(&|$)/', '$1', $_SERVER['HTTP_REFERER']);
        // 如果URL最後是&或?，則移除
        $url = rtrim($url, '&?');
        // 檢查URL是否包含?
        $url .= (strpos($url, '?') === false) ? '?' : '&';
        // 添加時間戳以避免緩存問題
        $url .= 'timestamp=' . time();
        
        header("Location: $url");
        exit;
    } else {
        header("Location: homepg.php");
        exit;
    }
}

// 從數據庫加載語言字符串
function getLanguageStrings() {
    global $conn; // 使用全局數據庫連接變量
    
    $language = $_SESSION['language'];
    $strings = [];
    
    // 從數據庫獲取所有語言字符串 - 調整為SQL Server語法
    try {
        $sql = "SELECT string_key, [$language] FROM language_strings";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        // 加入調試輸出
        $count = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $strings[$row['string_key']] = $row[$language];
            $count++;
        }
        
        // 調試語言字符串
        error_log("已載入 $count 個語言字符串，當前語言: $language");
        
        return $strings;
    } catch (PDOException $e) {
        // 錯誤處理
        error_log("語言數據庫查詢錯誤: " . $e->getMessage());
        return [];
    }
}

// 翻譯函數 - 使用這個函數獲取特定語言的文本
function __($key) {
    static $strings = null;
    
    if ($strings === null) {
        $strings = getLanguageStrings();
    }
    
    return isset($strings[$key]) ? $strings[$key] : $key;
}

// 添加此函數用於切換語言的前端顯示，轉換回帶連字符的格式
function getDisplayLanguage() {
    return str_replace('_', '-', $_SESSION['language']);
}

// 添加簡單的調試功能（可選，上線前可移除）
function debugLanguage() {
    echo "<div style='position:fixed; bottom:10px; right:10px; background:#f8f9fa; padding:5px; z-index:9999; border:1px solid #ddd; font-size:12px;'>";
    echo "當前語言: " . $_SESSION['language'] . "<br>";
    echo "顯示語言: " . getDisplayLanguage() . "<br>";
    echo "</div>";
}

// 添加生成語言切換鏈接的函數，帶確認對話框
function generateLanguageSwitchLink($lang, $label) {
    $confirmMessage = __('save_data_confirm'); // 您需要在語言數據庫中添加此鍵
    if (empty($confirmMessage)) {
        $confirmMessage = '請確保已儲存所有資料，網頁即將更新。確定要切換語言嗎？';
    }
    
    return '<a href="javascript:void(0);" onclick="if(confirm(\'' . $confirmMessage . '\')) window.location.href=\'?lang=' . $lang . '\'">' . $label . '</a>';
}
?>