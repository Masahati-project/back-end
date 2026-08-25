<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: 'Tahoma', sans-serif;
            background-color: #f4f4f7;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 500px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .code-box {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 8px;
            background-color: #f0f4ff;
            color: #2d3af5;
            padding: 15px 25px;
            border-radius: 6px;
            display: inline-block;
            margin: 20px 0;
        }
        .footer {
            font-size: 12px;
            color: #999999;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>مرحبًا 👋</h2>
        <p>استخدم الكود التالي لتفعيل حسابك:</p>
        <div class="code-box">{{ $code }}</div>
        <p>هذا الكود صالح لمدة 10 دقائق فقط.</p>
        <p class="footer">لو ما طلبت هذا الكود، تجاهل هذه الرسالة.</p>
    </div>
</body>
</html>