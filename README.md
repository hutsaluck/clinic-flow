# ClinicFlow

Українська CRM зі світлою/темною темами, клавіатурною навігацією та адаптивним інтерфейсом для приватних клінік: команда, пацієнти, графіки, календар записів, оплати й операційна аналітика. Демонстраційні дані повністю синтетичні.

ClinicFlow is a multi-tenant clinic operations CRM built with Symfony 7.4, PHP 8.3, Doctrine, PostgreSQL 16, React 19, TypeScript, Redis and Symfony Messenger. It demonstrates database-enforced interval booking, tenant-scoped authorization, immutable price snapshots and a transactional outbox.

## Запуск у Docker

Потрібні Docker Engine / Docker Desktop і Compose v2. З кореня репозиторію:

```sh
cp .env.example .env
docker compose up -d --build database redis mailer app web
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console messenger:setup-transports
docker compose exec app php bin/console app:demo:seed
docker compose up -d --build worker scheduler
```

- Застосунок: http://localhost:8080
- OpenAPI: http://localhost:8080/api/docs
- Mailpit: http://localhost:8025
- Перевірка процесу: `/health/live`; БД/схеми: `/health/ready`.

Демооблікові записи мають однаковий пароль **`ClinicFlow!2026`**:

| Email | Доступ |
| --- | --- |
| `admin@clinicflow.test` | Адміністратор двох демоклінік |
| `reception@clinicflow.test` | Реєстратор першої клініки |
| `doctor@clinicflow.test` | Лікар із власними прийомами першої клініки |
| `platform@clinicflow.test` | Технічне керування клініками без доступу до пацієнтів |

Повторний seed не змінює наявні дані. Для **нової порожньої БД** команда `app:demo:seed --full --no-debug` створює 5 клінік, 50 лікарів, 5000 пацієнтів і 30000 прийомів. Стандартний набір невеликий, зручний для щоденної розробки. `--full` не доповнює вже засіяну БД.

## Локальна розробка

PHP 8.3+ з `pdo_pgsql`, `intl`, `mbstring`, Composer 2, Node 22.13+.

```sh
cp .env.example .env
composer install --no-scripts
docker compose up -d database redis mailer
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:demo:seed
cd frontend
npm ci
npm run build
cd ..
php -S 127.0.0.1:8080 -t public public/router.php
```

У Git зберігається лише `.env.example` із демонстраційними значеннями. Робочі `.env`, `.env.dev`, `.env.test` та локальні перевизначення ігноруються. Команда копіювання потрібна під час першого налаштування; наявний `.env` зберігайте зі своїми значеннями. Docker створює базовий `.env` із шаблону всередині образу, а параметри підключень отримує через environment.

Для HMR запустіть `npm run dev` у `frontend`; Vite проксує API на PHP-сервер. У development черга використовує Doctrine, оскільки локальному PHP не потрібне розширення Redis:

```sh
php bin/console messenger:setup-transports
php bin/console app:notifications:tick
php bin/console messenger:consume async -vv
```

`app:notifications:tick` має виконуватися щохвилини. У Docker це робить `scheduler`, а черга використовує Redis. Для failed jobs: `messenger:failed:show` та `messenger:failed:retry`.

Новий користувач: `php bin/console app:user:create email@example.test Ім’я Прізвище`. Пароль вводиться приховано. Адміністратор додає наявний email у налаштуваннях клініки. Для нового platform admin є CLI-прапорець `--platform-admin`. Цей обраний режим MVP описаний у ADR.

## Перевірки

```sh
scripts/test.sh
vendor/bin/phpstan analyse --memory-limit=512M
composer analyse:domain
vendor/bin/php-cs-fixer fix --dry-run --diff
php bin/console lint:container
php bin/console lint:yaml config
cd frontend
npm run lint
npm run typecheck
npm run build
npx playwright install chromium
npm run test:e2e
```

Тести використовують **окрему PostgreSQL БД `clinicflow_test`**. `scripts/test.sh` створює її та застосовує міграції. Конкурентний тест запускає два незалежні PHP-процеси. E2E потребують запущеного застосунку з демоданими; `E2E_URL` змінює адресу. GitHub Actions описані в `.github/workflows/ci.yml`.

## Основні правила

- Tenant задається маршрутом; кожен endpoint перевіряє активну клініку, членство, роль і належність об’єкта. Platform admin не має автоматичного доступу до персональних даних.
- PostgreSQL `EXCLUDE USING gist` не допускає перетинів активних інтервалів `[start,end)`. Бронювання та графік блокують той самий рядок лікаря.
- `Idempotency-Key` діє 24 години; повтор з іншим payload повертає 409. Зміни прийому потребують поточної `version`.
- Ціна, назва, валюта та тривалість фіксуються в прийомі. Усі суми — цілі копійки.
- Робочі години локальні; timestamps — UTC. Слоти перебираються за реальними UTC-моментами. Повторна година DST має два різні offsets; неіснуючих моментів немає.
- Невдала зміна розкладу повертає 409 зі списком зачеплених записів. Спочатку адміністратор переносить/скасовує записи.
- Оплати часткові, сторнування окремими рядками, подвійне сторнування та переплата заборонені.
- Access token зберігається в пам’яті React; refresh — у HttpOnly SameSite cookie (Secure на HTTPS). У БД зберігаються лише хеші токенів. Повторне використання refresh відкликає всю сім’ю сесії.
- Outbox атомарний із прийомом, delivery дедуплікується; перед SMTP повторно перевіряється версія прийому. SMTP не забезпечує exactly-once delivery: збій після прийняття листа провайдером, але до commit може спричинити повтор. Використано стабільний Message-ID.

## Документація

[Технічне завдання](docs/ClinicFlow_TZ.md) · [Архітектура](docs/architecture/overview.md) · [ADR](docs/adr/decisions.md) · [OpenAPI JSON](docs/api/openapi.json) · [Стан вимог](docs/requirements.md).
