@extends('layouts.app')
@section('title','Weekly Roster')

@push('styles')
<style>
  /* A5.8 — the planner's drag-and-drop. Colours are set inline per shift, the
     same tint the scheduled chip in the grid uses. */
  .roster-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border: 1px solid transparent; border-radius: 999px;
    font-size: 11px; line-height: 1.5; font-weight: 600;
    cursor: grab; user-select: none; white-space: nowrap;
  }
  .roster-chip:active { cursor: grabbing; }
  .roster-chip--clear { background: #f3f4f6; border-color: #d5d8dd; color: #566069; }
  .roster-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }

  /* The drop target. Dashed rather than filled: it has to read as "this is
     where it would land" without hiding what is already in the cell. */
  .roster-cell.is-over { outline: 2px dashed #033C93; outline-offset: -2px; }
  .roster-cell.is-source { opacity: .45; }

  /* Changed since the page loaded and not saved yet. The Draft badge means
     something else — planned but not published — so this cannot reuse it. */
  .roster-cell.is-dirty { box-shadow: inset 3px 0 0 #7E5709; }
</style>
@endpush

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Weekly Roster</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('shifts.index') }}">Shifts &amp; Schedule</a></li>
      <li class="breadcrumb-item active">Weekly Roster</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap gap-2">
    @if($planning)
      <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#rotationModal">
        <i class="ti ti-repeat me-1"></i>Generate Rotation
      </button>
      @php
        // With nothing planned there is nothing to withdraw either, so the
        // button stays on "Publish" and is simply disabled.
        $canPublish = $unpublishedCount > 0 || $plannedCount === 0;
      @endphp
      <form action="{{ route('shifts.roster.publish') }}" method="POST" class="d-inline">
        @csrf
        <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
        <input type="hidden" name="action" value="{{ $canPublish ? 'publish' : 'unpublish' }}">
        <button type="submit" class="btn btn-{{ $canPublish ? 'success' : 'outline-warning' }}"
                @disabled($plannedCount === 0)>
          <i class="ti ti-{{ $canPublish ? 'send' : 'eye-off' }} me-1"></i>
          {{ $canPublish ? 'Publish Week' : 'Withdraw Week' }}
        </button>
      </form>
      <a href="{{ route('shifts.roster', ['week' => $weekStart->toDateString()]) }}" class="btn btn-light">Done</a>
    @else
      <a href="{{ route('shifts.roster', ['week' => $weekStart->toDateString(), 'plan' => 1]) }}" class="btn btn-primary">
        <i class="ti ti-edit me-1"></i>Plan Week
      </a>
      <a href="{{ route('shifts.index') }}" class="btn btn-outline-primary"><i class="ti ti-clock-hour-4 me-1"></i>Manage Shifts</a>
    @endif
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

@if($planning)
  <div class="alert alert-info">
    <i class="ti ti-info-circle me-1"></i>
    Set anyone's shift for a specific day. <strong>Follow standing shift</strong> leaves the day to
    their own shift or their department's, which is what applies where nothing is planned.
    @if($plannedCount > 0)
      <div class="mt-1">
        {{ $plannedCount }} day(s) planned this week
        @if($unpublishedCount > 0)
          — <strong>{{ $unpublishedCount }} not yet visible to staff.</strong>
        @else
          — all published.
        @endif
      </div>
    @endif
  </div>
@endif

{{-- Week navigation --}}
<div class="card mb-3">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
    <a href="{{ route('shifts.roster', ['week' => $weekStart->copy()->subWeek()->toDateString()]) }}" class="btn btn-sm btn-outline-secondary"><i class="ti ti-chevron-left"></i> Prev</a>
    <div class="text-center">
      <h5 class="mb-0">{{ $weekStart->format('M j') }} – {{ $weekEnd->format('M j, Y') }}</h5>
      <a href="{{ route('shifts.roster') }}" class="small text-decoration-none">This week</a>
    </div>
    <a href="{{ route('shifts.roster', ['week' => $weekStart->copy()->addWeek()->toDateString()]) }}" class="btn btn-sm btn-outline-secondary">Next <i class="ti ti-chevron-right"></i></a>
  </div>
