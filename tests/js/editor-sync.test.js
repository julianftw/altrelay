/**
 * Jest tests for AltRelay Editor Sync (editor-sync.js).
 *
 * The script is evaluated against a mocked wp.* environment that models the
 * parts of the block editor it relies on: separate data stores with named
 * subscriptions, a block tree that keeps its reference until something
 * changes, the save lifecycles of the post and widgets editors, the notices
 * store, the REST API for media and the BlockEdit filter.
 *
 * @package AltRelay
 */

const path = require("path");
const timers = jest.requireActual("timers");

const SCRIPT_PATH = path.join(__dirname, "..", "..", "assets", "js", "editor-sync.js");

const UPLOADS = "https://example.com/wp-content/uploads/";

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise} Resolves after the microtask queue drains.
 */
function flush() {
  return new Promise(function (resolve) {
    timers.setImmediate(resolve);
  });
}

/**
 * The file a test attachment owns.
 *
 * @param {number} id Attachment ID.
 * @return {string} Path relative to the uploads folder.
 */
function fileFor(id) {
  return "2026/01/image-" + id + ".jpg";
}

/**
 * A media record as the REST API returns it.
 *
 * @param {number} id        Attachment ID.
 * @param {string} altText   Alt text.
 * @param {Object} overrides Fields to replace.
 * @return {Object} Media record.
 */
function media(id, altText, overrides) {
  return Object.assign(
    {
      id: id,
      alt_text: altText || "",
      source_url: UPLOADS + fileFor(id),
      media_details: {
        file: fileFor(id),
        sizes: {
          large: { file: "image-" + id + "-1024x683.jpg" },
        },
      },
    },
    overrides || {},
  );
}

/**
 * Build a block. Media blocks get the URL of their own attachment unless
 * the test supplies one.
 *
 * @param {string} clientId   Client ID.
 * @param {Object} attributes Attributes.
 * @param {string} name       Block name.
 * @return {Object} Block.
 */
function block(clientId, attributes, name) {
  const blockName = name || "core/image";
  const attrs = Object.assign({}, attributes);

  if (blockName === "core/media-text") {
    if (attrs.mediaId && attrs.mediaUrl === undefined) {
      attrs.mediaUrl = UPLOADS + fileFor(attrs.mediaId);
    }
  } else if (attrs.id && attrs.url === undefined) {
    attrs.url = UPLOADS + fileFor(attrs.id);
  }

  return {
    name: blockName,
    clientId: clientId,
    attributes: attrs,
    innerBlocks: [],
  };
}

/**
 * Evaluate editor-sync.js in a mocked editor.
 *
 * @param {Object} options Harness options.
 * @return {Object} Handles for driving and inspecting the editor.
 */
function load(options) {
  const opts = options || {};
  const state = {
    blocks: opts.blocks || [],
    patterns: opts.patterns || {},
    saving: false,
    autosaving: false,
    savingEntities: false,
    saveSucceeded: true,
    savingWidgets: false,
    nextChangePersistent: true,
    // The core data store: which entities are being saved, and how it went.
    entitySaving: {},
    entityAutosave: {},
    entityErrors: {},
  };
  const subscribers = [];
  const apiCalls = [];
  const updated = [];
  const notices = [];
  const removedNotices = [];
  const filters = [];
  const customizerHandlers = {};

  function findBlock(blocks, clientId) {
    for (const b of blocks) {
      if (b.clientId === clientId) {
        return b;
      }
      const inner = findBlock(b.innerBlocks || [], clientId);
      if (inner) {
        return inner;
      }
    }
    for (const key of Object.keys(state.patterns)) {
      const found = state.patterns[key].find((b) => b.clientId === clientId);
      if (found) {
        return found;
      }
    }
    return null;
  }

  /**
   * Apply attribute changes immutably, so getBlocks() returns a new array.
   */
  function setAttrs(clientId, attrs) {
    // Like the store: a list keeps its identity unless one of its blocks changed.
    function update(blocks) {
      const next = blocks.map(function (b) {
        if (b.clientId === clientId) {
          return Object.assign({}, b, {
            attributes: Object.assign({}, b.attributes, attrs),
          });
        }
        if (b.innerBlocks && b.innerBlocks.length) {
          const inner = update(b.innerBlocks);
          return inner === b.innerBlocks ? b : Object.assign({}, b, { innerBlocks: inner });
        }
        return b;
      });

      return next.every((b, i) => b === blocks[i]) ? blocks : next;
    }
    state.blocks = update(state.blocks);
    Object.keys(state.patterns).forEach(function (key) {
      state.patterns[key] = update(state.patterns[key]);
    });
  }

  /**
   * The client IDs of a block's ancestors, innermost first, across the
   * lists the store keeps outside the main tree (state.patterns).
   */
  function parentsOf(clientId) {
    function search(blocks, trail) {
      for (const b of blocks) {
        if (b.clientId === clientId) {
          return trail;
        }
        const nested = search(b.innerBlocks || [], [b.clientId].concat(trail));
        if (nested) {
          return nested;
        }
        if (state.patterns[b.clientId]) {
          const inPattern = search(state.patterns[b.clientId], [b.clientId].concat(trail));
          if (inPattern) {
            return inPattern;
          }
        }
      }
      return null;
    }
    return search(state.blocks, []) || [];
  }

  const blockEditor = {
    getBlocks: jest.fn(function (clientId) {
      return clientId ? state.patterns[clientId] || [] : state.blocks;
    }),
    getBlockAttributes: jest.fn(function (clientId) {
      const b = findBlock(state.blocks, clientId);
      return b ? b.attributes : null;
    }),
  };

  if (opts.storeSelectors !== false) {
    // The real store lists ancestors outermost first.
    blockEditor.getBlockParents = (clientId) => parentsOf(clientId).slice().reverse();
    blockEditor.areInnerBlocksControlled = (clientId) =>
      Object.prototype.hasOwnProperty.call(state.patterns, clientId);
  }
  const postEditor =
    opts.postEditor === false
      ? null
      : {
          isSavingPost: () => state.saving,
          isAutosavingPost: () => state.autosaving,
          didPostSaveRequestSucceed: () => state.saveSucceeded,
          isSavingNonPostEntityChanges: () => state.savingEntities,
          getCurrentPostType: () => "post",
          getCurrentPostId: () => 1,
          getCurrentPostAttribute: (name) => (name === "status" ? opts.postStatus || "publish" : undefined),
        };

  const entityKey = (kind, name, id) => kind + "/" + name + "/" + id;
  const coreStore =
    opts.entityStore === false
      ? undefined
      : {
          isSavingEntityRecord: (kind, name, id) => !!state.entitySaving[entityKey(kind, name, id)],
          isAutosavingEntityRecord: (kind, name, id) => !!state.entityAutosave[entityKey(kind, name, id)],
          getLastEntitySaveError: (kind, name, id) => state.entityErrors[entityKey(kind, name, id)] || null,
          getCurrentTheme: () => ({ stylesheet: "theme" }),
        };

  const widgetsEditor = opts.widgetsEditor
    ? { isSavingWidgetAreas: () => state.savingWidgets }
    : null;

  const noticesStore =
    opts.notices === false
      ? null
      : {
          createWarningNotice: (message, o) =>
            notices.push({ type: "warning", message: message, options: o }),
          createInfoNotice: (message, o) =>
            notices.push({ type: "info", message: message, options: o }),
          removeNotice: (id) => removedNotices.push(id),
        };

  const blockEditorDispatch = {
    updateBlockAttributes: function (clientId, attrs) {
      updated.push({
        clientId: clientId,
        attrs: attrs,
        persistent: state.nextChangePersistent,
      });
      state.nextChangePersistent = true;
      setAttrs(clientId, attrs);
    },
  };

  if (opts.markNotPersistent !== false) {
    blockEditorDispatch.__unstableMarkNextChangeAsNotPersistent = function () {
      state.nextChangePersistent = false;
    };
  }

  /**
   * Default REST API: the library holds one item per ID named in
   * opts.library (ID => alt text, or a full record), and saves succeed.
   */
  function defaultApi(request) {
    if (request.method) {
      return Promise.resolve({});
    }

    const library = opts.library || {};
    const ids = /include=([\d,]+)/.exec(request.path)[1].split(",");

    return Promise.resolve(
      ids
        .filter((id) => Object.prototype.hasOwnProperty.call(library, id))
        .map((id) =>
          typeof library[id] === "object" ? library[id] : media(Number(id), library[id]),
        ),
    );
  }

  global.altrelayEditor = opts.settings;

  global.wp = {
    data: {
      subscribe: (cb, store) => subscribers.push({ callback: cb, store: store }),
      // Runs the selector once, as a render would; the calls are kept.
      useSelect: jest.fn((mapSelect, deps) => mapSelect(global.wp.data.select, deps)),
      select: function (store) {
        if (store === "core/block-editor") {
          return opts.noBlockEditor ? undefined : blockEditor;
        }
        if (store === "core/editor") {
          return postEditor;
        }
        if (store === "core/edit-widgets") {
          return widgetsEditor;
        }
        if (store === "core") {
          return coreStore;
        }
        return undefined;
      },
      dispatch: function (store) {
        if (store === "core/block-editor") {
          return blockEditorDispatch;
        }
        if (store === "core/notices") {
          return noticesStore;
        }
        return undefined;
      },
    },
    apiFetch: jest.fn(function (request) {
      apiCalls.push(request);
      if (typeof opts.apiFetch === "function") {
        const result = opts.apiFetch(request);
        if (result !== undefined) {
          return result;
        }
      }
      return defaultApi(request);
    }),
  };

  if (opts.customizer) {
    global.wp.customize = {
      bind: (event, handler) => (customizerHandlers[event] = handler),
    };
  }

  if (opts.i18n !== false) {
    global.wp.i18n = {
      __: (text) => text,
      _n: (single, plural, number) => (number === 1 ? single : plural),
      sprintf: (format, value) => format.replace(/%[sd]/, String(value)),
    };
  }

  if (opts.noUseSelect) {
    delete global.wp.data.useSelect;
  }

  if (opts.ui !== false) {
    global.wp.hooks = {
      addFilter: (hook, namespace, callback) =>
        filters.push({ hook: hook, namespace: namespace, callback: callback }),
    };
    global.wp.compose = { createHigherOrderComponent: (fn) => fn };
    global.wp.element = {
      createElement: (type, props, ...children) => ({
        type: type,
        props: props,
        children: children,
      }),
      Fragment: "Fragment",
    };
    global.wp.blockEditor = { InspectorControls: "InspectorControls" };
    global.wp.components = {
      PanelBody: "PanelBody",
      ToggleControl: "ToggleControl",
    };
  }

  // A fresh module per test, so state never leaks between tests and the
  // script is instrumented for coverage.
  jest.isolateModules(function () {
    require(SCRIPT_PATH);
  });

  function tick() {
    subscribers.forEach((s) => s.callback());
  }

  async function save(saveOptions) {
    const o = saveOptions || {};
    const post = "postType/post/1";
    state.saving = true;
    state.autosaving = !!o.autosave;
    if (postEditor) {
      state.entitySaving[post] = true;
      state.entityAutosave[post] = !!o.autosave;
    }
    tick();
    state.saving = false;
    state.autosaving = false;
    state.saveSucceeded = o.succeed !== false;
    state.entitySaving[post] = false;
    state.entityAutosave[post] = false;
    state.entityErrors[post] = o.succeed === false ? new Error("failed") : null;
    tick();
    await flush();
  }

  /**
   * Save one entity, the way the save panel or the Site Editor does.
   */
  async function saveEntity(key, saveOptions) {
    const o = saveOptions || {};
    state.entitySaving[key] = true;
    state.savingEntities = true;
    tick();
    state.entitySaving[key] = false;
    state.savingEntities = false;
    state.entityErrors[key] = o.succeed === false ? new Error("failed") : null;
    tick();
    await flush();
  }

  return {
    state: state,
    blockEditor: blockEditor,
    subscribers: subscribers,
    apiCalls: apiCalls,
    updated: updated,
    notices: notices,
    removedNotices: removedNotices,
    filters: filters,
    customizerHandlers: customizerHandlers,
    tick: tick,
    save: save,
    saveEntity: saveEntity,
    edit: function (clientId, attrs) {
      setAttrs(clientId, attrs);
      tick();
    },
    pushes: () => apiCalls.filter((c) => c.method === "POST"),
    lookups: () => apiCalls.filter((c) => !c.method),
    snackbars: () => notices.filter((n) => n.options && n.options.type === "snackbar"),
    messages: () => notices.filter((n) => !n.options || n.options.type !== "snackbar"),
  };
}

