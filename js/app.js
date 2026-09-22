/**
 * 愛知県ニュースアプリ (Pure JavaScript)
 */

document.addEventListener('DOMContentLoaded', () => {
  // ==========================================
  // 状態管理
  // ==========================================
  let articles = [];
  let currentArea = 'all';
  let searchQuery = '';
  let inputPin = '';
  const DEFAULT_PIN = '0928'; // 初期PINコード

  // ==========================================
  // DOM要素
  // ==========================================
  // ニュース関連
  const newsList = document.getElementById('newsList');
  const areaTabs = document.querySelectorAll('.area-tab');
  const sessionText = document.getElementById('sessionText');
  const lastUpdatedText = document.getElementById('lastUpdatedText');
  const articleCountText = document.getElementById('articleCount');
  const refreshBtn = document.getElementById('refreshBtn');

  // 天気関連
  const weatherIcon = document.getElementById('weatherIcon');
  const weatherTemp = document.getElementById('weatherTemp');
  const weatherRain = document.getElementById('weatherRain');

  // 検索関連
  const searchInput = document.getElementById('searchInput');
  const searchClearBtn = document.getElementById('searchClearBtn');

  // テーマ関連
  const themeToggleBtn = document.getElementById('themeToggleBtn');

  // パスコードロック関連
  const lockScreen = document.getElementById('lockScreen');
  const pinDots = document.querySelectorAll('.pin-dot');
  const keypadBtns = document.querySelectorAll('.key-btn');
  const lockBtn = document.getElementById('lockBtn');
  const lockMessage = document.getElementById('lockMessage');

  // ==========================================
  // 1. パスコードロック機能（クライアント簡易認証）
  // ==========================================
  function initLockSystem() {
    const isAuthed = sessionStorage.getItem('aichi_news_auth');
    if (isAuthed === 'true') {
      unlockApp(false);
    }

    keypadBtns.forEach(btn => {
      btn.addEventListener('click', (e) => {
        const key = e.currentTarget.dataset.key;
        handleKeypadInput(key);
      });
    });

    if (lockBtn) {
      lockBtn.addEventListener('click', lockApp);
    }
  }

  function handleKeypadInput(key) {
    if (key === 'clear') {
      inputPin = '';
      updatePinDisplay();
      return;
    }

    if (key === 'back') {
      inputPin = inputPin.slice(0, -1);
      updatePinDisplay();
      return;
    }

    if (inputPin.length < 4) {
      inputPin += key;
      updatePinDisplay();

      if (inputPin.length === 4) {
        // 4桁揃ったら即照合
        setTimeout(verifyPin, 150);
      }
    }
  }

  function updatePinDisplay() {
    pinDots.forEach((dot, index) => {
      if (index < inputPin.length) {
        dot.classList.add('filled');
      } else {
        dot.classList.remove('filled');
      }
    });
  }

  function verifyPin() {
    const savedPin = localStorage.getItem('aichi_news_pin') || DEFAULT_PIN;
    if (inputPin === savedPin) {
      sessionStorage.setItem('aichi_news_auth', 'true');
      unlockApp(true);
    } else {
      // 失敗時のシェイク
      const dotsContainer = document.getElementById('pinDots');
      dotsContainer.classList.add('shake');
      if (lockMessage) {
        lockMessage.innerHTML = '<span style="color:#ef4444; font-weight:bold;">パスコードが違います</span>';
      }
      setTimeout(() => {
        dotsContainer.classList.remove('shake');
        inputPin = '';
        updatePinDisplay();
      }, 500);
    }
  }

  function unlockApp(animate) {
    lockScreen.classList.add('unlocked');
    inputPin = '';
    updatePinDisplay();
    if (lockMessage) {
      lockMessage.innerHTML = 'プライベートアクセス用PIN（4桁）<br><span style="font-size: 11px; opacity: 0.8;">※ 初期コード: <strong>0928</strong></span>';
    }
  }

  function lockApp() {
    sessionStorage.removeItem('aichi_news_auth');
    lockScreen.classList.remove('unlocked');
    inputPin = '';
    updatePinDisplay();
  }

  // ==========================================
  // 2. ダークモード管理
  // ==========================================
  function initTheme() {
    const savedTheme = localStorage.getItem('aichi_news_theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
      document.body.classList.add('dark-mode');
      if (themeToggleBtn) themeToggleBtn.textContent = '☀️';
    } else {
      document.body.classList.remove('dark-mode');
      if (themeToggleBtn) themeToggleBtn.textContent = '🌙';
    }

    if (themeToggleBtn) {
      themeToggleBtn.addEventListener('click', () => {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('aichi_news_theme', isDark ? 'dark' : 'light');
        themeToggleBtn.textContent = isDark ? '☀️' : '🌙';
      });
    }
  }

  // ==========================================
  // 3. 検索機能
  // ==========================================
  function initSearch() {
    if (!searchInput) return;

    searchInput.addEventListener('input', (e) => {
      searchQuery = e.target.value.trim().toLowerCase();
      if (searchQuery.length > 0) {
        searchClearBtn.classList.add('visible');
      } else {
        searchClearBtn.classList.remove('visible');
      }
      applyFilters();
    });

    if (searchClearBtn) {
      searchClearBtn.addEventListener('click', () => {
        searchInput.value = '';
        searchQuery = '';
        searchClearBtn.classList.remove('visible');
        searchInput.focus();
        applyFilters();
      });
    }
  }

  // ==========================================
  // 4. ニュース一覧レンダリング & フィルター
  // ==========================================
  function applyFilters() {
    let filtered = articles;

    // エリア絞り込み
    if (currentArea !== 'all') {
      filtered = filtered.filter(item => item.area === currentArea || item.area === '全域');
    }

    // キーワード検索絞り込み
    if (searchQuery) {
      filtered = filtered.filter(item => {
        const title = (item.title || '').toLowerCase();
        const summary = (item.summary || '').toLowerCase();
        const source = (item.source || '').toLowerCase();
        const category = (item.category || '').toLowerCase();
        return title.includes(searchQuery) ||
               summary.includes(searchQuery) ||
               source.includes(searchQuery) ||
               category.includes(searchQuery);
      });
    }

    renderArticles(filtered);
  }

  function renderArticles(items) {
    if (!items || items.length === 0) {
      newsList.innerHTML = `
        <div class="empty-state">
          <p>該当するニュースは見つかりませんでした。</p>
          <p style="font-size: 11px; margin-top: 6px;">検索語句や地域フィルターを変更してお試しください。</p>
        </div>
      `;
      if (articleCountText) articleCountText.textContent = '0件';
      return;
    }

    if (articleCountText) articleCountText.textContent = `${items.length}件`;

    newsList.innerHTML = items.map(article => `
      <article class="news-card" data-id="${article.id}">
        <div class="card-header">
          <div class="tag-group">
            <span class="tag-area">${article.area || '愛知'}</span>
            <span class="tag-cat">${article.category || 'ニュース'}</span>
          </div>
          <span class="news-time">${formatTime(article.published_at)}</span>
        </div>

        <div class="card-body">
          <div class="card-info">
            <h2 class="news-title">
              <a href="${article.url}" target="_blank" rel="noopener noreferrer" style="text-decoration:none; color:inherit;">
                ${escapeHtml(article.title)}
              </a>
            </h2>
            <p class="news-summary">${escapeHtml(article.summary || '')}</p>
          </div>
          ${article.image_url ? `
            <div class="news-thumb-wrap">
              <img class="news-thumb" src="${article.image_url}" alt="${escapeHtml(article.title)}" loading="lazy" onerror="this.parentElement.style.display='none';">
            </div>
          ` : ''}
        </div>

        <div class="card-footer">
          <span class="source-name">${escapeHtml(article.source || '提供元')}</span>
          <span class="read-more">記事を読む →</span>
        </div>
      </article>
    `).join('');
  }

  function formatTime(isoString) {
    if (!isoString) return '';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now - date;
    const diffMin = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMin / 60);

    if (diffMin < 1) return 'たった今';
    if (diffMin < 60) return `${diffMin}分前`;
    if (diffHours < 24) return `${diffHours}時間前`;

    const month = date.getMonth() + 1;
    const day = date.getDate();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${month}/${day} ${hours}:${minutes}`;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ==========================================
  // 5. データ取得 (fetch & fallback)
  // ==========================================
  async function loadNews() {
    newsList.innerHTML = '<div class="loading-state"><p>最新の愛知県ニュースを取得中...</p></div>';

    try {
      const response = await fetch('./data/news.json?t=' + Date.now());
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json();

      articles = data.articles || [];
      const lastUpdated = data.last_updated;
      const updateSession = data.update_session || '定期配信';

      // ヘッダ情報の反映
      if (sessionText) sessionText.textContent = updateSession;
      if (lastUpdatedText && lastUpdated) {
        const updateDate = new Date(lastUpdated);
        const timeStr = `${String(updateDate.getHours()).padStart(2, '0')}:${String(updateDate.getMinutes()).padStart(2, '0')}`;
        lastUpdatedText.textContent = `本日 ${timeStr} 更新`;
      }

      // 天気情報の反映
      if (data.weather) {
        if (weatherIcon) weatherIcon.textContent = data.weather.icon || '🌤️';
        if (weatherTemp) weatherTemp.textContent = `${data.weather.temp_max}° / ${data.weather.temp_min}°`;
        if (weatherRain) weatherRain.textContent = `☂ ${data.weather.rain_prob || '10%'}`;
      }

      applyFilters();

    } catch (err) {
      console.warn('ローカルfetch失敗、またはfile://アクセスのため内蔵フォールバックを使用します:', err);
      useFallbackData();
    }
  }

  function useFallbackData() {
    articles = [
      {
        id: "fb-1",
        title: "「STATION Ai」で東海圏最大規模の学生ピッチコンテスト開催へ 愛知県が発表",
        source: "中日ニュース",
        published_at: new Date(Date.now() - 15 * 60 * 1000).toISOString(),
        category: "ビジネス",
        area: "名古屋",
        summary: "愛知県は、名古屋市鶴舞のスタートアップ支援拠点「STATION Ai」にて、大学生・高専生を対象としたビジネスアイデアコンテストを来月開催すると発表した。",
        url: "#",
        image_url: "https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=600&auto=format&fit=crop&q=80"
      },
      {
        id: "fb-2",
        title: "ジブリパーク「魔女の谷」秋の特別ライトアップイベントが10月よりスタート",
        source: "東海Walker Web",
        published_at: new Date(Date.now() - 45 * 60 * 1000).toISOString(),
        category: "観光・イベント",
        area: "尾張",
        summary: "長久手市の愛・地球博記念公園内にあるジブリパークで、秋の夜長を彩る限定ライトアップイベントの開催が決定。夕暮れ時のハウルの城など幻想的な景色が楽しめる。",
        url: "#",
        image_url: "https://images.unsplash.com/photo-1513836279014-a89f7a76ae86?w=600&auto=format&fit=crop&q=80"
      },
      {
        id: "fb-3",
        title: "豊田市 次世代モビリティと自動運転EVバスの実証実験を市内幹線道路で拡大",
        source: "愛知日報",
        published_at: new Date(Date.now() - 90 * 60 * 1000).toISOString(),
        category: "テクノロジー",
        area: "三河",
        summary: "豊田市と地元自動車メーカー各社は、レベル4相当の自動運転EVコミュニティバスの走行実験区間を拡大。持続可能な移動手段の確立を目指す。",
        url: "#",
        image_url: "https://images.unsplash.com/photo-1549399542-7e3f8b79c341?w=600&auto=format&fit=crop&q=80"
      },
      {
        id: "fb-4",
        title: "【秋の味覚】蒲郡みかんの出荷が本格化 甘みと酸味のバランス上々",
        source: "三河農業タイムス",
        published_at: new Date(Date.now() - 180 * 60 * 1000).toISOString(),
        category: "暮らし・交通",
        area: "三河",
        summary: "蒲郡市特産の温州みかんの選果作業がスタート。今年は日照時間が多く糖度が高い仕上がりとなっており、県内スーパーや贈答用として順次発送される。",
        url: "#",
        image_url: "https://images.unsplash.com/photo-1534088568595-a066f410bcda?w=600&auto=format&fit=crop&q=80"
      }
    ];

    if (sessionText) sessionText.textContent = "朝刊便（07:00更新）";
    if (lastUpdatedText) lastUpdatedText.textContent = "本日 07:15 更新";
    if (weatherIcon) weatherIcon.textContent = "🌤️";
    if (weatherTemp) weatherTemp.textContent = "28° / 19°";
    if (weatherRain) weatherRain.textContent = "☂ 10%";

    applyFilters();
  }

  // ==========================================
  // 6. イベントリスナー登録
  // ==========================================
  areaTabs.forEach(tab => {
    tab.addEventListener('click', (e) => {
      areaTabs.forEach(t => t.classList.remove('active'));
      e.currentTarget.classList.add('active');
      currentArea = e.currentTarget.dataset.area;
      applyFilters();
    });
  });

  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      refreshBtn.style.transform = 'rotate(360deg)';
      refreshBtn.style.transition = 'transform 0.5s ease';
      setTimeout(() => {
        refreshBtn.style.transform = 'none';
        refreshBtn.style.transition = '';
      }, 500);
      loadNews();
    });
  }

  // 初期化実行
  initLockSystem();
  initTheme();
  initSearch();
  loadNews();

  // ==========================================
  // 7. PWA Service Worker 登録
  // ==========================================
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('./sw.js')
        .then(reg => console.log('ServiceWorker 登録成功:', reg.scope))
        .catch(err => console.log('ServiceWorker 登録失敗:', err));
    });
  }
});
