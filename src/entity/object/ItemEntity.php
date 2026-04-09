<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\Hopper as HopperBlock;
use pocketmine\block\utils\HopperDataStore;
use pocketmine\block\utils\HopperRuntime;
use pocketmine\entity\animation\ItemEntityStackSizeChangeAnimation;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\ItemDespawnEvent;
use pocketmine\event\entity\ItemMergeEvent;
use pocketmine\event\entity\ItemSpawnEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\AddItemActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\timings\Timings;
use pocketmine\world\Position;
use pocketmine\world\World;
use function abs;
use function max;
use function min;

class ItemEntity extends Entity{

	private const TAG_HEALTH = "Health"; //TAG_Short
	private const TAG_AGE = "Age"; //TAG_Short
	private const TAG_PICKUP_DELAY = "PickupDelay"; //TAG_Short
	private const TAG_OWNER = "Owner"; //TAG_String
	private const TAG_THROWER = "Thrower"; //TAG_String
	public const TAG_ITEM = "Item"; //TAG_Compound

	public static function getNetworkTypeId() : string{ return EntityIds::ITEM; }

	public const MERGE_CHECK_PERIOD = 2; //0.1 seconds
	public const DEFAULT_DESPAWN_DELAY = 6000; //5 minutes
	public const NEVER_DESPAWN = -1;
	public const MAX_DESPAWN_DELAY = 32767 + self::DEFAULT_DESPAWN_DELAY; //max value storable by mojang NBT :(
	private const MAX_PICKUP_HEIGHT = 3;
	private const STILL_TICKS_THRESHOLD = 5;
	private const MOTION_EPSILON = 0.003;

	/** @var array<int, array<int, self>> */
	private static array $entitiesByWorld = [];
	/** @var array<int, array<string, int|null>> */
	private static array $columnHopperCache = [];

	protected string $owner = "";
	protected string $thrower = "";
	protected int $pickupDelay = 0;
	protected int $despawnDelay = self::DEFAULT_DESPAWN_DELAY;
	protected Item $item;
	private int $stillTicks = 0;
	private bool $motionFrozen = false;
	private ?Position $assignedHopper = null;
	private bool $initialRefreshDone = false;

	public function __construct(Location $location, Item $item, ?CompoundTag $nbt = null){
		if($item->isNull()){
			throw new \InvalidArgumentException("Item entity must have a non-air item with a count of at least 1");
		}
		$this->item = clone $item;
		parent::__construct($location, $nbt);
		self::registerEntity($this);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.04; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->setMaxHealth(5);
		$this->setHealth($nbt->getShort(self::TAG_HEALTH, (int) $this->getHealth()));

		$age = $nbt->getShort(self::TAG_AGE, 0);
		if($age === -32768){
			$this->despawnDelay = self::NEVER_DESPAWN;
		}else{
			$this->despawnDelay = max(0, self::DEFAULT_DESPAWN_DELAY - $age);
		}
		$configuredDespawnDelay = HopperRuntime::getInstance()->getItemDespawnTicks($this->server);
		if($configuredDespawnDelay > 0 && $this->despawnDelay !== self::NEVER_DESPAWN){
			$this->despawnDelay = min($this->despawnDelay, $configuredDespawnDelay);
		}
		$this->pickupDelay = $nbt->getShort(self::TAG_PICKUP_DELAY, $this->pickupDelay);
		$this->owner = $nbt->getString(self::TAG_OWNER, $this->owner);
		$this->thrower = $nbt->getString(self::TAG_THROWER, $this->thrower);
	}

	protected function onFirstUpdate(int $currentTick) : void{
		(new ItemSpawnEvent($this))->call(); //this must be called before EntitySpawnEvent, to maintain backwards compatibility
		parent::onFirstUpdate($currentTick);
	}

	public function onUpdate(int $currentTick) : bool{
		if(!$this->initialRefreshDone){
			$this->initialRefreshDone = true;
			$this->refreshAssignedHopper();
		}

		if(!$this->isFlaggedForDespawn() && !$this->closed){
			if($this->motionFrozen){
				$motion = $this->getMotion();
				if($this->onGround){
					$this->motion = new Vector3(0.0, 0.0, 0.0);
				}elseif($motion->y > 0.05){
					$this->motion = new Vector3($motion->x * 0.1, 0.05, $motion->z * 0.1);
				}
			}else{
				$motion = $this->getMotion();
				if(
					$this->onGround &&
					abs($motion->x) < self::MOTION_EPSILON &&
					abs($motion->y) < self::MOTION_EPSILON &&
					abs($motion->z) < self::MOTION_EPSILON
				){
					if(++$this->stillTicks >= self::STILL_TICKS_THRESHOLD){
						$this->motion = new Vector3(0.0, 0.0, 0.0);
						$this->motionFrozen = true;
					}
				}else{
					$this->stillTicks = 0;
				}
			}
		}

		return parent::onUpdate($currentTick);
	}

