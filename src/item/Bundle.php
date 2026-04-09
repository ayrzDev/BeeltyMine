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

namespace pocketmine\item;

use pocketmine\inventory\BaseInventory;
use pocketmine\inventory\BundleInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryHolder;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use pocketmine\world\sound\BundleDropContentsSound;
use function max;

final class Bundle extends Item implements InventoryAwareItem, InventoryHolder{
	public const TAG_DYNAMIC_ID = "bundle_id";
	public const TAG_CONTENTS = "storage_item_component_content";

	private static int $nextDynamicId = 1;

	private ?BundleInventory $inventory = null;
	private ?Inventory $carrierInventory = null;

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getInventory() : BundleInventory{
		return $this->inventory ??= new BundleInventory($this);
	}

	public function onInventoryChange(Inventory $inventory) : void{
		$this->carrierInventory = $inventory;
		$this->getBundleId();
		if($this->inventory !== null){
			$this->inventory->setHolder($this);
		}
	}

	public function getCarrierInventory() : ?Inventory{
		return $this->carrierInventory;
	}

	public function getBundleId() : int{
		$tag = $this->getNamedTag();
		if($tag->getTag(self::TAG_CONTENTS) === null){
			$tag->setTag(self::TAG_CONTENTS, new ListTag([], NBT::TAG_Compound));
		}
		$existingId = $tag->getInt(self::TAG_DYNAMIC_ID, 0);
		if($existingId > 0){
			$this->setNamedTag($tag);
			self::$nextDynamicId = max(self::$nextDynamicId, $existingId + 1);
			return $existingId;
		}

		$dynamicId = self::$nextDynamicId++;
		$tag->setInt(self::TAG_DYNAMIC_ID, $dynamicId);
		$this->setNamedTag($tag);

		return $dynamicId;
	}

	public function getStoredWeight() : int{
		return $this->getInventory()->getUsedWeight();
	}

	/**
	 * @return Item[]
	 * @phpstan-return array<int, Item>
	 */
	public function loadBundleContents() : array{
		$result = [];
		$contentsTag = $this->getNamedTag()->getListTag(self::TAG_CONTENTS, CompoundTag::class);
		if($contentsTag === null){
			return $result;
		}

		foreach($contentsTag as $itemTag){
			$slot = $itemTag->getByte("Slot", 0);
			$result[$slot] = Item::safeNbtDeserialize($itemTag, "Bundle slot $slot");
		}

		return $result;
	}

	public function saveBundleContents() : void{
		$contents = new ListTag([], NBT::TAG_Compound);
		foreach($this->getInventory()->getContents() as $slot => $item){
			$contents->push($item->nbtSerialize($slot));
		}

		$tag = $this->getNamedTag();
		$tag->setInt(self::TAG_DYNAMIC_ID, $this->getBundleId());
		$tag->setTag(self::TAG_CONTENTS, $contents);
		$this->setNamedTag($tag);
		$this->syncCarrierSlot();
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$slot = $this->getInventory()->firstOccupiedSlot();
		if($slot === null){
			return ItemUseResult::FAIL;
		}

		$item = $this->getInventory()->takeFromSlot($slot);
		if($item->isNull()){
			return ItemUseResult::FAIL;
		}

		$player->dropItem($item);
		$player->broadcastSound(new BundleDropContentsSound());

		return ItemUseResult::SUCCESS;
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);

		$contents = new ListTag([], NBT::TAG_Compound);
		if($this->inventory !== null){
			foreach($this->inventory->getContents() as $slot => $item){
				$contents->push($item->nbtSerialize($slot));
			}
		}else{
			$existingContents = $tag->getListTag(self::TAG_CONTENTS, CompoundTag::class);
			if($existingContents !== null){
				$contents = clone $existingContents;
			}
		}

		$tag->setTag(self::TAG_CONTENTS, $contents);
	}

	private function syncCarrierSlot() : void{
		$inventory = $this->carrierInventory;
		if(!$inventory instanceof BaseInventory){
			return;
		}

		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			if($inventory->getUnclonedItem($slot) !== $this){
				continue;
			}

			foreach($inventory->getViewers() as $viewer){
				$invManager = $viewer->getNetworkSession()->getInvManager();
				if($invManager !== null){
					$invManager->onSlotChange($inventory, $slot);
				}
			}
			return;
		}
	}

	public function __clone(){
		parent::__clone();
		$this->inventory = null;
		$this->carrierInventory = null;
	}
}
