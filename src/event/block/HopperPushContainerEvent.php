<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\block\Hopper;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;

class HopperPushContainerEvent extends HopperPushEvent{
	public function __construct(Hopper $block, HopperInventory $inventory, Block $destination, private Inventory $destinationInventory, Item $item){
		parent::__construct($block, $inventory, $destination, $item);
	}

	public function getDestinationInventory() : Inventory{
		return $this->destinationInventory;
	}
}