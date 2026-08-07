{{-- The report switcher. Shared by the fixed reports and the builder so a new
     report is added in one place rather than two. The date and office filters
     travel with the click — switching report is almost never a request to go
     back to this month. --}}
<ul class="nav nav-pills mb-3">
	<li class="nav-item"><a class="nav-link {{ $type=='late' ? 'active' : '' }}" href="{{ route('reports.late', request()->only('from','to','office_id')) }}">Late Arrivals</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='outliers' ? 'active' : '' }}" href="{{ route('reports.outliers', request()->only('from','to','office_id')) }}">Outliers</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='overtime' ? 'active' : '' }}" href="{{ route('reports.overtime', request()->only('from','to','office_id')) }}">Overtime</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='payroll' ? 'active' : '' }}" href="{{ route('reports.payroll', request()->only('from','to','office_id')) }}">Payroll Hours</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='leave' ? 'active' : '' }}" href="{{ route('reports.leave', request()->only('from','to','office_id')) }}">Leave</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='weekly' ? 'active' : '' }}" href="{{ route('reports.weekly', request()->only('from','to','office_id')) }}">Weekly</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='department' ? 'active' : '' }}" href="{{ route('reports.department', request()->only('from','to','office_id')) }}">Department</a></li>
	<li class="nav-item"><a class="nav-link {{ $type=='custom' ? 'active' : '' }}" href="{{ route('reports.custom', request()->only('from','to','office_id')) }}"><i class="ti ti-adjustments me-1"></i>Build Your Own</a></li>
	<li class="nav-item"><a class="nav-link" href="{{ route('attendance.report', request()->only('from','to','office_id')) }}">Attendance Summary</a></li>
</ul>
