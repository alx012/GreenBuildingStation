<?php
/****************************************************************************
 * [0] 開啟 Session，方便累積篩選條件, 利用「HTTP_REFERER」判斷是否從外部網站回來並清空
 ****************************************************************************/
session_start();

// 檢查是否已登入
if (!isset($_SESSION['user_id'])) {
    $isLoggedIn = false;
} else {
    $isLoggedIn = true;
}

$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

// 只要不是從本頁 (或特定條件) 回來，就清除 session
if (!empty($referer)) {
    // 取得路徑部份，例如 /projectlevel.php
    $refererPath = parse_url($referer, PHP_URL_PATH);

    // 檢查若不是從本頁連回，就清除
    // (路徑依實際情況調整; 比對檔名)
    if ($refererPath !== '/projectlevel-0420.php') {
        // 清除篩選條件
        unset($_SESSION['filters']);
        
        // 同時清除專案名稱和描述
        unset($_SESSION['current_project_name']);
        unset($_SESSION['current_project_description']);
    }
} else {
    // 如果沒有 referer 也視為新訪問，清除相關 session
    // 這能處理直接輸入網址或從書籤進入的情況
    if (!isset($_POST['save_filters']) && 
        !isset($_GET['add']) && 
        !isset($_GET['clear']) && 
        !isset($_GET['remove']) && 
        !isset($_GET['load_project'])) {
        
        unset($_SESSION['filters']);
        unset($_SESSION['current_project_name']);
        unset($_SESSION['current_project_description']);
    }
}

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

/****************************************************************************
 * [2] 定義外殼/空調 -> 資料表、欄位對應 (依你的實際需求調整)
 ****************************************************************************/
$typeConfig = [
    '外殼' => [
        'tableName'        => '外殼',
        'costDesignColumn' => '外殼設計方案'
    ],
    '空調' => [
        'tableName'        => '空調',
        'costDesignColumn' => '空調設計方案'
    ],
    '照明' => [
        'tableName'        => '照明',
        'costDesignColumn' => '照明設計方案'
    ],
    'CO2減量' => [
        'tableName'        => 'CO2減量',
        'costDesignColumn' => 'CO2減量方案'
    ],
    '廢棄物減量' => [
        'tableName'        => '廢棄物減量',
        'costDesignColumn' => '廢棄物減量方案'
    ],
    '室內環境' => [
        'tableName'        => '室內環境',
        'costDesignColumn' => '室內環境設計方案'
    ],
    '綠化設計' => [
        'tableName'        => '綠化設計',
        'costDesignColumn' => '綠化量設計方案'
    ],
    '基地保水' => [
        'tableName'        => '基地保水',
        'costDesignColumn' => '基地保水設計方案'
    ],
    '水資源' => [
        'tableName'        => '水資源',
        'costDesignColumn' => '水資源節省方案'
    ],
    '污水垃圾' => [
        'tableName'        => '污水垃圾',
        'costDesignColumn' => '污水垃圾改善方案'
    ]
];

/****************************************************************************
 * [3] 撈表格結構 & distinct 值的函式
 ****************************************************************************/
function getTableColumns(PDO $conn, $tableName) {
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo'
          AND TABLE_NAME = :tName
          AND COLUMN_NAME NOT LIKE '方案'  -- 排除包含'方案'的欄位
        ORDER BY ORDINAL_POSITION
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':tName' => $tableName]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN); // 回傳一維陣列(每個欄位名稱)
}
// new function
function getDistinctValues(PDO $conn, $tableName, $colName) {
    // 防止中括號衝突，將欄位名以中括號包起
    $colSafe = "[" . str_replace(["[","]"], "", $colName) . "]";
    
    // 1. 首先獲取所有唯一值
    $sql = "
        SELECT DISTINCT $colSafe AS val
        FROM [dbo].[$tableName]
        ORDER BY $colSafe
    ";
    $values = $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    
    // 2. 如果只有一個值，直接返回
    if (count($values) == 1) {
        return $values;
    }
    
    // 3. 檢查是否所有值都是數字
    $allNumeric = true;
    foreach ($values as $val) {
        if (!is_numeric($val)) {
            $allNumeric = false;
            break;
        }
    }
    
    // 4. 如果全是數字，則生成區間
    if ($allNumeric && count($values) > 1) {
        // 找出最小值和最大值
        $min = min($values);
        $max = max($values);
        
        // 決定區間數量：
        // - 如果值數量小於等於10，則每個值一個區間
        // - 否則，最多10個區間
        $numIntervals = min(10, count($values));
        
        // 計算每個區間的範圍
        $interval = ($max - $min) / $numIntervals;
        
        // 判斷數據類型並決定格式化方式
        $isInteger = true;
        foreach ($values as $val) {
            if (floor($val) != $val) {
                $isInteger = false;
                break;
            }
        }
        
        // 生成區間
        $intervals = [];
        for ($i = 0; $i < $numIntervals; $i++) {
            $start = $min + ($i * $interval);
            $end = $start + $interval;
            
            // 根據數據類型決定格式化方式
            if ($isInteger) {
                // 對於整數類型，四捨五入到整數
                $formattedStart = round($start);
                $formattedEnd = round($end);
            } else {
                // 對於小數，只保留一位小數
                $formattedStart = number_format($start, 1, '.', '');
                $formattedEnd = number_format($end, 1, '.', '');
            }
            
            // 特殊處理最後一個區間，確保包含最大值
            if ($i == $numIntervals - 1) {
                $formattedMax = $isInteger ? round($max) : number_format($max, 1, '.', '');
                $intervals[] = "{$formattedStart} - {$formattedMax}";
            } else {
                $intervals[] = "{$formattedStart} - {$formattedEnd}";
            }
        }
        
        // 添加原始值的範圍，以便在篩選時使用
        $intervalValues = [];
        for ($i = 0; $i < count($intervals); $i++) {
            $display = $intervals[$i]; // 顯示值
            
            // 取得實際使用的範圍（用於篩選的值）
            $start = $min + ($i * $interval);
            $end = ($i == $numIntervals - 1) ? $max : $start + $interval;
            
            // 儲存格式：顯示值|實際起始值|實際結束值
            $intervalValues[] = "$display|$start|$end";
        }
        
        return $intervalValues;
    }
    
    // 5. 如果不全是數字，則返回原始值
    return $values;
}
// new function
function processIntervalValue($columnName, $intervalStr) {
    // 檢查是否為區間格式 (格式如: "10.00 - 20.00")
    if (strpos($intervalStr, ' - ') !== false) {
        list($min, $max) = explode(' - ', $intervalStr);
        
        // 去除可能的空白
        $min = trim($min);
        $max = trim($max);
        
        // 返回BETWEEN條件
        return "$columnName BETWEEN $min AND $max";
    }
    
    // 如果不是區間，返回一般的等於條件
    return "$columnName = '$intervalStr'";
}
/****************************************************************************
 * [4] 接收使用者針對「一組篩選」的選擇
 ****************************************************************************/
