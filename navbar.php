<?php 
// 確保在任何 HTML 輸出之前啟動 session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 確保 language.php 已被包含
if (!function_exists('__')) {
    include('language.php');
}

// 檢查是否為專案驗證請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'validate_project') {
    header('Content-Type: application/json');
    
    // 確保用戶已登入
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['valid' => false]);
        exit;
    }
    
    // 獲取專案ID
    $projectId = isset($_POST['project_id']) ? $_POST['project_id'] : null;
    
    if (!$projectId) {
        echo json_encode(['valid' => false]);
        exit;
    }
    
    // 連接數據庫
    $serverName = "localhost\SQLEXPRESS";
    $database   = "Test";
    $username   = "weihao0120";
    $password   = "weihao0120";
    if ($conn->connect_error) {
        echo json_encode(['valid' => false]);
        exit;
    }
    
    // 查詢專案是否屬於當前用戶
    $userId = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $projectId, $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    
    // 如果找到記錄，則專案有效
    $isValid = ($count > 0);
    
    echo json_encode(['valid' => $isValid]);
    exit;
}

$confirmMessage = __('save_data_confirm');

// 調試信息 - 可以臨時添加這段代碼來檢查問題
if (!isset($_SESSION['language'])) {
  $_SESSION['language'] = 'zh-TW';
  error_log("設置默認語言為zh-TW");
}

// 獲取當前頁面的文件名
$current_page = basename($_SERVER['PHP_SELF']);

// 用於確定當前頁面是否active的函數
function isCurrentPage($pages) {
  $currentPage = basename($_SERVER['PHP_SELF']);
  return in_array($currentPage, (array) $pages) ? 'active' : '';
}
?>

<!-- 專案資訊JavaScript全局變數 -->
<script>
// 使用JavaScript儲存當前專案資訊
var currentProjectInfo = {
    id: <?php echo isset($_SESSION['current_gbd_project_id']) ? $_SESSION['current_gbd_project_id'] : 'null'; ?>,
    name: <?php echo isset($_SESSION['current_gbd_project_name']) ? json_encode($_SESSION['current_gbd_project_name']) : 'null'; ?>
};

// 新增：綠建築專案資訊
var gbdProjectInfo = {
    id: <?php echo isset($_SESSION['gbd_project_id']) ? $_SESSION['gbd_project_id'] : 'null'; ?>,
    name: <?php echo isset($_SESSION['gbd_project_name']) ? json_encode($_SESSION['gbd_project_name']) : 'null'; ?>
};

// 獲取當前頁面類型
function getCurrentPageType() {
    var path = window.location.pathname;
    if (path.includes('greenbuildingcal') || path.endsWith('gbd-') || path.includes('green-building')) {
        return 'gbd';
    } else if (path.includes('urbanclimate') || path.includes('urban-')) {
        return 'urban';
    } else if (path.includes('projectlevel') || path.includes('badge-')) {
        return 'badge';
    }
    return 'unknown';
}

