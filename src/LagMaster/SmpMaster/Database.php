<?php

declare(strict_types=1);

namespace LagMaster\SmpMaster;

use SQLite3;
use SQLite3Stmt;

final class Database{
    private SQLite3 $db;
    /** Starting balance applied to every newly created player row (set from config by Main). */
    private float $defaultStartingMoney = 0.0;

    public function setDefaultStartingMoney(float $amount) : void{
        $this->defaultStartingMoney = max(0.0, $amount);
    }

    public function __construct(string $path){
        if(!class_exists(SQLite3::class)){
            throw new \RuntimeException('The SQLite3 extension is required by SmpMaster.');
        }
        $this->db = new SQLite3($path);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('CREATE TABLE IF NOT EXISTS players (
            name TEXT PRIMARY KEY COLLATE NOCASE,
            money REAL NOT NULL DEFAULT 0,
            shards INTEGER NOT NULL DEFAULT 0,
            kills INTEGER NOT NULL DEFAULT 0,
            deaths INTEGER NOT NULL DEFAULT 0,
            bounty REAL NOT NULL DEFAULT 0,
            sold_total REAL NOT NULL DEFAULT 0,
            playtime INTEGER NOT NULL DEFAULT 0,
            first_join INTEGER NOT NULL DEFAULT 0
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sell_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player TEXT NOT NULL,
            item TEXT NOT NULL,
            amount INTEGER NOT NULL,
            payout REAL NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sell_history_player ON sell_history(player, id DESC)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS homes (
            player TEXT NOT NULL COLLATE NOCASE,
            home_name TEXT NOT NULL COLLATE NOCASE,
            world TEXT NOT NULL,
            x REAL NOT NULL, y REAL NOT NULL, z REAL NOT NULL,
            yaw REAL NOT NULL DEFAULT 0, pitch REAL NOT NULL DEFAULT 0,
            PRIMARY KEY(player, home_name)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS warps (
            warp_name TEXT PRIMARY KEY COLLATE NOCASE,
            world TEXT NOT NULL,
            x REAL NOT NULL, y REAL NOT NULL, z REAL NOT NULL,
            yaw REAL NOT NULL DEFAULT 0, pitch REAL NOT NULL DEFAULT 0,
            creator TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS crate_keys (
            player TEXT NOT NULL COLLATE NOCASE,
            crate_name TEXT NOT NULL COLLATE NOCASE,
            amount INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(player, crate_name)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS teams (
            team_name TEXT PRIMARY KEY COLLATE NOCASE,
            leader TEXT NOT NULL COLLATE NOCASE,
            created_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS team_members (
            player TEXT PRIMARY KEY COLLATE NOCASE,
            team_name TEXT NOT NULL COLLATE NOCASE,
            role TEXT NOT NULL DEFAULT \'member\'
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ah_listings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seller TEXT NOT NULL COLLATE NOCASE,
            price REAL NOT NULL CHECK(price > 0),
            item_data TEXT NOT NULL,
            item_name TEXT NOT NULL,
            amount INTEGER NOT NULL CHECK(amount > 0),
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ah_listings_expiry ON ah_listings(expires_at, id DESC)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ah_listings_seller ON ah_listings(seller COLLATE NOCASE, expires_at)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ah_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner TEXT NOT NULL COLLATE NOCASE,
            item_data TEXT NOT NULL,
            reason TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ah_claims_owner ON ah_claims(owner COLLATE NOCASE, id)');
    }

    public function close() : void{ $this->db->close(); }

    private function statement(string $sql, array $params = []) : SQLite3Stmt{
        $stmt = $this->db->prepare($sql);
        foreach($params as $key => $value){
            if(is_int($value)) $type = SQLITE3_INTEGER;
            elseif(is_float($value)) $type = SQLITE3_FLOAT;
            elseif($value === null) $type = SQLITE3_NULL;
            else $type = SQLITE3_TEXT;
            $stmt->bindValue(is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':'), $value, $type);
        }
        return $stmt;
    }

    private function execute(string $sql, array $params = []) : bool{
        return $this->statement($sql, $params)->execute() !== false;
    }

    private function fetchOne(string $sql, array $params = []) : ?array{
        $result = $this->statement($sql, $params)->execute();
        if($result === false) return null;
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return $row === false ? null : $row;
    }

    private function fetchAll(string $sql, array $params = []) : array{
        $result = $this->statement($sql, $params)->execute();
        if($result === false) return [];
        $rows = [];
        while(($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) $rows[] = $row;
        $result->finalize();
        return $rows;
    }

    public function ensurePlayer(string $name, ?float $startingMoney = null) : void{
        $this->execute('INSERT OR IGNORE INTO players(name, money, first_join) VALUES(:name, :money, :joined)', [
            'name' => $name, 'money' => max(0.0, $startingMoney ?? $this->defaultStartingMoney), 'joined' => time()
        ]);
    }

    /** True when a row exists for this name (read paths use this instead of creating ghost rows). */
    public function playerExists(string $name) : bool{
        return $this->fetchOne('SELECT 1 AS ok FROM players WHERE name = :name', ['name' => $name]) !== null;
    }

    /** Returns the stored spelling of a player name, or null if unknown. */
    public function getCanonicalName(string $name) : ?string{
        $row = $this->fetchOne('SELECT name FROM players WHERE name = :name', ['name' => $name]);
        return $row === null ? null : (string)$row['name'];
    }

    public function getBalance(string $name) : float{
        return (float) ($this->fetchOne('SELECT money FROM players WHERE name = :name', ['name' => $name])['money'] ?? 0.0);
    }

    public function setBalance(string $name, float $amount) : void{
        $this->ensurePlayer($name);
        $this->execute('UPDATE players SET money = :money WHERE name = :name', ['money' => max(0.0, $amount), 'name' => $name]);
    }

    public function addMoney(string $name, float $delta) : void{
        $this->ensurePlayer($name);
        $this->execute('UPDATE players SET money = MAX(0, money + :delta) WHERE name = :name', ['delta' => $delta, 'name' => $name]);
    }

    public function transferMoney(string $from, string $to, float $amount) : bool{
        if(strcasecmp($from, $to) === 0 || $amount <= 0 || !$this->playerExists($from) || !$this->playerExists($to) || $this->getBalance($from) < $amount) return false;
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $this->execute('UPDATE players SET money = money - :amount WHERE name = :from AND money >= :amount', ['amount' => $amount, 'from' => $from]);
            $changed = $this->db->changes();
            if($changed !== 1){ $this->db->exec('ROLLBACK'); return false; }
            $this->execute('UPDATE players SET money = money + :amount WHERE name = :to', ['amount' => $amount, 'to' => $to]);
            $this->db->exec('COMMIT');
            return true;
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); throw $e; }
    }

    public function getShards(string $name) : int{
        return (int) ($this->fetchOne('SELECT shards FROM players WHERE name = :name', ['name' => $name])['shards'] ?? 0);
    }

    public function setShards(string $name, int $amount) : void{
        $this->ensurePlayer($name);
        $this->execute('UPDATE players SET shards = :amount WHERE name = :name', ['amount' => max(0, $amount), 'name' => $name]);
    }

    public function addShards(string $name, int $amount) : void{
        $this->ensurePlayer($name);
        $this->execute('UPDATE players SET shards = MAX(0, shards + :amount) WHERE name = :name', ['amount' => $amount, 'name' => $name]);
    }

    public function transferShards(string $from, string $to, int $amount) : bool{
        if(strcasecmp($from, $to) === 0 || $amount < 1 || !$this->playerExists($from) || !$this->playerExists($to) || $this->getShards($from) < $amount) return false;
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $this->execute('UPDATE players SET shards = shards - :amount WHERE name = :from AND shards >= :amount', ['amount' => $amount, 'from' => $from]);
            if($this->db->changes() !== 1){ $this->db->exec('ROLLBACK'); return false; }
            $this->execute('UPDATE players SET shards = shards + :amount WHERE name = :to', ['amount' => $amount, 'to' => $to]);
            $this->db->exec('COMMIT'); return true;
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); throw $e; }
    }

    public function addStat(string $name, string $column, int $amount = 1) : void{
        $allowed = ['kills', 'deaths', 'playtime'];
        if(!in_array($column, $allowed, true)) return;
        $this->ensurePlayer($name);
        $this->execute("UPDATE players SET {$column} = MAX(0, {$column} + :amount) WHERE name = :name", ['amount' => $amount, 'name' => $name]);
    }

    public function getStats(string $name) : array{
        return $this->fetchOne('SELECT * FROM players WHERE name = :name', ['name' => $name]) ?? [];
    }

    public function setBounty(string $name, float $amount) : void{
        $this->ensurePlayer($name);
        $this->execute('UPDATE players SET bounty = :amount WHERE name = :name', ['amount' => max(0.0, $amount), 'name' => $name]);
    }

    public function addBounty(string $name, float $amount) : void{
        $this->ensurePlayer($name);
        $this->execute('UPDATE players SET bounty = bounty + :amount WHERE name = :name', ['amount' => max(0.0, $amount), 'name' => $name]);
    }

    public function getBounty(string $name) : float{
        return (float) ($this->fetchOne('SELECT bounty FROM players WHERE name = :name', ['name' => $name])['bounty'] ?? 0.0);
    }

    public function claimBounty(string $victim, string $killer) : float{
        $amount = $this->getBounty($victim);
        if($amount <= 0 || strcasecmp($victim, $killer) === 0) return 0.0;
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $this->execute('UPDATE players SET bounty = 0 WHERE name = :victim AND bounty > 0', ['victim' => $victim]);
            if($this->db->changes() !== 1){ $this->db->exec('ROLLBACK'); return 0.0; }
            $this->ensurePlayer($killer);
            $this->execute('UPDATE players SET money = money + :amount WHERE name = :killer', ['amount' => $amount, 'killer' => $killer]);
            $this->db->exec('COMMIT'); return $amount;
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); throw $e; }
    }