$selected_type = isset($_GET['type']) ? $_GET['type'] : '';
$selected_col  = isset($_GET['col'])  ? $_GET['col']  : '';
$selected_val  = isset($_GET['val'])  ? $_GET['val']  : '';

$addFilter = (isset($_GET['add']) && $_GET['add'] == '1');   // 按下「確認(新增)篩選」
$clearAll  = (isset($_GET['clear']) && $_GET['clear'] == '1'); // 按下「清除全部」篩選

/****************************************************************************
 * [5] 如果「清除全部」 => 把 Session 內累積的篩選清空, 如果有指定 remove 索引 => 刪除指定的篩選條目
 ****************************************************************************/
if ($clearAll) {
    unset($_SESSION['filters']);
    header("Location: projectlevel-0420.php");
    exit;
}

if (isset($_GET['remove'])) {
  $removeIndex = (int) $_GET['remove']; // 強制轉 int 比較保險
  if (isset($_SESSION['filters'][$removeIndex])) {
      // 移除該索引
      array_splice($_SESSION['filters'], $removeIndex, 1);
  }
  header("Location: projectlevel-0420.php");
  exit;
}
/****************************************************************************
 * [6] 載入下拉選單：依據「type」先載欄位，再載該欄位可能的值
 ****************************************************************************/
$columns = [];
$values  = [];

if (!empty($selected_type) && isset($typeConfig[$selected_type])) {
    $tableName = $typeConfig[$selected_type]['tableName'];
    $columns   = getTableColumns($conn, $tableName);

    if (!empty($selected_col)) {
        $values = getDistinctValues($conn, $tableName, $selected_col);
    }
}

/****************************************************************************
 * [7] 按「確認(新增)篩選」，三者 (type, col, val) 皆有效 => 寫入 Session
 ****************************************************************************/
if ($addFilter && $selected_type !== '' && $selected_col !== '' && $selected_val !== '') {
    if (!isset($_SESSION['filters'])) {
        $_SESSION['filters'] = [];
    }
    $_SESSION['filters'][] = [
        'type' => $selected_type,
        'col'  => $selected_col,
        'val'  => $selected_val
    ];
    header("Location: projectlevel-0420.php"); // 避免 F5 重覆送出
    exit;
}

/****************************************************************************
 * [8] 分頁設定
 ****************************************************************************/
$allowedPageSizes = [5, 10, 20, 50];
$recordsPerPage   = 10;  // 預設
if (isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowedPageSizes)) {
    $recordsPerPage = (int)$_GET['limit'];
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

/****************************************************************************
 * [9] 建立動態條件 (WHERE) + 參數
 ****************************************************************************/
$filters    = isset($_SESSION['filters']) ? $_SESSION['filters'] : [];
$whereParts = [];
$bindParams = [];

if (count($filters) > 0) {
    $paramIndex = 0;

    foreach ($filters as $f) {
        $t = $f['type'];   // 外殼 or 空調
        $c = $f['col'];
        $v = $f['val'];

        // 從 typeConfig 找對應資訊
        $tableName   = $typeConfig[$t]['tableName'];
        $costColName = "[".$typeConfig[$t]['costDesignColumn']."]";
        $colSafe     = "[" . str_replace(["[","]"], "", $c) . "]";

        // 檢查是否為區間值 (有兩種可能的格式：1. 5 - 10  2. 5 - 10|5|10)
        if (strpos($v, ' - ') !== false) {
            // 獲取區間的實際值
            $min = null;
            $max = null;
            
            if (strpos($v, '|') !== false) {
                // 格式為：顯示值|實際起始值|實際結束值
                $parts = explode('|', $v);
                if (count($parts) >= 3) {
                    $min = $parts[1];
                    $max = $parts[2];
                }
            }
            
            // 如果無法從格式中取得實際值，則解析顯示值
            if ($min === null || $max === null) {
                list($displayMin, $displayMax) = explode(' - ', $v);
                $min = trim($displayMin);
                $max = trim($displayMax);
            }
            
            // 使用BETWEEN子查詢
            $tmp = "c.$costColName IN (
                        SELECT [方案]
                        FROM [dbo].[$tableName]
                        WHERE $colSafe BETWEEN :MIN_$paramIndex AND :MAX_$paramIndex
                    )";
                    
            $whereParts[] = $tmp;
            $bindParams["MIN_$paramIndex"] = $min;
            $bindParams["MAX_$paramIndex"] = $max;
        } else {
            // 一般等於條件的子查詢
            $tmp = "c.$costColName IN (
                        SELECT [方案]
                        FROM [dbo].[$tableName]
                        WHERE $colSafe = :VAL_$paramIndex
                    )";
                    
            $whereParts[] = $tmp;
            $bindParams["VAL_$paramIndex"] = $v;
        }
        
        $paramIndex++;
    }
}

