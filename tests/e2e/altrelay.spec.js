/**
 * End-to-end tests: AltRelay in a real WordPress with the real block editor.
 *
 * These cover what neither the PHP nor the Jest suites can see: whether the
 * editor accepts the markup AltRelay writes, whether a fill really leaves a
 * post unchanged in the editor's eyes, and whether a change travels from
 * the editor to the Media Library and from there into other posts.
 *
 * They run on WordPress with and without core's own decorative setting
 * (7.1+), and take the path the site offers.
 *
 * @package AltRelay
 */

const { test, expect } = require("@playwright/test");
const {
  Api,
  blocksOf,
  drainQueue,
  imageBlock,
  login,
  noticeIds,
  openEditor,
  runBackgroundJobs,
} = require("./helpers");

const MARKER = "altrelay-decorative";
const DASHBOARD = "wp-admin/upload.php?page=altrelay";

test.describe.configure({ mode: "serial" });

/** @type {import('@playwright/test').Page} */
let page;
/** @type {Api} */
let api;
let image;
let other;
let errors = [];

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext();

  page = await context.newPage();
  page.on("pageerror", (error) => errors.push(error.message));

  await login(page);

  api = new Api(page);
  await api.start();

  image = await api.createImage("altrelay-e2e-dog", "A dog on a beach");
  other = await api.createImage("altrelay-e2e-cat", "A cat on a sofa");

  await drainQueue(page);
});

test.afterAll(async () => {
  // Leave the switch the way a fresh install has it.
  await page.goto(DASHBOARD);
  const off = page.getByRole("button", { name: "Turn review first off" });
  if (await off.count()) {
    await off.click();
  }

  await api.cleanUp();
  await page.context().close();
});

test.beforeEach(() => {
  errors = [];
});

test.afterEach(() => {
  expect(errors, "No script errors on any page.").toEqual([]);
});

