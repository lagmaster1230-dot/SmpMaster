<?php

declare(strict_types=1);

namespace LagMaster\SmpMaster;

use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\player\Player;

final class ScoreboardManager{
    private const OBJECTIVE = 'smp_side';
    /** @var array<string, string> signature of the rows last sent to each player */
    private array $lastSignature = [];
    /** @var array<string, int> refreshes since the last full resend */
    private array $sinceResend = [];

    public function __construct(private Main $plugin){}

    public function show(Player $player) : void{
        if(!$this->plugin->getConfig()->getNested('features.scoreboard', true) || !$this->plugin->getConfig()->getNested('settings.scoreboard-enabled', true)) return;
        unset($this->lastSignature[strtolower($player->getName())]);
        $this->update($player);
    }

    public function update(Player $player) : void{
        if(!$this->plugin->getConfig()->getNested('features.scoreboard', true) || !$this->plugin->getConfig()->getNested('settings.scoreboard-enabled', true)) return;
        $lines = $this->plugin->getScoreboardConfig()->get('lines', []);
        if(!is_array($lines) || $lines === []){
            // Last-resort fallback: never leave players with a title and no rows.
            $lines = [
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
        $db = $this->plugin->getDatabase();
        $name = $player->getName();
        // Make sure the row exists (with the configured starting balance) before reading,
        // because read methods no longer create rows implicitly.
        $db->ensurePlayer($name);
        $stats = $db->getStats($name);
        $teamRow = $db->getTeamFor($name);
        $teamName = $teamRow !== null ? (string)($teamRow['team_name'] ?? 'None') : 'None';
        if($teamName === '') $teamName = 'None';
        // NetworkSession::getPing() is nullable in API 5 until the first latency sample arrives.
        $ping = $player->getNetworkSession()->getPing();
        $replacements = [
            '%player%' => $player->getName(),
            '%rank%' => $player->hasPermission('smpmaster.rank.admin') ? 'Admin' : 'Player',
            // Read the canonical balance via the same method used by /sell and /shop.
            '%money%' => $this->plugin->formatMoney((float)($stats['money'] ?? 0.0)),
            '%shards%' => (string)($stats['shards'] ?? 0),
            '%kills%' => (string)($stats['kills'] ?? 0),
            '%deaths%' => (string)($stats['deaths'] ?? 0),
            '%bounty%' => $this->plugin->formatMoney((float)($stats['bounty'] ?? 0)),
            '%team%' => $teamName,
            '%ping%' => $ping === null ? '0' : (string)$ping,
            '%online%' => (string)count($this->plugin->getServer()->getOnlinePlayers()),
            '%sold%' => $this->plugin->formatMoney((float)($stats['sold_total'] ?? 0.0)),
            '%date%' => date('d/m/Y')
        ];
        $rendered = array_slice(array_map(static fn($line) => strtr((string)$line, $replacements), $lines), 0, 15);
        $seen = [];
        foreach($rendered as $i => $text){
            $text = (string)$text;
            $n = $seen[$text] ?? 0;
            $seen[$text] = $n + 1;
            $rendered[$i] = $n > 0 ? $text . str_repeat('§r', $n) : $text;
        }
        $count = count($rendered);
        $key = strtolower($player->getName());
        $signature = md5(implode("\n", $rendered));
        $title = (string) $this->plugin->getScoreboardConfig()->get('title', '§l§6SmpMaster');
        $this->sinceResend[$key] = ($this->sinceResend[$key] ?? 0) + 1;
        // Nothing changed: skip (avoids flicker). Still resend every ~10 refreshes in case the client
        // dropped the sidebar (dimension change, respawn, ...).
        if(($this->lastSignature[$key] ?? null) === $signature && $this->sinceResend[$key] < 10) return;
        $this->sinceResend[$key] = 0;
        $this->lastSignature[$key] = $signature;
        $entries = [];
        foreach($rendered as $index => $line){
            $entry = new ScorePacketEntry();
            $entry->scoreboardId = $index + 1;
            $entry->objectiveName = self::OBJECTIVE;
            $entry->score = $count - $index;
            $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
            $entry->actorUniqueId = null;
            $entry->customName = (string)$line;
            $entries[] = $entry;
        }
        // Bedrock clients ignore a text change on an existing row id. The reliable way to refresh
        // the sidebar is to drop the objective and send it again with the new rows.
        $session = $player->getNetworkSession();
        $session->sendDataPacket(RemoveObjectivePacket::create(self::OBJECTIVE));
        $session->sendDataPacket(SetDisplayObjectivePacket::create(
            SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
            self::OBJECTIVE,
            $title,
            'dummy',
            SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
        ));
        if($entries !== []){
            $session->sendDataPacket(SetScorePacket::create(SetScorePacket::TYPE_CHANGE, $entries));
        }
    }

    public function remove(Player $player) : void{
        $key = strtolower($player->getName());
        unset($this->lastSignature[$key], $this->sinceResend[$key]);
        try{
            $player->getNetworkSession()->sendDataPacket(RemoveObjectivePacket::create(self::OBJECTIVE));
        }catch(\Throwable $e){
            // player is already disconnecting
        }
    }
}
