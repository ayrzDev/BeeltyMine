<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\block\Hopper;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;

class HopperPullContainerEvent extends HopperPullEvent{
	public function __construct(Hopper $block, HopperInventory $inventory, Block $origin, private Inventory $originInventory, Item $item){
		parent::__construct($block, $inventory, $origin, $item);
	}

	public function getOriginInventory() : Inventory{
		return $this->originInventory;
	}
}