(function(){ try { if (typeof window!=='undefined' && typeof window.colSel==='undefined') window.colSel=null; } catch(e){} })();
(function(){
  const root = document.querySelector('.apf-design-picker');
  if(!root) return;

  const cfg = (window.APF_DESIGN_PICKER && APF_DESIGN_PICKER.settings) || {};
  const targetSelectorAttr = root.getAttribute('data-target') || '';
  const nameInput = root.querySelector('.adp-name');
  const idInput   = root.querySelector('.adp-id');
  const nameHidden= root.querySelector('.adp-name-hidden');
  const sugg      = root.querySelector('.adp-suggestions');
  const gallery   = root.querySelector('.adp-collection-gallery');
  const applyBtn = null;
  let overlay   = root.querySelector('.adp-overlay');
  // --- APF v5: hard-bind internal select and ensure name input enabled
  const internalSel = root.querySelector('#apf-collection') || root.querySelector('.adp-collection');
  function readPrefixFromSelect(sel){
    if (!sel) return '';
    const opt = sel.options && (sel.options[sel.selectedIndex] || sel.options[0]);
    const txt = (opt && (opt.text || opt.innerText || '')).trim();
    let m = txt && txt.match(/\(([A-Za-z]{3})\)/);
    if (m) return m[1];
    const val = (opt && (opt.value || '')).trim();
    return val ? val.slice(0,3).toUpperCase() : '';
  }
  function applyPrefixFromSelect(sel){
    if (!nameInput || !sel) return;
    const pref = readPrefixFromSelect(sel);
    if (!pref) return;
    try { nameInput.removeAttribute('disabled'); nameInput.removeAttribute('readonly'); } catch(e){}
    const current = nameInput.value || '';
    if (current === '' || current.toUpperCase().startsWith(pref.toUpperCase())) {
      nameInput.value = pref;
      try { nameInput.dispatchEvent(new Event('input', {bubbles:true})); } catch(e){}
    }
  }
  if (internalSel) {
    internalSel.addEventListener('change', ()=>applyPrefixFromSelect(internalSel));
    applyPrefixFromSelect(internalSel);
  }
  if (typeof jQuery !== 'undefined' && internalSel && jQuery.fn && jQuery.fn.select2) {
    try { jQuery(internalSel).on('select2:select', ()=>applyPrefixFromSelect(internalSel)); } catch(e){}
  }
  // --- /APF v5 ---


  // State: selected full title (for order meta), and last prefix for refocus search
  let selectedTitle = null;
  let lastPrefix = '';
  function getPrefix(str){
    if(!str) return '';
    const m = String(str).match(/^[A-Za-z]+/);
    return m ? m[0] : '';
  }
  function cleanTitle(raw){
    if(!raw) return '';
    const tmp = document.createElement('div');
    tmp.innerHTML = raw;
    const txt = tmp.textContent || tmp.innerText || '';
    return txt.trim();
  }


  const inline = {
    width: root.getAttribute('data-width') || '',
    top: root.getAttribute('data-top') || '',
    left: root.getAttribute('data-left') || '',
    clip: root.getAttribute('data-clip') || ''
  };

  const catStr = root.getAttribute('data-cats') || '';
  const catSlugs = catStr ? catStr.split(',').filter(Boolean) : [];

  const targetSelector = targetSelectorAttr || cfg.target || '.woocommerce-product-gallery';
  const targetEl = document.querySelector(targetSelector);

  if (targetEl) {
    const cs = window.getComputedStyle(targetEl);
    if (cs.position === 'static') targetEl.style.position = 'relative';
    overlay.remove();
    targetEl.appendChild(overlay);
    overlay.style.position = 'absolute';
    overlay.style.left = 0;
    overlay.style.top = 0;
    overlay.style.width = '100%';
    overlay.style.height = 'auto';
    overlay.style.pointerEvents = 'none';
    overlay.style.zIndex = 10;
    overlay.style.display = 'none';
  }

  
  function clearGallery(){
    if (!gallery) return;
    gallery.innerHTML = '';
    gallery.hidden = true;
  }
  function renderGallery(items){
    if (!gallery) return;
    gallery.innerHTML = '';
    if(!items || !items.length){ gallery.hidden = true; return; }
    const wrap = document.createElement('div');
    wrap.className = 'adp-gwrap';
    items.forEach(it=>{
      const sizes = it.media_details && it.media_details.sizes;
      const thumb = sizes && (sizes.medium_large || sizes.large || sizes.medium || sizes.thumbnail)
        ? ( (sizes.medium_large?.source_url) || (sizes.large?.source_url) || (sizes.medium?.source_url) || (sizes.thumbnail?.source_url) )
        : it.source_url;
      const big = sizes && (sizes.large || sizes.medium_large) ? ((sizes.large?.source_url) || (sizes.medium_large?.source_url)) : it.source_url;

      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'adp-gitem';
      card.innerHTML = `<img src="${thumb}" alt=""><span>${(it.title && it.title.rendered) ? it.title.rendered : ''}</span>`;
      card.addEventListener('click', ()=>{
        idInput.value = it.id;
        const full = cleanTitle(it.title && it.title.rendered);
        selectedTitle = full || (nameInput.value.trim());
        nameHidden.value = selectedTitle;
        nameInput.value  = selectedTitle; // show full code after pick
        overlay.src = big;
        overlay.style.display = 'block';
        
        // YENİ: CSS değişkenlerini güncelle
        applyPositioning();
      });
      wrap.appendChild(card);
    });
    gallery.appendChild(wrap);
    gallery.hidden = false;
  }

function applyPositioning() {
    let W = cfg.width || '100';
    let T = cfg.top   || '0';
    let L = cfg.left  || '0';
    let C = cfg.clip  || 'inset(0% 0% 0% 0%)';

    try {
      const arr = JSON.parse(cfg.overrides_json || '[]');
      if (Array.isArray(arr) && catSlugs.length) {
        for (const o of arr) {
          if (o && o.taxonomy === 'product_cat' && catSlugs.includes(String(o.term))) {
            W = o.width ?? W;
            T = o.top   ?? T;
            L = o.left  ?? L;
            C = o.clip  ?? C;
            break;
          }
        }
      }
    } catch(e){}

    if (inline.width) W = inline.width;
    if (inline.top)   T = inline.top;
    if (inline.left)  L = inline.left;
    if (inline.clip)  C = inline.clip;

    if (overlay) {
      // CSS değişkenleri kullanarak responsive konumlandırma
      overlay.style.setProperty('--apf-w', (Number(W)||0) + '%');
      overlay.style.setProperty('--apf-t', (Number(T)||0) + '%');
      overlay.style.setProperty('--apf-l', (Number(L)||0) + '%');
      
      overlay.style.width = 'var(--apf-w)';
      overlay.style.top = 'var(--apf-t)';
      overlay.style.left = 'var(--apf-l)';
      overlay.style.clipPath = C;
      overlay.style.objectFit = 'contain';
      overlay.style.maxWidth = 'none';
      
      // Eğer artboard içindeyse, artboard'a da CSS değişkenlerini uygula
      const artboard = document.querySelector('.apf-artboard');
      if (artboard && artboard.contains(overlay)) {
        artboard.style.setProperty('--apf-w', (Number(W)||0) + '%');
        artboard.style.setProperty('--apf-t', (Number(T)||0) + '%');
        artboard.style.setProperty('--apf-l', (Number(L)||0) + '%');
      }
    }
  }
  applyPositioning();

  let t, lastQ='';

  function clearSelection(){
    idInput.value = '';
    if (overlay) { overlay.removeAttribute('src'); overlay.style.display = 'none'; }
  }

  async function searchMediaAll(q){
    const perPage = Math.min(Number(cfg.per_page)||50, 100);
    const cap = Math.min(Number(cfg.max_items)||200, 1000);
    let page = 1;
    let all = [];
    while (all.length < cap) {
      const url = `${APF_DESIGN_PICKER.restUrl}?per_page=${perPage}&page=${page}&media_type=image&search=${encodeURIComponent(q)}`;
      const res = await fetch(url, { headers: { 'X-WP-Nonce': APF_DESIGN_PICKER.nonce } });
      if(!res.ok) break;
      const arr = await res.json();
      all = all.concat(arr);
      const totalPages = parseInt(res.headers.get('X-WP-TotalPages') || '1', 10);
      if (page >= totalPages) break;
      page++;
    }
    if (all.length > cap) all = all.slice(0, cap);
    return all;
  }

  function renderSuggestions(items){
    sugg.innerHTML='';
    if(!items.length){ sugg.hidden = true; return; }
    items.forEach(it=>{
      const li = document.createElement('li');
      li.className = 'adp-sugg-item';
      const sizes = it.media_details && it.media_details.sizes;
      const thumb = sizes && (sizes.medium_large || sizes.large || sizes.medium || sizes.thumbnail)
        ? ( (sizes.medium_large?.source_url) || (sizes.large?.source_url) || (sizes.medium?.source_url) || (sizes.thumbnail?.source_url) )
        : it.source_url;
      li.innerHTML = `<button type="button" class="adp-pick">
        <img src="${thumb}" alt="" /> <span>${it.title.rendered || 'Görsel'}</span>
      </button>`;
      li.querySelector('button').addEventListener('click', ()=>{
        idInput.value   = it.id;
        const full = cleanTitle(it.title && it.title.rendered);
        selectedTitle = full || nameInput.value.trim();
        nameHidden.value = selectedTitle; // order meta keeps FULL code
        // visible input shows FULL right after pick
        if (full) { nameInput.value = full; }
        // remember prefix for later refocus browsing
        lastPrefix = getPrefix(selectedTitle) || getPrefix(nameInput.value);
        const big = sizes && (sizes.large || sizes.medium_large) ? ((sizes.large?.source_url) || (sizes.medium_large?.source_url)) : it.source_url;
        overlay.src = big;
        overlay.style.display = 'block';
        sugg.hidden = true;
        sugg.innerHTML = '';
        
        // YENİ: CSS değişkenlerini güncelle
        applyPositioning();
      });
      sugg.appendChild(li);
    });
    sugg.hidden = false;
  }

  nameInput.addEventListener('input', ()=>{
    const q = nameInput.value.trim();
    if(!selectedTitle){ nameHidden.value = q; }
    clearTimeout(t);
    t = setTimeout(async ()=>{
      const min = Number(cfg.min_chars)||3;
      if(q.length < min){ sugg.hidden=true; sugg.innerHTML=''; clearSelection(); lastQ=''; return; }
      if(q === lastQ) return;
      lastQ = q;
      const items = await searchMediaAll(q);
      renderSuggestions(items);
      if(items[0]){
        const sizes = items[0].media_details && (items[0].media_details.sizes);
        const big = sizes && (sizes.large || sizes.medium_large) ? ((sizes.large?.source_url) || (sizes.medium_large?.source_url)) : items[0].source_url;
        overlay.src = big;
        overlay.style.display = 'block';
        
        // YENİ: CSS değişkenlerini güncelle
        applyPositioning();
      } else {
        clearSelection();
      }
    }, 250);
  });

  
  nameInput.addEventListener('focus', async ()=>{
    if (selectedTitle){ const pref = lastPrefix || getPrefix(selectedTitle); if (pref){ nameInput.value = pref; }}
    const min = Number(cfg.min_chars)||3;
    if (selectedTitle) {
      // Put back the prefix so user can browse siblings
      const prefix = lastPrefix || getPrefix(selectedTitle);
      if (prefix && nameInput.value !== prefix) {
        nameInput.value = prefix;
      }
    }
    const q = nameInput.value.trim();
    if(q.length >= min){
      lastQ = ''; // force reload
      const items = await searchMediaAll(q);
      renderSuggestions(items);
    }
  });

  
  // Continuous sync loop: if a collection is chosen and user hasn't typed a longer value,
  // ensure the input shows the prefix. Avoids fighting user input.
  if (colSel && nameInput) {
    setInterval(()=>{
      const pref = colSel.value || '';
      if (!pref) return;
      const val = nameInput.value || '';
      // If user hasn't typed beyond prefix || field is empty, keep it in sync
      const shouldSync = (val === '' || val.toUpperCase() === pref.toUpperCase() || val.length < pref.length);
      if (shouldSync) {
        nameInput.value = pref;
      }
    }, 300);
  }

  
  document.addEventListener('click', (e)=>{
    if(!root.contains(e.target)){ sugg.hidden = true; }
  });

})();

