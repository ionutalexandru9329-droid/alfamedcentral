(() => {
  const routerUrl = document.body?.dataset.routerUrl || 'index.php';

  function appUrl(path, params = {}) {
    const url = new URL(routerUrl, window.location.href);
    url.search = '';
    const route = '/' + String(path || '').replace(/^\/+/, '');
    if (route !== '/') url.searchParams.set('route', route);
    Object.entries(params).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach(v => url.searchParams.append(key, String(v)));
      else if (value !== undefined && value !== null) url.searchParams.set(key, String(value));
    });
    return url.pathname + url.search;
  }

  const esc = s => String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));


  // Responsive application header (tablet + mobile).
  const headerMenuToggle = document.getElementById('headerMenuToggle');
  const headerCollapse = document.getElementById('headerCollapse');
  const setHeaderMenu = open => {
    if (!headerMenuToggle || !headerCollapse) return;
    headerCollapse.classList.toggle('open', Boolean(open));
    headerMenuToggle.classList.toggle('open', Boolean(open));
    headerMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    headerMenuToggle.setAttribute('aria-label', open ? 'Inchide meniul' : 'Deschide meniul');
  };
  headerMenuToggle?.addEventListener('click', () => setHeaderMenu(!headerCollapse?.classList.contains('open')));
  headerCollapse?.querySelectorAll('.app-nav a').forEach(link => link.addEventListener('click', () => {
    if (window.matchMedia('(max-width: 1050px)').matches) setHeaderMenu(false);
  }));
  window.addEventListener('resize', () => { if (window.innerWidth > 1050) setHeaderMenu(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setHeaderMenu(false); });
  document.addEventListener('click', e => {
    if (!window.matchMedia('(max-width: 1050px)').matches || !headerCollapse?.classList.contains('open')) return;
    if (e.target.closest('#headerCollapse') || e.target.closest('#headerMenuToggle')) return;
    setHeaderMenu(false);
  });

  // Make horizontally scrollable data tables keyboard/touch friendly on small screens.
  document.querySelectorAll('.table-wrap').forEach(wrap => {
    if (!wrap.hasAttribute('tabindex')) wrap.tabIndex = 0;
    if (!wrap.hasAttribute('aria-label')) wrap.setAttribute('aria-label', 'Tabel derulabil pe orizontala');
  });

  // Global product image preview modal.
  const imageModal = document.getElementById('imagePreviewModal');
  const imageFull = document.getElementById('imagePreviewFull');
  const imageEmpty = document.getElementById('imagePreviewEmpty');
  const imageCaption = document.getElementById('imagePreviewCaption');
  const imageTools = document.getElementById('imagePreviewTools');
  const imageStatus = document.getElementById('imagePreviewStatus');
  const imageResync = document.getElementById('imagePreviewResync');
  const imageUpload = document.getElementById('imagePreviewUpload');
  const imageDropzone = document.getElementById('imagePreviewDropzone');
  const setPreviewImage = (src, caption = '') => {
    src = String(src || '');
    if (imageFull) {
      imageFull.src = src;
      imageFull.alt = src ? 'Imagine produs' : '';
      imageFull.hidden = !src;
    }
    if (imageEmpty) imageEmpty.hidden = !!src;
    if (imageCaption) imageCaption.textContent = caption || '';
  };
  const closeImage = () => {
    if (!imageModal) return;
    imageModal.hidden = true;
    imageModal.setAttribute('aria-hidden', 'true');
    imageModal.dataset.orderId = '';
    imageModal.dataset.orderItemId = '';
    document.body.classList.remove('body-modal-open');
    setPreviewImage('', '');
    if (imageTools) imageTools.hidden = true;
    if (imageStatus) imageStatus.textContent = '';
    if (imageUpload) imageUpload.value = '';
  };
  document.addEventListener('click', e => {
    const trigger = e.target.closest('.js-image-preview');
    if (trigger && imageModal && imageFull) {
      const src = trigger.dataset.image || '';
      const hasOrderContext = Number(trigger.dataset.orderId || 0) > 0 && Number(trigger.dataset.orderItemId || 0) > 0;
      if (!src && !hasOrderContext) return;
      setPreviewImage(src, trigger.dataset.caption || '');
      imageModal.dataset.orderId = trigger.dataset.orderId || '';
      imageModal.dataset.orderItemId = trigger.dataset.orderItemId || '';
      if (imageTools) imageTools.hidden = trigger.dataset.orderImageTools !== '1';
      if (imageStatus) imageStatus.textContent = '';
      imageModal.hidden = false;
      imageModal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('body-modal-open');
      return;
    }
    if (e.target.closest('.image-modal-close') || e.target.classList.contains('image-modal-backdrop')) closeImage();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImage(); });
  imageFull?.addEventListener('load', () => {
    if (!imageFull.src) return;
    imageFull.hidden = false;
    if (imageEmpty) imageEmpty.hidden = true;
  });
  imageFull?.addEventListener('error', () => {
    imageFull.hidden = true;
    if (imageEmpty) imageEmpty.hidden = false;
  });


  // AWB scanner module: manual/USB input plus camera QR/barcode detection.
  const scanPage = document.querySelector('[data-scan-page]');
  const input = document.getElementById('scanInput');
  const finePointerTextFocus = () => window.matchMedia('(pointer:fine)').matches && !window.matchMedia('(max-width:900px)').matches;
  const out = document.getElementById('scanResult');
  const manualButton = document.getElementById('scanManualButton');
  const scanVideo = document.getElementById('scanVideo');
  const cameraStart = document.getElementById('scanCameraStart');
  const cameraStop = document.getElementById('scanCameraStop');
  const cameraStatus = document.getElementById('scanCameraStatus');
  const cameraPlaceholder = document.getElementById('scanVideoPlaceholder');
  let scanStream = null;
  let detector = null;
  let scanFrame = 0;
  let scanBusy = false;
  let lastCameraValue = '';
  let lastCameraAt = 0;

  const setCameraStatus = (message, state = '') => {
    if (!cameraStatus) return;
    cameraStatus.textContent = message;
    cameraStatus.dataset.state = state;
  };

  const lookupScan = async (raw, source = 'manual') => {
    const q = String(raw || '').trim();
    if (!q || scanBusy) return false;
    scanBusy = true;
    if (out) out.innerHTML = '<div class="scan-working">Caut comanda...</div>';
    try {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 5000);
      let r;
      try {
        r = await fetch(appUrl('/api/scan', {q}), {headers:{'Accept':'application/json'}, cache:'no-store', signal:controller.signal});
      } finally {
        clearTimeout(timer);
      }
      const d = await r.json();
      if (!d.ok) {
        if (out) out.innerHTML = `<div class="scan-bad">${esc(d.message || 'Comanda nu a fost gasita.')}</div>`;
        if (source === 'camera') setCameraStatus('Cod detectat, dar nu exista o comanda asociata.', 'error');
        return false;
      }
      if (source === 'camera') setCameraStatus(`Comanda ${d.order?.code || ''} identificata. Se deschide...`, 'success');
      if (d.redirect) {
        if (out) out.innerHTML = `<div class="scan-ok">Comanda ${esc(d.order?.code || '')} identificata. Se deschide...</div>`;
        window.location.assign(d.redirect);
        return true;
      }
      const fallback = d.order?.id ? appUrl(`/orders/${d.order.id}`) : '';
      if (fallback) {
        window.location.assign(fallback);
        return true;
      }
      if (out) out.innerHTML = '<div class="scan-bad">Comanda a fost identificata, dar nu poate fi deschisa.</div>';
      return false;
    } catch (err) {
      const timedOut = err?.name === 'AbortError';
      if (out) out.innerHTML = `<div class="scan-bad">${timedOut ? 'Cautarea eMAG a depasit 15 secunde. Nu mai blocam scannerul; reincearca dupa ce mirror-ul AWB este actualizat.' : 'Eroare de comunicare.'}</div>`;
      if (source === 'camera') setCameraStatus(timedOut ? 'Cautarea AWB a expirat; scannerul este din nou disponibil.' : 'Eroare de comunicare cu platforma.', 'error');
      return false;
    } finally {
      scanBusy = false;
      if (source === 'manual' && finePointerTextFocus()) input?.select();
    }
  };

  if (input && out) {
    // Desktop keeps the fast USB-scanner workflow. Touch devices never receive
    // programmatic focus, so their software keyboard stays closed until a real tap.
    if (finePointerTextFocus()) {
      requestAnimationFrame(() => { try { input.focus({preventScroll:true}); } catch (_) { input.focus(); } });
    }
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        e.preventDefault();
        lookupScan(input.value, 'manual');
      }
    });
    manualButton?.addEventListener('click', () => lookupScan(input.value, 'manual'));
  }

  const stopCameraScanner = () => {
    if (scanFrame) cancelAnimationFrame(scanFrame);
    scanFrame = 0;
    if (scanStream) scanStream.getTracks().forEach(track => track.stop());
    scanStream = null;
    if (scanVideo) { scanVideo.pause(); scanVideo.srcObject = null; }
    if (cameraStart) cameraStart.hidden = false;
    if (cameraStop) cameraStop.hidden = true;
    if (cameraPlaceholder) cameraPlaceholder.hidden = false;
    setCameraStatus('Camera este oprita.');
  };

  const detectCameraFrame = async () => {
    if (!scanVideo || !detector || !scanStream) return;
    try {
      if (scanVideo.readyState >= 2 && !scanBusy) {
        const codes = await detector.detect(scanVideo);
        const hit = codes?.find(c => String(c.rawValue || '').trim() !== '');
        if (hit) {
          const value = String(hit.rawValue || '').trim();
          const now = Date.now();
          if (value !== lastCameraValue || now - lastCameraAt > 2500) {
            lastCameraValue = value;
            lastCameraAt = now;
            setCameraStatus(`Cod detectat: ${value.slice(0, 80)}`, 'working');
            const found = await lookupScan(value, 'camera');
          }
        }
      }
    } catch (_) {}
    if (scanStream) scanFrame = requestAnimationFrame(detectCameraFrame);
  };

  const startCameraScanner = async () => {
    if (!scanPage || scanPage.dataset.cameraEnabled === '0') return;
    if (!navigator.mediaDevices?.getUserMedia) {
      setCameraStatus('Browserul nu permite accesul la camera.', 'error');
      return;
    }
    if (!('BarcodeDetector' in window)) {
      setCameraStatus('Browserul nu ofera detector QR/cod de bare. Foloseste Chrome actualizat sau scannerul USB.', 'error');
      return;
    }
    try {
      let formats = ['qr_code','code_128','code_39','ean_13','ean_8','itf','codabar','upc_a','upc_e'];
      if (typeof BarcodeDetector.getSupportedFormats === 'function') {
        const supported = await BarcodeDetector.getSupportedFormats();
        formats = formats.filter(f => supported.includes(f));
        detector = formats.length ? new BarcodeDetector({formats}) : new BarcodeDetector();
      } else {
        detector = new BarcodeDetector();
      }
      const facing = scanPage.dataset.cameraFacing || 'environment';
      scanStream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:facing},width:{ideal:1280},height:{ideal:720}},audio:false});
      scanVideo.srcObject = scanStream;
      await scanVideo.play();
      if (cameraStart) cameraStart.hidden = true;
      if (cameraStop) cameraStop.hidden = false;
      if (cameraPlaceholder) cameraPlaceholder.hidden = true;
      setCameraStatus('Camera activa. Tine codul in interiorul cadrului.', 'success');
      scanFrame = requestAnimationFrame(detectCameraFrame);
    } catch (e) {
      stopCameraScanner();
      setCameraStatus(e?.name === 'NotAllowedError' ? 'Permisiunea pentru camera a fost refuzata.' : 'Camera nu a putut fi pornita.', 'error');
    }
  };

  cameraStart?.addEventListener('click', startCameraScanner);
  cameraStop?.addEventListener('click', stopCameraScanner);
  window.addEventListener('pagehide', stopCameraScanner);

  // Orders: channel/status filters apply immediately, without an extra Filter button.
  // Search also refreshes after a short pause to keep the list responsive without
  // firing a request on every keystroke.
  const ordersFilterForm = document.querySelector('[data-orders-filter-form]');
  if (ordersFilterForm) {
    const submitOrdersFilter = () => {
      if (typeof ordersFilterForm.requestSubmit === 'function') ordersFilterForm.requestSubmit();
      else ordersFilterForm.submit();
    };
    ordersFilterForm.querySelectorAll('[data-orders-auto-filter]').forEach(el => el.addEventListener('change', submitOrdersFilter));
    const search = ordersFilterForm.querySelector('[data-orders-search]');
    let searchTimer = null;
    search?.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      const value = search.value.trim();
      searchTimer = window.setTimeout(() => {
        if (value.length === 0 || value.length >= 2) submitOrdersFilter();
      }, 550);
    });
  }

  // Products use the same live filtering pattern as Orders.
  const productsFilterForm = document.querySelector('[data-products-filter-form]');
  if (productsFilterForm) {
    const submitProductsFilter = () => {
      if (typeof productsFilterForm.requestSubmit === 'function') productsFilterForm.requestSubmit();
      else productsFilterForm.submit();
    };
    productsFilterForm.querySelectorAll('[data-products-auto-filter]').forEach(el => el.addEventListener('change', submitProductsFilter));
    const search = productsFilterForm.querySelector('[data-products-search]');
    let searchTimer = null;
    search?.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      const value = search.value.trim();
      searchTimer = window.setTimeout(() => {
        if (value.length === 0 || value.length >= 2) submitProductsFilter();
      }, 550);
    });
  }


  // Statistics uses the same automatic filtering behaviour as Orders/Products.
  const statisticsFilterForm = document.querySelector('[data-statistics-filter-form]');
  if (statisticsFilterForm) {
    const submitStatisticsFilter = () => {
      if (typeof statisticsFilterForm.requestSubmit === 'function') statisticsFilterForm.requestSubmit();
      else statisticsFilterForm.submit();
    };
    statisticsFilterForm.querySelectorAll('[data-statistics-auto-filter]').forEach(el => el.addEventListener('change', submitStatisticsFilter));
  }

  // Compact collapse control for analytic cards, inspired by enterprise dashboards.
  document.querySelectorAll('[data-stat-chart-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      const card = button.closest('[data-stat-chart]');
      if (!card) return;
      const collapsed = card.classList.toggle('collapsed');
      button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      button.setAttribute('aria-label', collapsed ? 'Extinde graficul' : 'Restrange graficul');
    });
    button.setAttribute('aria-expanded', 'true');
  });

  // Select-all helpers and selection counter for bulk actions.
  document.querySelectorAll('[data-select-all]').forEach(master => {
    const group = master.dataset.selectAll;
    const items = [...document.querySelectorAll(`[data-select-item="${group}"]`)];
    const refresh = () => {
      const selected = items.filter(x => x.checked).length;
      const form = master.closest('form');
      const counter = form?.querySelector('[data-selection-count]');
      if (counter) counter.textContent = `${selected} selectate`;
      const apply = form?.querySelector('.bulk-apply-button');
      if (apply) apply.dataset.hasSelection = selected > 0 ? '1' : '0';
      master.checked = items.length > 0 && selected === items.length;
      master.indeterminate = selected > 0 && selected < items.length;
    };
    master.addEventListener('change', () => { items.forEach(x => x.checked = master.checked); refresh(); });
    items.forEach(x => x.addEventListener('change', refresh));
    refresh();
  });

  const syncEmagOrderMedia = async (id, scope = document, force = false, trigger = null) => {
    id = Number(id || 0); if (!id) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const originalLabel = trigger?.textContent || '';
    if (trigger) { trigger.disabled = true; trigger.classList.add('is-busy'); if (trigger === imageResync) trigger.setAttribute('aria-busy','true'); }
    try {
      const controller = new AbortController(); const timer = force ? setTimeout(() => controller.abort(), 45000) : setTimeout(() => controller.abort(), 30000);
      const payload = new URLSearchParams(); if (force) payload.set('force','1');
      let r; try { r = await fetch(appUrl(`/api/orders/${id}/emag-images`), {method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':token,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:payload,signal:controller.signal}); } finally { clearTimeout(timer); }
      const data = await r.json(); if (!r.ok || !data?.ok || !Array.isArray(data.items)) return null;
      data.items.forEach(item => {
        const itemId = Number(item.id || 0); if (!itemId) return;
        const image = String(item.image_url || ''); const productUrl = String(item.product_url || '');
        const row = scope.querySelector?.(`[data-preview-order-item="${itemId}"]`) || scope.querySelector?.(`[data-order-item-id="${itemId}"]`);
        const name = row?.dataset?.productName || '';
        const media = scope.querySelector?.(`[data-order-item-media="${itemId}"]`) || row?.querySelector?.('[data-preview-media]');
        if (media && image && (/^https?:\/\//i.test(image) || image.startsWith('/'))) {
          const previous=media.querySelector?.('.js-image-preview'); const toolsAllowed=previous?.dataset?.orderImageTools || '0'; const orderContext=Number(scope?.dataset?.orderOpenProcessing || 0);
          media.innerHTML=''; const btn=document.createElement('button');btn.type='button';btn.className='product-thumb-button js-image-preview';btn.dataset.image=image;btn.dataset.caption=name;
          if(orderContext>0){btn.dataset.orderId=String(orderContext);btn.dataset.orderItemId=String(itemId);btn.dataset.orderImageTools=toolsAllowed;}
          const img=document.createElement('img');img.className='product-thumb';img.src=image;img.alt=name;img.loading='lazy';btn.appendChild(img);media.appendChild(btn);
        } else if (media) {
          const pending=media.querySelector?.('.image-pending');
          if(pending){pending.textContent='fara imagine';pending.classList.remove('image-pending');const err=String(item.error||'');if(err)pending.title=err;}
        }
        const productCell = scope.querySelector?.(`[data-order-item-product="${itemId}"]`) || row?.querySelector?.('[data-preview-copy]');
        if (productCell && /^https?:\/\//i.test(productUrl)) {
          const old = productCell.querySelector?.('.product-source-link,[data-preview-title],strong');
          if (old) { const a=document.createElement('a');a.className='product-source-link';a.target='_blank';a.rel='noopener';a.href=productUrl;a.dataset.previewTitle='';a.append(document.createTextNode(name+' '));const ext=document.createElement('span');ext.setAttribute('aria-hidden','true');ext.textContent='↗';a.appendChild(ext);old.replaceWith(a); }
        }
      });
      return data;
    } catch (_) { return null; } finally { if (trigger) { trigger.disabled = false; trigger.classList.remove('is-busy'); if (trigger === imageResync) trigger.removeAttribute('aria-busy'); if (originalLabel && trigger !== imageResync) trigger.textContent = originalLabel; } }
  };

  const markOrderOpened = async (id, summary = null) => {
    id = Number(id || 0); if (!id) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    try {
      const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 10000);
      let r; try { r = await fetch(appUrl(`/api/orders/${id}/opened`), {method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':token,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:new URLSearchParams(),signal:controller.signal}); } finally { clearTimeout(timer); }
      const data = await r.json(); if (!r.ok || !data.ok) return;
      if (data.status) {
        const labels = {new:'Noua',pending:'In asteptare',processing:'In procesare','on-hold':'In asteptare',prepared:'Pregatita',completed:'Finalizata',cancelled:'Anulata',refunded:'Stornata',returned:'Returnata',failed:'Esuata'};
        const scopes = summary ? [summary] : [document];
        scopes.forEach(scope => scope.querySelectorAll?.('.status').forEach(el => {
          ['new','pending','processing','on-hold','prepared','completed','cancelled','refunded','returned','failed'].forEach(c => el.classList.remove(c));
          el.classList.add(String(data.status)); el.textContent = labels[data.status] || data.status;
        }));
      }
    } catch (_) {}
  };

  // Order list inline preview. Details are loaded only when requested.
  document.querySelectorAll('[data-order-details-toggle]').forEach(button => {
    button.addEventListener('click', async () => {
      const summary = button.closest('[data-order-row]');
      if (!summary) return;
      const id = summary.dataset.orderRow;
      const row = document.querySelector(`[data-order-details-row="${id}"]`);
      const body = row?.querySelector('[data-order-details-body]');
      if (!row || !body) return;
      const open = row.hidden;
      row.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      const label = button.querySelector('span'); if (label) label.textContent = open ? 'Ascunde detalii' : 'Arata detalii';
      summary.classList.toggle('details-open', open);
      if (!open || body.dataset.loaded === '1') return;
      body.innerHTML = '<div class="order-details-loading">Se incarca detaliile comenzii...</div>';
      try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 8000);
        let r;
        try {
          r = await fetch(button.dataset.previewUrl, {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}, signal:controller.signal});
        } finally {
          clearTimeout(timer);
        }
        const text = await r.text();
        let j = null; try { j = JSON.parse(text); } catch (_) {}
        if (!r.ok || !j?.ok) throw new Error(j?.message || `Detaliile nu au putut fi incarcate (HTTP ${r.status}).`);
        const o = j.order || {};
        const money = v => `${Number(v || 0).toFixed(2).replace('.', ',')} ${esc(o.currency || '')}`;
        const url = v => /^https?:\/\//i.test(String(v || '')) ? String(v) : '';
        const mediaUrl = v => { const s=String(v || ''); return (/^https?:\/\//i.test(s) || s.startsWith('/')) ? s : ''; };
        const items = (o.items || []).map(i => {
          const img = mediaUrl(i.image_url) ? `<button type="button" class="product-thumb-button js-image-preview" data-image="${esc(i.image_url)}" data-caption="${esc(i.name)}"><img class="product-thumb" src="${esc(i.image_url)}" alt="${esc(i.name)}" loading="lazy"></button>` : (url(i.product_url) ? '<div class="product-thumb placeholder image-pending">caut imagine</div>' : '<div class="product-thumb placeholder">fara imagine</div>');
          const product = url(i.product_url) ? `<a data-preview-title class="product-source-link" target="_blank" rel="noopener" href="${esc(i.product_url)}">${esc(i.name)} <span aria-hidden="true">↗</span></a>` : `<strong data-preview-title>${esc(i.name)}</strong>`;
          return `<div class="order-preview-product" data-preview-order-item="${Number(i.id || 0)}" data-product-name="${esc(i.name)}"><div data-preview-media>${img}</div><div data-preview-copy><small>SKU ${esc(i.sku || '—')}</small>${product}<span>${esc(i.qty)} x ${money(i.unit_price)}</span></div></div>`;
        }).join('') || '<span class="muted">Fara produse.</span>';
        const docs = (o.documents || []).map(d => `<span class="order-preview-doc">${esc(String(d.type || '').toUpperCase())} ${esc(d.series || '')} ${esc(d.number || '')}${url(d.link) ? ` · <a target="_blank" rel="noopener" href="${esc(d.link)}">deschide</a>` : ''}</span>`).join('') || '<span class="muted">Niciun document local.</span>';
        const shipment = o.shipment ? (()=>{const awb=esc(o.shipment.awb || o.shipment.awb_barcode || '');const courier=esc(o.shipment.courier || '');const href=mediaUrl(o.shipment.download_url)?esc(o.shipment.download_url):'';return href?`<a class="order-preview-awb-link" href="${href}" title="Descarca AWB A4">${awb}</a>${courier?`<small>${courier}</small>`:''}`:`<b>${awb}</b>${courier?`<small>${courier}</small>`:''}`;})() : '<span class="muted">Fara AWB.</span>';
        body.innerHTML = `<div class="order-preview-grid"><div class="order-preview-client"><span>Client</span><b>${esc(o.customer_name || '—')}</b><small>${esc(o.phone || '')}${o.email ? ' · '+esc(o.email) : ''}</small></div><div class="order-preview-external"><span>ID extern</span><b>${esc(o.external_id || '—')}</b></div><div><span>Plata</span><b>${esc(o.payment || '—')}</b></div><div><span>Livrare</span><b>${esc(o.delivery || '—')}</b></div><div><span>AWB</span>${shipment}</div></div><div class="order-preview-products">${items}</div><div class="order-preview-docs"><strong>Documente</strong>${docs}</div>`;
        body.dataset.loaded = '1';
        // Operational status/read acknowledgement and eMAG media repair remain detached from preview rendering.
        void markOrderOpened(id, summary);
        if (o.channel_type === 'emag') setTimeout(() => syncEmagOrderMedia(id, body), 120);
      } catch (err) {
        body.dataset.loaded = '0';
        const msg = err?.name === 'AbortError' ? 'Detaliile locale nu au raspuns in timp util. Reincearca; pagina nu mai asteapta servicii externe.' : (err.message || 'Detaliile nu au putut fi incarcate.');
        body.innerHTML = `<div class="order-details-error">${esc(msg)}</div>`;
      }
    });
  });

  const orderAction = document.querySelector('[data-order-action-select]');
  const courierOptions = document.querySelector('[data-courier-options]');
  const autoAwbOptions = document.querySelector('[data-auto-awb-options]');
  const awbDownloadOptions = document.querySelector('[data-awb-download-options]');
  const ordersBulkForm = document.getElementById('ordersBulkForm');
  const refreshOrderAction = () => {
    const showCourier = orderAction?.value === 'generate_awb';
    const showAutoAwb = orderAction?.value === 'generate_awb_auto';
    const showDownload = orderAction?.value === 'download_awb';
    if (courierOptions) {
      courierOptions.hidden = !showCourier;
      courierOptions.querySelectorAll('input,select,textarea').forEach(el => { el.disabled = !showCourier; });
    }
    if (autoAwbOptions) {
      autoAwbOptions.hidden = !showAutoAwb;
      autoAwbOptions.querySelectorAll('input,select,textarea').forEach(el => { el.disabled = !showAutoAwb; });
    }
    if (awbDownloadOptions) {
      awbDownloadOptions.hidden = !showDownload;
      awbDownloadOptions.querySelectorAll('input,select,textarea').forEach(el => { el.disabled = !showDownload; });
    }
    const apply = document.querySelector('.bulk-apply-button');
    if (apply) {
      apply.dataset.hasAction = orderAction?.value ? '1' : '0';
      apply.textContent = showDownload ? 'Descarca' : ((showCourier || showAutoAwb) ? 'Genereaza AWB' : 'Aplica');
    }
  };
  orderAction?.addEventListener('change', refreshOrderAction); refreshOrderAction();
  ordersBulkForm?.addEventListener('submit', e => {
    const selected=[...ordersBulkForm.querySelectorAll('[data-select-item="orders"]:checked')];
    if(!selected.length){e.preventDefault();window.alert('Selecteaza cel putin o comanda.');return;}
    if(orderAction?.value==='download_awb'){
      ordersBulkForm.action=appUrl('/orders/awbs-download');
      return;
    }
    ordersBulkForm.action=appUrl('/orders/actions');
    if(orderAction?.value==='generate_awb_auto'&&!window.confirm(`Generezi AWB automat pentru ${selected.length} comenzi? Comenzile care au deja AWB nu vor primi automat o expediere suplimentara.`)){e.preventDefault();return;}
    if(orderAction?.value==='create_storno'&&!window.confirm('Emiti storno total in Oblio pentru facturile comenzilor selectate? Daca gestiunea Oblio este activa, produsele vor fi repuse in stoc.'))e.preventDefault();
  });

  const productAction = document.querySelector('[data-product-action-select]');
  const transferOptions = document.querySelector('[data-transfer-options]');
  const deleteOptions = document.querySelector('[data-delete-options]');
  const bulkEditOptions = document.querySelector('[data-bulk-edit-options]');
  const setPanelState = (panel, show) => {
    if (!panel) return;
    panel.hidden = !show;
    panel.querySelectorAll('input,select,textarea').forEach(el => { el.disabled = !show; });
  };
  const refreshProductAction = () => {
    setPanelState(transferOptions, productAction?.value === 'transfer');
    setPanelState(deleteOptions, productAction?.value === 'delete');
    setPanelState(bulkEditOptions, productAction?.value === 'bulk_edit');
  };
  productAction?.addEventListener('change', refreshProductAction); refreshProductAction();
  document.querySelectorAll('.product-transfer-grid').forEach(grid=>{const source=grid.querySelector('select[name="source_code"]'),target=grid.querySelector('select[name="target_code"]');const sync=()=>{if(!source||!target)return;target.value=source.value==='univera'?'alfamed':'univera';};source?.addEventListener('change',sync);sync();});

  const quickModal = document.getElementById('quickEditModal');
  const quickForm = document.getElementById('quickEditForm');
  const closeQuick = () => { if (quickModal) quickModal.hidden = true; };
  document.querySelectorAll('.js-quick-edit').forEach(btn => btn.addEventListener('click', () => {
    if (!quickModal || !quickForm) return;
    quickForm.action = appUrl(`/products/${btn.dataset.id}/quick-save`);
    document.getElementById('qeName').value = btn.dataset.name || '';
    document.getElementById('qeSku').value = btn.dataset.sku || '';
    document.getElementById('qePrice').value = btn.dataset.price || '';
    document.getElementById('qeStock').value = btn.dataset.stock || '';
    quickModal.hidden = false;
  }));
  document.querySelectorAll('[data-modal-close]').forEach(x => x.addEventListener('click', closeQuick));

  const productModal = document.getElementById('productActionModal');
  const paAction = document.getElementById('paAction');
  const paTransfer = productModal?.querySelector('[data-pa-transfer]');
  const paDelete = productModal?.querySelector('[data-pa-delete]');
  const refreshProductModalAction = () => {
    const value = paAction?.value || 'transfer';
    setPanelState(paTransfer, value === 'transfer');
    setPanelState(paDelete, value === 'delete');
  };
  const closeProductModal = () => { if (productModal) productModal.hidden = true; };
  document.querySelectorAll('.js-product-action').forEach(btn => btn.addEventListener('click', () => {
    if (!productModal) return;
    document.getElementById('paId').value = btn.dataset.id || '';
    document.getElementById('paName').textContent = btn.dataset.name || '';
    if (paAction) paAction.value = 'transfer';
    refreshProductModalAction();
    productModal.hidden = false;
  }));
  paAction?.addEventListener('change', refreshProductModalAction); refreshProductModalAction();
  document.querySelectorAll('[data-product-modal-close]').forEach(x => x.addEventListener('click', closeProductModal));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeQuick(); closeProductModal(); } });

  // Product image input: switch cleanly between public URLs and local uploads.
  document.querySelectorAll('[data-product-image-editor]').forEach(root => {
    const radios=[...root.querySelectorAll('[data-image-mode]')];
    const panels=[...root.querySelectorAll('[data-image-panel]')];
    const input=root.querySelector('[data-product-images-input]');
    const list=root.querySelector('[data-product-upload-list]');
    const refresh=()=>{const mode=radios.find(r=>r.checked)?.value||'url';panels.forEach(panel=>{const on=panel.dataset.imagePanel===mode;panel.hidden=!on;panel.querySelectorAll('input,textarea,select').forEach(el=>el.disabled=!on);});};
    radios.forEach(r=>r.addEventListener('change',refresh));refresh();
    input?.addEventListener('change',()=>{if(!list)return;const files=[...(input.files||[])];list.innerHTML=files.length?files.map(f=>`<span class="upload-file-chip">🖼 ${esc(f.name)} <small>${Math.max(1,Math.round(f.size/1024))} KB</small></span>`).join(''):'<span class="muted">Nicio imagine selectata.</span>';});
  });

  if (document.body?.dataset.authenticated !== '1') return;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const openedOrderRoot = document.querySelector('[data-order-open-processing]');
  if (openedOrderRoot) {
    setTimeout(() => markOrderOpened(openedOrderRoot.dataset.orderOpenProcessing), 120);
    if (openedOrderRoot.dataset.emagMediaSync === '1') setTimeout(() => syncEmagOrderMedia(openedOrderRoot.dataset.orderOpenProcessing, openedOrderRoot, false), 260);
  }

  // Product-image maintenance stays inside the image preview, not in the order table.
  if (imageModal && openedOrderRoot) {
    const renderOrderItemImage = (itemId, image, caption = '') => {
      const media = openedOrderRoot.querySelector(`[data-order-item-media="${Number(itemId || 0)}"]`);
      if (!media) return;
      const toolsAllowed = imageTools && !imageTools.hidden ? '1' : '0';
      const orderId = Number(openedOrderRoot.dataset.orderOpenProcessing || 0);
      const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'product-thumb-button js-image-preview';
      btn.dataset.image = image || ''; btn.dataset.caption = caption || ''; btn.dataset.orderId = String(orderId); btn.dataset.orderItemId = String(itemId); btn.dataset.orderImageTools = toolsAllowed;
      if (image) {
        const img = document.createElement('img'); img.className = 'product-thumb'; img.src = image; img.alt = caption || ''; img.loading = 'lazy'; btn.appendChild(img);
      } else {
        btn.classList.add('order-image-placeholder-trigger'); const ph = document.createElement('span'); ph.className = 'product-thumb placeholder'; ph.textContent = 'fara imagine'; btn.appendChild(ph);
      }
      media.innerHTML = ''; media.appendChild(btn);
    };
    imageResync?.addEventListener('click', async () => {
      const orderId = Number(imageModal.dataset.orderId || 0), itemId = Number(imageModal.dataset.orderItemId || 0);
      if (!orderId || !itemId) return;
      if (imageStatus) imageStatus.textContent = 'Verific produsul si caut imaginea exacta...';
      const data = await syncEmagOrderMedia(orderId, openedOrderRoot, true, imageResync);
      const item = data?.items?.find(x => Number(x.id || 0) === itemId);
      if (item?.image_url) {
        const row = openedOrderRoot.querySelector(`[data-order-item-id="${itemId}"]`); const caption = row?.dataset?.productName || imageCaption?.textContent || '';
        setPreviewImage(item.image_url, caption); renderOrderItemImage(itemId, item.image_url, caption);
        if (imageStatus) imageStatus.textContent = 'Imaginea a fost resincronizata.';
      } else if (imageStatus) {
        const reason = String(item?.error || '');
        imageStatus.textContent = reason || 'Nu am gasit o imagine disponibila in datele eMAG pentru acest produs. O poti incarca manual.';
      }
    });
    const uploadManualOrderImage = async file => {
      if (!file || !String(file.type || '').startsWith('image/')) { if (imageStatus) imageStatus.textContent = 'Selecteaza un fisier imagine.'; return; }
      const orderId = Number(imageModal.dataset.orderId || 0), itemId = Number(imageModal.dataset.orderItemId || 0);
      if (!orderId || !itemId) return;
      if (imageStatus) imageStatus.textContent = 'Incarc imaginea...';
      const form = new FormData(); form.append('_csrf', csrf); form.append('image', file);
      try {
        const r = await fetch(appUrl(`/api/orders/${orderId}/items/${itemId}/manual-image`), {method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'Accept':'application/json'},body:form});
        const data = await r.json(); if (!r.ok || !data?.ok || !data?.image_url) throw new Error(data?.message || 'Imaginea nu a putut fi salvata.');
        const row = openedOrderRoot.querySelector(`[data-order-item-id="${itemId}"]`); const caption = row?.dataset?.productName || imageCaption?.textContent || '';
        setPreviewImage(data.image_url, caption); renderOrderItemImage(itemId, data.image_url, caption);
        if (imageStatus) imageStatus.textContent = 'Imaginea manuala a fost salvata si va ramane asociata produsului.';
      } catch (err) { if (imageStatus) imageStatus.textContent = err?.message || 'Imaginea nu a putut fi salvata.'; }
      finally { if (imageUpload) imageUpload.value = ''; imageDropzone?.classList.remove('is-dragging'); }
    };
    imageUpload?.addEventListener('change', () => uploadManualOrderImage(imageUpload.files?.[0]));
    ['dragenter','dragover'].forEach(type => imageDropzone?.addEventListener(type, e => { if (imageTools?.hidden) return; e.preventDefault(); imageDropzone.classList.add('is-dragging'); }));
    ['dragleave','drop'].forEach(type => imageDropzone?.addEventListener(type, e => { if (imageTools?.hidden) return; e.preventDefault(); imageDropzone.classList.remove('is-dragging'); }));
    imageDropzone?.addEventListener('drop', e => { if (imageTools?.hidden) return; const file = [...(e.dataTransfer?.files || [])].find(f => String(f.type || '').startsWith('image/')); if (file) uploadManualOrderImage(file); });
  }

  // Notification center: Facebook-style dropdown, unread badge and optional toast/browser alerts.
  const popup = document.getElementById('orderNotification');
  const popupTitle = document.getElementById('notificationTitle');
  const popupMessage = document.getElementById('notificationMessage');
  const popupOpen = document.getElementById('notificationOpen');
  const popupClose = document.getElementById('notificationClose');
  const popupDismiss = document.getElementById('notificationDismiss');
  const bell = document.getElementById('notificationBell');
  const badge = document.getElementById('notificationBadge');
  const dropdown = document.getElementById('notificationDropdown');
  const list = document.getElementById('notificationList');
  const unreadText = document.getElementById('notificationUnreadText');
  const markAllBtn = document.getElementById('notificationMarkAll');
  const desktopBtn = document.getElementById('notificationDesktopBtn');
  const profilePushBtn = document.getElementById('profilePushBtn');
  const profilePushTestBtn = document.getElementById('profilePushTestBtn');
  let notificationQueue = [], currentNotification = null, notificationPollMs = 5000, notificationTimer = null, browserNotificationsEnabled = true, markNotificationsSeenOnOpen = true, pushSubscriptionActive = false, pushRegistration = null;
  let backgroundOrderSyncInFlight = false;
  const knownPopupNotifications = new Set();
  let latestNotifications = [];
  let unreadCount = 0;

  const notificationTypeClass = type => ({new_order:'order',low_stock:'warning',out_of_stock:'danger'}[type] || 'info');
  function updateNotificationBadge(count) {
    unreadCount = Math.max(0, Number(count) || 0);
    if (badge) { badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount); badge.hidden = unreadCount < 1; }
    if (unreadText) unreadText.textContent = unreadCount ? `${unreadCount} necitite` : 'Nicio notificare noua';
  }
  function renderNotificationList(items) {
    latestNotifications = Array.isArray(items) ? items : [];
    if (!list) return;
    if (!latestNotifications.length) { list.innerHTML = '<div class="notification-empty">Nu exista notificari.</div>'; return; }
    list.innerHTML = latestNotifications.map(item => `<button type="button" class="notification-list-item ${item.is_read ? '' : 'unread'}" data-notification-id="${Number(item.id)}" data-notification-url="${esc(item.url || '')}"><span class="notification-list-icon ${notificationTypeClass(item.type)}">${esc(item.icon || '🔔')}</span><span class="notification-list-copy"><strong>${esc(item.title || 'Notificare')}</strong><span>${esc(item.message || '')}</span><small>${esc(item.time_label || '')}</small></span>${item.is_read ? '' : '<i></i>'}</button>`).join('');
  }
  async function markNotificationsRead(ids, markAll = false) {
    const affected = markAll ? latestNotifications.filter(x=>!x.is_read).map(x=>Number(x.id)).filter(Boolean) : (ids || []).map(Number).filter(Boolean);
    const body = new URLSearchParams();
    if (markAll) body.set('all','1'); else affected.forEach(id => body.append('ids[]', String(id)));
    try {
      const r = await fetch(appUrl('/api/notifications/read'), {method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});
      const d = await r.json();
      if (d.ok && typeof d.unread_count !== 'undefined') updateNotificationBadge(d.unread_count);
      const set=new Set(affected.map(String));
      if (markAll) latestNotifications = latestNotifications.map(x => ({...x,is_read:true}));
      else latestNotifications=latestNotifications.map(x=>set.has(String(x.id))?{...x,is_read:true}:x);
      notificationQueue=notificationQueue.filter(x=>!set.has(String(x.id)));
      if(currentNotification&&set.has(String(currentNotification.id))){currentNotification=null;popup?.classList.remove('show');if(popup)setTimeout(()=>{popup.hidden=true;showNextNotification();},180);}
      renderNotificationList(latestNotifications);
    } catch (_) {}
  }
  function showNextNotification() {
    if (currentNotification || !notificationQueue.length || !popup) return;
    currentNotification = notificationQueue.shift();
    if (popupTitle) popupTitle.textContent = currentNotification.title || 'Notificare';
    if (popupMessage) popupMessage.textContent = currentNotification.message || '';
    if (popupOpen) { popupOpen.href = currentNotification.url || '#'; popupOpen.textContent = currentNotification.order_id ? 'Deschide comanda' : (currentNotification.product_id ? 'Vezi produsul' : 'Deschide'); }
    popup.hidden = false; requestAnimationFrame(() => popup.classList.add('show'));
    if (browserNotificationsEnabled && !pushSubscriptionActive && 'Notification' in window && Notification.permission === 'granted') {
      try { const item=currentNotification; const n=new Notification(item.title || 'ALFAMED CENTRAL',{body:item.message || ''});n.onclick=()=>{window.focus();if(item.url)location.href=item.url;}; } catch (_) {}
    }
  }
  async function finishCurrentNotification(go=false) {
    if (!currentNotification) return;
    const item=currentNotification;currentNotification=null;await markNotificationsRead([item.id]);popup?.classList.remove('show');
    setTimeout(()=>{if(popup)popup.hidden=true;if(go&&item.url)location.href=item.url;else showNextNotification();},180);
  }
  popupClose?.addEventListener('click',()=>finishCurrentNotification(false));
  popupDismiss?.addEventListener('click',()=>finishCurrentNotification(false));
  popupOpen?.addEventListener('click',e=>{e.preventDefault();finishCurrentNotification(true);});

  bell?.addEventListener('click',e=>{e.stopPropagation();if(!dropdown)return;const opening=dropdown.hidden;dropdown.hidden=!opening;bell.setAttribute('aria-expanded',opening?'true':'false');if(opening&&markNotificationsSeenOnOpen){const ids=latestNotifications.filter(x=>!x.is_read).map(x=>Number(x.id)).filter(Boolean);if(ids.length)setTimeout(()=>markNotificationsRead(ids),350);}});
  dropdown?.addEventListener('click',e=>e.stopPropagation());
  document.addEventListener('click',()=>{if(dropdown&&!dropdown.hidden){dropdown.hidden=true;bell?.setAttribute('aria-expanded','false');}});
  list?.addEventListener('click',async e=>{const item=e.target.closest('.notification-list-item');if(!item)return;const id=Number(item.dataset.notificationId||0),url=item.dataset.notificationUrl||'';if(id)await markNotificationsRead([id]);if(url)location.href=url;});
  markAllBtn?.addEventListener('click',()=>markNotificationsRead([],true));
  const pushUrlBase64ToBytes=value=>{
    const padding='='.repeat((4-value.length%4)%4),base64=(value+padding).replace(/-/g,'+').replace(/_/g,'/'),raw=atob(base64),out=new Uint8Array(raw.length);
    for(let i=0;i<raw.length;i++)out[i]=raw.charCodeAt(i);
    return out;
  };
  const devicePushLabel=()=>{
    const platform=navigator.userAgentData?.platform||navigator.platform||'';
    const mobile=navigator.userAgentData?.mobile||/Android|iPhone|iPad|Mobile/i.test(navigator.userAgent||'');
    return `${mobile?'Mobil/Tableta':'PC'}${platform?' · '+platform:''}`.slice(0,120);
  };
  async function ensurePushRegistration(){
    if(pushRegistration)return pushRegistration;
    if(!('serviceWorker' in navigator)||!('PushManager' in window))throw new Error('Acest browser nu suporta Web Push.');
    const swUrl=document.body?.dataset.serviceWorkerUrl||appUrl('/push-sw.js');
    const parsed=new URL(swUrl,location.href),scope=parsed.pathname.replace(/[^/]*$/,'');
    pushRegistration=await navigator.serviceWorker.register(swUrl,{scope});
    await navigator.serviceWorker.ready;
    return pushRegistration;
  }
  async function savePushSubscription(subscription){
    const body=new URLSearchParams();body.set('_csrf',csrf);body.set('subscription',JSON.stringify(subscription.toJSON()));body.set('label',devicePushLabel());
    const r=await fetch(appUrl('/api/push/subscribe'),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body,credentials:'same-origin'});
    const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Abonarea push nu a putut fi salvata.');
  }
  function updatePushButton(){
    if(desktopBtn){desktopBtn.textContent=pushSubscriptionActive?'Notificari push active':'Activeaza notificari push';desktopBtn.classList.toggle('active',pushSubscriptionActive);}
    if(profilePushBtn){profilePushBtn.textContent=pushSubscriptionActive?'Notificari push active pe acest dispozitiv':'Activeaza push pe acest dispozitiv';profilePushBtn.classList.toggle('active',pushSubscriptionActive);}
  }
  async function initPushState(){
    if((!desktopBtn&&!profilePushBtn)||!('serviceWorker' in navigator)||!('PushManager' in window)){updatePushButton();return;}
    try{const reg=await ensurePushRegistration();const sub=await reg.pushManager.getSubscription();pushSubscriptionActive=!!sub;if(sub)await savePushSubscription(sub);updatePushButton();}catch(_){pushSubscriptionActive=false;updatePushButton();}
  }
  const togglePushOnDevice=async()=>{
    if(!('Notification' in window)){alert('Browserul nu suporta notificari de sistem.');return;}
    try{
      const reg=await ensurePushRegistration();let sub=await reg.pushManager.getSubscription();
      if(sub){
        if(!confirm('Dezactivezi notificarile push pe acest dispozitiv?'))return;
        const endpoint=sub.endpoint;await sub.unsubscribe();
        const body=new URLSearchParams();body.set('_csrf',csrf);body.set('endpoint',endpoint);
        await fetch(appUrl('/api/push/unsubscribe'),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body,credentials:'same-origin'});
        pushSubscriptionActive=false;updatePushButton();return;
      }
      const permission=Notification.permission==='granted'?'granted':await Notification.requestPermission();
      if(permission!=='granted'){pushSubscriptionActive=false;updatePushButton();return;}
      const keyRes=await fetch(appUrl('/api/push/public-key'),{headers:{'Accept':'application/json'},cache:'no-store'}),keyData=await keyRes.json();
      if(!keyRes.ok||!keyData.ok||!keyData.supported||!keyData.public_key)throw new Error(keyData.error||'Serverul nu poate activa Web Push.');
      sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:pushUrlBase64ToBytes(keyData.public_key)});
      await savePushSubscription(sub);pushSubscriptionActive=true;updatePushButton();
      try{new Notification('ALFAMED CENTRAL',{body:'Notificarile push sunt active pe acest dispozitiv.'});}catch(_){}
    }catch(err){alert(err?.message||'Notificarile push nu au putut fi activate.');}
  };
  desktopBtn?.addEventListener('click',togglePushOnDevice);
  profilePushBtn?.addEventListener('click',togglePushOnDevice);
  profilePushTestBtn?.addEventListener('click',async()=>{try{const body=new URLSearchParams();body.set('_csrf',csrf);const r=await fetch(appUrl('/api/push/test'),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body,credentials:'same-origin'});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Testul push a esuat.');alert('Notificarea test a fost trimisa catre '+String(d.sent||1)+' dispozitiv(e).');}catch(err){alert(err?.message||'Testul push a esuat.');}});
  initPushState();

  async function triggerBackgroundOrderSync() {
    if (backgroundOrderSyncInFlight || !csrf) return;
    const now=Date.now(), key='alfamed.orders.browserSyncAt';
    try {
      const last=Number(localStorage.getItem(key)||0);
      // Cross-tab throttle: one browser request is enough; the server also owns an exclusive lock.
      if(last && now-last<12000) return;
      localStorage.setItem(key,String(now));
    } catch (_) {}
    backgroundOrderSyncInFlight=true;
    try {
      const body=new URLSearchParams();body.set('_csrf',csrf);
      const r=await fetch(appUrl('/api/background/order-sync'),{method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body,credentials:'same-origin',cache:'no-store'});
      if(r.ok){
        // Fetch the notification center again immediately so freshly imported orders become visible without waiting another interval.
        clearTimeout(notificationTimer);notificationTimer=setTimeout(pollNotifications,350);
      }
    } catch (_) {} finally { backgroundOrderSyncInFlight=false; }
  }

  async function pollNotifications() {
    try {
      const r=await fetch(appUrl('/api/notifications'),{headers:{'Accept':'application/json'},cache:'no-store'});if(r.status===401||r.redirected)return;const d=await r.json();
      if(d.poll_seconds)notificationPollMs=Math.max(3,Number(d.poll_seconds))*1000;browserNotificationsEnabled=d.browser_notifications!==false;markNotificationsSeenOnOpen=d.mark_seen_on_open!==false;
      updateNotificationBadge(d.unread_count || 0);renderNotificationList(d.notifications || []);
      (d.popup_items || d.items || []).forEach(item=>{if(!knownPopupNotifications.has(String(item.id))){knownPopupNotifications.add(String(item.id));notificationQueue.push(item);}});showNextNotification();
      if(d.auto_sync?.background_fallback) triggerBackgroundOrderSync();
    } catch (_) {}
    const nextMs=document.hidden?Math.max(notificationPollMs,30000):notificationPollMs;
    clearTimeout(notificationTimer);notificationTimer=setTimeout(pollNotifications,nextMs);
  }
  if(document.body?.dataset.authenticated==='1'){notificationTimer=setTimeout(pollNotifications,900);document.addEventListener('visibilitychange',()=>{if(!document.hidden){clearTimeout(notificationTimer);notificationTimer=setTimeout(pollNotifications,250);}});}

  // Team chat v2: persistent popup, mute, sound, voice notes, camera and 5-minute message actions.
  const chatRoot=document.getElementById('teamChat');
  if(chatRoot){
    const launcher=document.getElementById('chatLauncher'),panel=document.getElementById('chatPanel'),chatClose=document.getElementById('chatClose'),chatBack=document.getElementById('chatBack'),roomsEl=document.getElementById('chatRooms'),usersEl=document.getElementById('chatUsers'),messagesEl=document.getElementById('chatMessages'),headEl=document.getElementById('chatConversationHead'),muteBtn=document.getElementById('chatMuteBtn'),composer=document.getElementById('chatComposer'),messageInput=document.getElementById('chatMessageInput'),attachmentInput=document.getElementById('chatAttachment'),attachmentBar=document.getElementById('chatAttachmentBar'),attachmentName=document.getElementById('chatAttachmentName'),attachmentClear=document.getElementById('chatAttachmentClear'),unreadBadge=document.getElementById('chatUnreadBadge'),emojiBtn=document.getElementById('chatEmojiBtn'),emojiPanel=document.getElementById('chatEmojiPanel'),voiceBtn=document.getElementById('chatVoiceBtn'),recordStatus=document.getElementById('chatRecordStatus'),recordTime=document.getElementById('chatRecordTime'),cameraBtn=document.getElementById('chatCameraBtn'),cameraNativeTool=document.getElementById('chatCameraNativeTool'),cameraFallbackInput=document.getElementById('chatCameraFallbackInput'),cameraModal=document.getElementById('chatCameraModal'),cameraVideo=document.getElementById('chatCameraVideo'),cameraCanvas=document.getElementById('chatCameraCanvas'),cameraCancel=document.getElementById('chatCameraCancel'),cameraCapture=document.getElementById('chatCameraCapture'),newGroupBtn=document.getElementById('chatNewGroup'),groupCreator=document.getElementById('chatGroupCreator'),groupName=document.getElementById('chatGroupName'),groupMembers=document.getElementById('chatGroupMembers'),groupCancel=document.getElementById('chatGroupCancel'),groupSave=document.getElementById('chatGroupSave'),connection=document.getElementById('chatConnection');
    const stateKey='alfamed.chat.state.v2';
    const requestedChatRoom=Number(new URLSearchParams(window.location.search).get('chat_room')||0);
    let chatPollMs=4000,activeRoom=0,activeRoomName='',lastMessageId=0,cachedUsers=[],cachedRooms=[],chatTimer=null,pendingFile=null,soundEnabled=true,voiceEnabled=true,cameraEnabled=true,firstSummary=true,mediaRecorder=null,mediaStream=null,recordChunks=[],recordStarted=0,recordTimer=null,cameraStream=null;
    const roomLastIds=new Map();
    const mobileChat=()=>window.matchMedia('(max-width:760px)').matches;
    const touchChat=()=>window.matchMedia('(pointer:coarse)').matches || navigator.maxTouchPoints>0;
    const focusChatInput=()=>{if(!messageInput||touchChat())return;try{messageInput.focus({preventScroll:true});}catch(_){messageInput.focus();}};
    const syncMobileChatView=()=>{
      const mobile=mobileChat(),open=panel&&!panel.hidden;
      document.body.classList.toggle('chat-mobile-open',!!(mobile&&open));
      if(launcher)launcher.hidden=!!(mobile&&open);
      if(!mobile)panel?.classList.remove('mobile-conversation-open');
    };
    const emojis=['😀','😂','❤️','👍','🙏','😍','😎','🎉','✅','📦','🚚','🧾','⚠️','👏','🙂','🔥','💬','👌','😃','🤝','🤔','😅','😢','💪','👀'];
    if(emojiPanel)emojiPanel.innerHTML=emojis.map(x=>`<button type="button">${x}</button>`).join('');
    const setChatBadge=n=>{n=Math.max(0,Number(n)||0);if(unreadBadge){unreadBadge.textContent=n>99?'99+':String(n);unreadBadge.hidden=n<1;}};
    const initials=name=>String(name||'?').trim().slice(0,1).toUpperCase();
    const chatAvatar=(url,name,extra='')=>url?`<img class="chat-avatar chat-avatar-image ${extra}" src="${esc(url)}" alt="${esc(name||'Utilizator')}">`:`<span class="chat-avatar ${extra}">${esc(initials(name))}</span>`;
    const roomById=id=>cachedRooms.find(r=>Number(r.id)===Number(id));
    const saveChatState=()=>{try{localStorage.setItem(stateKey,JSON.stringify({open:panel?!panel.hidden:false,room:activeRoom,name:activeRoomName}));}catch(_){}};
    const restoreChatState=()=>{try{return JSON.parse(localStorage.getItem(stateKey)||'{}')||{};}catch(_){return {};}};
    function playChatSound(){if(!soundEnabled)return;try{const AC=window.AudioContext||window.webkitAudioContext;if(!AC)return;const ctx=new AC(),osc=ctx.createOscillator(),gain=ctx.createGain();osc.type='sine';osc.frequency.setValueAtTime(740,ctx.currentTime);gain.gain.setValueAtTime(.0001,ctx.currentTime);gain.gain.exponentialRampToValueAtTime(.07,ctx.currentTime+.015);gain.gain.exponentialRampToValueAtTime(.0001,ctx.currentTime+.18);osc.connect(gain);gain.connect(ctx.destination);osc.start();osc.stop(ctx.currentTime+.2);setTimeout(()=>ctx.close().catch(()=>{}),350);}catch(_){}}
    function renderRooms(rooms){
      cachedRooms=rooms||[];if(!roomsEl)return;const groups=cachedRooms.filter(r=>r.type==='group');const label=document.getElementById('chatGroupsLabel');if(label)label.hidden=groups.length===0;roomsEl.hidden=groups.length===0;roomsEl.innerHTML=groups.map(r=>`<button type="button" class="chat-room ${Number(r.id)===activeRoom?'active':''}" data-room-id="${Number(r.id)}" data-room-name="${esc(r.display_name||'Grup')}">${chatAvatar(r.avatar_url||'',r.display_name||'Grup')}<span class="chat-room-copy"><b>${esc(r.display_name||'Grup')} ${r.muted?'<span title="Mute">🔕</span>':''}</b><small>${esc(r.last_message||'Grup')}</small></span>${Number(r.unread_count||0)>0?`<em>${Number(r.unread_count)}</em>`:''}</button>`).join('');
      updateHead();
    }
    function renderUsers(users){
      cachedUsers=users||[];if(!usersEl)return;usersEl.innerHTML=cachedUsers.map(u=>{const direct=cachedRooms.find(r=>r.type==='direct'&&Number(r.peer_user_id)===Number(u.id));const active=direct&&Number(direct.id)===activeRoom;const unread=Number(direct?.unread_count||0);return `<button type="button" class="chat-user ${active?'active':''}" data-chat-user="${Number(u.id)}" data-user-name="${esc(u.name)}"><span class="chat-avatar-wrap">${chatAvatar(u.avatar_url||'',u.name)}<i class="presence-dot ${u.online?'online':'offline'}"></i></span><span><b>${esc(u.name)} ${direct?.muted?'<span title="Mute">🔕</span>':''}</b><small>${esc(u.online?'Online':u.last_seen_label||'Offline')}</small></span>${unread>0?`<em class="chat-user-unread">${unread}</em>`:''}</button>`;}).join('')||'<div class="chat-empty">Nu exista alti utilizatori activi.</div>';
    }
    function updateHead(){
      if(!headEl)return;const room=roomById(activeRoom);headEl.querySelector('strong')?.replaceChildren(document.createTextNode(activeRoomName||'Alege o conversatie'));const sm=headEl.querySelector('small');if(sm)sm.textContent=activeRoom?'Mesaje interne ALFAMED':'Mesaje interne ALFAMED';if(muteBtn){muteBtn.hidden=!activeRoom;muteBtn.textContent=room?.muted?'🔕':'🔔';muteBtn.title=room?.muted?'Reactiveaza notificarile pentru conversatie':'Pune conversatia pe mute';muteBtn.classList.toggle('muted',!!room?.muted);}
    }
    function attachmentHtml(m){
      if(!m.attachment_url)return'';const mime=String(m.attachment_mime||'');
      if(mime.startsWith('image/'))return `<a class="chat-attachment image" href="${esc(m.attachment_url)}" target="_blank"><img src="${esc(m.attachment_url)}" alt="${esc(m.attachment_name||'Imagine')}"><span>${esc(m.attachment_name||'Imagine')}</span></a>`;
      if(mime.startsWith('audio/'))return `<div class="chat-voice"><span>🎙️ Mesaj vocal</span><audio controls preload="metadata" src="${esc(m.attachment_url)}"></audio></div>`;
      return `<a class="chat-attachment" href="${esc(m.attachment_url)}">📎 ${esc(m.attachment_name||'Atasament')}</a>`;
    }
    function renderMessages(rows,append=false){
      if(!messagesEl)return;if(!append)messagesEl.innerHTML='';
      (rows||[]).forEach(m=>{const div=document.createElement('div');div.className='chat-message '+(m.mine?'mine':'other');div.dataset.messageId=String(m.id||'');let content='';
        if(m.deleted){content='<span class="chat-deleted">Mesaj sters</span>';}
        else {content=(m.message?`<span class="chat-message-text">${esc(m.message).replace(/\n/g,'<br>')}</span>`:'')+attachmentHtml(m);}
        const editTag=m.edited&&!m.deleted?' · editat':'';const actions=m.mine&&!m.deleted&&(m.can_edit||m.can_delete)?`<div class="chat-message-actions">${m.can_edit?`<button type="button" data-chat-edit="${Number(m.id)}" data-current="${esc(m.message||'')}">Editeaza</button>`:''}${m.can_delete?`<button type="button" data-chat-delete="${Number(m.id)}">Sterge</button>`:''}</div>`:'';
        const senderHead=m.mine?'':`<div class="chat-sender-line"><b class="chat-sender">${esc(m.sender_name||'Utilizator')}</b></div>`;
        div.innerHTML=`${senderHead}<div class="chat-bubble">${content}<small>${esc(m.created_at||'')}${editTag}</small></div>${actions}`;messagesEl.appendChild(div);lastMessageId=Math.max(lastMessageId,Number(m.id||0));
      });if((rows||[]).length)messagesEl.scrollTop=messagesEl.scrollHeight;
    }
    async function loadMessages(initial=false){if(!activeRoom)return;try{const params=initial?{}:{after:lastMessageId};const r=await fetch(appUrl(`/api/chat/messages/${activeRoom}`,params),{headers:{'Accept':'application/json'},cache:'no-store'});const d=await r.json();if(d.ok)renderMessages(d.messages||[],!initial);}catch(_){}}
    async function reloadMessages(){lastMessageId=0;await loadMessages(true);}
    async function openRoom(id,name){activeRoom=Number(id);activeRoomName=name||roomById(id)?.display_name||'Conversatie';lastMessageId=0;updateHead();renderRooms(cachedRooms);renderUsers(cachedUsers);if(mobileChat())panel?.classList.add('mobile-conversation-open');syncMobileChatView();saveChatState();await loadMessages(true);focusChatInput();}
    async function startDirect(userId,name){const body=new URLSearchParams({user_id:String(userId)});const r=await fetch(appUrl('/api/chat/direct'),{method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});const d=await r.json();if(d.ok)await openRoom(d.room_id,name);else alert(d.error||'Conversatia nu a putut fi deschisa.');}
    function detectIncoming(rooms){for(const r of rooms||[]){const id=Number(r.id),last=Number(r.last_message_id||0),sender=Number(r.last_sender_id||0),prev=roomLastIds.get(id)||0;if(!firstSummary&&last>prev&&sender!==Number(chatRoot.dataset.userId)&&!r.muted)playChatSound();roomLastIds.set(id,last);}}
    async function refreshChat(){
      try{const r=await fetch(appUrl('/api/chat/summary'),{headers:{'Accept':'application/json'},cache:'no-store'});if(r.status===401||r.redirected)return;const d=await r.json();if(!d.ok)return;if(d.poll_seconds)chatPollMs=Math.max(2,Number(d.poll_seconds))*1000;soundEnabled=d.sound_enabled!==false;voiceEnabled=d.voice_enabled!==false;cameraEnabled=d.camera_enabled!==false;if(voiceBtn)voiceBtn.hidden=!voiceEnabled;if(cameraBtn)cameraBtn.hidden=!cameraEnabled;if(cameraNativeTool)cameraNativeTool.classList.toggle('is-disabled',!cameraEnabled);if(connection)connection.textContent='Conectat';detectIncoming(d.rooms||[]);setChatBadge(d.unread||0);renderRooms(d.rooms||[]);renderUsers(d.users||[]);firstSummary=false;if(activeRoom&&!panel?.hidden)await loadMessages(false);}catch(_){if(connection)connection.textContent='Reconectare...';}
      clearTimeout(chatTimer);chatTimer=setTimeout(refreshChat,document.hidden?Math.max(chatPollMs,30000):chatPollMs);
    }
    function setPanelOpen(open){if(!panel)return;panel.hidden=!open;if(open&&mobileChat())panel.classList.remove('mobile-conversation-open');syncMobileChatView();saveChatState();if(open){refreshChat();if(activeRoom)loadMessages(true);}}
    launcher?.addEventListener('click',()=>setPanelOpen(panel?.hidden??true));chatClose?.addEventListener('click',()=>setPanelOpen(false));chatBack?.addEventListener('click',()=>{panel?.classList.remove('mobile-conversation-open');syncMobileChatView();messageInput?.blur();});
    window.addEventListener('resize',syncMobileChatView);
    roomsEl?.addEventListener('click',e=>{const b=e.target.closest('[data-room-id]');if(b)openRoom(b.dataset.roomId,b.dataset.roomName);});usersEl?.addEventListener('click',e=>{const b=e.target.closest('[data-chat-user]');if(!b)return;const existing=cachedRooms.find(r=>r.type==='direct'&&Number(r.peer_user_id)===Number(b.dataset.chatUser));if(existing)openRoom(existing.id,existing.display_name||b.dataset.userName);else startDirect(b.dataset.chatUser,b.dataset.userName);});
    muteBtn?.addEventListener('click',async()=>{if(!activeRoom)return;const room=roomById(activeRoom),muted=!room?.muted;const body=new URLSearchParams({muted:muted?'1':'0'});try{const r=await fetch(appUrl(`/api/chat/rooms/${activeRoom}/mute`),{method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});const d=await r.json();if(!d.ok)throw new Error(d.error||'Eroare');if(room)room.muted=muted;renderRooms(cachedRooms);updateHead();await refreshChat();}catch(err){alert(err.message||'Nu am putut schimba mute.');}});
    function setPendingFile(file,label=''){pendingFile=file||null;if(attachmentBar)attachmentBar.hidden=!pendingFile;if(attachmentName)attachmentName.textContent=pendingFile?(label||pendingFile.name):'';}
    attachmentInput?.addEventListener('change',()=>setPendingFile(attachmentInput.files?.[0]||null));attachmentClear?.addEventListener('click',()=>{if(attachmentInput)attachmentInput.value='';setPendingFile(null);});
    async function sendChatPayload(file=null,messageOverride=null){if(!activeRoom)throw new Error('Alege o conversatie.');const fd=new FormData();fd.append('message',messageOverride===null?(messageInput?.value||''):messageOverride);const f=file||pendingFile||attachmentInput?.files?.[0];if(f)fd.append('attachment',f,f.name||'atasament.bin');const r=await fetch(appUrl(`/api/chat/messages/${activeRoom}`),{method:'POST',headers:{'X-CSRF-Token':csrf,'Accept':'application/json'},body:fd});const d=await r.json();if(!d.ok)throw new Error(d.error||'Eroare');if(messageOverride===null&&messageInput)messageInput.value='';if(attachmentInput)attachmentInput.value='';setPendingFile(null);await loadMessages(false);await refreshChat();}
    composer?.addEventListener('submit',async e=>{e.preventDefault();try{await sendChatPayload();}catch(err){alert(err.message||'Mesajul nu a putut fi trimis.');}});
    emojiBtn?.addEventListener('click',()=>{if(emojiPanel)emojiPanel.hidden=!emojiPanel.hidden;});emojiPanel?.addEventListener('click',e=>{const b=e.target.closest('button');if(!b||!messageInput)return;messageInput.value+=b.textContent||'';focusChatInput();emojiPanel.hidden=true;});
    messagesEl?.addEventListener('click',async e=>{const edit=e.target.closest('[data-chat-edit]'),del=e.target.closest('[data-chat-delete]');try{if(edit){const next=prompt('Editeaza mesajul (disponibil 5 minute de la trimitere):',edit.dataset.current||'');if(next===null)return;const body=new URLSearchParams({message:next});const r=await fetch(appUrl(`/api/chat/message/${edit.dataset.chatEdit}/edit`),{method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});const d=await r.json();if(!d.ok)throw new Error(d.error||'Eroare');await reloadMessages();}else if(del){if(!confirm('Stergi acest mesaj? Optiunea este disponibila doar in primele 5 minute.'))return;const r=await fetch(appUrl(`/api/chat/message/${del.dataset.chatDelete}/delete`),{method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:new URLSearchParams()});const d=await r.json();if(!d.ok)throw new Error(d.error||'Eroare');await reloadMessages();}}catch(err){alert(err.message||'Operatiunea nu a putut fi efectuata.');}});
    function stopRecorderTracks(){if(mediaStream){mediaStream.getTracks().forEach(t=>t.stop());mediaStream=null;}clearInterval(recordTimer);recordTimer=null;if(recordStatus)recordStatus.hidden=true;if(voiceBtn){voiceBtn.classList.remove('recording');voiceBtn.textContent='🎙️';}}
    voiceBtn?.addEventListener('click',async()=>{if(!voiceEnabled)return;if(mediaRecorder&&mediaRecorder.state==='recording'){mediaRecorder.stop();return;}if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){alert('Browserul nu suporta inregistrarea vocala sau pagina nu ruleaza prin HTTPS/localhost.');return;}try{mediaStream=await navigator.mediaDevices.getUserMedia({audio:true});let mime='';for(const x of ['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus'])if(MediaRecorder.isTypeSupported(x)){mime=x;break;}mediaRecorder=new MediaRecorder(mediaStream,mime?{mimeType:mime}:undefined);recordChunks=[];mediaRecorder.ondataavailable=e=>{if(e.data?.size)recordChunks.push(e.data);};mediaRecorder.onstop=()=>{const type=mediaRecorder.mimeType||mime||'audio/webm',ext=type.includes('ogg')?'ogg':'webm',blob=new Blob(recordChunks,{type}),file=new File([blob],`mesaj-vocal-${Date.now()}.${ext}`,{type});setPendingFile(file,'🎙️ Mesaj vocal pregatit');stopRecorderTracks();mediaRecorder=null;};mediaRecorder.start(250);recordStarted=Date.now();if(recordStatus)recordStatus.hidden=false;voiceBtn.classList.add('recording');voiceBtn.textContent='⏹';recordTimer=setInterval(()=>{const sec=Math.floor((Date.now()-recordStarted)/1000);if(recordTime)recordTime.textContent=`${String(Math.floor(sec/60)).padStart(2,'0')}:${String(sec%60).padStart(2,'0')}`;},500);}catch(err){stopRecorderTracks();alert('Microfonul nu a putut fi deschis: '+(err.message||err));}});
    async function closeCamera(){if(cameraStream){cameraStream.getTracks().forEach(t=>t.stop());cameraStream=null;}if(cameraVideo)cameraVideo.srcObject=null;if(cameraModal)cameraModal.hidden=true;}
    const openNativeCamera=()=>{
      if(!cameraFallbackInput)return false;
      cameraFallbackInput.value='';
      try{if(typeof cameraFallbackInput.showPicker==='function'){cameraFallbackInput.showPicker();return true;}}catch(_){}
      try{cameraFallbackInput.click();return true;}catch(_){return false;}
    };
    cameraNativeTool?.addEventListener('click',e=>{if(!cameraEnabled){e.preventDefault();return;}if(!activeRoom){e.preventDefault();alert('Alege o conversatie inainte sa faci fotografia.');}});
    cameraBtn?.addEventListener('click',async()=>{
      if(!cameraEnabled)return;if(!activeRoom){alert('Alege o conversatie.');return;}
      // Prefer an in-chat camera on mobile too. Native capture remains the fallback for browsers/WebViews that reject getUserMedia.
      if(!navigator.mediaDevices?.getUserMedia){if(!openNativeCamera())alert('Camera necesita HTTPS sau un browser compatibil.');return;}
      try{
        try{cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}},audio:false});}
        catch(_){cameraStream=await navigator.mediaDevices.getUserMedia({video:true,audio:false});}
        if(cameraModal)cameraModal.hidden=false;
        if(cameraVideo){cameraVideo.srcObject=cameraStream;try{await cameraVideo.play();}catch(_){}}
      }catch(err){if(!openNativeCamera())alert('Camera nu a putut fi deschisa: '+(err.message||err));}
    });cameraCancel?.addEventListener('click',closeCamera);
    cameraFallbackInput?.addEventListener('change',()=>{const file=cameraFallbackInput.files?.[0];if(!file)return;setPendingFile(file,'📷 Fotografie pregatita · apasa Trimite');cameraFallbackInput.value='';focusChatInput();});
    cameraCapture?.addEventListener('click',async()=>{if(!cameraVideo||!cameraCanvas)return;const w=cameraVideo.videoWidth||1280,h=cameraVideo.videoHeight||720;cameraCanvas.width=w;cameraCanvas.height=h;cameraCanvas.getContext('2d').drawImage(cameraVideo,0,0,w,h);cameraCapture.disabled=true;try{const blob=await new Promise((resolve,reject)=>cameraCanvas.toBlob(b=>b?resolve(b):reject(new Error('Fotografia nu a putut fi creata.')),'image/jpeg',.9));const file=new File([blob],`foto-${Date.now()}.jpg`,{type:'image/jpeg'});await closeCamera();setPendingFile(file,'📷 Fotografie pregatita · apasa Trimite');focusChatInput();}catch(err){alert(err.message||'Fotografia nu a putut fi pregatita.');}finally{cameraCapture.disabled=false;}});
    newGroupBtn?.addEventListener('click',()=>{if(!groupCreator||!groupMembers)return;groupMembers.innerHTML=cachedUsers.map(u=>`<label><input type="checkbox" value="${Number(u.id)}"> <span class="presence-dot ${u.online?'online':'offline'}"></span> ${esc(u.name)}</label>`).join('');groupCreator.hidden=false;groupName?.focus();});groupCancel?.addEventListener('click',()=>{if(groupCreator)groupCreator.hidden=true;});
    groupSave?.addEventListener('click',async()=>{const members=[...(groupMembers?.querySelectorAll('input:checked')||[])].map(x=>x.value);const body=new URLSearchParams({name:groupName?.value||''});members.forEach(id=>body.append('members[]',id));try{const r=await fetch(appUrl('/api/chat/group'),{method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});const d=await r.json();if(!d.ok)throw new Error(d.error||'Eroare');if(groupCreator)groupCreator.hidden=true;const name=groupName?.value||'Grup';if(groupName)groupName.value='';await openRoom(d.room_id,name);}catch(err){alert(err.message||'Grupul nu a putut fi creat.');}});
    const restored=restoreChatState();if(restored.room){activeRoom=Number(restored.room)||0;activeRoomName=String(restored.name||'Conversatie');updateHead();}if(restored.open&&panel)panel.hidden=false;syncMobileChatView();
    window.addEventListener('beforeunload',saveChatState);setTimeout(async()=>{
      await refreshChat();
      if(requestedChatRoom && roomById(requestedChatRoom)){
        setPanelOpen(true);await openRoom(requestedChatRoom,roomById(requestedChatRoom)?.display_name||'Conversatie');focusChatInput();
      }else if(restored.open&&activeRoom) await reloadMessages();
    },900);
  }


  // Product taxonomy chip editors: existing WooCommerce terms + free creation.
  document.querySelectorAll('[data-term-editor]').forEach(root => {
    const hidden=root.querySelector('[data-term-value]'),input=root.querySelector('[data-term-input]'),chips=root.querySelector('[data-term-chips]'),add=root.querySelector('[data-term-add]');
    if(!hidden||!input||!chips)return;
    let values=String(hidden.value||'').split(',').map(x=>x.trim()).filter(Boolean);
    const unique=()=>{const seen=new Set();values=values.filter(v=>{const k=v.toLocaleLowerCase('ro');if(seen.has(k))return false;seen.add(k);return true;});};
    const sync=()=>{unique();hidden.value=values.join(', ');chips.innerHTML=values.map((v,i)=>`<span class="term-chip">${esc(v)}<button type="button" data-term-remove="${i}" aria-label="Elimina ${esc(v)}">×</button></span>`).join('');};
    const addValue=()=>{const parts=String(input.value||'').split(',').map(x=>x.trim()).filter(Boolean);if(!parts.length)return;values.push(...parts);input.value='';sync();};
    add?.addEventListener('click',addValue);input.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===','){e.preventDefault();addValue();}});input.addEventListener('blur',()=>{if(input.value.trim())addValue();});
    chips.addEventListener('click',e=>{const b=e.target.closest('[data-term-remove]');if(!b)return;values.splice(Number(b.dataset.termRemove),1);sync();});sync();
  });

  // Rich HTML editor compatible with WooCommerce product descriptions.
  document.querySelectorAll('[data-rich-editor]').forEach(root => {
    const source=document.getElementById(root.dataset.sourceId||''),body=root.querySelector('[data-rich-body]'),html=root.querySelector('[data-rich-html]'),toolbar=root.querySelector('.rich-toolbar');if(!source||!body||!toolbar)return;
    body.innerHTML=source.value||'';if(html)html.value=source.value||'';let savedRange=null,sourceMode=false;
    const sync=()=>{if(sourceMode&&html){source.value=html.value;body.innerHTML=html.value;}else{source.value=body.innerHTML;if(html)html.value=source.value;}};
    const saveSelection=()=>{if(sourceMode)return;const sel=window.getSelection();if(!sel||!sel.rangeCount)return;const range=sel.getRangeAt(0);if(body.contains(range.commonAncestorContainer))savedRange=range.cloneRange();};
    const restoreSelection=()=>{if(sourceMode)return;body.focus();if(!savedRange)return;const sel=window.getSelection();sel.removeAllRanges();sel.addRange(savedRange);};
    const setSourceMode=next=>{sourceMode=Boolean(next);if(sourceMode){if(html){html.value=body.innerHTML;html.hidden=false;}body.hidden=true;root.classList.add('source-mode');}else{if(html){body.innerHTML=html.value;html.hidden=true;}body.hidden=false;root.classList.remove('source-mode');sync();body.focus();}};
    document.addEventListener('selectionchange',saveSelection);body.addEventListener('input',sync);body.addEventListener('blur',sync);html?.addEventListener('input',()=>{source.value=html.value;});
    toolbar.addEventListener('mousedown',e=>{if(e.target.closest('button')&&!e.target.closest('[data-rich-action=source]')&&!e.target.closest('[data-rich-action=fullscreen]'))e.preventDefault();});
    toolbar.addEventListener('click',e=>{const btn=e.target.closest('button');if(!btn)return;const command=btn.dataset.richCommand,action=btn.dataset.richAction;
      if(action==='source'){setSourceMode(!sourceMode);btn.classList.toggle('active',sourceMode);return;}
      if(action==='fullscreen'){root.classList.toggle('rich-editor-fullscreen');document.body.classList.toggle('body-modal-open',root.classList.contains('rich-editor-fullscreen'));btn.classList.toggle('active',root.classList.contains('rich-editor-fullscreen'));return;}
      if(sourceMode)return;restoreSelection();
      if(command){document.execCommand(command,false,null);sync();return;}
      if(action==='link'){const url=prompt('URL link (https://...)','https://');if(url&&/^https?:\/\//i.test(url)){document.execCommand('createLink',false,url);sync();}return;}
      if(action==='image'){const url=prompt('URL imagine (https://...)','https://');if(url&&/^https?:\/\//i.test(url)){document.execCommand('insertHTML',false,`<img src="${esc(url)}" alt="" style="max-width:100%;height:auto">`);sync();}return;}
    });
    toolbar.querySelectorAll('select[data-rich-command]').forEach(sel=>sel.addEventListener('change',()=>{if(sourceMode)return;restoreSelection();document.execCommand(sel.dataset.richCommand,false,sel.value);sync();}));
    toolbar.querySelectorAll('input[data-rich-color]').forEach(color=>color.addEventListener('input',()=>{if(sourceMode)return;restoreSelection();document.execCommand(color.dataset.richColor,false,color.value);sync();}));
    root.closest('form')?.addEventListener('submit',sync);
  });

  // Rank Math editor tabs.
  document.querySelectorAll('[data-rankmath-editor]').forEach(root=>{
    const tabs=[...root.querySelectorAll('[data-rank-tab]')],panels=[...root.querySelectorAll('[data-rank-panel]')];
    tabs.forEach(tab=>tab.addEventListener('click',()=>{const key=tab.dataset.rankTab;tabs.forEach(x=>x.classList.toggle('active',x===tab));panels.forEach(x=>x.classList.toggle('active',x.dataset.rankPanel===key));}));
  });

  // Fast product-store synchronization without blocking page navigation.
  const productSyncBtn=document.querySelector('[data-products-sync]');
  productSyncBtn?.addEventListener('click',async()=>{
    if(productSyncBtn.disabled)return;const label=productSyncBtn.querySelector('[data-sync-label]');const original=label?.textContent||'Sincronizeaza magazinele';const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
    productSyncBtn.disabled=true;productSyncBtn.classList.add('is-syncing');if(label)label.textContent='Actualizez produsele...';
    try{const r=await fetch(productSyncBtn.dataset.syncUrl||appUrl('/api/products/sync'),{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({_csrf:csrf}).toString()});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Sincronizarea a avut erori.');if(label)label.textContent=`Actualizat: ${Number(d.count||0)}`;setTimeout(()=>window.location.reload(),500);}catch(err){if(label)label.textContent='Eroare sincronizare';alert(err.message||'Sincronizarea nu a putut fi efectuata.');setTimeout(()=>{if(label)label.textContent=original;productSyncBtn.disabled=false;productSyncBtn.classList.remove('is-syncing');},1200);}
  });

  // Professional order status actions: show reason form only when needed.
  document.querySelectorAll('.js-order-status-dialog').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const form=document.querySelector('[data-order-status-form]');if(!form)return;
      const status=form.querySelector('input[name="status"]');if(status)status.value=btn.dataset.status||'';
      const label=form.querySelector('label:not([data-emag-cancel-reason])');
      if(label){const title='Motiv anulare / mesaj pentru client';const text=label.childNodes[0];if(text&&text.nodeType===Node.TEXT_NODE)text.nodeValue=title;}
      const emagReason=form.querySelector('[data-emag-cancel-reason]'),emagSelect=emagReason?.querySelector('select');
      if(emagReason){const needs=btn.dataset.status==='cancelled';emagReason.hidden=!needs;if(emagSelect)emagSelect.required=needs;}
      form.hidden=false;form.scrollIntoView({block:'nearest',behavior:'smooth'});form.querySelector('textarea')?.focus();
    });
  });
  document.querySelectorAll('[data-close-status-form]').forEach(btn=>btn.addEventListener('click',()=>{const form=btn.closest('[data-order-status-form]');if(form)form.hidden=true;}));

})();
