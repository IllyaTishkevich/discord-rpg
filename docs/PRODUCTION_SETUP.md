# Настройка production-сервера и Discord

Пошаговая инструкция для первого деплоя discord-rpg: регистрация приложения в Discord и разворачивание backend/activity/bot на реальном сервере с HTTPS. Без этих шагов Activity нельзя открыть внутри настоящего Discord — см. `README.md`, раздел "Статус".

Все команды ниже — для Linux-сервера (Ubuntu/Debian) **без Docker**: PHP-FPM, nginx и PostgreSQL устанавливаются напрямую на хост, бэкенд разворачивается как обычное Symfony-приложение под системным пользователем, бот — через `systemd`. Замените `discord-rpg.example.com` на свой домен везде по тексту.

> Локальная разработка (`README.md`) по-прежнему использует `docker-compose.yml` из корня репозитория — это никак не связано с настоящей инструкцией и не требует изменений.

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

## Часть B — Production-сервер (без Docker)

### B.1. Требования

- VPS под Ubuntu 22.04+ (Debian 12 тоже подходит, пакеты называются так же). Минимум 1 vCPU / 2 GB RAM достаточно для старта.
- Домен, A-запись которого указывает на IP сервера (`discord-rpg.example.com`).
- Порты 80 и 443 открыты (HTTPS обязателен — Discord Activity и Google OAuth оба требуют HTTPS).
- Root/sudo-доступ для установки пакетов и настройки systemd.

### B.2. Установить системные пакеты

**PHP 8.2 + расширения** (Ubuntu 22.04 по умолчанию даёт 8.1 — для 8.2 нужен PPA `ondrej/php`; backend требует `php: ">=8.1"`, так что 8.1 из коробки тоже подойдёт, если не хотите добавлять сторонний репозиторий):

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.2-fpm php8.2-cli php8.2-pgsql php8.2-intl \
    php8.2-opcache php8.2-mbstring php8.2-xml php8.2-curl
```

**Composer**:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**PostgreSQL 16** (через официальный репозиторий PGDG, чтобы версия совпадала с той, что использовалась при разработке):

```bash
sudo apt install -y curl ca-certificates
sudo install -d /usr/share/postgresql-common/pgdg
sudo curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    https://www.postgresql.org/media/keys/ACCC4CF8.asc
sudo sh -c 'echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
sudo apt update
sudo apt install -y postgresql-16
```

**nginx, Node.js 20 (для сборки Activity и запуска бота), certbot**:

```bash
sudo apt install -y nginx
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
sudo apt install -y certbot python3-certbot-nginx
```

### B.3. Создать системного пользователя и склонировать репозиторий

```bash
sudo useradd --system --create-home --shell /usr/sbin/nologin discord-rpg
sudo mkdir -p /opt/discord-rpg
sudo chown discord-rpg:discord-rpg /opt/discord-rpg
sudo -u discord-rpg git clone <ваш-репозиторий> /opt/discord-rpg
cd /opt/discord-rpg
```

Все команды `sudo -u discord-rpg ...` ниже подразумевают, что вы находитесь в `/opt/discord-rpg`.

### B.4. Настроить PostgreSQL

```bash
sudo -u postgres psql -c "CREATE USER discord_rpg WITH PASSWORD 'CHANGE_ME_STRONG_PASSWORD';"
sudo -u postgres psql -c "CREATE DATABASE discord_rpg OWNER discord_rpg;"
```

По умолчанию PostgreSQL слушает только `localhost` (`pg_hba.conf` с методом `peer`/`scram-sha-256` для локальных подключений) — этого достаточно, backend и Postgres будут на одном сервере. Наружу порт 5432 открывать не нужно.

### B.5. Настроить `.env.local`

`backend/.env.local` (создать от имени `discord-rpg`, не коммитится — см. `backend/.gitignore`):

```bash
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 16)

DATABASE_URL="postgresql://discord_rpg:CHANGE_ME_STRONG_PASSWORD@127.0.0.1:5432/discord_rpg?serverVersion=16&charset=utf8"

DISCORD_CLIENT_ID=<из A.1>
DISCORD_CLIENT_SECRET=<из A.1>

