(function () {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  function node(tag, attrs, label) {
    var el = document.createElementNS(NS, tag);
    Object.keys(attrs || {}).forEach(function (key) { el.setAttribute(key, attrs[key]); });
    if (label !== undefined) el.textContent = label;
    return el;
  }
  function present(point, key) { return point[key] !== null && point[key] !== undefined && Number.isFinite(Number(point[key])); }
  function val(point, key) { var n = Number(point[key]); return Number.isFinite(n) ? Math.max(0, n) : 0; }
  function seriesClass(index, series) { return 'wc-graph-series-' + index + (series.prior ? ' wc-graph-prior' : ''); }
  function periodLabel(row, series) {
    var month = /^(\d{4})-(\d{2})$/.exec(String(row.label));
    return series.prior && month ? (Number(month[1])-1)+'-'+month[2] : row.label;
  }
  function nice(v, money, tight) {
    if (money) return '$' + Number(v).toLocaleString(undefined, {maximumFractionDigits: tight ? 0 : 2, minimumFractionDigits: tight ? 0 : 2});
    return Number(v).toLocaleString(undefined, {maximumFractionDigits: tight ? 0 : 2});
  }
  function plot(root) {
    var cfg;
    try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }
    var rows = cfg.data || [], series = cfg.series || [], style = cfg.style || 'line';
    if (!series.length) return;
    if (!rows.length) {
      root.replaceChildren();
      var empty = document.createElement('div');
      empty.className = 'wc-graph-empty-plot';
      empty.setAttribute('aria-label','Empty chart');
      for (var line = 0; line < 4; line++) {
        var rule = document.createElement('div'); rule.className = 'wc-graph-empty-rule'; empty.appendChild(rule);
      }
      root.appendChild(empty); return;
    }
    var category = style === 'bar' && rows.some(function (r) { return String(r.label).length > 10; });
    if (category && root.clientWidth < 460) {
      var peak = Math.max.apply(null, [1].concat(rows.map(function(d){return val(d,series[0].key);}))) || 1;
      var list = document.createElement('div'); list.className='wc-graph-mobile-bars'; list.setAttribute('role','img');
      list.setAttribute('aria-label',series[0].name+' by category');
      peak = Math.max.apply(null, [peak].concat(rows.flatMap(function(d){return series.map(function(s){return val(d,s.key);});})));
      rows.forEach(function(d){
        var item=document.createElement('div'); item.className='wc-graph-mobile-row';
        var name=document.createElement('strong'); name.textContent=d.label; name.className='wc-graph-mobile-name';item.appendChild(name);
        series.forEach(function(s,j){
          if(!present(d,s.key)) return;
          var top=document.createElement('div'); top.className='wc-graph-mobile-heading';
          var label=document.createElement('span'); label.textContent=s.name;
          var amount=document.createElement('strong'); amount.textContent=nice(val(d,s.key),!!s.money,false);
          top.appendChild(label);top.appendChild(amount);item.appendChild(top);
          var track=document.createElement('div'); track.className='wc-graph-mobile-track';
          var fill=document.createElement('div');fill.className='wc-graph-mobile-fill'+(s.prior?' wc-graph-mobile-prior':'');
          fill.style.width=(val(d,s.key)/peak*100)+'%';track.appendChild(fill);item.appendChild(track);
        });list.appendChild(item);
      });
      root.replaceChildren(list); return;
    }

    var width = Math.max(240, Math.round(root.clientWidth || root.getBoundingClientRect().width || 600));
    var height = category ? Math.max(84, rows.length * (series.length > 1 ? 62 : 44) + 30) : Math.min(330,Math.max(270,Math.round(width*.67)));
    var l = category ? Math.min(205,Math.max(160,Math.round(width*.34))) : (width < 380 ? 52 : 72),
        r = category ? 28 : 14, t = category ? 12 : 24, b = category ? 12 : 56;
    var plotW = width - l - r, plotH = height - t - b;
    var max = 1, allValues = [];
    rows.forEach(function (d) { series.forEach(function (s) { allValues.push(val(d, s.key)); }); });
    max = Math.max.apply(null, [1].concat(allValues));
    var svg = node('svg', {viewBox:'0 0 '+width+' '+height,role:'img','aria-label':series.map(function(s){return s.name;}).join(', ')+' chart','preserveAspectRatio':'xMidYMid meet'});
    if (!category) for (var k = 0; k <= 4; k++) {
      var yy = t + plotH * (1-k/4);
      svg.appendChild(node('line', {x1:l,y1:yy,x2:width-r,y2:yy,class:'wc-graph-gridline'}));
      svg.appendChild(node('text',{x:l-12,y:yy+4,'text-anchor':'end',class:'wc-graph-axis'},nice(max*k/4,!!series[0].money,true)));
    }
    var slot = plotW / Math.max(1,rows.length);
    function addTooltip(el, text) { el.appendChild(node('title',{},text)); el.setAttribute('tabindex','0'); el.setAttribute('aria-label',text); return el; }
    function color(i, s) { return seriesClass(i, s); }
    if (category) {
      var maxLabel = Math.max(15,Math.floor((l-16)/6.8));
      var trackW = Math.max(45, plotW - 108);
      var rowHeight = series.length > 1 ? 62 : 44;
      rows.forEach(function (d,i) {
        var y = t + 6 + i*rowHeight;
        var label = String(d.label || '');
        var display = label.length > maxLabel ? label.slice(0,maxLabel-1) + '…' : label;
        var cat = node('text',{x:l-12,y:y+17,'text-anchor':'end',class:'wc-graph-category-label'},display);
        cat.appendChild(node('title',{},label));svg.appendChild(cat);
        series.forEach(function(s,j){
          if (!present(d,s.key)) return;
          var v=val(d,s.key), by=y+(j*27), barWidth=v/max*trackW;
          svg.appendChild(node('line',{x1:l,y1:by+23,x2:l+trackW,y2:by+23,class:'wc-graph-rowline'}));
          if (v>0) {
            var rect=node('rect',{x:l,y:by+2,width:barWidth,height:18,rx:4,class:'wc-graph-rect '+color(j,s)});
            svg.appendChild(addTooltip(rect,label+' · '+s.name+': '+nice(v,!!s.money,false)));
          }
          svg.appendChild(node('text',{x:l+barWidth+6,y:by+16,class:'wc-graph-value-label'},nice(v,!!s.money,false)));
        });
      });
    } else if (style==='line') {
      series.forEach(function (s,j) {
        var segment=[];
        function drawSegment(){
          if(segment.length>1) svg.appendChild(node('polyline',{points:segment.map(function(p){return p.join(',');}).join(' '),fill:'none',class:'wc-graph-path '+color(j,s)}));
          segment=[];
        }
        rows.forEach(function(d,i){
          if(!present(d,s.key)){drawSegment();return;}
          var pt=[l+slot*(i+.5),t+plotH-val(d,s.key)/max*plotH];
          segment.push(pt);
          var circle=node('circle',{cx:pt[0],cy:pt[1],r:width<420?3:4.5,class:'wc-graph-dot '+color(j,s)});
          svg.appendChild(addTooltip(circle,periodLabel(d,s)+' · '+s.name+': '+nice(val(d,s.key),!!s.money,false)));
        });drawSegment();
      });
    } else {
      var bars = style === 'cost' ? series.slice(0,2) : series;
      rows.forEach(function(d,i) {
        var bw=Math.min(36,Math.max(3,slot*.74/bars.length));
        bars.forEach(function(s,j) {
          if(!present(d,s.key)) return;
          var v=val(d,s.key), heightBar=v/max*plotH;
          var x=l+slot*(i+.5)+(j-bars.length/2)*bw;
          var rect=node('rect',{x:x,y:t+plotH-heightBar,width:Math.max(2,bw-3),height:Math.max(heightBar,0),rx:3,class:'wc-graph-rect '+color(j,s)});
          svg.appendChild(addTooltip(rect,periodLabel(d,s)+' · '+s.name+': '+nice(v,!!s.money,false)));
        });
      });
      if (style==='cost') {
        series.slice(2).forEach(function(s,offset){
          var j=offset+2,segment=[];
          function flush(){
            if(segment.length>1) svg.appendChild(node('polyline',{points:segment.map(function(p){return p.join(',');}).join(' '),fill:'none',class:'wc-graph-path '+color(j,s)}));
            segment=[];
          }
          rows.forEach(function(d,i){
            if(!present(d,s.key)){flush();return;}
            var point=[l+slot*(i+.5),t+plotH-val(d,s.key)/max*plotH];
            segment.push(point);
            var dot=node('circle',{cx:point[0],cy:point[1],r:width<420?3:4.5,class:'wc-graph-dot '+color(j,s)});
            svg.appendChild(addTooltip(dot,periodLabel(d,s)+' · '+s.name+': '+nice(val(d,s.key),!!s.money,false)));
          });flush();
        });
      }
    }
    if (!category) rows.forEach(function (d,i) {
      var jump = Math.max(1,Math.ceil(rows.length / Math.max(2,Math.floor(plotW/54))));
      if (i%jump!==0 && i!==rows.length-1) return;
      var match = /^(\d{4})-(\d{2})$/.exec(d.label);
      var tick = match ? ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][Number(match[2])-1] : d.label.slice(0,9);
      if (match && rows.length>12) tick += ' '+match[1].slice(2);
      svg.appendChild(node('text',{x:l+slot*(i+.5),y:height-b+24,'text-anchor':'middle',class:'wc-graph-axis'},tick));
    });
    root.replaceChildren(svg);
  }
  var observers = new WeakSet();
  var redrawQueue = new Set();
  var redrawFrame = 0;
  function queue(root) {
    redrawQueue.add(root);
    if(redrawFrame) return;
    redrawFrame=window.requestAnimationFrame(function(){
      redrawFrame=0;
      redrawQueue.forEach(function(el){ if(el.isConnected) plot(el); });
      redrawQueue.clear();
    });
  }
  function render() {
    document.querySelectorAll('.wc-graph-visual[data-config]').forEach(function(root){
      if(observers.has(root))return;
      observers.add(root);queue(root);
      if(typeof ResizeObserver!=='undefined'){
        var observer=new ResizeObserver(function(){queue(root);});observer.observe(root);
      }
    });
  }
  function init(){
    render();
    if(typeof MutationObserver!=='undefined'){
      var mutation=new MutationObserver(function(entries){
        if(entries.some(function(entry){return entry.addedNodes.length>0;}))render();
      });
      mutation.observe(document.body,{childList:true,subtree:true});
    }
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
