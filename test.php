<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(E_ALL);

const CITY_LABEL = 'Актау';
const ATTRIB_TXT = 'Материал подготовлен на основании открытых данных Technodom (technodom.kz). Цены и наличие актуальны на момент публикации и могут изменяться.';

function httpJson(string $url,int $timeout=20): array {
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_HTTPHEADER=>[
      'User-Agent: ArtVisionTD/1.0',
      'Accept: application/json',
      'Origin: https://www.technodom.kz',
      'Referer: https://www.technodom.kz/'
    ],
    CURLOPT_ENCODING=>'gzip,deflate'
  ]);
  $body=curl_exec($ch);
  $code=curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if($code!==200||!$body) return [];
  $j=json_decode($body,true);
  return is_array($j)?($j['payload']??[]):[];
}

function normalizeItems(array $payload): array {
  $out=[];
  foreach(($payload['items']??$payload) as $p){
    if(!is_array($p)) continue;
    $imgId=$p['images'][0]??null;
    $img=$imgId?("https://static.technodom.kz/medias/{$imgId}.jpg"):null;
    $specs=[];
    foreach($p['short_description']??[] as $s){
      $k=trim($s['title']??''); $v=trim($s['values'][0]['value_ru']??'');
      if($k && $v) $specs[$k]=$v;
    }
    $out[]=[
      'sku'=>$p['sku']??'',
      'title'=>$p['title']??'',
      'brand'=>$p['brand']??($p['brand_info']['title']??''),
      'price'=>isset($p['price'])?(int)$p['price']:null,
      'old_price'=>isset($p['old_price'])?(int)$p['old_price']:null,
      'discount'=>isset($p['discount'])?(int)$p['discount']:0,
      'rating'=>$p['rating']??null,
      'reviews'=>$p['reviews']??null,
      'uri'=>$p['uri']??'',
      'image'=>$img,
      'specs'=>$specs
    ];
  }
  // убираем пустые и дубли по sku
  $seen=[]; $clean=[];
  foreach($out as $it){ $k=$it['sku']?:$it['uri']; if(isset($seen[$k])) continue; $seen[$k]=1; $clean[]=$it; }
  return $clean;
}

function tableHtml(array $items, string $caption): string {
  $rows='';
  foreach($items as $it){
    $href = 'https://www.technodom.kz/aktau/'.$it['uri'];
    $keySpec = '';
    if(!empty($it['specs'])){
      $kv = array_slice($it['specs'],0,1,true);
      foreach($kv as $k=>$v){ $keySpec=$k.': '.$v; break; }
    }
    $price = $it['price']!==null ? number_format($it['price'],0,'.',' ') . ' ₸' : '';
    $rows.='<tr>'.
      '<td>'.htmlspecialchars($it['title']).'</td>'.
      '<td>'.htmlspecialchars($it['brand']).'</td>'.
      '<td>'.htmlspecialchars($keySpec).'</td>'.
      '<td>'.$price.'</td>'.
      '<td><a href="'.htmlspecialchars($href).'" target="_blank" rel="nofollow noopener">Открыть</a></td>'.
    '</tr>';
  }
  return '<h3>'.$caption.'</h3>'.
         '<table class="table table-striped table-sm">'.
         '<thead><tr><th>Модель</th><th>Бренд</th><th>Ключевая характеристика</th><th>Цена</th><th>Ссылка</th></tr></thead>'.
         '<tbody>'.$rows.'</tbody></table>';
}

function buildArticle(string $catName,string $slug,array $cheap,array $exp): array {
  $all = array_merge($cheap,$exp);
  $prices = array_values(array_filter(array_column($all,'price'),'is_numeric'));
  sort($prices);
  $min = $prices[0] ?? null;
  $max = $prices ? end($prices) : null;
  $avg = $prices ? (int)round(array_sum($prices)/count($prices)) : null;

  $title = "Лучшие {$catName} в ".CITY_LABEL.": от доступных до премиум-моделей";
  $meta_title = "{$catName} в ".CITY_LABEL." — цены, подборки и сравнение";
  $meta_desc  = $min && $max
    ? "Подборка {$catName} в ".CITY_LABEL.": доступные и премиум-модели. Диапазон цен {$min}–{$max} ₸, средняя ~{$avg} ₸. Обновлено: ".date('Y-m-d')."."
    : "Подборка {$catName} в ".CITY_LABEL.". Обновлено: ".date('Y-m-d').".";

  $lead = $min&&$max
    ? "Собрали актуальные {$catName} в ".CITY_LABEL." — от самых доступных до премиум. Диапазон цен: ".number_format($min,0,'.',' ')."–".number_format($max,0,'.',' ')." ₸."
    : "Собрали актуальные {$catName} в ".CITY_LABEL." — от бюджетных до премиум-решений.";

  $about = "Коротко про категорию. На что смотреть при выборе: ключевые характеристики, бренд, гарантия и соотношение цены и возможностей. Ниже — две подборки: бюджет и премиум.";

  $tblCheap = tableHtml($cheap, "ТОП-10 самых доступных");
  $tblExp   = tableHtml($exp,   "ТОП-10 премиум-моделей");

  $outro = "Вывод. Если нужен бюджетный вариант — ориентируйтесь на базовые модели без лишних опций. Средний сегмент — баланс автономности и характеристик. Премиум берут за максимум функций и материалов. ".$_SERVER['HTTP_HOST'];

  $short_story = $lead;
  $full_story =
    "<p>{$lead}</p>".
    "<p>{$about}</p>".
    $tblCheap.
    $tblExp.
    "<p>{$outro}</p>".
    "<p style=\"font-size:12px;color:#777\">".ATTRIB_TXT."</p>";

  return [
    'title' => $title,
    'short_story' => $short_story,
    'full_story' => $full_story,
    'meta_title' => $meta_title,
    'meta_description' => $meta_desc,
    'hashtags' => mb_strtolower($slug).", ".CITY_LABEL
  ];
}

function pickTop(array $arr,int $n): array { return array_slice($arr,0,$n); }

// --------- ENTRY ----------
$slug = $_GET['slug'] ?? 'smartfony';
$asc  = $_GET['asc']  ?? '';
$desc = $_GET['desc'] ?? '';
$catName = $_GET['name'] ?? $slug;

if(!$asc || !$desc){
  header('Content-Type: text/plain; charset=utf-8');
  echo "Передай ?slug=&name=&asc=&desc=\n";
  exit;
}

$ascPayload  = httpJson($asc);
$descPayload = httpJson($desc);
$cheap = pickTop(normalizeItems($ascPayload), 10);
$exp   = pickTop(normalizeItems($descPayload), 10);

// Если данных мало, мягкое объединение
if(count($cheap)<5 && count($exp)>0){ $cheap = array_slice(array_reverse($exp),0,10); }
if(count($exp)<5   && count($cheap)>0){ $exp   = array_slice(array_reverse($cheap),0,10); }

$article = buildArticle($catName,$slug,$cheap,$exp);

// JSON ответ (скормить твоей функции добавления в DLE)
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'slug'=>$slug,'data'=>$article,'cheap_count'=>count($cheap),'expensive_count'=>count($exp)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
