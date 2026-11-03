import { useCallback, useSyncExternalStore } from "react";
export function useUrlValue(key: string, fallback: string) {
    const subscribe = useCallback((listener: () => void) => {
        window.addEventListener("popstate", listener);
        window.addEventListener("clinicflow:navigation", listener);
        return () => {
            window.removeEventListener("popstate", listener);
            window.removeEventListener("clinicflow:navigation", listener);
        };
    }, []);
    const getSnapshot = () =>
        new URLSearchParams(window.location.search).get(key) ?? fallback;
    const value = useSyncExternalStore(subscribe, getSnapshot, () => fallback);
    const setValue = useCallback(
        (value: string, replace = false) => {
            const url = new URL(window.location.href);
            if (value === fallback || value === "")
                url.searchParams.delete(key);
            else url.searchParams.set(key, value);
            if (url.href === window.location.href) return;
            window.history[replace ? "replaceState" : "pushState"]({}, "", url);
            window.dispatchEvent(new Event("clinicflow:navigation"));
        },
        [key, fallback],
    );
    return [value, setValue] as const;
}
export function useTheme() {
    const subscribe = useCallback((listener: () => void) => {
        window.addEventListener("clinicflow:theme", listener);
        window.addEventListener("storage", listener);
        return () => {
            window.removeEventListener("clinicflow:theme", listener);
            window.removeEventListener("storage", listener);
        };
    }, []);
    const getSnapshot = () =>
        document.documentElement.dataset.theme === "dark" ? "dark" : "light";
    const theme = useSyncExternalStore(subscribe, getSnapshot, () => "light");
    const toggle = () => {
        const next = theme === "light" ? "dark" : "light";
        document.documentElement.dataset.theme = next;
        try {
            localStorage.setItem("clinicflow.theme.v1", next);
        } catch {
            /* Preferences are optional in private browsing. */
        }
        window.dispatchEvent(new Event("clinicflow:theme"));
    };
    return [theme, toggle] as const;
}
