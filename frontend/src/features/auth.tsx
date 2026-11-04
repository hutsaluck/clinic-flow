import { useState } from "react";
import {
    ArrowRight,
    Check,
    ShieldCheck,
    CalendarDays,
    Users,
    CreditCard,
} from "lucide-react";
import { api, setToken } from "../api";
import { err } from "../shared/ui";
import { BrandMark } from "../shared/brand";
import { demoMode } from "../shared/environment";

export function Login({ onLogin }: { onLogin: () => Promise<void> }) {
    const reset = new URLSearchParams(location.search).get("reset_token");
    const [mode, setMode] = useState(reset ? "reset" : "login"),
        [email, setEmail] = useState(demoMode ? "admin@clinicflow.test" : ""),
        [password, setPassword] = useState(
            demoMode && !reset ? "ClinicFlow!2026" : "",
        ),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [message, setMessage] = useState("");
    return (
        <div className="login-page">
            <header className="login-masthead">
                <a className="brand" href="/">
                    <BrandMark />
                    <span translate="no">clinicflow.</span>
                </a>
                <span>Простір, де день складається.</span>
            </header>
            <main className="login-stage">
                <section className="login-introduction">
                    <span className="section-context">Для клінік і людей</span>
                    <h1>
                        Робочий день,
                        <br />у якому все на місці.
                    </h1>
                    <p>
                        Від першого запису до завершеного прийому. Один простір
                        для команди, пацієнтів і щоденних рішень.
                    </p>
                    <div
                        className="login-route"
                        aria-label="Можливості робочого простору"
                    >
                        <span>
                            <CalendarDays size={20} aria-hidden="true" />
                            <strong>Продуманий план</strong>
                            <small>Зустрічі у зручному ритмі</small>
                        </span>
                        <i />
                        <span>
                            <Users size={20} aria-hidden="true" />
                            <strong>Злагоджена команда</strong>
                            <small>Кожен знає свій наступний крок</small>
                        </span>
                        <i />
                        <span>
                            <CreditCard size={20} aria-hidden="true" />
                            <strong>Прозорі оплати</strong>
                            <small>Кожна операція має свою історію</small>
                        </span>
                    </div>
                    <div className="login-assurance">
                        <Check size={15} aria-hidden="true" />
                        Власні ролі й окремі дані для кожної клініки
                    </div>
                </section>
                <section className="login-form">
                    <span className="login-form-mark">
                        <BrandMark />
                    </span>
                    <h2>
                        {mode === "login"
                            ? "З поверненням"
                            : mode === "forgot"
                              ? "Відновімо доступ"
                              : "Новий пароль"}
                    </h2>
                    <p>
                        {mode === "login"
                            ? "Увійдіть, щоб продовжити робочий день."
                            : "Допоможемо повернутися у ваш простір."}
                    </p>
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            try {
                                if (mode === "login") {
                                    const r = await api("/auth/login", "POST", {
                                        email,
                                        password,
                                    });
                                    setToken(r.data.access_token);
                                    await onLogin();
                                } else if (mode === "forgot") {
                                    const r = await api(
                                        "/auth/forgot-password",
                                        "POST",
                                        { email },
                                    );
                                    setMessage(r.data.message);
                                } else {
                                    await api("/auth/reset-password", "POST", {
                                        token: reset,
                                        password,
                                    });
                                    history.replaceState({}, "", "/");
                                    setMode("login");
                                    setMessage(
                                        "Пароль змінено. Увійдіть знову.",
                                    );
                                }
                            } catch (e) {
                                setError(err(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {mode !== "reset" && (
                            <label>
                                Email
                                <input
                                    required
                                    type="email"
                                    name="email"
                                    spellCheck={false}
                                    autoComplete="username"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                />
                            </label>
                        )}
                        {mode !== "forgot" && (
                            <label>
                                Пароль
                                <input
                                    required
                                    type="password"
                                    name="password"
                                    minLength={mode === "reset" ? 12 : 1}
                                    autoComplete={
                                        mode === "reset"
                                            ? "new-password"
                                            : "current-password"
                                    }
                                    placeholder="Введіть ваш пароль"
                                    value={password}
                                    onChange={(e) =>
                                        setPassword(e.target.value)
                                    }
                                />
                            </label>
                        )}
                        {error && (
                            <div role="alert" className="error">
                                {error}
                            </div>
                        )}
                        {message && <div className="notice">{message}</div>}
                        <button className="primary large" disabled={busy}>
                            {busy
                                ? "Зачекайте…"
                                : mode === "login"
                                  ? "Увійти до простору"
                                  : "Продовжити"}
                            <ArrowRight size={18} aria-hidden="true" />
                        </button>
                    </form>
                    <button
                        className="text-button"
                        onClick={() => {
                            setMode(mode === "login" ? "forgot" : "login");
                            setError("");
                            setMessage("");
                        }}
                    >
                        {mode === "login"
                            ? "Забули пароль?"
                            : "Повернутися до входу"}
                    </button>
                    {demoMode && (
                        <details className="demo-login">
                            <summary>
                                <ShieldCheck size={16} aria-hidden="true" />
                                Демооблікові записи
                            </summary>
                            <p>
                                admin / reception / doctor / platform
                                @clinicflow.test
                                <br />
                                Пароль: <code>ClinicFlow!2026</code>
                            </p>
                        </details>
                    )}
                </section>
            </main>
            <footer className="login-footer">
                <span>ClinicFlow. Створено для щоденної практики.</span>
                <span>
                    {demoMode
                        ? "Демонстраційний простір · синтетичні дані"
                        : "Простір для вашої клініки"}
                </span>
            </footer>
        </div>
    );
}
