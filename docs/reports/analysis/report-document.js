(function () {
  "use strict";

  var storageKey = "report-document-color-scheme";
  var root = document.documentElement;
  var media = window.matchMedia("(prefers-color-scheme: dark)");

  function storedScheme() {
    try {
      var value = window.localStorage.getItem(storageKey);

      return value === "light" || value === "dark" ? value : null;
    } catch (error) {
      return null;
    }
  }

  function preferredScheme() {
    return media.matches ? "dark" : "light";
  }

  function applyScheme(scheme) {
    root.dataset.colorScheme = scheme;

    var button = document.querySelector("[data-theme-toggle]");

    if (button) {
      var nextScheme = scheme === "dark" ? "jasny" : "ciemny";
      button.textContent = "Motyw: " + (scheme === "dark" ? "ciemny" : "jasny");
      button.setAttribute("aria-label", "Przełącz na motyw " + nextScheme);
      button.setAttribute("aria-pressed", scheme === "dark" ? "true" : "false");
    }
  }

  applyScheme(storedScheme() || preferredScheme());

  document.addEventListener("DOMContentLoaded", function () {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "theme-toggle";
    button.dataset.themeToggle = "";
    document.body.insertBefore(button, document.body.firstChild);
    applyScheme(root.dataset.colorScheme || preferredScheme());

    button.addEventListener("click", function () {
      var nextScheme = root.dataset.colorScheme === "dark" ? "light" : "dark";

      try {
        window.localStorage.setItem(storageKey, nextScheme);
      } catch (error) {
        // The preference still applies for the current page view.
      }

      applyScheme(nextScheme);
    });
  });

  media.addEventListener("change", function () {
    if (!storedScheme()) {
      applyScheme(preferredScheme());
    }
  });
})();