</div>

@if($planning)
  {{--
    A5.8 — the drag-and-drop half of the planner.

    Hidden until the script runs, and unhidden by it: an instruction to drag
    something is worse than no instruction at all on a browser that cannot.
    Nothing here is the only way to do anything — every cell keeps its select,
    so the planner stays fully usable by keyboard, by screen reader, and on a
    touch device, where HTML5 drag events do not fire at all.
  --}}
  <div class="card mb-3" id="rosterPalette" hidden>
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
      <span class="text-muted small me-1"><i class="ti ti-drag-drop me-1"></i>Drag onto a day:</span>
      @foreach($shifts as $s)
        {{-- Same tint the scheduled chip in the grid below uses, so a shift
             looks like itself whichever half of the screen it is on. --}}
        <span class="roster-chip" draggable="true" data-value="{{ $s->id }}"
              style="background:{{ $s->color }}1a;border-color:{{ $s->color }}55"
              title="{{ $s->name }} {{ $s->timing }}">
          <span class="roster-dot" style="background:{{ $s->color }}"></span>{{ $s->code ?? $s->name }}
        </span>
      @endforeach
      <span class="roster-chip" draggable="true" data-value="off"
            style="background:#6c757d1a;border-color:#6c757d55">
        <span class="roster-dot" style="background:#6c757d"></span>Day off
      </span>
      <span class="roster-chip roster-chip--clear" draggable="true" data-value=""
            title="Leave the day to their own shift or their department's">
        <i class="ti ti-eraser"></i>Clear
      </span>
      <span class="text-muted ms-auto" style="font-size:11px">
        Dragging a day onto another <strong>moves</strong> it. Nothing is saved until you press Save Roster.
      </span>
    </div>
  </div>
@endif

{{-- Legend --}}
<div class="d-flex flex-wrap gap-3 mb-3 small text-muted">
  <span><span class="badge bg-success">&nbsp;</span> Present</span>
  <span><span class="badge bg-warning">&nbsp;</span> Late</span>
  <span><span class="badge bg-danger">&nbsp;</span> Absent</span>
  <span><span class="badge bg-secondary-transparent text-secondary">&nbsp;</span> Scheduled</span>
  <span><span class="badge bg-info">&nbsp;</span> On Leave</span>
  <span><span class="badge bg-primary-transparent text-primary">&nbsp;</span> Holiday</span>
  <span><span class="badge bg-light text-dark">Off</span> Non-working day</span>
</div>

