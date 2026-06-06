/* ============================================================
   flo-event — shared interactions
   theme toggle · mobile nav · scroll reveal · pricing · FAQ
   ============================================================ */
(function () {
  // ---- theme ----
  const root = document.documentElement;
  function setTheme(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('flo-theme', t); } catch (e) {}
  }
  window.__toggleTheme = function () {
    setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  };

  document.addEventListener('DOMContentLoaded', function () {
    // theme toggle buttons
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.addEventListener('click', window.__toggleTheme);
    });

    // mobile menu
    const ham = document.querySelector('[data-hamburger]');
    const menu = document.querySelector('[data-mobile-menu]');
    if (ham && menu) {
      ham.addEventListener('click', function () { menu.classList.toggle('open'); });
      menu.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () { menu.classList.remove('open'); });
      });
    }

    // scroll reveal
    const reveals = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      reveals.forEach(function (el, i) {
        const d = el.getAttribute('data-delay');
        if (d) el.style.transitionDelay = d + 'ms';
        io.observe(el);
      });
    } else {
      reveals.forEach(function (el) { el.classList.add('in'); });
    }

    // pricing toggle
    const toggle = document.querySelector('[data-billing-toggle]');
    if (toggle) {
      toggle.addEventListener('click', function () {
        const yearly = toggle.getAttribute('aria-checked') === 'true';
        const next = !yearly;
        toggle.setAttribute('aria-checked', String(next));
        document.body.setAttribute('data-billing', next ? 'yearly' : 'monthly');
      });
    }

    // FAQ accordion
    document.querySelectorAll('[data-faq] .faq-q').forEach(function (q) {
      q.addEventListener('click', function () {
        const item = q.closest('.faq-item');
        const open = item.classList.contains('open');
        item.parentElement.querySelectorAll('.faq-item.open').forEach(function (o) {
          if (o !== item) o.classList.remove('open');
        });
        item.classList.toggle('open', !open);
      });
    });

    // nav shadow on scroll
    const nav = document.querySelector('.nav');
    if (nav) {
      const onScroll = function () {
        if (window.scrollY > 8) nav.style.boxShadow = 'var(--shadow-sm)';
        else nav.style.boxShadow = 'none';
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }
  });
})();
