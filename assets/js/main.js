/* ============================================================================
   odditytech.news — main.js (v1)
   Pure vanilla JS, no build step, no external deps. Targets evergreen browsers.

   Features:
   - View toggle (grid / compact / list) with persistence in localStorage
     under key 'odt.viewMode'.
   - Debounced client-side search across each card's title, summary, and tags.
   - Tag-chip filtering (multi-select AND) layered on top of the server-side
     category filter and pagination — operates purely on the current page.

   Server-side category filter (?cat=...) and pagination remain authoritative
   for no-JS clients and deep links; client-side filters only narrow the
   already-rendered page.
   ========================================================================= */
(function () {
  'use strict';

  var STORAGE_KEY  = 'odt.viewMode';
  var VALID_VIEWS  = ['grid', 'compact', 'list'];
  var DEFAULT_VIEW = 'grid';

  var grid          = document.querySelector('.headline-grid');
  var searchInput   = document.getElementById('search-input');
  var viewButtons   = document.querySelectorAll('.view-btn');
  var tagChips      = document.querySelectorAll('.tag-chip[data-tag-filter]');
  var tagClearBtn   = document.querySelector('[data-tag-clear]');
  var inCardTags    = document.querySelectorAll('.tag[data-tag]');
  var cards         = grid ? grid.querySelectorAll('.card') : [];
  var emptyFiltered = document.querySelector('.empty-state-filtered');
  var pagination    = document.querySelector('[data-pagination]');

  // -------- View mode ------------------------------------------------------
  function applyView(view) {
    if (VALID_VIEWS.indexOf(view) === -1) view = DEFAULT_VIEW;
    if (grid) {
      for (var i = 0; i < VALID_VIEWS.length; i++) {
        grid.classList.toggle('view-' + VALID_VIEWS[i], VALID_VIEWS[i] === view);
      }
    }
    for (var j = 0; j < viewButtons.length; j++) {
      var btn = viewButtons[j];
      var on = btn.getAttribute('data-view') === view;
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function persistView(view) {
    try { localStorage.setItem(STORAGE_KEY, view); } catch (e) { /* ignore */ }
  }

  function loadView() {
    try {
      var v = localStorage.getItem(STORAGE_KEY);
      return VALID_VIEWS.indexOf(v) >= 0 ? v : DEFAULT_VIEW;
    } catch (e) {
      return DEFAULT_VIEW;
    }
  }

  for (var v = 0; v < viewButtons.length; v++) {
    (function (btn) {
      btn.addEventListener('click', function () {
        var view = btn.getAttribute('data-view');
        applyView(view);
        persistView(view);
      });
    })(viewButtons[v]);
  }

  applyView(loadView());

  // -------- Filtering state ------------------------------------------------
  var activeTags = {};   // slug -> true
  var activeTagCount = 0;
  var searchTerm = '';

  function hasActiveFilters() {
    return searchTerm !== '' || activeTagCount > 0;
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  function cardMatches(card) {
    if (searchTerm) {
      var hay = (card.getAttribute('data-title')   || '') + ' ' +
                (card.getAttribute('data-summary') || '') + ' ' +
                (card.getAttribute('data-tags')    || '');
      if (hay.indexOf(searchTerm) === -1) return false;
    }
    if (activeTagCount > 0) {
      var raw = (card.getAttribute('data-tags') || '').split(/\s+/);
      var tagSet = {};
      for (var i = 0; i < raw.length; i++) {
        if (raw[i]) tagSet[raw[i]] = true;
      }
      for (var slug in activeTags) {
        if (Object.prototype.hasOwnProperty.call(activeTags, slug)) {
          if (!tagSet[slug]) return false;
        }
      }
    }
    return true;
  }

  function applyFilters() {
    var visible = 0;
    for (var i = 0; i < cards.length; i++) {
      var show = cardMatches(cards[i]);
      cards[i].hidden = !show;
      if (show) visible++;
    }
    if (emptyFiltered) {
      emptyFiltered.hidden = !(hasActiveFilters() && visible === 0);
    }
    if (grid) {
      grid.classList.toggle('is-empty', hasActiveFilters() && visible === 0);
    }
    // Hide pagination while client-side filters are active — the filter
    // operates on the current page only, so paging through filtered results
    // would be misleading. Direct page links still work for no-JS users.
    if (pagination) {
      pagination.hidden = hasActiveFilters();
    }
    if (tagClearBtn) {
      tagClearBtn.hidden = activeTagCount === 0;
    }
  }

  // -------- Search --------------------------------------------------------
  if (searchInput) {
    var runSearch = debounce(function () {
      searchTerm = searchInput.value.trim().toLowerCase();
      applyFilters();
    }, 150);
    searchInput.addEventListener('input', runSearch);
  }

  // -------- Tag chips -----------------------------------------------------
  function setChipState(slug, on) {
    for (var i = 0; i < tagChips.length; i++) {
      if (tagChips[i].getAttribute('data-tag-filter') === slug) {
        tagChips[i].classList.toggle('active', on);
        tagChips[i].setAttribute('aria-pressed', on ? 'true' : 'false');
      }
    }
  }

  function toggleTag(slug) {
    if (!slug) return;
    if (activeTags[slug]) {
      delete activeTags[slug];
      activeTagCount--;
      setChipState(slug, false);
    } else {
      activeTags[slug] = true;
      activeTagCount++;
      setChipState(slug, true);
    }
    applyFilters();
  }

  for (var c = 0; c < tagChips.length; c++) {
    (function (chip) {
      chip.addEventListener('click', function () {
        toggleTag(chip.getAttribute('data-tag-filter'));
      });
    })(tagChips[c]);
  }

  for (var t = 0; t < inCardTags.length; t++) {
    (function (el) {
      el.addEventListener('click', function () {
        var slug = el.getAttribute('data-tag');
        toggleTag(slug);
        var chip = document.querySelector('.tag-chip[data-tag-filter="' + slug + '"]');
        if (chip && typeof chip.scrollIntoView === 'function') {
          chip.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
      });
    })(inCardTags[t]);
  }

  if (tagClearBtn) {
    tagClearBtn.addEventListener('click', function () {
      for (var slug in activeTags) {
        if (Object.prototype.hasOwnProperty.call(activeTags, slug)) {
          setChipState(slug, false);
        }
      }
      activeTags = {};
      activeTagCount = 0;
      applyFilters();
    });
  }

  // Initial pass — no-op when no filters are active, but ensures pagination
  // and empty-state visibility are coherent on first paint.
  applyFilters();
})();