<form action="{{ route('shifts.roster.save') }}" method="POST">
@csrf
<input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-bordered mb-0 align-middle text-center" style="min-width:900px">
        <thead>
          <tr>
            <th class="text-start" style="min-width:190px">Employee</th>
            @foreach($days as $day)
              <th class="{{ $day->isSameDay($today) ? 'table-active' : '' }}">
                <div class="fw-semibold">{{ $day->format('D') }}</div>
                <small class="text-muted">{{ $day->format('m/d') }}</small>
              </th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @forelse($employees as $emp)
          <tr>
            <td class="text-start">
              <div class="fw-semibold">{{ $emp->full_name }}</div>
              <small class="text-muted">
                {{ $emp->department->name ?? '—' }}
                @if($emp->hasShiftOverride())
                  <span class="badge bg-info-transparent text-info ms-1" style="font-size:9px"
                        title="On their own shift, not the department's">own shift</span>
                @endif
              </small>
            </td>
            @foreach($days as $day)
              @php
                $dateStr = $day->toDateString();
                $assignment = $plan[$emp->id][$dateStr] ?? null;
                // A planned day wins; otherwise the standing shift applies.
                // A rostered day off resolves to no shift at all.
                $shift   = $assignment
                             ? ($assignment->is_day_off ? null : $assignment->shift)
                             : $emp->shift;
                $status  = $attendance[$emp->id][$dateStr] ?? null;
                $isPast  = $day->lt($today);
                $isToday = $day->isSameDay($today);
                $isWeekend = in_array($day->dayOfWeek, $weekend, true);
                $isHoliday = $holidays->has($dateStr);
                $isOnLeave = ($onLeave[$emp->id] ?? collect())->has($dateStr);
                $rosteredOff = $assignment?->is_day_off;
              @endphp
              <td class="{{ $isToday ? 'table-active' : '' }} {{ $planning ? 'roster-cell' : '' }}" style="min-width:100px"
                  @if($planning) data-roster-cell title="{{ $emp->full_name }} — {{ $day->format('D j M') }}" @endif>
                @if($planning)
                  {{-- Filled in by the script from the select below, so with no
                       script there is simply nothing here and the select is the
                       whole control, exactly as it was. --}}
                  <div data-chip></div>
                  <select name="roster[{{ $emp->id }}][{{ $dateStr }}]" class="form-select form-select-sm mb-1 roster-select" style="font-size:11px">
                    <option value="">Follow standing shift</option>
                    <option value="off" data-color="#6c757d" @selected($rosteredOff)>Day off</option>
                    @foreach($shifts as $s)
                      <option value="{{ $s->id }}" data-color="{{ $s->color }}" data-short="{{ $s->code ?? $s->name }}"
                              @selected($assignment && ! $assignment->is_day_off && $assignment->shift_id === $s->id)>
                        {{ $s->code ?? $s->name }} {{ $s->timing }}
                      </option>
                    @endforeach
                  </select>
                  @if($assignment && ! $assignment->isPublished())
                    <span class="badge bg-warning-transparent text-warning" style="font-size:9px">Draft</span>
                  @endif
                @elseif($rosteredOff)
                  <span class="badge bg-light text-dark">Rostered off</span>
                @elseif($isWeekend)
                  <span class="badge bg-light text-dark">Off</span>
                @elseif($isHoliday)
                  <span class="badge bg-primary-transparent text-primary">Holiday</span>
                @elseif($isOnLeave)
                  {{-- Booked and approved: not absent, whatever the punches say. --}}
                  <span class="badge bg-info">On Leave</span>
                @elseif(! $shift)
                  <span class="text-muted">—</span>
                @else
                  {{-- Scheduled shift chip --}}
                  <div class="d-inline-flex align-items-center px-2 py-1 rounded mb-1"
                       style="background:{{ $shift->color }}1a;border:1px solid {{ $shift->color }}55;font-size:11px;line-height:1.2">
                    <span class="d-inline-block me-1" style="width:8px;height:8px;border-radius:50%;background:{{ $shift->color }}"></span>
                    <span class="fw-semibold">{{ $shift->code ?? $shift->name }}</span>
                  </div>
                  <div class="text-muted" style="font-size:10px">
                    {{ $shift->timing }}@if($shift->crossesMidnight())<span title="Ends the next morning">+1</span>@endif
                  </div>
                  {{-- Attendance status --}}
                  <div class="mt-1">
                    @if($status === 'ontime')
                      <span class="badge bg-success" style="font-size:10px">Present</span>
                    @elseif($status === 'late')
                      <span class="badge bg-warning" style="font-size:10px">Late</span>
                    @elseif($isPast)
                      <span class="badge bg-danger" style="font-size:10px">Absent</span>
                    @else
                      <span class="badge bg-secondary-transparent text-secondary" style="font-size:10px">Scheduled</span>
                    @endif
                  </div>
                @endif
              </td>
            @endforeach
          </tr>
          @empty
          <tr><td colspan="8" class="text-muted py-4">No active employees to schedule.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  @if($planning)
    <div class="card-footer d-flex justify-content-end gap-2">
      <a href="{{ route('shifts.roster', ['week' => $weekStart->toDateString()]) }}" class="btn btn-light">Cancel</a>
      <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save Roster</button>
    </div>
  @endif
</div>
</form>