beforeEach(function () {
  jest.useFakeTimers();
  jest.spyOn(console, "warn").mockImplementation(() => {});
});

afterEach(function () {
  jest.useRealTimers();
  jest.restoreAllMocks();
  delete global.wp;
  delete global.altrelayEditor;
  delete global.document;
});

describe("loading", function () {
  test("listens to the stores it needs by name and registers the toggle", function () {
    const editor = load();

    expect(editor.subscribers.map((s) => s.store)).toEqual([
      "core/block-editor",
      "core/editor",
      "core",
    ]);
    expect(editor.filters).toHaveLength(1);
    expect(editor.filters[0].hook).toBe("editor.BlockEdit");
    expect(editor.filters[0].namespace).toBe("altrelay/decorative-toggle");
  });

  test("listens to the widgets editor where there is one", function () {
    const editor = load({ postEditor: false, widgetsEditor: true });

    expect(editor.subscribers.map((s) => s.store)).toEqual([
      "core/block-editor",
      "core",
      "core/edit-widgets",
      "core",
    ]);
  });

  test("waits for the page's scripts before looking for stores", function () {
    const listeners = {};
    global.document = {
      readyState: "loading",
      addEventListener: (event, handler) => (listeners[event] = handler),
    };

    const editor = load();

    expect(editor.subscribers).toHaveLength(0);

    listeners.DOMContentLoaded();

    expect(editor.subscribers).toHaveLength(3);
  });

  test("starts at once when the page has already loaded", function () {
    global.document = { readyState: "complete", addEventListener: jest.fn() };

    const editor = load();

    expect(editor.subscribers).toHaveLength(3);
    expect(global.document.addEventListener).not.toHaveBeenCalled();
  });

  test("works without the UI packages", function () {
    const editor = load({ ui: false });

    expect(editor.filters).toHaveLength(0);
    expect(() => editor.tick()).not.toThrow();
  });

  test("does not crash when the block editor store is missing", function () {
    const editor = load({ noBlockEditor: true });

    expect(() => editor.tick()).not.toThrow();
    expect(editor.apiCalls).toHaveLength(0);
  });
});

