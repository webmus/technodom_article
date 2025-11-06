<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(E_ALL);

define('SITE_HOST', $_SERVER['HTTP_HOST'] ?? 'localhost');
$csv = __DIR__ . '/td_cache/technodom_categories_links.csv';

$limit   = max(1, (int)($_GET['limit'] ?? 5));     // сколько обработать за прогон
$shuffle = (int)($_GET['shuffle'] ?? 1) === 1;     // перемешать?
$catId   = (int)($_GET['dle_category_id'] ?? 0);   // DLE категория
$publish = (int)($_GET['publish'] ?? 0);           // 0 — черновики, 1 — публикация

if (!is_file($csv)) { header('Content-Type: text/plain; charset=utf-8'); echo "Нет CSV: {$csv}\n"; exit; }

$rows = [];
if (($fh = fopen($csv, 'r')) !== false) {
  $header = fgetcsv($fh, 0, ';'); // заголовки
  while (($r = fgetcsv($fh, 0, ';')) !== false) {
    // Ожидаем колонки: Название рубрики;Slug;ASC;DESC;Sitemap URL
    $rows[] = [
      'name' => $r[0] ?? '',
      'slug' => $r[1] ?? '',
      'asc'  => $r[2] ?? '',
      'desc' => $r[3] ?? ''
    ];
  }
  fclose($fh);
}
$rows = array_values(array_filter($rows, fn($r)=> $r['slug'] && $r['asc'] && $r['desc']));

if ($shuffle) shuffle($rows);
$batch = array_slice($rows, 0, $limit);

function callJson(string $url,int $timeout=40): array {
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_HTTPHEADER=>['Accept: application/json'],
    CURLOPT_ENCODING=>'gzip,deflate'
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($code!==200 || !$body) return [];
  $j = json_decode($body, true);
  return is_array($j)?$j:[];
}

header('Content-Type: text/plain; charset=utf-8');
echo "TD MASS RUN | limit={$limit} | shuffle=".($shuffle?'1':'0')." | cat={$catId} | publish={$publish}\n\n";

$ok=0; $fail=0;
foreach ($batch as $r) {
  $pubUrl = "https://".SITE_HOST."/td_publish.php"
          ."?slug=".rawurlencode($r['slug'])
          ."&name=".rawurlencode($r['name'])
          ."&asc=".rawurlencode($r['asc'])
          ."&desc=".rawurlencode($r['desc'])
          ."&dle_category_id=".$catId
          ."&publish=".$publish;

  $res = callJson($pubUrl, 60);
  if (!empty($res['ok'])) {
    $ok++;
    echo "✔ {$r['slug']} → post_id=".$res['post_id']."\n";
  } else {
    $fail++;
    echo "✖ {$r['slug']} → ошибка\n";
  }
  usleep(120000); // щадим API
}

echo "\nГотово. Успешно: {$ok}, Ошибок: {$fail}\n";