// 組合 WHERE
$whereClause = "";
if (!empty($whereParts)) {
    $whereClause = "WHERE " . implode(" AND ", $whereParts);
}

/****************************************************************************
 * [10] 查詢「總筆數」+「分頁資料」
 ****************************************************************************/
// 1. 計算總筆數
$sql_count = "
    SELECT COUNT(DISTINCT [編號]) AS total
    FROM [dbo].[成本] c
    $whereClause
";
$stmt_count = $conn->prepare($sql_count);
foreach ($bindParams as $key => $val) {
    $stmt_count->bindValue(":".$key, $val);
}
$stmt_count->execute();
$totalRecords = (int)$stmt_count->fetch(PDO::FETCH_ASSOC)['total'];

$totalPages = ($totalRecords > 0) ? ceil($totalRecords / $recordsPerPage) : 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $recordsPerPage;

// 2. 查詢分頁資料
$sql_data = "
    SELECT DISTINCT *
    FROM [dbo].[成本] c
    $whereClause
    ORDER BY c.[編號] ASC
    OFFSET :offset ROWS
    FETCH NEXT :limit ROWS ONLY
";
$stmt_data = $conn->prepare($sql_data);
foreach ($bindParams as $key => $val) {
    $stmt_data->bindValue(":".$key, $val);
}
$stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_data->bindValue(':limit',  $recordsPerPage, PDO::PARAM_INT);
$stmt_data->execute();
$rows = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

// 1. 先接收使用者輸入（查詢「方案」）:
$lookup_type = isset($_GET['lookup_type']) ? $_GET['lookup_type'] : '';
$lookup_plan = isset($_GET['lookup_plan']) ? $_GET['lookup_plan'] : '';

/****************************************************************************
 * [11] 讀取下拉選單數據 + 儲存數據 + 匯入數據
 ****************************************************************************/
