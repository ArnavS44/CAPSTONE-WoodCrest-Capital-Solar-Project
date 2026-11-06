(function () {
  'use strict';
  function refresh(widget) {
    var searchInput = widget.querySelector('.wc-property-group-search');
    var typeInput = widget.querySelector('.wc-property-group-type');
    var statusInput = widget.querySelector('.wc-property-group-status');
    var orderInput = widget.querySelector('.wc-property-group-order');
    var search = searchInput ? searchInput.value.trim().toLocaleLowerCase() : '';
    var type = typeInput ? typeInput.value.toLocaleLowerCase() : '';
    var status = statusInput ? statusInput.value.toLocaleLowerCase() : '';
    var order = orderInput ? orderInput.value : 'newest';
    widget.querySelectorAll('.wc-property-group').forEach(function(group) {
      var tbody = group.querySelector('tbody');
      var rows = Array.from(tbody ? tbody.rows : []);
      rows.sort(function(a,b) {
        var ad = a.dataset.date || '', bd = b.dataset.date || '';
        return order === 'oldest' ? ad.localeCompare(bd) : bd.localeCompare(ad);
      });
      rows.forEach(function(row) { tbody.appendChild(row); });
      var property = group.querySelector('summary strong').textContent.toLocaleLowerCase();
      var visible = 0;
      rows.forEach(function(row) {
        var matchSearch = !search || property.includes(search) || row.textContent.toLocaleLowerCase().includes(search);
        var matchType = !type || row.dataset.type === type;
        var matchStatus = !status || row.dataset.status === status;
        var match = matchSearch && matchType && matchStatus;
        row.hidden = !match;
        if (match) visible++;
      });
      group.hidden = !visible;
      var count = group.querySelector('.wc-property-group-count');
      if (count) count.textContent = visible + (visible === 1 ? ' record' : ' records');
      if (search && visible) group.open = true;
    });
  }
  function initialize() {
    document.querySelectorAll('.wc-property-groups').forEach(function(widget) {
      widget.querySelectorAll('input, select').forEach(function(field) {
        field.addEventListener(field.tagName === 'INPUT' ? 'input' : 'change', function() { refresh(widget); });
      });
      refresh(widget);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
