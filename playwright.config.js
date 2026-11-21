/**
 * Playwright configuration for the end-to-end tests.
 *
 * Runs against http://localhost:8888 (wp-env) as admin / password by
 * default. Point it at another site with WP_BASE_URL, WP_USERNAME and
 * WP_PASSWORD.
 *
 * @package AltRelay
 */

const { defineConfig } = require("@playwright/test");

module.exports = defineConfig({
  testDir: "tests/e2e",
  outputDir: "build/e2e-results",
  timeout: 360000,
  expect: { timeout: 30000 },
  // The tests share one WordPress site and its one "review first" switch.
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [["list"], ["html", { open: "never", outputFolder: "build/e2e-report" }]]
    : "list",
  use: {
    // Always with a trailing slash, and the tests use paths without a
    // leading one, so a site in a subdirectory (a network site) works too.
    baseURL: (process.env.WP_BASE_URL || "http://localhost:8888").replace(/\/?$/, "/"),
    ignoreHTTPSErrors: true,
    navigationTimeout: 90000,
    actionTimeout: 30000,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [{ name: "chromium", use: { browserName: "chromium" } }],
});