describe("pull", function () {
  test("asks for every supported empty block in one request", async function () {
    const editor = load({
      blocks: [
        block("img", { id: 10, alt: "" }),
        block("cov", { id: 20, alt: "" }, "core/cover"),
        block("mt", { mediaId: 30, mediaAlt: "" }, "core/media-text"),
        block("para", {}, "core/paragraph"),
      ],
      library: { 10: "Ten", 20: "Twenty", 30: "Thirty" },
    });

    editor.tick();
    await flush();

    expect(editor.lookups()).toHaveLength(1);
    expect(editor.lookups()[0].path).toContain("/wp/v2/media?include=10,20,30&per_page=100");
    expect(editor.lookups()[0].path).toContain("_fields=id,alt_text,source_url,media_details.file");
    expect(editor.updated.map((u) => u.attrs)).toEqual([
      { alt: "Ten" },
      { alt: "Twenty" },
      { mediaAlt: "Thirty" },
    ]);
  });

  test("asks once for an image that is used twice", async function () {
    const editor = load({
      blocks: [block("a", { id: 10, alt: "" }), block("b", { id: 10, alt: "" })],
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.lookups()).toHaveLength(1);
    expect(editor.lookups()[0].path).toContain("include=10&");
    expect(editor.updated).toHaveLength(2);
  });

  test("splits more images than one request can return", async function () {
    const blocks = [];
    const library = {};

    for (let id = 1; id <= 101; id++) {
      blocks.push(block("b" + id, { id: id, alt: "" }));
      library[id] = "Alt " + id;
    }

    const editor = load({ blocks: blocks, library: library });

    editor.tick();
    await flush();

    expect(editor.lookups()).toHaveLength(2);
    expect(editor.updated).toHaveLength(101);
  });

  test("applies the library alt without pushing it back", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      library: { 10: "From library" },
    });

    editor.tick();
    await flush();
    editor.tick();
    await editor.save();

    expect(editor.updated).toEqual([
      { clientId: "img", attrs: { alt: "From library" }, persistent: false },
    ]);
    expect(editor.pushes()).toHaveLength(0);
  });

  test("fills without marking the post as changed", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      library: { 10: "From library" },
    });

    editor.tick();
    await flush();

    expect(editor.updated[0].persistent).toBe(false);
  });

  test("still fills on editors without non-persistent changes", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      library: { 10: "From library" },
      markNotPersistent: false,
    });

    editor.tick();
    await flush();

    expect(editor.updated[0].attrs).toEqual({ alt: "From library" });
  });

  test("announces how many images were filled", async function () {
    const editor = load({
      blocks: [block("a", { id: 10, alt: "" }), block("b", { id: 11, alt: "" })],
      library: { 10: "Ten", 11: "Eleven" },
    });

    editor.tick();
    await flush();

    expect(editor.snackbars()).toHaveLength(1);
    expect(editor.snackbars()[0].message).toContain("for 2 images");
    expect(editor.snackbars()[0].options.id).toBe("altrelay-pulled");
  });

  test("announces a single image in the singular", async function () {
    const editor = load({
      blocks: [block("a", { id: 10, alt: "" })],
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.snackbars()[0].message).toContain("for 1 image.");
  });

  test("leaves decorative blocks alone", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", className: "altrelay-decorative" })],
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("respects a site that treats empty alt as decorative", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      settings: { marker: "altrelay-decorative", emptyIsDecorative: true },
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("skips video backgrounds and video media", async function () {
    const editor = load({
      blocks: [
        block("cov", { id: 20, alt: "", backgroundType: "video" }, "core/cover"),
        block("mt", { mediaId: 30, mediaAlt: "", mediaType: "video" }, "core/media-text"),
      ],
    });

    editor.tick();
    await flush();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("skips blocks without media", async function () {
    const editor = load({ blocks: [block("img", { alt: "" })] });

    editor.tick();
    await flush();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("asks once per block", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "" })] });

    editor.tick();
    await flush();
    editor.edit("img", { align: "wide" });
    editor.tick();
    await flush();

    expect(editor.lookups()).toHaveLength(1);
  });

  test("does not overwrite alt typed while the request was in flight", async function () {
    let resolve;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      apiFetch: () => new Promise((r) => (resolve = r)),
    });

    editor.tick();
    await flush();
    editor.edit("img", { alt: "Typed" });
    resolve([media(10, "From library")]);
    await flush();

    expect(editor.updated).toHaveLength(0);
  });

  test.each([
    ["AltRelay's class", { className: "altrelay-decorative" }],
    ["core's setting", { isDecorative: true }],
  ])("does not fill a block marked decorative by %s while the request was in flight", async function (label, attrs) {
    let resolve;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      apiFetch: () => new Promise((r) => (resolve = r)),
    });

    editor.tick();
    await flush();
    editor.edit("img", attrs);
    resolve([media(10, "From library")]);
    await flush();

    expect(editor.updated).toHaveLength(0);
  });

  test("does not fill a block whose image was swapped while the request was in flight", async function () {
    let resolve;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      apiFetch: (request) =>
        request.path.includes("include=10&") ? new Promise((r) => (resolve = r)) : undefined,
      library: { 11: "" },
    });

    editor.tick();
    await flush();
    editor.edit("img", { id: 11, url: UPLOADS + fileFor(11) });
    resolve([media(10, "From library")]);
    await flush();

    expect(editor.updated).toHaveLength(0);
  });

  test("does not fill a block that was removed while the request was in flight", async function () {
    let resolve;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      apiFetch: () => new Promise((r) => (resolve = r)),
    });

    editor.tick();
    await flush();
    editor.state.blocks = [];
    resolve([media(10, "From library")]);
    await flush();

    expect(editor.updated).toHaveLength(0);
  });

  test("does nothing when the library has no alt", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      library: { 10: "" },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(0);
    expect(editor.notices).toHaveLength(0);
  });

  test("does nothing when the library has no such item", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "" })] });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(0);
  });

  test("survives a failed request", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      apiFetch: () => Promise.reject(new Error("offline")),
    });

    editor.tick();
    await flush();

    expect(console.warn).toHaveBeenCalled();
    expect(editor.updated).toHaveLength(0);
  });

  test("survives a response that is not a list", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      apiFetch: () => Promise.resolve({ code: "unexpected" }),
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(0);
  });
});

describe("images whose file does not belong to the Media Library item", function () {
  test("a foreign file with a local ID is not filled", async function () {
    const editor = load({
      blocks: [
        block("img", { id: 10, alt: "", url: "https://other.example/wp-content/uploads/2020/05/stranger.jpg" }),
      ],
      library: { 10: "A local image" },
    });

    editor.tick();
    await flush();

    expect(editor.lookups()).toHaveLength(1);
    expect(editor.updated).toHaveLength(0);
  });

  test("a block without a URL is not filled", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", url: "" })],
      library: { 10: "A local image" },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(0);
  });

  test("a foreign file with a local ID is never pushed, and the user is told why", async function () {
    const editor = load({
      blocks: [
        block("img", { id: 10, alt: "Old", url: "https://other.example/wp-content/uploads/2020/05/stranger.jpg" }),
      ],
      library: { 10: "A local image" },
    });

    editor.tick();
    editor.edit("img", { alt: "Describes the stranger" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
    expect(editor.messages()).toHaveLength(1);
    expect(editor.messages()[0].type).toBe("info");
    expect(editor.messages()[0].message).toContain("does not belong to Media Library item 10");
    expect(editor.messages()[0].options.id).toBe("altrelay-mismatch-img");
  });

  test("an ID that does not exist here is never pushed", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Old" })] });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
    expect(editor.notices).toHaveLength(0);
  });

  test.each([
    ["the original", UPLOADS + "2026/01/image-10.jpg"],
    ["a registered size", UPLOADS + "2026/01/image-10-1024x683.jpg"],
    ["a size that is no longer registered", UPLOADS + "2026/01/image-10-300x200.jpg"],
    ["the scaled copy", UPLOADS + "2026/01/image-10-scaled.jpg"],
    ["an edited copy", UPLOADS + "2026/01/image-10-e1700000000000-150x150.jpg"],
    ["a converted format", UPLOADS + "2026/01/image-10.jpg.webp"],
    ["a CDN host", "https://cdn.example.net/site/uploads/2026/01/image-10.jpg?w=800"],
    ["a protocol-relative URL", "//example.com/wp-content/uploads/2026/01/image-10.jpg"],
    ["an encoded URL", UPLOADS + "2026/01/image%2D10.jpg"],
  ])("recognises %s as the item's file", async function (label, url) {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", url: url })],
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(1);
  });

  test.each([
    ["another file in the same folder", UPLOADS + "2026/01/image-100.jpg"],
    ["the same name in another folder", UPLOADS + "2025/12/image-10.jpg"],
    ["a name that only ends the same", UPLOADS + "2026/01/my-image-10.jpg"],
    ["a URL that cannot be decoded", UPLOADS + "2026/01/%E0%A4%A.jpg"],
  ])("rejects %s", async function (label, url) {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", url: url })],
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(0);
  });

  // Regression (sixth audit): the rule exists in PHP and here. Both are
  // tested against the same cases, so they cannot drift apart.
  const SHARED = require("../fixtures/image-matching.json");

  test.each(SHARED.cases.map((c) => [c.label, c.url, c.matches]))(
    "follows the shared case: %s",
    async function (label, url, matches) {
      const file = SHARED.attachment.file;
      const sizes = {};

      Object.keys(SHARED.attachment.sizes).forEach((name) => {
        sizes[name] = { file: SHARED.attachment.sizes[name] };
      });

      const editor = load({
        blocks: [block("img", { id: 42, alt: "", url: url })],
        library: {
          42: media(42, "Forty-two", {
            source_url: "https://example.com/wp-content/uploads/" + file,
            media_details: { file: file, sizes: sizes },
          }),
        },
      });

      editor.tick();
      await flush();

      expect(editor.updated.length > 0).toBe(matches);
    },
  );

  test("recognises the original of a scaled upload", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", url: UPLOADS + "2026/01/photo.jpg" })],
      library: {
        10: media(10, "Ten", {
          source_url: UPLOADS + "2026/01/photo-scaled.jpg",
          media_details: { file: "2026/01/photo-scaled.jpg", original_image: "photo.jpg" },
        }),
      },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(1);
  });

  test("falls back to the item's URL when it has no file details", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", url: UPLOADS + "2026/01/logo.svg" })],
      library: {
        10: { id: 10, alt_text: "Logo", source_url: UPLOADS + "2026/01/logo.svg" },
      },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(1);
  });

  test("matches files stored without a folder", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", url: "flat.jpg" })],
      library: {
        10: { id: 10, alt_text: "Flat", media_details: { file: "flat.jpg", sizes: { thumb: {} } } },
      },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(1);
  });
});

