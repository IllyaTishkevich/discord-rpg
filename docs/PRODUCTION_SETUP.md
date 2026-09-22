# Настройка production-сервера и Discord

Пошаговая инструкция для первого деплоя discord-rpg: регистрация приложения в Discord и разворачивание backend/activity/bot на реальном сервере с HTTPS. Без этих шагов Activity нельзя открыть внутри настоящего Discord — см. `README.md`, раздел "Статус".

Все команды ниже — для Linux-сервера (Ubuntu/Debian) с Docker. Замените `discord-rpg.example.com` на свой домен везде по тексту.

---

## Часть A — Discord

### A.1. Создать приложение

1. Открыть [Discord Developer Portal](https://discord.com/developers/applications) → **New Application** → задать имя (например, `discord-rpg`).
2. На вкладке **OAuth2 → General** скопировать:
   - **Application ID** → это `DISCORD_CLIENT_ID` (backend `.env.local`) и `VITE_DISCORD_CLIENT_ID` (activity `.env.local`).
   - **Client Secret** (кнопка "Reset Secret", если ещё не генерировался) → `DISCORD_CLIENT_SECRET` (backend).

### A.2. Включить Activities и настроить URL Mapping

1. Вкладка **Activities → Settings** (может называться "App Testers"/"Embedded" в зависимости от текущего интерфейса портала — Discord периодически переименовывает разделы).
2. Включить **Enable Activity**.
3. **URL Mapping**: добавить маппинг корневого префикса `/` → `discord-rpg.example.com` (домен вашего продакшен-сервера, см. Часть B). Именно через этот маппинг Discord проксирует запросы Activity (`/.proxy/api/...`) на ваш backend — это то, что уже реализовано в `activity/src/api/client.ts` (`isEmbeddedInDiscord` → базовый URL `/.proxy/api`).
4. Сохранить.

> Без реального HTTPS-домена, прописанного в URL Mapping, Activity не откроется внутри Discord — см. `README.md`.

### A.3. Создать бота

1. Вкладка **Bot** → **Add Bot** (если ещё не создан).
2. Скопировать **Token** (кнопка "Reset Token") → `DISCORD_BOT_TOKEN` (`bot/.env`). Это секрет, храните только в `.env`, никогда не коммитьте.
3. В разделе **Privileged Gateway Intents** — дополнительные intents (Presence/Members/Message Content) боту не нужны, он использует только `Guilds` и `GuildVoiceStates` (см. `bot/src/index.js`).

### A.4. Пригласить бота на сервер

1. Вкладка **OAuth2 → URL Generator**.
2. **Scopes**: `bot`, `applications.commands`.
3. **Bot Permissions**: минимально нужны:
   - `Send Messages`
   - `Use Slash Commands` (даётся автоматически через `applications.commands`)
   - `Create Instant Invite` (нужен для `/play` — команда создаёт инвайт с `targetType: EmbeddedApplication`)
   - `Embed Links` (для эмбедов результатов боя/магазина/турнира)
4. Открыть сгенерированную ссылку, выбрать сервер, подтвердить.

### A.5. Зарегистрировать slash-команды

После того как заполнены `bot/.env` (`DISCORD_BOT_TOKEN`, `DISCORD_CLIENT_ID`, опционально `DISCORD_GUILD_ID` для мгновенной регистрации на одном сервере при разработке):

```bash
cd bot
npm install
npm run deploy-commands
```

Регистрирует: `/play`, `/profile`, `/pve`, `/duel`, `/shop`, `/event-start`, `/event-fight`, `/tournament`, `/quest`, `/leaderboard`. Без `DISCORD_GUILD_ID` команды регистрируются глобально — обновление занимает до часа.

---

## Часть B — Production-сервер

### B.1. Требования

- VPS с Docker + Docker Compose (Ubuntu 22.04+ рекомендуется). Минимум 1 vCPU / 2 GB RAM достаточно для старта.
- Домен, A-запись которого указывает на IP сервера (`discord-rpg.example.com`).
- Порты 80 и 443 открыты (HTTPS обязателен — Discord Activity и Google OAuth оба требуют HTTPS).

### B.2. Установить Docker

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# перелогиниться, чтобы группа применилась
```

### B.3. Склонировать репозиторий и настроить `.env.local`

```bash
git clone <ваш-репозиторий> /opt/discord-rpg
cd /opt/discord-rpg
```

`backend/.env.local` (создать, не коммитится — см. `backend/.gitignore`):

```bash
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 16)

