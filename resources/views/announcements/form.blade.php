{{--
	One set of fields, shared by the new-announcement modal and every draft's
	edit modal. `$uid` keeps the radio and select ids unique on a page that can
	carry twenty of these at once — duplicate ids would make a label click
	activate the first draft's controls rather than the one on screen.
--}}
<div class="mb-3">
	<label class="form-label">Title <span class="text-danger">*</span></label>
	<input type="text" name="title" class="form-control" maxlength="150"
		   value="{{ old('title', $a->title ?? '') }}" placeholder="e.g. Depot closed Friday afternoon" required>
	<div class="form-text">This is the line that appears on a locked phone.</div>
</div>
<div class="mb-3">
	<label class="form-label">Message <span class="text-danger">*</span></label>
	<textarea name="body" class="form-control" rows="5" maxlength="5000" required>{{ old('body', $a->body ?? '') }}</textarea>
	<div class="form-text">Shown in full in the app. Plain text &mdash; formatting is not carried through.</div>
</div>
<div class="mb-2">
	<label class="form-label d-block">Who sees it <span class="text-danger">*</span></label>
	@foreach($audiences as $value => $label)
	<div class="form-check form-check-inline">
		<input class="form-check-input" type="radio" name="audience" value="{{ $value }}"
			   id="aud{{ $uid }}{{ $value }}" @checked(old('audience', $a->audience ?? 'all') === $value)>
		<label class="form-check-label" for="aud{{ $uid }}{{ $value }}">{{ $label }}</label>
	</div>
	@endforeach
</div>
<div class="row g-3">
	<div class="col-md-6">
		<label class="form-label text-muted small">Department <span class="fw-normal">(only used by "One department")</span></label>
		<select name="department_id" class="form-select">
			<option value="">&mdash; choose &mdash;</option>
			@foreach($departments as $d)
			<option value="{{ $d->id }}" @selected(old('department_id', $a->department_id ?? null) == $d->id)>{{ $d->name }}</option>
			@endforeach
		</select>
	</div>
	<div class="col-md-6">
		<label class="form-label text-muted small">Office <span class="fw-normal">(only used by "One office")</span></label>
		<select name="office_id" class="form-select">
			<option value="">&mdash; choose &mdash;</option>
			@foreach($offices as $o)
			<option value="{{ $o->id }}" @selected(old('office_id', $a->office_id ?? null) == $o->id)>{{ $o->name }}</option>
			@endforeach
		</select>
	</div>
</div>
