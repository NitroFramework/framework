<?php

namespace Nitro\PerformanceBar\Contracts;

/**
 * Contract for a performance-bar panel that renders one section of dev metrics.
 */
interface PanelInterface
{
    /**
     * Panel unique identifier (used for tab ID in HTML)
     */
    public function getId(): string;

    /**
     * Panel display name shown in the tab
     */
    public function getName(): string;

    /**
     * Collect/finalize data - called just before rendering
     */
    public function collect(): void;

    /**
     * Render the tab button badge/summary (shown in the tab bar)
     * Return empty string for no badge
     */
    public function renderBadge(): string;

    /**
     * Render the full panel content (shown when tab is active)
     */
    public function renderContent(): string;
}
