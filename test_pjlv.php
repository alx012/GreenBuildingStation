<?php
/****************************************************************************
 * [0] 開啟 Session，方便累積篩選條件, 利用「HTTP_REFERER」判斷是否從外部網站回來並清空
 ****************************************************************************/
session_start();

$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

// 只要不是從本頁 (或特定條件) 回來，就清除 session
if (!empty($referer)) {
    // 取得路徑部份，例如 /projectlevel.php
    $refererPath = parse_url($referer, PHP_URL_PATH);

    // 檢查若不是從本頁連回，就清除
    // (路徑依實際情況調整; 若你的檔名是 /projectlevel.php 就比對這個)
    if ($refererPath !== '/projectlevel-past.php') {
        unset($_SESSION['filters']);
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

function getDistinctValues(PDO $conn, $tableName, $colName) {
    // 防止中括號衝突，將欄位名以中括號包起
    $colSafe = "[" . str_replace(["[","]"], "", $colName) . "]";
    $sql = "
        SELECT DISTINCT $colSafe AS val
        FROM [dbo].[$tableName]
        ORDER BY $colSafe
    ";
    return $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN);
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
    header("Location: projectlevel-past.php");
    exit;
}

if (isset($_GET['remove'])) {
  $removeIndex = (int) $_GET['remove']; // 強制轉 int 比較保險
  if (isset($_SESSION['filters'][$removeIndex])) {
      // 移除該索引
      array_splice($_SESSION['filters'], $removeIndex, 1);
  }
  header("Location: projectlevel-past.php");
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
    header("Location: projectlevel-past.php"); // 避免 F5 重覆送出
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

        // 子查詢 (利用 IN)
        $tmp = "c.$costColName IN (
                    SELECT [方案]
                    FROM [dbo].[$tableName]
                    WHERE $colSafe = :VAL_$paramIndex
                )";

        $whereParts[]               = $tmp;
        $bindParams["VAL_$paramIndex"] = $v;
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
// 讀取下拉選單數據
function fetchUniqueValues($conn, $tableName, $colName) {
    $sql = "SELECT DISTINCT [$colName] AS val FROM [dbo].[$tableName] ORDER BY [$colName]";
    return $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}

// 接收篩選條件
$selected_type = $_GET['type'] ?? '';
$selected_col  = $_GET['col'] ?? '';
$selected_val  = $_GET['val'] ?? '';
$addFilter     = isset($_GET['add']);
$clearAll      = isset($_GET['clear']);

// 清除篩選
if ($clearAll) {
    unset($_SESSION['filters']);
    header("Location: projectlevel.php");
    exit;
}

// 新增篩選條件
if ($addFilter && $selected_type && $selected_col && $selected_val) {
    $_SESSION['filters'][] = ['type' => $selected_type, 'col' => $selected_col, 'val' => $selected_val];
    header("Location: projectlevel-past.php");
    exit;
}

// 載入使用者的篩選條件
$filters = $_SESSION['filters'] ?? [];

// 儲存篩選條件到資料庫
if (isset($_GET['load_filter_group'])) {
    // 檢查是否登入
    if (!isset($_SESSION['user_id'])) {
        echo "<script>
            alert('請先登入帳號以使用該功能');
            window.location.href='login.php';  // 導向登入頁面
        </script>";
        exit;
    }

    $userID = $_SESSION['user_id'];
    $projectName = $_GET['load_filter_group'];
    
    // 修改 SQL 查詢，同時撈取 UserNote
    $stmt = $conn->prepare("
        SELECT 
            Type as type,
            ColumnName as col,
            Value as val,
            (SELECT TOP 1 UserNote 
             FROM pj_filters 
             WHERE UserID = f.UserID 
               AND ProjectName = f.ProjectName 
               AND UserNote IS NOT NULL) as user_note
        FROM pj_filters f
        WHERE UserID = :userID AND ProjectName = :projectName
        ORDER BY FilterID
    ");
    
    try {
        $stmt->execute([
            ':userID' => $userID,
            ':projectName' => $projectName
        ]);
        $loadedFilters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($loadedFilters)) {
            echo "<script>
                alert('查無可匯入的篩選條件！');
                window.location.href='projectlevel-past.php';  // 回到當前頁面
            </script>";
            exit;
        }
        
        // 重新格式化讀取的資料
        $formattedFilters = [];
        $projectNote = null;
        
        foreach ($loadedFilters as $filter) {
            if (!empty($filter['type']) && !empty($filter['col']) && !empty($filter['val'])) {
                $formattedFilters[] = [
                    'type' => $filter['type'],
                    'col'  => $filter['col'],
                    'val'  => $filter['val']
                ];
                
                // 儲存第一個非空的 UserNote
                if ($projectNote === null && !empty($filter['user_note'])) {
                    $projectNote = $filter['user_note'];
                }
            }
        }
        
        if (empty($formattedFilters)) {
            echo "<script>
                alert('載入的篩選條件格式不正確！');
                window.location.href='projectlevel-past.php';  // 回到當前頁面
            </script>";
            exit;
        }
        
        // 更新 session，設定篩選條件、當前專案名稱和專案備註
        $_SESSION['filters'] = $formattedFilters;
        $_SESSION['current_project_name'] = $projectName;
        
        // 如果有 UserNote 則儲存，沒有則移除
        if ($projectNote !== null) {
            $_SESSION['current_project_note'] = $projectNote;
        } else {
            unset($_SESSION['current_project_note']);
        }
        
        echo "<script>
            alert('成功匯入篩選組別：" . addslashes($projectName) . "！'); 
            window.location.href='projectlevel-past.php';
        </script>";
        
    } catch (PDOException $e) {
        echo "<script>
            alert('載入失敗：" . addslashes($e->getMessage()) . "');
            window.location.href='projectlevel.php';  // 回到當前頁面
        </script>";
    }
}

//載入篩選組別
if (isset($_GET['load_filter_group'])) {
    // 檢查是否登入
    if (!isset($_SESSION['user_id'])) {
        echo "<script>
            alert('請先登入帳號以使用該功能');
            window.location.href='login.php';  // 導向登入頁面
        </script>";
        exit;
    }

    $userID = $_SESSION['user_id'];
    $projectName = $_GET['load_filter_group'];
    $gbdProjectId = isset($_SESSION['gbd_project_id']) ? $_SESSION['gbd_project_id'] : null;
    
    // 修改 SQL 查詢，根據使用者ID、篩選組別名稱和綠建築專案ID取得篩選條件
    $sql = "
        SELECT 
            Type as type,
            ColumnName as col,
            Value as val 
        FROM pj_filters 
        WHERE UserID = :userID AND ProjectName = :projectName
    ";
    
    $params = [
        ':userID' => $userID,
        ':projectName' => $projectName
    ];
    
    // 如果有綠建築專案ID，加入過濾條件
    if ($gbdProjectId) {
        $sql .= " AND building_id = :buildingId";
        $params[':buildingId'] = $gbdProjectId;
    }
    
    $sql .= " ORDER BY FilterID";
    
    $stmt = $conn->prepare($sql);
    
    try {
        $stmt->execute($params);
        $loadedFilters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($loadedFilters)) {
            echo "<script>
                alert('查無可匯入的篩選條件！');
                window.location.href='projectlevel-past.php';  // 回到當前頁面
            </script>";
            exit;
        }
        
        // 重新格式化讀取的資料
        $formattedFilters = [];
        foreach ($loadedFilters as $filter) {
            if (!empty($filter['type']) && !empty($filter['col']) && !empty($filter['val'])) {
                $formattedFilters[] = [
                    'type' => $filter['type'],
                    'col'  => $filter['col'],
                    'val'  => $filter['val']
                ];
            }
        }
        
        if (empty($formattedFilters)) {
            echo "<script>
                alert('載入的篩選條件格式不正確！');
                window.location.href='projectlevel-past.php';  // 回到當前頁面
            </script>";
            exit;
        }
        
        // 更新 session，設定篩選條件及當前專案名稱
        $_SESSION['filters'] = $formattedFilters;
        $_SESSION['current_project_name'] = $projectName;  // 新增此行
        
        echo "<script>
            alert('成功匯入篩選組別：" . addslashes($projectName) . "！'); 
            window.location.href='projectlevel-past.php';
        </script>";
        
    } catch (PDOException $e) {
        echo "<script>
            alert('載入失敗：" . addslashes($e->getMessage()) . "');
            window.location.href='projectlevel.php';  // 回到當前頁面
        </script>";
    }
}

