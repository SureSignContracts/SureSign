{{--
    Friday Pack Realignment, R1G.1-VFIX5 — controlled two-column evidence
    system. $content = ['rows' => [['kind' => 'pair'|'single'|'panoramic',
    'photos' => [['image_uri', 'orientation', 'is_panoramic',
    'caption_location'], ...]]]] — already grouped by the presenter (see
    FridayPackSchemaTwoPdfPresenter::groupPhotosIntoRows()).

    'pair' — two ordinary photos side by side, one per evidence column.
    'single' — a trailing unpaired ordinary photo, at the SAME
      single-column width as a paired photo (never stretched to fill the
      row).
    'panoramic' — a genuinely wide (aspect ratio >= 2.0) photo, spanning
      the full evidence width.

    Every <img> sets max-width/max-height only — width and height
    together are never both fixed, so the real aspect ratio is always
    preserved regardless of which column a photo lands in.
--}}
@foreach($content['rows'] as $row)
    @if($row['kind'] === 'pair')
        <table class="photo-row-pair">
            <tr>
                @foreach($row['photos'] as $photo)
                    <td>
                        <div class="photo-block-column">
                            @if($photo['image_uri'])
                                <img src="{{ $photo['image_uri'] }}" class="photo-img">
                            @else
                                <div class="photo-unavailable">Photo unavailable</div>
                            @endif
                            @if($photo['caption_location'])
                                <div class="photo-caption">Caption / Location: {{ $photo['caption_location'] }}</div>
                            @endif
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    @elseif($row['kind'] === 'single')
        @php($photo = $row['photos'][0])
        <table class="photo-row-pair">
            <tr>
                <td>
                    <div class="photo-block-column">
                        @if($photo['image_uri'])
                            <img src="{{ $photo['image_uri'] }}" class="photo-img">
                        @else
                            <div class="photo-unavailable">Photo unavailable</div>
                        @endif
                        @if($photo['caption_location'])
                            <div class="photo-caption">Caption / Location: {{ $photo['caption_location'] }}</div>
                        @endif
                    </div>
                </td>
                <td></td>
            </tr>
        </table>
    @elseif($row['kind'] === 'lone')
        {{-- Exactly one photo selected overall — displayed larger than an
             ordinary evidence column, still capped to a sensible maximum
             so a low-resolution image is never enlarged absurdly. --}}
        @php($photo = $row['photos'][0])
        <div class="photo-row-lone">
            @if($photo['image_uri'])
                <img src="{{ $photo['image_uri'] }}" class="photo-img photo-img-lone">
            @else
                <div class="photo-unavailable">Photo unavailable</div>
            @endif
            @if($photo['caption_location'])
                <div class="photo-caption">Caption / Location: {{ $photo['caption_location'] }}</div>
            @endif
        </div>
    @else
        @php($photo = $row['photos'][0])
        <div class="photo-row-panoramic">
            @if($photo['image_uri'])
                <img src="{{ $photo['image_uri'] }}" class="photo-img photo-img-panoramic">
            @else
                <div class="photo-unavailable">Photo unavailable</div>
            @endif
            @if($photo['caption_location'])
                <div class="photo-caption">Caption / Location: {{ $photo['caption_location'] }}</div>
            @endif
        </div>
    @endif
@endforeach
