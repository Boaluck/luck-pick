<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

const SOURCE_BASE_URL = 'https://www.boatrace.jp/owpc/pc/race/index';
const USER_AGENT = 'LuckPick-RaceTitle-Updater/1.0 (+daily fetch; contact: set-your-email@example.com)';
const CONNECT_TIMEOUT_SECONDS = 10;
const REQUEST_TIMEOUT_SECONDS = 25;

$venueMap = [
    '01'=>'桐生','02'=>'戸田','03'=>'江戸川','04'=>'平和島','05'=>'多摩川','06'=>'浜名湖',
    '07'=>'蒲郡','08'=>'常滑','09'=>'津','10'=>'三国','11'=>'びわこ','12'=>'住之江',
    '13'=>'尼崎','14'=>'鳴門','15'=>'丸亀','16'=>'児島','17'=>'宮島','18'=>'徳山',
    '19'=>'下関','20'=>'若松','21'=>'芦屋','22'=>'福岡','23'=>'唐津','24'=>'大村',
];

function normalizeText(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
    return trim($value);
}

function fetchOfficialPage(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL拡張が有効ではありません。');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ja,en;q=0.7',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('公式サイトの取得に失敗しました: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('公式サイトがHTTP ' . $status . 'を返しました。');
    }
    if (stripos($contentType, 'text/html') === false) {
        throw new RuntimeException('HTML以外の応答を受信しました。');
    }

    if (!mb_check_encoding($body, 'UTF-8')) {
        $detected = mb_detect_encoding($body, ['UTF-8', 'SJIS-win', 'EUC-JP'], true);
        if ($detected !== false) {
            $body = mb_convert_encoding($body, 'UTF-8', $detected);
        }
    }
    return $body;
}

function findVenueCodeInRow(DOMXPath $xpath, DOMElement $row, array $venueMap): ?string
{
    foreach ($xpath->query('.//a[@href]', $row) ?: [] as $link) {
        $href = $link->getAttribute('href');
        if (preg_match('/(?:[?&]|&amp;)jcd=(\d{2})(?:&|$)/', $href, $m) && isset($venueMap[$m[1]])) {
            return $m[1];
        }
    }

    foreach ($xpath->query('.//img[@alt]', $row) ?: [] as $img) {
        $alt = normalizeText($img->getAttribute('alt'));
        foreach ($venueMap as $code => $name) {
            if ($alt === $name || str_contains($alt, $name)) return $code;
        }
    }

    $rowText = normalizeText($row->textContent);
    foreach ($venueMap as $code => $name) {
        if (str_contains($rowText, $name)) return $code;
    }
    return null;
}

function isLikelyRaceTitle(string $text, array $venueNames): bool
{
    if ($text === '' || mb_strlen($text) < 4 || mb_strlen($text) > 100) return false;
    if (in_array($text, $venueNames, true)) return false;
    if (preg_match('/^(?:出走表|オッズ|直前情報|コンピューター予想|マイ予想|結果|結果一覧|投票|ライブ|リプレイ|レース場データ|ピットレポート|得点率一覧|得点率早見表|更新|更新時間)$/u', $text)) return false;
    if (preg_match('/^(?:\d{1,2}R|最終Ｒ発売終了|発売中|-|初日|最終日|\d+日目)$/u', $text)) return false;
    if (preg_match('/^\d{1,2}\/\d{1,2}(?:-\d{1,2}\/\d{1,2})?$/u', $text)) return false;
    if (preg_match('/^\d{1,2}:\d{2}$/u', $text)) return false;
    if (preg_match('/^(?:モーニング|サマータイム|ナイター|ミッドナイト|ルーキーシリーズ|オールレディース|ヴィーナスシリーズ)$/u', $text)) return false;
    return true;
}

function extractRaceTitle(DOMXPath $xpath, DOMElement $row, array $venueMap): ?string
{
    $venueNames = array_values($venueMap);

    // 公式側のクラス名にtitleが含まれる場合を最優先。
    foreach ($xpath->query('.//*[contains(translate(@class,"TITLE","title"),"title")]', $row) ?: [] as $node) {
        $text = normalizeText($node->textContent);
        if (isLikelyRaceTitle($text, $venueNames)) return $text;
    }

    $candidates = [];
    foreach ($xpath->query('.//td', $row) ?: [] as $cell) {
        foreach ($xpath->query('.//*[self::p or self::div or self::span or self::a or self::strong or self::h3]', $cell) ?: [] as $node) {
            $text = normalizeText($node->textContent);
            if (isLikelyRaceTitle($text, $venueNames)) $candidates[$text] = mb_strlen($text);
        }
        $cellText = normalizeText($cell->textContent);
        if (isLikelyRaceTitle($cellText, $venueNames)) $candidates[$cellText] = mb_strlen($cellText);
    }

    if ($candidates === []) return null;
    arsort($candidates, SORT_NUMERIC);
    return (string)array_key_first($candidates);
}

function parseRaces(string $html, array $venueMap): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
        throw new RuntimeException('公式ページのHTMLを解析できませんでした。');
    }
    $xpath = new DOMXPath($dom);
    $races = [];

    foreach ($xpath->query('//tr') ?: [] as $row) {
        if (!$row instanceof DOMElement) continue;
        $code = findVenueCodeInRow($xpath, $row, $venueMap);
        if ($code === null || isset($races[$code])) continue;
        $title = extractRaceTitle($xpath, $row, $venueMap);
        if ($title === null) continue;
        $races[$code] = [
            'stadiumCode' => $code,
            'stadiumName' => $venueMap[$code],
            'raceTitle' => $title,
        ];
    }

    ksort($races, SORT_STRING);
    return array_values($races);
}

function atomicWriteJson(string $path, array $payload): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('保存フォルダを作成できません。');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('一時JSONを書き込めませんでした。');
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('JSONを置き換えられませんでした。');
    }
}

function logMessage(string $message): void
{
    $line = '[' . date(DATE_ATOM) . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/data/update-races.log', $line, FILE_APPEND | LOCK_EX);
}

try {
    $date = date('Ymd');
    $sourceUrl = SOURCE_BASE_URL . '?hd=' . $date;
    $html = fetchOfficialPage($sourceUrl);
    $races = parseRaces($html, $venueMap);

    if (count($races) === 0) {
        throw new RuntimeException('開催場とレース名を1件も抽出できませんでした。公式サイトの構造変更を確認してください。');
    }

    $payload = [
        'date' => date('Y-m-d'),
        'updatedAt' => date(DATE_ATOM),
        'source' => $sourceUrl,
        'status' => 'ok',
        'races' => $races,
    ];
    atomicWriteJson(__DIR__ . '/data/today-races.json', $payload);
    logMessage('更新成功: ' . count($races) . '場');
    echo '更新成功: ' . count($races) . '場' . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    // 失敗時は既存のJSONを上書きしない。
    logMessage('更新失敗: ' . $error->getMessage());
    fwrite(STDERR, '更新失敗: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
