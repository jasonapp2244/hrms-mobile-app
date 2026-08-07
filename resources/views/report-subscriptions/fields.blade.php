{{-- Shared by the add and edit modals. $s is the subscription being edited, or
     null when creating; $uid keeps the checkbox ids unique across the modals on
     the page. --}}
<div class="row g-3">
  <div class="col-md-6">
    <label class="form-label">Report <span class="text-danger">*</span></label>
    <select name="report_type" class="form-select" required>
      @foreach(\App\Models\ReportSubscription::REPORTS as $key => $label)
        <option value="{{ $key }}" @selected(old('report_type', $s?->report_type) === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label">Frequency <span class="text-danger">*</span></label>
    <select name="frequency" class="form-select" required>
      @foreach(\App\Models\ReportSubscription::FREQUENCIES as $key => $label)
        <option value="{{ $key }}" @selected(old('frequency', $s?->frequency) === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label">Format <span class="text-danger">*</span></label>
    <select name="format" class="form-select" required>
      @foreach(\App\Models\ReportSubscription::FORMATS as $key => $label)
        <option value="{{ $key }}" @selected(old('format', $s?->format ?? 'pdf') === $key)>{{ $label }}</option>
      @endforeach
    </select>
    <div class="form-text">PDF to read; Excel to work on.</div>
  </div>
  <div class="col-md-6">
    <label class="form-label">Office</label>
    <select name="office_id" class="form-select">
      <option value="">All offices</option>
      @foreach($offices as $office)
        <option value="{{ $office->id }}" @selected((string) old('office_id', $s?->office_id) === (string) $office->id)>{{ $office->name }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-12">
    <label class="form-label">Recipients <span class="text-danger">*</span></label>
    <textarea name="recipients" rows="2" class="form-control"
              placeholder="finance@example.com, hr@example.com"
              required>{{ old('recipients', $s ? implode(', ', $s->recipients) : '') }}</textarea>
    <div class="form-text">Separate addresses with a comma, a semicolon or a space. They do not need an account here.</div>
  </div>
  <div class="col-12">
    {{-- Hidden partner so unchecking actually persists. --}}
    <div class="form-check">
      <input type="hidden" name="is_active" value="0">
      <input class="form-check-input" type="checkbox" name="is_active" value="1" id="subActive{{ $uid }}" @checked(old('is_active', $s?->is_active ?? true))>
      <label class="form-check-label" for="subActive{{ $uid }}">Active — send on this schedule</label>
    </div>
  </div>
</div>
