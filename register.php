<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>註冊</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin-top: 200px; /* 確保 navbar 不會擋住主內容 */
            padding: 0;
            background-image: url('https://i.imgur.com/WJGtbFT.jpeg');
            background-color: rgba(255, 255, 255, 0.8);
            background-size: 100% 100%; /* 使背景圖片填滿整個背景區域 */
            background-position: center; /* 背景圖片居中 */
            background-repeat: no-repeat; /* 不重複背景圖片 */
            background-attachment: fixed; /* 背景固定在視口上 */
        }
        
        .navbar-brand {
            font-weight: bold;
        }

        .register-container { 
            background: white; 
            padding: 30px; 
            border-radius: 10px; 
            width: 400px; 
            margin: auto; 
            margin-top: 50px; 
        }

        .register-container h2 { 
            text-align: center; 
        }
        
        /* 導覽列背景顏色 */
        .custom-navbar {
        background-color: #769a76; /* 這裡可以換成你要的顏色 */
        }
        
    </style>
</head>
<body>

    <?php include('navbar.php'); ?>

    <div class="register-container">
        <h2><?php echo __('register_title'); ?></h2>

        <?php if (isset($_GET['error'])): ?>
            <p style="color: red;"><?php echo htmlspecialchars($_GET['error']); ?></p>
        <?php endif; ?>

        <form action="register_process.php" method="POST">
            <div class="mb-3">
                <label for="username" class="form-label"><?php echo __('username'); ?></label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label"><?php echo __('email'); ?></label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label"><?php echo __('password'); ?></label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="confirm-password" class="form-label"><?php echo __('confirm_password'); ?></label>
                <input type="password" class="form-control" id="confirm-password" name="confirm-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100"><?php echo __('register_button'); ?></button>
        </form>
    </div>

</body>
</html>
