import { defineConfig } from "@playwright/test";
export default defineConfig({
    testDir: "e2e",
    fullyParallel: false,
    use: {
        baseURL: process.env.E2E_URL || "http://127.0.0.1:8080",
        trace: "retain-on-failure",
    },
    reporter: "list",
});