test("the dashboard renders its sections", async () => {
  await page.goto(DASHBOARD);

  await expect(page.getByRole("heading", { name: "AltRelay Dashboard" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Review first" })).toBeVisible();
  await expect(page.locator("body")).not.toContainText(/Fatal error|Warning:|Notice:|Deprecated:/);

  // Each content list has a tab of its own.
  const tabs = page.getByRole("navigation", { name: "AltRelay lists" });

  await tabs.getByRole("link", { name: "Empty alt text in your content" }).click();
  await expect(page.getByRole("heading", { name: "Empty alt text in your content" })).toBeVisible();
  await expect(tabs.getByRole("link", { name: "Empty alt text in your content" })).toHaveAttribute("aria-current", "page");

  await tabs.getByRole("link", { name: "Images marked decorative" }).click();
  await expect(page.getByRole("heading", { name: "Images marked decorative" })).toBeVisible();
  await expect(page.locator("body")).not.toContainText(/Fatal error|Warning:|Notice:|Deprecated:/);
});

test("opening a post fills an empty alt without marking the post as changed", async () => {
  const post = await api.createPost("pull", imageBlock(image, ""));

  await openEditor(page, post.id);

  await expect
    .poll(async () => (await blocksOf(page, "core/image"))[0].attributes.alt)
    .toBe("A dog on a beach");

  const state = await page.evaluate(() => ({
    dirty: window.wp.data.select("core/editor").isEditedPostDirty(),
    undo: window.wp.data.select("core/editor").hasEditorUndo(),
  }));

  // Regression (external audit, bug 2): the fill used to make the post
  // dirty, add an undo step and trigger the "leave site?" dialog.
  expect(state).toEqual({ dirty: false, undo: false });
  expect((await blocksOf(page, "core/image"))[0].isValid).toBe(true);
  expect(await noticeIds(page)).toContain("altrelay-pulled");

  // Nothing was saved: the post still holds the empty alt.
  expect(await api.rawContent(post.id)).toContain('alt=""');
});

test("alt text typed in the editor reaches the Media Library on save, and other posts after that", async () => {
  const post = await api.createPost("push", imageBlock(image, "A dog on a beach"));
  const elsewhere = await api.createPost("elsewhere", imageBlock(image, "A dog on a beach"));

  await openEditor(page, post.id);

  const [block] = await blocksOf(page, "core/image");

  await page.evaluate(
    ([clientId]) =>
      window.wp.data
        .dispatch("core/block-editor")
        .updateBlockAttributes(clientId, { alt: "A brown dog running on a beach" }),
    [block.clientId],
  );

  // Not before the save: a discarded edit must never reach the library.
  await page.waitForTimeout(2500);
  expect(await api.getAlt(image.id)).toBe("A dog on a beach");

  await page.evaluate(() => window.wp.data.dispatch("core/editor").savePost());

  await expect.poll(() => api.getAlt(image.id)).toBe("A brown dog running on a beach");
  await expect.poll(() => noticeIds(page)).toContain("altrelay-pushed");

  // The other post is updated in the background, and the editor accepts it.
  await expect
    .poll(
      async () => {
        await runBackgroundJobs(page);
        return api.rawContent(elsewhere.id);
      },
      { timeout: 240000, intervals: [2000] },
    )
    .toContain('alt="A brown dog running on a beach"');

  await openEditor(page, elsewhere.id);

  const [synced] = await blocksOf(page, "core/image");

  expect(synced.isValid).toBe(true);
  expect(synced.attributes.alt).toBe("A brown dog running on a beach");

  await api.setAlt(image.id, "A dog on a beach");
  await drainQueue(page);
});

test("alt text in a draft that was only autosaved reaches the Media Library on the next save", async () => {
  // Regression (fourth audit, bug 1): an autosave pushes nothing, and the
  // reopened draft treated its alt text as already in the library.
  const kite = await api.createImage("altrelay-e2e-kite", "");
  const draft = await api.createPost("autosaved draft", imageBlock(kite, ""), "draft");

  await openEditor(page, draft.id);

  const [block] = await blocksOf(page, "core/image");

  await page.evaluate(
    ([clientId]) =>
      window.wp.data.dispatch("core/block-editor").updateBlockAttributes(clientId, { alt: "A red kite in flight" }),
    [block.clientId],
  );
  await page.evaluate(() => window.wp.data.dispatch("core/editor").autosave());

  await expect.poll(() => api.rawContent(draft.id)).toContain('alt="A red kite in flight"');
  await page.waitForTimeout(1500);
  expect(await api.getAlt(kite.id), "An autosave sends nothing.").toBe("");

  // Opened again later: the alt text is sent with the next save.
  await openEditor(page, draft.id);
  await page.waitForTimeout(1500);
  expect(await api.getAlt(kite.id), "Opening the draft sends nothing.").toBe("");

  await page.evaluate(() => window.wp.data.dispatch("core/editor").savePost());

  await expect.poll(() => api.getAlt(kite.id)).toBe("A red kite in flight");
  await drainQueue(page);
});

test("on WordPress 7.1, an image in a marked Group says so in the Content tab", async () => {
  // Regression (fourth audit, bug 5): the hint sat in the Settings tab,
  // while the Content tab with the alt text field opens first.
  const group =
    `<!-- wp:group {"className":"altrelay-decorative","layout":{"type":"constrained"}} -->\n` +
    `<div class="wp-block-group altrelay-decorative">${imageBlock(other, "")}</div>\n` +
    `<!-- /wp:group -->`;
  const post = await api.createPost("marked group", group);

  await openEditor(page, post.id);

  const coreDecorative = await page.evaluate(() => !!(window.altrelayEditor && window.altrelayEditor.coreDecorative));

  test.skip(!coreDecorative, "The Image block has its own decorative setting from WordPress 7.1.");

  const [image] = await blocksOf(page, "core/image");

  await page.evaluate(([clientId]) => {
    window.wp.data.dispatch("core/block-editor").selectBlock(clientId);
    window.wp.data.dispatch("core/edit-post").openGeneralSidebar("edit-post/block");
  }, [image.clientId]);

  const sidebar = page.locator(".block-editor-block-inspector");
  const hint = sidebar.getByText("Decorative image (set on a surrounding block)");

  await expect(hint).toBeVisible();
  // The tab that is open is the one with the alt text field.
  await expect(sidebar.getByLabel("Alternative text")).toBeVisible();
});

test("the editor fills and pushes while it shows the template around the post", async () => {
  // Regression (second audit, bug 2): with "Show template" on, the post's
  // blocks sit inside core/post-content, outside the editor's main block
  // tree, and AltRelay neither filled nor pushed them.
  const post = await api.createPost("template-view", "<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->");

  await openEditor(page, post.id);

  const switched = await page.evaluate(() => {
    const editor = window.wp.data.dispatch("core/editor");

    if (typeof editor.setRenderingMode !== "function") {
      return false;
    }

    editor.setRenderingMode("template-locked");
    return true;
  });

  // The template loads in steps, and the post content block is created
  // anew along the way. Only once it holds the post's own blocks is it the
  // one that stays; it is looked up again when the image is inserted.
  await page.evaluate(() => {
    window.altrelayTestPostContent = () => {
      const editor = window.wp.data.select("core/block-editor");
      const find = (blocks) => {
        for (const block of blocks) {
          if (block.name === "core/post-content") {
            return block.clientId;
          }
          const inner = find(block.innerBlocks || []);
          if (inner) {
            return inner;
          }
        }
        return null;
      };
      const id = find(editor.getBlocks());

      return id && editor.getBlocks(id).length > 0 ? id : null;
    };
  });

  const ready = switched
    ? await page
        .waitForFunction(() => window.altrelayTestPostContent(), null, { timeout: 30000 })
        .then(() => true)
        .catch(() => false)
    : false;

  test.skip(!ready, "The theme has no template with the post content to show.");

  await page.waitForTimeout(1000);

  const clientId = await page.evaluate((media) => {
    const block = window.wp.blocks.createBlock("core/image", { id: media.id, url: media.source_url, alt: "" });

    window.wp.data.dispatch("core/block-editor").insertBlocks(block, undefined, window.altrelayTestPostContent());
    return block.clientId;
  }, image);

  const altOf = () =>
    page.evaluate((id) => {
      const attrs = window.wp.data.select("core/block-editor").getBlockAttributes(id);
      return attrs ? attrs.alt : null;
    }, clientId);

  await expect.poll(altOf).toBe("A dog on a beach");

  await page.evaluate(
    (id) => window.wp.data.dispatch("core/block-editor").updateBlockAttributes(id, { alt: "A dog seen with the template" }),
    clientId,
  );
  await page.evaluate(() => window.wp.data.dispatch("core/editor").savePost());

  await expect.poll(() => api.getAlt(image.id)).toBe("A dog seen with the template");

  // Leave the editor the way it was.
  await page.evaluate(() => window.wp.data.dispatch("core/editor").setRenderingMode("post-only"));
  await api.setAlt(image.id, "A dog on a beach");
  await drainQueue(page);
});

test("a block whose file belongs to another image is neither filled nor pushed", async () => {
  // Regression (external audit, bug 1): a block pasted from another site
  // keeps that site's IDs, and one of them can be a local image's ID.
  const foreign = {
    id: other.id,
    source_url: "https://other.example/wp-content/uploads/2019/03/stranger.png",
  };
  const post = await api.createPost("foreign", imageBlock(foreign, ""));

  // On the frontend the stranger does not get the local image's alt text.
  await page.goto(post.link);
  await expect(page.locator(`img.wp-image-${other.id}`).first()).toHaveAttribute("alt", "");

  const lookup = page.waitForResponse(
    (response) => /media/.test(response.url()) && /include/.test(response.url()),
  );

  await openEditor(page, post.id);
  await lookup;
  await page.waitForTimeout(1500);

  const [block] = await blocksOf(page, "core/image");

  expect(block.attributes.alt).toBe("");

  await page.evaluate(
    ([clientId]) =>
      window.wp.data
        .dispatch("core/block-editor")
        .updateBlockAttributes(clientId, { alt: "About the stranger" }),
    [block.clientId],
  );
  await page.evaluate(() => window.wp.data.dispatch("core/editor").savePost());

  await expect
    .poll(async () => (await noticeIds(page)).some((id) => id.startsWith("altrelay-mismatch-")))
    .toBe(true);
  expect(await api.getAlt(other.id)).toBe("A cat on a sofa");
});

test("the dashboard marks and unmarks an image, and the editor accepts both results", async () => {
  const post = await api.createPost("decorative", imageBlock(other, ""));
  const item = `${post.id}:${other.id}`;

  await page.goto(`${DASHBOARD}&tab=content`);
  await page.locator(`button[name="altrelay_single"][value="decorative:${item}"]`).click();
  await expect(page.locator(".notice-success").first()).toContainText("Marked images as decorative in 1 post.");

  await openEditor(page, post.id);

  const usesCore = await page.evaluate(() => !!window.altrelayEditor.coreDecorative);
  let [block] = await blocksOf(page, "core/image");

  expect(block.isValid, "The editor accepts the marked block.").toBe(true);
  expect(block.attributes.alt).toBe("");

  if (usesCore) {
    expect(block.attributes.isDecorative).toBe(true);
  } else {
    expect(block.attributes.className).toContain(MARKER);
  }

  // Marked means left alone: no fill, even after a moment.
  await page.waitForTimeout(1500);
  expect((await blocksOf(page, "core/image"))[0].attributes.alt).toBe("");

  await page.goto(`${DASHBOARD}&tab=decorative`);
  await page.locator(`button[name="altrelay_single"][value="unmark:${item}"]`).click();
  await expect(page.locator(".notice-success").first()).toContainText("Removed the decorative mark in 1 post.");

  await openEditor(page, post.id);
  [block] = await blocksOf(page, "core/image");

  expect(block.isValid, "The editor accepts the unmarked block.").toBe(true);
  expect(block.attributes.alt).toBe("A cat on a sofa");
  expect(block.attributes.isDecorative).toBeFalsy();
  expect(block.attributes.className || "").not.toContain(MARKER);
});

test("the editor toggle marks a Cover block and refuses a linked image", async () => {
  const post = await api.createPost("toggle", "<!-- wp:paragraph -->\n<p>Toggle</p>\n<!-- /wp:paragraph -->");

  await openEditor(page, post.id);

  // Let the editor build the blocks, so the markup is its own.
  const ids = await page.evaluate(
    ([media]) => {
      const { createBlock } = window.wp.blocks;
      const cover = createBlock("core/cover", {
        url: media.source_url,
        id: media.id,
        alt: "A cat on a sofa",
        dimRatio: 50,
      });
      const mediaText = createBlock("core/media-text", {
        mediaId: media.id,
        mediaUrl: media.source_url,
        mediaType: "image",
        mediaAlt: "A cat on a sofa",
        href: "https://example.com/",
      });

      window.wp.data.dispatch("core/block-editor").insertBlocks([cover, mediaText]);

      return { cover: cover.clientId, mediaText: mediaText.clientId };
    },
    [other],
  );

  // A new Cover block gets a paragraph inside it, which takes the selection
  // a moment after the block was inserted. Wait for that to have happened.
  await expect
    .poll(async () => (await page.evaluate((id) => window.wp.data.select("core/block-editor").getBlockOrder(id).length, ids.cover)))
    .toBeGreaterThan(0);

  const select = async (clientId) => {
    await expect
      .poll(() =>
        page.evaluate((id) => {
          window.wp.data.dispatch("core/block-editor").selectBlock(id);

          const editPost = window.wp.data.dispatch("core/edit-post");
          if (editPost && editPost.openGeneralSidebar) {
            editPost.openGeneralSidebar("edit-post/block");
          }

          return window.wp.data.select("core/block-editor").getSelectedBlockClientId() === id;
        }, clientId),
      )
      .toBe(true);

    // Blocks with style options split their sidebar into tabs.
    const settings = page.getByRole("tab", { name: "Settings" });

    if (await settings.count()) {
      await settings.first().click();
    }
  };

  await select(ids.cover);

  const toggle = page.getByRole("checkbox", { name: "Decorative image" });

  await expect(toggle).toBeVisible();
  await expect(toggle).toBeEnabled();
  await toggle.check();

  const [cover] = await blocksOf(page, "core/cover");

  expect(cover.attributes.className).toContain(MARKER);
  expect(cover.attributes.alt).toBe("");
  expect(cover.isValid).toBe(true);

  // Regression (external audit, bug 7): a linked image cannot be decorative.
  await select(ids.mediaText);

  await expect(toggle).toBeVisible();
  await expect(toggle).toBeDisabled();
  await expect(page.getByText("This image is a link", { exact: false })).toBeVisible();
});

test("the widgets editor pushes a changed alt only when the widgets are saved", async () => {
  // The widgets screen has no post to save, so pushing on save means
  // pushing when the widget areas are saved, never while typing
  // (external audit, bug 12). Its widget areas keep their blocks outside
  // the main block tree, like the post content in template view.
  await page.goto("wp-admin/widgets.php");

  const ready = await page
    .waitForFunction(
      () =>
        window.wp &&
        window.wp.data &&
        window.wp.data.select("core/edit-widgets") &&
        window.wp.data
          .select("core/block-editor")
          .getBlocks()
          .some((block) => block.name === "core/widget-area"),
      null,
      { timeout: 30000 },
    )
    .then(() => true)
    .catch(() => false);

  test.skip(!ready, "This site has no block-based widgets screen (a block theme, or classic widgets).");

  await page.evaluate(() => {
    const preferences = window.wp.data.dispatch("core/preferences");

    if (preferences) {
      preferences.set("core/edit-widgets", "welcomeGuide", false);
    }
  });

  const clientId = await page.evaluate((media) => {
    const editor = window.wp.data.select("core/block-editor");
    const area = editor.getBlocks().find((block) => block.name === "core/widget-area");

    // A closed widget area does not hand its blocks to the widgets it saves.
    window.wp.data.dispatch("core/edit-widgets").setIsWidgetAreaOpen(area.clientId, true);
    const block = window.wp.blocks.createBlock("core/image", {
      id: media.id,
      url: media.source_url,
      alt: "A dog on a beach",
    });

    window.wp.data.dispatch("core/block-editor").insertBlocks(block, 0, area.clientId);
    return block.clientId;
  }, image);

  await page.waitForTimeout(1500);
  await page.evaluate(
    (id) => window.wp.data.dispatch("core/block-editor").updateBlockAttributes(id, { alt: "A dog in the sidebar" }),
    clientId,
  );

  // Not while typing.
  await page.waitForTimeout(2500);
  expect(await api.getAlt(image.id)).toBe("A dog on a beach");

  await page.evaluate(() => window.wp.data.dispatch("core/edit-widgets").saveEditedWidgetAreas());

  await expect.poll(() => api.getAlt(image.id), { timeout: 60000 }).toBe("A dog in the sidebar");

  // Delete the widget again. Removing its block in the editor would only
  // move it to the inactive widgets.
  await page.evaluate(async (mediaId) => {
    const widgets = await window.wp.apiFetch({ path: "/wp/v2/widgets?context=edit" });

    for (const widget of widgets) {
      const content = widget.instance && widget.instance.raw ? String(widget.instance.raw.content || "") : "";

      if (content.includes(`wp-image-${mediaId}`)) {
        await window.wp.apiFetch({ path: `/wp/v2/widgets/${widget.id}?force=true`, method: "DELETE" });
      }
    }
  }, image.id);

  await api.setAlt(image.id, "A dog on a beach");
  await drainQueue(page);
});

test("an image left without alt text on purpose is marked decorative wherever it is used", async () => {
  // The case WordPress itself suggests: alt text emptied in the Media
  // Library because the image is decorative. It used to stay listed as
  // missing alt text, with no way to say it was meant that way.
  const swirl = await api.createImage("altrelay-e2e-swirl", "");
  const post = await api.createPost("swirl", imageBlock(swirl, ""));
  const file = swirl.source_url.split("/").pop();

  await page.goto(DASHBOARD);
  await expect(page.getByRole("heading", { name: "Images without alt text in the Media Library" })).toBeVisible();

  // The accessible name starts with the visible label, then names the file.
  const escaped = file.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const markName = new RegExp(`^Mark decorative ?: ${escaped}, wherever it is used$`);

  // Enter in an alt text field still saves alt text, not the row button.
  const button = page.getByRole("button", { name: markName });

  await expect(button).toHaveAttribute("form", "altrelay-mark-image");

  // It asks first; saying no changes nothing.
  page.once("dialog", (dialog) => dialog.dismiss());
  await button.click();
  await expect(button).toHaveCount(1);
  expect(await api.rawContent(post.id)).not.toMatch(/"isDecorative":true|altrelay-decorative/);

  // Saying yes marks the posts in the background. WP-Cron may already have
  // done so by the time the dashboard is back, so only the notice is certain.
  page.once("dialog", (dialog) => dialog.accept());
  await button.click();
  await expect(page.locator(".notice-success", { hasText: "AltRelay is marking the image decorative in every post that uses it" })).toBeVisible();

  await drainQueue(page);
  await page.goto(DASHBOARD);

  await expect(page.getByRole("button", { name: markName })).toHaveCount(0);
  expect(await api.rawContent(post.id)).toMatch(/"isDecorative":true|altrelay-decorative/);

  // And back.
  await page.locator(".altrelay-recorded-decorative summary").click();
  await page.getByRole("button", { name: new RegExp(`^List as missing again ?: ${escaped}$`) }).click();
  await expect(page.locator(".notice-success", { hasText: "The image is listed as missing alt text again." })).toBeVisible();
  await expect(page.getByRole("button", { name: markName })).toHaveCount(1);
});

test("review first holds the frontend back until it is switched off", async () => {
  await page.goto(DASHBOARD);
  await page.getByRole("button", { name: "Turn review first on" }).click();
  await expect(page.locator(".notice-success").first()).toContainText("Review first is on.");

  const post = await api.createPost("review first", imageBlock(other, ""));
  const frontendImage = page.locator(`img.wp-image-${other.id}`).first();

  await page.goto(post.link);
  await expect(frontendImage).toHaveAttribute("alt", "");

  // The editor leaves the empty alt alone as well.
  await openEditor(page, post.id);
  await page.waitForTimeout(2500);
  expect((await blocksOf(page, "core/image"))[0].attributes.alt).toBe("");

  await page.goto(DASHBOARD);
  await page.getByRole("button", { name: "Turn review first off" }).click();
  await expect(page.locator(".notice-success").first()).toContainText("Review first is off.");

  await page.goto(post.link);
  await expect(frontendImage).toHaveAttribute("alt", "A cat on a sofa");
});
