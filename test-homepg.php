<?php 
include('language.php');
// 確保session已啟動
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('title'); ?></title>
    
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
    <?php include('test-navbar.php'); ?>
    
    <div class="button-container">
        <a class="btn" id="greenBuildingBtn" href="greenbuildingcal.php"
           onmouseover="changeText('greenBuildingBtn', '<?php echo __('btn_greenBuildingHover'); ?>')"
           onmouseout="changeText('greenBuildingBtn', '<?php echo __('btn_greenBuilding'); ?>')">
           <?php echo __('btn_greenBuilding'); ?>
        </a>
        
        <a class="btn" id="urbanClimateBtn" href="urbanclimate-new.php"
           onmouseover="changeText('urbanClimateBtn', '<?php echo __('btn_urbanClimateHover'); ?>')"
           onmouseout="changeText('urbanClimateBtn', '<?php echo __('btn_urbanClimate'); ?>')">
           <?php echo __('btn_urbanClimate'); ?>
        </a>
    </div>

<script>
        function changeText(buttonId, newText) {
            document.getElementById(buttonId).innerHTML = newText;
        }
    </script>
</body>
</html>