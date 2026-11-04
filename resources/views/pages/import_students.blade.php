@extends('layouts.app')
@section('title', 'Import students - Outing System')
@section('content')

    
    

    <main style="padding: 16px;">
        <h1>Import students</h1>

        <p>CSV columns: <code>std_no, std_name, ic_no, email, program, phone, emergency_contact_name, emergency_contact_phone</code>.
           Only <code>std_no</code>, <code>std_name</code>, and <code>ic_no</code> are required. Header row is required; column order doesn't matter.</p>

        <p>A student number that already exists gets its details <strong>updated</strong> -- their password is never touched.
           A new student number gets created with an initial password of the <strong>last 6 digits of their IC number</strong>;
           they should change it after logging in for the first time.</p>

        @if ($fatalError)
            <p style="color: red;">{!! h($fatalError) !!}</p>
        @endif

        @if ($result)
            <div style="border: 1px solid #ccc; padding: 12px; margin-bottom: 16px;">
                <p><strong>Created:</strong> {!! (int) $result['created'] !!} &nbsp;
                   <strong>Updated:</strong> {!! (int) $result['updated'] !!} &nbsp;
                   <strong>Skipped (errors):</strong> {!! count($result['errors']) !!}</p>

                @if ($result['errors'])
                    <table border="1" cellpadding="6" cellspacing="0">
                        <tr><th>Row</th><th>Student No.</th><th>Problem</th></tr>
                        @foreach ($result['errors'] as $err)
                            <tr>
                                <td>{!! (int) $err['row'] !!}</td>
                                <td>{!! h($err['std_no']) !!}</td>
                                <td>{!! h($err['message']) !!}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>
        @endif

        <form method="post" action="import_students.php" enctype="multipart/form-data">
            @csrf
            <label>CSV file <input type="file" name="csv_file" accept=".csv" required></label>
            <button type="submit">Import</button>
        </form>
    </main>

    

@endsection
