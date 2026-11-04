@extends('layouts.app')
@section('title', 'System settings - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>System settings</h1>
        {!! flash_render() !!}

        <p class="report-note">
            Changes take effect immediately for every page -- there's no separate "publish" step.
        </p>

        <form method="post" action="manage_settings.php">
            @csrf
            <table border="1" cellpadding="6" cellspacing="0">
                <tbody>
                    @foreach ($settings as $key => $s)
                        <tr>
                            <td>
                                <label for="setting_{!! h($key) !!}"><strong>{!! h($s['label']) !!}</strong></label>
                                <br><small>{!! h($s['help']) !!}</small>
                            </td>
                            <td>
                                @if ($s['type'] === 'bool')
                                    <select id="setting_{!! h($key) !!}" name="{!! h($key) !!}">
                                        <option value="1" {!! $s['value'] !== '0' ? 'selected' : '' !!}>Yes</option>
                                        <option value="0" {!! $s['value'] === '0' ? 'selected' : '' !!}>No</option>
                                    </select>
                                @else

                                <input
                                    type="{!! h($s['type']) !!}"
                                    id="setting_{!! h($key) !!}"
                                    name="{!! h($key) !!}"
                                    value="{!! h($s['type'] === 'time' ? substr($s['value'], 0, 5) : $s['value']) !!}"
                                    {!! $s['type'] === 'number' ? 'min="' . (int) ($s['min'] ?? 0) . '" step="1"' : '' !!}
                                    {!! isset($s['max']) ? 'max="' . (int) $s['max'] . '"' : '' !!}
                                    required
                                >
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p><button type="submit">Save settings</button></p>
        </form>
    </main>

    

@endsection
