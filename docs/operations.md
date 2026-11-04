# Експлуатація

## Розгортання

1. Зібрати незмінний image; перевірити CI, міграції й резервну копію.
2. Задати environment secrets: випадковий `APP_SECRET`, DB password, `APP_URL` HTTPS, SMTP. `.env.local` не комітити.
3. Запустити database/redis/mailer або керовані еквіваленти, потім app; виконати migrations та `messenger:setup-transports`.
4. Запустити web/worker/scheduler. Перевірити readiness, login, memberships та availability.
5. Налаштувати HTTPS ingress; проксувати коректний scheme. Для TLS termination перед nginx задайте `HTTPS on` у fastcgi params або налаштуйте trusted proxies з дозволеними IP. Інакше Secure cookie не визначатиметься коректно. Не довіряйте довільним forwarded headers.
6. `APP_ENV=prod`, `APP_DEBUG=0`; debug toolbar недоступна. Не запускати demo seed на реальних даних.

Production deploy залишається процедурою для конкретного хостингу; публічний staging/домен не створюються автоматично. Перед rolling deploy робіть backward-compatible migrations; поточна перша міграція призначена для чистої БД. Для worker після нової версії — restart контейнерів або `messenger:stop-workers`.

## Черги

Scheduler `app:notifications:tick` щохвилини обробляє outbox і прийоми в наступні 24 години. Unique notification key запобігає дублюванню рядків. Pending IDs можуть повторно потрапляти в транспорт; row lock і status роблять повторну обробку безпечною. Застарілі версії стають SKIPPED. SMTP exceptions повторюються з backoff, після 5 retries ідуть у failed transport.

`messenger:failed:show`, `messenger:failed:retry`, `messenger:stats`. Для повтору FAILED delivery після виправлення SMTP адміністратор інфраструктури має перевести конкретний delivery у PENDING і виконати retry; UI такого технічного доступу не надає.

Не зберігати персональні email payload у загальних логах. Handler зберігає безпечний код помилки, attempts та sent_at. Стабільний Message-ID допомагає дедуплікації на стороні пошти, але SMTP не дає exactly-once гарантії.

## Backup / restore

```sh
scripts/backup.sh var/backups/manual.dump
scripts/restore-check.sh var/backups/manual.dump
```

Restore створює **нову** БД `clinicflow_restore_TIMESTAMP`, застосовує pg_restore та перевіряє tenant relationships, interval overlap, overpayment і currency invariants. Вона зберігається для ручного огляду; видалення — окрема явна дія оператора.

Для HTTP smoke на відновленій БД запустіть окремий app із `DATABASE_URL` на цю БД та перевірте `/health/ready`, login, `/me`, `/clinics` і `/availability`. `app:integrity:check` доступна окремо.

Щоденний backup через cron із доступом до Docker; архіви шифрувати й копіювати поза хостом. Приклад retention: 7 щоденних + 4 щотижневих, restore rehearsal щомісяця. Самі архіви — поза webroot і Git, права 0600.

## Моніторинг і обслуговування

HTTP responses мають `X-Request-ID`; audit зв’язується за correlation_id. Symfony/Monolog записує помилки; production logs — JSON. Відстежуйте readiness, latency/error rate, DB connections/disk, Redis memory/queue lag, pending/failed notification count. Налаштування зовнішніх alert channels/дашбордів залежить від вашої інфраструктури.

Rate limit rows, expired idempotency/access/reset tokens необхідно періодично видаляти за retention. Refresh hashes зберігайте достатньо довго для reuse detection. Audit/financial history не видаляти повсякденними cleanup job. Ротація DB/SMTP secrets: змінити credential у провайдера, оновити environment, restart app/worker/scheduler, smoke test, відкликати попередній credential. Ротація сесій: revoke refresh families і видалити access tokens.
