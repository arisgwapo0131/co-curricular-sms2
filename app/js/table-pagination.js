/**
 * ============================================================
 *  TABLE-PAGINATION.JS — Universal Responsive Table Paginator
 *  BCP Co-Curricular Portal
 * ============================================================
 */

(function () {
  'use strict';

  class TablePaginator {
    constructor(tableOrSelector, options = {}) {
      this.table = typeof tableOrSelector === 'string' ? document.querySelector(tableOrSelector) : tableOrSelector;
      if (!this.table) return;

      // Prevent double init
      if (this.table._paginator) {
        this.table._paginator.refresh();
        return this.table._paginator;
      }

      this.options = Object.assign({
        pageSize: 10,
        pageSizeOptions: [10, 25, 50, 0], // 0 means 'All'
        showPageSizeSelector: false,
        showInfo: false,
        showTopBar: false,
        showBottomBar: true,
        searchFilterSelector: null,
        entityName: 'entries',
        className: 'pagination-wrapper'
      }, options);
      this.options.showInfo = false;
      this.options.showPageSizeSelector = false;

      this.currentPage = 1;
      this.pageSize = this.options.pageSize;
      this.table._paginator = this;

      this.init();
    }

    init() {
      // Ensure table is inside a responsive overflow wrapper
      if (!this.table.parentElement.classList.contains('table-responsive') && !this.table.parentElement.classList.contains('resp-table-wrap') && !this.table.parentElement.classList.contains('ledger-table-wrap') && !this.table.parentElement.classList.contains('table-wrap')) {
        const respWrap = document.createElement('div');
        respWrap.className = 'table-responsive';
        this.table.parentNode.insertBefore(respWrap, this.table);
        respWrap.appendChild(this.table);
      }

      // Create bottom pagination toolbar
      this.toolbar = document.createElement('div');
      this.toolbar.className = 'pagination-toolbar';
      
      // Controls section (right)
      this.controlsEl = document.createElement('div');
      this.controlsEl.className = 'pagination-controls';

      // Page size selector is disabled system-wide

      // Buttons container
      this.buttonsEl = document.createElement('div');
      this.buttonsEl.className = 'pagination-buttons';
      this.controlsEl.appendChild(this.buttonsEl);

      this.toolbar.appendChild(this.controlsEl);

      // Insert toolbar after responsive container
      const container = this.table.closest('.table-responsive') || this.table.closest('.resp-table-wrap') || this.table.closest('.ledger-table-wrap') || this.table.closest('.table-wrap') || this.table;
      container.parentNode.insertBefore(this.toolbar, container.nextSibling);

      // Bind search filter if provided
      if (this.options.searchFilterSelector) {
        const searchInput = document.querySelector(this.options.searchFilterSelector);
        if (searchInput) {
          searchInput.addEventListener('input', () => {
            setTimeout(() => {
              this.currentPage = 1;
              this.render();
            }, 50);
          });
        }
      }

      this.render();
    }

    getAllRows() {
      const tbody = this.table.querySelector('tbody');
      if (!tbody) return [];
      // Get all non-empty / non-notice rows
      return Array.from(tbody.querySelectorAll('tr')).filter(tr => {
        // Exclude system empty placeholders if they span 3+ cols
        const singleTd = tr.querySelector('td[colspan]');
        return !(singleTd && parseInt(singleTd.getAttribute('colspan') || '1', 10) >= 3);
      });
    }

    getVisibleRows() {
      return this.getAllRows().filter(tr => {
        return tr.style.display !== 'none' && !tr.classList.contains('filtered-out');
      });
    }

    render() {
      const allRows = this.getAllRows();
      // Only paginate rows that aren't hidden by search filters
      const visibleRows = allRows.filter(tr => {
        // Check if filtered out by search input
        return tr.getAttribute('data-search-hidden') !== 'true';
      });

      const totalEntries = visibleRows.length;
      if (totalEntries === 0) {
        this.toolbar.style.display = 'none';
        return;
      }
      this.toolbar.style.display = 'flex';

      const effectivePageSize = this.pageSize === 0 ? totalEntries : this.pageSize;
      const totalPages = Math.max(1, Math.ceil(totalEntries / (effectivePageSize || 1)));

      if (this.currentPage > totalPages) this.currentPage = totalPages;
      if (this.currentPage < 1) this.currentPage = 1;

      const startIndex = (this.currentPage - 1) * effectivePageSize;
      const endIndex = this.pageSize === 0 ? totalEntries : Math.min(startIndex + effectivePageSize, totalEntries);

      // Ensure all rows filtered out by search/criteria stay hidden
      allRows.forEach(tr => {
        if (tr.getAttribute('data-search-hidden') === 'true' || tr.classList.contains('filtered-out')) {
          tr.style.setProperty('display', 'none', 'important');
          tr.classList.add('is-hidden');
        }
      });

      // Apply visibility to rows
      visibleRows.forEach((tr, index) => {
        if (index >= startIndex && index < endIndex) {
          tr.style.removeProperty('display');
          tr.classList.remove('is-hidden');
        } else {
          tr.style.setProperty('display', 'none', 'important');
          tr.classList.add('is-hidden');
        }
      });

      // Info text is disabled system-wide
      if (this.infoEl) {
        this.infoEl.innerHTML = '';
        this.infoEl.style.display = 'none';
      }

      // Render pagination buttons
      this.renderButtons(totalPages);

      // Re-apply responsive mobile card labels
      if (typeof window.initResponsiveTables === 'function') {
        window.initResponsiveTables();
      }
    }

    renderButtons(totalPages) {
      this.buttonsEl.innerHTML = '';
      this.buttonsEl.style.display = 'inline-flex';

      // Prev Button with chevron icon
      const prevBtn = document.createElement('button');
      prevBtn.type = 'button';
      prevBtn.className = `card-btn btn-sm pagination-btn prev-btn ${this.currentPage <= 1 ? 'disabled' : ''}`;
      prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left"></i> Prev';
      prevBtn.disabled = (this.currentPage <= 1);
      prevBtn.addEventListener('click', () => {
        if (this.currentPage > 1) {
          this.currentPage--;
          this.render();
        }
      });
      this.buttonsEl.appendChild(prevBtn);

      // Page numbers (smart window with ellipsis)
      const pageNumbers = this.getPageNumbers(totalPages);
      pageNumbers.forEach(p => {
        if (p === '...') {
          const ellipsis = document.createElement('span');
          ellipsis.className = 'pagination-ellipsis';
          ellipsis.textContent = '...';
          this.buttonsEl.appendChild(ellipsis);
        } else {
          const pageBtn = document.createElement('button');
          pageBtn.type = 'button';
          pageBtn.className = `card-btn btn-sm pagination-btn page-num-btn ${p === this.currentPage ? 'active' : ''}`;
          pageBtn.textContent = p;
          pageBtn.addEventListener('click', () => {
            this.currentPage = p;
            this.render();
          });
          this.buttonsEl.appendChild(pageBtn);
        }
      });

      // Next Button with chevron icon
      const nextBtn = document.createElement('button');
      nextBtn.type = 'button';
      nextBtn.className = `card-btn btn-sm pagination-btn next-btn ${this.currentPage >= totalPages ? 'disabled' : ''}`;
      nextBtn.innerHTML = 'Next <i class="fa-solid fa-chevron-right"></i>';
      nextBtn.disabled = (this.currentPage >= totalPages);
      nextBtn.addEventListener('click', () => {
        if (this.currentPage < totalPages) {
          this.currentPage++;
          this.render();
        }
      });
      this.buttonsEl.appendChild(nextBtn);
    }

    getPageNumbers(totalPages) {
      if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, i) => i + 1);
      }
      const current = this.currentPage;
      const pages = [];
      for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= current - 1 && p <= current + 1)) {
          pages.push(p);
        } else if (p === current - 2 || p === current + 2) {
          pages.push('...');
        }
      }
      return pages;
    }

    refresh() {
      this.render();
    }
  }

  // Global helper to initialize pagination on a table
  window.initTablePagination = function (tableOrSelector, options = {}) {
    return new TablePaginator(tableOrSelector, options);
  };

  // Global helper to refresh pagination on a table
  window.refreshTablePagination = function (tableOrSelector) {
    const tbl = typeof tableOrSelector === 'string' ? document.querySelector(tableOrSelector) : tableOrSelector;
    if (tbl && tbl._paginator) {
      tbl._paginator.currentPage = 1;
      tbl._paginator.render();
    }
  };

  function autoInit() {
    document.querySelectorAll('table.data-table, table.ledger-table, table.resp-table, table.matrix-table, #councilActionQueueTable, #adminActionQueueTable').forEach(tbl => {
      // Don't auto-paginate modal tables without explicit id or meta tables
      if (tbl.closest('.modal-card') || tbl.closest('.modal-overlay')) return;
      if (tbl.classList.contains('meta-table') || tbl.classList.contains('no-auto-paginate')) return;
      if (!tbl._paginator) {
        window.initTablePagination(tbl, {
          pageSize: 10,
          showInfo: false
        });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

})();