describe("block bindings and pattern overrides", function () {
  test.each([
    ["the alt text", { bindings: { alt: { source: "core/post-meta" } } }],
    ["the image", { bindings: { id: { source: "core/post-meta" } } }],
    ["the URL", { bindings: { url: { source: "core/post-meta" } } }],
    ["a pattern override", { bindings: { __default: { source: "core/pattern-overrides" } } }],
  ])("a block bound through %s is neither filled nor pushed", async function (label, metadata) {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", metadata: metadata })],
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();
    editor.edit("img", { alt: "Typed" });
    await editor.save();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("a block with other metadata is handled as usual", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", metadata: { name: "Hero" } })],
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(1);
  });

  test("a block bound while the request was in flight is not filled", async function () {
    let resolve;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "" })],
      apiFetch: () => new Promise((r) => (resolve = r)),
    });

    editor.tick();
    await flush();
    editor.edit("img", { metadata: { bindings: { alt: { source: "core/post-meta" } } } });
    resolve([media(10, "From library")]);
    await flush();

    expect(editor.updated).toHaveLength(0);
  });
});

describe("push on save", function () {
  test("opening and saving a post never writes to the library", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Post alt" })],
      library: { 10: "Library alt" },
    });

    editor.tick();
    await editor.save();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("waits for the save, then pushes the final value once", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "N" });
    editor.edit("img", { alt: "Ne" });
    editor.edit("img", { alt: "New" });
    jest.advanceTimersByTime(10000);
    await flush();

    expect(editor.apiCalls).toHaveLength(0);

    await editor.save();

    expect(editor.pushes()).toEqual([
      { path: "/wp/v2/media/10", method: "POST", data: { alt_text: "New" } },
    ]);
  });

  test("confirms the save and says that other posts change too", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    expect(editor.snackbars()).toHaveLength(1);
    expect(editor.snackbars()[0].message).toContain("saved to the Media Library");
    expect(editor.snackbars()[0].message).toContain("Other posts");
    expect(editor.snackbars()[0].options.id).toBe("altrelay-pushed");
  });

  test("looks an item up once, however often it is pushed", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "First" });
    await editor.save();
    editor.edit("img", { alt: "Second" });
    await editor.save();

    expect(editor.lookups()).toHaveLength(1);
    expect(editor.pushes().map((c) => c.data.alt_text)).toEqual(["First", "Second"]);
  });

  test("autosaves do not push", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Old" })], library: { 10: "Old" } });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save({ autosave: true });

    expect(editor.pushes()).toHaveLength(0);
  });

  test("a failed save does not push", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Old" })], library: { 10: "Old" } });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save({ succeed: false });

    expect(editor.pushes()).toHaveLength(0);

    await editor.save();

    expect(editor.pushes()).toHaveLength(1);
  });

  test("saving template and pattern changes pushes too", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Old" })], library: { 10: "Old" } });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.saveEntity("postType/post/1");

    expect(editor.pushes()).toHaveLength(1);
  });

  test("a draft's alt text that was only autosaved is pushed on the next save", async function () {
    // Regression (fourth audit, bug 1): typed into a draft, autosaved, and
    // never in the library, because opening the draft again treated the
    // alt text as already synced.
    const editor = load({
      blocks: [block("img", { id: 10, alt: "A dog" }), block("done", { id: 11, alt: "Cat" })],
      library: { 10: "", 11: "Cat" },
      postStatus: "draft",
    });

    editor.tick();
    await flush();

    expect(editor.pushes()).toHaveLength(0);

    // An autosave still pushes nothing.
    await editor.save({ autosave: true });
    expect(editor.pushes()).toHaveLength(0);

    await editor.save();

    expect(editor.pushes()).toEqual([
      { path: "/wp/v2/media/10", method: "POST", data: { alt_text: "A dog" } },
    ]);
  });

  test("a draft's alt text does not replace a description added in the meantime", async function () {
    let libraryAlt = "";
    const editor = load({
      blocks: [block("img", { id: 10, alt: "A dog" })],
      apiFetch: (request) => (request.method ? undefined : Promise.resolve([media(10, libraryAlt)])),
      postStatus: "auto-draft",
    });

    editor.tick();
    await flush();

    libraryAlt = "A dog on a beach";
    await editor.save();

    expect(editor.lookups()).toHaveLength(2);
    expect(editor.pushes()).toHaveLength(0);
  });

  test("an edit made while the library is asked wins over the draft's alt text", async function () {
    let resolve;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "A dog" })],
      apiFetch: (request) => (request.method ? undefined : new Promise((r) => (resolve = r))),
      postStatus: "draft",
    });

    editor.tick();
    editor.edit("img", { alt: "A brown dog" });
    await flush();
    resolve([media(10, "")]);
    await flush();
    await editor.save();

    expect(editor.pushes().map((p) => p.data.alt_text)).toEqual(["A brown dog"]);
  });

  test("published posts, other entities and foreign images are not adopted", async function () {
    const published = load({ blocks: [block("img", { id: 10, alt: "A dog" })], library: { 10: "" } });

    published.tick();
    await published.save();
    expect(published.apiCalls).toHaveLength(0);

    const draft = load({
      blocks: [block("header", { slug: "header" }, "core/template-part"), block("img", { id: 12, alt: "Elsewhere", url: UPLOADS + "other.jpg" })],
      patterns: { header: [block("logo", { id: 5, alt: "Logo" })] },
      library: { 5: "", 12: "" },
      postStatus: "draft",
    });

    draft.tick();
    await flush();
    await draft.save();
    await draft.saveEntity("postType/wp_template_part/theme//header");

    expect(draft.pushes()).toHaveLength(0);
  });

  test("a draft opened in an editor without a post status is left alone", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "A dog" })], library: { 10: "" }, postStatus: "draft" });

    delete global.wp.data.select("core/editor").getCurrentPostAttribute;
    editor.tick();
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
  });

  test("a template part changed in the post editor is pushed when the template part is saved", async function () {
    // Regression (third audit, bug 4): the save panel saves a template part
    // without touching the post editor's store, so nothing was pushed.
    const editor = load({
      blocks: [block("header", { slug: "header" }, "core/template-part"), block("content", {}, "core/post-content")],
      patterns: {
        header: [block("logo", { id: 5, alt: "Logo" })],
        content: [block("img", { id: 10, alt: "Old" })],
      },
      library: { 5: "Logo", 10: "Old" },
    });

    editor.tick();
    editor.edit("logo", { alt: "Our logo" });
    editor.edit("img", { alt: "New" });

    // Only the template part is saved: only its change is pushed.
    await editor.saveEntity("postType/wp_template_part/theme//header");

    expect(editor.pushes().map((p) => p.path)).toEqual(["/wp/v2/media/5"]);

    // Then the post.
    await editor.save();

    expect(editor.pushes().map((p) => p.path)).toEqual(["/wp/v2/media/5", "/wp/v2/media/10"]);
  });

  test("saving the post does not push a change to a template part that was not saved", async function () {
    const editor = load({
      blocks: [block("header", { slug: "header", theme: "other" }, "core/template-part")],
      patterns: { header: [block("logo", { id: 5, alt: "Logo" })] },
      library: { 5: "Logo" },
    });

    editor.tick();
    editor.edit("logo", { alt: "Our logo" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);

    await editor.saveEntity("postType/wp_template_part/other//header", { succeed: false });

    expect(editor.pushes()).toHaveLength(0);

    await editor.saveEntity("postType/wp_template_part/other//header");

    expect(editor.pushes()).toHaveLength(1);
  });

  test("a synced pattern's change is pushed when the pattern is saved", async function () {
    const editor = load({
      blocks: [block("ref", { ref: 99 }, "core/block")],
      patterns: { ref: [block("pimg", { id: 10, alt: "Old" })] },
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("pimg", { alt: "New" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);

    await editor.saveEntity("postType/wp_block/99");

    expect(editor.pushes()).toHaveLength(1);
  });

  test("without entity tracking, every save pushes every change, as before", async function () {
    const editor = load({
      entityStore: false,
      blocks: [block("ref", { ref: 99 }, "core/block")],
      patterns: { ref: [block("pimg", { id: 10, alt: "Old" })] },
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("pimg", { alt: "New" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(1);
  });

  test("changing back before saving cancels the push", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Old" })], library: { 10: "Old" } });

    editor.tick();
    editor.edit("img", { alt: "New" });
    editor.edit("img", { alt: "Old" });
    await editor.save();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("does not push the same value twice", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Old" })], library: { 10: "Old" } });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();
    await editor.save();

    expect(editor.pushes()).toHaveLength(1);
  });

  test("swapping the image compares against the new image", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Cat" })],
      library: { 10: "Cat", 11: "Dog" },
    });

    editor.tick();
    editor.edit("img", { id: 11, alt: "Dog", url: UPLOADS + fileFor(11) });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
  });

  test("walks inner blocks", async function () {
    const group = block("grp", {}, "core/group");
    group.innerBlocks = [block("img", { id: 10, alt: "Old" })];
    const editor = load({ blocks: [group], library: { 10: "Old" } });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(1);
  });

  test("walks images inside synced patterns", async function () {
    const editor = load({
      blocks: [block("ref", { ref: 99 }, "core/block")],
      patterns: { ref: [block("pimg", { id: 10, alt: "Old" })] },
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("pimg", { alt: "New" });
    // A synced pattern is an entity of its own, saved through the save panel.
    await editor.saveEntity("postType/wp_block/99");

    expect(editor.pushes()).toEqual([
      { path: "/wp/v2/media/10", method: "POST", data: { alt_text: "New" } },
    ]);
  });

  test("pushes media-text through its own attributes", async function () {
    const editor = load({
      blocks: [block("mt", { mediaId: 30, mediaAlt: "Old" }, "core/media-text")],
      library: { 30: "Old" },
    });

    editor.tick();
    editor.edit("mt", { mediaAlt: "New" });
    await editor.save();

    expect(editor.pushes()[0].data).toEqual({ alt_text: "New" });
  });
});

describe("editors without a post to save", function () {
  test("nothing is sent while typing, however long the pause", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      postEditor: false,
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "N" });
    jest.advanceTimersByTime(800);
    editor.edit("img", { alt: "New" });
    jest.advanceTimersByTime(60000);
    await flush();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("the widgets editor pushes once its widget areas are saved", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      postEditor: false,
      widgetsEditor: true,
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await flush();

    expect(editor.pushes()).toHaveLength(0);

    editor.state.savingWidgets = true;
    editor.tick();
    editor.state.savingWidgets = false;
    editor.tick();
    await flush();

    expect(editor.pushes()).toEqual([
      { path: "/wp/v2/media/10", method: "POST", data: { alt_text: "New" } },
    ]);
  });

  test("the Customizer pushes when its changes are published", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      postEditor: false,
      customizer: true,
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await flush();

    expect(editor.pushes()).toHaveLength(0);

    editor.customizerHandlers.saved();
    await flush();

    expect(editor.pushes()).toHaveLength(1);
  });
});

describe("clearing and decorative", function () {
  test("clearing alt never clears the library and offers the marker", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Dog" })], library: { 10: "Dog" } });

    editor.tick();
    editor.edit("img", { alt: "" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
    expect(editor.notices).toHaveLength(1);
    expect(editor.notices[0].type).toBe("info");
    expect(editor.notices[0].options.actions[0].label).toBe("Mark as decorative");

    editor.notices[0].options.actions[0].onClick();

    expect(editor.updated.pop().attrs).toEqual({ className: "altrelay-decorative", alt: "" });
    expect(editor.removedNotices).toEqual(["altrelay-cleared-img"]);
  });

  test("clearing the alt of a linked image warns instead of offering the marker", function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Dog", href: "https://example.com/" })],
    });

    editor.tick();
    editor.edit("img", { alt: "" });

    expect(editor.notices).toHaveLength(1);
    expect(editor.notices[0].type).toBe("warning");
    expect(editor.notices[0].message).toContain("The image is a link");
    expect(editor.notices[0].options.actions).toBeUndefined();
  });

  test("the offer does nothing once the image has become a link", function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Dog" })] });

    editor.tick();
    editor.edit("img", { alt: "" });
    editor.edit("img", { href: "https://example.com/" });
    editor.notices[0].options.actions[0].onClick();

    expect(editor.updated).toHaveLength(0);
  });

  test("the offer does nothing once the block is gone", function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Dog" })] });

    editor.tick();
    editor.edit("img", { alt: "" });
    editor.state.blocks = [];
    editor.notices[0].options.actions[0].onClick();

    expect(editor.updated).toHaveLength(0);
  });

  test("clearing after an unsaved edit drops the pending push", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Dog" })], library: { 10: "Dog" } });

    editor.tick();
    editor.edit("img", { alt: "Dog on a beach" });
    editor.edit("img", { alt: "" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
  });

  test("marking decorative pushes nothing and shows no notice", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Dog" })], library: { 10: "Dog" } });

    editor.tick();
    editor.edit("img", { alt: "", className: "altrelay-decorative" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
    expect(editor.notices).toHaveLength(0);
  });

  test("the clear notice keeps existing classes when marking", function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Dog", className: "is-style-rounded" })],
    });

    editor.tick();
    editor.edit("img", { alt: "" });
    editor.notices[0].options.actions[0].onClick();

    expect(editor.updated.pop().attrs.className).toBe("is-style-rounded altrelay-decorative");
  });

  test("works without the notices store", function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Dog" })],
      notices: false,
    });

    editor.tick();

    expect(() => editor.edit("img", { alt: "" })).not.toThrow();
  });
});

