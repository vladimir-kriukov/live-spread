<?php
/**
 * quotes.php — relay котировок MOEX (bid/ask/last) из T-Invest REST для «Живого спреда».
 *
 * Зачем: цепочка сертификата invest-public-api.tinkoff.ru подписана УЦ Минцифры, и браузер
 * без этого корня режет запрос (ERR_CERT_AUTHORITY_INVALID → «Failed to fetch»). Здесь корень
 * задан явно (CURLOPT_CAINFO), поэтому пользователю ставить ничего не нужно и токен вводить
 * не нужно: токен общий, лежит ВНЕ public_html (<site>/tinvest.token, только чтение).
 * Кэш 1 с на файле + flock: N пользователей → не больше 5 запросов/с к T-Invest
 * (лимит GetOrderBook 600/мин). Ошибка T-Invest → отдаём последний кэш с stale:true.
 *
 *   GET /api/quotes.php?t=USDRUBF,CNYRUBF,SiZ6
 *   → {"ok":true,"at":<ms>,"moex":{"USDRUBF":{"bid":86.65,"ask":86.66,"last":86.66},…}}
 *
 * Хостинг Beget (виртуальный, PHP 8.2). Деплой: build_live_spread.py --deploy (rsync api/).
 */
declare(strict_types=1);
ini_set('serialize_precision', '-1');       // иначе хостинг печатает 12.9169999999999998152… вместо 12.917
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');   // публичные котировки, только чтение

$SITE   = dirname(__DIR__, 2);                          // ~/live-spread.kryuko.beget.tech
$TOKEN  = trim((string)@file_get_contents("$SITE/tinvest.token"));
$CA     = __DIR__ . '/ca-minzifry-root.crt';                // корень «Russian Trusted Root CA» (публичный, вынут из цепочки T-Invest)
$CACHE  = "$SITE/.cache";
$UIDS_F = "$CACHE/tinvest-uids.json";
$TTL    = 1.0;
$API    = 'https://invest-public-api.tinkoff.ru/rest/tinkoff.public.invest.api.contract.v1.';

$t = array_values(array_unique(array_filter(explode(',', (string)($_GET['t'] ?? '')),
        fn($s) => preg_match('/^[A-Za-z0-9]{2,12}$/', $s) === 1)));
if (!$t || count($t) > 10) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 't=TICKERS (1..10)'])); }
if ($TOKEN === '')         { http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'нет tinvest.token на сервере'])); }
sort($t);
@mkdir($CACHE, 0700, true);
$cacheF = "$CACHE/quotes-" . md5(implode(',', $t)) . '.json';

$fresh = function () use ($cacheF, $TTL): ?string {
    clearstatcache(true, $cacheF);
    $s = @file_get_contents($cacheF);
    return ($s !== false && (microtime(true) - filemtime($cacheF)) < $TTL) ? $s : null;
};
if (($hit = $fresh()) !== null) exit($hit);                       // 1) свежий кэш — без похода в T-Invest

$lock = fopen("$cacheF.lock", 'c'); flock($lock, LOCK_EX);        // 2) один поход на всех ждущих
if (($hit = $fresh()) !== null) { flock($lock, LOCK_UN); exit($hit); }

function ti(string $method, array $body): ?array {
    static $ch = null;                                             // один handle → TLS-соединение переиспользуется
    if ($ch === null) $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $GLOBALS['API'] . $method, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CAINFO => $GLOBALS['CA'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $GLOBALS['TOKEN']],
    ]);
    $r = curl_exec($ch);
    if ($r === false || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) return null;
    return json_decode((string)$r, true);
}
$q = fn($x) => $x ? ((float)($x['units'] ?? 0) + (float)($x['nano'] ?? 0) / 1e9) : 0.0;

$uids  = json_decode((string)@file_get_contents($UIDS_F), true) ?: [];
$dirty = false; $fails = 0;
$out   = ['ok' => true, 'at' => (int)round(microtime(true) * 1000), 'moex' => []];
foreach ($t as $tk) {
    if (empty($uids[$tk])) {                                       // uid по тикеру — один раз, кэш на диске
        $j = ti('InstrumentsService/FutureBy', ['idType' => 'INSTRUMENT_ID_TYPE_TICKER', 'classCode' => 'SPBFUT', 'id' => $tk]);
        if (empty($j['instrument']['uid'])) { $fails++; continue; }
        $uids[$tk] = $j['instrument']['uid']; $dirty = true;
    }
    $ob = ti('MarketDataService/GetOrderBook', ['instrumentId' => $uids[$tk], 'depth' => 1]);
    if ($ob === null) { $fails++; continue; }
    $bid = $q($ob['bids'][0]['price'] ?? null); $ask = $q($ob['asks'][0]['price'] ?? null); $last = $q($ob['lastPrice'] ?? null);
    if ($bid > 0 && $ask > 0)  $out['moex'][$tk] = ['bid' => $bid, 'ask' => $ask, 'last' => $last ?: ($bid + $ask) / 2];
    elseif ($last > 0)         $out['moex'][$tk] = ['bid' => 0, 'ask' => 0, 'last' => $last];   // сессия закрыта — только last
}
if ($dirty) file_put_contents($UIDS_F, json_encode($uids));

if ($fails === count($t)) {                                        // T-Invest лёг целиком → последний кэш как stale
    $stale = @file_get_contents($cacheF);
    flock($lock, LOCK_UN);
    if ($stale !== false) { $s = json_decode($stale, true); $s['stale'] = true; exit(json_encode($s)); }
    http_response_code(502); exit(json_encode(['ok' => false, 'error' => 'T-Invest недоступен']));
}
$json = json_encode($out);
file_put_contents("$cacheF.tmp", $json); rename("$cacheF.tmp", $cacheF);   // атомарная запись кэша
flock($lock, LOCK_UN);
echo $json;