// 更新導航欄顯示的專案
// 更新導航欄顯示的專案
function updateNavbarProjectDisplay() {
    console.log("嘗試更新導航欄...");
    
    // 合併來源，確保始終有可用的專案信息
    var projectId = gbdProjectInfo.id || currentProjectInfo.id || sessionStorage.getItem('currentProjectId');
    var projectName = gbdProjectInfo.name || currentProjectInfo.name || sessionStorage.getItem('currentProjectName');
    
    if (projectId && projectName) {
        console.log("更新導航欄顯示，專案信息:", projectId, projectName);
        
        // 移除現有的指示器
        document.querySelectorAll(".current-project-indicator, .project-dropdown").forEach(el => {
            if (el.parentElement && el.parentElement.classList.contains('nav-item')) {
                el.parentElement.remove();
            } else if (!el.parentElement.classList.contains('dropdown-menu')) {
                el.remove();
            }
        });
        
        // 找到適當位置插入
        const navbarNav = document.querySelector('.navbar-nav.me-auto');
        if (navbarNav) {
            // 創建桌面指示器(帶下拉選單)
            const desktopDropdown = document.createElement('li');
            desktopDropdown.className = 'nav-item dropdown project-dropdown d-none d-lg-block';
            desktopDropdown.innerHTML = `
                <div class="current-project-indicator dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="project-label"><?php echo __('current_project'); ?></span>
                    <span class="project-name">${projectName}</span>
                </div>
                <ul class="dropdown-menu project-actions-menu">
                    <li><a class="dropdown-item" href="#" onclick="editCurrentProject()"><i class="fas fa-edit"></i> 編輯專案</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="#" onclick="leaveCurrentProject()"><i class="fas fa-sign-out-alt"></i> 離開專案</a></li>
                </ul>
            `;
            
            // 創建移動端指示器(帶下拉選單)
            const mobileDropdown = document.createElement('li');
            mobileDropdown.className = 'nav-item dropdown project-dropdown d-lg-none';
            mobileDropdown.innerHTML = `
                <div class="current-project-indicator dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="project-label">當前專案:</span>
                    <span class="project-name">${projectName}</span>
                </div>
                <ul class="dropdown-menu project-actions-menu">
                    <li><a class="dropdown-item" href="#" onclick="editCurrentProject()"><i class="fas fa-edit"></i> 編輯專案</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="#" onclick="leaveCurrentProject()"><i class="fas fa-sign-out-alt"></i> 離開專案</a></li>
                </ul>
            `;
            
            // 插入元素
            navbarNav.appendChild(desktopDropdown);
            navbarNav.appendChild(mobileDropdown);
            console.log("導航欄指示器已插入DOM");
        } else {
            console.log("找不到導航欄元素");
        }
    } else {
        console.log("沒有可用的專案信息顯示");
        displayNoProjectSelected();
    }
}

// 直接強制更新導航欄的函數
function forceUpdateNavbar(projectId, projectName) {
    console.log("強制更新導航欄: ID=" + projectId + ", 名稱=" + projectName);
    
    // 移除所有現有的專案指示器
    document.querySelectorAll(".current-project-indicator, .nav-item .current-project-indicator").forEach(el => {
        if (el.parentElement && el.parentElement.classList.contains('nav-item')) {
            el.parentElement.remove();
        } else if (el.parentElement) {
            el.remove();
        }
    });
    
    // 直接在導航欄中找適合的位置插入
    const navbarNav = document.querySelector('.navbar-nav.me-auto');
    if (navbarNav) {
        // 創建桌面指示器
        const desktopIndicator = document.createElement('div');
        desktopIndicator.className = 'current-project-indicator d-none d-lg-flex';
        desktopIndicator.innerHTML = `
            <span class="project-label">當前專案:</span>
            <span class="project-name">${projectName}</span>
        `;
        
        // 創建移動端指示器
        const mobileIndicator = document.createElement('li');
        mobileIndicator.className = 'nav-item d-lg-none';
        mobileIndicator.innerHTML = `
            <div class="current-project-indicator">
                <span class="project-label">當前專案:</span>
                <span class="project-name">${projectName}</span>
            </div>
        `;
        
        // 插入元素
        navbarNav.appendChild(desktopIndicator);
        navbarNav.appendChild(mobileIndicator);
        console.log("導航欄指示器已直接插入到navbar-nav");
    } else {
        // 如果找不到.navbar-nav.me-auto，嘗試找任何.navbar元素
        const navbar = document.querySelector('.navbar, nav, header');
        if (navbar) {
            const indicator = document.createElement('div');
            indicator.className = 'current-project-indicator';
            indicator.innerHTML = `
                <span class="project-label">當前專案:</span>
                <span class="project-name">${projectName}</span>
            `;
            navbar.appendChild(indicator);
            console.log("導航欄指示器已插入到" + navbar.tagName);
        } else {
            console.error("找不到任何可插入的導航欄元素");
        }
    }
}

