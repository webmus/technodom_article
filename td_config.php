<?php
// === Конфиг Technodom → GPT → DLE ===

// 1) Хранение ключа OpenAI
// ВАРИАНТ A: через переменную окружения (рекомендуется)
$OPENAI_API_KEY = getenv('OPENAI_API_KEY') ?: '';

// ВАРИАНТ B: через защищённый файл (вне webroot)
//   $OPENAI_API_KEY = trim(@file_get_contents('/var/secure/openai.key') ?: '');

// Fallback: если вдруг не нашли — аварийно остановимся
if (!$OPENAI_API_KEY) {
    http_response_code(500);
    die('OpenAI API key not configured. Set OPENAI_API_KEY env or file.');
}

// 2) Модель и параметры по умолчанию
const OPENAI_MODEL   = 'gpt-4o-mini';  // быстро и Й; можно 'gpt-4o'
const OPENAI_BASEURL = 'https://api.openai.com/v1/chat/completions';
const OPENAI_TIMEOUT = 25; // сек.

// 3) DLE DB (заполните под себя или подключите общий конфиг)
const DB_HOST = 'localhost';       // например, 127.0.0.1
const DB_USER = 'dle_user';       // пользователь БД
const DB_PASS = 'dle_password';   // пароль БД
const DB_NAME = 'dle_database';   // имя БД
const DB_CHARSET = 'utf8mb4';

// 4) Параметры публикации
const DEFAULT_DLE_CAT_ID = 9;      // категория по умолчанию
const DEFAULT_ALLOW_COMM = 0;      // комментарии: 0=запрещены
const DEFAULT_ALLOW_MAIN = 1;      // показывать на главной
const DEFAULT_PUBLISH    = 1;      // по умолчанию публиковать
const DEFAULT_AUTHOR     = 'admin';// логин автора (должен существовать в DLE)

// 5) Служебные пути
const CACHE_DIR = __DIR__ . '/td_cache';
@mkdir(CACHE_DIR, 0775, true);
