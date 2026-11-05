(function () {
  'use strict';
  const options = window.woodcrestOcr || {};
  const OCR_MAX_PAGES = 6;
  async function browserReadPdf(file, onProgress) {
    if (!window.pdfjsLib) return { method: 'manual', text: '', note: 'Local PDF reader is unavailable.' };
    const pdfjs = window.pdfjsLib;
    pdfjs.GlobalWorkerOptions.workerSrc = options.pdfWorkerUrl;
    const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
    const pageCount = Math.min(OCR_MAX_PAGES, pdf.numPages);
    const extractedPages = new Array(pageCount).fill('');
    const pagesForOCR = [];
    for (let pageNo = 1; pageNo <= pageCount; pageNo++) {
      onProgress('Reading PDF page ' + pageNo + ' of ' + pageCount + '…');
      const page = await pdf.getPage(pageNo);
      const layer = await page.getTextContent();
      const lines = [];
      let line = []; let lastY = null;
      (layer.items || []).forEach(item => {
        if (typeof item.str !== 'string') return;
        const y = item.transform && item.transform[5];
        if (lastY !== null && y !== undefined && Math.abs(y - lastY) > 5) {
          if (line.length) lines.push(line.join(' '));
          line = [];
        }
        line.push(item.str);
        if (y !== undefined) lastY = y;
        if (item.hasEOL) { if (line.length) lines.push(line.join(' ')); line=[]; lastY=null; }
      });
      if (line.length) lines.push(line.join(' '));
      const text = lines.join('\n').trim();
      extractedPages[pageNo-1] = text;

      if (text.length < 80) pagesForOCR.push({page,pageNo});
    }
    const selectedText = extractedPages.join('\n').trim();
    if (!pagesForOCR.length) {
      await pdf.destroy();
      return selectedText.length >= 25
        ? {method:'browser_text',text:selectedText,note:''}
        : {method:'manual',text:'',note:'No readable text detected.'};
    }
    if (!window.Tesseract) {
      await pdf.destroy();
      return selectedText.length >= 25
        ? {method:'browser_text',text:selectedText,note:''}
        : {method:'manual',text:'',note:'Local OCR library is unavailable.'};
    }
    let worker;
    try {
      onProgress('Loading local OCR engine…');
      worker = await window.Tesseract.createWorker('eng', window.Tesseract.OEM.LSTM_ONLY, {
        workerPath: options.ocrWorkerUrl,
        corePath: options.ocrCoreUrl,
        langPath: options.ocrLangUrl,
        workerBlobURL: false,
        logger: progress => {
          if (progress && progress.status === 'recognizing text')
            onProgress('Recognizing invoice text… ' + Math.round((progress.progress || 0) * 100) + '%');
        }
      });
      for (const entry of pagesForOCR) {
        onProgress('Recognizing page ' + entry.pageNo + ' of ' + pageCount + '…');
        const viewport = entry.page.getViewport({scale:2.6});
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        const context = canvas.getContext('2d', {willReadFrequently:true});
        await entry.page.render({canvasContext:context, viewport}).promise;
        const result = await worker.recognize(canvas);
        extractedPages[entry.pageNo-1] = [extractedPages[entry.pageNo-1], result.data && result.data.text || ''].filter(Boolean).join('\n');
        canvas.width = 0; canvas.height = 0;
      }
    } finally {
      if (worker) await worker.terminate();
      await pdf.destroy();
    }
    const resultText = extractedPages.join('\n').trim();
    return resultText.length >= 25 ? {method:'browser_ocr',text:resultText,note:''} : {method:'manual',text:'',note:'No readable text detected.'};
  }
  function refreshMeterCount(root) {
    const count = root.querySelectorAll('.wc-ocr-meter-row').length;
    const label = root.querySelector('.wc-ocr-meter-count');
    if (label) label.textContent = count ? count + ' recorded' : 'Optional';
  }
  function addMeter(target, values) {
    const v = values || {};
    const row = document.createElement('div');
    row.className = 'wc-ocr-meter-row';
    const head = document.createElement('div');
    head.className = 'wc-ocr-meter-row-head';
    const caption = document.createElement('strong');
    caption.textContent = 'Meter reading';
    const remove = document.createElement('button');
    remove.type = 'button'; remove.className = 'wc-ocr-remove-meter'; remove.textContent = 'Remove';
    remove.addEventListener('click', () => { row.remove(); refreshMeterCount(target.closest('[data-wc-ocr]')); });
    head.append(caption, remove); row.appendChild(head);
    const fields = document.createElement('div'); fields.className = 'wc-ocr-meter-fields';
    [ ['number','Meter number'], ['type','Type'], ['previous','Previous'], ['current','Current'], ['usage','Usage'] ].forEach(([key, label]) => {
      const slot = document.createElement('label');
      slot.textContent = label;
      const input = document.createElement(key === 'type' ? 'select' : 'input');
      if (key === 'type') {
        const normalized = (v[key] || '').toString().toUpperCase();
        [['WA','Water'],['IR','Irrigation (property meter)'],['EL','Electricity']].forEach(([val, label]) => {
          const option = document.createElement('option');
          option.value = val; option.textContent = label;
          input.appendChild(option);
        });
        input.value = normalized.startsWith('EL') || normalized.startsWith('ELEC') ? 'EL' : normalized === 'IR' || normalized.startsWith('IRR') ? 'IR' : 'WA';
      } else {
        input.type = 'text';
        input.value = (v[key] || '').toString();
      }
      input.dataset.meter = key;
      slot.appendChild(input);
      fields.appendChild(slot);
    });
    row.appendChild(fields);
    target.appendChild(row);
    refreshMeterCount(target.closest('[data-wc-ocr]'));
  }
  const CATEGORIES = [
    ['WATER','Water'], ['ELECTRICITY','Electricity'], ['SEWER','Sewer / Sewage'],
    ['IRRIGATION','Irrigation'], ['DRAINAGE','Drainage / Stormwater'],
    ['MAINTENANCE','Maintenance'], ['LANDSCAPING','Landscaping'],
    ['INSURANCE','Insurance'], ['PROPERTY_TAX','Property Tax'], ['OTHER','Other']
  ];
  const ALLOCATIONS = [
    ['TENANT_REFERENCE','Tenant billback reference'], ['CAM_REFERENCE','CAM reference'],
    ['OWNER_REFERENCE','Owner expense'], ['UNASSIGNED','Unassigned']
  ];
  function updateChargeCaptions(target) {
    const rows = Array.from(target.querySelectorAll('.wc-ocr-charge-row'));
    rows.forEach((row, index) => {
      const title = row.querySelector('.wc-ocr-charge-title');
      if (title) title.textContent = 'Charge ' + (index + 1);
      const year = row.querySelector('[data-charge="expense_year"]');
      const meter = row.querySelector('[data-charge="meter_number"]');
      const hint = row.querySelector('.wc-ocr-more-hint');
      if (hint) hint.textContent = [year && year.value, meter && meter.value ? 'Meter ' + meter.value : ''].filter(Boolean).join(' · ');
    });
    const count = target.closest('[data-wc-ocr]').querySelector('.wc-ocr-charge-count');
    if (count) count.textContent = rows.length ? '(' + rows.length + ')' : '';
  }
  function addCharge(target, data, defaultYear, changed) {
    const value = data || {};
    const row = document.createElement('div'); row.className = 'wc-ocr-charge-row';
    const head = document.createElement('div'); head.className = 'wc-ocr-charge-head';
    const title = document.createElement('strong'); title.className = 'wc-ocr-charge-title';
    const remove = document.createElement('button');
    remove.type = 'button'; remove.className = 'wc-ocr-remove-charge'; remove.textContent = 'Remove';
    remove.addEventListener('click', () => { row.remove(); changed(); });
    head.append(title, remove); row.appendChild(head);
    const main = document.createElement('div'); main.className = 'wc-ocr-charge-main';
    const more = document.createElement('details'); more.className = 'wc-ocr-charge-more';
    const summary = document.createElement('summary'); summary.textContent = 'Description, year & meter';
    const hint = document.createElement('span'); hint.className = 'wc-ocr-more-hint';
    summary.appendChild(hint); more.appendChild(summary);
    const extra = document.createElement('div'); extra.className = 'wc-ocr-charge-extra';
    const fields = [
      ['category','Charge type','select',CATEGORIES], ['amount','Amount ($)','number',null],
      ['allocation','Classification','select',ALLOCATIONS],
      ['description','Description','text',null], ['expense_year','Expense year','number',null],
      ['meter_number','Meter # (optional)','text',null]
    ];
    fields.forEach(([key,caption,kind,choices]) => {
      const label = document.createElement('label'); label.textContent = caption;
      label.className = 'wc-ocr-charge-field wc-ocr-charge-' + key;
      const input = document.createElement(kind === 'select' ? 'select' : 'input');
      if (kind === 'select') choices.forEach(([id,name]) => {
        const option = document.createElement('option'); option.value = id; option.textContent = name; input.appendChild(option);
      });
      else {
        input.type = kind;
        if (key === 'amount') { input.min = '0'; input.step = '0.01'; input.required = true; }
        if (key === 'expense_year') { input.min='2000'; input.max='2099'; input.step='1'; input.required = true; }
        if (key === 'description') input.required = true;
      }
      input.dataset.charge = key;
      input.value = value[key] != null && value[key] !== '' ? value[key]
        : key === 'category' ? 'OTHER' : key === 'allocation' ? 'UNASSIGNED'
        : key === 'expense_year' ? defaultYear : key === 'description' ? 'Other charge' : '';
      input.addEventListener('input', changed); input.addEventListener('change', changed);
      label.appendChild(input);
      (['category','amount','allocation'].includes(key) ? main : extra).appendChild(label);
    });
    row.append(main);
    more.appendChild(extra); row.appendChild(more);
    target.appendChild(row); changed();
  }
  function updateCharges(root) {
    const list = root.querySelector('.wc-ocr-charges');
    const total = Number(root.querySelector('[name="total"]').value || 0);
    const lines = Array.from(list.querySelectorAll('.wc-ocr-charge-row'));
    const sum = lines.reduce((value,row)=>value + Number(row.querySelector('[data-charge="amount"]').value || 0), 0);
    const node = root.querySelector('.wc-ocr-reconcile');
    updateChargeCaptions(list);
    if (!lines.length) {node.textContent = 'Add at least one charge.';node.classList.add('is-error');return;}
    const delta = Math.round((total-sum)*100)/100;
    node.classList.toggle('is-error', Math.abs(delta) > 0.01);
    node.textContent = 'Charges $' + sum.toFixed(2) + ' · Invoice $' + total.toFixed(2) +
      (Math.abs(delta) > 0.01 ? ' · Difference $' + delta.toFixed(2) + ' — review before saving.' : ' · Totals match');
  }
  function message(root, text, error) {
    const node = root.querySelector('.wc-ocr-status');
    node.hidden = false;
    node.textContent = text;
    node.classList.toggle('is-error', !!error);
  }
  async function send(action, params) {
    params.append('action', action);
    params.append('nonce', options.nonce || '');
    const response = await fetch(options.ajaxUrl, { method: 'POST', body: params, credentials: 'same-origin' });
    const json = await response.json();
    if (!json.success) throw new Error(json.data && json.data.message ? json.data.message : 'Request failed.');
    return json.data;
  }
  function createPdfViewer(root) {
    const details = root.querySelector('.wc-ocr-pdf-details');
    const canvas = root.querySelector('.wc-ocr-pdf-canvas');
    const viewport = root.querySelector('.wc-ocr-pdf-viewport');
    const status = root.querySelector('.wc-ocr-pdf-error');
    const openLink = root.querySelector('.wc-ocr-pdf-open');
    const pageLabel = root.querySelector('.wc-ocr-pdf-page');
    const zoomLabel = root.querySelector('.wc-ocr-pdf-zoom-label');
    const prev = root.querySelector('.wc-ocr-pdf-prev');
    const next = root.querySelector('.wc-ocr-pdf-next');
    const zoomIn = root.querySelector('.wc-ocr-pdf-zoom-in');
    const zoomOut = root.querySelector('.wc-ocr-pdf-zoom-out');
    const fit = root.querySelector('.wc-ocr-pdf-fit');
    let documentPdf = null;
    let pageNo = 1;
    let zoom = 1;
    let generation = 0;
    let queue = Promise.resolve();
    function labels() {
      pageLabel.textContent = documentPdf ? 'Page ' + pageNo + ' / ' + documentPdf.numPages : 'No PDF';
      zoomLabel.textContent = Math.round(zoom * 100) + '%';
      prev.disabled = !documentPdf || pageNo <= 1;
      next.disabled = !documentPdf || pageNo >= documentPdf.numPages;
      zoomIn.disabled = !documentPdf || zoom >= 3;
      zoomOut.disabled = !documentPdf || zoom <= 0.5;
      fit.disabled = !documentPdf;
    }
    async function draw() {
      const pdf = documentPdf;
      const token = generation;
      if (!pdf || !details.open || !viewport.clientWidth) return;
      try {
        const page = await pdf.getPage(pageNo);
        if (generation !== token || documentPdf !== pdf) return;
        const initial = page.getViewport({scale: 1});
        const fitWidth = Math.max(170, viewport.clientWidth - 32) / initial.width;
        const scaled = page.getViewport({scale: fitWidth * zoom});
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        const ctx = canvas.getContext('2d', {alpha:false});
        canvas.width = Math.max(1, Math.round(scaled.width * ratio));
        canvas.height = Math.max(1, Math.round(scaled.height * ratio));
        canvas.style.width = scaled.width + 'px';
        canvas.style.height = scaled.height + 'px';
        await page.render({canvasContext:ctx,viewport:scaled,transform:ratio===1 ? null : [ratio,0,0,ratio,0,0]}).promise;
        if (generation !== token) return;
        status.hidden = true;
      } catch(err) {
        if (generation === token) { status.hidden = false; status.textContent = 'Could not render this page. Use Open full PDF to view it.'; }
      }
    }
    function schedule() { queue = queue.catch(()=>{}).then(draw); }
    prev.addEventListener('click',()=>{if (pageNo>1) {pageNo--;labels();schedule();}});
    next.addEventListener('click',()=>{if (documentPdf && pageNo < documentPdf.numPages) {pageNo++;labels();schedule();}});
    zoomIn.addEventListener('click',()=>{zoom=Math.min(3,Math.round((zoom+0.25)*100)/100);labels();schedule();});
    zoomOut.addEventListener('click',()=>{zoom=Math.max(.5,Math.round((zoom-.25)*100)/100);labels();schedule();});
    fit.addEventListener('click',()=>{zoom=1;labels();schedule();});
    details.addEventListener('toggle',()=>{if (details.open) schedule();});
    window.addEventListener('resize',()=>{if (details.open) schedule();});
    async function load(file, url) {
      ++generation;
      const token = generation;
      const previous = documentPdf;
      documentPdf = null;
      canvas.width = canvas.height = 1;
      pageNo = 1; zoom = 1;
      openLink.hidden = !(file || url);
      openLink.href = url || '#';
      status.hidden = true;
      labels();
      if (previous) { try { await previous.destroy(); } catch (_) {} }
      if (!file && !url) return;
      if (!window.pdfjsLib) {
        status.hidden = false; status.textContent = 'PDF preview unavailable. Use Open full PDF instead.'; return;
      }
      try {
        const bytes = file ? await file.arrayBuffer() : await (async()=>{
          const response = await fetch(url,{credentials:'same-origin',cache:'no-store'});
          if (!response.ok) throw new Error('Unable to load saved PDF');
          return response.arrayBuffer();
        })();
        if (token !== generation) return;
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = options.pdfWorkerUrl;
        const loaded = await window.pdfjsLib.getDocument({data:new Uint8Array(bytes)}).promise;
        if (token !== generation) { await loaded.destroy();return; }
        documentPdf = loaded; labels(); schedule();
      } catch(err) {
        if (token !== generation) return;
        status.hidden = false; status.textContent = 'Preview unavailable: ' + (err.message || 'PDF could not be loaded') + '. You can still open the original PDF.';
      }
    }
    labels();
    return {load};
  }
  document.querySelectorAll('[data-wc-ocr]').forEach(root => {
    const upload = root.querySelector('.wc-ocr-upload-form');
    const review = root.querySelector('.wc-ocr-review');
    const edit = root.querySelector('.wc-ocr-review-form');
    const meters = root.querySelector('.wc-ocr-meters');
    const charges = root.querySelector('.wc-ocr-charges');
    const chargeChanged = () => { updateCharges(root); postingLoaded = false; };
    const posting = root.querySelector('.wc-v25-posting-section');
    const postingStatus = root.querySelector('.wc-v25-posting-status');
    const readingMatches = root.querySelector('.wc-v25-reading-matches');
    const costMatches = root.querySelector('.wc-v25-cost-matches');
    let postingLoaded = false;
    let matchingRequest = 0;
    function snapshotFields() {
      const data = {};
      Array.from(edit.querySelectorAll('[name]')).forEach(node => { data[node.name] = node.value; });
      data.meter_rows = Array.from(meters.querySelectorAll('.wc-ocr-meter-row')).map(row => {
        const m = {};
        row.querySelectorAll('[data-meter]').forEach(input => { m[input.dataset.meter] = input.value; });
        return m;
      });
      data.charge_rows = Array.from(charges.querySelectorAll('.wc-ocr-charge-row')).map(row => {
        const c = {};
        row.querySelectorAll('[data-charge]').forEach(input => { c[input.dataset.charge] = input.value; });
        return c;
      });
      return data;
    }
    function choiceSelect(items, selected, name, type) {
      const select = document.createElement('select');
      select.setAttribute('aria-label', name);
      const blank = document.createElement('option');blank.value = '0';blank.textContent = 'Do not post / unmatched';select.append(blank);
      items.filter(item => item.utility_type === type && item.status === 'ACTIVE').forEach(item => {
        const option = document.createElement('option');
        const isMeter = 'meter_id' in item;
        option.value = String(item[isMeter ? 'meter_id' : 'utility_account_id']);
        option.textContent = isMeter
          ? 'Meter ' + item.meter_number + ' (' + item.meter_type + ')'
          : item.utility_company + ' · ' + item.account_number;
        select.append(option);
      });
      select.value = String(selected || 0);
      return select;
    }
    async function refreshMatches() {
      const token = ++matchingRequest;
      postingLoaded = false;
      readingMatches.replaceChildren();costMatches.replaceChildren();
      if (editMode) {
        postingStatus.textContent = 'Previously posted utility records remain unchanged. Edit their values in Manage Utilities.';
        postingLoaded = true;return;
      }
      const fields = snapshotFields();
      const propertyId = upload.elements.namedItem('property_id').value;
      if (!propertyId || !fields.period_start || !fields.period_end) {
        postingStatus.textContent = 'Enter a service period, then refresh matches.';
        return;
      }
      postingStatus.textContent = 'Matching existing utility accounts and meters…';
      const params = new FormData();params.append('property_id',propertyId);params.append('fields',JSON.stringify(fields));
      try {
        const data = await send('woodcrest_v25_candidates',params);
        if (token !== matchingRequest) return;
        data.readings.forEach(item => {
          if (!item.type) return;
          const line = document.createElement('label');line.className='wc-v25-match-row';
          const label=document.createElement('span');label.textContent='Invoice meter ' + item.number + ' · ' + (item.type === 'ELECTRIC' ? 'Electricity' : 'Water');
          const select=choiceSelect(data.meters,item.meter_id,'Match meter '+item.number,item.type);
          select.dataset.wcReadingIndex=String(item.row_index);
          line.append(label,select);readingMatches.append(line);
        });
        data.costs.forEach(item => {
          const line=document.createElement('div');line.className='wc-v25-match-row wc-v25-cost-row';
          const label=document.createElement('strong');label.textContent=item.category+' charges · $'+item.amount;
          const select=choiceSelect(data.accounts,item.account_id,'Utility account for '+item.type,item.type);
          select.dataset.wcCostType=item.type;
          const rateLabel=document.createElement('label');rateLabel.textContent='Verified unit rate ($ per '+(item.type === 'WATER'?'gallon':'kWh')+')';
          const rate=document.createElement('input');rate.type='number';rate.min='0';rate.step='0.000001';rate.placeholder='Confirm rate';rate.value=item.unit_rate || '';
          rate.dataset.wcCostRate=item.type;
          rateLabel.append(rate);
          if (item.rate_source) { const hint=document.createElement('small');hint.textContent=item.rate_source;rateLabel.append(hint); }
          line.append(label,select,rateLabel);costMatches.append(line);
        });
        postingStatus.textContent = 'Matched entries will be posted on confirmation. Unmatched entries remain on the saved invoice only. Check account, period and rates before saving.';
        if ((data.warnings || []).length) postingStatus.textContent += ' Warning: ' + data.warnings.join(' ');
        if (!data.readings.length && !data.costs.length) postingStatus.textContent = 'No applicable meter readings or tenant-allocated water/electricity charges were detected. This invoice will be saved as an expense record.';
        postingLoaded=true;
      } catch(e) {
        if (token !== matchingRequest) return;
        postingStatus.textContent='Could not verify utility matches: '+(e.message||'unknown error');
      }
    }
    function postingChoices() {
      return {
        readings: Array.from(readingMatches.querySelectorAll('[data-wc-reading-index]')).map(select => ({row_index:Number(select.dataset.wcReadingIndex),meter_id:Number(select.value)})),
        costs: Array.from(costMatches.querySelectorAll('[data-wc-cost-type]')).map(select => {
          const type=select.dataset.wcCostType;
          const input=costMatches.querySelector('[data-wc-cost-rate="'+type+'"]');
          return {type,account_id:Number(select.value),unit_rate:input ? input.value : ''};
        })
      };
    }
    root.querySelector('.wc-v25-refresh-matches').addEventListener('click',refreshMatches);
    const markMatchesStale = event => {
      if (editMode || !event.target.closest('.wc-ocr-details-section,.wc-ocr-charges-section,.wc-ocr-meter-section')) return;
      postingLoaded = false;
      postingStatus.textContent = 'Invoice details changed. Refresh matches before confirming.';
    };
    edit.addEventListener('input',markMatchesStale);
    edit.addEventListener('change',markMatchesStale);
    edit.addEventListener('click',event => {
      if (!editMode && event.target.closest('.wc-ocr-add-meter,.wc-ocr-remove-meter,.wc-ocr-add-charge,.wc-ocr-remove-charge')) {
        postingLoaded = false;
        postingStatus.textContent = 'Meter or charge rows changed. Refresh matches before confirming.';
      }
    });
    const pdf = root.querySelector('input[type=file]');
    const viewer = createPdfViewer(root);
    let currentId = 0;
    let editMode = false;
    let invoiceRevision = '';
    let pdfUrl = '';
    root.querySelector('.wc-ocr-add-meter').addEventListener('click', () => addMeter(meters, {}));
    root.querySelector('.wc-ocr-add-charge').addEventListener('click', () => {
      const year = Number((edit.elements.namedItem('period_start').value || '').slice(0,4)) || new Date().getFullYear();
      addCharge(charges, {}, year, chargeChanged);
    });
    edit.addEventListener('invalid', event => {
      let parent = event.target.closest('details');
      while (parent) {
        parent.open = true;
        parent = parent.parentElement && parent.parentElement.closest('details');
      }
    }, true);
    edit.elements.namedItem('total').addEventListener('input', chargeChanged);
    function showReview(data, file, note, isEdit = false) {
      currentId = data.id;
      editMode = !!isEdit;
      invoiceRevision = data.revision || '';

      edit.reset();
      root.querySelector('.wc-ocr-start').hidden = isEdit;
      Array.from(edit.querySelectorAll('[name]')).forEach(input=>{ if (!['meter_rows','charge_rows'].includes(input.name)) input.value = ''; });
      Object.entries(data.fields || {}).forEach(([name,value]) => {
        const input = edit.elements.namedItem(name);
        if (input && !['meter_rows','charge_rows'].includes(name)) input.value = value == null ? '' : value;
      });
      root.querySelectorAll('.wc-ocr-optional').forEach(item => { item.open = false; });
      const pdfDetails = root.querySelector('.wc-ocr-pdf-details');
      if (pdfDetails) pdfDetails.open = (!!file || !!data.pdf_url) && window.matchMedia('(min-width: 1300px)').matches;
      meters.replaceChildren();
      (data.fields.meter_rows || []).forEach(m => addMeter(meters,m));
      charges.replaceChildren();
      const year = Number((data.fields.period_start || '').slice(0,4)) || new Date().getFullYear();
      const rows = data.fields.charge_rows || [];
      (rows.length ? rows : [{}]).forEach(c => addCharge(charges,c,year,chargeChanged));
      if (pdfUrl) URL.revokeObjectURL(pdfUrl);
      pdfUrl = file ? URL.createObjectURL(file) : '';
      const originalUrl = data.pdf_url || pdfUrl;
      const panel = root.querySelector('.wc-ocr-preview-panel');
      panel.hidden = !(file || originalUrl);
      root.querySelector('.wc-ocr-columns').classList.toggle('is-manual', !(file || originalUrl));
      const original = root.querySelector('.wc-ocr-pdf-open');
      original.href = originalUrl || '#';
      viewer.load(file, originalUrl);
      root.querySelector('.wc-ocr-review-title').textContent = isEdit ? 'Edit saved invoice #' + currentId : 'Review invoice';
      root.querySelector('.wc-ocr-step').textContent = isEdit ? 'Saved invoice' : 'Step 2 of 2';
      root.querySelector('.wc-ocr-save-button').textContent = isEdit ? 'Save Changes' : 'Save Invoice';
      root.querySelector('.wc-ocr-cancel-edit').hidden = !isEdit;
      root.querySelector('.wc-v25-refresh-matches').hidden = !!isEdit;
      review.hidden = false;
      refreshMatches();
      message(root,note || 'Check the details and charges before saving.',false);
      review.scrollIntoView({behavior:'smooth',block:'start'});
    }

    document.querySelectorAll('.wc-ocr-history-edit').forEach(button => {
      button.addEventListener('click',async()=>{
        button.disabled = true;
        message(root,'Loading invoice for review…',false);
        try {
          const request = new FormData();request.append('invoice_id',button.dataset.wcInvoiceId);
          const data = await send('woodcrest_ocr_load_invoice',request);
          upload.elements.namedItem('property_id').value = data.property_id;
          showReview(data,null,'Editing a saved invoice. Changes update the expense reference only.',true);
        } catch(err) { message(root,err.message,true); }
        finally {button.disabled=false;}
      });
    });
    root.querySelector('.wc-ocr-cancel-edit').addEventListener('click',()=>{
      review.hidden = true;currentId=0;editMode=false;invoiceRevision='';
      root.querySelector('.wc-ocr-start').hidden = false;
      viewer.load(null,'');
      if (pdfUrl) URL.revokeObjectURL(pdfUrl);
      pdfUrl='';
      message(root,'Editing cancelled; no changes were saved.',false);
    });
    root.querySelector('.wc-ocr-manual').addEventListener('click', async () => {
      const propertyId = upload.querySelector('[name="property_id"]').value;
      if (!propertyId) {message(root,'Choose a property first.',true);return;}
      const params = new FormData();params.append('property_id',propertyId);
      try {
        const data = await send('woodcrest_ocr_manual_draft',params);
        showReview(data,null,'Enter the invoice details and charge breakdown.');
      } catch(err) {message(root,err.message,true);}
    });
    upload.addEventListener('submit', async e => {
      e.preventDefault();
      if (!upload.reportValidity()) return;
      const file = pdf.files[0];
      if (!file || file.size > 6 * 1024 * 1024) { message(root, 'Select a PDF under 6 MB.', true); return; }
      const submit = upload.querySelector('button[type=submit]');
      submit.disabled = true;
      message(root, 'Reading PDF…', false);
      try {
        let recognition;
        try { recognition = await browserReadPdf(file, txt => message(root, txt, false)); }
        catch (recognitionError) {
          console.warn('Woodcrest local PDF extraction:', recognitionError);
          recognition = { method:'manual', text:'', note:'Automatic recognition was unsuccessful.' };
        }
        const request = new FormData(upload);
        request.append('ocr_method', recognition.method);
        request.append('ocr_text', recognition.text);
        message(root, 'Preparing review form…', false);
        const data = await send('woodcrest_ocr_extract', request);
        showReview(data,file,data.ocr_method === 'MANUAL'
          ? (recognition.note || 'Enter the invoice details manually.')
          : 'Fields populated. Verify each charge, allocation, and year before saving.');
      } catch (err) { message(root, err.message, true); }
      finally { submit.disabled = false; }
    });
    edit.addEventListener('submit', async e => {
      e.preventDefault();
      if (!currentId) return;
      const firstInvalid = Array.from(edit.querySelectorAll('input,select,textarea')).find(input => !input.checkValidity());
      if (firstInvalid) {
        let parent = firstInvalid.closest('details');
        while (parent) {
          parent.open = true;
          parent = parent.parentElement && parent.parentElement.closest('details');
        }
        edit.reportValidity();
        return;
      }
      const fields = {};
      Array.from(edit.querySelectorAll('[name]')).forEach(input => { fields[input.name] = input.value; });
      fields.charge_rows = Array.from(charges.querySelectorAll('.wc-ocr-charge-row')).map(row => {
        const c = {};
        row.querySelectorAll('[data-charge]').forEach(input => { c[input.dataset.charge] = input.value; });
        return c;
      });
      fields.meter_rows = Array.from(meters.querySelectorAll('.wc-ocr-meter-row')).map(row => {
        const m = {};
        row.querySelectorAll('[data-meter]').forEach(input => { m[input.dataset.meter] = input.value; });
        return m;
      });
      if (!fields.provider || !fields.bill_number || !fields.period_start || !fields.period_end || !fields.total) {
        message(root, 'Complete provider, bill number, service period, and total.', true); return;
      }
      updateCharges(root);
      const subtotal = fields.charge_rows.reduce((acc,c)=>acc+Math.round(Number(c.amount||0)*100),0);
      if (!fields.charge_rows.length || Math.abs(subtotal - Math.round(Number(fields.total)*100)) > 1) {
        message(root,'Charge rows must add up to the invoice total before saving.',true);return;
      }
      if (!editMode && !postingLoaded) {
        message(root,'Refresh and verify utility matches before confirming the invoice.',true);return;
      }
      const submit = edit.querySelector('[type=submit]'); submit.disabled = true;
      const form = new FormData();
      form.append(editMode ? 'invoice_id' : 'draft_id', String(currentId));
      form.append('fields', JSON.stringify(fields));
      if (editMode) form.append('revision',invoiceRevision);
      else form.append('post_choices',JSON.stringify(postingChoices()));
      try {
        const data = await send(editMode ? 'woodcrest_ocr_update_invoice' : 'woodcrest_ocr_confirm', form);

        const url = new URL(window.location.href);
        if (data.posted) {
          url.searchParams.set('wc_post_readings',String(data.posted.readings||0));
          url.searchParams.set('wc_post_costs',String(data.posted.costs||0));
        }
        url.searchParams.set('wc_import_saved',editMode ? 'updated' : 'new');
        const propertyId = upload.elements.namedItem('property_id').value;
        if (propertyId) url.searchParams.set('wc_ocr_property',propertyId);
        window.location.assign(url.toString());
      } catch (err) { message(root, err.message, true); }
      finally { submit.disabled = false; }
    });
  });
})();
