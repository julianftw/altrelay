/**
 * Jest tests for the AltRelay dashboard script (admin.js).
 *
 * @package AltRelay
 */

const path = require("path");

const SCRIPT_PATH = path.join(__dirname, "..", "..", "assets", "js", "admin.js");

/**
 * Run the script against a minimal document and return its click handler.
 *
 * @param {boolean} answer What window.confirm answers.
 * @return {{click: Function, confirm: jest.Mock}} The handler and the confirm mock.
 */
function load(answer, notice) {
  const listeners = {};
  const document = {
    addEventListener: jest.fn(function (type, listener) {
      listeners[type] = listener;
    }),
    querySelector: jest.fn(() => notice || null),
  };
  const window = {
    confirm: jest.fn(() => answer),
    addEventListener: jest.fn(function (type, listener) {
      listeners["window:" + type] = listener;
    }),
  };

  global.document = document;
  global.window = window;

  // A fresh module per test, instrumented for coverage.
  jest.isolateModules(function () {
    require(SCRIPT_PATH);
  });

  return { click: listeners.click, loaded: listeners["window:load"], confirm: window.confirm, document: document };
}

/**
 * A click event on an element.
 *
 * @param {Object|null} button What closest() finds, or null.
 * @return {Object} The event.
 */
function clickOn(button) {
  return {
    target: { closest: jest.fn(() => button) },
    preventDefault: jest.fn(),
  };
}

afterEach(function () {
  delete global.document;
  delete global.window;
});

const BUTTON = { getAttribute: jest.fn(() => "Mark photo.jpg decorative in all 3 posts that use it?") };

describe("admin.js", () => {
  test("asks the button's question and lets the form go on a yes", () => {
    const { click, confirm } = load(true);
    const event = clickOn(BUTTON);

    click(event);

    expect(event.target.closest).toHaveBeenCalledWith("[data-altrelay-confirm]");
    expect(confirm).toHaveBeenCalledWith("Mark photo.jpg decorative in all 3 posts that use it?");
    expect(event.preventDefault).not.toHaveBeenCalled();
  });

  test("stops the form on a no", () => {
    const { click } = load(false);
    const event = clickOn(BUTTON);

    click(event);

    expect(event.preventDefault).toHaveBeenCalled();
  });

  test("moves focus to the notice that says what the form did, once the page has loaded", () => {
    // Regression (sixth audit, accessibility): the notice after a form was
    // sent was neither announced nor focused.
    const notice = { focus: jest.fn() };
    const { loaded, document } = load(true, notice);

    expect(notice.focus).not.toHaveBeenCalled();

    loaded();

    expect(document.querySelector).toHaveBeenCalledWith(".altrelay-result");
    expect(notice.focus).toHaveBeenCalled();
  });

  test("does nothing on load when there is no notice", () => {
    const { loaded } = load(true);

    expect(() => loaded()).not.toThrow();
  });

  test("leaves other clicks alone", () => {
    const { click, confirm } = load(false);
    const event = clickOn(null);

    click(event);
    click({ target: null, preventDefault: event.preventDefault });
    click({ target: {}, preventDefault: event.preventDefault });

    expect(confirm).not.toHaveBeenCalled();
    expect(event.preventDefault).not.toHaveBeenCalled();
  });
});
