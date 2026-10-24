<?php

declare(strict_types=1);

namespace LagMaster\SmpMaster;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

final class SmpCommand extends Command{
    public function __construct(private Main $plugin, string $name, string $description, array $aliases = [], string $permission = 'smpmaster.command.use'){
        parent::__construct($name, $description, '/' . $name, $aliases);
        $this->setPermission($permission);
        $this->setPermissionMessage('§cYou do not have permission to use this command.');
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args) : void{
        if(!$this->testPermission($sender)) return;
        $this->plugin->handleCommand($sender, $this->getName(), $args);
    }
}
