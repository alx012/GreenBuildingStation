<?php
require_once 'db_connection.php';

/**
 * 平面圖分析器
 * 實作基於線段分解的閉合區域識別算法
 */
class FloorplanAnalyzer {
    private $tolerance = 1.0; // 線段合併容差
    private $minRoomArea = 10.0; // 最小房間面積（平方公尺）
    
    public function __construct($tolerance = 1.0) {
        $this->tolerance = $tolerance;
    }
    
    /**
     * 分析平面圖圖檔
     * @param string $imagePath 圖檔路徑
     * @param float $scale 圖檔比例尺（公尺/像素）
     * @return array 分析結果
     */
    public function analyzeFloorplan($imagePath, $scale = 0.01) {
        try {
            // 1. 圖像預處理
            $edges = $this->preprocessImage($imagePath);
            
            // 2. 線段提取
            $segments = $this->extractSegments($edges);
            
            // 3. 計算交點並分割線段
            $splitSegments = $this->splitSegments($segments);
            
            // 4. 構建鄰接表
            $adjacencyMap = $this->buildAdjacencyMap($splitSegments);
            
            // 5. 找到閉合區域
            $closedRegions = $this->findClosedRegions($adjacencyMap);
            
            // 6. 識別建築元素
            $buildingElements = $this->identifyBuildingElements($closedRegions, $scale);
            
            return [
                'success' => true,
                'floors' => $buildingElements['floors'],
                'units' => $buildingElements['units'], 
                'rooms' => $buildingElements['rooms'],
                'windows' => $buildingElements['windows'],
                'statistics' => [
                    'total_segments' => count($segments),
                    'split_segments' => count($splitSegments),
                    'closed_regions' => count($closedRegions),
                    'identified_rooms' => count($buildingElements['rooms'])
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 圖像預處理 - 邊緣檢測
     */
    private function preprocessImage($imagePath) {
        // 載入圖像
        $image = $this->loadImage($imagePath);
        
        // 轉為灰階
        $gray = $this->convertToGrayscale($image);
        
        // 高斯模糊
        $blurred = $this->gaussianBlur($gray);
        
        // Canny邊緣檢測
        $edges = $this->cannyEdgeDetection($blurred);
        
        return $edges;
    }
    
    /**
     * 載入圖像
     */
    private function loadImage($imagePath) {
        $imageInfo = getimagesize($imagePath);
        $mimeType = $imageInfo['mime'];
        
        switch($mimeType) {
            case 'image/jpeg':
                return imagecreatefromjpeg($imagePath);
            case 'image/png':
                return imagecreatefrompng($imagePath);
            case 'image/gif':
                return imagecreatefromgif($imagePath);
            default:
                throw new Exception("不支援的圖像格式: " . $mimeType);
        }
    }
    
    /**
     * 轉換為灰階
     */
    private function convertToGrayscale($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $gray = imagecreatetruecolor($width, $height);
        
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // 灰階值計算
                $grayValue = intval($r * 0.299 + $g * 0.587 + $b * 0.114);
                $grayColor = imagecolorallocate($gray, $grayValue, $grayValue, $grayValue);
                imagesetpixel($gray, $x, $y, $grayColor);
            }
        }
        
        return $gray;
    }
    
    /**
     * 高斯模糊
     */
    private function gaussianBlur($image) {
        // 簡化的高斯模糊實作
        $width = imagesx($image);
        $height = imagesy($image);
        $blurred = imagecreatetruecolor($width, $height);
        
        $kernel = [
            [1, 2, 1],
            [2, 4, 2],
            [1, 2, 1]
        ];
        $kernelSum = 16;
        
        for ($x = 1; $x < $width - 1; $x++) {
            for ($y = 1; $y < $height - 1; $y++) {
                $sum = 0;
                for ($kx = -1; $kx <= 1; $kx++) {
                    for ($ky = -1; $ky <= 1; $ky++) {
                        $pixel = imagecolorat($image, $x + $kx, $y + $ky);
                        $gray = $pixel & 0xFF;
                        $sum += $gray * $kernel[$kx + 1][$ky + 1];
                    }
                }
                $blurredValue = intval($sum / $kernelSum);
                $color = imagecolorallocate($blurred, $blurredValue, $blurredValue, $blurredValue);
                imagesetpixel($blurred, $x, $y, $color);
            }
        }
        
        return $blurred;
    }
    
    /**
     * Canny邊緣檢測（簡化版）
     */
    private function cannyEdgeDetection($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $edges = imagecreatetruecolor($width, $height);
        
        // Sobel運算子
        $sobelX = [[-1, 0, 1], [-2, 0, 2], [-1, 0, 1]];
        $sobelY = [[-1, -2, -1], [0, 0, 0], [1, 2, 1]];
        
        for ($x = 1; $x < $width - 1; $x++) {
            for ($y = 1; $y < $height - 1; $y++) {
                $gx = 0; $gy = 0;
                
                for ($kx = -1; $kx <= 1; $kx++) {
                    for ($ky = -1; $ky <= 1; $ky++) {
                        $pixel = imagecolorat($image, $x + $kx, $y + $ky);
                        $gray = $pixel & 0xFF;
                        $gx += $gray * $sobelX[$kx + 1][$ky + 1];
                        $gy += $gray * $sobelY[$kx + 1][$ky + 1];
                    }
                }
                
                $magnitude = sqrt($gx * $gx + $gy * $gy);
                $edgeValue = $magnitude > 50 ? 255 : 0; // 閾值
                $color = imagecolorallocate($edges, $edgeValue, $edgeValue, $edgeValue);
                imagesetpixel($edges, $x, $y, $color);
            }
        }
        
        return $edges;
    }
    
    /**
     * 從邊緣圖像提取線段
     */
    private function extractSegments($edges) {
        $width = imagesx($edges);
        $height = imagesy($edges);
        $segments = [];
        
        // 霍夫直線變換的簡化實作
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $pixel = imagecolorat($edges, $x, $y);
                if (($pixel & 0xFF) > 128) { // 邊緣點
                    // 找到連接的邊緣點形成線段
                    $segment = $this->traceLineSegment($edges, $x, $y);
                    if ($segment && $this->getSegmentLength($segment) > 10) {
                        $segments[] = $segment;
                    }
                }
            }
        }
        
        return $this->mergeNearbySegments($segments);
    }
    
