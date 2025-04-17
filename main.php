<?php
/****************************************************************************
 * 一、資料庫連線
 ****************************************************************************/
$serverName = "localhost\\SQLEXPRESS";
$database   = "Test";
$username   = "weihao0120";   // 依照你實際的帳號
$password   = "weihao0120"; // 依照你實際的密碼

try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("資料庫連線失敗：" . $e->getMessage());
}

/****************************************************************************
 * 二、兩組篩選參數 (第一組 / 第二組) 及 是否「最終搜尋」
 ****************************************************************************/
// 第一組
$filter1_type = isset($_GET['filter1_type']) ? $_GET['filter1_type'] : '';
$filter1_col  = isset($_GET['filter1_col'])  ? $_GET['filter1_col']  : '';
$filter1_val  = isset($_GET['filter1_val'])  ? $_GET['filter1_val']  : '';

// 第二組
$filter2_type = isset($_GET['filter2_type']) ? $_GET['filter2_type'] : '';
$filter2_col  = isset($_GET['filter2_col'])  ? $_GET['filter2_col']  : '';
$filter2_val  = isset($_GET['filter2_val'])  ? $_GET['filter2_val']  : '';

// 是否按下「確認搜尋」 => do_search=1
$doSearch = isset($_GET['do_search']) && $_GET['do_search'] == 1;

/****************************************************************************
 * 三、定義外殼/空調->資料表、欄位對應
 ****************************************************************************/
$typeConfig = [
    '外殼' => [
        'tableName'        => '外殼',
        'planColumnName'   => '方案',
        'costDesignColumn' => '外殼設計方案'
    ],
    '空調' => [
        'tableName'        => '空調',
        'planColumnName'   => '方案',
        'costDesignColumn' => '空調設計方案'
    ]
];

/****************************************************************************
 * 四、撈出「欄位清單」與「distinct 值」的函式
 ****************************************************************************/
function getTableColumns(PDO $conn, $tableName) {
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo'
          AND TABLE_NAME = :tName
        ORDER BY ORDINAL_POSITION
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':tName' => $tableName]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN); // 回傳一維陣列(每個欄位名稱)
}

function getDistinctValues(PDO $conn, $tableName, $colName) {
    $colSafe = "[" . str_replace(["[","]"], "", $colName) . "]";
    $sql = "
        SELECT DISTINCT $colSafe AS val
        FROM [dbo].[$tableName]
        ORDER BY $colSafe
    ";
    return $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}

/****************************************************************************
 * 五、(第一組) 動態載入欄位 / 值
 ****************************************************************************/
$filter1_columns = [];
$filter1_values  = [];

if (!empty($filter1_type) && isset($typeConfig[$filter1_type])) {
    // 撈「第一組」可用的欄位清單
    $filter1_columns = getTableColumns($conn, $typeConfig[$filter1_type]['tableName']);
    // 若有選到欄位 => 撈 distinct 值
    if (!empty($filter1_col)) {
        $filter1_values = getDistinctValues($conn, $typeConfig[$filter1_type]['tableName'], $filter1_col);
    }
}

/****************************************************************************
 * 六、(第二組) 動態載入欄位 / 值
 ****************************************************************************/
$filter2_columns = [];
$filter2_values  = [];

if (!empty($filter2_type) && isset($typeConfig[$filter2_type])) {
    // 撈「第二組」可用的欄位清單
    $filter2_columns = getTableColumns($conn, $typeConfig[$filter2_type]['tableName']);
    // 若有選到欄位 => 撈 distinct 值
    if (!empty($filter2_col)) {
        $filter2_values = getDistinctValues($conn, $typeConfig[$filter2_type]['tableName'], $filter2_col);
    }
}

