if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('service-worker.js', {scope: './'}).catch(() => {
      // The website still works when service workers are unavailable.
    });
  });
}
