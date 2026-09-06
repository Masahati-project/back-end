<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    body {
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
        background-color: #f1f2f4;
        margin: 0;
        padding: 0;
    }
    .wrapper {
        width: 100%;
        padding: 40px 20px;
    }
    .container {
        max-width: 480px;
        margin: 0 auto;
        background: #ffffff;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #e2e4e8;
    }
    .header {
        background-color: #1e293b;
        padding: 26px 20px;
        text-align: center;
    }
    .header h1 {
        color: #ffffff;
        font-size: 22px;
        margin: 0;
        font-weight: 600;
        letter-spacing: 0.3px;
    }
    .body {
        padding: 36px 32px;
        text-align: center;
    }
    .greeting {
        font-size: 20px;
        color: #1e293b;
        margin: 0 0 10px;
        font-weight: 600;
    }
    .subtext {
        font-size: 16px;
        color: #64748b;
        margin: 0 0 28px;
        line-height: 1.7;
    }
    .code-box {
        display: inline-block;
        background-color: #f8fafc;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        padding: 16px 32px;
        margin-bottom: 24px;
    }
    .code {
        font-size: 34px;
        font-weight: 700;
        letter-spacing: 10px;
        color: #1e293b;
        direction: ltr;
        display: inline-block;
    }
    .note {
        font-size: 15px;
        color: #94a3b8;
        margin: 0 0 4px;
    }
    .warning {
        font-size: 15px;
        color: #b91c1c;
        margin: 16px 0 0;
        background-color: #fef2f2;
        border-radius: 8px;
        padding: 10px 16px;
        display: inline-block;
    }
    .divider {
        height: 1px;
        background-color: #e2e4e8;
        margin: 0;
    }
    .footer {
        text-align: center;
        padding: 20px;
        font-size: 14px;
        color: #94a3b8;
    }
</style>
</head>
<body>
<div class="wrapper">
    <div class="container">
        <div class="header">
            <h1>{{ config('app.name') }}</h1>
        </div>
        <div class="body">
            <p class="greeting">👋 {{ $userName}} مرحباً</p>
            <p class="subtext">لقد طلبت رمز التحقق للوصول إلى حسابك.<br>يرجى استخدام الرمز أدناه لإتمام العملية:</p>

            <div class="code-box">
                <span class="code">{{ $otp }}</span>
            </div>

            <p class="note">هذا الرمز صالح لمدة 10 دقائق فقط</p>
            <div class="warning">⚠️ لا تشارك هذا الرمز مع أي شخص</div>
        </div>
        <div class="divider"></div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }} — جميع الحقوق محفوظة
        </div>
    </div>
</div>
</body>
</html>