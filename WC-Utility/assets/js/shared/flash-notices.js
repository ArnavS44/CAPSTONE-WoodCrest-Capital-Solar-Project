(function(){
  'use strict';
  function init(){
    var flashes = document.querySelectorAll('[data-wc-flash]');
    if(!flashes.length) return;
    try {
      var url = new URL(window.location.href);
      ['wc_message','wc_status','wc_tn_message','wc_tn_status'].forEach(function(key){url.searchParams.delete(key);});
      window.history.replaceState(window.history.state,'',url.pathname+url.search+url.hash);
    } catch(e) {  }
    flashes.forEach(function(notice){
      if(notice.getAttribute('data-wc-flash') !== 'success') return;
      window.setTimeout(function(){notice.classList.add('wc-flash-dismissed');
        window.setTimeout(function(){notice.remove();},260);
      },5000);
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
