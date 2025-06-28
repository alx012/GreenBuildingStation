<?php
/****************************************************************************
 * 資料庫連線設定
 ****************************************************************************/
$serverName = "localhost\SQLEXPRESS";
$database   = "Test";

try {
    // 首先嘗試Windows認證
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database;TrustServerCertificate=true");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // 如果Windows認證失敗，嘗試SQL Server認證
    try {
        $username = "sa";           // SQL Server管理員帳號
        $password = "password1234"; // 您記住的密碼
        $conn = new PDO("sqlsrv:server=$serverName;Database=$database;TrustServerCertificate=true", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e2) {
        die("資料庫連線失敗：Windows認證和SQL Server認證都失敗。<br>錯誤詳情：" . $e2->getMessage());
    }
}
?>