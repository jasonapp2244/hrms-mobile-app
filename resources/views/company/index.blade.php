@extends('layouts.app')
@section('title','Company Profile')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Company Profile</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item active">Company Profile</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap"></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="card">
  <div class="card-header">
    <h5 class="mb-0">Company Details</h5>
  </div>
  <div class="card-body">
    <form action="{{ route('company.update') }}" method="POST">
      @csrf
      @method('PUT')
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" value="{{ old('name', $company->name) }}" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="{{ old('email', $company->email) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" value="{{ old('phone', $company->phone) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">Website</label>
          <input type="text" name="website" class="form-control" value="{{ old('website', $company->website) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">City</label>
          <input type="text" name="city" class="form-control" value="{{ old('city', $company->city) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">Country</label>
          <input type="text" name="country" class="form-control" value="{{ old('country', $company->country) }}">
        </div>
        <div class="col-12">
          <label class="form-label">Address</label>
          <textarea name="address" class="form-control" rows="3">{{ old('address', $company->address) }}</textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">Timezone <span class="text-danger">*</span></label>
          <input type="text" name="timezone" class="form-control" value="{{ old('timezone', $company->timezone) }}" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Currency <span class="text-danger">*</span></label>
          @php $selectedCurrency = old('currency', $company->currency ?? 'USD'); @endphp
          <select name="currency" class="form-select" required>
            @foreach(['USD' => 'US Dollar ($)', 'EUR' => 'Euro (€)', 'GBP' => 'British Pound (£)', 'CAD' => 'Canadian Dollar (C$)', 'AUD' => 'Australian Dollar (A$)'] as $code => $label)
              <option value="{{ $code }}" @selected($selectedCurrency === $code)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="mt-4">
        <button type="submit" class="btn btn-primary">Save Company</button>
      </div>
    </form>
  </div>
</div>
@endsection
