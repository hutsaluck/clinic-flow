# Покриття ТЗ та межі перевірки

Реалізовано робочі MVP workflow та основні v1.0 модулі: auth/profile/reset, клініки/членства/ролі, patients/doctors/services, schedule/overrides/absences, availability, booking/actions/history, manual billing/reversals, audit, CSV, analytics, outbox/reminders, Docker, CI configuration, OpenAPI, compact/full synthetic seed.

Реалізація policy-based замість Voters і DBAL агрегатів замість повного ORM mapping явно описана в ADR. Один backend controller не отримує глобального tenant обходу. MVP додає наявного співробітника за email; email invitations відкладено згідно з дозволеною альтернативою ТЗ.

| Критерій | Перевірка |
|---|---|
| AC-01 isolation | Functional: unauthorized clinic/resource, receptionist permissions, platform access boundary, doctor own patients |
| AC-02 availability | Unit: break/busy/adjacency, overrides and absence priority |
| AC-03 concurrent booking | Два незалежних PHP workers, один 201 та один 409; один appointment/event |
| AC-04 price snapshot | Service edit does not change existing appointment price/duration |
| AC-05 cancellation | Slot reuse, immutable final status, version conflicts; notification checks stale versions |
| AC-06 roles | Membership mutation/analytics/audit forbidden to receptionist; doctor cannot book/cancel |
| AC-07 billing | Partial payments, zero outstanding, overpayment rejection, single reversal |
| AC-08 duplicate delivery | Unique key and locked status handler; outbox/delivery integration tests |
| AC-09 DST | Europe/Kyiv spring nonexistent time and autumn duplicate hour with distinct offsets |
| AC-10 restore | Separate restore DB + integrity checker; operator HTTP smoke procedure |

Інфраструктурні пункти, які неможливо вважати завершеними самим репозиторієм: публічний staging URL/HTTPS, provider-specific production deploy, off-site backups/retention, зовнішні alerts, secrets manager, load test у цільовому production environment. Тестове локальне розгортання не є гарантією продуктивності або відповідності законодавству реальної клініки.

UI надає пошук/пагінацію та історію, деталі/оплати/стани записів, графіки й керування ролями. Великі календарні періоди завантажують усі сторінки API. Підтримуються базові API-фільтри та whitelist sort/order для довідників. CSV експортує операційні поля запису без контактних даних.

Після архітектурного оновлення Appointment/Billing мають агрегати, immutable value objects, typed commands і порти з Doctrine adapters. Domain/Application перевіряються PHPStan level 9 та architecture tests; загальний наявний backend залишається на level 5. Повна автоматична перевірка всього UI не дорівнює сертифікації WCAG: axe/keyboard/E2E перевіряють ключові екрани, теми та сценарії.
