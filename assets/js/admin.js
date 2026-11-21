/**
 * AltRelay dashboard.
 *
 * Asks before a button starts work that changes every post using an image,
 * such as "Mark decorative". The question comes from the button's
 * data-altrelay-confirm attribute, already translated on the server. Without
 * JavaScript the button works as before, without the question.
 *
 * After a form was sent, the page that comes back says what happened in a
 * notice. Focus moves to it, so screen reader users hear it, and keyboard
 * users continue from there.
 *
 * @package AltRelay
 */

(function () {
  "use strict";

  /**
   * Ask the button's question, and stop its form when the answer is no.
   *
   * @param {Event} event The click event.
   * @return {void}
   */
  function confirmWork(event) {
    var target = event.target;
    var button = target && typeof target.closest === "function" ? target.closest("[data-altrelay-confirm]") : null;

    if (!button) {
      return;
    }

    if (!window.confirm(button.getAttribute("data-altrelay-confirm"))) {
      event.preventDefault();
    }
  }

  /**
   * Move focus to the notice that reports what the last form did.
   *
   * Run once the page has loaded: WordPress moves notices below the page
   * heading when it is ready, and moving an element takes its focus away.
   *
   * @return {void}
   */
  function focusResult() {
    var notice = document.querySelector(".altrelay-result");

    if (notice && typeof notice.focus === "function") {
      notice.focus();
    }
  }

  document.addEventListener("click", confirmWork);
  window.addEventListener("load", focusResult);
})();