// 儲存usernote
if (isset($_POST['save_project_note'])) {
    // 檢查是否登入
    if (!isset($_SESSION['user_id'])) {
        echo "請先登入";
        exit;
    }

    $userID = $_SESSION['user_id'];
    $projectName = $_SESSION['current_project_name'];
    $projectNote = $_POST['project_note'] ?? '';

    // 更新資料庫中的 UserNote
    $stmt = $conn->prepare("
        UPDATE pj_filters 
        SET UserNote = :note 
        WHERE UserID = :userID 
        AND ProjectName = :projectName
    ");

    try {
        $stmt->execute([
            ':note' => $projectNote,
            ':userID' => $userID,
            ':projectName' => $projectName
        ]);

        // 更新 SESSION
        if ($projectNote) {
            $_SESSION['current_project_note'] = $projectNote;
        } else {
            unset($_SESSION['current_project_note']);
        }

        echo "儲存成功";
    } catch (PDOException $e) {
        echo "儲存失敗：" . $e->getMessage();
    }
    exit;
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
            background: linear-gradient(to bottom, rgba(255, 255, 255, 0.8), rgba(255, 255, 255, 0) 80%), 
                        url('https://i.imgur.com/WJGtbFT.jpeg');
            background-size: 100% 100%; /* 使背景圖片填滿整個背景區域 */
            background-position: center; /* 背景圖片居中 */
            background-repeat: no-repeat; /* 不重複背景圖片 */
            background-attachment: fixed; /* 背景固定在視口上 */
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

    .card-header {
        background-color: #f8f9fa;
        border-bottom: 1px solid rgba(0,0,0,.125);
    }

    .project-list {
        max-height: 700px;
        overflow-y: auto;
    }

    .badge {
        font-weight: normal;
    }

    .hidden-initially {
      display: none;
    }

    .project-card {
        cursor: pointer;
        transition: background-color 0.2s, transform 0.1s;
        border-left: 4px solid #769a76;
    }

    .project-card:hover {
        background-color: #f0f0f0;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    /* 預設隱藏按鈕 */
    .load-filter-btn {
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s ease-in-out, visibility 0.2s ease-in-out;
    }

    /* 滑鼠懸停時顯示按鈕 */
    .project-card:hover .load-filter-btn {
        opacity: 1;
        visibility: visible;
    }

    /* 新增：分頁控制的樣式 */
    .pagination {
        display: flex;
        justify-content: center;
        margin-top: 20px;
        margin-bottom: 20px;
    }

    .pagination button {
        margin: 0 5px;
        padding: 5px 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        cursor: pointer;
        background-color: #fff;
    }

    .pagination button.active {
        background-color: #769a76;
        color: #fff;
    }

    .load-filter-btn {
        background-color: #769a76 !important; /* 綠色 */
        border-color: #769a76 !important;
        color: #ffffff !important; /* 文字顏色設為白色 */
    }

    .load-filter-btn:hover {
        background-color: #5e7d5e !important; /* 懸停時變深 */
        border-color: #5e7d5e !important;
    }


  </style>
</head>
<body>

<!--引用include() -->
<?php include('navbar.php'); ?>

<!-- HTML 主體部分修改 -->
<div class="container my-3">

<!-- 歷史專案區域 - 初始顯示 -->
<div class="card mb-4" id="history-section">
    <div id="section-card-list">
        <div class="section-card">
            <div class="filter-project-list-section" id="filterprojectListSection">
            <h2 class="card-header"><?php echo __('project_badge_history'); ?></h2>
                <div id="projectList" class="project-list p-3">
                    <?php
                    // 在顯示專案列表前添加分頁設定 (添加到歷史專案區域之前)
                    $projectsPerPage = isset($_GET['projects_limit']) ? (int)$_GET['projects_limit'] : 5; // 預設每頁顯示5個專案
                    $projectsPage = isset($_GET['projects_page']) ? (int)$_GET['projects_page'] : 1;
                    if ($projectsPage < 1) $projectsPage = 1;

                    // 替換原來的專案列表查詢，增加分頁功能
                    if (isset($_SESSION['user_id'])) {
                        $userID = $_SESSION['user_id'];
                        
                        // 檢查是否有當前綠建築專案ID
                        $gbdProjectId = isset($_SESSION['gbd_project_id']) ? $_SESSION['gbd_project_id'] : null;
                        
                        // 先獲取總專案數 - 加入 building_id 過濾條件
                        $countSql = "
                            SELECT COUNT(DISTINCT ProjectName) as total
                            FROM pj_filters 
                            WHERE UserID = :userID
                        ";
                        
                        $countParams = [':userID' => $userID];
                        
                        // 如果有綠建築專案ID，加入過濾條件
                        if ($gbdProjectId) {
                            $countSql .= " AND building_id = :buildingId";
                            $countParams[':buildingId'] = $gbdProjectId;
                        }
                        
                        $countStmt = $conn->prepare($countSql);
                        $countStmt->execute($countParams);
                        $totalProjects = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                        
                        $totalProjectPages = ($totalProjects > 0) ? ceil($totalProjects / $projectsPerPage) : 1;
                        if ($projectsPage > $totalProjectPages) $projectsPage = $totalProjectPages;
                        $projectsOffset = ($projectsPage - 1) * $projectsPerPage;
                        
                        // 查詢該使用者的所有篩選組別，加入 building_id 過濾條件
                        $sql = "
                            SELECT 
                                ProjectName,
                                MIN(CreatedAt) as CreatedAt,
                                COUNT(*) as FilterCount,
                                STRING_AGG(Type + ': ' + Value, '、') as FilterDetails
                            FROM pj_filters 
                            WHERE UserID = :userID
                        ";
                        
                        $params = [':userID' => $userID];
                        
                        // 如果有綠建築專案ID，加入過濾條件
                        if ($gbdProjectId) {
                            $sql .= " AND building_id = :buildingId";
                            $params[':buildingId'] = $gbdProjectId;
                        }
                        
                        // 添加分組和排序條件
                        $sql .= " 
                            GROUP BY ProjectName
                            ORDER BY MIN(CreatedAt) DESC
                            OFFSET :offset ROWS
                            FETCH NEXT :limit ROWS ONLY
                        ";
                        
                        $stmt = $conn->prepare($sql);
                        
                        try {
                            foreach ($params as $key => $value) {
                                $stmt->bindValue($key, $value);
                            }
                            $stmt->bindValue(':offset', $projectsOffset, PDO::PARAM_INT);
                            $stmt->bindValue(':limit', $projectsPerPage, PDO::PARAM_INT);
                            $stmt->execute();
                            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($projects)) {
                                if ($gbdProjectId) {
                                    echo "<div class='alert alert-info'>當前綠建築專案下尚無儲存的篩選組別</div>";
                                } else {
                                    echo "<div class='alert alert-info'>尚無儲存的篩選組別</div>";
                                }
                            } 
                            else {
                                // 顯示專案列表
                                foreach ($projects as $project) {
                                    $projectName = htmlspecialchars($project['ProjectName']);
                                    $createdAt = date('Y-m-d H:i', strtotime($project['CreatedAt']));
                                    $filterCount = $project['FilterCount'];
                                    $filterDetails = htmlspecialchars($project['FilterDetails']);
                                    ?>
                                      <div class="card mb-3 project-card">
                                          <div class="card-body">
                                              <div class="d-flex justify-content-between align-items-start">
                                                  <div>
                                                      <h5 class="card-title"><?php echo $projectName; ?></h5>
                                                        <div class="d-flex align-items-center mt-2">
                                                            <span class="badge bg-info"><?php echo __('filter_count'); ?>：<?php echo $filterCount; ?></span>
                                                            <div class="mx-2">|</div>
                                                            <small class="text-muted"><?php echo __('created_at'); ?>：<?php echo $createdAt; ?></small>
                                                        </div>
                                                  </div>
                                                  <div class="ms-3 load-filter-container">
                                                    <a href="projectlevel-past.php?load_filter_group=<?php echo urlencode($projectName); ?>" 
                                                    class="btn btn-primary btn-sm load-filter-btn">
                                                    <?php echo __('load_this_filter'); ?>
                                                    </a>
                                                  </div>
                                              </div>
                                          </div>
                                      </div>
                                    <?php
                                }
                                
                                // 添加專案列表的分頁導航
                                echo buildProjectsPagination($projectsPage, $totalProjectPages, $projectsPerPage);
                            }
                        } catch (PDOException $e) {
                            echo "<div class='alert alert-danger'>載入歷史記錄時發生錯誤：" . htmlspecialchars($e->getMessage()) . "</div>";
                        }
                    } else {
                        echo "<div class='alert alert-warning'>請先登入以查看已儲存的篩選組別</div>";
                    }

                    /**
                     * 專案列表分頁導航生成函式 (調整為符合自定義樣式)
                     */
                    function buildProjectsPagination($currentPage, $totalPages, $limit) {
                      $qs = $_GET;
                      $range = 3;
                      $start = max(1, $currentPage - $range);
                      $end = min($totalPages, $currentPage + $range);

                      ob_start();
                      echo "<div class='pagination'>";

                      // 上一頁
                      if ($currentPage > 1) {
                        $qs['projects_page'] = $currentPage - 1;
                        $qs['projects_limit'] = $limit;
                        $prevUrl = "?".http_build_query($qs);
                        echo "<button onclick=\"location.href='{$prevUrl}'\">" . __('previous_page') . "</button>";
                    }

                      // 第一頁
                      if ($start > 1) {
                          $qs['projects_page'] = 1;
                          $qs['projects_limit'] = $limit;
                          $firstUrl = "?".http_build_query($qs);
                          echo "<button onclick=\"location.href='{$firstUrl}'\">1</button>";
                          if ($start > 2) {
                              echo "<span>...</span>";
                          }
                      }

                      // 中間頁
                      for ($i = $start; $i <= $end; $i++) {
                          $qs['projects_page'] = $i;
                          $qs['projects_limit'] = $limit;
                          $pageUrl = "?".http_build_query($qs);
                          $activeClass = ($i == $currentPage) ? ' active' : '';
                          echo "<button class='{$activeClass}' onclick=\"location.href='{$pageUrl}'\">{$i}</button>";
                      }

                      // 最後頁
                      if ($end < $totalPages) {
                          if ($end < $totalPages - 1) {
                              echo "<span>...</span>";
                          }
                          $qs['projects_page'] = $totalPages;
                          $qs['projects_limit'] = $limit;
                          $lastUrl = "?".http_build_query($qs);
                          echo "<button onclick=\"location.href='{$lastUrl}'\">{$totalPages}</button>";
                      }

                    // 下一頁
                    if ($currentPage < $totalPages) {
                        $qs['projects_page'] = $currentPage + 1;
                        $qs['projects_limit'] = $limit;
                        $nextUrl = "?".http_build_query($qs);
                        echo "<button onclick=\"location.href='{$nextUrl}'\">" . __('next_page') . "</button>";
                    }

                      echo "</div>";
                      return ob_get_clean();
                    }
                    ?>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- 其他所有部分初始隱藏 - 添加 hidden-initially 類 -->
<div id="filter-content-section" class="hidden-initially">
    <!-- [B-2] 顯示已選篩選條件(累積) -->
    <div class="card mb-4">
      <div class="card-body">
      <?php if (isset($_SESSION['current_project_name']) && !empty($_SESSION['current_project_name'])): ?>
            <div class="d-flex justify-content-between align-items-center">
                <div class="mt-1 text-info fs-4 mb-2">
                    <i class="fas fa-project-diagram"></i> 
                    <strong><?php echo __('current_project'); ?> <?php echo htmlspecialchars($_SESSION['current_project_name']); ?></strong>
                </div>
                <button type="button" class="btn btn-sm btn-outline-info me-2" id="openDescriptionBtn">
                    📝 <?php echo __('filter_description_btn'); ?>
                </button>
            </div>
        <?php endif; ?>
        <?php if (count($filters) > 0): ?>
        <h5 class="card-title legend-title mb-3"><?php echo __('filtered_content'); ?></h5>
            <ul class="filters-list mb-4">
            <?php foreach ($filters as $idx => $filter): ?>
              <li class="mb-2">
                <?php echo htmlspecialchars($filter['type']); ?> /
                <?php echo htmlspecialchars($filter['col']); ?> /
                <?php echo htmlspecialchars($filter['val']); ?>

                <!-- 這裡加一個「刪除」連結，把索引帶到 URL -->
                <a href="projectlevel-past.php?remove=<?= $idx ?>" style="color:red; margin-left:10px; text-decoration: none;">
                  X
                </a>
            </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted mb-4"><?php echo __('no_filter_selected'); ?></p>
        <?php endif; ?>
        
        <!-- <form method="POST" class="mt-4">
            <div class="input-group mb-3">
                <input type="text" name="filter_name" class="form-control" placeholder="請輸入篩選組別名稱" required>
                <button type="submit" name="save_filters" class="btn btn-success">💾 儲存篩選內容</button>
            </div>
            <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#loadFiltersModal">
                📂 查看並匯入篩選組別
            </button>
        </form> -->
      </div>
    </div>

    <!-- 在 </body> 標籤前新增模態框 -->
    <div id="descriptionModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); overflow: auto;">
    <div style="background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 50%; border-radius: 5px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h5 style="margin: 0;"><?php echo __('filter_description_title'); ?></h5>
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

    <!-- [C] 顯示查詢結果 (含分頁) -->
    <h5 class="legend-title"><?php echo __('query_cost_table_results'); ?></h5>

    <?php if ($totalRecords === 0): ?>
        <div class="alert alert-warning">
            查無符合條件的資料
        </div>
    <?php else: ?>

        <!-- [C-1] 每頁筆數下拉 -->
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
    
    <!-- [D] 依「類型 + 方案編號」做查詢 -->
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

                // 使用語言翻譯函數來顯示查詢結果標題，並插入表格名稱
                echo '<h5 class="legend-title">' . sprintf(__('query_table_results'), $tableName) . '</h5>';
          if ($resultRows) {
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
    </div><!-- 結束 filter-content-section -->
</div><!-- /.container -->

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

<script>
document.getElementById('openDescriptionBtn').addEventListener('click', function() {
    // 預設載入已有的描述
    const currentNote = "<?php echo isset($_SESSION['current_project_note']) ? htmlspecialchars($_SESSION['current_project_note']) : ''; ?>";
    document.getElementById('modalDescription').value = currentNote;
    
    // 顯示模態框
    document.getElementById('descriptionModal').style.display = 'block';
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 清除可能存在的舊事件監聽器
    const openBtn = document.getElementById('openDescriptionBtn');
    const saveBtn = document.getElementById('saveDescription');
    
    if (openBtn) {
        // 移除所有現有的事件監聽器
        const newOpenBtn = openBtn.cloneNode(true);
        openBtn.parentNode.replaceChild(newOpenBtn, openBtn);
        
        // 添加新的事件監聽器
        newOpenBtn.addEventListener('click', function() {
            // 預設載入已有的描述
            const currentNote = "<?php echo isset($_SESSION['current_project_note']) ? htmlspecialchars($_SESSION['current_project_note']) : ''; ?>";
            document.getElementById('modalDescription').value = currentNote;
            
            // 顯示模態框
            document.getElementById('descriptionModal').style.display = 'block';
        });
    }

    // 關閉模態框按鈕
    document.getElementById('closeModal').addEventListener('click', function() {
        document.getElementById('descriptionModal').style.display = 'none';
    });

    document.getElementById('cancelDescription').addEventListener('click', function() {
        document.getElementById('descriptionModal').style.display = 'none';
    });

    // 清除舊的儲存事件監聽器
    if (saveBtn) {
        const newSaveBtn = saveBtn.cloneNode(true);
        saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
        
        // 添加新的儲存事件監聽器
        newSaveBtn.addEventListener('click', function() {
            const description = document.getElementById('modalDescription').value.trim();
            
            // 使用 AJAX 儲存描述
            const formData = new FormData();
            formData.append('save_project_note', '1');
            formData.append('project_note', description);
            
            // 禁用按鈕，防止重複提交
            newSaveBtn.disabled = true;
            
            fetch('projectlevel-past.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('網路或伺服器錯誤');
                }
                return response.text();
            })
            .then(result => {
                // 處理伺服器回應
                console.log('Server response:', result);
                alert('專案描述已儲存');
                document.getElementById('descriptionModal').style.display = 'none';
            })
            .catch(error => {
                console.error('Error:', error);
                alert('儲存失敗: ' + error.message);
            })
            .finally(() => {
                // 重新啟用按鈕
                newSaveBtn.disabled = false;
            });
        });
    }
});
</script>

<script>

// 頁面載入時檢查是否有 load_filter_group 參數
document.addEventListener('DOMContentLoaded', function() {
    // 檢查 URL 是否包含 load_filter_group 參數
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('load_filter_group')) {
        // 如果是載入篩選組別返回的頁面，隱藏歷史列表，顯示內容區域
        showFilterContent();
    } else {
        // 添加全局返回按鈕監聽 (針對通過 AJAX 方式載入的情況)
        setupLoadFilterListeners();
    }
});

