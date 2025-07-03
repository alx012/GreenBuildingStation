<?php
require_once 'db_connection.php';

/**
 * 平面圖上傳和處理類別
 * 使用 Google Gemini 2.0 Flash 進行AI圖像分析
 */
class FloorplanUploader {
    private $uploadDir = 'uploads/floorplans/';
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    private $maxFileSize = 10 * 1024 * 1024; // 10MB
    private $geminiApiKey;
    private $geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent';
    
    public function __construct() {
        // 確保上傳目錄存在
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        
        // 設置 Gemini API Key（請在環境變數或配置文件中設置）
        $this->geminiApiKey = $this->getGeminiApiKey();
    }
    
    /**
     * 獲取 Gemini API Key
     */
    private function getGeminiApiKey() {
        // 優先從環境變數獲取
        $apiKey = getenv('GEMINI_API_KEY');
        
        // 如果環境變數沒有，嘗試從配置文件獲取
        if (empty($apiKey) && file_exists('config/gemini_config.php')) {
            include 'config/gemini_config.php';
            $apiKey = $GEMINI_API_KEY ?? '';
        }
        
        // 如果還是沒有，使用預設值（請替換為您的實際API Key）
        if (empty($apiKey)) {
            $apiKey = 'YOUR_GEMINI_API_KEY_HERE'; // 請替換為實際的API Key
        }
        
        return $apiKey;
    }
    
