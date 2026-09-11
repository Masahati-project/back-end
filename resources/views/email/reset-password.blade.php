<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعادة تعيين كلمة المرور</title>
</head>

<body style="margin:0;padding:0;background-color:#fdf3e9;font-family:'Tahoma','Segoe UI',sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="background-color:#fdf3e9;padding:48px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="500" cellpadding="0" cellspacing="0"
                    style="background-color:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(224,123,36,0.18);">

                    <!-- الهيدر البرتقالي -->
                    <tr>
                        <td style="background-color:#E07B24;padding:32px 20px;text-align:center;">
                            <table role="presentation" align="center" cellpadding="0" cellspacing="0"
                                style="background-color:#ffffff;border-radius:14px;box-shadow:0 4px 14px rgba(0,0,0,0.1);">
                                <tr>
                                    <td style="padding:10px 20px;">
                                        <img src="{{ asset('images/masahati-logo-original.png') }}" alt="مساحاتي"
                                            width="165" style="display:block;max-width:150px;height:auto;">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- أيقونة القفل العائمة -->
                    <tr>
                        <td align="center" style="background-color:#ffffff;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:-28px;">
                                <tr>
                                    <td
                                        style="background-color:#ffffff;width:56px;height:56px;border-radius:50%;box-shadow:0 4px 14px rgba(224,123,36,0.28);text-align:center;vertical-align:middle;">
                                        <span style="font-size:26px;line-height:56px;">🔑</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- المحتوى -->
                    <tr>
                        <td
                            style="padding:20px 20px 20px;text-align:center;background-image:url('{{ asset('images/masahati-watermark-center.png') }}');background-repeat:no-repeat;background-position:center 10px;background-size:350px auto;">

                            <p style="font-size:21px;color:#1f2937;margin:0 0 10px;font-weight:700;">
                                مرحباً {{ $name }} 👋
                            </p>
                            <p style="font-size:14.5px;color:#6b7280;line-height:1.9;margin:0 0 30px;">
                                وصلنا طلب لإعادة تعيين كلمة المرور الخاصة بحسابك في مساحاتي.
                                اضغط على الزر أدناه لتعيين كلمة مرور جديدة.
                            </p>

                            <table role="presentation" align="center" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $url }}"
                                            style="background-color:#E07B24;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;padding:16px 48px;border-radius:12px;display:inline-block;box-shadow:0 8px 20px rgba(224,123,36,0.35);">
                                            إعادة تعيين كلمة المرور
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px auto 0;">
                                <tr>
                                    <td style="background-color:#fdf3e9;border-radius:30px;padding:8px 20px;">
                                        <span style="font-size:13px;color:#c9711f;font-weight:600;">⏱️ صالح لمدة
                                            {{ $expire }} دقيقة فقط</span>
                                    </td>
                                </tr>
                            </table>

                            <hr style="border:none;border-top:1px dashed #f0d9bf;margin:32px 0 20px;">

                            <p style="font-size:13px;color:#9ca3af;line-height:1.8;margin:0 0 4px;">
                                لم تطلب إعادة تعيين كلمة المرور؟ تجاهل هذه الرسالة بأمان.
                            </p>
                            <p style="font-size:12px;color:#c1c7d0;line-height:1.8;margin:0;">
                                مشكلة بالزر؟ انسخ والصق:
                                <span style="word-break:break-all;color:#E07B24;">{{ $url }}</span>
                            </p>
                        </td>
                    </tr>

                    <!-- الفوتر -->
                    <tr>
                        <td align="center" style="background-color:#fdf3e9;padding:20px;">
                            <p style="font-size:12px;color:#c9a271;margin:0;">
                                © {{ date('Y') }} مساحاتي — منصة اكتشاف وحجز مساحات العمل المشتركة
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>