/* APF 2.1.2 — Prefix sync + event burst */
(function(){
  function fireAll(el, lastCh){
    var code = (lastCh||'A').toUpperCase().charCodeAt(0);
    var opts   = {bubbles:true, cancelable:true};
    var keyOpt = Object.assign({key:lastCh||'A', code:'Key'+(lastCh||'A').toUpperCase(), which:code, keyCode:code, charCode:code}, opts);
    try{ el.dispatchEvent(new Event('input', opts)); }catch(e){}
    try{ el.dispatchEvent(new KeyboardEvent('keydown', keyOpt)); }catch(e){}
    try{ el.dispatchEvent(new KeyboardEvent('keypress', keyOpt)); }catch(e){}
    try{ el.dispatchEvent(new KeyboardEvent('keyup', keyOpt)); }catch(e){}
    try{ el.dispatchEvent(new Event('change', opts)); }catch(e){}
  }
  function wire(root){
    var sel  = root.querySelector('#apf-collection') || root.querySelector('.adp-collection');
    var name = root.querySelector('.adp-name');
    if(!sel || !name) return;
    try { window.colSel = sel; } catch(e){}

    function readPref(){
      var opt = sel.options && (sel.options[sel.selectedIndex] || sel.options[0]);
      var txt = (opt && (opt.text || opt.innerText || '')).trim();
      var m = txt && txt.match(/\(([A-Za-z]{3})\)/);
      if (m) return m[1];
      var val = (opt && (opt.value || '')).trim();
      return val ? val.slice(0,3).toUpperCase() : '';
    }
    function apply(){
      var pref = readPref();
      if (!pref) return;
      name.removeAttribute('disabled'); name.removeAttribute('readonly');
      name.value = pref;
      fireAll(name, pref.slice(-1));
    }
    sel.addEventListener('change', apply);
    if (typeof jQuery!=='undefined' && jQuery.fn && jQuery.fn.select2){
      try { jQuery(sel).on('select2:select', apply); } catch(e){}
    }
    setTimeout(apply,80); setTimeout(apply,400);
  }
  function init(){ var root=document.querySelector('.apf-design-picker'); if(root) wire(root); }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', init);} else {init();}
  try{ new MutationObserver(init).observe(document.documentElement,{childList:true,subtree:true}); }catch(e){}
})();


