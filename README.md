# 📡 Живой спред · MOEX vs Forex

Одностраничный live-индикатор раздвижки для межбиржевого арбитража:
вечники MOEX (USDRUBF/CNYRUBF/EURRUBF) и квартальный тройничок Si/Cr против форекса
(USD/CNH, EUR/CNH, EUR/USD). Две строки цен на стратегию — обе стороны сделки по лимиткам
(Short → ask, Buy → bid), процент раздвижки на каждой, свежесть по ногам, клик копирует формулу.

**Пользователю настраивать нечего: ни токенов, ни сертификатов.**

| Нога | Источник | Как |
|---|---|---|
| MOEX bid/ask | T-Invest API через relay `api/quotes.php` на этом же хостинге | PHP ходит в T-Invest с корнем УЦ Минцифры (`CURLOPT_CAINFO`), общий токен «только для чтения» лежит вне `public_html`; `GetOrderBook depth=1`, кэш 1 с на всех посетителей, страница опрашивает раз в 2 с |
| Forex bid/ask | TradingView scanner `FX_IDC` | без ключа, раз в 2 с; EUR/CNH считается кроссом EURUSD×USDCNH |

## Использование

1. Откройте http://live-spread.kryuko.beget.tech/ — и всё. Локально скачанный `index.html` тоже работает (relay берётся с того же адреса).
2. Зеркало на GitHub Pages (https://vladimir-kriukov.github.io/live-spread/) показывает только форекс: https-страница не может обращаться к http-relay, пока у Beget-домена нет сертификата.

## Разработка

`index.html` **генерируется**, руками не править. UI и формулы приезжают из сайдкара №13
калькулятора (`.claude/live-spread-hud.html` в соседнем проекте `../spread`), форма токена и
слой данных из `template.html` здесь. Сборка из `../spread`:
`python3 .claude/scripts/build_live_spread.py` (пишет `index.html` сюда, папка задаётся
`LIVE_SPREAD_DIR`), затем commit + push в этом репозитории. Заливка на Beget: тот же скрипт с `--deploy` (rsync `index.html` + `api/` по SSH, ключ — через `ssh-copy-id`).
Relay: `api/quotes.php` (PHP 7.4+/8.2) + `api/ca-minzifry-root.crt` (корень «Russian Trusted Root CA», публичный).
Токен T-Invest на сервере: `~/live-spread.kryuko.beget.tech/tinvest.token` (вне `public_html`, не в репо), кэш и uid — в `.cache/` рядом. При перекладке контрактов
(`SiZ6/CRZ6` на следующую серию) правится список `PERPS` в сайдкаре и страница пересобирается.
