<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>@yield('title') — {{ config('app.name') }}</title>

	{{-- Deliberately standalone rather than extending the dashboard layout.
	     These pages are read by Google's reviewers and by staff who are not
	     signed in, so they must render without the app's session, sidebar or
	     permission checks. --}}
	<style>
		:root {
			--brand: #F26522;
			--ink: #1A1815;
			--muted: #6F6A63;
			--rule: #E3E0DB;
			--paper: #FFFFFF;
		}
		@media (prefers-color-scheme: dark) {
			:root { --ink:#EDEAE5; --muted:#9A938A; --rule:#302C29; --paper:#151312; }
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			background: var(--paper);
			color: var(--ink);
			font: 16px/1.65 "Segoe UI", system-ui, -apple-system, Arial, sans-serif;
		}
		.wrap { max-width: 46rem; margin: 0 auto; padding: 3rem 1.25rem 5rem; }
		.mark {
			display: inline-flex; align-items: center; justify-content: center;
			width: 44px; height: 44px; border-radius: 10px;
			background: var(--brand); color: #fff; font-weight: 800; font-size: 17px;
			margin-bottom: 1.5rem;
		}
		h1 { font-size: 1.9rem; line-height: 1.2; margin: 0 0 .5rem; letter-spacing: -.02em; }
		h2 { font-size: 1.15rem; margin: 2.25rem 0 .5rem; letter-spacing: -.01em; }
		h3 { font-size: 1rem; margin: 1.5rem 0 .35rem; }
		p, li { color: var(--ink); }
		.lede { color: var(--muted); font-size: 1.05rem; }
		.updated { color: var(--muted); font-size: .9rem; }
		ul { padding-left: 1.15rem; }
		li { margin: .3rem 0; }
		table { border-collapse: collapse; width: 100%; margin: 1rem 0; font-size: .95rem; }
		th, td { text-align: left; padding: .55rem .7rem; border-bottom: 1px solid var(--rule); vertical-align: top; }
		th { font-size: .78rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
		.note {
			border: 1px solid var(--rule); border-left: 3px solid var(--brand);
			border-radius: 4px; padding: 1rem 1.15rem; margin: 1.5rem 0;
		}
		a { color: var(--brand); }
		footer { margin-top: 3rem; padding-top: 1.25rem; border-top: 1px solid var(--rule); color: var(--muted); font-size: .9rem; }
		.scroll { overflow-x: auto; }
	</style>
</head>
<body>
	<div class="wrap">
		<div class="mark">HR</div>
		@yield('content')

		<footer>
			{{ $company?->name ?? config('app.name') }} ·
			<a href="{{ route('legal.privacy') }}">Privacy</a> ·
			<a href="{{ route('legal.deletion') }}">Deleting your data</a>
		</footer>
	</div>
</body>
</html>