// 提供更新專案資訊的函數
function updateGBDProject(id, name) {
    console.log("更新綠建築專案: ID=" + id + ", 名稱=" + name);
    
    // 更新全局變數
    gbdProjectInfo.id = id;
    gbdProjectInfo.name = name;
    
    // 同步更新currentProjectInfo
    currentProjectInfo.id = id;
    currentProjectInfo.name = name;
    
    // 設置瀏覽器會話存儲
    sessionStorage.setItem('currentProjectId', id);
    sessionStorage.setItem('currentProjectName', name);
    
    // 更新 PHP Session (這點很重要)
    fetch('set_session.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'gbd_project_id=' + id + '&gbd_project_name=' + encodeURIComponent(name)
    })
    .then(response => response.json())
    .then(data => {
        console.log("PHP Session 更新結果:", data);
        updateNavbarProjectDisplay();
    })
    .catch(err => {
        console.error("Session 更新失敗:", err);
    });
    
    // 更新導航欄
    updateNavbarProjectDisplay();
}

// 頁面加載時初始化
$(document).ready(function() {
    // 從PHP Session或瀏覽器會話存儲中恢復專案信息
    if (<?php echo isset($_SESSION['gbd_project_id']) && isset($_SESSION['gbd_project_name']) ? 'true' : 'false'; ?>) {
        gbdProjectInfo.id = <?php echo isset($_SESSION['gbd_project_id']) ? $_SESSION['gbd_project_id'] : 'null'; ?>;
        gbdProjectInfo.name = <?php echo isset($_SESSION['gbd_project_name']) ? json_encode($_SESSION['gbd_project_name']) : 'null'; ?>;
    } else if (sessionStorage.getItem('currentProjectId') && sessionStorage.getItem('currentProjectName')) {
        gbdProjectInfo.id = sessionStorage.getItem('currentProjectId');
        gbdProjectInfo.name = sessionStorage.getItem('currentProjectName');
        
        // 如果從瀏覽器會話中恢復，確保PHP Session也更新
        fetch('set_session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'gbd_project_id=' + gbdProjectInfo.id + '&gbd_project_name=' + encodeURIComponent(gbdProjectInfo.name)
        }).catch(err => console.log('Session初始化錯誤:', err));
    }
    
    // 初始化導航欄顯示
    updateNavbarProjectDisplay();
    
    // 專案表單提交處理
    $("#projectForm").submit(function(e) {
        e.preventDefault();
        
        var projectName = $("#projectName").val();
        
        $.ajax({
            url: window.location.href,
            type: "POST",
            data: $(this).serialize() + "&action=createProject",
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    // 判斷當前頁面類型
                    var pageType = getCurrentPageType();
                    
                    // 根據頁面類型，更新對應的專案資訊
                    if (pageType === 'gbd') {
                        updateGBDProject(response.building_id, projectName);
                    } else {
                        updateCurrentProject(response.building_id, projectName);
                    }
                    
                    // 顯示成功訊息
                    alert(response.message);
                } else {
                    alert(response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error:", error);
                alert("發生錯誤，請稍後再試");
            }
        });
    });
});
</script>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top custom-navbar">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">GreenBuildingStation</a>
    
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
            aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarSupportedContent">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link <?php echo isCurrentPage('homepg.php'); ?>" 
            href="homepg.php"><?php echo __('nav_home'); ?>
          </a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?php echo isCurrentPage(['greenbuildingcal.php', 'greenbuildingcal-new.php', 'greenbuildingcal-past.php']); ?>" 
             href="#" id="greenBuildingDropdown" role="button" 
             data-bs-toggle="dropdown" aria-expanded="false">
            <?php echo __('nav_green_building'); ?>
          </a>
          <ul class="dropdown-menu" aria-labelledby="greenBuildingDropdown">
            <li><a class="dropdown-item" href="greenbuildingcal-new.php?type=green"><?php echo __('nav_new_project'); ?></a></li>
            <li><a class="dropdown-item" href="greenbuildingcal-past.php?type=green"><?php echo __('nav_past_project'); ?></a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?php echo isCurrentPage(['urbanclimate.php', 'urbanclimate-new.php', 'urbanclimate-past.php']); ?>" 
             href="#" id="urbanClimateDropdown" role="button" 
             data-bs-toggle="dropdown" aria-expanded="false">
            <?php echo __('nav_urban_climate'); ?>
          </a>
          <ul class="dropdown-menu" aria-labelledby="urbanClimateDropdown">
            <li><a class="dropdown-item" href="urbanclimate-new.php?type=urban"><?php echo __('nav_new_project'); ?></a></li>
            <li><a class="dropdown-item" href="urbanclimate-past.php?type=urban"><?php echo __('nav_past_project'); ?></a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?php echo isCurrentPage(['projectlevel.php', 'projectlevel-new.php', 'projectlevel-past.php']); ?>" 
             href="#" id="projectBadgeDropdown" role="button" 
             data-bs-toggle="dropdown" aria-expanded="false">
            <?php echo __('nav_project_badge'); ?>
          </a>
          <ul class="dropdown-menu" aria-labelledby="projectBadgeDropdown">
            <li><a class="dropdown-item" href="projectlevel-new.php?type=badge"><?php echo __('nav_new_project'); ?></a></li>
            <li><a class="dropdown-item" href="projectlevel-past.php?type=badge"><?php echo __('nav_past_project'); ?></a></li>
          </ul>
        </li>
        
        <!-- 當前專案指示器 - 只顯示綠建築專案 -->
        <?php 
        // 只檢查是否有綠建築專案名稱
        $display_project_name = '';
        if (isset($_SESSION['gbd_project_name']) && !empty($_SESSION['gbd_project_name'])) {
            $display_project_name = $_SESSION['gbd_project_name'];
        }

        // 只在有專案名稱時顯示
        if (!empty($display_project_name)):
        ?>
        <li class="nav-item dropdown project-dropdown d-none d-lg-block">
          <a class="nav-link dropdown-toggle" href="#" id="currentProjectDropdown" role="button" 
            data-bs-toggle="dropdown" aria-expanded="false">
            <?php echo __('current_project'); ?>: <?php echo htmlspecialchars($display_project_name); ?>
          </a>
          <ul class="dropdown-menu project-actions-menu" aria-labelledby="currentProjectDropdown">
            <li><a class="dropdown-item" href="#" onclick="editCurrentProject()"><i class="fas fa-edit"></i> 編輯專案</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="#" onclick="leaveCurrentProject()"><i class="fas fa-sign-out-alt"></i> 離開專案</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

      <ul class="navbar-nav ms-auto">
        <!-- 語言切換按鈕 -->
        <li class="nav-item">
          <select class="form-select form-select-sm mt-2"
                  style="background-color: transparent; color: white; border-color: rgba(255,255,255,0.5);"
                  onchange="confirmLanguageChange(this.value)">
            <option value="zh-TW" <?php echo ($_SESSION['language'] == 'zh-TW') ? 'selected' : ''; ?>>中文</option>
            <option value="en" <?php echo ($_SESSION['language'] == 'en') ? 'selected' : ''; ?>>English</option>
          </select>
        </li>
        <?php if (isset($_SESSION['user_id'])): ?>
          <li class="nav-item">
              <a class="nav-link <?php echo isCurrentPage(['profile.php']); ?>" href="profile.php">
                  <?php echo htmlspecialchars($_SESSION['username']); ?> <?php echo __('greeting'); ?>
              </a>
          </li>
          <li class="nav-item">
              <a class="nav-link <?php echo isCurrentPage('logout.php'); ?>" 
                href="logout.php"><?php echo __('nav_logout'); ?></a>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link <?php echo isCurrentPage('login.php'); ?>" 
              href="login.php"><?php echo __('nav_login'); ?></a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo isCurrentPage('register.php'); ?>" 
              href="register.php"><?php echo __('nav_register'); ?></a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>


