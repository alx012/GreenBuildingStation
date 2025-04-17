<?php
session_start();

// 檢查用戶是否已登入
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
    
    // 獲取用戶當前資料
    $stmt = $conn->prepare("SELECT Username, Email FROM Users WHERE UserID = :user_id");
    $stmt->bindParam(":user_id", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>編輯個人資料</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin-top: 200px;
            padding: 0;
            background-image: url('https://i.imgur.com/WJGtbFT.jpeg');
            background-color: rgba(255, 255, 255, 0.8);
            background-size: 100% 100%;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        .edit-container { 
            background: white; 
            padding: 30px; 
            border-radius: 10px; 
            width: 600px; 
            margin: auto; 
            margin-top: 50px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .custom-navbar {
            background-color: #769a76;
        }
    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="edit-container">
        <h2 class="text-center mb-4"><?php echo __('edit_profile'); ?></h2>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <form action="update_profile.php" method="POST">
            <div class="mb-3">
                <label for="username" class="form-label"><?php echo __('username_label'); ?></label>
                <input type="text" class="form-control" id="username" name="username" 
                    value="<?php echo htmlspecialchars($user['Username']); ?>" required>
            </div>
            
            <div class="mb-3">
                <label for="email" class="form-label"><?php echo __('email_label'); ?></label>
                <input type="email" class="form-control" id="email" name="email" 
                    value="<?php echo htmlspecialchars($user['Email']); ?>" required>
            </div>
            
            <div class="mb-3">
                <label for="new_password" class="form-label"><?php echo __('new_password_label'); ?></label>
                <input type="password" class="form-control" id="new_password" name="new_password">
            </div>
            
            <div class="mb-3">
                <label for="confirm_password" class="form-label"><?php echo __('confirm_password_label'); ?></label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password">
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary"><?php echo __('update_btn'); ?></button>
                <a href="profile.php" class="btn btn-secondary"><?php echo __('back_btn'); ?></a>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>