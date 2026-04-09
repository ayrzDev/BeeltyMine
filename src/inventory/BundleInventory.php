<?php

/*
 *
 *     ____            ____        __  ____
 *    / __ )___  ___  / / /___  __/  |/  (_)___  ___
 *   / __  / _ \/ _ \/ / __/ / / / /|_/ / / __ \/ _ \
 *  / /_/ /  __/  __/ / /_/ /_/ / /  / / / / / /  __/
 * /_____/\___/\___/_/\__/\__, /_/  /_/_/_/ /_/\___/
 *                       /____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Ayrz
 * @team BeeltyMine
 * 
 * 
 */

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\inventory\transaction\action\validator\CallbackSlotValidator;
use pocketmine\item\Bundle;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

final class BundleInventory extends SimpleInventory{
	public const MAX_WEIGHT = 64;

	public function __construct(
		private Bundle $holder
	){
		parent::__construct(self::MAX_WEIGHT);

		foreach($holder->loadBundleContents() as $slot => $item){
			$this->slots[$slot] = $item->isNull() ? null : clone $item;
		}

		$this->getListeners()->add(CallbackInventoryListener::onAnyChange(function() : void{
			$this->holder->saveBundleContents();
		}));
		$this->getSlotValidators()->add(new CallbackSlotValidator(self::validateWeight(...)));
	}

	public function getHolder() : Bundle{
		return $this->holder;
	}

	public function setHolder(Bundle $holder) : void{
		$this->holder = $holder;
	}

	public function setItem(int $index, Item $item) : void{
		$this->assertWeightFits($index, $item);
		parent::setItem($index, $item);
	}

	public function getUsedWeight() : int{
		$weight = 0;
		for($i = 0, $size = $this->getSize(); $i < $size; ++$i){
			$weight += $this->getItemWeight($this->getUnclonedItem($i));
		}

		return $weight;
	}

	public function firstOccupiedSlot() : ?int{
		for($i = 0, $size = $this->getSize(); $i < $size; ++$i){
			if(!$this->isSlotEmpty($i)){
				return $i;
			}
		}

		return null;
	}

	public function takeFromSlot(int $slot) : Item{
		$item = clone $this->getUnclonedItem($slot);
		if($item->isNull()){
			return VanillaItems::AIR();
		}

		$this->clear($slot);
		return $item;
	}

	public function getItemWeight(Item $item) : int{
		if($item->isNull()){
			return 0;
		}
		if($item instanceof Bundle){
			return $item->getStoredWeight() + 4;
		}

		return intdiv(self::MAX_WEIGHT, $item->getMaxStackSize()) * $item->getCount();
	}

	private function getUsedWeightExcludingSlot(int $slot) : int{
		$weight = 0;
		for($i = 0, $size = $this->getSize(); $i < $size; ++$i){
			if($i === $slot){
				continue;
			}
			$weight += $this->getItemWeight($this->getUnclonedItem($i));
		}

		return $weight;
	}

	private function assertWeightFits(int $slot, Item $item) : void{
		if($this->getUsedWeightExcludingSlot($slot) + $this->getItemWeight($item) > self::MAX_WEIGHT){
			throw new \InvalidArgumentException("Cannot overfill bundle contents");
		}
	}

	private static function validateWeight(Inventory $inventory, Item $item, int $slot) : ?TransactionValidationException{
		if(!$inventory instanceof self){
			return new TransactionValidationException("Bundle validator received an unexpected inventory type");
		}

		if($inventory->getUsedWeightExcludingSlot($slot) + $inventory->getItemWeight($item) > self::MAX_WEIGHT){
			return new TransactionValidationException("Cannot overfill bundle contents");
		}

		return null;
	}
}
