<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\block\Hopper;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\item\Item;

abstract class HopperPushEvent extends HopperEvent{
	public function __construct(Hopper $block, HopperInventory $inventory, private Block $destination, private Item $item){
		parent::__construct($block, $inventory);
	}

	public function getDestination() : Block{
		return $this->destination;
	}

	public function getItem() : Item{
		return clone $this->item;
	}
}