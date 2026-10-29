@php
    $loginUrl = \Illuminate\Support\Facades\Route::has('login') ? route('login') : url('/login');
    $registerUrl = \Illuminate\Support\Facades\Route::has('register') ? route('register') : url('/register');
    $v = fn (string $f) => asset('welcome/' . $f) . '?v=' . time();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Ethical Lending - Move forward. On your terms.</title>
    <link rel="stylesheet" href="{{ $v('welcome.css') }}">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
</head>
<body>

<div class="glow" id="glow"></div>

<div class="page">
    <header class="nav">
        <a href="{{ url('/') }}" class="brand">Ethical <span>Lending</span></a>
        <a href="{{ $loginUrl }}" class="nav-signin">Sign in</a>
    </header>

    <main class="hero">
        <div class="hero-text">
            <p class="eyebrow slide" style="--i:1">A more considered way to borrow</p>
            <h1>
                <span class="line slide" style="--i:2">Move forward.</span>
                <span class="line accent slide" style="--i:3">On your terms.</span>
            </h1>
            <p class="lead slide" style="--i:4">Every charge explained. Before you say yes.</p>
            <p class="copy slide" style="--i:5">
                Borrow a small amount for 30 days and see the fee, the excise duty and the
                interest laid out in plain figures before you accept. Money goes straight to
                your M-Pesa, and you repay the same way, whenever it suits you.
            </p>
            <div class="actions slide" style="--i:6">
                <a href="{{ $registerUrl }}" class="btn-gold">Get started</a>
                <a href="{{ $loginUrl }}" class="link">Sign in &rarr;</a>
            </div>
            <div class="stats slide" style="--i:7">
                <div><b>KES 1,000</b><span>Smallest loan</span></div>
                <div><b>KES 20,000</b><span>Largest loan</span></div>
                <div><b>30 days</b><span>To repay</span></div>
            </div>
            <div class="ticks slide" style="--i:8">
                <span>Consent first</span>
                <span>Fair collections</span>
                <span>No hidden fees</span>
            </div>
        </div>

        <div class="hero-stage" id="stageContainer" aria-hidden="true">
            <div class="stage-backdrop-glow"></div>
            <canvas id="webglCanvas"></canvas>
        </div>
    </main>

    <footer class="foot">
        <span>Demonstration project. Not a licensed lender.</span>
        <span>Built in Kenya, for Kenya.</span>
    </footer>
</div>

<script src="{{ $v('welcome.js') }}" defer></script>
</body>
</html>