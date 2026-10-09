<x-mail::message>
# {{ $reportTitle }}

{{ $subscription->company->name ?? __('notifications.scheduled_report.your_company') }} — {{ __('notifications.scheduled_report.period', ['from' => $periodFrom, 'to' => $periodTo]) }}
@if($subscription->office)
{{ __('notifications.scheduled_report.office', ['office' => $subscription->office->name]) }}
@endif

@if(count($tiles))
<x-mail::table>
| {{ __('notifications.scheduled_report.figure') }} | {{ __('notifications.scheduled_report.value') }} |
|:-------|------:|
@foreach($tiles as $tile)
| {{ $tile['label'] }} | {{ $tile['value'] }} |
@endforeach
</x-mail::table>
@endif

{{ __('notifications.scheduled_report.attached', ['file' => $filename]) }}

<x-mail::button :url="route('reports.'.$subscription->report_type, ['from' => $periodFrom, 'to' => $periodTo, 'office_id' => $subscription->office_id])">
{{ __('notifications.scheduled_report.action') }}
</x-mail::button>

<x-slot:subcopy>
{{ __('notifications.scheduled_report.why', ['frequency' => __('notifications.scheduled_report.frequency.'.$subscription->frequency)]) }}
</x-slot:subcopy>
</x-mail::message>
