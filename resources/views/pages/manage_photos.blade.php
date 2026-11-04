@extends('layouts.app')
@section('title', 'Student photos - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Student photos</h1>
        {!! flash_render() !!}

        <p>Guards see this photo after scanning a student's QR code. Upload one or many images at once &mdash; each
           file must be named with the student number, e.g. <code>2026000003.jpg</code>. JPEG, PNG or WebP, up to 5 MB each.
           Uploading again replaces the old photo.</p>
        <p><strong>{!! count_student_photos() !!}</strong> photo(s) on file for <strong>{!! $totalStudents !!}</strong> active student(s).</p>

        <form method="post" enctype="multipart/form-data">
            @csrf
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
            <button type="submit">Upload</button>
        </form>

        @if ($results)
            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:16px;">
                <thead><tr><th>File</th><th>Result</th></tr></thead>
                <tbody>
                @foreach ($results as [$name, $ok, $msg])
                    <tr>
                        <td>{!! h($name) !!}</td>
                        <td style="color:{!! $ok ? 'green' : 'red' !!};">{!! h($msg) !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </main>

    

@endsection
