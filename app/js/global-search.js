// ============================================================
//  GLOBAL-SEARCH.JS
//  Role-Based Access Control (RBAC) System Spotlight Search
//  Provides instant search for authorized pages, events & orgs.
// ============================================================

(function () {
  'use strict';

  let activeWrap     = null;
  let activeDropdown = null;
  let debounceTimer  = null;
  let selectedIndex  = -1;
  let visibleItems   = [];

  function initSearchInputs() {
    document.querySelectorAll('.search-wrap').forEach(wrap => {
      if (wrap.dataset.searchBound) return;
      wrap.dataset.searchBound = 'true';

      const input = wrap.querySelector('input');
      if (!input) return;

      // 1. Normalize DOM structure: ensure search icon is first child
      let icon = wrap.querySelector('i.fa-magnifying-glass, .search-icon');
      if (!icon) {
        icon = document.createElement('i');
        icon.className = 'fa-solid fa-magnifying-glass search-icon';
        wrap.insertBefore(icon, input);
      } else {
        icon.classList.add('search-icon');
        if (input.nextElementSibling === icon) {
          wrap.insertBefore(icon, input);
        }
      }

      // 2. Clear button (✕)
      let clearBtn = wrap.querySelector('.search-clear-btn');
      if (!clearBtn) {
        clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'search-clear-btn';
        clearBtn.title = 'Clear search';
        clearBtn.setAttribute('aria-label', 'Clear search');
        clearBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        wrap.appendChild(clearBtn);
      }

      // 3. Dropdown results menu
      let dropdown = wrap.querySelector('.search-dropdown-menu');
      if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.className = 'search-dropdown-menu';
        dropdown.innerHTML = '<div class="search-dropdown-body"></div>';
        wrap.appendChild(dropdown);
      }

      function updateHasText() {
        if (input.value.trim().length > 0) {
          wrap.classList.add('has-text');
        } else {
          wrap.classList.remove('has-text');
        }
      }

      // Expand and focus
      function activateSearch() {
        wrap.classList.add('active');
        activeWrap = wrap;
        activeDropdown = dropdown;
        updateHasText();
        input.focus();
      }

      // Click icon / wrapper to expand
      wrap.addEventListener('click', function (e) {
        if (e.target.closest('.search-clear-btn') || e.target.closest('.search-dropdown-menu')) {
          return;
        }
        if (!wrap.classList.contains('active')) {
          e.preventDefault();
          activateSearch();
          openDropdown(wrap, dropdown, input.value.trim());
        }
      });

      // Clear button click
      clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        input.value = '';
        updateHasText();
        input.focus();
        openDropdown(wrap, dropdown, '');
      });

      // Focus
      input.addEventListener('focus', function () {
        activateSearch();
        openDropdown(wrap, dropdown, input.value.trim());
      });

      // Input changes with debounce
      input.addEventListener('input', function (e) {
        activateSearch();
        const q = e.target.value.trim();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          openDropdown(wrap, dropdown, q);
        }, 130);
      });

      // Keyboard navigation
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          input.value = '';
          updateHasText();
          wrap.classList.remove('active');
          input.blur();
          closeAllDropdowns();
          return;
        }
        handleKeyboardNav(e, dropdown);
      });
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.search-wrap')) {
        document.querySelectorAll('.search-wrap.active').forEach(w => {
          const inp = w.querySelector('input');
          if (!inp || inp.value.trim() === '') {
            w.classList.remove('active');
            w.classList.remove('has-text');
          }
        });
        closeAllDropdowns();
      }
    });
  }

  function openDropdown(wrap, dropdown, query) {
    dropdown.classList.add('active');
    const body = dropdown.querySelector('.search-dropdown-body');
    if (!body) return;

    body.innerHTML = `
      <div class="search-dropdown-empty">
        <i class="fa-solid fa-spinner fa-spin" style="color:#2563eb; margin-right:6px;"></i>
        Searching system...
      </div>
    `;

    fetch('../shared/search_actions.php?q=' + encodeURIComponent(query))
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          body.innerHTML = '<div class="search-dropdown-empty">No results found.</div>';
          visibleItems = [];
          selectedIndex = -1;
          return;
        }
        renderDropdownResults(body, data.results, query);
      })
      .catch(() => {
        body.innerHTML = '<div class="search-dropdown-empty">Unable to complete search request.</div>';
        visibleItems = [];
        selectedIndex = -1;
      });
  }

  function renderDropdownResults(body, results, query) {
    const { modules = [], events = [], clubs = [] } = results;
    const total = modules.length + events.length + clubs.length;

    if (total === 0) {
      body.innerHTML = `
        <div class="search-dropdown-empty">
          <i class="fa-solid fa-folder-open" style="font-size:1.4rem; color:#94a3b8; display:block; margin-bottom:6px;"></i>
          <div>No results found for "<strong>${escapeHtml(query)}</strong>"</div>
        </div>
      `;
      visibleItems = [];
      selectedIndex = -1;
      return;
    }

    let html = '';

    // 1. Pages & Modules
    if (modules.length > 0) {
      html += `<div class="search-dropdown-group-title"><i class="fa-solid fa-layer-group" style="color:#2563eb;"></i> Modules &amp; Pages</div>`;
      modules.forEach(item => {
        html += `
          <a href="${item.url}" class="search-dropdown-item">
            <div class="search-dropdown-icon">
              <i class="${item.icon || 'fa-solid fa-file'}"></i>
            </div>
            <div class="search-dropdown-info">
              <div class="search-dropdown-title">${escapeHtml(item.title)}</div>
              <div class="search-dropdown-desc">${escapeHtml(item.description || '')}</div>
            </div>
            <span class="search-dropdown-badge">Module</span>
          </a>
        `;
      });
    }

    // 2. Events & Activities
    if (events.length > 0) {
      html += `<div class="search-dropdown-group-title"><i class="fa-solid fa-calendar-days" style="color:#16a34a;"></i> Campus Events</div>`;
      events.forEach(item => {
        html += `
          <a href="${item.url}" class="search-dropdown-item">
            <div class="search-dropdown-icon" style="color:#16a34a; background:#f0fdf4;">
              <i class="${item.icon || 'fa-solid fa-calendar-check'}"></i>
            </div>
            <div class="search-dropdown-info">
              <div class="search-dropdown-title">${escapeHtml(item.title)}</div>
              <div class="search-dropdown-desc">${item.description || ''}</div>
            </div>
            <span class="search-dropdown-badge" style="background:#dcfce7; color:#15803d;">Event</span>
          </a>
        `;
      });
    }

    // 3. Recognized Organizations
    if (clubs.length > 0) {
      html += `<div class="search-dropdown-group-title"><i class="fa-solid fa-sitemap" style="color:#9333ea;"></i> Student Organizations</div>`;
      clubs.forEach(item => {
        html += `
          <a href="${item.url}" class="search-dropdown-item">
            <div class="search-dropdown-icon" style="color:#9333ea; background:#faf5ff;">
              <i class="${item.icon || 'fa-solid fa-sitemap'}"></i>
            </div>
            <div class="search-dropdown-info">
              <div class="search-dropdown-title">${escapeHtml(item.title)}</div>
              <div class="search-dropdown-desc">${escapeHtml(item.description || '')}</div>
            </div>
            <span class="search-dropdown-badge" style="background:#f3e8ff; color:#7e22ce;">Club</span>
          </a>
        `;
      });
    }

    body.innerHTML = html;

    visibleItems = Array.from(body.querySelectorAll('.search-dropdown-item'));
    selectedIndex = visibleItems.length > 0 ? 0 : -1;
    updateSelection();

    // Click handler for search items
    visibleItems.forEach(item => {
      item.addEventListener('click', function (e) {
        e.preventDefault();
        const dest = item.getAttribute('href');
        if (dest) {
          window.location.href = dest;
        }
      });
    });
  }

  function handleKeyboardNav(e, dropdown) {
    if (!dropdown.classList.contains('active') || !visibleItems.length) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      selectedIndex = (selectedIndex + 1) % visibleItems.length;
      updateSelection();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      selectedIndex = (selectedIndex - 1 + visibleItems.length) % visibleItems.length;
      updateSelection();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (selectedIndex >= 0 && visibleItems[selectedIndex]) {
        visibleItems[selectedIndex].click();
      } else if (visibleItems.length > 0) {
        visibleItems[0].click();
      }
    }
  }

  function updateSelection() {
    visibleItems.forEach((el, idx) => {
      const isSel = (idx === selectedIndex);
      el.classList.toggle('selected', isSel);
      if (isSel) {
        el.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  function closeAllDropdowns() {
    document.querySelectorAll('.search-dropdown-menu.active').forEach(d => {
      d.classList.remove('active');
    });
    selectedIndex = -1;
    visibleItems = [];
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Export to global scope if needed
  window.initGlobalSearch = initSearchInputs;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSearchInputs);
  } else {
    initSearchInputs();
  }
})();
