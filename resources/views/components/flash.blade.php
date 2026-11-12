@if (session('status'))
    <div class="alert alert-ok">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif
