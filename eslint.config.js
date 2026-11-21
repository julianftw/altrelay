/**
 * ESLint flat config for AltRelay.
 *
 * @package AltRelay
 */

const js = require("@eslint/js");
const globals = require("globals");

module.exports = [
  {
    ignores: ["node_modules/", "vendor/", "dist/", "build/"],
  },
  js.configs.recommended,
  {
    files: ["assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2021,
      sourceType: "script",
      globals: {
        ...globals.browser,
        wp: "readonly",
        altrelayEditor: "readonly",
      },
    },
    rules: {
      // The editor script is a plain IIFE loaded without a build step.
      "no-var": "off",
      "prefer-const": "off",
      "no-unused-vars": ["error", { args: "none" }],
      eqeqeq: ["error", "always"],
      curly: ["error", "all"],
      "no-implicit-globals": "error",
    },
  },
  {
    files: ["tests/js/**/*.js", "eslint.config.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "commonjs",
      globals: {
        ...globals.node,
        ...globals.jest,
      },
    },
    rules: {
      "no-unused-vars": ["error", { args: "none" }],
      eqeqeq: ["error", "always"],
      // The tests evaluate the IIFE the same way the browser does.
      "no-eval": "off",
    },
  },
  {
    // End-to-end tests: Node code, with callbacks that run inside the page.
    files: ["tests/e2e/**/*.js", "playwright.config.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "commonjs",
      globals: {
        ...globals.node,
        window: "readonly",
      },
    },
    rules: {
      "no-unused-vars": ["error", { args: "none" }],
      eqeqeq: ["error", "always"],
      curly: ["error", "all"],
    },
  },
];
