/**
 * Helpers for the end-to-end tests.
 *
 * Everything a test needs is created through the REST API as the logged-in
 * user and deleted again afterwards, so the suite runs against any
 * WordPress site with AltRelay active: wp-env in CI, or a local site.
 *
 * @package AltRelay
 */

const USERNAME = process.env.WP_USERNAME || "admin";
const PASSWORD = process.env.WP_PASSWORD || "password";

// Sites that move the login form, as security plugins do, set WP_LOGIN_PATH.
const LOGIN_PATH = process.env.WP_LOGIN_PATH || "wp-login.php";

/**
 * A 1x1 PNG. Enough for WordPress to treat the upload as an image.
 */
const PNG = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==",
  "base64",
);

/**
 * Log in through the login form.
 *
 * @param {import('@playwright/test').Page} page     The page.
 * @param {string}                          username Default: WP_USERNAME.
 * @param {string}                          password Default: WP_PASSWORD.
 */
async function login(page, username = USERNAME, password = PASSWORD) {
  await page.goto(LOGIN_PATH);

  // WordPress focuses and selects the username field shortly after the
  // login page loads. A fill that lands at that moment types into the
  // username field, so both values are checked before submitting.
  for (let attempt = 0; attempt < 5; attempt++) {
    await page.fill("#user_login", username);
    await page.fill("#user_pass", password);

    if ((await page.inputValue("#user_login")) === username && (await page.inputValue("#user_pass")) === password) {
      break;
    }

    await page.waitForTimeout(250);
  }

  await page.click("#wp-submit");
  await page.waitForURL(/wp-admin/);
}

/**
 * A client for the REST API that acts as the logged-in user.
 */
class Api {
  /**
   * @param {import('@playwright/test').Page} page A logged-in page.
   */
  constructor(page) {
    this.page = page;
    this.nonce = "";
    this.created = [];
  }

  /**
   * Fetch the nonce the REST API expects from a cookie-authenticated user.
   */
  async start() {
    const response = await this.page.request.get(
      "wp-admin/admin-ajax.php?action=rest-nonce",
    );
    this.nonce = (await response.text()).trim();
  }

  /**
   * Send a request. Uses ?rest_route=, which works with any permalink setting.
   *
   * @param {string} method  HTTP method.
   * @param {string} route   Route, such as "/wp/v2/posts".
   * @param {Object} options params, data, headers.
   * @return {Promise<Object>} The decoded response.
   */
  async send(method, route, options = {}) {
    const response = await this.page.request.fetch("index.php", {
      method,
      params: { rest_route: route, ...(options.params || {}) },
      headers: { "X-WP-Nonce": this.nonce, ...(options.headers || {}) },
      data: options.data,
      failOnStatusCode: true,
    });

    return response.json();
  }

  /**
   * Upload an image and give it alt text.
   *
   * @param {string} name File name without extension.
   * @param {string} alt  Alt text, or "" for none.
   * @return {Promise<Object>} The media record.
   */
  async createImage(name, alt) {
    const media = await this.send("POST", "/wp/v2/media", {
      headers: {
        "Content-Type": "image/png",
        "Content-Disposition": `attachment; filename="${name}-${Date.now()}.png"`,
      },
      data: PNG,
    });

    this.created.push(`/wp/v2/media/${media.id}`);

    if (alt) {
      await this.setAlt(media.id, alt);
    }

    return media;
  }

  /**
   * Change an image's alt text in the Media Library.
   *
   * @param {number} id  Attachment ID.
   * @param {string} alt Alt text.
   */
  async setAlt(id, alt) {
    await this.send("POST", `/wp/v2/media/${id}`, { data: { alt_text: alt } });
  }

  /**
   * Read an image's alt text from the Media Library.
   *
   * @param {number} id Attachment ID.
   * @return {Promise<string>} Alt text.
   */
  async getAlt(id) {
    const media = await this.send("GET", `/wp/v2/media/${id}`, {
      params: { _fields: "alt_text" },
    });

    return media.alt_text;
  }

  /**
   * Create a post, published unless a status is given.
   *
   * @param {string} title   Title.
   * @param {string} content Block markup.
   * @param {string} status  Post status.
   * @return {Promise<Object>} The post record.
   */
  async createPost(title, content, status = "publish") {
    const post = await this.send("POST", "/wp/v2/posts", {
      data: { title: `AltRelay E2E: ${title}`, status, content },
    });

    this.created.push(`/wp/v2/posts/${post.id}`);

    return post;
  }

  /**
   * Read a post's content exactly as stored.
   *
   * @param {number} id Post ID.
   * @return {Promise<string>} Raw content.
   */
  async rawContent(id) {
    const post = await this.send("GET", `/wp/v2/posts/${id}`, {
      params: { context: "edit", _fields: "content" },
    });

    return post.content.raw;
  }

