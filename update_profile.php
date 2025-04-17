<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 資料庫連線設定
$serverName = "localhost\SQLEXPRESS";
$database   = "Test";
$username   = "weihao0120";  
$password   = "weihao0120";  

try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 獲取表單數據
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    // 基本驗證
    if (empty($new_username) || empty($new_email)) {
        header("Location: edit_profile.php?error=請填寫必要欄位");
        exit();
    }

    // 檢查電子郵件格式
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        header("Location: edit_profile.php?error=無效的電子郵件格式");
        exit();
    }

    // 檢查使用者名稱或電子郵件是否已被其他用戶使用
    $stmt = $conn->prepare("SELECT UserID FROM Users WHERE (Username = :username OR Email = :email) AND UserID != :user_id");
    $stmt->bindParam(":username", $new_username);
    $stmt->bindParam(":email", $new_email);
    $stmt->bindParam(":user_id", $_SESSION['user_id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        header("Location: edit_profile.php?error=使用者名稱或電子郵件已被使用");
        exit();
    }

    // 準備更新SQL
    if (!empty($new_password)) {
        // 驗證密碼
        if ($new_password !== $confirm_password) {
            header("Location: edit_profile.php?error=兩次輸入的密碼不相符");
            exit();
        }
        
        // 更新包含密碼
        $sql = "UPDATE Users SET Username = :username, Email = :email, Password = :password WHERE UserID = :user_id";
        $stmt = $conn->prepare($sql);
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt->bindParam(":password", $hashed_password);
    } else {
        // 只更新用戶名和郵箱
        $sql = "UPDATE Users SET Username = :username, Email = :email WHERE UserID = :user_id";
        $stmt = $conn->prepare($sql);
    }

    $stmt->bindParam(":username", $new_username);
    $stmt->bindParam(":email", $new_email);
    $stmt->bindParam(":user_id", $_SESSION['user_id']);
    $stmt->execute();

    // 更新 session 中的用戶名
    $_SESSION['username'] = $new_username;

    header("Location: profile.php?success=個人資料更新成功");
    exit();

} catch (PDOException $e) {
    header("Location: edit_profile.php?error=更新失敗：" . urlencode($e->getMessage()));
    exit();
}
?>