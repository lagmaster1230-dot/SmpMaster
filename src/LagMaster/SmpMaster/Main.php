<?php

declare(strict_types=1);

namespace LagMaster\SmpMaster;

use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\object\ItemEntity;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\entity\Location;
use pocketmine\world\World;

final class Main extends PluginBase implements Listener{
    private Database $database;
    private Config $shopConfig;
    private Config $worthConfig;
    private Config $scoreboardConfig;
    private Config $cratesConfig;
    private Config $auctionConfig;
    private ScoreboardManager $scoreboards;
    /** @var array<string, array{from:string, expires:int}> */
    private array $tpaRequests = [];
    /** @var array<string, int> */
    private array $tpaCooldowns = [];
    /** @var array<string, int> */
    private array $rtpCooldowns = [];
    /** @var array<string, int> */
    private array $joinTimes = [];
    /** @var array<string, array<string, int>> */
    private array $teamInvites = [];
    /** @var array<string, array{id:int, player:Player, handler:?TaskHandler, world:string, x:float, y:float, z:float, remaining:int, label:string, action:\Closure}> One active teleport countdown per player. */
    private array $countdowns = [];
    private int $countdownSeq = 0;
    /** @var array<string, array{killer:string, time:int}> last player who damaged each player */
    private array $lastAttackers = [];
    /** @var list<SmpCommand> */
    private array $registeredCommands = [];

    /** @return array<string, array{name:string, items:array<int, array{id:string, name:string, price:float|int}>}> */
    private static function defaultShopCategories() : array{
        return [
            'blocks' => ['name' => '§6Blocks', 'items' => [
                ['id' => 'stone', 'name' => '§fStone', 'price' => 4],
                ['id' => 'cobblestone', 'name' => '§7Cobblestone', 'price' => 3],
                ['id' => 'obsidian', 'name' => '§5Obsidian', 'price' => 80],
                ['id' => 'glass', 'name' => '§bGlass', 'price' => 8],
                ['id' => 'oak_log', 'name' => '§6Oak Log', 'price' => 12]
            ]],
            'ores' => ['name' => '§bOres & Gems', 'items' => [
                ['id' => 'coal', 'name' => '§8Coal', 'price' => 15],
                ['id' => 'iron_ingot', 'name' => '§fIron Ingot', 'price' => 35],
                ['id' => 'gold_ingot', 'name' => '§6Gold Ingot', 'price' => 60],
                ['id' => 'diamond', 'name' => '§bDiamond', 'price' => 500],
                ['id' => 'emerald', 'name' => '§aEmerald', 'price' => 350],
                ['id' => 'redstone', 'name' => '§cRedstone', 'price' => 20]
            ]],
            'farming' => ['name' => '§aFarming', 'items' => [
                ['id' => 'wheat', 'name' => '§eWheat', 'price' => 8],
                ['id' => 'carrot', 'name' => '§6Carrot', 'price' => 7],
                ['id' => 'potato', 'name' => '§ePotato', 'price' => 7],
                ['id' => 'sugar_cane', 'name' => '§aSugar Cane', 'price' => 10],
                ['id' => 'cactus', 'name' => '§2Cactus', 'price' => 9]
            ]],
            'utility' => ['name' => '§dUtility', 'items' => [
                ['id' => 'ender_pearl', 'name' => '§dEnder Pearl', 'price' => 120],
                ['id' => 'golden_apple', 'name' => '§6Golden Apple', 'price' => 250],
                ['id' => 'experience_bottle', 'name' => "§aBottle o' Enchanting", 'price' => 80]
            ]]
        ];
    }

    /** @return list<string> */
    private static function defaultScoreboardLines() : array{
        return [
            '§8----------------',
            '§fPlayer: %player%',
            '§7Rank: %rank%',
            '§8----------------',
            '§6Money: $%money%',
            '§dShards: %shards%',
            '§aKills: %kills%',
            '§cDeaths: %deaths%',
            '§eBounty: $%bounty%',
            '§bTeam: %team%',
            '§aPing: %ping%ms',
            '§7Online: %online%',
            '§8----------------',
            '§6Shop • Sell • Survive'
        ];
    }

