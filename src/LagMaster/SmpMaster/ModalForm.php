<?php

declare(strict_types=1);

namespace LagMaster\SmpMaster;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

/** Small Bedrock custom form with text inputs. */
final class ModalForm implements Form{
    /** @param list<array{label:string, placeholder?:string, default?:string}> $inputs */
    public function __construct(
        private string $title,
        private string $content,
        private array $inputs,
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
            }elseif(preg_match('/\b(error|failed|not found|cancelled|canceled|warning|denied|expired|invalid|cannot|can\x27t|full|insufficient)\b/i', $line)){
                $color = '§c';
            }else{
                $color = $i === 0 ? '§f' : '§7';
            }
            $lines[$i] = $color . $line;
        }
        return implode("\n", $lines);
    }

    public function jsonSerialize() : array{
        $content = [];
        if($this->content !== '') $content[] = ['type' => 'label', 'text' => self::colorContent($this->content)];
        foreach($this->inputs as $input){
            $content[] = [
                'type' => 'input',
                'text' => '§f' . self::plain((string)$input['label']),
                'placeholder' => self::plain((string)($input['placeholder'] ?? '')),
                'default' => self::plain((string)($input['default'] ?? ''))
            ];
        }
        return ['type' => 'custom_form', 'title' => '§l§6' . trim(self::plain($this->title)), 'content' => $content];
    }

    public function handleResponse(Player $player, $data) : void{
        if(!is_array($data)) return;
        ($this->callback)($player, $data);
    }
}