<script>
    // 透過 PHP 傳入當前語言的提醒文字
    var confirmMessage = <?php echo json_encode($confirmMessage); ?>;

    function confirmLanguageChange(lang) {
        if (confirm(confirmMessage)) {
             window.location.href = 'language.php?lang=' + lang;
        }
    }

    // 將這段代碼放在所有頁面共用的JS文件或頁頭部分
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化導航欄專案顯示
        initializeProjectDisplay();
    });

    // 修改 initializeProjectDisplay 函數添加檢核機制
    function initializeProjectDisplay() {
        // 檢查用戶是否已登入
        var isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        
        // 如果用戶未登入，清除所有專案信息並顯示"尚未選取專案"
        if (!isLoggedIn) {
            clearProjectInfo();
            displayNoProjectSelected();
            return;
        }
        
        // 優先使用 PHP Session 數據
        var sessionProjectId = <?php echo isset($_SESSION['gbd_project_id']) ? $_SESSION['gbd_project_id'] : 'null'; ?>;
        var sessionProjectName = <?php echo isset($_SESSION['gbd_project_name']) ? json_encode($_SESSION['gbd_project_name']) : 'null'; ?>;
        
        if (sessionProjectId && sessionProjectName) {
            console.log("從 PHP Session 恢復專案信息:", sessionProjectId, sessionProjectName);
            
            // 更新全局變數
            gbdProjectInfo.id = sessionProjectId;
            gbdProjectInfo.name = sessionProjectName;
            currentProjectInfo.id = sessionProjectId;
            currentProjectInfo.name = sessionProjectName;
            
            // 更新會話存儲
            sessionStorage.setItem('currentProjectId', sessionProjectId);
            sessionStorage.setItem('currentProjectName', sessionProjectName);
            
            // 更新導航欄顯示
            updateNavbarProjectDisplay();
        }
        // 如果 PHP Session 沒有數據，嘗試從 sessionStorage 讀取
        else if (sessionStorage.getItem('currentProjectId') && sessionStorage.getItem('currentProjectName')) {
            var storedId = sessionStorage.getItem('currentProjectId');
            var storedName = sessionStorage.getItem('currentProjectName');
            
            // 驗證專案是否為當前用戶的有效專案
            validateProject(storedId, function(isValid) {
                if (isValid) {
                    console.log("從 sessionStorage 恢復專案信息:", storedId, storedName);
                    
                    // 更新全局變數
                    gbdProjectInfo.id = storedId;
                    gbdProjectInfo.name = storedName;
                    currentProjectInfo.id = storedId;
                    currentProjectInfo.name = storedName;
                    
                    // 更新 PHP Session 確保跨頁面一致性
                    fetch('set_session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'gbd_project_id=' + storedId + '&gbd_project_name=' + encodeURIComponent(storedName)
                    });
                    
                    // 更新導航欄顯示
                    updateNavbarProjectDisplay();
                } else {
                    // 如果專案無效，清除信息並顯示"尚未選取專案"
                    clearProjectInfo();
                    displayNoProjectSelected();
                }
            });
        } else {
            // 如果沒有任何專案信息，顯示"尚未選取專案"
            clearProjectInfo();
            displayNoProjectSelected();
        }
        
        // 延遲確保 DOM 完全載入後再次更新
        setTimeout(function() {
            if (!gbdProjectInfo.id && !currentProjectInfo.id) {
                displayNoProjectSelected();
            } else {
                updateNavbarProjectDisplay();
            }
        }, 300);
    }

    // 清除專案信息
    function clearProjectInfo() {
        gbdProjectInfo.id = null;
        gbdProjectInfo.name = null;
        currentProjectInfo.id = null;
        currentProjectInfo.name = null;
        sessionStorage.removeItem('currentProjectId');
        sessionStorage.removeItem('currentProjectName');
    }

    // 顯示"尚未選取專案"
    function displayNoProjectSelected() {
        console.log("顯示尚未選取專案");
        
        // 移除現有的指示器
        document.querySelectorAll(".current-project-indicator, .project-dropdown").forEach(el => {
            if (el.parentElement && el.parentElement.classList.contains('nav-item')) {
                el.parentElement.remove();
            } else if (!el.parentElement || !el.parentElement.classList.contains('dropdown-menu')) {
                el.remove();
            }
        });
        
        // 找到適當位置插入
        const navbarNav = document.querySelector('.navbar-nav.me-auto');
        if (navbarNav) {
            // 創建桌面指示器(無下拉選單)
            const desktopIndicator = document.createElement('div');
            desktopIndicator.className = 'current-project-indicator d-none d-lg-flex';
            desktopIndicator.innerHTML = `
                <span class="project-label"><?php echo __('current_project'); ?>:</span>
                <span class="project-name"><?php echo __('no_project_selected'); ?></span>
            `;
            
            // 創建移動端指示器(無下拉選單)
            const mobileIndicator = document.createElement('li');
            mobileIndicator.className = 'nav-item d-lg-none';
            mobileIndicator.innerHTML = `
                <div class="current-project-indicator">
                    <span class="project-label">當前專案:</span>
                    <span class="project-name">尚未選取專案</span>
                </div>
            `;
            
            // 插入元素
            navbarNav.appendChild(desktopIndicator);
            navbarNav.appendChild(mobileIndicator);
            console.log("尚未選取專案指示器已插入DOM");
        }
    }

    // 驗證專案是否為當前用戶的有效專案
    function validateProject(projectId, callback) {
        // 發送AJAX請求到navbar.php驗證專案
        fetch('navbar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=validate_project&project_id=' + projectId
        })
        .then(response => response.json())
        .then(data => {
            callback(data.valid === true);
        })
        .catch(err => {
            console.error("專案驗證失敗:", err);
            callback(false);
        });
    }
