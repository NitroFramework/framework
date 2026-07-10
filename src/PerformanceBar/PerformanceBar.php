<?php

namespace Nitro\PerformanceBar;

use Nitro\PerformanceBar\Contracts\PanelInterface;
use Nitro\Http\Response;

/**
 * PerformanceBar — the central debug bar hub.
 *
 * Usage:
 *   PerformanceBar::getInstance()->addPanel(new SomePanel());
 *   PerformanceBar::getInstance()->inject($response);
 *
 * Panels implement PanelInterface: getId(), getName(), collect(), renderBadge(), renderContent()
 */
class PerformanceBar
{
    private static ?self $instance = null;

    /** @var PanelInterface[] */
    private array $panels = [];

    private bool $enabled = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Probe whether the bar has been instantiated and enabled, without
     * allocating it. Callers on the hot path use this to skip getInstance()
     * + inject() entirely when nothing has opted into the bar.
     */
    public static function isAvailable(): bool
    {
        return self::$instance !== null && self::$instance->enabled;
    }

    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function addPanel(PanelInterface $panel): self
    {
        $this->panels[$panel->getId()] = $panel;
        return $this;
    }

    public function getPanel(string $id): ?PanelInterface
    {
        return $this->panels[$id] ?? null;
    }

    /**
     * Collect all panels then inject the bar HTML into the response.
     */
    public function inject(Response $response): void
    {
        if (!$this->enabled) return;

        $contentType = $response->header('Content-Type') ?? 'text/html';
        if (!str_contains($contentType, 'text/html')) return;

        $content = $response->getContent();
        if (!str_contains($content, '</body>')) return;

        // Collect all panels
        foreach ($this->panels as $panel) {
            $panel->collect();
        }

        $html = $this->render();
        $response->setContent(str_replace('</body>', $html . '</body>', $content));
    }

    private function render(): string
    {
        if (empty($this->panels)) return '';

        $container = app();
        $isHtmx = $container->has('request') && $container->make('request')->isHtmx();
        $oob = $isHtmx ? ' hx-swap-oob="true"' : '';

        return $this->renderStyles()
            . $this->renderBar($oob)
            . $this->renderScript();
    }

    private function renderStyles(): string
    {
        return '<style>' . file_get_contents(__DIR__ . '/assets/performancebar.css') . '</style>';
    }

    private function renderBar(string $oob): string
    {
        $tabButtons = '';
        $firstTab = true;
        foreach ($this->panels as $panel) {
            $active = $firstTab ? ' ndb-tab-active' : '';
            $badge  = $panel->renderBadge();
            $badgeHtml = $badge !== '' ? "<span class='ndb-badge'>{$badge}</span>" : '';
            $tabButtons .= "<button class='ndb-tab{$active}' onclick='ndbSwitchTab(\"{$panel->getId()}\",this)'>{$panel->getName()}{$badgeHtml}</button>";
            $firstTab = false;
        }

        $panelContents = '';
        $firstPanel = true;
        foreach ($this->panels as $panel) {
            $display = $firstPanel ? '' : ' style="display:none"';
            $panelContents .= "<div id='ndb-panel-{$panel->getId()}' class='ndb-panel-content'{$display}>{$panel->renderContent()}</div>";
            $firstPanel = false;
        }

        ob_start();
        extract(['oob' => $oob, 'tabButtons' => $tabButtons, 'panelContents' => $panelContents]);
        include __DIR__ . '/assets/performancebar.html.php';
        return ob_get_clean() ?: '';

    }

    private function renderScript(): string
    {
        return '<script>' . file_get_contents(__DIR__ . '/assets/performancebar.js') . '</script>';
    }
}
