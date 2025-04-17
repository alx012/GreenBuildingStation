<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>首頁</title>
    
    <!-- 引入 Bootstrap 5 -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    />
    
    <style>
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            background-image: url('https://i.imgur.com/WJGtbFT.jpeg');
            background-color: rgba(255, 255, 255, 0.8);
            background-size: 100% 100%;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .navbar-brand {
            font-weight: bold;
        }

        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .button-container {
            display: flex;
            flex-direction: row;
            justify-content: center;
            gap: 150px;
            margin-top: 80px;
        }

        .btn {
            background-color: rgba(254, 254, 254, 0.7);
            color: #333;
            text-align: center;
            text-decoration: none;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 32px;
            font-weight: bold;
            margin: 10px;
            cursor: pointer;
            border-radius: 12px;
            border: none;
            transition: background-color 0.2s, color 0.2s;
            width: 350px;
            height: 200px;
        }

        .btn:hover {
            background-color: rgba(166, 186, 175, 0.8);
            color: #fff;
        }

        .custom-navbar {
            background-color: #769a76;
        }
    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="button-container">
        <a class="btn" id="greenBuildingBtn" href="greenbuildingcal.php"
           onmouseover="changeText('greenBuildingBtn', i18n.getText('greenBuildingHover'))"
           onmouseout="changeText('greenBuildingBtn', i18n.getText('greenBuilding'))">
        </a>

        <a class="btn" id="urbanClimateBtn" href="urbanclimate.php"
           onmouseover="changeText('urbanClimateBtn', i18n.getText('urbanClimateHover'))"
           onmouseout="changeText('urbanClimateBtn', i18n.getText('urbanClimate'))">
        </a>
    </div>
    
    <!-- 先加載翻譯文件 -->
    <script src="GBS_js/translations.js"></script>
    <!-- 後加載 i18n 類 -->
    <script src="GBS_js/i18n.js"></script>
    
    <script>
        function changeText(buttonId, newText) {
            document.getElementById(buttonId).innerHTML = newText;
        }

        // 為了同步 navbar 和主頁的語言切換
        window.addEventListener('storage', function(e) {
            if (e.key === 'language') {
                // 當 localStorage 中的語言改變時，重新加載頁面
                window.location.reload();
            }
        });
    </script>
</body>
</html>

<!-- 基本版 -->
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>首頁</title>
    
    <!-- 引入 Bootstrap 5 -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    />
    
    <style>
        body {
            margin: 0;
            padding: 0;
            height: 100vh; /* 讓 body 佔滿整個視窗 */
            background-image: url('https://i.imgur.com/WJGtbFT.jpeg');
            background-color: rgba(255, 255, 255, 0.8);
            background-size: 100% 100%; /* 讓背景圖片填滿整個背景區域 */
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            
            /* 讓內容垂直 & 水平置中 */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .navbar-brand {
            font-weight: bold;
        }

        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .button-container {
            display: flex;
            flex-direction: row;
            justify-content: center;
            gap: 150px;
            margin-top: 80px;
        }

        .btn {
            background-color: rgba(254, 254, 254, 0.7);
            color: #333;
            text-align: center;
            text-decoration: none;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 32px;
            font-weight: bold;
            margin: 10px;
            cursor: pointer;
            border-radius: 12px;
            border: none;
            transition: background-color 0.2s, color 0.2s;
            width: 350px;
            height: 200px;
        }

        .btn:hover {
            background-color: rgba(166, 186, 175, 0.8);
            color: #fff;
        }

        /* 導覽列背景顏色 */
        .custom-navbar {
        background-color: #769a76; /* 這裡可以換成你要的顏色 */
        }
        
    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="button-container">
        <a class="btn" id="greenBuildingBtn" href="greenbuildingcal.php"
           onmouseover="changeText('greenBuildingBtn', '開始建置>>>')"
           onmouseout="changeText('greenBuildingBtn', '綠建築資料建置')">
           綠建築資料建置
        </a>

        <a class="btn" id="urbanClimateBtn" href="urbanclimate.php"
           onmouseover="changeText('urbanClimateBtn', '開始建置>>>')"
           onmouseout="changeText('urbanClimateBtn', '街廓微氣候資料建置')">
           街廓微氣候資料建置
        </a>
    </div>

    <script>
        function changeText(buttonId, newText) {
            document.getElementById(buttonId).innerHTML = newText;
        }
    </script>
</body>
</html>
