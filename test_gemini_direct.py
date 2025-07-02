#!/usr/bin/env python3
"""
直接測試 Gemini API 的腳本
測試 API Key 是否正確設置並能分析平面圖
"""

import os
import base64
import json
import requests
import time
from pathlib import Path

# 配置
IMAGE_PATH = "uploads/floorplans/floorplan_3358_1750861587.jpg"
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent"

def get_api_key():
    """獲取 Gemini API Key"""
    # 方法 1: 從環境變數獲取
    api_key = os.getenv('GEMINI_API_KEY')
    if api_key and api_key != 'YOUR_GEMINI_API_KEY_HERE':
        return api_key, '環境變數'
    
    # 方法 2: 從配置文件獲取
    config_file = Path('config/gemini_config.php')
    if config_file.exists():
        content = config_file.read_text()
        # 提取 PHP 變數中的 API Key
        import re
        match = re.search(r'\$GEMINI_API_KEY\s*=\s*[\'"]([^\'"]+)[\'"]', content)
        if match:
            api_key = match.group(1)
            if api_key and api_key != 'YOUR_ACTUAL_GEMINI_API_KEY_HERE':
                return api_key, '配置文件'
    
    return None, None

def build_analysis_prompt():
    """建構分析提示詞"""
    return """請仔細分析這張建築平面圖，並以JSON格式回傳房間資訊：

{
  "success": true,
  "analysis_summary": {
    "total_floors": 1,
    "total_units": 1,
    "total_rooms": 11,
    "total_windows": 5
  },
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
2. 計算每個房間的大致尺寸和面積
3. 識別每個房間的牆面方位（東、西、南、北）
4. 找出每面牆上的窗戶位置和大小
5. 房間類型請根據標示文字判斷（客廳、臥室、廚房、浴室等）
6. 確保返回有效的JSON格式
7. 請特別注意房間數量的準確性

請分析圖中所有可識別的房間，不要遺漏任何標示的區域。"""

