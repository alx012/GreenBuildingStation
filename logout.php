<?php
session_start();

// 清除會話資料
session_unset();

// 銷毀會話
session_destroy();

// 重定向回登入頁面
header("Location: homepg.php");
exit;
?>