  /**
   * Delete everything this client created.
   */
  async cleanUp() {
    for (const route of this.created.reverse()) {
      await this.page.request.fetch("index.php", {
        method: "DELETE",
        params: { rest_route: route, force: "true" },
        headers: { "X-WP-Nonce": this.nonce },
      });
    }

    this.created = [];
  }
}

/**
 * Markup of an Image block, as the editor saves it.
 *
 * @param {Object} media A media record, or { id, source_url }.
 * @param {string} alt   Alt text in the markup.
 * @return {string} Block markup.
 */
function imageBlock(media, alt) {
  return (
    `<!-- wp:image {"id":${media.id},"sizeSlug":"full","linkDestination":"none"} -->\n` +
    `<figure class="wp-block-image size-full"><img src="${media.source_url}" alt="${alt}" class="wp-image-${media.id}"/></figure>\n` +
    `<!-- /wp:image -->`
  );
}

/**
 * Open a post in the block editor and wait until its blocks are there.
 *
 * @param {import('@playwright/test').Page} page   The page.
 * @param {number}                          postId Post ID.
 */
async function openEditor(page, postId) {
  await page.goto(`wp-admin/post.php?post=${postId}&action=edit`);
  await page.waitForFunction(
    () =>
      window.wp &&
      window.wp.data &&
      window.wp.data.select("core/block-editor") &&
      window.wp.data.select("core/block-editor").getBlocks().length > 0,
  );

  // The welcome guide is a modal that would sit on top of everything.
  await page.evaluate(() => {
    const preferences = window.wp.data.dispatch("core/preferences");

    if (preferences) {
      preferences.set("core/edit-post", "welcomeGuide", false);
      preferences.set("core", "enableChoosePatternModal", false);
    }
  });
}

/**
 * The attributes and validity of every block of a type in the editor.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string}                          name Block name.
 * @return {Promise<Array<Object>>} { clientId, isValid, attributes } per block.
 */
function blocksOf(page, name) {
  return page.evaluate((blockName) => {
    const found = [];
    const walk = (blocks) =>
      blocks.forEach((block) => {
        if (block.name === blockName) {
          found.push({
            clientId: block.clientId,
            isValid: block.isValid,
            attributes: block.attributes,
          });
        }
        walk(block.innerBlocks || []);
      });

    walk(window.wp.data.select("core/block-editor").getBlocks());

    return found;
  }, name);
}

/**
 * IDs of the notices the editor is showing.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<Array<string>>} Notice IDs.
 */
function noticeIds(page) {
  return page.evaluate(() =>
    window.wp.data
      .select("core/notices")
      .getNotices()
      .map((notice) => notice.id),
  );
}

/**
 * Give background jobs a chance to run: WP-Cron directly, and an admin
 * request for Action Scheduler's own runner.
 *
 * @param {import('@playwright/test').Page} page The page.
 */
async function runBackgroundJobs(page) {
  // Without a doing_wp_cron parameter, wp-cron.php takes the lock itself
  // and runs whatever is due, the way a system cron job calls it. While a
  // page load has spawned WP-Cron itself, though, the lock is taken and
  // this call does nothing.
  await page.request.get("wp-cron.php");

  // So on wp-env a must-use plugin (tests/e2e/mu-plugins) also runs the
  // queue directly. Elsewhere the request does nothing, and on an admin
  // request Action Scheduler runs its own queue anyway.
  const response = await page.request.get("wp-admin/admin-ajax.php?action=altrelay-e2e-tick");

  return response.ok() ? response.text() : "";
}

/**
 * Wait until AltRelay's background queue is empty.
 *
 * Giving an image alt text queues a job that fills every empty copy of it.
 * A test that is about an empty alt must not start while such a job is
 * still on its way, or the job decides the outcome instead of the test.
 * The dashboard says how many jobs are waiting.
 *
 * @param {import('@playwright/test').Page} page The page.
 */
async function drainQueue(page) {
  let state = "";

  for (let attempt = 0; attempt < 90; attempt++) {
    state = await runBackgroundJobs(page);
    await page.goto("wp-admin/upload.php?page=altrelay");

    if ((await page.locator(".altrelay-queue-notice").count()) === 0) {
      // A job that has just been picked up is no longer "waiting".
      await page.waitForTimeout(2000);
      return;
    }

    await page.waitForTimeout(2000);
  }

  throw new Error(`AltRelay's background queue did not empty. Last state: ${state || "unknown"}`);
}

module.exports = {
  Api,
  blocksOf,
  drainQueue,
  imageBlock,
  login,
  noticeIds,
  openEditor,
  runBackgroundJobs,
};
