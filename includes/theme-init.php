<script>
  // Blocking (non-deferred) and placed as early as possible in <head> so
  // the `dark` class lands on <html> before first paint — avoids a
  // flash of the wrong theme. Preference: localStorage > system setting.
  (function () {
    try {
      var stored = localStorage.getItem('care-theme');
      var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (dark) document.documentElement.classList.add('dark');
    } catch (e) {}
  })();

  // Chart.js color helpers. Defined this early (rather than in the
  // deferred app.js) so a page's own inline chart-creation script
  // (which runs before app.js loads, further down the body) can call
  // applyChartDefaults() right before instantiating its charts —
  // Chart.js only reads Chart.defaults as a fallback at creation time,
  // so this has to run first, not reactively. Safe no-ops on any page
  // that doesn't load Chart.js.
  function chartThemeColors() {
    var dark = document.documentElement.classList.contains('dark');
    return {
      text: dark ? '#b7bfd8' : '#475569',
      grid: dark ? 'rgba(255,255,255,0.08)' : 'rgba(15,23,42,0.06)',
    };
  }
  function applyChartDefaults() {
    if (typeof Chart === 'undefined') return;
    var c = chartThemeColors();
    Chart.defaults.color = c.text;
    Chart.defaults.borderColor = c.grid;
  }
</script>
