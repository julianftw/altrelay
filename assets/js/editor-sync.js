/**
 * AltRelay Editor Sync
 *
 * Block editor integration:
 * - Pull: fill an image block's empty alt from the Media Library on load.
 *   The fill is not an edit of the user's: it adds no undo step and does not
 *   mark the post as changed.
 * - Push: when an image block's alt text changes, update the Media Library
 *   once the post is saved (so discarded edits never reach the library).
 *   Editors without a save step of their own never push.
 * - Decorative: a "Decorative image" toggle marks one image as decorative.
 *   AltRelay never fills, pulls or pushes alt text for a marked image.
 *
 * A block is only connected to a Media Library item when its image file
 * belongs to that item. Blocks pasted from another site keep the IDs of the
 * site they came from, and those must not read or write a local image.
 *
 * Clearing an image's alt text in a post never clears the Media Library.
 * Removing a description sitewide is a Media Library action, where its scope
 * is obvious.
 *
 * Supports: core/image, core/cover, core/media-text, and images inside
 * synced patterns (core/block). Blocks whose image or alt text comes from a
 * block binding or a pattern override are left to that source.
 *
 * @package AltRelay
 * @since   1.0.0
 */
(function (wp) {
  "use strict";

  // Printed by altrelay_enqueue_editor_assets() as window.altrelayEditor.
  var settings =
    (typeof altrelayEditor !== "undefined" && altrelayEditor) || {};

  /**
   * Class that marks an image's block as decorative.
   *
   * @type {string}
   */
  var MARKER = settings.marker || "altrelay-decorative";

  /**
   * Whether the site treats an unmarked empty alt as decorative.
   *
   * @type {boolean}
   */
  var EMPTY_IS_DECORATIVE = !!settings.emptyIsDecorative;

  /**
   * Whether the Image block has core's own "Mark as decorative" setting
   * (WordPress 7.1+). Image blocks then use it instead of AltRelay's class.
   *
   * @type {boolean}
   */
  var CORE_DECORATIVE = !!settings.coreDecorative;

  /**
   * Delay before retrying a failed push.
   *
   * @type {number}
   */
  var RETRY_MS = 5000;

  /**
   * Maximum number of push attempts per change.
   *
   * @type {number}
   */
  var MAX_ATTEMPTS = 3;

  /**
   * Blocks that keep their inner blocks outside the editor's main tree.
   *
   * @type {Array<string>}
   */
  var CONTROLLED_BLOCKS = [
    "core/block",
    "core/post-content",
    "core/template-part",
    "core/widget-area",
  ];

  /**
   * Most Media Library items the REST API returns in one request.
   *
   * @type {number}
   */
  var FETCH_PAGE = 100;

  /**
   * Block type configuration: the attributes that hold the alt text, the
   * media ID, the image URL, the media type and the link.
   */
  var BLOCK_CONFIG = {
    "core/image": {
      altAttr: "alt",
      idAttr: "id",
      urlAttr: "url",
      typeAttr: null,
      hrefAttr: "href",
    },
    "core/cover": {
      altAttr: "alt",
      idAttr: "id",
      urlAttr: "url",
      typeAttr: "backgroundType",
      hrefAttr: null,
    },
    "core/media-text": {
      altAttr: "mediaAlt",
      idAttr: "mediaId",
      urlAttr: "mediaUrl",
      typeAttr: "mediaType",
      hrefAttr: "href",
    },
  };

  /**
   * Alt text last observed per block, used to detect changes.
   *
   * @type {Object<string, string>}
   */
  var seen = {};

  /**
   * Alt text each block is known to share with the Media Library.
   *
   * Seeded on first observation, so opening a post never writes anything.
   *
   * @type {Object<string, string>}
   */
  var synced = {};

  /**
   * Changes waiting for the next save, keyed by block key.
   *
   * @type {Object<string, {clientId: string, mediaId: number, alt: string, altAttr: string, url: string}>}
   */
  var pending = {};

  /**
   * Whether each block was decorative when last observed, to notice when it
   * stops being decorative and its description should come back.
   *
   * @type {Object<string, boolean>}
   */
  var wasDecorative = {};
  var retryTimers = {};

  /**
   * Blocks met during the current walk of the whole tree, by block key, or
   * null outside one. What a walk did not meet is forgotten after it, so a
   * long session that adds and removes many blocks does not keep them all.
   *
   * @type {Object<string, boolean>|null}
   */
  var walked = null;

  /**
   * Entities with pending changes that are being saved, by entity key,
   * with whether the save is an autosave.
   *
   * @type {Object<string, {autosave: boolean}>}
   */
  var savingOwners = {};
  var pushAttempts = {};

  /**
   * Media Library items already loaded, by ID. null means "no such item".
   *
   * @type {Object<number, Object|null>}
   */
  var mediaRecords = {};

  /**
   * Callbacks waiting for a Media Library item, by ID.
   *
   * @type {Object<number, Array<Function>>}
   */
  var mediaWaiters = {};
  var fetchScheduled = false;

  /**
   * Images filled while one response was being applied, for one announcement.
   *
   * @type {number}
   */
  var pulledCount = 0;

  var lastBlocks = null;

  /**
   * Blocks whose inner blocks the store keeps outside the main tree (synced
   * patterns, the post content in template view, template parts), with the
   * inner block list seen on the last walk.
   *
   * @type {Array<{clientId: string, blocks: Array}>}
   */
  var lastControlled = [];
  var wasSavingPost = false;
  var wasSavingEntities = false;
  var wasSavingWidgets = false;

  var __ =
    wp.i18n && typeof wp.i18n.__ === "function"
      ? wp.i18n.__
      : function (text) {
          return text;
        };

  var _n =
    wp.i18n && typeof wp.i18n._n === "function"
      ? wp.i18n._n
      : function (single, plural, number) {
          return number === 1 ? single : plural;
        };

  var sprintf =
    wp.i18n && typeof wp.i18n.sprintf === "function"
      ? wp.i18n.sprintf
      : function (format) {
          var args = Array.prototype.slice.call(arguments, 1);
          return format.replace(/%[sd]/g, function () {
            return String(args.shift());
          });
        };

  /**
   * Whether a block type marks decorative images with core's isDecorative.
   *
   * @param {string} blockName Block name.
   * @return {boolean} Whether core's setting is used.
   */
  function usesCoreDecorative(blockName) {
    return CORE_DECORATIVE && blockName === "core/image";
  }

  /**
   * Whether a block is decorative, by core's setting or AltRelay's class.
   *
   * @param {Object} attrs Block attributes.
   * @return {boolean} Whether the block is decorative.
   */
  function isDecorativeBlock(attrs) {
    return attrs.isDecorative === true || hasMarker(attrs.className);
  }

  /**
   * Whether a block sits inside a block that carries AltRelay's class.
   *
   * The class on a Group, Columns or any other block makes every image in
   * it decorative, the same rule the sync and the frontend apply.
   *
   * @param {Object} editor   The block editor store.
   * @param {string} clientId The block's client ID.
   * @return {boolean} Whether an ancestor is marked.
   */
  function hasMarkedAncestor(editor, clientId) {
    if (!editor || typeof editor.getBlockParents !== "function") {
      return false;
    }

    return (editor.getBlockParents(clientId) || []).some(function (parentId) {
      var attrs = editor.getBlockAttributes(parentId);

      return !!attrs && hasMarker(attrs.className);
    });
  }

  /**
   * Format a count for the user's language.
   *
   * @param {number} count The number.
   * @return {string} The formatted number.
   */
  function formatNumber(count) {
    var lang =
      typeof document !== "undefined" && document.documentElement
        ? document.documentElement.lang
        : "";

    try {
      return Number(count).toLocaleString(lang || undefined);
    } catch {
      return String(count);
    }
  }

  /**
   * Whether a className string contains the decorative marker.
   *
   * @param {string|undefined} className Block className attribute.
   * @return {boolean} Whether the marker is present.
   */
  function hasMarker(className) {
    return (className || "").split(/\s+/).indexOf(MARKER) !== -1;
  }

  /**
   * Add the marker to a className string.
   *
   * @param {string|undefined} className Block className attribute.
   * @return {string} The new className.
   */
  function addMarker(className) {
    if (hasMarker(className)) {
      return className;
    }
    return ((className || "") + " " + MARKER).trim();
  }

  /**
   * Remove the marker from a className string.
   *
   * @param {string|undefined} className Block className attribute.
   * @return {string|undefined} The new className, or undefined when empty.
   */
  function removeMarker(className) {
    var rest = (className || "").split(/\s+/).filter(function (name) {
      return name && name !== MARKER;
    });
    return rest.length ? rest.join(" ") : undefined;
  }

  /**
   * Build the cache key for a block, so swapping the image in a block
   * compares against the right image.
   *
   * @param {string} clientId The block's client ID.
   * @param {number} mediaId  The attachment ID.
   * @return {string} Cache key.
   */
  function blockKey(clientId, mediaId) {
    return clientId + ":" + mediaId;
  }

  /**
   * Whether a supported block currently shows an image (not a video).
   *
   * @param {Object} attrs  Block attributes.
   * @param {Object} config Block configuration.
   * @return {boolean} Whether the block shows an image.
   */
  function showsImage(attrs, config) {
    if (!config.typeAttr || attrs[config.typeAttr] === undefined) {
      return true;
    }
    return attrs[config.typeAttr] === "image";
  }

  /**
   * Whether a block's image or alt text is supplied by a block binding or a
   * pattern override. The attribute in the block is then only a fallback,
   * and the real value lives somewhere AltRelay must not write.
   *
   * @param {Object} attrs  Block attributes.
   * @param {Object} config Block configuration.
   * @return {boolean} Whether the block is bound.
   */
  function isBound(attrs, config) {
    var bindings = attrs.metadata && attrs.metadata.bindings;

    if (!bindings) {
      return false;
    }

    return !!(
      bindings.__default ||
      bindings[config.altAttr] ||
      bindings[config.idAttr] ||
      bindings[config.urlAttr]
    );
  }

  /**
   * Whether a block's image is a link. The alt text of a linked image is
   * what names the link, so such an image cannot be decorative.
   *
   * @param {Object} attrs  Block attributes.
   * @param {Object} config Block configuration.
   * @return {boolean} Whether the image is linked.
   */
  function isLinked(attrs, config) {
    return !!(config.hrefAttr && attrs[config.hrefAttr]);
  }

  /**
   * The path of a URL: no origin, no query, no fragment, decoded.
   *
   * @param {string} url A URL.
   * @return {string} Its path.
   */
  function pathOf(url) {
    var path = String(url || "")
      .replace(/[?#].*$/, "")
      .replace(/^(?:[a-z][a-z0-9+.-]*:)?\/\/[^/]*/i, "");

    try {
      return decodeURIComponent(path);
    } catch {
      return path;
    }
  }

  /**
   * Whether a URL path ends with a file path, on a folder boundary.
   *
   * @param {string} path URL path.
   * @param {string} file File path relative to the uploads folder.
   * @return {boolean} Whether it does.
   */
  function pathEndsWith(path, file) {
    if (!file) {
      return false;
    }

    var suffix = "/" + file.replace(/^\/+/, "");

    return path === file || path.slice(-suffix.length) === suffix;
  }

  /**
   * A file path without extension and without the suffixes WordPress adds
   * to resized, scaled, rotated and edited copies.
   *
   * @param {string} path File or URL path.
   * @return {string} The base name, with its folder.
   */
  function baseName(path) {
    var cut = path.lastIndexOf("/");
    var folder = cut === -1 ? "" : path.slice(0, cut + 1);
    var name = path
      .slice(cut + 1)
      .replace(/\.[A-Za-z0-9]{2,5}$/, "")
      .replace(/\.(?:jpe?g|png|gif|webp|avif|bmp|tiff?|heic)$/i, "")
      .replace(/(?:-scaled|-rotated|-e\d{10,}|-\d+x\d+)+$/, "");

    return folder + name;
  }

  /**
   * Whether an image URL points at a file of a Media Library item.
   *
   * The same rule as AltRelay_Content::image_matches_attachment() on the
   * server: the original, a registered size, or a resized, scaled, rotated
   * or edited variant of the same file in the same folder. Only the path is
   * compared, so images served through a CDN still match.
   *
   * @param {Object} media A media record from the REST API.
   * @param {string} url   The block's image URL.
   * @return {boolean} Whether the file belongs to the item.
   */
  function mediaMatchesUrl(media, url) {
    var path = pathOf(url);

    if (!path || !media) {
      return false;
    }

    var details = media.media_details || {};
    var main = typeof details.file === "string" ? details.file : "";
    var cut = main.lastIndexOf("/");
    var folder = cut === -1 ? "" : main.slice(0, cut + 1);
    var originals = [];
    var all = [];

    if (main) {
      originals.push(main);
    }

    if (typeof details.original_image === "string" && details.original_image) {
      originals.push(folder + details.original_image);
    }

    if (media.source_url) {
      originals.push(pathOf(media.source_url));
    }

    all = originals.slice();

    Object.keys(details.sizes || {}).forEach(function (name) {
      var size = details.sizes[name];

      if (size && typeof size.file === "string" && size.file) {
        all.push(folder + size.file);
      }
    });

    var exact = all.some(function (file) {
      return pathEndsWith(path, file);
    });

    if (exact) {
      return true;
    }

    var base = baseName(path);

    return originals.some(function (file) {
      return pathEndsWith(base, baseName(file));
    });
  }

  /**
   * The block editor store, when it is registered.
   *
   * @return {Object|null} The store selectors.
   */
  function blockEditor() {
    var store = wp.data.select("core/block-editor");
    return store && typeof store.getBlocks === "function" ? store : null;
  }

  /**
   * The post editor store, when this editor has a save lifecycle.
   *
   * @return {Object|null} The store selectors.
   */
  function postEditor() {
    var store = wp.data.select("core/editor");
    return store && typeof store.isSavingPost === "function" ? store : null;
  }

  /**
   * The core data store, when it tracks the saving of entities.
   *
   * @return {Object|null} The store selectors.
   */
  function entityStore() {
    var store = wp.data.select("core");
    return store && typeof store.isSavingEntityRecord === "function" ? store : null;
  }

  /**
   * The entity the editor edits: the post, page or template.
   *
   * @return {Object|null} { kind, name, id }, or null outside the post and site editors.
   */
  function mainOwner() {
    var store = wp.data.select("core/editor");

    if (!store || typeof store.getCurrentPostType !== "function") {
      return null;
    }

    var type = store.getCurrentPostType();
    var id = store.getCurrentPostId();

    return type && id ? { kind: "postType", name: type, id: id } : null;
  }

  /**
   * The entity the inner blocks of a block are saved to.
   *
   * A template part and a synced pattern are entities of their own: the
   * editor saves them separately from the post around them, sometimes not
   * at all. The post content block holds the edited post's own blocks.
   *
   * @param {Object}      block  The block.
   * @param {Object|null} parent The entity the block itself belongs to.
   * @return {Object|null} { kind, name, id }.
   */
  function ownerOf(block, parent) {
    var attrs = block.attributes || {};

    if (block.name === "core/template-part" && attrs.slug) {
      var core = wp.data.select("core");
      var theme =
        attrs.theme ||
        (core && typeof core.getCurrentTheme === "function" && core.getCurrentTheme()
          ? core.getCurrentTheme().stylesheet
          : "");

      return theme
        ? { kind: "postType", name: "wp_template_part", id: theme + "//" + attrs.slug }
        : parent;
    }

    if (block.name === "core/block" && attrs.ref) {
      return { kind: "postType", name: "wp_block", id: attrs.ref };
    }

    if (block.name === "core/post-content") {
      return mainOwner() || parent;
    }

    return parent;
  }

  /**
   * A string that names an entity.
   *
   * @param {Object|null} owner The entity.
   * @return {string} The key, or "" for none.
   */
  function ownerKey(owner) {
    return owner ? owner.kind + "/" + owner.name + "/" + owner.id : "";
  }

  /**
   * The widgets editor store, when this is the widgets screen.
   *
   * @return {Object|null} The store selectors.
   */
  function widgetsEditor() {
    var store = wp.data.select("core/edit-widgets");
    return store && typeof store.isSavingWidgetAreas === "function"
      ? store
      : null;
  }

  /**
   * Show a notice in the editor.
   *
   * @param {string} type    "warning", "info" or "snackbar".
   * @param {string} message The message.
   * @param {Object} options Notice options.
   */
  function notice(type, message, options) {
    var notices = wp.data.dispatch("core/notices");
    var method = type === "warning" ? "createWarningNotice" : "createInfoNotice";
    var opts = options || {};

    if (type === "snackbar") {
      // Snackbars are announced to screen readers when they appear.
      opts.type = "snackbar";
    }

    if (notices && typeof notices[method] === "function") {
      notices[method](message, opts);
    }
  }

  /**
   * React to changes of the block tree.
   */
  function onBlocksChanged() {
    var editor = blockEditor();

    // The store is not registered in every context, or not yet. Bail quietly.
    if (!editor) {
      return;
    }

    var blocks = editor.getBlocks();

    // An empty tree is walked too: it forgets the blocks that were removed.
    if (!blocks) {
      return;
    }

    // getBlocks() returns the same array until a block changes, so most
    // store updates (selection, typing elsewhere) end here. Synced patterns,
    // the post content in template view and template parts keep their
    // blocks outside this tree; their lists are compared one by one.
    if (blocks === lastBlocks && !controlledChanged(editor)) {
      return;
    }

    lastBlocks = blocks;
    lastControlled = [];
    walked = {};
    walkBlocks(blocks, editor, false, mainOwner());
    forgetRemovedBlocks(walked);
    walked = null;
  }

  /**
   * Forget the blocks a walk of the whole tree no longer met: they were
   * removed, or their image was replaced. A change of theirs that waits for
   * the next save is kept until then.
   *
   * @param {Object<string, boolean>} met Block keys the walk met.
   */
  function forgetRemovedBlocks(met) {
    Object.keys(seen).forEach(function (key) {
      if (!met[key] && !pending[key]) {
        delete seen[key];
        delete synced[key];
        delete wasDecorative[key];
      }
    });
  }

  /**
   * Whether any block list kept outside the main tree changed.
   *
   * @param {Object} editor The block editor store.
   * @return {boolean} Whether one of them is a different list now.
   */
  function controlledChanged(editor) {
    return lastControlled.some(function (entry) {
      return editor.getBlocks(entry.clientId) !== entry.blocks;
    });
  }

  /**
   * Whether the store keeps, or may later keep, a block's inner blocks
   * outside the main tree.
   *
   * The blocks that do so are watched even before their contents arrive:
   * a widget area or a template part gets its blocks only once it is shown,
   * and the main tree does not change when it does.
   *
   * @param {Object} block  The block.
   * @param {Object} editor The block editor store.
   * @return {boolean} Whether its inner blocks must be fetched separately.
   */
  function hasControlledInnerBlocks(block, editor) {
    if (CONTROLLED_BLOCKS.indexOf(block.name) !== -1) {
      return true;
    }

    return (
      typeof editor.areInnerBlocksControlled === "function" &&
      !!editor.areInnerBlocksControlled(block.clientId)
    );
  }
  /**
   * Push pending changes when a post save finishes successfully.
   */
  function onPostEditorChanged() {
    var store = postEditor();

    if (!store) {
      return;
    }

    var autosaving =
      typeof store.isAutosavingPost === "function" && store.isAutosavingPost();
    var savingPost = !!store.isSavingPost() && !autosaving;
    var savingEntities =
      typeof store.isSavingNonPostEntityChanges === "function" &&
      !!store.isSavingNonPostEntityChanges();

    var postFinished = wasSavingPost && !savingPost;
    var entitiesFinished = wasSavingEntities && !savingEntities;

    wasSavingPost = savingPost;
    wasSavingEntities = savingEntities;

    if (postFinished) {
      var succeeded =
        typeof store.didPostSaveRequestSucceed !== "function" ||
        store.didPostSaveRequestSucceed();

      if (succeeded) {
        flushAfterEditorSave();
      }
      return;
    }

    if (entitiesFinished) {
      flushAfterEditorSave();
    }
  }

  /**
   * After a save in the post or site editor: where the core data store
   * tracks entities, changes that belong to one are pushed when that entity
   * is saved (onEntitiesChanged). Here, only the rest.
   */
  function flushAfterEditorSave() {
    if (entityStore()) {
      flushOwner("");
    } else {
      flushPending();
    }
  }

  /**
   * Push pending changes when the widgets screen finishes saving.
   */
  function onWidgetsEditorChanged() {
    var store = widgetsEditor();

    if (!store) {
      return;
    }

    var saving = !!store.isSavingWidgetAreas();
    var finished = wasSavingWidgets && !saving;

    wasSavingWidgets = saving;

    if (finished) {
      flushPending();
    }
  }

  /**
   * Recursively walk all blocks and process supported image blocks.
   *
   * Some blocks do not expose their contents through innerBlocks: synced
   * patterns, the post content while the post editor shows the template,
   * and template parts. Their blocks are fetched from the store instead.
   *
   * @param {Array}   blocks   List of blocks to walk.
   * @param {Object}  editor   The block editor store.
   * @param {boolean}     inMarked Whether a surrounding block carries AltRelay's class.
   * @param {Object|null} owner    The entity these blocks are saved to.
   */
  function walkBlocks(blocks, editor, inMarked, owner) {
    blocks.forEach(function (block) {
      var config = BLOCK_CONFIG[block.name];
      var marked = inMarked || hasMarker((block.attributes || {}).className);

      if (config) {
        processBlock(block, config, inMarked, owner);
      }

      if (block.innerBlocks && block.innerBlocks.length) {
        walkBlocks(block.innerBlocks, editor, marked, owner);
      }

      if (hasControlledInnerBlocks(block, editor)) {
        var resolved = editor.getBlocks(block.clientId);

        lastControlled.push({ clientId: block.clientId, blocks: resolved });

        if (resolved && resolved.length && resolved !== block.innerBlocks) {
          walkBlocks(resolved, editor, marked, ownerOf(block, owner));
        }
      }
    });
  }
  /**
   * Process a single supported block for pull/push.
   *
   * An image inside a block that carries AltRelay's class is decorative too:
   * it is never filled. A description typed into it is still a description
   * of the image, and is pushed like any other.
   *
   * @param {Object}  block     The block object.
   * @param {Object}  config    The block type configuration.
   * @param {boolean}     inherited Whether a surrounding block carries AltRelay's class.
   * @param {Object|null} owner     The entity the block is saved to.
   */
  function processBlock(block, config, inherited, owner) {
    var attrs = block.attributes || {};
    var mediaId = attrs[config.idAttr];

    if (!mediaId || !showsImage(attrs, config) || isBound(attrs, config)) {
      return;
    }

    var clientId = block.clientId;
    var alt = attrs[config.altAttr] || "";
    var key = blockKey(clientId, mediaId);
    var decorative = isDecorativeBlock(attrs);

    if (walked) {
      walked[key] = true;
    }

    // First observation: remember the current value without writing anything.
    if (!Object.prototype.hasOwnProperty.call(seen, key)) {
      seen[key] = alt;
      synced[key] = alt;
      wasDecorative[key] = decorative;

      if (!alt && !decorative && !inherited && !EMPTY_IS_DECORATIVE) {
        pullAlt(key, clientId, mediaId, config);
      } else if (alt && !decorative && isUnsavedDraft(owner)) {
        adoptDraftAlt(key, clientId, mediaId, config, attrs[config.urlAttr] || "", owner);
      }
      return;
    }

    // Typing a description into a block that carries AltRelay's class means
    // the image is not decorative after all. Drop the class, so the new text
    // is treated like any other edit instead of being silently kept local.
    if (alt && hasMarker(attrs.className) && attrs.isDecorative !== true) {
      wp.data
        .dispatch("core/block-editor")
        .updateBlockAttributes(clientId, { className: removeMarker(attrs.className) });
      decorative = false;
    }

    // No longer decorative and still empty: bring back the library text.
    if (wasDecorative[key] && !decorative && !inherited && !alt) {
      pullAlt(key, clientId, mediaId, config);
    }
    wasDecorative[key] = decorative;

    if (alt === seen[key]) {
      return;
    }

    var previous = seen[key];
    seen[key] = alt;

    // Marking an image decorative empties its alt. That is a change to this
    // post only, never something to send to the library.
    if (decorative) {
      cancelPush(key);
      return;
    }

    // Clearing the field affects this post only. Offer the decorative marker,
    // which is usually what clearing an alt in one place means.
    if (!alt) {
      cancelPush(key);

      // Inside a marked block the image is decorative already.
      if (previous && !inherited) {
        notifyCleared(clientId, config, block.name, isLinked(attrs, config));
      }
      return;
    }

    if (alt === synced[key]) {
      cancelPush(key);
      return;
    }

    queuePush(key, {
      clientId: clientId,
      mediaId: mediaId,
      alt: alt,
      altAttr: config.altAttr,
      url: attrs[config.urlAttr] || "",
      owner: owner || null,
    });
  }

  /**
   * Whether blocks belong to a draft that was never saved by hand, as far
   * as AltRelay can tell: the post being edited, while it is a draft.
   *
   * An autosave stores a draft but pushes nothing, because the text may be
   * half typed. Opened again, the draft's alt text looks like any other.
   * Published content is left out: its alt text was pushed when it was
   * saved, or the push was refused.
   *
   * @param {Object|null} owner The entity the blocks are saved to.
   * @return {boolean} Whether alt text in them may never have been pushed.
   */
  function isUnsavedDraft(owner) {
    var main = mainOwner();
    var store = wp.data.select("core/editor");

    if (!owner || !main || ownerKey(owner) !== ownerKey(main)) {
      return false;
    }

    var status =
      typeof store.getCurrentPostAttribute === "function"
        ? store.getCurrentPostAttribute("status")
        : "";

    return status === "draft" || status === "auto-draft";
  }

  /**
   * Queue the alt text a draft opened with, when its Media Library item has
   * none: it was typed and only autosaved. It is pushed on the next save,
   * like an edit.
   *
   * @param {string}      key      The block key.
   * @param {string}      clientId The block's client ID.
   * @param {number}      mediaId  The attachment ID.
   * @param {Object}      config   The block configuration.
   * @param {string}      url      The image URL in the block.
   * @param {Object|null} owner    The entity the block is saved to.
   */
  function adoptDraftAlt(key, clientId, mediaId, config, url, owner) {
    withMedia(mediaId, false, function (media) {
      if (!media || media.alt_text || !mediaMatchesUrl(media, url)) {
        return;
      }

      // An edit made meanwhile is queued, or cancelled, on its own.
      var alt = currentBlockAlt(clientId, config.altAttr);

      if (pending[key] || !alt || alt !== seen[key] || alt !== synced[key]) {
        return;
      }

      synced[key] = "";
      queuePush(key, {
        clientId: clientId,
        mediaId: mediaId,
        alt: alt,
        altAttr: config.altAttr,
        url: url,
        owner: owner,
        adopted: true,
      });
    });
  }

  /**
   * Queue a change for the next save.
   *
   * Nothing is sent while the user is typing. An editor that never reports
   * a save (AltRelay knows the post, site and widgets editors and the
   * Customizer) keeps its changes in the content it is editing.
   *
   * @param {string} key    The block key.
   * @param {Object} change The change.
   */
  function queuePush(key, change) {
    clearRetry(key);
    delete pushAttempts[key];
    pending[key] = change;
  }

  /**
   * Drop any pending or retrying push for a block.
   *
   * @param {string} key The block key.
   */
  function cancelPush(key) {
    clearRetry(key);
    delete pending[key];
    delete pushAttempts[key];
  }

  /**
   * Clear the retry timer of a block.
   *
   * @param {string} key The block key.
   */
  function clearRetry(key) {
    if (retryTimers[key]) {
      clearTimeout(retryTimers[key]);
      delete retryTimers[key];
    }
  }

  /**
   * Push the pending changes of one entity, or of none.
   *
   * @param {string} key The entity key, or "" for changes without one.
   */
  function flushOwner(key) {
    Object.keys(pending).forEach(function (blockKey) {
      var change = pending[blockKey];

      if (ownerKey(change.owner) === key) {
        delete pending[blockKey];
        pushAltToMediaLibrary(blockKey, change);
      }
    });
  }

  /**
   * Push changes when the entity they belong to has been saved.
   *
   * The core data store knows when each entity is saved, whichever button
   * started the save: the post's own, the save panel's for a template part,
   * the Site Editor's. A change is pushed only when its own entity was
   * saved, and only when that save succeeded and was not an autosave.
   */
  function onEntitiesChanged() {
    var core = entityStore();

    if (!core) {
      return;
    }

    var looked = {};

    Object.keys(pending).forEach(function (blockKey) {
      var owner = pending[blockKey].owner;
      var key = ownerKey(owner);

      if (!owner || looked[key]) {
        return;
      }
      looked[key] = true;

      var saving = !!core.isSavingEntityRecord(owner.kind, owner.name, owner.id);
      var autosave =
        typeof core.isAutosavingEntityRecord === "function" &&
        !!core.isAutosavingEntityRecord(owner.kind, owner.name, owner.id);
      var was = savingOwners[key];

      if (saving) {
        savingOwners[key] = { autosave: (was && was.autosave) || autosave };
        return;
      }

      if (!was) {
        return;
      }

      delete savingOwners[key];

      var failed =
        typeof core.getLastEntitySaveError === "function" &&
        core.getLastEntitySaveError(owner.kind, owner.name, owner.id);

      if (!was.autosave && !failed) {
        flushOwner(key);
      }
    });
  }

  /**
   * Push every pending change.
   */
  function flushPending() {
    Object.keys(pending).forEach(function (key) {
      var change = pending[key];

      delete pending[key];
      pushAltToMediaLibrary(key, change);
    });
  }

  /**
   * Hand a Media Library item to a callback, loading it if necessary.
   *
   * Requests made in the same tick are combined into one REST request, so a
   * post with fifty undescribed images costs one request, not fifty.
   *
   * @param {number}   mediaId  The attachment ID.
   * @param {boolean}  fresh    Whether to load the item even when it is cached.
   * @param {Function} callback Receives (media|null, error|undefined).
   */
  function withMedia(mediaId, fresh, callback) {
    if (!fresh && Object.prototype.hasOwnProperty.call(mediaRecords, mediaId)) {
      callback(mediaRecords[mediaId]);
      return;
    }

    if (!mediaWaiters[mediaId]) {
      mediaWaiters[mediaId] = [];
    }
    mediaWaiters[mediaId].push(callback);

    if (!fetchScheduled) {
      fetchScheduled = true;
      Promise.resolve().then(fetchWaitingMedia);
    }
  }

  /**
   * Load every Media Library item somebody is waiting for.
   */
  function fetchWaitingMedia() {
    var ids = Object.keys(mediaWaiters);
    var waiters = mediaWaiters;

    fetchScheduled = false;
    mediaWaiters = {};

    for (var start = 0; start < ids.length; start += FETCH_PAGE) {
      fetchMedia(ids.slice(start, start + FETCH_PAGE), waiters);
    }
  }

  /**
   * Load one page of Media Library items and settle their waiters.
   *
   * @param {Array<string>} ids     Attachment IDs.
   * @param {Object}        waiters Callbacks by attachment ID.
   */
  function fetchMedia(ids, waiters) {
    function settle(records, err) {
      pulledCount = 0;

      ids.forEach(function (id) {
        var media = records ? records[id] || null : null;

        if (records) {
          mediaRecords[id] = media;
        }

        // One callback that throws must not keep the others from running,
        // nor turn a good response into a reported failure.
        waiters[id].forEach(function (callback) {
          try {
            callback(media, err);
          } catch (callbackErr) {
            if (typeof console !== "undefined" && console.error) {
              console.error("[AltRelay] Error while applying media " + id, callbackErr);
            }
          }
        });
      });

      announcePulls(pulledCount);
    }

    wp.apiFetch({
      path:
        "/wp/v2/media?include=" +
        ids.join(",") +
        "&per_page=" +
        FETCH_PAGE +
        "&_fields=id,alt_text,source_url,media_details.file,media_details.sizes,media_details.original_image",
    })
      .then(
        function (list) {
          var records = {};

          (Array.isArray(list) ? list : []).forEach(function (media) {
            if (media && media.id) {
              records[media.id] = media;
            }
          });

          return { records: records, err: undefined };
        },
        function (err) {
          if (typeof console !== "undefined" && console.warn) {
            console.warn("[AltRelay] Failed to load media " + ids.join(", "), err);
          }

          return { records: null, err: err || new Error("Request failed") };
        },
      )
      // Only the request decides between success and failure; what the
      // callbacks do with the answer is settled separately.
      .then(function (result) {
        settle(result.records, result.err);
      });
  }

  /**
   * Fetch alt text from the Media Library and apply it to a block.
   *
   * @param {string} key      The block cache key.
   * @param {string} clientId The block's client ID.
   * @param {number} mediaId  The attachment ID.
   * @param {Object} config   The block configuration.
   */
  function pullAlt(key, clientId, mediaId, config) {
    withMedia(mediaId, true, function (media) {
      // Non-blocking: on a failed request, a missing item or an item
      // without alt text, the block keeps its current (empty) alt.
      if (!media || !media.alt_text) {
        return;
      }

      // Re-check: the user may have typed, marked the image decorative,
      // swapped the image or removed the block while the request was in
      // flight.
      var editor = blockEditor();
      var current = editor ? editor.getBlockAttributes(clientId) : null;

      if (
        !current ||
        current[config.altAttr] ||
        current[config.idAttr] !== mediaId ||
        isDecorativeBlock(current) ||
        isBound(current, config) ||
        hasMarkedAncestor(editor, clientId)
      ) {
        return;
      }

      // The block's file is not this item's file: a stale or foreign ID.
      if (!mediaMatchesUrl(media, current[config.urlAttr])) {
        return;
      }

      // The pulled value is already in the library: record it so it is not
      // pushed straight back.
      seen[key] = media.alt_text;
      synced[key] = media.alt_text;

      var update = {};
      update[config.altAttr] = media.alt_text;

      var dispatch = wp.data.dispatch("core/block-editor");

      // Not an edit of the user's: no undo step, and the post does not
      // become "changed" just because it was opened.
      if (typeof dispatch.__unstableMarkNextChangeAsNotPersistent === "function") {
        dispatch.__unstableMarkNextChangeAsNotPersistent();
      }

      dispatch.updateBlockAttributes(clientId, update);
      pulledCount++;
    });
  }

  /**
   * Tell the user that images were filled from the Media Library.
   *
   * @param {number} count Number of images filled.
   */
  function announcePulls(count) {
    if (!count) {
      return;
    }

    notice(
      "snackbar",
      sprintf(
        /* translators: %s: number of images */
        _n(
          "AltRelay filled in alt text from the Media Library for %s image.",
          "AltRelay filled in alt text from the Media Library for %s images.",
          count,
          "altrelay",
        ),
        formatNumber(count),
      ),
      { id: "altrelay-pulled" },
    );
  }

  /**
   * Read the current alt text of a block straight from the store.
   *
   * @param {string} clientId The block's client ID.
   * @param {string} altAttr  The block attribute name for alt text.
   * @return {string|null} The current alt, or null when unavailable.
   */
  function currentBlockAlt(clientId, altAttr) {
    var editor = blockEditor();
    var attrs =
      editor && typeof editor.getBlockAttributes === "function"
        ? editor.getBlockAttributes(clientId)
        : null;

    return attrs ? attrs[altAttr] || "" : null;
  }

  /**
   * Whether an API error means the user may not edit the image.
   *
   * @param {Object} err The error.
   * @return {boolean} Whether retrying is pointless.
   */
  function isPermissionError(err) {
    var status = err && err.data && err.data.status;
    return status === 401 || status === 403;
  }

  /**
   * Push updated alt text to the Media Library.
   *
   * The item is looked up first, and nothing is written unless the block's
   * file belongs to it.
   *
   * @param {string} key    The block key.
   * @param {Object} change The change to push.
   */
  function pushAltToMediaLibrary(key, change) {
    // A draft's alt text only fills an item that is still without one, so
    // the item is loaded again: someone may have described it meanwhile.
    withMedia(change.mediaId, !!change.adopted, function (media, err) {
      if (err) {
        handlePushFailure(key, change, err);
        return;
      }

      // No such item here: the ID came with markup from somewhere else.
      if (!media) {
        return;
      }

      if (change.adopted && media.alt_text) {
        return;
      }

      if (!mediaMatchesUrl(media, change.url)) {
        notifyMismatch(change);
        return;
      }

      wp.apiFetch({
        path: "/wp/v2/media/" + change.mediaId,
        method: "POST",
        data: { alt_text: change.alt },
      })
        .then(function () {
          synced[key] = change.alt;
          delete pushAttempts[key];
          media.alt_text = change.alt;
          notifyPushed();
        })
        .catch(function (pushErr) {
          handlePushFailure(key, change, pushErr);
        });
    });
  }

  /**
   * Report a failed push and retry it, unless retrying is pointless.
   *
   * @param {string} key    The block key.
   * @param {Object} change The change that failed.
   * @param {Object} err    The error.
   */
  function handlePushFailure(key, change, err) {
    var denied = isPermissionError(err);

    notifyFailure(change.mediaId, denied, err);

    if (denied) {
      return;
    }

    var attempts = (pushAttempts[key] || 0) + 1;
    pushAttempts[key] = attempts;

    if (attempts >= MAX_ATTEMPTS) {
      return;
    }

    retryTimers[key] = setTimeout(function () {
      delete retryTimers[key];

      // Drop the retry if a newer change is waiting, or the block has
      // moved on since this attempt.
      var latest = currentBlockAlt(change.clientId, change.altAttr);

      if (pending[key] || (latest !== null && latest !== change.alt)) {
        return;
      }

      pushAltToMediaLibrary(key, change);
    }, RETRY_MS);
  }

  /**
   * Confirm that a change reached the Media Library, and say what follows
   * from it: every other post that uses the image changes too.
   */
  function notifyPushed() {
    notice(
      "snackbar",
      __(
        "Alt text saved to the Media Library. Other posts that use the image are updated in the background.",
        "altrelay",
      ),
      { id: "altrelay-pushed" },
    );
  }

  /**
   * Explain why a change stayed in this post.
   *
   * @param {Object} change The change that was not pushed.
   */
  function notifyMismatch(change) {
    notice(
      "info",
      sprintf(
        /* translators: %d: attachment ID */
        __(
          "This image's file does not belong to Media Library item %d, so its alt text was saved in this post only. Replace the image from the Media Library to connect it.",
          "altrelay",
        ),
        change.mediaId,
      ),
      {
        id: "altrelay-mismatch-" + change.clientId,
        isDismissible: true,
      },
    );
  }

  /**
   * Tell the user a push failed, so a change never silently stays local.
   *
   * @param {number}  mediaId The attachment ID.
   * @param {boolean} denied  Whether the user lacks permission.
   * @param {Object}  err     The error.
   */
  function notifyFailure(mediaId, denied, err) {
    var message = denied
      ? __(
          "You cannot edit this image in the Media Library, so its new alt text was saved in this post only.",
          "altrelay",
        )
      : sprintf(
          /* translators: %d: attachment ID */
          __(
            "AltRelay could not update the Media Library for image %d. The alt text in this post was not saved sitewide.",
            "altrelay",
          ),
          mediaId,
        );

    if (typeof console !== "undefined" && console.warn) {
      console.warn("[AltRelay] " + message, err);
    }

    notice("warning", message, {
      id: "altrelay-push-failed-" + mediaId,
      isDismissible: true,
    });
  }

  /**
   * Explain that clearing an alt only affected this post, and offer to mark
   * the image decorative, unless the image is a link.
   *
   * @param {string}  clientId  The block's client ID.
   * @param {Object}  config    The block configuration.
   * @param {string}  blockName The block name.
   * @param {boolean} linked    Whether the image is a link.
   */
  function notifyCleared(clientId, config, blockName, linked) {
    if (linked) {
      notice(
        "warning",
        __(
          "Alt text removed from this image in this post only. The image is a link, and its alt text is what names the link for screen reader users. Add alt text again, or remove the link.",
          "altrelay",
        ),
        {
          id: "altrelay-cleared-" + clientId,
          isDismissible: true,
        },
      );
      return;
    }

    notice(
      "info",
      __(
        "Alt text removed from this image in this post only. The Media Library keeps its description. If the image is purely decorative, mark it so screen readers skip it.",
        "altrelay",
      ),
      {
        id: "altrelay-cleared-" + clientId,
        isDismissible: true,
        actions: [
          {
            label: __("Mark as decorative", "altrelay"),
            onClick: function () {
              markDecorative(clientId, config, blockName);
            },
          },
        ],
      },
    );
  }

  /**
   * Mark a block decorative from outside its settings panel.
   *
   * Image blocks on WordPress 7.1+ get core's own setting, so the "Mark as
   * decorative" checkbox in the block settings reflects it. Other blocks get
   * AltRelay's class.
   *
   * @param {string} clientId  The block's client ID.
   * @param {Object} config    The block configuration.
   * @param {string} blockName The block name.
   */
  function markDecorative(clientId, config, blockName) {
    var editor = blockEditor();
    var attrs = editor ? editor.getBlockAttributes(clientId) : null;

    // The image may have become a link since the notice was shown.
    if (!attrs || isLinked(attrs, config)) {
      return;
    }

    var update = usesCoreDecorative(blockName)
      ? { isDecorative: true }
      : { className: addMarker(attrs.className) };
    update[config.altAttr] = "";

    wp.data.dispatch("core/block-editor").updateBlockAttributes(clientId, update);

    var notices = wp.data.dispatch("core/notices");
    if (notices && typeof notices.removeNotice === "function") {
      notices.removeNotice("altrelay-cleared-" + clientId);
    }
  }

  /**
   * Handle the decorative toggle.
   *
   * @param {Object}  props  Block edit props.
   * @param {Object}  config The block configuration.
   * @param {boolean} value  The new toggle state.
   */
  function onDecorativeToggle(props, config, value) {
    var update = {
      className: value
        ? addMarker(props.attributes.className)
        : removeMarker(props.attributes.className),
    };

    if (value) {
      update[config.altAttr] = "";
    }

    // Turning decorative off brings the library description back; the
    // subscriber notices the change, the same way as for core's setting.
    props.setAttributes(update);
  }

  /**
   * Add the "Decorative image" toggle to supported blocks' settings.
   */
  function registerDecorativeToggle() {
    if (
      !wp.hooks ||
      !wp.compose ||
      !wp.element ||
      !wp.blockEditor ||
      !wp.components ||
      typeof wp.data.useSelect !== "function"
    ) {
      return;
    }

    var el = wp.element.createElement;
    var useSelect = wp.data.useSelect;
    var Fragment = wp.element.Fragment;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var ToggleControl = wp.components.ToggleControl;

    var withDecorativeToggle = wp.compose.createHigherOrderComponent(
      function (BlockEdit) {
        return function (props) {
          var config = BLOCK_CONFIG[props.name];

          // Most blocks hold no image: they pass through before anything
          // is looked up. A block's type never changes while it is shown,
          // so the hook below is called on every render or on none.
          if (!config) {
            return el(BlockEdit, props);
          }

          var attrs = props.attributes || {};
          var decorative = hasMarker(attrs.className);
          var shown =
            !!props.isSelected &&
            !!attrs[config.idAttr] &&
            showsImage(attrs, config) &&
            !isBound(attrs, config);

          // Whether a block around the image carries the mark. Subscribed,
          // so the toggle follows when that block changes, and only looked
          // up for the selected image, the one whose settings are shown.
          var inherited = useSelect(
            function (select) {
              return (
                shown &&
                !decorative &&
                hasMarkedAncestor(select("core/block-editor"), props.clientId)
              );
            },
            [shown, decorative, props.clientId],
          );

          // Image blocks with core's own "Mark as decorative" checkbox do not
          // get a second toggle, unless they still carry AltRelay's class and
          // need a way to turn it off, or a block around them carries it and
          // core's unticked checkbox alone would not say so.
          var coreHandles =
            usesCoreDecorative(props.name) && !decorative && !inherited;

          if (!shown || coreHandles) {
            return el(BlockEdit, props);
          }

          // A linked image cannot become decorative. One that already is
          // keeps a working toggle, so it can be turned off.
          var refused = !decorative && !inherited && isLinked(attrs, config);
          var label = __("Decorative image", "altrelay");
          var help = __(
            "Turn on if the image adds no information. Its alt text is emptied in this post only; the Media Library keeps its description.",
            "altrelay",
          );

          if (decorative) {
            help = __(
              "Screen readers skip this image. AltRelay will not fill or sync its alt text here.",
              "altrelay",
            );
          } else if (inherited) {
            label = __("Decorative image (set on a surrounding block)", "altrelay");
            help = __(
              "A block around this image carries AltRelay's decorative class, so AltRelay does not fill or sync the alt text of the images in it. Screen readers skip those whose alt text is empty. Remove the class from that block to change it.",
              "altrelay",
            );
          } else if (refused) {
            label = __("Decorative image (not available for linked images)", "altrelay");
            help = __(
              "This image is a link, and its alt text is what names the link for screen reader users. Remove the link to mark the image decorative.",
              "altrelay",
            );
          }

          return el(
            Fragment,
            null,
            el(BlockEdit, props),
            el(
              InspectorControls,
              // Next to the alt text field: on WordPress 7.0 and newer the
              // Image block keeps it in the Content tab, which opens first.
              // Core's own decorative setting (7.1) implies that tab exists.
              usesCoreDecorative(props.name) ? { group: "content" } : null,
              el(
                PanelBody,
                { title: __("Accessibility", "altrelay"), initialOpen: true },
                el(ToggleControl, {
                  __nextHasNoMarginBottom: true,
                  label: label,
                  help: help,
                  checked: decorative || inherited,
                  disabled: refused || inherited,
                  onChange: function (value) {
                    onDecorativeToggle(props, config, value);
                  },
                }),
              ),
            ),
          );
        };
      },
      "withAltRelayDecorativeToggle",
    );

    wp.hooks.addFilter(
      "editor.BlockEdit",
      "altrelay/decorative-toggle",
      withDecorativeToggle,
    );
  }

  /**
   * Listen to the stores this script depends on, and to nothing else.
   *
   * A subscription without a store name runs on every change of every
   * store in the editor. Each listener here only needs one.
   */
  function start() {
    wp.data.subscribe(onBlocksChanged, "core/block-editor");

    if (postEditor()) {
      wp.data.subscribe(onPostEditorChanged, "core/editor");
    }

    if (entityStore()) {
      wp.data.subscribe(onEntitiesChanged, "core");
    }

    if (widgetsEditor()) {
      // Whether the widget areas are being saved is read from the core
      // data store, where the requests are tracked; the widgets store
      // itself does not change while they are saved.
      wp.data.subscribe(onWidgetsEditorChanged, "core/edit-widgets");
      wp.data.subscribe(onWidgetsEditorChanged, "core");
    }

    // The Customizer saves widgets through its own "Publish" button.
    if (wp.customize && typeof wp.customize.bind === "function") {
      wp.customize.bind("saved", flushPending);
    }
  }

  registerDecorativeToggle();

  // The editor's stores are registered by scripts that may load after this
  // one, so wait until every script on the page has run.
  if (typeof document !== "undefined" && document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})(wp);
