# discord-rpg

RPG-бот для Discord с боевой системой на "битах" (двусторонние монеты: удар / защита / действие) в духе Runebound 3rd ed., реализованный через Discord Activity.

## Архитектура

- **backend/** — Symfony API. Источник истины: пользователи, персонажи, классы, биты, снаряжение, HP/энергия, бои, турниры, недельные задания, ивенты.
- **activity/** — React-приложение, открывается как Discord Activity (iframe в голосовом канале через Embedded App SDK). Арена боя, магазин, инвентарь, турнир, недельные задания.
- **bot/** — Discord bot (Node.js / discord.js). Запуск Activity, slash-команды (`/profile`, `/pve`, `/shop`, `/duel`, `/event-start`, `/event-fight`, `/tournament`, `/quest`, `/leaderboard`), рассылка ивентов.

## Ключевые решения

- Синхронизация боя: **API-поллинг + оптимистичный UI** на React (не WebSocket) — дешевле в разработке и хостинге. Раунд боя разбит на `/throw` и `/resolve`, чтобы игрок видел свой бросок до выбора цели для "действия".
- Discord Activity требует HTTPS-домен (прод) или туннель (Discord Developer Tunnel / ngrok) в разработке; фронт обращается к бэкенду через `/.proxy/` при запуске внутри Discord.
- Авторизация в Activity — через Discord OAuth2 (Embedded App SDK: `authorize` → бэкенд обменивает код на токен → своя JWT-сессия).
- Bot ↔ backend — отдельный контур авторизации через общий секрет (`X-Bot-Secret`), а не пользовательский JWT: бот действует от имени игрока по его `discordId`, не имея его токена.
- PvP пока не реализован как живой синхронный бой: `/duel` — это приглашение/подтверждение, а турнирные матчи симулируются сервером по статам обоих персонажей (см. `TournamentService`).

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

## Статус

Реализованы Sprint 0–7 из `docs/ROADMAP.md` (инфраструктура, аккаунты, боевое ядро, Activity-визуализация, бот, магазин, ивенты, турниры и задания) плюс `/leaderboard`. Не сделано и требует действий за пределами кода:

- **Регистрация приложения в Discord Developer Portal** — нужны реальные `DISCORD_CLIENT_ID`/`DISCORD_CLIENT_SECRET` и настройка Activity/URL Mappings, чтобы вообще запустить Activity внутри Discord и проверить OAuth-флоу живьём.
- **HTTPS-туннель для разработки** (Developer Tunnel/ngrok) — тоже требует аккаунта/настройки, не подставишь программно.
- **Баланс классов/снаряжения/монстров** — числа сейчас плейсхолдеры для сквозного тестирования API, реальная балансировка — по `docs/ROADMAP.md` (Game Design backlog), после живого плейтеста.
- **Мониторинг/алерты для прода** — нужен выбор провайдера (Sentry и т.п.) и его учётные данные.
- **Живой PvP** — не реализован; `/duel` только приглашает и подтверждает пару, реальные бои между двумя игроками потребуют отдельного протокола синхронизации (кандидат — расширить схему throw/resolve на двух реальных игроков вместо игрок-vs-бот).
