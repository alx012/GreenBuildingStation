<?php
/****************************************************************************
 * [1] 資料庫連線 (請根據你的實際環境調整)
 ****************************************************************************/
$serverName = "localhost\SQLEXPRESS";
$database   = "Test";
$username   = "weihao0120";   // 依照你實際的帳號
$password   = "weihao0120";   // 依照你實際的密碼

try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

$data = json_decode(file_get_contents("php://input"), true);
$filterName = $data['filterName'];
$filterData = json_encode($data['filterData']); // 轉成 JSON 格式存入資料庫

$userId = $_SESSION['UserID'];  // 假設使用者已登入，且 UserID 存在 session 中

$sql = "INSERT INTO pj_filter_settings (UserID, filter_name, filter_conditions) VALUES (:userId, :filterName, :filterData)";
$stmt = $conn->prepare($sql);
$stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
$stmt->bindValue(':filterName', $filterName, PDO::PARAM_STR);
$stmt->bindValue(':filterData', $filterData, PDO::PARAM_STR);

if ($stmt->execute()) {
    echo json_encode(["message" => "篩選條件已儲存"]);
} else {
    echo json_encode(["message" => "儲存失敗"]);
}

$stmt = null;
$conn = null;
?>
