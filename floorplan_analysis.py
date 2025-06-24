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
            
            # 2. 邊緣檢測
            edges = self.preprocess_image(image)
            
            # 3. 線段提取
            segments = self.extract_segments(edges)
            print(f"提取到 {len(segments)} 條線段")
            
            # 4. 分割線段（在交點處）
            split_segments = self.split_segments_at_intersections(segments)
            print(f"分割後有 {len(split_segments)} 條線段")
            
            # 5. 構建鄰接表
            adjacency_map = self.build_adjacency_map(split_segments)
            print(f"構建了 {len(adjacency_map)} 個節點的鄰接表")
            
            # 6. 尋找閉合區域
            closed_regions = self.find_closed_regions(adjacency_map)
            print(f"找到 {len(closed_regions)} 個閉合區域")
            
            # 7. 分類建築元素
            building_elements = self.classify_regions(closed_regions, scale)
            
            return {
                'success': True,
                'floors': building_elements['floors'],
                'units': building_elements['units'],
                'rooms': building_elements['rooms'],
                'windows': building_elements['windows'],
                'statistics': {
                    'total_segments': len(segments),
                    'split_segments': len(split_segments),
                    'closed_regions': len(closed_regions),
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
        
        # 高斯模糊
        blurred = cv2.GaussianBlur(gray, (5, 5), 0)
        
        # 自適應閾值二值化
        binary = cv2.adaptiveThreshold(blurred, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, 
                                     cv2.THRESH_BINARY_INV, 11, 2)
        
        # 形態學操作：閉運算
        kernel = np.ones((3, 3), np.uint8)
        binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel)
        
        # Canny邊緣檢測
        edges = cv2.Canny(binary, 50, 150, apertureSize=3)
        
        return edges
    
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
    
    def classify_regions(self, regions, scale):
        """分類區域為建築元素"""
        floors = []
        units = []
        rooms = []
        windows = []
        
        for i, region in enumerate(regions):
            area_pixels = self.calculate_polygon_area(region)
            area_meters = area_pixels * scale * scale
            
            bounds = self.get_region_bounds(region)
            width = (bounds['max_x'] - bounds['min_x']) * scale
            height = (bounds['max_y'] - bounds['min_y']) * scale
            
            if area_meters > 500:  # 大區域 - 樓層
                floors.append({
                    'floor_number': len(floors) + 1,
                    'area': area_meters,
                    'bounds': bounds
                })
            elif area_meters > 100:  # 中等區域 - 單元
                units.append({
                    'unit_number': len(units) + 1,
                    'area': area_meters,
                    'width': width,
                    'height': height,
                    'bounds': bounds
                })
            elif area_meters > 10:  # 小區域 - 房間
                rooms.append({
                    'room_number': len(rooms) + 1,
                    'area': area_meters,
                    'width': width,
                    'height': height,
                    'bounds': self.convert_bounds_to_dict(bounds),
                    'wall_orientation': self.detect_orientation(width, height),
                    'window_position': self.detect_window_position(region)
                })
            elif area_meters > 1:  # 很小區域 - 窗戶
                center = self.get_polygon_center(region)
                windows.append({
                    'window_id': len(windows) + 1,
                    'area': area_meters,
                    'width': width,
                    'height': height,
                    'position': center
                })
        
        return {
            'floors': floors,
            'units': units,
            'rooms': rooms,
            'windows': windows
        }
    
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
    
    def detect_window_position(self, region):
        """檢測窗戶位置"""
        center = self.get_polygon_center(region)
        
        # 簡化的位置判斷
        if center['y'] < 200:
            return '北'
        elif center['y'] > 600:
            return '南'
        elif center['x'] < 300:
            return '西'
        elif center['x'] > 700:
            return '東'
        else:
            return '中央'

def main():
    parser = argparse.ArgumentParser(description='平面圖閉合區域識別')
    parser.add_argument('image_path', help='圖像檔案路徑')
    parser.add_argument('--scale', type=float, default=0.01, help='比例尺（公尺/像素）')
    parser.add_argument('--tolerance', type=float, default=5.0, help='線段合併容差')
    parser.add_argument('--min-area', type=float, default=500, help='最小房間面積（像素²）')
    parser.add_argument('--output', help='輸出JSON檔案路徑')
    
    args = parser.parse_args()
    
    analyzer = FloorplanAnalyzer(tolerance=args.tolerance, min_room_area=args.min_area)
    result = analyzer.analyze_floorplan(args.image_path, args.scale)
    
    if args.output:
        with open(args.output, 'w', encoding='utf-8') as f:
            json.dump(result, f, ensure_ascii=False, indent=2)
        print(f"結果已儲存到 {args.output}")
    else:
        print(json.dumps(result, ensure_ascii=False, indent=2))

if __name__ == '__main__':
    main() 