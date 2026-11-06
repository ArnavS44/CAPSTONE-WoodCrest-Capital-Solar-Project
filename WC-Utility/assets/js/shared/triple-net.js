(function () {
  'use strict';

  function money(value) {
    var n = Number(value || 0);
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' $';
  }

  function scrollKey() {
    return 'wcTnScroll:' + window.location.pathname;
  }

  function saveScroll() {
    try { sessionStorage.setItem(scrollKey(), String(window.scrollY || 0)); } catch (e) {}
  }

  function restoreScroll() {
    try {
      var value = sessionStorage.getItem(scrollKey());
      if (value === null) return;
      sessionStorage.removeItem(scrollKey());
      var y = Number(value);
      if (!Number.isNaN(y)) window.requestAnimationFrame(function () { window.scrollTo(0, y); });
    } catch (e) {}
  }


  function updateBillingYear(expenseInput) {
    if (!expenseInput) return;
    var form = expenseInput.closest('.wc-tn-selector-form');
    if (!form) return;
    var billingInput = form.querySelector('#wc_tn_billing_year');
    if (!billingInput) return;
    var expenseYear = Number(expenseInput.value || 0);
    billingInput.value = expenseYear >= 2000 && expenseYear <= 2099 ? String(expenseYear + 1) : '';
  }

  function updateRates(scope) {
    var setup = scope.querySelector('[data-wc-tn-allocable-sqft]');
    if (!setup) return;
    var sqft = Number(setup.dataset.wcTnAllocableSqft || 0);
    scope.querySelectorAll('.wc-tn-expense-block').forEach(function (block) {
      var totalInput = block.querySelector('.wc-tn-expense-total');
      var rateEl = block.querySelector('.wc-tn-live-rate');
      if (!totalInput || !rateEl) return;
      var total = Number(totalInput.value || 0);
      rateEl.textContent = money(sqft > 0 ? total / sqft : 0);
    });
  }

  function updateOwnerResponsibility(scope) {
    var setup = scope.querySelector('[data-wc-tn-allocable-sqft]');
    if (!setup) return;
    var toggle = setup.querySelector('.wc-tn-owner-toggle');
    var percentInput = setup.querySelector('.wc-tn-owner-percent');
    var percentWrap = setup.querySelector('.wc-tn-owner-percent-wrap');
    if (!toggle || !percentInput || !percentWrap) return;

    percentWrap.hidden = !toggle.checked;
    if (!toggle.disabled) percentInput.disabled = !toggle.checked;
  }

  function updateChargeCard(card) {
    var method = card.querySelector('.wc-tn-method');
    var adjustment = card.querySelector('.wc-tn-adjustment-input');
    var fixed = card.querySelector('.wc-tn-fixed-input');
    var reason = card.querySelector('.wc-tn-reason-input');
    var finalEl = card.querySelector('.wc-tn-final-preview');
    if (!method || !adjustment || !fixed || !reason || !finalEl) return;

    var isFixed = method.value === 'FIXED';
    if (!method.disabled) {
      adjustment.disabled = isFixed;
      fixed.disabled = !isFixed;
      fixed.required = isFixed;
    }
    var base = Number(card.dataset.base || 0);
    var adj = Number(adjustment.value || 0);
    var fixedVal = Number(fixed.value || 0);
    var final = isFixed ? fixedVal : base + adj;
    finalEl.textContent = money(Math.max(0, final));
    if (!reason.disabled) reason.required = isFixed || Math.abs(adj) > 0.00001;
    card.classList.toggle('is-fixed', isFixed);
    card.classList.toggle('has-adjustment', !isFixed && Math.abs(adj) > 0.00001);
  }

  function snapshotForm(form) {
    var parts = [];
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
        parts.push(el.name + '=');
      } else {
        parts.push(el.name + '=' + String(el.value));
      }
    });
    return parts.join('&');
  }

  function setDirtyState(form) {
    if (!form.dataset.wcTnInitial) return;
    var dirty = snapshotForm(form) !== form.dataset.wcTnInitial;
    form.querySelectorAll('.wc-tn-track-dirty').forEach(function (button) {
      button.classList.toggle('is-ready', dirty);
      var idle = button.dataset.idleLabel;
      var ready = button.dataset.readyLabel;
      if (idle && ready) button.textContent = dirty ? ready : idle;
    });
  }

  function initTrackedForm(form) {
    form.dataset.wcTnInitial = snapshotForm(form);
    setDirtyState(form);
  }

  document.addEventListener('change', function (event) {
    var page = event.target.closest('.wc-tn-page');
    if (event.target.matches('#wc_tn_expense_year')) updateBillingYear(event.target);
    if (page && event.target.matches('.wc-tn-owner-toggle')) updateOwnerResponsibility(page);
    if (event.target.matches('.wc-tn-method')) {
      var card = event.target.closest('.wc-tn-charge-card');
      if (card) updateChargeCard(card);
    }
    var form = event.target.closest('.wc-tn-track-form');
    if (form) setDirtyState(form);
  });

  document.addEventListener('input', function (event) {
    var page = event.target.closest('.wc-tn-page');
    if (event.target.matches('#wc_tn_expense_year')) updateBillingYear(event.target);
    if (page && event.target.matches('.wc-tn-expense-total')) updateRates(page);
    if (page && event.target.matches('.wc-tn-owner-percent')) updateOwnerResponsibility(page);
    if (event.target.matches('.wc-tn-adjustment-input, .wc-tn-fixed-input')) {
      var card = event.target.closest('.wc-tn-charge-card');
      if (card) updateChargeCard(card);
    }
    if (event.target.matches('.wc-tn-table-search')) {
      var cardRoot = event.target.closest('.wc-tn-card');
      if (!cardRoot) return;
      var query = event.target.value.trim().toLowerCase();
      cardRoot.querySelectorAll('.wc-tn-allocations-table tbody tr').forEach(function (row) {
        var show = !query || row.textContent.toLowerCase().indexOf(query) !== -1;
        row.hidden = !show;
        var id = row.getAttribute('data-wc-tn-search-row');
        var details = cardRoot.querySelector('[data-wc-tn-search-details="' + id + '"]');
        if (details) details.hidden = !show;
      });
    }
    var form = event.target.closest('.wc-tn-track-form');
    if (form) setDirtyState(form);
  });

  document.addEventListener('submit', function (event) {
    if (event.target.matches('.wc-tn-finalize-form') && !window.confirm('Finalize this Triple Net year for invoices?')) {
      event.preventDefault();
      return;
    }
    if (event.target.matches('.wc-tn-reopen-form') && !window.confirm('Reopen this Triple Net year?')) {
      event.preventDefault();
      return;
    }
    if (event.target.closest('.wc-tn-page')) saveScroll();
  });

  document.querySelectorAll('#wc_tn_expense_year').forEach(updateBillingYear);
  document.querySelectorAll('.wc-tn-page').forEach(function (page) {
    updateRates(page);
    updateOwnerResponsibility(page);
    page.querySelectorAll('.wc-tn-charge-card').forEach(updateChargeCard);
  });
  document.querySelectorAll('.wc-tn-track-form').forEach(initTrackedForm);
  restoreScroll();
})();
