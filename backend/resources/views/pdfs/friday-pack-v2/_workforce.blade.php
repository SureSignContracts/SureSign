{{--
    Friday Pack Realignment, R1G.1-VFIX3 — Workforce on Site. ONE primary
    construction table (superseding VFIX2's separate weekday-totals band
    + trade table). $content = ['days' => [...], 'rows' => [...],
    'has_trade_breakdown' => bool, 'person_days_total' => int,
    'has_conflicts' => bool]
--}}
<table class="data-table workforce-table">
    <tr>
        <th>Trade / Role</th>
        @foreach($content['days'] as $day)
            <th class="amount">{{ strtoupper(substr($day['day'], 0, 3)) }}<span class="day-date">{{ $day['date'] }}</span></th>
        @endforeach
        <th class="amount">Person-Days</th>
    </tr>

    @if($content['has_trade_breakdown'])
        @foreach($content['rows'] as $row)
            <tr>
                <td>{{ $row['trade_or_role'] }}</td>
                <td class="amount">{{ $row['counts']['monday'] ?? '—' }}</td>
                <td class="amount">{{ $row['counts']['tuesday'] ?? '—' }}</td>
                <td class="amount">{{ $row['counts']['wednesday'] ?? '—' }}</td>
                <td class="amount">{{ $row['counts']['thursday'] ?? '—' }}</td>
                <td class="amount">{{ $row['counts']['friday'] ?? '—' }}</td>
                <td class="amount">{{ $row['person_days_total'] }}</td>
            </tr>
        @endforeach
    @endif

    <tr class="daily-total-row">
        <td>Daily Total</td>
        @foreach($content['days'] as $day)
            <td class="amount">{{ $day['daily_total'] }}</td>
        @endforeach
        <td class="amount">{{ $content['person_days_total'] }}</td>
    </tr>
</table>

@if($content['has_conflicts'])
    <p class="neutral">One or more days have conflicting Site Report figures — shown as "Conflicting reports" above rather than an invented total.</p>
@endif