describe("failures", function () {
  const failingSave = (request) => (request.method ? Promise.reject(new Error("500")) : undefined);

  test("warns, then retries until the limit", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
      apiFetch: failingSave,
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    expect(editor.notices[0].type).toBe("warning");
    expect(editor.notices[0].message).toContain("image 10");

    jest.advanceTimersByTime(5000);
    await flush();
    jest.advanceTimersByTime(5000);
    await flush();
    jest.advanceTimersByTime(5000);
    await flush();

    expect(editor.pushes()).toHaveLength(3);
  });

  test("a failed lookup is reported and retried like a failed save", async function () {
    let offline = true;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
      apiFetch: (request) =>
        !request.method && offline ? Promise.reject(new Error("offline")) : undefined,
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
    expect(editor.notices[0].type).toBe("warning");

    offline = false;
    jest.advanceTimersByTime(5000);
    await flush();

    expect(editor.pushes()).toHaveLength(1);
  });

  test("a rejection without a reason is still reported", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
       
      apiFetch: (request) => (request.method ? undefined : Promise.reject()),
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    expect(editor.notices[0].type).toBe("warning");
  });

  test("does not retry when the user may not edit the image", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
      apiFetch: (request) =>
        request.method
          ? Promise.reject({ code: "rest_cannot_edit", data: { status: 403 } })
          : undefined,
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();
    jest.advanceTimersByTime(20000);
    await flush();

    expect(editor.pushes()).toHaveLength(1);
    expect(editor.notices[0].message).toContain("saved in this post only");
  });

  test("drops a retry once the block has moved on", async function () {
    let fail = true;
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
      apiFetch: (request) => (request.method && fail ? Promise.reject(new Error("500")) : undefined),
    });

    editor.tick();
    editor.edit("img", { alt: "First" });
    await editor.save();

    fail = false;
    editor.edit("img", { alt: "Second" });
    jest.advanceTimersByTime(5000);
    await flush();

    // The stale "First" retry must not fire; "Second" waits for the save.
    expect(editor.pushes().map((c) => c.data.alt_text)).toEqual(["First"]);

    await editor.save();

    expect(editor.pushes().map((c) => c.data.alt_text)).toEqual(["First", "Second"]);
  });

  test("drops a retry when the block has changed without a new push", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
      apiFetch: failingSave,
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    // Back to the value the library already has: nothing is pending.
    editor.edit("img", { alt: "Old" });
    jest.advanceTimersByTime(5000);
    await flush();

    expect(editor.pushes()).toHaveLength(1);
  });

  test("retries when the block editor store has gone away", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" })],
      library: { 10: "Old" },
      apiFetch: failingSave,
    });

    editor.tick();
    editor.edit("img", { alt: "New" });
    await editor.save();

    editor.blockEditor.getBlocks = undefined;
    jest.advanceTimersByTime(5000);
    await flush();

    expect(editor.pushes()).toHaveLength(2);
  });

  test("formats its messages without wp.i18n", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Old" }), block("empty", { id: 11, alt: "" })],
      library: { 10: "Old", 11: "Eleven" },
      i18n: false,
      apiFetch: failingSave,
    });

    editor.tick();
    await flush();

    expect(editor.snackbars()[0].message).toContain("for 1 image.");

    editor.edit("img", { alt: "New" });
    await editor.save();

    expect(editor.messages()[0].message).toContain("image 10");
  });

  test("counts in the plural without wp.i18n", async function () {
    const editor = load({
      blocks: [block("a", { id: 10, alt: "" }), block("b", { id: 11, alt: "" })],
      library: { 10: "Ten", 11: "Eleven" },
      i18n: false,
    });

    editor.tick();
    await flush();

    expect(editor.snackbars()[0].message).toContain("for 2 images.");
  });
});

