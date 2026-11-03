import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";
async function login(page: import("@playwright/test").Page) {
    await page.goto("/");
    await page.getByLabel("Пароль", { exact: true }).fill("ClinicFlow!2026");
    await page.getByRole("button", { name: "Увійти до простору" }).click();
    await expect(
        page.getByRole("heading", { name: "План прийомів" }),
    ).toBeVisible();
}
async function accessible(page: import("@playwright/test").Page) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        for (const animation of document.getAnimations()) {
            const timing = animation.effect?.getComputedTiming();
            if (timing && Number.isFinite(timing.endTime)) animation.finish();
        }
    });
    const result = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        result.violations,
        result.violations
            .map(
                (v) =>
                    `${v.id}: ${v.nodes.map((n) => n.target.join(" ")).join(", ")}`,
            )
            .join("\n"),
    ).toEqual([]);
}
test("light and dark themes, directories and modal pass accessibility checks", async ({
    page,
}) => {
    test.setTimeout(90000);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await login(page);
    for (const theme of ["light", "dark"]) {
        if (theme === "dark")
            await page
                .getByRole("button", { name: "Увімкнути темну тему" })
                .click();
        await accessible(page);
        await page.screenshot({
            path: `../var/screenshots/workspace-${theme}.png`,
            fullPage: true,
        });
        await page.getByRole("link", { name: "Пацієнти", exact: true }).click();
        await expect(
            page.getByRole("heading", { name: "Пацієнти", exact: true }),
        ).toBeVisible();
        await expect(page.getByRole("row").nth(1)).toBeVisible();
        await accessible(page);
        await page.getByRole("button", { name: "Додати пацієнта" }).click();
        await accessible(page);
        for (let i = 0; i < 15; i++) {
            await page.keyboard.press("Tab");
            expect(
                await page.evaluate(() =>
                    Boolean(
                        document
                            .querySelector("dialog[open]")
                            ?.contains(document.activeElement),
                    ),
                ),
            ).toBeTruthy();
        }
        await page.keyboard.press("Escape");
        await expect(page.getByRole("dialog")).not.toBeVisible();
        await expect(
            page.getByRole("button", { name: "Додати пацієнта" }),
        ).toBeFocused();
        await page
            .getByRole("link", { name: "План прийомів", exact: true })
            .click();
    }
    await page.reload();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
    await expect(
        page.getByRole("heading", { name: "План прийомів" }),
    ).toBeVisible();
});
test("deep links, browser history and keyboard navigation preserve context", async ({
    page,
}) => {
    await login(page);
    await page.getByRole("button", { name: "Тиждень", exact: true }).click();
    await expect(page).toHaveURL(/view=week/);
    const value = "2026-11-10";
    await page.getByLabel("Дата календаря").fill(value);
    await page.reload();
    await expect(page.getByLabel("Дата календаря")).toHaveValue(value);
    await expect(
        page.getByRole("button", { name: "Тиждень", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");
    await page.keyboard.press("Control+k");
    await expect(
        page.getByRole("dialog", { name: "Швидка навігація" }),
    ).toBeVisible();
    await page.getByLabel("Розділ", { exact: true }).fill("Послуги");
    await page.getByRole("button", { name: "Послуги Робота" }).click();
    await expect(
        page.getByRole("heading", { name: "Послуги та вартість", exact: true }),
    ).toBeVisible();
    await page.goBack();
    await expect(
        page.getByRole("heading", { name: "План прийомів" }),
    ).toBeVisible();
    await expect(page.getByLabel("Дата календаря")).toHaveValue(value);
});
test("small screens, zoom and mobile navigation remain usable", async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
    ).toBeLessThanOrEqual(390);
    await page.getByRole("button", { name: "Меню", exact: true }).click();
    await expect(
        page.getByRole("link", { name: "Налаштування", exact: true }),
    ).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(
        page.getByRole("link", { name: "Налаштування", exact: true }),
    ).not.toBeInViewport();
    await page.screenshot({
        path: "../var/screenshots/workspace-mobile.png",
        fullPage: true,
    });
    await page.setViewportSize({ width: 768, height: 1000 });
    await page.evaluate(
        () => (document.documentElement.style.fontSize = "125%"),
    );
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
    ).toBeLessThanOrEqual(768);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.getByRole("button", { name: "Новий запис" }).click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page.keyboard.press("Escape");
});

