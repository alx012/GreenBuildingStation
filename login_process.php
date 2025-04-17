<?php
session_start();

// 連接資料庫
$serverName = "localhost\SQLEXPRESS";
$database   = "Test";
$username   = "weihao0120";
$password   = "weihao0120";

try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

// 確保請求是 POST
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: login.php?error=非法存取");
    exit;
}

// 接收表單資料並驗證
$username = trim($_POST["username"]);
$password = $_POST["password"];

if (empty($username) || empty($password)) {
    header("Location: login.php?error=請填寫所有欄位");
    exit;
}

// 檢查帳號是否存在
$checkUserSQL = "SELECT * FROM Users WHERE Username = :username";
$stmt = $conn->prepare($checkUserSQL);
$stmt->bindParam(":username", $username);
$stmt->execute();

if ($stmt->rowCount() == 0) {
    header("Location: login.php?error=帳號不存在");
    exit;
}

// 取得使用者資料
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// 驗證密碼
if (!password_verify($password, $user['Password'])) {
    header("Location: login.php?error=密碼錯誤");
    exit;
}

// 登入成功，建立會話
$_SESSION['user_id'] = $user['UserID'];
$_SESSION['username'] = $user['Username'];

// 重定向到主頁或使用者儀表板
header("Location: homepg.php");
exit;
?>
