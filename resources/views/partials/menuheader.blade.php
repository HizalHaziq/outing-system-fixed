<link rel="stylesheet" href="assets/app.css?v={!! filemtime(public_path('assets/app.css')) !!}">
<header id="m_header" class="m-grid__item m-header" m-minimize-offset="200" m-minimize-mobile-offset="200">
    <div class="m-container m-container--fluid m-container--full-height">
        <div class="m-stack m-stack--ver m-stack--desktop">
            <div class="m-stack__item m-brand m-brand--skin-dark">
                <button type="button" id="navToggle" class="nav-toggle" aria-label="Toggle menu" aria-controls="m_aside_left" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                <div class="m-stack m-stack--ver m-stack--general">
                    <div class="m-stack__item m-stack__item--middle m-brand__logo">
                        <a href="dashboard.php" class="m-brand__logo-wrapper">
                            <img alt="Outing System" src="images/logo_dark.png">
                        </a>
                    </div>
                </div>
            </div>
            <div class="m-stack__item m-stack__item--middle m-stack__item--right header-right">
                @include('partials.clock_widget')
                {!! profile_dropdown(
                    $account['name'] ?? '',
                    $account['staff_id'] ?? '',
                    $account['role'] ?? '',
                    $account['email'] ?? null,
                    [['href' => 'profile.php', 'label' => 'My profile']]
                ) !!}
            </div>
        </div>
    </div>
</header>
<script>
(function () {
    var btn = document.getElementById('navToggle');
    function setNav(open) {
        document.body.classList.toggle('nav-open', open);
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (btn) {
        btn.addEventListener('click', function () {
            setNav(!document.body.classList.contains('nav-open'));
        });
    }
    // Tap the dimmed area (body itself) or press Esc to close the mobile menu.
    document.addEventListener('click', function (e) {
        if (e.target === document.body) setNav(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setNav(false);
    });
})();
document.addEventListener('click', function (e) {
    document.querySelectorAll('.user-menu[open]').forEach(function (menu) {
        if (!menu.contains(e.target)) {
            menu.removeAttribute('open');
        }
    });
});
</script>