// 設置篩選載入按鈕的事件監聽
function setupLoadFilterListeners() {
    // 綁定所有載入篩選按鈕的點擊事件
    const loadButtons = document.querySelectorAll('.load-filter-btn');
    loadButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            // 防止立即跳轉，因為我們想先執行一些 UI 調整
            e.preventDefault();
            
            // 抓取目標 URL
            const targetUrl = this.getAttribute('href');
            
            // 儲存使用者正在載入的篩選組別到 localStorage (這樣頁面重整後也能記住狀態)
            localStorage.setItem('loadingFilter', 'true');
            
            // 執行頁面跳轉
            window.location.href = targetUrl;
        });
    });
}

// 顯示篩選內容區域，隱藏歷史專案區域
function showFilterContent() {
    document.getElementById('history-section').style.display = 'none';
    document.getElementById('filter-content-section').style.display = 'block';
    
    // 添加一個返回歷史列表的按鈕
    const filterContentSection = document.getElementById('filter-content-section');
    if (!document.getElementById('back-to-history-btn')) {
        const backButton = document.createElement('div');
        backButton.innerHTML = `
            <button id="back-to-history-btn" class="btn btn-secondary mb-3">
                <i class="bi bi-arrow-left"></i> 返回歷史專案列表
            </button>
        `;
        filterContentSection.insertBefore(backButton, filterContentSection.firstChild);
        
        // 添加返回按鈕的點擊事件
        document.getElementById('back-to-history-btn').addEventListener('click', function() {
            // 清除 localStorage 中的標記
            localStorage.removeItem('loadingFilter');
            
            // 隱藏篩選內容，顯示歷史專案列表
            document.getElementById('filter-content-section').style.display = 'none';
            document.getElementById('history-section').style.display = 'block';
        });
    }
}

// 檢查是否已加載篩選條件
function checkLoadedFilterStatus() {
    // 這裡判斷是否是從篩選載入返回的頁面
    if (localStorage.getItem('loadingFilter') === 'true') {
        showFilterContent();
    }
}

// 在頁面加載時檢查狀態
window.addEventListener('load', checkLoadedFilterStatus);
</script>
<script>
// 更改每頁專案數
function changeProjectsPageSize() {
    const sel = document.getElementById('projectsPageSizeSelect');
    const limit = sel.value;
    const url = new URL(window.location.href);
    url.searchParams.set('projects_limit', limit);
    // 換頁後回到第一頁
    url.searchParams.set('projects_page', 1);
    window.location.href = url.toString();
}
</script>
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