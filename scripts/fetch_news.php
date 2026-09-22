<?php
/**
 * 愛知県＆豊橋市 ニュース・イベント・天気 自動収集スクリプト (Pure PHP)
 * 1日3回 GitHub Actions またはローカル環境から実行されます。
 */

date_default_timezone_set('Asia/Tokyo');

$newsJsonPath = __DIR__ . '/../data/news.json';

echo "=== 愛知県＆豊橋市 ニュース・天気 収集バッチ開始 ===" . PHP_EOL;

// 1. 気象庁APIから愛知県の天気データを取得
$weatherData = fetchAichiWeather();

// 2. 記事収集
// A. 豊橋市役所 公式新着RSS
$cityNotices = fetchToyohashiCityRss();
echo "- 豊橋市役所お知らせ取得: " . count($cityNotices) . " 件" . PHP_EOL;

// B. 豊橋市特化 ニュース＆イベント (Google News)
$toyohashiNews = fetchToyohashiNews();
echo "- 豊橋市ニュース＆イベント取得: " . count($toyohashiNews) . " 件" . PHP_EOL;

// C. 愛知県全般ニュース (Google News)
$aichiNews = fetchAichiNews();
echo "- 愛知県全般ニュース取得: " . count($aichiNews) . " 件" . PHP_EOL;

// 3. マージと重複排除（豊橋の情報を優先して上位へ）
$allArticles = array_merge($cityNotices, $toyohashiNews, $aichiNews);
$uniqueArticles = [];
$seenTitles = [];

foreach ($allArticles as $art) {
    // 簡易的なタイトル正規化で重複排除
    $normTitle = mb_substr(preg_replace('/[\s\p{P}]+/u', '', $art['title']), 0, 20);
    if (!isset($seenTitles[$normTitle])) {
        $seenTitles[$normTitle] = true;
        $uniqueArticles[] = $art;
    }
}

// 最大30件までに厳選
$finalArticles = array_slice($uniqueArticles, 0, 30);

if (empty($finalArticles)) {
    echo "警告: ニュース記事を取得できませんでした。既存の news.json を維持します。" . PHP_EOL;
    exit(0);
}

// 4. 配信セグメント判定 (朝刊 / 昼刊 / 夕刊)
$hour = (int)date('H');
if ($hour >= 5 && $hour < 11) {
    $session = "朝刊便（07:00配信）";
} elseif ($hour >= 11 && $hour < 16) {
    $session = "昼刊便（12:00配信）";
} else {
    $session = "夕刊便（17:00配信）";
}

// 5. JSON構造の構築
$output = [
    'last_updated' => date('c'),
    'update_session' => $session,
    'weather' => $weatherData,
    'total_count' => count($finalArticles),
    'articles' => $finalArticles
];

$jsonContent = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// 6. ファイル保存
if (!is_dir(dirname($newsJsonPath))) {
    mkdir(dirname($newsJsonPath), 0755, true);
}

file_put_contents($newsJsonPath, $jsonContent);

echo "正常終了: " . count($finalArticles) . " 件のニュースを保存しました。(" . $newsJsonPath . ")" . PHP_EOL;

// ==========================================
// ヘルパー関数群
// ==========================================

/**
 * 豊橋市役所 公式新着情報 RSS (RDF 1.0) を取得
 */
