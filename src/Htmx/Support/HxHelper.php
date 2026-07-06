<?php

namespace Nitro\Htmx\Support;

class HxHelper
{
    public function __construct(
        private HxObfuscator $obfuscator,
        private HxEncryptor $encryptor,
        private string $routePrefix,
    ) {}

    public function compile(array $args): string
    {
        $component = $args['component'] ?? throw new \RuntimeException('HTMX: component required.');
        $action    = $args['action']    ?? throw new \RuntimeException('HTMX: action required.');
        $method    = strtolower($args['method'] ?? 'post');

        $obfuscatedComp   = $this->obfuscator->obfuscate($component);
        $obfuscatedAction = $this->obfuscator->obfuscateAction($action, $component);
        $url = $this->routePrefix . "/{$obfuscatedComp}/{$obfuscatedAction}";

        $tag    = $args['tag']    ?? 'button';
        $class  = $args['class']  ?? null;
        $target = $args['target'] ?? '';
        $swap   = $args['swap']   ?? 'innerHTML';
        $extra  = $args['attr']   ?? '';

        if ($target !== '' && !str_starts_with($target, '#') && !str_starts_with($target, '.')) {
            $target = '#' . $target;
        }

        $attrs = [
            "hx-{$method}" => $url,
            "hx-swap"      => $swap,
            "data-hx-action" => "",
        ];

        if ($target) $attrs['hx-target'] = $target;
        if (isset($args['trigger'])) $attrs['hx-trigger'] = $args['trigger'];
        if (isset($args['include'])) $attrs['hx-include'] = $args['include'];
        if (isset($args['confirm'])) $attrs['hx-confirm'] = $args['confirm'];

        if (isset($args['vals']) && is_array($args['vals'])) {
            $token = $this->encryptor->encrypt($args['vals']);
            $attrs['hx-vals'] = json_encode(['_t' => $token]);
        }

        $attrString = '';
        foreach ($attrs as $k => $v) {
            $attrString .= sprintf(' %s="%s"', $k, htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
        }

        if ($extra) {
            $attrString .= ' ' . $extra;
        }

        $classAttr = $class ? sprintf(' class="%s"', htmlspecialchars($class, ENT_QUOTES, 'UTF-8')) : '';

        if ($tag === 'none') {
            return $attrString;
        }

        if ($tag === 'input') {
            return "<input type=\"text\"{$classAttr}{$attrString} />";
        }

        $typeAttr = ($tag === 'button') ? ' type="button"' : '';

        return "<{$tag}{$typeAttr}{$classAttr}{$attrString}>";
    }








   
}
