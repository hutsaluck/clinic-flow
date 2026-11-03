import { useCallback, useEffect, useRef, useState } from "react";
import {
    ArrowDown,
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock3,
    CreditCard,
    FileClock,
    ListFilter,
    Plus,
    RefreshCw,
    Search,
    Stethoscope,
} from "lucide-react";
import { api, type Row } from "../api";
import { useUrlValue } from "../app/navigation";
import { useConfirmation } from "../shared/confirmation";
import {
    addDays,
    Badge,
    dateKey,
    Empty,
    err,
    fullName,
    localDay,
    methods,
    Modal,
    money,
    statuses,
    time,
} from "../shared/ui";

const weekStart = (date: Date) => addDays(date, -((date.getDay() + 6) % 7));
const initials = (name: string) =>
    (name || "")
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((s) => s[0])
        .join("");
const dateFrom = (value: string) => {
    const d = new Date(value + "T12:00:00");
    return /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(d.getTime())
        ? d
        : new Date();
};

function MiniMonth({
    date,
    onDate,
}: {
    date: Date;
    onDate: (value: Date) => void;
}) {
    const [month, setMonth] = useState(
        new Date(date.getFullYear(), date.getMonth(), 1),
    );
    useEffect(
        () => setMonth(new Date(date.getFullYear(), date.getMonth(), 1)),
        [date.getFullYear(), date.getMonth()],
    );
    const first = weekStart(month),
        today = dateKey(new Date());
    return (
        <section className="mini-month" aria-label="Календар місяця">
            <div className="mini-month-heading">
                <h2>
                    {month.toLocaleDateString("uk-UA", {
                        month: "long",
                        year: "numeric",
                    })}
                </h2>
                <div>
                    <button
                        className="icon-button"
                        aria-label="Попередній місяць"
                        onClick={() =>
                            setMonth(
                                new Date(
                                    month.getFullYear(),
                                    month.getMonth() - 1,
                                    1,
                                ),
                            )
                        }
                    >
                        <ChevronLeft size={16} />
                    </button>
                    <button
                        className="icon-button"
                        aria-label="Наступний місяць"
                        onClick={() =>
                            setMonth(
                                new Date(
                                    month.getFullYear(),
                                    month.getMonth() + 1,
                                    1,
                                ),
                            )
                        }
                    >
                        <ChevronRight size={16} />
                    </button>
                </div>
            </div>
            <div className="mini-month-grid">
                {["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Нд"].map((day) => (
                    <span className="weekday" key={day}>
                        {day}
                    </span>
                ))}
                {Array.from({ length: 42 }, (_, index) => {
                    const day = addDays(first, index),
                        key = dateKey(day),
                        selected = key === dateKey(date);
                    return (
                        <button
                            key={key}
                            aria-label={day.toLocaleDateString("uk-UA", {
                                day: "numeric",
                                month: "long",
                                year: "numeric",
                            })}
                            aria-pressed={selected}
                            className={[
                                selected ? "is-selected" : "",
                                day.getMonth() !== month.getMonth()
                                    ? "outside-month"
                                    : "",
                                key === today ? "is-today" : "",
                            ].join(" ")}
                            onClick={() => onDate(day)}
                        >
                            {day.getDate()}
                        </button>
                    );
                })}
            </div>
        </section>
    );
}

function AgendaEntry({
    visit,
    timezone,
    onOpen,
}: {
    visit: Row;
    timezone: string;
    onOpen: (visit: Row) => void;
}) {
    return (
        <button
            className={"agenda-entry state-" + visit.status.toLowerCase()}
            onClick={() => onOpen(visit)}
            aria-label={`${visit.patient_name}, ${time(visit.starts_at, timezone)}, ${statuses[visit.status]}`}
        >
            <span className="patient-monogram" aria-hidden="true">
                {initials(visit.patient_name)}
            </span>
            <span className="agenda-patient">
                <strong>{visit.patient_name}</strong>
                <small>
                    {visit.service_name_snapshot}
                    <span>{visit.duration_minutes_snapshot} хв</span>
                </small>
            </span>
            <span className="agenda-doctor">
                <span>{visit.doctor_name}</span>
                <small>до {time(visit.ends_at, timezone)}</small>
            </span>
            <Badge status={visit.status} />
            <span className="entry-arrow" aria-hidden="true">
                <ArrowUpRight size={17} />
            </span>
        </button>
    );
}