DATABASE_URL="postgresql://discord_rpg:CHANGE_ME_STRONG_PASSWORD@database:5432/discord_rpg?serverVersion=16&charset=utf8"

DISCORD_CLIENT_ID=<из A.1>
DISCORD_CLIENT_SECRET=<из A.1>

BOT_API_SECRET=$(openssl rand -hex 24)

GOOGLE_CLIENT_ID=<из B.7>
GOOGLE_CLIENT_SECRET=<из B.7>
```

`bot/.env`:

```bash
DISCORD_BOT_TOKEN=<из A.3>
DISCORD_CLIENT_ID=<из A.1>
BACKEND_API_URL=https://discord-rpg.example.com/api
BOT_API_SECRET=<то же значение, что в backend/.env.local>
```

`activity/.env.local`:

```bash
VITE_DISCORD_CLIENT_ID=<из A.1>
VITE_BACKEND_API_URL=https://discord-rpg.example.com/api
```

> `BOT_API_SECRET` должен **совпадать** в `backend` и `bot` — это общий секрет для service-to-service запросов (заголовок `X-Bot-Secret`, проверяется в `backend/src/Controller/AbstractBotController.php`).

### B.4. Production docker-compose

Корневой `docker-compose.yml` в репозитории — dev-конфиг: монтирует исходники как volume и пробрасывает порт Postgres наружу. Для прода создайте `docker-compose.prod.yml` рядом:

```yaml
services:
  database:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: discord_rpg
      POSTGRES_USER: discord_rpg
      POSTGRES_PASSWORD: CHANGE_ME_STRONG_PASSWORD
    volumes:
      - db-data:/var/lib/postgresql/data
    # порт наружу НЕ пробрасываем в проде

  backend:
    build:
      context: ./backend
    restart: unless-stopped
    env_file:
      - ./backend/.env.local
    depends_on:
      - database

  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile
      - ./activity/dist:/srv/activity:ro
      - caddy-data:/data
    depends_on:
      - backend

volumes:
  db-data:
  caddy-data:
