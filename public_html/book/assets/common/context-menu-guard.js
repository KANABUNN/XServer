/* Standard context menus are disabled across FIT-SC sites.
 * This file is mirrored in each virtual host so strict same-origin CSPs can load it.
 */
(() => {
  'use strict';

  const protectedDocuments = new WeakSet();
  const protectedFrames = new WeakSet();

  const blockContextMenu = (event) => {
    event.preventDefault();
  };

  const protectFrame = (frame) => {
    if (protectedFrames.has(frame)) return;
    protectedFrames.add(frame);
    const protectContent = () => {
      try {
        protectDocument(frame.contentDocument);
      } catch (error) {
        // Cross-origin frames cannot be accessed; their own origin controls the menu.
      }
    };
    frame.addEventListener('load', protectContent);
    protectContent();
  };

  const protectDocument = (doc) => {
    if (!doc || protectedDocuments.has(doc)) return;
    protectedDocuments.add(doc);
    doc.addEventListener('contextmenu', blockContextMenu, { capture: true });

    const scan = (root) => {
      if (root.matches?.('iframe')) protectFrame(root);
      root.querySelectorAll?.('iframe').forEach(protectFrame);
    };

    scan(doc);
    const Observer = doc.defaultView?.MutationObserver;
    if (!doc.documentElement || typeof Observer !== 'function') return;
    const observer = new Observer((records) => {
      records.forEach((record) => {
        record.addedNodes.forEach((node) => {
          if (node.nodeType === 1) scan(node);
        });
      });
    });
    observer.observe(doc.documentElement, { childList: true, subtree: true });
  };

  protectDocument(document);
})();
