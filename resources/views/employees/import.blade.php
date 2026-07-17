@extends('layouts.app')
@section('title','Bulk Import Employees')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Bulk Import Employees</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
      <li class="breadcrumb-item active">Bulk Import</li>
    </ol></nav></div>
  <div class="d-flex align-items-center flex-wrap">
    <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Back</a>
  </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="row">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Upload CSV File</h5></div>
      <div class="card-body">
        <div class="alert alert-info">
          <p class="mb-2"><i class="ti ti-info-circle me-1"></i>The CSV file must include the following header columns:</p>
          <p class="mb-2"><code>employee_code, first_name, last_name, email, phone</code> &mdash; <strong>employee_code</strong> is optional and will be auto-generated when left blank.</p>
          <pre class="mb-0 bg-light p-2 rounded">employee_code,first_name,last_name,email,phone
EMP001,John,Doe,john@example.com,1234567890
,Jane,Smith,jane@example.com,0987654321</pre>
        </div>
        <form action="{{ route('employees.import.store') }}" method="POST" enctype="multipart/form-data">
          @csrf
          <div class="mb-3">
            <label class="form-label">CSV File <span class="text-danger">*</span></label>
            <input type="file" name="file" class="form-control" accept=".csv" required>
          </div>
          <div class="text-end">
            <a href="{{ route('employees.index') }}" class="btn btn-light me-2">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="ti ti-upload me-1"></i>Import CSV</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
