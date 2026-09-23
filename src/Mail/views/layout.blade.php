{{--
    The shell every markdown mail is rendered into.

    Table-based and inline-styled on purpose: a mail client is not a
    browser, and most of them drop a stylesheet, a flex container and
    anything positioned. An application overrides this by creating
    resources/views/vendor/mail/layout.blade.php.

    Available: $slot (the converted markdown), $theme (the stylesheet).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <style>{!! $theme !!}</style>
</head>
<body>
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
    <tr>
        <td align="center">
            <table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                    <td class="body" width="100%" cellpadding="0" cellspacing="0">
                        <table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                <td class="content-cell">
                                    {!! $slot !!}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
