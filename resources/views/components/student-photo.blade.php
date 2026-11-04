
@if(student_photo_file($std))
<img src="{{ route('student_photo',['std_no'=>$std]) }}" alt="{{ $name }}" style="width:{{ $size }}px;height:{{ $size }}px;border-radius:8px;object-fit:cover;background:#eee;">
@else

<div title="No photo on file" style="width:{{ $size }}px;height:{{ $size }}px;border-radius:8px;background:#e2e8f0;color:#4a5568;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:{{ (int)($size/3) }}px;">{{ initials($name) }}</div>
@endif
