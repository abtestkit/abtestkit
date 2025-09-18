// assets/js/editor.js
(function (wp) {
  const { createHigherOrderComponent } = wp.compose;
  const { select } = wp.data;
  const { useState, useEffect } = wp.element;

  /** 1) Extend core/button and acf/bv-panel to add all the A/B attributes needed */
  function addAbAttributes(settings, name) {
    const supportedBlocks = ['core/button', 'acf/bv-panel', 'core/heading', 'core/paragraph', 'core/image'];
    if (!supportedBlocks.includes(name)) return settings;

    settings.attributes = {
      ...settings.attributes,
      abTestEnabled: { type: 'boolean', default: false },
      abTestVariants: { type: 'object', default: {} },
      abTestId: { type: 'string', default: '' },
      abTestRunning: { type: 'boolean', default: false },
      abTestWinner: { type: 'string', default: '' },
      abTestResultsViewed: { type: 'boolean', default: false },
      conversionFrom: { type: 'array', default: [] },
      abTestLastUnlocked: { type: 'number', default: 0 },
      abTestStartedAt: { type: 'number', default: 0 },
      abTestFinishedAt: { type: 'number', default: 0 },
      abSync: { type: 'boolean', default: false },
      abGroupKey: { type: 'string',  default: '' },
    };
    return settings;
  }

  wp.hooks.addFilter('blocks.registerBlockType', 'abtest/extend-attributes', addAbAttributes);

const withInlineLock = wp.compose.createHigherOrderComponent((BlockEdit) => {
  return (props) => {
    const LOCKABLE = ['core/button','core/heading','core/paragraph','core/image','acf/bv-panel'];
    const isLockable = LOCKABLE.includes(props.name);

    const { name, attributes: attrs = {}, setAttributes, clientId } = props;
    const locked = !!(attrs.abTestRunning || attrs.abTestWinner);
    const isTextBlock = (name === 'core/heading' || name === 'core/paragraph' || name === 'core/button');

    // Always mount the CSS once
    wp.element.useEffect(() => {
      if (document.getElementById('abtest-no-caret-css')) return;
      const style = document.createElement('style');
      style.id = 'abtest-no-caret-css';
      style.textContent = `
        .abtest-locked-text .block-editor-rich-text__editable {
          user-select: none !important;
          -webkit-user-select: none !important;
          caret-color: transparent !important;
        }
      `;
      document.head.appendChild(style);
    }, []);

    // Guarded binding: only runs when relevant, but the hook itself is always present
    wp.element.useEffect(() => {
      if (!isLockable || !isTextBlock) return;
      const wrapper = document.querySelector(`.block-editor-block-list__block[data-block="${clientId}"]`);
      if (!wrapper) return;

      const dispatch = (wp.data.dispatch('core/block-editor') || wp.data.dispatch('core/editor'));
      const inRichText = (t) => !!t && t.closest && t.closest('.block-editor-rich-text__editable');

      const onMouseDown = (e) => { if (locked && inRichText(e.target)) { e.preventDefault(); e.stopPropagation(); try { dispatch.selectBlock(clientId); } catch(_) {} } };
      const onFocusIn  = (e) => { if (locked && inRichText(e.target)) { e.preventDefault(); e.stopPropagation(); try { dispatch.selectBlock(clientId); } catch(_) {} e.target.blur(); } };
      const stopInput  = (e) => { if (locked && inRichText(e.target)) { e.preventDefault(); e.stopPropagation(); } };

      if (locked) wrapper.classList.add('abtest-locked-text'); else wrapper.classList.remove('abtest-locked-text');

      if (locked) {
        wrapper.addEventListener('mousedown', onMouseDown, true);
        wrapper.addEventListener('focusin', onFocusIn, true);
        wrapper.addEventListener('beforeinput', stopInput, true);
        wrapper.addEventListener('input', stopInput, true);
        wrapper.addEventListener('keydown', stopInput, true);
        wrapper.addEventListener('paste', stopInput, true);
        wrapper.addEventListener('drop', stopInput, true);
      }
      return () => {
        if (!wrapper) return;
        wrapper.classList.remove('abtest-locked-text');
        wrapper.removeEventListener('mousedown', onMouseDown, true);
        wrapper.removeEventListener('focusin', onFocusIn, true);
        wrapper.removeEventListener('beforeinput', stopInput, true);
        wrapper.removeEventListener('input', stopInput, true);
        wrapper.removeEventListener('keydown', stopInput, true);
        wrapper.removeEventListener('paste', stopInput, true);
        wrapper.removeEventListener('drop', stopInput, true);
      };
    }, [isLockable, isTextBlock, clientId, locked]);

    if (!isLockable) {
      // Hooks above still ran, so order is stable.
      return wp.element.createElement(BlockEdit, props);
    }

    const effectiveSetAttributes = locked ? () => {} : setAttributes;

    return wp.element.createElement(
      'div',
      { style: { position: 'relative' } },
      [
        wp.element.createElement(BlockEdit, { ...props, setAttributes: effectiveSetAttributes }),
        locked &&
          wp.element.createElement('div', {
            style: {
              position: 'absolute', inset: 0, background: 'rgba(255,255,255,0.55)',
              color: '#333', fontSize: '13px', display: 'flex', alignItems: 'center',
              justifyContent: 'center', textAlign: 'center', fontWeight: 600,
              borderRadius: '6px', pointerEvents: 'none', zIndex: 2,
            },
          }, '🔒 A/B Test Running — Block Locked'),
      ]
    );
  };
}, 'withInlineLock');


const assignAbTestIdGlobally = createHigherOrderComponent((BlockEdit) => {
  return (props) => {
    const supportedBlocks = ['core/button', 'core/heading', 'core/paragraph', 'core/image', 'acf/bv-panel'];
    const { name, attributes, setAttributes, clientId } = props;

    useEffect(() => {
  if (
    supportedBlocks.includes(name) &&
    clientId &&
    (!attributes.abTestId || attributes.abTestId.startsWith("auto-"))
  ) {
    const newId = 'ab-' + Math.random().toString(36).slice(2, 11);
    setAttributes({ abTestId: newId });
    console.log(`🆔 Assigned abTestId to ${name}: ${newId}`);
  }
}, [clientId]);

    return wp.element.createElement(BlockEdit, props);
  };
}, 'assignAbTestIdGlobally');


wp.hooks.addFilter('editor.BlockEdit', 'abtest/inline-lock', withInlineLock);

// ---- SAFE: variant syncing for acf/bv-panel (unconditional hooks) ----
const withVariantSidebarSync = wp.compose.createHigherOrderComponent((BlockEdit) => {
  return (props) => {
    const isBV = props.name === 'acf/bv-panel';
    const { attributes, setAttributes } = props;

    // These hooks are now ALWAYS called
    const [previewVariant, setPreviewVariant] = wp.element.useState('A');

    const abTestEnabled   = attributes?.abTestEnabled;
    const abTestVariants  = attributes?.abTestVariants || {};
    const abTestId        = attributes?.abTestId || '';
    const abTestRunning   = !!attributes?.abTestRunning;
    const abTestWinner    = attributes?.abTestWinner || '';

    // Listen for preview change events only when relevant
    wp.element.useEffect(() => {
      if (!isBV) return;
      function handlePreviewChange(e) {
        if (!e?.detail || e.detail.abTestId !== abTestId) return;
        const v = e.detail.preview === 'B' ? 'B' : 'A';
        setPreviewVariant(v);

        const variantData = abTestVariants[abTestId]?.[v] || {};
        const fields = (window.acf?.getFields?.() || []);
        ['myTitle','myContent','myButtonText','myButtonURL'].forEach((name) => {
          const field = fields.find(f => f.get?.('name') === name);
          if (field && variantData[name] !== undefined) field.val(variantData[name]);
        });
      }
      window.addEventListener('abTestVariantChange', handlePreviewChange);
      return () => window.removeEventListener('abTestVariantChange', handlePreviewChange);
    }, [isBV, abTestId, abTestVariants]);

    // Initial sync when the preview variant changes
    wp.element.useEffect(() => {
      if (!isBV) return;
      const variantData = abTestVariants[abTestId]?.[previewVariant] || {};
      const fields = (window.acf?.getFields?.() || []);
      ['myTitle','myContent','myButtonText','myButtonURL'].forEach((name) => {
        const field = fields.find(f => f.get?.('name') === name);
        if (field && variantData[name] !== undefined) field.val(variantData[name]);
      });
    }, [isBV, previewVariant, abTestVariants, abTestId]);

    // Only wrap setAttributes for BV; otherwise pass through
    const wrappedSetAttributes = (patch) => {
      if (!isBV) return setAttributes(patch);
      const variantPatch = { ...(abTestVariants || {}) };
      if (!variantPatch[abTestId]) variantPatch[abTestId] = { A: {}, B: {} };
      Object.keys(patch).forEach((key) => {
        if (['myTitle','myContent','myButtonText','myButtonURL'].includes(key)) {
          variantPatch[abTestId][previewVariant][key] = patch[key];
          delete patch[key];
        }
      });
      setAttributes({ ...patch, abTestVariants: variantPatch });
    };

    const locked = !!(abTestRunning || (abTestWinner === 'A' || abTestWinner === 'B'));

    // If this *is* the panel and AB is enabled, surface the variant fields for preview only
    const newProps = (isBV && abTestEnabled && abTestId && abTestVariants[abTestId])
      ? {
          ...props,
          attributes: {
            ...attributes,
            ...(abTestVariants[abTestId]?.[previewVariant] || {}),
          },
          setAttributes: locked ? () => {} : wrappedSetAttributes,
        }
      : props;

    return wp.element.createElement(BlockEdit, newProps);
  };
}, 'withVariantSidebarSync');

// Ensure the HOC is actually registered
wp.hooks.addFilter('editor.BlockEdit', 'abtest/variant-sidebar-sync', withVariantSidebarSync);

  /** 4) Disable native ACF inspector completely when A/B Test is enabled */
  const withInspectorDisable = createHigherOrderComponent((BlockEdit) => {
    return (props) => {
      if (props.name !== 'acf/bv-panel') {
        return wp.element.createElement(BlockEdit, props);
      }
      const { attributes } = props;
      if (!attributes.abTestEnabled) {
        return wp.element.createElement(BlockEdit, props);
      }
      // A/B Test is enabled — disable native inspector
      return wp.element.createElement(wp.element.Fragment, null,
        wp.element.createElement(BlockEdit, {
          ...props,
          InspectorControls: () => null,
        })
      );
    };
  }, 'withInspectorDisable');
  wp.hooks.addFilter('editor.BlockEdit', 'abtest/assign-ab-id', assignAbTestIdGlobally);
  wp.hooks.addFilter('editor.BlockEdit', 'abtest/inspector-disable', withInspectorDisable);

})(window.wp);