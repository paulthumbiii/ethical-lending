{{-- resources/views/welcome.blade.php --}}
@php
    $loginUrl = \Illuminate\Support\Facades\Route::has('login') ? route('login') : url('/login');
    $registerUrl = \Illuminate\Support\Facades\Route::has('register') ? route('register') : url('/register');
    $v = fn (string $f) => asset('welcome/' . $f) . '?v=' . time();

    $signedIn = auth()->user();
    $homeUrl = $signedIn === null
        ? null
        : ($signedIn->hasRole(\App\Enums\UserRole::Borrower->value) ? route('borrower.dashboard') : route('lender.dashboard'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ethical Lending - Move forward. On your terms.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="{{ $v('landing.css') }}">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
</head>
<body>

<div class="glow" id="glow"></div>

<header class="site-nav" id="siteNav">
    <div class="wrap nav-inner">
        <a href="{{ url('/') }}" class="brand"><span class="mark" aria-hidden="true">EL</span> Ethical Lending</a>
        <button type="button" class="menu-btn" id="menuBtn" aria-label="Menu" aria-expanded="false" aria-controls="navLinks">
            <svg width="22" height="22" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
        </button>
        <nav class="nav-links" id="navLinks" aria-label="Main">
            <a href="#how">How it works</a>
            <a href="#calculator">Calculator</a>
            <a href="#fair">Fair collections</a>
            <a href="#faq">FAQ</a>
            @if ($signedIn)
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="nav-signin linkbtn">Sign out</button>
                </form>
                <a href="{{ $homeUrl }}" class="btn btn-primary btn-pill">Open dashboard</a>
            @else
                <a href="{{ $loginUrl }}" class="nav-signin">Sign in</a>
                <a href="{{ $registerUrl }}" class="btn btn-primary btn-pill">Get started</a>
            @endif
        </nav>
    </div>
</header>

<main>
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="hero-text">
                <p class="eyebrow rise" style="--i:1">A more considered way to borrow</p>
                <h1 class="rise" style="--i:2">Move forward.<br><span class="hl">On your terms.</span></h1>
                <p class="lead rise" style="--i:3">Every charge explained. Before you say yes.</p>
                <p class="copy rise" style="--i:4">
                    Borrow a small amount for 30 days and see the fee, the excise duty and the interest laid out in plain figures before you accept.
                    Money goes straight to your M-Pesa, and you repay the same way.
                </p>
                <div class="actions rise" style="--i:5">
                    @if ($signedIn)
                        <a href="{{ $homeUrl }}" class="btn btn-primary">Open your dashboard</a>
                    @else
                        <a href="{{ $registerUrl }}" class="btn btn-primary">Get started</a>
                    @endif
                    <a href="#calculator" class="btn btn-ghost">See what a loan costs</a>
                </div>
                <div class="stats rise" style="--i:6">
                    <div><b data-count="1000" data-prefix="KES ">KES 1,000</b><span>Smallest loan</span></div>
                    <div><b data-count="20000" data-prefix="KES ">KES 20,000</b><span>Largest loan</span></div>
                    <div><b data-count="30" data-suffix=" days">30 days</b><span>To repay</span></div>
                </div>
            </div>

            <div class="hero-stage" id="stageContainer" aria-hidden="true">
                <div class="stage-backdrop-glow"></div>
                <canvas id="webglCanvas"></canvas>
            </div>
        </div>
    </section>

    <section class="strip">
        <div class="wrap strip-inner">
            <span class="mono">Our promises</span>
            <ul>
                <li>Consent first</li>
                <li>Fair collections</li>
                <li>No hidden fees</li>
                <li>Charges capped by law after default</li>
            </ul>
        </div>
    </section>

    <section class="section" id="how">
        <div class="wrap">
            <p class="eyebrow reveal">How it works</p>
            <h2 class="reveal">Four steps, nothing hidden</h2>
            <div class="grid4">
                <article class="card reveal"><span class="num">01</span><h3>Apply</h3><p>Create an account, agree to how your data is used, and ask for an amount between KES 1,000 and KES 20,000.</p></article>
                <article class="card reveal"><span class="num">02</span><h3>Get checked</h3><p>A person reviews your ID and your M-Pesa statement. Two staff members must approve before money moves.</p></article>
                <article class="card reveal"><span class="num">03</span><h3>Receive money</h3><p>The full amount lands in your M-Pesa. Your contract shows the fee, excise duty and interest up front.</p></article>
                <article class="card reveal"><span class="num">04</span><h3>Repay your way</h3><p>Pay by M-Pesa whenever it suits you, in full or in part. Your balance and schedule update straight away.</p></article>
            </div>
        </div>
    </section>

    <section class="section alt" id="calculator">
        <div class="wrap calc">
            <div class="reveal">
                <p class="eyebrow">Live example</p>
                <h2>See the cost before you ask</h2>
                <p class="sub">Move the slider. Every figure below is what a 30-day Quick Cash loan would show you before you accept.</p>
            </div>
            <div class="calc-card reveal">
                <label for="amt" class="calc-label">How much do you need?</label>
                <div class="calc-amount" id="amtOut">KES 10,000</div>
                <input id="amt" type="range" min="1000" max="20000" step="500" value="10000">
                <div class="scale"><span>KES 1,000</span><span>KES 20,000</span></div>
                <dl class="rows" aria-live="polite">
                    <div><dt>You receive</dt><dd id="rcv">KES 10,000.00</dd></div>
                    <div><dt>Interest (18% a year, 30 days)</dt><dd id="int">KES 147.95</dd></div>
                    <div><dt>Processing fee <small id="exc">includes KES 100.00 excise duty</small></dt><dd id="fee">KES 500.00</dd></div>
                    <div class="total"><dt>Total to repay</dt><dd id="tot">KES 10,647.95</dd></div>
                    <div><dt>Pay by</dt><dd id="due">30 days after payout</dd></div>
                </dl>
                <p class="fine">Illustration only. Your actual offer is shown, in full, before you accept.</p>
            </div>
        </div>
    </section>

    <section class="section" id="fair">
        <div class="wrap">
            <p class="eyebrow reveal">If life gets in the way</p>
            <h2 class="reveal">What happens if you cannot pay</h2>
            <div class="grid3">
                <article class="card reveal"><h3>Tell us when you will pay</h3><p>Promise a date and an amount. We pause reminders until then. A promise never changes what you owe.</p></article>
                <article class="card reveal"><h3>Ask for relief</h3><p>If you are struggling, you can ask for more time. Penalties are removed, the processing fee stays, and you pay the rest in two parts.</p></article>
                <article class="card reveal"><h3>Fair limits, always</h3><p>Reminders only between 8am and 6pm, no more than one every 48 hours, never to your friends or family, and never shaming.</p></article>
            </div>
            <p class="note reveal">After default, interest and penalties together can never go above the amount you owed at that point. You see a live meter of that limit in your account.</p>
        </div>
    </section>

    <section class="section alt" id="faq">
        <div class="wrap narrow">
            <p class="eyebrow reveal">Questions</p>
            <h2 class="reveal">Good questions, plain answers</h2>
            <div class="faq reveal">
                <details><summary>Is this a real lender?</summary><p>No. This is a demonstration project and not a licensed lender. No real money moves.</p></details>
                <details><summary>When do I pay it back?</summary><p>30 days after the money is sent. You can pay by M-Pesa at any time before that, in full or in part.</p></details>
                <details><summary>What if I cannot pay on time?</summary><p>Tell us when you will pay, or ask for a relief plan from your account. Both are explained above.</p></details>
                <details><summary>How is my data used?</summary><p>We record your consent, with the time and your device, before we use your details or read your M-Pesa statement. Only the staff who need your documents can open them.</p></details>
                <details><summary>Will you contact my friends or family?</summary><p>No. We only contact you, and only within the allowed hours.</p></details>
            </div>
        </div>
    </section>
</main>

<footer class="foot">
    <div class="wrap foot-inner">
        <span>Demonstration project. Not a licensed lender.</span>
        <span>Built in Kenya, for Kenya.</span>
    </div>
</footer>

<script src="{{ $v('welcome.js') }}" defer></script>
<script src="{{ $v('landing.js') }}" defer></script>
</body>
</html>