    public function addSale(string $player, string $item, int $amount, float $payout) : void{
        $this->ensurePlayer($player);
        $this->execute('INSERT INTO sell_history(player, item, amount, payout, created_at) VALUES(:player, :item, :amount, :payout, :created)', [
            'player' => $player, 'item' => $item, 'amount' => $amount, 'payout' => $payout, 'created' => time()
        ]);
        $this->execute('UPDATE players SET sold_total = sold_total + :payout WHERE name = :player', ['payout' => $payout, 'player' => $player]);
    }

    public function getSoldTotal(string $player) : float{
        return (float) ($this->fetchOne('SELECT sold_total FROM players WHERE name = :player', ['player' => $player])['sold_total'] ?? 0.0);
    }

    public function getSellHistory(string $player, int $limit = 8) : array{
        return $this->fetchAll('SELECT item, amount, payout, created_at FROM sell_history WHERE player = :player COLLATE NOCASE ORDER BY id DESC LIMIT :limit', ['player' => $player, 'limit' => $limit]);
    }

    public function topPlayers(string $column = 'money', int $limit = 10) : array{
        $allowed = ['money', 'kills', 'shards', 'playtime', 'sold_total', 'bounty'];
        if(!in_array($column, $allowed, true)) $column = 'money';
        return $this->fetchAll("SELECT name, {$column} AS value FROM players ORDER BY {$column} DESC LIMIT " . max(1, min(50, $limit)));
    }

