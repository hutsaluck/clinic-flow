import React, { useEffect, useId, useRef } from "react";
import { CalendarDays, X } from "lucide-react";
import { type Row } from "../api";

export const statuses: Row = {
    SCHEDULED: "Очікує підтвердження",
    CONFIRMED: "Підтверджено",
    COMPLETED: "Завершено",
    CANCELLED: "Скасовано",
    NO_SHOW: "Неявка",
};
export const roleNames: Row = {
    CLINIC_ADMIN: "Адміністратор",
    RECEPTIONIST: "Реєстратор",
    DOCTOR: "Лікар",
};
export const methods: Row = {
    CASH: "Готівка",
    CARD_TERMINAL: "Термінал",
    BANK_TRANSFER: "Банківський переказ",
    OTHER: "Інше",
};
export const fullName = (r: Row) => `${r.last_name} ${r.first_name}`;
export const money = (n: number, c = "UAH") =>
    new Intl.NumberFormat("uk-UA", {
        style: "currency",
        currency: c,
        maximumFractionDigits: 2,
    }).format(n / 100);
export const dateKey = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
export const addDays = (d: Date, n: number) => {
    const v = new Date(d);
    v.setDate(v.getDate() + n);
    return v;
};
export const time = (d: string, tz = "Europe/Kyiv") =>
    new Intl.DateTimeFormat("uk-UA", {
        hour: "2-digit",
        minute: "2-digit",
        timeZone: tz,
    }).format(new Date(d));
export const localDay = (d: string, tz: string) =>
    new Intl.DateTimeFormat("en-CA", {
        timeZone: tz,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date(d));
export const err = (e: unknown) =>
    e instanceof Error ? e.message : "Сталася помилка";

export function Badge({ status }: { status: string }) {
    return (
        <span className={`badge ${status.toLowerCase()}`}>
            <i />
            {statuses[status] || status}
        </span>
    );
}

export function Modal({
    title,
    children,
    onClose,
    wide = false,
    variant = "center",
}: {
    title: string;
    children: React.ReactNode;
    onClose: () => void;
    wide?: boolean;
    variant?: "center" | "drawer" | "workspace";
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    useEffect(() => {
        const element = dialog.current;
        const previous =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        const overflow = document.documentElement.style.overflow;
        element?.showModal();
        document.documentElement.style.overflow = "hidden";
        return () => {
            element?.close();
            document.documentElement.style.overflow = overflow;
            if (previous?.isConnected) previous.focus({ preventScroll: true });
        };
    }, []);
    return (
        <dialog
            ref={dialog}
            className={"modal-backdrop modal-" + variant}
            aria-labelledby={titleId}
            onKeyDown={(event) => {
                if (event.key !== "Tab") return;
                const controls = Array.from(
                    event.currentTarget.querySelectorAll<HTMLElement>(
                        'button:not([disabled]),a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])',
                    ),
                ).filter((element) => element.getClientRects().length > 0);
                const first = controls[0],
                    last = controls.at(-1);
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first?.focus();
                }
            }}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                if (event.target === dialog.current) onClose();
            }}
        >
            <section className={"modal " + (wide ? "wide" : "")}>
                <header>
                    <h2 id={titleId}>{title}</h2>
                    <button
                        type="button"
                        className="icon-button"
                        onClick={onClose}
                        aria-label="Закрити"
                    >
                        <X size={20} aria-hidden="true" />
                    </button>
                </header>
                {children}
            </section>
        </dialog>
    );
}

export function Empty({
    text = "Тут поки немає даних",
    description = "Додайте дані або змініть фільтри.",
}: {
    text?: string;
    description?: string;
}) {
    return (
        <div className="empty">
            <CalendarDays size={32} aria-hidden="true" />
            <h3>{text}</h3>
            <p>{description}</p>
        </div>
    );
}
