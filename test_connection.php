<?php
// SQL Server 連線參數
$serverName = "localhost\SQLEXPRESS"; // 或伺服器 IP 地址
$database = "Test";      // 資料庫名稱
$username = "weihao0120";     // 資料庫用戶
$password = "weihao0120"; // 資料庫密碼

// 嘗試連接 SQL Server
try {
    // 建立 PDO 連線
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);

    // 設定 PDO 錯誤模式為例外
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 查詢 Users 資料表
    $sql = "SELECT * FROM Users";
    $stmt = $conn->query($sql);

    // 將結果存入陣列
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // 錯誤處理
    die("資料庫連線失敗：" . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL Server 與 PHP 串接</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
        }
    </style>
</head>
<body>
    <h1>使用者清單</h1>
    <?php if (!empty($users)): ?>
        <table>
            <thead>
                <tr>
                    <th>UserID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>CreatedAt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['UserID']); ?></td>
                        <td><?php echo htmlspecialchars($user['Username']); ?></td>
                        <td><?php echo htmlspecialchars($user['Email']); ?></td>
                        <td><?php echo htmlspecialchars($user['CreatedAt']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>無任何使用者資料。</p>
    <?php endif; ?>
</body>
</html>