</script>

<script>
// 專案表單提交處理
$("#projectForm").submit(function(e) {
    e.preventDefault();
    
    var projectName = $("#projectName").val();
    
    $.ajax({
        url: window.location.href,
        type: "POST",
        data: $(this).serialize() + "&action=createProject",
        dataType: "json",
        success: function(response) {
            if (response.success) {
                // 更新 JavaScript 全局變數
                currentProjectInfo.id = response.building_id;
                currentProjectInfo.name = projectName;
                
                // 只在綠建築頁面才更新綠建築專案資訊
                var pageType = getCurrentPageType();
                if (pageType === 'gbd') {
                    gbdProjectInfo.id = response.building_id;
                    gbdProjectInfo.name = projectName;
                    console.log("更新綠建築專案: ID=" + response.building_id + ", 名稱=" + projectName);
                } else {
                    console.log("更新一般專案: ID=" + response.building_id + ", 名稱=" + projectName);
                }
                
                // 更新導航欄顯示
                updateNavbarProjectDisplay();
                
                // 顯示成功訊息
                alert(response.message);
            } else {
                alert(response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", error);
            alert("發生錯誤，請稍後再試");
        }
    });
});
</script>

<script>
// 編輯當前專案
function editCurrentProject() {
    var projectId = gbdProjectInfo.id || currentProjectInfo.id;
    if (!projectId) {
        alert("無法編輯專案：找不到專案資訊");
        return;
    }
    
    // 創建並立即顯示載入中畫面
    showLoadingOverlay();
    
    // 判斷當前是否在正確的頁面
    const onCorrectPage = window.location.href.includes('greenbuildingcal-past.php');
    
    if (!onCorrectPage) {
        // 如果不在正確頁面，在跳轉前設置會話存儲標記
        sessionStorage.setItem('showLoadingAfterRedirect', 'true');
        sessionStorage.setItem('pendingProjectId', projectId);
        // 直接導航（載入畫面會消失，但我們會在新頁面中重新顯示）
        window.location.href = 'greenbuildingcal-past.php?autoload=' + projectId;
        return;
    }
    
    // 使用Fetch API加載專案數據
    loadProjectData(projectId);
}

// 顯示載入中覆蓋層
function showLoadingOverlay() {
    let loadingElement = document.getElementById('loading-overlay');
    if (!loadingElement) {
        loadingElement = document.createElement('div');
        loadingElement.id = 'loading-overlay';
        
        // 使用內聯樣式確保顯示正確
        loadingElement.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        `;
        
        loadingElement.innerHTML = `
            <div style="
                text-align: center;
                padding: 20px;
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            ">
                <div style="
                    width: 50px;
                    height: 50px;
                    margin: 0 auto 15px auto;
                    border: 5px solid #f3f3f3;
                    border-top: 5px solid #3498db;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                "></div>
                <div style="font-size: 18px; font-weight: bold;">載入中...</div>
            </div>
            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        `;
        
        document.body.insertBefore(loadingElement, document.body.firstChild);
    } else {
        loadingElement.style.display = 'flex';
    }
    
    // 強制瀏覽器重繪
    void loadingElement.offsetHeight;
}

// 隱藏載入中覆蓋層
function hideLoadingOverlay() {
    let loadingElement = document.getElementById('loading-overlay');
    if (loadingElement) {
        loadingElement.style.display = 'none';
    }
}

// 加載專案數據的函數
function loadProjectData(projectId) {
    // 確保載入畫面顯示
    showLoadingOverlay();
    
    fetch('greenbuildingcal-past.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=getProjectData&projectId=' + encodeURIComponent(projectId),
        cache: 'no-cache'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('服務器響應錯誤: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // 更新URL以反映當前狀態
            const newUrl = 'greenbuildingcal-past.php?autoload=' + projectId;
            window.history.pushState({path: newUrl}, '', newUrl);
            
            // 獲取專案名稱
            const projectName = data.projectData.project.building_name;
            
            // 更新全局變數和會話存儲
            if (typeof gbdProjectInfo !== 'undefined') {
                gbdProjectInfo.id = projectId;
                gbdProjectInfo.name = projectName;
            }
            if (typeof currentProjectInfo !== 'undefined') {
                currentProjectInfo.id = projectId;
                currentProjectInfo.name = projectName;
            }
            
            sessionStorage.setItem('currentProjectId', projectId);
            sessionStorage.setItem('currentProjectName', projectName);
            
            // 更新PHP會話 - 這一步將保持載入畫面
            return fetch('set_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'gbd_project_id=' + encodeURIComponent(projectId) + 
                      '&gbd_project_name=' + encodeURIComponent(projectName)
            })
            .then(sessionResponse => sessionResponse.json())
            .then(sessionData => {
                // 即使會話更新失敗也繼續執行
                if (!sessionData.success) {
                    console.warn('會話更新警告:', sessionData.message);
                }
                
                // 更新導航欄
                if (typeof forceUpdateNavbar === 'function') {
                    forceUpdateNavbar(projectId, projectName);
                }
                
                // 隱藏歷史部分，顯示計算器內容
                if (document.getElementById('history-section')) {
                    document.getElementById('history-section').style.display = 'none';
                }
                if (document.getElementById('calculatorContent')) {
                    document.getElementById('calculatorContent').style.display = 'block';
                }
                
                // 渲染專案數據 - 這是最後一步
                if (typeof renderProjectData === 'function') {
                    // 先執行渲染
                    renderProjectData(data.projectData);
                    
                    // 渲染完成後，使用setTimeout確保DOM完全更新後再隱藏載入提示
                    setTimeout(() => {
                        hideLoadingOverlay();
                    }, 500); // 給予更多時間確保渲染完成
                } else {
                    // 如果無法渲染，也要隱藏載入提示
                    hideLoadingOverlay();
                }
                
                return data;
            });
        } else {
            throw new Error(data.message || '無法載入專案資料');
        }
    })
    .catch(error => {
        console.error('載入專案時發生錯誤:', error);
        
        // 隱藏載入提示
        hideLoadingOverlay();
        
        // 顯示錯誤並處理
        alert('載入資料時發生錯誤: ' + error.message);
        
        // 如果在正確的頁面上，嘗試加載專案歷史
        if (window.location.href.includes('greenbuildingcal-past.php') && typeof loadProjectHistory === 'function') {
            loadProjectHistory();
        } else {
            // 否則重新導航
            window.location.href = 'greenbuildingcal-past.php';
        }
    });
}

// 頁面載入時檢查是否需要顯示載入畫面
document.addEventListener('DOMContentLoaded', function() {
    // 檢查URL參數和會話存儲
    const urlParams = new URLSearchParams(window.location.search);
    const autoloadParam = urlParams.get('autoload');
    const shouldShowLoading = sessionStorage.getItem('showLoadingAfterRedirect');
    const pendingProjectId = sessionStorage.getItem('pendingProjectId');
    
    // 如果從其他頁面跳轉過來並且需要顯示載入中
    if (shouldShowLoading === 'true' && pendingProjectId) {
        // 清除會話存儲標記
        sessionStorage.removeItem('showLoadingAfterRedirect');
        sessionStorage.removeItem('pendingProjectId');
        
        // 顯示載入中並加載專案
        showLoadingOverlay();
        
        // 給瀏覽器一點時間來渲染載入畫面
        setTimeout(() => {
            loadProjectData(pendingProjectId);
        }, 100);
    }
    // 或者如果URL中有autoload參數
    else if (autoloadParam) {
        showLoadingOverlay();
        
        // 給瀏覽器一點時間來渲染載入畫面
        setTimeout(() => {
            loadProjectData(autoloadParam);
        }, 100);
    }
});

// 離開當前專案
function leaveCurrentProject() {
    if (confirm("確定要離開當前專案嗎？")) {
        // 清除專案資訊
        clearProjectInfo();
        
        // 更新PHP Session
        fetch('set_session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'clear_project=1'
        })
        .then(response => response.json())
        .then(data => {
            console.log("已離開專案:", data);
            // 顯示"尚未選取專案"
            displayNoProjectSelected();
        })
        .catch(err => {
            console.error("離開專案失敗:", err);
        });
    }
}
</script>

<style>
.custom-navbar {
    background-color: #769a76;
}

.nav-link {
    position: relative;
    color: rgba(255, 255, 255, 0.7) !important;
    transition: color 0.3s ease;
}

.nav-link:hover {
    color: white !important;
}

.nav-link.active {
    color: white !important;
    font-weight: bold;
}

/* 完全移除下拉選單的三角形圖示 */
.nav-link.dropdown-toggle::after {
    display: none;
}

/* 底線效果統一使用 before */
.nav-link:before {
    content: '';
    position: absolute;
    width: 0;
    height: 2px;
    bottom: 0;
    left: 50%;
    background-color: white;
    transition: all 0.3s ease;
    transform: translateX(-50%);
}

/* 懸停和當前頁面的底線效果 */
.nav-link:hover:before,
.nav-link.active:before {
    width: 100%;
}

/* 下拉選單樣式 */
.dropdown-menu {
    background-color: #769a76;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.25rem; /* 添加圓角 */
}

.dropdown-item {
    color: rgba(255, 255, 255, 0.7);
    transition: all 0.3s ease;
}

.dropdown-item:hover {
    background-color: rgba(255, 255, 255, 0.1);
    color: white;
}

/* 滑鼠懸停時顯示下拉選單 */
.dropdown:hover .dropdown-menu {
    display: block;
}

.current-project-indicator {
  background-color: rgba(255, 255, 255, 0.15);
  padding: 5px 12px;
  border-radius: 20px;
  margin-left: 15px;
  display: flex;
  align-items: center;
  font-size: 0.9rem;
}

.project-label {
  color: rgba(255, 255, 255, 0.7);
  margin-right: 5px;
}

.project-name {
  color: white;
  font-weight: bold;
}

/* 為小屏幕設置樣式 */
@media (max-width: 992px) {
  .current-project-indicator {
    margin: 8px 0;
    justify-content: center;
  }
}

/* 為專案名稱下拉選單添加樣式 */
.project-dropdown .dropdown-menu {
    margin-left: 20px;
}

.project-actions-menu {
    min-width: 150px;
    background-color: #769a76;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.50rem; /* 添加圓角，與其他下拉選單一致 */
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.project-actions-menu .dropdown-item {
    color: rgba(255, 255, 255, 0.7); /* 與其他下拉選單項目顏色一致 */
    padding: 8px 16px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.project-actions-menu .dropdown-item:hover {
    background-color: rgba(255, 255, 255, 0.1); /* 與其他下拉選單項目懸停效果一致 */
    color: white;
}

.project-actions-menu .dropdown-item.text-danger {
    color: rgba(255, 255, 255, 0.7) !important; /* 保持與其他項目一致 */
}

.project-actions-menu .dropdown-item.text-danger:hover {
    background-color: rgba(255, 255, 255, 0.1);
    color: white !important;
}

.current-project-indicator {
    cursor: pointer;
}

/* 確保專案名稱與其他導航項目在相同水平線上 */
.project-dropdown .nav-link {
    padding-top: 8px;
    padding-bottom: 8px;
    line-height: 1.5;
    display: flex;
    align-items: center;
}

/* 確保所有導航項目水平對齊 */
.navbar-nav {
    align-items: center;
}

/* 移除專案下拉選單的margin，保持與其他項目一致 */
.project-dropdown {
    margin: 0;
}

/* 等待載入專案 */
.loading-spinner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.loading-spinner:before {
    content: '';
    width: 40px;
    height: 40px;
    margin-bottom: 10px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

</style>