    protected function onEnable() : void{
        @mkdir($this->getDataFolder(), 0777, true);
        // PocketMine reads plugin resources from the PHAR's resources/ directory.
        // Restore missing files before Config objects are created, otherwise Config may
        // create empty YAML files that make the shop/scoreboard appear unconfigured.
        foreach(['config.yml', 'shop.yml', 'worth.yml', 'scoreboard.yml', 'crates.yml', 'auction.yml'] as $resource){
            $resourcePath = $this->getDataFolder() . $resource;
            if(!is_file($resourcePath) || filesize($resourcePath) === 0){
                if(!$this->saveResource($resource, true) || !is_file($resourcePath) || filesize($resourcePath) === 0){
                    $this->getLogger()->warning('Could not restore embedded resource ' . $resource . '. Built-in fallback values will be used where available.');
                }
            }
        }
        $this->saveDefaultConfig();
        // One-time migration for installations created before config schema v2.
        // The old default was 300 seconds; make the requested 120-second cleanup automatic
        // without overwriting unrelated user settings.
        $configVersion = (int)$this->getConfig()->get('config-version', 1);
        if($configVersion < 2){
            $this->getConfig()->setNested('settings.drops.interval-seconds', 120);
            $this->getConfig()->set('config-version', 2);
            $this->getConfig()->save();
        }
        $this->shopConfig = new Config($this->getDataFolder() . 'shop.yml', Config::YAML);
        $this->worthConfig = new Config($this->getDataFolder() . 'worth.yml', Config::YAML);
        $this->scoreboardConfig = new Config($this->getDataFolder() . 'scoreboard.yml', Config::YAML);
        $this->cratesConfig = new Config($this->getDataFolder() . 'crates.yml', Config::YAML);
        $this->auctionConfig = new Config($this->getDataFolder() . 'auction.yml', Config::YAML);
        if(!is_array($this->shopConfig->get('categories', null)) || $this->shopConfig->get('categories', []) === []){
            $this->shopConfig->set('title', '§l§6SmpMaster §8| §fShop');
            $this->shopConfig->set('categories', self::defaultShopCategories());
            $this->shopConfig->save();
            $this->getLogger()->warning('shop.yml was empty or invalid; built-in shop categories were restored.');
        }
        if(!is_array($this->scoreboardConfig->get('lines', null)) || $this->scoreboardConfig->get('lines', []) === []){
            $this->scoreboardConfig->set('title', '§l§6SmpMaster');
            $this->scoreboardConfig->set('refresh-ticks', 40);
            $this->scoreboardConfig->set('lines', self::defaultScoreboardLines());
            $this->scoreboardConfig->save();
            $this->getLogger()->warning('scoreboard.yml was empty or invalid; built-in scoreboard lines were restored.');
        }
        if(!is_array($this->cratesConfig->get('crates', null)) || $this->cratesConfig->get('crates', []) === []){
            $this->cratesConfig->set('crates', [
                'common' => ['name' => '§aCommon Crate', 'rewards' => [
                    ['type' => 'money', 'amount' => 250, 'chance' => 50, 'name' => '§a$250'],
                    ['type' => 'shards', 'amount' => 5, 'chance' => 25, 'name' => '§d5 Shards'],
                    ['type' => 'item', 'id' => 'diamond', 'amount' => 3, 'chance' => 15, 'name' => '§b3 Diamonds'],
                    ['type' => 'money', 'amount' => 2000, 'chance' => 10, 'name' => '§6Grand Prize $2,000']
                ]]
            ]);
            $this->cratesConfig->save();
            $this->getLogger()->warning('crates.yml was empty or invalid; a basic crate was restored.');
        }
        try{
            $this->database = new Database($this->getDataFolder() . 'smpmaster.sqlite3');
        }catch(\Throwable $e){
            $this->getLogger()->critical('SmpMaster could not open its database: ' . $e->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        // Every newly created account (join, admin command, any code path) gets the configured starting balance.
        $this->database->setDefaultStartingMoney((float)$this->getConfig()->getNested('settings.starting-money', 250.0));
        $this->migrateScoreboardLines();
        $this->migrateWorthDefaults();
        $this->scoreboards = new ScoreboardManager($this);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->registerCommands();
        $refreshTicks = max(20, (int)$this->scoreboardConfig->get('refresh-ticks', $this->getConfig()->getNested('settings.scoreboard-refresh-ticks', 40)));
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            if(!$this->getConfig()->getNested('features.scoreboard', true)) return;
            foreach($this->getServer()->getOnlinePlayers() as $player){
                $this->scoreboards->update($player);
            }
        }), $refreshTicks);
        $dropInterval = max(30, min(86400, (int)$this->getConfig()->getNested('settings.drops.interval-seconds', 120)));
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            if(!$this->getConfig()->getNested('features.drop-cleaner', true) || !$this->getConfig()->getNested('settings.drops.auto-clear', true)) return;
            try{
                $removed = $this->clearDroppedItems();
            }catch(\Throwable $e){
                $this->getLogger()->error('Ground item cleanup failed: ' . $e->getMessage());
                return;
            }
            if($this->getConfig()->getNested('settings.drops.announce', true)){
                $popup = $removed > 0
                    ? '§aGround items cleared: §e' . $removed . ' §aitem(s). §7Thanks for keeping the server clean!'
                    : '§7Ground item cleanup complete. §aNo items found — thanks!';
                foreach($this->getServer()->getOnlinePlayers() as $online){
                    if(!$online->isConnected()) continue;
                    $online->sendPopup($popup);
                    $this->playSound($online, 'random.orb', 1.0, 0.6);
                }
            }
        }), $dropInterval * 20);
        // Handle players who are already online (e.g. plugin enabled after startup).
        foreach($this->getServer()->getOnlinePlayers() as $online){
            $this->database->ensurePlayer($online->getName());
            $this->joinTimes[strtolower($online->getName())] ??= time();
            $this->scoreboards->show($online);
        }
        $this->getLogger()->info('SmpMaster v' . $this->getDescription()->getVersion() . ' | database: ' . $this->getDataFolder() . 'smpmaster.sqlite3');
        $this->getLogger()->info('SmpMaster enabled. Resources loaded; RTP, economy, scoreboard and timed ground-item cleanup are ready.');
    }

    protected function onDisable() : void{
        foreach(array_keys($this->countdowns) as $countdownKey){
            $this->cancelCountdown((string)$countdownKey);
        }
        $this->getScheduler()->cancelAllTasks();
        foreach($this->registeredCommands as $registered){
            $this->getServer()->getCommandMap()->unregister($registered);
        }
        $this->registeredCommands = [];
        if(isset($this->joinTimes, $this->database)){
            foreach($this->joinTimes as $name => $started){
                $this->database->addStat($name, 'playtime', max(0, time() - $started));
            }
        }
        if(isset($this->database)) $this->database->close();
    }

    private function registerCommands() : void{
        $commands = [
            ['shop', 'Open the SmpMaster shop', [], 'smpmaster.command.shop'],
            ['sell', 'Open the sell menu', [], 'smpmaster.command.sell'],
            ['sellhand', 'Sell held item', [], 'smpmaster.command.sellhand'],
            ['sellall', 'Sell all sellable items', [], 'smpmaster.command.sellall'],
            ['sellhistory', 'View sell history', [], 'smpmaster.command.sellhistory'],
            ['sellmulti', 'View sell multiplier', ['sellmultiplier', 'sellprogress'], 'smpmaster.command.sellmulti'],
            ['ah', 'Open the auction house', ['auctionhouse'], 'smpmaster.command.ah'],
            ['worth', 'View item prices', ['prices'], 'smpmaster.command.worth'],
            ['money', 'View money balance', ['balance', 'bal'], 'smpmaster.command.money'],
            ['pay', 'Pay a player', [], 'smpmaster.command.pay'],
            ['setmoney', 'Set player balance', [], 'smpmaster.admin.money'],
            ['addmoney', 'Add money to player', [], 'smpmaster.admin.money'],
            ['removemoney', 'Remove money from player', [], 'smpmaster.admin.money'],
            ['shards', 'View shard balance', [], 'smpmaster.command.shards'],
            ['shardpay', 'Send shards to player', [], 'smpmaster.command.shardpay'],
            ['setshards', 'Set player shards', [], 'smpmaster.admin.shards'],
            ['bounty', 'Manage player bounties', [], 'smpmaster.command.bounty'],
            ['stats', 'View player stats', [], 'smpmaster.command.stats'],
            ['leaderboard', 'View leaderboards', ['lb', 'top', 'baltop'], 'smpmaster.command.leaderboard'],
            ['sethome', 'Set a home', ['sehome'], 'smpmaster.command.sethome'],
            ['home', 'Teleport home', [], 'smpmaster.command.home'],
            ['delhome', 'Delete a home', [], 'smpmaster.command.delhome'],
            ['setwarp', 'Set a public warp', [], 'smpmaster.admin.warp'],
            ['warp', 'Teleport to a warp', [], 'smpmaster.command.warp'],
            ['delwarp', 'Delete a warp', [], 'smpmaster.admin.warp'],
            ['warps', 'List public warps', [], 'smpmaster.command.warp'],
            ['setspawn', 'Set server spawn', [], 'smpmaster.admin.spawn'],
            ['spawn', 'Teleport to spawn', [], 'smpmaster.command.spawn'],
            ['rtp', 'Random teleport', [], 'smpmaster.command.rtp'],
            ['tpa', 'Request player teleport', [], 'smpmaster.command.tpa'],
            ['tpaccept', 'Accept teleport request', [], 'smpmaster.command.tpa'],
            ['tpdeny', 'Deny teleport request', [], 'smpmaster.command.tpa'],
            ['team', 'Create and manage teams', [], 'smpmaster.command.team'],
            ['crates', 'View crates', [], 'smpmaster.command.crates'],
            ['crate', 'Open a crate', [], 'smpmaster.command.crates'],
            ['keyall', 'Give keys to online players', [], 'smpmaster.admin.crates'],
            ['report', 'Report a player to staff', [], 'smpmaster.command.report'],
            ['helpop', 'Ask online staff for help', [], 'smpmaster.command.helpop'],
            ['cleardrops', 'Remove dropped item entities', [], 'smpmaster.admin.cleardrops'],
            ['smp', 'Help and reload settings', [], 'smpmaster.command.smp']
        ];
        foreach($commands as [$name, $description, $aliases, $permission]){
            $command = new SmpCommand($this, $name, $description, $aliases, $permission);
            $this->registeredCommands[] = $command;
            $this->getServer()->getCommandMap()->register('smpmaster', $command);
        }
    }

    // ------------------------------------------------------------------
    // Helpers: sounds, scoreboard refresh, worlds, scoreboard config migration
    // ------------------------------------------------------------------

    /** Plays a built-in Bedrock sound to one player only. Never throws. */
    private function playSound(Player $player, string $sound, float $pitch = 1.0, float $volume = 1.0) : void{
        try{
            if(!$player->isConnected()) return;
            $pos = $player->getPosition();
            $player->getNetworkSession()->sendDataPacket(PlaySoundPacket::create($sound, $pos->getX(), $pos->getY(), $pos->getZ(), $volume, $pitch));
        }catch(\Throwable $e){
            $this->getLogger()->debug('Could not play sound ' . $sound . ': ' . $e->getMessage());
        }
    }

    private function refreshScoreboardFor(string $name) : void{
        $online = $this->online($name);
        if($online !== null && $online->isConnected() && isset($this->scoreboards)) $this->scoreboards->update($online);
    }

    private function resolveWorld(string $folderName) : ?World{
        try{
            $manager = $this->getServer()->getWorldManager();
            $world = $manager->getWorldByName($folderName);
            if($world === null){
                $manager->loadWorld($folderName);
                $world = $manager->getWorldByName($folderName);
            }
            return $world;
        }catch(\Throwable $e){
            $this->getLogger()->warning('Could not load world ' . $folderName . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Adds the %team% and %ping% rows to scoreboard.yml files created by older versions (backup kept). */
    private function migrateScoreboardLines() : void{
        if((int)$this->scoreboardConfig->get('lines-version', 1) >= 2) return;
        $lines = $this->scoreboardConfig->get('lines', []);
        if(!is_array($lines) || $lines === []) return;
        $joined = implode("\n", array_map('strval', $lines));
        $hasTeam = str_contains($joined, '%team%');
        $hasPing = str_contains($joined, '%ping%');
        if(!$hasTeam || !$hasPing){
            @copy($this->getDataFolder() . 'scoreboard.yml', $this->getDataFolder() . 'scoreboard.yml.bak-0.4.4');
            $new = array_values($lines);
            $insert = [];
            if(!$hasTeam) $insert[] = '§bTeam: %team%';
            if(!$hasPing) $insert[] = '§aPing: %ping%ms';
            $at = null;
            foreach($new as $i => $line){ if(str_contains((string)$line, '%bounty%')) $at = $i; }
            if($at === null){ foreach($new as $i => $line){ if(str_contains((string)$line, '%kills%')) $at = $i; } }
            if($at === null) $at = max(0, count($new) - 2);
            array_splice($new, $at + 1, 0, $insert);
            $this->scoreboardConfig->set('lines', array_values($new));
            $this->getLogger()->info('scoreboard.yml upgraded: added Team/Ping rows (backup: scoreboard.yml.bak-0.4.4).');
        }
        $this->scoreboardConfig->set('lines-version', 2);
        $this->scoreboardConfig->save();
    }

    // ------------------------------------------------------------------
    // Teleport countdown (one active countdown per player)
    // ------------------------------------------------------------------

    /**
     * Starts a countdown, then runs $action (which performs the real teleport and returns true on success).
     * Any previous countdown of the same player is cancelled first, so only one is ever active.
     */
    private function startTeleportCountdown(Player $player, string $label, \Closure $action) : void{
        $key = strtolower($player->getName());
        if(isset($this->countdowns[$key])){
            $this->cancelCountdown($key, '§eYour previous teleport was cancelled because you started a new one.');
        }
        $seconds = max(1, min(30, (int)$this->getConfig()->getNested('settings.teleport.countdown-seconds', 5)));
        $pos = $player->getPosition();
        $id = ++$this->countdownSeq;
        $this->countdowns[$key] = [
            'id' => $id, 'player' => $player, 'handler' => null,
            'world' => $pos->getWorld()->getFolderName(),
            'x' => $pos->getX(), 'y' => $pos->getY(), 'z' => $pos->getZ(),
            'remaining' => $seconds, 'label' => $label, 'action' => $action
        ];
        $this->msg($player, 'Teleporting to §e' . $label . '§f in §e' . $seconds . '§f seconds. §cDo not move!');
        $player->sendTip('§eTeleporting in §6' . $seconds . '§e...');
        $this->playSound($player, 'random.click', 1.0, 1.0);
        $handler = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use($key, $id) : void{
            $this->tickCountdown($key, $id);
        }), 20);
        // The task cannot run before this method returns, so the handler is always stored in time.
        if(isset($this->countdowns[$key]) && $this->countdowns[$key]['id'] === $id){
            $this->countdowns[$key]['handler'] = $handler;
        }else{
            $handler->cancel();
        }
    }

    private function tickCountdown(string $key, int $id) : void{
        $state = $this->countdowns[$key] ?? null;
        if($state === null || $state['id'] !== $id){
            return; // stale task; it was already replaced or cancelled
        }
        $player = $state['player'];
        if(!$player->isConnected() || !$player->isAlive()){
            $this->cancelCountdown($key);
            return;
        }
        $pos = $player->getPosition();
        if($this->movedFromOrigin($state, $pos->getWorld()->getFolderName(), $pos->getX(), $pos->getY(), $pos->getZ())){
            $this->cancelCountdown($key, '§cTeleport cancelled because you moved.');
            return;
        }
        $remaining = $state['remaining'] - 1;
        if($remaining > 0){
            $this->countdowns[$key]['remaining'] = $remaining;
            $player->sendTip('§eTeleporting in §6' . $remaining . '§e...');
            $this->playSound($player, 'random.click', 1.0 + (1.0 - $remaining / max(1, $state['remaining'])) * 0.4, 1.0);
            return;
        }
        // Countdown finished: clear state first so the action can safely start another countdown.
        $action = $state['action'];
        $label = $state['label'];
        $this->cancelCountdown($key);
        try{
            $ok = (bool)$action();
        }catch(\Throwable $e){
            $ok = false;
            $this->getLogger()->error('Teleport of ' . $player->getName() . ' to ' . $label . ' failed: ' . $e->getMessage());
            $this->msg($player, 'Teleport failed safely. Check the server log.');
        }
        if($ok){
            $this->playSound($player, 'mob.endermen.portal', 1.0, 1.0);
            $player->sendTip('§aTeleported!');
        }else{
            $this->playSound($player, 'note.bass', 0.8, 1.0);
        }
    }

    /** Only position counts as movement; yaw/pitch (camera) changes are ignored. */
    private function movedFromOrigin(array $state, string $world, float $x, float $y, float $z) : bool{
        if($world !== $state['world']) return true;
        $dx = $x - $state['x']; $dy = $y - $state['y']; $dz = $z - $state['z'];
        return ($dx * $dx + $dy * $dy + $dz * $dz) > 0.04; // more than 0.2 block
    }

    /** Cancels a player's countdown (if any). A message is shown only if one is supplied. */
    private function cancelCountdown(string $key, ?string $message = null) : void{
        $state = $this->countdowns[$key] ?? null;
        if($state === null) return;
        unset($this->countdowns[$key]);
        $handler = $state['handler'];
        if($handler instanceof TaskHandler && !$handler->isCancelled()) $handler->cancel();
        $player = $state['player'];
        if($message !== null && $player->isConnected()){
            $this->msg($player, $message);
            $player->sendTip('§cTeleport cancelled');
            $this->playSound($player, 'note.bass', 0.8, 1.0);
        }
    }

    /** @priority MONITOR */
    public function onMove(PlayerMoveEvent $event) : void{
        if($this->countdowns === []) return;
        $key = strtolower($event->getPlayer()->getName());
        $state = $this->countdowns[$key] ?? null;
        if($state === null) return;
        $to = $event->getTo();
        if($this->movedFromOrigin($state, $to->getWorld()->getFolderName(), $to->getX(), $to->getY(), $to->getZ())){
            $this->cancelCountdown($key, '§cTeleport cancelled because you moved.');
        }
    }

    public function getDatabase() : Database{ return $this->database; }
    public function getScoreboardConfig() : Config{ return $this->scoreboardConfig; }

    public function formatMoney(float $amount) : string{
        $places = max(0, min(4, (int)$this->getConfig()->getNested('settings.decimal-places', 2)));
        return number_format($amount, $places, '.', ',');
    }

    private function prefix() : string{ return (string)$this->getConfig()->getNested('settings.prefix', '§8[§6SmpMaster§8] §r'); }
    private function msg(CommandSender $sender, string $message) : void{ $sender->sendMessage($this->prefix() . $message); }
    private function isPlayer(CommandSender $sender) : bool{
        if($sender instanceof Player) return true;
        $this->msg($sender, (string)$this->getConfig()->getNested('messages.player-only', '§cThis command can only be used in-game.'));
        return false;
    }
    private function online(string $name) : ?Player{ return $this->getServer()->getPlayerExact($name); }
    private function validAmount(string $raw) : ?float{
        if(!is_numeric($raw)) return null;
        $value = (float)$raw;
        return is_finite($value) && $value > 0 && $value <= 1.0e12 ? $value : null;
    }

    public function handleCommand(CommandSender $sender, string $command, array $args) : void{
        $feature = match(strtolower($command)){
            'shop' => 'shop',
            'sell', 'sellhand', 'sellall', 'sellhistory', 'sellmulti', 'worth' => 'sell',
            'ah' => 'auction-house',
            'money', 'pay', 'setmoney', 'addmoney', 'removemoney' => 'economy',
            'shards', 'shardpay', 'setshards' => 'shards',
            'bounty' => 'bounty',
            'stats', 'leaderboard' => 'stats',
            'sethome', 'home', 'delhome' => 'homes',
            'setwarp', 'warp', 'delwarp', 'warps', 'setspawn', 'spawn' => 'warps',
            'rtp' => 'rtp',
            'tpa', 'tpaccept', 'tpdeny' => 'tpa',
            'team' => 'teams',
            'crate', 'crates', 'keyall' => 'crates',
            'cleardrops' => 'drop-cleaner',
            default => null
        };
        if($feature !== null && !$this->getConfig()->getNested('features.' . $feature, true)){
            $featureNames = ['shop' => 'Shop', 'sell' => 'Sell', 'auction-house' => 'Auction House', 'economy' => 'Economy', 'shards' => 'Shards', 'bounty' => 'Bounties', 'stats' => 'Stats', 'homes' => 'Homes', 'warps' => 'Warps', 'rtp' => 'Random Teleport', 'tpa' => 'Teleport Requests', 'teams' => 'Teams', 'crates' => 'Crates', 'drop-cleaner' => 'Drop Cleaner'];
            $label = $featureNames[$feature] ?? $feature;
            $this->msg($sender, 'The ' . $label . ' feature is disabled in config.yml.');
            return;
        }
        switch(strtolower($command)){
            case 'shop': $this->openShop($sender); break;
            case 'sell': $this->openSellMenu($sender); break;
            case 'sellhand':
                if(!$this->isPlayer($sender)) return;
                $count = isset($args[0]) && ctype_digit((string)$args[0]) ? max(1, (int)$args[0]) : null;
                $this->sellHand($sender, $count); break;
            case 'sellall': if($this->isPlayer($sender)) $this->sellInventory($sender); break;
            case 'sellhistory': if($this->isPlayer($sender)) $this->showSellHistory($sender); break;
            case 'sellmulti': $this->showSellMultiplier($sender); break;
            case 'ah': if($this->isPlayer($sender)) $this->auctionCommand($sender, $args); break;
            case 'worth': $this->openWorth($sender, $args); break;
            case 'money': $this->moneyCommand($sender, $args); break;
            case 'pay': $this->payCommand($sender, $args); break;
            case 'setmoney': $this->adminMoney($sender, $args, 'set'); break;
            case 'addmoney': $this->adminMoney($sender, $args, 'add'); break;
            case 'removemoney': $this->adminMoney($sender, $args, 'remove'); break;
            case 'shards': $this->shardsCommand($sender, $args); break;
            case 'shardpay': $this->shardPayCommand($sender, $args); break;
            case 'setshards': $this->setShardsCommand($sender, $args); break;
            case 'bounty': $this->bountyCommand($sender, $args); break;
            case 'stats': $this->statsCommand($sender, $args); break;
            case 'leaderboard': $this->leaderboardCommand($sender, $args); break;
            case 'sethome':
                if($this->isPlayer($sender)){
                    try{ $this->setHomeCommand($sender, $args); }
                    catch(\Throwable $e){ $this->getLogger()->error('sethome failed for ' . $sender->getName() . ': ' . $e->getMessage()); $this->msg($sender, 'Could not save this home. Check the server log for details.'); }
                }
                break;
            case 'home':
                if($this->isPlayer($sender)){
                    try{ $this->homeCommand($sender, $args); }
                    catch(\Throwable $e){ $this->getLogger()->error('home failed for ' . $sender->getName() . ': ' . $e->getMessage()); $this->msg($sender, 'Teleport failed safely. Check the server log for details.'); }
                }
                break;
            case 'delhome':
                if($this->isPlayer($sender)){
                    try{ $this->deleteHomeCommand($sender, $args); }
                    catch(\Throwable $e){ $this->getLogger()->error('delhome failed for ' . $sender->getName() . ': ' . $e->getMessage()); $this->msg($sender, 'Could not delete this home. Check the server log for details.'); }
                }
                break;
            case 'setwarp': if($this->isPlayer($sender)) $this->setWarpCommand($sender, $args); break;
            case 'warp': if($this->isPlayer($sender)) $this->warpCommand($sender, $args); break;
            case 'delwarp': $this->deleteWarpCommand($sender, $args); break;
            case 'warps': $this->listWarps($sender); break;
            case 'setspawn': if($this->isPlayer($sender)) $this->setSpawnCommand($sender); break;
            case 'spawn': if($this->isPlayer($sender)) $this->teleportSpawn($sender); break;
            case 'rtp': if($this->isPlayer($sender)) $this->randomTeleport($sender); break;
            case 'tpa': if($this->isPlayer($sender)) $this->tpaCommand($sender, $args); break;
            case 'tpaccept': if($this->isPlayer($sender)) $this->tpAcceptCommand($sender); break;
            case 'tpdeny': if($this->isPlayer($sender)) $this->tpDenyCommand($sender); break;
            case 'team': if($this->isPlayer($sender)) $this->teamCommand($sender, $args); break;
            case 'crates': if($this->isPlayer($sender)) $this->openCrates($sender); break;
            case 'crate': if($this->isPlayer($sender)) $this->crateCommand($sender, $args); break;
            case 'keyall': $this->keyAllCommand($sender, $args); break;
            case 'cleardrops': $this->clearDroppedItemsCommand($sender); break;
            case 'report': $this->reportCommand($sender, $args); break;
            case 'helpop': $this->helpopCommand($sender, $args); break;
            case 'smp': $this->smpCommand($sender, $args); break;
            default: $this->msg($sender, 'Use §e/smp help §f to view commands.');
        }
    }

    private function openShop(CommandSender $sender) : void{
        if(!$sender instanceof Player){ $this->msg($sender, 'The shop can only be opened in-game.'); return; }
        if(!$this->getConfig()->getNested('features.shop', true)){ $this->msg($sender, 'The shop is disabled.'); return; }
        $categories = $this->shopConfig->get('categories', []);
        if(!is_array($categories) || $categories === []){
            // Recover even if shop.yml is emptied while the server is running.
            $categories = self::defaultShopCategories();
            $this->shopConfig->set('title', '§l§6SmpMaster §8| §fShop');
            $this->shopConfig->set('categories', $categories);
            $this->shopConfig->save();
            $this->getLogger()->warning('shop.yml was empty at /shop; restored default categories.');
        }
        $keys = array_keys($categories);
        $buttons = [];
        foreach($categories as $category){ $buttons[] = (string)($category['name'] ?? 'Category'); }
        $sender->sendForm(new SimpleForm((string)$this->shopConfig->get('title', '§l§6SmpMaster Shop'), "Select a category.\nBalance: $" . $this->formatMoney($this->database->getBalance($sender->getName())), $buttons, function(Player $player, int $choice) use ($keys, $categories) : void{
            $key = $keys[$choice] ?? null;
            if($key === null || !isset($categories[$key])) return;
            $this->openShopCategory($player, (string)$key, (array)$categories[$key]);
        }));
    }

    private function openShopCategory(Player $player, string $key, array $category) : void{
        $items = array_values(array_filter((array)($category['items'] ?? []), 'is_array'));
        if($items === []){ $this->msg($player, 'This category has no products.'); return; }
        $buttons = [];
        foreach($items as $item){
            $name = (string)($item['name'] ?? $item['id'] ?? 'Unknown item');
            $price = max(0.0, (float)($item['price'] ?? 0));
            $buttons[] = $name . "\nPrice: $" . $this->formatMoney($price) . ' | Buy';
        }
        $buttons[] = '§cBack';
        $player->sendForm(new SimpleForm((string)($category['name'] ?? 'Shop'), 'Select an item to purchase.', $buttons, function(Player $p, int $choice) use ($items) : void{
            if($choice === count($items)) { $this->openShop($p); return; }
            if(!isset($items[$choice])) return;
            $this->openPurchaseMenu($p, $items[$choice]);
        }));
    }

    private function openPurchaseMenu(Player $player, array $product) : void{
        $id = (string)($product['id'] ?? '');
        $base = max(0.0, (float)($product['price'] ?? 0));
        $name = (string)($product['name'] ?? $id);
        $quantities = array_values(array_filter(array_map('intval', (array)$this->getConfig()->getNested('settings.shop.purchase-quantities', [1, 16, 64])), static fn(int $quantity) : bool => $quantity > 0 && $quantity <= 2304));
        if($quantities === []) $quantities = [1, 16, 64];
        $buttons = [];
        foreach($quantities as $quantity){ $buttons[] = '§aBuy x' . $quantity . "\nTotal: $" . $this->formatMoney($base * $quantity); }
        $buttons[] = '§cCancel';
        $player->sendForm(new SimpleForm('§l§6Confirm Purchase', $name . "\nYour balance: $" . $this->formatMoney($this->database->getBalance($player->getName())), $buttons, function(Player $p, int $choice) use ($product, $quantities) : void{
            if(!isset($quantities[$choice])) return;
            $this->buyProduct($p, $product, $quantities[$choice]);
        }));
    }

    private function buyProduct(Player $player, array $product, int $quantity) : void{
        $id = trim((string)($product['id'] ?? ''));
        $item = StringToItemParser::getInstance()->parse($id);
        if($item === null){ $this->msg($player, 'Invalid item ID in shop.yml: ' . $id); return; }
        $price = max(0.0, (float)($product['price'] ?? 0)) * $quantity;
        if($price <= 0){ $this->msg($player, 'This item has an invalid price.'); return; }
        if($this->database->getBalance($player->getName()) < $price){ $this->msg($player, 'Insufficient funds. You need $' . $this->formatMoney($price) . '  more.'); return; }
        $item->setCount($quantity);
        if(!$player->getInventory()->canAddItem($item)){ $this->msg($player, 'Your inventory is full.'); return; }
        $this->database->addMoney($player->getName(), -$price);
        $player->getInventory()->addItem($item);
        $this->scoreboards->update($player);
        $this->msg($player, 'Purchased: ' . $quantity . ' x' . (string)($product['name'] ?? $id) . ' | Amount: $' . $this->formatMoney($price) . ' | Balance: $' . $this->formatMoney($this->database->getBalance($player->getName())) . '.');
    }

    private function openSellMenu(CommandSender $sender) : void{
        if(!$sender instanceof Player){ $this->msg($sender, 'This menu can only be opened in-game.'); return; }
        if(!$this->getConfig()->getNested('features.sell', true)){ $this->msg($sender, 'The sell system is disabled.'); return; }
        $buttons = ['§aSell all sellable items', '§eSell held item', '§bCheck held item price', '§dSell history', '§cClose'];
        $content = 'Balance: $' . $this->formatMoney($this->database->getBalance($sender->getName())) . "\nSell multiplier: " . number_format($this->sellMultiplier($sender->getName()), 2) . 'x';
        $sender->sendForm(new SimpleForm('§l§6SmpMaster | Sell', $content, $buttons, function(Player $player, int $choice) : void{
            switch($choice){
                case 0: $this->sellInventory($player); break;
                case 1: $this->sellHand($player, null); break;
                case 2: $this->openWorth($player, ['hand']); break;
                case 3: $this->showSellHistory($player); break;
                default: break;
            }
        }));
    }

    /** Returns configured pricing first, then an automatic survival-item price. */
    private function getWorthEntry(Item $item) : ?array{
        if($item->isNull()) return null;

        $name = $item->getVanillaName();
        $normalizedName = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? $name, '_'));
        // Never allow creative/admin-only blocks to be sold, even if added to worth.yml.
        $protected = ['air', 'bedrock', 'barrier', 'command_block', 'repeating_command_block', 'chain_command_block', 'structure_block', 'structure_void', 'jigsaw', 'light', 'light_block'];
        $blockedNames = array_merge($protected, (array)$this->worthConfig->get('excluded-items', []));
        foreach($blockedNames as $blocked){
            $blocked = strtolower(trim((string)$blocked));
            $blockedNormalized = trim(preg_replace('/[^a-z0-9]+/i', '_', $blocked) ?? $blocked, '_');
            if($blockedNormalized !== '' && ($normalizedName === $blockedNormalized || str_contains($normalizedName, $blockedNormalized))) return null;
        }

        $entries = (array)$this->worthConfig->get('items', []);
        foreach($entries as $id => $entry){
            if(!is_array($entry)) continue;
            $parsed = StringToItemParser::getInstance()->parse((string)$id);
            if($parsed !== null && $parsed->getTypeId() === $item->getTypeId()){
                return ['id' => (string)$id, 'name' => (string)($entry['name'] ?? $name), 'buy' => (float)($entry['buy'] ?? 0), 'sell' => max(0.0, (float)($entry['sell'] ?? 0))] + $entry;
            }
        }

        $defaultSell = max(0.0, (float)$this->worthConfig->get('default-sell', 0.25));
        $autoPrices = (array)$this->worthConfig->get('auto-prices', []);
        foreach($autoPrices as $keyword => $price){
            $needle = strtolower(trim((string)$keyword));
            if($needle !== '' && $needle !== 'default' && str_contains($normalizedName, $needle)){
                $defaultSell = max(0.0, (float)$price);
                break;
            }
        }
        if($defaultSell <= 0) return null;
        return [
            'id' => 'auto:' . $normalizedName,
            'name' => $name,
            'buy' => max(0.0, (float)$this->worthConfig->get('default-buy', 0)),
            'sell' => $defaultSell,
            'category' => 'automatic'
        ];
    }

    /** Adds new safe default prices to older config files without overwriting existing custom prices. */
    private function migrateWorthDefaults() : void{
        $defaults = [
            'netherite_upgrade_smithing_template' => 600, 'netherite_ingot' => 400, 'netherite' => 250,
            'elytra' => 1000, 'nether_star' => 500, 'beacon' => 2000, 'ancient_debris' => 150,
            'totem_of_undying' => 200, 'trident' => 150, 'diamond_sword' => 70, 'diamond_pickaxe' => 90,
            'diamond' => 40, 'emerald' => 25, 'shulker' => 25, 'gold_ingot' => 4, 'gold' => 2,
            'iron_ingot' => 2, 'iron' => 1, 'copper' => 0.75, 'coal' => 0.5, 'wheat' => 0.5,
            'carrot' => 0.5, 'potato' => 0.5, 'sugar_cane' => 0.5, 'oak_log' => 0.5, 'log' => 0.4,
            'default' => 0.25
        ];
        $autoPrices = (array)$this->worthConfig->get('auto-prices', []);
        foreach($defaults as $key => $value){ if(!array_key_exists($key, $autoPrices)) $autoPrices[$key] = $value; }
        $this->worthConfig->set('auto-prices', $autoPrices);
        if(!$this->worthConfig->exists('default-buy')) $this->worthConfig->set('default-buy', 0);
        if(!$this->worthConfig->exists('default-sell')) $this->worthConfig->set('default-sell', 0.25);
        $protected = ['air', 'bedrock', 'barrier', 'command_block', 'repeating_command_block', 'chain_command_block', 'structure_block', 'structure_void', 'jigsaw', 'light', 'light_block'];
        $excluded = array_values(array_unique(array_merge($protected, (array)$this->worthConfig->get('excluded-items', []))));
        $this->worthConfig->set('excluded-items', $excluded);
        $this->worthConfig->save();
    }

    private function sellMultiplier(string $player) : float{
        $total = $this->database->getSoldTotal($player);
        $thresholds = (array)$this->getConfig()->getNested('settings.sell.multiplier-thresholds', [0 => 1.0]);
        uksort($thresholds, static fn($a, $b) => (float)$a <=> (float)$b);
        $multiplier = (float)$this->getConfig()->getNested('settings.sell.default-multiplier', 1.0);
        foreach($thresholds as $threshold => $value){ if($total >= (float)$threshold) $multiplier = max(0.1, (float)$value); }
        return $multiplier;
    }

    private function sellHand(Player $player, ?int $requestedAmount) : void{
        $item = $player->getInventory()->getItemInHand();
        if($item->isNull()){ $this->msg($player, 'Hold an item first.'); return; }
        $entry = $this->getWorthEntry($item);
        if($entry === null || (float)($entry['sell'] ?? 0) <= 0){ $this->msg($player, 'This item cannot be sold.'); return; }
        $amount = min($item->getCount(), max(1, $requestedAmount ?? $item->getCount()));
        $multiplier = $this->sellMultiplier($player->getName());
        $price = (float)$entry['sell'] * $amount * $multiplier;
        $remainingCount = $item->getCount() - $amount;
        if($remainingCount <= 0){
            $player->getInventory()->setItemInHand(VanillaItems::AIR());
        }else{
            $remaining = clone $item;
            $remaining->setCount($remainingCount);
            $player->getInventory()->setItemInHand($remaining);
        }
        $this->database->addMoney($player->getName(), $price);
        $this->database->addSale($player->getName(), (string)($entry['name'] ?? $entry['id']), $amount, $price);
        $this->scoreboards->update($player);
        $this->msg($player, 'Sold: ' . $amount . ' x' . (string)($entry['name'] ?? $entry['id']) . ' | Payout: $' . $this->formatMoney($price) . ' | Balance: $' . $this->formatMoney($this->database->getBalance($player->getName())) . ' | Multiplier: ' . number_format($multiplier, 2) . 'x');
    }

    private function sellInventory(Player $player) : void{
        $inventory = $player->getInventory();
        $multiplier = $this->sellMultiplier($player->getName());
        $total = 0.0;
        $soldItems = [];
        foreach($inventory->getContents() as $slot => $item){
            if($item->isNull()) continue;
            $entry = $this->getWorthEntry($item);
            $sell = (float)($entry['sell'] ?? 0);
            if($entry === null || $sell <= 0) continue;
            $amount = $item->getCount();
            $payout = $sell * $amount * $multiplier;
            $total += $payout;
            $soldItems[] = ['slot' => $slot, 'name' => (string)($entry['name'] ?? $entry['id']), 'amount' => $amount, 'payout' => $payout];
        }
        if($soldItems === []){ $this->msg($player, 'No sellable items were found in your inventory.'); return; }
        foreach($soldItems as $sold){
            $inventory->setItem((int)$sold['slot'], VanillaItems::AIR());
            $this->database->addSale($player->getName(), (string)$sold['name'], (int)$sold['amount'], (float)$sold['payout']);
        }
        $this->database->addMoney($player->getName(), $total);
        $this->scoreboards->update($player);
        $this->msg($player, count($soldItems) . ' item stack(s) sold | Payout: $' . $this->formatMoney($total) . ' | Balance: $' . $this->formatMoney($this->database->getBalance($player->getName())) . ' | Multiplier: ' . number_format($multiplier, 2) . 'x');
    }

    private function openWorth(CommandSender $sender, array $args) : void{
        if($sender instanceof Player && isset($args[0]) && strtolower((string)$args[0]) === 'hand'){
            $item = $sender->getInventory()->getItemInHand();
            $entry = $item->isNull() ? null : $this->getWorthEntry($item);
            if($entry === null){ $this->msg($sender, 'No sell price is defined for this item.'); return; }
            $unit = (float)($entry['sell'] ?? 0);
            $total = $unit * $item->getCount() * $this->sellMultiplier($sender->getName());
            $this->msg($sender, (string)($entry['name'] ?? $entry['id']) . ' | Unit price: $' . $this->formatMoney($unit) . ' | Amount: ' . $item->getCount() . ' | Total with multiplier: $' . $this->formatMoney($total));
            return;
        }
        $items = (array)$this->worthConfig->get('items', []);
        $defaultSell = (float)$this->worthConfig->get('default-sell', 0.25);
        $text = 'Configured prices: ' . count($items) . "\nAutomatic price for most common items: $" . $this->formatMoney($defaultSell) . "\nUse /worth hand to see the exact price of your held item.";
        if(!$sender instanceof Player){ $this->msg($sender, $text); return; }
        $buttons = [];
        foreach($items as $id => $entry){
            if(!is_array($entry)) continue;
            $buttons[] = (string)($entry['name'] ?? $id) . "\nBuy: $" . $this->formatMoney((float)($entry['buy'] ?? 0)) . ' | Sell: $' . $this->formatMoney((float)($entry['sell'] ?? 0));
        }
        if($buttons === []) $buttons[] = 'Automatic price: $' . $this->formatMoney($defaultSell) . ' per common item';
        $sender->sendForm(new SimpleForm('§l§6Item Prices', $text, $buttons, static function(Player $p, int $choice) : void{}));
    }

    private function showSellHistory(Player $player) : void{
        $history = $this->database->getSellHistory($player->getName(), 8);
        if($history === []){ $this->msg($player, 'No sales recorded yet.'); return; }
        $this->msg($player, 'Recent sales:');
        foreach($history as $sale){
            $this->msg($player, date('m-d H:i', (int)$sale['created_at']) . ' | ' . (int)$sale['amount'] . ' x' . (string)$sale['item'] . ' | +$' . $this->formatMoney((float)$sale['payout']));
        }
    }

    private function auctionCommand(Player $player, array $args) : void{
        if(!$this->auctionConfig->get('enabled', true) || !$this->getConfig()->getNested('features.auction-house', true)){
            $this->msg($player, 'The auction house is disabled.');
            return;
        }
        $this->database->expireAhListings();
        $sub = strtolower((string)($args[0] ?? ''));
        if($sub === 'sell'){
            if(!isset($args[1])){ $this->openAuctionSellForm($player); return; }
            $price = $this->validAmount((string)$args[1]);
            if(isset($args[2]) && !ctype_digit((string)$args[2])){ $this->msg($player, 'Amount must be a positive whole number. Usage: /ah sell <price> [amount]'); return; }
            $amount = isset($args[2]) ? (int)$args[2] : null;
            if($price === null){ $this->msg($player, 'Usage: /ah sell <price> [amount]'); return; }
            $this->createAuctionListing($player, $price, $amount);
            return;
        }
        if($sub === 'my') { $this->showMyAuctionListings($player); return; }
        if($sub === 'claims') { $this->showAuctionClaims($player); return; }
        if($sub === 'cancel'){
            if(!isset($args[1]) || !ctype_digit((string)$args[1])){ $this->msg($player, 'Usage: /ah cancel <id>'); return; }
            $this->cancelAuctionListing($player, (int)$args[1]);
            return;
        }
        if($sub === 'help'){
            $this->msg($player, 'Commands: /ah | /ah sell <price> [amount] | /ah my | /ah claims | /ah cancel <id>');
            return;
        }
        $this->openAuctionMenu($player);
    }

    private function openAuctionMenu(Player $player) : void{
        $count = $this->database->countAhListings();
        $buttons = ['§aBrowse listings (' . $count . ')', '§eList held item', '§bMy Listings', '§dClaim items', '§7Help and commands', '§cClose'];
        $body = 'Player-driven marketplace. Items are listed at fixed prices.' . "\n" . 'Your balance: $' . $this->formatMoney($this->database->getBalance($player->getName()));
        $player->sendForm(new SimpleForm('§l§6SmpMaster Market', $body, $buttons, function(Player $p, int $choice) : void{
            switch($choice){
                case 0: $this->showAuctionListings($p, 0); break;
                case 1: $this->openAuctionSellForm($p); break;
                case 2: $this->showMyAuctionListings($p); break;
                case 3: $this->showAuctionClaims($p); break;
                case 4: $this->msg($p, 'Commands: /ah | /ah sell <price> [amount] | /ah my | /ah claims | /ah cancel <id>'); break;
                default: break;
            }
        }));
    }

    private function openAuctionSellForm(Player $player) : void{
        $held = $player->getInventory()->getItemInHand();
        if($held->isNull()){ $this->msg($player, 'Hold the item you want to list first.'); return; }
        $player->sendForm(new ModalForm(
            'Create Listing',
            'Enter the total listing price. This price applies to the full listed amount. Expired items are returned to your claims.',
            [
                ['label' => 'Total price (e.g. 100)', 'placeholder' => '100', 'default' => ''],
                ['label' => 'Amount to list', 'placeholder' => (string)$held->getCount(), 'default' => (string)$held->getCount()]
            ],
            function(Player $p, array $data) : void{
                $price = $this->validAmount((string)($data[0] ?? ''));
                $rawAmount = (string)($data[1] ?? '');
                if($price === null || !ctype_digit($rawAmount) || (int)$rawAmount < 1){
                    $this->msg($p, 'Price and amount must be valid positive numbers.');
                    return;
                }
                $this->createAuctionListing($p, $price, (int)$rawAmount);
            }
        ));
    }

    private function createAuctionListing(Player $player, float $price, ?int $requestedAmount) : void{
        $minimum = max(0.01, (float)$this->auctionConfig->get('minimum-price', 1));
        $maximum = max($minimum, (float)$this->auctionConfig->get('maximum-price', 1.0e12));
        if($price < $minimum || $price > $maximum){
            $this->msg($player, 'Price must be between $' . $this->formatMoney($minimum) . '  and $' . $this->formatMoney($maximum) . '  .');
            return;
        }
        $limit = max(1, min(36, (int)$this->auctionConfig->get('listing-limit-per-player', 10)));
        if($this->database->getAhListingCountBySeller($player->getName()) >= $limit){
            $this->msg($player, 'You have reached your active listing limit. Cancel a listing first.');
            return;
        }
        $held = $player->getInventory()->getItemInHand();
        if($held->isNull()){ $this->msg($player, 'You are not holding an item.'); return; }
        $amount = $requestedAmount ?? $held->getCount();
        if($amount < 1 || $amount > $held->getCount()){
            $this->msg($player, 'Amount must be between 1 and ' . $held->getCount() . '  .');
            return;
        }
        $itemName = $held->getVanillaName();
        foreach((array)$this->auctionConfig->get('blocked-item-names', []) as $blocked){
            if(strcasecmp(trim((string)$blocked), trim($itemName)) === 0){
                $this->msg($player, 'This item is not allowed in the auction house.');
                return;
            }
        }
        $listingItem = clone $held;
        $listingItem->setCount($amount);
        try{
            $payload = base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($listingItem->nbtSerialize())));
            $hours = max(1, min(8760, (int)$this->auctionConfig->get('listing-lifetime-hours', 168)));
            $id = $this->database->createAhListing($player->getName(), $price, $payload, $itemName, $amount, time() + ($hours * 3600));
        }catch(\Throwable $e){
            $this->getLogger()->error('Could not create auction listing: ' . $e->getMessage());
            $this->msg($player, 'Listing failed. Your item was not removed.');
            return;
        }
        $remainingCount = $held->getCount() - $amount;
        if($remainingCount <= 0){
            $player->getInventory()->setItemInHand(VanillaItems::AIR());
        }else{
            $remaining = clone $held;
            $remaining->setCount($remainingCount);
            $player->getInventory()->setItemInHand($remaining);
        }
        $this->msg($player, 'Listing #' . $id . ' created: ' . $amount . ' x ' . $itemName . ' for a total of $' . $this->formatMoney($price) . '.');
    }

    private function showAuctionListings(Player $player, int $page) : void{
        $limit = max(1, min(36, (int)$this->auctionConfig->get('items-per-page', 18)));
        $offset = max(0, $page) * $limit;
        $listings = $this->database->getAhListings($limit, $offset);
        if($listings === []){
            $this->msg($player, 'There are no active listings right now.');
            $this->openAuctionMenu($player);
            return;
        }
        $buttons = [];
        foreach($listings as $listing){
            $buttons[] = '#' . (int)$listing['id'] . ' | ' . (string)$listing['item_name'] . ' x' . (int)$listing['amount'] . "\nTotal price: $" . $this->formatMoney((float)$listing['price']) . ' | Seller: ' . (string)$listing['seller'];
        }
        $hasNext = $this->database->countAhListings() > ($offset + count($listings));
        if($hasNext) $buttons[] = '§eNext page';
        $buttons[] = '§cBack';
        $player->sendForm(new SimpleForm('§l§6Auction Listings', 'Select a listing to view its details.', $buttons, function(Player $p, int $choice) use($listings, $hasNext, $page) : void{
            if(isset($listings[$choice])){ $this->openAuctionListing($p, (int)$listings[$choice]['id'], $page); return; }
            $nextIndex = count($listings);
            if($hasNext && $choice === $nextIndex){ $this->showAuctionListings($p, $page + 1); return; }
            $this->openAuctionMenu($p);
        }));
    }

    private function openAuctionListing(Player $player, int $id, int $returnPage = 0) : void{
        $listing = $this->database->getAhListing($id);
        if($listing === null){ $this->msg($player, 'This listing is no longer active.'); $this->showAuctionListings($player, $returnPage); return; }
        if(strcasecmp((string)$listing['seller'], $player->getName()) === 0){
            $this->msg($player, 'This is your own listing. Cancel it from My Listings instead.');
            return;
        }
        $item = $this->decodeAuctionItem((string)$listing['item_data']);
        if($item === null || $item->isNull()){ $this->msg($player, 'The item data for this listing is invalid. Purchase cancelled.'); return; }
        $body = 'Item: ' . $item->getName() . "\nAmount: " . $item->getCount() . "\nSeller: " . (string)$listing['seller'] . "\nTotal price: $" . $this->formatMoney((float)$listing['price']) . "\nYour balance: $" . $this->formatMoney($this->database->getBalance($player->getName()));
        $player->sendForm(new SimpleForm('§l§6Listing Details #' . $id, $body, ['§aBuy Listing', '§cBack to Market'], function(Player $p, int $choice) use($id, $returnPage) : void{
            if($choice === 0){ $this->buyAuctionListing($p, $id, $returnPage); }
            else{ $this->showAuctionListings($p, $returnPage); }
        }));
    }

    private function buyAuctionListing(Player $player, int $id, int $returnPage) : void{
        $listing = $this->database->getAhListing($id);
        if($listing === null){ $this->msg($player, 'This listing is no longer available.'); $this->showAuctionListings($player, $returnPage); return; }
        if(strcasecmp((string)$listing['seller'], $player->getName()) === 0){ $this->msg($player, 'You cannot buy your own listing.'); return; }
        if($this->database->getBalance($player->getName()) < (float)$listing['price']){ $this->msg($player, 'Insufficient funds. This listing costs $' . $this->formatMoney((float)$listing['price']) . '.'); return; }
        $bought = $this->database->buyAhListing($id, $player->getName());
        if($bought === null){ $this->msg($player, 'Purchase failed. Check your balance and the listing status.'); return; }
        $this->msg($player, 'Purchase successful: ' . (string)$bought['item_name'] . ' x' . (int)$bought['amount'] . ' for $' . $this->formatMoney((float)$bought['price']) . '. The item is stored in your claims. Use /ah claims.');
        if(($seller = $this->online((string)$bought['seller'])) !== null){
            $this->msg($seller, 'Listing #' . $id . ' sold. $' . $this->formatMoney((float)$bought['price']) . ' was added to your balance.');
        }
    }

    private function showMyAuctionListings(Player $player) : void{
        $listings = $this->database->getAhListingsBySeller($player->getName(), 36);
        if($listings === []){ $this->msg($player, 'You have no active listings.'); $this->openAuctionMenu($player); return; }
        $buttons = [];
        foreach($listings as $listing){
            $buttons[] = '#' . (int)$listing['id'] . ' | ' . (string)$listing['item_name'] . ' x' . (int)$listing['amount'] . "\nTotal price: $" . $this->formatMoney((float)$listing['price']) . ' | Cancel listing';
        }
        $buttons[] = '§cBack';
        $player->sendForm(new SimpleForm('§l§6My Listings', 'Select a listing to cancel and return the item.', $buttons, function(Player $p, int $choice) use($listings) : void{
            if(isset($listings[$choice])){ $this->cancelAuctionListing($p, (int)$listings[$choice]['id']); }
            else{ $this->openAuctionMenu($p); }
        }));
    }

    private function cancelAuctionListing(Player $player, int $id) : void{
        $listing = $this->database->cancelAhListingToClaim($id, $player->getName());
        if($listing === null){ $this->msg($player, 'Listing not found or it does not belong to you.'); return; }
        $this->msg($player, 'Listing cancelled. The item was safely stored in your claims. Use /ah claims.');
    }

    private function showAuctionClaims(Player $player) : void{
        $this->database->expireAhListings();
        $claims = $this->database->getAhClaims($player->getName(), 36);
        if($claims === []){ $this->msg($player, 'You have no items to claim.'); $this->openAuctionMenu($player); return; }
        $buttons = [];
        foreach($claims as $claim){
            $item = $this->decodeAuctionItem((string)$claim['item_data']);
            $description = $item !== null && !$item->isNull() ? $item->getName() . ' x' . $item->getCount() : 'Stored item';
            $reason = match((string)$claim['reason']){ 'expired' => ' | Expired', 'purchase' => ' | Purchased', default => ' | Returned' };
            $buttons[] = '#' . (int)$claim['id'] . ' | ' . $description . $reason;
        }
        $buttons[] = '§cBack';
        $player->sendForm(new SimpleForm('§l§6Items Ready to Claim', 'Make sure you have enough inventory space to claim items.', $buttons, function(Player $p, int $choice) use($claims) : void{
            if(isset($claims[$choice])){ $this->claimAuctionItem($p, (int)$claims[$choice]['id']); }
            else{ $this->openAuctionMenu($p); }
        }));
    }

    private function claimAuctionItem(Player $player, int $id) : void{
        $claims = $this->database->getAhClaims($player->getName(), 72);
        $claim = null;
        foreach($claims as $candidate){ if((int)$candidate['id'] === $id){ $claim = $candidate; break; } }
        if($claim === null){ $this->msg($player, 'This item is no longer available to claim.'); return; }
        $item = $this->decodeAuctionItem((string)$claim['item_data']);
        if($item === null || $item->isNull()){ $this->msg($player, 'This item data could not be read; the claim was not deleted.'); return; }
        if(!$player->getInventory()->canAddItem($item)){ $this->msg($player, 'You do not have enough inventory space.'); return; }
        $removed = $this->database->claimAhItem($id, $player->getName());
        if($removed === null){ $this->msg($player, 'The claim changed before it could be completed. Please try again.'); return; }
        $player->getInventory()->addItem($item);
        $this->msg($player, 'Item claimed: ' . $item->getName() . ' x' . $item->getCount() . '.');
    }

    private function decodeAuctionItem(string $payload) : ?Item{
        $raw = base64_decode($payload, true);
        if($raw === false || $raw === '') return null;
        try{
            $tag = (new LittleEndianNbtSerializer())->read($raw)->mustGetCompoundTag();
            $item = Item::nbtDeserialize($tag);
            return $item->isNull() ? null : $item;
        }catch(\Throwable $e){
            $this->getLogger()->warning('Failed to decode auction item: ' . $e->getMessage());
            return null;
        }
    }

    private function showHeldPrice(Player $player, Item $item) : void{
        if(!$this->getConfig()->getNested('features.sell', true) || !$this->getConfig()->getNested('settings.sell.show-held-price', true)) return;
        if($item->isNull()) return;
        $entry = $this->getWorthEntry($item);
        if($entry === null || (float)($entry['sell'] ?? 0) <= 0){
            $player->sendActionBarMessage($item->getVanillaName() . ': This item cannot be sold.');
            return;
        }
        $unit = (float)$entry['sell'];
        $stack = $unit * $item->getCount() * $this->sellMultiplier($player->getName());
        $text = 'Sell price per item ' . $item->getVanillaName() . ': $' . $this->formatMoney($unit) . ' | Total for ' . $item->getCount() . ': $' . $this->formatMoney($stack);
        $player->sendActionBarMessage($text);
    }

    public function onItemHeld(PlayerItemHeldEvent $event) : void{
        if(!$this->getConfig()->getNested('features.sell', true)) return;
        $this->showHeldPrice($event->getPlayer(), $event->getItem());
    }

    private function showSellMultiplier(CommandSender $sender) : void{
        if(!$sender instanceof Player){ $this->msg($sender, 'Run this command in-game.'); return; }
        $total = $this->database->getSoldTotal($sender->getName());
        $multiplier = $this->sellMultiplier($sender->getName());
        $thresholds = (array)$this->getConfig()->getNested('settings.sell.multiplier-thresholds', [0 => 1.0]);
        uksort($thresholds, static fn($a, $b) => (float)$a <=> (float)$b);
        $next = null;
        foreach($thresholds as $threshold => $value){ if((float)$threshold > $total){ $next = (float)$threshold; break; } }
        $text = '§7Total sold: §a$' . $this->formatMoney($total) . "\n§7Current multiplier: §e" . number_format($multiplier, 2) . 'x';
        if($next !== null) $text .= "\n§7Next tier: §e$" . $this->formatMoney($next) . ' §7(' . $this->formatMoney(max(0, $next - $total)) . ' left)';
        $sender->sendForm(new SimpleForm('§l§6Sell Progress', $text, ['§aDone'], static function(Player $p, int $choice) : void{}));
    }

    private function moneyCommand(CommandSender $sender, array $args) : void{
        $name = $sender instanceof Player ? $sender->getName() : '';
        if(isset($args[0])) $name = (string)$args[0];
        if($name === ''){ $this->msg($sender, 'Usage: /money <player>'); return; }
        $canonical = $this->database->getCanonicalName($name);
        if($canonical === null){ $this->msg($sender, '§cPlayer not found. They must have joined the server at least once.'); return; }
        $name = $canonical;
        $balance = $this->database->getBalance($name);
        $this->msg($sender, 'Balance of ' . $name . ': $' . $this->formatMoney($balance));
    }

    private function payCommand(CommandSender $sender, array $args) : void{
        if(!$sender instanceof Player) { $this->msg($sender, 'Only in-game players can send payments.'); return; }
        if(count($args) < 2){ $this->msg($sender, 'Usage: /pay <player> <amount>'); return; }
        $target = (string)$args[0];
        $amount = $this->validAmount((string)$args[1]);
        if($amount === null){ $this->msg($sender, 'Enter a valid positive amount.'); return; }
        if(strcasecmp($target, $sender->getName()) === 0){ $this->msg($sender, 'You cannot pay yourself.'); return; }
        $canonical = $this->database->getCanonicalName($target);
        if($canonical === null){ $this->msg($sender, '§cPlayer not found. They must have joined the server at least once. No money was taken.'); return; }
        $target = $canonical;
        $this->database->ensurePlayer($sender->getName());
        if(!$this->database->transferMoney($sender->getName(), $target, $amount)){ $this->msg($sender, 'Payment failed. Check your balance.'); return; }
        $this->msg($sender, 'Sent $' . $this->formatMoney($amount) . ' to ' . $target . '.');
        if(($online = $this->online($target)) !== null) $this->msg($online, '$' . $this->formatMoney($amount) . ' received from ' . $sender->getName() . '.');
        $this->refreshScoreboardFor($sender->getName());
        $this->refreshScoreboardFor($target);
    }

    private function adminMoney(CommandSender $sender, array $args, string $mode) : void{
        if(count($args) < 2){ $this->msg($sender, 'Usage: /' . $mode . 'money <player> <amount>'); return; }
        $name = (string)$args[0];
        if(!is_numeric((string)$args[1]) || !is_finite((float)$args[1]) || (float)$args[1] < 0 || (float)$args[1] > 1.0e12){ $this->msg($sender, 'Enter a valid non-negative amount.'); return; }
        $amount = (float)$args[1];
        $canonical = $this->database->getCanonicalName($name);
        if($canonical === null){ $this->msg($sender, '§cPlayer not found. They must have joined the server at least once.'); return; }
        $name = $canonical;
        if($mode === 'set') $this->database->setBalance($name, $amount);
        elseif($mode === 'add') $this->database->addMoney($name, $amount);
        else $this->database->addMoney($name, -$amount);
        $this->msg($sender, 'Balance for §e' . $name . '§f updated. New balance: §a$' . $this->formatMoney($this->database->getBalance($name)));
        if(($online = $this->online($name)) !== null) $this->msg($online, 'Your balance was updated by staff. New balance: §a$' . $this->formatMoney($this->database->getBalance($name)));
        $this->refreshScoreboardFor($name);
    }

    private function shardsCommand(CommandSender $sender, array $args) : void{
        if(!$this->getConfig()->getNested('features.shards', true)){ $this->msg($sender, 'The shard system is disabled.'); return; }
        $name = $sender instanceof Player ? $sender->getName() : '';
        if(isset($args[0]) && strtolower((string)$args[0]) !== 'everywhere') $name = (string)$args[0];
        if($name === ''){ $this->msg($sender, 'Usage: /shards <player>'); return; }
        $canonical = $this->database->getCanonicalName($name);
        if($canonical === null){ $this->msg($sender, '§cPlayer not found. They must have joined the server at least once.'); return; }
        $name = $canonical;
        $this->msg($sender, 'Shard balance for §e' . $name . '§f: §d' . $this->database->getShards($name) . '§f.');
    }

    private function shardPayCommand(CommandSender $sender, array $args) : void{
        if(!$sender instanceof Player){ $this->msg($sender, 'Only players can send shards.'); return; }
        if(count($args) < 2 || !ctype_digit((string)$args[1])){ $this->msg($sender, 'Usage: /shardpay <player> <amount>'); return; }
        $target=(string)$args[0]; $amount=(int)$args[1];
        if($amount < 1 || strcasecmp($target, $sender->getName()) === 0){ $this->msg($sender, 'Invalid player or amount.'); return; }
        $canonical = $this->database->getCanonicalName($target);
        if($canonical === null){ $this->msg($sender, '§cPlayer not found. They must have joined the server at least once. No shards were taken.'); return; }
        $target = $canonical;
        $this->database->ensurePlayer($sender->getName());
        if(!$this->database->transferShards($sender->getName(), $target, $amount)){ $this->msg($sender, 'Not enough shards.'); return; }
        $this->refreshScoreboardFor($sender->getName());
        $this->refreshScoreboardFor($target);
        $this->msg($sender, '§d' . $amount . ' shards §fsent to §e' . $target . '§f.');
        if(($online=$this->online($target)) !== null) $this->msg($online, '§d' . $amount . ' shards §freceived from §e' . $sender->getName() . '§f.');
    }

    private function setShardsCommand(CommandSender $sender, array $args) : void{
        if(count($args) < 2 || !ctype_digit((string)$args[1])){ $this->msg($sender, 'Usage: /setshards <player> <amount>'); return; }
        $amount=max(0,(int)$args[1]); $name=(string)$args[0];
        $canonical = $this->database->getCanonicalName($name);
        if($canonical === null){ $this->msg($sender, '§cPlayer not found. They must have joined the server at least once.'); return; }
        $name = $canonical; $this->database->setShards($name,$amount); $this->refreshScoreboardFor($name);
        $this->msg($sender, 'Shard balance for §e' . $name . '§f set to §d' . $amount . '§f.');
    }

    private function bountyCommand(CommandSender $sender, array $args) : void{
        if(!$this->getConfig()->getNested('features.bounty', true)){ $this->msg($sender, 'The bounty system is disabled.'); return; }
        if(isset($args[0]) && strtolower((string)$args[0]) === 'list'){
            $this->msg($sender, '§6Bounty Board:');
            $rows = array_filter($this->database->topPlayers('bounty', 10), static fn(array $row) : bool => (float)$row['value'] > 0);
            if($rows === []) $this->msg($sender, '§7No active bounties.');
            foreach($rows as $index => $row) $this->msg($sender, '§e' . ($index + 1) . '. ' . $row['name'] . ' §7— §a$' . $this->formatMoney((float)$row['value']));
            return;
        }
        if(!$sender instanceof Player){ $this->msg($sender, 'Usage: /bounty <player> <amount> or /bounty list'); return; }
        if(count($args) < 2){ $this->msg($sender, 'Usage: /bounty <online-player> <amount>'); return; }
        $target=(string)$args[0]; $amount=$this->validAmount((string)$args[1]);
        if($amount===null || strcasecmp($target,$sender->getName())===0){ $this->msg($sender, 'Invalid player or amount.'); return; }
        $targetPlayer = $this->online($target);
        if($targetPlayer===null){ $this->msg($sender, 'The target player must be online.'); return; }
        $target = $targetPlayer->getName();
        $this->database->ensurePlayer($target);
        $this->database->ensurePlayer($sender->getName());
        if($this->database->getBalance($sender->getName()) < $amount){ $this->msg($sender, 'You do not have enough money.'); return; }
        $this->database->addMoney($sender->getName(), -$amount);
        $this->database->addBounty($target, $amount);
        $this->refreshScoreboardFor($sender->getName());
        $this->refreshScoreboardFor($target);
        $this->msg($sender, 'Placed a bounty of §a$' . $this->formatMoney($amount) . '§f on player §e' . $target . '§f.');
        $this->getServer()->broadcastMessage($this->prefix() . '§e' . $target . ' §7now has a bounty worth §a$' . $this->formatMoney($this->database->getBounty($target)) . '§7!');
    }

    private function statsCommand(CommandSender $sender, array $args) : void{
        $name = $sender instanceof Player ? $sender->getName() : '';
        if(isset($args[0])) $name=(string)$args[0];
        if($name===''){ $this->msg($sender, 'Usage: /stats <player>'); return; }
        $canonical = $this->database->getCanonicalName($name);
        if($canonical === null){ $this->msg($sender, '§cPlayer not found. They must have joined the server at least once.'); return; }
        $name = $canonical;
        $stats=$this->database->getStats($name);
        $this->msg($sender, '§6Player Stats: §e' . $name . '§6:');
        $this->msg($sender, '§7Money: §a$' . $this->formatMoney((float)$stats['money']) . ' §8| §7Shards: §d' . (int)$stats['shards']);
        $this->msg($sender, '§7Kills: §a' . (int)$stats['kills'] . ' §8| §7Deaths: §c' . (int)$stats['deaths'] . ' §8| §7Bounty: §6$' . $this->formatMoney((float)$stats['bounty']));
        $this->msg($sender, '§7Total sales: §a$' . $this->formatMoney((float)$stats['sold_total']) . ' §8| §7Playtime: §f' . $this->formatTime((int)$stats['playtime']));
    }

    private function leaderboardCommand(CommandSender $sender, array $args) : void{
        $type=strtolower((string)($args[0] ?? 'money'));
        $map=['money'=>'money','bal'=>'money','baltop'=>'money','kills'=>'kills','shards'=>'shards','playtime'=>'playtime','sell'=>'sold_total','sold'=>'sold_total'];
        if(!isset($map[$type])){ $this->msg($sender, 'Usage: /leaderboard <money|kills|shards|playtime|sell>'); return; }
        $column=$map[$type]; $rows=$this->database->topPlayers($column,10);
        $this->msg($sender, '§6Leaderboard: ' . $type . ':');
        foreach($rows as $index=>$row){
            $value = match($column){ 'money','sold_total' => '$'.$this->formatMoney((float)$row['value']), 'playtime' => $this->formatTime((int)$row['value']), default => (string)$row['value'] };
            $this->msg($sender, '§e' . ($index+1) . '. §f' . $row['name'] . ' §8— §a' . $value);
        }
    }

    private function formatTime(int $seconds) : string{
        $hours=(int)floor($seconds/3600); $minutes=(int)floor(($seconds%3600)/60);
        return $hours . 'h ' . $minutes . 'm';
    }

    private function setHomeCommand(Player $player, array $args) : void{
        if(!$this->getConfig()->getNested('features.homes', true)){ $this->msg($player,'Homes are disabled.'); return; }
        $name=strtolower((string)($args[0] ?? 'home'));
        if(!preg_match('/^[a-z0-9_-]{1,16}$/',$name)){ $this->msg($player,'Home names must be 1-16 characters using English letters, digits, _ or -.'); return; }
        $homes=$this->database->getHomes($player->getName());
        $max=max(1,(int)$this->getConfig()->getNested('settings.homes.max-per-player',3));
        if($this->database->getHome($player->getName(),$name)===null && count($homes)>=$max){ $this->msg($player,'You have reached your home limit (' . $max . ').'); return; }
        $loc=$player->getLocation();
        $this->database->setHome($player->getName(),$name,$loc->getWorld()->getFolderName(),$loc->getX(),$loc->getY(),$loc->getZ(),$loc->getYaw(),$loc->getPitch());
        $this->msg($player,'Home §e' . $name . ' §fsaved.');
    }

    private function homeCommand(Player $player, array $args) : void{
        $homes=$this->database->getHomes($player->getName());
        if(!isset($args[0])){
            $defaultHome = $this->database->getHome($player->getName(), 'home');
            if($defaultHome !== null){
                $this->teleportStored($player, $defaultHome, 'Home');
                return;
            }
            if($homes === []){
                $this->msg($player, 'You have no homes yet. Use §e/sethome <name>§f.');
                return;
            }
            // Make /home useful even when the player has exactly one custom-named home.
            if(count($homes) === 1){
                $singleName = (string)($homes[0]['home_name'] ?? '');
                $singleHome = $singleName !== '' ? $this->database->getHome($player->getName(), $singleName) : null;
                if($singleHome !== null){
                    $this->teleportStored($player, $singleHome, 'Home');
                    return;
                }
            }
            $this->msg($player, 'Your homes: §e' . implode(', ', array_column($homes, 'home_name')) . '§f. Use §e/home <name>§f.');
            return;
        }
        $row=$this->database->getHome($player->getName(),strtolower((string)$args[0]));
        if($row===null){ $this->msg($player,'Home not found.'); return; }
        $this->teleportStored($player,$row,'Home');
    }

    private function deleteHomeCommand(Player $player, array $args) : void{
        if(!isset($args[0])){ $this->msg($player,'Usage: /delhome <name>'); return; }
        $name=strtolower((string)$args[0]);
        $this->msg($player,$this->database->deleteHome($player->getName(),$name) ? 'Home §e'.$name.'§f deleted.' : 'Home not found.');
    }

    private function setWarpCommand(Player $player, array $args) : void{
        if(!isset($args[0]) || !preg_match('/^[a-z0-9_-]{1,24}$/i',(string)$args[0])){ $this->msg($player,'Usage: /setwarp <name>'); return; }
        $loc=$player->getLocation();
        $this->database->setWarp(strtolower((string)$args[0]),$loc->getWorld()->getFolderName(),$loc->getX(),$loc->getY(),$loc->getZ(),$loc->getYaw(),$loc->getPitch(),$player->getName());
        $this->msg($player,'Warp §e' . strtolower((string)$args[0]) . ' §fsaved.');
    }

    private function warpCommand(Player $player, array $args) : void{
        if(!isset($args[0])){ $this->listWarps($player); return; }
        $row=$this->database->getWarp(strtolower((string)$args[0]));
        if($row===null){ $this->msg($player,'Warp not found.'); return; }
        $this->teleportStored($player,$row,'Warp');
    }

    private function deleteWarpCommand(CommandSender $sender, array $args) : void{
        if(!isset($args[0])){ $this->msg($sender,'Usage: /delwarp <name>'); return; }
        $name=strtolower((string)$args[0]);
        $this->msg($sender,$this->database->deleteWarp($name) ? 'Warp §e'.$name.'§f deleted.' : 'Warp not found.');
    }

    private function listWarps(CommandSender $sender) : void{
        $rows=$this->database->getWarps(); $names=array_values(array_filter(array_column($rows,'warp_name'),static fn(string $name):bool=>$name!=='__spawn__'));
        $this->msg($sender,'Warps: §e' . ($names===[] ? 'None' : implode(', ',$names)) . '§f.');
    }

    private function setSpawnCommand(Player $player) : void{
        $loc=$player->getLocation();
        $this->database->setWarp('__spawn__',$loc->getWorld()->getFolderName(),$loc->getX(),$loc->getY(),$loc->getZ(),$loc->getYaw(),$loc->getPitch(),$player->getName());
        $this->msg($player,'Server spawn location set.');
    }

    private function teleportSpawn(Player $player) : void{
        $row=$this->database->getWarp('__spawn__');
        if($row!==null){ $this->teleportStored($player,$row,'Spawn',false); return; }
        $world=$this->getServer()->getWorldManager()->getDefaultWorld();
        if($world===null){ $this->msg($player,'No default world is loaded.'); return; }
        $player->teleport($world->getSpawnLocation()); $this->msg($player,'Teleported to spawn.');
    }

    private function teleportStored(Player $player, ?array $row, string $label, bool $countdown = true) : void{
        if($row === null){ $this->msg($player, $label . ' was not found.'); return; }
        $worldName = trim((string)($row['world'] ?? ''));
        if($worldName === ''){ $this->msg($player, 'This saved location has no world name. Please set it again.'); return; }
        try{
            $worldManager = $this->getServer()->getWorldManager();
            $world = $worldManager->getWorldByName($worldName);
            if($world === null){
                $loaded = $worldManager->loadWorld($worldName);
                $world = $worldManager->getWorldByName($worldName);
                if(!$loaded && $world === null){
                    $this->msg($player, 'World §e' . $worldName . ' §fcould not be loaded. The saved location was not changed.');
                    return;
                }
            }
            if($world === null){ $this->msg($player, 'World §e' . $worldName . ' §fis not available.'); return; }
            $x = (float)($row['x'] ?? NAN);
            $y = (float)($row['y'] ?? NAN);
            $z = (float)($row['z'] ?? NAN);
            $yaw = (float)($row['yaw'] ?? 0.0);
            $pitch = (float)($row['pitch'] ?? 0.0);
            if(!is_finite($x) || !is_finite($y) || !is_finite($z) || !is_finite($yaw) || !is_finite($pitch)){
                $this->msg($player, 'This saved location is invalid. Set it again with /sethome or ask an administrator.');
                return;
            }
            if($countdown){
                $folder = $world->getFolderName();
                $this->startTeleportCountdown($player, $label, function() use($player, $folder, $x, $y, $z, $yaw, $pitch, $label) : bool{
                    // Re-resolve the world: it may have been unloaded during the countdown.
                    $target = $this->resolveWorld($folder);
                    if($target === null){ $this->msg($player, 'World §e' . $folder . ' §fis not available. Teleport cancelled.'); return false; }
                    if(!$player->teleport(new Location($x, $y, $z, $target, $yaw, $pitch))){ $this->msg($player, 'Teleport was cancelled by the server.'); return false; }
                    $this->msg($player, 'Teleported to ' . $label . '.');
                    return true;
                });
                return;
            }
            $location = new Location($x, $y, $z, $world, $yaw, $pitch);
            if(!$player->teleport($location)){ $this->msg($player, 'Teleport was cancelled by the server.'); return; }
            $this->msg($player, 'Teleported to ' . $label . '.');
        }catch(\Throwable $e){
            $this->getLogger()->error('Teleporting ' . $player->getName() . ' to saved ' . $label . ' failed: ' . $e->getMessage());
            $this->msg($player, 'Teleport failed safely. Check the server log; the server should remain running.');
        }
    }

    private function randomTeleport(Player $player) : void{
        if(!$this->getConfig()->getNested('features.rtp',true) || !$this->getConfig()->getNested('settings.rtp.enabled',true)){ $this->msg($player,'Random teleport is disabled.'); return; }
        $now=time(); $key=strtolower($player->getName()); $cooldown=(int)$this->getConfig()->getNested('settings.rtp.cooldown-seconds',30);
        if(($this->rtpCooldowns[$key] ?? 0)>$now){ $this->msg($player,'Wait §e'.($this->rtpCooldowns[$key]-$now).'§f seconds before using random teleport again.'); return; }
        $world=$player->getWorld();
        $radius=max(100,min(30000,(int)$this->getConfig()->getNested('settings.rtp.radius',5000)));
        $attempts=max(1,min(100,(int)$this->getConfig()->getNested('settings.rtp.max-attempts',20)));
        $destination = $this->findRtpLocation($player, $world, $radius, $attempts);
        if($destination === null){
            $this->msg($player,'Could not find a safe location in generated terrain. Explore more terrain, then try again.');
            return;
        }
        $folder = $destination->getWorld()->getFolderName();
        $dx = $destination->getX(); $dy = $destination->getY(); $dz = $destination->getZ();
        $dyaw = $destination->getYaw(); $dpitch = $destination->getPitch();
        $this->startTeleportCountdown($player, 'a random location', function() use($player, $folder, $dx, $dy, $dz, $dyaw, $dpitch, $key, $cooldown) : bool{
            $target = $this->resolveWorld($folder);
            if($target === null){ $this->msg($player, 'The world is not available. Random teleport cancelled.'); return false; }
            if(!$player->teleport(new Location($dx, $dy, $dz, $target, $dyaw, $dpitch))){ $this->msg($player, 'Teleport was cancelled by the server.'); return false; }
            $this->rtpCooldowns[$key] = time() + $cooldown;
            $this->msg($player, 'You were randomly teleported.');
            return true;
        });
    }

    /** Finds a safe random destination (same search logic as before). The player is not moved here. */
    private function findRtpLocation(Player $player, World $world, int $radius, int $attempts) : ?Location{
        $originX = $player->getPosition()->getFloorX();
        $originZ = $player->getPosition()->getFloorZ();
        for($i=0;$i<$attempts;$i++){
            $x=$originX + mt_rand(-$radius,$radius); $z=$originZ + mt_rand(-$radius,$radius);
            // API 5 has isChunkGenerated(), not World::getChunkAt(). Check the chunk
            // before reading the heightmap so unexplored coordinates never crash RTP.
            try{
                if(!$world->isChunkGenerated($x >> 4, $z >> 4)){
                    continue;
                }
                $y = $world->getHighestBlockAt($x, $z);
                if(!is_int($y) || $y < 1 || $y >= $world->getMaxY() - 2){
                    continue;
                }
                $floor = $world->getBlockAt($x, $y, $z);
                $feet = $world->getBlockAt($x, $y + 1, $z);
                $head = $world->getBlockAt($x, $y + 2, $z);
                if(!$floor->isSolid() || $feet->isSolid() || $head->isSolid()){
                    continue;
                }
                return new Location($x + 0.5, $y + 1.0, $z + 0.5, $world, $player->getLocation()->getYaw(), $player->getLocation()->getPitch());
            }catch(\Throwable $e){
                // A stale/unavailable chunk must not crash the server; try another location.
                $this->getLogger()->debug('RTP skipped an unavailable chunk: ' . $e->getMessage());
                continue;
            }
        }
        // Fallback: look near the player's current area. The configured radius is often
        // mostly ungenerated, while spawn/player surroundings are much more likely to exist.
        for($i = 0; $i < 150; $i++){
            $x = $originX + mt_rand(-96, 96);
            $z = $originZ + mt_rand(-96, 96);
            try{
                if(!$world->isChunkGenerated($x >> 4, $z >> 4)){
                    continue;
                }
                $y = $world->getHighestBlockAt($x, $z);
                if(!is_int($y) || $y < 1 || $y >= $world->getMaxY() - 2){
                    continue;
                }
                $floor = $world->getBlockAt($x, $y, $z);
                $feet = $world->getBlockAt($x, $y + 1, $z);
                $head = $world->getBlockAt($x, $y + 2, $z);
                if(!$floor->isSolid() || $feet->isSolid() || $head->isSolid()){
                    continue;
                }
                return new Location($x + 0.5, $y + 1.0, $z + 0.5, $world, $player->getLocation()->getYaw(), $player->getLocation()->getPitch());
            }catch(\Throwable $e){
                $this->getLogger()->debug('RTP fallback skipped an unavailable chunk: ' . $e->getMessage());
            }
        }
        return null;
    }

    private function tpaCommand(Player $player, array $args) : void{
        if(!isset($args[0])){ $this->msg($player,'Usage: /tpa <player>'); return; }
        $target=$this->online((string)$args[0]);
        if($target===null){ $this->msg($player,'That player is not online.'); return; }
        if($target===$player){ $this->msg($player,'You cannot send a teleport request to yourself.'); return; }
        $key=strtolower($player->getName()); $now=time(); $cooldown=(int)$this->getConfig()->getNested('settings.tpa.cooldown-seconds',5);
        if(($this->tpaCooldowns[$key]??0)>$now){ $this->msg($player,'Please wait '.($this->tpaCooldowns[$key]-$now).' seconds before sending another request.'); return; }
        $expiry=(int)$this->getConfig()->getNested('settings.tpa.request-expiry-seconds',60);
        $this->tpaRequests[strtolower($target->getName())]=['from'=>$player->getName(),'expires'=>$now+$expiry]; $this->tpaCooldowns[$key]=$now+$cooldown;
        $this->msg($player,'Teleport request sent to §e'.$target->getName().'§f.');
        $this->msg($target,'§e'.$player->getName().' §fwants to teleport to you. Use §a/tpaccept §for §c/tpdeny§f.');
    }

    private function tpAcceptCommand(Player $target) : void{
        $key=strtolower($target->getName()); $request=$this->tpaRequests[$key]??null;
        if($request===null){ $this->msg($target,'You have no pending teleport requests.'); return; }
        unset($this->tpaRequests[$key]);
        if((int)$request['expires']<time()){ $this->msg($target,'This request has expired.'); return; }
        $requester=$this->online((string)$request['from']);
        if($requester===null){ $this->msg($target,'The requester is no longer online.'); return; }
        $targetName = $target->getName();
        $this->msg($target, 'Request accepted. §e' . $requester->getName() . '§f will be teleported after the countdown.');
        $this->startTeleportCountdown($requester, $targetName, function() use($requester, $targetName) : bool{
            // Use the target's CURRENT location; abort safely if they left or died meanwhile.
            $destination = $this->online($targetName);
            if($destination === null || !$destination->isAlive()){
                $this->msg($requester, 'The player you were teleporting to is no longer available.');
                return false;
            }
            if(!$requester->teleport($destination->getLocation())){
                $this->msg($requester, 'Teleport was cancelled by the server. Ask the player to try again.');
                return false;
            }
            $this->msg($requester, 'Teleport request accepted.');
            $this->msg($destination, $requester->getName() . ' teleported to you.');
            return true;
        });
    }

    private function tpDenyCommand(Player $target) : void{
        $key=strtolower($target->getName()); $request=$this->tpaRequests[$key]??null;
        if($request===null){ $this->msg($target,'You have no pending teleport requests.'); return; }
        unset($this->tpaRequests[$key]); $this->msg($target,'Teleport request denied.');
        if(($requester=$this->online((string)$request['from']))!==null) $this->msg($requester,'Your teleport request was denied.');
    }

    private function teamCommand(Player $player, array $args) : void{
        $sub=strtolower((string)($args[0]??'info')); $own=$this->database->getTeamFor($player->getName());
        if($sub==='create'){
            $name=(string)($args[1]??'');
            if(!preg_match('/^[a-z0-9_-]{3,16}$/i',$name)){ $this->msg($player,'Usage: /team create <3-16 character name>'); return; }
            if($this->database->createTeam($name,$player->getName())){ $this->msg($player,'Team §e'.$name.' §f created.'); $this->refreshScoreboardFor($player->getName()); } else $this->msg($player,'You are already in a team or that team name is taken.');
            return;
        }
        if($sub==='invite'){
            if($own===null || strcasecmp((string)$own['leader'],$player->getName())!==0){ $this->msg($player,'Only your team leader can invite players.'); return; }
            $target=$this->online((string)($args[1]??'')); if($target===null){ $this->msg($player,'Usage: /team invite <online-player>'); return; }
            if($this->database->getTeamFor($target->getName())!==null){ $this->msg($player,'That player is already in a team.'); return; }
            $this->teamInvites[strtolower($target->getName())][strtolower((string)$own['team_name'])]=time()+60;
            $this->msg($player,'Player §e'.$target->getName().'§f was invited to your team.'); $this->msg($target,'You were invited to team §e'.$own['team_name'].'§f. Use §e/team join '.$own['team_name'].' §fwithin 60 seconds.'); return;
        }
        if($sub==='join'){
            $name=strtolower((string)($args[1]??'')); $expiry=$this->teamInvites[strtolower($player->getName())][$name]??0;
            if($expiry<time()){ $this->msg($player,'You do not have an active invitation to that team.'); return; }
            if($this->database->addTeamMember($name,$player->getName())){ unset($this->teamInvites[strtolower($player->getName())][$name]); $this->msg($player,'You joined team §e'.$name.'§f.'); $this->refreshScoreboardFor($player->getName()); $this->broadcastTeam($name,'§e'.$player->getName().' §7joined the team.'); } else $this->msg($player,'Could not join the team.'); return;
        }
        if($sub==='leave'){
            if($own===null){ $this->msg($player,'You are not in a team.'); return; }
            $teamName=(string)$own['team_name']; $isLeader=strcasecmp((string)$own['leader'],$player->getName())===0;
            $members=$this->database->getTeamMembers($teamName); $this->database->removeTeamMember($player->getName()); foreach($members as $m){ $this->refreshScoreboardFor((string)$m['player']); } $this->msg($player,$isLeader?'Your team was disbanded.':'You left the team.');
            if(!$isLeader) $this->broadcastTeam($teamName,'§e'.$player->getName().' §7left the team.'); return;
        }
        if($sub==='info' && isset($args[1])) $own=$this->database->getTeam((string)$args[1]);
        if($own===null){ $this->msg($player,'You are not in a team. Use §e/team create <name>§f.'); return; }
        $members=$this->database->getTeamMembers((string)$own['team_name']);
        $this->msg($player,'§6Team §e'.$own['team_name'].' §7| Leader: §f'.$own['leader']);
        $this->msg($player,'§7Members (' . count($members) . '): §f'.implode(', ',array_column($members,'player')));
        $this->msg($player,'§7Commands: /team create, invite, join, leave, info');
    }

    private function broadcastTeam(string $team, string $message) : void{
        foreach($this->database->getTeamMembers($team) as $member){ if(($p=$this->online((string)$member['player']))!==null) $this->msg($p,$message); }
    }

    private function openCrates(Player $player) : void{
        if(!$this->getConfig()->getNested('features.crates',true)){ $this->msg($player,'Crates are disabled.'); return; }
        $crates=(array)$this->cratesConfig->get('crates',[]); $keys=array_keys($crates); $buttons=[];
        foreach($crates as $name=>$crate){ $buttons[]=(string)($crate['name']??$name).' §7| Keys: §e'.$this->database->getKeys($player->getName(),(string)$name); }
        if($buttons===[]){ $this->msg($player,'No crates are configured in crates.yml.'); return; }
        $player->sendForm(new SimpleForm('§l§6SmpMaster | Crates','§7Select a crate. Keys are virtual and stored in the database.',$buttons,function(Player $p,int $choice) use($keys):void{ if(isset($keys[$choice])) $this->openCrate($p,(string)$keys[$choice]); }));
    }

    private function crateCommand(Player $player, array $args) : void{
        if(!isset($args[0])){ $this->openCrates($player); return; }
        $this->openCrate($player,strtolower((string)$args[0]));
    }

    private function openCrate(Player $player, string $crateName) : void{
        $crates=(array)$this->cratesConfig->get('crates',[]); $crate=$crates[$crateName]??null;
        if(!is_array($crate)){ $this->msg($player,'Unknown crate. Use /crates.'); return; }
        $rewards=array_values(array_filter((array)($crate['rewards']??[]),'is_array'));
        if($rewards===[]){ $this->msg($player,'No rewards are configured for this crate.'); return; }
        if($this->database->getKeys($player->getName(),$crateName)<1){ $this->msg($player,'You need a §e'.$crateName.'§f key to do this.'); return; }
        $this->msg($player,'Opening '.$crate['name'].'§f...');
        $reward=$this->chooseReward($rewards);
        if($reward===null){ $this->msg($player,'No valid rewards are configured.'); return; }
        $type=strtolower((string)($reward['type']??''));
        if(!in_array($type, ['money', 'shards', 'item'], true)){ $this->msg($player,'Unsupported reward type: '.$type.' (key was not consumed).'); return; }
        $item=null;
        if($type==='item'){
            $item=StringToItemParser::getInstance()->parse((string)($reward['id']??''));
            if($item===null){ $this->msg($player,'Invalid reward item in crates.yml.'); return; }
            $item->setCount(max(1,(int)($reward['amount']??1)));
            if(!$player->getInventory()->canAddItem($item)){ $this->msg($player,'Inventory is full; key was not consumed.'); return; }
        }
        if(!$this->database->useKey($player->getName(),$crateName)){ $this->msg($player,'You have no more keys.'); return; }
        if($type==='money'){
            $amount=max(0,(float)($reward['amount']??0)); $this->database->addMoney($player->getName(),$amount); $result='$'.$this->formatMoney($amount);
        }elseif($type==='shards'){
            $amount=max(0,(int)($reward['amount']??0)); $this->database->addShards($player->getName(),$amount); $result=$amount.' Shards';
        }elseif($type==='item' && $item instanceof Item){
            $player->getInventory()->addItem($item); $result=(string)($reward['name']??$item->getName());
        }
        $this->scoreboards->update($player);
        $this->getServer()->broadcastMessage($this->prefix().'§e'.$player->getName().' §7opened '.$crate['name'].' §7and won §a'.$result.'§7!');
    }

    private function chooseReward(array $rewards) : ?array{
        $total=0; foreach($rewards as $reward) $total+=max(0,(int)($reward['chance']??1));
        if($total<1) return null;
        $roll=mt_rand(1,$total);
        foreach($rewards as $reward){ $weight=max(0,(int)($reward['chance']??1)); $roll-=$weight; if($roll<=0) return $reward; }
        return $rewards[array_key_last($rewards)]??null;
    }

    private function keyAllCommand(CommandSender $sender, array $args) : void{
        if(count($args)<2 || !ctype_digit((string)$args[1])){ $this->msg($sender,'Usage: /keyall <crate> <amount>'); return; }
        $crate=strtolower((string)$args[0]); $amount=max(1,min(100000,(int)$args[1])); $crates=(array)$this->cratesConfig->get('crates',[]);
        if(!isset($crates[$crate])){ $this->msg($sender,'Unknown crate.'); return; }
        foreach($this->getServer()->getOnlinePlayers() as $player){ $this->database->addKey($player->getName(),$crate,$amount); $this->msg($player,'§e'.$amount.'  key(s) '.$crate.'§f received!'); }
        $this->msg($sender,'Keys were given to '.count($this->getServer()->getOnlinePlayers()).' online players.');
    }

    private function reportCommand(CommandSender $sender, array $args) : void{
        if(count($args)<2){ $this->msg($sender,'Usage: /report <player> <reason>'); return; }
        $target=(string)$args[0]; $reason=implode(' ',array_slice($args,1));
        foreach($this->getServer()->getOnlinePlayers() as $staff){ if($staff->hasPermission('smpmaster.staff.alerts')) $this->msg($staff,'§cReport: §e'.$sender->getName().' §7about §e'.$target.'§7: '.$reason); }
        $this->msg($sender,'Your report was sent to online staff.');
    }

    private function helpopCommand(CommandSender $sender, array $args) : void{
        if($args===[]){ $this->msg($sender,'Usage: /helpop <message>'); return; }
        $message=implode(' ',$args);
        foreach($this->getServer()->getOnlinePlayers() as $staff){ if($staff->hasPermission('smpmaster.staff.alerts')) $this->msg($staff,'§6Help request: §e'.$sender->getName().'§7: '.$message); }
        $this->msg($sender,'Your help request was sent to online staff.');
    }

    private function clearDroppedItemsCommand(CommandSender $sender) : void{
        if(!$sender->hasPermission('smpmaster.admin.cleardrops')){
            $this->msg($sender, '§cYou do not have permission to clear dropped items.');
            return;
        }
        $removed = $this->clearDroppedItems();
        $this->msg($sender, '§aRemoved ' . $removed . ' dropped item(s) from all loaded worlds.');
        if($removed > 0 && $this->getConfig()->getNested('settings.drops.announce', true)){
            $this->getServer()->broadcastMessage($this->prefix() . '§eGround items were cleared by an administrator.');
        }
    }

    /** Removes ONLY dropped-item entities (ItemEntity). Players, mobs, XP orbs and everything else are untouched. */
    private function clearDroppedItems() : int{
        $removed = 0;
        foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
            try{
                foreach($world->getEntities() as $entity){
                    if($entity instanceof ItemEntity && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
                        $entity->flagForDespawn();
                        ++$removed;
                    }
                }
            }catch(\Throwable $e){
                $this->getLogger()->warning('Ground item cleanup skipped world ' . $world->getFolderName() . ': ' . $e->getMessage());
            }
        }
        return $removed;
    }

    private function smpCommand(CommandSender $sender, array $args) : void{
        $sub=strtolower((string)($args[0]??'help'));
        if($sub==='reload'){
            if(!$sender->hasPermission('smpmaster.admin.reload')){ $this->msg($sender,'No permission.'); return; }
            $this->reloadConfig(); $this->shopConfig->reload(); $this->worthConfig->reload(); $this->scoreboardConfig->reload(); $this->cratesConfig->reload(); $this->auctionConfig->reload();
            foreach($this->getServer()->getOnlinePlayers() as $online) $this->scoreboards->show($online);
            $this->msg($sender,'SmpMaster settings reloaded.'); return;
        }
        if($sub==='debug'){
            if(!$sender->hasPermission('smpmaster.admin.reload')){ $this->msg($sender,'No permission.'); return; }
            $name = isset($args[1]) ? (string)$args[1] : ($sender instanceof Player ? $sender->getName() : '');
            $canonical = $name === '' ? null : $this->database->getCanonicalName($name);
            if($canonical === null){ $this->msg($sender,'Usage: /smp debug <player> (player must exist in the database).'); return; }
            $row = $this->database->getStats($canonical);
            $team = $this->database->getTeamFor($canonical);
            $this->msg($sender,'§6DB row for §e'.$canonical.'§6: money='.($row['money']??'?').' shards='.($row['shards']??'?').' kills='.($row['kills']??'?').' deaths='.($row['deaths']??'?').' bounty='.($row['bounty']??'?').' team='.($team['team_name']??'None'));
            $online = $this->online($canonical);
            if($online !== null){
                $ping = $online->getNetworkSession()->getPing();
                $this->msg($sender,'§7Online ping: '.($ping===null?'n/a':$ping.'ms').' | scoreboard enabled: '.(($this->getConfig()->getNested('features.scoreboard',true) && $this->getConfig()->getNested('settings.scoreboard-enabled',true))?'yes':'no').' | active countdown: '.(isset($this->countdowns[strtolower($canonical)])?'yes':'no'));
            }
            return;
        }
        $this->msg($sender,'§6SmpMaster §7v' . $this->getDescription()->getVersion() . ' §8| §fSurvival systems for PocketMine API 5');
        $this->msg($sender,'§eEconomy: §f/money, /pay, /shards, /shardpay');
        $this->msg($sender,'§eMarket: §f/shop, /sell, /sellall, /sellhand, /worth, /sellhistory, /ah');
        $this->msg($sender,'§eSurvival: §f/home, /sethome, /warp, /rtp, /tpa, /team, /bounty, /crates');
        $this->msg($sender,'§7Admin: /smp reload, /smp debug <player>, /cleardrops.');
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player=$event->getPlayer();
        $this->database->ensurePlayer($player->getName(),(float)$this->getConfig()->getNested('settings.starting-money',250.0));
        $this->joinTimes[strtolower($player->getName())] = time();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($player) : void{
            if(!$player->isConnected()) return;
            $this->scoreboards->show($player);
            $this->showHeldPrice($player, $player->getInventory()->getItemInHand());
        }), 20);
        // A join message can optionally be added by another plugin; keep SmpMaster quiet by default.
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        $player=$event->getPlayer(); $key=strtolower($player->getName());
        if(isset($this->joinTimes[$key])){ $this->database->addStat($player->getName(),'playtime',max(0,time()-$this->joinTimes[$key])); unset($this->joinTimes[$key]); }
        $this->cancelCountdown($key);
        $this->scoreboards->remove($player);
        unset($this->tpaRequests[$key], $this->teamInvites[$key], $this->lastAttackers[$key]);
    }

    /** @priority MONITOR */
    public function onDamage(EntityDamageByEntityEvent $event) : void{
        $victim = $event->getEntity();
        $damager = $event->getDamager();
        if($victim instanceof Player && $damager instanceof Player && $victim !== $damager){
            $this->lastAttackers[strtolower($victim->getName())] = ['killer' => $damager->getName(), 'time' => time()];
        }
    }

    public function onRespawn(PlayerRespawnEvent $event) : void{
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($player) : void{
            if($player->isConnected()) $this->scoreboards->show($player);
        }), 10);
    }

    public function onDeath(PlayerDeathEvent $event) : void{
        $victim=$event->getPlayer();
        $vkey=strtolower($victim->getName());
        $this->cancelCountdown($vkey, '§cTeleport cancelled because you died.');
        $statsOn = (bool)$this->getConfig()->getNested('features.stats', true);
        $this->database->ensurePlayer($victim->getName());
        if($statsOn) $this->database->addStat($victim->getName(),'deaths',1);
        // Direct killer from the last damage cause; fall back to the last player who hit the victim
        // in the last 15 seconds (e.g. hit, then died from fire/fall/void).
        $killer=null;
        $cause=$victim->getLastDamageCause();
        if($cause instanceof EntityDamageByEntityEvent && $cause->getDamager() instanceof Player){
            $killer=$cause->getDamager();
        }elseif(isset($this->lastAttackers[$vkey]) && time()-$this->lastAttackers[$vkey]['time'] <= 15){
            $killer=$this->online($this->lastAttackers[$vkey]['killer']);
        }
        unset($this->lastAttackers[$vkey]);
        if($killer instanceof Player && strcasecmp($killer->getName(),$victim->getName())!==0){
            $this->database->ensurePlayer($killer->getName());
            if($statsOn) $this->database->addStat($killer->getName(),'kills',1);
            $bounty=$this->getConfig()->getNested('features.bounty', true) ? $this->database->claimBounty($victim->getName(),$killer->getName()) : 0.0;
            if($bounty>0){ $this->msg($killer,'Bounty §a$'.$this->formatMoney($bounty).' §f for defeating §e'.$victim->getName().'§f.'); $this->getServer()->broadcastMessage($this->prefix().'§e'.$killer->getName().' §7claimed a bounty worth §a$'.$this->formatMoney($bounty).' §7for player '.$victim->getName().'!'); }
            $this->refreshScoreboardFor($killer->getName());
        }
        $this->refreshScoreboardFor($victim->getName());
    }

}
