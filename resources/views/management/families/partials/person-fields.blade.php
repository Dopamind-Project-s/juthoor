@include('management.families.partials.person-identity-fields')
@include('management.families.partials.person-history-fields')
@include('management.families.partials.person-publication-fields')

@if(!request()->routeIs('management.changes.*'))
@include('management.families.partials.person-connections')
@endif
