// Applied before first paint to avoid a flash of the wrong theme.
(function () {
  var t = 'auto';
  try { t = localStorage.getItem('pbput-theme') || 'auto'; } catch (e) {}
  if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
})();
