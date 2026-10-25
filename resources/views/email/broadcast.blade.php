<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6;">
    <h2>{{ $title }}</h2>
    <p>{!! nl2br(e($body)) !!}</p>
    @if($link)
        <p><a href="{{ $link }}">عرض التفاصيل</a></p>
    @endif
</body>
</html>
