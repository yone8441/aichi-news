<?php
/**
 * 愛知県ニュース＆天気 自動収集スクリプト (Pure PHP)
 * 1日3回 GitHub Actions またはローカル環境から実行されます。
 */

date_default_timezone_set('Asia/Tokyo');

$newsJsonPath = __DIR__ . '/../data/news.json';

echo "=== 愛知県ニュース＆天気 収集バッチ開始 ===" . PHP_EOL;

// 1. 気象庁APIから愛知県の天気データを取得
$weatherData = fetchAichiWeather();

// 2. Google News RSSから愛知県ニュースを取得
$articles = fetchAichiNews();

if (empty($articles)) {
    echo "警告: ニュース記事を取得できませんでした。既存の news.json を維持します。" . PHP_EOL;
    exit(0);
}

// 3. 配信セグメント判定 (朝刊 / 昼刊 / 夕刊)
$hour = (int)date('H');
if ($hour >= 5 && $hour < 11) {
    $session = "朝刊便（07:00配信）";
} elseif ($hour >= 11 && $hour < 16) {
    $session = "昼刊便（12:00配信）";
} else {
    $session = "夕刊便（17:00配信）";
}

// 4. JSON構造の構築
$output = [
    'last_updated' => date('c'), // ISO 8601 (例: 2026-09-22T07:15:00+09:00)
    'update_session' => $session,
    'weather' => $weatherData,
    'total_count' => count($articles),
    'articles' => $articles
];

$jsonContent = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// 5. ファイル保存
if (!is_dir(dirname($newsJsonPath))) {
    mkdir(dirname($newsJsonPath), 0755, true);
}

file_put_contents($newsJsonPath, $jsonContent);

echo "正常終了: " . count($articles) . " 件のニュースを保存しました。(" . $newsJsonPath . ")" . PHP_EOL;

// ==========================================
// ヘルパー関数群
// ==========================================

/**
 * 気象庁API (愛知県: 230000) から天気を取得
 */
function fetchAichiWeather() {
    $apiUrl = 'https://www.jma.go.jp/bosai/forecast/data/forecast/230000.json';
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($options);
    $raw = @file_get_contents($apiUrl, false, $context);

    // デフォルト値
    $weather = [
        'location' => '愛知県（西部・東部）',
        'condition' => '晴れ',
        'icon' => '☀️',
        'temp_max' => 26,
        'temp_min' => 18,
        'rain_prob' => '10%'
    ];

    if (!$raw) {
        return $weather;
    }

    $data = json_decode($raw, true);
    if (!isset($data[0]['timeSeries'])) {
        return $weather;
    }

    try {
        // 天気概況
        $weathers = $data[0]['timeSeries'][0]['areas'][0]['weathers'] ?? [];
        if (!empty($weathers)) {
            $condition = $weathers[0];
            // 全角空白や不要文字のトリミング
            $condition = preg_replace('/\s+/', ' ', trim($condition));
            $weather['condition'] = $condition;

            // アイコン判定
            if (mb_strpos($condition, '雨') !== false) {
                $weather['icon'] = '🌧️';
            } elseif (mb_strpos($condition, '曇') !== false) {
                $weather['icon'] = '☁️';
            } elseif (mb_strpos($condition, '晴') !== false) {
                $weather['icon'] = mb_strpos($condition, '曇') !== false ? '🌤️' : '☀️';
            }
        }

        // 降水確率
        $pops = $data[0]['timeSeries'][1]['areas'][0]['pops'] ?? [];
        if (!empty($pops)) {
            $weather['rain_prob'] = end($pops) . '%';
        }

        // 気温 (timeSeries[2] にある場合)
        if (isset($data[0]['timeSeries'][2]['areas'][0]['temps'])) {
            $temps = $data[0]['timeSeries'][2]['areas'][0]['temps'];
            if (count($temps) >= 2) {
                $weather['temp_min'] = (int)$temps[0];
                $weather['temp_max'] = (int)$temps[1];
            }
        }
    } catch (\Throwable $e) {
        // パースエラー時はデフォルト値を返す
    }

    return $weather;
}

/**
 * Google News RSS から愛知県ニュースを取得
 */
