#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
平面圖閉合區域識別算法
基於線段分解的方法，用於識別建築平面圖中的房間、單元等封閉區域
"""

import cv2
import numpy as np
import math
from collections import defaultdict
import json
import sys
import argparse

class FloorplanAnalyzer:
    def __init__(self, tolerance=5.0, min_room_area=500):
        self.tolerance = tolerance  # 線段合併容差（像素）
        self.min_room_area = min_room_area  # 最小房間面積（像素²）
        
    def analyze_floorplan(self, image_path, scale=0.01):
        """
        分析平面圖
        Args:
            image_path: 圖像檔案路徑
            scale: 比例尺（公尺/像素）
        Returns:
            dict: 分析結果
        """
        try:
            # 1. 載入和預處理圖像
            image = cv2.imread(image_path)
            if image is None:
                raise Exception(f"無法載入圖像: {image_path}")
            
            # 2. 邊緣檢測和輪廓分析
            binary_image = self.preprocess_image(image)
            
            # 3. 使用輪廓檢測來識別封閉區域
            contours = self.find_contours(binary_image)
            print(f"找到 {len(contours)} 個輪廓")
            
            # 4. 過濾和分析輪廓
            valid_regions = self.filter_and_analyze_contours(contours, scale)
            print(f"有效區域數: {len(valid_regions)}")
            
            # 5. 如果輪廓檢測失敗，回退到線段分析
            if len(valid_regions) == 0:
                print("輪廓檢測未找到區域，使用線段分析...")
                segments = self.extract_segments(binary_image)
                print(f"提取到 {len(segments)} 條線段")
                
                split_segments = self.split_segments_at_intersections(segments)
                print(f"分割後有 {len(split_segments)} 條線段")
                
                adjacency_map = self.build_adjacency_map(split_segments)
                print(f"構建了 {len(adjacency_map)} 個節點的鄰接表")
                
                closed_regions = self.find_closed_regions(adjacency_map)
                print(f"找到 {len(closed_regions)} 個閉合區域")
                
                valid_regions = self.convert_polygon_regions(closed_regions, scale)
            
            # 6. 分類建築元素
            building_elements = self.classify_regions_improved(valid_regions, scale)
            
            return {
                'success': True,
                'floors': building_elements['floors'],
                'units': building_elements['units'],
                'rooms': building_elements['rooms'],
                'windows': building_elements['windows'],
                'statistics': {
                    'total_contours': len(contours) if 'contours' in locals() else 0,
                    'valid_regions': len(valid_regions),
                    'identified_rooms': len(building_elements['rooms'])
                }
            }
        except Exception as e:
            return {
                'success': False,
                'error': str(e)
            }
    
    def preprocess_image(self, image):
        """圖像預處理和邊緣檢測"""
        # 轉為灰階
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        
        # 自適應閾值二值化 - 使用較小的區塊大小
        binary = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, 
                                     cv2.THRESH_BINARY, 15, 3)
        
        # 反轉圖像（讓線條變成白色，背景變成黑色）
        binary = cv2.bitwise_not(binary)
        
        # 使用較小的核進行形態學操作，保持線條細節
        kernel_small = np.ones((2, 2), np.uint8)
        binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel_small, iterations=1)
        
        return binary
    
    def extract_segments(self, edges):
        """使用霍夫直線變換提取線段"""
        # 霍夫直線變換
        lines = cv2.HoughLinesP(edges, 1, np.pi/180, threshold=80, 
                               minLineLength=30, maxLineGap=10)
        
        segments = []
        if lines is not None:
            for line in lines:
                x1, y1, x2, y2 = line[0]
                segments.append({
                    'start': {'x': float(x1), 'y': float(y1)},
                    'end': {'x': float(x2), 'y': float(y2)}
                })
        
        # 合併相近的線段
        return self.merge_nearby_segments(segments)
    
    def merge_nearby_segments(self, segments):
        """合併相近的線段"""
        merged = []
        used = [False] * len(segments)
        
        for i in range(len(segments)):
            if used[i]:
                continue
                
            current_segment = segments[i]
            used[i] = True
            
            # 尋找可合併的線段
            for j in range(i + 1, len(segments)):
                if used[j]:
                    continue
                    
                if self.can_merge_segments(current_segment, segments[j]):
                    current_segment = self.merge_segments(current_segment, segments[j])
                    used[j] = True
            
            merged.append(current_segment)
        
        return merged
    
    def can_merge_segments(self, seg1, seg2):
        """檢查兩線段是否可以合併"""
        # 檢查是否在同一直線上
        if not self.are_collinear(seg1, seg2):
            return False
        
        # 檢查是否連續或重疊
        return (self.point_distance(seg1['end'], seg2['start']) < self.tolerance or
                self.point_distance(seg1['start'], seg2['end']) < self.tolerance or
                self.point_distance(seg1['end'], seg2['end']) < self.tolerance or
                self.point_distance(seg1['start'], seg2['start']) < self.tolerance)
    
    def are_collinear(self, seg1, seg2):
        """檢查兩線段是否共線"""
        # 計算向量
        v1 = np.array([seg1['end']['x'] - seg1['start']['x'], 
                      seg1['end']['y'] - seg1['start']['y']])
        v2 = np.array([seg2['end']['x'] - seg2['start']['x'], 
                      seg2['end']['y'] - seg2['start']['y']])
        
        # 計算角度差
        if np.linalg.norm(v1) == 0 or np.linalg.norm(v2) == 0:
            return False
        
        cos_angle = np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2))
        cos_angle = np.clip(cos_angle, -1, 1)  # 防止數值誤差
        angle = np.arccos(abs(cos_angle))
        
        return angle < np.pi / 36  # 5度容差
    
    def merge_segments(self, seg1, seg2):
        """合併兩線段"""
        # 收集所有端點
        points = [seg1['start'], seg1['end'], seg2['start'], seg2['end']]
        
        # 找出最遠的兩個點
        max_dist = 0
        result_start, result_end = points[0], points[1]
        
        for i in range(len(points)):
            for j in range(i + 1, len(points)):
                dist = self.point_distance(points[i], points[j])
                if dist > max_dist:
                    max_dist = dist
                    result_start, result_end = points[i], points[j]
        
        return {'start': result_start, 'end': result_end}
    
    def point_distance(self, p1, p2):
        """計算兩點距離"""
        return math.sqrt((p1['x'] - p2['x'])**2 + (p1['y'] - p2['y'])**2)
    
    def split_segments_at_intersections(self, segments):
        """在交點處分割線段"""
        split_segments = []
        
        for i, seg1 in enumerate(segments):
            current_splits = [seg1]
            
            for j, seg2 in enumerate(segments):
                if i == j:
                    continue
                
                intersection = self.get_line_intersection(seg1, seg2)
                if intersection:
                    # 分割當前線段
                    new_splits = []
                    for seg in current_splits:
                        new_splits.extend(self.split_segment_at_point(seg, intersection))
                    current_splits = new_splits
            
            split_segments.extend(current_splits)
        
        return split_segments
    
    def get_line_intersection(self, seg1, seg2):
        """計算兩線段交點"""
        x1, y1 = seg1['start']['x'], seg1['start']['y']
        x2, y2 = seg1['end']['x'], seg1['end']['y']
        x3, y3 = seg2['start']['x'], seg2['start']['y']
        x4, y4 = seg2['end']['x'], seg2['end']['y']
        
        denom = (x1 - x2) * (y3 - y4) - (y1 - y2) * (x3 - x4)
        
        if abs(denom) < 1e-10:
            return None  # 平行線
        
        t = ((x1 - x3) * (y3 - y4) - (y1 - y3) * (x3 - x4)) / denom
        u = -((x1 - x2) * (y1 - y3) - (y1 - y2) * (x1 - x3)) / denom
        
        if 0.01 <= t <= 0.99 and 0.01 <= u <= 0.99:  # 排除端點
            return {
                'x': x1 + t * (x2 - x1),
                'y': y1 + t * (y2 - y1)
            }
        
        return None
    
    def split_segment_at_point(self, segment, point):
        """在指定點分割線段"""
        if not self.point_on_segment(point, segment):
            return [segment]
        
        # 檢查點是否太靠近端點
        if (self.point_distance(point, segment['start']) < self.tolerance or
            self.point_distance(point, segment['end']) < self.tolerance):
            return [segment]
        
        return [
            {'start': segment['start'], 'end': point},
            {'start': point, 'end': segment['end']}
        ]
    
    def point_on_segment(self, point, segment):
        """檢查點是否在線段上"""
        # 計算距離
        d1 = self.point_distance(point, segment['start'])
        d2 = self.point_distance(point, segment['end'])
        line_length = self.point_distance(segment['start'], segment['end'])
        
        return abs(d1 + d2 - line_length) < self.tolerance
    
    def build_adjacency_map(self, segments):
        """構建點的鄰接表"""
        adjacency_map = defaultdict(list)
        
        for segment in segments:
            start_key = self.point_to_key(segment['start'])
            end_key = self.point_to_key(segment['end'])
            
            adjacency_map[start_key].append(end_key)
            adjacency_map[end_key].append(start_key)
        
        # 去除重複連接
        for key in adjacency_map:
            adjacency_map[key] = list(set(adjacency_map[key]))
        
        return dict(adjacency_map)
    
    def point_to_key(self, point):
        """將點轉換為字串鍵"""
        return f"{int(point['x'])},{int(point['y'])}"
    
    def key_to_point(self, key):
        """將字串鍵轉換為點"""
        x, y = map(int, key.split(','))
        return {'x': float(x), 'y': float(y)}
    
    def find_closed_regions(self, adjacency_map):
        """尋找閉合區域"""
        visited_edges = set()
        regions = []
        
        for start_key in adjacency_map:
            for next_key in adjacency_map[start_key]:
                edge_key = self.get_edge_key(start_key, next_key)
                
                if edge_key in visited_edges:
                    continue
                
                path = self.find_closed_path(adjacency_map, start_key, next_key, visited_edges)
                
                if path and len(path) >= 4:  # 至少4個點形成閉合區域
                    region = [self.key_to_point(key) for key in path[:-1]]  # 去除重複的起點
                    area = self.calculate_polygon_area(region)
                    
                    if area > self.min_room_area:
                        regions.append(region)
        
        return self.remove_duplicate_regions(regions)
    
    def get_edge_key(self, key1, key2):
        """生成邊的鍵"""
        return tuple(sorted([key1, key2]))
    
    def find_closed_path(self, adjacency_map, start_key, current_key, visited_edges):
        """尋找閉合路徑"""
        path = [start_key]
        prev_key = start_key
        
        while True:
            path.append(current_key)
            
            if current_key == start_key and len(path) > 3:
                return path  # 找到閉合路徑
            
            if len(path) > 100:  # 防止無限循環
                break
            
            # 找下一個點
            candidates = [key for key in adjacency_map.get(current_key, []) 
                         if key != prev_key]
            
            if not candidates:
                break
            
            # 選擇角度最小的候選（右手定則）
            next_key = self.select_next_point_by_angle(
                adjacency_map, prev_key, current_key, candidates
            )
            
            edge_key = self.get_edge_key(current_key, next_key)
            visited_edges.add(edge_key)
            
            prev_key = current_key
            current_key = next_key
        
        return None
    
    def select_next_point_by_angle(self, adjacency_map, prev_key, current_key, candidates):
        """根據角度選擇下一個點"""
        if len(candidates) == 1:
            return candidates[0]
        
        current_point = self.key_to_point(current_key)
        prev_point = self.key_to_point(prev_key)
        
        # 計算入射角度
        incoming_angle = math.atan2(
            current_point['y'] - prev_point['y'],
            current_point['x'] - prev_point['x']
        )
        
        best_candidate = candidates[0]
        best_angle = float('inf')
        
        for candidate_key in candidates:
            candidate_point = self.key_to_point(candidate_key)
            
            # 計算出射角度
            outgoing_angle = math.atan2(
                candidate_point['y'] - current_point['y'],
                candidate_point['x'] - current_point['x']
            )
            
            # 計算角度差（逆時針為正）
            angle_diff = outgoing_angle - incoming_angle
            if angle_diff < 0:
                angle_diff += 2 * math.pi
            
            if angle_diff < best_angle:
                best_angle = angle_diff
                best_candidate = candidate_key
        
        return best_candidate
    
    def calculate_polygon_area(self, polygon):
        """使用鞋帶公式計算多邊形面積"""
        if len(polygon) < 3:
            return 0
        
        area = 0
        n = len(polygon)
        
        for i in range(n):
            j = (i + 1) % n
            area += polygon[i]['x'] * polygon[j]['y']
            area -= polygon[j]['x'] * polygon[i]['y']
        
        return abs(area) / 2
    
    def remove_duplicate_regions(self, regions):
        """移除重複區域"""
        unique_regions = []
        
        for region in regions:
            is_unique = True
            
            for existing_region in unique_regions:
                if self.are_regions_similar(region, existing_region):
                    is_unique = False
                    break
            
            if is_unique:
                unique_regions.append(region)
        
        return unique_regions
    
    def are_regions_similar(self, region1, region2):
        """檢查兩區域是否相似"""
        area1 = self.calculate_polygon_area(region1)
        area2 = self.calculate_polygon_area(region2)
        
        if abs(area1 - area2) / max(area1, area2) > 0.1:
            return False
        
        center1 = self.get_polygon_center(region1)
        center2 = self.get_polygon_center(region2)
        
        return self.point_distance(center1, center2) < 50
    
    def get_polygon_center(self, polygon):
        """計算多邊形中心點"""
        if not polygon:
            return {'x': 0, 'y': 0}
        
        center_x = sum(p['x'] for p in polygon) / len(polygon)
        center_y = sum(p['y'] for p in polygon) / len(polygon)
        
        return {'x': center_x, 'y': center_y}
    
    def classify_regions_improved(self, regions, scale):
        """改進的區域分類"""
        floors = []
        units = []
        rooms = []
        windows = []
        
        for i, region in enumerate(regions):
            area_meters = region['area_meters']
            bounds = region['bounds']
            
            # 計算寬度和高度（公尺）
            if 'width' in bounds and 'height' in bounds:
                width = bounds['width'] * scale
                height = bounds['height'] * scale
            else:
                width = (bounds['max_x'] - bounds['min_x']) * scale
                height = (bounds['max_y'] - bounds['min_y']) * scale
            
            # 更寬鬆的分類標準
            if area_meters > 200:  # 大區域 - 可能是整個樓層或大空間
                floors.append({
                    'floor_number': len(floors) + 1,
                    'area': area_meters,
                    'bounds': bounds
                })
            elif area_meters > 50:  # 中等區域 - 單元或大房間
                units.append({
                    'unit_number': len(units) + 1,
                    'area': area_meters,
                    'width': width,
                    'height': height,
                    'bounds': bounds
                })
            elif area_meters > 2:  # 小區域 - 房間（降低閾值）
                room_name = f"Room_{i+1}"
                
                # 嘗試根據面積和形狀推測房間類型
                room_type = "未知"
                if area_meters > 20:
                    room_type = "客廳"
                elif area_meters > 15:
                    room_type = "臥室"
                elif area_meters > 8:
                    room_type = "廚房"
                elif area_meters > 4:
                    room_type = "浴室"
                else:
                    room_type = "儲藏室"
                
                rooms.append({
                    'room_number': len(rooms) + 1,
                    'name': room_name,
                    'type': room_type,
                    'area': area_meters,
                    'width': width,
                    'height': height,
                    'length': width,  # 相容性
                    'depth': height,  # 相容性
                    'bounds': self.convert_bounds_to_dict(bounds) if 'min_x' in bounds else bounds,
                    'wall_orientation': self.detect_orientation(width, height),
                    'window_position': self.detect_window_position_improved(region)
                })
            elif area_meters > 0.5:  # 很小區域 - 可能是窗戶
                center = self.get_region_center(region)
                windows.append({
                    'window_id': len(windows) + 1,
                    'area': area_meters,
                    'width': width,
                    'height': height,
                    'position': center,
                    'orientation': self.detect_orientation(width, height)
                })
        
        # 如果沒有找到樓層，創建一個預設樓層
        if len(floors) == 0:
            floors.append({
                'floor_number': 1,
                'area': sum(r['area_meters'] for r in regions),
                'bounds': {}
            })
        
        # 如果沒有找到單元，創建一個預設單元
        if len(units) == 0:
            units.append({
                'unit_number': 1,
                'area': sum(r['area_meters'] for r in regions),
                'width': 0,
                'height': 0,
                'bounds': {}
            })
        
        return {
            'floors': floors,
            'units': units,
            'rooms': rooms,
            'windows': windows
        }
    
    def get_region_center(self, region):
        """計算區域中心點"""
        if 'polygon' in region:
            return self.get_polygon_center(region['polygon'])
        else:
            bounds = region['bounds']
            if 'width' in bounds:
                return {
                    'x': bounds['x'] + bounds['width'] / 2,
                    'y': bounds['y'] + bounds['height'] / 2
                }
            else:
                return {
                    'x': (bounds['min_x'] + bounds['max_x']) / 2,
                    'y': (bounds['min_y'] + bounds['max_y']) / 2
                }
    
    def detect_window_position_improved(self, region):
        """改進的窗戶位置檢測"""
        center = self.get_region_center(region)
        bounds = region['bounds']
        
        # 根據位置判斷方位
        if 'width' in bounds:
            img_width = 1000  # 假設圖像寬度
            img_height = 800  # 假設圖像高度
        else:
            img_width = bounds.get('max_x', 1000)
            img_height = bounds.get('max_y', 800)
        
        x_ratio = center['x'] / img_width
        y_ratio = center['y'] / img_height
        
        if y_ratio < 0.3:
            return '北'
        elif y_ratio > 0.7:
            return '南'
        elif x_ratio < 0.3:
            return '西'
        elif x_ratio > 0.7:
            return '東'
        else:
            return '中央'
    
    def find_contours(self, binary_image):
        """改進的輪廓檢測方法"""
        # 保存原始圖像
        original = binary_image.copy()
        
        # 方法1: 直接找輪廓
        contours_direct, _ = cv2.findContours(binary_image, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
        
        # 方法2: 使用距離變換和分水嶺算法
        # 計算距離變換
        dist_transform = cv2.distanceTransform(cv2.bitwise_not(binary_image), cv2.DIST_L2, 5)
        
        # 找局部最大值作為標記
        local_maxima = cv2.dilate(dist_transform, np.ones((20, 20), np.uint8))
        markers = np.zeros_like(dist_transform, dtype=np.int32)
        markers[local_maxima == dist_transform] = 1
        markers[dist_transform < 0.4 * dist_transform.max()] = 0
        
        # 給每個區域不同的標記
        num_labels, labels = cv2.connectedComponents(markers.astype(np.uint8))
        
        # 應用分水嶺算法
        if num_labels > 1:
            # 創建3通道圖像用於分水嶺
            img_for_watershed = cv2.cvtColor(binary_image, cv2.COLOR_GRAY2BGR)
            markers = cv2.watershed(img_for_watershed, labels)
            
            # 從分水嶺結果中提取輪廓
            watershed_contours = []
            for i in range(1, num_labels):
                mask = (labels == i).astype(np.uint8) * 255
                contours_temp, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
                watershed_contours.extend(contours_temp)
        else:
            watershed_contours = []
        
        # 方法3: 使用腐蝕和膨脹來分離連接的區域
        kernel_erode = np.ones((3, 3), np.uint8)
        eroded = cv2.erode(cv2.bitwise_not(binary_image), kernel_erode, iterations=2)
        
        # 找連通組件
        num_labels, labels, stats, centroids = cv2.connectedComponentsWithStats(eroded, connectivity=8)
        
        component_contours = []
        for i in range(1, num_labels):  # 跳過背景
            mask = (labels == i).astype(np.uint8) * 255
            contours_temp, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
            component_contours.extend(contours_temp)
        
        # 合併所有方法的結果
        all_contours = list(contours_direct) + list(watershed_contours) + list(component_contours)
        
        # 去重和過濾
        filtered_contours = self.filter_duplicate_contours(all_contours)
        
        return filtered_contours
    
    def filter_duplicate_contours(self, contours):
        """過濾重複的輪廓"""
        if not contours:
            return []
        
        unique_contours = []
        
        for contour in contours:
            area = cv2.contourArea(contour)
            if area < 100:  # 太小的輪廓直接跳過
                continue
            
            # 檢查是否與已有輪廓重複
            is_duplicate = False
            for existing_contour in unique_contours:
                # 計算輪廓中心的距離
                m1 = cv2.moments(contour)
                m2 = cv2.moments(existing_contour)
                
                if m1['m00'] == 0 or m2['m00'] == 0:
                    continue
                
                cx1, cy1 = int(m1['m10']/m1['m00']), int(m1['m01']/m1['m00'])
                cx2, cy2 = int(m2['m10']/m2['m00']), int(m2['m01']/m2['m00'])
                
                distance = np.sqrt((cx1-cx2)**2 + (cy1-cy2)**2)
                area_ratio = abs(area - cv2.contourArea(existing_contour)) / max(area, cv2.contourArea(existing_contour))
                
                # 如果中心距離很近且面積差異不大，認為是重複
                if distance < 50 and area_ratio < 0.3:
                    is_duplicate = True
                    break
            
            if not is_duplicate:
                unique_contours.append(contour)
        
        return unique_contours
    
    def filter_and_analyze_contours(self, contours, scale):
        """改進的輪廓過濾和分析"""
        valid_regions = []
        
        for i, contour in enumerate(contours):
            # 計算輪廓面積
            area_pixels = cv2.contourArea(contour)
            area_meters = area_pixels * scale * scale
            
            # 調整面積閾值 - 接受更小的區域
            if area_meters < 0.5:  # 降低閾值到0.5平方公尺
                continue
            
            # 計算邊界框
            x, y, w, h = cv2.boundingRect(contour)
            
            # 放寬長寬比限制
            if min(w, h) < 10:  # 太小的邊界框跳過
                continue
            
            aspect_ratio = max(w, h) / min(w, h)
            if aspect_ratio > 20:  # 放寬到20:1
                continue
            
            # 計算輪廓的凸包和凸度
            hull = cv2.convexHull(contour)
            hull_area = cv2.contourArea(hull)
            if hull_area > 0:
                solidity = area_pixels / hull_area
            else:
                solidity = 0
            
            # 放寬凸度要求
            if solidity < 0.1:  # 降低到0.1
                continue
            
            # 近似輪廓為多邊形
            epsilon = 0.01 * cv2.arcLength(contour, True)  # 更精確的近似
            approx = cv2.approxPolyDP(contour, epsilon, True)
            
            # 轉換為我們的格式
            polygon = []
            for point in approx:
                polygon.append({
                    'x': float(point[0][0]),
                    'y': float(point[0][1])
                })
            
            region = {
                'polygon': polygon,
                'area_pixels': area_pixels,
                'area_meters': area_meters,
                'bounds': {
                    'x': x, 'y': y, 'width': w, 'height': h
                },
                'solidity': solidity,
                'contour_index': i
            }
            
            valid_regions.append(region)
        
        # 按面積排序，小的優先（房間通常比整體建築小）
        valid_regions.sort(key=lambda r: r['area_meters'])
        
        return valid_regions
    
    def convert_polygon_regions(self, polygon_regions, scale):
        """將多邊形區域轉換為標準格式"""
        valid_regions = []
        
        for polygon in polygon_regions:
            area_pixels = self.calculate_polygon_area(polygon)
            area_meters = area_pixels * scale * scale
            
            if area_meters < 1.0:  # 過濾太小的區域
                continue
            
            bounds = self.get_region_bounds(polygon)
            
            region = {
                'polygon': polygon,
                'area_pixels': area_pixels,
                'area_meters': area_meters,
                'bounds': bounds,
                'solidity': 1.0  # 多邊形都設為1.0
            }
            
            valid_regions.append(region)
        
        return valid_regions
    
    def get_region_bounds(self, region):
        """獲取區域邊界"""
        if not region:
            return {'min_x': 0, 'max_x': 0, 'min_y': 0, 'max_y': 0}
        
        xs = [p['x'] for p in region]
        ys = [p['y'] for p in region]
        
        return {
            'min_x': min(xs),
            'max_x': max(xs),
            'min_y': min(ys),
            'max_y': max(ys)
        }
    
    def convert_bounds_to_dict(self, bounds):
        """將邊界轉換為字典格式"""
        return {
            'topLeft': {'x': bounds['min_x'], 'y': bounds['min_y']},
            'topRight': {'x': bounds['max_x'], 'y': bounds['min_y']},
            'bottomRight': {'x': bounds['max_x'], 'y': bounds['max_y']},
            'bottomLeft': {'x': bounds['min_x'], 'y': bounds['max_y']}
        }
    
    def detect_orientation(self, width, height):
        """檢測方位"""
        if width > height * 1.5:
            return '東西'
        elif height > width * 1.5:
            return '南北'
        else:
            return '混合'

def main():
    """主函數，處理命令列參數並執行平面圖分析"""
    parser = argparse.ArgumentParser(description='平面圖閉合區域識別分析')
    parser.add_argument('--image', required=True, help='輸入圖像檔案路徑')
    parser.add_argument('--scale', type=float, default=0.01, help='比例尺（公尺/像素）')
    parser.add_argument('--output', choices=['json', 'verbose'], default='json', help='輸出格式')
    parser.add_argument('--tolerance', type=float, default=5.0, help='線段合併容差（像素）')
    parser.add_argument('--min-room-area', type=int, default=500, help='最小房間面積（像素²）')
    
    args = parser.parse_args()
    
    try:
        # 創建分析器實例
        analyzer = FloorplanAnalyzer(tolerance=args.tolerance, min_room_area=args.min_room_area)
        
        # 執行分析
        result = analyzer.analyze_floorplan(args.image, args.scale)
        
        if args.output == 'json':
            # 輸出JSON格式結果
            print(json.dumps(result, ensure_ascii=False, indent=2))
        else:
            # 詳細輸出
            if result['success']:
                print(f"分析成功！")
                print(f"識別到 {len(result['floors'])} 個樓層")
                print(f"識別到 {len(result['units'])} 個單元")
                print(f"識別到 {len(result['rooms'])} 個房間")
                print(f"識別到 {len(result['windows'])} 個窗戶")
                
                if 'statistics' in result:
                    stats = result['statistics']
                    print(f"\n分析統計：")
                    print(f"- 總輪廓數: {stats.get('total_contours', 'N/A')}")
                    print(f"- 有效區域數: {stats.get('valid_regions', 'N/A')}")
                    print(f"- 識別房間數: {stats.get('identified_rooms', 'N/A')}")
            else:
                print(f"分析失敗: {result['error']}")
                sys.exit(1)
    
    except Exception as e:
        error_result = {
            'success': False,
            'error': f'執行錯誤: {str(e)}'
        }
        
        if args.output == 'json':
            print(json.dumps(error_result, ensure_ascii=False, indent=2))
        else:
            print(f"錯誤: {str(e)}")
        
        sys.exit(1)

if __name__ == "__main__":
    main() 