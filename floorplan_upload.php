<?php
require_once 'db_connection.php';

/**
 * 平面圖上傳和處理類別
 * 實作基於線段分解的閉合區域識別算法
 */
class FloorplanUploader {
    private $uploadDir = 'uploads/floorplans/';
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    private $maxFileSize = 10 * 1024 * 1024; // 10MB
    
    public function __construct() {
        // 確保上傳目錄存在
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    /**
     * 處理檔案上傳
     */
    public function handleUpload($fileData, $building_id) {
        try {
            // 驗證檔案
            $validation = $this->validateFile($fileData);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }
            
            // 儲存檔案
            $fileName = $this->saveFile($fileData, $building_id);
            if (!$fileName) {
                return ['success' => false, 'error' => '檔案儲存失敗'];
            }
            
            // 分析圖檔
            $analysisResult = $this->analyzeFloorplan($this->uploadDir . $fileName);
            
            if ($analysisResult['success']) {
                // 儲存分析結果到資料庫
                $saved = $this->saveToBuildingData($analysisResult, $building_id);
                if ($saved) {
                    return [
                        'success' => true,
                        'fileName' => $fileName,
                        'analysisResult' => $analysisResult,
                        'message' => '平面圖分析完成並已儲存建築資料'
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => '分析成功但儲存資料失敗'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error' => '圖檔分析失敗: ' . $analysisResult['error']
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 驗證上傳檔案
     */
    private function validateFile($fileData) {
        if ($fileData['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => '檔案上傳失敗'];
        }
        
        if ($fileData['size'] > $this->maxFileSize) {
            return ['valid' => false, 'error' => '檔案大小超過限制（最大10MB）'];
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileData['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            return ['valid' => false, 'error' => '不支援的檔案格式，請上傳 JPG、PNG 或 GIF 檔案'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * 儲存檔案
     */
    private function saveFile($fileData, $building_id) {
        $extension = pathinfo($fileData['name'], PATHINFO_EXTENSION);
        $fileName = 'floorplan_' . $building_id . '_' . time() . '.' . $extension;
        $targetPath = $this->uploadDir . $fileName;
        
        if (move_uploaded_file($fileData['tmp_name'], $targetPath)) {
            return $fileName;
        }
        
        return false;
    }
    
    /**
     * 分析平面圖（簡化版）
     */
    public function analyzeFloorplan($imagePath, $scale = 0.01) {
        try {
            // 使用簡化的線段分析方法
            $segments = $this->extractSimpleSegments($imagePath);
            $regions = $this->findSimpleRegions($segments);
            $buildingElements = $this->classifyRegions($regions, $scale);
            
            return [
                'success' => true,
                'floors' => $buildingElements['floors'],
                'units' => $buildingElements['units'], 
                'rooms' => $buildingElements['rooms'],
                'windows' => $buildingElements['windows']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 簡化的線段提取
     */
    private function extractSimpleSegments($imagePath) {
        $image = $this->loadAndPreprocessImage($imagePath);
        $width = imagesx($image);
        $height = imagesy($image);
        $segments = [];
        
        // 簡化的邊緣檢測 - 尋找黑色像素的連續線段
        for ($y = 0; $y < $height; $y += 5) { // 減少掃描密度
            $lineStart = null;
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $brightness = ($pixel >> 16 & 0xFF) + ($pixel >> 8 & 0xFF) + ($pixel & 0xFF);
                
                if ($brightness < 200) { // 暗色像素
                    if ($lineStart === null) {
                        $lineStart = $x;
                    }
                } else {
                    if ($lineStart !== null && $x - $lineStart > 20) { // 最小線段長度
                        $segments[] = [
                            'start' => ['x' => $lineStart, 'y' => $y],
                            'end' => ['x' => $x - 1, 'y' => $y],
                            'type' => 'horizontal'
                        ];
                    }
                    $lineStart = null;
                }
            }
        }
        
        // 垂直線段檢測
        for ($x = 0; $x < $width; $x += 5) {
            $lineStart = null;
            for ($y = 0; $y < $height; $y++) {
                $pixel = imagecolorat($image, $x, $y);
                $brightness = ($pixel >> 16 & 0xFF) + ($pixel >> 8 & 0xFF) + ($pixel & 0xFF);
                
                if ($brightness < 200) {
                    if ($lineStart === null) {
                        $lineStart = $y;
                    }
                } else {
                    if ($lineStart !== null && $y - $lineStart > 20) {
                        $segments[] = [
                            'start' => ['x' => $x, 'y' => $lineStart],
                            'end' => ['x' => $x, 'y' => $y - 1],
                            'type' => 'vertical'
                        ];
                    }
                    $lineStart = null;
                }
            }
        }
        
        return $this->mergeNearbySegments($segments);
    }
    
    /**
     * 載入和預處理圖像
     */
    private function loadAndPreprocessImage($imagePath) {
        $imageInfo = getimagesize($imagePath);
        $mimeType = $imageInfo['mime'];
        
        switch($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($imagePath);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($imagePath);
                break;
            default:
                throw new Exception("不支援的圖像格式");
        }
        
        // 轉換為灰階並增強對比度
        $width = imagesx($image);
        $height = imagesy($image);
        $processed = imagecreatetruecolor($width, $height);
        
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                $gray = intval($r * 0.299 + $g * 0.587 + $b * 0.114);
                
                // 增強對比度 - 二值化
                $enhanced = $gray < 128 ? 0 : 255;
                $color = imagecolorallocate($processed, $enhanced, $enhanced, $enhanced);
                imagesetpixel($processed, $x, $y, $color);
            }
        }
        
        imagedestroy($image);
        return $processed;
    }
    
    /**
     * 合併鄰近線段
     */
    private function mergeNearbySegments($segments) {
        $merged = [];
        $used = array_fill(0, count($segments), false);
        
        for ($i = 0; $i < count($segments); $i++) {
            if ($used[$i]) continue;
            
            $currentSegment = $segments[$i];
            $used[$i] = true;
            
            // 尋找相同類型且可合併的線段
            for ($j = $i + 1; $j < count($segments); $j++) {
                if ($used[$j] || $segments[$j]['type'] !== $currentSegment['type']) continue;
                
                if ($this->canMergeSegments($currentSegment, $segments[$j])) {
                    $currentSegment = $this->mergeSegments($currentSegment, $segments[$j]);
                    $used[$j] = true;
                }
            }
            
            $merged[] = $currentSegment;
        }
        
        return $merged;
    }
    
    /**
     * 檢查是否可以合併線段
     */
    private function canMergeSegments($seg1, $seg2) {
        if ($seg1['type'] !== $seg2['type']) return false;
        
        $tolerance = 10; // 像素容差
        
        if ($seg1['type'] === 'horizontal') {
            // 水平線段：檢查Y座標是否相近，X座標是否連續
            return abs($seg1['start']['y'] - $seg2['start']['y']) <= $tolerance &&
                   (abs($seg1['end']['x'] - $seg2['start']['x']) <= $tolerance ||
                    abs($seg1['start']['x'] - $seg2['end']['x']) <= $tolerance);
        } else {
            // 垂直線段：檢查X座標是否相近，Y座標是否連續
            return abs($seg1['start']['x'] - $seg2['start']['x']) <= $tolerance &&
                   (abs($seg1['end']['y'] - $seg2['start']['y']) <= $tolerance ||
                    abs($seg1['start']['y'] - $seg2['end']['y']) <= $tolerance);
        }
    }
    
    /**
     * 合併線段
     */
    private function mergeSegments($seg1, $seg2) {
        if ($seg1['type'] === 'horizontal') {
            return [
                'start' => [
                    'x' => min($seg1['start']['x'], $seg1['end']['x'], $seg2['start']['x'], $seg2['end']['x']),
                    'y' => intval(($seg1['start']['y'] + $seg2['start']['y']) / 2)
                ],
                'end' => [
                    'x' => max($seg1['start']['x'], $seg1['end']['x'], $seg2['start']['x'], $seg2['end']['x']),
                    'y' => intval(($seg1['start']['y'] + $seg2['start']['y']) / 2)
                ],
                'type' => 'horizontal'
            ];
        } else {
            return [
                'start' => [
                    'x' => intval(($seg1['start']['x'] + $seg2['start']['x']) / 2),
                    'y' => min($seg1['start']['y'], $seg1['end']['y'], $seg2['start']['y'], $seg2['end']['y'])
                ],
                'end' => [
                    'x' => intval(($seg1['start']['x'] + $seg2['start']['x']) / 2),
                    'y' => max($seg1['start']['y'], $seg1['end']['y'], $seg2['start']['y'], $seg2['end']['y'])
                ],
                'type' => 'vertical'
            ];
        }
    }
    
    /**
     * 尋找簡單的閉合區域
     */
    private function findSimpleRegions($segments) {
        $regions = [];
        
        // 簡化的矩形檢測
        $horizontalLines = array_filter($segments, function($seg) {
            return $seg['type'] === 'horizontal';
        });
        
        $verticalLines = array_filter($segments, function($seg) {
            return $seg['type'] === 'vertical';
        });
        
        foreach ($horizontalLines as $topLine) {
            foreach ($horizontalLines as $bottomLine) {
                if ($topLine === $bottomLine) continue;
                if ($bottomLine['start']['y'] <= $topLine['start']['y']) continue;
                
                foreach ($verticalLines as $leftLine) {
                    foreach ($verticalLines as $rightLine) {
                        if ($leftLine === $rightLine) continue;
                        if ($rightLine['start']['x'] <= $leftLine['start']['x']) continue;
                        
                        // 檢查是否形成矩形
                        if ($this->linesFormRectangle($topLine, $bottomLine, $leftLine, $rightLine)) {
                            $regions[] = [
                                'topLeft' => ['x' => $leftLine['start']['x'], 'y' => $topLine['start']['y']],
                                'topRight' => ['x' => $rightLine['start']['x'], 'y' => $topLine['start']['y']],
                                'bottomRight' => ['x' => $rightLine['start']['x'], 'y' => $bottomLine['start']['y']],
                                'bottomLeft' => ['x' => $leftLine['start']['x'], 'y' => $bottomLine['start']['y']]
                            ];
                        }
                    }
                }
            }
        }
        
        return $this->removeDuplicateRegions($regions);
    }
    
    /**
     * 檢查四條線是否形成矩形
     */
    private function linesFormRectangle($top, $bottom, $left, $right) {
        $tolerance = 20;
        
        // 檢查水平線的X範圍是否重疊
        $topOverlap = $this->rangesOverlap(
            [$top['start']['x'], $top['end']['x']],
            [$left['start']['x'], $right['start']['x']]
        );
        
        $bottomOverlap = $this->rangesOverlap(
            [$bottom['start']['x'], $bottom['end']['x']],
            [$left['start']['x'], $right['start']['x']]
        );
        
        // 檢查垂直線的Y範圍是否重疊
        $leftOverlap = $this->rangesOverlap(
            [$left['start']['y'], $left['end']['y']],
            [$top['start']['y'], $bottom['start']['y']]
        );
        
        $rightOverlap = $this->rangesOverlap(
            [$right['start']['y'], $right['end']['y']],
            [$top['start']['y'], $bottom['start']['y']]
        );
        
        return $topOverlap && $bottomOverlap && $leftOverlap && $rightOverlap;
    }
    
    /**
     * 檢查兩個範圍是否重疊
     */
    private function rangesOverlap($range1, $range2) {
        $min1 = min($range1);
        $max1 = max($range1);
        $min2 = min($range2);
        $max2 = max($range2);
        
        return $max1 >= $min2 && $max2 >= $min1;
    }
    
    /**
     * 移除重複區域
     */
    private function removeDuplicateRegions($regions) {
        $unique = [];
        
        foreach ($regions as $region) {
            $isUnique = true;
            
            foreach ($unique as $existingRegion) {
                if ($this->areRegionsSimilar($region, $existingRegion)) {
                    $isUnique = false;
                    break;
                }
            }
            
            if ($isUnique) {
                $unique[] = $region;
            }
        }
        
        return $unique;
    }
    
    /**
     * 檢查兩區域是否相似
     */
    private function areRegionsSimilar($region1, $region2) {
        $tolerance = 50;
        
        $center1 = [
            'x' => ($region1['topLeft']['x'] + $region1['bottomRight']['x']) / 2,
            'y' => ($region1['topLeft']['y'] + $region1['bottomRight']['y']) / 2
        ];
        
        $center2 = [
            'x' => ($region2['topLeft']['x'] + $region2['bottomRight']['x']) / 2,
            'y' => ($region2['topLeft']['y'] + $region2['bottomRight']['y']) / 2
        ];
        
        $distance = sqrt(
            pow($center1['x'] - $center2['x'], 2) +
            pow($center1['y'] - $center2['y'], 2)
        );
        
        return $distance < $tolerance;
    }
    
    /**
     * 分類區域為建築元素
     */
    private function classifyRegions($regions, $scale) {
        $floors = [];
        $units = [];
        $rooms = [];
        $windows = [];
        
        foreach ($regions as $index => $region) {
            $width = abs($region['bottomRight']['x'] - $region['topLeft']['x']) * $scale;
            $height = abs($region['bottomRight']['y'] - $region['topLeft']['y']) * $scale;
            $area = $width * $height;
            
            if ($area > 200) {
                // 大區域 - 可能是樓層
                $floors[] = [
                    'floor_number' => count($floors) + 1,
                    'area' => $area,
                    'bounds' => $region
                ];
            } elseif ($area > 50) {
                // 中等區域 - 可能是單元
                $units[] = [
                    'unit_number' => count($units) + 1,
                    'area' => $area,
                    'width' => $width,
                    'height' => $height,
                    'bounds' => $region
                ];
            } elseif ($area > 5) {
                // 小區域 - 可能是房間
                $rooms[] = [
                    'room_number' => count($rooms) + 1,
                    'area' => $area,
                    'width' => $width,
                    'height' => $height,
                    'bounds' => $region,
                    'wall_orientation' => $this->detectOrientation($width, $height),
                    'window_position' => $this->detectWindowPosition($region)
                ];
            } elseif ($area > 1) {
                // 很小的區域 - 可能是窗戶
                $windows[] = [
                    'window_id' => count($windows) + 1,
                    'area' => $area,
                    'width' => $width,
                    'height' => $height,
                    'position' => [
                        'x' => ($region['topLeft']['x'] + $region['bottomRight']['x']) / 2,
                        'y' => ($region['topLeft']['y'] + $region['bottomRight']['y']) / 2
                    ]
                ];
            }
        }
        
        return [
            'floors' => $floors,
            'units' => $units,
            'rooms' => $rooms,
            'windows' => $windows
        ];
    }
    
    /**
     * 檢測方位
     */
    private function detectOrientation($width, $height) {
        if ($width > $height * 1.5) {
            return '東西';
        } elseif ($height > $width * 1.5) {
            return '南北';
        } else {
            return '混合';
        }
    }
    
    /**
     * 檢測窗戶位置
     */
    private function detectWindowPosition($region) {
        // 簡化的窗戶位置檢測
        $centerX = ($region['topLeft']['x'] + $region['bottomRight']['x']) / 2;
        $centerY = ($region['topLeft']['y'] + $region['bottomRight']['y']) / 2;
        
        // 根據位置推測方位
        if ($centerY < 200) {
            return '北';
        } elseif ($centerY > 600) {
            return '南';
        } elseif ($centerX < 300) {
            return '西';
        } elseif ($centerX > 700) {
            return '東';
        } else {
            return '中央';
        }
    }
    
    /**
     * 儲存分析結果到資料庫
     */
    public function saveToBuildingData($analysisResult, $building_id) {
        global $serverName, $database, $username, $password;
        
        try {
            $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();
            
            // 如果沒有樓層，創建一個預設樓層
            if (empty($analysisResult['floors'])) {
                $analysisResult['floors'][] = [
                    'floor_number' => 1,
                    'area' => 0
                ];
            }
            
            // 儲存樓層
            $floorStmt = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_floors] (building_id, floor_number, created_at) VALUES (?, ?, GETDATE())");
            
            foreach ($analysisResult['floors'] as $floor) {
                $floorStmt->execute([$building_id, $floor['floor_number']]);
                $floor_id = $conn->lastInsertId();
                
                // 為每個樓層創建單元和房間
                $this->saveUnitsAndRooms($conn, $building_id, $floor_id, $analysisResult);
            }
            
            $conn->commit();
            return true;
            
        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("儲存建築資料失敗: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 儲存單元和房間
     */
    private function saveUnitsAndRooms($conn, $building_id, $floor_id, $analysisResult) {
        $unitStmt = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_units] (building_id, floor_id, unit_number, created_at) VALUES (?, ?, ?, GETDATE())");
        $roomStmt = $conn->prepare("
            INSERT INTO [Test].[dbo].[GBD_Project_rooms] 
            (building_id, floor_id, unit_id, room_number, height, length, depth, wall_orientation, wall_area, window_position, window_area, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
        ");
        
        // 如果沒有單元，創建一個預設單元
        if (empty($analysisResult['units'])) {
            $analysisResult['units'][] = [
                'unit_number' => 1
            ];
        }
        
        foreach ($analysisResult['units'] as $unit) {
            $unitStmt->execute([$building_id, $floor_id, $unit['unit_number']]);
            $unit_id = $conn->lastInsertId();
            
            // 為每個單元分配房間
            $roomsPerUnit = ceil(count($analysisResult['rooms']) / count($analysisResult['units']));
            $startIndex = ($unit['unit_number'] - 1) * $roomsPerUnit;
            
            for ($i = 0; $i < $roomsPerUnit && $startIndex + $i < count($analysisResult['rooms']); $i++) {
                $room = $analysisResult['rooms'][$startIndex + $i];
                
                // 計算窗戶面積
                $windowArea = $this->calculateWindowAreaForRoom($room, $analysisResult['windows']);
                
                $roomStmt->execute([
                    $building_id,
                    $floor_id,
                    $unit_id,
                    $room['room_number'],
                    3.0, // 預設高度 3公尺
                    $room['width'],
                    $room['height'],
                    $room['wall_orientation'],
                    $room['area'], // 暫時使用房間面積作為牆面積
                    $room['window_position'],
                    $windowArea
                ]);
            }
        }
    }
    
    /**
     * 計算房間的窗戶面積
     */
    private function calculateWindowAreaForRoom($room, $windows) {
        $totalArea = 0;
        
        foreach ($windows as $window) {
            // 簡化檢查：如果窗戶在房間附近，就算是該房間的窗戶
            $roomCenterX = ($room['bounds']['topLeft']['x'] + $room['bounds']['bottomRight']['x']) / 2;
            $roomCenterY = ($room['bounds']['topLeft']['y'] + $room['bounds']['bottomRight']['y']) / 2;
            
            $distance = sqrt(
                pow($window['position']['x'] - $roomCenterX, 2) +
                pow($window['position']['y'] - $roomCenterY, 2)
            );
            
            if ($distance < 100) { // 在100像素範圍內
                $totalArea += $window['area'];
            }
        }
        
        return $totalArea;
    }
}
?> 