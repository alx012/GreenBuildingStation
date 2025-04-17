class I18n {
    constructor() {
        this.currentLang = localStorage.getItem('language') || 'zh-TW';
        this.init();
    }

    init() {
        document.documentElement.lang = this.currentLang;
        this.updateContent();
    }

    switchLanguage() {
        this.currentLang = this.currentLang === 'zh-TW' ? 'en' : 'zh-TW';
        localStorage.setItem('language', this.currentLang);
        this.init();
    }

    updateContent() {
        // 更新標題
        document.title = translations[this.currentLang].title;
        
        // 更新按鈕文字
        document.getElementById('greenBuildingBtn').textContent = 
            translations[this.currentLang].greenBuilding;
        document.getElementById('urbanClimateBtn').textContent = 
            translations[this.currentLang].urbanClimate;
    }

    getText(key) {
        return translations[this.currentLang][key];
    }
}

// 初始化 i18n
const i18n = new I18n();