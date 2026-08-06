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

{{-- Every problem in the file at once. One upload per mistake is a miserable
     way to load two hundred people. --}}
@if(session('import_errors'))
  <div class="alert alert-danger">
    <div class="fw-semibold mb-2"><i class="ti ti-alert-triangle me-1"></i>Fix these and upload again &mdash; nothing was imported.</div>
    <ul class="mb-0" style="max-height:340px;overflow-y:auto">
      @foreach(session('import_errors') as $e)<li>{{ $e }}</li>@endforeach
    </ul>
  </div>
@endif

<div class="row">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="mb-0">Upload CSV File</h5>
        <a href="{{ route('employees.import.template') }}" class="btn btn-sm btn-outline-primary">
          <i class="ti ti-download me-1"></i>Download template
        </a>
      </div>
      <div class="card-body">
        <div class="alert alert-info">
          <p class="mb-2"><i class="ti ti-info-circle me-1"></i>Accepted columns &mdash; only <strong>first_name</strong> and <strong>department</strong> are required:</p>
          <p class="mb-2"><code>employee_code, first_name, last_name, email, phone, gender, hire_date, office, department, designation, manager_code</code></p>
          <pre class="mb-2 bg-light p-2 rounded">employee_code,first_name,last_name,email,phone,gender,hire_date,office,department,designation,manager_code
EMP-0101,Jane,Doe,jane@example.com,+1 212 555 0142,female,2024-03-01,Head Office,Engineering,Software Engineer,
,John,Smith,john@example.com,,male,2025-11-17,Head Office,Engineering,,EMP-0101</pre>
          <ul class="mb-0 small">
            <li><strong>employee_code</strong> is generated when blank. <strong>manager_code</strong> refers to one &mdash; the manager may appear anywhere in the file.</li>
            <li><strong>office</strong>, <strong>department</strong> and <strong>designation</strong> are matched by name against what this company has. An unrecognised name is reported, not ignored.</li>
            <li><strong>department</strong> is required because it carries the shift. Without one there is no start time to be late against, so that person's attendance is never judged &mdash; and nothing on screen would tell you.</li>
            <li>The whole file is checked before anything is written. If any row is wrong, nothing is imported.</li>
          </ul>
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
