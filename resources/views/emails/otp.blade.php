<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رمز التحقق</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            direction: rtl;
            text-align: right;
        }

        .container {
            max-width: 550px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .header {
            background-color: #4F46E5;
            padding: 30px;
            text-align: center;
            color: #ffffff;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }

        .content {
            padding: 40px 30px;
            color: #333333;
            line-height: 1.6;
        }

        .otp-box {
            background-color: #EEF2FF;
            border: 2px dashed #6366F1;
            border-radius: 10px;
            text-align: center;
            padding: 20px;
            margin: 30px 0;
        }

        .otp-code {
            font-size: 36px;
            font-weight: 800;
            color: #4F46E5;
            letter-spacing: 6px;
        }

        .footer {
            background-color: #f9fafb;
            padding: 20px;
            text-align: center;
            font-size: 13px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>{{ config('app.name', 'Masahati') }}</h1>
        </div>
        <div class="content">
            <h2>مرحباً {{ $userName }} 👋</h2>
            <p>لقد طلبت رمز التحقق للوصول إلى حسابك. يرجى استخدام الرمز أدناه لإتمام العملية:</p>

            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
            </div>

            <p style="font-size: 14px; color: #ef4444;">* هذا الرمز صالحة لمدة 10 دقائق فقط. لا تشارك هذا الرمز مع أي
                شخص.</p>
        </div>
        <div class="footer">
            جميع الحقوق محفوظة © {{ date('Y') }} {{ config('app.name', 'Masahati') }}
        </div>
    </div>
</body>

</html>