describe("performance", function () {
  test("skips the walk while the block tree is unchanged", function () {
    let reads = 0;
    const b = block("img", {});
    Object.defineProperty(b, "attributes", {
      get: function () {
        reads++;
        return { id: 10, alt: "Alt" };
      },
    });
    const editor = load({ blocks: [b] });

    editor.tick();
    const afterFirst = reads;
    editor.tick();
    editor.tick();

    expect(afterFirst).toBeGreaterThan(0);
    expect(reads).toBe(afterFirst);
  });
});

describe("blocks that go away", function () {
  test("are forgotten, so a block that comes back is treated as new", async function () {
    // Regression (sixth audit): what the script knew about each block was
    // never pruned, so a long session kept every block it had ever seen.
    const editor = load({ blocks: [block("img", { id: 10, alt: "" })], library: { 10: "Ten" } });

    editor.tick();
    await flush();
    expect(editor.updated).toHaveLength(1);

    editor.state.blocks = [];
    editor.tick();

    // The same block back, empty again: filled again, as on a fresh start.
    editor.state.blocks = [block("img", { id: 10, alt: "" })];
    editor.tick();
    await flush();

    expect(editor.updated).toHaveLength(2);
  });

  test("keep a change that waits for the save", async function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Old" })], library: { 10: "Old" } });

    editor.tick();
    editor.edit("img", { alt: "New" });

    // Removed before the save: the change still reaches the Media Library.
    editor.state.blocks = [];
    editor.tick();
    await editor.save();

    expect(editor.pushes()).toEqual([{ path: "/wp/v2/media/10", method: "POST", data: { alt_text: "New" } }]);
  });
});

