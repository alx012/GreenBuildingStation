<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 檢查是否有建築 ID
if (!isset($_GET['building_id']) && !isset($_SESSION['building_id'])) {
    header('Location: greenbuildingcal-new.php');
    exit;
}

$building_id = $_GET['building_id'] ?? $_SESSION['building_id'];
$_SESSION['building_id'] = $building_id;

include('language.php');
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Speckle 模型匯入</title>
    
    <!-- 引入 Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <style>
        body {
            margin-top: 100px;
            background-color: #f8f9fa;
        }
        
        .main-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .speckle-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin-bottom: 20px;
        }
        
        .step-indicator {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .step {
            display: flex;
            align-items: center;
            flex: 1;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #769a76;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .step.completed .step-number {
            background: #28a745;
        }
        
        .step.active .step-number {
            background: #007bff;
        }
        
        .step-line {
            height: 2px;
            background: #ddd;
            flex: 1;
            margin: 0 20px;
        }
        
        .step.completed + .step .step-line,
        .step.active .step-line {
            background: #28a745;
        }
        
        .btn-speckle {
            background-color: #769a76;
            border-color: #769a76;
            color: white;
        }
        
        .btn-speckle:hover {
            background-color: #658965;
            border-color: #658965;
            color: white;
        }
        
        .model-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .model-card:hover {
            border-color: #769a76;
            box-shadow: 0 2px 8px rgba(118, 154, 118, 0.2);
        }
        
        .model-card.selected {
            border-color: #769a76;
            background-color: rgba(118, 154, 118, 0.1);
        }
        
        .progress-container {
            margin: 20px 0;
        }
        
        .hidden {
            display: none;
        }
        
        .alert-custom {
            border-radius: 8px;
            border: none;
        }
    </style>
</head>
<body>
    <?php include('navbar.php'); ?>
    
    <div class="main-container">
        <!-- 頁面標題 -->
        <div class="text-center mb-4">
            <h2><i class="fas fa-cube me-2"></i>從 Speckle 匯入 Revit 模型</h2>
            <p class="text-muted">將您的 Revit 模型從 Speckle 匯入到系統中進行分析</p>
        </div>
        
        <!-- 步驟指示器 -->
        <div class="speckle-card">
            <div class="step-indicator">
                <div class="step active" id="step1">
                    <div class="step-number">1</div>
                    <span>驗證 Token</span>
                </div>
                <div class="step-line"></div>
                <div class="step" id="step2">
                    <div class="step-number">2</div>
                    <span>選擇模型</span>
                </div>
                <div class="step-line"></div>
                <div class="step" id="step3">
                    <div class="step-number">3</div>
                    <span>匯入完成</span>
                </div>
            </div>
        </div>
        
        <!-- 步驟 1: Token 輸入 -->
        <div class="speckle-card" id="tokenStep">
            <h4><i class="fas fa-key me-2"></i>步驟 1: 輸入 Speckle 存取權杖</h4>
            <div class="alert alert-info alert-custom">
                <h6><i class="fas fa-info-circle me-2"></i>如何取得 Speckle Token？</h6>
                <ol class="mb-2">
                    <li>前往 <a href="https://speckle.xyz/profile" target="_blank" class="text-decoration-none">Speckle Profile 頁面</a></li>
                    <li>登入您的 Speckle 帳戶</li>
                    <li>在 "Personal Access Tokens" 區域創建新的 Token</li>
                    <li>複製 Token 並貼到下方欄位</li>
                </ol>
            </div>
            
            <div class="mb-3">
                <label for="speckleToken" class="form-label">Speckle 存取權杖 *</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="speckleToken" 
                           placeholder="請貼上您的 Speckle Token">
                    <button class="btn btn-outline-secondary" type="button" onclick="toggleTokenVisibility()">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>
            
            <button type="button" class="btn btn-speckle btn-lg" onclick="validateToken()">
                <i class="fas fa-check me-2"></i>驗證並載入專案
            </button>
            
            <div id="tokenLoading" class="hidden">
                <div class="progress-container">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             style="width: 100%"></div>
                    </div>
                    <p class="text-center mt-2">正在連接 Speckle 服務...</p>
                </div>
            </div>
        </div>
        
        <!-- 步驟 2: 專案和模型選擇 -->
        <div class="speckle-card hidden" id="modelStep">
            <h4><i class="fas fa-cubes me-2"></i>步驟 2: 選擇要匯入的模型</h4>
            
            <div class="mb-3">
                <label for="speckleProject" class="form-label">選擇 Speckle 專案</label>
                <select class="form-select" id="speckleProject" onchange="loadModels()">
                    <option value="">-- 請選擇專案 --</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">選擇模型</label>
                <div id="modelsList" class="mt-2">
                    <p class="text-muted">請先選擇專案</p>
                </div>
            </div>
            
            <div class="d-flex gap-3">
                <button type="button" class="btn btn-secondary" onclick="goBackToStep1()">
                    <i class="fas fa-arrow-left me-2"></i>返回上一步
                </button>
                <button type="button" class="btn btn-speckle btn-lg" onclick="importModel()" disabled id="importBtn">
                    <i class="fas fa-download me-2"></i>匯入選中的模型
                </button>
            </div>
        </div>
        
        <!-- 步驟 3: 匯入完成 -->
        <div class="speckle-card hidden" id="completeStep">
            <div class="text-center">
                <div class="mb-4">
                    <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                </div>
                <h4 class="text-success">模型匯入完成！</h4>
                <p class="text-muted mb-4">您的 Revit 模型已成功從 Speckle 匯入到系統中</p>
                
                <div class="d-flex justify-content-center gap-3">
                    <a href="greenbuildingcal-new.php" class="btn btn-speckle btn-lg">
                        <i class="fas fa-arrow-left me-2"></i>返回專案列表
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-lg" onclick="viewModel()">
                        <i class="fas fa-eye me-2"></i>檢視匯入的模型
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let speckleProjects = [];
        let selectedProject = null;
        let selectedModel = null;
        let currentToken = '';
        
        // Token 可見性切換
        function toggleTokenVisibility() {
            const tokenInput = document.getElementById('speckleToken');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (tokenInput.type === 'password') {
                tokenInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                tokenInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
        
        // 驗證 Token 並載入專案
        function validateToken() {
            const token = document.getElementById('speckleToken').value.trim();
            
            if (!token) {
                alert('請輸入 Speckle Token');
                return;
            }
            
            currentToken = token;
            
            // 顯示載入狀態
            document.getElementById('tokenLoading').classList.remove('hidden');
            
            // 發送請求到後端
            fetch('greenbuildingcal-new.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=getSpeckleProjects&token=' + encodeURIComponent(token)
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('tokenLoading').classList.add('hidden');
                
                if (data.success) {
                    speckleProjects = data.projects;
                    populateProjects();
                    goToStep2();
                } else {
                    alert('Token 驗證失敗: ' + data.message);
                }
            })
            .catch(error => {
                document.getElementById('tokenLoading').classList.add('hidden');
                console.error('Error:', error);
                alert('連接 Speckle 時發生錯誤');
            });
        }
        
        // 填充專案列表
        function populateProjects() {
            const select = document.getElementById('speckleProject');
            select.innerHTML = '<option value="">-- 請選擇專案 --</option>';
            
            speckleProjects.forEach((project, index) => {
                const option = document.createElement('option');
                option.value = index;
                option.textContent = project.name + (project.description ? ` (${project.description})` : '');
                select.appendChild(option);
            });
        }
        
        // 載入模型列表
        function loadModels() {
            const projectIndex = document.getElementById('speckleProject').value;
            const modelsList = document.getElementById('modelsList');
            
            if (!projectIndex) {
                modelsList.innerHTML = '<p class="text-muted">請先選擇專案</p>';
                document.getElementById('importBtn').disabled = true;
                return;
            }
            
            selectedProject = speckleProjects[projectIndex];
            selectedModel = null;
            
            if (!selectedProject.models || !selectedProject.models.items || selectedProject.models.items.length === 0) {
                modelsList.innerHTML = '<p class="text-warning">此專案沒有可用的模型</p>';
                document.getElementById('importBtn').disabled = true;
                return;
            }
            
            let modelsHtml = '';
            selectedProject.models.items.forEach((model, index) => {
                modelsHtml += `
                    <div class="model-card" onclick="selectModel(${index})">
                        <div class="d-flex align-items-center">
                            <input type="radio" name="selectedModel" value="${index}" class="me-3">
                            <div>
                                <h6 class="mb-1">${model.name}</h6>
                                ${model.description ? `<p class="text-muted mb-0 small">${model.description}</p>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            modelsList.innerHTML = modelsHtml;
            document.getElementById('importBtn').disabled = true;
        }
        
        // 選擇模型
        function selectModel(modelIndex) {
            // 移除所有選中狀態
            document.querySelectorAll('.model-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // 選中當前模型
            event.currentTarget.classList.add('selected');
            const radio = event.currentTarget.querySelector('input[type="radio"]');
            radio.checked = true;
            
            selectedModel = selectedProject.models.items[modelIndex];
            document.getElementById('importBtn').disabled = false;
        }
        
        // 匯入模型
        function importModel() {
            if (!selectedModel || !selectedProject) {
                alert('請先選擇要匯入的模型');
                return;
            }
            
            // 顯示載入狀態
            const importBtn = document.getElementById('importBtn');
            const originalText = importBtn.innerHTML;
            importBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>匯入中...';
            importBtn.disabled = true;
            
            // 發送匯入請求
            const formData = new FormData();
            formData.append('action', 'saveSpeckleData');
            formData.append('speckleProjectId', selectedProject.id);
            formData.append('speckleModelId', selectedModel.id);
            
            fetch('greenbuildingcal-new.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    goToStep3();
                } else {
                    alert('匯入失敗: ' + data.message);
                    importBtn.innerHTML = originalText;
                    importBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('匯入時發生錯誤');
                importBtn.innerHTML = originalText;
                importBtn.disabled = false;
            });
        }
        
        // 步驟導航
        function goToStep2() {
            document.getElementById('step1').classList.remove('active');
            document.getElementById('step1').classList.add('completed');
            document.getElementById('step2').classList.add('active');
            
            document.getElementById('tokenStep').classList.add('hidden');
            document.getElementById('modelStep').classList.remove('hidden');
        }
        
        function goBackToStep1() {
            document.getElementById('step1').classList.add('active');
            document.getElementById('step1').classList.remove('completed');
            document.getElementById('step2').classList.remove('active');
            
            document.getElementById('tokenStep').classList.remove('hidden');
            document.getElementById('modelStep').classList.add('hidden');
        }
        
        function goToStep3() {
            document.getElementById('step2').classList.remove('active');
            document.getElementById('step2').classList.add('completed');
            document.getElementById('step3').classList.add('active');
            
            document.getElementById('modelStep').classList.add('hidden');
            document.getElementById('completeStep').classList.remove('hidden');
        }
        
        // 檢視模型（可以擴展為顯示 3D 檢視器）
        function viewModel() {
            alert('3D 模型檢視功能即將推出！');
        }
    </script>
</body>
</html> 