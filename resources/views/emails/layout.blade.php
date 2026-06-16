<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'DiNaDrawing')</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#242424;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f7;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e8e8eb;">
                <tr>
                    <td style="padding:22px 28px;background:#f2b73f;color:#171717;font-size:22px;font-weight:800;">
                        DiNaDrawing
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px 28px;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px;background:#fafafa;color:#777;font-size:12px;line-height:1.5;">
                        This is an automated message from DiNaDrawing. You can turn email reminders off in Settings.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