// 處理新增描述表單提交
if (isset($_POST['save_filters']) && $_POST['save_filters'] == '1') {
  $filterName = $_POST['filter_name'] ?? '';
  $filterDescription = $_POST['filter_description'] ?? ''; // 確保獲取描述
  $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] == '1';
  
  // 宣告訊息變數，用於JavaScript通知
  $notification_type = '';
  $notification_message = '';
  
  // 檢查是否登入
  if (!isset($_SESSION['user_id'])) {
      $notification_type = 'error';
      $notification_message = '請先登入帳號以使用該功能';
  }
  // 檢查篩選名稱
  else if (empty($filterName)) {
      $notification_type = 'error';
      $notification_message = '請輸入篩選組別名稱';
  }
  // 檢查篩選條件是否為空
  else if (empty($filters)) {
      $notification_type = 'error';
      $notification_message = '沒有篩選條件可供儲存';
  }
  else {
      $userID = $_SESSION['user_id'];
      
      // 獲取當前綠建築專案的building_id (從session中獲取)
      $currentBuildingId = $_SESSION['gbd_project_id'] ?? null;
      
      try {
          // 檢查該使用者是否已有相同的專案名稱
          $stmtCheck = $conn->prepare("
              SELECT COUNT(*) FROM pj_filters 
              WHERE UserID = ? AND ProjectName = ?
          ");
          $stmtCheck->execute([$userID, $filterName]);
          $existingCount = $stmtCheck->fetchColumn();

          // 检查是否是当前正在编辑的同名项目
          $isCurrentProject = isset($_SESSION['current_project_name']) && 
                            $_SESSION['current_project_name'] === $filterName;

          if ($existingCount > 0 && !$overwrite && !$isCurrentProject) {
              $notification_type = 'error';
              $notification_message = '專案名稱已存在，請使用其他名稱';
          } else {
              // 如果需要覆蓋或是更新當前項目，先刪除舊有專案
              if ($existingCount > 0 && ($overwrite || $isCurrentProject)) {
                  $deleteStmt = $conn->prepare("
                      DELETE FROM pj_filters 
                      WHERE UserID = ? AND ProjectName = ?
                  ");
                  $deleteStmt->execute([$userID, $filterName]);
              }
              
              // 開始交易
              $conn->beginTransaction();
              
              foreach ($filters as $filter) {
                  $sql = "INSERT INTO pj_filters 
                         (UserID, Type, ColumnName, Value, CreatedAt, ProjectName, UserNote, building_id) 
                         VALUES (?, ?, ?, ?, GETDATE(), ?, ?, ?)";
                  
                  $stmt = $conn->prepare($sql);
                  $stmt->execute([
                      $userID, // 用戶ID
                      $filter['type'],
                      $filter['col'],
                      $filter['val'],
                      $filterName, // 篩選組別名稱
                      $filterDescription, // 描述內容保存在UserNote欄位
                      $currentBuildingId // 將當前的綠建築專案building_id儲存
                  ]);
              }
              
              // 提交交易
              $conn->commit();
              
              // 設置成功訊息
              $notification_type = 'success';
              $message_action = ($existingCount > 0) ? '覆蓋' : '儲存';
              $notification_message = '篩選內容已成功' . $message_action . '為：' . htmlspecialchars($filterName);
              
              // 儲存當前專案名稱和描述到SESSION
              $_SESSION['current_project_name'] = $filterName;
              $_SESSION['current_project_description'] = $filterDescription;
          }
      } catch (PDOException $e) {
          // 回滾交易
          $conn->rollBack();
          $notification_type = 'error';
          $notification_message = '儲存失敗：' . $e->getMessage();
      }
  }
  
  // 顯示通知訊息
  echo "<script>
      alert('{$notification_message}');
      window.location.href = 'projectlevel-0420.php';
  </script>";
  exit;
}


/****************************************************************************
 * [12] 處理新增描述表單提交
 ****************************************************************************/
// 處理AJAX請求：檢查項目是否存在
if (isset($_GET['check_project']) && $_GET['check_project'] == '1') {
  $projectName = $_GET['name'] ?? '';
  $response = ['exists' => false];

  if (!empty($projectName) && isset($_SESSION['user_id'])) {
      try {
          $userID = $_SESSION['user_id'];
          
          // 檢查該使用者是否已有相同的專案名稱
          $stmtCheck = $conn->prepare("
              SELECT COUNT(*) FROM pj_filters 
              WHERE UserID = ? AND ProjectName = ?
          ");
          $stmtCheck->execute([$userID, $projectName]);
          $existingCount = $stmtCheck->fetchColumn();
          
          $response['exists'] = ($existingCount > 0);
      } catch (PDOException $e) {
          $response['error'] = $e->getMessage();
      }
  }

  // 返回JSON響應
  header('Content-Type: application/json');
  echo json_encode($response);
  exit;
}
// 處理新增描述表單提交
if (isset($_POST['save_filters']) && $_POST['save_filters'] == '1') {
  $filterName = $_POST['filter_name'] ?? '';
  $filterDescription = $_POST['filter_description'] ?? ''; // 確保獲取描述
  $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] == '1';
  
  // 宣告訊息變數，用於JavaScript通知
  $notification_type = '';
  $notification_message = '';
  
  // 檢查是否登入
  if (!isset($_SESSION['user_id'])) {
      $notification_type = 'error';
      $notification_message = '請先登入帳號以使用該功能';
  }
  // 檢查篩選名稱
  else if (empty($filterName)) {
      $notification_type = 'error';
      $notification_message = '請輸入篩選組別名稱';
  }
  // 檢查篩選條件是否為空
  else if (empty($filters)) {
      $notification_type = 'error';
      $notification_message = '沒有篩選條件可供儲存';
  }
  else {
      $userID = $_SESSION['user_id'];
      
      try {
          // 檢查該使用者是否已有相同的專案名稱
          $stmtCheck = $conn->prepare("
              SELECT COUNT(*) FROM pj_filters 
              WHERE UserID = ? AND ProjectName = ?
          ");
          $stmtCheck->execute([$userID, $filterName]);
          $existingCount = $stmtCheck->fetchColumn();

          // 检查是否是当前正在编辑的同名项目
          $isCurrentProject = isset($_SESSION['current_project_name']) && 
                            $_SESSION['current_project_name'] === $filterName;

          if ($existingCount > 0 && !$overwrite && !$isCurrentProject) {
              $notification_type = 'error';
              $notification_message = '專案名稱已存在，請使用其他名稱';
          } else {
              // 如果需要覆蓋或是更新當前項目，先刪除舊有專案
              if ($existingCount > 0 && ($overwrite || $isCurrentProject)) {
                  $deleteStmt = $conn->prepare("
                      DELETE FROM pj_filters 
                      WHERE UserID = ? AND ProjectName = ?
                  ");
                  $deleteStmt->execute([$userID, $filterName]);
              }
              
              // 開始交易
              $conn->beginTransaction();
              
              foreach ($filters as $filter) {
                  $sql = "INSERT INTO pj_filters 
                         (UserID, Type, ColumnName, Value, CreatedAt, ProjectName, UserNote) 
                         VALUES (?, ?, ?, ?, GETDATE(), ?, ?)";
                  
                  $stmt = $conn->prepare($sql);
                  $stmt->execute([
                      $userID, // 用戶ID
                      $filter['type'],
                      $filter['col'],
                      $filter['val'],
                      $filterName, // 篩選組別名稱
                      $filterDescription // 描述內容保存在UserNote欄位
                  ]);
              }
              
              // 提交交易
              $conn->commit();
              
              // 設置成功訊息
              $notification_type = 'success';
              $message_action = ($existingCount > 0) ? '覆蓋' : '儲存';
              $notification_message = '篩選內容已成功' . $message_action . '為：' . htmlspecialchars($filterName);
              
              // 儲存當前專案名稱和描述到SESSION
              $_SESSION['current_project_name'] = $filterName;
              $_SESSION['current_project_description'] = $filterDescription;
          }
      } catch (PDOException $e) {
          // 回滾交易
          $conn->rollBack();
          $notification_type = 'error';
          $notification_message = '儲存失敗：' . $e->getMessage();
      }
  }
  
  // 顯示通知訊息
  echo "<script>
      alert('{$notification_message}');
      window.location.href = 'projectlevel-0420.php';
  </script>";
  exit;
}

// 如果是從資料庫載入專案，需要獲取描述
if (isset($_GET['load_project']) && !empty($_GET['load_project'])) {
    $projectName = $_GET['load_project'];
    $userID = $_SESSION['user_id'] ?? 0;
    
    try {
        // 獲取專案資料
        $stmt = $conn->prepare("
            SELECT DISTINCT ProjectName, UserNote 
            FROM pj_filters 
            WHERE UserID = ? AND ProjectName = ?
            GROUP BY ProjectName, UserNote
        ");
        $stmt->execute([$userID, $projectName]);
        $projectInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($projectInfo) {
            // 設置專案名稱和描述
            $_SESSION['current_project_name'] = $projectInfo['ProjectName'];
            $_SESSION['current_project_description'] = $projectInfo['UserNote'] ?? '';
        }
    } catch (PDOException $e) {
        // 處理錯誤
        echo "<script>console.error('載入專案描述失敗：{$e->getMessage()}');</script>";
    }
}

// 添加檢查機制（調試用）
if (isset($_GET['debug'])) {
  echo "<pre>";
  echo "POST 數據:\n";
  print_r($_POST);
  echo "\n篩選條件:\n";
  print_r($filters);
  echo "</pre>";
}
?>


<!-- HTML 主體 -->
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <title>Green Building Station</title>

  <!-- 引入 Bootstrap 5 -->
  <link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
  />
  <style>
    /* 移除預設整體置中的設定，改為左對齊 */
    body {
      margin-top: 100px; /* 確保 navbar 不會擋住主內容 */
      padding: 0;
      background: #f8f9fa;
    }

    .navbar-brand {
      font-weight: bold;
    }

    .table-container {
      background-color: #fff;
      border-radius: 6px;
      padding: 1rem;
    }
    /* 預留可以做條紋表格或其他表格樣式 */
    .custom-table thead th {
      background-color: #e9ecef;
    }
    /* 若有自訂欄位寬度、nav bar 顏色等，可在此調整 */
    .legend-title {
      font-weight: bold;
      margin-bottom: 1rem;
    }
    /* 1. 讓 ul 變成 flex container，移除預設清單樣式 */
    .filters-list {
      display: flex;       /* 水平排列 */
      flex-wrap: wrap;     /* 超出寬度時換行 */
      list-style: none;    /* 移除項目符號 */
      margin: 0;
      padding: 0;
      gap: 8px;            /* 方塊間距(瀏覽器不支援時，可改用 margin) */
    }
    /* 2. 讓每個 li 看起來像一個方塊 */
    .filters-list li {
      background-color: #f2f2f2;  /* 背景色 */
      border: 1px solid #ccc;     /* 灰色外框 */
      padding: 6px 12px;          /* 內距 */
      border-radius: 6px;         /* 圓角 */
      white-space: nowrap;        /* 避免多字被分行 */
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

<div class="container my-3">
<h1><?php echo __('green_building_performance_title'); ?></h1>

<!-- [B] 主要內容區：使用 container 讓整體寬度更易閱讀，但文字仍預設左對齊 -->
<div class="container my-3">
  <!-- [B-1] 篩選條件表單 -->
  <form method="GET" id="filterForm" class="mb-4">
    <div class="card">
      <div class="card-body">
      <h4 class="card-title legend-title"><?php echo __('create_filter_group'); ?></h4>
        <!-- 新增登入檢查 -->
        <?php if (!$isLoggedIn): ?>
          <div class="alert alert-warning">
              <?php echo __('loginRequired'); ?>
          </div>
        <?php else: ?>    
      <div class="row g-3 align-items-end">
          <!-- 類型下拉 -->
          <div class="col-sm-3">
              <label for="typeSelect" class="form-label"><?php echo __('type'); ?></label>
              <select name="type" id="typeSelect" class="form-select" onchange="onChangeType()">
                  <option value=""><?php echo __('please_select'); ?></option>
                  <?php
                  foreach ($typeConfig as $t => $cfg) {
                      $sel = ($t === $selected_type) ? 'selected' : '';
                      echo "<option value=\"$t\" $sel>$t</option>";
                  }
                  ?>
              </select>
          </div>
          <!-- 欄位下拉 -->
          <div class="col-sm-3">
              <label for="colSel" class="form-label"><?php echo __('column'); ?></label>
              <select name="col" id="colSel" class="form-select" onchange="onChangeCol()">
                  <option value=""><?php echo __('please_select'); ?></option>
                  <?php
                  foreach ($columns as $col) {
                      $sel = ($col === $selected_col) ? 'selected' : '';
                      echo "<option value=\"".htmlspecialchars($col)."\" $sel>".htmlspecialchars($col)."</option>";
                  }
                  ?>
              </select>
          </div>
          <!-- 值下拉 -->
          <div class="col-sm-3">
            <label for="valSel" class="form-label"><?php echo __('value'); ?></label>
            <select name="val" id="valSel" class="form-select">
                <option value=""><?php echo __('please_select'); ?></option>
                <?php
                foreach ($values as $v) {
                    // 處理區間值顯示 (格式：顯示值|實際起始值|實際結束值)
                    $valueToUse = $v;
                    $displayValue = $v;
                    
                    if (strpos($v, '|') !== false) {
                        $parts = explode('|', $v);
                        $displayValue = $parts[0]; // 取顯示值
                        $valueToUse = $parts[0];   // 保持與顯示值相同（處理將在後端進行）
                    }
                    
                    $sel = ($valueToUse === $selected_val) ? 'selected' : '';
                    echo "<option value=\"".htmlspecialchars($valueToUse)."\" $sel>".htmlspecialchars($displayValue)."</option>";
                }
                ?>
            </select>
        </div>
          <!-- 按鈕區 -->
          <div class="col-sm-3 d-flex flex-wrap gap-2">
              <button type="submit" name="add" value="1" class="btn btn-primary">
                <?php echo __('add_confirm'); ?>
              </button>
              <button type="submit" name="clear" value="1" class="btn btn-danger">
                <?php echo __('clear_all'); ?>
              </button>
          </div>
        </div>
      </div>
    </div>
  </form>

<!-- [B-2] 顯示已選篩選條件(累積) -->
<?php if (count($filters) > 0): ?>
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
      <?php if (isset($_SESSION['current_project_name']) && !empty($_SESSION['current_project_name'])): ?>
            <div class="mt-1  text-info fs-4 mb-2"> <!-- 使用 text-primary 改變顏色，fs-5 增加文字大小 -->
                <i class="fas fa-project-diagram"></i> 
                <strong><?php echo __('current_project'); ?> <?php echo htmlspecialchars($_SESSION['current_project_name']); ?></strong>
            </div>
        <?php endif; ?>
        <h5 class="card-title legend-title mb-0"><?php echo __('filtered_content'); ?></h5>
        </div>
      <button type="button" id="addDescriptionBtn" class="btn btn-outline-info btn-sm">
      <i class="fas fa-comment-alt"></i> 📝 <?php echo __('add_filter_description_title'); ?>
      </button>
    </div>
    
    <ul class="filters-list mb-4">
        <?php foreach ($filters as $idx => $filter): ?>
            <li class="mb-2">
                <?php echo htmlspecialchars($filter['type']); ?> /
                <?php echo htmlspecialchars($filter['col']); ?> /
                <?php
                $displayVal = $filter['val'];
                // 只顯示區間的顯示部分（如果有管道符號）
                if (strpos($displayVal, '|') !== false) {
                    $parts = explode('|', $displayVal);
                    $displayVal = $parts[0];
                }
                echo htmlspecialchars($displayVal);
                ?>

                <!-- 刪除連結 -->
                <a href="projectlevel-new.php?remove=<?= $idx ?>" style="color:red; margin-left:10px; text-decoration: none;">
                    X
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    
    <form method="POST" class="mt-4" id="saveFilterForm">
      <!-- 新增隱藏欄位 -->
      <input type="hidden" name="save_filters" value="1">
      <input type="hidden" name="filter_description" id="filterDescription" value="">
      <div class="input-group mb-3">
      <input type="text" name="filter_name" class="form-control d-none" placeholder="<?php echo __('filter_group_name'); ?>" required>
      <button type="button" id="toggleFilterInput" class="btn btn-success">💾 <?php echo __('save'); ?></button>
      </div>
    </form>
  </div>
</div>

<!-- 彈出的描述輸入對話框 (自定義樣式) -->
<div id="descriptionModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); overflow: auto;">
  <div style="background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 50%; border-radius: 5px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
      <h5 style="margin: 0;"><?php echo __('add_filter_description_title'); ?></h5>>
      <span id="closeModal" style="color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
    </div>
    <div style="margin-bottom: 15px;">
    <textarea id="modalDescription" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; min-height: 100px;" placeholder="<?php echo __('filter_description_placeholder'); ?>"></textarea>
    </div>
    <div style="text-align: right;">
    <button type="button" id="cancelDescription" style="padding: 6px 12px; margin-right: 10px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;"><?php echo __('cancel'); ?></button>
    <button type="button" id="saveDescription" style="padding: 6px 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;"><?php echo __('confirm'); ?></button>
    </div>
  </div>
</div>

<!-- 懸浮通知元素 -->
<div id="notification-container" style="display: none; position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 300px; max-width: 400px;"></div>

<!-- 如果有通知消息，顯示懸浮通知 -->
<?php if (!empty($notification_type) && !empty($notification_message)): ?>
  <script>
document.addEventListener('DOMContentLoaded', function() {
    // 創建通知元素
    showNotification('<?php echo $notification_type; ?>', '<?php echo addslashes($notification_message); ?>');
});

// 懸浮通知函數
function showNotification(type, message) {
    const container = document.getElementById('notification-container');
    
    // 設置通知樣式
    let bgColor, textColor, icon;
    if (type === 'success') {
        bgColor = '#d4edda';
        textColor = '#155724';
        icon = '<i class="fas fa-check-circle"></i>';
    } else if (type === 'error') {
        bgColor = '#f8d7da';
        textColor = '#721c24';
        icon = '<i class="fas fa-exclamation-circle"></i>';
    } else {
        bgColor = '#d1ecf1';
        textColor = '#0c5460';
        icon = '<i class="fas fa-info-circle"></i>';
    }
    
    // 創建通知HTML
    const notification = document.createElement('div');
    notification.style.backgroundColor = bgColor;
    notification.style.color = textColor;
    notification.style.padding = '15px';
    notification.style.marginBottom = '10px';
    notification.style.borderRadius = '5px';
    notification.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
    notification.style.display = 'flex';
    notification.style.justifyContent = 'space-between';
    notification.style.alignItems = 'center';
    notification.innerHTML = `
        <div style="display: flex; align-items: center;">
            <span style="margin-right: 10px;">${icon}</span>
            <span>${message}</span>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: ${textColor}; cursor: pointer; font-size: 20px;">&times;</button>
    `;
    
    // 加入到容器
    container.style.display = 'block';
    container.appendChild(notification);
    
    // 設置自動消失
    setTimeout(() => {
        notification.style.transition = 'opacity 0.5s ease-out';
        notification.style.opacity = '0';
        setTimeout(() => {
            notification.remove();
            // 如果沒有更多通知，隱藏容器
            if (container.children.length === 0) {
                container.style.display = 'none';
            }
        }, 500);
    }, 5000); // 5秒後自動消失
}
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const toggleButton = document.getElementById('toggleFilterInput');
  const filterNameInput = document.querySelector('input[name="filter_name"]');
  const saveFilterForm = document.getElementById('saveFilterForm');
  const addDescriptionBtn = document.getElementById('addDescriptionBtn');
  const filterDescriptionInput = document.getElementById('filterDescription');
  const descriptionModal = document.getElementById('descriptionModal');
  const closeModal = document.getElementById('closeModal');
  const cancelDescription = document.getElementById('cancelDescription');
  const saveDescriptionBtn = document.getElementById('saveDescription');
  
  // 如果SESSION中有當前專案名稱，則預先填入
  <?php if (isset($_SESSION['current_project_name']) && !empty($_SESSION['current_project_name'])): ?>
  filterNameInput.value = '<?php echo addslashes($_SESSION['current_project_name']); ?>';
  <?php endif; ?>
  
  // 處理儲存篩選內容按鈕
  toggleButton.addEventListener('click', function() {
    if (filterNameInput.classList.contains('d-none')) {
      // 顯示輸入欄位
      filterNameInput.classList.remove('d-none');
      toggleButton.textContent = "確認儲存";
      toggleButton.classList.replace('btn-success', 'btn-primary');
    } else {
      // 提交表單前檢查
      if (filterNameInput.value.trim() !== '') {
        // 檢查是否需要確認覆蓋
        const projectName = filterNameInput.value.trim();
        const currentProjectName = '<?php echo addslashes($_SESSION['current_project_name'] ?? ""); ?>';
        
        // 不管是否為當前專案，都檢查是否已存在
        fetch('projectlevel-0420.php?check_project=1&name=' + encodeURIComponent(projectName))
          .then(response => response.json())
          .then(data => {
            if (data.exists) {
              // 即使是當前專案，也顯示確認對話框
              if (confirm('已有篩選內容「' + projectName + '」，是否要進行覆蓋?')) {
                // 用戶確認覆蓋，添加覆蓋標記
                const overwriteInput = document.createElement('input');
                overwriteInput.type = 'hidden';
                overwriteInput.name = 'overwrite';
                overwriteInput.value = '1';
                saveFilterForm.appendChild(overwriteInput);
                saveFilterForm.submit();
              }
            } else {
              // 不存在同名專案，直接提交
              saveFilterForm.submit();
            }
          })
          .catch(error => {
            console.error('檢查專案時出錯:', error);
            // 發生錯誤時，為安全起見，顯示一般錯誤提示
            alert('檢查專案時發生錯誤，請重試');
          });
      } else {
        alert('請輸入篩選組別名稱！');
      }
    }
  });
  
  // 描述相關功能
  addDescriptionBtn.addEventListener('click', function() {
    // 如果已有描述，則在對話框中顯示
    const currentDescription = filterDescriptionInput.value || '<?php echo addslashes($_SESSION['current_project_description'] ?? ''); ?>';
    document.getElementById('modalDescription').value = currentDescription;
    descriptionModal.style.display = 'block';
  });
  
  closeModal.addEventListener('click', function() {
    descriptionModal.style.display = 'none';
  });
  
  cancelDescription.addEventListener('click', function() {
    descriptionModal.style.display = 'none';
  });
  
  window.addEventListener('click', function(event) {
    if (event.target === descriptionModal) {
      descriptionModal.style.display = 'none';
    }
  });
  
  saveDescriptionBtn.addEventListener('click', function() {
    const description = document.getElementById('modalDescription').value;
    filterDescriptionInput.value = description;
    descriptionModal.style.display = 'none';
    
    // 提供視覺反饋
    if (description.trim() !== '') {
      addDescriptionBtn.className = 'btn btn-info btn-sm';
      addDescriptionBtn.innerHTML = '<i class="fas fa-check"></i> 已添加描述';
    } else {
      addDescriptionBtn.className = 'btn btn-outline-info btn-sm';
      addDescriptionBtn.innerHTML = '<i class="fas fa-comment-alt"></i> 新增描述';
    }
  });
  
  // 初始化時如果有描述則更新按鈕狀態
  const savedDescription = '<?php echo addslashes($_SESSION['current_project_description'] ?? ''); ?>';
  if (savedDescription.trim() !== '') {
    filterDescriptionInput.value = savedDescription;
    addDescriptionBtn.className = 'btn btn-info btn-sm';
    addDescriptionBtn.innerHTML = '<i class="fas fa-check"></i> 已添加描述';
  }
});
</script>

<?php endif; ?>

<!-- <script>
  document.getElementById('toggleFilterInput').addEventListener('click', function() {
    var input = document.querySelector('input[name="filter_name"]');
    // 如果輸入欄位還是隱藏狀態，則顯示它
    if (input.classList.contains('d-none')) {
      input.classList.remove('d-none');
      input.focus();
    } else {
      // 如果已顯示，檢查是否有填寫值再送出
      if (input.value.trim() !== '') {
        // 將按鈕類型改為 submit，讓後端能收到 save_filters 參數
        this.setAttribute('type', 'submit');
        document.getElementById('saveFilterForm').submit();
      } else {
        alert('請輸入篩選組別名稱');
        input.focus();
      }
    }
  });
</script> -->

  <!-- [C] 顯示查詢結果 (含分頁) -->
  <h5 class="legend-title"><?php echo __('query_cost_table_results'); ?></h5>
  
  <?php if ($totalRecords === 0): ?>
      <div class="alert alert-warning">
          <?php echo __('no_data_found'); ?>
      </div>
  <?php else: ?>
      <div class="mb-3">
      <p class="d-inline me-2">
        <?php echo __('total_records'); ?> <strong><?php echo $totalRecords; ?></strong> <?php echo __('records'); ?>，
        <?php echo __('total_pages'); ?> <strong><?php echo $totalPages; ?></strong> <?php echo __('pages'); ?>，
        <?php echo __('current_page'); ?> <strong><?php echo $page; ?></strong> <?php echo __('page'); ?>
      </p>
        <span><?php echo __('change_page_size'); ?>：</span>
        <select id="pageSizeSelect" class="form-select d-inline-block w-auto" onchange="changePageSize()">
          <?php foreach ($allowedPageSizes as $sz): ?>
              <option value="<?php echo $sz; ?>" <?php echo ($sz == $recordsPerPage) ? 'selected' : ''; ?>>
                <?php echo $sz; ?>
              </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- [C-2] 顯示資料表 -->
      <div class="table-responsive">
        <table class="table table-bordered custom-table">
          <thead>
            <tr>
              <?php foreach (array_keys($rows[0]) as $field): ?>
                <th><?php echo htmlspecialchars($field); ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <?php foreach ($r as $val): ?>
                <td><?php echo htmlspecialchars($val); ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- [C-3] 分頁導覽 -->
      <?php echo buildPagination($page, $totalPages, $recordsPerPage); ?>
  <?php endif; ?>
  <hr>
</div><!-- /.container -->

<!-- [D] 依「類型 + 方案編號」做查詢 -->
<div class="container my-3">
    <div class="card">
      <div class="card-body">
      <h5 class="card-title legend-title"><?php echo __('query_by_type_and_plan'); ?></h5>
        <form method="GET">
          <div class="row g-3 align-items-end">
            <!-- (A) 類型下拉 -->
            <div class="col-sm-3">
              <label for="lookup_type" class="form-label"><?php echo __('type_label'); ?></label>
              <select name="lookup_type" id="lookup_type" class="form-select">
                  <option value=""><?php echo __('please_select'); ?></option>
                <?php foreach ($typeConfig as $t => $cfg): 
                      $selected = ($t === $lookup_type) ? 'selected' : ''; ?>
                  <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $selected; ?>>
                    <?php echo htmlspecialchars($t); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- (B) 輸入方案編號 (假設方案欄位都是數字; 若是文字也可用 text) -->
            <div class="col-sm-3">
                <label for="lookup_plan" class="form-label"><?php echo __('plan_number_label'); ?></label>
                <input type="number" name="lookup_plan" id="lookup_plan" 
                      class="form-control"
                      value="<?php echo htmlspecialchars($lookup_plan); ?>">
            </div>
            <!-- (C) 查詢按鈕 -->
            <div class="col-sm-3">
            <button type="submit" class="btn btn-primary"><?php echo __('query'); ?></button>
            </div>
          </div>
        </form>
      </div>
    </div>
    
    
    <br>
    
    <!-- [D-1] 如果使用者有選擇類型且填了方案編號，則執行查詢 -->
    <?php
    if (!empty($lookup_type) && !empty($lookup_plan)) {
      // 檢查有無定義這個類型
      if (isset($typeConfig[$lookup_type])) {
        // 拿對應的資料表名稱
        $tableName = $typeConfig[$lookup_type]['tableName'];
        
        // 查詢該表 WHERE 方案 = :p
        $sql = "SELECT DISTINCT * FROM [dbo].[$tableName] WHERE [方案] = :p";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':p', $lookup_plan, PDO::PARAM_INT);
        $stmt->execute();
        $resultRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo '<h5 class="legend-title">' . sprintf(__('query_table_results'), $tableName) . '</h5>';
        if ($resultRows) {
          // 顯示表格
          // echo '<div class="card mt-3">';
          // echo '<div class="card-body">';
          echo '<div class="table-responsive">';
          echo '<table class="table table-bordered custom-table">';
          // 表頭
          echo '<thead><tr>';
          foreach (array_keys($resultRows[0]) as $colName) {
              echo '<th>' . htmlspecialchars($colName) . '</th>';
          }
          echo '</tr></thead>';
          // 資料列 (假設只會有一筆 or 多筆都列出)
          echo '<tbody>';
          foreach ($resultRows as $row) {
              echo '<tr>';
              foreach ($row as $key => $val) {
                  // 若欄位名稱是 "編號" 或 "方案"，就直接輸出，不處理
                  if ($key === '編號' || $key === '方案') {
                      echo '<td>' . $val . '</td>';
                      continue;
                  }
                  // 檢查是否為數字類型
                  if (is_numeric($val)) {
                      // 格式化數字，顯示到小數點後兩位
                      echo '<td>' . number_format((float)$val, 2, '.', ',') . '</td>';
                  } else {
                      echo '<td>' . htmlspecialchars($val) . '</td>';
                  }
              }
              echo '</tr>';
          }
          echo '</tbody></table></div>';          
          // echo '</div></div>';
        } else {
          // 無資料
          echo '<p class="text-muted">' . __('no_matching_plan_data') . '</p>';
      }
      } else {
          // 非法類型
          echo '<h5 class="legend-title">' . __('incorrect_type_selection') . '</h5>';
      }
      }
      else {
          echo '<h5 class="legend-title">' . __('no_filter_selected') . '</h5>';
      }
    ?>
</div><!-- /.container -->
</div>
<!-- 引入 Bootstrap 5 JS (若需下拉功能) -->
<script
  src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
</script>

<script>
function onChangeType() {
    // 一旦改了「類型」，就重置 col / val
    document.getElementById('colSel').value = "";
    document.getElementById('valSel').value = "";
    document.getElementById('filterForm').submit();
}
function onChangeCol() {
    // 一旦改了「欄位」，就重置 val
    document.getElementById('valSel').value = "";
    document.getElementById('filterForm').submit();
}
// 更改每頁筆數
function changePageSize() {
    const sel = document.getElementById('pageSizeSelect');
    const limit = sel.value;
    const url = new URL(window.location.href);
    url.searchParams.set('limit', limit);
    // 換頁後回到第一頁
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}
</script>

<!-- 下行為檢查是否登入已使用專案功能的程式結束碼 -->
<?php endif; ?>

</body>
</html>

<?php
/****************************************************************************
 * [11] 分頁連結生成函式 (維持 Bootstrap 樣式)
 ****************************************************************************/
function buildPagination($currentPage, $totalPages, $limit) {
    $qs    = $_GET;
    $range = 3;
    $start = max(1, $currentPage - $range);
    $end   = min($totalPages, $currentPage + $range);

    ob_start();
    echo "<nav aria-label='Page navigation'>";
    echo "<ul class='pagination justify-content-center'>";

    // 上一頁
    if ($currentPage > 1) {
        $qs['page']  = $currentPage - 1;
        $qs['limit'] = $limit;
        $prevUrl = "?".http_build_query($qs);
        echo "<li class='page-item'><a class='page-link' href='{$prevUrl}'>上一頁</a></li>";
    }

    // 第一頁
    if ($start > 1) {
        $qs['page']  = 1;
        $qs['limit'] = $limit;
        $firstUrl = "?".http_build_query($qs);
        echo "<li class='page-item'><a class='page-link' href='{$firstUrl}'>1</a></li>";
        if ($start > 2) {
            echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
        }
    }

    // 中間頁
    for ($i = $start; $i <= $end; $i++) {
        $qs['page']  = $i;
        $qs['limit'] = $limit;
        $pageUrl = "?".http_build_query($qs);
        $activeClass = ($i == $currentPage) ? ' active' : '';
        echo "<li class='page-item{$activeClass}'><a class='page-link' href='{$pageUrl}'>{$i}</a></li>";
    }

    // 最後頁
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
        }
        $qs['page'] = $totalPages;
        $lastUrl = "?".http_build_query($qs);
        echo "<li class='page-item'><a class='page-link' href='{$lastUrl}'>{$totalPages}</a></li>";
    }

    // 下一頁
    if ($currentPage < $totalPages) {
        $qs['page']  = $currentPage + 1;
        $qs['limit'] = $limit;
        $nextUrl = "?".http_build_query($qs);
        echo "<li class='page-item'><a class='page-link' href='{$nextUrl}'>下一頁</a></li>";
    }

    echo "</ul>";
    echo "</nav>";
    return ob_get_clean();
}
