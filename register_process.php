<?php
session_start();

// [1] 連接資料庫
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

// [2] 確保請求是 POST
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: register.php?error=非法存取");
    exit;
}

// [3] 接收表單資料並驗證
$username = trim($_POST["username"]);
$email = trim($_POST["email"]);
$password = $_POST["password"];
$confirmPassword = $_POST["confirm-password"];

if (empty($username) || empty($email) || empty($password) || empty($confirmPassword)) {
    header("Location: register.php?error=請填寫所有欄位");
    exit;
}

if ($password !== $confirmPassword) {
    header("Location: register.php?error=密碼與確認密碼不相符");
    exit;
}

// [4] 加密密碼
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// [5] 檢查 Email 是否已存在
$checkEmailSQL = "SELECT * FROM Users WHERE Email = :email";
$stmt = $conn->prepare($checkEmailSQL);
$stmt->bindParam(":email", $email);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    header("Location: register.php?error=此電子郵件已被註冊");
    exit;
}

// [6] 新增使用者到資料庫
$sql = "INSERT INTO Users (Username, Email, Password, CreatedAt) 
        VALUES (:username, :email, :password, GETDATE())";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(":username", $username);
    $stmt->bindParam(":email", $email);
    $stmt->bindParam(":password", $hashedPassword);
    $stmt->execute();

    header("Location: login.php?success=註冊成功，請登入");
    exit;
} catch (PDOException $e) {
    header("Location: register.php?error=註冊失敗：" . $e->getMessage());
    exit;
}
?>
