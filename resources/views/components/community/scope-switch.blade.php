@props(['scope', 'label' => 'نطاق العرض'])
<div class="btn-group btn-group-sm scope-switch" role="group" aria-label="{{ $label }}" data-scope-switch>
    @foreach(\App\Services\Community\EntityContentScope::MODES as $key => $text)
        <a class="btn {{ $scope === $key ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ request()->fullUrlWithQuery(['scope' => $key]) }}" data-preserve-hash @if($scope === $key) aria-current="true" @endif>{{ $text }}</a>
    @endforeach
</div>
