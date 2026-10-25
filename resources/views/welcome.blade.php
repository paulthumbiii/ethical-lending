{{-- Phase 11.5 | resources/views/welcome.blade.php
     Public landing page. Replaces Laravel's stock page, whose "Dashboard" link pointed to a route we don't have. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ethical Lending</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f7f7f2; color: #1c2b22; margin: 0; }
        main { max-width: 32rem; margin: 0 auto; padding: 4rem 1.5rem; }
        h1 { font-size: 1.75rem; margin: 0 0 .5rem; color: #14532d; }
        p { line-height: 1.5; color: #4b5b52; }
        .row { display: flex; gap: .75rem; margin-top: 1.5rem; flex-wrap: wrap; }
        a.btn { padding: .65rem 1.1rem; border-radius: .4rem; border: 1px solid #166534; text-decoration: none; font-weight: 600; }
        a.primary { background: #166534; color: #fff; }
        a.ghost { color: #166534; background: #fff; }
    </style>
</head>
<body>
<main>
    <h1>Ethical Lending</h1>
    <p>Lending that shows its working. Every charge is explained, and you can always see your protections.</p>

    <div class="row">
        @auth
            @php
                $home = auth()->user()->hasRole('borrower') ? route('borrower.dashboard') : route('lender.dashboard');
            @endphp
            <a class="btn primary" href="{{ $home }}">Go to my dashboard</a>
        @else
            <a class="btn primary" href="{{ route('login') }}">Log in</a>
            <a class="btn ghost" href="{{ route('register') }}">Create an account</a>
        @endauth
    </div>
</main>
</body>
</html>