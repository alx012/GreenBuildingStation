# Speckle 整合功能說明

## 概述
本系統已整合 Speckle 平台，讓使用者可以直接從 Revit 模型匯入建築資料到綠建築計算系統中。

## 使用步驟

### 1. 準備 Revit 模型
在您的 Revit 中安裝 Speckle 外掛，並將建築模型發送到 Speckle 平台。

### 2. 取得 Speckle 存取權杖
1. 造訪 [Speckle 個人檔案頁面](https://speckle.xyz/profile)
2. 在「Developer Settings」區域創建新的 Personal Access Token
3. 複製此權杖，稍後在系統中會需要使用

### 3. 在系統中創建專案
1. 點擊「新增專案」按鈕
2. 填寫專案名稱和地址
3. 選擇「從 Speckle 匯入 Revit 模型」選項
4. 貼上您的 Speckle 存取權杖
5. 點擊「載入我的 Speckle 專案」
6. 從下拉選單中選擇要匯入的專案和模型
7. 點擊「創建專案」

### 4. 檢視 3D 模型
專案創建後，如果成功連結 Speckle 模型，您將看到：
- 在固定按鈕區域出現「3D 模型」按鈕
- 點擊此按鈕可開啟內嵌的 Speckle Viewer
- 在 Viewer 中可以檢視、旋轉、縮放 3D 建築模型

## 功能特色

### Speckle API 整合
- 自動擷取使用者的 Speckle 專案列表
- 支援選擇特定模型版本
- 即時載入模型資料

### 3D 檢視器
- 使用官方 Speckle Viewer
- 支援模型的互動式檢視
- 顯示建築元件的詳細資訊

### 資料同步
- 專案與 Speckle 模型的關聯儲存在資料庫中
- 支援未來擴充自動同步功能

## 技術實作

### 前端
- 使用 Bootstrap 5 建構 UI
- 整合 Speckle Viewer 2.0
- AJAX 處理 API 呼叫

### 後端
- PHP 處理 Speckle GraphQL API 呼叫
- SQL Server 資料庫儲存關聯資訊
- 自動資料庫結構更新

### 資料庫欄位
```sql
-- 新增到 GBD_Project 資料表的欄位
speckle_project_id NVARCHAR(255) NULL
speckle_model_id NVARCHAR(255) NULL
```

## 未來擴充
- 自動從 Speckle 模型解析房間資訊
- 支援模型變更時的自動同步
- 整合更多 Speckle 元件屬性用於綠建築計算

## 故障排除

### 常見問題
1. **無法載入 Speckle 專案**
   - 檢查存取權杖是否正確
   - 確認權杖擁有足夠的權限

2. **3D 模型無法顯示**
   - 確認瀏覽器支援 WebGL
   - 檢查網路連線是否穩定

3. **資料庫錯誤**
   - 系統會自動嘗試創建必要的資料庫欄位
   - 如持續發生錯誤，請檢查資料庫權限

### 支援的瀏覽器
- Chrome 80+
- Firefox 75+
- Safari 13+
- Edge 80+

## 聯絡資訊
如有任何技術問題或建議，請聯絡開發團隊。 