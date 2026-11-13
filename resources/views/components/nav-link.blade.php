@props(['href', 'icon', 'active' => false, 'sub' => false, 'badge' => null])
<a href="{{ $href }}" @class(['nav-link', 'nav-sub' => $sub, 'active' => $active])>
    <x-nav-icon :name="$icon" />
    <span class="nav-label">{{ $slot }}</span>
    @if ($badge)<span class="nav-badge">{{ $badge }}</span>@endif
</a>