    public function setHome(string $player, string $home, string $world, float $x, float $y, float $z, float $yaw, float $pitch) : void{
        $this->execute('INSERT INTO homes(player, home_name, world, x, y, z, yaw, pitch) VALUES(:player,:home,:world,:x,:y,:z,:yaw,:pitch) ON CONFLICT(player, home_name) DO UPDATE SET world=excluded.world,x=excluded.x,y=excluded.y,z=excluded.z,yaw=excluded.yaw,pitch=excluded.pitch', [
            'player'=>$player,'home'=>$home,'world'=>$world,'x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>$yaw,'pitch'=>$pitch
        ]);
    }

    public function getHome(string $player, string $home) : ?array{
        return $this->fetchOne('SELECT * FROM homes WHERE player = :player AND home_name = :home', ['player'=>$player,'home'=>$home]);
    }

    public function getHomes(string $player) : array{
        return $this->fetchAll('SELECT home_name FROM homes WHERE player = :player ORDER BY home_name ASC', ['player'=>$player]);
    }

    public function deleteHome(string $player, string $home) : bool{
        $this->execute('DELETE FROM homes WHERE player = :player AND home_name = :home', ['player'=>$player,'home'=>$home]);
        return $this->db->changes() > 0;
    }

    public function setWarp(string $name, string $world, float $x, float $y, float $z, float $yaw, float $pitch, string $creator) : void{
        $this->execute('INSERT INTO warps(warp_name,world,x,y,z,yaw,pitch,creator) VALUES(:name,:world,:x,:y,:z,:yaw,:pitch,:creator) ON CONFLICT(warp_name) DO UPDATE SET world=excluded.world,x=excluded.x,y=excluded.y,z=excluded.z,yaw=excluded.yaw,pitch=excluded.pitch,creator=excluded.creator', [
            'name'=>$name,'world'=>$world,'x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>$yaw,'pitch'=>$pitch,'creator'=>$creator
        ]);
    }

