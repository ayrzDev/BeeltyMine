<?php

/*
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
 */

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\block\Hopper as BlockHopper;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\block\Jukebox as BlockJukebox;
use pocketmine\block\utils\HopperDataStore;
use pocketmine\block\utils\HopperRuntime;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\block\BlockItemPickupEvent;
use pocketmine\event\block\HopperPullContainerEvent;
use pocketmine\event\block\HopperPushContainerEvent;
use pocketmine\event\block\HopperPushJukeboxEvent;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\item\Bucket;
use pocketmine\item\Item;
use pocketmine\item\Record;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\Position;
use pocketmine\world\World;
use function max;
use function min;

/**
 * @author rockanxy
 */
class Hopper extends Spawnable implements Container, Nameable{

	use ContainerTrait;
	use NameableTrait;

	private const TAG_TRANSFER_COOLDOWN = 'TransferCooldown';

	private HopperInventory $inventory;
	private int $transferCooldown = 0;
	private int $lastUpdateTick;
	private ?int $nextUpdateTick = null;
	private ?int $lastProcessedTick = null;
	/** @var AxisAlignedBB[]|null */
	private ?array $pickupBoxes = null;
	private bool $suppressInventorySchedule = false;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->lastUpdateTick = $world->getServer()->getTick();
		$this->inventory = new HopperInventory($this->position);
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(
			function(Inventory $unused) : void{
				if(!$this->suppressInventorySchedule){
					$this->scheduleUpdate(1);
				}
			}
		));
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadItems($nbt);
		$this->loadName($nbt);

		$this->setTransferCooldown($nbt->getInt(self::TAG_TRANSFER_COOLDOWN, 0));
		$this->scheduleUpdate($this->transferCooldown);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveItems($nbt);
		$this->saveName($nbt);

		$nbt->setInt(self::TAG_TRANSFER_COOLDOWN, $this->getTransferCooldown());
	}

	public function close() : void{
		if(!$this->closed){
			$this->nextUpdateTick = null;
			HopperDataStore::getInstance()->removeBlock($this->position);
			$this->inventory->removeAllViewers();

			parent::close();
		}
	}

	public function getDefaultName() : string{
		return 'Hopper';
	}

	public function getInventory() : HopperInventory{
		return $this->inventory;
	}

	public function getRealInventory() : HopperInventory{
		return $this->inventory;
	}

	public function getTransferCooldown() : int{
		return max(0, $this->transferCooldown - ($this->position->getWorld()->getServer()->getTick() - $this->lastUpdateTick));
	}

	public function setTransferCooldown(int $transferCooldown) : void{
		$this->transferCooldown = max(0, $transferCooldown);
		$this->lastUpdateTick = $this->position->getWorld()->getServer()->getTick();
	}

	public function scheduleUpdate(int $delay = 1) : void{
		if($this->closed){
			return;
		}

		$delay = max(0, $delay);
		$currentTick = $this->position->getWorld()->getServer()->getTick();
		$scheduledTick = $currentTick + $delay;
		if($this->nextUpdateTick !== null && $this->nextUpdateTick <= $scheduledTick){
			return;
		}

		$actualDelay = HopperRuntime::getInstance()->scheduleDelayedBlockUpdate($this->position->getWorld(), $this->position, $delay);
		$this->nextUpdateTick = $currentTick + $actualDelay;
	}

	public function onUpdate() : void{
		if($this->closed){
			return;
		}

		$this->timings->startTiming();
		try{
			$currentTick = $this->position->getWorld()->getServer()->getTick();
			if($this->nextUpdateTick !== null && $currentTick < $this->nextUpdateTick){
				return;
			}
			if($this->lastProcessedTick === $currentTick){
				return;
			}

			$this->nextUpdateTick = null;
			$this->lastProcessedTick = $currentTick;
			$this->transferCooldown = $this->getTransferCooldown();
			$this->lastUpdateTick = $currentTick;

			if($this->transferCooldown === 0 && !$this->isPowered()){
				$didTransfer = $this->pushItems();
				$origin = $this->getPullableContainer();
				if($origin !== null){
					$didTransfer = $this->pullItems($origin) || $didTransfer;
				}else{
					$didTransfer = $this->pickupItems() || $didTransfer;
				}

				if($didTransfer){
					$this->transferCooldown = $this->getDefaultTransferCooldown();
				}
			}

			if($this->transferCooldown > 0){
				$this->scheduleUpdate($this->transferCooldown);
			}
		}finally{
			$this->timings->stopTiming();
		}
	}

	private function isPowered() : bool{
		$block = $this->getBlock();
		return $block instanceof BlockHopper && $block->isPowered();
	}

	private function getPullableContainer() : ?Container{
		$tile = $this->position->getWorld()->getTile($this->position->getSide(Facing::UP));
		return $tile instanceof Container ? $tile : null;
	}

	private function pushItems() : bool{
		$block = $this->getBlock();
		if(!$block instanceof BlockHopper){
			return false;
		}

		$destination = $this->position->getWorld()->getTile($this->position->getSide($block->getFacing()));
		if($destination === null){
			return false;
		}

		$itemsToTransfer = $this->getItemsPerUpdate();
		if($destination instanceof Furnace || $destination instanceof Jukebox){
			return $this->pushIntoSpecialContainer($destination, $itemsToTransfer, $block->getFacing());
		}

		if(!$destination instanceof Container){
			return false;
		}
		if(!$destination instanceof Tile){
			return false;
		}

		$destinationInventory = $destination->getInventory();
		$destinationWasEmpty = $destination instanceof self && self::inventoryIsEmpty($destinationInventory);
		$didTransfer = false;

		for($slot = 0, $size = $this->inventory->getSize(); $slot < $size && $itemsToTransfer > 0; ++$slot){
			$item = $this->inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}

			$transferAmount = min(
				$item->getCount(),
				$itemsToTransfer,
				$destinationInventory->getAddableItemQuantity($item)
			);
			if($transferAmount <= 0){
				continue;
			}

			$itemToPush = clone $item;
			$itemToPush->setCount($transferAmount);

			if(HopperPushContainerEvent::hasHandlers()){
				$event = new HopperPushContainerEvent($block, $this->inventory, $destination->getBlock(), $destinationInventory, $itemToPush);
				$event->call();
				if($event->isCancelled()){
					continue;
				}
			}

			$transferredAmount = $destination instanceof self ?
				$destination->withoutInventoryScheduling(fn() => self::addItemAndGetTransferredCount($destinationInventory, $itemToPush)) :
				self::addItemAndGetTransferredCount($destinationInventory, $itemToPush);
			if($transferredAmount <= 0){
				continue;
			}

			if($destinationWasEmpty && $destination instanceof self){
				$destination->setTransferCooldown($this->getDefaultTransferCooldown());
				$destination->scheduleUpdate($this->getDefaultTransferCooldown());
				$destinationWasEmpty = false;
			}

			$remaining = clone $item;
			$remaining->setCount($item->getCount() - $transferredAmount);
			$this->withoutInventoryScheduling(function() use ($slot, $remaining) : void{
				$this->inventory->setItem($slot, $remaining);
			});

			$itemsToTransfer -= $transferredAmount;
			$didTransfer = true;
		}

		if($didTransfer){
			$this->scheduleAfterPush($destination->getPosition());
		}

		return $didTransfer;
	}

	private function pushIntoSpecialContainer(Furnace|Jukebox $destination, int $itemsToTransfer, int $facing) : bool{
		$block = $this->getBlock();
		if(!$block instanceof BlockHopper){
			return false;
		}

		$didTransfer = false;

		for($slot = 0, $size = $this->inventory->getSize(); $slot < $size && $itemsToTransfer > 0; ++$slot){
			$item = $this->inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}

			if($destination instanceof Furnace){
				if($facing === Facing::DOWN){
					$slotInFurnace = FurnaceInventory::SLOT_INPUT;
					$itemInFurnace = $destination->getInventory()->getSmelting();
				}else{
					if($item->getFuelTime() === 0){
						continue;
					}
					$slotInFurnace = FurnaceInventory::SLOT_FUEL;
					$itemInFurnace = $destination->getInventory()->getFuel();
				}

				if(!$itemInFurnace->isNull()){
					if(!$itemInFurnace->canStackWith($item)){
						continue;
					}
					$availableSpace = min($itemInFurnace->getMaxStackSize(), $destination->getInventory()->getMaxStackSize()) - $itemInFurnace->getCount();
				}else{
					$availableSpace = min($item->getMaxStackSize(), $destination->getInventory()->getMaxStackSize());
				}

				if($availableSpace <= 0){
					continue;
				}

				$transferAmount = min($item->getCount(), $itemsToTransfer, $availableSpace);
				if($transferAmount <= 0){
					continue;
				}

				$itemToPush = clone $item;
				$itemToPush->setCount($transferAmount);

				if(HopperPushContainerEvent::hasHandlers()){
					$event = new HopperPushContainerEvent($block, $this->inventory, $destination->getBlock(), $destination->getInventory(), $itemToPush);
					$event->call();
					if($event->isCancelled()){
						continue;
					}
				}

				if(!$itemInFurnace->isNull()){
					$updatedItem = clone $itemInFurnace;
					$updatedItem->setCount($itemInFurnace->getCount() + $transferAmount);
				}else{
					$updatedItem = $itemToPush;
				}

				$remaining = clone $item;
				$remaining->setCount($item->getCount() - $transferAmount);
				$this->withoutInventoryScheduling(function() use ($slot, $remaining) : void{
					$this->inventory->setItem($slot, $remaining);
				});
				$destination->getInventory()->setItem($slotInFurnace, $updatedItem);
				$itemsToTransfer -= $transferAmount;
				$didTransfer = true;
				continue;
			}

			if(!$item instanceof Record || $destination->getRecord() !== null){
				continue;
			}

			$jukebox = $destination->getBlock();
			if(!$jukebox instanceof BlockJukebox){
				continue;
			}

			if(HopperPushJukeboxEvent::hasHandlers()){
				$event = new HopperPushJukeboxEvent($block, $this->inventory, $jukebox, $item);
				$event->call();
				if($event->isCancelled()){
					continue;
				}
			}

			$jukebox->insertRecord(clone $item);
			$jukebox->getPosition()->getWorld()->setBlock($jukebox->getPosition(), $jukebox);

			$remaining = clone $item;
			$remaining->setCount($item->getCount() - 1);
			$this->withoutInventoryScheduling(function() use ($slot, $remaining) : void{
				$this->inventory->setItem($slot, $remaining);
			});

			$this->scheduleAfterPush($destination->getPosition());
			return true;
		}

		if($didTransfer){
			$this->scheduleAfterPush($destination->getPosition());
		}

		return $didTransfer;
	}

	private function pullItems(Container $origin) : bool{
		$block = $this->getBlock();
		if(!$block instanceof BlockHopper){
			return false;
		}

		if($origin instanceof Furnace){
			return $this->pullFromFurnace($origin, $this->getItemsPerUpdate());
		}
		if(!$origin instanceof Tile){
			return false;
		}

		$originInventory = $origin->getInventory();
		$originBlock = $origin->getBlock();
		$itemsToTransfer = $this->getItemsPerUpdate();
		$didTransfer = false;

		for($slot = 0, $size = $originInventory->getSize(); $slot < $size && $itemsToTransfer > 0; ++$slot){
			$item = $originInventory->getItem($slot);
			if($item->isNull()){
				continue;
			}

			$transferAmount = min(
				$item->getCount(),
				$itemsToTransfer,
				$this->inventory->getAddableItemQuantity($item)
			);
			if($transferAmount <= 0){
				continue;
			}
			$itemToPull = clone $item;
			$itemToPull->setCount($transferAmount);

			if(HopperPullContainerEvent::hasHandlers()){
				$event = new HopperPullContainerEvent($block, $this->inventory, $originBlock, $originInventory, $itemToPull);
				$event->call();
				if($event->isCancelled()){
					continue;
				}
			}

			$transferredAmount = $this->withoutInventoryScheduling(fn() => self::addItemAndGetTransferredCount($this->inventory, $itemToPull));
			if($transferredAmount <= 0){
				continue;
			}

			$remaining = clone $item;
			$remaining->setCount($item->getCount() - $transferredAmount);
			if($origin instanceof self){
				$origin->withoutInventoryScheduling(function() use ($originInventory, $slot, $remaining) : void{
					$originInventory->setItem($slot, $remaining);
				});
			}else{
				$originInventory->setItem($slot, $remaining);
			}

			$itemsToTransfer -= $transferredAmount;
			$didTransfer = true;
		}

		if($didTransfer){
			$this->scheduleAfterPull($originBlock->getPosition());
		}

		return $didTransfer;
	}

	private function pullFromFurnace(Furnace $furnace, int $itemsToTransfer) : bool{
		$block = $this->getBlock();
		if(!$block instanceof BlockHopper){
			return false;
		}

		$originBlock = $furnace->getBlock();
		$didTransfer = false;

		foreach([FurnaceInventory::SLOT_FUEL, FurnaceInventory::SLOT_RESULT] as $slot){
			if($itemsToTransfer <= 0){
				break;
			}

			$item = $furnace->getInventory()->getItem($slot);
			if($slot === FurnaceInventory::SLOT_FUEL && !$item instanceof Bucket){
				continue;
			}
			if($item->isNull()){
				continue;
			}

			$transferAmount = min($item->getCount(), $itemsToTransfer, $this->inventory->getAddableItemQuantity($item));
			if($transferAmount <= 0){
				continue;
			}

			$itemToPull = clone $item;
			$itemToPull->setCount($transferAmount);

			if(HopperPullContainerEvent::hasHandlers()){
				$event = new HopperPullContainerEvent($block, $this->inventory, $originBlock, $furnace->getInventory(), $itemToPull);
				$event->call();
				if($event->isCancelled()){
					continue;
				}
			}

			$transferredAmount = $this->withoutInventoryScheduling(fn() => self::addItemAndGetTransferredCount($this->inventory, $itemToPull));
			if($transferredAmount <= 0){
				continue;
			}

			$remaining = clone $item;
			$remaining->setCount($item->getCount() - $transferredAmount);
			$furnace->getInventory()->setItem($slot, $remaining);
			$itemsToTransfer -= $transferredAmount;
			$didTransfer = true;
		}

		if($didTransfer){
			$this->scheduleAfterPull($originBlock->getPosition());
		}

		return $didTransfer;
	}

	private function pickupItems() : bool{
		$collector = $this->getBlock();
		if(!$collector instanceof BlockHopper){
			return false;
		}

		$itemsToTransfer = $this->getItemsPerUpdate();
		$didTransfer = false;
		$dataStore = HopperDataStore::getInstance();

		foreach($dataStore->getAssignedEntities($this->position) as $entity){
			if($entity->isClosed() || $entity->isFlaggedForDespawn()){
				$dataStore->unassignEntity($this->position, $entity);
				continue;
			}

			if($itemsToTransfer <= 0){
				return true;
			}

			$item = $entity->getItem();
			if($this->inventory->getAddableItemQuantity($item) <= 0){
				continue;
			}

			foreach($this->getPickupCollisionBoxes() as $box){
				if(!$entity->boundingBox->intersectsWith($box)){
					continue;
				}

				$targetInventory = $this->inventory;
				$pickupItem = $item;

				if(BlockItemPickupEvent::hasHandlers()){
					$event = new BlockItemPickupEvent($collector, $entity, $item, $this->inventory);
					$event->call();
					if($event->isCancelled()){
						continue;
					}

					$targetInventory = $event->getInventory();
					$pickupItem = $event->getItem();
				}

				--$itemsToTransfer;
				$didTransfer = true;
				$this->scheduleAfterPickup();

				if($targetInventory !== null){
					$remains = $targetInventory === $this->inventory ?
						$this->withoutInventoryScheduling(fn() => $targetInventory->addItem($pickupItem)) :
						$targetInventory->addItem($pickupItem);

					if($remains !== []){
						$entity->setItem($remains[0]);
						continue 2;
					}
				}

				$dataStore->unassignEntity($this->position, $entity);
				$entity->flagForDespawn();
				continue 2;
			}
		}

		return $didTransfer;
	}

	private function getDefaultTransferCooldown() : int{
		return HopperRuntime::getInstance()->getDefaultTransferCooldown($this->position->getWorld()->getServer());
	}

	private function getItemsPerUpdate() : int{
		return HopperRuntime::getInstance()->getItemsPerUpdate($this->position->getWorld()->getServer());
	}

	private function scheduleAfterPush(Position $destination) : void{
		$runtime = HopperRuntime::getInstance();
		$runtime->scheduleHoppersAround($this->position, [Facing::UP, Facing::NORTH, Facing::SOUTH, Facing::WEST, Facing::EAST]);
		$runtime->scheduleHoppersAround($destination, [Facing::DOWN]);
	}

	private function scheduleAfterPull(Position $origin) : void{
		$runtime = HopperRuntime::getInstance();
		$runtime->scheduleHoppersAround($this->position, [Facing::DOWN, Facing::UP]);
		$runtime->scheduleHoppersAround($origin, [Facing::UP, Facing::NORTH, Facing::SOUTH, Facing::WEST, Facing::EAST]);
	}

	private function scheduleAfterPickup() : void{
		HopperRuntime::getInstance()->scheduleHoppersAround($this->position, [Facing::DOWN]);
	}

	/**
	 * @return AxisAlignedBB[]
	 */
	private function getPickupCollisionBoxes() : array{
		if($this->pickupBoxes !== null){
			return $this->pickupBoxes;
		}

		$x = $this->position->x;
		$y = $this->position->y;
		$z = $this->position->z;

		return $this->pickupBoxes = [
			new AxisAlignedBB($x, $y + 1, $z, $x + 1, $y + 3.75, $z + 1),
			new AxisAlignedBB($x + 3 / 16, $y + 10 / 16, $z + 3 / 16, $x + 13 / 16, $y + 1, $z + 13 / 16)
		];
	}

	private function withoutInventoryScheduling(\Closure $callback) : mixed{
		$this->suppressInventorySchedule = true;
		try{
			return $callback();
		}finally{
			$this->suppressInventorySchedule = false;
		}
	}

	private static function inventoryIsEmpty(Inventory $inventory) : bool{
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			if(!$inventory->getItem($slot)->isNull()){
				return false;
			}
		}

		return true;
	}

	private static function addItemAndGetTransferredCount(Inventory $inventory, Item $item) : int{
		$originalCount = $item->getCount();
		$remains = $inventory->addItem($item);

		return $originalCount - self::getTotalItemCount($remains);
	}

	/**
	 * @param Item[] $items
	 */
	private static function getTotalItemCount(array $items) : int{
		$count = 0;
		foreach($items as $item){
			$count += $item->getCount();
		}

		return $count;
	}
}