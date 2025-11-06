<?php
// td_dashboard.php — единый дашборд управления конвейером
declare(strict_types=1);

// ===== mini API: отдаём CSV как JSON для фронта =====
if (isset($_GET['action']) && $_GET['action'] === 'csv') {
    $csvPath = __DIR__ . '/td_cache/technodom_categories_links.csv';
    header('Content-Type: application/json; charset=utf-8');
    if (!is_file($csvPath)) {
        echo json_encode(['ok'=>false,'error'=>'CSV not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $out = [];
    if (($fh = fopen($csvPath, 'r')) !== false) {
        $header = fgetcsv($fh, 0, ';'); // "Название рубрики";Slug;ASC;DESC;"Sitemap URL"
        while (($r = fgetcsv($fh, 0, ';')) !== false) {
            $out[] = [
                'name'  => $r[0] ?? '',
                'slug'  => $r[1] ?? '',
                'asc'   => $r[2] ?? '',
                'desc'  => $r[3] ?? '',
                'smap'  => $r[4] ?? '',
            ];
        }
        fclose($fh);
    }
    echo json_encode(['ok'=>true,'items'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
}

// для построения URL соседних скриптов
function same_dir_url(string $file): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\','/', dirname($_SERVER['REQUEST_URI'] ?? '/')), '/');
    if ($dir === '') $dir = '/';
    return rtrim($scheme.'://'.$host.$dir, '/') . '/' . ltrim($file, '/');
}

$buildUrl   = same_dir_url('td_build_article.php');
$publishUrl = same_dir_url('td_publish.php');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>TD Dashboard — сбор → GPT → публикация</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8fafc; }
    .container-narrow { max-width: 1200px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    .card-shadow { box-shadow: 0 10px 25px rgba(0,0,0,.05); }
    .h-40 { height: 40px; }
    #result pre { background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; }
    .tableFixHead { overflow-y:auto; max-height: 320px; }
    .tableFixHead thead th { position: sticky; top: 0; z-index: 1; background:#fff; }
  </style>
</head>
<body>
<div class="container container-narrow py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="m-0">🧱 TD Dashboard — Technodom → GPT → DLE</h2>
    <span class="badge text-bg-secondary">beta</span>
  </div>

  <div class="row g-3">
    <!-- Панель настроек -->
    <div class="col-lg-4">
      <div class="card card-shadow">
        <div class="card-header bg-light">⚙️ Настройки</div>
        <div class="card-body">
          <div class="mb-2">
            <label class="form-label">Категория (из CSV)</label>
            <div class="d-flex gap-2">
              <select id="selCategory" class="form-select"></select>
              <button id="refreshCsv" class="btn btn-outline-secondary">⟳</button>
            </div>
            <div class="form-text">Источник: td_cache/technodom_categories_links.csv</div>
          </div>
          <div class="mb-2">
            <label class="form-label">Slug</label>
            <input id="inSlug" type="text" class="form-control" placeholder="например: vneshnie-akkumuljatory">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Лимит (в каждую таблицу)</label>
              <input id="inLimit" type="number" class="form-control" value="10" min="1" max="50">
            </div>
            <div class="col-6">
              <label class="form-label">Категория DLE (ID)</label>
              <input id="inCatId" type="number" class="form-control" value="9" min="1">
            </div>
          </div>
          <div class="mt-3 d-flex gap-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="cbPublish" checked>
              <label class="form-check-label" for="cbPublish">Публиковать</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="cbGPT" checked>
              <label class="form-check-label" for="cbGPT">GPT-рерайт</label>
            </div>
          </div>
          <div class="mt-3">
            <button id="btnPreview" class="btn btn-primary w-100">🔎 Предпросмотр статьи</button>
          </div>
          <div class="mt-2 d-grid gap-2">
            <button id="btnPublish" class="btn btn-success">🚀 Опубликовать (GPT)</button>
            <button id="btnDraft" class="btn btn-outline-success">📝 Черновик (GPT)</button>
            <button id="btnNoGpt" class="btn btn-outline-secondary">⚡ Опубликовать без GPT</button>
          </div>
          <hr>
          <div class="small text-muted">
            <div>Builder: <span class="mono"><?= htmlspecialchars($buildUrl) ?></span></div>
            <div>Publish: <span class="mono"><?= htmlspecialchars($publishUrl) ?></span></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Предпросмотр -->
    <div class="col-lg-8">
      <div class="card card-shadow mb-3">
        <div class="card-header bg-light">📄 Предпросмотр</div>
        <div class="card-body" id="preview">
          <div class="text-muted">Нажми «Предпросмотр статьи» — здесь появится заголовок, лид, таблицы и мета.</div>
        </div>
      </div>

      <div class="card card-shadow">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
          <span>🧾 Журнал / Ответы API</span>
          <div class="d-flex gap-2">
            <button id="btnPing" class="btn btn-sm btn-outline-secondary">Ping endpoints</button>
            <button id="btnClearLog" class="btn btn-sm btn-outline-secondary">Очистить</button>
          </div>
        </div>
        <div class="card-body" id="result">
          <pre class="mb-0" id="log" style="white-space:pre-wrap;">Готов к работе…</pre>
        </div>
      </div>
    </div>
  </div>

  <!-- Пакетный режим -->
  <div class="card card-shadow mt-4">
    <div class="card-header bg-light">📦 Пакетная публикация</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="tableFixHead border rounded">
            <table class="table table-sm mb-0" id="tblBatch">
              <thead>
                <tr><th style="width:36px;"><input type="checkbox" id="chAll"></th><th>Название</th><th>Slug</th></tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="form-text">Отметь нужные категории и нажми «Старт пакетного прогона»</div>
        </div>
        <div class="col-lg-4">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Лимит</label>
              <input id="inBLimit" type="number" class="form-control" value="10" min="1" max="50">
            </div>
            <div class="col-6">
              <label class="form-label">DLE cat</label>
              <input id="inBCatId" type="number" class="form-control" value="9" min="1">
            </div>
          </div>
          <div class="mt-2 form-check">
            <input class="form-check-input" type="checkbox" id="cbBPublish" checked>
            <label class="form-check-label" for="cbBPublish">Публиковать</label>
          </div>
          <div class="mt-2 form-check">
            <input class="form-check-input" type="checkbox" id="cbBGPT" checked>
            <label class="form-check-label" for="cbBGPT">GPT-рерайт</label>
          </div>
          <div class="mt-3 d-grid gap-2">
            <button id="btnBatch" class="btn btn-dark">▶️ Старт пакетного прогона</button>
            <button id="btnStop" class="btn btn-outline-danger">⏹ Остановить</button>
          </div>
          <div class="mt-3">
            <div class="progress">
              <div id="prog" class="progress-bar" role="progressbar" style="width:0%">0%</div>
            </div>
            <div class="small mt-1" id="batchStat">—</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <footer class="mt-4 text-center text-muted small">TD Dashboard • <?= date('Y-m-d H:i') ?></footer>
</div>

<script>
const buildUrl   = <?= json_encode($buildUrl) ?>;
const publishUrl = <?= json_encode($publishUrl) ?>;

const selCategory = document.getElementById('selCategory');
const inSlug      = document.getElementById('inSlug');
const inLimit     = document.getElementById('inLimit');
const inCatId     = document.getElementById('inCatId');
const cbPublish   = document.getElementById('cbPublish');
const cbGPT       = document.getElementById('cbGPT');

const previewBox  = document.getElementById('preview');
const logBox      = document.getElementById('log');

function log(msg) {
  logBox.textContent += '\\n' + msg;
  logBox.scrollTop = logBox.scrollHeight;
}
function clearLog(){ logBox.textContent = 'Готов к работе…'; }
document.getElementById('btnClearLog').onclick = clearLog;

async function loadCsv() {
  const r = await fetch('?action=csv');
  const j = await r.json();
  selCategory.innerHTML = '';
  document.querySelector('#tblBatch tbody').innerHTML = '';
  if (!j.ok) { log('CSV error: ' + (j.error || 'unknown')); return; }
  j.items.forEach((it, idx) => {
    const o = document.createElement('option');
    o.value = it.slug;
    o.textContent = `${it.name || it.slug} — ${it.slug}`;
    o.dataset.asc = it.asc; o.dataset.desc = it.desc; o.dataset.name = it.name;
    selCategory.appendChild(o);

    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input type="checkbox" class="chRow" data-slug="${it.slug}"></td>
                    <td>${escapeHtml(it.name || '')}</td>
                    <td class="mono">${escapeHtml(it.slug || '')}</td>`;
    document.querySelector('#tblBatch tbody').appendChild(tr);
  });
  if (j.items.length) {
    selCategory.selectedIndex = 0;
    inSlug.value = j.items[0].slug || '';
  }
  log('CSV загружен: ' + j.items.length + ' строк');
}
document.getElementById('refreshCsv').onclick = loadCsv;

selCategory.addEventListener('change', () => {
  const o = selCategory.options[selCategory.selectedIndex];
  inSlug.value = o.value || '';
});

function escapeHtml(s){return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));}

async function previewArticle() {
  const slug  = inSlug.value.trim();
  const limit = parseInt(inLimit.value||'10',10);
  if (!slug) return alert('Укажи slug');
  clearLog();
  log('🔎 Предпросмотр: ' + slug);

  const url = buildUrl + '?slug=' + encodeURIComponent(slug) + '&limit=' + limit + '&json=1';
  const r = await fetch(url);
  if (!r.ok) { log('Builder HTTP ' + r.status); return; }
  const j = await r.json();
  if (!j.ok) { log('Builder error'); return; }

  const a = j.data;
  previewBox.innerHTML = `
    <h3 class="mb-2">${escapeHtml(a.title)}</h3>
    <p>${a.short_story}</p>
    <hr>
    ${a.full_story}
    <hr>
    <div class="row g-2 small">
      <div class="col-md-6"><b>meta_title:</b> <span class="mono">${escapeHtml(a.meta_title)}</span></div>
      <div class="col-md-6"><b>meta_description:</b> <span class="mono">${escapeHtml(a.meta_description)}</span></div>
      <div class="col-12"><b>hashtags:</b> <span class="mono">${escapeHtml(a.hashtags)}</span></div>
    </div>`;
  log('OK: cheap='+(j.cheap_count||0)+' / expensive='+(j.expensive_count||0));
}

async function doPublish({publish=true, use_gpt=true}) {
  const slug  = inSlug.value.trim();
  const limit = parseInt(inLimit.value||'10',10);
  const catId = parseInt(inCatId.value||'9',10);
  if (!slug) return alert('Укажи slug');

  const url = `${publishUrl}?slug=${encodeURIComponent(slug)}&limit=${limit}&dle_category_id=${catId}&publish=${publish?1:0}&use_gpt=${use_gpt?1:0}`;
  log('▶️ Публикация: ' + url.replace(location.origin,''));
  const r = await fetch(url);
  if (!r.ok) { log('Publish HTTP '+r.status); return; }
  const j = await r.json();
  if (!j.ok) { log('Publish error'); return; }
  log('✅ post_id=' + j.post_id + ', published=' + j.published + ', cat=' + j.category);
}

document.getElementById('btnPreview').onclick = previewArticle;
document.getElementById('btnPublish').onclick = () => doPublish({publish:true, use_gpt:true});
document.getElementById('btnDraft').onclick   = () => doPublish({publish:false, use_gpt:true});
document.getElementById('btnNoGpt').onclick   = () => doPublish({publish:true, use_gpt:false});

document.getElementById('btnPing').onclick = async () => {
  const a = await fetch(publishUrl+'?ping=1').then(r=>r.json()).catch(()=>null);
  const b = await fetch(buildUrl  +'?ping=1').then(r=>r.json()).catch(()=>null);
  log('Ping publish: ' + (a && a.ok ? 'ok' : 'fail'));
  log('Ping build:   ' + (b && b.ok ? 'ok' : 'fail'));
};

// Пакетный режим
let stopBatch = false;
document.getElementById('btnStop').onclick = ()=>{ stopBatch = true; };
document.getElementById('chAll').onclick = (e)=>{
  document.querySelectorAll('.chRow').forEach(ch=>ch.checked = e.target.checked);
};
document.getElementById('btnBatch').onclick = async ()=>{
  const limit  = parseInt(document.getElementById('inBLimit').value||'10',10);
  const catId  = parseInt(document.getElementById('inBCatId').value||'9',10);
  const pub    = document.getElementById('cbBPublish').checked;
  const useGpt = document.getElementById('cbBGPT').checked;

  const rows = Array.from(document.querySelectorAll('.chRow')).filter(x=>x.checked);
  if (!rows.length) return alert('Отметь хотя бы одну категорию');

  stopBatch = false;
  let done=0;
  for (let i=0;i<rows.length;i++){
    if (stopBatch){ log('⏹ Остановлено'); break; }
    const slug = rows[i].dataset.slug;
    const url  = `${publishUrl}?slug=${encodeURIComponent(slug)}&limit=${limit}&dle_category_id=${catId}&publish=${pub?1:0}&use_gpt=${useGpt?1:0}`;
    log(`▶️ [${i+1}/${rows.length}] ${slug}`);
    try{
      const r = await fetch(url);
      const j = await r.json();
      if (j && j.ok){
        log(`✅ post_id=${j.post_id}, published=${j.published}`);
      } else {
        log(`❌ error on ${slug}`);
      }
    }catch(e){ log(`❌ HTTP error on ${slug}`); }
    done++;
    const pct = Math.round(done*100/rows.length);
    document.getElementById('prog').style.width = pct+'%';
    document.getElementById('prog').textContent = pct+'%';
    document.getElementById('batchStat').textContent = `Готово ${done} из ${rows.length}`;
    await new Promise(r=>setTimeout(r, 300)); // маленькая пауза
  }
};

// инициализация
loadCsv();
</script>
</body>
</html>