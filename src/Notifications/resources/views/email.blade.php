@php
    $accent = match ($level ?? 'info') {
        'success' => '#1f7a4d',
        'error'   => '#a8201a',
        default   => '#2f5fa8',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
                    <tr>
                        <td style="font-size:18px;font-weight:600;padding-bottom:20px;">
                            {{ config('app.name') }}
                        </td>
                    </tr>

                    <tr>
                        <td style="font-size:16px;line-height:1.55;padding-bottom:12px;">
                            {{ $greeting ?? ($level === 'error' ? 'Whoops!' : 'Hello!') }}
                        </td>
                    </tr>

                    @foreach ($introLines ?? [] as $line)
                        <tr>
                            <td style="font-size:15px;line-height:1.6;padding-bottom:12px;">{{ $line }}</td>
                        </tr>
                    @endforeach

                    @if (! empty($actionUrl))
                        <tr>
                            <td style="padding:12px 0 20px;">
                                <a href="{{ $actionUrl }}"
                                   style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:600;">
                                    {{ $actionText }}
                                </a>
                            </td>
                        </tr>
                    @endif

                    @foreach ($outroLines ?? [] as $line)
                        <tr>
                            <td style="font-size:15px;line-height:1.6;padding-bottom:12px;">{{ $line }}</td>
                        </tr>
                    @endforeach

                    <tr>
                        <td style="font-size:15px;line-height:1.6;padding-top:8px;">
                            {{ $salutation ?? 'Regards,' }}<br>{{ config('app.name') }}
                        </td>
                    </tr>

                    @if (! empty($actionUrl))
                        <tr>
                            <td style="font-size:13px;line-height:1.6;color:#71717a;padding-top:24px;border-top:1px solid #e4e4e7;margin-top:24px;">
                                If the button above does not work, copy and paste this link into your browser:<br>
                                <span style="word-break:break-all;">{{ $actionUrl }}</span>
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
