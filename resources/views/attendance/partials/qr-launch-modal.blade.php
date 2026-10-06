{{--
	"Open QR screen" (A4.21): pick an office, open its check-in screen in a new
	tab. Shared by the dashboard and the QR Screens page; expects $qrLaunch from
	App\Support\QrLaunch::forCompany(). Opening reuses the office's screen, or
	makes one the first time — QrDisplayController@launch.
--}}
@php $launchOffices = $qrLaunch['offices']; @endphp
<div class="modal fade" id="qrLaunchModal" tabindex="-1" aria-labelledby="qrLaunchTitle" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" style="max-width:540px">
		<div class="modal-content">
			<form method="POST" action="{{ route('attendance.qr-displays.launch') }}" target="_blank" id="qrLaunchForm">
				@csrf
				<div class="modal-header border-0 pb-0 align-items-start">
					<div class="d-flex align-items-center gap-3">
						<span class="avatar avatar-lg avatar-rounded bg-primary-transparent text-primary flex-shrink-0">
							<i class="ti ti-qrcode fs-24"></i>
						</span>
						<div>
							<h5 class="modal-title mb-1" id="qrLaunchTitle">Open a QR check-in screen</h5>
							<p class="text-muted fs-13 mb-0">Pick the office, then open the screen on the device staff will scan.</p>
						</div>
					</div>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>

				<div class="modal-body">
					@if(! count($launchOffices))
						<div class="text-center py-4">
							<span class="avatar avatar-xl avatar-rounded bg-warning-transparent text-warning mb-3"><i class="ti ti-building-off fs-24"></i></span>
							<h6 class="mb-1">No active offices yet</h6>
							<p class="text-muted fs-13 mb-2">A check-in screen belongs to an office. An administrator adds offices first.</p>
							@can('manage-offices')
								<a href="{{ route('offices.index') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus me-1"></i>Add an office</a>
							@endcan
						</div>
					@else
						<label for="qrLaunchOffice" class="form-label fw-medium">Office</label>
						<select name="office_id" id="qrLaunchOffice" class="form-select mb-3" required>
							@foreach($launchOffices as $office)
								<option value="{{ $office['id'] }}"
								        data-url="{{ $office['url'] }}"
								        data-live="{{ $office['live'] ? '1' : '0' }}"
								        data-seen="{{ $office['seen'] }}">{{ $office['name'] }}</option>
							@endforeach
						</select>

						<div id="qrLaunchStatus" class="alert d-flex align-items-center gap-2 py-2 px-3 fs-13 mb-3" role="status" aria-live="polite">
							<i class="ti fs-18" data-status-icon></i><span data-status-text></span>
						</div>

						<div id="qrLaunchLinkWrap" class="mb-3">
							<label for="qrLaunchLink" class="form-label fw-medium mb-1">Screen link</label>
							<div class="input-group">
								<input type="text" id="qrLaunchLink" class="form-control fs-13" readonly aria-describedby="qrLaunchLinkHelp">
								<button type="button" class="btn btn-white border" id="qrLaunchCopy"><i class="ti ti-copy me-1"></i>Copy</button>
							</div>
							<div id="qrLaunchLinkHelp" class="form-text">The same link every time — bookmark it on the office tablet.</div>
						</div>

						@if($qrLaunch['localOnly'])
							<div class="alert alert-warning d-flex gap-2 py-2 px-3 fs-12 mb-3">
								<i class="ti ti-alert-triangle fs-16 flex-shrink-0"></i>
								<span>
									This panel is open on <strong>{{ request()->getHost() }}</strong>, so the link only works on this computer.
									To use a tablet, open the panel through this computer's network address or the live site.
								</span>
							</div>
						@endif

						<div class="bg-light rounded p-3">
							<p class="fw-medium fs-13 mb-2"><i class="ti ti-device-mobile me-1"></i>How staff use it</p>
							<ol class="fs-13 text-muted mb-0 ps-3" style="list-style: decimal">
								<li>Leave the screen open on a tablet, laptop or TV at the office.</li>
								<li>Staff open the app and tap <strong>Scan to check in</strong>.</li>
								<li>They point their phone at the code — it changes after every scan.</li>
							</ol>
						</div>
					@endif
				</div>

				<div class="modal-footer border-0 pt-0">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary" id="qrLaunchOpen" @disabled(! count($launchOffices))>
						<i class="ti ti-external-link me-1"></i>Open in new tab
					</button>
				</div>
			</form>
		</div>
	</div>
</div>

@push('scripts')
<script>
(function () {
	var modal = document.getElementById('qrLaunchModal');
	var select = document.getElementById('qrLaunchOffice');
	if (!modal || !select) {
		return;
	}

	var status = document.getElementById('qrLaunchStatus');
	var linkWrap = document.getElementById('qrLaunchLinkWrap');
	var link = document.getElementById('qrLaunchLink');
	var copy = document.getElementById('qrLaunchCopy');
	var copyLabel = copy.innerHTML;

	function show() {
		var option = select.options[select.selectedIndex];
		var url = option.getAttribute('data-url');
		var live = option.getAttribute('data-live') === '1';
		var seen = option.getAttribute('data-seen');
		var tone, icon, text;

		if (url && live) {
			tone = 'success'; icon = 'ti-broadcast';
			text = 'Showing codes now' + (seen ? ' · last seen ' + seen : '') + '.';
		} else if (url) {
			tone = 'warning'; icon = 'ti-device-desktop-off';
			text = 'Set up, but not open on any device' + (seen ? ' · last seen ' + seen : '') + '.';
		} else {
			tone = 'info'; icon = 'ti-sparkles';
			text = 'No screen yet — one will be created when you open it.';
		}

		status.className = 'alert alert-' + tone + ' d-flex align-items-center gap-2 py-2 px-3 fs-13 mb-3';
		status.querySelector('[data-status-icon]').className = 'ti ' + icon + ' fs-18';
		status.querySelector('[data-status-text]').textContent = text;

		linkWrap.classList.toggle('d-none', !url);
		link.value = url || '';
		copy.innerHTML = copyLabel;
	}

	copy.addEventListener('click', function () {
		var done = function () { copy.innerHTML = '<i class="ti ti-check me-1"></i>Copied'; };
		// The clipboard API needs a secure context; a panel opened on a
		// network address over plain http is not one, so fall back.
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(link.value).then(done);
		} else {
			link.select();
			document.execCommand('copy');
			done();
		}
	});

	select.addEventListener('change', show);
	modal.addEventListener('shown.bs.modal', function () { select.focus(); });

	// The first open creates the screen; reload behind the new tab so this
	// page's status and link are the real ones next time.
	document.getElementById('qrLaunchForm').addEventListener('submit', function () {
		setTimeout(function () { window.location.reload(); }, 1200);
	});

	show();
})();
</script>
@endpush
