/* public/welcome/landing.js */
(function () {
    document.documentElement.classList.add('js');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var nav = document.getElementById('siteNav');
    function onScroll() { nav.classList.toggle('scrolled', window.scrollY > 8); }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    var btn = document.getElementById('menuBtn');
    var links = document.getElementById('navLinks');
    btn.addEventListener('click', function () {
        var open = links.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    links.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') { links.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { links.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
    });

    var reveals = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window && !reduce) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
            });
        }, { threshold: 0.12 });
        reveals.forEach(function (el) { io.observe(el); });
    } else {
        reveals.forEach(function (el) { el.classList.add('in'); });
    }

    function countUp(el) {
        var target = parseInt(el.getAttribute('data-count'), 10);
        var prefix = el.getAttribute('data-prefix') || '';
        var suffix = el.getAttribute('data-suffix') || '';
        var start = null;
        function step(ts) {
            if (start === null) { start = ts; }
            var t = Math.min((ts - start) / 900, 1);
            var eased = 1 - Math.pow(1 - t, 3);
            el.textContent = prefix + Math.round(target * eased).toLocaleString('en-US') + suffix;
            if (t < 1) { requestAnimationFrame(step); }
        }
        requestAnimationFrame(step);
    }
    if (!reduce) {
        document.querySelectorAll('[data-count]').forEach(countUp);
    }

    var amt = document.getElementById('amt');
    if (amt) {
        var fmt = function (cents) {
            return 'KES ' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };
        var set = function (id, text) { document.getElementById(id).textContent = text; };
        var update = function () {
            var p = parseInt(amt.value, 10);
            var num = p * 18 * 30 * 100;
            var den = 36500;
            var interest = Math.floor((2 * num + den) / (2 * den));
            var fee = p * 5;
            var excise = p;
            var total = p * 100 + interest + fee;
            var due = new Date();
            due.setDate(due.getDate() + 30);

            amt.style.setProperty('--p', ((p - 1000) / 19000 * 100) + '%');
            set('amtOut', 'KES ' + p.toLocaleString('en-US'));
            set('rcv', fmt(p * 100));
            set('int', fmt(interest));
            set('fee', fmt(fee));
            set('exc', 'includes ' + fmt(excise) + ' excise duty');
            set('tot', fmt(total));
            set('due', due.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Africa/Nairobi' }));
        };
        amt.addEventListener('input', update);
        update();
    }
})();