/* ASLA desktop mega menu interaction. */
(function () {
  'use strict';

  var DESKTOP = '(min-width: 992px)'; // keep in sync with css/mega-menu.css breakpoint

  function activate(list, selector, match) {
    list.forEach(function (el) {
      el.classList.toggle('is-active', match(el));
    });
  }

  function initItem(mega) {
    var tabs = Array.prototype.slice.call(mega.querySelectorAll('.asla-mega__tab'));
    var bodies = Array.prototype.slice.call(mega.querySelectorAll('.asla-mega__body'));
    var panel = mega.querySelector('.asla-mega__panel');
    var closeTimer = null;

    function openPanel() {
      if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
      if (panel) { panel.classList.add('is-open'); }
    }
    function schedulePanelClose() {
      if (closeTimer) { clearTimeout(closeTimer); }
      closeTimer = setTimeout(function () {
        if (panel) { panel.classList.remove('is-open'); }
        if (tabs[0]) { showTab(tabs[0].getAttribute('data-tab')); }
      }, 150);
    }

    mega.addEventListener('mouseenter', openPanel);
    mega.addEventListener('mouseleave', schedulePanelClose);
    if (panel) {
      panel.addEventListener('mouseenter', openPanel);
      panel.addEventListener('mouseleave', schedulePanelClose);
    }

    function showTab(tabId) {
      activate(tabs, null, function (t) { return t.getAttribute('data-tab') === tabId; });
      activate(bodies, null, function (b) { return b.getAttribute('data-tab-body') === tabId; });
      // Activate the first side-tab with content in the now-active body.
      var body = mega.querySelector('.asla-mega__body[data-tab-body="' + tabId + '"]');
      if (!body) { return; }
      var firstSide = body.querySelector('.asla-mega__sidetab:not(.asla-mega__sidetab--label)');
      if (firstSide) { showSide(body, firstSide.getAttribute('data-side')); }
    }

    function showSide(body, sideId) {
      var sides = Array.prototype.slice.call(body.querySelectorAll('.asla-mega__sidetab'));
      var groups = Array.prototype.slice.call(body.querySelectorAll('.asla-mega__group'));
      activate(sides, null, function (s) { return s.getAttribute('data-side') === sideId; });
      activate(groups, null, function (g) { return g.getAttribute('data-side-body') === sideId; });
    }

    // Hover/focus switching on tabs.
    tabs.forEach(function (tab) {
      tab.addEventListener('mouseenter', function () { showTab(tab.getAttribute('data-tab')); });
      tab.addEventListener('focus', function () { showTab(tab.getAttribute('data-tab')); });
    });

    // Hover/focus switching on side-tabs (skip label headings).
    bodies.forEach(function (body) {
      body.querySelectorAll('.asla-mega__sidetab:not(.asla-mega__sidetab--label)').forEach(function (side) {
        side.addEventListener('mouseenter', function () { showSide(body, side.getAttribute('data-side')); });
        side.addEventListener('focus', function () { showSide(body, side.getAttribute('data-side')); });
      });
    });

  }

  function init() {
    if (!window.matchMedia || !window.matchMedia(DESKTOP).matches) { return; }
    document.querySelectorAll('.asla-mm .asla-mega.has-mega').forEach(initItem);
  }

  if (document.readyState !== 'loading') { init(); }
  else { document.addEventListener('DOMContentLoaded', init); }
})();
