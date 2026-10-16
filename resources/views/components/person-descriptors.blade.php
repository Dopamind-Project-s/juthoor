@props(['person'])
@if($person instanceof \App\Models\Person)
<span class="person-descriptors">
@foreach($person->displayAttributes() as $attribute)
<span class="person-attribute" style="--attribute-color:{{ $attribute->color }};--attribute-ink:{{ $attribute->text_color }}">{{ $attribute->label }}</span>
@endforeach
</span>
@endif