/****************************************************************************
 * 七、輸出前端 HTML + JS
 ****************************************************************************/
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8" />
    <title>逐級下拉 + 改變時重置 + 成本表查詢</title>
    <style>
        body { margin:20px; font-family: sans-serif; }
        .filter-block { border:1px solid #ccc; margin-bottom:10px; padding:10px; }
        label { display:inline-block; width: 80px; }
        select { width:200px; }
        table { margin:20px 0; border-collapse: collapse; width: 80%; }
        th, td { border:1px solid #999; padding:6px 10px; }
        .pagination { margin:10px 0; text-align:center; }
        .pagination a {
            display:inline-block; text-decoration:none; color:#333;
            border:1px solid #ccc; margin:0 3px; padding:4px 8px;
        }
        .pagination a.active, .pagination a:hover {
            background-color: #ddd;
        }
        .ellipsis { display:inline-block; margin:0 3px; }
    </style>
    <script>
    // JS: resetDropdown => 清空指定下拉 (設為空值)
    function resetDropdown(name) {
        var sel = document.getElementsByName(name)[0];
        if (sel) {
            sel.value = "";
        }
    }
    // 第一組
    function onChangeFirstType() {
        // 改變「第一組類型」 => 重置 filter1_col、filter1_val
        resetDropdown("filter1_col");
        resetDropdown("filter1_val");
        document.getElementById("filterForm").submit();
    }
    function onChangeFirstCol() {
        // 改變「第一組欄位」 => 重置 filter1_val
        resetDropdown("filter1_val");
        document.getElementById("filterForm").submit();
    }

    // 第二組
    function onChangeSecondType() {
        // 改變「第二組類型」 => 重置 filter2_col、filter2_val
        resetDropdown("filter2_col");
        resetDropdown("filter2_val");
        document.getElementById("filterForm").submit();
    }
    function onChangeSecondCol() {
        // 改變「第二組欄位」 => 重置 filter2_val
        resetDropdown("filter2_val");
        document.getElementById("filterForm").submit();
    }

    // 每頁筆數切換
    function changePageSize() {
        const sel = document.getElementById('pageSizeSelect');
        const limit = sel.value;
        const url = new URL(window.location.href);
        url.searchParams.set('limit', limit);
        url.searchParams.set('page', 1);
        window.location.href = url.toString();
    }
    </script>
</head>
<body>

<h1>逐級下拉 + 改變時重置 + 成本表查詢</h1>

<form method="GET" id="filterForm">
    <!-- 第一組 -->
    <div class="filter-block">
        <h3>第一組篩選</h3>
        <div>
            <label>類型：</label>
            <select name="filter1_type" onchange="onChangeFirstType()">
                <option value="" disabled <?php echo ($filter1_type===''?'selected':''); ?>>-- 請選擇 --</option>
                <option value="外殼" <?php echo ($filter1_type==='外殼'?'selected':''); ?>>外殼</option>
                <option value="空調" <?php echo ($filter1_type==='空調'?'selected':''); ?>>空調</option>
            </select>
        </div>
        <br/>
        <div>
            <label>欄位：</label>
            <select name="filter1_col" onchange="onChangeFirstCol()">
                <option value="" disabled <?php echo ($filter1_col===''?'selected':''); ?>>-- 請選欄位 --</option>
                <?php
                foreach ($filter1_columns as $col) {
                    $sel = ($col===$filter1_col?'selected':'');
                    echo "<option value=\"".htmlspecialchars($col)."\" $sel>".htmlspecialchars($col)."</option>";
                }
                ?>
            </select>
        </div>
        <br/>
        <div>
            <label>值：</label>
            <select name="filter1_val">
                <option value="" disabled <?php echo ($filter1_val===''?'selected':''); ?>>-- 請選值 --</option>
                <?php
                foreach ($filter1_values as $val) {
                    $sel = ($val===$filter1_val?'selected':'');
                    echo "<option value=\"".htmlspecialchars($val)."\" $sel>".htmlspecialchars($val)."</option>";
                }
                ?>
            </select>
        </div>
    </div>

    <!-- 第二組 -->
    <div class="filter-block">
        <h3>第二組篩選</h3>
        <div>
            <label>類型：</label>
            <select name="filter2_type" onchange="onChangeSecondType()">
                <option value="" disabled <?php echo ($filter2_type===''?'selected':''); ?>>-- 請選擇 --</option>
                <option value="外殼" <?php echo ($filter2_type==='外殼'?'selected':''); ?>>外殼</option>
                <option value="空調" <?php echo ($filter2_type==='空調'?'selected':''); ?>>空調</option>
            </select>
        </div>
        <br/>
        <div>
            <label>欄位：</label>
            <select name="filter2_col" onchange="onChangeSecondCol()">
                <option value="" disabled <?php echo ($filter2_col===''?'selected':''); ?>>-- 請選欄位 --</option>
                <?php
                foreach ($filter2_columns as $col) {
                    $sel = ($col===$filter2_col?'selected':'');
                    echo "<option value=\"".htmlspecialchars($col)."\" $sel>".htmlspecialchars($col)."</option>";
                }
                ?>
            </select>
        </div>
        <br/>
        <div>
            <label>值：</label>
            <select name="filter2_val">
                <option value="" disabled <?php echo ($filter2_val===''?'selected':''); ?>>-- 請選值 --</option>
                <?php
                foreach ($filter2_values as $val) {
                    $sel = ($val===$filter2_val?'selected':'');
                    echo "<option value=\"".htmlspecialchars($val)."\" $sel>".htmlspecialchars($val)."</option>";
                }
                ?>
            </select>
        </div>
    </div>

    <!-- 先保留每頁筆數 (limit) 用於分頁 -->
    <?php
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
    echo "<input type='hidden' name='limit' value='{$limit}'>";
    ?>

    <!-- 最終按鈕 => do_search=1 -->
    <button type="submit" name="do_search" value="1">確認搜尋</button>
</form>

<hr/>

<?php
/****************************************************************************
 * 八、只有當 do_search=1 時，才執行「最終查成本表」的查詢
 ****************************************************************************/
if ($doSearch) {
    // 是否兩組都有選擇
    if (!empty($filter1_type) && !empty($filter1_col) && $filter1_val!==''
        && !empty($filter2_type) && !empty($filter2_col) && $filter2_val!==''
        && isset($typeConfig[$filter1_type]) && isset($typeConfig[$filter2_type])
    ) {
        // (A) 找出「成本」表對應到的欄位
        //     例如 filter1_type=外殼 => [外殼設計方案]
        //          filter2_type=空調 => [空調設計方案]
        $col1 = "[" . $typeConfig[$filter1_type]['costDesignColumn'] . "]";
        $col2 = "[" . $typeConfig[$filter2_type]['costDesignColumn'] . "]";

        // (B) 來源表、方案欄位
        $table1   = $typeConfig[$filter1_type]['tableName'];    // ex. 外殼
        $planCol1 = "[" . $typeConfig[$filter1_type]['planColumnName'] . "]"; // ex. 方案
        $table2   = $typeConfig[$filter2_type]['tableName'];
        $planCol2 = "[" . $typeConfig[$filter2_type]['planColumnName'] . "]";

        // (C) 對應使用者選到的 (filter1_col, filter1_val)
        $colSafe1 = "[" . str_replace(["[","]"], "", $filter1_col) . "]";
        $colSafe2 = "[" . str_replace(["[","]"], "", $filter2_col) . "]";

        // (D) 分頁設定
        $allowedPageSizes      = [5, 10, 20, 50];
        $defaultRecordsPerPage = 5;
        if (isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowedPageSizes)) {
            $recordsPerPage = (int)$_GET['limit'];
        } else {
            $recordsPerPage = $defaultRecordsPerPage;
        }
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $recordsPerPage;

        // (E) 子查詢方式 (先計算總筆數)
        $sql_count = "
            SELECT COUNT(*) AS total
            FROM [dbo].[成本] AS c
            WHERE
                c.$col1 IN (
                    SELECT $planCol1
                    FROM [dbo].[$table1]
                    WHERE $colSafe1 = :val1
                )
              AND
                c.$col2 IN (
                    SELECT $planCol2
                    FROM [dbo].[$table2]
                    WHERE $colSafe2 = :val2
                )
        ";
        $stmt_count = $conn->prepare($sql_count);
        $stmt_count->bindValue(':val1', $filter1_val, PDO::PARAM_STR);
        $stmt_count->bindValue(':val2', $filter2_val, PDO::PARAM_STR);
        $stmt_count->execute();
        $totalRecords = (int)$stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages   = ($totalRecords > 0) ? ceil($totalRecords / $recordsPerPage) : 1;

        // (F) 查詢「分頁資料」
        $sql_data = "
            SELECT *
            FROM [dbo].[成本] AS c
            WHERE
                c.$col1 IN (
                    SELECT $planCol1
                    FROM [dbo].[$table1]
                    WHERE $colSafe1 = :val1
                )
              AND
                c.$col2 IN (
                    SELECT $planCol2
                    FROM [dbo].[$table2]
                    WHERE $colSafe2 = :val2
                )
            ORDER BY c.[編號] ASC
            OFFSET :offset ROWS
            FETCH NEXT :limit ROWS ONLY
        ";
        $stmt_data = $conn->prepare($sql_data);
        $stmt_data->bindValue(':val1', $filter1_val, PDO::PARAM_STR);
        $stmt_data->bindValue(':val2', $filter2_val, PDO::PARAM_STR);
        $stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt_data->bindValue(':limit',  $recordsPerPage, PDO::PARAM_INT);
        $stmt_data->execute();
        $costRows = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

        // (G) 顯示結果
        echo "<h3>查詢結果</h3>";
        if ($totalRecords > 0) {
            echo "<p>共 {$totalRecords} 筆，分成 {$totalPages} 頁，目前在第 {$page} 頁</p>";

            // 每頁筆數下拉
            ?>
            <div>
                <label>每頁顯示筆數：</label>
                <select id="pageSizeSelect" onchange="changePageSize()">
                    <?php foreach ($allowedPageSizes as $sz): ?>
                        <option value="<?php echo $sz; ?>" <?php echo ($sz==$recordsPerPage?'selected':''); ?>>
                            <?php echo $sz; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php

            // 顯示表格
            echo "<table>";
            echo "<tr>";
            foreach (array_keys($costRows[0]) as $field) {
                echo "<th>".htmlspecialchars($field)."</th>";
            }
            echo "</tr>";
            foreach ($costRows as $row) {
                echo "<tr>";
                foreach ($row as $val) {
                    echo "<td>".htmlspecialchars($val)."</td>";
                }
                echo "</tr>";
            }
            echo "</table>";

            // 分頁導覽
            $range = 3; // 前後顯示頁碼範圍
            $start = max(1, $page-$range);
            $end   = min($totalPages, $page+$range);

            echo "<div class='pagination'>";
            // 上一頁
            if ($page > 1) {
                $prev = $page - 1;
                echo "<a href='".buildPageUrl($prev, $recordsPerPage)."'>上一頁</a>";
            }
            // 第一頁 + ...
            if ($start > 1) {
                echo "<a href='".buildPageUrl(1, $recordsPerPage)."'>1</a>";
                if ($start > 2) echo "<span class='ellipsis'>...</span>";
            }
            // 中間頁
            for ($i = $start; $i <= $end; $i++) {
                if ($i == $page) {
                    echo "<a class='active' href='".buildPageUrl($i, $recordsPerPage)."'>{$i}</a>";
                } else {
                    echo "<a href='".buildPageUrl($i, $recordsPerPage)."'>{$i}</a>";
                }
            }
            // ... + 最後頁
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo "<span class='ellipsis'>...</span>";
                echo "<a href='".buildPageUrl($totalPages, $recordsPerPage)."'>{$totalPages}</a>";
            }
            // 下一頁
            if ($page < $totalPages) {
                $next = $page + 1;
                echo "<a href='".buildPageUrl($next, $recordsPerPage)."'>下一頁</a>";
            }
            echo "</div>";
        } else {
            echo "<p>找不到符合的成本資料。</p>";
        }
    } else {
        echo "<p>請確保兩組(type, col, val)都已完整選擇。</p>";
    }
} else {
    echo "<p>請依序選擇類型、欄位、值，最後點「確認搜尋」。</p>";
}

/****************************************************************************
 * 九、分頁連結生成
 ****************************************************************************/
function buildPageUrl($page, $limit) {
    // 複製現有的 GET，再改 page、limit
    $qs = $_GET;
    $qs['page'] = $page;
    $qs['limit'] = $limit;
    // 保留 do_search=1，否則分頁跳頁會不再查詢
    $qs['do_search'] = 1;
    return '?' . http_build_query($qs);
}
?>
</body>
</html>

實現可以動態新增篩選，網頁只讓使用者選擇一組篩選，當按下確認後可以搜尋，之後若我輸入選擇另一組篩選，按下確認後可以與先前選擇過的篩選同時篩選成本表