function fetchToyohashiCityRss() {
    $url = 'https://www.city.toyohashi.lg.jp/services/rdf/rss10/11280.xml';
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($options);
    $xmlString = @file_get_contents($url, false, $context);
    if (!$xmlString) return [];

    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($xmlString);
    if (!$xml || !isset($xml->item)) return [];

    $results = [];
    $count = 0;
    foreach ($xml->item as $item) {
        if ($count >= 6) break; // 上位6件

        $title = trim((string)$item->title);
        $link = trim((string)$item->link);
        $desc = trim((string)$item->description);
        $dc = $item->children('http://purl.org/dc/elements/1.1/');
        $pubDate = isset($dc->date) ? (string)$dc->date : date('c');

        // カテゴリ判定
        $category = '市政・くらし';
        if (preg_match('/(イベント|講座|祭|展|教室|募集|フェス)/u', $title)) {
            $category = '観光・イベント';
        } elseif (preg_match('/(健康|ワクチン|検診|医療|福祉)/u', $title)) {
            $category = '健康・医療';
        }

        $results[] = [
            'id' => 'toyohashi-city-' . md5($link),
            'title' => $title,
            'source' => '豊橋市役所',
            'published_at' => date('c', strtotime($pubDate) ?: time()),
            'category' => $category,
            'area' => '豊橋',
            'summary' => $desc ? mb_substr(strip_tags($desc), 0, 90) : '豊橋市役所公式ホームページの新着情報・お知らせです。',
            'url' => $link,
            'image_url' => 'https://images.unsplash.com/photo-1541872703-74c5e44368f9?w=400&auto=format&fit=crop&q=80'
        ];
        $count++;
    }

    return $results;
}

/**
 * 豊橋市特化 ニュース＆イベント (Google News) を取得
 */
function fetchToyohashiNews() {
    $rssUrl = 'https://news.google.com/rss/search?q=' . urlencode('豊橋市 when:48h') . '&hl=ja&gl=JP&ceid=JP:ja';
    return parseGoogleNewsRss($rssUrl, '豊橋', 10);
}

/**
 * 愛知県全般ニュース (Google News) を取得
 */
function fetchAichiNews() {
    $rssUrl = 'https://news.google.com/rss/search?q=' . urlencode('愛知県 when:24h') . '&hl=ja&gl=JP&ceid=JP:ja';
    return parseGoogleNewsRss($rssUrl, null, 15);
}

/**
 * Google News RSS の汎用パーサー
 */
function parseGoogleNewsRss($rssUrl, $forceArea = null, $maxCount = 15) {
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            'timeout' => 12
        ]
    ];
    $context = stream_context_create($options);
    $xmlString = @file_get_contents($rssUrl, false, $context);
    if (!$xmlString) return [];

    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($xmlString);
    if (!$xml || !isset($xml->channel->item)) return [];

    $articles = [];
    $count = 0;

    foreach ($xml->channel->item as $item) {
        if ($count >= $maxCount) break;

        $rawTitle = (string)$item->title;
        $link = (string)$item->link;
        $pubDate = (string)$item->pubDate;
        $desc = (string)$item->description;

        $source = '提供元';
        $title = $rawTitle;
        if (preg_match('/^(.*?)\s*-\s*([^-]+)$/u', $rawTitle, $matches)) {
            $title = trim($matches[1]);
            $source = trim($matches[2]);
        }

        $cleanDesc = trim(strip_tags(html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($cleanDesc) < 10) {
            $cleanDesc = $title;
        }

        $area = $forceArea ?: detectArea($title . ' ' . $cleanDesc);
        $category = detectCategory($title . ' ' . $cleanDesc);
        $timestamp = strtotime($pubDate) ?: time();

        $articles[] = [
            'id' => 'news-' . md5($link),
            'title' => $title,
            'source' => $source,
            'published_at' => date('c', $timestamp),
            'category' => $category,
            'area' => $area,
            'summary' => mb_substr($cleanDesc, 0, 90) . (mb_strlen($cleanDesc) > 90 ? '...' : ''),
            'url' => $link,
            'image_url' => getFallbackImage($category, $area)
        ];

        $count++;
    }

    return $articles;
}

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

    $weather = [
        'location' => '愛知県（西部・東部）',
        'condition' => '晴れ',
        'icon' => '☀️',
        'temp_max' => 26,
        'temp_min' => 18,
        'rain_prob' => '10%'
    ];

    if (!$raw) return $weather;

    $data = json_decode($raw, true);
    if (!isset($data[0]['timeSeries'])) return $weather;

    try {
        $weathers = $data[0]['timeSeries'][0]['areas'][0]['weathers'] ?? [];
        if (!empty($weathers)) {
            $condition = preg_replace('/\s+/', ' ', trim($weathers[0]));
            $weather['condition'] = $condition;

            if (mb_strpos($condition, '雨') !== false) {
                $weather['icon'] = '🌧️';
            } elseif (mb_strpos($condition, '曇') !== false) {
                $weather['icon'] = '☁️';
            } elseif (mb_strpos($condition, '晴') !== false) {
                $weather['icon'] = mb_strpos($condition, '曇') !== false ? '🌤️' : '☀️';
            }
        }

        $pops = $data[0]['timeSeries'][1]['areas'][0]['pops'] ?? [];
        if (!empty($pops)) {
            $weather['rain_prob'] = end($pops) . '%';
        }

        if (isset($data[0]['timeSeries'][2]['areas'][0]['temps'])) {
            $temps = $data[0]['timeSeries'][2]['areas'][0]['temps'];
            if (count($temps) >= 2) {
                $weather['temp_min'] = (int)$temps[0];
                $weather['temp_max'] = (int)$temps[1];
            }
        }
    } catch (\Throwable $e) {}

    return $weather;
}

