{{--
    Friday Pack Realignment, R1G.1-VFIX2 — the shared "standard section"
    body renderer, used for both top-level sections (Weekly Summary,
    Workforce, Site Photographs, Plant, Materials, Site Issues, Look
    Ahead) and each Health & Safety subsection (4.1–4.5). $section is one
    entry from the presenter's 'standard'-kind array
    (key/render_mode/declaration/content).
--}}
@php($content = $section['content'] ?? null)

@if($section['render_mode'] === 'source')
    @switch($section['key'])
        @case('weekly_summary')
        @case('site_issues')
            <p class="narrative">{{ $content['text'] }}</p>
            @break

        @case('look_ahead')
            <p class="narrative">{{ $content['text'] }}</p>
            @if(!empty($content['milestones']))
                <h4 class="subsection-heading" style="font-size:8pt;">Programme Milestones</h4>
                <table class="data-table">
                    <tr><th>Milestone</th><th>Type</th><th>Date</th></tr>
                    @foreach($content['milestones'] as $m)
                        <tr><td>{{ $m['name'] }}</td><td>{{ $m['milestone_type'] }}</td><td>{{ $m['upcoming_date'] }}</td></tr>
                    @endforeach
                </table>
            @endif
            @break

        @case('workforce')
            @include('pdfs.friday-pack-v2._workforce', ['content' => $content])
            @break

        @case('site_photographs')
            @include('pdfs.friday-pack-v2._photographs', ['content' => $content])
            @break

        @case('rams')
            <table class="data-table">
                <tr><th class="col-wide">Document</th><th class="col-narrow">Revision</th><th class="col-narrow">Status</th><th class="col-narrow amount">Current</th><th class="col-medium amount">Submitted This Week</th><th class="col-medium amount">Approved This Week</th><th class="col-narrow">Expiry</th></tr>
                @foreach($content as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['revision'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td class="amount">{{ $row['current'] }}</td>
                        <td class="amount">{{ $row['submitted_this_week'] }}</td>
                        <td class="amount">{{ $row['approved_this_week'] }}</td>
                        <td>{{ $row['expiry_date'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
            @break

        @case('toolbox_talks')
            <table class="data-table">
                <tr><th class="col-narrow">Date</th><th class="col-wide">Title</th><th class="col-medium">Trade / Subcontractor</th><th class="col-narrow amount">Attendance</th></tr>
                @foreach($content as $row)
                    <tr>
                        <td>{{ $row['talk_date'] }}</td>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['trade_or_subcontractor'] }}</td>
                        <td class="amount">{{ $row['attendee_count'] }}</td>
                    </tr>
                @endforeach
            </table>
            @break

        @case('site_inductions')
            <table class="data-table">
                <tr><th class="col-narrow">Date</th><th class="col-wide">Session</th><th class="col-medium">Company / Trade</th><th class="col-medium amount">Number Inducted</th></tr>
                @foreach($content['items'] as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['session_title'] }}</td>
                        <td>{{ $row['company_or_trade'] }}</td>
                        <td class="amount">{{ $row['inductee_count'] }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="fact-row"><span class="fact-label">Total Inducted (Sessions)</span> <span class="fact-value">{{ $content['total_inducted'] }}</span></div>
            @break

        @case('incidents')
            <table class="data-table">
                <tr><th class="col-medium">Date / Time</th><th class="col-narrow">Type</th><th class="col-wide">Summary</th><th class="col-medium">Location</th><th class="col-narrow">Injury</th><th class="col-medium">Reportability</th><th class="col-narrow">Status</th></tr>
                @foreach($content as $row)
                    <tr>
                        <td>{{ $row['date'] }} {{ $row['time'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['location'] }}</td>
                        <td>{{ $row['injury'] }}</td>
                        <td>{{ $row['reportability'] }}</td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @endforeach
            </table>
            @break

        @case('hs_inspections')
            <table class="data-table">
                <tr><th class="col-narrow">Date</th><th class="col-wide">Inspection</th><th class="col-medium">Inspected By</th><th class="col-medium">Outcome</th><th class="col-narrow">Status</th></tr>
                @foreach($content as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['inspection_type'] }}</td>
                        <td>{{ $row['inspected_by'] }}</td>
                        <td>{{ $row['outcome'] }}</td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @endforeach
            </table>
            @foreach($content as $row)
                @if($row['findings'] || $row['actions'])
                    <div class="fact-row">
                        @if($row['findings'])<span class="fact-label">Findings</span> {{ $row['findings'] }}<br>@endif
                        @if($row['actions'])<span class="fact-label">Actions</span> {{ $row['actions'] }}@endif
                    </div>
                @endif
            @endforeach
            @break

        @case('plant_equipment')
            <table class="data-table">
                <tr><th class="col-wide">Plant / Equipment</th><th class="col-medium">Type</th><th class="col-medium">Identifier</th><th class="col-medium">Owner / Supplier</th><th class="col-wide">Presence Period(s)</th></tr>
                @foreach($content as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['identifier'] }}</td>
                        <td>{{ $row['owner_supplier'] }}</td>
                        <td>
                            @foreach($row['presence_periods'] as $period)
                                {{ $period['on_site_from'] }} – {{ $period['off_site_at'] }}@if(!$loop->last)<br>@endif
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </table>
            @break

        @case('materials_delivered')
            <table class="data-table">
                <tr><th class="col-narrow">Date</th><th>Materials Delivered</th></tr>
                @foreach($content as $row)
                    <tr><td>{{ $row['date'] }}</td><td>{{ $row['materials_delivered'] }}</td></tr>
                @endforeach
            </table>
            @break
    @endswitch
@else
    @include('pdfs.friday-pack-v2._declaration', ['state' => $section])
@endif
