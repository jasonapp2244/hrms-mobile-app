{{-- Summary tiles and the result table. Shared by every report, which is what
     lets the builder produce a report that looks like the fixed ones rather
     than like a spreadsheet dump. --}}
<p class="text-muted mb-3">{{ $subtitle }}</p>

<div class="row">
	@foreach($tiles as $t)
	<div class="col-md-3 col-6 mb-3">
		<div class="card"><div class="card-body text-center">
			<h3 class="mb-0">{{ $t['value'] }}</h3>
			<p class="text-muted mb-0 fs-13">{{ $t['label'] }}</p>
		</div></div>
	</div>
	@endforeach
</div>

<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>@foreach($headings as $h)<th>{{ $h }}</th>@endforeach</tr>
				</thead>
				<tbody>
					@forelse($rows as $row)
					<tr>
						@foreach($headings as $h)
							@php $val = $row[$h]; @endphp
							<td>
								@if($h === 'On-time %')
									{{-- "—" means nobody clocked in at all, which is not 0%. --}}
									@if($val === '—')
										<span class="text-muted">—</span>
									@else
										<span class="badge bg-{{ (float)$val < 70 ? 'danger' : ((float)$val < 90 ? 'warning' : 'success') }}-transparent">{{ $val }}</span>
									@endif
								@elseif($h === 'Late %' || $h === 'Late Count')
									<span class="badge bg-warning-transparent">{{ $val }}</span>
								@elseif($h === 'Flag')
									<span class="badge bg-danger-transparent">{{ $val }}</span>
								@else
									{{ $val }}
								@endif
							</td>
						@endforeach
					</tr>
					@empty
					<tr><td colspan="{{ count($headings) }}" class="text-center text-muted py-4">{{ $emptyMessage ?? 'No data for the selected filters — everyone is on track. 🎉' }}</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
</div>
