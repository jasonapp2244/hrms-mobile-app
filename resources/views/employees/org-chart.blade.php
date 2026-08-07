@extends('layouts.app')
@section('title','Org Chart')

@php
	/**
	 * Drawn as nested lists rather than boxes and connector lines. A real org
	 * chart is unreadable past about thirty people on a laptop screen and
	 * unusable on a phone; an indented tree stays legible at any size, collapses
	 * naturally, and prints.
	 */
@endphp

@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">Org Chart</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
      <li class="breadcrumb-item active">Org Chart</li>
    </ol></nav></div>
  <div>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="ti ti-printer me-1"></i>Print</button>
  </div>
</div>

<div class="alert alert-info">
  <i class="ti ti-hierarchy-2 me-1"></i>
  Active staff, arranged by reporting line. Anybody without a manager — or whose
  manager has left — appears at the top level, because everyone has to show up somewhere.
  Set the reporting line on each employee's record.
</div>

<div class="card">
  <div class="card-body">
    @if($roots->isEmpty())
      <p class="text-muted text-center py-4 mb-0">No active employees yet.</p>
    @else
      <ul class="list-unstyled mb-0">
        @foreach($roots as $root)
          @include('employees.partials.org-node', ['person' => $root, 'byManager' => $byManager, 'depth' => 0])
        @endforeach
      </ul>
    @endif
  </div>
</div>

<p class="text-muted fs-13 mt-2">
  {{ $employees->count() }} active employee(s) · {{ $roots->count() }} at the top level
</p>
@endsection
