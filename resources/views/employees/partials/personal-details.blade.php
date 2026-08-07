{{--
	Photo, personal details and the emergency contact (A3.7, A3.9).

	Shared by the create and edit forms. `$employee` is null when creating.

	Kept in a separate card below the employment details rather than mixed into
	them: this is the part HR fills in later, from a form the person hands back,
	and burying it among the fields needed to create the record at all would
	make the create screen twice as long for no gain.
--}}
@php $employee = $employee ?? null; @endphp

<div class="card mt-3">
  <div class="card-header"><h5 class="mb-0">Photo &amp; Personal Details</h5></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Profile Photo</label>
        <div class="d-flex align-items-center gap-3">
          @if($employee?->photo_url)
            <img src="{{ $employee->photo_url }}" class="rounded-circle" width="56" height="56"
                 style="object-fit:cover" alt="Current photo">
          @endif
          <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="form-text">JPG, PNG or WebP, up to 2&nbsp;MB. Leave empty to keep the current one.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Personal Email</label>
        <input type="email" name="personal_email" class="form-control"
               value="{{ old('personal_email', $employee?->personal_email) }}">
        <div class="form-text">Used when their work address no longer reaches them.</div>
      </div>
      <div class="col-md-2">
        <label class="form-label">National ID</label>
        <input type="text" name="national_id" class="form-control"
               value="{{ old('national_id', $employee?->national_id) }}">
      </div>
      <div class="col-md-2">
        <label class="form-label">Blood Group</label>
        <input type="text" name="blood_group" class="form-control" placeholder="e.g. O+"
               value="{{ old('blood_group', $employee?->blood_group) }}">
      </div>

      <div class="col-md-6">
        <label class="form-label">Address</label>
        <textarea name="address" rows="2" class="form-control">{{ old('address', $employee?->address) }}</textarea>
      </div>
      <div class="col-md-3">
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control" value="{{ old('city', $employee?->city) }}">
      </div>
      <div class="col-md-3">
        <label class="form-label">Country</label>
        <input type="text" name="country" class="form-control" value="{{ old('country', $employee?->country) }}">
      </div>
    </div>

    <hr class="my-4">

    <h6 class="mb-3"><i class="ti ti-urgent me-1"></i>Emergency Contact</h6>
    <p class="text-muted fs-13">
      Read on one day only, and on that day nobody has time to go looking for it.
    </p>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Name</label>
        <input type="text" name="emergency_contact_name" class="form-control"
               value="{{ old('emergency_contact_name', $employee?->emergency_contact_name) }}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Phone</label>
        <input type="text" name="emergency_contact_phone" class="form-control"
               value="{{ old('emergency_contact_phone', $employee?->emergency_contact_phone) }}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Relationship</label>
        <input type="text" name="emergency_contact_relation" class="form-control" placeholder="e.g. Spouse, Parent"
               value="{{ old('emergency_contact_relation', $employee?->emergency_contact_relation) }}">
      </div>
    </div>
  </div>
</div>
