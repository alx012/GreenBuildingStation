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
    
    // 使用正確的欄位名稱 UserID
    $stmt = $conn->prepare("SELECT Username, Email, CreatedAt FROM Users WHERE UserID = :user_id");
    $stmt->bindParam(":user_id", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        die("無法找到用戶資料");
    }
    
} catch (PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>個人資訊</title>
    
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
        
        .navbar-brand {
            font-weight: bold;
        }

        .profile-container { 
            background: white; 
            padding: 30px; 
            border-radius: 10px; 
            width: 600px; 
            margin: auto; 
            margin-top: 50px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .profile-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .profile-info {
            margin-bottom: 20px;
        }

        .info-label {
            font-weight: bold;
            color: #666;
        }

        .custom-navbar {
            background-color: #769a76;
        }
    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="profile-container">
        <div class="profile-header">
            <h2><?php echo __('personal_info'); ?></h2>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <div class="profile-info">
            <div class="row mb-3">
                <div class="col-4 info-label"><?php echo __('username_label'); ?>：</div>
                <div class="col-8"><?php echo htmlspecialchars($user['Username']); ?></div>
            </div>
            <div class="row mb-3">
                <div class="col-4 info-label"><?php echo __('email_label'); ?>：</div>
                <div class="col-8"><?php echo htmlspecialchars($user['Email']); ?></div>
            </div>
            <div class="row mb-3">
                <div class="col-4 info-label"><?php echo __('registration_date'); ?>：</div>
                <div class="col-8"><?php echo htmlspecialchars($user['CreatedAt']); ?></div>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="edit_profile.php" class="btn btn-primary"><?php echo __('edit_profile'); ?></a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>