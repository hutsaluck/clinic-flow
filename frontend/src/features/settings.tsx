import { useEffect, useState, useCallback } from "react";
import {
    Plus,
    ChevronLeft,
    ChevronRight,
    Building2,
    Users,
    FileClock,
} from "lucide-react";
import { api, type Row } from "../api";
import { roleNames, fullName, err, Modal, Empty } from "../shared/ui";
import { useConfirmation } from "../shared/confirmation";

export function SettingsPage({
    clinic,
    onSave,
}: {
    clinic: Row;
    onSave: () => void;
}) {
    const confirm = useConfirmation(),
        [section, setSection] = useState("profile"),
        [data, setData] = useState<Row>({ ...clinic }),
        [members, setMembers] = useState<Row[]>([]),
        [email, setEmail] = useState(""),
        [role, setRole] = useState("RECEPTIONIST"),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const load = useCallback(() => {
        api(`/clinics/${clinic.id}/memberships`)
            .then((result) => setMembers(result.data))
            .catch((e) => setError(err(e)));
    }, [clinic.id]);
    useEffect(load, [load]);
    return (
        <>
            <div className="page-heading">
                <div>
                    <span className="section-context">Ваш простір</span>
                    <h1>Налаштування клініки</h1>
                    <p>Інформація, правила запису та доступи вашої команди.</p>
                </div>
            </div>
            <div className="settings-layout">
                <nav
                    className="settings-index"
                    aria-label="Розділи налаштувань"
                >
                    <button
                        aria-pressed={section === "profile"}
                        onClick={() => setSection("profile")}
                    >
                        <Building2 size={18} aria-hidden="true" />
                        <span>
                            Профіль клініки<small>Контакти та правила</small>
                        </span>
                    </button>
                    <button
                        aria-pressed={section === "team"}
                        onClick={() => setSection("team")}
                    >
                        <Users size={18} aria-hidden="true" />
                        <span>
                            Команда й доступ
                            <small>{members.length} співробітників</small>
                        </span>
                    </button>
                </nav>
                <section className="settings-content">
                    {section === "profile" ? (
                        <>
                            <div className="section-heading">
                                <div>
                                    <h2>Про клініку</h2>
                                    <p>
                                        Ця інформація використовується у вашому
                                        робочому просторі.
                                    </p>
                                </div>
                            </div>
                            <form
                                onSubmit={async (event) => {
                                    event.preventDefault();
                                    setBusy(true);
                                    setError("");
                                    try {
                                        await api(
                                            `/clinics/${clinic.id}`,
                                            "PATCH",
                                            {
                                                ...data,
                                                booking_horizon_days: Number(
                                                    data.booking_horizon_days,
                                                ),
                                                cancellation_hours: Number(
                                                    data.cancellation_hours,
                                                ),
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
                                <div className="form-grid">
                                    {[
                                        ["name", "Назва клініки"],
                                        ["slug", "Коротка назва в системі"],
                                        ["email", "Email"],
                                        ["phone", "Телефон"],
                                        ["address", "Адреса"],
                                        ["timezone", "Часовий пояс IANA"],
                                        ["currency", "Валюта"],
                                    ].map(([field, label]) => (
                                        <label key={field}>
                                            {label}
                                            <input
                                                required={[
                                                    "name",
                                                    "slug",
                                                    "timezone",
                                                    "currency",
                                                ].includes(field)}
                                                type={
                                                    field === "email"
                                                        ? "email"
                                                        : field === "phone"
                                                          ? "tel"
                                                          : "text"
                                                }
                                                name={field}
                                                value={data[field] ?? ""}
                                                onChange={(event) =>
                                                    setData({
                                                        ...data,
                                                        [field]:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        </label>
                                    ))}
                                </div>
                                <div className="settings-section-divider">
                                    <h3>Правила планування</h3>
                                    <p>
                                        Визначте, наскільки наперед можна
                                        записатися та коли дозволено скасування.
                                    </p>
                                </div>
                                <div className="form-grid">
                                    <label>
                                        Горизонт запису, днів
                                        <input
                                            type="number"
                                            min="1"
                                            max="365"
                                            value={data.booking_horizon_days}
                                            onChange={(event) =>
                                                setData({
                                                    ...data,
                                                    booking_horizon_days:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        Скасування не пізніше ніж за, годин
                                        <input
                                            type="number"
                                            min="0"
                                            max="168"
                                            value={data.cancellation_hours}
                                            onChange={(event) =>
                                                setData({
                                                    ...data,
                                                    cancellation_hours:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                                <div className="settings-save">
                                    <span>
                                        Зміни застосуються до нових операцій.
                                    </span>
                                    <button className="primary" disabled={busy}>
                                        {busy
                                            ? "Зберігаємо…"
                                            : "Зберегти налаштування"}
                                    </button>
                                </div>
                            </form>
                        </>
                    ) : (
                        <>
                            <div className="section-heading">
                                <div>
                                    <h2>Люди та ролі</h2>
                                    <p>
                                        Керуйте доступом до даних і щоденних
                                        процесів клініки.
                                    </p>
                                </div>
                            </div>
                            <div className="membership-list">
                                {members.map((member) => (
                                    <article key={member.id}>
                                        <span
                                            className="patient-monogram"
                                            aria-hidden="true"
                                        >
                                            {member.first_name[0]}
                                            {member.last_name[0]}
                                        </span>
                                        <div className="membership-person">
                                            <strong>{fullName(member)}</strong>
                                            <small>{member.email}</small>
                                        </div>
                                        <label>
                                            <span className="sr-only">
                                                Роль {member.email}
                                            </span>
                                            <select
                                                aria-label={
                                                    "Роль " + member.email
                                                }
                                                value={member.role}
                                                onChange={(event) =>
                                                    setMembers(
                                                        members.map((m) =>
                                                            m.id === member.id
                                                                ? {
                                                                      ...m,
                                                                      role: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : m,
                                                        ),
                                                    )
                                                }
                                            >
                                                {Object.entries(roleNames).map(
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
                                        <label>
                                            <span className="sr-only">
                                                Статус {member.email}
                                            </span>
                                            <select
                                                aria-label={
                                                    "Статус " + member.email
                                                }
                                                value={member.status}
                                                onChange={(event) =>
                                                    setMembers(
                                                        members.map((m) =>
                                                            m.id === member.id
                                                                ? {
                                                                      ...m,
                                                                      status: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : m,
                                                        ),
                                                    )
                                                }
                                            >
                                                <option value="ACTIVE">
                                                    Активний
                                                </option>
                                                <option value="INACTIVE">
                                                    Вимкнений
                                                </option>
                                            </select>
                                        </label>
                                        <button
                                            className="text-button"
                                            onClick={async () => {
                                                if (
                                                    member.status ===
                                                        "INACTIVE" &&
                                                    !(await confirm({
                                                        title: "Вимкнути доступ?",
                                                        description:
                                                            "Співробітник більше не матиме доступу до цієї клініки.",
                                                        confirmLabel:
                                                            "Вимкнути доступ",
                                                    }))
                                                )
                                                    return;
                                                try {
                                                    await api(
                                                        `/clinics/${clinic.id}/memberships/${member.id}`,
                                                        "PATCH",
                                                        {
                                                            role: member.role,
                                                            status: member.status,
                                                        },
                                                    );
                                                    load();
                                                    onSave();
                                                } catch (e) {
                                                    setError(err(e));
                                                }
                                            }}
                                        >
                                            Зберегти
                                        </button>
                                    </article>
                                ))}
                            </div>
                            <section className="add-member">
                                <h3>Додати співробітника</h3>
                                <p>
                                    Вкажіть email наявного облікового запису та
                                    оберіть роль у клініці.
                                </p>
                                <form
                                    className="add-member-form"
                                    onSubmit={async (event) => {
                                        event.preventDefault();
                                        setBusy(true);
                                        setError("");
                                        try {
                                            await api(
                                                `/clinics/${clinic.id}/memberships`,
                                                "POST",
                                                { email, role },
                                            );
                                            setEmail("");
                                            load();
                                            onSave();
                                        } catch (e) {
                                            setError(err(e));
                                        } finally {
                                            setBusy(false);
                                        }
                                    }}
                                >
                                    <label>
                                        Email
                                        <input
                                            required
                                            type="email"
                                            autoComplete="off"
                                            aria-label="Email співробітника"
                                            value={email}
                                            onChange={(event) =>
                                                setEmail(event.target.value)
                                            }
                                            placeholder="name@clinic.test"
                                        />
                                    </label>
                                    <label>
                                        Роль
                                        <select
                                            aria-label="Роль нового співробітника"
                                            value={role}
                                            onChange={(event) =>
                                                setRole(event.target.value)
                                            }
                                        >
                                            {Object.entries(roleNames).map(
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
                                    <button className="primary" disabled={busy}>
                                        <Plus size={17} aria-hidden="true" />
                                        Додати
                                    </button>
                                </form>
                            </section>
                        </>
                    )}
                    {error && (
                        <div className="error" role="alert">
                            {error}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

export function Audit({ clinic }: { clinic: Row }) {
    const [rows, setRows] = useState<Row[]>([]),
        [page, setPage] = useState(1),
        [meta, setMeta] = useState<Row>({}),
        [error, setError] = useState("");
    useEffect(() => {
        let active = true;
        api(`/clinics/${clinic.id}/audit-logs?page=${page}`)
            .then((result) => {
                if (active) {
                    setRows(result.data);
                    setMeta(result.meta);
                }
            })
            .catch((e) => {
                if (active) setError(err(e));
            });
        return () => {
            active = false;
        };
    }, [clinic.id, page]);
    const labels: Row = {
        CREATED: "Додано нові дані",
        UPDATED: "Оновлено дані",
        ARCHIVED: "Пацієнта архівовано",
        APPOINTMENT_CREATED: "Створено запис на прийом",
        APPOINTMENT_CONFIRM: "Прийом підтверджено",
        APPOINTMENT_RESCHEDULE: "Прийом перенесено",
        APPOINTMENT_CANCEL: "Прийом скасовано",
        APPOINTMENT_COMPLETE: "Прийом завершено",
        APPOINTMENT_MARK_NO_SHOW: "Позначено неявку",
        PAYMENT_RECORDED: "Зареєстровано оплату",
        PAYMENT_REVERSED: "Оплату сторновано",
        SCHEDULE_UPDATED: "Оновлено робочий графік",
        SERVICES_ASSIGNED: "Оновлено послуги лікаря",
        MEMBERSHIP_UPDATED: "Змінено доступ співробітника",
        CLINIC_UPDATED: "Оновлено налаштування клініки",
        CSV_EXPORTED: "Експортовано звіт",
        EXCEPTION_DELETED: "Видалено виняток розкладу",
    };
    return (
        <>
            <div className="page-heading">
                <div>
                    <span className="section-context">Хронологія клініки</span>
                    <h1>Журнал дій</h1>
                    <p>Важливі зміни та люди, які за ними стоять.</p>
                </div>
                <span className="activity-total">
                    {meta.total || 0}
                    <small>подій збережено</small>
                </span>
            </div>
            <section className="activity-stream">
                {rows.length ? (
                    rows.map((row) => (
                        <article key={row.id}>
                            <span className="activity-icon">
                                <FileClock size={18} aria-hidden="true" />
                            </span>
                            <div className="activity-content">
                                <h2>
                                    {labels[row.action] ||
                                        "Виконано дію в клініці"}
                                </h2>
                                <p>{row.actor_name || "Система"}</p>
                                <details>
                                    <summary>Технічні деталі</summary>
                                    <dl>
                                        <div>
                                            <dt>Операція</dt>
                                            <dd>{row.action}</dd>
                                        </div>
                                        <div>
                                            <dt>Об’єкт</dt>
                                            <dd>
                                                {row.subject_type} /{" "}
                                                {row.subject_id || "—"}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>ID запиту</dt>
                                            <dd>{row.correlation_id}</dd>
                                        </div>
                                    </dl>
                                </details>
                            </div>
                            <time>
                                {new Date(row.occurred_at).toLocaleDateString(
                                    "uk-UA",
                                    {
                                        day: "numeric",
                                        month: "short",
                                        timeZone: clinic.timezone,
                                    },
                                )}
                                <small>
                                    {new Date(
                                        row.occurred_at,
                                    ).toLocaleTimeString("uk-UA", {
                                        hour: "2-digit",
                                        minute: "2-digit",
                                        timeZone: clinic.timezone,
                                    })}
                                </small>
                            </time>
                        </article>
                    ))
                ) : (
                    <Empty
                        text="Поки що без подій"
                        description="Історія важливих змін з’являтиметься тут."
                    />
                )}
            </section>
            <div className="pagination">
                <span>Сторінка {page}</span>
                <button
                    className="icon-button"
                    aria-label="Попередня сторінка"
                    disabled={page === 1}
                    onClick={() => setPage(page - 1)}
                >
                    <ChevronLeft size={17} />
                </button>
                <button
                    className="icon-button"
                    aria-label="Наступна сторінка"
                    disabled={page >= (meta.total_pages || 1)}
                    onClick={() => setPage(page + 1)}
                >
                    <ChevronRight size={17} />
                </button>
            </div>
            {error && (
                <div className="error" role="alert">
                    {error}
                </div>
            )}
        </>
    );
}

export function Profile({ user, onSave }: { user: Row; onSave: () => void }) {
    const [d, setD] = useState({ ...user }),
        [error, setError] = useState("");
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Мій профіль</h1>
                    <p>{user.email}</p>
                </div>
            </div>
            <section className="panel padded narrow">
                <form
                    onSubmit={async (e) => {
                        e.preventDefault();
                        try {
                            await api("/me", "PATCH", {
                                first_name: d.first_name,
                                last_name: d.last_name,
                            });
                            onSave();
                        } catch (e) {
                            setError(err(e));
                        }
                    }}
                >
                    {["first_name", "last_name"].map((f, i) => (
                        <label key={f}>
                            {i ? "Прізвище" : "Ім’я"}
                            <input
                                required
                                value={d[f]}
                                onChange={(e) =>
                                    setD({ ...d, [f]: e.target.value })
                                }
                            />
                        </label>
                    ))}
                    {error && <div className="error">{error}</div>}
                    <button className="primary">Зберегти профіль</button>
                </form>
            </section>
        </>
    );
}

export function Platform({ notify }: { notify: (s?: string) => void }) {
    const [rows, setRows] = useState<Row[]>([]),
        [edit, setEdit] = useState<Row | null>(null),
        [error, setError] = useState("");
    const load = () =>
        api("/platform/clinics")
            .then((r) => setRows(r.data))
            .catch((e) => setError(err(e)));
    useEffect(() => {
        load();
    }, []);
    return (
        <>
            <div className="page-heading">
                <div>
                    <h1>Клініки платформи</h1>
                    <p>Технічне керування організаціями.</p>
                </div>
                <button
                    className="primary"
                    onClick={() =>
                        setEdit({ timezone: "Europe/Kyiv", currency: "UAH" })
                    }
                >
                    <Plus size={17} aria-hidden="true" />
                    Створити клініку
                </button>
            </div>
            <section className="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Назва</th>
                            <th>Часовий пояс</th>
                            <th>Статус</th>
                            <th>Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td>
                                    <strong>{r.name}</strong>
                                    <small>{r.slug}</small>
                                </td>
                                <td>{r.timezone}</td>
                                <td>
                                    {r.status === "ACTIVE"
                                        ? "Активна"
                                        : "Заблокована"}
                                </td>
                                <td>
                                    <button
                                        className="text-button"
                                        onClick={() => setEdit(r)}
                                    >
                                        Редагувати
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
            {error && <div className="error">{error}</div>}
            {edit && (
                <Modal title="Клініка" onClose={() => setEdit(null)}>
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            try {
                                await api(
                                    "/platform/clinics" +
                                        (edit.id ? "/" + edit.id : ""),
                                    edit.id ? "PATCH" : "POST",
                                    edit,
                                );
                                setEdit(null);
                                load();
                                notify();
                            } catch (e) {
                                setError(err(e));
                            }
                        }}
                    >
                        {[
                            ["name", "Назва"],
                            ["slug", "Slug"],
                            ["timezone", "Часовий пояс"],
                            ["currency", "Валюта"],
                            ...(!edit.id
                                ? [["owner_user_id", "UUID адміністратора"]]
                                : []),
                        ].map(([f, label]) => (
                            <label key={f}>
                                {label}
                                <input
                                    required
                                    value={edit[f] || ""}
                                    onChange={(e) =>
                                        setEdit({
                                            ...edit,
                                            [f]: e.target.value,
                                        })
                                    }
                                />
                            </label>
                        ))}
                        {edit.id && (
                            <label>
                                Статус
                                <select
                                    value={edit.status}
                                    onChange={(e) =>
                                        setEdit({
                                            ...edit,
                                            status: e.target.value,
                                        })
                                    }
                                >
                                    <option value="ACTIVE">Активна</option>
                                    <option value="SUSPENDED">
                                        Заблокована
                                    </option>
                                </select>
                            </label>
                        )}
                        {error && <div className="error">{error}</div>}
                        <button className="primary">Зберегти</button>
                    </form>
                </Modal>
            )}
        </>
    );
}
