import { useEffect, useState, useCallback, useRef } from "react";
import {
    Plus,
    Search,
    ChevronLeft,
    ChevronRight,
    X,
    ArrowUpRight,
    Clock3,
    Mail,
    Phone,
    CalendarDays,
} from "lucide-react";
import { api, type Row } from "../api";
import { useConfirmation } from "../shared/confirmation";
import { useUrlValue } from "../app/navigation";
import { fullName, money, err, time, Badge } from "../shared/ui";
import { Modal, Empty } from "../shared/ui";
import { Booking, Visit } from "./scheduling";

export function Catalog({
    resource,
    clinic,
    canEdit,
    notify,
    revision,
}: {
    resource: string;
    clinic: Row;
    canEdit: boolean;
    notify: (message?: string) => void;
    revision: number;
}) {
    const [search, setSearchValue] = useUrlValue(resource + ".search", ""),
        [pageValue, setPageValue] = useUrlValue(resource + ".page", "1"),
        [sort, setSort] = useUrlValue(
            resource + ".sort",
            resource === "services" ? "name" : "last_name",
        );
    const page = Math.max(1, Number(pageValue) || 1),
        setPage = (next: number) => setPageValue(String(next));
    const [rows, setRows] = useState<Row[]>([]),
        [meta, setMeta] = useState<Row>({}),
        [error, setError] = useState(""),
        [loading, setLoading] = useState(true),
        [edit, setEdit] = useState<Row | null>(null),
        [schedule, setSchedule] = useState<Row | null>(null),
        [history, setHistory] = useState<Row | null>(null),
        [bookingPatient, setBookingPatient] = useState<Row | null>(null),
        [archived, setArchived] = useState(false);
    const requestId = useRef(0);
    const labels: Row = {
        patients: [
            "Пацієнти",
            "Люди, які довіряють вашій команді. Контакти та історія зустрічей поруч.",
            "Додати пацієнта",
        ],
        doctors: [
            "Команда клініки",
            "Спеціалісти, їхні послуги та робочі графіки.",
            "Додати лікаря",
        ],
        services: [
            "Послуги та вартість",
            "Зрозумілий каталог для команди й ваших пацієнтів.",
            "Додати послугу",
        ],
    };
    const load = useCallback(async () => {
        const request = ++requestId.current;
        setLoading(true);
        try {
            const result = await api(
                `/clinics/${clinic.id}/${resource}?page=${page}&search=${encodeURIComponent(search)}${archived ? "&archived=1" : ""}&sort=${sort}`,
            );
            if (request !== requestId.current) return;
            setRows(result.data);
            setMeta(result.meta);
            setError("");
        } catch (e) {
            if (request === requestId.current) setError(err(e));
        } finally {
            if (request === requestId.current) setLoading(false);
        }
    }, [clinic.id, resource, page, search, archived, sort]);
    useEffect(() => {
        const timer = setTimeout(() => void load(), 180);
        return () => clearTimeout(timer);
    }, [load, revision]);
    return (
        <>
            <div className="page-heading directory-heading">
                <div>
                    <span className="section-context">
                        {resource === "patients"
                            ? "Картотека"
                            : resource === "doctors"
                              ? "Наші спеціалісти"
                              : "Прайс клініки"}
                    </span>
                    <h1>
                        {labels[resource][0]}
                        <span className="heading-count" aria-hidden="true">
                            {meta.total || 0}
                        </span>
                    </h1>
                    <p>{labels[resource][1]}</p>
                </div>
                {canEdit && (
                    <button className="primary" onClick={() => setEdit({})}>
                        <Plus size={18} aria-hidden="true" />
                        {labels[resource][2]}
                    </button>
                )}
            </div>
            <div className="directory-toolbar">
                <div className="search-box">
                    <Search size={18} aria-hidden="true" />
                    <input
                        aria-label="Пошук"
                        autoComplete="off"
                        placeholder={
                            resource === "patients"
                                ? "Знайти людину за іменем або контактом…"
                                : resource === "doctors"
                                  ? "Ім’я або спеціалізація…"
                                  : "Назва послуги…"
                        }
                        value={search}
                        onChange={(e) => {
                            setSearchValue(e.target.value, true);
                            setPage(1);
                        }}
                    />
                </div>
                <select
                    className="sort-select"
                    aria-label="Сортування"
                    value={sort}
                    onChange={(e) => {
                        setSort(e.target.value);
                        setPage(1);
                    }}
                >
                    {(resource === "services"
                        ? [
                              ["name", "За назвою"],
                              ["price_minor", "За вартістю"],
                              ["duration_minutes", "За тривалістю"],
                          ]
                        : [
                              ["last_name", "За прізвищем"],
                              ["first_name", "За іменем"],
                              ["created_at", "За датою додавання"],
                          ]
                    ).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
                <label className="checkbox">
                    <input
                        type="checkbox"
                        checked={archived}
                        onChange={(e) => {
                            setArchived(e.target.checked);
                            setPage(1);
                        }}
                    />
                    Архівні також
                </label>
            </div>
            {error && (
                <div className="error" role="alert">
                    {error}
                </div>
            )}
            {loading ? (
                <div className="loading" role="status">
                    Збираємо дані…
                </div>
            ) : rows.length ? (
                resource === "doctors" ? (
                    <div className="team-directory">
                        {rows.map((d) => (
                            <article
                                className={
                                    "doctor-profile " +
                                    (!d.is_active ? "inactive" : "")
                                }
                                key={d.id}
                            >
                                <div className="doctor-profile-top">
                                    <span
                                        className="doctor-portrait"
                                        aria-hidden="true"
                                    >
                                        {d.first_name[0]}
                                        {d.last_name[0]}
                                    </span>
                                    <span
                                        className={
                                            "staff-state " +
                                            (d.is_active ? "active" : "")
                                        }
                                    >
                                        {d.is_active
                                            ? "У команді"
                                            : "Неактивний"}
                                    </span>
                                </div>
                                <h2>{fullName(d)}</h2>
                                <span className="specialization">
                                    {d.specialization}
                                </span>
                                <p>
                                    {d.description ||
                                        "Профіль спеціаліста клініки"}
                                </p>
                                <div className="doctor-profile-actions">
                                    {(clinic.role !== "DOCTOR" ||
                                        d.membership_id ===
                                            clinic.membership_id) && (
                                        <button
                                            className="secondary"
                                            onClick={() => setSchedule(d)}
                                        >
                                            <CalendarDays
                                                size={16}
                                                aria-hidden="true"
                                            />
                                            Робочий графік
                                        </button>
                                    )}
                                    {canEdit && (
                                        <button
                                            className="icon-button"
                                            aria-label={
                                                "Редагувати " + fullName(d)
                                            }
                                            onClick={() => setEdit(d)}
                                        >
                                            <ArrowUpRight size={18} />
                                        </button>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                ) : resource === "services" ? (
                    <section
                        className="service-catalog"
                        aria-label="Каталог послуг"
                    >
                        <div className="service-catalog-heading">
                            <span>Послуга</span>
                            <span>Тривалість</span>
                            <span>Вартість</span>
                            <span />
                        </div>
                        {rows.map((service) => (
                            <article
                                className={
                                    "service-catalog-row " +
                                    (!service.is_active ? "inactive" : "")
                                }
                                key={service.id}
                            >
                                <div>
                                    <h2>{service.name}</h2>
                                    <p>
                                        {service.description ||
                                            "Медична послуга"}
                                        {!service.is_active && (
                                            <span className="archived-label">
                                                В архіві
                                            </span>
                                        )}
                                    </p>
                                </div>
                                <span className="service-length">
                                    <Clock3 size={15} aria-hidden="true" />
                                    {service.duration_minutes} хв
                                </span>
                                <strong className="service-price">
                                    {money(
                                        Number(service.price_minor),
                                        service.currency,
                                    )}
                                </strong>
                                {canEdit ? (
                                    <button
                                        className="icon-button"
                                        aria-label={
                                            "Редагувати " + service.name
                                        }
                                        onClick={() => setEdit(service)}
                                    >
                                        <ArrowUpRight size={18} />
                                    </button>
                                ) : (
                                    <span />
                                )}
                            </article>
                        ))}
                    </section>
                ) : (
                    <section className="patient-register table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Пацієнт</th>
                                    <th>Контакти</th>
                                    <th>Дата народження</th>
                                    <th>Картка та запис</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((patient) => (
                                    <tr key={patient.id}>
                                        <td>
                                            <div className="person-cell">
                                                <span
                                                    className="patient-monogram"
                                                    aria-hidden="true"
                                                >
                                                    {patient.first_name[0]}
                                                    {patient.last_name[0]}
                                                </span>
                                                <div>
                                                    <strong>
                                                        {fullName(patient)}
                                                    </strong>
                                                    {patient.middle_name && (
                                                        <small>
                                                            {
                                                                patient.middle_name
                                                            }
                                                        </small>
                                                    )}
                                                    {patient.archived_at && (
                                                        <span className="archived-label">
                                                            В архіві
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span className="contact-value">
                                                {patient.phone_e164 || "—"}
                                            </span>
                                            <small>{patient.email}</small>
                                        </td>
                                        <td>
                                            {patient.birth_date
                                                ? new Date(
                                                      patient.birth_date +
                                                          "T12:00:00",
                                                  ).toLocaleDateString("uk-UA")
                                                : "—"}
                                        </td>
                                        <td>
                                            <div className="row-actions">
                                                <button
                                                    className="text-button"
                                                    onClick={() =>
                                                        setHistory(patient)
                                                    }
                                                >
                                                    Картка
                                                    <ArrowUpRight
                                                        size={13}
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                                {canEdit && (
                                                    <>
                                                        <button
                                                            className="text-button"
                                                            onClick={() =>
                                                                setEdit(patient)
                                                            }
                                                        >
                                                            Редагувати
                                                        </button>
                                                        {!patient.archived_at && (
                                                            <button
                                                                className="patient-book-button"
                                                                onClick={() =>
                                                                    setBookingPatient(
                                                                        patient,
                                                                    )
                                                                }
                                                            >
                                                                <Plus
                                                                    size={14}
                                                                    aria-hidden="true"
                                                                />
                                                                Записати
                                                            </button>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )
            ) : (
                <Empty
                    text={
                        resource === "patients"
                            ? "Пацієнтів не знайдено"
                            : resource === "doctors"
                              ? "Додайте першого спеціаліста"
                              : "Каталог ще порожній"
                    }
                    description="Спробуйте інший пошук або додайте нові дані."
                />
            )}
            <div className="pagination">
                <span>
                    {meta.total || 0}{" "}
                    {resource === "patients"
                        ? "пацієнтів"
                        : resource === "doctors"
                          ? "спеціалістів"
                          : "послуг"}
                    <span className="pagination-separator">/</span>Сторінка{" "}
                    {page} з {Math.max(1, meta.total_pages || 1)}
                </span>
                <button
                    className="icon-button"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                    aria-label="Попередня сторінка"
                >
                    <ChevronLeft size={18} />
                </button>
                <button
                    className="icon-button"
                    disabled={page >= (meta.total_pages || 1)}
                    onClick={() => setPage(page + 1)}
                    aria-label="Наступна сторінка"
                >
                    <ChevronRight size={18} />
                </button>
            </div>
            {edit && (
                <CatalogForm
                    resource={resource}
                    clinic={clinic}
                    row={edit}
                    onClose={() => setEdit(null)}
                    onSave={(message) => {
                        setEdit(null);
                        notify(message);
                    }}
                />
            )}
            {schedule && (
                <ScheduleEditor
                    clinic={clinic}
                    doctor={schedule}
                    canEdit={canEdit}
                    onClose={() => setSchedule(null)}
                    onSave={() => {
                        setSchedule(null);
                        notify();
                    }}
                />
            )}
            {history && (
                <PatientHistory
                    clinic={clinic}
                    patient={history}
                    onClose={() => setHistory(null)}
                />
            )}
            {bookingPatient && (
                <Booking
                    clinic={clinic}
                    initialPatient={bookingPatient}
                    onClose={() => setBookingPatient(null)}
                    onSave={() => {
                        setBookingPatient(null);
                        notify("Пацієнта успішно записано");
                    }}
                />
            )}
        </>
    );
}

export function CatalogForm({
    resource,
    clinic,
    row,
    onClose,
    onSave,
}: {
    resource: string;
    clinic: Row;
    row: Row;
    onClose: () => void;
    onSave: (m?: string) => void;
}) {
    const confirm = useConfirmation();
    const [d, setD] = useState<Row>({
            ...row,
            duration_minutes: row.duration_minutes || 30,
            price:
                row.price_minor !== undefined
                    ? Number(row.price_minor) / 100
                    : "",
            is_active: row.is_active ?? true,
        }),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [services, setServices] = useState<Row[]>([]),
        [selected, setSelected] = useState<string[]>([]);
    useEffect(() => {
        if (resource === "doctors") {
            api(`/clinics/${clinic.id}/services?per_page=100`)
                .then((r) => setServices(r.data))
                .catch((e) => setError(err(e)));
            if (row.id)
                api(`/clinics/${clinic.id}/doctors/${row.id}`)
                    .then((r) => setSelected(r.data.service_ids))
                    .catch((e) => setError(err(e)));
        }
    }, [resource, clinic.id, row.id]);
    const field = (
        key: string,
        label: string,
        type = "text",
        required = false,
    ) => (
        <label key={key}>
            {label}
            <input
                type={type}
                required={required}
                value={d[key] ?? ""}
                onChange={(e) => setD({ ...d, [key]: e.target.value })}
            />
        </label>
    );
    return (
        <Modal
            title={
                (row.id ? "Редагувати: " : "Додати: ") +
                { patients: "пацієнт", doctors: "лікар", services: "послуга" }[
                    resource
                ]
            }
            onClose={onClose}
            variant="drawer"
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        const body: Row = { ...d };
                        if (resource === "services") {
                            body.duration_minutes = Number(d.duration_minutes);
                            body.price_minor = Math.round(
                                Number(d.price) * 100,
                            );
                            body.currency = clinic.currency;
                        }
                        const result = await api(
                            `/clinics/${clinic.id}/${resource}${row.id ? "/" + row.id : ""}`,
                            row.id ? "PATCH" : "POST",
                            body,
                        );
                        if (resource === "doctors")
                            await api(
                                `/clinics/${clinic.id}/doctors/${result.data.id}/services`,
                                "PUT",
                                { service_ids: selected },
                            );
                        onSave(
                            result.duplicates?.length
                                ? "Збережено. Увага: знайдено можливі дублікати пацієнта."
                                : "Зміни збережено",
                        );
                    } catch (e) {
                        setError(err(e));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                {resource === "services" ? (
                    <>
                        {field("name", "Назва послуги", "text", true)}
                        <div className="form-grid">
                            {field(
                                "duration_minutes",
                                "Тривалість, хвилин",
                                "number",
                                true,
                            )}
                            {field("price", "Ціна, грн", "number", true)}
                        </div>
                        {field("description", "Опис")}
                    </>
                ) : (
                    <>
                        <div className="form-grid">
                            {field("last_name", "Прізвище", "text", true)}
                            {field("first_name", "Ім’я", "text", true)}
                        </div>
                        {resource === "patients" ? (
                            <>
                                {field("middle_name", "По батькові")}
                                <div className="form-grid">
                                    {field("phone_e164", "Телефон", "tel")}
                                    {field("email", "Email", "email")}
                                </div>
                                {field("birth_date", "Дата народження", "date")}
                                <label>
                                    Адміністративні нотатки
                                    <textarea
                                        value={d.administrative_notes || ""}
                                        onChange={(e) =>
                                            setD({
                                                ...d,
                                                administrative_notes:
                                                    e.target.value,
                                            })
                                        }
                                    />
                                    <small>
                                        Без діагнозів та інших медичних даних.
                                    </small>
                                </label>
                            </>
                        ) : (
                            <>
                                {field(
                                    "specialization",
                                    "Спеціалізація",
                                    "text",
                                    true,
                                )}
                                {field("description", "Опис")}
                                <label>Послуги лікаря</label>
                                <div className="service-checks">
                                    {services.map((s) => (
                                        <label className="checkbox" key={s.id}>
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(
                                                    s.id,
                                                )}
                                                onChange={(e) =>
                                                    setSelected(
                                                        e.target.checked
                                                            ? [
                                                                  ...selected,
                                                                  s.id,
                                                              ]
                                                            : selected.filter(
                                                                  (v) =>
                                                                      v !==
                                                                      s.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {s.name}
                                        </label>
                                    ))}
                                </div>
                            </>
                        )}
                    </>
                )}
                {resource !== "patients" && (
                    <label className="checkbox">
                        <input
                            type="checkbox"
                            checked={d.is_active}
                            onChange={(e) =>
                                setD({ ...d, is_active: e.target.checked })
                            }
                        />
                        Активний профіль
                    </label>
                )}
                {error && (
                    <div role="alert" className="error">
                        {error}
                    </div>
                )}
                <div className="modal-actions">
                    {row.id && resource === "patients" && !row.archived_at && (
                        <button
                            className="text-button danger"
                            type="button"
                            disabled={busy}
                            onClick={async () => {
                                if (
                                    !(await confirm({
                                        title: "Архівувати пацієнта?",
                                        description:
                                            "Картку буде приховано з основного списку. Історія прийомів залишиться доступною.",
                                        confirmLabel: "Архівувати",
                                    }))
                                )
                                    return;
                                setBusy(true);
                                try {
                                    await api(
                                        `/clinics/${clinic.id}/patients/${row.id}/archive`,
                                        "POST",
                                        {},
                                    );
                                    onSave("Пацієнта архівовано");
                                } catch (e) {
                                    setError(err(e));
                                    setBusy(false);
                                }
                            }}
                        >
                            Архівувати
                        </button>
                    )}
                    <button
                        className="secondary"
                        type="button"
                        onClick={onClose}
                    >
                        Скасувати
                    </button>
                    <button className="primary" disabled={busy}>
                        {busy ? "Зберігаємо…" : "Зберегти"}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export function PatientHistory({
    clinic,
    patient,
    onClose,
}: {
    clinic: Row;
    patient: Row;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<Row[]>([]),
        [selected, setSelected] = useState<Row | null>(null),
        [error, setError] = useState(""),
        [loading, setLoading] = useState(true);
    useEffect(() => {
        let active = true;
        api(
            `/clinics/${clinic.id}/patients/${patient.id}/appointments?per_page=100`,
        )
            .then((result) => {
                if (active) setRows(result.data);
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
    }, [clinic.id, patient.id]);
    if (selected)
        return (
            <Visit
                clinic={clinic}
                visit={selected}
                doctor={clinic.role === "DOCTOR"}
                onClose={() => setSelected(null)}
                onSave={() => {
                    setSelected(null);
                    onClose();
                }}
            />
        );
    return (
        <Modal title="Картка пацієнта" onClose={onClose} variant="drawer">
            <div className="patient-profile-heading">
                <span className="visit-monogram" aria-hidden="true">
                    {patient.first_name[0]}
                    {patient.last_name[0]}
                </span>
                <h3>{fullName(patient)}</h3>
                <p>{patient.middle_name || "Пацієнт клініки"}</p>
            </div>
            <div className="patient-contacts">
                {patient.phone_e164 && (
                    <a href={"tel:" + patient.phone_e164}>
                        <Phone size={17} aria-hidden="true" />
                        {patient.phone_e164}
                    </a>
                )}
                {patient.email && (
                    <a href={"mailto:" + patient.email}>
                        <Mail size={17} aria-hidden="true" />
                        {patient.email}
                    </a>
                )}
            </div>
            {patient.administrative_notes && (
                <div className="patient-note">
                    <strong>Адміністративні нотатки</strong>
                    <p>{patient.administrative_notes}</p>
                </div>
            )}
            <h3 className="patient-history-title">
                Історія зустрічей<span>{rows.length}</span>
            </h3>
            {loading ? (
                <div className="loading" role="status">
                    Завантажуємо прийоми…
                </div>
            ) : rows.length ? (
                <div className="patient-history-list">
                    {[...rows].reverse().map((row) => (
                        <button key={row.id} onClick={() => setSelected(row)}>
                            <div className="history-date">
                                <strong>
                                    {new Date(row.starts_at).toLocaleDateString(
                                        "uk-UA",
                                        {
                                            day: "numeric",
                                            month: "short",
                                            timeZone: clinic.timezone,
                                        },
                                    )}
                                </strong>
                                <span>
                                    {time(row.starts_at, clinic.timezone)}
                                </span>
                            </div>
                            <span>
                                <strong>{row.service_name_snapshot}</strong>
                                <small>{row.doctor_name}</small>
                                <Badge status={row.status} />
                            </span>
                            <ArrowUpRight size={17} aria-hidden="true" />
                        </button>
                    ))}
                </div>
            ) : (
                <Empty
                    text="Перший прийом ще попереду"
                    description="Коли з’явиться запис, його буде видно тут."
                />
            )}
            {error && (
                <div className="error" role="alert">
                    {error}
                </div>
            )}
        </Modal>
    );
}

export function ScheduleEditor({
    clinic,
    doctor,
    canEdit,
    onClose,
    onSave,
}: {
    clinic: Row;
    doctor: Row;
    canEdit: boolean;
    onClose: () => void;
    onSave: () => void;
}) {
    const [hours, setHours] = useState<Row[]>([]),
        [exceptions, setExceptions] = useState<Row[]>([]),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [x, setX] = useState<Row>({ type: "UNAVAILABLE" });
    useEffect(() => {
        Promise.all([
            api(`/clinics/${clinic.id}/doctors/${doctor.id}/working-hours`),
            api(`/clinics/${clinic.id}/doctors/${doctor.id}/exceptions`),
        ])
            .then(([h, e]) => {
                setHours(h.data);
                setExceptions(e.data);
            })
            .catch((e) => setError(err(e)));
    }, [clinic.id, doctor.id]);
    return (
        <Modal title={"Графік · " + fullName(doctor)} onClose={onClose} wide>
            <p className="muted">
                Локальний час клініки: {clinic.timezone}. Перерви задаються
                окремими інтервалами.
            </p>
            <div className="hours-list">
                {hours.map((h, i) => (
                    <div className="hours-row" key={i}>
                        <select
                            aria-label="День тижня"
                            disabled={!canEdit}
                            value={h.day_of_week}
                            onChange={(e) =>
                                setHours(
                                    hours.map((v, n) =>
                                        n === i
                                            ? {
                                                  ...v,
                                                  day_of_week: Number(
                                                      e.target.value,
                                                  ),
                                              }
                                            : v,
                                    ),
                                )
                            }
                        >
                            {[
                                "Понеділок",
                                "Вівторок",
                                "Середа",
                                "Четвер",
                                "П’ятниця",
                                "Субота",
                                "Неділя",
                            ].map((d, n) => (
                                <option value={n + 1} key={d}>
                                    {d}
                                </option>
                            ))}
                        </select>
                        <input
                            aria-label="Початок робочого часу"
                            disabled={!canEdit}
                            type="time"
                            value={h.start_local_time.slice(0, 5)}
                            onChange={(e) =>
                                setHours(
                                    hours.map((v, n) =>
                                        n === i
                                            ? {
                                                  ...v,
                                                  start_local_time:
                                                      e.target.value,
                                              }
                                            : v,
                                    ),
                                )
                            }
                        />
                        <span>—</span>
                        <input
                            aria-label="Кінець робочого часу"
                            disabled={!canEdit}
                            type="time"
                            value={h.end_local_time.slice(0, 5)}
                            onChange={(e) =>
                                setHours(
                                    hours.map((v, n) =>
                                        n === i
                                            ? {
                                                  ...v,
                                                  end_local_time:
                                                      e.target.value,
                                              }
                                            : v,
                                    ),
                                )
                            }
                        />
                        {canEdit && (
                            <button
                                className="icon-button danger"
                                onClick={() =>
                                    setHours(hours.filter((_, n) => n !== i))
                                }
                                aria-label="Видалити інтервал"
                            >
                                <X size={16} aria-hidden="true" />
                            </button>
                        )}
                    </div>
                ))}
            </div>
            {canEdit && (
                <div className="modal-actions">
                    <button
                        className="secondary"
                        onClick={() =>
                            setHours([
                                ...hours,
                                {
                                    day_of_week: 1,
                                    start_local_time: "09:00",
                                    end_local_time: "18:00",
                                },
                            ])
                        }
                    >
                        <Plus size={16} aria-hidden="true" />
                        Інтервал
                    </button>
                    <button
                        className="primary"
                        disabled={busy}
                        onClick={async () => {
                            setBusy(true);
                            setError("");
                            try {
                                await api(
                                    `/clinics/${clinic.id}/doctors/${doctor.id}/working-hours`,
                                    "PUT",
                                    {
                                        intervals: hours.map((h) => ({
                                            day_of_week: Number(h.day_of_week),
                                            start_local_time:
                                                h.start_local_time,
                                            end_local_time: h.end_local_time,
                                        })),
                                    },
                                );
                                onSave();
                            } catch (e) {
                                setError(err(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        Зберегти графік
                    </button>
                </div>
            )}
            <section className="visit-section">
                <h3>Винятки та відсутність</h3>
                {exceptions.map((e) => (
                    <div className="exception-row" key={e.id}>
                        <span>
                            {e.type === "UNAVAILABLE"
                                ? "Відсутність"
                                : "Додатковий час"}
                            <small>
                                {new Date(e.starts_at).toLocaleString("uk-UA", {
                                    timeZone: clinic.timezone,
                                })}{" "}
                                —{" "}
                                {new Date(e.ends_at).toLocaleString("uk-UA", {
                                    timeZone: clinic.timezone,
                                })}
                            </small>
                        </span>
                        {canEdit && (
                            <button
                                className="icon-button danger"
                                aria-label="Видалити виняток"
                                onClick={async () => {
                                    try {
                                        await api(
                                            `/clinics/${clinic.id}/doctors/${doctor.id}/exceptions/${e.id}`,
                                            "DELETE",
                                        );
                                        setExceptions(
                                            exceptions.filter(
                                                (v) => v.id !== e.id,
                                            ),
                                        );
                                    } catch (e) {
                                        setError(err(e));
                                    }
                                }}
                            >
                                <X size={16} aria-hidden="true" />
                            </button>
                        )}
                    </div>
                ))}
                {canEdit && (
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            try {
                                const result = await api(
                                    `/clinics/${clinic.id}/doctors/${doctor.id}/exceptions`,
                                    "POST",
                                    x,
                                );
                                setExceptions(result.data);
                                setX({ type: "UNAVAILABLE" });
                            } catch (e) {
                                setError(err(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <label>
                            Тип
                            <select
                                value={x.type}
                                onChange={(e) =>
                                    setX({ ...x, type: e.target.value })
                                }
                            >
                                <option value="UNAVAILABLE">
                                    Відсутність / відпустка
                                </option>
                                <option value="AVAILABLE_OVERRIDE">
                                    Додатковий робочий час
                                </option>
                            </select>
                        </label>
                        <div className="form-grid">
                            {["starts_at", "ends_at"].map((f, i) => (
                                <label key={f}>
                                    {i === 0 ? "Початок" : "Кінець"} (ISO 8601)
                                    <input
                                        required
                                        placeholder="2026-11-12T09:00:00+02:00"
                                        value={x[f] || ""}
                                        onChange={(e) =>
                                            setX({ ...x, [f]: e.target.value })
                                        }
                                    />
                                </label>
                            ))}
                        </div>
                        <label>
                            Причина
                            <input
                                value={x.reason || ""}
                                onChange={(e) =>
                                    setX({ ...x, reason: e.target.value })
                                }
                            />
                        </label>
                        <button className="secondary" disabled={busy}>
                            Додати виняток
                        </button>
                    </form>
                )}
            </section>
            {error && (
                <div className="error" role="alert">
                    {error}
                </div>
            )}
        </Modal>
    );
}