describe("blocks the store keeps outside the main tree", function () {
  test("fills and pushes images in the post content while the template is shown", async function () {
    const editor = load({
      blocks: [
        block("header", { slug: "header" }, "core/template-part"),
        block("content", {}, "core/post-content"),
      ],
      patterns: {
        header: [block("logo", { id: 5, alt: "Logo" })],
        content: [block("img", { id: 10, alt: "" })],
      },
      library: { 5: "Logo", 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.updated).toEqual([{ clientId: "img", attrs: { alt: "Ten" }, persistent: false }]);

    editor.edit("img", { alt: "Changed in template view" });
    await editor.save();

    expect(editor.pushes()).toEqual([
      { path: "/wp/v2/media/10", method: "POST", data: { alt_text: "Changed in template view" } },
    ]);
  });

  test("notices a change inside the post content when the template's own tree is unchanged", async function () {
    const editor = load({
      blocks: [block("content", {}, "core/post-content")],
      patterns: { content: [block("img", { id: 10, alt: "Old" })] },
      library: { 10: "Old" },
    });

    editor.tick();
    const rootBefore = editor.state.blocks;

    editor.edit("img", { alt: "New" });

    expect(editor.state.blocks).toBe(rootBefore);

    await editor.save();

    expect(editor.pushes()).toHaveLength(1);
  });

  test("does not walk again while nothing changed", function () {
    const editor = load({
      blocks: [block("content", {}, "core/post-content")],
      patterns: { content: [block("img", { id: 10, alt: "Old" })] },
      library: { 10: "Old" },
    });

    editor.tick();
    const reads = editor.blockEditor.getBlockAttributes.mock.calls.length;

    editor.tick();
    editor.tick();

    expect(editor.blockEditor.getBlockAttributes.mock.calls.length).toBe(reads);
  });

  test("notices a widget area whose blocks arrive after the first look", async function () {
    // The widgets screen hands a widget area its blocks only when the area
    // is opened, and the list of areas stays the same array.
    const editor = load({
      postEditor: false,
      widgetsEditor: true,
      blocks: [block("area", {}, "core/widget-area")],
      library: { 10: "Ten" },
    });

    editor.tick();
    expect(editor.lookups()).toHaveLength(0);

    const root = editor.state.blocks;
    editor.state.patterns.area = [block("img", { id: 10, alt: "" })];
    editor.tick();
    await flush();

    expect(editor.state.blocks).toBe(root);
    expect(editor.updated.map((u) => u.attrs)).toEqual([{ alt: "Ten" }]);
  });
  test("falls back to known block names on stores without areInnerBlocksControlled", async function () {
    const editor = load({
      storeSelectors: false,
      blocks: [block("content", {}, "core/post-content")],
      patterns: { content: [block("img", { id: 10, alt: "" })] },
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.updated.map((u) => u.attrs)).toEqual([{ alt: "Ten" }]);
  });
});

describe("AltRelay's class on a surrounding block", function () {
  function marked(children) {
    const group = block("group", { className: "is-style-x altrelay-decorative" }, "core/group");
    group.innerBlocks = children;
    return group;
  }

  test("leaves the images inside it empty", async function () {
    const editor = load({
      blocks: [marked([block("img", { id: 10, alt: "" })]), block("other", { id: 20, alt: "" })],
      library: { 10: "Ten", 20: "Twenty" },
    });

    editor.tick();
    await flush();

    expect(editor.lookups()[0].path).toContain("include=20&");
    expect(editor.updated.map((u) => u.clientId)).toEqual(["other"]);
  });

  test("reaches into synced patterns inside the marked block", async function () {
    const editor = load({
      blocks: [marked([block("ref", { ref: 99 }, "core/block")])],
      patterns: { ref: [block("pimg", { id: 10, alt: "" })] },
      library: { 10: "Ten" },
    });

    editor.tick();
    await flush();

    expect(editor.lookups()).toHaveLength(0);
    expect(editor.updated).toHaveLength(0);
  });

  test("still pushes a description typed into such an image, and offers nothing on clearing", async function () {
    const editor = load({
      blocks: [marked([block("img", { id: 10, alt: "Old" })])],
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "Typed" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(1);

    editor.edit("img", { alt: "" });

    expect(editor.messages()).toHaveLength(0);
  });

  test("a block that becomes marked while its media loads is not filled", async function () {
    let release;
    const editor = load({
      blocks: [block("group", {}, "core/group")],
      apiFetch: () =>
        new Promise((resolve) => {
          release = resolve;
        }),
    });

    editor.state.blocks[0].innerBlocks = [block("img", { id: 10, alt: "" })];
    editor.state.blocks = editor.state.blocks.slice();
    editor.tick();
    await flush();

    editor.edit("group", { className: "altrelay-decorative" });
    release([media(10, "Ten")]);
    await flush();

    expect(editor.updated.filter((u) => u.clientId === "img")).toHaveLength(0);
  });
});

describe("an error while applying a response", function () {
  test("affects only the image it happened on, and is not reported as a failed request", async function () {
    const editor = load({
      blocks: [block("boom", { id: 10, alt: "" }), block("ok", { id: 20, alt: "" })],
      library: { 10: "Ten", 20: "Twenty" },
    });
    const errors = jest.spyOn(console, "error").mockImplementation(() => {});
    const read = editor.blockEditor.getBlockAttributes.getMockImplementation();

    editor.blockEditor.getBlockAttributes.mockImplementation(function (clientId) {
      if (clientId === "boom") {
        throw new Error("broken block");
      }
      return read(clientId);
    });

    editor.tick();
    await flush();

    expect(editor.updated.map((u) => u.clientId)).toEqual(["ok"]);
    expect(errors).toHaveBeenCalledTimes(1);
    expect(console.warn).not.toHaveBeenCalled();
    expect(editor.lookups()).toHaveLength(1);
  });
});

describe("numbers in notices", function () {
  test("follow the language of the admin screen", async function () {
    global.document = { readyState: "complete", documentElement: { lang: "ar-EG" } };

    const editor = load({
      blocks: [block("a", { id: 10, alt: "" }), block("b", { id: 20, alt: "" })],
      library: { 10: "Ten", 20: "Twenty" },
    });

    editor.tick();
    await flush();

    expect(editor.snackbars()[0].message).toContain((2).toLocaleString("ar-EG"));
    expect(editor.snackbars()[0].message).not.toContain(" 2 ");
  });

  test("fall back to plain digits for an unknown language tag", async function () {
    global.document = { readyState: "complete", documentElement: { lang: "not a tag!" } };

    const editor = load({
      blocks: [block("a", { id: 10, alt: "" }), block("b", { id: 20, alt: "" })],
      library: { 10: "Ten", 20: "Twenty" },
    });

    editor.tick();
    await flush();

    expect(editor.snackbars()[0].message).toContain("for 2 images");
  });
});

describe("decorative toggle", function () {  /**
   * Render the BlockEdit wrapper and return its tree.
   */
  function render(editor, props) {
    const BlockEdit = "BlockEdit";
    const Wrapped = editor.filters[0].callback(BlockEdit);
    return Wrapped(props);
  }

  function findToggle(tree) {
    if (!tree || typeof tree !== "object") {
      return null;
    }
    if (tree.type === "ToggleControl") {
      return tree;
    }
    for (const child of tree.children || []) {
      const found = findToggle(child);
      if (found) {
        return found;
      }
    }
    return null;
  }

  function props(overrides) {
    return Object.assign(
      {
        name: "core/image",
        clientId: "img",
        isSelected: true,
        attributes: { id: 10, alt: "Dog" },
        setAttributes: jest.fn(),
      },
      overrides,
    );
  }

  test("shows the toggle on a selected image block", function () {
    const editor = load();
    const toggle = findToggle(render(editor, props()));

    expect(toggle).not.toBeNull();
    expect(toggle.props.label).toBe("Decorative image");
    expect(toggle.props.checked).toBe(false);
    expect(toggle.props.disabled).toBe(false);
  });

  test("reflects the marker", function () {
    const editor = load();
    const toggle = findToggle(
      render(editor, props({ attributes: { id: 10, alt: "", className: "x altrelay-decorative" } })),
    );

    expect(toggle.props.checked).toBe(true);
    expect(toggle.props.help).toContain("Screen readers skip this image");
  });

  test("is absent for unselected, unsupported, empty, video or bound blocks", function () {
    const editor = load();

    [
      props({ isSelected: false }),
      props({ name: "core/paragraph" }),
      props({ attributes: { alt: "" } }),
      props({ attributes: undefined }),
      props({ name: "core/cover", attributes: { id: 5, backgroundType: "video" } }),
      props({ attributes: { id: 10, alt: "", metadata: { bindings: { alt: {} } } } }),
    ].forEach(function (p) {
      expect(render(editor, p)).toEqual({
        type: "BlockEdit",
        props: p,
        children: [],
      });
    });
  });

  test("turning it on adds the marker and empties the alt", function () {
    const editor = load();
    const p = props({ attributes: { id: 10, alt: "Dog", className: "is-style-rounded" } });

    findToggle(render(editor, p)).props.onChange(true);

    expect(p.setAttributes).toHaveBeenCalledWith({
      className: "is-style-rounded altrelay-decorative",
      alt: "",
    });
  });

  test("turning it on twice does not add the marker twice", function () {
    const editor = load();
    const p = props({ attributes: { id: 10, alt: "", className: "altrelay-decorative" } });

    findToggle(render(editor, p)).props.onChange(true);

    expect(p.setAttributes).toHaveBeenCalledWith({ className: "altrelay-decorative", alt: "" });
  });

  test("cannot be turned on for a linked image, and says why", function () {
    const editor = load();
    const toggle = findToggle(
      render(editor, props({ attributes: { id: 10, alt: "Dog", href: "https://example.com/" } })),
    );

    expect(toggle.props.disabled).toBe(true);
    expect(toggle.props.checked).toBe(false);
    expect(toggle.props.help).toContain("This image is a link");
    expect(toggle.props.label).toBe("Decorative image (not available for linked images)");
  });

  test("looks up surrounding blocks only for the selected image, through a subscription", function () {
    // Regression (fifth audit): every block, image or not, selected or
    // not, looked up its parents on every render, and read the editor
    // directly, so the state could be out of date.
    const editor = load();
    const parents = jest.spyOn(editor.blockEditor, "getBlockParents");

    render(editor, props({ name: "core/paragraph" }));
    expect(global.wp.data.useSelect).not.toHaveBeenCalled();

    render(editor, props({ isSelected: false }));
    expect(global.wp.data.useSelect).toHaveBeenCalledTimes(1);
    expect(global.wp.data.useSelect.mock.calls[0][1]).toEqual([false, false, "img"]);
    expect(parents).not.toHaveBeenCalled();

    render(editor, props());
    expect(global.wp.data.useSelect.mock.calls[1][1]).toEqual([true, false, "img"]);
    expect(parents).toHaveBeenCalledWith("img");
  });

  test("is not added where the editor has no useSelect", function () {
    const editor = load({ noUseSelect: true });

    expect(editor.filters).toHaveLength(0);
  });

  test("shows a mark on a surrounding block as on, and cannot change it", function () {
    const group = block("group", { className: "altrelay-decorative" }, "core/group");
    group.innerBlocks = [block("img", { id: 10, alt: "", href: "https://example.com/" })];

    const editor = load({ blocks: [group] });
    const toggle = findToggle(render(editor, props({ attributes: { id: 10, alt: "", href: "https://example.com/" } })));

    expect(toggle.props.checked).toBe(true);
    expect(toggle.props.disabled).toBe(true);
    expect(toggle.props.label).toBe("Decorative image (set on a surrounding block)");
    expect(toggle.props.help).toContain("A block around this image carries AltRelay's decorative class");
  });

  test("on WordPress 7.1, an image in a marked block shows the toggle next to core's checkbox", function () {
    // Regression (third audit, bug 6): core's unticked checkbox was all the
    // sidebar showed, as if nothing made the image decorative.
    const group = block("group", { className: "altrelay-decorative" }, "core/group");
    group.innerBlocks = [block("img", { id: 10, alt: "" })];

    const editor = load({ blocks: [group], settings: { coreDecorative: true } });
    const toggle = findToggle(render(editor, props({ attributes: { id: 10, alt: "" } })));

    expect(toggle).not.toBeNull();
    expect(toggle.props.label).toBe("Decorative image (set on a surrounding block)");
    expect(toggle.props.disabled).toBe(true);

    // Outside a marked block, core's checkbox is the only one.
    const plain = load({ blocks: [block("img", { id: 10, alt: "" })], settings: { coreDecorative: true } });
    expect(findToggle(render(plain, props({ attributes: { id: 10, alt: "" } })))).toBeNull();
  });

  test("on WordPress 7.1, the Image block's toggle sits in the Content tab with the alt text field", function () {
    // Regression (fourth audit, bug 5): the hint was in the Settings tab,
    // while the Content tab opens first.
    const group = block("group", { className: "altrelay-decorative" }, "core/group");
    group.innerBlocks = [block("img", { id: 10, alt: "" })];

    const editor = load({ blocks: [group], settings: { coreDecorative: true } });
    const tree = render(editor, props({ attributes: { id: 10, alt: "" } }));
    const inspector = tree.children.find((child) => child && child.type === "InspectorControls");

    expect(inspector.props).toEqual({ group: "content" });

    // Other blocks keep their alt text in the Settings tab, and so does the
    // toggle; before 7.1 the Image block does too.
    const cover = render(editor, props({ name: "core/cover", attributes: { id: 20, alt: "Dog" } }));
    expect(cover.children.find((child) => child && child.type === "InspectorControls").props).toBeNull();

    const older = load({ blocks: [group] });
    const image = render(older, props({ attributes: { id: 10, alt: "" } }));
    expect(image.children.find((child) => child && child.type === "InspectorControls").props).toBeNull();
  });

  test("can still be turned off for a linked image that is already marked", function () {
    const editor = load();
    const toggle = findToggle(
      render(
        editor,
        props({ attributes: { id: 10, alt: "", href: "https://example.com/", className: "altrelay-decorative" } }),
      ),
    );

    expect(toggle.props.disabled).toBe(false);
    expect(toggle.props.checked).toBe(true);
  });

  test("a linked media-text image is refused too, a cover has no link", function () {
    const editor = load();

    const mediaText = findToggle(
      render(
        editor,
        props({
          name: "core/media-text",
          attributes: { mediaId: 30, mediaAlt: "Dog", mediaType: "image", href: "https://example.com/" },
        }),
      ),
    );
    const cover = findToggle(
      render(editor, props({ name: "core/cover", attributes: { id: 20, alt: "Dog", href: "ignored" } })),
    );

    expect(mediaText.props.disabled).toBe(true);
    expect(cover.props.disabled).toBe(false);
  });

  test("turning it off removes the marker and restores the library alt", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", className: "altrelay-decorative" })],
      library: { 10: "Dog" },
    });
    editor.tick();

    const p = props({ attributes: { id: 10, alt: "", className: "altrelay-decorative" } });
    p.setAttributes = (attrs) => editor.edit("img", attrs);

    findToggle(render(editor, p)).props.onChange(false);
    await flush();

    expect(editor.blockEditor.getBlockAttributes("img")).toMatchObject({
      id: 10,
      alt: "Dog",
      className: undefined,
    });
    expect(editor.pushes()).toHaveLength(0);
  });

  test("uses media-text's alt attribute", function () {
    const editor = load();
    const p = props({
      name: "core/media-text",
      attributes: { mediaId: 30, mediaAlt: "Dog", mediaType: "image" },
    });

    findToggle(render(editor, p)).props.onChange(true);

    expect(p.setAttributes).toHaveBeenCalledWith({
      className: "altrelay-decorative",
      mediaAlt: "",
    });
  });
});

describe("WordPress 7.1 core decorative setting", function () {
  const CORE = { marker: "altrelay-decorative", emptyIsDecorative: false, coreDecorative: true };

  test("ticking core's checkbox shows no 'removed' notice and pushes nothing", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "Dog" })],
      settings: CORE,
      library: { 10: "Dog" },
    });

    editor.tick();
    // Core's updateIsDecorative() sets both in one change.
    editor.edit("img", { isDecorative: true, alt: "" });
    await editor.save();

    expect(editor.notices).toHaveLength(0);
    expect(editor.pushes()).toHaveLength(0);
  });

  test("the notice button uses core's setting on Image blocks", function () {
    const editor = load({ blocks: [block("img", { id: 10, alt: "Dog" })], settings: CORE });

    editor.tick();
    editor.edit("img", { alt: "" });
    editor.notices[0].options.actions[0].onClick();

    expect(editor.updated.pop().attrs).toEqual({ isDecorative: true, alt: "" });
  });

  test("the notice button still uses the class on Cover blocks", function () {
    const editor = load({
      blocks: [block("cov", { id: 20, alt: "Dog" }, "core/cover")],
      settings: CORE,
    });

    editor.tick();
    editor.edit("cov", { alt: "" });
    editor.notices[0].options.actions[0].onClick();

    expect(editor.updated.pop().attrs).toEqual({ className: "altrelay-decorative", alt: "" });
  });

  test("a core-decorative image is not filled on load", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", isDecorative: true })],
      settings: CORE,
      library: { 10: "Dog" },
    });

    editor.tick();
    await flush();

    expect(editor.apiCalls).toHaveLength(0);
  });

  test("un-ticking core's checkbox brings the library text back", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", isDecorative: true })],
      settings: CORE,
      library: { 10: "Dog" },
    });

    editor.tick();
    editor.edit("img", { isDecorative: false });
    await flush();

    expect(editor.blockEditor.getBlockAttributes("img").alt).toBe("Dog");
    expect(editor.pushes()).toHaveLength(0);
  });
});

