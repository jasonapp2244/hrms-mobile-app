{{-- Shared by the add and edit modals. $step is null when adding; $uid keeps
     checkbox ids unique across the several modals on the page. --}}
<div class="row g-3">
  <div class="col-md-6">
    <label class="form-label">List <span class="text-danger">*</span></label>
    <select name="kind" class="form-select" required>
      @foreach(\App\Models\ChecklistTemplate::KINDS as $key => $label)
        <option value="{{ $key }}" @selected(old('kind', $step?->kind) === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label">Owner</label>
    <input type="text" name="owner" class="form-control" placeholder="e.g. IT, Line manager"
           value="{{ old('owner', $step?->owner) }}">
    <div class="form-text">A role, not a person — the person changes more often than the task.</div>
  </div>
  <div class="col-12">
    <label class="form-label">Step <span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" required
           placeholder="e.g. Issue laptop and building pass"
           value="{{ old('title', $step?->title) }}">
  </div>
  <div class="col-12">
    <label class="form-label">Detail</label>
    <textarea name="description" rows="2" class="form-control">{{ old('description', $step?->description) }}</textarea>
  </div>
  <div class="col-md-6">
    <label class="form-label">Due <span class="text-danger">*</span></label>
    <div class="input-group">
      <input type="number" name="due_offset_days" class="form-control" min="-365" max="365"
             value="{{ old('due_offset_days', $step?->due_offset_days ?? 0) }}">
      <span class="input-group-text">days</span>
    </div>
    <div class="form-text">
      Negative is <strong>before</strong> the date, which is where most of joining actually
      happens. 0 is on the day.
    </div>
  </div>
  <div class="col-md-6 d-flex align-items-end">
    <div class="form-check mb-2">
      <input type="hidden" name="is_active" value="0">
      <input class="form-check-input" type="checkbox" name="is_active" value="1"
             id="stepActive{{ $uid }}" @checked(old('is_active', $step?->is_active ?? true))>
      <label class="form-check-label" for="stepActive{{ $uid }}">
        Active — include when raising new checklists
      </label>
    </div>
  </div>
</div>