/* APF 2.1.2 — Artboard percent positioning; prefer .apf-artboard */
(function(){
  function apply(board){
    var base = 900;
    var img  = board.querySelector('.apf-design');
    if(!img) return;
    
    // CSS değişkenleri varsa onları kullan, yoksa data attribute'ları kullan
    var w = parseFloat(board.style.getPropertyValue('--apf-w') || img.getAttribute('data-width') || img.dataset.width || '0');
    var t = parseFloat(board.style.getPropertyValue('--apf-t') || img.getAttribute('data-top') || img.dataset.top || '0');
    var l = parseFloat(board.style.getPropertyValue('--apf-l') || img.getAttribute('data-left') || img.dataset.left || '0');
    
    if(!(w>0)) return;
    
    // CSS değişkenlerini kullanarak responsive konumlandırma
    board.style.setProperty('--apf-w', (w/base*100)+'%');
    board.style.setProperty('--apf-t', (t/base*100)+'%');
    board.style.setProperty('--apf-l', (l/base*100)+'%');
    
    img.style.width = 'var(--apf-w)';
    img.style.top = 'var(--apf-t)';
    img.style.left = 'var(--apf-l)';
    img.style.height = 'auto';
    img.style.transform = 'none';
    img.style.maxWidth = 'none';
  }
  
  function init(){ 
    document.querySelectorAll('.apf-artboard').forEach(apply); 
    
    // Ayrıca window resize olduğunda da uygula
    window.addEventListener('resize', function(){
      document.querySelectorAll('.apf-artboard').forEach(apply);
    });
  }
  
  if(document.readyState==='loading'){ 
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  
  try{ 
    new MutationObserver(function(mutations){
      mutations.forEach(function(mutation){
        mutation.addedNodes.forEach(function(node){
          if (node.nodeType === 1 && node.classList.contains('apf-artboard')) {
            apply(node);
          }
        });
      });
    }).observe(document.documentElement, {childList: true, subtree: true}); 
  } catch(e){}
})();