# discord-rpg

RPG-бот для Discord с боевой системой на "битах" (двусторонние монеты: удар / защита / действие) в духе Runebound 3rd ed., реализованный через Discord Activity.

Если вы просто игрок и хотите начать играть — см. `docs/PLAYER_GUIDE.md` (как создать персонажа, что делают команды бота). Ниже — техническая документация для разработки/деплоя.

## Архитектура

- **backend/** — Symfony API. Источник истины: пользователи, персонажи, классы, биты, снаряжение, HP/энергия, бои, турниры, недельные задания, ивенты. Плюс `/admin` — EasyAdmin-панель со входом через Google.
- **activity/** — React-приложение, открывается как Discord Activity (iframe в голосовом канале через Embedded App SDK). Арена боя, магазин, инвентарь, турнир, недельные задания.
- **bot/** — Discord bot (Node.js / discord.js). Запуск Activity, slash-команды (`/profile`, `/pve`, `/shop`, `/duel`, `/event-start`, `/event-fight`, `/tournament`, `/quest`, `/leaderboard`), рассылка ивентов.

## Ключевые решения

- Синхронизация боя: **API-поллинг + оптимистичный UI** на React (не WebSocket) — дешевле в разработке и хостинге. Раунд боя разбит на `/throw` и `/resolve`, чтобы игрок видел свой бросок до выбора цели для "действия".
- Discord Activity требует HTTPS-домен (прод) или туннель (Discord Developer Tunnel / ngrok) в разработке; фронт обращается к бэкенду через `/.proxy/` при запуске внутри Discord.
- Авторизация в Activity — через Discord OAuth2 (Embedded App SDK: `authorize` → бэкенд обменивает код на токен → своя JWT-сессия).
- Bot ↔ backend — отдельный контур авторизации через общий секрет (`X-Bot-Secret`), а не пользовательский JWT: бот действует от имени игрока по его `discordId`, не имея его токена.
- PvP (`/duel`) — живой пошаговый бой между двумя реальными игроками, тот же интерактивный движок, что PvE/ивент (см. `docs/COMBAT_V2_DESIGN.md` §7, `docs/BATTLE_ROOM_DESIGN.md`): `Battle` в статусе `waiting` — это и есть приглашение; после готовности обеих сторон раунд разыгрывается ход за ходом (`exchanges/move`), сторона, чей сейчас не ход, поллит `GET /battles/{id}`. Турнирные матчи (`/tournament`) — отдельный, более простой случай: сервер симулирует их сразу целиком по статам обоих персонажей (см. `TournamentService`), без живого пошагового боя.
- Админка (`/admin`) — отдельный контур: свой firewall, своя сущность `Admin` (не `User`), вход только через Google OAuth и только для email, добавленных заранее через `app:admin:add` — самостоятельной регистрации нет.

## Модель данных

```
User (discordId, displayName, avatar)
 └─ Character (class, hp/maxHp, energy/maxEnergy, level, xp, coins)
     ├─ Bit[]                    — 2 стороны: attack / defense / action
     ├─ CharacterEquipment[]     — история покупок в магазине
     ├─ Battle[]                 — PvE / event-бои (throw → resolve, лог раундов)
     ├─ TournamentEntry[]
     └─ CharacterQuestProgress[]

CharacterClass    — справочник классов со стартовым набором бит
Equipment         — каталог магазина (даёт биту или +HP)
Event             — периодический ивент ("нападение монстров")
Tournament / TournamentEntry / TournamentMatch  — сетка на выбывание
WeeklyQuest       — одно задание в неделю (win_battles)
```

## Быстрый старт (локально)

```bash
docker compose up -d
docker compose run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm backend php bin/console app:seed-character-classes
docker compose run --rm backend php bin/console app:seed-equipment
docker compose run --rm backend php bin/console app:weekly-quests:generate
```

Backend доступен на `http://localhost:8000`. Для Activity: `cd activity && npm run dev`. Для бота: `cd bot && npm run deploy-commands && npm start` (нужны `DISCORD_BOT_TOKEN`, `DISCORD_CLIENT_ID` в `bot/.env`).

### Админка

1. Создать OAuth-клиент в [Google Cloud Console](https://console.cloud.google.com/apis/credentials) (тип "Web application"), redirect URI — `https://<ваш-домен>/admin/oauth/google/check` (локально — `http://localhost:8000/admin/oauth/google/check`).
2. Заполнить `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` в `backend/.env.local`.
3. Выдать доступ своему аккаунту:
   ```bash
   docker compose run --rm backend php bin/console app:admin:add you@gmail.com "Your Name"
   ```
4. Открыть `http://localhost:8000/admin` → "Войти через Google".

### Превью Activity без Discord (дизайн/дебаг)

Открывать Activity можно только реально внутри Discord (нужен HTTPS-туннель — Developer Tunnel/ngrok, см. "Известные ограничения" ниже). Для итерации по вёрстке/дизайну без этого есть `/admin` → "Превью Activity" — выбираете персонажа, открывается iframe с Activity, авторизованный сразу токеном этого игрока, без реального Discord SDK. Работает через `ACTIVITY_PREVIEW_URL` в `backend/.env(.local)` (по умолчанию `http://localhost:5173` — порт `npm run dev`), так что достаточно держать `activity` запущенным в dev-режиме рядом — правки в коде подхватятся с hot-reload прямо во фрейме. Доступно только залогиненным админам (тот же firewall, что у остальной `/admin`) — снаружи никак не достать.

## Статус

Реализованы Sprint 0–7 из `docs/ROADMAP.md` (инфраструктура, аккаунты, боевое ядро, Activity-визуализация, бот, магазин, ивенты, турниры и задания) плюс `/leaderboard`, админка на EasyAdmin и живой PvP (`docs/BATTLE_ROOM_DESIGN.md`). Не сделано и требует действий за пределами кода:

- **Регистрация приложения в Discord Developer Portal** — нужны реальные `DISCORD_CLIENT_ID`/`DISCORD_CLIENT_SECRET` и настройка Activity/URL Mappings, чтобы вообще запустить Activity внутри Discord и проверить OAuth-флоу живьём.
- **Google OAuth credentials для админки** — аналогично, нужен реальный проект в Google Cloud Console (см. выше); без него `/admin/login` работает, но сам OAuth-обмен не пройти.
- **HTTPS-туннель для разработки** (Developer Tunnel/ngrok) — тоже требует аккаунта/настройки, не подставишь программно.
- **Баланс классов/снаряжения/монстров** — числа сейчас плейсхолдеры для сквозного тестирования API, реальная балансировка — по `docs/ROADMAP.md` (Game Design backlog), после живого плейтеста. Теперь их можно крутить прямо в админке (классы, снаряжение), не трогая код.
- **Мониторинг/алерты для прода** — нужен выбор провайдера (Sentry и т.п.) и его учётные данные.
- **PvP-полировка** (см. `docs/BATTLE_ROOM_DESIGN.md` §8/§9, фаза 5) — что считать зависшей дуэлью, если оба игрока пропали посреди боя (сейчас чистится вручную через `/admin`), ставки на победу, реванш.