    public function getWarp(string $name) : ?array{ return $this->fetchOne('SELECT * FROM warps WHERE warp_name = :name', ['name'=>$name]); }
    public function getWarps() : array{ return $this->fetchAll('SELECT warp_name FROM warps ORDER BY warp_name ASC'); }
    public function deleteWarp(string $name) : bool{
        $this->execute('DELETE FROM warps WHERE warp_name = :name', ['name'=>$name]);
        return $this->db->changes() > 0;
    }

    public function addKey(string $player, string $crate, int $amount) : void{
        if($amount < 1) return;
        $this->execute('INSERT INTO crate_keys(player,crate_name,amount) VALUES(:player,:crate,:amount) ON CONFLICT(player,crate_name) DO UPDATE SET amount=amount+excluded.amount', ['player'=>$player,'crate'=>$crate,'amount'=>$amount]);
    }
    public function getKeys(string $player, string $crate) : int{
        return (int) ($this->fetchOne('SELECT amount FROM crate_keys WHERE player=:player AND crate_name=:crate', ['player'=>$player,'crate'=>$crate])['amount'] ?? 0);
    }
    public function useKey(string $player, string $crate) : bool{
        $this->execute('UPDATE crate_keys SET amount=amount-1 WHERE player=:player AND crate_name=:crate AND amount>0', ['player'=>$player,'crate'=>$crate]);
        if($this->db->changes() > 0){
            $this->execute('DELETE FROM crate_keys WHERE player=:player AND crate_name=:crate AND amount<=0', ['player'=>$player,'crate'=>$crate]);
            return true;
        }
        return false;
    }