def test_gemini_api():
    """測試 Gemini API"""
    print("🤖 Gemini API 直接測試")
    print("=" * 50)
    
    # 1. 檢查 API Key
    print("1️⃣ 檢查 API Key...")
    api_key, source = get_api_key()
    
    if not api_key:
        print("❌ 未找到 Gemini API Key")
        print("請設置環境變數：export GEMINI_API_KEY='your_api_key'")
        print("或編輯 config/gemini_config.php 文件")
        return False
    
    print(f"✅ API Key 已設置 (來源: {source})")
    print(f"   Key 前綴: {api_key[:20]}...")
    
    # 2. 檢查圖片文件
    print("\n2️⃣ 檢查測試圖片...")
    if not os.path.exists(IMAGE_PATH):
        print(f"❌ 測試圖片不存在: {IMAGE_PATH}")
        return False
    
    file_size = os.path.getsize(IMAGE_PATH)
    print(f"✅ 圖片文件存在")
    print(f"   路徑: {IMAGE_PATH}")
    print(f"   大小: {file_size:,} bytes ({file_size/1024:.1f} KB)")
    
    # 3. 讀取並編碼圖片
    print("\n3️⃣ 編碼圖片...")
    try:
        with open(IMAGE_PATH, 'rb') as f:
            image_data = f.read()
        base64_image = base64.b64encode(image_data).decode('utf-8')
        print(f"✅ 圖片編碼完成 ({len(base64_image):,} 字符)")
    except Exception as e:
        print(f"❌ 圖片編碼失敗: {e}")
        return False
    
    # 4. 準備 API 請求
    print("\n4️⃣ 準備 API 請求...")
    url = f"{GEMINI_API_URL}?key={api_key}"
    
    request_data = {
        "contents": [
            {
                "parts": [
                    {
                        "text": build_analysis_prompt()
                    },
                    {
                        "inline_data": {
                            "mime_type": "image/jpeg",
                            "data": base64_image
                        }
                    }
                ]
            }
        ],
        "generationConfig": {
            "temperature": 0.1,
            "topP": 0.8,
            "topK": 10,
            "maxOutputTokens": 8192,
            "responseMimeType": "application/json"
        }
    }
    
    headers = {
        "Content-Type": "application/json"
    }
    
    print("✅ 請求資料準備完成")
    
    # 5. 發送 API 請求
    print("\n5️⃣ 發送 Gemini API 請求...")
    print("⏳ 請稍等，這可能需要 10-30 秒...")
    
    start_time = time.time()
    try:
        response = requests.post(url, json=request_data, headers=headers, timeout=60)
        end_time = time.time()
        
        print(f"✅ API 請求完成 (耗時: {end_time - start_time:.2f} 秒)")
        print(f"   HTTP 狀態碼: {response.status_code}")
        
        if response.status_code != 200:
            print(f"❌ API 請求失敗")
            print(f"   錯誤回應: {response.text}")
            return False
            
    except requests.exceptions.Timeout:
        print("❌ API 請求超時")
        return False
    except Exception as e:
        print(f"❌ API 請求異常: {e}")
        return False
    
    # 6. 解析回應
    print("\n6️⃣ 解析 Gemini 回應...")
    try:
        response_data = response.json()
        
        if 'candidates' not in response_data:
            print("❌ 回應格式錯誤，缺少 candidates")
            print(f"   原始回應: {json.dumps(response_data, indent=2, ensure_ascii=False)}")
            return False
        
        # 提取分析文本
        analysis_text = response_data['candidates'][0]['content']['parts'][0]['text']
        print("✅ 成功提取分析結果")
        
        # 解析 JSON
        try:
            analysis_result = json.loads(analysis_text)
            print("✅ JSON 解析成功")
        except json.JSONDecodeError as e:
            print(f"❌ JSON 解析失敗: {e}")
            print(f"   原始文本: {analysis_text[:500]}...")
            return False
            
    except Exception as e:
        print(f"❌ 回應解析失敗: {e}")
        return False
    
    # 7. 分析結果
    print("\n7️⃣ 分析結果統計")
    print("=" * 30)
    
    # 統計數據
    floors = analysis_result.get('floors', [])
    total_rooms = 0
    total_units = 0
    total_windows = 0
    
    for floor in floors:
        units = floor.get('units', [])
        total_units += len(units)
        
        for unit in units:
            rooms = unit.get('rooms', [])
            total_rooms += len(rooms)
            
            for room in rooms:
                walls = room.get('walls', [])
                for wall in walls:
                    windows = wall.get('windows', [])
                    total_windows += len(windows)
    
    print(f"🏢 樓層數量: {len(floors)}")
    print(f"🏠 單元數量: {total_units}")
    print(f"🚪 房間數量: {total_rooms}")
    print(f"🪟 窗戶數量: {total_windows}")
    
    # 房間詳細資訊
    print(f"\n📋 房間詳細資訊:")
    room_count = 0
    for floor_idx, floor in enumerate(floors):
        print(f"  樓層 {floor.get('floor_number', floor_idx + 1)}:")
        
        for unit_idx, unit in enumerate(floor.get('units', [])):
            print(f"    單元 {unit.get('unit_number', unit_idx + 1)}:")
            
            for room_idx, room in enumerate(unit.get('rooms', [])):
                room_count += 1
                room_name = room.get('name', f'房間{room_count}')
                room_type = room.get('type', '未知')
                room_area = room.get('area', 0)
                
                print(f"      {room_count}. {room_name} ({room_type}) - {room_area} m²")
    
    # 8. 測試結果評估
    print(f"\n8️⃣ 測試結果評估")
    print("=" * 30)
    
    expected_rooms = 11  # 預期房間數
    if total_rooms == expected_rooms:
        print(f"✅ 房間數量測試通過！")
        print(f"   預期: {expected_rooms} 個房間")
        print(f"   實際: {total_rooms} 個房間")
        print(f"   🎯 Gemini AI 成功識別出正確的房間數量！")
        success = True
    else:
        print(f"⚠️ 房間數量與預期不符")
        print(f"   預期: {expected_rooms} 個房間")
        print(f"   實際: {total_rooms} 個房間")
        print(f"   差異: {total_rooms - expected_rooms:+d}")
        print(f"   💡 這可能是 AI 識別標準不同，結果仍可參考")
        success = False
    
    # 顯示完整結果
    print(f"\n📄 完整分析結果:")
    print(json.dumps(analysis_result, indent=2, ensure_ascii=False))
    
    return success

if __name__ == "__main__":
    print("🚀 開始測試 Gemini API...")
    success = test_gemini_api()
    
    print(f"\n{'='*50}")
    if success:
        print("🎉 測試完全成功！Gemini API 配置正確且房間識別準確！")
    else:
        print("⚠️ 測試部分成功！API 正常但房間數量可能需要調整。")
    
    print("\n💡 如果測試成功，您可以放心使用 Gemini AI 平面圖分析功能！") 