describe("typing into a block marked with AltRelay's class", function () {
  test("removes the class and sends the new text on save", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", className: "is-style-rounded altrelay-decorative" })],
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "Described after all" });
    await editor.save();

    expect(editor.blockEditor.getBlockAttributes("img").className).toBe("is-style-rounded");
    expect(editor.pushes()).toEqual([
      { path: "/wp/v2/media/10", method: "POST", data: { alt_text: "Described after all" } },
    ]);
  });

  test("leaves a core-decorative block alone", async function () {
    const editor = load({
      blocks: [block("img", { id: 10, alt: "", isDecorative: true, className: "altrelay-decorative" })],
      library: { 10: "Old" },
    });

    editor.tick();
    editor.edit("img", { alt: "Ignored" });
    await editor.save();

    expect(editor.pushes()).toHaveLength(0);
  });
});

describe("toggle placement with core's setting", function () {
  const CORE = { marker: "altrelay-decorative", emptyIsDecorative: false, coreDecorative: true };

  function rendersToggle(editor, props) {
    const tree = editor.filters[0].callback("BlockEdit")(props);
    return JSON.stringify(tree).includes("ToggleControl");
  }

  function props(name, attributes) {
    return { name: name, clientId: "x", isSelected: true, attributes: attributes, setAttributes: jest.fn() };
  }

  test("Image blocks rely on core's checkbox", function () {
    const editor = load({ settings: CORE });

    expect(rendersToggle(editor, props("core/image", { id: 10, alt: "Dog" }))).toBe(false);
  });

  test("Image blocks still carrying AltRelay's class keep the toggle to turn it off", function () {
    const editor = load({ settings: CORE });

    expect(rendersToggle(editor, props("core/image", { id: 10, alt: "", className: "altrelay-decorative" }))).toBe(true);
  });

  test("Cover blocks keep AltRelay's toggle", function () {
    const editor = load({ settings: CORE });

    expect(rendersToggle(editor, props("core/cover", { id: 20, alt: "Dog" }))).toBe(true);
  });
});
