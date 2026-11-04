import { useCallback, useEffect, useState } from "react";
import {
    ArrowRight,
    ArrowUpRight,
    Building2,
    Check,
    ChevronDown,
    Command,
    LogOut,
    Menu,
    Moon,
    Search,
    Sun,
    X,
} from "lucide-react";
import { api, refresh, setToken } from "./api";
import { roleNames, Empty, Modal } from "./shared/ui";
import { BrandMark } from "./shared/brand";
import { demoMode } from "./shared/environment";
import { useTheme, useUrlValue } from "./app/navigation";
import type { SessionUser, Clinic, SessionResponse } from "./app/types";
import { Login } from "./features/auth";
import { CalendarPage } from "./features/scheduling";
import { Catalog } from "./features/catalog";
import { Analytics } from "./features/analytics";
import { SettingsPage, Audit, Profile, Platform } from "./features/settings";

export function App() {
    const [user, setUser] = useState<SessionUser | null>(null),
        [clinics, setClinics] = useState<Clinic[]>([]),
        [boot, setBoot] = useState(true),
        [revision, setRevision] = useState(0),
        [toast, setToast] = useState(""),
        [mobile, setMobile] = useState(false),
        [quick, setQuick] = useState(false),
        [query, setQuery] = useState(""),
        [error, setError] = useState("");
    const [page, setPage] = useUrlValue("page", "calendar"),
        [clinicId, setClinicId] = useUrlValue("clinic", "");
    const [theme, toggleTheme] = useTheme();
    const clinic = clinics.find((c) => c.id === clinicId) || clinics[0] || null;
    const loadMe = useCallback(async () => {
        const r = (await api("/me")) as SessionResponse;
        setUser(r.data);
        setClinics(r.clinics);
    }, []);
    useEffect(() => {
        let active = true;
        refresh()
            .then(() => {
                if (active) return loadMe();
            })
            .catch(() => {})
            .finally(() => {
                if (active) setBoot(false);
            });
        return () => {
            active = false;
        };
    }, [loadMe]);
    useEffect(() => {
        const expire = () => {
            setToken(null);
            setUser(null);
            setError("Сесія закінчилася. Увійдіть знову.");
        };
        window.addEventListener("clinicflow:session-expired", expire);
        return () =>
            window.removeEventListener("clinicflow:session-expired", expire);
    }, []);
    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(""), 5000);
        return () => clearTimeout(t);
    }, [toast]);
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
                e.preventDefault();
                setMobile(false);
                setQuick((q) => !q);
            }
            if (e.key === "Escape") setMobile(false);
        };
        window.addEventListener("keydown", key);
        return () => window.removeEventListener("keydown", key);
    }, []);
    const notify = (message = "Зміни збережено") => {
        setToast(message);
        setRevision((n) => n + 1);
    };
    if (boot)
        return (
            <div className="boot" role="status">
                <BrandMark />
                <p>Відкриваємо робочий день…</p>
            </div>
        );
    if (!user)
        return (
            <>
                <Login onLogin={loadMe} />
                {error && (
                    <div className="session-notice" role="alert">
                        {error}
                    </div>
                )}
            </>
        );
    const role = clinic?.role,
        admin = role === "CLINIC_ADMIN",
        doctor = role === "DOCTOR";
    const nav = [
        {
            id: "calendar",
            label: "План прийомів",
            show: !!clinic,
            group: "Робота",
        },
        { id: "patients", label: "Пацієнти", show: !!clinic, group: "Робота" },
        { id: "doctors", label: "Команда", show: !!clinic, group: "Робота" },
        { id: "services", label: "Послуги", show: !!clinic, group: "Робота" },
        {
            id: "overview",
            label: "Огляд клініки",
            show: admin,
            group: "Результати",
        },
        {
            id: "analytics",
            label: "Аналітика",
            show: admin,
            group: "Результати",
        },
        { id: "audit", label: "Журнал дій", show: admin, group: "Керування" },
        {
            id: "settings",
            label: "Налаштування",
            show: admin,
            group: "Керування",
        },
        {
            id: "platform",
            label: "Клініки платформи",
            show: user.platform_admin,
            group: "Платформа",
        },
    ].filter((n) => n.show);
    const selected = nav.find((n) => n.id === page) || nav[0];
    const navigate = (id: string) => {
        setPage(id);
        setMobile(false);
        setQuick(false);
        setQuery("");
    };
    return (
        <div className="app-shell">
            <a className="skip-link" href="#main-content">
                Перейти до вмісту
            </a>
            <header className="workspace-header">
                <div className="header-inner">
                    <a
                        className="brand"
                        href="/"
                        onClick={(e) => {
                            if (e.metaKey || e.ctrlKey) return;
                            e.preventDefault();
                            navigate("calendar");
                        }}
                    >
                        <BrandMark />
                        <span translate="no">
                            clinicflow<span className="brand-period">.</span>
                        </span>
                    </a>
                    {clinic && (
                        <div className="clinic-switch">
                            <Building2 size={17} aria-hidden="true" />
                            <select
                                aria-label="Поточна клініка"
                                value={clinic.id}
                                onChange={(e) => setClinicId(e.target.value)}
                            >
                                {clinics.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div className="header-tools">
                        <button
                            className="workspace-search"
                            aria-label="Швидка навігація"
                            onClick={() => setQuick(true)}
                        >
                            <Search size={18} aria-hidden="true" />
                            <span>Знайти розділ</span>
                            <kbd>⌘ K</kbd>
                        </button>
                        <button
                            className="icon-button theme-toggle"
                            onClick={toggleTheme}
                            aria-label={
                                theme === "light"
                                    ? "Увімкнути темну тему"
                                    : "Увімкнути світлу тему"
                            }
                        >
                            {theme === "light" ? (
                                <Moon size={19} />
                            ) : (
                                <Sun size={19} />
                            )}
                        </button>
                        <details className="account-menu">
                            <summary aria-label="Обліковий запис">
                                <span className="avatar" aria-hidden="true">
                                    {user.first_name[0]}
                                    {user.last_name[0]}
                                </span>
                                <span className="account-name">
                                    {user.first_name}
                                    <small>
                                        {role ? roleNames[role] : "Платформа"}
                                    </small>
                                </span>
                                <ChevronDown size={14} aria-hidden="true" />
                            </summary>
                            <div className="account-options">
                                <button
                                    onClick={(e) => {
                                        e.currentTarget
                                            .closest("details")
                                            ?.removeAttribute("open");
                                        navigate("profile");
                                    }}
                                >
                                    Мій профіль
                                    <ArrowUpRight
                                        size={15}
                                        aria-hidden="true"
                                    />
                                </button>
                                <button
                                    onClick={async () => {
                                        try {
                                            await api("/auth/logout", "POST");
                                            setToken(null);
                                            setUser(null);
                                        } catch {
                                            setToast(
                                                "Не вдалося завершити сесію. Спробуйте ще раз.",
                                            );
                                        }
                                    }}
                                >
                                    Вийти
                                    <LogOut size={15} aria-hidden="true" />
                                </button>
                            </div>
                        </details>
                        <button
                            className="mobile-menu icon-button"
                            aria-label="Меню"
                            aria-expanded={mobile}
                            aria-controls="workspace-nav"
                            onClick={() => setMobile(!mobile)}
                        >
                            {mobile ? <X size={21} /> : <Menu size={21} />}
                        </button>
                    </div>
                </div>
                <nav
                    id="workspace-nav"
                    className={"main-nav " + (mobile ? "is-open" : "")}
                    aria-label="Основна навігація"
                >
                    {nav.map((n) => (
                        <a
                            key={n.id}
                            href={`?page=${n.id}${clinic ? "&clinic=" + clinic.id : ""}`}
                            className={
                                selected?.id === n.id && page !== "profile"
                                    ? "active"
                                    : ""
                            }
                            aria-current={
                                selected?.id === n.id && page !== "profile"
                                    ? "page"
                                    : undefined
                            }
                            onClick={(e) => {
                                if (
                                    e.metaKey ||
                                    e.ctrlKey ||
                                    e.altKey ||
                                    e.shiftKey
                                )
                                    return;
                                e.preventDefault();
                                navigate(n.id);
                            }}
                        >
                            {n.label}
                            {n.id === "calendar" && (
                                <span
                                    className="nav-indicator"
                                    aria-hidden="true"
                                />
                            )}
                        </a>
                    ))}
                    <span className="nav-spacer" />
                    <span className="environment-mark">
                        <i />
                        {demoMode ? "Демопростір" : "Робочий простір"}
                    </span>
                </nav>
            </header>
            <main
                id="main-content"
                className="page-content"
                tabIndex={-1}
                key={clinic?.id}
            >
                {page === "profile" ? (
                    <Profile
                        user={user}
                        onSave={() => {
                            notify();
                            void loadMe();
                        }}
                    />
                ) : !clinic && !user.platform_admin ? (
                    <Empty
                        text="Немає активних клінік"
                        description="Зверніться до адміністратора, щоб отримати доступ до робочого простору."
                    />
                ) : selected?.id === "platform" ? (
                    <Platform notify={notify} />
                ) : (
                    clinic && (
                        <>
                            {selected?.id === "calendar" && (
                                <CalendarPage
                                    clinic={clinic}
                                    doctor={doctor}
                                    revision={revision}
                                    notify={notify}
                                />
                            )}
                            {["patients", "doctors", "services"].includes(
                                selected?.id || "",
                            ) && (
                                <Catalog
                                    key={selected!.id}
                                    resource={selected!.id}
                                    clinic={clinic}
                                    canEdit={
                                        selected!.id === "patients"
                                            ? !doctor
                                            : admin
                                    }
                                    notify={notify}
                                    revision={revision}
                                />
                            )}
                            {["overview", "analytics"].includes(
                                selected?.id || "",
                            ) && (
                                <Analytics
                                    key={selected?.id}
                                    clinic={clinic}
                                    revision={revision}
                                    mode={
                                        selected?.id === "overview"
                                            ? "overview"
                                            : "analytics"
                                    }
                                />
                            )}
                            {selected?.id === "settings" && (
                                <SettingsPage
                                    clinic={clinic}
                                    onSave={() => {
                                        notify();
                                        void loadMe();
                                    }}
                                />
                            )}
                            {selected?.id === "audit" && (
                                <Audit clinic={clinic} />
                            )}
                        </>
                    )
                )}
            </main>
            <footer className="app-footer">
                <span>Робочий простір для вашої клініки.</span>
                <span>
                    {demoMode ? "Синтетичні демодані" : "ClinicFlow"} <i />
                    {clinic?.timezone || "UTC"}
                    <a href="/api/docs" target="_blank" rel="noreferrer">
                        Документація
                        <ArrowUpRight size={12} aria-hidden="true" />
                    </a>
                </span>
            </footer>
            {toast && (
                <div role="status" aria-live="polite" className="toast">
                    <span className="toast-mark">
                        <Check size={18} aria-hidden="true" />
                    </span>
                    {toast}
                    <button
                        className="icon-button"
                        aria-label="Закрити повідомлення"
                        onClick={() => setToast("")}
                    >
                        <X size={16} />
                    </button>
                </div>
            )}
            {quick && (
                <Modal title="Швидка навігація" onClose={() => setQuick(false)}>
                    <label className="command-search">
                        Розділ
                        <input
                            autoFocus
                            autoComplete="off"
                            name="navigation-search"
                            placeholder="Куди перейдемо?"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </label>
                    <div className="command-results">
                        {nav
                            .filter((n) =>
                                n.label
                                    .toLocaleLowerCase("uk-UA")
                                    .includes(query.toLocaleLowerCase("uk-UA")),
                            )
                            .map((n) => (
                                <button
                                    key={n.id}
                                    onClick={() => navigate(n.id)}
                                >
                                    <span>
                                        {n.label}
                                        <small>{n.group}</small>
                                    </span>
                                    <ArrowRight size={17} aria-hidden="true" />
                                </button>
                            ))}
                        {!nav.some((n) =>
                            n.label
                                .toLocaleLowerCase("uk-UA")
                                .includes(query.toLocaleLowerCase("uk-UA")),
                        ) && (
                            <p className="muted">
                                Нічого не знайшлося. Спробуйте іншу назву.
                            </p>
                        )}
                    </div>
                    <p className="keyboard-help">
                        <Command size={13} aria-hidden="true" />
                        Ctrl / Cmd + K<span>Esc — закрити</span>
                    </p>
                </Modal>
            )}
        </div>
    );
}
