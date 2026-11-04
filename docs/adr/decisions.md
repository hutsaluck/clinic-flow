# Architecture decisions

Дата: 2026-11-03. Статус: прийнято для поточної реалізації.

| ADR | Рішення та наслідки |
|---|---|
| 001 | Symfony 7.4 LTS, PHP 8.3+, модульний моноліт. Backend залишається в корені наявного Symfony-проєкту; React у `frontend`. |
| 002 | Opaque random access tokens 15 хв замість JWT. Хеші в БД спрощують миттєве відкликання. Refresh 14 днів, rotation/reuse detection, HttpOnly SameSite=Strict, Secure на HTTPS. |
| 003 | Shared database/schema, clinic_id, membership policy на кожному endpoint і складені FK. |
| 004 | Policy service `Identity` замість Symfony Voters. Ролі не глобальні; доктору додатково перевіряється ownership. Політика використовується для всіх HTTP операцій. |
| 005 | UUIDv4 всюди; непрозорість ID не замінює авторизацію. |
| 006 | UTC instants у TIMESTAMPTZ; IANA timezone клініки. Grid 15 хв за локальними хвилинами, тривалість у реальних хвилинах. Повторні години DST мають окремі offsets. |
| 007 | Money як цілі minor units, snapshot у appointment. Оплати з групуванням за валютою; не підсумовувати різні валюти в одне число. |
| 008 | GiST exclusion для активних інтервалів, спільний row lock doctor для графіка/бронювань, optimistic version для змін appointment. |
| 009 | Transactional outbox → persisted deduplicated deliveries → Messenger/Redis. Scheduler повторно dispatch pending ID після збою. Handler перевіряє актуальну appointment version. SMTP має at-least-once крайовий сценарій після provider accept / до commit. |
| 010 | OpenAPI 3.1 JSON і Swagger UI. |
| 011 | Тільки PostgreSQL для integration/functional/concurrency tests; test DB має suffix `_test`. |
| 012 | React 19 + TypeScript, Vite. Access token лише в module memory; reload відновлює через cookie. |
| 013 | Точні PHP-залежності у composer.lock; JS у package-lock.json. Docker PHP 8.3 / Node 22 / PostgreSQL 16 / Redis 7. |
| 014 | Обрано дозволений ТЗ MVP режим додавання **наявного користувача за email**. Користувачів створює CLI; email invitations не реалізовані. |
| 015 | Final appointment statuses immutable; виправлення фінальних прийомів поза поточним scope. |
| 016 | Doctrine ORM для identity, DBAL для агрегатних транзакцій/read models, міграції для всіх constraints. Це свідомий відступ від повного ORM mapping, що зберігає прозорість PostgreSQL locking. |
| 017 | Зміни графіка з конфліктами відхиляються атомарно; адміністратор спочатку переносить/скасовує прийоми, потім повторює зміну. |
| 018 | Business-critical bounded contexts Appointment/Billing: framework-free aggregates, immutable value objects, backed enums, typed commands, ports and Doctrine adapters. Clock and transaction manager injected. HTTP errors mapped at presentation boundary. Directory CRUD/read models remain straightforward. |
| 019 | Domain/Application quality gate: PHPStan level 9 and executable dependency-boundary tests. Existing infrastructure/controllers retain the base level-5 gate; no baselines suppress new-core failures. |
| 020 | UI design system uses semantic light/dark tokens, local IBM Plex Sans with Cyrillic, native dialogs, URL state and keyboard quick navigation. |
| 021 | Supersedes the visual composition/font in 020: day journal with horizontal navigation, chronological patient rows, week overview, visit drawers and a booking workspace. Local Commissioner variable font, porcelain/plum semantic themes, distinct patient/team/service/analytics views. |