```

Используется [Caddy](https://caddyserver.com/) вместо голого nginx — он сам получает и продлевает Let's Encrypt сертификат по домену из `Caddyfile`, без ручной настройки certbot.

`Caddyfile` (в корне репозитория):

```
discord-rpg.example.com {
    handle /api/* {
        reverse_proxy backend:9000 {
            transport fastcgi {
                root /var/www/app/public
                split .php
            }
        }
    }

    handle {
        root * /srv/activity
        try_files {path} /index.html
        file_server
    }
}
```

> Если решите оставить nginx вместо Caddy — переиспользуйте `docker/nginx/default.conf` из репозитория как основу и добавьте `location /` для раздачи `activity/dist`, плюс отдельный контейнер `certbot` для сертификатов.

### B.5. Собрать и задеплоить backend

```bash
cd /opt/discord-rpg
docker compose -f docker-compose.prod.yml build backend
docker compose -f docker-compose.prod.yml up -d database
docker compose -f docker-compose.prod.yml run --rm backend php bin/console lexik:jwt:generate-keypair
docker compose -f docker-compose.prod.yml run --rm backend php bin/console doctrine:database:create --if-not-exists
docker compose -f docker-compose.prod.yml run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f docker-compose.prod.yml run --rm backend php bin/console app:seed-character-classes
docker compose -f docker-compose.prod.yml run --rm backend php bin/console app:seed-equipment
docker compose -f docker-compose.prod.yml run --rm backend php bin/console app:weekly-quests:generate
docker compose -f docker-compose.prod.yml up -d
```

`lexik:jwt:generate-keypair` создаёт `backend/config/jwt/{private,public}.pem` — эти файлы не коммитятся (см. `.gitignore`), генерируются один раз на сервере и остаются на нём (при пересборке образа не теряются, так как лежат вне `vendor/`; при переносе на новый сервер их нужно скопировать, иначе все выданные JWT станут невалидны).

### B.6. Собрать и задеплоить Activity

```bash
cd activity
npm install
npm run build   # собирает activity/dist — именно его раздаёт Caddy/nginx
```

Пересобирать (`npm run build`) и рестартовать Caddy при каждом обновлении фронтенда.

### B.7. Настроить Google OAuth для админки

1. [Google Cloud Console](https://console.cloud.google.com/apis/credentials) → **Create Credentials → OAuth client ID** → тип **Web application**.
2. **Authorized redirect URIs**: `https://discord-rpg.example.com/admin/oauth/google/check`.
3. Скопировать **Client ID**/**Client Secret** в `backend/.env.local` (см. B.3).
4. Выдать себе доступ:
   ```bash
   docker compose -f docker-compose.prod.yml run --rm backend php bin/console app:admin:add you@gmail.com "Your Name"
   ```
5. Открыть `https://discord-rpg.example.com/admin`.

### B.8. Задеплоить бота

Бот — постоянно работающий Node-процесс, не HTTP-сервис, поэтому его проще всего запускать вне docker-compose стека приложения, через `systemd`:

```ini
# /etc/systemd/system/discord-rpg-bot.service
[Unit]
Description=discord-rpg Discord bot
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/discord-rpg/bot
ExecStart=/usr/bin/node src/index.js
EnvironmentFile=/opt/discord-rpg/bot/.env
Restart=on-failure
User=discord-rpg

[Install]
WantedBy=multi-user.target
```

```bash
cd /opt/discord-rpg/bot && npm install --omit=dev
sudo systemctl daemon-reload
sudo systemctl enable --now discord-rpg-bot
```

Не забудьте `npm run deploy-commands` (см. A.5) после каждого добавления/изменения slash-команд — это отдельный разовый шаг, не часть `systemctl start`.

### B.9. Cron для недельных заданий

`app:weekly-quests:generate` идемпотентна — безопасно дёргать чаще, чем раз в неделю:

```bash
# crontab -e
0 6 * * 1 cd /opt/discord-rpg && docker compose -f docker-compose.prod.yml run --rm backend php bin/console app:weekly-quests:generate >> /var/log/discord-rpg-quests.log 2>&1
```

### B.10. Чек-лист безопасности перед запуском

- [ ] `APP_ENV=prod` в `backend/.env.local` (не `dev` — иначе включён профайлер и подробные ошибки).
- [ ] Все секреты (`APP_SECRET`, `BOT_API_SECRET`, пароль БД, `JWT_PASSPHRASE`) — сгенерированы заново, не оставлены dev-плейсхолдерами из `backend/.env` (`changeme-bot-secret`, `!ChangeMe!` и т.п.).
- [ ] Порт Postgres (5432) не пробрасывается наружу (см. B.4 — в проде без `ports:` у `database`).
- [ ] `backend/config/jwt/*.pem` и все `.env.local` — не в git (проверьте `git status`, они должны быть проигнорированы).
- [ ] HTTPS работает и на активити, и на `/api` (оба на одном домене — так спроектирован клиент активности).
- [ ] Discord URL Mapping (A.2) указывает на тот же домен, что в `activity/.env.local` и `backend/.env.local`.
- [ ] Первый админ добавлен командой из B.7, вход в `/admin` проверен вручную.

---

## Известные ограничения на момент написания

- Балансировка классов/снаряжения/монстров всё ещё плейсхолдерная (см. `docs/ROADMAP.md`, Game Design backlog) — в проде это будет заметно по чрезмерно долгим/несправедливым боям, поправимо через `/admin` без редеплоя.
- Мониторинг/алерты не настроены — рекомендуется подключить Sentry (`composer require sentry/sentry-symfony`) или аналог перед реальным запуском на пользователях.
- Живого PvP нет — `/duel` только приглашает и подтверждает пару (см. README).
