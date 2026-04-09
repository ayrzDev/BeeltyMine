<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Hopper;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\block\Jukebox;
use pocketmine\item\Record;

class HopperPushJukeboxEvent extends HopperPushEvent{
	public function __construct(Hopper $block, HopperInventory $inventory, private Jukebox $destination, private Record $item){
		parent::__construct($block, $inventory, $destination, $item);
	}

	public function getDestination() : Jukebox{
		return $this->destination;
	}

	public function getItem() : Record{
		return clone $this->item;
	}
}