BOT_API_SECRET=$(openssl rand -hex 24)

GOOGLE_CLIENT_ID=<из B.10>
GOOGLE_CLIENT_SECRET=<из B.10>
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

### B.6. Установить зависимости и подготовить backend

```bash
cd /opt/discord-rpg/backend
sudo -u discord-rpg composer install --no-dev --optimize-autoloader --no-interaction

sudo -u discord-rpg php bin/console lexik:jwt:generate-keypair
sudo -u discord-rpg php bin/console doctrine:migrations:migrate --no-interaction
sudo -u discord-rpg php bin/console app:seed-character-classes
sudo -u discord-rpg php bin/console app:seed-equipment
sudo -u discord-rpg php bin/console app:weekly-quests:generate
sudo -u discord-rpg php bin/console cache:clear --env=prod
```

`lexik:jwt:generate-keypair` создаёт `backend/config/jwt/{private,public}.pem` — эти файлы не коммитятся (см. `.gitignore`), генерируются один раз на сервере и остаются на нём; при переносе на новый сервер их нужно скопировать, иначе все выданные JWT станут невалидны.

### B.7. Настроить PHP-FPM пул

Дефолтный пул `www` можно использовать как есть, но отдельный пул под своим пользователем удобнее для логов и ограничения ресурсов:

```ini
; /etc/php/8.2/fpm/pool.d/discord-rpg.conf
[discord-rpg]
user = discord-rpg
group = discord-rpg
listen = /run/php/discord-rpg.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
```

```bash
sudo systemctl restart php8.2-fpm
sudo systemctl enable php8.2-fpm
```

### B.8. Настроить nginx + HTTPS

Сначала выпустить сертификат (certbot сам временно поднимет валидацию через порт 80 — домен уже должен указывать на сервер):

```bash
sudo certbot certonly --nginx -d discord-rpg.example.com
```

`/etc/nginx/sites-available/discord-rpg`:

```nginx
server {
    listen 80;
    server_name discord-rpg.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name discord-rpg.example.com;

    ssl_certificate     /etc/letsencrypt/live/discord-rpg.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/discord-rpg.example.com/privkey.pem;

    # backend API + EasyAdmin (/admin живёт в том же Symfony-приложении)
    location ~ ^/(api|admin)(/|$) {
        root /opt/discord-rpg/backend/public;
        try_files $uri /index.php$is_args$args;

        location ~ ^/(api|admin).*\.php(/|$) {
            fastcgi_pass unix:/run/php/discord-rpg.sock;
            fastcgi_split_path_info ^(.+\.php)(/.*)$;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /opt/discord-rpg/backend/public/index.php;
            fastcgi_param DOCUMENT_ROOT /opt/discord-rpg/backend/public;
        }
    }

    # статика Activity (собранный React, см. B.9)
    location / {
        root /opt/discord-rpg/activity/dist;
        try_files $uri /index.html;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/discord-rpg /etc/nginx/sites-enabled/discord-rpg
sudo nginx -t
sudo systemctl reload nginx
```

`certbot` ставит systemd-таймер (`certbot.timer`) для автопродления автоматически — отдельно настраивать не нужно, но стоит один раз проверить `sudo certbot renew --dry-run`.

### B.9. Собрать и задеплоить Activity

```bash
cd /opt/discord-rpg/activity
sudo -u discord-rpg npm install
sudo -u discord-rpg npm run build   # собирает activity/dist — именно его раздаёт nginx (см. B.8)
```

Пересобирать (`npm run build`) при каждом обновлении фронтенда — nginx раздаёт статику напрямую из `dist/`, рестарт nginx не нужен (только если менялся сам конфиг).

### B.10. Настроить Google OAuth для админки

