<?php
# This file is part of Nitro.

namespace Nitro\Debug;

/**
 * Developer dump helper — pretty-prints values for debugging (dump/dd).
 */
class Dumper
{
    private int $maxDepth;
    private int $maxItems;
    private int $maxString;

    public function __construct(int $maxDepth = 10, int $maxItems = 100, int $maxString = 200)
    {
        $this->maxDepth = $maxDepth;
        $this->maxItems = $maxItems;
        $this->maxString = $maxString;
    }

    public function dump(mixed $value): mixed
    {
        echo '<div class="nitro-dump">' . $this->getStyles() . $this->render($value, 0) . '</div>';
        return $value;
    }

    private function render(mixed $value, int $depth): string
    {
        if ($depth > $this->maxDepth) {
            return '<span class="nd-ellipsis">…</span>';
        }

        return match (true) {
            is_null($value)     => '<span class="nd-null">null</span>',
            is_bool($value)     => '<span class="nd-bool">' . ($value ? 'true' : 'false') . '</span>',
            is_int($value)      => '<span class="nd-int">' . $value . '</span>',
            is_float($value)    => '<span class="nd-float">' . $value . '</span>',
            is_string($value)   => $this->renderString($value),
            is_array($value)    => $this->renderArray($value, $depth),
            is_object($value)   => $this->renderObject($value, $depth),
            is_resource($value) => '<span class="nd-resource">resource(' . get_resource_type($value) . ')</span>',
            default             => '<span class="nd-unknown">' . gettype($value) . '</span>',
        };
    }

    private function renderString(string $value): string
    {
        $len = strlen($value);
        $truncated = $len > $this->maxString
            ? substr($value, 0, $this->maxString) . '…'
            : $value;

        return '<span class="nd-string">"' . $this->esc($truncated) . '"</span>'
             . '<span class="nd-info"> (' . $len . ')</span>';
    }

    private function renderArray(array $value, int $depth): string
    {
        $count = count($value);

        if ($count === 0) {
            return '<span class="nd-muted">[] empty</span>';
        }

        $id = 'nd-' . mt_rand();
        $items = '';
        $i = 0;

        foreach ($value as $k => $v) {
            if ($i++ >= $this->maxItems) {
                $remaining = $count - $this->maxItems;
                $items .= '<div class="nd-indent"><span class="nd-muted">… and ' . $remaining . ' more</span></div>';
                break;
            }

            $key = is_string($k) ? '"' . $this->esc($k) . '"' : $k;
            $items .= '<div class="nd-indent">'
                     . '<span class="nd-key">' . $key . '</span> => '
                     . $this->render($v, $depth + 1)
                     . '</div>';
        }

        return '<span class="nd-block">'
             . '<span class="nd-toggle" data-nd-toggle="1">'
             . 'array(' . $count . ')</span>'
             . '<div class="nd-group">' . $items . '</div>'
             . '</span>';
    }

    private function renderObject(object $value, int $depth): string
    {
        $class = get_class($value);

        if (method_exists($value, 'toArray')) {
            $data = $value->toArray();
            $label = 'toArray';
        } else {
            $data = (array) $value;
            $label = 'props';
        }

        $count = count($data);
        $items = '';

        foreach ($data as $k => $v) {
            // Clean protected/private key prefixes e.g. \0Class\0prop
            $k = preg_replace('/\x00.*?\x00/', '', $k);
            $items .= '<div class="nd-indent">'
                     . '<span class="nd-key">' . $this->esc($k) . '</span>: '
                     . $this->render($v, $depth + 1)
                     . '</div>';
        }

        return '<span class="nd-block">'
             . '<span class="nd-toggle" data-nd-toggle="1">'
             . '<span class="nd-class">' . $this->esc($class) . '</span>'
             . ' <span class="nd-info">{' . $count . ' ' . $label . '}</span></span>'
             . '<div class="nd-group">' . $items . '</div>'
             . '</span>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function getStyles(): string
    {
        static $rendered = false;

        // Only inject styles once per page
        if ($rendered) return '';
        $rendered = true;

        return '<style>
            .nitro-dump {
                background: #f8f8f8;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 16px;
                font: 13px/1.6 "SF Mono", Consolas, monospace;
                overflow: auto;
                max-height: 500px;
                margin: 8px 0;
            }
            .nd-null { color: #777; font-style: italic; }
            .nd-bool { color: #d35400; font-weight: bold; }
            .nd-int, .nd-float { color: #1a9655; }
            .nd-string { color: #c0392b; }
            .nd-resource { color: #8e44ad; font-style: italic; }
            .nd-info { color: #999; font-size: 11px; }
            .nd-key { color: #2471a3; }
            .nd-class { color: #8e44ad; font-weight: bold; }
            .nd-muted { color: #999; font-style: italic; }
            .nd-ellipsis { color: #999; }
            .nd-unknown { color: #e67e22; }
            .nd-indent { padding-left: 20px; }
            .nd-toggle { cursor: pointer; user-select: none; }
            .nd-toggle:hover { opacity: 0.7; }
            .nd-toggle::before { content: "▼ "; font-size: 10px; color: #999; }
            .nd-collapsed > .nd-toggle::before { content: "▶ "; }
            .nd-collapsed > .nd-group { display: none; }
        </style>
        <script>
            (function () {
                if (window.__nitroDumpInit) return;
                window.__nitroDumpInit = true;
                document.addEventListener("click", function (e) {
                    var t = e.target.closest && e.target.closest("[data-nd-toggle]");
                    if (!t) return;
                    t.parentElement.classList.toggle("nd-collapsed");
                });
            })();
        </script>';
    }
}