    /**
     * 處理檔案上傳和分析
     */
    public function handleUpload($fileData, $building_id) {
        try {
            // 驗證檔案
            $validation = $this->validateFile($fileData);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }
            
            // 檢查API Key
            if (empty($this->geminiApiKey) || $this->geminiApiKey === 'YOUR_GEMINI_API_KEY_HERE') {
                return [
                    'success' => false, 
                    'error' => '請設置 Gemini API Key。請在環境變數 GEMINI_API_KEY 中設置您的API Key'
                ];
            }
            
            // 儲存檔案
            $fileName = $this->saveFile($fileData, $building_id);
            if (!$fileName) {
                return ['success' => false, 'error' => '檔案儲存失敗'];
            }
            
            $filePath = $this->uploadDir . $fileName;
            
            // 使用 Gemini API 分析平面圖
            $analysisResult = $this->analyzeFloorplanWithGemini($filePath);
            
            if ($analysisResult['success']) {
                // 儲存分析結果到資料庫
                $saved = $this->saveToBuildingData($analysisResult, $building_id);
                if ($saved) {
                    error_log("平面圖分析成功並儲存: building_id={$building_id}, 檔案={$fileName}");
                    return [
                        'success' => true,
                        'fileName' => $fileName,
                        'analysisResult' => $analysisResult,
                        'message' => '平面圖AI分析完成並已儲存建築資料'
                    ];
                } else {
                    return [
                        'success' => true,
                        'fileName' => $fileName,
                        'analysisResult' => $analysisResult,
                        'message' => '平面圖AI分析完成，但儲存資料時發生警告'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error' => 'AI圖檔分析失敗: ' . $analysisResult['error']
                ];
            }
            
        } catch (Exception $e) {
            error_log('平面圖上傳處理錯誤: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => '處理過程中發生錯誤: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 驗證上傳檔案
     */
    private function validateFile($fileData) {
        if ($fileData['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => '檔案上傳失敗: ' . $this->getUploadErrorMessage($fileData['error'])];
        }
        
        if ($fileData['size'] > $this->maxFileSize) {
            return ['valid' => false, 'error' => '檔案大小超過限制（最大10MB）'];
        }
        
        if ($fileData['size'] == 0) {
            return ['valid' => false, 'error' => '檔案是空的'];
        }
        
        // 檢查MIME類型
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileData['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            return ['valid' => false, 'error' => '不支援的檔案格式，請上傳 JPG、PNG 或 GIF 檔案'];
        }
        
        // 檢查是否為有效的圖像
        $imageInfo = getimagesize($fileData['tmp_name']);
        if ($imageInfo === false) {
            return ['valid' => false, 'error' => '檔案不是有效的圖像格式'];
        }
        
        // 檢查圖像尺寸
        if ($imageInfo[0] < 100 || $imageInfo[1] < 100) {
            return ['valid' => false, 'error' => '圖像尺寸太小，建議至少800x600像素'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * 取得上傳錯誤訊息
     */
    private function getUploadErrorMessage($errorCode) {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return '檔案超過系統限制大小';
            case UPLOAD_ERR_FORM_SIZE:
                return '檔案超過表單限制大小';
            case UPLOAD_ERR_PARTIAL:
                return '檔案只有部分被上傳';
            case UPLOAD_ERR_NO_FILE:
                return '沒有檔案被上傳';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '缺少臨時資料夾';
            case UPLOAD_ERR_CANT_WRITE:
                return '檔案寫入失敗';
            case UPLOAD_ERR_EXTENSION:
                return '檔案上傳被PHP擴展阻止';
            default:
                return '未知錯誤';
        }
    }
    
    /**
     * 儲存檔案
     */
    private function saveFile($fileData, $building_id) {
        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        $fileName = 'floorplan_' . $building_id . '_' . time() . '.' . $extension;
        $targetPath = $this->uploadDir . $fileName;
        
        if (move_uploaded_file($fileData['tmp_name'], $targetPath)) {
            return $fileName;
        }
        
        return false;
    }
    
    /**
     * 使用 Gemini API 分析平面圖
     */
    private function analyzeFloorplanWithGemini($imagePath) {
        try {
            // 讀取圖像並轉換為 base64
            $imageData = file_get_contents($imagePath);
            if ($imageData === false) {
                throw new Exception('無法讀取圖像檔案');
            }
            
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($imagePath);
            
            // 準備 Gemini API 請求
            $prompt = $this->buildAnalysisPrompt();
            
            $requestData = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Image
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'topP' => 0.8,
                    'topK' => 10,
                    'maxOutputTokens' => 8192,
                    'responseMimeType' => 'application/json'
                ]
            ];
            
            // 發送請求到 Gemini API
            $response = $this->sendGeminiRequest($requestData);
            
            if ($response['success']) {
                return $this->parseGeminiResponse($response['data']);
            } else {
                throw new Exception($response['error']);
            }
            
        } catch (Exception $e) {
            error_log('Gemini API 分析錯誤: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 建構分析提示詞
     */
    private function buildAnalysisPrompt() {
        return '請仔細分析這張建築平面圖，並以JSON格式回傳以下詳細資訊：

{
  "success": true,
  "floors": [
    {
      "floor_number": 1,
      "area": 100.5,
      "units": [
        {
          "unit_number": 1,
          "area": 50.2,
          "rooms": [
            {
              "room_number": 1,
              "name": "客廳",
              "type": "living_room",
              "area": 20.5,
              "length": 5.0,
              "width": 4.1,
              "height": 3.0,
              "walls": [
                {
                  "wall_id": 1,
                  "orientation": "北",
                  "length": 5.0,
                  "area": 15.0,
                  "windows": [
                    {
                      "window_id": 1,
                      "orientation": "北",
                      "width": 1.5,
                      "height": 1.2,
                      "area": 1.8
                    }
                  ]
                },
                {
                  "wall_id": 2,
                  "orientation": "東",
                  "length": 4.1,
                  "area": 12.3,
                  "windows": []
                }
              ]
            }
          ]
        }
      ]
    }
  ]
}

分析要求：
1. 仔細識別所有封閉的房間區域，包括有中文標示的房間
2. 測量每個房間的大致尺寸（長度、寬度），假設一般住宅房間高度為3.0公尺
3. 識別每個房間的牆面方位（東、西、南、北）
4. 找出每面牆上的窗戶位置和大小
5. 估算面積時請使用合理的住宅尺度
6. 房間類型請根據標示文字和大小判斷（客廳、臥室、廚房、浴室、儲藏室等）
7. 確保返回有效的JSON格式

請分析圖中所有可識別的房間，不要遺漏任何標示的區域。';
    }
    
    /**
     * 發送 Gemini API 請求
     */
    private function sendGeminiRequest($requestData) {
        $url = $this->geminiApiUrl . '?key=' . $this->geminiApiKey;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60秒超時
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'cURL錯誤: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            $errorInfo = json_decode($response, true);
            $errorMessage = isset($errorInfo['error']['message']) ? 
                $errorInfo['error']['message'] : 
                "HTTP錯誤 {$httpCode}";
            
            return [
                'success' => false,
                'error' => "Gemini API錯誤: {$errorMessage}"
            ];
        }
        
        return [
            'success' => true,
            'data' => $response
        ];
    }
    
    /**
     * 解析 Gemini 回應
     */
    private function parseGeminiResponse($responseData) {
        try {
            $data = json_decode($responseData, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Gemini 回應不是有效的JSON: ' . json_last_error_msg());
            }
            
            // 檢查回應結構
            if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('Gemini 回應結構不正確');
            }
            
            $analysisText = $data['candidates'][0]['content']['parts'][0]['text'];
            
            // 解析分析結果JSON
            $analysisResult = json_decode($analysisText, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('分析結果不是有效的JSON: ' . json_last_error_msg());
            }
            
            // 驗證必要的欄位
            if (!isset($analysisResult['success']) || !isset($analysisResult['floors'])) {
                throw new Exception('分析結果缺少必要欄位');
            }
            
            // 轉換格式以相容現有系統
            return $this->convertGeminiResultToStandardFormat($analysisResult);
            
        } catch (Exception $e) {
            error_log('解析 Gemini 回應錯誤: ' . $e->getMessage());
            error_log('原始回應: ' . $responseData);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 轉換 Gemini 結果為標準格式
     */
    private function convertGeminiResultToStandardFormat($geminiResult) {
        $standardResult = [
            'success' => true,
            'floors' => [],
            'units' => [],
            'rooms' => [],
            'windows' => []
        ];
        
        foreach ($geminiResult['floors'] as $floor) {
            // 處理樓層
            $standardResult['floors'][] = [
                'floor_number' => $floor['floor_number'],
                'area' => $floor['area'] ?? 0
            ];
            
            // 處理單元
            if (isset($floor['units'])) {
                foreach ($floor['units'] as $unit) {
                    $standardResult['units'][] = [
                        'unit_number' => $unit['unit_number'],
                        'area' => $unit['area'] ?? 0,
                        'width' => $unit['width'] ?? 0,
                        'height' => $unit['height'] ?? 0
                    ];
                    
                    // 處理房間
                    if (isset($unit['rooms'])) {
                        foreach ($unit['rooms'] as $room) {
                            $roomData = [
                                'room_number' => $room['room_number'],
                                'name' => $room['name'] ?? 'Room ' . $room['room_number'],
                                'type' => $room['type'] ?? 'unknown',
                                'area' => $room['area'] ?? 0,
                                'length' => $room['length'] ?? 0,
                                'width' => $room['width'] ?? 0,
                                'height' => $room['height'] ?? 3.0,
                                'walls' => $room['walls'] ?? []
                            ];
                            
                            $standardResult['rooms'][] = $roomData;
                            
                            // 處理窗戶
                            if (isset($room['walls'])) {
                                foreach ($room['walls'] as $wall) {
                                    if (isset($wall['windows'])) {
                                        foreach ($wall['windows'] as $window) {
                                            $standardResult['windows'][] = [
                                                'window_id' => $window['window_id'],
                                                'room_id' => $room['room_number'],
                                                'orientation' => $window['orientation'],
                                                'width' => $window['width'] ?? 0,
                                                'height' => $window['height'] ?? 0,
                                                'area' => $window['area'] ?? 0
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $standardResult;
    }
    
    /**
     * 將分析結果儲存到資料庫
     */
    public function saveToBuildingData($analysisResult, $building_id) {
        global $serverName, $database, $username, $password;
        
        try {
            $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();
            
            // 清除現有的平面圖分析資料
            $this->clearExistingFloorplanData($conn, $building_id);
            
            // 準備插入語句
            $stmtFloor = $conn->prepare("
                INSERT INTO [Test].[dbo].[GBD_Project_floors] 
                (building_id, floor_number, floor_area, created_at) 
                VALUES (:building_id, :floor_number, :floor_area, GETDATE())
            ");
            
            $stmtUnit = $conn->prepare("
                INSERT INTO [Test].[dbo].[GBD_Project_units] 
                (floor_id, unit_number, unit_area, created_at) 
                VALUES (:floor_id, :unit_number, :unit_area, GETDATE())
            ");
            
            $stmtRoom = $conn->prepare("
                INSERT INTO [Test].[dbo].[GBD_Project_rooms] 
                (unit_id, room_number, height, length, depth, room_area, 
                 wall_orientation, window_position, window_area, room_type, created_at, updated_at) 
                VALUES (:unit_id, :room_number, :height, :length, :depth, :room_area,
                        :wall_orientation, :window_position, :window_area, :room_type, GETDATE(), GETDATE())
            ");
            
            // 處理樓層資料
            $floors = $analysisResult['floors'] ?? [];
            if (empty($floors)) {
                $floors = [['floor_number' => 1, 'area' => 0]];
            }
            
            foreach ($floors as $floorIndex => $floorData) {
                $floorNumber = $floorData['floor_number'] ?? ($floorIndex + 1);
                $floorArea = $floorData['area'] ?? 0;
                
                $stmtFloor->execute([
                    ':building_id' => $building_id,
                    ':floor_number' => $floorNumber,
                    ':floor_area' => $floorArea
                ]);
                
                $floor_id = $conn->lastInsertId();
                
                // 處理單元資料
                $units = $analysisResult['units'] ?? [];
                if (empty($units)) {
                    $units = [['unit_number' => 1, 'area' => 0]];
                }
                
                foreach ($units as $unitIndex => $unitData) {
                    $unitNumber = $unitData['unit_number'] ?? ($unitIndex + 1);
                    $unitArea = $unitData['area'] ?? 0;
                    
                    $stmtUnit->execute([
                        ':floor_id' => $floor_id,
                        ':unit_number' => $unitNumber,
                        ':unit_area' => $unitArea
                    ]);
                    
                    $unit_id = $conn->lastInsertId();
                    
                    // 處理房間資料
                    $rooms = $analysisResult['rooms'] ?? [];
                    if (empty($rooms)) {
                        $rooms = [['room_number' => 1, 'area' => 0]];
                    }
                    
                    // 將房間分配給單元
                    $roomsPerUnit = ceil(count($rooms) / count($units));
                    $startIndex = $unitIndex * $roomsPerUnit;
                    $unitRooms = array_slice($rooms, $startIndex, $roomsPerUnit);
                    
                    if (empty($unitRooms)) {
                        $unitRooms = [['room_number' => 1, 'area' => 0]];
                    }
                    
                    foreach ($unitRooms as $roomIndex => $roomData) {
                        $roomNumber = $roomData['name'] ?? ('Room ' . ($roomIndex + 1));
                        $roomArea = $roomData['area'] ?? 0;
                        $length = $roomData['length'] ?? $roomData['width'] ?? 0;
                        $depth = $roomData['depth'] ?? $roomData['height'] ?? 0;
                        $height = $roomData['height'] ?? 3.0;
                        $roomType = $roomData['type'] ?? 'unknown';
                        
                        // 處理牆面和窗戶資訊
                        $wallOrientations = [];
                        $windowPositions = [];
                        $totalWindowArea = 0;
                        
                        if (isset($roomData['walls'])) {
                            foreach ($roomData['walls'] as $wall) {
                                $wallOrientations[] = $wall['orientation'] ?? '';
                                
                                if (isset($wall['windows'])) {
                                    foreach ($wall['windows'] as $window) {
                                        $windowPositions[] = $window['orientation'] ?? '';
                                        $totalWindowArea += $window['area'] ?? 0;
                                    }
                                }
                            }
                        }
                        
                        $stmtRoom->execute([
                            ':unit_id' => $unit_id,
                            ':room_number' => $roomNumber,
                            ':height' => $height,
                            ':length' => $length,
                            ':depth' => $depth,
                            ':room_area' => $roomArea,
                            ':wall_orientation' => implode(',', array_unique($wallOrientations)),
                            ':window_position' => implode(',', array_unique($windowPositions)),
                            ':window_area' => $totalWindowArea,
                            ':room_type' => $roomType
                        ]);
                    }
                }
            }
            
            $conn->commit();
            error_log("Gemini 平面圖分析結果已成功儲存到資料庫: building_id={$building_id}");
            return true;
            
        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("儲存 Gemini 分析結果到資料庫時發生錯誤: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 清除現有的平面圖分析資料
     */
    private function clearExistingFloorplanData($conn, $building_id) {
        // 清除順序：rooms -> units -> floors
        $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_rooms] 
            WHERE unit_id IN (
                SELECT unit_id FROM [Test].[dbo].[GBD_Project_units] 
                WHERE floor_id IN (
                    SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] 
                    WHERE building_id = :building_id
                )
            )
        ")->execute([':building_id' => $building_id]);
        
        $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_units] 
            WHERE floor_id IN (
                SELECT floor_id FROM [Test].[dbo].[GBD_Project_floors] 
                WHERE building_id = :building_id
            )
        ")->execute([':building_id' => $building_id]);
        
        $conn->prepare("
            DELETE FROM [Test].[dbo].[GBD_Project_floors] 
            WHERE building_id = :building_id
        ")->execute([':building_id' => $building_id]);
        
        error_log("已清除building_id={$building_id}的現有平面圖分析資料");
    }
}
?> 