function detectArea($text) {
    if (preg_match('/(豊橋|豊橋市|吉田城|豊橋駅)/u', $text)) {
        return '豊橋';
    }
    if (preg_match('/(名古屋|栄|名駅|鶴舞|千種|熱田|中川|東区|中区|港区)/u', $text)) {
        return '名古屋';
    }
    if (preg_match('/(一宮|春日井|小牧|江南|犬山|瀬戸|長久手|日進|清須|北名古屋|津島|知多|半田|東海市|常滑|大府)/u', $text)) {
        return '尾張';
    }
    if (preg_match('/(豊田|岡崎|安城|刈谷|西尾|知立|高浜|みよし|碧南|豊川|蒲郡|新城|田原|設楽|東栄|豊根|三河)/u', $text)) {
        return '三河';
    }
    return '愛知';
}

function detectCategory($text) {
    if (preg_match('/(祭り|まつり|イベント|観光|フェス|出展|開催|オープン|展覧会|お出かけ)/u', $text)) {
        return '観光・イベント';
    }
    if (preg_match('/(事件|事故|警察|逮捕|火災|裁判|捜査)/u', $text)) {
        return '社会';
    }
    if (preg_match('/(企業|ビジネス|開発|投資|スタートアップ|経済|工場|株|売上)/u', $text)) {
        return 'ビジネス';
    }
    if (preg_match('/(市役所|市長|知事|県議|市議|選挙|予算|政策|条例|愛知県|豊橋市)/u', $text)) {
        return '市政・行政';
    }
    if (preg_match('/(フェニックス|グランパス|ドラゴンズ|野球|サッカー|バスケ|選手|試合|勝利|敗戦)/u', $text)) {
        return 'スポーツ';
    }
    if (preg_match('/(交通|鉄道|ダイヤ|新幹線|名鉄|近鉄|道路|天気|暮らし|グルメ)/u', $text)) {
        return '暮らし・交通';
    }
    return '一般ニュース';
}

function getFallbackImage($category, $area) {
    if ($area === '豊橋') {
        if ($category === '観光・イベント') {
            return 'https://images.unsplash.com/photo-1533105079780-92b9be482077?w=400&auto=format&fit=crop&q=80';
        }
    }
    $images = [
        '観光・イベント' => 'https://images.unsplash.com/photo-1513836279014-a89f7a76ae86?w=400&auto=format&fit=crop&q=80',
        'ビジネス' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=400&auto=format&fit=crop&q=80',
        '暮らし・交通' => 'https://images.unsplash.com/photo-1517649763962-0c623266ddc0?w=400&auto=format&fit=crop&q=80',
        '市政・行政' => 'https://images.unsplash.com/photo-1541872703-74c5e44368f9?w=400&auto=format&fit=crop&q=80',
        'スポーツ' => 'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=400&auto=format&fit=crop&q=80',
        '社会' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=400&auto=format&fit=crop&q=80'
    ];
    return $images[$category] ?? 'https://images.unsplash.com/photo-1585829365295-ab7cd400c167?w=400&auto=format&fit=crop&q=80';
}
