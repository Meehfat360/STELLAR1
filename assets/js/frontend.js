/**
 * Stellar Meta — Frontend JS Bridge
 * Handles browser-side pixel events, CAPI server relay, consent gate,
 * and unified multi-channel datalayer.
 *
 * Loaded on every page when the plugin is active.
 */
(function ($, config) {
  'use strict';

  if (!config) return;

  /* ── State ──────────────────────────────────────────────── */
  var consentGranted = !config.gdprMode || document.cookie.indexOf('stellar_consent=1') !== -1;
  var pixelLoaded    = false;

  /* ── Unified DataLayer push ─────────────────────────────── */
  window.stellarDataLayer = window.stellarDataLayer || [];

  function push(eventName, params) {
    params = params || {};

    // Meta Pixel
    if (consentGranted && config.platforms.indexOf('meta') !== -1) {
      if (typeof fbq !== 'undefined') {
        fbq('track', eventName, params, { eventID: params.event_id || generateEventId() });
      }
    }

    // GA4
    if (consentGranted && config.platforms.indexOf('ga4') !== -1 && typeof gtag !== 'undefined') {
      var ga4Map = mapToGA4(eventName, params);
      if (ga4Map) gtag('event', ga4Map.name, ga4Map.params);
    }

    // TikTok
    if (consentGranted && config.platforms.indexOf('tiktok') !== -1 && typeof ttq !== 'undefined') {
      ttq.track(mapToTikTok(eventName), params);
    }

    // Relay to server-side CAPI
    if (config.platforms.indexOf('meta') !== -1) {
      relayToCAPI(eventName, params);
    }

    window.stellarDataLayer.push({ event: eventName, params: params, ts: Date.now() });
  }

  /* ── CAPI Server Relay ──────────────────────────────────── */
  function relayToCAPI(eventName, params) {
    var relayEvents = ['ViewContent', 'InitiateCheckout'];
    if (relayEvents.indexOf(eventName) === -1) return;

    var body = {
      event:      eventName,
      product_id: params.content_ids ? params.content_ids[0] : 0,
      event_id:   params.event_id || generateEventId(),
      fbc:        getCookie('_fbc'),
      fbp:        getCookie('_fbp'),
    };

    fetch(config.restUrl + 'track', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(body),
      keepalive: true,
    }).catch(function () {});
  }

  /* ── Page-level event tracking ──────────────────────────── */
  function trackPage(ctx) {
    if (!ctx) return;

    if (ctx.pageType === 'product') {
      push('ViewContent', {
        content_ids:  [String(ctx.productId)],
        content_name: ctx.productName,
        content_type: 'product',
        value:        ctx.price,
        currency:     ctx.currency,
        event_id:     generateEventId(),
      });
    }

    if (ctx.pageType === 'checkout') {
      push('InitiateCheckout', {
        event_id: generateEventId(),
      });
    }
  }

  /* ── Add-to-cart tracking ────────────────────────────────── */
  $(document).on('click', '.single_add_to_cart_button, .add_to_cart_button', function () {
    var $btn  = $(this);
    var price = parseFloat($btn.closest('form,li').find('.price .amount').first().text().replace(/[^0-9.]/g, '')) || 0;
    var id    = $btn.data('product_id') || $btn.closest('form').find('[name=add-to-cart]').val() || '';
    var name  = $btn.closest('li,form').find('.woocommerce-loop-product__title,.product_title').first().text().trim();

    if (!id) return;

    push('AddToCart', {
      content_ids:  [String(id)],
      content_name: name,
      content_type: 'product',
      value:        price,
      currency:     (config.pageContext && config.pageContext.currency) || 'USD',
      event_id:     generateEventId(),
    });
  });

  /* ── Consent handling ────────────────────────────────────── */
  window.stellarGrantConsent = function () {
    consentGranted = true;
    document.cookie = 'stellar_consent=1;path=/;max-age=' + (365 * 24 * 3600);

    // Load pixel if it was deferred
    if (config.lazyPixel && !pixelLoaded && config.pixelId) {
      loadPixel();
    }

    // Notify server
    fetch(config.restUrl + 'consent', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ granted: true }),
    }).catch(function () {});

    // Fire pending PageView
    if (typeof fbq !== 'undefined') {
      fbq('track', 'PageView');
    }
  };

  window.stellarRevokeConsent = function () {
    consentGranted = false;
    document.cookie = 'stellar_consent=0;path=/;max-age=' + (365 * 24 * 3600);
  };

  /* ── Lazy pixel loader ───────────────────────────────────── */
  function loadPixel() {
    if (pixelLoaded || !config.pixelId) return;
    pixelLoaded = true;

    /* jshint ignore:start */
    !function (f, b, e, v, n, t, s) {
      if (f.fbq) return; n = f.fbq = function () {
        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
      };
      if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0';
      n.queue = []; t = b.createElement(e); t.async = !0;
      t.src = v; s = b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t, s);
    }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    /* jshint ignore:end */

    fbq('init', config.pixelId);
    fbq('track', 'PageView');
  }

  /* ── Lazy load trigger ───────────────────────────────────── */
  if (config.lazyPixel) {
    function onFirstInteraction() {
      if (consentGranted) loadPixel();
      document.removeEventListener('scroll',    onFirstInteraction);
      document.removeEventListener('mousemove', onFirstInteraction);
      document.removeEventListener('keydown',   onFirstInteraction);
      document.removeEventListener('touchstart',onFirstInteraction);
    }
    document.addEventListener('scroll',     onFirstInteraction, { passive: true });
    document.addEventListener('mousemove',  onFirstInteraction, { passive: true });
    document.addEventListener('keydown',    onFirstInteraction);
    document.addEventListener('touchstart', onFirstInteraction, { passive: true });
  }

  /* ── Platform mapping helpers ────────────────────────────── */
  function mapToGA4(eventName, params) {
    var map = {
      'ViewContent':      { name: 'view_item',       params: { currency: params.currency, value: params.value, items: [{ item_id: (params.content_ids||[])[0], item_name: params.content_name }] } },
      'AddToCart':        { name: 'add_to_cart',      params: { currency: params.currency, value: params.value, items: [{ item_id: (params.content_ids||[])[0] }] } },
      'InitiateCheckout': { name: 'begin_checkout',   params: { currency: params.currency, value: params.value } },
      'Purchase':         { name: 'purchase',         params: { currency: params.currency, value: params.value, transaction_id: params.order_id } },
    };
    return map[eventName] || null;
  }

  function mapToTikTok(eventName) {
    var map = { 'ViewContent': 'ViewContent', 'AddToCart': 'AddToCart', 'InitiateCheckout': 'InitiateCheckout', 'Purchase': 'PlaceAnOrder' };
    return map[eventName] || eventName;
  }

  /* ── Utilities ───────────────────────────────────────────── */
  function generateEventId() {
    return 'sm_' + Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 9);
  }

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : '';
  }

  /* ── Expose public API ───────────────────────────────────── */
  window.stellarMetaTrackPage = trackPage;
  window.stellarMetaPush      = push;

  /* ── Boot ────────────────────────────────────────────────── */
  $(document).ready(function () {
    // Track page context injected by PHP
    if (config.pageContext) {
      trackPage(config.pageContext);
    }
    // If pixel was not lazy-loaded, fire pending events
    if (!config.lazyPixel && consentGranted && !config.gdprMode) {
      loadPixel();
    }
  });

}(jQuery, window.StellarMeta || null));

  /* ── GA4 client_id capture at checkout ─────────────────── */
  if (config.pageContext && config.pageContext.pageType === 'checkout') {
    // Store GA4 client_id in hidden field for order meta
    var ga4cid = '';
    if (document.cookie.indexOf('_ga=') !== -1) {
      var gaParts = document.cookie.split('_ga=')[1].split(';')[0].split('.');
      if (gaParts.length >= 4) ga4cid = gaParts[2] + '.' + gaParts[3];
    }
    if (ga4cid) {
      jQuery(document).on('checkout_place_order', function() {
        jQuery('<input>').attr({type:'hidden',name:'_stellar_ga4_client_id',value:ga4cid}).appendTo('form.checkout');
      });
    }
    // Capture gclid for Google Ads Enhanced Conversions
    var urlParams = new URLSearchParams(window.location.search);
    var gclid = urlParams.get('gclid') || getCookie('_gcl_aw') || '';
    if (gclid) {
      jQuery('<input>').attr({type:'hidden',name:'_stellar_gclid',value:gclid}).appendTo('form.checkout');
    }
  }

  /* ── Expose trackPage globally ──────────────────────────── */
  window.stellarMetaTrackPage = trackPage;

  /* ── Helpers ─────────────────────────────────────────────── */
  function generateEventId() {
    return 'sm_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
  }

  function getCookie(name) {
    var v = document.cookie.match('(^|;) ?' + name + '=([^;]*)(;|$)');
    return v ? v[2] : '';
  }

  function mapToGA4(eventName, params) {
    var map = {
      'ViewContent':      { name: 'view_item', params: { currency: params.currency, value: params.value, items: [{ item_id: String(params.content_ids && params.content_ids[0] || ''), item_name: params.content_name || '', price: params.value || 0 }] } },
      'AddToCart':        { name: 'add_to_cart', params: { currency: params.currency, value: params.value } },
      'InitiateCheckout': { name: 'begin_checkout', params: { currency: params.currency, value: params.value } },
      'Purchase':         { name: 'purchase', params: { transaction_id: params.order_id || '', value: params.value, currency: params.currency } },
    };
    return map[eventName] || null;
  }

  function mapToTikTok(eventName) {
    var m = { 'ViewContent': 'ViewContent', 'AddToCart': 'AddToCart', 'InitiateCheckout': 'InitiateCheckout', 'Purchase': 'PlaceAnOrder' };
    return m[eventName] || eventName;
  }

  // Trigger page tracking when context is ready
  if (typeof config.pageContext !== 'undefined') {
    trackPage(config.pageContext);
  }

}(jQuery, window.StellarMeta || null));
