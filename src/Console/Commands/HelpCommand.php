<?php

namespace Nitro\Console\Commands;

use Nitro\Console\Contracts\CommandInterface;
use Nitro\Console\CommandManager;
use Nitro\Console\OutputFormatter;

/**
 * Console command for displaying help information.
 *
 * Takes the CommandManager directly so the container can auto-wire it; the
 * previous \Closure constructor parameter required a factory binding that
 * was never registered.
 */
class HelpCommand implements CommandInterface
{
    public function __construct(
        private CommandManager $manager,
        private OutputFormatter $output
    ) {}

    public function getCommands(): array
    {
        return ['help' => 'Show this help message'];
    }

    public function handle(string $signature, array $arguments): void
    {
        $this->output->writeln($this->output->color("NitroPHP Console Commands", 'cyan', true));
        $this->output->writeln($this->output->color("============================", 'cyan'));
        $this->output->writeln("");
        $this->output->writeln($this->output->color("Available commands:", 'yellow', true));
        $this->output->writeln("");

        $commands = $this->manager->getDescriptions();
        $groups = $this->groupCommands($commands);

        foreach ($groups as $group => $groupCommands) {
            $this->output->writeln($this->output->color($group . " Commands:", 'magenta', true));
            foreach ($groupCommands as $signature => $description) {
                $this->output->writeln("  " . $this->output->color($signature, 'green') . "   " . $description);
            }
            $this->output->writeln("");
        }

        $this->output->writeln($this->output->color("Usage:", 'yellow', true) . " php nitro <command>");
        $this->output->writeln("");
    }

    /**
     * Group commands by their prefix for organized display.
     *
     * @param array $commands Array of all available commands
     * @return array Commands grouped by category
     */
    protected function groupCommands(array $commands): array
    {
        $groups = [];

        foreach ($commands as $signature => $description) {
            $prefix = explode(':', $signature)[0];
            $groupName = ucfirst($prefix);

            if ($signature === 'help') {
                $groupName = 'General';
            }

            $groups[$groupName][$signature] = $description;
        }

        $orderedGroups = [];
        $order = ['Route', 'View', 'General'];

        foreach ($order as $groupName) {
            if (isset($groups[$groupName])) {
                $orderedGroups[$groupName] = $groups[$groupName];
                unset($groups[$groupName]);
            }
        }

        foreach ($groups as $groupName => $groupCommands) {
            $orderedGroups[$groupName] = $groupCommands;
        }

        return $orderedGroups;
    }
}