-- 更新 GBD_Project 資料表，加入 Speckle 相關欄位
-- 如果欄位不存在才會新增

IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_NAME = 'GBD_Project' 
               AND COLUMN_NAME = 'speckle_project_id' 
               AND TABLE_SCHEMA = 'dbo')
BEGIN
    ALTER TABLE [Test].[dbo].[GBD_Project]
    ADD speckle_project_id NVARCHAR(255) NULL;
END

IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
               WHERE TABLE_NAME = 'GBD_Project' 
               AND COLUMN_NAME = 'speckle_model_id' 
               AND TABLE_SCHEMA = 'dbo')
BEGIN
    ALTER TABLE [Test].[dbo].[GBD_Project]
    ADD speckle_model_id NVARCHAR(255) NULL;
END

-- 建立索引以提升查詢效能
IF NOT EXISTS (SELECT * FROM sys.indexes 
               WHERE name = 'IX_GBD_Project_Speckle' 
               AND object_id = OBJECT_ID('[Test].[dbo].[GBD_Project]'))
BEGIN
    CREATE INDEX IX_GBD_Project_Speckle 
    ON [Test].[dbo].[GBD_Project] (speckle_project_id, speckle_model_id);
END

PRINT 'Speckle 欄位已成功加入到 GBD_Project 資料表'; 