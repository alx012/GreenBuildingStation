<?php 
// 確保在任何 HTML 輸出之前啟動 session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 確保 language.php 已被包含
if (!function_exists('__')) {
    include('language.php');
}

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

<nav class="navbar navbar-expand-lg navbar-dark fixed-top custom-navbar">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">GreenBuildingStation</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarSupportedContent" aria-controls="#navbarSupportedContent"
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
      </ul>
      <ul class="navbar-nav ms-auto">
        <!-- 語言切換按鈕 -->
        <li class="nav-item">
          <select class="form-select form-select-sm mt-2" 
                  onchange="window.location.href='language.php?lang='+this.value" 
                  style="background-color: transparent; color: white; border-color: rgba(255,255,255,0.5);">
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
</style>