export function CalendarPage({
    clinic,
    doctor,
    revision,
    notify,
}: {
    clinic: Row;
    doctor: boolean;
    revision: number;
    notify: (message?: string) => void;
}) {
    const today = localDay(new Date().toISOString(), clinic.timezone),
        [dateValue, setDateValue] = useUrlValue("date", today),
        [rawView, setView] = useUrlValue("view", "day"),
        [filter, setFilter] = useUrlValue("doctor", ""),
        [status, setStatus] = useUrlValue("status", "");
    const date = dateFrom(dateValue),
        view = ["day", "week", "list"].includes(rawView) ? rawView : "day",
        selectedDay = dateKey(date),
        rangeStart = view === "week" ? dateKey(weekStart(date)) : selectedDay;
    const [doctors, setDoctors] = useState<Row[]>([]),
        [rows, setRows] = useState<Row[]>([]),
        [loading, setLoading] = useState(true),
        [error, setError] = useState(""),
        [booking, setBooking] = useState(false),
        [detail, setDetail] = useState<Row | null>(null),
        [limit, setLimit] = useState(12);
    const requestId = useRef(0),
        setDate = (next: Date) => setDateValue(dateKey(next));
    const load = useCallback(async () => {
        const request = ++requestId.current;
        setLoading(true);
        setError("");
        try {
            const end = dateKey(
                addDays(dateFrom(rangeStart), view === "week" ? 7 : 1),
            );
            const path = `/clinics/${clinic.id}/appointments?per_page=100&from=${rangeStart}&to=${end}${filter ? "&doctor_id=" + filter : ""}${status ? "&status=" + status : ""}`;
            const [a, d] = await Promise.all([
                api(path),
                api(`/clinics/${clinic.id}/doctors?per_page=100&archived=1`),
            ]);
            const all = [...a.data];
            if (a.meta.total_pages > 1) {
                const rest = await Promise.all(
                    Array.from({ length: a.meta.total_pages - 1 }, (_, i) =>
                        api(path + "&page=" + (i + 2)),
                    ),
                );
                rest.forEach((r) => all.push(...r.data));
            }
            if (request !== requestId.current) return;
            setRows(all);
            setDoctors(d.data);
        } catch (e) {
            if (request === requestId.current) setError(err(e));
        } finally {
            if (request === requestId.current) setLoading(false);
        }
    }, [clinic.id, rangeStart, view, filter, status]);
    useEffect(() => {
        void load();
    }, [load, revision]);
    useEffect(() => setLimit(12), [selectedDay, filter, status, view]);
    const shown = rows.filter(
            (a) =>
                view === "week" ||
                localDay(a.starts_at, clinic.timezone) === selectedDay,
        ),
        visible = shown.slice(0, limit),
        groups = new Map<string, Row[]>();
    visible.forEach((a) => {
        const key = time(a.starts_at, clinic.timezone);
        groups.set(key, [...(groups.get(key) || []), a]);
    });
    const week = Array.from({ length: 7 }, (_, i) =>
        addDays(weekStart(date), i),
    );
    const availableDoctors = doctor
        ? doctors.filter((d) => d.membership_id === clinic.membership_id)
        : doctors;
    const counts = {
        total: shown.length,
        pending: shown.filter((a) => a.status === "SCHEDULED").length,
        confirmed: shown.filter((a) => a.status === "CONFIRMED").length,
        completed: shown.filter((a) => a.status === "COMPLETED").length,
    };
    return (
        <>
            <div className="page-heading agenda-heading">
                <div>
                    <span className="section-context">
                        Робочий день клініки
                    </span>
                    <h1>План прийомів</h1>
                    <p>
                        Усі зустрічі, пацієнти та наступні кроки — перед вами.
                    </p>
                </div>
                {!doctor && (
                    <button
                        className="primary"
                        onClick={() => setBooking(true)}
                    >
                        <Plus size={18} aria-hidden="true" />
                        Новий запис
                    </button>
                )}
            </div>
            <div className="planning-toolbar">
                <div className="date-control">
                    <button
                        className="icon-button"
                        aria-label="Попередня дата"
                        onClick={() =>
                            setDate(addDays(date, view === "week" ? -7 : -1))
                        }
                    >
                        <ChevronLeft size={18} />
                    </button>
                    <div className="selected-date">
                        <strong>
                            {date.toLocaleDateString("uk-UA", {
                                day: "numeric",
                                month: "long",
                            })}
                        </strong>
                        <span>
                            {date.toLocaleDateString("uk-UA", {
                                weekday: "long",
                                year: "numeric",
                            })}
                        </span>
                        <input
                            aria-label="Дата календаря"
                            type="date"
                            value={selectedDay}
                            onChange={(e) => {
                                if (e.target.value)
                                    setDateValue(e.target.value);
                            }}
                        />
                    </div>
                    <button
                        className="icon-button"
                        aria-label="Наступна дата"
                        onClick={() =>
                            setDate(addDays(date, view === "week" ? 7 : 1))
                        }
                    >
                        <ChevronRight size={18} />
                    </button>
                    <button
                        className="today-button"
                        onClick={() => setDateValue(today)}
                    >
                        Сьогодні
                    </button>
                </div>
                <div className="planning-filters">
                    <label className="filter-control">
                        <Stethoscope size={15} aria-hidden="true" />
                        <select
                            aria-label="Фільтр за лікарем"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        >
                            <option value="">Уся команда</option>
                            {availableDoctors.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {fullName(d)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="filter-control">
                        <ListFilter size={15} aria-hidden="true" />
                        <select
                            aria-label="Фільтр за статусом"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                        >
                            <option value="">Усі статуси</option>
                            {Object.entries(statuses).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {String(label)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="view-switch" aria-label="Вигляд плану">
                        {[
                            ["day", "День"],
                            ["week", "Тиждень"],
                            ["list", "Список"],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                aria-pressed={view === key}
                                className={view === key ? "selected" : ""}
                                onClick={() => setView(key)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
            <div className="day-strip" aria-label="Дні тижня">
                {week.map((day) => (
                    <button
                        key={dateKey(day)}
                        className={
                            dateKey(day) === selectedDay ? "selected" : ""
                        }
                        aria-pressed={dateKey(day) === selectedDay}
                        onClick={() => setDate(day)}
                    >
                        <span>
                            {day.toLocaleDateString("uk-UA", {
                                weekday: "short",
                            })}
                        </span>
                        <strong>{day.getDate()}</strong>
                        {dateKey(day) === today && <i aria-label="сьогодні" />}
                    </button>
                ))}
            </div>
            {error && (
                <div className="error" role="alert">
                    {error}
                    <button onClick={() => void load()}>Оновити план</button>
                </div>
            )}
            <div className={"planning-workspace view-" + view}>
                <aside className="day-context">
                    <MiniMonth date={date} onDate={setDate} />
                    <section className="context-team">
                        <div className="context-section-title">
                            <h2>Команда</h2>
                            <span>{availableDoctors.length}</span>
                        </div>
                        <button
                            className={
                                "team-filter " + (!filter ? "selected" : "")
                            }
                            onClick={() => setFilter("")}
                        >
                            <span className="team-all">
                                <Stethoscope size={16} aria-hidden="true" />
                            </span>
                            <span>Усі лікарі</span>
                        </button>
                        {availableDoctors.map((d) => (
                            <button
                                key={d.id}
                                className={
                                    "team-filter " +
                                    (filter === d.id ? "selected" : "")
                                }
                                onClick={() =>
                                    setFilter(filter === d.id ? "" : d.id)
                                }
                            >
                                <span
                                    className="doctor-monogram"
                                    aria-hidden="true"
                                >
                                    {initials(fullName(d))}
                                </span>
                                <span>
                                    <strong>{fullName(d)}</strong>
                                    <small>{d.specialization}</small>
                                </span>
                                <i>
                                    {
                                        shown.filter(
                                            (a) => a.doctor_id === d.id,
                                        ).length
                                    }
                                </i>
                            </button>
                        ))}
                    </section>
                    <div className="context-note">
                        <Clock3 size={15} aria-hidden="true" />
                        <span>
                            Час клініки<strong>{clinic.timezone}</strong>
                        </span>
                    </div>
                </aside>
                <section className="day-journal" aria-label="Журнал прийомів">
                    <div className="journal-summary">
                        <div>
                            <h2>
                                {view === "week"
                                    ? "Цього тижня"
                                    : view === "list"
                                      ? "Реєстр прийомів"
                                      : "Записи на день"}
                                <span>{counts.total}</span>
                            </h2>
                            <p>
                                {counts.pending > 0
                                    ? `${counts.pending} очікують підтвердження`
                                    : "Прийоми за вибраний період"}
                            </p>
                        </div>
                        <div className="journal-completed">
                            <span className="status-dot completed" />
                            <strong>{counts.completed}</strong> завершено
                        </div>
                        <button
                            className="icon-button"
                            aria-label="Оновити план"
                            onClick={() => void load()}
                        >
                            <RefreshCw
                                size={17}
                                className={loading ? "spin" : ""}
                            />
                        </button>
                    </div>
                    {loading ? (
                        <div className="journal-loading" role="status">
                            <RefreshCw
                                size={20}
                                className="spin"
                                aria-hidden="true"
                            />
                            <span>Оновлюємо робочий день…</span>
                            <div />
                            <div />
                            <div />
                        </div>
                    ) : view === "list" ? (
                        <AppointmentTable
                            rows={shown}
                            timezone={clinic.timezone}
                            onClick={setDetail}
                        />
                    ) : view === "week" ? (
                        <div className="week-board">
                            {week.map((day) => {
                                const daily = shown.filter(
                                    (a) =>
                                        localDay(
                                            a.starts_at,
                                            clinic.timezone,
                                        ) === dateKey(day),
                                );
                                return (
                                    <section
                                        className={
                                            "week-day " +
                                            (dateKey(day) === today
                                                ? "is-today"
                                                : "")
                                        }
                                        key={dateKey(day)}
                                    >
                                        <button
                                            className="week-day-heading"
                                            onClick={() => {
                                                setDate(day);
                                                setView("day");
                                            }}
                                        >
                                            <span>
                                                {day.toLocaleDateString(
                                                    "uk-UA",
                                                    { weekday: "short" },
                                                )}
                                            </span>
                                            <strong>{day.getDate()}</strong>
                                            <small>
                                                {daily.length} прийомів
                                            </small>
                                        </button>
                                        <div className="week-day-entries">
                                            {daily.slice(0, 6).map((a) => (
                                                <button
                                                    className="week-entry"
                                                    key={a.id}
                                                    onClick={() => setDetail(a)}
                                                >
                                                    <time>
                                                        {time(
                                                            a.starts_at,
                                                            clinic.timezone,
                                                        )}
                                                    </time>
                                                    <strong>
                                                        {a.patient_name}
                                                    </strong>
                                                    <small>
                                                        {a.doctor_name}
                                                    </small>
                                                    <span
                                                        className={
                                                            "week-status " +
                                                            a.status.toLowerCase()
                                                        }
                                                    >
                                                        {statuses[a.status]}
                                                    </span>
                                                </button>
                                            ))}
                                            {daily.length === 0 && (
                                                <p className="week-empty">
                                                    Без записів
                                                </p>
                                            )}
                                            {daily.length > 6 && (
                                                <button
                                                    className="text-button"
                                                    onClick={() => {
                                                        setDate(day);
                                                        setView("day");
                                                    }}
                                                >
                                                    Ще {daily.length - 6}
                                                    <ArrowRight
                                                        size={14}
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            )}
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    ) : shown.length ? (
                        <>
                            <div className="agenda-groups">
                                {Array.from(groups.entries()).map(
                                    ([start, appointments]) => (
                                        <section
                                            className="agenda-time-group"
                                            key={start}
                                        >
                                            <div className="time-anchor">
                                                <time>{start}</time>
                                                <span>
                                                    {appointments.length}{" "}
                                                    {appointments.length === 1
                                                        ? "прийом"
                                                        : "прийоми"}
                                                </span>
                                            </div>
                                            <div className="time-entries">
                                                {appointments.map((a) => (
                                                    <AgendaEntry
                                                        key={a.id}
                                                        visit={a}
                                                        timezone={
                                                            clinic.timezone
                                                        }
                                                        onOpen={setDetail}
                                                    />
                                                ))}
                                            </div>
                                        </section>
                                    ),
                                )}
                            </div>
                            {limit < shown.length && (
                                <div className="journal-more">
                                    <span>
                                        Показано {visible.length} із{" "}
                                        {shown.length}
                                    </span>
                                    <button
                                        className="secondary"
                                        onClick={() => setLimit((n) => n + 12)}
                                    >
                                        Наступні{" "}
                                        {Math.min(12, shown.length - limit)}{" "}
                                        записів
                                        <ArrowDown
                                            size={15}
                                            aria-hidden="true"
                                        />
                                    </button>
                                </div>
                            )}
                        </>
                    ) : (
                        <Empty
                            text="День відкритий для нових зустрічей"
                            description="Додайте прийом або оберіть іншу дату в календарі."
                        />
                    )}
                    <div className="journal-footer">
                        <span>
                            Підтверджено <strong>{counts.confirmed}</strong>
                        </span>
                        <span>
                            Очікують <strong>{counts.pending}</strong>
                        </span>
                        <span>
                            Завершено <strong>{counts.completed}</strong>
                        </span>
                    </div>
                </section>
            </div>
            {booking && (
                <Booking
                    clinic={clinic}
                    initialDate={selectedDay}
                    initialDoctor={filter}
                    onClose={() => setBooking(false)}
                    onSave={() => {
                        setBooking(false);
                        notify("Пацієнта успішно записано");
                    }}
                />
            )}
            {detail && (
                <Visit
                    clinic={clinic}
                    visit={detail}
                    doctor={doctor}
                    onClose={() => setDetail(null)}
                    onSave={() => {
                        setDetail(null);
                        notify();
                    }}
                />
            )}
        </>
    );
}

export function AppointmentTable({
    rows,
    timezone,
    onClick,
}: {
    rows: Row[];
    timezone: string;
    onClick: (row: Row) => void;
}) {
    return rows.length ? (
        <div className="table-wrap appointment-register">
            <table>
                <thead>
                    <tr>
                        <th>Дата та час</th>
                        <th>Пацієнт</th>
                        <th>Лікар та послуга</th>
                        <th>Стан прийому</th>
                        <th>Вартість</th>
                        <th>
                            <span className="sr-only">Відкрити</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((a) => (
                        <tr
                            key={a.id}
                            onClick={() => onClick(a)}
                            tabIndex={0}
                            onKeyDown={(e) => {
                                if (e.key === "Enter" || e.key === " ") {
                                    e.preventDefault();
                                    onClick(a);
                                }
                            }}
                        >
                            <td>
                                <strong>{time(a.starts_at, timezone)}</strong>
                                <small>
                                    {new Date(a.starts_at).toLocaleDateString(
                                        "uk-UA",
                                        {
                                            day: "numeric",
                                            month: "short",
                                            timeZone: timezone,
                                        },
                                    )}
                                </small>
                            </td>
                            <td>
                                <div className="person-cell">
                                    <span
                                        className="patient-monogram"
                                        aria-hidden="true"
                                    >
                                        {initials(a.patient_name)}
                                    </span>
                                    <strong>{a.patient_name}</strong>
                                </div>
                            </td>
                            <td>
                                {a.doctor_name}
                                <small>{a.service_name_snapshot}</small>
                            </td>
                            <td>
                                <Badge status={a.status} />
                            </td>
                            <td>
                                {money(
                                    Number(a.price_minor_snapshot),
                                    a.currency_snapshot,
                                )}
                            </td>
                            <td>
                                <ArrowUpRight size={16} aria-hidden="true" />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    ) : (
        <Empty text="Записів за цей період немає" />
    );
}

export function Booking({
    clinic,
    onClose,
    onSave,
    existing,
    initialDate,
    initialDoctor,
    initialPatient,
}: {
    clinic: Row;
    onClose: () => void;
    onSave: () => void;
    existing?: Row;
    initialDate?: string;
    initialDoctor?: string;
    initialPatient?: Row;
}) {
    const today = localDay(new Date().toISOString(), clinic.timezone);
    const [patients, setPatients] = useState<Row[]>(
            initialPatient ? [initialPatient] : [],
        ),
        [doctors, setDoctors] = useState<Row[]>([]),
        [services, setServices] = useState<Row[]>([]),
        [doctorServices, setDoctorServices] = useState<string[]>([]),
        [patient, setPatient] = useState(
            existing?.patient_id || initialPatient?.id || "",
        ),
        [doctor, setDoctor] = useState(
            existing?.doctor_id || initialDoctor || "",
        ),
        [service, setService] = useState(existing?.service_id || ""),
        [date, setDate] = useState(() => {
            const day = existing
                ? localDay(existing.starts_at, clinic.timezone)
                : initialDate || dateKey(addDays(new Date(), 1));
            return day < today ? today : day;
        }),
        [slots, setSlots] = useState<Row[]>([]),
        [slot, setSlot] = useState(""),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [loading, setLoading] = useState(false),
        [query, setQuery] = useState(""),
        [key] = useState(crypto.randomUUID());
    useEffect(() => {
        let active = true;
        Promise.all([
            api(`/clinics/${clinic.id}/doctors?per_page=100`),
            api(`/clinics/${clinic.id}/services?per_page=100`),
        ])
            .then(([d, s]) => {
                if (active) {
                    setDoctors(d.data);
                    setServices(s.data);
                }
            })
            .catch((e) => {
                if (active) setError(err(e));
            });
        return () => {
            active = false;
        };
    }, [clinic.id]);
    useEffect(() => {
        if (existing) return;
        let active = true;
        const timer = setTimeout(() => {
            api(
                `/clinics/${clinic.id}/patients?per_page=100&search=${encodeURIComponent(query)}`,
            )
                .then((r) => {
                    if (active)
                        setPatients(
                            initialPatient &&
                                !r.data.some(
                                    (patient: Row) =>
                                        patient.id === initialPatient.id,
                                )
                                ? [initialPatient, ...r.data]
                                : r.data,
                        );
                })
                .catch((e) => {
                    if (active) setError(err(e));
                });
        }, 200);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [clinic.id, query, existing, initialPatient]);
    useEffect(() => {
        if (!doctor) return;
        let active = true;
        api(`/clinics/${clinic.id}/doctors/${doctor}`)
            .then((r) => {
                if (active) setDoctorServices(r.data.service_ids);
            })
            .catch((e) => {
                if (active) setError(err(e));
            });
        return () => {
            active = false;
        };
    }, [clinic.id, doctor]);
    useEffect(() => {
        setSlot("");
        setSlots([]);
        if (!doctor || !service || !date) return;
        let active = true;
        setLoading(true);
        setError("");
        api(
            `/clinics/${clinic.id}/availability?doctor_id=${doctor}&service_id=${service}&date=${date}${existing ? "&appointment_id=" + existing.id : ""}`,
        )
            .then((r) => {
                if (active) setSlots(r.slots);
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
    }, [clinic.id, doctor, service, date, existing]);
    const selectedService = services.find((s) => s.id === service),
        selectedPatient = patients.find((p) => p.id === patient),
        selectedDoctor = doctors.find((d) => d.id === doctor),
        price = Number(
            existing?.price_minor_snapshot ?? selectedService?.price_minor ?? 0,
        ),
        currency =
            existing?.currency_snapshot ||
            selectedService?.currency ||
            clinic.currency;
    return (
        <Modal
            title={existing ? "Перенести прийом" : "Новий запис"}
            onClose={onClose}
            variant="workspace"
            wide
        >
            <form
                className="booking-workspace"
                onSubmit={async (e) => {
                    e.preventDefault();
                    if (!slot) return;
                    setBusy(true);
                    setError("");
                    try {
                        if (existing)
                            await api(
                                `/clinics/${clinic.id}/appointments/${existing.id}/reschedule`,
                                "POST",
                                {
                                    starts_at: slot,
                                    version: Number(existing.version),
                                },
                            );
                        else
                            await api(
                                `/clinics/${clinic.id}/appointments`,
                                "POST",
                                {
                                    patient_id: patient,
                                    doctor_id: doctor,
                                    service_id: service,
                                    starts_at: slot,
                                },
                                key,
                            );
                        onSave();
                    } catch (e) {
                        setError(err(e));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <div className="booking-columns">
                    <section className="booking-person">
                        <div className="form-step-heading">
                            <span>1</span>
                            <div>
                                <h3>Пацієнт і команда</h3>
                                <p>Кого та до кого записуємо</p>
                            </div>
                        </div>
                        {!existing ? (
                            <>
                                <label>
                                    Знайти пацієнта
                                    <div className="input-with-icon">
                                        <Search size={17} aria-hidden="true" />
                                        <input
                                            name="patient-search"
                                            autoComplete="off"
                                            placeholder="Ім’я, прізвище або контакт…"
                                            value={query}
                                            onChange={(e) =>
                                                setQuery(e.target.value)
                                            }
                                        />
                                    </div>
                                </label>
                                <label>
                                    Пацієнт
                                    <select
                                        aria-label="Пацієнт"
                                        required
                                        value={patient}
                                        onChange={(e) =>
                                            setPatient(e.target.value)
                                        }
                                    >
                                        <option value="">
                                            Оберіть пацієнта
                                        </option>
                                        {patients.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {fullName(p)} ·{" "}
                                                {p.phone_e164 || p.email}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </>
                        ) : (
                            <div className="reschedule-patient">
                                <span className="patient-monogram">
                                    {initials(existing.patient_name)}
                                </span>
                                <strong>{existing.patient_name}</strong>
                            </div>
                        )}
                        <label>
                            Лікар
                            <select
                                aria-label="Лікар"
                                required
                                disabled={!!existing}
                                value={doctor}
                                onChange={(e) => {
                                    setDoctor(e.target.value);
                                    setService("");
                                }}
                            >
                                <option value="">Оберіть лікаря</option>
                                {doctors.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {fullName(d)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Послуга
                            <select
                                aria-label="Послуга"
                                required
                                disabled={!!existing || !doctor}
                                value={service}
                                onChange={(e) => setService(e.target.value)}
                            >
                                <option value="">Оберіть послугу</option>
                                {services
                                    .filter((s) =>
                                        doctorServices.includes(s.id),
                                    )
                                    .map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} · {s.duration_minutes} хв
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <p className="booking-help">
                            Доступний час враховує розклад лікаря, перерви та
                            вже створені прийоми.
                        </p>
                    </section>
                    <section className="booking-time">
                        <div className="form-step-heading">
                            <span>2</span>
                            <div>
                                <h3>Зручний час</h3>
                                <p>{clinic.timezone}</p>
                            </div>
                        </div>
                        <label>
                            Дата прийому
                            <input
                                type="date"
                                required
                                min={today}
                                value={date}
                                onChange={(e) => setDate(e.target.value)}
                            />
                        </label>
                        <div className="slot-heading">
                            <strong>Вільні години</strong>
                            {slots.length > 0 && (
                                <span>{slots.length} варіантів</span>
                            )}
                        </div>
                        {loading ? (
                            <div className="slots-loading" role="status">
                                <RefreshCw
                                    size={18}
                                    className="spin"
                                    aria-hidden="true"
                                />
                                Перевіряємо доступність…
                            </div>
                        ) : (
                            <div className="slots">
                                {slots.map((s) => (
                                    <button
                                        type="button"
                                        key={s.starts_at}
                                        aria-pressed={slot === s.starts_at}
                                        className={
                                            slot === s.starts_at ? "chosen" : ""
                                        }
                                        onClick={() => setSlot(s.starts_at)}
                                    >
                                        {time(s.starts_at, clinic.timezone)}
                                        <small>{s.local_start.slice(-6)}</small>
                                        {slot === s.starts_at && (
                                            <Check
                                                size={12}
                                                aria-hidden="true"
                                            />
                                        )}
                                    </button>
                                ))}
                                {!slots.length && (
                                    <div className="slots-empty">
                                        <Clock3 size={28} aria-hidden="true" />
                                        <p>
                                            {doctor && service
                                                ? "На цю дату немає вільного часу."
                                                : "Оберіть лікаря та послугу — покажемо доступні години."}
                                        </p>
                                        {doctor && service && (
                                            <button
                                                type="button"
                                                className="text-button"
                                                onClick={() =>
                                                    setDate(
                                                        dateKey(
                                                            addDays(
                                                                dateFrom(date),
                                                                1,
                                                            ),
                                                        ),
                                                    )
                                                }
                                            >
                                                Перевірити наступний день
                                                <ArrowRight
                                                    size={15}
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </section>
                </div>
                <div className="booking-recap">
                    <div>
                        <span>Ваш запис</span>
                        <strong>
                            {selectedPatient
                                ? fullName(selectedPatient)
                                : existing?.patient_name || "Оберіть пацієнта"}
                        </strong>
                        <small>
                            {selectedDoctor
                                ? fullName(selectedDoctor)
                                : "Лікаря ще не обрано"}
                            {slot ? " · " + time(slot, clinic.timezone) : ""}
                        </small>
                    </div>
                    <div className="recap-price">
                        <span>
                            {existing?.duration_minutes_snapshot ||
                                selectedService?.duration_minutes ||
                                "—"}{" "}
                            хвилин
                        </span>
                        <strong>
                            {service ? money(price, currency) : "—"}
                        </strong>
                    </div>
                </div>
                {error && (
                    <div className="error" role="alert">
                        {error}
                    </div>
                )}
                <div className="modal-actions">
                    <button
                        type="button"
                        className="secondary"
                        onClick={onClose}
                    >
                        Скасувати
                    </button>
                    <button className="primary" disabled={busy || !slot}>
                        {busy
                            ? "Зберігаємо…"
                            : existing
                              ? "Перенести прийом"
                              : "Створити запис"}
                        <ArrowRight size={17} aria-hidden="true" />
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export function Visit({
    clinic,
    visit,
    doctor,
    onClose,
    onSave,
}: {
    clinic: Row;
    visit: Row;
    doctor: boolean;
    onClose: () => void;
    onSave: () => void;
}) {
    const confirm = useConfirmation(),
        [tab, setTab] = useState("overview"),
        [events, setEvents] = useState<Row[]>([]),
        [payments, setPayments] = useState<Row[]>([]),
        [paid, setPaid] = useState(0),
        [amount, setAmount] = useState(""),
        [method, setMethod] = useState("CASH"),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [reschedule, setReschedule] = useState(false),
        [reason, setReason] = useState(""),
        [cancel, setCancel] = useState(false);
    const load = useCallback(() => {
        api(`/clinics/${clinic.id}/appointments/${visit.id}/events`)
            .then((r) => setEvents(r.data))
            .catch((e) => setError(err(e)));
        if (!doctor)
            api(`/clinics/${clinic.id}/appointments/${visit.id}/payments`)
                .then((r) => {
                    setPayments(r.data);
                    setPaid(Number(r.paid_amount));
                })
                .catch((e) => setError(err(e)));
    }, [clinic.id, visit.id, doctor]);
    useEffect(load, [load]);
    async function act(action: string) {
        setBusy(true);
        setError("");
        try {
            await api(
                `/clinics/${clinic.id}/appointments/${visit.id}/${action}`,
                "POST",
                { version: Number(visit.version), reason },
            );
            onSave();
        } catch (e) {
            setError(err(e));
        } finally {
            setBusy(false);
        }
    }
    const active = ["SCHEDULED", "CONFIRMED"].includes(visit.status),
        started = new Date(visit.starts_at) <= new Date(),
        outstanding = Number(visit.price_minor_snapshot) - paid;
    if (reschedule)
        return (
            <Booking
                clinic={clinic}
                existing={visit}
                onClose={() => setReschedule(false)}
                onSave={onSave}
            />
        );
    return (
        <Modal title="Картка прийому" onClose={onClose} variant="drawer">
            <div className="visit-profile">
                <span className="visit-monogram" aria-hidden="true">
                    {initials(visit.patient_name)}
                </span>
                <div>
                    <span className="detail-caption">Пацієнт</span>
                    <h3>{visit.patient_name}</h3>
                    <Badge status={visit.status} />
                </div>
            </div>
            <div className="visit-date-banner">
                <CalendarDays size={22} aria-hidden="true" />
                <div>
                    <strong>
                        {new Date(visit.starts_at).toLocaleDateString("uk-UA", {
                            day: "numeric",
                            month: "long",
                            year: "numeric",
                            timeZone: clinic.timezone,
                        })}
                    </strong>
                    <span>
                        {time(visit.starts_at, clinic.timezone)}–
                        {time(visit.ends_at, clinic.timezone)}
                        <small>{clinic.timezone}</small>
                    </span>
                </div>
                <span className="duration-pill">
                    {visit.duration_minutes_snapshot} хв
                </span>
            </div>
            <div
                className="detail-tabs"
                role="tablist"
                aria-label="Деталі прийому"
                onKeyDown={(event) => {
                    if (
                        !["ArrowLeft", "ArrowRight", "Home", "End"].includes(
                            event.key,
                        )
                    )
                        return;
                    event.preventDefault();
                    const buttons = Array.from(
                        event.currentTarget.querySelectorAll<HTMLButtonElement>(
                            '[role="tab"]',
                        ),
                    );
                    const index = buttons.indexOf(
                        document.activeElement as HTMLButtonElement,
                    );
                    const next =
                        event.key === "Home"
                            ? 0
                            : event.key === "End"
                              ? buttons.length - 1
                              : (index +
                                    (event.key === "ArrowRight" ? 1 : -1) +
                                    buttons.length) %
                                buttons.length;
                    buttons[next]?.click();
                    buttons[next]?.focus();
                }}
            >
                {[
                    ["overview", "Огляд"],
                    ...(!doctor ? [["payments", "Оплата"]] : []),
                    ["history", "Історія"],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        role="tab"
                        aria-selected={tab === key}
                        aria-controls="visit-panel"
                        tabIndex={tab === key ? 0 : -1}
                        id={"visit-tab-" + key}
                        onClick={() => setTab(key)}
                    >
                        {label}
                        {key === "history" && <span>{events.length}</span>}
                    </button>
                ))}
            </div>
            <section
                className="visit-tab-content"
                role="tabpanel"
                id="visit-panel"
                aria-labelledby={"visit-tab-" + tab}
            >
                {tab === "overview" && (
                    <>
                        <dl className="visit-facts">
                            <div>
                                <dt>Лікар</dt>
                                <dd>
                                    <Stethoscope size={17} aria-hidden="true" />
                                    {visit.doctor_name}
                                </dd>
                            </div>
                            <div>
                                <dt>Послуга</dt>
                                <dd>{visit.service_name_snapshot}</dd>
                            </div>
                            <div>
                                <dt>Вартість</dt>
                                <dd className="visit-cost">
                                    {money(
                                        Number(visit.price_minor_snapshot),
                                        visit.currency_snapshot,
                                    )}
                                </dd>
                            </div>
                        </dl>
                        {!doctor && (
                            <div className="invoice-overview">
                                <div>
                                    <CreditCard size={18} aria-hidden="true" />
                                    <span>
                                        {outstanding === 0
                                            ? "Прийом оплачено"
                                            : paid > 0
                                              ? "Частково оплачено"
                                              : "Оплата ще не внесена"}
                                        <small>
                                            До сплати:{" "}
                                            {money(
                                                outstanding,
                                                visit.currency_snapshot,
                                            )}
                                        </small>
                                    </span>
                                </div>
                                <button
                                    className="text-button"
                                    onClick={() => setTab("payments")}
                                >
                                    {outstanding > 0
                                        ? "Прийняти оплату"
                                        : "Переглянути"}
                                    <ArrowRight size={16} aria-hidden="true" />
                                </button>
                            </div>
                        )}
                        {active && (
                            <div className="visit-actions">
                                {!doctor && (
                                    <>
                                        {visit.status === "SCHEDULED" && (
                                            <button
                                                className="primary"
                                                disabled={busy}
                                                onClick={() =>
                                                    void act("confirm")
                                                }
                                            >
                                                <Check
                                                    size={17}
                                                    aria-hidden="true"
                                                />
                                                Підтвердити
                                            </button>
                                        )}
                                        <button
                                            className="secondary"
                                            onClick={() => setReschedule(true)}
                                        >
                                            Перенести
                                        </button>
                                    </>
                                )}
                                {started && (
                                    <>
                                        <button
                                            className="primary"
                                            disabled={busy}
                                            onClick={() => void act("complete")}
                                        >
                                            Завершити прийом
                                        </button>
                                        <button
                                            className="secondary"
                                            disabled={busy}
                                            onClick={() =>
                                                void act("mark-no-show")
                                            }
                                        >
                                            Неявка
                                        </button>
                                    </>
                                )}
                                {!doctor && (
                                    <button
                                        className="text-button danger"
                                        onClick={() => setCancel(!cancel)}
                                    >
                                        Скасувати прийом
                                    </button>
                                )}
                            </div>
                        )}
                        {cancel && (
                            <div className="cancel-form">
                                <h3>Скасувати цей прийом?</h3>
                                <p>
                                    Час знову стане доступним для запису. Ця дія
                                    залишиться в історії.
                                </p>
                                <label>
                                    Причина скасування
                                    <textarea
                                        value={reason}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                    />
                                </label>
                                <button
                                    className="danger-button"
                                    disabled={busy}
                                    onClick={() => void act("cancel")}
                                >
                                    Підтвердити скасування
                                </button>
                            </div>
                        )}
                        {!active && (
                            <p className="terminal-note">
                                Цей прийом завершений. Його статус та час
                                зберігаються в історії.
                            </p>
                        )}
                    </>
                )}
                {tab === "payments" && !doctor && (
                    <>
                        <div className="payment-summary">
                            <div>
                                <span>Внесено</span>
                                <strong>
                                    {money(paid, visit.currency_snapshot)}
                                </strong>
                            </div>
                            <div>
                                <span>Залишок</span>
                                <strong>
                                    {money(
                                        outstanding,
                                        visit.currency_snapshot,
                                    )}
                                </strong>
                            </div>
                        </div>
                        {outstanding > 0 && (
                            <form
                                className="payment-form"
                                onSubmit={async (e) => {
                                    e.preventDefault();
                                    setBusy(true);
                                    setError("");
                                    try {
                                        await api(
                                            `/clinics/${clinic.id}/appointments/${visit.id}/payments`,
                                            "POST",
                                            {
                                                amount_minor: Math.round(
                                                    Number(amount) * 100,
                                                ),
                                                currency:
                                                    visit.currency_snapshot,
                                                method,
                                            },
                                        );
                                        setAmount("");
                                        load();
                                    } catch (e) {
                                        setError(err(e));
                                    } finally {
                                        setBusy(false);
                                    }
                                }}
                            >
                                <div className="form-grid">
                                    <label>
                                        Сума, грн
                                        <input
                                            aria-label="Сума оплати у гривнях"
                                            name="payment-amount"
                                            inputMode="decimal"
                                            type="number"
                                            min="0.01"
                                            max={outstanding / 100}
                                            step="0.01"
                                            required
                                            placeholder="0,00"
                                            value={amount}
                                            onChange={(e) =>
                                                setAmount(e.target.value)
                                            }
                                        />
                                    </label>
                                    <label>
                                        Спосіб оплати
                                        <select
                                            aria-label="Метод оплати"
                                            value={method}
                                            onChange={(e) =>
                                                setMethod(e.target.value)
                                            }
                                        >
                                            {Object.entries(methods).map(
                                                ([key, label]) => (
                                                    <option
                                                        key={key}
                                                        value={key}
                                                    >
                                                        {String(label)}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                </div>
                                <button className="primary" disabled={busy}>
                                    {busy ? "Зберігаємо…" : "Додати оплату"}
                                    <Plus size={17} aria-hidden="true" />
                                </button>
                            </form>
                        )}
                        <h3 className="ledger-title">
                            Операції за прийомом<span>{payments.length}</span>
                        </h3>
                        {payments.length ? (
                            payments.map((p) => (
                                <div
                                    className={
                                        "payment-row " +
                                        (p.type === "REVERSAL"
                                            ? "reversal"
                                            : "")
                                    }
                                    key={p.id}
                                >
                                    <span className="payment-icon">
                                        {p.type === "REVERSAL" ? (
                                            <ArrowDown
                                                size={17}
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <CreditCard
                                                size={17}
                                                aria-hidden="true"
                                            />
                                        )}
                                    </span>
                                    <span>
                                        {p.type === "REVERSAL"
                                            ? "Сторнування"
                                            : methods[p.method]}
                                        <small>
                                            {new Date(
                                                p.received_at,
                                            ).toLocaleDateString("uk-UA", {
                                                day: "numeric",
                                                month: "short",
                                                timeZone: clinic.timezone,
                                            })}
                                        </small>
                                    </span>
                                    <strong>
                                        {money(
                                            Number(p.amount_minor),
                                            p.currency,
                                        )}
                                    </strong>
                                    {p.type === "PAYMENT" &&
                                        !payments.some(
                                            (r) =>
                                                r.reverses_payment_id === p.id,
                                        ) && (
                                            <button
                                                className="text-button danger"
                                                disabled={busy}
                                                onClick={async () => {
                                                    if (
                                                        !(await confirm({
                                                            title: "Сторнувати оплату?",
                                                            description:
                                                                "Суму буде повернено до залишку. Початкова операція залишиться в історії.",
                                                            confirmLabel:
                                                                "Сторнувати оплату",
                                                        }))
                                                    )
                                                        return;
                                                    setBusy(true);
                                                    try {
                                                        await api(
                                                            `/clinics/${clinic.id}/payments/${p.id}/reverse`,
                                                            "POST",
                                                            {},
                                                        );
                                                        load();
                                                    } catch (e) {
                                                        setError(err(e));
                                                    } finally {
                                                        setBusy(false);
                                                    }
                                                }}
                                            >
                                                Сторнувати
                                            </button>
                                        )}
                                </div>
                            ))
                        ) : (
                            <p className="ledger-empty">
                                Операцій ще немає. Внесіть першу оплату вище.
                            </p>
                        )}
                    </>
                )}
                {tab === "history" && (
                    <>
                        <h3>Рух прийому</h3>
                        <div className="timeline">
                            {events.map((e) => (
                                <div key={e.id}>
                                    <span className="timeline-icon">
                                        <FileClock
                                            size={16}
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div>
                                        <strong>
                                            {{
                                                CREATED: "Запис створено",
                                                CONFIRM: "Прийом підтверджено",
                                                RESCHEDULE: "Прийом перенесено",
                                                CANCEL: "Прийом скасовано",
                                                COMPLETE: "Прийом завершено",
                                                MARK_NO_SHOW:
                                                    "Позначено неявку",
                                            }[e.event_type as string] ||
                                                e.event_type}
                                        </strong>
                                        <small>
                                            {new Date(
                                                e.occurred_at,
                                            ).toLocaleString("uk-UA", {
                                                timeZone: clinic.timezone,
                                            })}
                                        </small>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </section>
            {error && (
                <div className="error" role="alert">
                    {error}
                </div>
            )}
            <div className="drawer-footer">
                <span>Запис № {visit.id.slice(0, 8)}</span>
                <button className="text-button" onClick={onClose}>
                    Повернутися до плану
                    <ArrowRight size={15} aria-hidden="true" />
                </button>
            </div>
        </Modal>
    );
}
