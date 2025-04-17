<?php
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

session_start();
$userId = $_SESSION['UserID'];  // 假設使用者已登入，且 UserID 存在 session 中

// 查詢該使用者所有的篩選條件
$sql = "SELECT * FROM pj_filter_settings WHERE UserID = :userId";
$stmt = $conn->prepare($sql);
$stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
$stmt->execute();

// 準備回傳的結果
$filters = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // 將篩選條件轉換為 JSON 格式的陣列
    $filters[] = [
        'filterName' => $row['filter_name'],
        'filterData' => json_decode($row['filter_conditions'], true),
        'createdAt' => $row['created_at']
    ];
}

// 回傳篩選條件列表
echo json_encode($filters);

// 關閉連線
$stmt = null;
$conn = null;
?>