test("visit drawer supports keyboard tabs and the patient registry prefills booking", async ({
    page,
}) => {
    test.setTimeout(90000);
    await login(page);
    const selectedDate = await page.getByLabel("Дата календаря").inputValue();
    await page.locator(".agenda-entry").first().click();
    await expect(
        page.getByRole("dialog", { name: "Картка прийому" }),
    ).toBeVisible();
    await page.getByRole("tab", { name: "Огляд", exact: true }).focus();
    await page.keyboard.press("ArrowRight");
    await expect(
        page.getByRole("tab", { name: "Оплата", exact: true }),
    ).toBeFocused();
    await expect(page.getByLabel("Сума оплати у гривнях")).toBeVisible();
    await accessible(page);
    await page.keyboard.press("ArrowRight");
    await expect(page.getByRole("tab", { name: /Історія/ })).toHaveAttribute(
        "aria-selected",
        "true",
    );
    await expect(page.getByRole("tabpanel")).toContainText("Запис створено");
    await page.keyboard.press("Escape");
    await expect(page.getByLabel("Дата календаря")).toHaveValue(selectedDate);
    await page.getByRole("link", { name: "Пацієнти", exact: true }).click();
    const patient = page.getByRole("row").nth(1);
    const name = await patient.locator(".person-cell strong").innerText();
    await patient
        .getByRole("button", { name: "Записати", exact: true })
        .click();
    await expect(
        page.getByLabel("Пацієнт", { exact: true }).locator("option:checked"),
    ).toContainText(name);
    await expect(
        page.getByRole("button", { name: "Створити запис", exact: true }),
    ).toBeDisabled();
    await accessible(page);
    await page.keyboard.press("Escape");
    await expect(
        patient.getByRole("button", { name: "Записати", exact: true }),
    ).toBeFocused();
});

test("all redesigned sections remain accessible and fit desktop, tablet and phone", async ({
    page,
}) => {
    test.setTimeout(120000);
    await login(page);
    const sections = [
        ["Команда", "Команда клініки", ".team-directory article", "team"],
        [
            "Послуги",
            "Послуги та вартість",
            ".service-catalog article",
            "services",
        ],
        ["Огляд клініки", "Клініка в деталях", ".insight-band", "overview"],
        ["Аналітика", "Результати роботи", ".insight-band", "analytics"],
        ["Журнал дій", "Журнал дій", ".activity-stream article", "audit"],
        [
            "Налаштування",
            "Налаштування клініки",
            ".settings-content form",
            "settings",
        ],
    ];
    for (const theme of ["light", "dark"]) {
        if (theme === "dark")
            await page
                .getByRole("button", { name: "Увімкнути темну тему" })
                .click();
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const [nav, title, ready, file] of sections) {
                const link = page.getByRole("link", { name: nav, exact: true });
                if (!(await link.isVisible()))
                    await page
                        .getByRole("button", { name: "Меню", exact: true })
                        .click();
                await link.click();
                await expect(
                    page.getByRole("heading", { name: title, exact: true }),
                ).toBeVisible();
                await expect(page.locator(ready).first()).toBeVisible();
                await expect(page.getByRole("alert")).toHaveCount(0);
                expect(
                    await page.evaluate(
                        () => document.documentElement.scrollWidth,
                    ),
                    `${nav}, ${width}px, ${theme}`,
                ).toBeLessThanOrEqual(width);
                if (width !== 768) await accessible(page);
                if (width === 1440 || width === 390)
                    await page.screenshot({
                        path: `../var/screenshots/individual-${file}-${width}-${theme}.png`,
                        fullPage: true,
                    });
            }
        }
    }
});
