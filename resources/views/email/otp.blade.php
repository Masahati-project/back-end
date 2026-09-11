<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>رمز التحقق</title>
</head>
<body style="margin:0;padding:0;background-color:#fdf3e9;font-family:'Tahoma','Segoe UI',sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fdf3e9;padding:48px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="500" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(224,123,36,0.18);">

          <!-- الهيدر البرتقالي -->
          <tr>
            <td style="background-color:#E07B24;padding:36px 20px;text-align:center;">
              <img src="{{ asset('images/masahati-logo-original.png') }}" alt="مساحاتي" width="180" style="display:block;margin:0 auto;max-width:180px;height:auto;">
            </td>
          </tr>

          <!-- أيقونة عائمة -->
          <tr>
            <td align="center" style="background-color:#ffffff;">
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:-28px;">
                <tr>
                  <td style="background-color:#ffffff;width:56px;height:56px;border-radius:50%;box-shadow:0 4px 14px rgba(224,123,36,0.28);text-align:center;vertical-align:middle;">
                    <span style="font-size:26px;line-height:56px;">🔐</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- المحتوى -->
          <tr>
            <td style="padding:20px 40px 40px;text-align:center;background-image:url('{{ asset('images/masahati-watermark-center.png') }}');background-repeat:no-repeat;background-position:center 10px;background-size:240px auto;">

              <p style="font-size:21px;color:#1f2937;margin:0 0 10px;font-weight:700;">
                مرحباً {{ $userName }} 👋
              </p>
              <p style="font-size:14.5px;color:#6b7280;line-height:1.9;margin:0 0 28px;">
                استخدم الرمز التالي لإتمام تسجيل الدخول إلى حسابك في مساحاتي
              </p>

              <table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
                <tr>
                  <td style="background-color:#fdf3e9;border:2px dashed #E07B24;border-radius:14px;padding:18px 44px;">
                    <span style="font-size:36px;font-weight:700;letter-spacing:10px;color:#E07B24;direction:ltr;display:inline-block;font-family:'Courier New',monospace;">
                      {{ $otp }}
                    </span>
                  </td>
                </tr>
              </table>

              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
                <tr>
                  <td style="background-color:#fdf3e9;border-radius:30px;padding:8px 20px;">
                    <span style="font-size:13px;color:#c9711f;font-weight:600;">⏱️ صالح لمدة 10 دقائق فقط</span>
                  </td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fef2f2;border-radius:12px;">
                <tr>
                  <td style="padding:14px 18px;text-align:center;">
                    <span style="font-size:13px;color:#b91c1c;font-weight:600;">
                      ⚠️ لا تشارك هذا الرمز مع أي شخص، حتى لو ادّعى أنه من فريق مساحاتي
                    </span>
                  </td>
                </tr>
              </table>

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