@if($planning)
{{-- Rotation generator --}}
<div class="modal fade" id="rotationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form action="{{ route('shifts.roster.rotation') }}" method="POST">
        @csrf
        <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
        <div class="modal-header">
          <h5 class="modal-title">Generate Rotation</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted">
            The pattern is applied to consecutive days from the start date and then repeats.
            Its <strong>length</strong> is what makes it rotate: seven entries repeat on the same
            weekdays forever, while four entries walk around the week — which is what
            "four on, four off" actually means.
          </p>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Start date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" class="form-control" value="{{ $weekStart->toDateString() }}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Repeat for <span class="text-danger">*</span></label>
              <select name="weeks" class="form-select">
                @foreach(range(1, 12) as $w)
                  <option value="{{ $w }}" @selected($w === 4)>{{ $w }} week{{ $w > 1 ? 's' : '' }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <label class="form-label">Pattern <span class="text-danger">*</span></label>
          <div class="row g-2 mb-1">
            @foreach(range(1, 7) as $slot)
              <div class="col">
                <div class="text-muted text-center" style="font-size:11px">Day {{ $slot }}</div>
                <select name="cycle[]" class="form-select form-select-sm" style="font-size:11px">
                  <option value="">—</option>
                  <option value="off">Off</option>
                  @foreach($shifts as $s)
                    <option value="{{ $s->id }}">{{ $s->code ?? $s->name }}</option>
                  @endforeach
                </select>
              </div>
            @endforeach
          </div>
          <div class="form-text mb-3">Leave the trailing days as “—” to shorten the cycle.</div>

          <label class="form-label">Employees <span class="text-danger">*</span></label>
          <div class="border rounded p-2" style="max-height:220px;overflow-y:auto">
            @foreach($employees as $emp)
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="employee_ids[]" value="{{ $emp->id }}" id="rot{{ $emp->id }}">
                <label class="form-check-label" for="rot{{ $emp->id }}">
                  {{ $emp->full_name }} <span class="text-muted small">{{ $emp->department->name ?? '—' }}</span>
                </label>
              </div>
            @endforeach
          </div>

          <div class="alert alert-warning mt-3 mb-0 py-2 small">
            <i class="ti ti-alert-triangle me-1"></i>
            Any existing plan for these people inside the generated period is replaced.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Generate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/**
 * A5.8 — drag a shift onto a day.
 *
 * Everything here writes into the selects that were already on the page and
 * posts through the form that was already there. No new endpoint, no new
 * validation surface, and no second idea of what the roster says: the select
 * is the value, the chip is a picture of it.
 *
 * That is also what makes this safe to bolt on. Turn the script off and the
 * planner is exactly what it was — which matters more than usual here, because
 * HTML5 drag events do not fire on a touch screen at all, and a planner that
 * only worked with a mouse would have quietly excluded anybody on a tablet.
 */
(function () {
  var palette = document.getElementById('rosterPalette');
  var cells = Array.prototype.slice.call(document.querySelectorAll('[data-roster-cell]'));

  if (!palette || !cells.length) return;

  // Nothing above this line touched the page. The instruction to drag only
  // appears once there is something able to honour it.
  palette.hidden = false;

  // Same-document drag, so the payload is held here rather than in
  // dataTransfer: Firefox will not let you read dataTransfer during dragover,
  // which is exactly when the drop target has to decide whether to accept.
  var carried = null;

  function selectIn(cell) { return cell.querySelector('.roster-select'); }

  /** Redraw a cell's chip from whatever its select currently says. */
  function paint(cell) {
    var select = selectIn(cell);
    var box = cell.querySelector('[data-chip]');
    if (!select || !box) return;

    var option = select.options[select.selectedIndex];
    box.innerHTML = '';

    // "Follow standing shift" is the absence of a plan, and drawing a chip for
    // it would make an unplanned day look planned.
    if (!option || option.value === '') return;

    var color = option.getAttribute('data-color') || '#6c757d';
    var chip = document.createElement('span');

    chip.className = 'roster-chip mb-1';
    chip.setAttribute('draggable', 'true');
    chip.style.background = color + '1a';
    chip.style.borderColor = color + '55';

    var dot = document.createElement('span');
    dot.className = 'roster-dot';
    dot.style.background = color;
    chip.appendChild(dot);
    chip.appendChild(document.createTextNode(
      option.getAttribute('data-short') || option.text
    ));

    box.appendChild(chip);
  }

  function apply(cell, value) {
    var select = selectIn(cell);
    if (!select) return;

    select.value = value;
    // Dispatched rather than assumed: anything else listening to this form —
    // now or later — hears a drag exactly as it hears a click.
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function markDirty(cell) { cell.classList.add('is-dirty'); }

  function clearHighlights() {
    cells.forEach(function (cell) {
      cell.classList.remove('is-over', 'is-source');
    });
  }

  // ---- dragging out of the palette -------------------------------------

  palette.addEventListener('dragstart', function (event) {
    var chip = event.target.closest('.roster-chip');
    if (!chip) return;

    carried = { value: chip.getAttribute('data-value'), from: null };
    event.dataTransfer.effectAllowed = 'copy';
    // Set for form's sake: some browsers refuse to start a drag with no data.
    event.dataTransfer.setData('text/plain', carried.value);
  });

  // ---- dragging a day onto another day ---------------------------------

  cells.forEach(function (cell) {
    paint(cell);

    // A manual change through the select repaints the chip too, so the two can
    // never disagree about what the day says.
    cell.addEventListener('change', function () {
      paint(cell);
      markDirty(cell);
    });

    cell.addEventListener('dragstart', function (event) {
      var chip = event.target.closest('.roster-chip');
      if (!chip) return;

      carried = { value: selectIn(cell).value, from: cell };
      cell.classList.add('is-source');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', carried.value);
    });

    cell.addEventListener('dragover', function (event) {
      if (!carried || carried.from === cell) return;

      // Without this the browser refuses the drop, silently.
      event.preventDefault();
      event.dataTransfer.dropEffect = carried.from ? 'move' : 'copy';
      cell.classList.add('is-over');
    });

    cell.addEventListener('dragleave', function () {
      cell.classList.remove('is-over');
    });

    cell.addEventListener('drop', function (event) {
      if (!carried || carried.from === cell) return;

      event.preventDefault();

      // Cleared here and not left to dragend, because the drop below repaints
      // the source cell and destroys the chip the drag started from — and a
      // `dragend` on a detached node never reaches the listener on document.
      // Without this the cell somebody just moved a shift *out of* stays at
      // 45% opacity for the rest of the session, looking disabled.
      clearHighlights();

      // Repainting and marking dirty is the change handler's job — apply()
      // dispatches one, so doing it again here would be a second place that
      // has to remember.
      apply(cell, carried.value);

      // Moved, not copied: a day dragged onto another leaves where it was.
      // Copying instead would silently double a shift every time somebody
      // fixed a mistake, which is the more expensive way to be wrong.
      if (carried.from) apply(carried.from, '');

      carried = null;
    });
  });

  // The net for a drag that ends somewhere other than a cell — dropped on the
  // page, or abandoned with Escape. A drag that *did* land has already tidied
  // up in the drop handler, for the reason noted there.
  document.addEventListener('dragend', function () {
    carried = null;
    clearHighlights();
  });

  // Leaving with changes that were never posted loses them, and the Draft
  // badges on screen make it look as though something was kept.
  window.addEventListener('beforeunload', function (event) {
    if (!document.querySelector('.roster-cell.is-dirty')) return;
    event.preventDefault();
    event.returnValue = '';
  });

  // The planner's own form, found through a cell rather than by its action:
  // three forms on this page post to a /shifts/roster* URL, and matching on
  // the path would eventually pick the wrong one.
  var form = cells[0].closest('form');

  if (form) {
    form.addEventListener('submit', function () {
      cells.forEach(function (cell) { cell.classList.remove('is-dirty'); });
    });
  }
}());
</script>
@endif
@endsection
