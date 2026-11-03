export type Row = Record<string, any>;
let token: string | null = null;
let refreshing: Promise<void> | null = null;
export function setToken(value: string | null) {
    token = value;
}
export async function refresh() {
    if (!refreshing) {
        const rotate = async () => {
            const r = await fetch("/api/v1/auth/refresh", {
                method: "POST",
                credentials: "same-origin",
            });
            const j = await r.json();
            if (!r.ok) throw new Error(j.error?.message || "Увійдіть знову");
            token = j.data.access_token;
        };
        // Coordinate rotation across tabs sharing one HttpOnly cookie.
        refreshing = (
            navigator.locks
                ? navigator.locks.request("clinicflow-session", rotate)
                : rotate()
        ).finally(() => {
            refreshing = null;
        });
    }
    return refreshing;
}
export async function api(
    path: string,
    method = "GET",
    body?: Row,
    key?: string,
    retry = true,
): Promise<Row> {
    const r = await fetch("/api/v1" + path, {
        method,
        credentials: "same-origin",
        headers: {
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...(body ? { "Content-Type": "application/json" } : {}),
            ...(key ? { "Idempotency-Key": key } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    if (r.status === 401 && retry && !path.startsWith("/auth/")) {
        try {
            await refresh();
        } catch (error) {
            window.dispatchEvent(new Event("clinicflow:session-expired"));
            throw error;
        }
        return api(path, method, body, key, false);
    }
    if (r.status === 204) return {};
    const data = await r.json();
    if (!r.ok)
        throw new Error(data.error?.message || "Не вдалося виконати запит");
    return data;
}
export async function download(path: string) {
    const r = await fetch("/api/v1" + path, {
        headers: { Authorization: `Bearer ${token}` },
    });
    if (!r.ok) throw new Error("Не вдалося експортувати дані");
    const url = URL.createObjectURL(await r.blob());
    const a = document.createElement("a");
    a.href = url;
    a.download = "appointments.csv";
    a.click();
    URL.revokeObjectURL(url);
}
