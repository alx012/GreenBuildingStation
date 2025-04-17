<?php if (isset($_GET['error'])): ?>
    <script>
        alert("<?php echo htmlspecialchars($_GET['error']); ?>");
    </script>
<?php endif; ?>


<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入</title>
    
    <!-- 引入 Bootstrap 5 -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    />

    <style>
        body { 
            font-family: Arial, sans-serif; 
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f4f4f4;
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
        
        .login-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 300px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .login-container h2 {
            margin-bottom: 20px;
        }
        .input-group {
            margin-bottom: 15px;
            text-align: center;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .input-group label {
            display: block;
            margin-bottom: 5px;
        }
        .input-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            text-align: center;
        }
        .login-btn {
            width: 80%;
            padding: 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .login-btn:hover {
            background: #218838;
        }

        .button-container {
            display: flex;
            justify-content: center;
            width: 100%;
        }

        /* 導覽列背景顏色 */
        .custom-navbar {
        background-color: #769a76; /* 這裡可以換成你要的顏色 */
        }

    </style>
</head>
<body>
    <!--引用include() -->
    <?php include('navbar.php'); ?>
    
    <div class="login-container">
        <h2><?php echo __('login_title'); ?></h2>
        <form action="login_process.php" method="POST">
            <div class="input-group">
                <label for="username"><?php echo __('username'); ?></label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="input-group">
                <label for="password"><?php echo __('password'); ?></label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="button-container">
                <button type="submit" class="login-btn"><?php echo __('login_button'); ?></button>
            </div>
        </form>
    </div>
</body>
</html>