	public function setMotion(Vector3 $motion) : bool{
		$updated = parent::setMotion($motion);
		if($updated && $motion->lengthSquared() > 0.0){
			$this->unfreezeMotion();
		}

		return $updated;
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		$previousWorld = $this->location->world;
		$previousFloorX = $this->location->getFloorX();
		$previousFloorY = $this->location->getFloorY();
		$previousFloorZ = $this->location->getFloorZ();

		parent::move($dx, $dy, $dz);

		if(
			$this->location->world === $previousWorld &&
			$this->location->getFloorX() === $previousFloorX &&
			$this->location->getFloorY() === $previousFloorY &&
			$this->location->getFloorZ() === $previousFloorZ
		){
			return;
		}

		$this->refreshAssignedHopper();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}

		Timings::$itemEntityBaseTick->startTiming();
		try{

			$hasUpdate = parent::entityBaseTick($tickDiff);

			if($this->isFlaggedForDespawn()){
				return $hasUpdate;
			}

			if($this->pickupDelay !== self::NEVER_DESPAWN && $this->pickupDelay > 0){ //Infinite delay
				$hasUpdate = true;
				$this->pickupDelay -= $tickDiff;
				if($this->pickupDelay < 0){
					$this->pickupDelay = 0;
				}
			}

			if($this->hasMovementUpdate() && $this->isMergeCandidate() && $this->despawnDelay % self::MERGE_CHECK_PERIOD === 0){
				$mergeable = [$this]; //in case the merge target ends up not being this
				$mergeTarget = $this;
				foreach($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy(0.5, 0.5, 0.5), $this) as $entity){
					if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn()){
						continue;
					}

					if($entity->isMergeable($this)){
						$mergeable[] = $entity;
						if($entity->item->getCount() > $mergeTarget->item->getCount()){
							$mergeTarget = $entity;
						}
					}
				}
				foreach($mergeable as $itemEntity){
					if($itemEntity !== $mergeTarget){
						$itemEntity->tryMergeInto($mergeTarget);
					}
				}
			}

			if(!$this->isFlaggedForDespawn() && $this->despawnDelay !== self::NEVER_DESPAWN){
				$hasUpdate = true;
				$this->despawnDelay -= $tickDiff;
				if($this->despawnDelay <= 0){
					$ev = new ItemDespawnEvent($this);
					$ev->call();
					if($ev->isCancelled()){
						$this->despawnDelay = self::DEFAULT_DESPAWN_DELAY;
					}else{
						$this->flagForDespawn();
					}
				}
			}

			return $hasUpdate;
		}finally{
			Timings::$itemEntityBaseTick->stopTiming();
		}
	}

	private function isMergeCandidate() : bool{
		return $this->pickupDelay !== self::NEVER_DESPAWN && $this->item->getCount() < $this->item->getMaxStackSize();
	}

	/**
	 * Returns whether this item entity can merge with the given one.
	 */
	public function isMergeable(ItemEntity $entity) : bool{
		if(!$this->isMergeCandidate() || !$entity->isMergeCandidate()){
			return false;
		}
		$item = $entity->item;
		return $entity !== $this && $item->canStackWith($this->item) && $item->getCount() + $this->item->getCount() <= $item->getMaxStackSize();
	}

	/**
	 * Attempts to merge this item entity into the given item entity. Returns true if it was successful.
	 */
	public function tryMergeInto(ItemEntity $consumer) : bool{
		if(!$this->isMergeable($consumer)){
			return false;
		}

		$ev = new ItemMergeEvent($this, $consumer);
		$ev->call();

		if($ev->isCancelled()){
			return false;
		}

		$consumer->setStackSize($consumer->item->getCount() + $this->item->getCount());
		$this->flagForDespawn();
		$consumer->pickupDelay = max($consumer->pickupDelay, $this->pickupDelay);
		$consumer->despawnDelay = max($consumer->despawnDelay, $this->despawnDelay);

		return true;
	}

	protected function tryChangeMovement() : void{
		$this->checkObstruction($this->location->x, $this->location->y, $this->location->z);
		parent::tryChangeMovement();
	}

	protected function applyDragBeforeGravity() : bool{
		return true;
	}

	public function canSaveWithChunk() : bool{
		return !$this->item->isNull() && parent::canSaveWithChunk();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		$nbt->setShort(self::TAG_HEALTH, (int) $this->getHealth());
		if($this->despawnDelay === self::NEVER_DESPAWN){
			$age = -32768;
		}else{
			$age = self::DEFAULT_DESPAWN_DELAY - $this->despawnDelay;
		}
		$nbt->setShort(self::TAG_AGE, $age);
		$nbt->setShort(self::TAG_PICKUP_DELAY, $this->pickupDelay);
		$nbt->setString(self::TAG_OWNER, $this->owner);
		$nbt->setString(self::TAG_THROWER, $this->thrower);

		return $nbt;
	}

	public function getItem() : Item{
		return $this->item;
	}

	public function setItem(Item $item) : void{
		if($item->isNull()){
			$this->flagForDespawn();
			return;
		}

		if($this->item->canStackWith($item)){
			if($this->item->getCount() !== $item->getCount()){
				$this->setStackSize($item->getCount());
			}
			return;
		}

		$this->unfreezeMotion();
		$this->item = clone $item;
		$this->despawnFromAll();
		$this->spawnToAll();
	}

	public function isFireProof() : bool{
		return $this->item->isFireProof();
	}

	public function canCollideWith(Entity $entity) : bool{
		return false;
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	public function getPickupDelay() : int{
		return $this->pickupDelay;
	}

	public function setPickupDelay(int $delay) : void{
		$this->pickupDelay = $delay;
	}

	/**
	 * Returns the number of ticks left before this item will despawn. If -1, the item will never despawn.
	 */
	public function getDespawnDelay() : int{
		return $this->despawnDelay;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function setDespawnDelay(int $despawnDelay) : void{
		if(($despawnDelay < 0 || $despawnDelay > self::MAX_DESPAWN_DELAY) && $despawnDelay !== self::NEVER_DESPAWN){
			throw new \InvalidArgumentException("Despawn ticker must be in range 0 ... " . self::MAX_DESPAWN_DELAY . " or " . self::NEVER_DESPAWN . ", got $despawnDelay");
		}
		$this->despawnDelay = $despawnDelay;
	}

	public function getOwner() : string{
		return $this->owner;
	}

	public function setOwner(string $owner) : void{
		$this->owner = $owner;
	}

	public function getThrower() : string{
		return $this->thrower;
	}

	public function setThrower(string $thrower) : void{
		$this->thrower = $thrower;
	}

	protected function setPosition(Vector3 $pos) : bool{
		$oldWorld = $this->getWorld();
		$oldFloorX = $this->location->getFloorX();
		$oldFloorY = $this->location->getFloorY();
		$oldFloorZ = $this->location->getFloorZ();
		$moved = parent::setPosition($pos);
		if(!$moved){
			return false;
		}

		if($oldWorld !== $this->getWorld()){
			self::unregisterEntity($this, $oldWorld);
			self::registerEntity($this);
		}

		if(
			$oldWorld !== $this->getWorld() ||
			$oldFloorX !== $this->location->getFloorX() ||
			$oldFloorY !== $this->location->getFloorY() ||
			$oldFloorZ !== $this->location->getFloorZ()
		){
			$this->refreshAssignedHopper();
		}

		return true;
	}

	protected function onDispose() : void{
		$this->clearAssignedHopper();
		self::unregisterEntity($this);
		parent::onDispose();
	}

	protected function sendSpawnPacket(Player $player) : void{
		$networkSession = $player->getNetworkSession();
		$networkSession->sendDataPacket(AddItemActorPacket::create(
			$this->getId(), //TODO: entity unique ID
			$this->getId(),
			ItemStackWrapper::legacy($networkSession->getTypeConverter()->coreItemStackToNet($this->getItem())),
			$this->location->asVector3(),
			$this->getMotion(),
			$this->getAllNetworkData(),
			false //TODO: I have no idea what this is needed for, but right now we don't support fishing anyway
		));
	}

	public function setStackSize(int $newCount) : void{
		if($newCount <= 0){
			throw new \InvalidArgumentException("Stack size must be at least 1");
		}
		$this->item->setCount($newCount);
		$this->broadcastAnimation(new ItemEntityStackSizeChangeAnimation($this, $newCount));
	}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3->add(0, 0.125, 0);
	}

	public function onCollideWithPlayer(Player $player) : void{
		if($this->getPickupDelay() !== 0){
			return;
		}

		$item = $this->getItem();
		$playerInventory = match(true){
			$player->getOffHandInventory()->getItem(0)->canStackWith($item) && $player->getOffHandInventory()->getAddableItemQuantity($item) > 0 => $player->getOffHandInventory(),
			$player->getInventory()->getAddableItemQuantity($item) > 0 => $player->getInventory(),
			default => null
		};

		$ev = new EntityItemPickupEvent($player, $this, $item, $playerInventory);
		if($player->hasFiniteResources() && $playerInventory === null){
			$ev->cancel();
		}

		$ev->call();
		if($ev->isCancelled()){
			return;
		}

		NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $player, $this)
		);

		$inventory = $ev->getInventory();
		if($inventory !== null){
			foreach($inventory->addItem($ev->getItem()) as $remains){
				$this->getWorld()->dropItem($this->location, $remains, new Vector3(0, 0, 0));
			}
		}
		$this->flagForDespawn();
	}

	/**
	 * @return array<int, array<int, self>>
	 */
	public static function getOverflowWorlds(int $limit) : array{
		if($limit <= 0){
			return [];
		}

		$overflow = [];
		foreach(self::$entitiesByWorld as $worldId => $entities){
			if(count($entities) > $limit){
				$overflow[$worldId] = $entities;
			}
		}

		return $overflow;
	}

	public static function removeWorld(World $world) : void{
		unset(self::$entitiesByWorld[$world->getId()]);
	}

	public static function clearColumnCacheForWorld(World $world) : void{
		unset(self::$columnHopperCache[$world->getId()]);
	}

	private static function registerEntity(self $entity) : void{
		if($entity->closed || $entity->isFlaggedForDespawn()){
			return;
		}

		self::$entitiesByWorld[$entity->getWorld()->getId()][$entity->getId()] = $entity;
	}

	private static function unregisterEntity(self $entity, ?World $world = null) : void{
		$world ??= $entity->getWorld();
		$worldId = $world->getId();

		unset(self::$entitiesByWorld[$worldId][$entity->getId()]);
		if((self::$entitiesByWorld[$worldId] ?? []) === []){
			unset(self::$entitiesByWorld[$worldId]);
		}
	}

	private function unfreezeMotion() : void{
		$this->stillTicks = 0;
		$this->motionFrozen = false;
	}

	private function refreshAssignedHopper() : bool{
		$world = $this->location->getWorld();
		$floorX = $this->location->getFloorX();
		$floorY = $this->location->getFloorY();
		$floorZ = $this->location->getFloorZ();

		$cachedY = self::$columnHopperCache[$world->getId()][$this->getColumnCacheKey($floorX, $floorZ)] ?? null;
		if($cachedY !== null && $cachedY <= $floorY && $cachedY >= $floorY - self::MAX_PICKUP_HEIGHT){
			$cachedBlock = $world->getBlockAt($floorX, $cachedY, $floorZ);
			if($cachedBlock instanceof HopperBlock){
				$cachedBlock->scheduleDelayedBlockUpdate(0);
				$this->assignToHopper($cachedBlock->getPosition());
				return true;
			}
		}

		for($dy = 0; $dy <= self::MAX_PICKUP_HEIGHT; ++$dy){
			$block = $world->getBlockAt($floorX, $floorY - $dy, $floorZ);
			if($block instanceof HopperBlock){
				self::$columnHopperCache[$world->getId()][$this->getColumnCacheKey($floorX, $floorZ)] = $floorY - $dy;
				$block->scheduleDelayedBlockUpdate(0);
				$this->assignToHopper($block->getPosition());
				return true;
			}
		}

		self::$columnHopperCache[$world->getId()][$this->getColumnCacheKey($floorX, $floorZ)] = null;
		$this->clearAssignedHopper();
		return false;
	}

	private function assignToHopper(Position $position) : void{
		if($this->assignedHopper !== null && $this->isSamePosition($this->assignedHopper, $position)){
			HopperDataStore::getInstance()->assignEntity($position, $this);
			return;
		}

		$this->clearAssignedHopper();
		$this->assignedHopper = Position::fromObject($position, $position->getWorld());
		HopperDataStore::getInstance()->assignEntity($position, $this);
	}

	private function clearAssignedHopper() : void{
		if($this->assignedHopper === null){
			return;
		}

		HopperDataStore::getInstance()->unassignEntity($this->assignedHopper, $this);
		$this->assignedHopper = null;
	}

	private function isSamePosition(Position $left, Position $right) : bool{
		return $left->getWorld() === $right->getWorld()
			&& $left->getFloorX() === $right->getFloorX()
			&& $left->getFloorY() === $right->getFloorY()
			&& $left->getFloorZ() === $right->getFloorZ();
	}

	private function getColumnCacheKey(int $x, int $z) : string{
		return $x . ":" . $z;
	}
}