    public function createTeam(string $team, string $leader) : bool{
        if($this->getTeamFor($leader) !== null) return false;
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $this->execute('INSERT INTO teams(team_name,leader,created_at) VALUES(:team,:leader,:created)', ['team'=>$team,'leader'=>$leader,'created'=>time()]);
            $this->execute('INSERT INTO team_members(player,team_name,role) VALUES(:player,:team,\'leader\')', ['player'=>$leader,'team'=>$team]);
            $this->db->exec('COMMIT'); return true;
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); return false; }
    }
    public function getTeam(string $team) : ?array{ return $this->fetchOne('SELECT * FROM teams WHERE team_name=:team', ['team'=>$team]); }
    public function getTeamFor(string $player) : ?array{
        $member = $this->fetchOne('SELECT team_name,role FROM team_members WHERE player=:player', ['player'=>$player]);
        if($member === null) return null;
        $team = $this->getTeam((string)$member['team_name']);
        return $team === null ? null : array_merge($team, ['role'=>$member['role']]);
    }
    public function addTeamMember(string $team, string $player) : bool{
        if($this->getTeam($team) === null || $this->getTeamFor($player) !== null) return false;
        return $this->execute('INSERT INTO team_members(player,team_name,role) VALUES(:player,:team,\'member\')', ['player'=>$player,'team'=>$team]);
    }
    public function removeTeamMember(string $player) : void{
        $team = $this->getTeamFor($player);
        if($team === null) return;
        if(strcasecmp((string)$team['leader'], $player) === 0){
            $this->execute('DELETE FROM team_members WHERE team_name=:team', ['team'=>$team['team_name']]);
            $this->execute('DELETE FROM teams WHERE team_name=:team', ['team'=>$team['team_name']]);
        }else{
            $this->execute('DELETE FROM team_members WHERE player=:player', ['player'=>$player]);
        }
    }
    public function getTeamMembers(string $team) : array{
        return $this->fetchAll('SELECT player,role FROM team_members WHERE team_name=:team ORDER BY role DESC, player ASC', ['team'=>$team]);
    }

    /** @return int new listing ID */
    public function createAhListing(string $seller, float $price, string $itemData, string $itemName, int $amount, int $expiresAt) : int{
        $this->ensurePlayer($seller);
        $this->execute('INSERT INTO ah_listings(seller,price,item_data,item_name,amount,created_at,expires_at) VALUES(:seller,:price,:data,:name,:amount,:created,:expires)', [
            'seller' => $seller,
            'price' => $price,
            'data' => $itemData,
            'name' => $itemName,
            'amount' => $amount,
            'created' => time(),
            'expires' => $expiresAt
        ]);
        return $this->db->lastInsertRowID();
    }

    public function countAhListings() : int{
        $row = $this->fetchOne('SELECT COUNT(*) AS total FROM ah_listings WHERE expires_at>:now', ['now' => time()]);
        return (int)($row['total'] ?? 0);
    }

    public function getAhListing(int $id) : ?array{
        return $this->fetchOne('SELECT * FROM ah_listings WHERE id=:id AND expires_at>:now', ['id' => $id, 'now' => time()]);
    }

    /** @return list<array<string, mixed>> */
    public function getAhListings(int $limit = 18, int $offset = 0) : array{
        $limit = max(1, min(36, $limit));
        $offset = max(0, $offset);
        return $this->fetchAll('SELECT id,seller,price,item_name,amount,created_at,expires_at FROM ah_listings WHERE expires_at>:now ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, ['now' => time()]);
    }

    /** @return list<array<string, mixed>> */
    public function getAhListingsBySeller(string $seller, int $limit = 36) : array{
        return $this->fetchAll('SELECT * FROM ah_listings WHERE seller=:seller AND expires_at>:now ORDER BY created_at DESC LIMIT ' . max(1, min(72, $limit)), ['seller' => $seller, 'now' => time()]);
    }

    public function getAhListingCountBySeller(string $seller) : int{
        $row = $this->fetchOne('SELECT COUNT(*) AS total FROM ah_listings WHERE seller=:seller AND expires_at>:now', ['seller' => $seller, 'now' => time()]);
        return (int)($row['total'] ?? 0);
    }

    /** Cancels a listing and writes its item to the owner's claim queue in the same transaction. */
    public function cancelAhListingToClaim(int $id, string $seller) : ?array{
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $row = $this->fetchOne('SELECT * FROM ah_listings WHERE id=:id AND seller=:seller AND expires_at>:now', ['id' => $id, 'seller' => $seller, 'now' => time()]);
            if($row === null){ $this->db->exec('ROLLBACK'); return null; }
            $this->execute('DELETE FROM ah_listings WHERE id=:id AND seller=:seller', ['id' => $id, 'seller' => $seller]);
            if($this->db->changes() !== 1){ $this->db->exec('ROLLBACK'); return null; }
            $this->execute('INSERT INTO ah_claims(owner,item_data,reason,created_at) VALUES(:owner,:data,:reason,:created)', [
                'owner' => (string)$row['seller'], 'data' => (string)$row['item_data'], 'reason' => 'cancelled', 'created' => time()
            ]);
            $this->db->exec('COMMIT');
            return $row;
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); throw $e; }
    }

    /** Atomically charges the buyer, credits the seller, removes the listing and queues its item for collection. */
    public function buyAhListing(int $id, string $buyer) : ?array{
        $this->ensurePlayer($buyer);
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $row = $this->fetchOne('SELECT * FROM ah_listings WHERE id=:id AND expires_at>:now', ['id' => $id, 'now' => time()]);
            if($row === null || strcasecmp((string)$row['seller'], $buyer) === 0){ $this->db->exec('ROLLBACK'); return null; }
            $price = (float)$row['price'];
            $this->execute('UPDATE players SET money=money-:price WHERE name=:buyer AND money>=:price', ['price' => $price, 'buyer' => $buyer]);
            if($this->db->changes() !== 1){ $this->db->exec('ROLLBACK'); return null; }
            $this->ensurePlayer((string)$row['seller']);
            $this->execute('UPDATE players SET money=money+:price WHERE name=:seller', ['price' => $price, 'seller' => (string)$row['seller']]);
            $this->execute('DELETE FROM ah_listings WHERE id=:id AND expires_at>:now', ['id' => $id, 'now' => time()]);
            if($this->db->changes() !== 1){ $this->db->exec('ROLLBACK'); return null; }
            // The item's ownership is persisted before the transaction commits, so a server restart
            // between payment and inventory delivery cannot destroy a successful purchase.
            $this->execute('INSERT INTO ah_claims(owner,item_data,reason,created_at) VALUES(:owner,:data,:reason,:created)', [
                'owner' => $buyer, 'data' => (string)$row['item_data'], 'reason' => 'purchase', 'created' => time()
            ]);
            $this->db->exec('COMMIT');
            return $row;
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); throw $e; }
    }

    public function addAhClaim(string $owner, string $itemData, string $reason) : void{
        $this->ensurePlayer($owner);
        $this->execute('INSERT INTO ah_claims(owner,item_data,reason,created_at) VALUES(:owner,:data,:reason,:created)', [
            'owner' => $owner, 'data' => $itemData, 'reason' => $reason, 'created' => time()
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function getAhClaims(string $owner, int $limit = 36) : array{
        return $this->fetchAll('SELECT * FROM ah_claims WHERE owner=:owner ORDER BY id ASC LIMIT ' . max(1, min(72, $limit)), ['owner' => $owner]);
    }

    /** Atomically removes one claim and returns the stored item payload. */
    public function claimAhItem(int $id, string $owner) : ?array{
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $row = $this->fetchOne('SELECT * FROM ah_claims WHERE id=:id AND owner=:owner', ['id' => $id, 'owner' => $owner]);
            if($row === null){ $this->db->exec('ROLLBACK'); return null; }
            $this->execute('DELETE FROM ah_claims WHERE id=:id AND owner=:owner', ['id' => $id, 'owner' => $owner]);
            if($this->db->changes() !== 1){ $this->db->exec('ROLLBACK'); return null; }
            $this->db->exec('COMMIT');
            return $row;
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); throw $e; }
    }

    /** Moves expired listings to an offline-safe claim queue, exactly once. */
    public function expireAhListings() : int{
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $rows = $this->fetchAll('SELECT * FROM ah_listings WHERE expires_at<=:now ORDER BY id ASC', ['now' => time()]);
            foreach($rows as $row){
                $this->execute('INSERT INTO ah_claims(owner,item_data,reason,created_at) VALUES(:owner,:data,:reason,:created)', [
                    'owner' => (string)$row['seller'],
                    'data' => (string)$row['item_data'],
                    'reason' => 'expired',
                    'created' => time()
                ]);
                $this->execute('DELETE FROM ah_listings WHERE id=:id AND expires_at<=:now', ['id' => (int)$row['id'], 'now' => time()]);
            }
            $this->db->exec('COMMIT');
            return count($rows);
        }catch(\Throwable $e){ $this->db->exec('ROLLBACK'); throw $e; }
    }

}
