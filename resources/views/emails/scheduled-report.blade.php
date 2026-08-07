@component('mail::message')
# {{ $reportTitle }}

{{ $subscription->company->name ?? 'Your company' }} — **{{ $periodFrom }}** to **{{ $periodTo }}**
@if($subscription->office)
Office: {{ $subscription->office->name }}
@endif

@if(count($tiles))
@component('mail::table')
| Figure | Value |
|:-------|------:|
@foreach($tiles as $tile)
| {{ $tile['label'] }} | {{ $tile['value'] }} |
@endforeach
@endcomponent
@endif

The full report is attached as **{{ $filename }}**.

@component('mail::button', ['url' => route('reports.'.$subscription->report_type, ['from' => $periodFrom, 'to' => $periodTo, 'office_id' => $subscription->office_id])])
Open in the dashboard
@endcomponent

@slot('subcopy')
You are receiving this because this address is on a scheduled report list
({{ $subscription->frequency_label }}). An administrator can change or stop it
under Reports → Scheduled Reports.
@endslot
@endcomponent
