import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./specs",
  timeout: 60_000,
  retries: 1,
  workers: 1, // sequential – WP state is shared
  reporter: [["list"], ["html", { open: "never" }]],

  use: {
    baseURL: process.env.WP_URL ?? "http://localhost:8080",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
    trace: "retain-on-failure",
  },

  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
});
