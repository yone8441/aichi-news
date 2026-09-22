# あいちNews速報 (Aichi News Web App)

一日3回、愛知県の最新ニュースと気象情報を自動収集して配信するスマートフォン向けWebアプリケーションです。  
サーバー代完全無料（**GitHub Actions** ＋ **GitHub Pages**）のJamstack構成で運用できます。

---

## 📱 主な機能

- **一日3回の定期配信**: 朝（07:00）、昼（12:30）、夕（18:00）の自動収集
- **愛知の気象情報**: 気象庁公式データと連動したリアルタイム天気・気温・降水確率表示
- **リアルタイムキーワード検索**: タイトル・要約・配信元のインクリメンタル検索
- **地域別フィルター**: 名古屋、尾張・知多、西三河・東三河、全域
- **ダークモード対応**: ワンタップ切り替え＆端末設定連動
- **プライベートPINロック**: 4桁パスコード（初期値: `7580`）による覗き見防止

---

## 🛠 システム構成

```
[定期収集バッチ (1日3回)]
GitHub Actions (.github/workflows/update_news.yml)
  │
  ├─ ① scripts/fetch_news.php (Pure PHP)
  │    ├─ 気象庁API (愛知県: 230000)
  │    └─ Google News RSS (愛知県クエリ)
  │
  └─ ② data/news.json を自動コミット & プッシュ
          │
          ▼
[画面表示 (Webアプリ)]
GitHub Pages
  │
  └─ index.html + Pure JavaScript (js/app.js) で news.json を fetch して描画
```

---

## 📂 ディレクトリ構成

```text
├── .github/
│   └── workflows/
│       └── update_news.yml   # GitHub Actions 定期実行ワークフロー
├── css/
│   └── style.css             # モバイルファーストCSS（ダークモード対応）
├── data/
│   └── news.json             # 収集されたニュース＆気象データ（DB代わり）
├── js/
│   └── app.js                # Pure JavaScript（UI操作・fetch・PIN認証）
├── scripts/
│   └── fetch_news.php        # ニュース＆天気収集バッチ（Pure PHP）
├── index.html                # メイン画面HTML
└── README.md
```

---

## 🚀 GitHubへのデプロイ・運用手順

### 1. リポジトリの作成とプッシュ
```bash
git init
git add .
git commit -m "Initial commit: あいちNews速報"
git branch -M main
git remote add origin https://github.com/<あなたのユーザー名>/<リポジトリ名>.git
git push -u origin main
```

### 2. GitHub Actions の書き込み権限を許可（★重要）
1. GitHubリポジトリの **[Settings]** タブを開きます。
2. 左メニューの **[Actions]** -> **[General]** を選択します。
3. ページ下部の **[Workflow permissions]** で **「Read and write permissions」** を選択し、**[Save]** をクリックします。
   *(※ これを行わないと、GitHub Actionsからの git push が権限エラーになります)*

### 3. GitHub Pages の公開
1. リポジトリの **[Settings]** -> **[Pages]** を開きます。
2. **[Build and deployment]** の Source を **「Deploy from a branch」** にします。
3. Branch を **`main`**、フォルダを **`/ (root)`** に設定して **[Save]** をクリックします。
4. 数分後、発行されたURL（`https://<ユーザー名>.github.io/<リポジトリ名>/`）からスマートフォンでアクセスできます！
