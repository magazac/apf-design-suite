(function(){
  function findArtboard(){
    return document.querySelector('.apf-artboard') ||
           document.querySelector('.woocommerce-product-gallery');
  }
  function applyPreset(artboard, preset){
    if (!artboard || !preset) return;
    artboard.style.setProperty('--apf-w', preset.w + '%');
    artboard.style.setProperty('--apf-t', preset.t + '%');
    artboard.style.setProperty('--apf-l', preset.l + '%');
  }
  function ensureOverlayImg(artboard){
    let img = artboard.querySelector('.apf-design');
    if (!img) {
      img = document.createElement('img');
      img.className = 'apf-design';
      img.alt = '';
      artboard.appendChild(img);
    }
    // normalize
    img.removeAttribute('width'); img.removeAttribute('height');
    img.style.width=''; img.style.height=''; img.style.transform='none';
    if(!img.classList.contains('apf-design')) img.classList.add('apf-design');
    return img;
  }
  function initUploader(container){
    const presets = (window.APFDU_DATA && APFDU_DATA.presets) ||
                    JSON.parse(container.getAttribute('data-apf-presets') || '{}');
    const defKey  = (window.APFDU_DATA && APFDU_DATA.default) ||
                    container.getAttribute('data-apf-default') || 'medium';
    const sel     = container.querySelector('.apf-size-preset');
    const artboard = findArtboard();

    // Varsayılan preset uygula
    applyPreset(artboard, presets[defKey] || presets.medium);

    // Preset değişince uygula
    if (sel) {
      sel.value = defKey;
      sel.addEventListener('change', function(){
        const key = sel.value;
        applyPreset(artboard, presets[key] || presets.medium);
      });
    }

    // Basit uploader: dosya seçilince overlay'e uygula
    const fileInput = container.querySelector('#apfdu-file');
    if (fileInput) {
      fileInput.addEventListener('change', function(e){
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(ev){
          const url = ev.target.result;
          const img = ensureOverlayImg(artboard);
          img.src = url;
          document.dispatchEvent(new CustomEvent('apf:uploaded', {detail:{src:url}}));
          const key = sel ? sel.value : defKey;
          applyPreset(artboard, presets[key] || presets.medium);
        };
        reader.readAsDataURL(file);
      });
    }

    // Dışarıdan .apf-design eklenirse preset'i yeniden uygula
    const mo = new MutationObserver(function(){
      const key = sel ? sel.value : defKey;
      applyPreset(artboard, presets[key] || presets.medium);
    });
    if (artboard) {
      mo.observe(artboard, {childList:true, subtree:true});
    }

    // Harici uploader/picker bir olayı tetiklerse
    document.addEventListener('apf:uploaded', function(){
      const key = sel ? sel.value : defKey;
      applyPreset(artboard, presets[key] || presets.medium);
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.apf-uploader').forEach(initUploader);
  });
})();