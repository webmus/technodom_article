<?php
declare(strict_types=1);

// ------- лёгкая диагностика, чтобы понять: файл вообще выполняется? -------
if (isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'pong'=>date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/td_config.php';
require_once __DIR__ . '/td_gpt.php';

// ====== helpers ======
function debug_enabled(): bool { return (int)($_GET['debug'] ?? 0) === 1; }
function say($msg){ if(debug_enabled()){ echo '<div style="font:12px/1.4 monospace;color:#111;background:#f6f8fa;border-left:4px solid #2f81f7;padding:8px 10px;margin:6px 0;">'.$msg.'</div>'; } }
function json_out($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
function http_get_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => 'gzip,deflate'
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code !== 200 || !$body) { return null; }
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}
function base_origin(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
function same_dir_url(string $file): string {
    // URL к файлу в той же директории: base_origin + dirname(request_uri) + file
    $dir = rtrim(str_replace('\\','/', dirname($_SERVER['REQUEST_URI'] ?? '/')), '/');
    if ($dir === '') $dir = '/';
    return rtrim(base_origin().$dir, '/') . '/' . ltrim($file, '/');
}
function extract_cat_name_from_title(string $title, string $slug): string {
    if (preg_match('~Лучшие\s+(.+?)\s+в\s+~u', $title, $m)) return trim($m[1]);
    $t = str_replace(['-', '_'], ' ', $slug);
    return mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
}
function translit_to_url(string $s): string {
    $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',' '=>'-','—'=>'-','–'=>'-','«'=>'','»'=>'','"'=>'','\''=>'','/'=>'-','\\'=>'-','&'=>'-','?'=>'',','=>'','.'=>'','('=>' ',')'=>' '];
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, $map);
    $s = preg_replace('~[^a-z0-9\-]+~', '-', $s);
    $s = preg_replace('~-+~', '-', $s);
    return trim($s, '-');
}

// ====== параметры ======
$slug    = trim((string)($_GET['slug'] ?? ''));
$limit   = max(1, (int)($_GET['limit'] ?? 10));
$catId   = (int)($_GET['dle_category_id'] ?? DEFAULT_DLE_CAT_ID);
$publish = (int)($_GET['publish'] ?? DEFAULT_PUBLISH); // 1=публиковать, 0=черновик
$useGpt  = (int)($_GET['use_gpt'] ?? 1);

if (debug_enabled()) { ini_set('display_errors','1'); error_reporting(E_ALL); }
if (!$slug) {
    if (debug_enabled()) { die('Param "slug" is required'); }
    json_out(['ok'=>false,'error'=>'Param "slug" is required']);
}

// ====== 1) тянем сырую статью из билдера ======
$builder = same_dir_url('td_build_article.php') . '?slug=' . urlencode($slug) . '&limit=' . $limit . '&json=1';
say('Builder URL: <b>'.$builder.'</b>');

$raw = http_get_json($builder);
if (!$raw || empty($raw['ok']) || empty($raw['data'])) {
    if (debug_enabled()) {
        die('Build article failed (нет JSON от td_build_article.php). Проверь: файл существует, открывается напрямую и отдаёт json=1.');
    }
    json_out(['ok'=>false,'error'=>'Build article failed']);
}
$article = $raw['data'];
say('Builder OK: получены поля title/short/full/meta');

// ====== 2) GPT рерайт (по желанию) ======
$catName = extract_cat_name_from_title($article['title'] ?? '', $slug);
if ($useGpt) {
    say('GPT: start rewrite…');
    $article = td_gpt_rewrite($article, $catName, $slug);
    say('GPT: done');
} else {
    say('GPT: пропущен (use_gpt=0)');
}

// ====== 3) публикация в DLE ======
try {
    $postId = dle_publish($article, $catId, (bool)$publish);
    say('DLE: вставка выполнена, post_id='.$postId.', publish='.(int)(bool)$publish);
} catch (Throwable $e) {
    if (debug_enabled()) { die('DLE publish error: '.$e->getMessage()); }
    json_out(['ok'=>false,'error'=>'DLE publish error']);
}

// ====== итог ======
if (debug_enabled()) {
    echo '<hr><pre style="font:12px/1.5 monospace;background:#fafafa;border:1px solid #eee;padding:8px;">'.
         htmlspecialchars(json_encode(['ok'=>true,'post_id'=>$postId,'slug'=>$slug,'published'=>(bool)$publish,'category'=>$catId], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)).
         '</pre>';
    exit;
}
json_out(['ok'=>true,'post_id'=>$postId,'slug'=>$slug,'published'=>(bool)$publish,'category'=>$catId]);

// ====== DLE вставка ======
function dle_publish(array $article, int $catId, bool $publish): int {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        throw new RuntimeException('DB connect: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset(DB_CHARSET);

    $title = (string)($article['title'] ?? '');
    $short = (string)($article['short_story'] ?? '');
    $full  = (string)($article['full_story'] ?? '');
    $mt    = (string)($article['meta_title'] ?? '');
    $mdesc = (string)($article['meta_description'] ?? '');
    $tags  = (string)($article['hashtags'] ?? '');

    if ($title === '' || $full === '') {
        throw new RuntimeException('Empty title/full_story — ничего публиковать');
    }

    // защита от дублей
    $escTitle = $mysqli->real_escape_string($title);
    $dupSql   = "SELECT id FROM dle_post WHERE title='{$escTitle}' AND category='{$catId}' LIMIT 1";
    $dupRes   = $mysqli->query($dupSql);
    if ($dupRes && $dupRes->num_rows > 0) {
        $row = $dupRes->fetch_assoc();
        return (int)$row['id']; // уже есть
    }

    $date       = date('Y-m-d H:i:s');
    $autor      = DEFAULT_AUTHOR;
    $approve    = $publish ? 1 : 0;
    $allow_comm = DEFAULT_ALLOW_COMM;
    $allow_main = DEFAULT_ALLOW_MAIN;
    $alt_name   = translit_to_url($title);

    $stmt = $mysqli->prepare("
        INSERT INTO dle_post
          (autor, date, title, short_story, full_story, category, tags, alt_name, approve, allow_comm, allow_main, descr, metatitle)
        VALUES
          (?,     ?,    ?,     ?,           ?,          ?,        ?,    ?,        ?,       ?,          ?,         ?,     ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('DB prepare: ' . $mysqli->error);
    }
    $stmt->bind_param(
        'sssssisiiiiss',
        $autor, $date, $title, $short, $full, $catId, $tags, $alt_name, $approve, $allow_comm, $allow_main, $mdesc, $mt
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('DB execute: ' . $stmt->error);
    }
    $postId = (int)$stmt->insert_id;
    $stmt->close();
    $mysqli->close();
    return $postId;
}