1. [Google Cloud Console](https://console.cloud.google.com/apis/credentials) → **Create Credentials → OAuth client ID** → тип **Web application**.
2. **Authorized redirect URIs**: `https://discord-rpg.example.com/admin/oauth/google/check`.
3. Скопировать **Client ID**/**Client Secret** в `backend/.env.local` (см. B.5).
4. Выдать себе доступ:
   ```bash
   cd /opt/discord-rpg/backend
   sudo -u discord-rpg php bin/console app:admin:add you@gmail.com "Your Name"
   ```
5. Открыть `https://discord-rpg.example.com/admin`.

### B.11. Задеплоить бота

Бот — постоянно работающий Node-процесс, не HTTP-сервис, поэтому он запускается через `systemd`, а не через nginx/PHP-FPM:

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
cd /opt/discord-rpg/bot
sudo -u discord-rpg npm install --omit=dev
sudo systemctl daemon-reload
sudo systemctl enable --now discord-rpg-bot
```

Не забудьте `npm run deploy-commands` (см. A.5) после каждого добавления/изменения slash-команд — это отдельный разовый шаг, не часть `systemctl start`.

### B.12. Cron для недельных заданий

`app:weekly-quests:generate` идемпотентна — безопасно дёргать чаще, чем раз в неделю. Правьте crontab пользователя `discord-rpg` (`sudo -u discord-rpg crontab -e`):

```cron
0 6 * * 1 cd /opt/discord-rpg/backend && php bin/console app:weekly-quests:generate >> /var/log/discord-rpg-quests.log 2>&1
```

(Если `/var/log/discord-rpg-quests.log` недоступен для записи пользователю `discord-rpg` — создайте его заранее: `sudo touch /var/log/discord-rpg-quests.log && sudo chown discord-rpg /var/log/discord-rpg-quests.log`, либо пишите лог в `/opt/discord-rpg/var/log/`.)

### B.13. Обновление после первого деплоя

Без Docker-образов обновление — это `git pull` + переустановка зависимостей + миграции + рестарт PHP-FPM (перечитать новый код) и, если менялся фронтенд, пересборка Activity:

```bash
cd /opt/discord-rpg
sudo -u discord-rpg git pull

cd backend
sudo -u discord-rpg composer install --no-dev --optimize-autoloader --no-interaction
sudo -u discord-rpg php bin/console doctrine:migrations:migrate --no-interaction
sudo -u discord-rpg php bin/console cache:clear --env=prod
sudo systemctl restart php8.2-fpm

cd ../activity
sudo -u discord-rpg npm install
sudo -u discord-rpg npm run build

cd ../bot
sudo -u discord-rpg npm install --omit=dev
sudo systemctl restart discord-rpg-bot
```

### B.14. Чек-лист безопасности перед запуском

- [ ] `APP_ENV=prod` в `backend/.env.local` (не `dev` — иначе включён профайлер и подробные ошибки).
- [ ] Все секреты (`APP_SECRET`, `BOT_API_SECRET`, пароль БД, `JWT_PASSPHRASE`) — сгенерированы заново, не оставлены dev-плейсхолдерами из `backend/.env` (`changeme-bot-secret`, `!ChangeMe!` и т.п.).
- [ ] PostgreSQL слушает только `localhost` (проверьте `listen_addresses` в `postgresql.conf`), порт 5432 не открыт наружу в firewall (`ufw status` / `iptables`).
- [ ] `backend/config/jwt/*.pem` и все `.env.local` — не в git (проверьте `git status`, они должны быть проигнорированы).
- [ ] Backend и все процессы (`php8.2-fpm`, бот) работают от непривилегированного пользователя `discord-rpg`, не от root.
- [ ] HTTPS работает и на активити, и на `/api` (оба на одном домене — так спроектирован клиент активности).
- [ ] Discord URL Mapping (A.2) указывает на тот же домен, что в `activity/.env.local` и `backend/.env.local`.
- [ ] Первый админ добавлен командой из B.10, вход в `/admin` проверен вручную.

---

## Известные ограничения на момент написания

- Балансировка классов/снаряжения/монстров всё ещё плейсхолдерная (см. `docs/ROADMAP.md`, Game Design backlog) — в проде это будет заметно по чрезмерно долгим/несправедливым боям, поправимо через `/admin` без редеплоя.
- Мониторинг/алерты не настроены — рекомендуется подключить Sentry (`composer require sentry/sentry-symfony`) или аналог перед реальным запуском на пользователях.
- Зависшие PvP-дуэли (например, если один игрок закрыл Activity и не вернулся) чистятся вручную через `/admin`, автоматической очистки по расписанию нет.
