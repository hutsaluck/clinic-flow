import { useEffect, useState } from "react";
import {
    ArrowDownRight,
    ArrowUpRight,
    CreditCard,
    Download,
    RefreshCw,
} from "lucide-react";
import { api, download, type Row } from "../api";
import { addDays, dateKey, err, methods, money } from "../shared/ui";

export function Analytics({
    clinic,
    revision,
    mode = "analytics",
}: {
    clinic: Row;
    revision: number;
    mode?: "overview" | "analytics";
}) {
    const [from, setFrom] = useState(() =>
            mode === "overview"
                ? dateKey(new Date())
                : dateKey(
                      new Date(
                          new Date().getFullYear(),
                          new Date().getMonth(),
                          1,
                      ),
                  ),
        ),
        [to, setTo] = useState(() =>
            dateKey(addDays(new Date(), mode === "overview" ? 1 : 30)),
        ),
        [data, setData] = useState<Row | null>(null),
        [services, setServices] = useState<Row[]>([]),
        [doctors, setDoctors] = useState<Row[]>([]),
        [error, setError] = useState(""),
        [loading, setLoading] = useState(true);
    useEffect(() => {
        let active = true;
        setLoading(true);
        const query = `?from=${from}T00:00:00Z&to=${to}T00:00:00Z`;
        Promise.all([
            api(`/clinics/${clinic.id}/analytics/overview${query}`),
            api(`/clinics/${clinic.id}/analytics/services${query}`),
            api(`/clinics/${clinic.id}/analytics/doctors${query}`),
        ])
            .then(([overview, sv, dr]) => {
                if (active) {
                    setData(overview.data);
                    setServices(sv.data);
                    setDoctors(dr.data);
                    setError("");
                }
            })
            .catch((e) => {
                if (active) setError(err(e));
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [clinic.id, from, to, revision]);
    const revenue = new Map<string, number>();
    data?.payments.forEach((payment: Row) =>
        revenue.set(
            payment.currency,
            (revenue.get(payment.currency) || 0) + Number(payment.amount_minor),
        ),
    );
    const distribution = data
        ? [
              {
                  name: "Відбулися",
                  count: Number(data.completed),
                  className: "completed",
              },
              {
                  name: "Заплановані",
                  count:
                      Number(data.total) -
                      Number(data.completed) -
                      Number(data.cancelled) -
                      Number(data.no_show),
                  className: "confirmed",
              },
              {
                  name: "Скасовані",
                  count: Number(data.cancelled),
                  className: "cancelled",
              },
              {
                  name: "Неявки",
                  count: Number(data.no_show),
                  className: "no_show",
              },
          ]
        : [];
    return (
        <>
            <div className="page-heading">
                <div>
                    <span className="section-context">
                        {mode === "overview"
                            ? "Пульс клініки"
                            : "Фінанси та робота команди"}
                    </span>
                    <h1>
                        {mode === "overview"
                            ? "Клініка в деталях"
                            : "Результати роботи"}
                    </h1>
                    <p>
                        {mode === "overview"
                            ? "Що відбувається сьогодні та на що варто звернути увагу."
                            : "Від прийомів до оплат — дані, які допомагають планувати."}
                    </p>
                </div>
                <button
                    className="secondary"
                    onClick={() =>
                        download(
                            `/clinics/${clinic.id}/reports/appointments.csv?from=${from}T00:00:00Z&to=${to}T00:00:00Z`,
                        ).catch((e) => setError(err(e)))
                    }
                >
                    <Download size={17} aria-hidden="true" />
                    Експорт CSV
                </button>
            </div>
            <div className="report-period">
                <span>Період огляду</span>
                <label>
                    <span className="sr-only">Від</span>
                    <input
                        aria-label="Початок періоду"
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                    />
                </label>
                <span>—</span>
                <label>
                    <span className="sr-only">До, не включно</span>
                    <input
                        aria-label="Кінець періоду, не включно"
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                    />
                </label>
                <button
                    className="text-button"
                    onClick={() => {
                        setFrom(dateKey(new Date()));
                        setTo(dateKey(addDays(new Date(), 1)));
                    }}
                >
                    Сьогодні
                </button>
                {loading && (
                    <RefreshCw
                        className="spin"
                        size={17}
                        aria-label="Оновлюємо звіт"
                    />
                )}
            </div>
            {error && (
                <div className="error" role="alert">
                    {error}
                </div>
            )}
            {!data && loading ? (
                <div className="loading" role="status">
                    Готуємо огляд…
                </div>
            ) : (
                data && (
                    <>
                        <div className="insight-band">
                            <div className="insight-lead">
                                <span>Прийомів у плані</span>
                                <strong>{data.total}</strong>
                                <p>
                                    {data.completed} завершено за обраний період
                                </p>
                            </div>
                            <div className="insight-distribution">
                                <div
                                    className="distribution-bar"
                                    role="img"
                                    aria-label={distribution
                                        .map(
                                            (item) =>
                                                `${item.name}: ${item.count}`,
                                        )
                                        .join(", ")}
                                >
                                    {distribution
                                        .filter((item) => item.count > 0)
                                        .map((item) => (
                                            <span
                                                className={item.className}
                                                key={item.className}
                                                style={{ flex: item.count }}
                                            />
                                        ))}
                                </div>
                                <div className="distribution-legend">
                                    {distribution.map((item) => (
                                        <span key={item.className}>
                                            <i
                                                className={
                                                    "status-dot " +
                                                    item.className
                                                }
                                            />
                                            {item.name}
                                            <strong>{item.count}</strong>
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <div className="insight-created">
                                <span>Нових записів створено</span>
                                <strong>{data.created_count}</strong>
                                <small>за датою створення</small>
                            </div>
                        </div>
                        <div className="report-grid">
                            <section className="revenue-section">
                                <div className="section-heading">
                                    <div>
                                        <span className="section-context">
                                            Фінансовий результат
                                        </span>
                                        <h2>Фактично отримані оплати</h2>
                                    </div>
                                    <span className="quiet-icon">
                                        <CreditCard
                                            size={21}
                                            aria-hidden="true"
                                        />
                                    </span>
                                </div>
                                <div className="revenue-total">
                                    {revenue.size ? (
                                        Array.from(revenue.entries()).map(
                                            ([currency, amount]) => (
                                                <strong key={currency}>
                                                    {money(amount, currency)}
                                                </strong>
                                            ),
                                        )
                                    ) : (
                                        <strong>
                                            {money(0, clinic.currency)}
                                        </strong>
                                    )}
                                    <span>Отримано за обраний період</span>
                                </div>
                                <div className="revenue-methods">
                                    {data.payments.length ? (
                                        data.payments.map((payment: Row) => (
                                            <div
                                                key={
                                                    payment.currency +
                                                    payment.method
                                                }
                                            >
                                                <span>
                                                    <ArrowDownRight
                                                        size={16}
                                                        aria-hidden="true"
                                                    />
                                                    {methods[payment.method]}
                                                </span>
                                                <strong>
                                                    {money(
                                                        Number(
                                                            payment.amount_minor,
                                                        ),
                                                        payment.currency,
                                                    )}
                                                </strong>
                                            </div>
                                        ))
                                    ) : (
                                        <p>За цей період оплат ще не було.</p>
                                    )}
                                </div>
                                <div className="expected-revenue">
                                    <span>Планова вартість прийомів</span>
                                    {data.expected_by_currency.length ? (
                                        data.expected_by_currency.map(
                                            (item: Row) => (
                                                <strong key={item.currency}>
                                                    {money(
                                                        Number(
                                                            item.amount_minor,
                                                        ),
                                                        item.currency,
                                                    )}
                                                </strong>
                                            ),
                                        )
                                    ) : (
                                        <strong>
                                            {money(0, clinic.currency)}
                                        </strong>
                                    )}
                                </div>
                                <p className="report-footnote">
                                    Скасовані прийоми не входять до планової
                                    вартості. Фактичні оплати враховують
                                    сторнування за датою платежу.
                                </p>
                            </section>
                            <section className="service-demand">
                                <div className="section-heading">
                                    <div>
                                        <span className="section-context">
                                            Зацікавленість пацієнтів
                                        </span>
                                        <h2>Популярні послуги</h2>
                                    </div>
                                    <ArrowUpRight
                                        size={21}
                                        aria-hidden="true"
                                    />
                                </div>
                                {services.length ? (
                                    <ol className="ranked-services">
                                        {services.slice(0, 6).map((service) => (
                                            <li
                                                key={
                                                    service.service_id +
                                                    service.name
                                                }
                                            >
                                                <div>
                                                    <strong>
                                                        {service.name}
                                                    </strong>
                                                    <span>
                                                        {service.appointments}{" "}
                                                        прийомів
                                                    </span>
                                                </div>
                                                <div className="bar-track">
                                                    <span
                                                        style={{
                                                            width:
                                                                Math.max(
                                                                    2,
                                                                    (Number(
                                                                        service.appointments,
                                                                    ) /
                                                                        Math.max(
                                                                            1,
                                                                            ...services.map(
                                                                                (
                                                                                    s,
                                                                                ) =>
                                                                                    Number(
                                                                                        s.appointments,
                                                                                    ),
                                                                            ),
                                                                        )) *
                                                                        100,
                                                                ) + "%",
                                                        }}
                                                    />
                                                </div>
                                            </li>
                                        ))}
                                    </ol>
                                ) : (
                                    <p className="report-empty">
                                        Послуги з’являться тут після створення
                                        прийомів.
                                    </p>
                                )}
                            </section>
                        </div>
                        <section className="team-workload">
                            <div className="section-heading">
                                <div>
                                    <span className="section-context">
                                        Ритм роботи
                                    </span>
                                    <h2>Завантаженість команди</h2>
                                </div>
                                <span>{doctors.length} спеціалістів</span>
                            </div>
                            <div className="workload-rows">
                                {doctors.map((doctor) => (
                                    <div key={doctor.id}>
                                        <span
                                            className="doctor-monogram"
                                            aria-hidden="true"
                                        >
                                            {doctor.name
                                                .split(" ")
                                                .slice(0, 2)
                                                .map((s: string) => s[0])
                                                .join("")}
                                        </span>
                                        <div className="workload-person">
                                            <strong>{doctor.name}</strong>
                                            <small>
                                                {doctor.appointments} прийомів ·{" "}
                                                {Math.round(
                                                    Number(
                                                        doctor.booked_minutes,
                                                    ),
                                                )}{" "}
                                                хв зайнято
                                            </small>
                                        </div>
                                        <div className="workload-bar">
                                            <div className="bar-track">
                                                <span
                                                    style={{
                                                        width:
                                                            Math.min(
                                                                100,
                                                                Number(
                                                                    doctor.utilization_percent,
                                                                ),
                                                            ) + "%",
                                                    }}
                                                />
                                            </div>
                                            <small>
                                                {doctor.available_minutes} хв у
                                                графіку
                                            </small>
                                        </div>
                                        <strong>
                                            {doctor.utilization_percent}%
                                        </strong>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </>
                )
            )}
        </>
    );
}
