<?php
require_once __DIR__ . '/td_config.php';

/**
 * Переписывает статью через GPT (title, short, full, meta).
 * Возвращает массив с теми же ключами, что и вход, если всё ок.
 * При ошибке вернёт исходные поля (фоллбэк), но оставит пометку в meta_description.
 */
function td_gpt_rewrite(array $article, string $categoryName, string $slug): array {
    $title       = $article['title']            ?? '';
    $short_story = $article['short_story']      ?? '';
    $full_story  = $article['full_story']       ?? '';
    $meta_title  = $article['meta_title']       ?? '';
    $meta_desc   = $article['meta_description'] ?? '';
    $hashtags    = $article['hashtags']         ?? ($slug . ', Актау');

    // Промпт: коротко и по делу; просим корректный HTML без лишнего мусора
    $system = "Ты опытный русскоязычный редактор и SEO-копирайтер. Перепиши текст лаконично и полезно, сохраняя факты (цены/бренды/характеристики). Выдай чистый HTML (абзацы <p>, подзаголовки <h3>, таблицы как есть), без инлайн-стилей, без лишней воды. Мета-заголовок до ~70 символов, мета-описание до ~160. Тон — информативный, без агрессивных продаж.";
    $user = [
        "category"     => $categoryName,
        "slug"         => $slug,
        "title"        => $title,
        "short_story"  => $short_story,
        "full_story"   => $full_story,
        "meta_title"   => $meta_title,
        "meta_desc"    => $meta_desc,
        "hashtags"     => $hashtags
    ];

    $payload = [
        "model" => OPENAI_MODEL,
        "temperature" => 0.4,
        "messages" => [
            ["role" => "system", "content" => $system],
            ["role" => "user",   "content" => "Вот данные статьи в JSON. Перепиши: верни JSON с полями title, short_story, full_story, meta_title, meta_description, hashtags.\n\n" . json_encode($user, JSON_UNESCAPED_UNICODE)]
        ]
    ];

    $resp = openai_chat($payload);
    if (!$resp || empty($resp['choices'][0]['message']['content'])) {
        // Фоллбэк: возвращаем исходник с пометкой
        $article['meta_description'] = ($article['meta_description'] ?? '') . ' (auto: fallback)';
        return $article;
    }

    // Парсим JSON из ответа
    $txt = trim($resp['choices'][0]['message']['content']);
    $clean = try_json($txt);
    if (!$clean) {
        // Иногда модель отдаёт текст с ```json
        $txt = trim(preg_replace('~^```json|```$~m', '', $txt));
        $clean = try_json($txt);
    }
    if (!$clean) {
        $article['meta_description'] = ($article['meta_description'] ?? '') . ' (auto: parse-fallback)';
        return $article;
    }

    // Сливаем, оставляя обязательные поля
    $out = [
        'title'            => $clean['title']            ?? $title,
        'short_story'      => $clean['short_story']      ?? $short_story,
        'full_story'       => $clean['full_story']       ?? $full_story,
        'meta_title'       => $clean['meta_title']       ?? $meta_title,
        'meta_description' => $clean['meta_description'] ?? $meta_desc,
        'hashtags'         => $clean['hashtags']         ?? $hashtags,
    ];

    // Страховка: clean HTML от <script> и мусора
    $out['full_story'] = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $out['full_story']);
    return $out;
}

/** Вызов OpenAI с ретраями */
function openai_chat(array $payload): ?array {
    global $OPENAI_API_KEY;

    $attempts = 3;
    $sleep    = 1;
    for ($i=0; $i<$attempts; $i++) {
        $ch = curl_init(OPENAI_BASEURL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => OPENAI_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $OPENAI_API_KEY,
                'Content-Type: application/json'
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code === 200 && $body) {
            $j = json_decode($body, true);
            if (is_array($j)) return $j;
        }
        // 429/5xx — подождём и попробуем ещё
        if (in_array($code, [429,500,502,503,504], true)) {
            usleep($sleep * 1000000);
            $sleep *= 2;
            continue;
        }
        // Прочие ошибки — выходим
        return null;
    }
    return null;
}

function try_json(string $s): ?array {
    $j = json_decode($s, true);
    return is_array($j) ? $j : null;
}
