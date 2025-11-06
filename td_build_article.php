<?php
declare(strict_types=1);

/**
 * td_build_article.php  (v3 — HTML preview + JSON)
 * Режимы:
 *   ?ping=1                    — пинг
 *   ?json=1                    — сырой JSON
 *   (по умолчанию)             — HTML-предпросмотр (Bootstrap)
 * Параметры:
 *   slug, name (опц.), asc, desc, limit (по умолчанию 10), log=1
 *   или только slug — asc/desc подтянутся из CSV (см. CSV_PATH).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---------- Константы / Настройки ----------
const CITY_LABEL   = 'Актау';
const ATTRIB_TXT   = 'Материал подготовлен на основании открытых данных Technodom (technodom.kz). Цены и наличие актуальны на момент публикации и могут изменяться. Ссылки ведут на карточки товаров Technodom.';
const CSV_PATH     = __DIR__ . '/td_cache/technodom_categories_links.csv';
const LOGS_DIR     = __DIR__ . '/td_cache/logs';
const NAME_CACHE   = __DIR__ . '/td_cache/name_cache.json';
const UA           = 'ArtVisionTD/1.0 (+vaktau.kz)';

// ---------- Утилиты ----------
function ok(array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
function fail(string $msg, array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
function ensureDir(string $path): void { if (!is_dir($path)) @mkdir($path, 0775, true); }
function logLine(string $line): void {
    static $enabled = null;
    if ($enabled === null) {
        $enabled = (int)($_GET['log'] ?? 0) === 1;
        if ($enabled) ensureDir(LOGS_DIR);
    }
    if (!$enabled) return;
    $file = LOGS_DIR . '/' . date('Y-m-d') . '.log';
    @file_put_contents($file, '[' . date('H:i:s') . "] " . $line . PHP_EOL, FILE_APPEND);
}
function httpJson(string $url, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => 'gzip,deflate',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: ' . UA,
            'Origin: https://www.technodom.kz',
            'Referer: https://www.technodom.kz/'
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        logLine("HTTP $code $url :: $err");
        return [];
    }
    $j = json_decode($body, true);
    if (!is_array($j)) return [];
    return $j['payload'] ?? $j;
}
function numberKZT(?int $n): string { return $n === null ? '' : number_format($n, 0, '.', ' ') . ' ₸'; }

// ---------- Нормализация ----------
function normalizeItems(array $payload): array {
    $list = $payload['items'] ?? $payload;
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $p) {
        if (!is_array($p)) continue;
        $imgId = $p['images'][0] ?? null;
        $img   = $imgId ? "https://static.technodom.kz/medias/{$imgId}.jpg" : null;

        $specs = [];
        if (!empty($p['short_description']) && is_array($p['short_description'])) {
            foreach ($p['short_description'] as $s) {
                $k = trim((string)($s['title'] ?? ''));
                $v = trim((string)($s['values'][0]['value_ru'] ?? ''));
                if ($k && $v) $specs[$k] = $v;
            }
        }

        $out[] = [
            'sku'        => (string)($p['sku'] ?? ''),
            'title'      => (string)($p['title'] ?? ''),
            'brand'      => (string)($p['brand'] ?? ($p['brand_info']['title'] ?? '')),
            'price'      => isset($p['price']) ? (int)$p['price'] : null,
            'old_price'  => isset($p['old_price']) ? (int)$p['old_price'] : null,
            'discount'   => isset($p['discount']) ? (int)$p['discount'] : 0,
            'rating'     => $p['rating'] ?? null,
            'reviews'    => $p['reviews'] ?? null,
            'uri'        => (string)($p['uri'] ?? ''),
            'image'      => $img,
            'specs'      => $specs,
        ];
    }
    // дедуп
    $seen = []; $clean = [];
    foreach ($out as $it) {
        $k = $it['sku'] ?: $it['uri'];
        if (!$k || isset($seen[$k])) continue;
        $seen[$k] = true;
        $clean[]  = $it;
    }
    return $clean;
}
function pickTop(array $arr, int $n): array { return array_slice($arr, 0, $n); }

// ---------- Таблица HTML ----------
function tableHtml(array $items, string $caption): string {
    $rows = '';
    foreach ($items as $it) {
        $href    = 'https://www.technodom.kz/aktau/' . ltrim($it['uri'], '/');
        $keySpec = '';
        if (!empty($it['specs'])) {
            foreach ($it['specs'] as $k => $v) { $keySpec = $k . ': ' . $v; break; }
        }
        $rows .= '<tr>'
               . '<td class="text-truncate" style="max-width:420px">' . htmlspecialchars($it['title']) . '</td>'
               . '<td>' . htmlspecialchars($it['brand']) . '</td>'
               . '<td class="text-muted">' . htmlspecialchars($keySpec) . '</td>'
               . '<td><span class="badge bg-dark-subtle text-dark">' . numberKZT($it['price']) . '</span></td>'
               . '<td><a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars($href) . '" target="_blank" rel="nofollow noopener">Открыть</a></td>'
               . '</tr>';
    }
    return '<h5 class="mt-4">' . htmlspecialchars($caption) . '</h5>'
         . '<div class="table-responsive"><table class="table table-striped table-hover align-middle">'
         . '<thead class="table-light"><tr>'
         . '<th>Модель</th><th>Бренд</th><th>Ключевая характеристика</th><th>Цена</th><th></th>'
         . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

// ---------- Построение статьи ----------
function buildArticle(string $catName, string $slug, array $cheap, array $exp): array {
    $all    = array_merge($cheap, $exp);
    $prices = array_values(array_filter(array_column($all, 'price'), 'is_numeric'));
    sort($prices);
    $min = $prices[0] ?? null;
    $max = $prices ? end($prices) : null;
    $avg = $prices ? (int)round(array_sum($prices) / count($prices)) : null;

    $title      = "Лучшие {$catName} в " . CITY_LABEL . ": от доступных до премиум-моделей";
    $meta_title = "{$catName} в " . CITY_LABEL . " — цены, подборки и сравнение";

    $min_meta = $min !== null ? $min : '';
    $max_meta = $max !== null ? $max : '';
    $avg_meta = $avg !== null ? $avg : '';

    $meta_desc  = ($min !== null && $max !== null)
        ? "Подборка {$catName} в " . CITY_LABEL . ": доступные и премиум-модели. Диапазон цен {$min_meta}–{$max_meta} ₸, средняя ~{$avg_meta} ₸. Обновлено: " . date('Y-m-d') . "."
        : "Подборка {$catName} в " . CITY_LABEL . ". Обновлено: " . date('Y-m-d') . ".";

    $lead  = ($min !== null && $max !== null)
        ? "Собрали актуальные {$catName} в " . CITY_LABEL . " — от самых доступных до премиум. Диапазон цен: " . number_format($min, 0, '.', ' ') . "–" . number_format($max, 0, '.', ' ') . " ₸."
        : "Собрали актуальные {$catName} в " . CITY_LABEL . " — от бюджетных до премиум-решений.";
    $about = "Коротко про категорию. На что смотреть при выборе: ключевые характеристики, бренд, гарантия и соотношение цены и возможностей. Ниже — две подборки: бюджет и премиум.";

    $tblCheap = tableHtml($cheap, "ТОП-10 самых доступных");
    $tblExp   = tableHtml($exp,   "ТОП-10 премиум-моделей");

    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $outro  = "Вывод. Если нужен бюджетный вариант — ориентируйтесь на базовые модели без лишних опций. Средний сегмент — баланс автономности и характеристик. Премиум берут за максимум функций и материалов. {$host}";

    $short_story = $lead;
    $full_story  = "<p>{$lead}</p>"
                 . "<p>{$about}</p>"
                 . $tblCheap
                 . $tblExp
                 . "<p>{$outro}</p>"
                 . '<p class="small text-muted">' . ATTRIB_TXT . '</p>';

    return [
        'title'            => $title,
        'short_story'      => $short_story,
        'full_story'       => $full_story,
        'meta_title'       => $meta_title,
        'meta_description' => $meta_desc,
        'hashtags'         => mb_strtolower($slug) . ", " . CITY_LABEL,
    ];
}

// ---------- CSV lookup ----------
function loadCsvMap(string $csvPath): array {
    $map = []; if (!is_file($csvPath)) return $map;
    if (($fh = fopen($csvPath, 'r')) === false) return $map;
    $header = fgetcsv($fh, 0, ';'); // заголовки
    while (($r = fgetcsv($fh, 0, ';')) !== false) {
        $name = $r[0] ?? '';
        $slug = $r[1] ?? '';
        $asc  = $r[2] ?? '';
        $desc = $r[3] ?? '';
        if ($slug && $asc && $desc) {
            $map[$slug] = ['name' => $name ?: $slug, 'asc' => $asc, 'desc' => $desc];
        }
    }
    fclose($fh);
    return $map;
}

// ---------- Получение нормального названия ----------
function humanizeSlug(string $slug): string {
    $t = str_replace(['-', '_'], ' ', $slug);
    $t = preg_replace('~\s+~u', ' ', $t);
    $t = trim($t);
    if ($t === '') return $slug;
    $first = mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8');
    $rest  = mb_substr($t, 1, null, 'UTF-8');
    return $first . $rest;
}
function loadNameCache(): array {
    if (!is_file(NAME_CACHE)) return [];
    $raw = @file_get_contents(NAME_CACHE);
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : [];
}
function saveNameCache(array $cache): void {
    ensureDir(dirname(NAME_CACHE));
    @file_put_contents(NAME_CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function resolveCategoryName(string $slug, ?string $preferredName = null): string {
    if (!empty($preferredName) && $preferredName !== $slug) return $preferredName;

    $cache = loadNameCache();
    if (!empty($cache[$slug])) return $cache[$slug];

    $url = "https://api.technodom.kz/menu/api/v1/menu/breadcrumbs/categories/" . rawurlencode($slug);
    $data = httpJson($url);
    $name = null;
    $crumbs = $data['breadcrumbs'] ?? $data;
    if (is_array($crumbs)) {
        $last = end($crumbs);
        if (is_array($last)) $name = $last['title'] ?? $last['label'] ?? $last['name'] ?? null;
        if (!$name) {
            foreach ($crumbs as $c) {
                if (is_array($c) && !empty($c['title'])) $name = $c['title'];
            }
        }
    }
    if (!$name) $name = humanizeSlug($slug);

    $cache[$slug] = $name;
    saveNameCache($cache);
    logLine("Resolved name for {$slug}: {$name}");
    return $name;
}

// ---------- ENTRY ----------
if (isset($_GET['ping'])) {
    ok(['ping' => 'pong', 'now' => date('c')]);
}

$slug    = trim((string)($_GET['slug'] ?? ''));
$name    = trim((string)($_GET['name'] ?? ''));
$asc     = trim((string)($_GET['asc'] ?? ''));
$desc    = trim((string)($_GET['desc'] ?? ''));
$limit   = max(1, (int)($_GET['limit'] ?? 10)); // сколько брать в таблицы
$jsonOut = (int)($_GET['json'] ?? 0) === 1;

if ($slug && (!$asc || !$desc)) {
    $map = loadCsvMap(CSV_PATH);
    if (isset($map[$slug])) {
        $asc  = $asc  ?: $map[$slug]['asc'];
        $desc = $desc ?: $map[$slug]['desc'];
        $name = $name ?: ($map[$slug]['name'] ?? '');
        logLine("CSV matched slug={$slug}");
    }
}

if (!$slug || !$asc || !$desc) {
    fail('Передай параметры: slug, asc, desc (name — опц.) или подготовь CSV для автоподстановки.', [
        'example' => [
            'ping' => '?ping=1',
            'full' => '?slug=vneshnie-akkumuljatory&name=Внешние%20аккумуляторы'
                    . '&asc=' . rawurlencode('https://api.technodom.kz/katalog/api/v2/products/category/vneshnie-akkumuljatory?city_id=5f5f1e346a600b98a31fddb5&limit=24&sorting=price%3Aasc')
                    . '&desc=' . rawurlencode('https://api.technodom.kz/katalog/api/v2/products/category/vneshnie-akkumuljatory?city_id=5f5f1e346a600b98a31fddb5&limit=24&sorting=price%3Adesc'),
            'slug_only' => '?slug=vneshnie-akkumuljatory&limit=10&log=1 (если есть CSV)',
        ]
    ]);
}

$catName = resolveCategoryName($slug, $name);

// Снятие данных
logLine("Fetch ASC for {$slug}");
$ascPayload = httpJson($asc);
usleep(100000);
logLine("Fetch DESC for {$slug}");
$descPayload = httpJson($desc);

// Нормализация
$cheap = pickTop(normalizeItems($ascPayload),  $limit);
$exp   = pickTop(normalizeItems($descPayload), $limit);

// Фоллбэки
if (count($cheap) < 3 && count($exp) > 0) { $cheap = array_slice(array_reverse($exp), 0, $limit); }
if (count($exp)   < 3 && count($cheap) > 0) { $exp   = array_slice(array_reverse($cheap), 0, $limit); }
if (count($cheap) === 0 && count($exp) === 0) {
    fail('Не удалось получить товары по переданным ссылкам (или пустые ответы). Проверь ASC/DESC URL.');
}

// Статья
$article = buildArticle($catName, $slug, $cheap, $exp);

// ---------- JSON режим ----------
if ($jsonOut) {
    ok([
        'slug'            => $slug,
        'cheap_count'     => count($cheap),
        'expensive_count' => count($exp),
        'data'            => $article,
        'asc_url'         => $asc,
        'desc_url'        => $desc
    ]);
}

// ---------- HTML Preview ----------
$title = htmlspecialchars($article['title']);
$lead  = $article['short_story'];
$full  = $article['full_story'];
$metaTitle = htmlspecialchars($article['meta_title']);
$metaDesc  = htmlspecialchars($article['meta_description']);
$hashtags  = htmlspecialchars($article['hashtags']);

?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Предпросмотр: <?= $title ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8fafc; }
    .container-narrow { max-width: 1100px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    pre.code { background:#0f172a; color:#e2e8f0; padding:16px; border-radius:12px; }
    .card-shadow { box-shadow: 0 10px 25px rgba(0,0,0,.05); }
  </style>
</head>
<body>
<div class="container container-narrow py-4">
  <div class="d-flex align-items-center gap-3 mb-3">
    <h2 class="m-0">🧱 Предпросмотр статьи</h2>
    <span class="badge text-bg-secondary"><?= htmlspecialchars($slug) ?></span>
  </div>

  <div class="alert alert-success py-2">
    ✅ Получено товаров: <b><?= count($cheap) ?></b> (дешёвые) и <b><?= count($exp) ?></b> (дорогие)
  </div>

  <div class="card card-shadow mb-4">
    <div class="card-body">
      <h3 class="card-title"><?= $title ?></h3>
      <p class="card-text"><?= $lead ?></p>
      <hr>
      <?= $full ?>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card card-shadow h-100">
        <div class="card-header bg-light">📌 Meta-данные</div>
        <div class="card-body">
          <div class="mb-2"><div class="text-muted small">meta_title</div><div class="mono"><?= $metaTitle ?></div></div>
          <div class="mb-2"><div class="text-muted small">meta_description</div><div class="mono"><?= $metaDesc ?></div></div>
          <div class="mb-2"><div class="text-muted small">hashtags</div><div class="mono"><?= $hashtags ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card card-shadow h-100">
        <div class="card-header bg-light">🔗 Источники API</div>
        <div class="card-body">
          <div class="mb-2"><span class="badge text-bg-dark me-2">ASC</span>
            <a href="<?= htmlspecialchars($asc) ?>" target="_blank" rel="noopener">Открыть</a></div>
          <div class="mb-2"><span class="badge text-bg-dark me-2">DESC</span>
            <a href="<?= htmlspecialchars($desc) ?>" target="_blank" rel="noopener">Открыть</a></div>
          <div class="mt-3">
            <a class="btn btn-sm btn-outline-secondary" href="?<?= http_build_query($_GET + ['json'=>1]) ?>">Показать JSON</a>
            <button id="copyHtml" class="btn btn-sm btn-primary ms-2">Скопировать HTML статьи</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card card-shadow my-4">
    <div class="card-header bg-light">📄 Чистый HTML для вставки в DLE</div>
    <div class="card-body">
      <pre class="code"><code id="articleHtml"><?= htmlspecialchars($full) ?></code></pre>
    </div>
  </div>

  <div class="d-flex gap-2">
    <!-- Заготовка на будущее: публикация в категорию 9 -->
    <a class="btn btn-success"
       href="/td_publish.php?slug=<?= urlencode($slug) ?>&name=<?= urlencode($catName ?? $slug) ?>&asc=<?= urlencode($asc) ?>&desc=<?= urlencode($desc) ?>&dle_category_id=9&publish=0"
       target="_blank" rel="noopener">➕ Отправить в DLE (черновик, cat=9)</a>
    <a class="btn btn-outline-secondary" href="javascript:history.back()">← Назад</a>
  </div>

  <footer class="mt-5 text-center text-muted small">v3 preview • <?= date('Y-m-d H:i') ?></footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.getElementById('copyHtml')?.addEventListener('click', async () => {
    const el = document.getElementById('articleHtml');
    try {
      await navigator.clipboard.writeText(el.textContent);
      alert('HTML статьи скопирован в буфер обмена!');
    } catch (e) {
      // Fallback
      const range = document.createRange();
      range.selectNode(el);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      document.execCommand('copy');
      sel.removeAllRanges();
      alert('HTML статьи скопирован (fallback).');
    }
  });
</script>
</body>
</html>
