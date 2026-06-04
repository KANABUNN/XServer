(function () {
  'use strict';

  const links = Array.from(document.querySelectorAll('[data-view-target]'));
  const views = Array.from(document.querySelectorAll('.content-view'));

  function activateView(viewId) {
    for (const view of views) {
      view.classList.toggle('is-active', view.id === viewId);
    }
    for (const link of links) {
      link.classList.toggle('is-active', link.dataset.viewTarget === viewId);
    }
    if (history.replaceState) {
      history.replaceState(null, '', '#' + viewId);
    }
  }

  for (const link of links) {
    link.addEventListener('click', function () {
      activateView(link.dataset.viewTarget);
    });
  }

  const initialHash = decodeURIComponent(location.hash.replace(/^#/, ''));
  if (initialHash && document.getElementById(initialHash)) {
    activateView(initialHash);
  }
})();
