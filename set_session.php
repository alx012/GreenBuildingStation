<?php
//用來保持navbar當前專案名稱一致性

session_start();

$response = ['success' => false, 'message' => ''];

if (isset($_POST['gbd_project_id']) && isset($_POST['gbd_project_name'])) {
    $_SESSION['gbd_project_id'] = $_POST['gbd_project_id'];
    $_SESSION['gbd_project_name'] = $_POST['gbd_project_name'];
    
    // 同時也更新通用專案變數
    $_SESSION['current_gbd_project_id'] = $_POST['gbd_project_id'];
    $_SESSION['current_gbd_project_name'] = $_POST['gbd_project_name'];
    
    $response['success'] = true;
    $response['message'] = '專案資訊已更新';
} else {
    $response['message'] = '缺少必要參數';
}

header('Content-Type: application/json');
$response = ['success' => false];

// 處理專案ID和名稱設置
if (isset($_POST['gbd_project_id']) && isset($_POST['gbd_project_name'])) {
    $_SESSION['gbd_project_id'] = $_POST['gbd_project_id'];
    $_SESSION['gbd_project_name'] = $_POST['gbd_project_name'];
    $response['success'] = true;
    $response['message'] = '專案資訊已更新';
}
// 處理清除專案資訊
else if (isset($_POST['clear_project'])) {
    unset($_SESSION['gbd_project_id']);
    unset($_SESSION['gbd_project_name']);
    $response['success'] = true;
    $response['message'] = '專案資訊已清除';
}

echo json_encode($response);
?>