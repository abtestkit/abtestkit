// assets/js/frontend.js
(function () {
  console.log("🔔 AB-Test frontend.js loaded", JSON.stringify(abTestConfig, null, 2));

  const { restUrl, nonce, postId, index } = abTestConfig;


// Keep the block's native href (Popup Maker). Only fill if href is empty/# and a variant explicitly provides a URL.
function ensureButtonHref(a, assignedVariant, variants) {
  if (!a) return;

  const currentHref = (a.getAttribute('href') || '').trim();
  const isMeaningful = (s) => !!s && s !== '#' && !/^javascript:/i.test(s);
  if (isMeaningful(currentHref)) return; // respect native popup attrs/href

  // Optionally allow a variant URL (if you ever set one)
  const v = (variants && (assignedVariant === 'B' ? variants.B : variants.A)) || {};
  const variantUrl =
    (v.url || v.myButtonURL || v.href || '').trim() ||
    (a.querySelector(`[data-ab-variant="${assignedVariant}"]`)?.getAttribute('data-href') || '').trim();

  if (variantUrl) {
    a.setAttribute('href', variantUrl);
  }
}

  // Unified tracker: always sends nonce + ts/sig; keepalive only for clicks
  function trackEvent({ type, abTestId, variant, index: idx }) {
  const ts  = abTestConfig._ts;
  const sig = abTestConfig._sig;

  const payload = {
    type,
    abTestId,
    postId,
    index: typeof idx === 'number' ? idx : (index || 0),
    variant,
    ...(ts  ? { ts }  : {}),
    ...(sig ? { sig } : {}),
  };

  return fetch(`${restUrl}/track`, {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: type === 'click',
    headers: {
    'Content-Type': 'application/json',
    ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
    },
    body: JSON.stringify(payload),
  }).then(async (res) => {
    if (!res.ok) {
      let msg = `HTTP ${res.status}`;
      try {
        const data = await res.json();
        if (data && data.error) msg += ` - ${data.error}`;
      } catch {}
      throw new Error(msg);
    }
    console.log(`[AB TEST] ${type} sent for ${abTestId} (${variant})`);
  }).catch((err) => {
    console.warn(`[AB TEST] ${type} error for ${abTestId}`, err.message || err);
  });
  }

  // === Group Sync bootstrap (A+A / B+B across blocks with the same data-ab-group) ===
  (function () {
    function forced(groupKey) {
      const p = new URLSearchParams(window.location.search);
      const v = p.get('abgroup__' + groupKey);
     return (v === 'A' || v === 'B') ? v : null;
    }
    function load(groupKey) {
      try {
        const v = localStorage.getItem('abg_' + groupKey);
        if (v === 'A' || v === 'B') return v;
     } catch (_) {}
     return null;
   }
    function save(groupKey, v) {
      try { localStorage.setItem('abg_' + groupKey, v); } catch (_) {}
      document.cookie = `abg_${groupKey}=${v};path=/;max-age=2592000`;
   }
    function apply(node, v) {
      // Hide all variant children under this group node…
      node.querySelectorAll('[data-ab-variant]').forEach(el => { el.style.display = 'none'; });
      // …then show the chosen variant’s children
      node.querySelectorAll(`[data-ab-variant="${v}"]`).forEach(el => { el.style.display = ''; });
      node.setAttribute('data-ab-variant', v);
      node.setAttribute('data-ab-active', v);
   }

   // Bucket DOM nodes by group key
   const buckets = {};
   document.querySelectorAll('[data-ab-group]').forEach((node) => {
     const key = node.getAttribute('data-ab-group');
     if (!key) return;
     (buckets[key] ||= []).push(node);
   });

   // Choose one variant per group and apply to all members immediately
   Object.entries(buckets).forEach(([key, nodes]) => {
      const chosen = forced(key) || load(key) || (Math.random() < 0.5 ? 'A' : 'B');
     save(key, chosen);
     nodes.forEach((n) => apply(n, chosen));
    });
  })();

  const sentImpressions = new Set();
Object.keys(abTestConfig).forEach(function (key) {
  if (['postId', 'index', 'nonce', 'restUrl', '_ts', '_sig'].includes(key)) return;

  const variants = abTestConfig[key];
  if (typeof variants !== 'object' || !variants || !variants.A || !variants.B) return;

    const abTestId = key;
    const urlParams = new URLSearchParams(window.location.search);
    const previewParam = urlParams.get('ab_preview');
    const blockEls = document.querySelectorAll(`[data-ab-test-id="${abTestId}"]`);
    // Helper: reveal only the assigned variant child within a test wrapper
    const showVariantChild = (wrapper, assigned, variants) => {
      // Hide all variant children first
      wrapper.querySelectorAll('[data-ab-variant]').forEach(el => { el.style.display = 'none'; });

      // Show the assigned one
     const child = wrapper.querySelector(`[data-ab-variant="${assigned}"]`);
      if (child) child.style.display = '';

      // If wrapper is an <a>, route to the single helper that decides the href.
      if (wrapper.tagName && wrapper.tagName.toLowerCase() === 'a') {
       ensureButtonHref(wrapper, assigned, variants);
      }
    };

  
 blockEls.forEach(function (blockEl) {
  // 👉 If this element (or an ancestor) is part of a group, use the group's chosen variant
  const groupNode = blockEl.closest('[data-ab-group]');
  let assigned = null;

  if (groupNode) {
    assigned = groupNode.getAttribute('data-ab-variant') || groupNode.getAttribute('data-ab-active');
  }

  // Fallback to existing per-test logic if NOT grouped
  if (!assigned) {
    if (previewParam) {
      const pairs = previewParam.split(',');
      for (let pair of pairs) {
        const [id, variant] = pair.split(':');
        if (id === abTestId && (variant === 'A' || variant === 'B')) {
          assigned = variant;
          break;
        }
      }
    }
    if (!assigned) {
      assigned = localStorage.getItem(`ab-${abTestId}`) || (Math.random() < 0.5 ? 'A' : 'B');
      localStorage.setItem(`ab-${abTestId}`, assigned);
    }
  }

  blockEl.dataset.abVariant = assigned;
  blockEl.dataset.abIndex = index;

  // If not grouped, toggle visibility here; grouped nodes were handled in the bootstrap
  if (!groupNode) {
    showVariantChild(blockEl, assigned, variants);
    const link = blockEl.querySelector('a.wp-block-button__link');
    if (link) ensureButtonHref(link, assigned, variants);
  } else if (blockEl.matches('a.wp-block-button__link')) {
    // Still ensure href for buttons inside a group
    ensureButtonHref(blockEl, assigned, variants);
  }

  // 👁️ Impression
  if (!sentImpressions.has(abTestId)) {
    trackEvent({ type: 'impression', abTestId, variant: assigned, index });
    sentImpressions.add(abTestId);
  }

  // 🖱️ Clicks for non-buttons (your late-binding covers buttons)
  if (!blockEl.matches('a.wp-block-button__link')) {
    blockEl.addEventListener('click', () => {
      const key = `ab-clicked-${abTestId}`;
      if (sessionStorage.getItem(key) === '1') return;
      sessionStorage.setItem(key, '1');
      trackEvent({ type: 'click', abTestId, variant: assigned, index });
    }, { passive: true });
  }
});

  });


window.addEventListener("load", () => {
  setTimeout(() => {
    const buttons = document.querySelectorAll(".wp-block-button__link");
    console.log(`🔍 [AB-Test] Late binding: Found ${buttons.length} button(s)`);

    const { restUrl, nonce, postId } = window.abTestConfig || {};

    // Build: buttonId -> [targetTestIds]
const buttonTargetsMap = {};
Object.entries(abTestConfig).forEach(([testId, variants]) => {
  const sources = variants?.conversionFrom || [];
  sources.forEach((buttonId) => {
    if (!buttonTargetsMap[buttonId]) buttonTargetsMap[buttonId] = new Set();
    buttonTargetsMap[buttonId].add(testId); // testId is the TARGET (heading/paragraph/image)
  });
});

// Augment with group membership (even if no explicit conversionFrom is set)
const domButtonIds = Array.from(
  document.querySelectorAll('a.wp-block-button__link[data-ab-test-id]')
)
  .map(a => a.getAttribute('data-ab-test-id'))
  .filter(Boolean);

const uniqueButtonIds = Array.from(new Set([
  ...Object.keys(buttonTargetsMap),              // buttons from conversionFrom
  ...domButtonIds                                // every CTA button present in DOM
]));

uniqueButtonIds.forEach((buttonId) => {
  const wrapper =
    document.querySelector(`[data-ab-test-id="${buttonId}"]`) ||
    document.querySelector(`[data-block][data-ab-test-id="${buttonId}"]`);
  if (!wrapper) return;

  // Seed with any groupedAbTests already present in the config (if you store them there)
  const seedFromConfig = (abTestConfig[buttonId]?.groupedAbTests || []);
  if (!buttonTargetsMap[buttonId]) buttonTargetsMap[buttonId] = new Set(seedFromConfig);
  else seedFromConfig.forEach(id => buttonTargetsMap[buttonId].add(id));

    // Find group marker on self, descendants, or ancestors
    let groupHost =
    (wrapper.matches('[data-ab-group]') && wrapper) ||
    wrapper.querySelector('[data-ab-group]') ||
    wrapper.closest('[data-ab-group]');

  // Fallback: if the button isn't inside a group, try any seeded testId's group
  if (!groupHost && seedFromConfig.length) {
    for (const seedId of seedFromConfig) {
      const seedNode = document.querySelector(`[data-ab-test-id="${seedId}"]`);
      if (!seedNode) continue;
      const found =
        (seedNode.matches?.('[data-ab-group]') && seedNode) ||
        seedNode.querySelector?.('[data-ab-group]') ||
        seedNode.closest?.('[data-ab-group]');
      if (found) { groupHost = found; break; }
    }
  }

  if (!groupHost) return;

  const groupKey = groupHost.getAttribute('data-ab-group');
  if (!groupKey) return;

  // Add every member in the same group (self + descendants across all group nodes)
  document.querySelectorAll(`[data-ab-group="${groupKey}"]`).forEach((groupNode) => {
    const selfId = groupNode.getAttribute('data-ab-test-id');
    if (selfId && selfId !== buttonId) buttonTargetsMap[buttonId].add(selfId);

    groupNode.querySelectorAll('[data-ab-test-id]').forEach((el) => {
      const id = el.getAttribute('data-ab-test-id');
      if (id && id !== buttonId) buttonTargetsMap[buttonId].add(id);
    });
  });

  try {
    console.debug('[AB-Test] group targets for', buttonId, Array.from(buttonTargetsMap[buttonId]));
  } catch {}
});

    // Write targets to the actual <a.wp-block-button__link> the click handler reads from
    Object.entries(buttonTargetsMap).forEach(([buttonId, targetSet]) => {
      const wrapper = document.querySelector(`[data-ab-test-id="${buttonId}"]`);
     if (!wrapper) {
        console.warn(`⚠️ Could not find button with test ID: ${buttonId}`);
        return;
      }

      const link = wrapper.matches('.wp-block-button__link')
       ? wrapper
       : wrapper.querySelector('.wp-block-button__link');

     if (!link) {
       console.warn(`⚠️ Button wrapper ${buttonId} has no .wp-block-button__link child`);
       return;
     }

     const targetsCsv = [...targetSet].join(',');
     link.setAttribute('data-ab-conversion-targets', targetsCsv);
    });

    buttons.forEach((btn) => {
  btn.dataset.abClickBound = 'true';

  btn.addEventListener("click", () => {
    const sentTo = new Set();

    // 🎯 Conversion targets (e.g., headings/images this button converts)
    const rawTargets =
      btn.dataset.abConversionTargets ||
      btn.getAttribute('data-ab-conversion-targets') ||
      btn.getAttribute('data-ab-conversion-from') || '';
    const conversionTargets = rawTargets
      .split(',')
      .map(s => s.trim())
      .filter(Boolean);

    conversionTargets.forEach((targetId) => {
      const sessionKey = `ab-clicked-${targetId}`;
      if (sessionStorage.getItem(sessionKey) === '1') return; // once per session

      const el = document.querySelector(
        `[data-ab-test-id="${targetId}"], [data-block][data-ab-test-id="${targetId}"]`
      );
      let variant = el?.getAttribute('data-ab-variant');
      if (!variant && el) {
        const inner = el.querySelector('[data-ab-test-id][data-ab-variant]');
        variant = inner?.getAttribute('data-ab-variant');
      }

      if (el && variant && !sentTo.has(targetId)) {
        trackEvent({ type: 'click', abTestId: targetId, variant });
        sessionStorage.setItem(sessionKey, '1');
        sentTo.add(targetId);
      }
    });

    // 🟢 Also send click to the button’s own block, if it’s a test block
    const selfTestId = btn.dataset.abTestId;
    const selfVariant = btn.dataset.abVariant;
    if (selfTestId && selfVariant && !sentTo.has(selfTestId)) {
      const selfKey = `ab-clicked-${selfTestId}`;
      if (sessionStorage.getItem(selfKey) !== '1') {
        trackEvent({ type: 'click', abTestId: selfTestId, variant: selfVariant });
        sessionStorage.setItem(selfKey, '1');
        sentTo.add(selfTestId);
      }
    }
  }, { passive: true });
});
  }, 250);
});
})();