/**
 * End-to-end tests on a multisite network.
 *
 * They run on a site of the network that is not the main one, as that
 * site's administrator. On a network only Super Admins may post unfiltered
 * HTML, so every change a site administrator makes is synced by a background
 * job that kses would filter: the case these tests are about.
 *
 * Set WP_MULTISITE=1 to run them, with WP_BASE_URL pointing at the site,
 * WP_USERNAME and WP_PASSWORD for its administrator, and WP_SUPER_USERNAME
 * and WP_SUPER_PASSWORD for a Super Admin. CI sets the network up with
 * wp-env; see the multisite entry of the e2e job in .github/workflows/ci.yml.
 *
 * @package AltRelay
 */

const { test, expect } = require("@playwright/test");
const { Api, drainQueue, imageBlock, login, runBackgroundJobs } = require("./helpers");

const MULTISITE = !!process.env.WP_MULTISITE;
const SUPER_USERNAME = process.env.WP_SUPER_USERNAME || "admin";
const SUPER_PASSWORD = process.env.WP_SUPER_PASSWORD || "password";
const DASHBOARD = "wp-admin/upload.php?page=altrelay";
const EMBED =
  '<!-- wp:html --><iframe src="https://example.com/embed"></iframe><form action="/subscribe"><input type="email" name="e"></form><!-- /wp:html -->';

test.describe.configure({ mode: "serial" });

test.describe("on a multisite network", () => {
  test.skip(!MULTISITE, "Runs on a multisite network only (WP_MULTISITE=1).");

  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {import('@playwright/test').Page} */
  let superPage;
  /** @type {Api} */
  let api;
  /** @type {Api} */
  let superApi;
  let image;
  let errors = [];

  test.beforeAll(async ({ browser }) => {
    page = await (await browser.newContext()).newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    await login(page);
    api = new Api(page);
    await api.start();

    superPage = await (await browser.newContext()).newPage();
    await login(superPage, SUPER_USERNAME, SUPER_PASSWORD);
    superApi = new Api(superPage);
    await superApi.start();

    image = await api.createImage("altrelay-ms-dog", "A dog on a beach");
    await drainQueue(page);
  });

  test.afterAll(async () => {
    await superApi.cleanUp();
    await api.cleanUp();
    await superPage.context().close();
    await page.context().close();
  });

  test.beforeEach(() => {
    errors = [];
  });

  test.afterEach(() => {
    expect(errors, "No script errors on any page.").toEqual([]);
  });

  test("AltRelay is active on the whole network, and its dashboard works on the site", async () => {
    // The network admin lives on the main site; its address is in the admin bar.
    await superPage.goto("wp-admin/");
    const network = await superPage.locator("#wp-admin-bar-network-admin > a").getAttribute("href");

    expect(network).toBeTruthy();
    await superPage.goto(new URL("plugins.php", String(network)).href);
    // The row of the plugin whose main file is altrelay.php, whatever its folder is called.
    await expect(superPage.locator('tr[data-plugin$="/altrelay.php"]')).toContainText("Network Deactivate");

    await page.goto(DASHBOARD);
    await expect(page.getByRole("heading", { name: "AltRelay Dashboard" })).toBeVisible();
    await expect(page.locator("body")).not.toContainText(/Fatal error|Warning:|Notice:|Deprecated:/);
  });

  test("the site administrator cannot post unfiltered HTML", async () => {
    // The precondition of the next test: without it, it would prove nothing.
    const own = await api.createPost("ms-own", EMBED);

    expect(await api.rawContent(own.id)).not.toContain("<iframe");
  });

  test("a change by the site administrator reaches a Super Admin's post and keeps its embeds", async () => {
    const post = await superApi.createPost("ms-embed", imageBlock(image, "A dog on a beach") + "\n\n" + EMBED);

    expect(await superApi.rawContent(post.id)).toContain("<iframe");

    await api.setAlt(image.id, "A brown dog on a beach");

    await expect
      .poll(
        async () => {
          await runBackgroundJobs(page);
          return superApi.rawContent(post.id);
        },
        { timeout: 240000, intervals: [2000] },
      )
      .toContain('alt="A brown dog on a beach"');

    const saved = await superApi.rawContent(post.id);

    expect(saved).toContain('<iframe src="https://example.com/embed"></iframe>');
    expect(saved).toContain('<form action="/subscribe">');

    await api.setAlt(image.id, "A dog on a beach");
    await drainQueue(page);
  });
});