    /**
     * 追踪線段
     */
    private function traceLineSegment($edges, $startX, $startY) {
        $width = imagesx($edges);
        $height = imagesy($edges);
        $visited = [];
        $points = [];
        
        $stack = [[$startX, $startY]];
        
        while (!empty($stack)) {
            list($x, $y) = array_pop($stack);
            $key = "{$x},{$y}";
            
            if (isset($visited[$key]) || $x < 0 || $x >= $width || $y < 0 || $y >= $height) {
                continue;
            }
            
            $pixel = imagecolorat($edges, $x, $y);
            if (($pixel & 0xFF) < 128) {
                continue;
            }
            
            $visited[$key] = true;
            $points[] = ['x' => $x, 'y' => $y];
            
            // 檢查8連通的鄰居
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dy = -1; $dy <= 1; $dy++) {
                    if ($dx === 0 && $dy === 0) continue;
                    $stack[] = [$x + $dx, $y + $dy];
                }
            }
        }
        
        if (count($points) < 2) {
            return null;
        }
        
        // 簡化線段為起點和終點
        return [
            'start' => $points[0],
            'end' => $points[count($points) - 1]
        ];
    }
    
    /**
     * 計算線段長度
     */
    private function getSegmentLength($segment) {
        $dx = $segment['end']['x'] - $segment['start']['x'];
        $dy = $segment['end']['y'] - $segment['start']['y'];
        return sqrt($dx * $dx + $dy * $dy);
    }
    
    /**
     * 合併鄰近的線段
     */
    private function mergeNearbySegments($segments) {
        $merged = [];
        $used = array_fill(0, count($segments), false);
        
        for ($i = 0; $i < count($segments); $i++) {
            if ($used[$i]) continue;
            
            $currentSegment = $segments[$i];
            $used[$i] = true;
            
            // 尋找可以合併的線段
            for ($j = $i + 1; $j < count($segments); $j++) {
                if ($used[$j]) continue;
                
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
     * 檢查兩線段是否可以合併
     */
    private function canMergeSegments($seg1, $seg2) {
        // 檢查端點是否足夠接近
        $threshold = $this->tolerance;
        
        $distances = [
            $this->pointDistance($seg1['end'], $seg2['start']),
            $this->pointDistance($seg1['end'], $seg2['end']),
            $this->pointDistance($seg1['start'], $seg2['start']),
            $this->pointDistance($seg1['start'], $seg2['end'])
        ];
        
        return min($distances) < $threshold;
    }
    
    /**
     * 合併兩線段
     */
    private function mergeSegments($seg1, $seg2) {
        // 簡化：取兩線段的極端點
        $allPoints = [$seg1['start'], $seg1['end'], $seg2['start'], $seg2['end']];
        
        $minX = min(array_column($allPoints, 'x'));
        $maxX = max(array_column($allPoints, 'x'));
        $minY = min(array_column($allPoints, 'y'));
        $maxY = max(array_column($allPoints, 'y'));
        
        // 根據長度決定主要方向
        $deltaX = $maxX - $minX;
        $deltaY = $maxY - $minY;
        
        if ($deltaX > $deltaY) {
            // 水平線段
            return [
                'start' => ['x' => $minX, 'y' => ($seg1['start']['y'] + $seg1['end']['y'] + $seg2['start']['y'] + $seg2['end']['y']) / 4],
                'end' => ['x' => $maxX, 'y' => ($seg1['start']['y'] + $seg1['end']['y'] + $seg2['start']['y'] + $seg2['end']['y']) / 4]
            ];
        } else {
            // 垂直線段
            return [
                'start' => ['x' => ($seg1['start']['x'] + $seg1['end']['x'] + $seg2['start']['x'] + $seg2['end']['x']) / 4, 'y' => $minY],
                'end' => ['x' => ($seg1['start']['x'] + $seg1['end']['x'] + $seg2['start']['x'] + $seg2['end']['x']) / 4, 'y' => $maxY]
            ];
        }
    }
    
    /**
     * 計算兩點間距離
     */
    private function pointDistance($p1, $p2) {
        $dx = $p1['x'] - $p2['x'];
        $dy = $p1['y'] - $p2['y'];
        return sqrt($dx * $dx + $dy * $dy);
    }
    
    /**
     * 分割線段（在交點處）
     */
    private function splitSegments($segments) {
        $splitSegments = [];
        
        foreach ($segments as $i => $seg1) {
            $currentSegments = [$seg1];
            
            foreach ($segments as $j => $seg2) {
                if ($i === $j) continue;
                
                $intersection = $this->getIntersection($seg1, $seg2);
                if ($intersection) {
                    // 分割線段
                    $newSegments = [];
                    foreach ($currentSegments as $seg) {
                        $splitParts = $this->splitSegmentAtPoint($seg, $intersection);
                        $newSegments = array_merge($newSegments, $splitParts);
                    }
                    $currentSegments = $newSegments;
                }
            }
            
            $splitSegments = array_merge($splitSegments, $currentSegments);
        }
        
        return $splitSegments;
    }
    
    /**
     * 計算兩線段交點
     */
    private function getIntersection($seg1, $seg2) {
        $x1 = $seg1['start']['x']; $y1 = $seg1['start']['y'];
        $x2 = $seg1['end']['x']; $y2 = $seg1['end']['y'];
        $x3 = $seg2['start']['x']; $y3 = $seg2['start']['y'];
        $x4 = $seg2['end']['x']; $y4 = $seg2['end']['y'];
        
        $denom = ($x1 - $x2) * ($y3 - $y4) - ($y1 - $y2) * ($x3 - $x4);
        
        if (abs($denom) < 1e-10) {
            return null; // 平行線
        }
        
        $t = (($x1 - $x3) * ($y3 - $y4) - ($y1 - $y3) * ($x3 - $x4)) / $denom;
        $u = -(($x1 - $x2) * ($y1 - $y3) - ($y1 - $y2) * ($x1 - $x3)) / $denom;
        
        if ($t >= 0 && $t <= 1 && $u >= 0 && $u <= 1) {
            return [
                'x' => $x1 + $t * ($x2 - $x1),
                'y' => $y1 + $t * ($y2 - $y1)
            ];
        }
        
        return null;
    }
    
    /**
     * 在指定點分割線段
     */
    private function splitSegmentAtPoint($segment, $point) {
        $threshold = $this->tolerance;
        
        // 檢查點是否在線段上
        if ($this->pointDistance($segment['start'], $point) < $threshold) {
            return [$segment]; // 點太靠近起點
        }
        
        if ($this->pointDistance($segment['end'], $point) < $threshold) {
            return [$segment]; // 點太靠近終點
        }
        
        // 分割為兩段
        return [
            ['start' => $segment['start'], 'end' => $point],
            ['start' => $point, 'end' => $segment['end']]
        ];
    }
    
    /**
     * 構建鄰接表
     */
    private function buildAdjacencyMap($segments) {
        $adjacencyMap = [];
        
        foreach ($segments as $segment) {
            $startKey = $this->pointToKey($segment['start']);
            $endKey = $this->pointToKey($segment['end']);
            
            if (!isset($adjacencyMap[$startKey])) {
                $adjacencyMap[$startKey] = ['point' => $segment['start'], 'connections' => []];
            }
            
            if (!isset($adjacencyMap[$endKey])) {
                $adjacencyMap[$endKey] = ['point' => $segment['end'], 'connections' => []];
            }
            
            $adjacencyMap[$startKey]['connections'][] = $endKey;
            $adjacencyMap[$endKey]['connections'][] = $startKey;
        }
        
        return $adjacencyMap;
    }
    
    /**
     * 將點轉換為字串鍵
     */
    private function pointToKey($point) {
        return sprintf("%.1f,%.1f", $point['x'], $point['y']);
    }
    
    /**
     * 尋找閉合區域
     */
    private function findClosedRegions($adjacencyMap) {
        $visitedEdges = [];
        $regions = [];
        
        foreach ($adjacencyMap as $startKey => $startData) {
            foreach ($startData['connections'] as $nextKey) {
                $edgeKey = $this->getEdgeKey($startKey, $nextKey);
                
                if (isset($visitedEdges[$edgeKey])) {
                    continue;
                }
                
                $path = $this->findClosedPath($adjacencyMap, $startKey, $nextKey, $visitedEdges);
                
                if ($path && count($path) >= 3) {
                    $region = $this->pathToRegion($path, $adjacencyMap);
                    if ($this->calculatePolygonArea($region) > $this->minRoomArea) {
                        $regions[] = $region;
                    }
                }
            }
        }
        
        return $this->removeDuplicateRegions($regions);
    }
    
    /**
     * 生成邊的鍵
     */
    private function getEdgeKey($point1Key, $point2Key) {
        return $point1Key < $point2Key ? "{$point1Key}-{$point2Key}" : "{$point2Key}-{$point1Key}";
    }
    
    /**
     * 尋找閉合路徑
     */
    private function findClosedPath($adjacencyMap, $startKey, $currentKey, &$visitedEdges) {
        $path = [$startKey];
        $prevKey = $startKey;
        
        while (true) {
            $path[] = $currentKey;
            
            if ($currentKey === $startKey && count($path) > 3) {
                return $path; // 找到閉合路徑
            }
            
            if (count($path) > 50) { // 防止無限循環
                break;
            }
            
            $currentData = $adjacencyMap[$currentKey];
            $candidates = [];
            
            foreach ($currentData['connections'] as $nextKey) {
                if ($nextKey === $prevKey) continue; // 不回到前一點
                
                $edgeKey = $this->getEdgeKey($currentKey, $nextKey);
                if (!isset($visitedEdges[$edgeKey])) {
                    $candidates[] = $nextKey;
                }
            }
            
            if (empty($candidates)) {
                break;
            }
            
            // 選擇角度最小的候選（右手定則）
            $nextKey = $this->selectNextPointByAngle($adjacencyMap, $prevKey, $currentKey, $candidates);
            
            $edgeKey = $this->getEdgeKey($currentKey, $nextKey);
            $visitedEdges[$edgeKey] = true;
            
            $prevKey = $currentKey;
            $currentKey = $nextKey;
        }
        
        return null;
    }
    
    /**
     * 根據角度選擇下一個點
     */
    private function selectNextPointByAngle($adjacencyMap, $prevKey, $currentKey, $candidates) {
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        
        $currentPoint = $adjacencyMap[$currentKey]['point'];
        $prevPoint = $adjacencyMap[$prevKey]['point'];
        
        $incomingAngle = atan2(
            $currentPoint['y'] - $prevPoint['y'],
            $currentPoint['x'] - $prevPoint['x']
        );
        
        $bestCandidate = $candidates[0];
        $bestAngle = PHP_FLOAT_MAX;
        
        foreach ($candidates as $candidateKey) {
            $candidatePoint = $adjacencyMap[$candidateKey]['point'];
            
            $outgoingAngle = atan2(
                $candidatePoint['y'] - $currentPoint['y'],
                $candidatePoint['x'] - $currentPoint['x']
            );
            
            $angleDiff = $outgoingAngle - $incomingAngle;
            if ($angleDiff < 0) {
                $angleDiff += 2 * M_PI;
            }
            
            if ($angleDiff < $bestAngle) {
                $bestAngle = $angleDiff;
                $bestCandidate = $candidateKey;
            }
        }
        
        return $bestCandidate;
    }
    
    /**
     * 將路徑轉換為區域
     */
    private function pathToRegion($path, $adjacencyMap) {
        $region = [];
        
        foreach ($path as $pointKey) {
            if (isset($adjacencyMap[$pointKey])) {
                $region[] = $adjacencyMap[$pointKey]['point'];
            }
        }
        
        return $region;
    }
    
    /**
     * 計算多邊形面積
     */
    private function calculatePolygonArea($polygon) {
        $n = count($polygon);
        if ($n < 3) return 0;
        
        $area = 0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $area += $polygon[$i]['x'] * $polygon[$j]['y'];
            $area -= $polygon[$j]['x'] * $polygon[$i]['y'];
        }
        
        return abs($area) / 2;
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
        if (abs(count($region1) - count($region2)) > 2) {
            return false;
        }
        
        $area1 = $this->calculatePolygonArea($region1);
        $area2 = $this->calculatePolygonArea($region2);
        
        return abs($area1 - $area2) / max($area1, $area2) < 0.1;
    }
    
    /**
     * 識別建築元素
     */
    private function identifyBuildingElements($regions, $scale) {
        $floors = [];
        $units = [];
        $rooms = [];
        $windows = [];
        
        foreach ($regions as $index => $region) {
            $area = $this->calculatePolygonArea($region) * $scale * $scale;
            $bounds = $this->getRegionBounds($region);
            $width = ($bounds['maxX'] - $bounds['minX']) * $scale;
            $height = ($bounds['maxY'] - $bounds['minY']) * $scale;
            
            // 根據面積和形狀特徵分類
            if ($area > 200) {
                // 大區域可能是樓層
                $floors[] = [
                    'floor_number' => count($floors) + 1,
                    'area' => $area,
                    'bounds' => $bounds,
                    'region' => $region
                ];
            } elseif ($area > 50) {
                // 中等區域可能是單元
                $units[] = [
                    'unit_number' => count($units) + 1,
                    'area' => $area,
                    'width' => $width,
                    'height' => $height,
                    'bounds' => $bounds,
                    'region' => $region
                ];
            } elseif ($area > 10) {
                // 小區域可能是房間
                $rooms[] = [
                    'room_number' => count($rooms) + 1,
                    'area' => $area,
                    'width' => $width,
                    'height' => $height,
                    'bounds' => $bounds,
                    'region' => $region,
                    'wall_orientation' => $this->detectWallOrientation($region),
                    'window_position' => $this->detectWindowPosition($region)
                ];
            } elseif ($area > 1) {
                // 很小的區域可能是窗戶
                $windows[] = [
                    'window_id' => count($windows) + 1,
                    'area' => $area,
                    'width' => $width,
                    'height' => $height,
                    'position' => $this->getRegionCenter($region),
                    'region' => $region
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
     * 獲取區域邊界
     */
    private function getRegionBounds($region) {
        $xs = array_column($region, 'x');
        $ys = array_column($region, 'y');
        
        return [
            'minX' => min($xs),
            'maxX' => max($xs),
            'minY' => min($ys),
            'maxY' => max($ys)
        ];
    }
    
    /**
     * 獲取區域中心點
     */
    private function getRegionCenter($region) {
        $bounds = $this->getRegionBounds($region);
        
        return [
            'x' => ($bounds['minX'] + $bounds['maxX']) / 2,
            'y' => ($bounds['minY'] + $bounds['maxY']) / 2
        ];
    }
    
    /**
     * 檢測牆面方位
     */
    private function detectWallOrientation($region) {
        $bounds = $this->getRegionBounds($region);
        $width = $bounds['maxX'] - $bounds['minX'];
        $height = $bounds['maxY'] - $bounds['minY'];
        
        // 簡化的方位檢測
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
        $center = $this->getRegionCenter($region);
        $bounds = $this->getRegionBounds($region);
        
        // 簡化的窗戶位置檢測
        $relativeX = ($center['x'] - $bounds['minX']) / ($bounds['maxX'] - $bounds['minX']);
        $relativeY = ($center['y'] - $bounds['minY']) / ($bounds['maxY'] - $bounds['minY']);
        
        if ($relativeY < 0.3) {
            return '北';
        } elseif ($relativeY > 0.7) {
            return '南';
        } elseif ($relativeX < 0.3) {
            return '西';
        } elseif ($relativeX > 0.7) {
            return '東';
        } else {
            return '中央';
        }
    }
    
    /**
     * 保存分析結果到資料庫
     */
    public function saveToBuildingData($analysisResult, $building_id) {
        global $serverName, $database, $username, $password;
        
        try {
            $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();
            
            // 儲存樓層資料
            $floorStmt = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_floors] (building_id, floor_number, created_at) VALUES (?, ?, GETDATE())");
            
            foreach ($analysisResult['floors'] as $floor) {
                $floorStmt->execute([$building_id, $floor['floor_number']]);
                $floor_id = $conn->lastInsertId();
                
                // 儲存該樓層的單元和房間
                $this->saveUnitsAndRooms($conn, $building_id, $floor_id, $floor['floor_number'], $analysisResult);
            }
            
            // 如果沒有樓層資料，創建一個預設樓層
            if (empty($analysisResult['floors'])) {
                $floorStmt->execute([$building_id, 1]);
                $floor_id = $conn->lastInsertId();
                $this->saveUnitsAndRooms($conn, $building_id, $floor_id, 1, $analysisResult);
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
     * 儲存單元和房間資料
     */
    private function saveUnitsAndRooms($conn, $building_id, $floor_id, $floor_number, $analysisResult) {
        $unitStmt = $conn->prepare("INSERT INTO [Test].[dbo].[GBD_Project_units] (building_id, floor_id, unit_number, created_at) VALUES (?, ?, ?, GETDATE())");
        $roomStmt = $conn->prepare("
            INSERT INTO [Test].[dbo].[GBD_Project_rooms] 
            (building_id, floor_id, unit_id, room_number, height, length, depth, wall_orientation, wall_area, window_position, window_area, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
        ");
        
        // 如果有單元資料，按單元分組
        if (!empty($analysisResult['units'])) {
            foreach ($analysisResult['units'] as $unit) {
                $unitStmt->execute([$building_id, $floor_id, $unit['unit_number']]);
                $unit_id = $conn->lastInsertId();
                
                // 找到屬於這個單元的房間
                $unitRooms = $this->findRoomsInUnit($unit, $analysisResult['rooms']);
                
                foreach ($unitRooms as $room) {
                    $this->saveRoom($roomStmt, $building_id, $floor_id, $unit_id, $room, $analysisResult['windows']);
                }
            }
        } else {
            // 沒有單元資料，創建一個預設單元
            $unitStmt->execute([$building_id, $floor_id, 1]);
            $unit_id = $conn->lastInsertId();
            
            foreach ($analysisResult['rooms'] as $room) {
                $this->saveRoom($roomStmt, $building_id, $floor_id, $unit_id, $room, $analysisResult['windows']);
            }
        }
    }
    
    /**
     * 找到屬於單元的房間
     */
    private function findRoomsInUnit($unit, $rooms) {
        $unitRooms = [];
        
        foreach ($rooms as $room) {
            if ($this->isRoomInUnit($room, $unit)) {
                $unitRooms[] = $room;
            }
        }
        
        return $unitRooms;
    }
    
    /**
     * 檢查房間是否在單元內
     */
    private function isRoomInUnit($room, $unit) {
        $roomCenter = $this->getRegionCenter($room['region']);
        
        // 簡化的點在多邊形內檢測
        return $this->pointInPolygon($roomCenter, $unit['region']);
    }
    
    /**
     * 點在多邊形內檢測
     */
    private function pointInPolygon($point, $polygon) {
        $n = count($polygon);
        $inside = false;
        
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            if ((($polygon[$i]['y'] > $point['y']) !== ($polygon[$j]['y'] > $point['y'])) &&
                ($point['x'] < ($polygon[$j]['x'] - $polygon[$i]['x']) * ($point['y'] - $polygon[$i]['y']) / ($polygon[$j]['y'] - $polygon[$i]['y']) + $polygon[$i]['x'])) {
                $inside = !$inside;
            }
        }
        
        return $inside;
    }
    
    /**
     * 儲存房間資料
     */
    private function saveRoom($roomStmt, $building_id, $floor_id, $unit_id, $room, $windows) {
        // 計算房間尺寸
        $roomWindows = $this->findWindowsInRoom($room, $windows);
        $totalWindowArea = array_sum(array_column($roomWindows, 'area'));
        
        $roomStmt->execute([
            $building_id,
            $floor_id,
            $unit_id,
            $room['room_number'],
            3.0, // 預設高度
            $room['width'],
            $room['height'],
            $room['wall_orientation'],
            $room['area'], // 牆面積暫時使用房間面積
            $room['window_position'],
            $totalWindowArea
        ]);
    }
    
    /**
     * 找到房間內的窗戶
     */
    private function findWindowsInRoom($room, $windows) {
        $roomWindows = [];
        
        foreach ($windows as $window) {
            if ($this->pointInPolygon($window['position'], $room['region'])) {
                $roomWindows[] = $window;
            }
        }
        
        return $roomWindows;
    }
}
?> 