@props(['notices'])
@if($notices->isNotEmpty())
    <ul class="content-notices" data-public-notices aria-label="التحديثات">
        @foreach($notices as $notice)
            <li class="notice-{{ $notice->kind }}"><strong>{{ \App\Models\ContentNotice::KINDS[$notice->kind] ?? $notice->kind }}</strong> <time datetime="{{ $notice->created_at?->toIso8601String() }}">{{ $notice->created_at?->setTimezone(config('community.timezone'))->format('Y-m-d H:i') }}</time>: {{ $notice->message }}</li>
        @endforeach
    </ul>
@endif
