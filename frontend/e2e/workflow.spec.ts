import { test, expect } from "@playwright/test";
test("admin can manage clinic and book a visit", async ({ page }) => {
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("/");
    await page
        .getByLabel("Email", { exact: true })
        .fill("admin@clinicflow.test");
    await page.getByLabel("Пароль", { exact: true }).fill("ClinicFlow!2026");
    await page.getByRole("button", { name: "Увійти до простору" }).click();
    await expect(
        page.getByRole("heading", { name: "План прийомів" }),
    ).toBeVisible();
    await page.screenshot({
        path: "../var/screenshots/calendar.png",
        fullPage: true,
    });
    await page.getByRole("link", { name: "Пацієнти", exact: true }).click();
    await page.getByRole("button", { name: "Додати пацієнта" }).click();
    const unique = Date.now();
    await page
        .getByLabel("Прізвище", { exact: true })
        .fill(`Тестовий${unique}`);
    await page.getByLabel("Ім’я", { exact: true }).fill("Олексій");
    await page.getByLabel("Телефон", { exact: true }).fill("+380501234567");
    await page.getByRole("button", { name: "Зберегти", exact: true }).click();
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await page
        .getByRole("link", { name: "План прийомів", exact: true })
        .click();
    await page.getByRole("button", { name: "Новий запис" }).click();
    await page.getByLabel("Знайти пацієнта").fill(`Тестовий${unique}`);
    await expect(
        page.getByLabel("Пацієнт", { exact: true }).locator("option"),
    ).toHaveCount(2);
    await page
        .getByLabel("Пацієнт", { exact: true })
        .selectOption({ index: 1 });
    await page.getByLabel("Лікар", { exact: true }).selectOption({ index: 1 });
    await expect(
        page.getByLabel("Послуга", { exact: true }).locator("option"),
    ).not.toHaveCount(1);
    await page
        .getByLabel("Послуга", { exact: true })
        .selectOption({ index: 1 });
    const target = new Date();
    target.setDate(target.getDate() + 7);
    await page
        .getByLabel("Дата прийому")
        .fill(target.toISOString().slice(0, 10));
    await page.locator(".slots button").first().click();
    await page
        .getByRole("button", { name: "Створити запис", exact: true })
        .click();
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await expect(
        page
            .getByRole("status")
            .filter({ hasText: "Пацієнта успішно записано" }),
    ).toContainText("Пацієнта успішно записано");
    await page
        .getByLabel("Дата календаря")
        .fill(target.toISOString().slice(0, 10));
    await page.getByRole("button", { name: "Список", exact: true }).click();
    const visitRow = page
        .getByRole("row")
        .filter({ hasText: `Тестовий${unique}` });
    await visitRow.click();
    await page
        .getByRole("button", { name: "Підтвердити", exact: true })
        .click();
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await visitRow.click();
    await page.getByRole("tab", { name: "Оплата", exact: true }).click();
    await page.getByLabel("Сума оплати у гривнях").fill("1");
    await page
        .getByRole("button", { name: "Додати оплату", exact: true })
        .click();
    await expect(page.locator(".payment-row")).toHaveCount(1);
    await page.getByRole("button", { name: "Сторнувати", exact: true }).click();
    await page
        .getByRole("button", { name: "Сторнувати оплату", exact: true })
        .click();
    await expect(page.locator(".payment-row")).toHaveCount(2);
    await page.getByRole("button", { name: "Закрити", exact: true }).click();
    await page
        .getByRole("link", { name: "Огляд клініки", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Фактично отримані оплати" }),
    ).toBeVisible();
    await page.screenshot({
        path: "../var/screenshots/analytics.png",
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
test("mobile layout and login validation", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/");
    await page.getByLabel("Пароль", { exact: true }).fill("ClinicFlow!2026");
    await page.getByRole("button", { name: "Увійти до простору" }).click();
    await expect(
        page.getByRole("heading", { name: "План прийомів" }),
    ).toBeVisible();
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
    ).toBeLessThanOrEqual(390);
    await page.screenshot({
        path: "../var/screenshots/mobile.png",
        fullPage: true,
    });
    await page.getByRole("button", { name: "Меню", exact: true }).click();
    await page.getByRole("link", { name: "Пацієнти", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Пацієнти", exact: true }),
    ).toBeVisible();
});

test("doctor and platform navigation preserve permission boundaries", async ({
    page,
}) => {
    for (const role of ["doctor", "platform"]) {
        await page.context().clearCookies();
        await page.goto("/");
        await page
            .getByLabel("Email", { exact: true })
            .fill(role + "@clinicflow.test");
        await page
            .getByLabel("Пароль", { exact: true })
            .fill("ClinicFlow!2026");
        await page.getByRole("button", { name: "Увійти до простору" }).click();
        if (role === "doctor") {
            await expect(
                page.getByRole("heading", { name: "План прийомів" }),
            ).toBeVisible();
            await expect(
                page.getByRole("button", { name: "Новий запис", exact: true }),
            ).not.toBeVisible();
            await page
                .getByRole("link", { name: "Пацієнти", exact: true })
                .click();
            await expect(
                page.getByRole("heading", { name: "Пацієнти", exact: true }),
            ).toBeVisible();
            await expect(
                page.getByRole("button", { name: "Додати пацієнта" }),
            ).not.toBeVisible();
            await expect(
                page.getByRole("link", { name: "Налаштування", exact: true }),
            ).not.toBeVisible();
        } else {
            await expect(
                page.getByRole("heading", { name: "Клініки платформи" }),
            ).toBeVisible();
            await expect(
                page.getByRole("link", { name: "Пацієнти", exact: true }),
            ).not.toBeVisible();
        }
    }
});
