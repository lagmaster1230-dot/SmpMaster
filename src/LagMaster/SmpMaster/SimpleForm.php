<?php

declare(strict_types=1);

namespace LagMaster\SmpMaster;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class SimpleForm implements Form{
    /** @param list<string> $buttons */
    public function __construct(
        private string $title,
        private string $content,
        private array $buttons,
        private Closure $callback
    ){}

    private static function plain(string $text) : string{
        return preg_replace('/§[0-9a-fk-or]/i', '', $text) ?? $text;
    }

    private static function colorContent(string $text) : string{
        $text = str_replace(["\r\n", "\r"], "\n", self::plain($text));
        $lines = explode("\n", $text);
        foreach($lines as $i => $line){
            if(trim($line) === '') continue;
            if(preg_match('/\b(price|total|balance|money|cost|amount|keys?|multiplier|bid|value|reward|profit|shards?)\b/i', $line)){
                $color = '§e';
            }elseif(preg_match('/\b(error|failed|not found|cancelled|canceled|warning|denied|expired|invalid|cannot|can\x27t|full|insufficient|no pending)\b/i', $line)){
                $color = '§c';
            }elseif(preg_match('/\b(success|purchased|accepted|saved|received|teleported|claimed|sold)\b/i', $line)){
                $color = '§a';
            }else{
                $color = $i === 0 ? '§f' : '§7';
            }
            $lines[$i] = $color . $line;
        }
        return implode("\n", $lines);
    }

    private static function colorButton(string $text) : string{
        $text = str_replace(["\r\n", "\r"], "\n", self::plain($text));
        $lines = explode("\n", $text);
        $first = strtolower(trim($lines[0] ?? ''));
        if(preg_match('/\b(cancel|close|deny|delete|remove|reject|decline|unlist)\b/i', $first)){
            $firstColor = '§c';
        }elseif(preg_match('/\b(back|previous|next|page|refresh)\b/i', $first)){
            $firstColor = '§e';
        }elseif(preg_match('/\b(buy|sell|accept|claim|confirm|purchase|open|teleport|create|join|submit|start|yes|collect)\b/i', $first)){
            $firstColor = '§a';
        }else{
            $firstColor = '§f';
        }
        foreach($lines as $i => $line){
            if(trim($line) === '') continue;
            if($i === 0){
                $color = $firstColor;
            }elseif(preg_match('/\b(price|total|balance|money|cost|amount|keys?|multiplier|bid|value|reward|profit|shards?)\b/i', $line)){
                $color = '§e';
            }else{
                $color = '§7';
            }
            $lines[$i] = $color . $line;
        }
        return implode("\n", $lines);
    }

    public function jsonSerialize() : array{
        $title = trim(self::plain($this->title));
        return [
            'type' => 'form',
            'title' => '§l§6' . $title,
            'content' => self::colorContent($this->content),
            'buttons' => array_map(static fn(string $text) : array => ['text' => self::colorButton($text)], $this->buttons)
        ];
    }

    public function handleResponse(Player $player, $data) : void{
        if(!is_int($data) || !array_key_exists($data, $this->buttons)) return;
        ($this->callback)($player, $data);
    }
}