function fetchAichiNews() {
    $rssUrl = 'https://news.google.com/rss/search?q=' . urlencode('愛知県 when:24h') . '&hl=ja&gl=JP&ceid=JP:ja';
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            'timeout' => 15
        ]
    ];
    $context = stream_context_create($options);
    $xmlString = @file_get_contents($rssUrl, false, $context);

    if (!$xmlString) {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if (!$xml || !isset($xml->channel->item)) {
        return [];
    }

    $articles = [];
    $count = 0;
    $maxCount = 20; // 最大20件に厳選

    foreach ($xml->channel->item as $item) {
        if ($count >= $maxCount) break;

        $rawTitle = (string)$item->title;
        $link = (string)$item->link;
        $pubDate = (string)$item->pubDate;
        $desc = (string)$item->description;

        // タイトルと配信元を分割 ("記事タイトル - メディア名")
        $source = '提供元';
        $title = $rawTitle;
        if (preg_match('/^(.*?)\s*-\s*([^-]+)$/u', $rawTitle, $matches)) {
            $title = trim($matches[1]);
            $source = trim($matches[2]);
        }

        // 概要テキストのHTMLタグ除去
        $cleanDesc = trim(strip_tags(html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        // 概要からタイトルや配信元と被る部分を調整
        if (mb_strlen($cleanDesc) < 10) {
            $cleanDesc = $title;
        }

        // 地域判定（キーワードマッチ）
        $area = detectArea($title . ' ' . $cleanDesc);

        // カテゴリ判定
        $category = detectCategory($title . ' ' . $cleanDesc);

        // 公開日時のパース (ISO 8601形式へ)
        $timestamp = strtotime($pubDate) ?: time();
        $isoPublishedAt = date('c', $timestamp);

        // ダミーサムネイル画像（Unsplashの関連写真）
        $imageUrl = getFallbackImage($category, $area);

        $articles[] = [
            'id' => 'aichi-' . date('Ymd', $timestamp) . '-' . sprintf('%03d', $count + 1),
            'title' => $title,
            'source' => $source,
            'published_at' => $isoPublishedAt,
            'category' => $category,
            'area' => $area,
            'summary' => mb_substr($cleanDesc, 0, 90) . (mb_strlen($cleanDesc) > 90 ? '...' : ''),
            'url' => $link,
            'image_url' => $imageUrl
        ];

        $count++;
    }

    return $articles;
}

/**
 * テキストから地域を判定
 */
function detectArea($text) {
    if (preg_match('/(名古屋|栄|名駅|鶴舞|千種|熱田|中川|東区|中区|港区)/u', $text)) {
        return '名古屋';
    }
    if (preg_match('/(一宮|春日井|小牧|江南|犬山|瀬戸|長久手|日進|清須|北名古屋|津島|知多|半田|東海市|常滑|大府)/u', $text)) {
        return '尾張';
    }
    if (preg_match('/(豊田|岡崎|安城|刈谷|西尾|知立|高浜|みよし|碧南|豊橋|豊川|蒲郡|新城|田原|設楽|東栄|豊根|三河)/u', $text)) {
        return '三河';
    }
    return '愛知';
}

/**
 * テキストからカテゴリを判定
 */
function detectCategory($text) {
    if (preg_match('/(事件|事故|警察|逮捕|火災|裁判|捜査)/u', $text)) {
        return '社会';
    }
    if (preg_match('/(企業|ビジネス|開発|投資|スタートアップ|経済|工場|株|売上)/u', $text)) {
        return 'ビジネス';
    }
    if (preg_match('/(知事|県議|市長|市議|選挙|予算|政策|条例|愛知県)/u', $text)) {
        return '政治・行政';
    }
    if (preg_match('/(祭り|イベント|観光|ジブリ|名所|開園|オープン|展覧会|フェス)/u', $text)) {
        return '観光・イベント';
    }
    if (preg_match('/(グランパス|ドラゴンズ|野球|サッカー|バスケ|選手|試合|勝利|敗戦)/u', $text)) {
        return 'スポーツ';
    }
    if (preg_match('/(交通|鉄道|ダイヤ|新幹線|名鉄|近鉄|道路|天気|暮らし|グルメ)/u', $text)) {
        return '暮らし・交通';
    }
    return '一般ニュース';
}

/**
 * カテゴリ・地域に応じたイメージ写真
 */
function getFallbackImage($category, $area) {
    $images = [
        'ビジネス' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=400&auto=format&fit=crop&q=80',
        '観光・イベント' => 'https://images.unsplash.com/photo-1513836279014-a89f7a76ae86?w=400&auto=format&fit=crop&q=80',
        '暮らし・交通' => 'https://images.unsplash.com/photo-1517649763962-0c623266ddc0?w=400&auto=format&fit=crop&q=80',
        '政治・行政' => 'https://images.unsplash.com/photo-1541872703-74c5e44368f9?w=400&auto=format&fit=crop&q=80',
        'スポーツ' => 'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=400&auto=format&fit=crop&q=80',
        '社会' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=400&auto=format&fit=crop&q=80'
    ];
    return $images[$category] ?? 'https://images.unsplash.com/photo-1585829365295-ab7cd400c167?w=400&auto=format&fit=crop&q=80';
}
