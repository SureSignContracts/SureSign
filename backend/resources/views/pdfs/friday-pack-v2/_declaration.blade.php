{{--
    Friday Pack Realignment, R1G.1 — the ONE place a section's
    declaration/missing fallback is rendered. Included only when the
    caller has already established the section has no real source rows
    ($section['render_mode'] !== 'source') — never duplicates
    FridayPackReadinessMatrix's own statement text, which is passed in
    verbatim via the presenter.
--}}
@php($declaration = $state['declaration'] ?? null)
@if(($state['render_mode'] ?? null) === 'declaration' && $declaration)
    <div class="declaration">
        {{ $declaration['statement'] }}
        @if($declaration['note'])
            <div class="declaration-note">{{ $declaration['note'] }}</div>
        @endif
        @if($declaration['attribution'])
            <div class="attribution">{{ $declaration['attribution'] }}</div>
        @endif
    </div>
@else
    <p class="neutral">Information not completed for this reporting period.</p>
@endif
